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
  $context = create_context($sid, $client_messages);
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
    if ($m['role'] === 'tool') {error_log(json_encode($m, JSON_PRETTY_PRINT));}
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
  $choice   = $resp['data']['choices'][0] ?? [];
  $message  = $choice['message'] ?? [];
  $toolCalls= is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];

  $answer_raw = isset($message['content']) ? sanitize_punct((string)$message['content']) : '';  // may contain fences
  // --- debug tap: post-call (readable) ---
  /*
  error_log(json_encode([
    't'=>'post','rid'=>$rid,'sid'=>$sid,
    'answer_len'=>mb_strlen($answer_raw)
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
          if ($role === 'system' && strncmp($content, 'router result: ', 15) === 0) {
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
            $skip_next_user_once = true;     // next user line is ephemeral nudge
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
    'answer'               => (string)($message['content'] ?? ''),
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
    $payload = [
        'model' => OPENAI_MODEL,
        'input' => $messages,
        'max_output_tokens' => 2048,
        'text' => [ 'verbosity' => 'low' ],
        'reasoning' => [ 'effort' => 'low' ], // adapted 202508
    ];
    // ds
    $TOOLS = require '/var/lib/euno/memory/tool-functions.php';
    $payload = [
        'model'      => 'deepseek-chat',        
        'messages'   => $messages,          
        'max_tokens' => 2048,
        'tools'      => $TOOLS,
        'temperature'=> 1.3,                
        // 'stream'   => true,               // optional: if streaming will be added later
        'frequency_penalty' => 0.7,
        'presence_penalty' => 0.5,  
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
    return sanitize_punct($msg['content']);
  }
  if (!empty($msg['reasoning_content'])) return (string)$msg['reasoning_content'];

  //error_log('[deepseek] msg=' . $msg['content'] . '. Parsed nothing!');
  return '';
}

// forbid —, –, and -- in final output.
function sanitize_punct(string $s): string {
    // Replace any em/en dash or double hyphen (with surrounding spaces) by a comma+space
    $s = preg_replace('/\h*(?:—|–|--|-)\h*/u', ', ', $s);
    // Collapse duplicate commas/spaces
    $s = preg_replace('/\s*,\s*,+/', ', ', $s);
    $s = preg_replace('/\s{2,}/', ' ', $s);
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

