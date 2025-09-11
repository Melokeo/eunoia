<?php
/**
 * Database helper for history management.
 */

declare(strict_types=1);

require_once '/var/lib/euno/sql/db-credential.php';

const ROLLING_WINDOW_ROWS = 600;

function db(): PDO {
  global $db_usr, $db_pwd;
  static $pdo = null;
  if ($pdo) return $pdo;
  $pdo = new PDO(
    'mysql:host=localhost;dbname=euno;charset=utf8mb4',
    $db_usr, $db_pwd,
    [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,   
      PDO::MYSQL_ATTR_MULTI_STATEMENTS => false 
    ]
  );
  $pdo->exec("SET time_zone = '-04:00'");
  return $pdo;
}

function ulid(): string {
  $ENC = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
  $ms  = (int) floor(microtime(true) * 1000);
  $time = '';
  for ($i=0; $i<10; $i++){ $time = $ENC[$ms % 32] . $time; $ms = intdiv($ms, 32); }
  $rand = '';
  for ($i=0; $i<16; $i++) $rand .= $ENC[random_int(0,31)];
  return $time.$rand; // 26 chars
}

function ensure_session(): string {
  $sid = $_COOKIE['euno_sid'] ?? '';
  if (!preg_match('/^[0-9A-Z]{26}$/', $sid)) {
    $sid = ulid();
    db()->prepare('INSERT INTO sessions(id, started_at) VALUES(?, CURRENT_TIMESTAMP(6))')->execute([$sid]);
    setcookie('euno_sid', $sid, ['path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
  }
  return $sid;
}

function insert_message(string $sid, string $role, string $content, int $is_summary=0, ?string $model=null, ?int $tin=null, ?int $tout=null, ?string $toolCallsJson=null, ?string $toolCallId=null): void {
  $h = hash('sha256', $content . '|' . ($toolCallsJson ?? '') . '|' . ($toolCallId ?? ''), true);
  $sql = 'INSERT IGNORE INTO messages(session_id, ts, role, content, model, tokens_in, tokens_out, is_summary, content_hash, tool_call_id, tool_calls_json)
          VALUES(?, CURRENT_TIMESTAMP(6), ?, ?, ?, ?, ?, ?, ?, ?, ?)';
  db()->prepare($sql)->execute([$sid, $role, $content, $model, $tin, $tout, $is_summary, $h, $toolCallId, $toolCallsJson]);
}

function insert_message_id(string $sid, string $role, string $content, int $is_summary=0, ?string $model=null, ?int $tin=null, ?int $tout=null, ?string $toolCallsJson=null, ?string $toolCallId=null): ?int {
  $pdo = db();
  $h = hash('sha256', $content . '|' . ($toolCallsJson ?? '') . '|' . ($toolCallId ?? ''), true);
  $sql = 'INSERT IGNORE INTO messages(session_id, ts, role, content, model, tokens_in, tokens_out, is_summary, content_hash, tool_call_id, tool_calls_json)
          VALUES(?, CURRENT_TIMESTAMP(6), ?, ?, ?, ?, ?, ?, ?, ?, ?)';
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$sid, $role, $content, $model, $tin, $tout, $is_summary, $h, $toolCallId, $toolCallsJson]);

  if ($stmt->rowCount() > 0) {
    return (int)$pdo->lastInsertId();                // new row
  }
  // ignored (duplicate) → fetch existing by unique content_hash within session
  $q = $pdo->prepare('SELECT id FROM messages WHERE session_id=? AND content_hash=? ORDER BY id DESC LIMIT 1');
  $q->execute([$sid, $h]);
  $id = $q->fetchColumn();
  return $id !== false ? (int)$id : null;
}

function fetch_last_messages(string $sid, int $limit=200): array {
  $limit = max(1, min(1000, (int)$limit)); // clamp; avoid placeholder in LIMIT  AND session_id=?
  $sql = 'SELECT id, role, content, ts, tool_call_id, tool_calls_json
          FROM messages
          WHERE `role` in ("user", "tool", "assistant") 
          ORDER BY id DESC
          LIMIT '.$limit;

  $st = db()->prepare($sql);
  $st->execute(); //[$sid]
  $rows = $st->fetchAll();

  // Oldest → newest for the model/UI
  return array_reverse($rows);
}


