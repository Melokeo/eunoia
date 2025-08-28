<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/var/lib/euno/sql/db.php';

$sid = ensure_session();
$lim = max(1, min(1000, (int)($_GET['limit'] ?? 200)));
$items = fetch_last_messages($sid, $lim);
echo json_encode(['items' => array_reverse($items)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
