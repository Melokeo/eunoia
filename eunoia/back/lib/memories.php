<?php
declare(strict_types=1);
require_once '/var/lib/euno/lib/memory-store.php';
header('Content-Type: application/json; charset=utf-8');
$store = new MemoryStore('/var/lib/euno/memory/global.json');
echo json_encode(['entries'=>$store->listAll()], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
