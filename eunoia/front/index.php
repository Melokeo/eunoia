<?php
declare(strict_types=1);

/**
 * /var/www/typecho/euno/index.php
 * - Serves the chat UI (GET)
 * - Handles AJAX model calls (POST application/json)
 * - Model: fast Responses API model
 */

// ========== Paths ==========
const LIB_ROOT        = '/var/lib/euno';
const LOG_ROOT        = '/var/log/euno';

const MEM_ROOT        = LIB_ROOT . '/memory';
const SECRET_ROOT     = LIB_ROOT . '/secrets';
const LAST_OUT_LOG    = LOG_ROOT . '/last-output.json';
const LAST_IN_LOG     = LOG_ROOT . '/last-input.json';

// ========== Auth Settings ==========
const AUTH_CONFIG      = SECRET_ROOT . '/auth_config.json';
const AUTH_VALID_HOURS = 144;

// ========== API Configuration ========== {open-ai-new, open-ai-old, claude}
const CURR_API_TYPE   = 'claude';

const API_KEY_JSON    = SECRET_ROOT . '/claude_key.json';
const API_URL         = 'https://api.anthropic.com/v1/messages';
const API_MODEL       = 'claude-sonnet-4-5-20250929';

// ========== Feature Flags ==========
const THINKING        = true;
const TOOL_MODE       = false;
const HISTORY_LINES   = 100;

/* Alternative APIs
const API_KEY_JSON = '/var/lib/euno/secrets/openai_key.json';
const API_URL  = 'https://api.openai.com/v1/responses';
const API_MODEL    = 'gpt-4o';

const API_KEY_JSON = '/var/lib/euno/secrets/deepseek_key.json';
const API_URL  = 'https://api.deepseek.com/chat/completions';
const API_MODEL    = 'deepseek-chat';

const API_KEY_JSON = '/var/lib/euno/secrets/para_key.json';
const API_URL  = 'https://llmapi.paratera.com/chat/completions';
const API_MODEL    = 'DeepSeek-R1-0528';
*/

// ========== Dependencies ==========
require_once LIB_ROOT . '/sql/db.php';
require_once LIB_ROOT . '/graph-memory/GraphMemoryBridge.php';
require_once LIB_ROOT . '/lib/create-context.php';

require_once LIB_ROOT . '/lib/post-handler/api-parse.php';
require_once LIB_ROOT . '/lib/post-handler/builder.php';
require_once LIB_ROOT . '/lib/post-handler/call-api.php';
require_once LIB_ROOT . '/lib/post-handler/summary.php';
require_once LIB_ROOT . '/lib/post-handler/db-insert.php';

// ========== Session Init ==========
ensure_session(); // from db.php
session_start();

// ========== Img Auth ==========
if (!isset($_SESSION['auth_valid']) || $_SESSION['auth_valid'] < time()) {
    if (!isset($_COOKIE['euno_auth']) || !verify_auth_token($_COOKIE['euno_auth'])) {
        header('Location: auth.php');
        exit;
    }
    $_SESSION['auth_valid'] = time() + (AUTH_VALID_HOURS * 3600);
}

function verify_auth_token(string $token): bool {
    if (!file_exists(AUTH_CONFIG)) return false;
    $data = json_decode(file_get_contents(AUTH_CONFIG), true);
    return hash_equals($data['token'] ?? '', $token);
}

// ========== server: AJAX endpoint ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST'     // P~~~~~~O~~~~~~~~~S~~~T~~~~~
  && isset($_SERVER['CONTENT_TYPE'])
  && stripos($_SERVER['CONTENT_TYPE'], 'application/json') === 0) {

  header('Content-Type: application/json; charset=utf-8');

  $rid = bin2hex(random_bytes(8));   
  header('X-Euno-Req: '.$rid);

  // Session (cookie-backed)
  $sid = ensure_session();

  // parse post input
  $in = parse_input();
  $client_messages = $in['client_messages'];
  $last_func_call  = $in['last_func_call'];

  // construct req
  $req = create_request($sid, $client_messages, $last_func_call);
  $outgoing = $req['messages'];
  $meta     = $req['meta'];

  // debug log
  log_last_in($outgoing);
  
  // call api
  $apiKey = load_key();
  $resp   = call_model_api($outgoing, $apiKey);
  if (isset($resp['error'])) {
      echo json_encode(['error' => $resp['error']]); 
      exit;
  }

  // handling api tool call
  $data = $resp['data'];
  $answer_raw = extract_text($data, CURR_API_TYPE);
  $tool_calls = get_tool_call($data);

  // update db
  db_insert_message(
      $sid,
      $client_messages,
      $meta,
      $answer_raw,
      $tool_calls,
      API_MODEL
  );

  // main return
  echo json_encode([
      'answer' => $answer_raw,
      'assistant_tool_calls' => $tool_calls
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  // update_summary($sid);

  exit;
}

/* ---------- main steps moved to lib/post-handler/ ---------- */
function parse_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    $msgs = $data['messages'] ?? null;
    if (!is_array($msgs) || $msgs === []) {
        http_response_code(400);
        echo json_encode(['error' => 'Empty messages']);
        exit;
    }

    return [
        'client_messages' => $msgs,
        'last_func_call'  => $data['last_func_call'] ?? null,
    ];
}

/* ---------- helpers ---------- */
function load_key(): string {
    $data = json_decode(@file_get_contents(API_KEY_JSON), true);
    if (!is_array($data) || empty($data['api_key'])) {
        http_response_code(500);
        exit(json_encode(['error' => 'OpenAI key missing'], JSON_UNESCAPED_UNICODE));
    }
    return $data['api_key'];
}

function log_last_in(array $messages, int $limit = 250): void {
    $preview = array_map(function($m) use ($limit) {
        $c = (string)($m['content'] ?? '');
        $info = [
            'r'   => $m['role'] ?? '',
            'len' => mb_strlen($c),
            'text'=> mb_substr($c, 0, $limit)
        ];
        if (isset($m['tool_call_id'])) $info['tool_call_id'] = $m['tool_call_id'];
        return $info;
    }, $messages);

    file_put_contents(LAST_IN_LOG, prettyTrimmedJson($messages, $limit), LOCK_EX);
}

// sub builders moved to builder.php

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

?>

<!doctype html>
<html lang="en" data-debug="off" compact="off">
<head>
<meta charset="utf-8">
<title>M.ICU - Eunoia</title>
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover,interactive-widget=resizes-content">
<link rel="stylesheet" href="https://melokeo.icu/eunoia/assets/chat-ui.css">
</head>
<body>
<div class="wrap">
  <div class="card">
    <header>
      <h1>Eunoia</h1>
      <div class="meta">V1.2.0-251013</div>
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
    <div class="small">Key: <?=htmlspecialchars(API_KEY_JSON)?> | Endpoint: <?=htmlspecialchars(API_URL)?> | Model: <?=htmlspecialchars(API_MODEL)?></div>
  </div>
</div>

<script id='hist-data' type='application/json'>
<?= json_encode(fetch_last_messages(ensure_session(), HISTORY_LINES), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>
</script>
<script src="https://melokeo.icu/eunoia/assets/chat-ui.js"></script>
</body>
</html>