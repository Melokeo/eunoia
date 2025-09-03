<?php
// /var/lib/euno/graph-memory/BuildGraph.php
declare(strict_types=1);

/**
 * Batch detector + linker for euno.messages → graph.
 * - Uses OpenAI Responses API once per chunk (shared system prompt).
 * - No local model. No regex fallback.
 * - Repo/Linker perform DB ops and aliasing.
 *
 * Run:
 *   php /var/lib/euno/graph-memory/BuildGraph.php
 *
 * Env (optional):
 *   PER_CALL=50   # messages per API call
 *   BATCH=500     # rows pulled from DB each outer loop
 *   TZ=America/New_York
 */

// ---- OpenAI config (as requested) ----
const OPENAI_KEY_PATH = '/var/lib/euno/secrets/openai_key.json';
const OPENAI_API_URL  = 'https://api.openai.com/v1/responses';
const OPENAI_MODEL    = 'gpt-5-mini';

function load_key(): string {
    $data = json_decode(@file_get_contents(OPENAI_KEY_PATH), true);
    if (!is_array($data) || empty($data['api_key'])) {
        http_response_code(500);
        exit(json_encode(['error' => 'OpenAI key missing'], JSON_UNESCAPED_UNICODE));
    }
    return $data['api_key'];
}

// ---- Project deps ----
require_once __DIR__ . '/Repo.php';
require_once __DIR__ . '/Linker.php';

final class BuildGraph
{
    private const VERSION = 'det-v2';
    private const SLOT_KEYS = ['deadline','owner','priority','date','time_range'];
    private const TYPES = ['Person','Project','Task','Preference','Artifact','Time','Quantity','Other'];
    private const INTENTS = ['plan','assign','schedule','query','preference','note','other'];

    private Repo $repo;
    private Linker $linker;
    private \PDO $pdo;

    private string $apiKey;
    private string $apiUrl = OPENAI_API_URL;
    private string $apiModel = OPENAI_MODEL;
    private string $tz;
    private int $batch;
    private int $perCall;
    private float $minEntityScore = 0.40;

    public function __construct()
    {
        $this->repo    = new Repo();
        $this->linker  = new Linker($this->repo);
        $this->pdo     = $this->repo->pdo();

        $this->apiKey  = load_key();
        $this->tz      = getenv('TZ') ?: 'America/New_York';
        $this->batch   = (int)(getenv('BATCH') ?: 500);
        $this->perCall = (int)(getenv('PER_CALL') ?: 30);
        if ($this->perCall < 1) $this->perCall = 1;
    }

    public function run(): void
    {
        [$lastId, $useConfig] = $this->loadCheckpoint();

        while (true) {
            $rows = $this->fetchMessages($lastId, $this->batch);
            if (!$rows) break;

            for ($i = 0; $i < count($rows); $i += $this->perCall) {
                $chunk = array_slice($rows, $i, $this->perCall);
                $items = [];
                foreach ($chunk as $r) {
                    $items[] = [
                        'id'      => (int)$r['id'],
                        'session' => (string)$r['session_id'],
                        'ts'      => (string)$r['ts'],
                        'role'    => (string)$r['role'],
                        'text'    => (string)$r['content'],
                        'opts'    => [
                            'role'       => (string)$r['role'],
                            'lang_hint'  => null,
                            'tz'         => $this->tz,
                            'today'      => $this->dayFromTs((string)$r['ts'], $this->tz),
                            'session_id' => (string)$r['session_id'],
                        ],
                    ];
                }

                $detections = $this->detectMany($items);                // [{__id, det}]
                $map = [];
                foreach ($detections as $it) {
                    $mid = (int)($it['__id'] ?? 0);
                    if ($mid > 0) $map[$mid] = $it['det'] ?? null;
                }

                foreach ($chunk as $r) {
                    $lastId = (int)$r['id'];
                    $det = $map[$lastId] ?? null;
                    if (!$this->validDet($det ?? [])) {
                        $this->saveCheckpoint($lastId, $useConfig);
                        continue;
                    }

                    $this->repo->begin();
                    try {
                        // Link entities → nodes/aliases/evidence (Linker governs dedupe)
                        $this->linker->link($r['session_id'], (int)$r['id'], $det['entities']);

                        // Invent and persist edges conservatively from slots + co-occurrence
                        $this->createEdges($r, $det);

                        $this->repo->commit();
                    } catch (\Throwable $e) {
                        $this->repo->rollBack(); // advance anyway
                    }

                    $this->saveCheckpoint($lastId, $useConfig);
                }
            }
        }
    }

