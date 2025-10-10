<?php
declare(strict_types=1);

/**
 * /var/www/typecho/euno/index.php
 * - Serves the chat UI (GET)
 * - Handles AJAX model calls (POST application/json)
 * - Model: fast Responses API model
 */

/*const OPENAI_KEY_PATH = '/var/lib/euno/secrets/openai_key.json';
const OPENAI_API_URL  = 'https://api.openai.com/v1/responses';
const OPENAI_MODEL    = 'gpt-4o';
const OPENAI_KEY_PATH = '/var/lib/euno/secrets/deepseek_key.json';
const OPENAI_API_URL  = 'https://api.deepseek.com/chat/completions';
const OPENAI_MODEL    = 'deepseek-chat'; */
/*const OPENAI_KEY_PATH = '/var/lib/euno/secrets/para_key.json';
const OPENAI_API_URL  = 'https://llmapi.paratera.com/chat/completions';
const OPENAI_MODEL    = 'DeepSeek-R1-0528';*/
const OPENAI_KEY_PATH = '/var/lib/euno/secrets/claude_key.json';
const OPENAI_API_URL  = 'https://api.anthropic.com/v1/messages';
const OPENAI_MODEL    = 'claude-sonnet-4-5-20250929';
const THINKING        = true;

const MEM_ROOT        = '/var/lib/euno/memory';
const OUTPUT_LOG      = '/var/log/euno/last-output.json';

const TOOL_MODE       = false;
const HISTORY_LINES   = 100;

// open-ai-new, open-ai-old, claude
const CURR_API_TYPE = 'claude';


// Load system prompt (server-side only)
$SYSTEM_PROMPT = '';
$sysPath = '/var/lib/euno/memory/system-prompt.php';
if (is_file($sysPath) && is_readable($sysPath)) {
    $ret = require $sysPath;              // file must `return "..."`;
    if (is_string($ret)) { $SYSTEM_PROMPT = $ret; }
}

require_once '/var/lib/euno/sql/db.php';
ensure_session(); // from db.php

require '/var/lib/euno/graph-memory/GraphMemoryBridge.php';

require_once '/var/lib/euno/lib/create-context.php';


// require_once __DIR__ . '/../vendor/autoload.php';

