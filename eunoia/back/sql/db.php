<?php
/**
 * Database helper for history management.
 */

declare(strict_types=1);

require_once '/var/lib/euno/sql/db-credential.php';
require_once '/var/lib/euno/sql/db-helper/tool-msg-cleaning.php';
require_once '/var/lib/euno/sql/db-helper/msg-timestamp.php';

const ROLLING_WINDOW_ROWS = 100;

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

  $id = null;
  if ($stmt->rowCount() > 0) {
    $id = (int)$pdo->lastInsertId();
  } else {
    $q = $pdo->prepare('SELECT id FROM messages WHERE session_id=? AND content_hash=? ORDER BY id DESC LIMIT 1');
    $q->execute([$sid, $h]);
    $fetched = $q->fetchColumn();
    $id = $fetched !== false ? (int)$fetched : null;
  }
  
  // trigger diary check for assistant messages only
  if ($id && $role === 'assistant' && !$is_summary) {
    check_diary_trigger($sid);
  }
  
  return $id;
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

/**
 * Fetch and prepare message history from DB.
 * 
 * @param int $max_msgs Maximum number of messages to fetch
 * @return array Messages oldest→newest with '_ts' field for timestamping.
 *               Returns ['messages' => array, 'was_trimmed' => bool]
 */
function fetch_and_prepare_history(int $max_msgs): array {
  $rows = fetch_last_messages('', $max_msgs);  // oldest→newest
  $was_trimmed = (count($rows) === $max_msgs);
  
  $window = [];
  for ($i = count($rows) - 1; $i >= 0; $i--) {  // walk backwards (newest first)
    $row = $rows[$i];
    $m = ['role'=>$row['role'], 'content'=>$row['content'], '_ts'=>$row['ts'] ?? null];
    add_tool_call_id($row, $m);
    add_ass_tool_call($row, $m);
    $m['content'] = filterContent($m['content']);
    $window[] = $m;  // collecting newest→older
  }
  $window = array_reverse($window);  // send oldest→newest
  
  return ['messages' => $window, 'was_trimmed' => $was_trimmed];
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

  $add_summary = false;

  // 2) fetch and prepare history
  $history_data = fetch_and_prepare_history(ROLLING_WINDOW_ROWS);
  $window = $history_data['messages'];
  $addSummary = $history_data['was_trimmed'];

  // Recompute tokens and trim head if exceeding budget
  $remain = $budget - $used;
  $tok = [];
  $sum = 0;
  foreach ($window as $idx => $m) {
    $t = rough_tokens($m['content']);
    $tok[$idx] = $t;
    $sum += $t;
  }
  apply_group_stamps($window, 5);

  $used += $sum;

  // Clean temp keys
  foreach ($window as &$m) { unset($m['_ts']); }
  $window = reconcile_assistant_tool_sequence($window);
  no_tail_tool($window);
  // error_log('san - ' . json_encode($window[count($window) - 1], JSON_PRETTY_PRINT));

  // if trimmed and a summary exists, prepend it once
  if ($add_summary && ($sum = fetch_latest_summary($sid))) {
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

function filterContent(string $text): string
    {
        $text = preg_replace('/function result:\s*(?<obj>\{(?:[^{}]++|(?&obj))*\})/u', 'function result: [result]', $text);
        // add more filters here as needed
        //$text = preg_replace('/~~~.*?~~~/s', '[cmd censored]', $text);
        
        return $text;
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

function increment_assistant_turn_counter(): int {
  $file = '/var/lib/euno/.assistant_turn_count';
  $lock = fopen($file, 'c+');
  if (!$lock) return 0;
  
  if (flock($lock, LOCK_EX)) {
    $count = (int)stream_get_contents($lock);
    $count++;
    ftruncate($lock, 0);
    rewind($lock);
    fwrite($lock, (string)$count);
    fflush($lock);
    flock($lock, LOCK_UN);
    fclose($lock);
    return $count;
  }
  
  fclose($lock);
  return 0;
}

function check_diary_trigger(string $sid): void {
  if (!should_trigger_diary(50)) return;
  
  $worker = '/var/lib/euno/lib/diary-writer.php';
  if (!is_file($worker)) return;
  
  exec('php ' . escapeshellarg($worker) . ' ' . escapeshellarg($sid) . ' > /dev/null 2>&1 &');
}

function should_trigger_diary(int $interval = 50): bool {
  $count = increment_assistant_turn_counter();
  return ($count > 0 && $count % $interval === 0);
}

function update_pinecone(): bool {
  $c = curl_init('http://127.0.0.1:5145/run');
  curl_setopt($c, CURLOPT_POST, true);
  curl_setopt($c, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
  curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $response = curl_exec($ch);
  $result = json_decode($response, true);
  curl_close($ch);
}