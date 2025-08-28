<?php
declare(strict_types=1);

require_once '/var/www/typecho/vendor/autoload.php';

use Google\Client;
use Google\Service\Calendar;

const CREDENTIALS = '/var/lib/euno/secrets/google/client_secret_1007975332091-plrhd7tndgj42c9eedrh3rq78rmk20bj.apps.googleusercontent.com.json';
const TOKEN_FILE  = '/var/lib/euno/secrets/google/token.json';

if (!is_file(CREDENTIALS)) {
    fwrite(STDERR, "Missing credentials: " . CREDENTIALS . PHP_EOL);
    exit(1);
}

$client = new Client();
$client->setApplicationName('Calendar Sync Init');
$client->setScopes([Calendar::CALENDAR_READONLY]);
$client->setAuthConfig(CREDENTIALS);
$client->setAccessType('offline');     // needed for refresh_token
$client->setPrompt('consent');         // force issuing refresh_token on first consent

$authUrl = $client->createAuthUrl();
echo "Open this URL in a browser:\n$authUrl\n\n";
echo "Enter the authorization code here: ";
$authCode = trim(fgets(STDIN));

$accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
if (isset($accessToken['error'])) {
    fwrite(STDERR, "Auth error: " . $accessToken['error'] . PHP_EOL);
    exit(2);
}

if (!is_dir(dirname(TOKEN_FILE))) {
    mkdir(dirname(TOKEN_FILE), 0700, true);
}
file_put_contents(TOKEN_FILE, json_encode($accessToken, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
chmod(TOKEN_FILE, 0600);

echo "Token stored at " . TOKEN_FILE . PHP_EOL;