/* ---------- server: AJAX endpoint ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
  && isset($_SERVER['CONTENT_TYPE'])
  && stripos($_SERVER['CONTENT_TYPE'], 'application/json') === 0) {

  header('Content-Type: application/json; charset=utf-8');

  // Parse input
  $in = json_decode(file_get_contents('php://input'), true);
  $client_messages = $in['messages'] ?? null;
  if (!is_array($client_messages) || !$client_messages) {
      echo json_encode(['error' => 'Empty messages']); exit;
  }

  // Session (cookie-backed)
  $sid = ensure_session();
  
  // Load context using the function in create-context.php
  $context = create_context($sid, $client_messages, true, true);
  $outgoing = $context['messages'];

  $last_tool_call = $in['last_func_call'] ?? null;
  if ($last_tool_call) preserve_tool_call($outgoing, $last_tool_call);

  $__skip_user_contents = $context['skip_user_contents'];
  $__pre_user_id = $context['pre_user_id'];
  $__detector = $context['detector'];
  
  // error_log('outgoing='.json_encode($outgoing, JSON_UNESCAPED_UNICODE));

  // --- debug tap: pre-call (readable) ---
  
  $rid = bin2hex(random_bytes(8));              // request id
  $DBG_PREVIEW_CHARS = 150;                     // small, readable cap
  $roleCounts = array_count_values(array_map(fn($m)=>$m['role'], $outgoing));
  $preview = array_map(function($m) use ($DBG_PREVIEW_CHARS){
    $c = (string)($m['content'] ?? '');
    // if ($m['role'] === 'tool') {error_log(json_encode($m, JSON_PRETTY_PRINT));}
    $logged = [
      'r'   => $m['role'],
      'len' => mb_strlen($c),
      'text'=> mb_substr($c, 0, $DBG_PREVIEW_CHARS)  // <-- readable content
    ];
    if (array_key_exists('tool_call_id', $m)) {
      $logged['tool_call_id'] = $m['tool_call_id'];
    };
    return $logged;
  }, $outgoing);

  // error_log(prettyTrimmedJson($outgoing, 150), 3, '/var/log/euno/last-input.json');
  file_put_contents('/var/log/euno/last-input.json', prettyTrimmedJson($outgoing, 150), LOCK_EX);

  header('X-Euno-Req: '.$rid);
  


  // ------- API -------
  $apiKey = load_key();
  $resp   = call_openai($outgoing, $apiKey);
  if (isset($resp['error'])) {
      echo json_encode(['error' => $resp['error']]); exit;
  }

  // handling api tool call
  $message  = $resp['data']['content'] ?? [];
  $answer_raw = extract_text($resp['data']);  // may contain fences

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
          if ($role === 'system' && strncmp($content, 'function result: ', 15) === 0) {
              // keep the system trace; comment next line if not desired
              insert_message($sid, 'system', $content);
              $skip_next_user_once = true;     // next user line is ephemeral nudge
              continue;
          }

          // ephemeral router-nudged user input: do NOT persist
          if ($role === 'user' && $skip_next_user_once) {
            if (stripos($content, 'continue') !== 0) {
              insert_message($sid, 'user', $content); // compatible if nudge no longer needed
            }
            $skip_next_user_once = false;    
            continue;
          }

          // GM: do not skip current-turn user; rely on INSERT IGNORE to dedupe
          if ($role === 'user' && isset($__skip_user_contents) && is_array($__skip_user_contents)) {
              $idx = array_search($content, $__skip_user_contents, true);
              if ($idx !== false) {
                  array_splice($__skip_user_contents, $idx, 1);
                  if (!$__skip_user_contents) unset($__skip_user_contents);
                  // fall through to insert
              }
          }

          // keep tool info
          if ($role === 'tool') {
            if (!isset($m['tool_call_id'])) { continue; }
            insert_message($sid, 'tool', $content, 0, null, null, null, null, $m['tool_call_id']);
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
                  strpos($lc, '现在时间') === 0 ||
                  strncmp($content, '[Memory', 11) === 0 ||
                  preg_match('/^(\d{1,2}\/\d{1,2}\/\d{2,4}|\d{4}-\d{2}-\d{2})/i', $content)
              )) {
                  continue;
              }
              insert_message($sid, $role, $content);
          }
      }

      // assistant reply (RAW)
      $assistant_msg_id = null;
      $toolCalls = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];
      $toolCallsJson = $toolCalls ? json_encode($toolCalls, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
      if ($answer_raw !== '' || $toolCallsJson) {
          $tool_calls_json = $toolCalls ? json_encode($toolCalls, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
          $assistant_msg_id = insert_message_id($sid, 'assistant', $answer_raw, 0, OPENAI_MODEL, null, null, $tool_calls_json, null);
      }
      GraphMemoryBridge::logTurn($sid, $__pre_user_id ?? null, $assistant_msg_id, $__detector ?? []);

      if ($assistant_msg_id && ($answer_raw !== '' || $toolCallsJson)) {
            GraphMemoryBridge::processAssistantMemory($sid, $assistant_msg_id, $answer_raw);
        }
  } catch (Throwable $e) {
      error_log('[history] persist failed: '.$e->getMessage());
  }

  echo json_encode([
    'answer'               => (string)($answer_raw ?? ''),
    'assistant_tool_calls' => $toolCalls
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

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
    $TOOLS = require '/var/lib/euno/memory/tool-functions.php';
    if (CURR_API_TYPE === 'open-ai-new') {
      $payload = [
        'model' => OPENAI_MODEL,
        'input' => $messages,
        'max_output_tokens' => 12048,
        'text' => [ 'verbosity' => 'medium' ],
        'reasoning' => [ 'effort' => 'medium' ], // adapted 202508
        //'tools'      => $TOOLS
      ];
    } else if ((CURR_API_TYPE === 'open-ai-old')) {
      // ds
      $payload = [
          'model'      => OPENAI_MODEL,        
          'messages'   => $messages,          
          'max_tokens' => 8192,
          // 'tools'      => $TOOLS,
          'temperature'=> 0.3,                
          // 'stream'   => true,               // optional: if streaming will be added later
          'frequency_penalty' => 1.7,
          'presence_penalty' => 1.7,  
      ];
    } else {
      // claude - cache only: personality, task context, memories
      // separate stable (cacheable) from dynamic (uncacheable) system messages
      $stable = [];
      $dynamic = [];

      foreach (array_slice($messages, 0, 5) as $m) {
        if (($m['role'] ?? '') !== 'system') continue;
        $c = $m['content'];
        
        // dynamic content goes to end, uncached
        if (stripos($c, 'Current time is:') === 0 || stripos($c, '[Memory v2]') === 0 || stripos($c, '现在时间') === 0) {
          $dynamic[] = $c;
          continue;
        }
        
        // everything else is stable and cached
        $stable[] = $c;
      }

      // build blocks: all stable content cached, then dynamic uncached
      $sys_blocks = array_map(
        fn($text) => ['type' => 'text', 'text' => $text, 'cache_control' => ['type' => 'ephemeral']], 
        $stable
      );

      foreach ($dynamic as $text) {
        $sys_blocks[] = ['type' => 'text', 'text' => $text];
      }
      
      /*error_log('=== CACHE DEBUG ===');
      foreach ($sys_blocks as $i => $block) {
        $has_cache = isset($block['cache_control']);
        $preview = substr($block['text'], 0, 50);
        error_log("Block $i [cache=$has_cache]: $preview...");
      }*/

      $mc = array_slice($messages, 5);
      $payload = [
        'model' => OPENAI_MODEL,
        'messages' => $mc,
        'system' => $sys_blocks,
        'max_tokens' => 8192,
        'temperature' => 1,
      ];

      if (THINKING) {
        $payload['thinking'] = [
          "type" => "enabled",
          "budget_tokens" => 2000
        ];
      }
    }
    
    if (TOOL_MODE) {$payload['tools'] = $TOOLS;}
    $ch = curl_init(OPENAI_API_URL);

    if (CURR_API_TYPE !== 'claude') {
      curl_setopt_array($ch, [
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_POST           => true,
          CURLOPT_HTTPHEADER     => [
              'Content-Type: application/json',
              'Authorization: Bearer ' . $apiKey,
          ],
          CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
          CURLOPT_TIMEOUT        => 60,
      ]);
    } else {
      curl_setopt_array($ch, [
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_POST           => true,
          CURLOPT_HTTPHEADER     => [
              'x-api-key: ' . $apiKey,
              'anthropic-version: 2023-06-01',
              'Content-Type: application/json'
          ],
          CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
          CURLOPT_TIMEOUT        => 30,
      ]);
    }
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
        error_log('[AI] raw=' . substr($raw, 0, 2000) . $msg); 
        return ['error' => $msg];
    }
    
    file_put_contents(OUTPUT_LOG, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return ['data' => $json];
}