    private function fetchMessages(int $afterId, int $limit): array
    {
        $sql = "SELECT id, session_id, ts, role, content
                  FROM messages
                 WHERE id > ? AND role IN ('user','assistant') AND is_summary=0
              ORDER BY id ASC
                 LIMIT ?";
        $st  = $this->pdo->prepare($sql);
        $st->execute([$afterId, $limit]);
        return $st->fetchAll();
    }

    private function dayFromTs(string $ts, string $tz): string
    {
        $dt = new \DateTimeImmutable($ts, new \DateTimeZone('UTC'));
        return $dt->setTimezone(new \DateTimeZone($tz))->format('Y-m-d');
    }

    /**
     * ONE API call per chunk. New Responses payload shape (2025-08).
     * Input: two messages [system, user]. User contains items array.
     * Output expected: JSON array [{__id, det:{...}}, ...]
     */
    private function detectMany(array $items): array
    {
        $system = $this->systemPrompt();
        $user   = json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ];

        $payload = [
            'model' => $this->apiModel,
            'input' => $messages,
            'max_output_tokens' => 18192,
            'text' => [ 'verbosity' => 'high' ],
            'reasoning' => [ 'effort' => 'medium' ],
        ];

        // simple retry on 429/5xx
        $attempts = 0;
        while (true) {
            $attempts++;
            [$ok, $resp] = $this->postJson($this->apiUrl, $payload, $this->apiKey);
            if ($ok) break;
            if ($attempts >= 4) return [];
            usleep(150000 * $attempts); // backoff
        }

        // Accept Response API shapes
        $arr = [];
        if (isset($resp['output']) && is_array($resp['output'])) {
            $arr = $resp['output'];
        } elseif (isset($resp['output_text']) && is_string($resp['output_text'])) {
            $arr = json_decode($resp['output_text'], true) ?: [];
        } elseif (isset($resp['choices'][0]['message']['content'])) {
            $arr = json_decode((string)$resp['choices'][0]['message']['content'], true) ?: [];
        }

