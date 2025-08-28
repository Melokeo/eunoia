<?php
declare(strict_types=1);

/**
 * /var/www/typecho/euno/index.php
 * - Serves the chat UI (GET)
 * - Handles AJAX model calls (POST application/json)
 * - Model: fast Responses API model
 */

// const OPENAI_KEY_PATH = '/var/lib/euno/secrets/openai_key.json';
// const OPENAI_API_URL  = 'https://api.openai.com/v1/responses';
// const OPENAI_MODEL    = 'gpt-5-mini';
const OPENAI_KEY_PATH = '/var/lib/euno/secrets/deepseek_key.json';
const OPENAI_API_URL  = 'https://api.deepseek.com/chat/completions';
const OPENAI_MODEL    = 'deepseek-chat';
const MEM_ROOT        = '/var/lib/euno/memory';

// Load system prompt (server-side only)
$SYSTEM_PROMPT = '';
$sysPath = '/var/lib/euno/memory/system-prompt.php';
if (is_file($sysPath) && is_readable($sysPath)) {
    $ret = require $sysPath;              // file must `return "..."`;
    if (is_string($ret)) { $SYSTEM_PROMPT = $ret; }
}

require '/var/lib/euno/sql/db.php';
ensure_session(); // from db.php

// require_once __DIR__ . '/../vendor/autoload.php';

/* ---------- server: AJAX endpoint ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
  && isset($_SERVER['CONTENT_TYPE'])
  && stripos($_SERVER['CONTENT_TYPE'], 'application/json') === 0) {

  header('Content-Type: application/json; charset=utf-8');

  // parse input
  $in = json_decode(file_get_contents('php://input'), true);
  $client_messages = $in['messages'] ?? null;
  if (!is_array($client_messages) || !$client_messages) {
      echo json_encode(['error' => 'Empty messages']); exit;
  }

  // session (cookie-backed)
  $sid = ensure_session();

  $system_stack = [];
  // euno persona
  if ($SYSTEM_PROMPT !== '') {
      $system_stack[] = ['role' => 'system', 'content' => $SYSTEM_PROMPT];
  }
  // task + memories
  $system_stack = array_merge($system_stack, extract_system_from_client($client_messages));
  // DB history + client delta
  $outgoing = pack_messages_for_model($sid, $system_stack, 8192, 1024);
  $client_delta = build_client_delta($client_messages);
  if (!empty($client_delta)) {
      $outgoing = array_merge($outgoing, $client_delta);
  }

  // --- debug tap: pre-call (readable) ---
  /*
  $rid = bin2hex(random_bytes(8));              // request id
  $DBG_PREVIEW_CHARS = 150;                     // small, readable cap
  $roleCounts = array_count_values(array_map(fn($m)=>$m['role'], $outgoing));
  $preview = array_map(function($m) use ($DBG_PREVIEW_CHARS){
    $c = (string)($m['content'] ?? '');
    return [
      'r'   => $m['role'],
      'len' => mb_strlen($c),
      'sha' => substr(sha1($c), 0, 12),
      'text'=> mb_substr($c, 0, $DBG_PREVIEW_CHARS)   // <-- readable content
    ];
  }, $outgoing);

  error_log(json_encode([
    't'=>'pre','rid'=>$rid,'sid'=>$sid,'n'=>count($outgoing),
    'roles'=>$roleCounts,'preview'=>$preview
  ], JSON_UNESCAPED_UNICODE)."\n", 3, '/var/log/euno/chat.jsonl');

  header('X-Euno-Req: '.$rid);
  */


  // ------- API -------
  $apiKey = load_key();
  $resp   = call_openai($outgoing, $apiKey);
  if (isset($resp['error'])) {
      echo json_encode(['error' => $resp['error']]); exit;
  }

  $answer_raw = extract_text($resp['data']);  // may contain fences
  // --- debug tap: post-call (readable) ---
  /*
  error_log(json_encode([
    't'=>'post','rid'=>$rid,'sid'=>$sid,
    'answer_len'=>mb_strlen($answer_raw),
    'answer_sha'=>substr(sha1($answer_raw),0,12),
    'answer'=>mb_substr($answer_raw, 0, $DBG_PREVIEW_CHARS)   // <-- readable content
  ], JSON_UNESCAPED_UNICODE)."\n", 3, '/var/log/euno/chat.jsonl');
  */



  // ------- persist history (append-only, idempotent) -------
  try {
      // Skip persisting the single "nudged user" that follows a router result
      $skip_next_user_once = false;

      foreach ($client_messages as $m) {
          $role = $m['role'] ?? '';
          $content = (string)($m['content'] ?? '');
          if ($content === '') continue;

          // marker injected by frontend after routing
          //   history.push({ role:'system', content:'tool:router_result ' + JSON.stringify(router) });
          if ($role === 'system' && strncmp($content, 'tool:router_result ', 19) === 0) {
              // keep the system trace; comment next line if not desired
              insert_message($sid, 'system', $content);
              $skip_next_user_once = true;     // next user line is ephemeral nudge
              continue;
          }

          // ephemeral router-nudged user input: do NOT persist
          if ($role === 'user' && $skip_next_user_once) {
              $skip_next_user_once = false;
              continue;
          }

          // normal persistence (system/user/tool only)
          if ($role !== 'assistant') {
              $lc = strtolower($content);
              // skip ephemerals: task/memories/time system lines
              if ($role === 'system' && (
                  strpos($lc, 'task-context:') === 0 ||
                  strpos($lc, '```task context policy:') === 0 ||
                  strpos($lc, 'memories:') === 0 ||
                  strpos($lc, 'current time is:') === 0 ||
                  preg_match('/^(\d{1,2}\/\d{1,2}\/\d{2,4}|\d{4}-\d{2}-\d{2})/i', $content)
              )) {
                  continue;
              }
              insert_message($sid, $role, $content);
          }
      }

      // assistant reply (RAW)
      if ($answer_raw !== '') {
          insert_message($sid, 'assistant', $answer_raw, 0, OPENAI_MODEL, null, null);
      }
  } catch (Throwable $e) {
      error_log('[history] persist failed: '.$e->getMessage());
  }

  echo json_encode(['answer' => $answer_raw], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  try { // summary mechanism
    if (need_summary($sid)) {
      // adapter: reuse your call_openai but with custom limits
      $sumCall = function(array $messages, int $maxTokens, float $temp) use ($apiKey) : ?string {
        // clone your call_openai but override tokens/temp
        $payload = [
          'model'      => OPENAI_MODEL,
          'messages'   => $messages,
          'max_tokens' => $maxTokens,
          'temperature'=> $temp,
        ];
        $ch = curl_init(OPENAI_API_URL);
        curl_setopt_array($ch, [
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_POST           => true,
          CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
          ],
          CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
          CURLOPT_TIMEOUT        => 25,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $json = json_decode((string)$raw, true);
        if (!is_array($json)) return null;
        $msg = $json['choices'][0]['message']['content'] ?? '';
        return is_string($msg) && $msg !== '' ? $msg : null;
      };

      create_session_summary($sid, function(array $m, int $k, float $t) use ($sumCall) {
        return $sumCall($m, $k, $t);
      });
    }
  } catch (Throwable $e) {
    error_log('[summary] failed: '.$e->getMessage());
  }


  exit;
}


function extract_system_from_client(array $msgs): array {
    $out = [];
    $seen_task = false; $seen_mem = false; $seen_date = false;

    foreach ($msgs as $m) {
        if (($m['role'] ?? '') !== 'system') continue;
        $c = (string)($m['content'] ?? '');

        // keep first "task-context: ..."
        if (!$seen_task && stripos($c, '```Task context policy:') === 0) {
            $out[] = ['role' => 'system', 'content' => $c];
            $seen_task = true; 
            continue;
        }
        // keep first "memories: ..."
        if (!$seen_mem && stripos($c, 'memories:') === 0) {
            $out[] = ['role' => 'system', 'content' => $c];
            $seen_mem = true; 
            continue;
        }
        // keep one timestamp line (the date the client injected)
        // relaxed check: starts with digits and has separators
        if (!$seen_date && preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}|\d{4}-\d{2}-\d{2}/', $c)) {
            $out[] = ['role' => 'system', 'content' => $c];
            $seen_date = true;
            continue;
        }
    }
    return $out;    // order preserved as they appeared in client payload
}

