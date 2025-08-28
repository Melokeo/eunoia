#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Background updater:
 * - Reads Google config: /var/lib/euno/secrets/google/google.json
 * - Auth via auth_config (oauth2 or service_account)
 * - Fetches upcoming events (now ? +31 days)
 * - Upserts minimal tasks into /var/lib/euno/memory/tasks.json
 *
 * Requires (composer):
 *   composer require google/apiclient:^2.17
 */

use Google\Client;
use Google\Service\Calendar;

require_once '/var/www/typecho/vendor/autoload.php';
require_once '/var/lib/euno/lib/task-store.php';
require_once '/var/lib/euno/lib/fetch-ical.php';

// ---------- config ----------
const GOOGLE_CFG   = '/var/lib/euno/secrets/google/google.json';
const ICAL_CFG     = '/var/lib/euno/secrets/icals.json';
const TASKS_PATH   = '/var/lib/euno/memory/tasks.json';
const HORIZON_DAYS = 31;
const DEFAULT_IMPORTANCE = 'low';
const GMAIL_TAGS = ['calendar'];

// ---------- helpers ----------
function loadGoogleCfg(): array {
    if (!file_exists(GOOGLE_CFG)) {
        fwrite(STDERR, "Missing config: " . GOOGLE_CFG . PHP_EOL);
        exit(1);
    }
    $cfg = json_decode((string)file_get_contents(GOOGLE_CFG), true);
    if (!is_array($cfg) || !isset($cfg['auth_config']) || !is_array($cfg['auth_config'])) {
        fwrite(STDERR, "Invalid google.json (missing auth_config)" . PHP_EOL);
        exit(1);
    }
    return $cfg;
}

/**
 * Build a Google Client from auth_config:
 *   oauth2:
 *     - credentials_file (required)
 *     - token_file (required at runtime; obtain once via OAuth flow)
 *   service_account:
 *     - service_account_file (required)
 *     - subject (optional, domain-wide delegation)
 */