function extract_text(array $resp): string {
  $resp = is_array($resp) ? $resp : json_decode((string)$resp, true);
  if (CURR_API_TYPE === 'open-ai-new') {
   // BELOW IS FOR CHATGPT
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

  } else if (CURR_API_TYPE === 'open-ai-old') {
    $msg = $resp['choices'][0]['message'] ?? [];
    if (!empty($msg['content'])) {
      //error_log('[deepseek] msg=' . $msg['content']);
      return sanitize_punct($msg['content']);
    }
    if (!empty($msg['reasoning_content'])) return (string)$msg['reasoning_content'];

    //error_log('[deepseek] msg=' . $msg['content'] . '. Parsed nothing!');
    return '';
  } else {
    // handle both thinking and non-thinking responses
    $msg = '';
    
    foreach ($resp['content'] as $block) {
      if ($block['type'] === 'thinking') {
        // optionally log or skip thinking blocks
        // error_log('[claude] thinking: ' . $block['thinking']);
        continue;
      }
      
      if ($block['type'] === 'text') {
        $msg = $block['text'];
        break;  // get first text block
      }
    }
    
    if ($msg !== '') {
      // error_log('[claude] msg=' . $msg);
      return sanitize_punct($msg);
    }
    error_log('[claude] msg=' . $msg . '. Parsed nothing!');
    return '';
  }
}

