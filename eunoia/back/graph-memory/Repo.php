<?php
// /var/lib/euno/graph-memory/Repo.php
declare(strict_types=1);

require_once '/var/lib/euno/sql/db.php';

/**
 * Data access layer for graph-memory on MariaDB.
 * All SQL centralized here. Returns native PHP types; no JSON to callers.
 */
final class Repo
{
    private \PDO $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? db(); // uses existing global db() helper
    }

    /** Generate prefixed ULID; falls back to time-based unique id if ulid() exists not. */
    public function newId(string $prefix): string
    {
        if (function_exists('ulid')) {
            return $prefix . ulid();
        }
        // Fallback: prefix + time-based random (sortable-ish)
        $t   = (int) floor(microtime(true) * 1000);
        $rnd = bin2hex(random_bytes(8));
        return sprintf('%s%013d%s', $prefix, $t, $rnd);
    }

    /** Get config value from graph_config_kv; returns mixed or null if missing. */
    public function getConfig(string $key, string $ns = 'graph')
    {
        $sql = 'SELECT value_json FROM graph_config_kv WHERE ns=? AND `key`=?';
        $row = $this->pdo->prepare($sql);
        $row->execute([$ns, $key]);
        $v = $row->fetchColumn();
        if ($v === false) return null;

        // Try JSON decode first, then fall back to raw string/number.
        $decoded = json_decode((string)$v, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $v;
    }

    /** Set config value; accepts scalar|array. */
    public function setConfig(string $key, $value, string $ns = 'graph'): void
    {
        $val = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        $sql = 'INSERT INTO graph_config_kv (ns, `key`, value_json) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE value_json=VALUES(value_json), updated_ts=CURRENT_TIMESTAMP(6)';
        $this->pdo->prepare($sql)->execute([$ns, $key, $val]);
    }

    /** Insert a turn row; expects pre-encoded JSON strings for *_json fields. */
    public function insertTurn(array $fields): void
    {
        $sql = 'INSERT INTO graph_turns
                (id, sid, user_msg_id, assistant_msg_id, ts_user, intent, scores_json, detections_json, slots_json, detector_version)
                VALUES (:id, :sid, :user_msg_id, :assistant_msg_id, CURRENT_TIMESTAMP(6), :intent, :scores_json, :detections_json, :slots_json, :detector_version)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id'               => $fields['id'],
            ':sid'              => $fields['sid'],
            ':user_msg_id'      => $fields['user_msg_id'],
            ':assistant_msg_id' => $fields['assistant_msg_id'],
            ':intent'           => $fields['intent'],
            ':scores_json'      => $fields['scores_json'],
            ':detections_json'  => $fields['detections_json'],
            ':slots_json'       => $fields['slots_json'],
            ':detector_version' => $fields['detector_version'],
        ]);
    }

    /** Enqueue a job; payload encoded to JSON internally. Returns job id. */
    public function enqueueJob(string $kind, array $payload = [], int $priority = 0): string
    {
        $id   = $this->newId('q_');
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $sql  = 'INSERT INTO graph_queue_jobs (id, kind, payload_json, state, priority, created_ts)
                 VALUES (?, ?, ?, "queued", ?, CURRENT_TIMESTAMP(6))';
        $this->pdo->prepare($sql)->execute([$id, $kind, $json, $priority]);
        return $id;
    }

    /** Simple alias search using FULLTEXT then LIKE fallback. */
    public function findAliases(string $q, int $limit = 20): array
    {
        $out = [];

        // Try FULLTEXT first
        $sqlFT = 'SELECT a.node_id, a.alias, a.weight
                  FROM graph_node_aliases a
                  WHERE MATCH(alias) AGAINST(:q IN NATURAL LANGUAGE MODE)
                  ORDER BY a.weight DESC LIMIT :lim';
        try {
            $stmt = $this->pdo->prepare($sqlFT);
            $stmt->bindValue(':q', $q, \PDO::PARAM_STR);
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $out = $stmt->fetchAll();
        } catch (\Throwable $e) {
            // FULLTEXT may not exist in some setups; fall back silently
        }

        if (!$out) {
            $stmt = $this->pdo->prepare(
                'SELECT a.node_id, a.alias, a.weight
                 FROM graph_node_aliases a
                 WHERE a.alias LIKE :q
                 ORDER BY a.weight DESC
                 LIMIT :lim'
            );
            $stmt->bindValue(':q', '%' . $q . '%', \PDO::PARAM_STR);
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $out = $stmt->fetchAll();
        }

        return $out ?: [];
    }

    /** Transaction helpers */
    public function begin(): void { $this->pdo->beginTransaction(); }
    public function commit(): void { $this->pdo->commit(); }
    public function rollBack(): void { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); }

    /** Insert node */
    public function insertNode(string $id, string $type, string $title, float $confidence, string $status = 'provisional'): void
    {
        $sql = 'INSERT INTO graph_nodes (id,type,title,confidence,status,created_ts,updated_ts)
                VALUES (?,?,?,?,?,CURRENT_TIMESTAMP(6),CURRENT_TIMESTAMP(6))';
        $this->pdo->prepare($sql)->execute([$id,$type,$title,$confidence,$status]);
    }

    /** Insert alias */
    public function insertAlias(string $id, string $nodeId, string $alias, string $source = 'span', float $weight = 1.0): void
    {
        $sql = 'INSERT INTO graph_node_aliases (id,node_id,alias,source,weight,created_ts)
                VALUES (?,?,?,?,?,CURRENT_TIMESTAMP(6))';
        $this->pdo->prepare($sql)->execute([$id,$nodeId,$alias,$source,$weight]);
    }

    /** Insert evidence; subject_kind in {"node","edge"}; $spanJson can be null */
    public function insertEvidence(string $subjectId, string $subjectKind, int $msgId, ?string $spanJson): void
    {
        $id  = $this->newId('v_');
        $sql = 'INSERT INTO graph_evidence (id,subject_kind,subject_id,msg_id,span_json,created_ts)
                VALUES (?,?,?,?,?,CURRENT_TIMESTAMP(6))';
        $this->pdo->prepare($sql)->execute([$id,$subjectKind,$subjectId,$msgId,$spanJson]);
    }

    /** Exact alias hit */
    public function findAliasExact(string $alias): ?array
    {
        $sql = 'SELECT node_id, alias, weight FROM graph_node_aliases WHERE alias = ? ORDER BY weight DESC LIMIT 1';
        $st  = $this->pdo->prepare($sql);
        $st->execute([$alias]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /* D */
    public function insertEdge(string $id,string $srcId,string $dstId,string $type,float $weight=0.5,?string $attrsJson=null):void {
        $sql='INSERT INTO graph_edges (id,src_id,dst_id,type,weight,attrs_json,created_ts,updated_ts)
              VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP(6),CURRENT_TIMESTAMP(6))
              ON DUPLICATE KEY UPDATE weight=VALUES(weight), attrs_json=VALUES(attrs_json), updated_ts=CURRENT_TIMESTAMP(6)';
        $this->pdo->prepare($sql)->execute([$id,$srcId,$dstId,$type,$weight,$attrsJson]);
    }

    public function getNodes(array $ids): array {
        if (!$ids) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT id,type,title,confidence,status,updated_ts
                FROM graph_nodes WHERE id IN ($placeholders)";
        $st = $this->pdo->prepare($sql);
        // bind as separate scalars
        $st->execute(array_values($ids));
        return $st->fetchAll();
    }

    public function getEdgesFromSeeds(array $seedIds): array {
        if (!$seedIds) return [];
        $placeholders = implode(',', array_fill(0, count($seedIds), '?'));
        $sql = "SELECT id,src_id,dst_id,type,weight
                FROM graph_edges
                WHERE src_id IN ($placeholders) OR dst_id IN ($placeholders)";
        $st = $this->pdo->prepare($sql);
        // need to pass the ids twice, once for src_id, once for dst_id
        $params = array_merge(array_values($seedIds), array_values($seedIds));
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Edges touching any node in $nodeIds (src or dst). */
    public function getEdgesTouching(array $nodeIds): array {
        if (!$nodeIds) return [];
        $ph = implode(',', array_fill(0, count($nodeIds), '?'));
        $sql = "SELECT id,src_id,dst_id,type,weight,attrs_json,created_ts,updated_ts
                FROM graph_edges
                WHERE src_id IN ($ph) OR dst_id IN ($ph)";
        $st = $this->pdo->prepare($sql);
        $st->execute([...array_values($nodeIds), ...array_values($nodeIds)]);
        return $st->fetchAll();
    }

    public function pdo():\PDO { return $this->pdo; }

}
