<?php
declare(strict_types=1);

// fetch-dida.php — returns TaskStore-shaped tasks from Dida365 starting today+

/**
 * Parse a date/datetime string in task TZ to a Unix timestamp.
 * Accepts 'YYYY-MM-DD' or ISO8601. Falls back to America/New_York.
 */
function dida_parse_ts(string $s, ?string $taskTz, string $fallbackTz = 'America/New_York'): ?int {
    $tz = new DateTimeZone($taskTz ?: $fallbackTz);
    try {
        // If date only, set 00:00 in task TZ.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            $dt = new DateTime($s.' 00:00:00', $tz);
        } else {
            $dt = new DateTime($s, $tz);
        }
        return $dt->getTimestamp();
    } catch (Throwable $e) { return null; }
}

/**
 * RRULE next occurrence calculator supporting FREQ=DAILY|WEEKLY|MONTHLY and BYDAY (MO,TU,...).
 * $anchor: first occurrence timestamp in task TZ if available; else dueDate.
 * Returns next occurrence >= $fromTs in task TZ.
 */
function dida_next_occurrence(string $rrule, int $anchor, int $fromTs, string $taskTz): ?int {
    $tz = new DateTimeZone($taskTz);
    parse_str(str_replace(['RRULE:', ';'], ['', '&'], strtoupper($rrule)), $r);
    $freq = $r['FREQ'] ?? null;
    if (!$freq) return null;

    $cur = (new DateTime('@'.$anchor))->setTimezone($tz);
    $from = (new DateTime('@'.$fromTs))->setTimezone($tz);

    // Advance cur to >= from
    $step = function(DateTime $d) use ($freq, $r) {
        if ($freq === 'DAILY')      $d->modify('+1 day');
        elseif ($freq === 'WEEKLY') $d->modify('+1 week');
        elseif ($freq === 'MONTHLY')$d->modify('+1 month');
        else return false;

        // BYDAY support for WEEKLY (simple): move to nearest listed weekday in the same week-or-forward
        if ($freq === 'WEEKLY' && !empty($r['BYDAY'])) {
            $days = is_array($r['BYDAY']) ? $r['BYDAY'] : explode(',', $r['BYDAY']);
            // Map to PHP weekday numbers 1=Mon..7=Sun
            $order = ['MO'=>1,'TU'=>2,'WE'=>3,'TH'=>4,'FR'=>5,'SA'=>6,'SU'=>7];
            $targets = array_values(array_intersect_key($order, array_flip($days)));
            sort($targets);
            $w = (int)$d->format('N');
            foreach ($targets as $t) {
                $delta = $t - $w;
                $cand = clone $d;
                $cand->modify(($delta>=0?'+':'').$delta.' day');
                if ($cand >= $d) { $d->setTimestamp($cand->getTimestamp()); break; }
            }
        }
        return true;
    };

    // If anchor already before 'from', fast-forward in coarse steps
    if ($cur < $from) {
        // Rough upper bound on iterations
        for ($i=0; $i<512 && $cur < $from; $i++) { if ($step($cur) === false) break; }
    }
    return $cur->getTimestamp();
}