function build_client_delta(array $msgs): array {
    // take only lines AFTER the last assistant in the client history
    $lastA = -1;
    for ($i = count($msgs) - 1; $i >= 0; $i--) {
        if (($msgs[$i]['role'] ?? '') === 'assistant') { $lastA = $i; break; }
    }
    $tail = array_slice($msgs, $lastA + 1); // could be empty; preserves original order

    $delta = [];
    foreach ($tail as $m) {
        $role = $m['role'] ?? '';
        if ($role === 'assistant') continue;                  // safety
        $c = (string)($m['content'] ?? '');
        if ($c === '') continue;

        // drop ephemerals already handled via system_stack
        if ($role === 'system') {
            $lc = strtolower($c);
            if (strpos($lc, 'task-context:') === 0) continue;
            if (strpos($lc, '```task context policy:') === 0) continue;
            if (strpos($lc, 'memories:') === 0) continue;
            if (strpos($lc, 'current time is:') === 0) continue;
            if (preg_match('/^(\d{1,2}\/\d{1,2}\/\d{2,4}|\d{4}-\d{2}-\d{2})/i', $c)) continue;
        }

        $delta[] = ['role'=>$role, 'content'=>$c];
    }
    return $delta;  // may include multiple consecutive user/tool lines
}


/* ---------- helpers ---------- */
function load_key(): string {
    $data = json_decode(@file_get_contents(OPENAI_KEY_PATH), true);
    if (!is_array($data) || empty($data['api_key'])) {
        http_response_code(500);
        exit(json_encode(['error' => 'OpenAI key missing'], JSON_UNESCAPED_UNICODE));
    }
    return $data['api_key'];
}

