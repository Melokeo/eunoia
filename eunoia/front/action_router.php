<?php
declare(strict_types=1);

/**
 * Parses AI fenced blocks from a model reply and performs server-side actions.
 * Supports:
 *   ~~~action { ... } ~~~      // existing task actions
 *   ~~~memory { ... } ~~~      // global memory upsert/delete
 *
 * POST application/json:
 *   { "ai_output": "…raw model text possibly with ~~~ blocks…" }
 */

require_once '/var/lib/euno/lib/task-store.php';
require_once '/var/lib/euno/lib/memory-store.php';
require_once '/var/lib/euno/lib/dida-ops.php';

header('Content-Type: application/json; charset=utf-8');

// --- allow POST with or without application/json ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']); exit;
}

// parse input: JSON first, then fallback to form field
$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (is_array($in) && isset($in['ai_output'])) {
    $text = (string)$in['ai_output'];
} else {
    $text = isset($_POST['ai_output']) ? (string)$_POST['ai_output'] : '';
}

// accept API-level tool calls
$toolCalls = [];
if (is_array($in ?? null)) {
    if (isset($in['tool_calls']) && is_array($in['tool_calls'])) {
        $toolCalls = $in['tool_calls'];
    } elseif (isset($in['assistant']['tool_calls']) && is_array($in['assistant']['tool_calls'])) {
        $toolCalls = $in['assistant']['tool_calls'];
    }
}

if ($text === '' && !$toolCalls) {
    echo json_encode(['error' => 'Empty ai_output']); exit;
}


function normalize_json_like(string $s): string {
    // replace smart quotes with ascii
    $s = str_replace(["\xE2\x80\x9C","\xE2\x80\x9D","\xE2\x80\x98","\xE2\x80\x99"], '"', $s);
    // remove trailing commas
    $s = preg_replace('/,\s*(\}|\])/', '$1', $s);
    return trim($s);
}

