#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * NDJSON → MariaDB graph importer
 * - Nodes file: lines like {"nid":"<neo4j elementId>","labels":[...],"title":null,"props":{...}}
 * - Edges file: lines like {"rid":"<neo4j rel elementId>","type":"LIKES","src":"<nid>","dst":"<nid>","weight":null,"attrs":{}}
 *
 * Usage:
 *   php /usr/local/bin/euno-graph-import-ndjson.php --nodes=/path/entities.jsonl --edges=/path/relations.jsonl
 */

const NODE_PREFIX = 'n_';
const EDGE_PREFIX = 'e_';
const ALIAS_PREFIX= 'a_';

require '/var/lib/euno/sql/db.php'; // provides db(): PDO

$options = getopt('', ['nodes:', 'edges:']);
$nodesPath = $options['nodes'] ?? null;
$edgesPath = $options['edges'] ?? null;
if (!$nodesPath || !is_readable($nodesPath)) {
  fwrite(STDERR, "Missing or unreadable --nodes file\n"); exit(2);
}
if (!$edgesPath || !is_readable($edgesPath)) {
  fwrite(STDERR, "Missing or unreadable --edges file\n"); exit(2);
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// --- helpers ---
function gid(string $prefix, string $external): string {
  // stable 26-char suffix from sha1(external)
  $h = substr(bin2hex(sha1($external, true)), 0, 26);
  return $prefix . strtoupper($h);
}
function normType(array $labels): string {
  static $allowed = ['Person','Project','Task','Preference','Artifact','Time','Quantity','Company','Product','Animal','AI','Agent','Speaker','Event','Topic','Concept','Meeting','Course','Other'];
  $candidates = array_values(array_filter($labels, fn($l) => $l !== '__Entity__' && $l !== 'Entity'));
  $t = $candidates[0] ?? 'Other';
  return in_array($t, $allowed, true) ? $t : 'Other';
}
function strvalOrNull($v): ?string {
  if (!isset($v)) return null;
  $s = is_string($v) ? $v : (is_numeric($v) ? (string)$v : null);
  return $s !== '' ? $s : null;
}
function uniqAliases(array $arr): array {
  $out = [];
  foreach ($arr as $a) {
    $a = trim((string)$a);
    if ($a === '') continue;
    $out[strtolower($a)] = $a; // case-folded dedupe
  }
  return array_values($out);
}

// --- prepared statements ---
$insNode = $pdo->prepare(
  "INSERT INTO graph_nodes (id, type, title, confidence, status, created_ts, updated_ts)
   VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP(6), CURRENT_TIMESTAMP(6))
   ON DUPLICATE KEY UPDATE title=VALUES(title), confidence=VALUES(confidence), status=VALUES(status), updated_ts=CURRENT_TIMESTAMP(6)"
);
$insAlias = $pdo->prepare(
  "INSERT IGNORE INTO graph_node_aliases (id, node_id, alias, source, weight, created_ts)
   VALUES (?, ?, ?, 'import', 1.0, CURRENT_TIMESTAMP(6))"
);
$insEdge = $pdo->prepare(
  "INSERT INTO graph_edges (id, src_id, dst_id, type, weight, attrs_json, created_ts, updated_ts)
   VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(6), CURRENT_TIMESTAMP(6))
   ON DUPLICATE KEY UPDATE weight=VALUES(weight), attrs_json=VALUES(attrs_json), updated_ts=CURRENT_TIMESTAMP(6)"
);

// --- pass 1: nodes ---
$nodeCount = 0; $aliasCount = 0;
$pdo->beginTransaction();
$fh = fopen($nodesPath, 'rb');
while (!feof($fh)) {
  $line = trim(fgets($fh) ?: '');
  if ($line === '') continue;
  $j = json_decode($line, true);
  if (!is_array($j)) continue;

  $nidExt = (string)($j['nid'] ?? '');
  if ($nidExt === '') continue;

  $labels  = is_array($j['labels'] ?? null) ? $j['labels'] : [];
  $props   = is_array($j['props'] ?? null) ? $j['props'] : [];

  // title: prefer explicit title/name; else props.id; else fallback to first non-empty label+id tail
  $title = strvalOrNull($j['title'] ?? null) ?? strvalOrNull($props['name'] ?? null) ?? strvalOrNull($props['id'] ?? null);
  if ($title === null) {
    $title = ($labels ? ($labels[0] . '#' . substr($nidExt, -6)) : ('Entity#' . substr($nidExt, -6)));
  }

  $type = normType($labels);
  $nodeId = gid(NODE_PREFIX, $nidExt);

  // confidence/status not present in builder; choose neutral defaults once on import
  $conf = 0.7;
  $status = 'promoted';

  $insNode->execute([$nodeId, $type, mb_substr($title, 0, 255), $conf, $status]);
  $nodeCount++;

  // aliases: from props.aliases if present; also add props.id and title as aliases
  $aliases = [];
  if (isset($props['aliases']) && is_array($props['aliases'])) $aliases = array_merge($aliases, $props['aliases']);
  if (isset($props['aka']) && is_array($props['aka'])) $aliases = array_merge($aliases, $props['aka']);
  if (isset($props['synonyms']) && is_array($props['synonyms'])) $aliases = array_merge($aliases, $props['synonyms']);
  if (isset($props['id'])) $aliases[] = (string)$props['id'];
  if ($title) $aliases[] = $title;

  foreach (uniqAliases($aliases) as $al) {
    $aid = gid(ALIAS_PREFIX, $nodeId . '|' . mb_strtolower($al));
    $insAlias->execute([$aid, $nodeId, mb_substr($al, 0, 255)]);
    $aliasCount++;
  }
}
fclose($fh);
$pdo->commit();

// --- pass 2: edges ---
$edgeCount = 0;
$pdo->beginTransaction();
$fh = fopen($edgesPath, 'rb');
while (!feof($fh)) {
  $line = trim(fgets($fh) ?: '');
  if ($line === '') continue;
  $j = json_decode($line, true);
  if (!is_array($j)) continue;

  $ridExt = (string)($j['rid'] ?? '');
  $srcExt = (string)($j['src'] ?? '');
  $dstExt = (string)($j['dst'] ?? '');
  $type   = strvalOrNull($j['type'] ?? null) ?? 'related_to';
  if ($ridExt === '' || $srcExt === '' || $dstExt === '') continue;

  $srcId = gid(NODE_PREFIX, $srcExt);
  $dstId = gid(NODE_PREFIX, $dstExt);
  $edgeId= gid(EDGE_PREFIX, $srcId.'|'.$type.'|'.$dstId);

  // weight and attrs from source; weight can be null → set heuristic default once
  $w = isset($j['weight']) && is_numeric($j['weight']) ? (float)$j['weight'] : 0.7;
  $attrs = isset($j['attrs']) && is_array($j['attrs']) ? $j['attrs'] : [];
  $attrsJson = json_encode($attrs, JSON_UNESCAPED_UNICODE);

  $insEdge->execute([$edgeId, $srcId, $dstId, $type, $w, $attrsJson]);
  $edgeCount++;
}
fclose($fh);
$pdo->commit();

fwrite(STDOUT, json_encode([
  'nodes_upserted'=>$nodeCount,
  'aliases_inserted'=>$aliasCount,
  'edges_upserted'=>$edgeCount
], JSON_UNESCAPED_UNICODE)."\n");
