<?php
declare(strict_types=1);

require '/var/lib/euno/graph-memory/Repo.php';
require '/var/lib/euno/graph-memory/Schema.php';
require '/var/lib/euno/graph-memory/Graph.php';

[$sid, $userMsgId] = [$argv[1] ?? null, isset($argv[2]) ? (int)$argv[2] : 0];
if (!$sid || !$userMsgId) { fwrite(STDERR, "usage: euno-graph-smoke.php <sid> <user_msg_id>\n"); exit(2); }

$g = new Graph();

/* Retrieval text block */
$mem = $g->retrieveText($sid, "finish Pici hand analysis");
echo "---retrieveText---\n";
echo $mem;

/* Log a turn with minimal detector output */
$det = [
  'detector_version' => 'v1',
  'intent' => ['label' => 'plan', 'score' => 0.91],
  'entities' => [['type'=>'Task','text'=>'Pici hand analysis','span'=>[0,20],'norm'=>'Pici hand analysis','score'=>0.8]],
  'slots' => ['deadline' => '2025-09-01']
];
$turnId = $g->logTurn($sid, $userMsgId, null, $det);
echo "---logTurn---\nturn_id=$turnId\n";