// forbid —, –, and -- in final output.
function sanitize_punct(string $s): string {
    // Replace any em/en dash or double hyphen (with surrounding spaces) by a comma+space
    $s = preg_replace('/(?<=\h)(?:—|–|--|-)(?=\h)/u', ', ', $s);
    // Collapse duplicate commas/spaces
    $s = preg_replace('/\s*,\s*,+/', ', ', $s);
    // Preserve double newlines but collapse other multiple whitespace
    $s = preg_replace('/[ \t]{2,}/', ' ', $s);  // collapse spaces/tabs only
    $s = preg_replace('/\n{3,}/', '\n\n', $s);  // max 2 consecutive newlines
    return trim($s);
}
function preserve_tool_call(array &$messages, array $tool_calls): void {
  $l = count($messages);
  $last_msg = $messages[$l - 1];
  if ($last_msg['role'] !== 'tool') return;
  
  $sec_last_msg = $messages[$l - 2];
  if ($sec_last_msg['role'] !== 'assistant') {
    error_log('[index] preserve_tool_call expected assitant but not');
    error_log(json_encode($sec_last_msg));
    return;
  }

  $sec_last_msg['tool_calls'] = $tool_calls;
  $messages[$l - 2] = $sec_last_msg;

  // error_log(json_encode($messages[$l - 2]));
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
<html lang="en" data-debug="off" compact="off">
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

/* Compact mode */
:root[compact="on"] .chat { gap:6px; padding:12px; }
:root[compact="on"] .msg { max-width:86%; padding:6px 8px; border-radius:8px; font-size:13px; line-height:1.25; }
:root[compact="on"] .sys { font-size:11px; }
:root[compact="on"] header { padding:10px 12px; }
:root[compact="on"] h1 { font-size:14px; }
:root[compact="on"] .small { font-size:11px; padding:0 12px 8px; }
:root[compact="on"] .pending { padding:0 6px; }
:root[compact="on"] textarea { min-height:48px; padding:8px; font-size:14px; }
:root[compact="on"] .inputbar { padding:8px 12px; gap:8px; }
:root[compact="on"] button { padding:8px 12px; font-size:13px; border-radius:8px; }

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
    <div class="small">Key: <?=htmlspecialchars(OPENAI_KEY_PATH)?> | Endpoint: <?=htmlspecialchars(OPENAI_API_URL)?> | Model: <?=htmlspecialchars(OPENAI_MODEL)?></div>
  </div>
</div>

<script id='hist-data' type='application/json'>
<?= json_encode(fetch_last_messages(ensure_session(), HISTORY_LINES), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>
</script>

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

// in-page history only
const history = [];
let inFlight = false;

// user msg buffering
let debounceMs = 1500;
let pendingTimer = null;  
let pendingTick = null;   
let pendingETA = 0; 
let queuedCount = 0;
let lastTurnQueuedCount = 0;
let isComposing = false;
let isKeyboardOpen = false;
let _kbPrev = false;

let typingTimer = null;
let typingIdleMs = 6000;
let typingInitMs = 1800;
let typingETA = 0;

let lineInterval = 2001;

pendingEl.classList.add('on'); 
updatePendingText();
setInterval(updatePendingText, 500);

function rearmTypingGrace(){
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

function imeOpen(){
  if (isComposing) return true;
  const focused = document.activeElement === box;
  if (!focused) return false;

  const vv = window.visualViewport;
  if (vv){
    const occluded = window.innerHeight - (vv.height + vv.offsetTop);
    if (occluded > KB_EPS) return true;
  }

  return IS_LIKELY_IOS && focused;
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
    const prevScrollTop = chat.scrollTop;
    
    const vh   = vv ? vv.height : window.innerHeight;
    const vTop = vv ? vv.offsetTop : 0;
    root.style.setProperty('--app-h', vh + 'px');
    root.style.setProperty('--vv-top', vTop + 'px');

    const kbOpen = imeOpen();
    const wasKbOpen = root.classList.contains('ime-open');
    root.classList.toggle('ime-open', kbOpen);
    
    if (kbOpen !== wasKbOpen) {
      handleKeyboardToggle(kbOpen);
      
      if (kbOpen && !wasKbOpen) {
        requestAnimationFrame(() => {
          chat.scrollTop = prevScrollTop;
        });
      }
    }

    if (!kbOpen && wasAtBottom) {
      chat.scrollTop = chat.scrollHeight;
    }
  }

  setAppHeight();
  vv?.addEventListener('resize', setAppHeight);
  vv?.addEventListener?.('geometrychange', setAppHeight);
  window.addEventListener('orientationchange', setAppHeight);
})();

function debugOn(){ return document.documentElement.getAttribute('data-debug') !== 'off'; }
function sys(msg){ if (debugOn()) addBubble('sys', msg); }

function addBubble(role, text){
  const div = document.createElement('div');
  div.className = 'msg ' + (role==='user' ? 'user' : role==='assistant' ? 'assistant' : 'sys');
  if (role === 'assistant') {text = renderVisible(text);}
  div.textContent = text;
  chat.appendChild(div);
  chat.scrollTop = chat.scrollHeight;
}

// display history chats
try {
  const histElem = document.getElementById('hist-data');
  if (histElem) {
    const hist = JSON.parse(histElem.textContent || '[]');
    hist.forEach(m => {
      const role = m.role || 'sys';
      const content = m.content || '';
      if (content) {
        parts = content.split(/\n\n+/);
        for (let i = 0; i < parts.length; i++) {
          addBubble(role, parts[i]); 
        }
      }
    });
  }
} catch (e) {
  console.error('Failed parsing history', e);
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
  } else if (isComposing) {
    pendingTextEl.textContent = 'IME composing';
  } else if (imeOpen()) {
    pendingTextEl.textContent = 'Idle (keyboard open)';
  } else {
    const suffix = (tailRole() === 'assistant') ? ' (awaiting user)' : '';
    pendingTextEl.textContent = 'Idle' + suffix;
  }

  const varsEl = document.getElementById('pendingVars');
  if (document.documentElement.getAttribute('data-debug') !== 'off') {
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

/* ---------- fences: parse, render, route, interest ---------- */
function parseFencedBlocks(s){
  const re=/~~~(\w+)\s*(\{[\s\S]*?\})\s*~~~/giu, out=[]; let m;
  while((m=re.exec(s))){
    try{ out.push({tag:(m[1]||'').toLowerCase(), json:JSON.parse(m[2])}); }catch{}
  }
  return out;
}

function stripFences(s) {
  s = String(s || '').replace(/~~~\w+\s*\{[\s\S]*?\}\s*~~~/g, '').trim();
  // s = s.replace(/\n{2,}/g, '\n');
  return s;
}

function renderVisible(s){
  s = s.replace(/(?<=\s)(?:—|–|--|-)(?=\s)/g, ', ');
  return String(s||'')
    .replace(/~~~action[\s\S]*?~~~\s*/g,'[ACT]')
    .replace(/~~~memory[\s\S]*?~~~\s*/g,'[MEM]')
    .replace(/~~~interest[\s\S]*?~~~\s*/g,'[INT]')
    .trim();
}

async function routeBlocks(aiOutput, toolCalls){
  const hasFences = /~~~\w+[\s\S]*?~~~/m.test(aiOutput);
  const hasTools  = Array.isArray(toolCalls) && toolCalls.length>0;
  if(!hasFences && !hasTools) return null;

  const r = await fetch('action_router.php',{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      ai_output: String(aiOutput||''),
      tool_calls: hasTools ? toolCalls : undefined
    }),
    credentials:'same-origin'
  });
  if(!r.ok) throw new Error('router http '+r.status);
  return await r.json();
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

function traceIssuedFences(aiOutput){
  const blocks = parseFencedBlocks(aiOutput);
  for(const b of blocks){
    if (b.tag==='action')  sys('Action issued');
    else if (b.tag==='memory') sys('Memory update issued');
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
    } else {
      sys('Handled '+(h.tag||'block'));
    }
  });
  unhandled.forEach(u=>{
    if (!u) return;
    addBubble('sys', 'Unhandled '+(u.tag||'block')+(u.error?': '+u.error:'')); 
  });
}