// -- executors --
function handle_action(array $json, TaskStore $taskStore, ?DidaOps $dida): array {
    $intent = strtolower((string)($json['intent'] ?? ''));
    $args   = is_array($json['args'] ?? null) ? $json['args'] : [];

    // helper: normalize updates to flat string lines
    $normalizeUpdates = static function($v): array {
        if ($v === null) return [];
        if (is_string($v)) { $v = [$v]; }
        $lines = array_map('strval', (array)$v);
        $lines = array_map(static fn($s)=>trim($s), $lines);
        return array_values(array_filter(array_unique($lines), static fn($s)=>$s!==''));
    };

    // helper: find first matching by name (+ optional date/start/end)
    $findMatch = static function(array $all, string $name, ?string $date, ?string $start, ?string $end): ?array {
        $n = mb_strtolower(trim($name));
        foreach ($all as $t) {
            $tn = mb_strtolower((string)($t['name'] ?? ''));
            if ($tn !== $n) continue;
            $td = (string)($t['time']['date']  ?? '');
            $ts = (string)($t['time']['start'] ?? '');
            $te = (string)($t['time']['end']   ?? '');
            if ($date  !== null && $td !== '' && $td !== (string)$date)  continue;
            if ($start !== null && $ts !== '' && $ts !== (string)$start) continue;
            if ($end   !== null && $te !== '' && $te !== (string)$end)   continue;
            return $t;
        }
        return null;
    };

    if ($intent === 'fetch_task') {
        $name = trim((string)($args['name'] ?? ''));
        $date = $args['date'] ?? null;
        $task = $taskStore->findByName($name, is_string($date)?$date:null);
        return ['tag'=>'action','intent'=>$intent,'result'=>$task,'note'=>$task?null:'not_found'];
    }

    if ($intent === 'create_task') {
        $name = trim((string)($args['name'] ?? ''));
        if ($name === '') return ['tag'=>'action','intent'=>$intent,'error'=>'missing_name'];

        // Build task payload (TaskStore will normalize)
        $task = [
            'name'       => $name,
            'importance' => isset($args['importance']) ? (string)$args['importance'] : null,
            'tags'       => isset($args['tags']) ? (array)$args['tags'] : null,
            'time'       => isset($args['time']) && is_array($args['time']) ? $args['time'] : [
                'date'  => (string)($args['date']  ?? ''),
                'start' => isset($args['start']) ? (string)$args['start'] : null,
                'end'   => isset($args['end'])   ? (string)$args['end']   : null,
            ],
            'updates'    => $normalizeUpdates($args['updates'] ?? null),
        ];

        $inserted = $taskStore->upsert($task);

        // sync to dida365
        if ($dida && $inserted) {
            $tid = $dida->create($task);
            if ($tid) {
                $task['updates'][] = "TID=$tid";
                $taskStore->upsert($task);
            }
        }

        return ['tag'=>'action','intent'=>$intent,'status'=>$inserted?'inserted':'updated','result'=>$task];
    }

    if ($intent === 'update_task') {
        $name  = trim((string)($args['name']  ?? ''));
        $date  = isset($args['date'])  ? (string)$args['date']  : null;
        $start = isset($args['start']) ? (string)$args['start'] : null;
        $end   = isset($args['end'])   ? (string)$args['end']   : null;
        if ($name === '') return ['tag'=>'action','intent'=>$intent,'error'=>'missing_name'];

        $existing = $findMatch($taskStore->listAll(), $name, $date, null, null);//, $start, $end);
        if (!$existing) return ['tag'=>'action','intent'=>$intent,'note'=>'not_found'];

        // Merge fields
        $merged = $existing;
        if (array_key_exists('importance', $args)) $merged['importance'] = (string)$args['importance'];
        if (array_key_exists('tags', $args))       $merged['tags']       = array_values(array_map('strval', (array)$args['tags']));
        // time can come as nested or flat
        $ntime = $merged['time'] ?? ['date'=>''];
        if (isset($args['time']) && is_array($args['time'])) {
            foreach (['date','start','end'] as $k) {
                if (array_key_exists($k, $args['time'])) $ntime[$k] = (string)$args['time'][$k];
            }
        }
        foreach (['date','start','end'] as $k) {
            if (array_key_exists($k, $args)) $ntime[$k] = (string)$args[$k];
        }
        $merged['time'] = $ntime;

        if (array_key_exists('updates', $args)) {
            $merged['updates'] = array_values(array_unique(array_merge(
                $existing['updates'] ?? [],
                $normalizeUpdates($args['updates'])
            )));
        }

        // sync to dida365
        if ($dida) {
            $tid = $dida->extractTid($merged);
            if (!$tid) {
                $tid = $dida->findByName($name, $existing['time'] ?? null);
            }
            
            if ($tid) {
                $dida->update($tid, $merged);
            } else {
                // no existing remote task, create new
                $newTid = $dida->create($merged);
                if ($newTid) {
                    $merged['updates'][] = "TID=$newTid";
                }
            }
        }

        $taskStore->upsert($merged);
        return ['tag'=>'action','intent'=>$intent,'status'=>'updated','result'=>$merged];
    }

    if ($intent === 'delete_task') {
        $name  = trim((string)($args['name']  ?? ''));
        $date  = isset($args['date'])  ? (string)$args['date']  : null;
        $start = isset($args['start']) ? (string)$args['start'] : null;
        $end   = isset($args['end'])   ? (string)$args['end']   : null;
        if ($name === '') return ['tag'=>'action','intent'=>$intent,'error'=>'missing_name'];

        // find task to get TID
        $task = $findMatch($taskStore->listAll(), $name, $date, null, null);
        if ($task && $dida) {
            $tid = $dida->extractTid($task);
            if (!$tid) {
                $tid = $dida->findByName($name, $task['time'] ?? null);
            }
            if ($tid) {
                $dida->delete($tid);
            }
        }

        $ok = $taskStore->delete($name, $date, $start, $end);
        return ['tag'=>'action','intent'=>$intent,'status'=>$ok?'deleted':'not_found'];
    }

    if ($intent === 'finish_task') {
        $name  = trim((string)($args['name']  ?? ''));
        $date  = isset($args['date'])  ? (string)$args['date']  : null;
        $start = isset($args['start']) ? (string)$args['start'] : null;
        $end   = isset($args['end'])   ? (string)$args['end']   : null;
        if ($name === '') return ['tag'=>'action','intent'=>$intent,'error'=>'missing_name'];

        // find task to get TID
        $task = $findMatch($taskStore->listAll(), $name, $date, null, null);
        if ($task && $dida) {
            $tid = $dida->extractTid($task);
            if (!$tid) {
                $tid = $dida->findByName($name, $task['time'] ?? null);
            }
            if ($tid) {
                $dida->complete($tid);
            }
        }

        $finished = $taskStore->finish($name, $date, $start, $end);
        if ($finished === null) {
            return ['tag'=>'action','intent'=>$intent,'status'=>'not_found'];
        }
        return ['tag'=>'action','intent'=>$intent,'status'=>'finished','result'=>$finished];
    }

    return ['tag'=>'action','intent'=>$intent,'status'=>'not_implemented'];
}