function call_openai(array $messages, string $apiKey): array {
    $payload = [
        'model' => OPENAI_MODEL,
        'input' => $messages,
        'max_output_tokens' => 2048,
        'text' => [ 'verbosity' => 'low' ],
        'reasoning' => [ 'effort' => 'low' ], // adapted 202508
    ];
    // ds
    $payload = [
        'model'      => 'deepseek-chat',        
        'messages'   => $messages,          
        'max_tokens' => 2048,
        'temperature'=> 0.85,                
        // 'stream'   => true,               // optional: if streaming will be added later
    ];
    $ch = curl_init(OPENAI_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['error' => 'cURL error: ' . $err];
    }
    curl_close($ch);

    $json = json_decode($raw, true);
    if ($code >= 400 || !is_array($json)) {
        $msg = is_array($json) && isset($json['error']['message']) ? $json['error']['message'] : ('HTTP ' . $code);
        error_log('[deepseek] raw=' . substr($raw, 0, 2000) . $msg); 
        return ['error' => $msg];
    }
    // error_log('[deepseek] raw=' . substr($raw, 0, 2000)); 
    return ['data' => $json];
}

function extract_text(array $resp): string {
  /* // BELOW IS FOR CHATGPT
    if (isset($resp['output_text']) && is_string($resp['output_text'])) return sanitize_punct($resp['output_text']);
    if (isset($resp['output']) && is_array($resp['output'])) {
        $buf = [];
        foreach ($resp['output'] as $part) {
            if (isset($part['content'][0]['text'])) { $buf[] = $part['content'][0]['text']; }
            elseif (isset($part['text'])) { $buf[] = $part['text']; }
        }
        if ($buf) return sanitize_punct(implode("\n", $buf));
    }
    return sanitize_punct(json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  */
  //BELOW IS FOR DEEPSUCK
  $msg = $resp['choices'][0]['message'] ?? [];
  if (!empty($msg['content'])) {
    //error_log('[deepseek] msg=' . $msg['content']);
    return (string)$msg['content'];
  }
  if (!empty($msg['reasoning_content'])) return (string)$msg['reasoning_content'];

  //error_log('[deepseek] msg=' . $msg['content'] . '. Parsed nothing!');
  return '';
}

// forbid —, –, and -- in final output.
function sanitize_punct(string $s): string {
    // Replace any em/en dash or double hyphen (with surrounding spaces) by a comma+space
    $s = preg_replace('/\h*(?:—|–|--)\h*/u', ', ', $s);
    // Collapse duplicate commas/spaces
    $s = preg_replace('/\s*,\s*,+/', ', ', $s);
    $s = preg_replace('/\s{2,}/', ' ', $s);
    return trim($s);
}

// Load system prompt content for embedding into the page
$SYSTEM_PROMPT = '';
$sysPath = '/var/lib/euno/memory/system-prompt.php';
if (is_file($sysPath) && is_readable($sysPath)) {
    /** @var string $SYSTEM_PROMPT */
    $SYSTEM_PROMPT = (string) (require $sysPath);
}

?>

