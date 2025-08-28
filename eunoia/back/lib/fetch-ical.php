<?php
declare(strict_types=1);

function normalizeTzid(string $tzid): string {
    $tzid = trim($tzid, "\"'");
    static $map = [
        'Eastern Standard Time'        => 'America/New_York',
        'Central Standard Time'        => 'America/Chicago',
        'Mountain Standard Time'       => 'America/Denver',
        'Pacific Standard Time'        => 'America/Los_Angeles',
        'Atlantic Standard Time'       => 'America/Halifax',
        'Newfoundland Standard Time'   => 'America/St_Johns',
        'Alaskan Standard Time'        => 'America/Anchorage',
        'Hawaiian Standard Time'       => 'Pacific/Honolulu',
        'GMT Standard Time'            => 'Europe/London',
        'Greenwich Standard Time'      => 'Etc/GMT',
        'W. Europe Standard Time'      => 'Europe/Berlin',
        'Central Europe Standard Time' => 'Europe/Budapest',
        'Romance Standard Time'        => 'Europe/Paris',
        'E. Europe Standard Time'      => 'Europe/Bucharest',
        'China Standard Time'          => 'Asia/Shanghai',
        'Taipei Standard Time'         => 'Asia/Taipei',
        'Tokyo Standard Time'          => 'Asia/Tokyo',
        'Korea Standard Time'          => 'Asia/Seoul',
        'India Standard Time'          => 'Asia/Kolkata',
        'UTC'                          => 'UTC',
        'Coordinated Universal Time'   => 'UTC',
    ];
    return $map[$tzid] ?? $tzid;
}

function fetchIcs(string $url, int $timeout = 15): string {
    $ch = curl_init($url);
    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Euno/ics'
        ]
    );
    $data = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($data === false || $code >= 400) {
        fwrite(STDERR, "ICS fetch failure ($code): $url" . ($err ? " ($err)" : "(no error msg)") . PHP_EOL);
        return '';
    }

    return (string)$data;
}

function icsUnfold(string $ics): array{
    $lines = preg_split("/\r\n|\r|\n/", $ics);
    $out = [];
    foreach ($lines as $line) {
        if ($line === '') { $out[] = ''; continue; }
        if (isset($out[count($out)-1]) && (strlen($line) && ($line[0] === ' ' || $line[0] === "\t"))) {
            $out[count($out)-1] .= substr($line, 1);
        } else {
            $out[] = $line;
        }
    }
    return $out;
}

function icsParseParams(string $part): array {
    $params = [];
    $bits = explode(';', $part);
    array_shift($bits); // remove property name
    foreach ($bits as $p) {
        $kv = explode('=', $p, 2);
        if (count($kv) === 2) $params[$kv[0]] = $kv[1];
    }
    return $params;
}

function icsParseDateTime(string $value, array $params): array {
    $isAllDay = false;

    if ((isset($params['VALUE']) && strtoupper($params['VALUE']) === 'DATE') || preg_match('/^\d{8}$/', $value)) {
        $isAllDay = true;
        $tzid = isset($params['TZID']) ? normalizeTzid($params['TZID']) : date_default_timezone_get();
        $tz   = new DateTimeZone($tzid);
        $dt = DateTimeImmutable::createFromFormat('Ymd H:i:s', $value . ' 00:00:00', $tz);
        return [ $dt, true ];
    }

    if (isset($params['TZID'])) {
        $tz  = new DateTimeZone(normalizeTzid($params['TZID']));
        $fmt = strlen($value) === 15 ? 'Ymd\THis' : (strlen($value) === 13 ? 'Ymd\THi' : 'Ymd\THis');
        $dt  = DateTimeImmutable::createFromFormat($fmt, $value, $tz);
        return [ $dt, false ];
    }

    if (str_ends_with($value, 'Z')) {
        $val = rtrim($value, 'Z');
        $fmt = strlen($val) === 15 ? 'Ymd\THis' : (strlen($val) === 13 ? 'Ymd\THi' : 'Ymd\THis');
        $dt  = DateTimeImmutable::createFromFormat($fmt, $val, new DateTimeZone('UTC'));
        return [ $dt, false ];
    }

    $fmt = strlen($value) === 15 ? 'Ymd\THis' : (strlen($value) === 13 ? 'Ymd\THi' : 'Ymd\THis');
    $dt  = DateTimeImmutable::createFromFormat($fmt, $value, new DateTimeZone(date_default_timezone_get()));
    return [ $dt?->setTimezone(new DateTimeZone('UTC')), false ];
}

// main interface
function icsExtractEvents(string $ics, DateTimeImmutable $nowUtc, DateTimeImmutable $endUtc): array {
    if ($ics === '') return [];
    $lines = icsUnfold($ics);

    $events = [];
    $inEvent = false;
    $cur = ['SUMMARY' => null, 'DTSTART' => null, 'DTSTART_PARAMS' => [], 'DTEND' => null, 'DTEND_PARAMS' => []];

    foreach ($lines as $line) {
        if (stripos($line, 'BEGIN:VEVENT') === 0) {
            $inEvent = true;
            $cur = ['SUMMARY' => null, 'DTSTART' => null, 'DTSTART_PARAMS' => [], 'DTEND' => null, 'DTEND_PARAMS' => []];
            continue;
        }
        if (stripos($line, 'END:VEVENT') === 0) {
            if ($cur['DTSTART']) {
                [$startDt, $isAllDay] = icsParseDateTime($cur['DTSTART'], $cur['DTSTART_PARAMS']);

                if ($cur['DTEND']) {
                    [$endDt, $_] = icsParseDateTime($cur['DTEND'], $cur['DTEND_PARAMS']);
                } else {
                    $endDt = $isAllDay ? $startDt?->modify('+1 day') : $startDt?->modify('+1 hour');
                }

                if ($startDt && $endDt) {
                    $overlaps = ($startDt < $endUtc) && ($endDt > $nowUtc);
                    if ($overlaps) {
                        $events[] = [
                            'summary' => $cur['SUMMARY'] ?: 'Untitled',
                            'start'   => $startDt,
                            'end'     => $endDt,
                            'allDay'  => $isAllDay,
                        ];
                    }
                }
            }
            $inEvent = false;
            $cur = ['SUMMARY' => null, 'DTSTART' => null, 'DTSTART_PARAMS' => [], 'DTEND' => null, 'DTEND_PARAMS' => []];
            continue;
        }
        if (!$inEvent) continue;

        if (str_starts_with($line, 'SUMMARY')) {
            $parts = explode(':', $line, 2);
            $cur['SUMMARY'] = isset($parts[1]) ? trim($parts[1]) : null;
            continue;
        }

        if (str_starts_with($line, 'DTSTART')) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $cur['DTSTART_PARAMS'] = icsParseParams($parts[0]);
                $cur['DTSTART'] = trim($parts[1]);
            }
            continue;
        }

        if (str_starts_with($line, 'DTEND')) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $cur['DTEND_PARAMS'] = icsParseParams($parts[0]);
                $cur['DTEND'] = trim($parts[1]);
            }
            continue;
        }
    }

    return $events;
}