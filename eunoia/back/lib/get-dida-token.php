<?php
// auth.php — Single-user OAuth for TickTick/Dida365
// Fill these constants, deploy at the exact REDIRECT_URI path below.

http_response_code(403);
exit;

const TT_BASE      = 'https://dida365.com'; 
const CLIENT_JSON  = '/var/lib/euno/secrets/dida_client.json';
const REDIRECT_URI = 'https://melokeo.icu/eunoia/dida_oauth.php'; // must match app settings exactly
const SCOPE        = 'tasks:read tasks:write';

session_start();

// api key
$data = json_decode(@file_get_contents(CLIENT_JSON), true);
if (!is_array($data) || empty($data['client_secret'])) {
    http_response_code(500);
    exit(json_encode(['error' => 'Dida client json missing'], JSON_UNESCAPED_UNICODE));
}

$client_id = $data['client_id'];
$client_secret = $data['client_secret'];
$pname = $data['target_project'];
$pid = $data['target_project_id'];

$cid_prev = substr($client_id, 0, 5);
$cse_prev = substr($client_secret, 0, 5);
error_log("Create dida oauth $cid_prev == $cse_prev");

function http_post_form($url, $data) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $tokenes = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($tokenes === false) throw new RuntimeException("HTTP POST failed: $err");
    $json = json_decode($tokenes, true);
    if (!is_array($json)) throw new RuntimeException("Non-JSON response: $tokenes");
    return $json;
}

$code  = $_GET['code']  ?? null;
$error = $_GET['error'] ?? null;

if ($error) {
    http_response_code(400);
    echo "Auth error: " . htmlspecialchars($error);
    exit;
}

if (!$code) {
    // Step 1: send browser to consent
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $auth = TT_BASE . '/oauth/authorize?' . http_build_query([
        'client_id'     => $client_id,
        'response_type' => 'code',
        'redirect_uri'  => REDIRECT_URI,
        'scope'         => SCOPE,
        'state'         => $state,
    ]);
    header("Location: $auth");
    exit;
}

// Step 2: validate state and exchange code for tokens
if (!isset($_SESSION['oauth_state']) || ($_GET['state'] ?? '') !== $_SESSION['oauth_state']) {
    http_response_code(400);
    echo "Invalid state.";
    exit;
}
unset($_SESSION['oauth_state']);

$token = http_post_form(TT_BASE . '/oauth/token', [
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'redirect_uri'  => REDIRECT_URI,
    'client_id'     => $client_id,
    'client_secret' => $client_secret,
]);

// Persist tokens somewhere secure; here print once for manual capture.
header('Content-Type: text/plain; charset=utf-8');
echo "access_token=" . $token['access_token'] . PHP_EOL;
echo "refresh_token=" . $token['refresh_token'] . PHP_EOL;
echo "expires_in=" . $token['expires_in'] . "s" . PHP_EOL;

// Optional: refresh helper if needed later.
echo PHP_EOL . "To refresh: POST to " . TT_BASE . "/oauth/token with" . PHP_EOL;
echo "grant_type=refresh_token&refresh_token=<stored_refresh>&client_id=" . $client_id . "&client_secret=<hidden>" . PHP_EOL;

$saved = [
    'access_token' => $token['access_token'] ?? null,
    'token_type'   => $token['token_type'] ?? 'Bearer',
    'expires_in'   => $token['expires_in'] ?? null,
    'created_at'   => time(),
    'expires_at'   => isset($token['expires_in']) ? time() + (int)$token['expires_in'] : null,
    // refresh_token may be absent
    'refresh_token'=> $token['refresh_token'] ?? null,
    'target_project' => $pname,
    'target_project_id' =>$pid,
];

file_put_contents(
    '/var/lib/euno/secrets/dida_tokens.json',
    json_encode($saved, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)
);
@chmod('/var/lib/euno/secrets/dida_tokens.json', 0600);