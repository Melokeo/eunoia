<?php
declare(strict_types=1);

/**
 * History mechanism tester (no external input).
 * - Generates a fresh session id for each run
 * - Simulates user → assistant(tool_calls) → tool → assistant cycles
 * - Uses the same DB insert/skip logic style as index.php (adapted for tool calls)
 * - No API call; assistant answers are synthetic
 * - Displays per-turn traces, the JS history, and the DB history (including tool_call_id/tool_calls_json)
 */

require '/var/lib/euno/sql/db.php';
require '/var/lib/euno/graph-memory/GraphMemoryBridge.php';
require_once '/var/lib/euno/lib/create-context.php';
const OPENAI_MODEL = '<TEST>';

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

/* ---------- fresh logical session (no cookie dependency) ---------- */
$sid = ulid();
db()->prepare('INSERT INTO sessions(id, started_at) VALUES(?, CURRENT_TIMESTAMP(6))')->execute([$sid]);

/* ---------- synthetic JS history and turns ---------- */
$js = [];                // in-page history the frontend would keep
$turns = [];

/*
 Turn 1:
   user U1
   assistant A1 with a DeepSeek tool call (action)
   router/tool result arrives as role:tool with matching tool_call_id
   client nudges a follow-up user (should be skipped once on next turn)
*/
$turns[] = [
  'label' => 'T1',
  'pre_js_append' => [
    ['role'=>'user', 'content'=>'U1: hello']
  ],
  'assistant' => "A1: calling action",
  'assistant_tool_calls' => [[
    'id' => 'call_1',
    'type' => 'function',
    'function' => [
      'name' => 'action',
      'arguments' => '{"intent":"create_task","args":{"name":"TestTask","date":"2099-12-31"}}'
    ]
  ]],
  'post_js_append' => [
    ['role'=>'tool','tool_call_id'=>'call_1','content'=>'{"tag":"action","intent":"create_task","status":"inserted"}'],
    ['role'=>'user','content'=>'Continue your response with the last router result']
  ]
];

/*
 Turn 2:
   real next user U2; router-nudged user from end of T1 will be skipped once
   assistant A2 follows up
*/
$turns[] = [
  'label' => 'T2',
  'pre_js_append' => [
    ['role'=>'user','content'=>'U2: after router trace']
  ],
  'assistant' => "A2: follow-up using tool result"
];

/*
 Turn 3:
   user U3; assistant duplicates A1 body to trigger dedupe/attach behavior
*/
$turns[] = [
  'label' => 'T3',
  'pre_js_append' => [
    ['role'=>'user','content'=>'U3: test duplicate assistant']
  ],
  'assistant' => "A1: calling action"  // duplicate of T1 assistant content
];

/*
 Turn 4:
   inject client-side ephemerals to show skip_ephemeral behavior
   no assistant body
*/
$turns[] = [
  'label' => 'T4',
  'pre_js_append' => [
    ['role'=>'system','content'=>'task-context: manual debug policy'],
    ['role'=>'system','content'=>'Current time is: 1/1/2099, 1:23:45 PM'],
    ['role'=>'system','content'=>'memories: alpha | beta']
  ],
  'assistant' => ""
];