/* ---- token budgeting ---- */

function rough_tokens(string $s): int {
  // crude but fast: CJK dense, Latin sparse
  $cjk = preg_match_all('/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', $s);
  $latin = strlen($s) - $cjk*3;
  return (int) ceil($cjk + max(0,$latin)/4);
}

function pack_messages_for_model(string $sid, array $system_stack, int $max_ctx=8192, int $reserve=1024): array {
  $budget = $max_ctx - $reserve;
  $out = [];
  $used = 0;

  // 1) system stack first (keep order)
  foreach ($system_stack as $m) {
    $t = rough_tokens($m['content'] ?? '');
    if ($used + $t > $budget) break;
    $out[] = $m; $used += $t;
  }

  $addSummary = false;

  // 2) DB rows are oldest→newest; take the most recent suffix that fits
  $rows = fetch_last_messages($sid, ROLLING_WINDOW_ROWS);      // oldest→newest
  $addSummary = (count($rows) === ROLLING_WINDOW_ROWS);  // trimmed → true

  $window = [];
  for ($i = count($rows) - 1; $i >= 0; $i--) {  // walk backwards (newest first)
    $row = $rows[$i];
    $m = ['role'=>$row['role'], 'content'=>$row['content']];
    add_tool_call_id($row, $m);
    add_ass_tool_call($row, $m);

    $t = rough_tokens($m['content']);
    if ($used + $t > $budget) break;            // stop when adding would exceed budget
    $window[] = $m;                              // collecting newest→older
    $used += $t;
  }
  $window = array_reverse($window);             // send oldest→newest
  $window = reconcile_assistant_tool_sequence($window);
  no_tail_tool($window);
  // error_log('san - ' . json_encode($window[count($window) - 1], JSON_PRETTY_PRINT));

  // if trimmed and a summary exists, prepend it once
  if ($addSummary && ($sum = fetch_latest_summary($sid))) {
  $t = rough_tokens($sum['content'] ?? '');
  if ($used + $t <= $budget) {
      $out[] = ['role'=>$sum['role'], 'content'=>$sum['content']];
      $used += $t;
  }
  }
  
  $out = array_merge($out, $window);
  return $out;
}

function add_tool_call_id(array $row, array &$m): void {
  $tcid = $row['tool_call_id'] ?? '';
  if ($row['role'] !== 'tool') { return; }
  if ($tcid === '') { error_log("[MsgPack] Missing tool_call_id for tool: ".$row['content']); return; }
  $m['tool_call_id'] = $tcid;
}

function add_ass_tool_call(array $row, array &$m): void{
  if ($row['role'] !== 'assistant' || empty($row['tool_calls_json'])) { return; }
  $tc = json_decode((string)$row['tool_calls_json'], true);
  if (json_last_error() === JSON_ERROR_NONE && is_array($tc) && $tc) {
    $m['tool_calls'] = $tc;
  }
}