<!doctype html>
<html lang="en" data-debug="off">
<head>
<meta charset="utf-8">
<title>M.ICU - Eunoia</title>
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover,interactive-widget=resizes-content">
<style>
:root { --bg:#0b0c0f; --panel:#11141a; --muted:#9aa0aa; --fg:#e9eef4; --accent:#5aa1ff; --border:#1c2230; }
*{box-sizing:border-box}
/* lock the page; only .chat scrolls */
html, body { height: 100%; overflow: hidden; overscroll-behavior: none; overflow-anchor: none; }
/* avoid seeing “no background” outside the body during rubber-banding */
html { background: #0b0c0f; }

/* pin the app to the visual viewport and follow its top offset */
/* replace the existing .wrap style */
.wrap { 
  position: fixed; 
  inset: 0; 
  /*height: var(--app-h, 100svh);*/
  height: 100svh;
}

/* give the chat space when the keyboard is open */
.chat { padding-bottom: calc(18px + var(--kb, 0px)); }

/* keep the inputbar above the home indicator */
.inputbar { padding-bottom: max(12px, env(safe-area-inset-bottom)); }

html,body{}
body{
  margin:0;
  background:linear-gradient(180deg,#0b0c0f,#0d1117);
  font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif;
  color:var(--fg);
  overflow:hidden;
}
.wrap{
  max-width:860px;
  margin:0 auto;
  padding:0 14px;
  /*height:var(--app-h, 100dvh);*/
  height: 100svh;
  display:flex;
}
.card{
  background:var(--panel);
  border:1px solid var(--border);
  border-radius:16px;
  box-shadow:0 10px 30px rgba(0,0,0,.25);

  display:grid;
  grid-template-rows:auto auto 1fr auto auto;
  height:100%;
  width:100%;
  overflow:hidden;
}
header{
  padding:16px 18px;
  border-bottom:1px solid var(--border);
  display:flex;
  justify-content:space-between;
  align-items:center
}
h1{margin:0;font-size:16px;letter-spacing:.2px}
.meta{color:var(--muted);font-size:12px}
.chat{
  overflow:auto;
  padding:18px;
  display:flex;
  flex-direction:column;
  gap:12px;
  -webkit-overflow-scrolling:touch;
  overscroll-behavior:contain;
}
.msg{max-width:80%;padding:12px 14px;border-radius:12px;white-space:pre-wrap}
.user{align-self:flex-end;background:#1b2230;border:1px solid #2a3145}
.assistant{align-self:flex-start;background:#0f1218;border:1px solid #1f2738}
.sys{align-self:center;color:var(--muted);font-size:12px;line-height:1.2;margin:0;padding:0}
.inputbar{
  display:flex;
  gap:10px;
  padding:12px 16px;
  border-top:1px solid var(--border);
  background:var(--panel);
}
textarea{
  flex:1;
  min-height:68px;
  max-height:160px;
  padding:10px;
  background:#0f1218;
  border:1px solid var(--border);
  border-radius:10px;
  color:var(--fg);
  resize:vertical;
  font:inherit
}
button{
  background:var(--accent);
  color:#071120;
  border:0;
  border-radius:10px;
  padding:10px 16px;
  font:600 14px/1 system-ui;
  cursor:pointer
}
button:hover{filter:brightness(1.05)}
.small{
  font-size:12px;
  color:var(--muted);
  padding:0 16px 12px;
  background:var(--panel);
}
.pending{
  font-size:12px;
  color:var(--muted);
  display:grid;grid-template-columns:auto 1fr;align-items:start;gap:6px 16px;
  background:var(--panel);
  padding: 0px 6px;
}
.pending.on{display:block}
.pending .dot{
  display:inline-block;
  width:6px;
  height:6px;
  border-radius:50%;
  background:var(--accent);
  margin-right:6px;
  animation:blink 1s infinite
}
.pending .vars{
  margin-top:2px;
  font-size:11px;
  color:#666;
  white-space:pre-line; 
}
.pending .status{display:flex;align-items:center;gap:6px}
.pending .vars{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(140px,1fr));
  gap:4px 12px;
  font:11px/1.25 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  color:#8a94a6;
  white-space:normal;               /* was pre-line */
}
.pending .kv{display:flex;justify-content:space-between;gap:8px}
.pending .k{opacity:.75}
.pending .v{text-align:right}
@keyframes blink{0%,100%{opacity:.3}50%{opacity:1}}

/* Debug-off: hide pending contents and squeeze the bar to a slim spacer */
:root[data-debug="off"] #pending .dot,
:root[data-debug="off"] #pending #pendingText,
:root[data-debug="off"] #pending .vars {
  visibility: hidden; /* keep layout without showing content */
}
:root[data-debug="off"] #pending {
  padding: 2px 16px;     /* slimmer vertical space */
  min-height: 6px;       /* technical placeholder height */
  line-height: 0;        /* avoid accidental text height */
  border-bottom-color: transparent; /* visually unobtrusive */
  background: var(--panel);         /* consistent with card */
}
/* optional: during IME, squeeze non-essential bars to gain a bit more space */
html.ime-open #pending{ padding: 2px 16px; }
/*html.ime-open .small{ display:none; }*/
html.ime-open body {
  overflow: hidden;  
}

/* add to existing styles */
html.ime-open .chat {
  scroll-behavior: auto; /* disable smooth scrolling during keyboard */
}

/* prevent body scroll entirely when keyboard is open */
html.ime-open {
  position: fixed;
  width: 100%;
}

</style>

</head>
<body>
<div class="wrap">
  <div class="card">
    <header>
      <h1>Eunoia</h1>
      <div class="meta">Session history is in-page only</div>
    </header>

    <div id="pending" class="pending" aria-live="polite">
      <div class="status"><span class="dot"></span><span id="pendingText">Waiting…</span></div>
      <div id="pendingVars" class="vars"></div>
    </div>


    <div id="chat" class="chat">
      <!--<div class="msg sys">New session started</div>-->
    </div>

    <div class="inputbar">
      <textarea id="box" placeholder="Type a message..."></textarea>
      <button id="send">Send</button>
    </div>
    <div class="small">Key: <?=htmlspecialchars(OPENAI_KEY_PATH)?> | Endpoint: <?=htmlspecialchars(OPENAI_API_URL)?></div>
  </div>
</div>

<script>
const chat = document.getElementById('chat');
const box  = document.getElementById('box');
const btn  = document.getElementById('send');
const pendingEl = document.getElementById('pending');
const pendingTextEl = document.getElementById('pendingText');
const MAX_CHAIN = 3;
const KB_EPS = 24;

const IS_LIKELY_IOS = /iP(hone|ad|od)/i.test(navigator.userAgent) ||
                      (/Mac/i.test(navigator.userAgent) && 'ontouchend' in document);
const IS_TOUCH = ('ontouchstart' in window) || navigator.maxTouchPoints > 0;


// in-page history only (server adds hidden system persona)
const history = [];
let injectedContext = false;
let inFlight = false;

// user msg buffering
let debounceMs = 1500;
let pendingTimer = null;  
let pendingTick = null;   
let pendingETA = 0; 
let queuedCount = 0;
let lastTurnQueuedCount = 0;
let isComposing = false;  // IME (Chinese/Japanese/etc.) composition state
let isKeyboardOpen = false;
let _kbPrev = false;

let typingTimer = null;       // fires when user stops typing
let typingIdleMs = 6000;      
let typingInitMs = 1800;      
let typingETA = 0;            // for status/debug

pendingEl.classList.add('on'); 
updatePendingText();
setInterval(updatePendingText, 500); // heartbeat while idle

function rearmTypingGrace(){                       // start/refresh grace without sending
  if (pendingTimer) { clearTimeout(pendingTimer); pendingTimer = null; }
  clearPending();
  if (typingTimer) { clearTimeout(typingTimer); typingTimer = null; typingETA = 0; }
  typingETA = Date.now() + typingIdleMs;
  typingTimer = setTimeout(() => {
    typingTimer = null; typingETA = 0;
    if (!inFlight && tailRole() === 'user') scheduleTurn();
    else updatePendingText();
  }, typingIdleMs);
  updatePendingText();
}

function imeOpen(){                         // predicate for “IME/keyboard is open”
  if (isComposing) return true;             // IME composing = open
  const focused = document.activeElement === box;
  if (!focused) return false;               // only care when the textarea is focused

  const vv = window.visualViewport;
  if (vv){
    const occluded = window.innerHeight - (vv.height + vv.offsetTop);
    if (occluded > KB_EPS) return true;     // visual occlusion says keyboard is up
  }

  // Fallback: on iOS Safari, focus ≈ keyboard shown even if occlusion is 0
  return IS_LIKELY_IOS && focused;          // keeps PC behavior unchanged
}


(function(){
  const root = document.documentElement;
  const vv = window.visualViewport;

  let resizeTimeout;
  function debouncedSetAppHeight() {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(setAppHeight, 10);
  }

  function setAppHeight(){
    const wasAtBottom = Math.abs(chat.scrollHeight - chat.scrollTop - chat.clientHeight) < 2;
    
    // store current scroll position before any changes
    const prevScrollTop = chat.scrollTop;
    
    const vh   = vv ? vv.height : window.innerHeight;
    const vTop = vv ? vv.offsetTop : 0;
    root.style.setProperty('--app-h', vh + 'px');
    root.style.setProperty('--vv-top', vTop + 'px');

    const kbOpen = imeOpen();
    const wasKbOpen = root.classList.contains('ime-open');
    root.classList.toggle('ime-open', kbOpen);
    
    // only handle keyboard toggle on state change
    if (kbOpen !== wasKbOpen) {
      handleKeyboardToggle(kbOpen);
      
      // restore scroll position if keyboard just opened
      if (kbOpen && !wasKbOpen) {
        requestAnimationFrame(() => {
          chat.scrollTop = prevScrollTop;
        });
      }
    }

    // only auto-scroll to bottom if user was already there AND not during keyboard open
    if (!kbOpen && wasAtBottom) {
      chat.scrollTop = chat.scrollHeight;
    }
  }

  setAppHeight();
  vv?.addEventListener('resize', setAppHeight);
  // vv?.addEventListener('scroll', setAppHeight);
  // geometrychange isn’t everywhere; safe no-op if missing
  vv?.addEventListener?.('geometrychange', setAppHeight); // [NEW]
  window.addEventListener('orientationchange', setAppHeight);
})();


/* ---------- UI ---------- */
function debugOn(){ return document.documentElement.getAttribute('data-debug') !== 'off'; }
function sys(msg){ if (debugOn()) addBubble('sys', msg); }

function addBubble(role, text){
  const div = document.createElement('div');
  div.className = 'msg ' + (role==='user' ? 'user' : role==='assistant' ? 'assistant' : 'sys');
  div.textContent = text;
  chat.appendChild(div);
  chat.scrollTop = chat.scrollHeight;
}

sys('New session started')

function showPending(ms){
  pendingETA = Date.now() + ms;
  pendingEl.classList.add('on');
  updatePendingText();
}
function clearPending(){
  pendingETA = 0;
  if (pendingTick) { clearInterval(pendingTick); pendingTick = null; }
  pendingTextEl.textContent = 'Idle';
  updatePendingText();
}
function esc(s){return String(s).replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]))}
function setVars(obj){
  const el = document.getElementById('pendingVars');
  if (document.documentElement.getAttribute('data-debug') === 'off'){ el.innerHTML=''; return; }
  el.innerHTML = Object.entries(obj).map(([k,v]) =>
    `<div class="kv"><span class="k">${esc(k)}</span><span class="v">${esc(v)}</span></div>`
  ).join('');
}

