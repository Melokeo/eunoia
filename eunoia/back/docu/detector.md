# Purpose

Normalize one message into intent, entities, and slots. No DB writes. No external calls.

# Class surface
final class Detector {
  public function __construct(array $config = []);              // pure, stateless
  public function version(): string;                             // e.g. "det-v2"
  public function detect(string $text, array $opts = []): array; // main API
  public function types(): array;                                // allowed node/edge/slot types
}

# Input

text: UTF-8, ≤ 8k chars.

opts:

role: 'user'|'assistant' (default 'user')

lang_hint: IETF tag or null

tz: IANA tz string for date resolution

today: YYYY-MM-DD for relative dates

session_id: string (for logging only, not used in output)

# Output schema (single assoc array)
[
  'detector_version' => 'det-v2',
  'intent' => ['label'=>'<string>', 'score'=>0.0..1.0],
  'entities' => [ Entity, ... ],
  'slots' => [ SlotKey => SlotVal, ... ],
  'notes' => ['warnings'=>[...]]           // optional diagnostics
]

## Entity object
[
  'type'   => 'Person|Project|Task|Preference|Artifact|Time|Quantity|Other',
  'text'   => '<verbatim span>',
  'norm'   => '<canonical form>',          // lowercase or title case as appropriate
  'span'   => [start:int, end:int],        // byte offsets in input
  'attrs'  => { key => scalar|string[], ... }, // type-constrained (see below)
  'score'  => 0.0..1.0
]

## Slots

Flat key→value map. Values are scalars or small arrays of scalars.

Keys must be in allow-list. Example keys: deadline, owner, priority, date, time_range.

## Constraints

Deterministic for same (text, opts, config).

No side effects.

Language-agnostic; must not drop non-Latin tokens.

Time resolution: resolve today/tomorrow/Mon using opts.today and opts.tz into ISO YYYY-MM-DD in entities and slots.

Normalization rules:

Trim, collapse whitespace.

Titles ≤255 chars.

norm for Person/Project/Task/Artifact/Preference is title-cased or stable lowercase; no punctuation tails.

Quantity → value (float) and unit in attrs.

Type allow-lists exposed by types(); reject anything else.

## Intent

One of: plan|assign|schedule|query|preference|note|other.

Confidence score required. If ambiguous, return other with low score.

# Minimum quality gates (validator inside detector)

Drop entities with score < config.min_entity_score (default 0.40).

Require multi-word for Task/Project unless quoted.

Stoplist for months, weekdays, generic words from becoming Task/Project.

Merge overlapping spans of same type; prefer higher score.

# Attributes by type (allowed keys)

Time: iso (YYYY-MM-DD), time (HH:MM), range ([HH:MM,HH:MM]), grain (day|time|range).

Quantity: value, unit.

Preference: polarity (like|dislike|prefer|avoid).

Task: status_hint (todo|done|wip), priority_hint (low|med|high).

Others: empty or minimal.

# Config keys (constructor)

min_entity_score (float)

titlecase_locale (string)

require_multiword_task (bool)

stopwords_task (array)

weekday_locale (string)

date_formats (array of regex patterns)

# Errors

On invalid input types, throw InvalidArgumentException.

Never return partial malformed structures. If detection fails, return a valid empty result with intent=other and empty arrays.

# Performance targets

1–2 ms per 200-char message on typical VPS PHP 8.2.

Memory < 1 MB per call.

Zero allocations of large temporaries (>1 MB).

# Logging hooks (optional, no I/O by default)

If config.debug=true, include notes.warnings[] with short strings like ["short_singleton_task_dropped"].

# Test points (must pass)

Multiword task: “finish Pici hand analysis by 09/01”

Entities: Task("Pici hand analysis"), Time("2025-09-01")

Slot: deadline="2025-09-01"

Singleton suppression: “finish Pici”

No Task if require_multiword_task=true and not quoted.

Quoted allowance: “start ‘Pici’ tomorrow”

Task("Pici") allowed because quoted; Time(today+1).

Quantity parse: “32GB RAM in 2 h”

Quantity(32, gb), Quantity(2, h).

Preference: “prefer classical music”

Preference("classical music", polarity="prefer").

Weekday resolution with today and tz set.

Non-Latin: handles CJK names as Person/Project without loss.

# Example return (shape, not regex):
[
  'detector_version'=>'det-v2',
  'intent'=>['label'=>'plan','score'=>0.82],
  'entities'=>[
    ['type'=>'Task','text'=>'Pici hand analysis','norm'=>'Pici hand analysis','span'=>[7,27],'attrs'=>['status_hint'=>'todo'],'score'=>0.76],
    ['type'=>'Time','text'=>'09/01','norm'=>'2025-09-01','span'=>[31,36],'attrs'=>['iso'=>'2025-09-01','grain'=>'day'],'score'=>0.84],
  ],
  'slots'=>['deadline'=>'2025-09-01'],
  'notes'=>[]
]