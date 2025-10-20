<?php
declare(strict_types=1);

http_response_code(403);
exit;

require_once '/var/www/typecho/vendor/autoload.php';

use Google\Client;
use Google\Service\Calendar;

const CREDENTIALS = '/var/lib/euno/secrets/google/client_secret_759723851630-kmu385eeqjaj3siomf7ieb9jnbesq6g0.apps.googleusercontent.com.json';
const TOKEN_FILE  = '/var/lib/euno/secrets/google/token.json';
const REDIRECT_URI = 'https://melokeo.icu/eunoia/google_oauth.php'; // must exactly match in Google Console

ini_set('session.cookie_secure','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax'); // Strict breaks Google callback
session_start();

if (!is_file(CREDENTIALS)) { http_response_code(500); exit('missing credentials'); }

$client = new Client();
$client->setApplicationName('Calendar Sync Init');
$client->setScopes([Calendar::CALENDAR_READONLY]);
$client->setAccessType('offline');   // obtain refresh_token
$client->setPrompt('consent');       // ensure refresh_token on first grant
$client->setIncludeGrantedScopes(true);
$client->setRedirectUri(REDIRECT_URI);
$client->setAuthConfig(CREDENTIALS);

$code  = $_GET['code']  ?? null;
$error = $_GET['error'] ?? null;

if ($error) { http_response_code(400); exit("auth error: ".htmlspecialchars($error)); }

if (!$code) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $client->setState($state);
    $authUrl = $client->createAuthUrl();

    header('Location: ' . $authUrl);
    exit;
}

if (($_GET['state'] ?? '') !== ($_SESSION['oauth_state'] ?? '')) { http_response_code(400); exit('invalid state'); }
unset($_SESSION['oauth_state']);

$token = $client->fetchAccessTokenWithAuthCode($code);
if (isset($token['error'])) { http_response_code(400); exit($token['error']); }

if (!is_dir(dirname(TOKEN_FILE))) mkdir(dirname(TOKEN_FILE), 0700, true);
file_put_contents(TOKEN_FILE, json_encode($token, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo "authorized\n";