function updatePendingText(){
  const hasTimer = !!pendingTimer;
  const hasTyping = !!typingTimer;

  if (inFlight) {
    pendingTextEl.textContent = 'Sending…';
  } else if (hasTimer) {
    const left = Math.max(0, pendingETA - Date.now());
    pendingTextEl.textContent = `Pending send in ${(left/1000).toFixed(1)}s`;
  } else if (hasTyping) {
    const tleft = Math.max(0, typingETA - Date.now());
    const mode = (box === document.activeElement
                    ? (box.value.trim() ? 'typing grace' : 'focus grace')
                    : 'typing grace');
    pendingTextEl.textContent = `${mode} ${(tleft/1000).toFixed(1)}s`;
  } else if (isComposing) {                  // [MOD] kept for clarity; also covered by imeOpen()
    pendingTextEl.textContent = 'IME composing';
  } else if (imeOpen()) {                    // [MOD] use unified predicate (was: isKeyboardOpen flag)
    pendingTextEl.textContent = 'Idle (keyboard open)';
  } else {
    const suffix = (tailRole() === 'assistant') ? ' (awaiting user)' : '';
    pendingTextEl.textContent = 'Idle' + suffix;
  }


  const varsEl = document.getElementById('pendingVars');
  if (document.documentElement.getAttribute('data-debug') !== 'off') {
    /*varsEl.textContent =
      `touch=${IS_TOUCH}\n` +
      `pendingTimer=${!!pendingTimer}\n` +
      //`pendingTick=${!!pendingTick}\n` +
      //`pendingETA=${pendingETA}\n` +
      //`queuedCount=${queuedCount}\n` +
      `lastTurnQueuedCount=${lastTurnQueuedCount}\n` +
      `inFlight=${inFlight}\n` +
      `tailRole=${tailRole()}\n` +
      `typingTimer=${!!typingTimer}\n` +
      `isComposing=${isComposing}\n` + 
      `isKeyboardOpen=${isKeyboardOpen}\n`;
      //`typingETA=${typingETA}`;*/
      setVars({
        pendingTimer: !!pendingTimer,
        lastTurnQueuedCount,
        inFlight,
        queuedCount: queuedCount - lastTurnQueuedCount,
        tailRole: tailRole(),
        typingTimer: !!typingTimer,
        isComposing,
        isKeyboardOpen,
      });

  } else {
    varsEl.textContent = '';
  }
}