function scheduleTurn() {
  if (inFlight) return;
  if (tailRole() === 'assistant'){
    clearPending();
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
    return;
  }
  if (pendingTimer) clearTimeout(pendingTimer);
  pendingTimer = setTimeout(performModelTurn, debounceMs);
  showPending(debounceMs);
}

async function sendMessage(){
  const q = box.value.trim();
  if (!q) return;
  if (typingTimer) { clearTimeout(typingTimer); typingTimer = null; typingETA = 0; }
  addBubble('user', q);
  box.value = '';
  history.push({ role: 'user', content: q });
  queuedCount++;

  if (pendingTimer) { clearTimeout(pendingTimer); pendingTimer = null; }
  clearPending();
  scheduleTurn();
}

async function performModelTurn() {
  if (tailRole() === 'assistant'){
    clearPending();
    pendingTextEl.textContent = 'Idle (awaiting user)';
    updatePendingText();
    return;
  }

  if (inFlight) return;
  inFlight = true;
  btn.disabled = true;
  clearPending();
  const turnStartQueued = queuedCount;
  if (lastTurnQueuedCount === queuedCount) {
    sys('Tried to submit API req with no new msg.');
    inFlight = false;
    btn.disabled = false;
    return;
  }
  lastTurnQueuedCount = turnStartQueued;
  if (pendingTimer) { clearTimeout(pendingTimer); pendingTimer = null; }
  if (typingTimer) { clearTimeout(typingTimer); typingTimer = null; }

  try{
    const r = await fetch('', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ messages: history })
    });
    const j = await r.json();
    if (j.error){ addBubble('assistant', 'Error: ' + j.error); return; }

    const ans = String(j.answer ?? '');
    const toolCalls = Array.isArray(j.assistant_tool_calls) ? j.assistant_tool_calls : [];
    await handleAiOutput(ans, toolCalls, 0);

  } finally {
    inFlight = false;
    btn.disabled = false;

    if (queuedCount > lastTurnQueuedCount) {
      scheduleTurn();
    }
  }
}