function handle_memory_legacy(array $json, MemoryStore $mem): array {
    $op = strtolower((string)($json['op'] ?? ''));
    $fact = trim((string)($json['fact'] ?? ''));
    if ($op === 'upsert' && $fact) {
        $mem->remember($fact);
        return ['tag'=>'memory','status'=>'remembered','fact'=>$fact];
    }
    if ($op === 'delete' && $fact) {
        $mem->forget($fact);
        return ['tag'=>'memory','status'=>'forgot','fact'=>$fact];
    }
    return ['tag'=>'memory','error'=>'bad_request'];
}

function handle_memory(array $json, MemoryStore $mem): array {
    $op   = strtolower((string)($json['op'] ?? ''));
    $fact = trim((string)($json['fact'] ?? ''));
    $key  = trim((string)($json['key']  ?? 'General')) ?: 'General';

    if ($op === 'upsert' && $fact) {
        $mem->remember($fact, $key);
        return ['tag'=>'memory','status'=>'remembered','key'=>$key,'fact'=>$fact];
    }
    if ($op === 'delete' && $fact) {
        $mem->forget($fact, $key);
        return ['tag'=>'memory','status'=>'forgot','key'=>$key,'fact'=>$fact];
    }
    return ['tag'=>'memory','error'=>'bad_request'];
}

/* ======== processor ======= */

$taskStore   = new TaskStore('/var/lib/euno/memory/tasks.json');
$memoryStore = new MemoryStore('/var/lib/euno/memory/global.json');
$didaOps     = DidaOps::fromFile(); // null if unavailable
$out = ['handled'=>[], 'unhandled'=>[], 'tool_messages'=>[]];

// API-level function calls
if ($toolCalls) {
    foreach ($toolCalls as $tc) {
        $id   = (string)($tc['id'] ?? '');
        $type = strtolower((string)($tc['type'] ?? ''));
        $fn   = (string)($tc['function']['name'] ?? '');
        $argsRaw = (string)($tc['function']['arguments'] ?? '{}');

        if ($type !== 'function' || $fn === '') {
            $out['unhandled'][] = ['tag'=>'tool_call','error'=>'bad_tool_call'];
            continue;
        }

        $json = json_decode($argsRaw, true);
        if (!is_array($json)) $json = json_decode(normalize_json_like($argsRaw), true);
        if (!is_array($json)) {
            $out['unhandled'][] = ['tag'=>$fn,'error'=>'invalid_json','tool_call_id'=>$id];
            continue;
        }

        $res = null;
        switch (strtolower($fn)) {
            case 'action':   $res = handle_action($json, $taskStore, $didaOps);   break;
            case 'memory':   $res = handle_memory($json, $memoryStore); break;
            case 'interest': $res = ['tag'=>'interest','payload'=>$json,'status'=>'for_server_execute']; break;
            case 'debug':    $res = ['tag'=>'debug','payload'=>$json['op']??null,'status'=>'for_server_execute']; break;
            default:         $res = ['tag'=>$fn,'status'=>'reserved'];  break;
        }

        $out['handled'][] = $res;

        // ready-to-send tool message back to the model
        $out['tool_messages'][] = [
            'role'         => 'tool',
            'tool_call_id' => $id,
            'content'      => json_encode($res, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
        ];
    }

    // If invoked solely for tool calls, short-circuit with result
    if ($text === '') {
        echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
        exit;
    }
}

// Extract fenced blocks: ~~~<tag>\n{json}\n~~~
$matches = [];
preg_match_all('/~~~(\w+)\s*({[\s\S]*?})\s*~~~/u', $text, $matches, PREG_SET_ORDER);


if (!$matches) {
    echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    exit;
}

foreach ($matches as $m) {
    $tag = strtolower($m[1] ?? '');
    $raw = $m[2] ?? '';
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        $json = json_decode(normalize_json_like($raw), true);
    }
    if (!is_array($json)) {
        $out['unhandled'][] = ['tag'=>$tag,'error'=>'invalid_json'];
        continue;
    }

    switch ($tag) {
        case 'action':
            $out['handled'][] = handle_action($json, $taskStore, $didaOps);
            break;
        case 'memory':
            $out['handled'][] = handle_memory($json, $memoryStore);
            break;
        case 'interest':
            // Router only records trace; interest follow-up handled client side
            $out['handled'][] = ['tag'=>'interest','payload'=>$json,'status'=>'for_server_execute'];
            break;
        case 'debug':
            $out['handled'][] = ['tag'=>'debug','payload'=>$json['op'],'status'=>'for_server_execute'];
            break;
        default:
            $out['unhandled'][] = ['tag'=>$tag,'status'=>'reserved'];
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);