/* ---------- boot: context + memories ---------- */
async function ensureTaskContextOnce(){
  if (injectedContext) return;
  try{
    const r = await fetch('tasks-context.php', { cache: 'no-store' });
    if (!r.ok) throw new Error('http '+r.status);
    const j = await r.json();
    const ctx = (j && j.context) ? String(j.context) : '';
    history.unshift({ role: 'system', content: ctx || 'task-context: empty' });
    const now = new Date();
    history.unshift({ role: 'system', content: 'Current time is: ' + now.toLocaleString() });
    injectedContext = true;
    addBubble('sys', 'Loaded task context for this session');
  }catch(e){
    injectedContext = true;
    history.unshift({ role: 'system', content: 'task-context: unavailable' });
    addBubble('sys', 'Task context unavailable');
  }
}
ensureTaskContextOnce();

async function injectMemoriesOnce(){
  if (history.some(h => h.role === 'system' && h.content.startsWith('memories:'))) return;
  try {
    const r = await fetch('memories.php', { cache: 'no-store' });
    if (!r.ok) return;
    const j = await r.json();
    if (Array.isArray(j.entries) && j.entries.length) {
      history.unshift({ role: 'system', content: 'memories: ' + j.entries.join(' | ') });
      addBubble('sys', 'Loaded Euno\'s memory');
    }
  } catch(_){
    addBubble('sys', 'Euno is in amnesia');
  }
}
injectMemoriesOnce();

/* ---------- fences: parse, render, route, interest ---------- */
function parseFencedBlocks(s){
  const re=/~~~(\w+)\s*(\{[\s\S]*?\})\s*~~~/giu, out=[]; let m;
  while((m=re.exec(s))){
    try{ out.push({tag:(m[1]||'').toLowerCase(), json:JSON.parse(m[2])}); }catch{}
  }
  return out;
}
function stripFences(s){
  return String(s||'').replace(/~~~\w+\s*\{[\s\S]*?\}\s*~~~/g,'').trim();
}
function renderVisible(s){
  return String(s||'')
    .replace(/~~~action[\s\S]*?~~~\s*/g,'[ACT]')
    .replace(/~~~memory[\s\S]*?~~~\s*/g,'[MEM]')
    .replace(/~~~interest[\s\S]*?~~~\s*/g,'[INT]')
    .trim();
}
async function routeBlocks(aiOutput){
  const hasFences=/~~~\w+[\s\S]*?~~~/m.test(aiOutput);
  if(!hasFences) return null;
  const r=await fetch('action_router.php',{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({ai_output:aiOutput}),
    credentials:'same-origin'
  });
  if(!r.ok) throw new Error('router http '+r.status);
  return await r.json(); // {handled,unhandled}
}
function extractInterest(aiOutput){
  const blocks=parseFencedBlocks(aiOutput);
  let j=(blocks.find(b=>b.tag==='interest')||{}).json||null;
  if(!j){
    for(const b of blocks){ const o=b.json||{};
      if(Array.isArray(o.topics)&&typeof o.confidence==='number'){ j=o; break; }
    }
  }
  if(!j) return null;
  const confidence=Math.max(0,Math.min(1,Number(j.confidence)));
  if(!isFinite(confidence)) return null;
  return {
    topics:Array.isArray(j.topics)?j.topics.slice(0,4):[],
    reason:typeof j.reason==='string'?j.reason:'',
    confidence
  };
}
/* ---------- traces in UI (no placeholders) ---------- */
function traceIssuedFences(aiOutput){
  const blocks = parseFencedBlocks(aiOutput);
  for(const b of blocks){
    if (b.tag==='action')  sys('Action issued');
    else if (b.tag==='memory') {
      sys('Memory update issued');
    }
    else if (b.tag==='interest') {
      //sys('Interest marker issued');
    }
    else sys('Block '+b.tag+' issued');
  }
}
function traceRouterResult(router){
  if (!router) return;
  const handled = Array.isArray(router.handled) ? router.handled : [];
  const unhandled = Array.isArray(router.unhandled) ? router.unhandled : [];
  handled.forEach(h=>{
    if (!h) return;
    if (h.tag==='action'){
      const intent = h.intent || 'action';
      const note = h.note || h.status || (h.result ? 'ok' : 'not_found');
      addBubble('sys', 'Action '+intent+': '+note);
    } else if (h.tag==='memory'){
      sys('Memory '+(h.status||'ok')+(h.fact?': '+h.fact:''));
    } else if (h.tag==='interest' || h.tag==='interest_sanitized'){
      // addBubble('sys', 'Interest logged');
    } else if (h.tag==='debug'){
      //
    } else {
      sys('Handled '+(h.tag||'block'));
    }
  });
  unhandled.forEach(u=>{
    if (!u) return;
    addBubble('sys', 'Unhandled '+(u.tag||'block')+(u.error?': '+u.error:'')); 
  });
}