async function handleAiOutput(aiOutput, toolCalls = [], depth = 0){
  if (!aiOutput && !(Array.isArray(toolCalls) && toolCalls.length)) return;
  const raw = String(aiOutput);
  const visible = stripFences(raw) || '*command*'; // visible should be non-empty.


  //addBubble('assistant', visible);
  const parts = visible.split(/\n\n+/);
  for (let i = 0; i < parts.length; i++) {
    setTimeout(() => addBubble('assistant', parts[i]), i * lineInterval); 
  }
  
  if (Array.isArray(toolCalls) && toolCalls.length){
    history.push({ role:'assistant', content: raw, tool_calls: toolCalls });
  } else {
    history.push({ role:'assistant', content: raw });
  }

  traceIssuedFences(raw);
  applyDebugOps(parseFencedBlocks(raw));
  applyDebugOpsFrom(toolCalls, null);

  let router = null;
  try {
    router = await routeBlocks(raw, toolCalls);
    traceRouterResult(router);
    applyDebugOpsFrom(toolCalls, router);
    // push ready tool messages returned by router (API-level calls)
    if (router) {
      if (Array.isArray(router.tool_messages) && router.tool_messages.length > 0) {
        for (const tm of router.tool_messages) {
          if (!tm || typeof tm !== 'object') continue;
          history.push({
            role: 'tool',
            tool_call_id: String(tm.tool_call_id || ''),
            content: String(tm.content || '')
          });
        }
      } else {
        //history.push({ role: 'system', content: 'function result: ' + JSON.stringify(router) })
        //history.push({ role: 'user', content: 'Continue your response with the last function result' });
        history.push({ role: 'user', content: 'function result: ' + JSON.stringify(router) });
      }
    }
  } catch (error) {
    console.error('Router error:', error);
    addBubble('sys', `Router error: ${error.message}`);
  }

  if (depth >= MAX_CHAIN) return;

  const handled = (router && Array.isArray(router.handled)) ? router.handled : [];
  const hasNonMemoryAction = handled.some(h => h && h.tag === 'action');

  if (hasNonMemoryAction){
    const r2 = await fetch('', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ 
        messages: history, 
        last_func_call: Array.isArray(toolCalls) ? toolCalls : []
      })
    });
    const j2 = await r2.json();
    if (!j2.error){
      const toolCalls2 = Array.isArray(j2.assistant_tool_calls) ? j2.assistant_tool_calls : [];
      await handleAiOutput(String(j2.answer ?? ''), toolCalls2, depth + 1);
    } else {
      addBubble('sys', 'followup_error: ' + j2.error);
    }
    return;
  }

  const interest = resolveInterest(raw, toolCalls, router);
  if (interest){
    const p = Math.max(0, Math.min(1, interest.confidence + 0.2));
    if (Math.random() < p){
      sys('Interest trigger p=' + p.toFixed(2) + (interest.topics.length ? (' [' + interest.topics.join(', ') + ']') : ''));
      const r3 = await fetch('', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ 
          messages: history,
          last_func_call: Array.isArray(toolCalls) ? toolCalls : []
        })
      });
      const j3 = await r3.json();
      if (!j3.error){
        const toolCalls3 = Array.isArray(j3.assistant_tool_calls) ? j3.assistant_tool_calls : [];
        await handleAiOutput(String(j3.answer ?? ''), toolCalls3, depth + 1);
      } else {
        addBubble('sys', 'interest_followup_error: ' + j3.error);
      }
    }
  }
}