<!doctypehtml><html data-debug="off"lang="en"><meta charset="utf-8"><title>M.ICU - Eunoia</title><meta content="width=device-width,initial-scale=1,viewport-fit=cover,interactive-widget=resizes-content"name="viewport"><style>:root{--bg:#0b0c0f;--panel:#11141a;--muted:#9aa0aa;--fg:#e9eef4;--accent:#5aa1ff;--border:#1c2230}*{box-sizing:border-box}body,html{height:100%;overflow:hidden;overscroll-behavior:none;overflow-anchor:none}html{background:#0b0c0f}.wrap{position:fixed;inset:0;height:100svh}.chat{padding-bottom:calc(18px + var(--kb,0px))}.inputbar{padding-bottom:max(12px,env(safe-area-inset-bottom))}body{margin:0;background:linear-gradient(180deg,#0b0c0f,#0d1117);font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif;color:var(--fg);overflow:hidden}.wrap{max-width:860px;margin:0 auto;padding:0 14px;height:100svh;display:flex}.card{background:var(--panel);border:1px solid var(--border);border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.25);display:grid;grid-template-rows:auto auto 1fr auto auto;height:100%;width:100%;overflow:hidden}header{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}h1{margin:0;font-size:16px;letter-spacing:.2px}.meta{color:var(--muted);font-size:12px}.chat{overflow:auto;padding:18px;display:flex;flex-direction:column;gap:12px;-webkit-overflow-scrolling:touch;overscroll-behavior:contain}.msg{max-width:80%;padding:12px 14px;border-radius:12px;white-space:pre-wrap}.user{align-self:flex-end;background:#1b2230;border:1px solid #2a3145}.assistant{align-self:flex-start;background:#0f1218;border:1px solid #1f2738}.sys{align-self:center;color:var(--muted);font-size:12px;line-height:1.2;margin:0;padding:0}.inputbar{display:flex;gap:10px;padding:12px 16px;border-top:1px solid var(--border);background:var(--panel)}textarea{flex:1;min-height:68px;max-height:160px;padding:10px;background:#0f1218;border:1px solid var(--border);border-radius:10px;color:var(--fg);resize:vertical;font:inherit}button{background:var(--accent);color:#071120;border:0;border-radius:10px;padding:10px 16px;font:600 14px/1 system-ui;cursor:pointer}button:hover{filter:brightness(1.05)}.small{font-size:12px;color:var(--muted);padding:0 16px 12px;background:var(--panel)}.pending{font-size:12px;color:var(--muted);display:grid;grid-template-columns:auto 1fr;align-items:start;gap:6px 16px;background:var(--panel);padding:0 6px}.pending.on{display:block}.pending .dot{display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--accent);margin-right:6px;animation:blink 1s infinite}.pending .vars{margin-top:2px;font-size:11px;color:#666;white-space:pre-line}.pending .status{display:flex;align-items:center;gap:6px}.pending .vars{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:4px 12px;font:11px/1.25 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#8a94a6;white-space:normal}.pending .kv{display:flex;justify-content:space-between;gap:8px}.pending .k{opacity:.75}.pending .v{text-align:right}@keyframes blink{0%,100%{opacity:.3}50%{opacity:1}}:root[data-debug=off] #pending #pendingText,:root[data-debug=off] #pending .dot,:root[data-debug=off] #pending .vars{visibility:hidden}:root[data-debug=off] #pending{padding:2px 16px;min-height:6px;line-height:0;border-bottom-color:transparent;background:var(--panel)}html.ime-open #pending{padding:2px 16px}html.ime-open body{overflow:hidden}html.ime-open .chat{scroll-behavior:auto}html.ime-open{position:fixed;width:100%}</style><div class="wrap"><div class="card"><header><h1>Eunoia</h1><div class="meta">Session history is in-page only</div></header><div class="pending"id="pending"aria-live="polite"><div class="status"><span class="dot"></span><span id="pendingText">Waiting…</span></div><div class="vars"id="pendingVars"></div></div><div class="chat"id="chat"></div><div class="inputbar"><textarea id="box"placeholder="Type a message..."></textarea> <button id="send">Send</button></div><div class="small">Key:<?=htmlspecialchars(OPENAI_KEY_PATH)?>| Endpoint:<?=htmlspecialchars(OPENAI_API_URL)?></div></div></div><script>const chat=document.getElementById("chat"),box=document.getElementById("box"),btn=document.getElementById("send"),pendingEl=document.getElementById("pending"),pendingTextEl=document.getElementById("pendingText"),MAX_CHAIN=3,KB_EPS=24,IS_LIKELY_IOS=/iP(hone|ad|od)/i.test(navigator.userAgent)||/Mac/i.test(navigator.userAgent)&&"ontouchend"in document,IS_TOUCH="ontouchstart"in window||navigator.maxTouchPoints>0,history=[];let inFlight=!1,debounceMs=1500,pendingTimer=null,pendingTick=null,pendingETA=0,queuedCount=0,lastTurnQueuedCount=0,isComposing=!1,isKeyboardOpen=!1,_kbPrev=!1,typingTimer=null,typingIdleMs=6e3,typingInitMs=1800,typingETA=0,lineInterval=1601;function rearmTypingGrace(){pendingTimer&&(clearTimeout(pendingTimer),pendingTimer=null),clearPending(),typingTimer&&(clearTimeout(typingTimer),typingTimer=null,typingETA=0),typingETA=Date.now()+typingIdleMs,typingTimer=setTimeout(()=>{typingTimer=null,typingETA=0,inFlight||"user"!==tailRole()?updatePendingText():scheduleTurn()},typingIdleMs),updatePendingText()}function imeOpen(){if(isComposing)return!0;const e=document.activeElement===box;if(!e)return!1;const t=window.visualViewport;if(t){if(window.innerHeight-(t.height+t.offsetTop)>24)return!0}return IS_LIKELY_IOS&&e}function debugOn(){return"off"!==document.documentElement.getAttribute("data-debug")}function sys(e){debugOn()&&addBubble("sys",e)}function addBubble(e,t){const n=document.createElement("div");n.className="msg "+("user"===e?"user":"assistant"===e?"assistant":"sys"),"assistant"===e&&(t=renderVisible(t)),n.textContent=t,chat.appendChild(n),chat.scrollTop=chat.scrollHeight}function showPending(e){pendingETA=Date.now()+e,pendingEl.classList.add("on"),updatePendingText()}function clearPending(){pendingETA=0,pendingTick&&(clearInterval(pendingTick),pendingTick=null),pendingTextEl.textContent="Idle",updatePendingText()}function esc(e){return String(e).replace(/[&<>"']/g,e=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[e]))}function setVars(e){const t=document.getElementById("pendingVars");"off"!==document.documentElement.getAttribute("data-debug")?t.innerHTML=Object.entries(e).map(([e,t])=>`<div class="kv"><span class="k">${esc(e)}</span><span class="v">${esc(t)}</span></div>`).join(""):t.innerHTML=""}function updatePendingText(){const e=!!pendingTimer,t=!!typingTimer;if(inFlight)pendingTextEl.textContent="Sending…";else if(e){const e=Math.max(0,pendingETA-Date.now());pendingTextEl.textContent=`Pending send in ${(e/1e3).toFixed(1)}s`}else if(t){const e=Math.max(0,typingETA-Date.now()),t=box===document.activeElement?box.value.trim()?"typing grace":"focus grace":"typing grace";pendingTextEl.textContent=`${t} ${(e/1e3).toFixed(1)}s`}else if(isComposing)pendingTextEl.textContent="IME composing";else if(imeOpen())pendingTextEl.textContent="Idle (keyboard open)";else{const e="assistant"===tailRole()?" (awaiting user)":"";pendingTextEl.textContent="Idle"+e}const n=document.getElementById("pendingVars");"off"!==document.documentElement.getAttribute("data-debug")?setVars({pendingTimer:!!pendingTimer,lastTurnQueuedCount:lastTurnQueuedCount,inFlight:inFlight,queuedCount:queuedCount-lastTurnQueuedCount,tailRole:tailRole(),typingTimer:!!typingTimer,isComposing:isComposing,isKeyboardOpen:isKeyboardOpen}):n.textContent=""}function parseFencedBlocks(e){const t=/~~~(\w+)\s*(\{[\s\S]*?\})\s*~~~/giu,n=[];let i;for(;i=t.exec(e);)try{n.push({tag:(i[1]||"").toLowerCase(),json:JSON.parse(i[2])})}catch{}return n}function stripFences(e){return e=String(e||"").replace(/~~~\w+\s*\{[\s\S]*?\}\s*~~~/g,"").trim()}function renderVisible(e){return e=e.replace(/\s*(?:—|–|--|-)\s*/g,", "),String(e||"").replace(/~~~action[\s\S]*?~~~\s*/g,"[ACT]").replace(/~~~memory[\s\S]*?~~~\s*/g,"[MEM]").replace(/~~~interest[\s\S]*?~~~\s*/g,"[INT]").trim()}async function routeBlocks(e,t){const n=/~~~\w+[\s\S]*?~~~/m.test(e),i=Array.isArray(t)&&t.length>0;if(!n&&!i)return null;const o=await fetch("action_router.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({ai_output:String(e||""),tool_calls:i?t:void 0}),credentials:"same-origin"});if(!o.ok)throw new Error("router http "+o.status);return await o.json()}function extractInterest(e){const t=parseFencedBlocks(e);let n=(t.find(e=>"interest"===e.tag)||{}).json||null;if(!n)for(const e of t){const t=e.json||{};if(Array.isArray(t.topics)&&"number"==typeof t.confidence){n=t;break}}if(!n)return null;const i=Math.max(0,Math.min(1,Number(n.confidence)));return isFinite(i)?{topics:Array.isArray(n.topics)?n.topics.slice(0,4):[],reason:"string"==typeof n.reason?n.reason:"",confidence:i}:null}function traceIssuedFences(e){const t=parseFencedBlocks(e);for(const e of t)"action"===e.tag?sys("Action issued"):"memory"===e.tag?sys("Memory update issued"):sys("Block "+e.tag+" issued")}function traceRouterResult(e){if(!e)return;const t=Array.isArray(e.handled)?e.handled:[],n=Array.isArray(e.unhandled)?e.unhandled:[];t.forEach(e=>{if(e)if("action"===e.tag){addBubble("sys","Action "+(e.intent||"action")+": "+(e.note||e.status||(e.result?"ok":"not_found")))}else"memory"===e.tag?sys("Memory "+(e.status||"ok")+(e.fact?": "+e.fact:"")):sys("Handled "+(e.tag||"block"))}),n.forEach(e=>{e&&addBubble("sys","Unhandled "+(e.tag||"block")+(e.error?": "+e.error:""))})}function scheduleTurn(){if(!inFlight){if("assistant"===tailRole())return clearPending(),pendingTextEl.textContent="Idle (awaiting user)",void updatePendingText();if(imeOpen()){box.value.trim().length>0||(typingTimer=setTimeout(performModelTurn,typingInitMs),typingETA=Date.now()+typingInitMs)}else pendingTimer&&clearTimeout(pendingTimer),pendingTimer=setTimeout(performModelTurn,debounceMs),showPending(debounceMs)}}async function sendMessage(){const e=box.value.trim();e&&(typingTimer&&(clearTimeout(typingTimer),typingTimer=null,typingETA=0),addBubble("user",e),box.value="",history.push({role:"user",content:e}),queuedCount++,pendingTimer&&(clearTimeout(pendingTimer),pendingTimer=null),clearPending(),scheduleTurn())}async function performModelTurn(){if("assistant"===tailRole())return clearPending(),pendingTextEl.textContent="Idle (awaiting user)",void updatePendingText();if(inFlight)return;inFlight=!0,btn.disabled=!0,clearPending();const e=queuedCount;if(lastTurnQueuedCount===queuedCount)return sys("Tried to submit API req with no new msg."),inFlight=!1,void(btn.disabled=!1);lastTurnQueuedCount=e,pendingTimer&&(clearTimeout(pendingTimer),pendingTimer=null),typingTimer&&(clearTimeout(typingTimer),typingTimer=null);try{const e=await fetch("",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({messages:history})}),t=await e.json();if(t.error)return void addBubble("assistant","Error: "+t.error);const n=String(t.answer??""),i=Array.isArray(t.assistant_tool_calls)?t.assistant_tool_calls:[];await handleAiOutput(n,i,0)}finally{inFlight=!1,btn.disabled=!1,queuedCount>lastTurnQueuedCount&&scheduleTurn()}}async function handleAiOutput(e,t=[],n=0){if(!(e||Array.isArray(t)&&t.length))return;const i=String(e),o=(stripFences(i)||"*command*").split(/\n\n+/);for(let e=0;e<o.length;e++)setTimeout(()=>addBubble("assistant",o[e]),e*lineInterval);Array.isArray(t)&&t.length?history.push({role:"assistant",content:i,tool_calls:t}):history.push({role:"assistant",content:i}),traceIssuedFences(i),applyDebugOps(parseFencedBlocks(i)),applyDebugOpsFrom(t,null);let r=null;try{if(r=await routeBlocks(i,t),traceRouterResult(r),applyDebugOpsFrom(t,r),r)if(Array.isArray(r.tool_messages)&&r.tool_messages.length>0)for(const e of r.tool_messages)e&&"object"==typeof e&&history.push({role:"tool",tool_call_id:String(e.tool_call_id||""),content:String(e.content||"")});else history.push({role:"system",content:"function result: "+JSON.stringify(r)}),history.push({role:"user",content:"Continue your response with the last function result"})}catch(e){console.error("Router error:",e),addBubble("sys","Router error: "+e.message)}if(n>=3)return;if((r&&Array.isArray(r.handled)?r.handled:[]).some(e=>e&&"action"===e.tag)){const e=await fetch("",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({messages:history,last_func_call:Array.isArray(t)?t:[]})}),i=await e.json();if(i.error)addBubble("sys","followup_error: "+i.error);else{const e=Array.isArray(i.assistant_tool_calls)?i.assistant_tool_calls:[];await handleAiOutput(String(i.answer??""),e,n+1)}return}const s=resolveInterest(i,t,r);if(s){const e=Math.max(0,Math.min(1,s.confidence+.2));if(Math.random()<e){sys("Interest trigger p="+e.toFixed(2)+(s.topics.length?" ["+s.topics.join(", ")+"]":""));const i=await fetch("",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({messages:history,last_func_call:Array.isArray(t)?t:[]})}),o=await i.json();if(o.error)addBubble("sys","interest_followup_error: "+o.error);else{const e=Array.isArray(o.assistant_tool_calls)?o.assistant_tool_calls:[];await handleAiOutput(String(o.answer??""),e,n+1)}}}}function safeParseJSON(e){try{return JSON.parse(String(e||""))}catch{return null}}function getToolFnArgs(e,t){if(!Array.isArray(e))return null;for(const n of e){if(!n||"function"!==n.type)continue;const e=n.function||{};if((e.name||"").toLowerCase()===t)return safeParseJSON(e.arguments)}return null}function getRouterHandled(e,t){if(!e||!Array.isArray(e.handled))return null;for(const n of e.handled)if(n&&(n.tag||"").toLowerCase()===t)return n;return null}function applyDebugOps(e){e.forEach(e=>{if("debug"===e.tag&&e.json&&"string"==typeof e.json.op){const t=e.json.op.toLowerCase();"on"===t?(document.documentElement.setAttribute("data-debug","on"),addBubble("sys","Debug mode ON")):"off"===t&&(document.documentElement.setAttribute("data-debug","off"),addBubble("sys","Debug mode OFF"))}})}function applyDebugOpsFrom(e,t){const n=getToolFnArgs(e,"debug");let i=n&&"string"==typeof n.op?n.op.toLowerCase():null;if(!i){const e=getRouterHandled(t,"debug");e&&e.payload&&"string"==typeof e.payload&&(i=e.payload.toLowerCase())}i&&("on"===i?(document.documentElement.setAttribute("data-debug","on"),addBubble("sys","Debug mode ON")):"off"===i&&(document.documentElement.setAttribute("data-debug","off"),addBubble("sys","Debug mode OFF")))}function extractInterestFrom(e,t){let n=getToolFnArgs(e,"interest");if(!n){const e=getRouterHandled(t,"interest");e&&e.payload&&"object"==typeof e.payload&&(n=e.payload)}if(!n)return null;const i=Number(n.confidence);return isFinite(i)?{topics:Array.isArray(n.topics)?n.topics.slice(0,4):[],reason:"string"==typeof n.reason?n.reason:"",confidence:Math.max(0,Math.min(1,i))}:null}function resolveInterest(e,t,n){const i=extractInterestFrom(t,n);if(i&&(i.topics.length||i.reason))return i;const o=extractInterest(e);return o&&(o.topics.length||o.reason)?o:null}function tailRole(){return history.length?history[history.length-1].role:""}function handleKeyboardToggle(e){e!==_kbPrev&&(_kbPrev=e,isKeyboardOpen=e,e?(pendingTimer&&(clearTimeout(pendingTimer),pendingTimer=null),typingTimer&&(clearTimeout(typingTimer),typingTimer=null,typingETA=0),clearPending(),pendingTextEl.textContent="Idle (keyboard open)",updatePendingText()):inFlight||"user"!==tailRole()?updatePendingText():scheduleTurn())}pendingEl.classList.add("on"),updatePendingText(),setInterval(updatePendingText,500),function(){const e=document.documentElement,t=window.visualViewport;function n(){const n=Math.abs(chat.scrollHeight-chat.scrollTop-chat.clientHeight)<2,i=chat.scrollTop,o=t?t.height:window.innerHeight,r=t?t.offsetTop:0;e.style.setProperty("--app-h",o+"px"),e.style.setProperty("--vv-top",r+"px");const s=imeOpen(),a=e.classList.contains("ime-open");e.classList.toggle("ime-open",s),s!==a&&(handleKeyboardToggle(s),s&&!a&&requestAnimationFrame(()=>{chat.scrollTop=i})),!s&&n&&(chat.scrollTop=chat.scrollHeight)}n(),t?.addEventListener("resize",n),t?.addEventListener?.("geometrychange",n),window.addEventListener("orientationchange",n)}(),sys("New session started"),btn.addEventListener("click",sendMessage),box.addEventListener("keydown",e=>{229!==e.keyCode?"Enter"!==e.key||e.shiftKey||(e.preventDefault(),sendMessage()):rearmTypingGrace()}),box.addEventListener("focus",()=>{imeOpen()?handleKeyboardToggle(!0):(pendingTimer&&(clearTimeout(pendingTimer),pendingTimer=null),clearPending(),typingTimer&&(clearTimeout(typingTimer),typingTimer=null,typingETA=0),typingETA=Date.now()+typingIdleMs,typingTimer=setTimeout(()=>{typingTimer=null,typingETA=0,inFlight||"user"!==tailRole()?updatePendingText():scheduleTurn()},typingIdleMs),updatePendingText())}),box.addEventListener("input",e=>{const t=box.value.trim().length>0;e.isComposing||isComposing||t?rearmTypingGrace():(pendingTimer&&(clearTimeout(pendingTimer),pendingTimer=null),clearPending(),typingTimer&&(clearTimeout(typingTimer),typingTimer=null,typingETA=0),t?(typingETA=Date.now()+typingIdleMs,typingTimer=setTimeout(()=>{typingTimer=null,typingETA=0,inFlight||"user"!==tailRole()?updatePendingText():scheduleTurn()},typingIdleMs)):inFlight||"user"!==tailRole()||scheduleTurn())}),box.addEventListener("blur",()=>{typingTimer&&(clearTimeout(typingTimer),typingTimer=null,typingETA=0,updatePendingText()),!inFlight&&!pendingTimer&&queuedCount>lastTurnQueuedCount&&scheduleTurn()}),box.addEventListener("compositionstart",()=>{isComposing=!0,handleKeyboardToggle(!0)}),box.addEventListener("compositionend",()=>{isComposing=!1}),box.addEventListener("touchstart",()=>{IS_TOUCH&&rearmTypingGrace()},{passive:!0}),box.addEventListener("compositionupdate",()=>{rearmTypingGrace()});</script>