/* summary related */
function fetch_latest_summary(string $sid): ?array {
  $st = db()->prepare('SELECT role, content FROM messages
                       WHERE session_id=? AND is_summary=1
                       ORDER BY id DESC LIMIT 1');
  $st->execute([$sid]);
  $r = $st->fetch();
  return $r ?: null;
}

function need_summary(string $sid, int $windowRows=ROLLING_WINDOW_ROWS, int $minGapTurns=40, int $minGapMinutes=30): bool {
  // window is capped?
  $st = db()->prepare('SELECT COUNT(*) c FROM messages WHERE session_id=?');
  $st->execute([$sid]);
  $total = (int)$st->fetchColumn();
  if ($total < $windowRows) return false;

  // recent summary?
  $st = db()->prepare(
    'SELECT id, ts FROM messages WHERE session_id=? AND is_summary=1 ORDER BY id DESC LIMIT 1'
  );
  $st->execute([$sid]);
  $row = $st->fetch();
  if ($row) {
    $st2 = db()->prepare('SELECT TIMESTAMPDIFF(MINUTE, ?, NOW())');
    $st2->execute([$row['ts']]);
    $mins = (int)$st2->fetchColumn();
    if ($mins < $minGapMinutes) return false;
  }

  // enough new turns since last summary?
  $st = db()->prepare('SELECT COUNT(*) FROM messages WHERE session_id=? AND is_summary=0 ORDER BY id DESC');
  $st->execute([$sid]);
  $nonSum = (int)$st->fetchColumn();
  return $nonSum >= $minGapTurns;
}

function create_session_summary(string $sid, callable $openai): ?string {
  // Pull last summary (optional)
  $st = db()->prepare('SELECT content FROM messages WHERE session_id=? AND is_summary=1 ORDER BY id DESC LIMIT 1');
  $st->execute([$sid]);
  $prevSum = $st->fetchColumn() ?: null;

  // Build context: older tail + recent window
  // Older tail: last 400 rows total; recent window already ~ROLLING_WINDOW_ROWS
  $st = db()->prepare("SELECT role, content FROM (
                         SELECT id, role, content
                         FROM messages
                         WHERE session_id=?
                         ORDER BY id DESC
                         LIMIT 400
                       ) t ORDER BY id ASC");
  $st->execute([$sid]);
  $rows = $st->fetchAll();

  $ctx = [];
  if ($prevSum) { $ctx[] = ['role'=>'system','content'=>"Previous summary:\n".$prevSum]; }
  // Take only non-system ephemerals and real turns for summarization
  foreach ($rows as $r) {
    $role = $r['role'];
    $c = (string)$r['content'];
    if ($role === 'system') continue; // persona/memories/time not needed for summary text
    $ctx[] = ['role'=>$role, 'content'=>$c];
  }

  // Summarizer instruction (single system message)
  $sys = ['role'=>'system', 'content' =>
    "Summarize the session so far into 10–14 compact bullet points:\n".
    "- preserve facts, decisions, names\n".
    "- include active tasks and open questions\n".
    "- omit greetings and routing chatter\n".
    "- be neutral and terse"
  ];

  // Call model via provided callable (keeps your call_openai intact)
  $msgs = array_merge([$sys], $ctx);
  $ans = $openai($msgs, 640, 0.2);                // returns string or null
  if (!$ans) return null;

  // Store as assistant + is_summary=1
  insert_message($sid, 'assistant', $ans, 1, OPENAI_MODEL, null, null);
  return $ans;
}

function summarize_via(callable $fnCallOpenAI, array $messages, int $maxTokens, float $temp): ?string {
  return $fnCallOpenAI($messages, $maxTokens, $temp);
}

/**
 * Ensure assistant tool_calls are followed by matching tool entries.
 * - If assistant has a call with no tool return => inject synthetic tool with empty content.
 * - If a tool has no preceding assistant call => drop it.
 * Input order: oldest → newest.
 */
/**
 * Validate and repair assistant↔tool sequencing.
 * Invariants enforced:
 *  - For every assistant message with tool_calls_json = [{id:...}, ...],
 *    there are exactly N following tool messages matched in order by tool_call_id.
 *  - Stray tool messages (no pending assistant or mismatched id) are dropped.
 *  - If a matching tool message lacks tool_call_id, it is filled from the pending id.
 *  - If tools are missing when a non-tool message arrives, synthetic empty tool
 *    messages are inserted to satisfy the pending ids.
 *
 * Input:  $msgs oldest→newest, each item: ['role','content','tool_call_id','tool_calls_json', ...]
 * Output: repaired list oldest→newest.
 */
function reconcile_assistant_tool_sequence(array $msgs): array {
    $out = [];
    $pending = [];                 // queue of ['id'=>string, 'need'=>bool] from the last assistant
    $havePending = false;          // true iff pending belongs to the last assistant in $out

    $flush_pending = function() use (&$out, &$pending, &$havePending) {
        if ($havePending && $pending) {
            // Insert synthetic empty tool messages for any unmet tool calls
            foreach ($pending as $p) {
                if ($p['need']) {
                    $out[] = [
                        'role' => 'tool',
                        'content' => $p['placeholder'],
                        'tool_call_id' => $p['id'],
                    ];
                }
            }
        }
        $pending = [];
        $havePending = false;
    };

    foreach ($msgs as $m) {
        $role = $m['role'] ?? '';

        if ($role === 'assistant') {
            // New assistant: close out any prior pending first
            $flush_pending();

            // Parse tool_calls_json
            $pending = [];
            $havePending = true;
            $tc = [];
            if (!empty($m['tool_calls'])) {
                //$tc = json_decode((string)$m['tool_calls'], true);
                $tc = $m['tool_calls'];
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($tc)) {
                    $tc = [];
                }
                $l = count($tc);
                $t = prettyTrimmedJson($tc);
                //error_log("tc det: len $l \n$t");
            }
            // Build ordered queue from assistant-declared calls
            foreach ($tc as $call) {
                $id = is_array($call) ? ($call['id'] ?? null) : null;
                if (is_string($id) && $id !== '') {
                    $pending[] = ['id' => $id, 'need' => true, 'placeholder' => is_really_no_response($call)? 'void function' : 'PLACEHOLDERx'];
                }
            }

            if (empty($tc)) { $havePending = false; } else {
              $e = prettyTrimmedJson($pending);
              //error_log("pending = $e");
            }

            // Keep the assistant as-is
            $out[] = $m;
            continue;
        }

        if ($role === 'tool') {
            if (!$havePending || !$pending) {
                // No assistant expecting tools → drop stray tool
                $t = prettyTrimmedJson($m);
                //rror_log("Dropped tool:\n$t");
                continue;
            }
            // Match against the head of the queue
            $expected = $pending[0]['id'];
            $tid = $m['tool_call_id'] ?? null;

            if (!is_string($tid) || $tid === '') {
                // Fill missing id with expected
                $m['tool_call_id'] = $expected;
                $tid = $expected;
            }

            if ($tid === $expected) {
                // Consume one pending slot
                $pending[0]['need'] = false;
                array_shift($pending);
                $out[] = $m;
                // If all matched, clear the pending flag
                if (!$pending) $havePending = false;
            } else {
                // Mismatched id → drop as stray
                continue;
            }
            continue;
        }

        // Any other role breaks the pending chain: flush unmet tool calls, then pass through
        $flush_pending();
        $out[] = $m;
    }

    // End: flush any unmet tools
    $flush_pending();
    return $out;
}