function safeParseJSON(s){ try{ return JSON.parse(String(s||'')); }catch{ return null; } }

function getToolFnArgs(toolCalls, fnName){
  if (!Array.isArray(toolCalls)) return null;
  for (const tc of toolCalls){
    if (!tc || tc.type !== 'function') continue;
    const f = tc.function || {};
    if ((f.name||'').toLowerCase() === fnName) return safeParseJSON(f.arguments);
  }
  return null;
}

function getRouterHandled(router, tag){
  if (!router || !Array.isArray(router.handled)) return null;
  for (const h of router.handled){ if (h && (h.tag||'').toLowerCase() === tag) return h; }
  return null;
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

function applyDebugOpsFrom(toolCalls, router){
  // prefer explicit tool call
  const args = getToolFnArgs(toolCalls, 'debug');
  let op = (args && typeof args.op === 'string') ? args.op.toLowerCase() : null;

  // fallback to router-handled debug
  if (!op){
    const h = getRouterHandled(router, 'debug');
    if (h && h.payload && typeof h.payload === 'string') op = h.payload.toLowerCase();
  }
  if (!op) return;

  if (op === 'on'){
    document.documentElement.setAttribute('data-debug','on');
    addBubble('sys','Debug mode ON');
  } else if (op === 'off'){
    document.documentElement.setAttribute('data-debug','off');
    addBubble('sys','Debug mode OFF');
  }
}

function extractInterestFrom(toolCalls, router){
  // prefer explicit tool call
  let o = getToolFnArgs(toolCalls, 'interest');

  // fallback to router-handled interest
  if (!o){
    const h = getRouterHandled(router, 'interest');
    if (h && h.payload && typeof h.payload === 'object') o = h.payload;
  }
  if (!o) return null;

  const conf = Number(o.confidence);
  if (!isFinite(conf)) return null;
  return {
    topics: Array.isArray(o.topics) ? o.topics.slice(0,4) : [],
    reason: typeof o.reason === 'string' ? o.reason : '',
    confidence: Math.max(0, Math.min(1, conf))
  };
}

function resolveInterest(aiOutput, toolCalls, router){
  const a = extractInterestFrom(toolCalls, router);
  if (a && (a.topics.length || a.reason)) return a;
  const b = extractInterest(aiOutput);
  return (b && (b.topics.length || b.reason)) ? b : null;
}

function tailRole(){ 
  return history.length ? history[history.length-1].role : ''; 
}

function handleKeyboardToggle(open){
  if (open === _kbPrev) return;
  _kbPrev = open;
  isKeyboardOpen = open;

  if (open){
    if (pendingTimer) { clearTimeout(pendingTimer); pendingTimer = null; }
    if (typingTimer)  { clearTimeout(typingTimer);  typingTimer  = null; typingETA = 0; }
    clearPending();
    pendingTextEl.textContent = 'Idle (keyboard open)';
    updatePendingText();
  } else {
    if (!inFlight && tailRole() === 'user') scheduleTurn();
    else updatePendingText();
  }
}

btn.addEventListener('click', sendMessage);
box.addEventListener('keydown', (e)=>{
  if (e.keyCode === 229) {
    rearmTypingGrace();
    return;
  };
  if (e.key === 'Enter' && !e.shiftKey) {  e.preventDefault(); sendMessage(); }
});
box.addEventListener('focus', () => {        
  if (imeOpen()){                            
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
  const hasText = box.value.trim().length > 0;
  if (e.isComposing || isComposing || hasText) {
    rearmTypingGrace();
    return;
  };
  if (pendingTimer) { clearTimeout(pendingTimer); pendingTimer = null; }
  clearPending();

  if (typingTimer) { clearTimeout(typingTimer); typingTimer = null; typingETA = 0; }

  if (hasText) {
    typingETA = Date.now() + typingIdleMs;
    typingTimer = setTimeout(() => {
      typingTimer = null; typingETA = 0;
      if (!inFlight && tailRole() === 'user') {
        scheduleTurn();
      } else {
        updatePendingText();
      }
    }, typingIdleMs);
  } else {
    if (!inFlight && tailRole() === 'user') scheduleTurn();
  }
});

box.addEventListener('blur', () => {
  if (typingTimer) { clearTimeout(typingTimer); typingTimer = null; typingETA = 0; updatePendingText(); }
  if (!inFlight && !pendingTimer && queuedCount > lastTurnQueuedCount) scheduleTurn();
});
box.addEventListener('compositionstart', () => {
  isComposing = true;
  handleKeyboardToggle(true);
});
box.addEventListener('compositionend', () => {
  isComposing = false;
});

box.addEventListener('touchstart', () => {
  if (!IS_TOUCH) return;               
  rearmTypingGrace();
}, {passive:true});

box.addEventListener('compositionupdate', () => {
  rearmTypingGrace();
});
</script>

</body>
</html>
