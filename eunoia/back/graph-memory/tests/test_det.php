#!/usr/bin/env php
<?php
declare(strict_types=1);

// Usage:
//   echo "Schedule a meeting with Dr. Lee at CMU next Tuesday at 3pm. Prefer Zoom. Finish \"Pici hand analysis\" by 09/02. Allocate 3 hours." | php run_detector.php
//   php run_detector.php "Finish Pici hand analysis by 2025-09-02 and prefer Zoom."

require '/var/lib/euno/graph-memory/Detector.php';

function readAllStdin(): string {
    if (posix_isatty(STDIN)) return '';
    return stream_get_contents(STDIN) ?: '';
}

$argText = $argv[1] ?? '';
$stdinText = readAllStdin();
$text = trim($stdinText !== '' ? $stdinText : $argText);

if ($text === '') {
    fwrite(STDERR, "Provide input text via STDIN or as first argument.\n");
    exit(2);
}

try {
    $det = new Detector();
    $res = $det->detect($text);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Detector error: " . $e->getMessage() . "\n");
    exit(1);
}