function buildClient(array $auth): Client {
    $type = strtolower((string)($auth['type'] ?? 'oauth2'));

    $client = new Client();
    $client->setApplicationName('Calendar Sync'); // neutral label
    $client->setScopes([Calendar::CALENDAR_READONLY]);
    $client->setAccessType('offline');

    if ($type === 'service_account') {
        $sa = (string)($auth['service_account_file'] ?? '');
        if ($sa === '' || !file_exists($sa)) {
            fwrite(STDERR, "Missing or invalid service_account_file" . PHP_EOL);
            exit(2);
        }
        $client->setAuthConfig($sa);
        if (!empty($auth['subject'])) {
            $client->setSubject((string)$auth['subject']);
        }
        return $client;
    }

    // oauth2
    $cred = (string)($auth['credentials_file'] ?? '');
    $tok  = (string)($auth['token_file'] ?? '');
    if ($cred === '' || !file_exists($cred)) {
        fwrite(STDERR, "Missing credentials_file" . PHP_EOL);
        exit(2);
    }
    if ($tok === '') {
        fwrite(STDERR, "Missing token_file path" . PHP_EOL);
        exit(2);
    }

    $client->setPrompt('select_account consent');
    $client->setAuthConfig($cred);

    // Load existing token if present
    if (file_exists($tok)) {
        $accessToken = json_decode((string)file_get_contents($tok), true);
        if (is_array($accessToken)) {
            $client->setAccessToken($accessToken);
        }
    }

    // Refresh if expired and a refresh_token exists
    if ($client->isAccessTokenExpired()) {
        $refreshToken = $client->getRefreshToken();
        if ($refreshToken) {
            // Correct method to refresh:
            $client->fetchAccessTokenWithRefreshToken($refreshToken); // or $client->refreshToken($refreshToken)
            if (!is_dir(dirname($tok))) {
                mkdir(dirname($tok), 0700, true);
            }
            file_put_contents(
                $tok,
                json_encode($client->getAccessToken(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
            chmod($tok, 0600);
        } else {
            fwrite(STDERR, "Token expired and no refresh token present. Re-authorize to create token.json." . PHP_EOL);
            exit(2);
        }
    }

    return $client;
}

function ymd(\DateTimeInterface $dt): string { return $dt->format('Y-m-d'); }
function hm(?\DateTimeInterface $dt): ?string { return $dt ? $dt->format('H:i') : null; }

// ---------- main ----------
$cfg     = loadGoogleCfg();
$auth    = $cfg['auth_config'];
$client  = buildClient($auth);
$service = new Calendar($client);
$store   = new TaskStore();

$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$endUtc = $nowUtc->modify('+' . HORIZON_DAYS . ' days');

$params = [
    'timeMin'      => $nowUtc->format(DATE_RFC3339_EXTENDED),
    'timeMax'      => $endUtc->format(DATE_RFC3339_EXTENDED),
    'singleEvents' => true,
    'orderBy'      => 'startTime',
    'maxResults'   => 500,
];

// collect all calendars (primary + shared)
$targetCals = ['primary', 'MONKEYS_RNEL'];
$calendars = [];
$clPage = null;
do {
    $list = $service->calendarList->listCalendarList(['pageToken' => $clPage]);
    foreach ($list->getItems() as $c) {
        $name = $c->getSummaryOverride() ?: $c->getSummary();
        if (in_array($name, $targetCals, true) || 
            (in_array('primary', $targetCals, true) && $c->getPrimary())) {
            $calendars[] = [
                'id'   => $c->getId(),
                'name' => $c->getPrimary() ? 'primary' : $name,
            ];
        }
    }
    $clPage = $list->getNextPageToken();
} while ($clPage);

if (empty($calendars)) {
    fwrite(STDERR, "Target calendars not found\n");
    exit(3);
}

$inserted = 0;
$updated = 0;
foreach ($calendars as $cal) {
    $calId = $cal['id'];
    $calTag = 'gcal:' . preg_replace('/\s+/', '_', strtolower($cal['name']));
    $pageToken = null;

    do {
        if ($pageToken) { $params['pageToken'] = $pageToken; } else { unset($params['pageToken']); }
        $events = $service->events->listEvents($calId, $params);

        foreach ($events->getItems() as $e) {
            // start
            $startDt = $e->getStart()->getDateTime();
            $startTz = $e->getStart()->getTimeZone();
            if ($startDt) {
                $start = $startTz
                    ? new DateTimeImmutable($startDt, new DateTimeZone($startTz))
                    : new DateTimeImmutable($startDt);
                $isTimedStart = true;
            } else {
                // all-day
                $start = new DateTimeImmutable($e->getStart()->getDate() . ' 00:00:00', new DateTimeZone('UTC'));
                $isTimedStart = false;
            }

            // end
            $endDt = $e->getEnd()->getDateTime();
            $endTz = $e->getEnd()->getTimeZone();
            if ($endDt) {
                $end = $endTz
                    ? new DateTimeImmutable($endDt, new DateTimeZone($endTz))
                    : new DateTimeImmutable($endDt);
                $isTimedEnd = true;
            } else {
                $end = $e->getEnd()->getDate()
                    ? new DateTimeImmutable($e->getEnd()->getDate() . ' 00:00:00', new DateTimeZone('UTC'))
                    : $start->modify('+1 hour');
                $isTimedEnd = false;
            }

            $time = ['date' => ymd($start)];
            if ($isTimedStart) { $time['start'] = hm($start); }
            if ($isTimedEnd)   { $time['end']   = hm($end);   }

            $tags = array_values(array_unique(array_merge(GMAIL_TAGS, [$calTag])));

            $name = trim((string)$e->getSummary());
            if ($name === '') {
                $name = 'Untitled';
            }

            $task = [
                'name'       => $name,
                'importance' => DEFAULT_IMPORTANCE,
                'tags'       => $tags,
                'time'       => $time,
                'updates'    => [],
            ];

            $wasInsert = $store->upsert($task);
            $wasInsert ? $inserted++ : $updated++;
        }

        $pageToken = $events->getNextPageToken();
    } while ($pageToken);
};

echo sprintf(
    "[%s] Google primary synced: inserted=%d updated=%d horizon=%dd\n",
    (new DateTimeImmutable('now'))->format('c'),
    $inserted,
    $updated,
    HORIZON_DAYS
);

// now process ICS
$icsInserted = 0;
$icsUpdated = 0;

if (file_exists(ICAL_CFG)) {
    $rawCfg = json_decode((string)file_get_contents(ICAL_CFG), true);
    if (is_array($rawCfg)) {
        $icsFeeds = $rawCfg;
    }
}

if (!empty($icsFeeds)) {
    foreach ($icsFeeds as $feed) {
        $url = is_array($feed) ? (string)($feed['ical'] ?? '') : '';
        if ($url === '') continue;

        $raw = fetchIcs($url);
        if ($raw === '') continue;

        $icsEvents = icsExtractEvents($raw, $nowUtc, $endUtc);

        // Build tags: default + per-feed tags
        $tags  = [];
        if (isset($feed['tag']) && is_string($feed['tag']) && $feed['tag'] !== '') {
            $tags[] = $feed['tag'];
        }
        if (isset($feed['tags']) && is_array($feed['tags'])) {
            foreach ($feed['tags'] as $t) {
                if (is_string($t) && $t !== '') $tags[] = $t;
            }
        }
        $tags = array_values(array_unique($tags));

        // Upsert like gcal
        foreach ($icsEvents as $ev) {
            $time = ['date' => ymd($ev['start'])];
            if (!$ev['allDay']) {
                $time['start'] = hm($ev['start']);
                $time['end']   = hm($ev['end']);
            }

            $name = trim((string)($ev['summary'] ?? ''));
            if ($name === '') {
                $name = 'Untitled';
            }

            $task = [
                'name'       => $name,
                'importance' => DEFAULT_IMPORTANCE,
                'tags'       => $tags,
                'time'       => $time,
                'updates'    => [],
            ];

            $wasInsert = $store->upsert($task);
            $wasInsert ? $icsInserted++ : $icsUpdated++;
        }
    }

    echo sprintf(
        "[%s] ICS feeds synced: inserted=%d updated=%d feeds=%d horizon=%dd\n",
        (new DateTimeImmutable('now'))->format('c'),
        $icsInserted,
        $icsUpdated,
        count($icsFeeds),
        HORIZON_DAYS
    );
}