        if (!is_array($arr)) return [];
        $out = [];
        foreach ($arr as $row) {
            if (!is_array($row)) continue;
            $mid = $row['__id'] ?? null;
            $det = $row['det']  ?? null;
            if (!is_int($mid) || !is_array($det)) continue;
            $out[] = ['__id'=>$mid, 'det'=>$this->coerceDet($det)];
        }
        return $out;
    }

    private function systemPrompt(): string
    {
        return <<<SYS
Normalize EACH input chat message independently.
Return ONLY a JSON array: [{ "__id": <message id>, "det": { ... } }, ...]

Schema for det:
- detector_version: "det-v2"
- intent: {label ∈ [plan,assign,schedule,query,preference,note,other], score ∈ [0,1]}
- entities: array of objects with:
  - type ∈ [Person,Project,Task,Preference,Artifact,Time,Quantity,Other]
  - text: verbatim span from input
  - norm: canonical (Latin title-case; CJK unchanged; strip trailing punctuation; trim; ≤255 chars)
  - span: [start,end] byte offsets in the ORIGINAL input string
  - attrs: allowed-by-type ONLY
  - score: 0..1
- slots: object with keys ONLY in [deadline,owner,priority,date,time_range]

Rules:
- Use each item's opts.today (YYYY-MM-DD) and opts.tz (IANA) to resolve relative dates: today, tomorrow, Mon/Tue/… → ISO date in entities.attrs.iso and slots.
- Quantity attrs: {value: float, unit}.
- Time attrs: {iso: YYYY-MM-DD, time: HH:MM, range: [HH:MM,HH:MM], grain ∈ [day,time,range]}.
- Preference attrs: {polarity ∈ [like,dislike,prefer,avoid]}.
- Task attrs: {status_hint ∈ [todo,done,wip], priority_hint ∈ [low,med,high]}.
- Enforce type allow-list. Drop entities with score < 0.40.
- Require multi-word Task/Project unless quoted (single-word allowed if '...' or “...” present).
- Merge overlapping spans of the SAME type; keep the higher score.
- Derive slots deterministically:
  - deadline := first Time entity with attrs.iso when “by <date>” present.
  - date := first Time entity with grain=day.
  - time_range := first Time entity with range.
  - priority := {urgent→high, med→med, high/low as-is} if present in text.
- Do NOT invent extra keys. Do NOT omit obvious entities (e.g., explicit dates, quoted task names, HH:MM times, simple quantities).

### Examples

#1
INPUT items:
[
  {"id": 1, "text": "finish Pici hand analysis by 09/01", "opts":{"today":"2025-08-29","tz":"America/New_York"}}
]
OUTPUT:
[
  {"__id":1,"det":{
    "detector_version":"det-v2",
    "intent":{"label":"plan","score":0.86},
    "entities":[
      {"type":"Task","text":"Pici hand analysis","norm":"Pici Hand Analysis","span":[7,27],"attrs":{"status_hint":"todo"},"score":0.80},
      {"type":"Time","text":"09/01","norm":"2025-09-01","span":[31,36],"attrs":{"iso":"2025-09-01","grain":"day"},"score":0.88}
    ],
    "slots":{"deadline":"2025-09-01","date":"2025-09-01"}
  }}
]

#2
INPUT items:
[
  {"id": 2, "text": "start 'Pici' tomorrow", "opts":{"today":"2025-08-29","tz":"America/New_York"}}
]
OUTPUT:
[
  {"__id":2,"det":{
    "detector_version":"det-v2",
    "intent":{"label":"plan","score":0.82},
    "entities":[
      {"type":"Task","text":"Pici","norm":"Pici","span":[6,12],"attrs":{"status_hint":"todo"},"score":0.86},
      {"type":"Time","text":"tomorrow","norm":"2025-08-30","span":[14,22],"attrs":{"iso":"2025-08-30","grain":"day"},"score":0.84}
    ],
    "slots":{"date":"2025-08-30"}
  }}
]

#3
INPUT items:
[
  {"id": 3, "text": "32GB RAM in 2 h", "opts":{"today":"2025-08-29","tz":"America/New_York"}}
]
OUTPUT:
[
  {"__id":3,"det":{
    "detector_version":"det-v2",
    "intent":{"label":"note","score":0.60},
    "entities":[
      {"type":"Quantity","text":"32GB","norm":"32","span":[0,4],"attrs":{"value":32,"unit":"gb"},"score":0.85},
      {"type":"Quantity","text":"2 h","norm":"2","span":[12,15],"attrs":{"value":2,"unit":"h"},"score":0.82}
    ],
    "slots":{}
  }}
]

#4
INPUT items:
[
  {"id": 4, "text": "i prefer classical music", "opts":{"today":"2025-08-29","tz":"America/New_York"}}
]
OUTPUT:
[
  {"__id":4,"det":{
    "detector_version":"det-v2",
    "intent":{"label":"preference","score":0.90},
    "entities":[
      {"type":"Preference","text":"classical music","norm":"Classical Music","span":[10,26],"attrs":{"polarity":"prefer"},"score":0.86},
      {"type":"Person","text":"i","norm":"USER","span":[0,1],"attrs":{},"score":0.80}
    ],
    "slots":{}
  }}
]

#5  (weekday + CJK name + time range)
INPUT items:
[
  {"id": 5, "text": "周一和洛普约会 14:00-16:00", "opts":{"today":"2025-08-29","tz":"America/New_York"}}
]
OUTPUT:
[
  {"__id":5,"det":{
    "detector_version":"det-v2",
    "intent":{"label":"schedule","score":0.85},
    "entities":[
      {"type":"Time","text":"周一","norm":"2025-09-01","span":[0,6],"attrs":{"iso":"2025-09-01","grain":"day"},"score":0.80},
      {"type":"Person","text":"洛普","norm":"洛普","span":[9,15],"attrs":{},"score":0.70},
      {"type":"Time","text":"14:00-16:00","norm":"2025-08-29","span":[19,30],"attrs":{"range":["14:00","16:00"],"grain":"range"},"score":0.78}
    ],
    "slots":{"date":"2025-09-01","time_range":["14:00","16:00"]}
  }}
]

#6  (singleton suppression)
INPUT items:
[
  {"id": 6, "text": "finish Pici", "opts":{"today":"2025-08-29","tz":"America/New_York"}}
]
OUTPUT:
[
  {"__id":6,"det":{
    "detector_version":"det-v2",
    "intent":{"label":"plan","score":0.70},
    "entities":[],
    "slots":{}
  }}
]

#7  (person query)
INPUT items:
[
  {"id": 7, "text": "who is ada lovelace?", "opts":{"today":"2025-08-29","tz":"America/New_York"}}
]
OUTPUT:
[
  {"__id":7,"det":{
    "detector_version":"det-v2",
    "intent":{"label":"query","score":0.95},
    "entities":[
      {"type":"Person","text":"ada lovelace","norm":"Ada Lovelace","span":[7,19],"attrs":{},"score":0.90}
    ],
    "slots":{}
  }}
]

#8  (preference dislike)
INPUT items:
[
  {"id": 8, "text": "dislike long meetings", "opts":{"today":"2025-08-29","tz":"America/New_York"}}
]
OUTPUT:
[
  {"__id":8,"det":{
    "detector_version":"det-v2",
    "intent":{"label":"preference","score":0.90},
    "entities":[
      {"type":"Preference","text":"long meetings","norm":"Long Meetings","span":[8,21],"attrs":{"polarity":"dislike"},"score":0.86}
    ],
    "slots":{}
  }}
]

#9  (note with URL → Artifact)
INPUT items:
[
  {"id": 9, "text": "FYI: see https://acme.co/Q3-report.pdf", "opts":{"today":"2025-08-29","tz":"America/New_York"}}
]
OUTPUT:
[
  {"__id":9,"det":{
    "detector_version":"det-v2",
    "intent":{"label":"note","score":0.70},
    "entities":[
      {"type":"Artifact","text":"https://acme.co/Q3-report.pdf","norm":"https://acme.co/q3-report.pdf","span":[9,39],"attrs":{},"score":0.80}
    ],
    "slots":{}
  }}
]

#10 (day + time range)
INPUT items:
[
  {"id": 10, "text": "Friday 09:00-11:30 OK?", "opts":{"today":"2025-08-29","tz":"America/New_York"}}
]
OUTPUT:
[
  {"__id":10,"det":{
    "detector_version":"det-v2",
    "intent":{"label":"schedule","score":0.85},
    "entities":[
      {"type":"Time","text":"Friday","norm":"2025-08-29","span":[0,6],"attrs":{"iso":"2025-08-29","grain":"day"},"score":0.80},
      {"type":"Time","text":"09:00-11:30","norm":"2025-08-29","span":[7,19],"attrs":{"range":["09:00","11:30"],"grain":"range"},"score":0.80}
    ],
    "slots":{"date":"2025-08-29","time_range":["09:00","11:30"]}
  }}
]

#11 (mixed language + person)
INPUT items:
[
  {"id": 11, "text": "明天 10:00 和 Alice 聊聊", "opts":{"today":"2025-08-29","tz":"America/New_York"}}
]
OUTPUT:
[
  {"__id":11,"det":{
    "detector_version":"det-v2",
    "intent":{"label":"schedule","score":0.85},
    "entities":[
      {"type":"Time","text":"明天","norm":"2025-08-30","span":[0,6],"attrs":{"iso":"2025-08-30","grain":"day"},"score":0.84},
      {"type":"Time","text":"10:00","norm":"2025-08-29","span":[7,12],"attrs":{"time":"10:00","grain":"time"},"score":0.75},
      {"type":"Person","text":"Alice","norm":"Alice","span":[15,20],"attrs":{},"score":0.70}
    ],
    "slots":{"date":"2025-08-30"}
  }}
]

#12 (artifact name in quotes)
INPUT items:
[
  {"id": 12, "text": "where is the file 'Roadmap_v2.xlsx'?", "opts":{"today":"2025-08-29","tz":"America/New_York"}}
]
OUTPUT:
[
  {"__id":12,"det":{
    "detector_version":"det-v2",
    "intent":{"label":"query","score":0.90},
    "entities":[
      {"type":"Artifact","text":"Roadmap_v2.xlsx","norm":"Roadmap_V2.Xlsx","span":[20,37],"attrs":{},"score":0.85}
    ],
    "slots":{}
  }}
]

#13 (numeric fact as note + quantity)
INPUT items:
[
  {"id": 13, "text": "Revenue ~1.5M USD last quarter", "opts":{"today":"2025-08-29","tz":"America/New_York"}}
]
OUTPUT:
[
  {"__id":13,"det":{
    "detector_version":"det-v2",
    "intent":{"label":"note","score":0.65},
    "entities":[
      {"type":"Quantity","text":"1.5M USD","norm":"1500000","span":[9,17],"attrs":{"value":1500000,"unit":"usd"},"score":0.78}
    ],
    "slots":{}
  }}
]

#14 (chit-chat → other)
INPUT items:
[
  {"id": 14, "text": "lol ok", "opts":{"today":"2025-08-29","tz":"America/New_York"}}
]
OUTPUT:
[
  {"__id":14,"det":{
    "detector_version":"det-v2",
    "intent":{"label":"other","score":0.90},
    "entities":[],
    "slots":{}
  }}
]

#15 (avoid preference)
INPUT items:
[
  {"id": 15, "text": "avoid spicy food", "opts":{"today":"2025-08-29","tz":"America/New_York"}}
]
OUTPUT:
[
  {"__id":15,"det":{
    "detector_version":"det-v2",
    "intent":{"label":"preference","score":0.90},
    "entities":[
      {"type":"Preference","text":"spicy food","norm":"Spicy Food","span":[6,16],"attrs":{"polarity":"avoid"},"score":0.86}
    ],
    "slots":{}
  }}
]

#16 (project mention in a query)
INPUT items:
[
  {"id": 16, "text": "status of Project Nimbus?", "opts":{"today":"2025-08-29","tz":"America/New_York"}}
]
OUTPUT:
[
  {"__id":16,"det":{
    "detector_version":"det-v2",
    "intent":{"label":"query","score":0.92},
    "entities":[
      {"type":"Project","text":"Project Nimbus","norm":"Project Nimbus","span":[10,24],"attrs":{},"score":0.75}
    ],
    "slots":{}
  }}
]

#17 (Spanish person query)
INPUT items:
[
  {"id": 17, "text": "¿Quién es Gabriel García Márquez?", "opts":{"today":"2025-08-29","tz":"America/New_York"}}
]
OUTPUT:
[
  {"__id":17,"det":{
    "detector_version":"det-v2",
    "intent":{"label":"query","score":0.95},
    "entities":[
      {"type":"Person","text":"Gabriel García Márquez","norm":"Gabriel García Márquez","span":[9,33],"attrs":{},"score":0.92}
    ],
    "slots":{}
  }}
]

SYS;
    }

    private function coerceDet(array $det): array
    {
        $label = in_array(($det['intent']['label'] ?? 'other'), self::INTENTS, true)
               ? $det['intent']['label'] : 'other';
        $score = (float)($det['intent']['score'] ?? 0.2);

        $entsIn = is_array($det['entities'] ?? null) ? $det['entities'] : [];
        $ents = [];
        foreach ($entsIn as $e) {
            if (!is_array($e)) continue;
            $t = $e['type'] ?? null;
            if (!in_array($t, self::TYPES, true)) continue;
            $sc = (float)($e['score'] ?? 0.0);
            if ($sc < $this->minEntityScore) continue;
            $text = (string)($e['text'] ?? '');
            $norm = (string)($e['norm'] ?? $text);
            $span = $e['span'] ?? [0,0];
            $attrs= is_array($e['attrs'] ?? null) ? $e['attrs'] : [];

            // keep only allowed attrs per type
            $attrs = match($t){
                'Time'       => array_intersect_key($attrs, array_flip(['iso','time','range','grain'])),
                'Quantity'   => array_intersect_key($attrs, array_flip(['value','unit'])),
                'Preference' => array_intersect_key($attrs, array_flip(['polarity'])),
                'Task'       => array_intersect_key($attrs, array_flip(['status_hint','priority_hint'])),
                default      => []
            };

            $ents[] = [
                'type'=>$t,'text'=>$text,'norm'=>$norm,
                'span'=>[(int)($span[0] ?? 0),(int)($span[1] ?? 0)],
                'attrs'=>$attrs,'score'=>$sc
            ];
        }

        $slotsIn = is_array($det['slots'] ?? null) ? $det['slots'] : [];
        $slots = [];
        foreach (self::SLOT_KEYS as $k) if (array_key_exists($k, $slotsIn)) $slots[$k] = $slotsIn[$k];

        return [
            'detector_version' => self::VERSION,
            'intent'   => ['label'=>$label,'score'=>round($score,2)],
            'entities' => $ents,
            'slots'    => $slots,
        ];
    }

    private function validDet(array $d): bool
    {
        if (!isset($d['intent']['label'])) return false;
        if (!isset($d['entities']) || !is_array($d['entities'])) return false;
        if (!isset($d['slots']) || !is_array($d['slots'])) return false;
        return true;
    }

    private function createEdges(array $msg, array $det): void
    {
        $msgId = (int)$msg['id'];
        $entities = $det['entities'];
        $slots    = $det['slots'];

        $tasks = [];
        $projects = [];
        $timesByIso = [];

        foreach ($entities as $e) {
            $type = $e['type'] ?? 'Other';
            $norm = (string)($e['norm'] ?? $e['text'] ?? '');
            if ($norm === '') continue;

            $nid = $this->resolveNodeId($norm);
            if (!$nid) continue;

            if ($type === 'Task')     $tasks[] = ['nid'=>$nid,'ent'=>$e];
            if ($type === 'Project')  $projects[] = ['nid'=>$nid,'ent'=>$e];
            if ($type === 'Time' && isset($e['attrs']['iso'])) {
                $iso = (string)$e['attrs']['iso'];
                $timesByIso[$iso] = ['nid'=>$nid,'ent'=>$e];
            }
        }

        // Task --deadline--> Time
        if (isset($slots['deadline']) && is_string($slots['deadline'])) {
            $iso = $slots['deadline'];
            if (isset($timesByIso[$iso])) {
                $timeN = $timesByIso[$iso]['nid'];
                foreach ($tasks as $t) {
                    $this->insertEdgeWithEvidence('deadline', $t['nid'], $timeN, ['iso'=>$iso], $msgId);
                }
            }
        }

        // Task --task_in_project--> Project
        foreach ($tasks as $t) {
            foreach ($projects as $p) {
                $this->insertEdgeWithEvidence('task_in_project', $t['nid'], $p['nid'], [], $msgId);
            }
        }
    }

    private function resolveNodeId(string $alias): ?string
    {
        // Prefer Repo alias lookup; adjust if your Repo exposes a different method.
        if (method_exists($this->repo, 'findAliasExact')) {
            $hit = $this->repo->findAliasExact($alias);
            return $hit['node_id'] ?? null;
        }
        return null;
    }

    private function insertEdgeWithEvidence(string $type, string $src, string $dst, array $attrs, int $msgId): void
    {
        // Adjust to actual Repo API if needed.
        $eid = method_exists($this->repo, 'newId') ? $this->repo->newId('e_') : bin2hex(random_bytes(8));
        $attrsJson = $attrs ? json_encode($attrs, JSON_UNESCAPED_UNICODE) : null;

        if (method_exists($this->repo, 'insertEdge')) {
            $this->repo->insertEdge($eid, $src, $dst, $type, 0.5, $attrsJson);
        }
        if (method_exists($this->repo, 'insertEvidence')) {
            $this->repo->insertEvidence($eid, 'edge', $msgId, null);
        }
    }

    private function loadCheckpoint(): array
    {
        // Prefer Repo config; fallback to file.
        $cfgKey = 'kg.last_id'; $ns='graph';
        if (method_exists($this->repo, 'getConfig') && method_exists($this->repo, 'setConfig')) {
            $v = (int)($this->repo->getConfig($cfgKey, $ns) ?? 0);
            return [$v, true];
        }
        $f = __DIR__ . '/checkpoint.json';
        if (is_file($f)) {
            $j = json_decode((string)@file_get_contents($f), true);
            return [ (int)($j['last_id'] ?? 0), false ];
        }
        return [0, false];
    }

    private function saveCheckpoint(int $lastId, bool $useConfig): void
    {
        if ($useConfig) {
            $this->repo->setConfig('kg.last_id', $lastId, 'graph');
            return;
        }
        $f = __DIR__ . '/checkpoint.json';
        @file_put_contents($f, json_encode(['last_id'=>$lastId], JSON_UNESCAPED_UNICODE));
    }

    private function postJson(string $url, array $payload, string $apiKey): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12000
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $raw==='') return [false, []];
        $j = json_decode($raw, true);
        if ($code >= 200 && $code < 300 && is_array($j)) return [true, $j];
        return [false, is_array($j)?$j:[]];
    }
}

(new BuildGraph())->run();