/* Msg queue */
function scheduleTurn() {
  if (inFlight) return;
  if (tailRole() === 'assistant'){           // prevent arming when assistant spoke last
    clearPending();                          // keep bar visible, switch to Idle
    pendingTextEl.textContent = 'Idle (awaiting user)';
    updatePendingText();
    return;
  }
  if (imeOpen()) {
    const hasText = box.value.trim().length > 0;
    if (!hasText) {
      typingTimer = setTimeout( performModelTurn, typingInitMs);
      typingETA = Date.now() + typingInitMs;
    }
    return; // don't go to normal pending as long as ime is up
  }
  if (pendingTimer) clearTimeout(pendingTimer);
  pendingTimer = setTimeout(performModelTurn, debounceMs);
  showPending(debounceMs);
}

/* ---------- main ---------- */
async function sendMessage(){
  // enqueue-only; no network call here
  const q = box.value.trim();
  if (!q) return;
  if (typingTimer) { clearTimeout(typingTimer); typingTimer = null; typingETA = 0; }
  addBubble('user', q);
  box.value = '';
  history.push({ role: 'user', content: q });
  queuedCount++;

  // cancel any in-flight scheduled send and re-arm a new silence window
  if (pendingTimer) { clearTimeout(pendingTimer); pendingTimer = null; }
  clearPending();
  scheduleTurn();
}

async function performModelTurn() {
  if (tailRole() === 'assistant'){           // prevent accidental sends
    clearPending();
    pendingTextEl.textContent = 'Idle (awaiting user)';
    updatePendingText();
    return;
  }

  if (inFlight) return;
  inFlight = true;
  btn.disabled = true;
  clearPending();
  const turnStartQueued = queuedCount;   // snapshot to detect new msgs during turn
  if (lastTurnQueuedCount === queuedCount) {
    sys('Tried to submit API req with no new msg.');
    return;
  }
  lastTurnQueuedCount = turnStartQueued;
  if (pendingTimer) { clearTimeout(pendingTimer); pendingTimer = null; }
  if (typingTimer) { clearTimeout(typingTimer); typingTimer = null; }

  try{
    await ensureTaskContextOnce();
    if (typeof injectMemoriesOnce === 'function') await injectMemoriesOnce();

    const r = await fetch('', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ messages: history })
    });
    const j = await r.json();
    if (j.error){ addBubble('assistant', 'Error: ' + j.error); return; }

    const ans = String(j.answer ?? '');
    await handleAiOutput(ans);

  } finally {
    inFlight = false;
    btn.disabled = false;

    // If more user messages arrived during the turn, arm a new silence window
    if (queuedCount > lastTurnQueuedCount) {
      scheduleTurn();
    }
  }
}

// output handler: store raw (with fences) in history; show stripped to user
async function handleAiOutput(aiOutput, depth = 0){
  if (!aiOutput) return;
  const raw = String(aiOutput);                  // keep fences
  const visible = stripFences(raw) || '*command*';

  addBubble('assistant', visible);               // user-view
  history.push({ role: 'assistant', content: raw });  // RAW in history

  traceIssuedFences(raw);
  applyDebugOps(parseFencedBlocks(raw));

  let router = null;
  try {
    router = await routeBlocks(raw);
    traceRouterResult(router);
    if (router) {
      history.push({ role: 'system', content: 'tool:router_result ' + JSON.stringify(router) });
      history.push({ role: 'user', content: 'Continue your response with the last router result' });
    }
  } catch {
    addBubble('sys', 'Router error');
  }

  if (depth >= MAX_CHAIN) return;

  const handled = (router && Array.isArray(router.handled)) ? router.handled : [];
  const hasNonMemoryAction = handled.some(h => h && h.tag === 'action');

  if (hasNonMemoryAction){
    const r2 = await fetch('', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ messages: history })
    });
    const j2 = await r2.json();
    if (!j2.error){
      await handleAiOutput(String(j2.answer ?? ''), depth + 1);  // pass RAW
    } else {
      addBubble('sys', 'followup_error: ' + j2.error);
    }
    return;
  }

  const interest = extractInterest(raw);
  if (interest){
    const p = Math.max(0, Math.min(1, interest.confidence + 0.2));
    if (Math.random() < p){
      sys('Interest trigger p=' + p.toFixed(2) + (interest.topics.length ? (' [' + interest.topics.join(', ') + ']') : ''));
      const r3 = await fetch('', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ messages: history })
      });
      const j3 = await r3.json();
      if (!j3.error){
        await handleAiOutput(String(j3.answer ?? ''), depth + 1); // pass RAW
      } else {
        addBubble('sys', 'interest_followup_error: ' + j3.error);
      }
    }
  }
}

