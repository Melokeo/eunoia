<?php
declare(strict_types=1);
require '/var/lib/euno/graph-memory/Repo.php';
require '/var/lib/euno/graph-memory/Detector.php';
require '/var/lib/euno/graph-memory/Linker.php';

[$sessionId, $userMsgId, $utterance] = [$argv[1] ?? null, isset($argv[2])?(int)$argv[2]:0, $argv[3] ?? 'What about german shepherd? does euno like it?'];
if (!$sessionId || !$userMsgId) { fwrite(STDERR,"usage: euno-graph-test-det-link.php <session_id> <user_msg_id> [utterance]\n"); exit(2); }

$det = (new Detector())->detect($utterance);
print "--- Detector ---\n"; var_export($det); print "\n";

$link = (new Linker())->link($sessionId, $userMsgId, $det['entities']);
print "--- Linker ---\n"; var_export($link); print "\n";