function dida_fetch_upcoming(
    string $tokenFile = '/var/lib/euno/secrets/dida_tokens.json',
    string $baseUrl   = 'https://api.dida365.com'
): array {
    date_default_timezone_set('America/New_York');

    $tok = json_decode(@file_get_contents($tokenFile), true);
    if (!is_array($tok) || empty($tok['access_token'])) return [];

    $h = ["Authorization: Bearer " . $tok['access_token'], "Content-Type: application/json"];

    $projects = dida_http_get($baseUrl . '/open/v1/project', $h);
    if (!is_array($projects)) {
        dida_log('Empty project list!');
        return [];
    }

    $localTz = new DateTimeZone('America/New_York');
    $today0  = (new DateTime('today', $localTz))->getTimestamp();

    $out = [];

    foreach ($projects as $p) {
        $pid = $p['id'] ?? null;
        if (!$pid) continue;

        $data  = dida_http_get($baseUrl . "/open/v1/project/$pid/data", $h);
        $tasks = isset($data['tasks']) && is_array($data['tasks']) ? $data['tasks'] : [];
        
        $projectName = (string)($p['name'] ?? '');
        // dida_log("project id=$pid name='$projectName'");

        foreach ($tasks as $t) {
            $title = $t['title'];
            // dida_log("task name='$title'");

            // 1) Skip completed
            if (!empty($t['completedTime']) || (($t['status'] ?? 0) !== 0)) continue;

            // 2) Task timezone
            $taskTz = (string)($t['timeZone'] ?? $t['timezone'] ?? 'America/New_York');

            // 3) Raw dates
            $dueRaw   = $t['dueDate']   ?? $t['dueDateTime']   ?? null;
            $startRaw = $t['startDate'] ?? $t['startDateTime'] ?? null;

            // 4) Parse timestamps in task TZ
            $dueTs   = $dueRaw   ? dida_parse_ts((string)$dueRaw, $taskTz)   : null;
            $startTs = $startRaw ? dida_parse_ts((string)$startRaw, $taskTz) : null;

            // 5) Handle repeats: compute next occurrence if past
            $rrule = $t['repeatFlag'] ?? $t['repeat'] ?? null;
            if ($rrule && $dueTs && $dueTs < $today0) {
                $next = dida_next_occurrence((string)$rrule, $dueTs, $today0, $taskTz);
                if ($next) $dueTs = $next;
            }
            if ($rrule && !$dueTs && $startTs && $startTs < $today0) {
                $next = dida_next_occurrence((string)$rrule, $startTs, $today0, $taskTz);
                if ($next) $startTs = $next;
            }

            // 6) Horizon start filter: today+ in local baseline
            $candTs = $dueTs ?? $startTs;
            if ($candTs === null) continue;

            // Convert candidate to local baseline for comparison
            $candLocal = (new DateTime('@'.$candTs))->setTimezone($localTz)->getTimestamp();
            if ($candLocal < $today0) continue;

            // 7) Compose output
            $name = trim((string)($t['title'] ?? ''));
            if ($name === '') $name = 'Untitled';

            $allDay = (bool)($t['allDay'] ?? ($dueRaw && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dueRaw)));
            $whenLocal = (new DateTime('@'.$candTs))->setTimezone($localTz);
            $time = ['date' => $whenLocal->format('Y-m-d')];
            if (!$allDay && $dueRaw) {
                $time['start'] = $whenLocal->format('H:i');
            }

            $tags = array_values(array_unique(array_filter([
                'dida',
                $projectName !== '' ? ('dida:' . preg_replace('/\s+/', '_', strtolower($projectName))) : null,
                $rrule ? 'recurring' : null,
            ])));

            $out[] = [
                'name'       => $name,
                'importance' => defined('DEFAULT_IMPORTANCE') ? DEFAULT_IMPORTANCE : 'low',
                'tags'       => $tags,
                'time'       => $time,
                'updates'    => isset($t['id']) && $t['id'] !== '' ? ["TID={$t['id']}"] : [],
            ];
        }
    }

    usort($out, function($a, $b) {
        $ad = $a['time']['date'] ?? '';
        $bd = $b['time']['date'] ?? '';
        $at = ($a['time']['start'] ?? '00:00');
        $bt = ($b['time']['start'] ?? '00:00');
        return strcmp("$ad $at", "$bd $bt");
    });
    // dida_log("returning " . count($out) . " tasks total");
    return $out;
}

function dida_http_get(string $url, array $headers): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) throw new RuntimeException("HTTP GET failed: $err");
    $json = json_decode($res, true);
    if (!is_array($json)) throw new RuntimeException("Non-JSON from $url");
    return $json;
}

function dida_log(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents('/var/log/euno/dida.log', $line, FILE_APPEND | LOCK_EX);
}