function applyDebugOps(blocks){
  blocks.forEach(b=>{
    if (b.tag === 'debug' && b.json && typeof b.json.op === 'string'){
      const op = b.json.op.toLowerCase();
      if (op === 'on'){
        document.documentElement.setAttribute('data-debug','on');
        addBubble('sys','Debug mode ON');
      } else if (op === 'off'){
        document.documentElement.setAttribute('data-debug','off');
        addBubble('sys','Debug mode OFF');
      }
    }
  });
}

function tailRole(){ 
  return history.length ? history[history.length-1].role : ''; 
}

/* ---------- events ---------- */

function handleKeyboardToggle(open){
  if (open === _kbPrev) return;      // only on edges
  _kbPrev = open;
  isKeyboardOpen = open;

  // Pause everything while keyboard/IME is open; resume on close
  if (open){
    if (pendingTimer) { clearTimeout(pendingTimer); pendingTimer = null; }
    if (typingTimer)  { clearTimeout(typingTimer);  typingTimer  = null; typingETA = 0; }
    clearPending();                            // keep bar; show idle
    pendingTextEl.textContent = 'Idle (keyboard open)';
    updatePendingText();
  } else {
    // keyboard closed: if a reply is pending (last is user), re-arm normal debounce
    if (!inFlight && tailRole() === 'user') scheduleTurn();
    else updatePendingText();
  }
}


btn.addEventListener('click', sendMessage);
box.addEventListener('keydown', (e)=>{
  if (e.keyCode === 229) {
    rearmTypingGrace();
    return;
  };   // IME composition in progress
  if (e.key === 'Enter' && !e.shiftKey) {  e.preventDefault(); sendMessage(); }
});
box.addEventListener('focus', () => {        
  if (imeOpen()){                            // [NEW] pause immediately if keyboard/IME up on focus
    handleKeyboardToggle(true);            
    return;                                  
  }
  if (pendingTimer) { clearTimeout(pendingTimer); pendingTimer = null; }
  clearPending();
  if (typingTimer) { clearTimeout(typingTimer); typingTimer = null; typingETA = 0; }
  typingETA = Date.now() + typingIdleMs;
  typingTimer = setTimeout(() => {
    typingTimer = null; typingETA = 0;
    if (!inFlight && tailRole() === 'user') scheduleTurn();
    else updatePendingText();
  }, typingIdleMs);
  updatePendingText();
});
box.addEventListener('input', (e) => {
  if (e.isComposing || isComposing) {
    rearmTypingGrace();
    return;
  };
  // cancel the pending model-send debounce
  if (pendingTimer) { clearTimeout(pendingTimer); pendingTimer = null; }
  clearPending();

  // (re)arm the typing-idle grace; do NOT send box content
  if (typingTimer) { clearTimeout(typingTimer); typingTimer = null; typingETA = 0; }
  const hasText = box.value.trim().length > 0;

  if (hasText) {
    typingETA = Date.now() + typingIdleMs;
    typingTimer = setTimeout(() => {
      typingTimer = null; typingETA = 0;

      // Only schedule a send if there is already a user message awaiting a reply
      // (i.e., last role is 'user', and not currently sending)
      if (!inFlight && tailRole() === 'user') {
        scheduleTurn();            // re-arm normal debounce (uses debounceMs)
      } else {
        updatePendingText();       // keep status accurate
      }
    }, typingIdleMs);
  } else {
    // user cleared box; if a user message is pending, re-arm debounce immediately
    if (!inFlight && tailRole() === 'user') scheduleTurn();
  }
});

box.addEventListener('blur', () => {
  if (typingTimer) { clearTimeout(typingTimer); typingTimer = null; typingETA = 0; updatePendingText(); }
  if (!inFlight && !pendingTimer && queuedCount > lastTurnQueuedCount) scheduleTurn();
});
box.addEventListener('compositionstart', () => {   // [MOD] simplified
  isComposing = true;
  handleKeyboardToggle(true);
});
box.addEventListener('compositionend', () => {     // [MOD] simplified
  isComposing = false;
  handleKeyboardToggle(imeOpen());                 // stay paused if occlusion persists
});

// (older iOS fallback)
box.addEventListener('touchstart', () => {
  if (!IS_TOUCH) return;               
  // showToast('touchstart fired!');
  rearmTypingGrace();
}, {passive:true});

box.addEventListener('compositionupdate', () => {
  // showToast('compositionupdate fired!');
  rearmTypingGrace();
});


function showToast(msg) {
  const toast = document.createElement('div');
  toast.textContent = msg;
  toast.style.cssText = 'position:fixed;top:50px;left:50%;transform:translateX(-50%);background:black;color:white;padding:8px;border-radius:4px;z-index:9999';
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 1000);
}

</script>

</body>
</html>