/* returns true if a function call normally lack tool msg */
function is_really_no_response(array $tool_call): bool {
  return false;
  
  $fname = $tool_call['function']['name'];
  if (!isset($tool_call['function']['name'])) return false;
  if ($fname === 'memory' || $fname === 'debug') return true;
  return false;
}

function no_tail_tool(array &$msgs): void {
  if (empty($msgs)) return;
  $last = $msgs[count($msgs) - 1];
  // error_log(json_encode($last, JSON_PRETTY_PRINT));
  if ($last['role'] !== 'assistant') return;
  if (array_key_exists('tool_calls', $last)) {
    $last['tool_calls'] = null;
    //error_log($last['tool_calls']);
  } else {
    // error_log('tool_calls: ok');
  }

  $msgs[count($msgs) - 1] =  $last;
}

function prettyTrimmedJson(array $data, int $maxLen = 50): string {
    $trimFn = function (&$item) use ($maxLen, &$trimFn) {
        if (is_string($item)) {
            if (mb_strlen($item) > $maxLen) {
                $item = mb_substr($item, 0, $maxLen);
            }
        } elseif (is_array($item)) {
            foreach ($item as &$v) {
                $trimFn($v);
            }
        }
    };

    $trimFn($data);

    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// if call is openai, tool_calls won't be correctly parsed, so just get rid of them.
function discard_all_tools(array &$msgs): void {
  foreach ($msgs as $i => $m) {
    if ($m['role'] === 'tool') {
      unset($msgs[$i]);
    } else{
      if (array_key_exists('tool_calls', $m)) {
        unset($msgs[$i]['tool_calls']);
      }
    }
  }
}