/* ---------- run turns (persistence + traces) ---------- */
$perTurn = [];
foreach ($turns as $t) {
  $label = $t['label'];

  // 0) extend JS history before POST
  foreach ($t['pre_js_append'] ?? [] as $m) { $js[] = $m; }

  // 1) server builds context (no API)
  $context = create_context($sid, $js);       // keep original behavior
  $outgoing = $context['messages'] ?? [];
  $__skip_user_contents = $context['skip_user_contents'] ?? [];
  $__pre_user_id = $context['pre_user_id'] ?? null;
  $__detector = $context['detector'] ?? [];

  // 2) persist loop (tool-aware)
  $persist_trace = [];
  $skip_next_user_once = false;

  foreach ($js as $idx => $m) {
    $role    = (string)($m['role'] ?? '');
    $content = (string)($m['content'] ?? '');
    if ($content === '') { $persist_trace[] = ['i'=>$idx,'role'=>$role,'op'=>'skip_empty']; continue; }

    // server router trace (if ever present)
    if ($role === 'system' && strncmp($content, 'router result: ', 15) === 0) {
      insert_message($sid, 'system', $content);
      $persist_trace[] = ['i'=>$idx,'role'=>$role,'op'=>'insert_router_trace'];
      $skip_next_user_once = true;
      continue;
    }

    // tool message with required tool_call_id
    if ($role === 'tool') {
      $tcid = isset($m['tool_call_id']) ? (string)$m['tool_call_id'] : '';
      insert_message($sid, 'tool', $content, 0, null, null, null, null, $tcid);
      $persist_trace[] = ['i'=>$idx,'role'=>$role,'op'=>'insert_tool','tool_call_id'=>$tcid];
      // after a tool result, the next synthetic "continue" user is treated as a nudge
      $skip_next_user_once = true;
      continue;
    }

    // router-nudged user input: skip once
    if ($role === 'user' && $skip_next_user_once) {
      $persist_trace[] = ['i'=>$idx,'role'=>$role,'op'=>'skip_router_nudge'];
      $skip_next_user_once = false;
      continue;
    }

    // tail user line dedupe consumed by memory pre-pass
    if ($role === 'user' && $__skip_user_contents) {
      $j = array_search($content, $__skip_user_contents, true);
      if ($j !== false) {
        array_splice($__skip_user_contents, $j, 1);
        if (!$__skip_user_contents) $__skip_user_contents = [];
        $persist_trace[] = ['i'=>$idx,'role'=>$role,'op'=>'tail_user_dedup_via_insert_ignore'];
        // fall through to insert
      }
    }

    // skip ephemerals
    if ($role !== 'assistant') {
      $lc = strtolower($content);
      $is_ephemeral = ($role === 'system') && (
        strpos($lc, 'task-context:') === 0 ||
        strpos($lc, '```task context policy:') === 0 ||
        strpos($lc, 'memories:') === 0 ||
        strpos($lc, 'current time is:') === 0 ||
        strncmp($content, '[Memory', 7) === 0 ||
        preg_match('/^(\d{1,2}\/\d{1,2}\/\d{2,4}|\d{4}-\d{2}-\d{2})/i', $content)
      );
      if ($is_ephemeral) { $persist_trace[] = ['i'=>$idx,'role'=>$role,'op'=>'skip_ephemeral']; continue; }
      insert_message($sid, $role, $content);
      $persist_trace[] = ['i'=>$idx,'role'=>$role,'op'=>'insert'];
    }
  }

  // 3) assistant reply insertion after loop (persist tool_calls_json when present)
  $assistant_msg_id = null;
  $ans = (string)($t['assistant'] ?? '');
  $assistant_tool_calls = is_array($t['assistant_tool_calls'] ?? null) ? $t['assistant_tool_calls'] : [];
  $toolCallsJson = $assistant_tool_calls ? json_encode($assistant_tool_calls, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;

  if ($ans !== '' || $toolCallsJson) {
    $assistant_msg_id = insert_message_id($sid, 'assistant', $ans, 0, OPENAI_MODEL, null, null, $toolCallsJson, null);
    $persist_trace[] = ['i'=>'assistant','role'=>'assistant','op'=>(is_int($assistant_msg_id)?'insert_or_attach':'no_id'),'tool_calls'=>($assistant_tool_calls?count($assistant_tool_calls):0)];
  }

  // 4) memory bookkeeping calls
  GraphMemoryBridge::logTurn($sid, $__pre_user_id, $assistant_msg_id, $__detector);
  if ($assistant_msg_id && $ans !== '') {
    GraphMemoryBridge::processAssistantMemory($sid, $assistant_msg_id, $ans);
  }

  // 5) append assistant to JS history for the next POST, plus any post-js appends
  if ($ans !== '') {
    $msg = ['role'=>'assistant','content'=>$ans];
    if ($assistant_tool_calls) $msg['tool_calls'] = $assistant_tool_calls;
    $js[] = $msg;
  } elseif ($assistant_tool_calls) {
    // allow assistant with empty content but tool_calls present
    $js[] = ['role'=>'assistant','content'=>'', 'tool_calls'=>$assistant_tool_calls];
  }
  foreach ($t['post_js_append'] ?? [] as $m) { $js[] = $m; }

  // 6) snapshot DB history (ASC ids) after this turn
  $st = db()->prepare('SELECT id, ts, role, content, tool_call_id, tool_calls_json FROM messages WHERE session_id=? ORDER BY id ASC');
  $st->execute([$sid]);
  $rowsAsc = $st->fetchAll();

  $perTurn[] = [
    'label' => $label,
    'outgoing' => $outgoing,
    'trace' => $persist_trace,
    'db' => $rowsAsc,
    'js' => $js,
  ];
}

/* ---------- render ---------- */
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>History tester (user→assistant(tool)→tool→assistant)</title>
<style>
body{font:13px/1.35 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background:#0b0c0f; color:#e9eef4; margin:0; padding:16px}
h1{margin:0 0 10px 0; font:700 16px system-ui}
h2{margin:20px 0 8px 0; font:700 14px system-ui}
small{color:#9aa0aa}
pre,code{white-space:pre-wrap}
pre{background:#0f1218; border:1px solid #1f2738; border-radius:8px; padding:10px}
table{width:100%; border-collapse:collapse; font-size:12px}
th,td{border:1px solid #1f2738; padding:6px 8px; vertical-align:top}
th{background:#11141a; text-align:left}
.grid{display:grid; grid-template-columns:1fr 1fr; gap:16px}
.sec{margin-bottom:18px}
.bad{color:#ff7a7a}
.ok{color:#9ee37d}
hr{border:0; border-top:1px solid #1f2738; margin:16px 0}
</style>
</head>
<body>
<h1>History tester (user→assistant(tool)→tool→assistant)</h1>
<div>Session: <?=esc($sid)?></div>
<div class="sec"><small>Simulates 4 POST turns. Shows server “outgoing” slice, per-message persistence ops, JS history echo, and DB rows in true chronological order. Includes DeepSeek tool-calls.</small></div>

<?php foreach ($perTurn as $t): ?>
  <h2><?=esc($t['label'])?></h2>
  <div class="grid">
    <div>
      <div><b>Server-computed outgoing (first 24)</b></div>
      <pre><?php
        $show = array_slice($t['outgoing'], 0, 24);
        foreach ($show as $i=>$m) {
          $tc = isset($m['tool_calls']) && is_array($m['tool_calls']) ? ' [tool_calls='.count($m['tool_calls']).']' : '';
          printf("#%02d %-9s %s%s\n", $i, $m['role']??'', mb_substr((string)($m['content']??''),0,140), $tc);
        }
      ?></pre>

      <div><b>Persistence trace</b></div>
      <pre><?php
        foreach ($t['trace'] as $tr) {
          $i = str_pad((string)$tr['i'], 3, ' ', STR_PAD_LEFT);
          $extra = '';
          if (isset($tr['tool_call_id'])) $extra .= ' tool_call_id='.$tr['tool_call_id'];
          if (isset($tr['tool_calls'])) $extra .= ' tool_calls='.$tr['tool_calls'];
          printf("i=%s role=%-9s op=%s%s\n", $i, $tr['role']??'', $tr['op']??'', $extra);
        }
      ?></pre>
    </div>

    <div>
      <div><b>JS history (echo)</b></div>
      <pre><?php
        foreach ($t['js'] as $i=>$m) {
          $tc = isset($m['tool_calls']) && is_array($m['tool_calls']) ? ' [tool_calls='.count($m['tool_calls']).']' : '';
          $tcid = isset($m['tool_call_id']) ? ' [tool_call_id='.$m['tool_call_id'].']' : '';
          printf("@%02d %-9s %s%s%s\n", $i, $m['role']??'', mb_substr((string)($m['content']??''),0,160), $tc, $tcid);
        }
      ?></pre>

      <div><b>DB history (id ASC)</b></div>
      <table>
        <thead><tr><th>#</th><th>id</th><th>ts</th><th>role</th><th>tool_call_id</th><th>tool_calls_json</th><th>content</th></tr></thead>
        <tbody>
        <?php foreach ($t['db'] as $k=>$r): ?>
          <tr>
            <td><?= $k ?></td>
            <td><?= esc($r['id']) ?></td>
            <td><?= esc($r['ts']) ?></td>
            <td><?= esc($r['role']) ?></td>
            <td><?= esc((string)($r['tool_call_id'] ?? '')) ?></td>
            <td><?= esc(mb_substr((string)($r['tool_calls_json'] ?? ''), 0, 120)) ?></td>
            <td><?= esc(mb_substr($r['content'], 0, 200)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <hr>
<?php endforeach; ?>

<div class="sec">
  <b>Key behaviors</b>
  <ul>
    <li>Assistant rows persist optional <code>tool_calls_json</code> with the exact JSON array, content may be empty.</li>
    <li>Tool rows persist <code>tool_call_id</code> that must match <code>assistant.tool_calls[].id</code>.</li>
    <li>After a tool result, the very next user line is treated as a nudge and skipped once.</li>
    <li>Client ephemerals (<code>task-context:</code>, <code>```Task context policy:</code>, <code>memories:</code>, <code>Current time is:</code>, date) are skipped.</li>
    <li>Duplicate assistant content attaches via <code>insert_message_id</code> content-hash logic.</li>
  </ul>
</div>
</body>
</html>
