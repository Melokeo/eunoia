<?php
/**
 * Database helper for history management.
 */

declare(strict_types=1);

const ROLLING_WINDOW_ROWS = 300;

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $pdo = new PDO(
    'mysql:host=localhost;dbname=euno;charset=utf8mb4',
    'euno_app','euno2263',
    [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,   
      PDO::MYSQL_ATTR_MULTI_STATEMENTS => false 
    ]
  );
  $pdo->exec("SET time_zone = '+00:00'");
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

function insert_message(string $sid, string $role, string $content, int $is_summary=0, ?string $model=null, ?int $tin=null, ?int $tout=null): void {
  $h = hash('sha256', $content, true);
  $sql = 'INSERT IGNORE INTO messages(session_id, ts, role, content, model, tokens_in, tokens_out, is_summary, content_hash)
          VALUES(?, CURRENT_TIMESTAMP(6), ?, ?, ?, ?, ?, ?, ?)';
  db()->prepare($sql)->execute([$sid, $role, $content, $model, $tin, $tout, $is_summary, $h]);
}

function fetch_last_messages(string $sid, int $limit=200): array {
  $limit = max(1, min(1000, (int)$limit)); // clamp; avoid placeholder in LIMIT
  $sql = 'SELECT id, role, content, ts
          FROM messages
          WHERE session_id=?
          ORDER BY id DESC
          LIMIT '.$limit;

  $st = db()->prepare($sql);
  $st->execute([$sid]);
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
    $m = ['role'=>$rows[$i]['role'], 'content'=>$rows[$i]['content']];
    $t = rough_tokens($m['content']);
    if ($used + $t > $budget) break;            // stop when adding would exceed budget
    $window[] = $m;                              // collecting newest→older
    $used += $t;
  }
  $window = array_reverse($window);             // send oldest→newest

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