<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$path = '/var/lib/euno/memory/tasks.json';
// safe read with lock + retries
if (! function_exists('load_json_safe')) {
    function load_json_safe(string $p, int $tries = 5, int $usleep = 150000): array {
        for ($i = 1; $i <= $tries; $i++) {
            $raw = '';
            $len = 0;
            $rp  = @realpath($p) ?: $p;

            $fh = @fopen($p, 'rb');
            if ($fh === false) { error_log("[ctx] open fail: $rp"); break; }

            @flock($fh, LOCK_SH);
            $raw = stream_get_contents($fh) ?: '';
            $len = strlen($raw);
            @flock($fh, LOCK_UN);
            fclose($fh);

            // strip UTF-8 BOM
            if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) { $raw = substr($raw, 3); }

            // ensure UTF-8
            if (function_exists('mb_check_encoding') && !mb_check_encoding($raw, 'UTF-8')) {
                $raw = @iconv('UTF-8', 'UTF-8//IGNORE', $raw) ?: $raw;
                error_log("[ctx] non-utf8 cleaned");
            }

            $data = json_decode($raw, true);
            if (is_array($data)) { 
                error_log("[ctx] ok path=" . $rp . " size=$len");
                return $data;
            }

            $err = json_last_error_msg();
            // log head/tail bytes to catch hidden chars or truncation
            $head = bin2hex(substr($raw, 0, 16));
            $tail = bin2hex(substr($raw, max(0, $len - 16)));
            error_log("[ctx] decode fail try=$i err=$err size=$len head=$head tail=$tail path=$rp");

            usleep($usleep); // retry (handles concurrent write)
        }
        return [];
    }
}

$data  = load_json_safe($path);
$tasks = [];
if (isset($data['tasks']) && is_array($data['tasks'])) {
    $tasks = $data['tasks'];
} elseif (is_array($data) && isset($data[0]) && is_array($data[0])) {
    $tasks = $data; // tolerate raw array form
}
// error_log('[ctx] tasks.count=' . count($tasks));

$today = new DateTimeImmutable('today');
$limit = $today->modify('+15 days');

// sort by datetime
usort($tasks, function($a, $b) {
    $ta = $a['time'] ?? [];
    $tb = $b['time'] ?? [];
    $da = $ta['date'] ?? null;
    $db = $tb['date'] ?? null;

    if ($da && $db) {
        $sa = $ta['start'] ?? '00:00';
        $sb = $tb['start'] ?? '00:00';
        $t1 = $da . ' ' . $sa;
        $t2 = $db . ' ' . $sb;
        return strcmp($t1, $t2);
    }
    if ($da) return -1;
    if ($db) return 1;
    return 0;
});


$past = []; $present = []; $future = [];

foreach ($tasks as $t) {
    $tm = $t['time'] ?? [];
    $d  = $tm['date'] ?? null;
    $when = $d ? new DateTimeImmutable($d) : null;

    if (!$when) { $future[] = $t; continue; }

    if ($when < $today)        $past[]    = $t;
    elseif ($when <= $limit)   $present[] = $t;
    else                       $future[]  = $t;
}

// compressors
$fmt = fn($t) => trim(
    (isset($t['importance']) && $t['importance'] !== '' ? '['.$t['importance'].'] ' : '') .
    ($t['name'] ?? '(no name)') . ' - ' .
    (($t['time']['date'] ?? 'no time') .
     (isset($t['time']['start']) ? ' '.$t['time']['start'] .
        (isset($t['time']['end']) ? '-'.$t['time']['end'] : '') : ''))
);


$lines = [];
$lines[] = 'Task context policy: past & future, names only; present(in 15d) full info.';
if ($past) {
    $lines[] = 'Past:';
    foreach (array_slice($past, 0, 8) as $t) $lines[] = '- ' . ($t['name'] ?? '(no name)');
}
if ($present) {
    if ($past) $lines[] = '';
    $lines[] = 'Present (next 15 days):';
    foreach (array_slice($present, 0, 10) as $t) {
        $updates = $t['updates'] ?? [];
        $u = $updates ? ' Updates: ' . implode('; ', array_slice($updates, -2)) : '';
        $lines[] = '- ' . $fmt($t) . $u;
    }
}
if ($future) {
    if ($present) $lines[] = '';
    $lines[] = 'Future:';
    foreach (array_slice($future, 0, 8) as $t) $lines[] = '- ' . ($t['name'] ?? '(no name)');
}

echo json_encode(['context' => $lines[0] . "\n```" . implode("\n", array_slice($lines, 1)) . '```'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
