#!/usr/bin/env php
<?php
/**
 * Graph Knowledge Base Synchronizer
 * Syncs nodes.json and edges.json to MariaDB tables
 * IDs are normalized to 32 chars: prefix + base + hash padding
 */

require_once '/var/lib/euno/sql/db-credential.php';

class GraphSynchronizer {
    private PDO $db;
    private array $stats = [
        'nodes_inserted' => 0,
        'nodes_updated' => 0,
        'edges_inserted' => 0,
        'edges_updated' => 0,
        'aliases_inserted' => 0,
        'aliases_skipped' => 0,
        'errors' => []
    ];
    
    // id normalization constants
    private const ID_LENGTH = 32;
    private const NODE_PREFIX = 'n_';
    private const EDGE_PREFIX = 'e_';
    private const ALIAS_PREFIX = 'a_';
    
    // provide credentials here or load from environment/config file
    private const DB_CONFIG = [
        'host' => 'localhost',
        'port' => 3306,
        'dbname' => 'euno'
    ];
    
    private const JSON_PATH = '/var/lib/euno/memory/graph-json/';
    private const DEFAULT_SOURCE = 'json_import';
    
    public function __construct() {
        $this->connectDB();
    }
    
    private function connectDB(): void {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                self::DB_CONFIG['host'],
                self::DB_CONFIG['port'],
                self::DB_CONFIG['dbname']
            );
            
            $this->db = new PDO($dsn, DB_USR, DB_PWD);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // ensure utf8mb4 for connection
            $this->db->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
        } catch (PDOException $e) {
            die("Connection failed: " . $e->getMessage() . "\n");
        }
    }
    
    /**
     * Normalize ID to fixed 32 characters
     * Format: prefix + original_base + hash_padding
     */
    private function normalizeId(string $originalId, string $prefix): string {
        // ensure prefix is present
        if (!str_starts_with($originalId, $prefix)) {
            $originalId = $prefix . $originalId;
        }
        
        $idLen = strlen($originalId);
        
        if ($idLen >= self::ID_LENGTH) {
            // if too long, truncate and add 4-char hash at end
            $truncated = substr($originalId, 0, self::ID_LENGTH - 4);
            $hash = substr(md5($originalId), 0, 4);
            return $truncated . $hash;
        }
        
        // if too short, pad with hash
        $hashNeeded = self::ID_LENGTH - $idLen;
        $fullHash = md5($originalId . '_pad');  // deterministic hash
        $padding = substr($fullHash, 0, $hashNeeded);
        
        return $originalId . $padding;
    }
    
    /**
     * Generate alias ID: 32 chars with prefix a_
     */
    private function generateAliasId(string $nodeId, string $alias): string {
        // remove prefix from node_id for cleaner alias id
        $baseNodeId = preg_replace('/^n_/', '', $nodeId);
        $base = 'a_' . $baseNodeId . '_';
        
        // add hash of alias
        $aliasHash = substr(md5($alias), 0, 8);
        $combined = $base . $aliasHash;
        
        if (strlen($combined) > self::ID_LENGTH) {
            // truncate base and keep hash
            $maxBase = self::ID_LENGTH - 8;  // reserve 8 for hash
            return substr($combined, 0, $maxBase) . $aliasHash;
        }
        
        // pad if needed
        return $this->normalizeId($combined, 'a_');
    }
    
    public function sync(): void {
        echo "Starting synchronization...\n";
        
        try {
            $this->db->beginTransaction();
            
            // sync nodes first (edges depend on them)
            $this->syncNodes();
            
            // then sync edges
            $this->syncEdges();
            
            $this->db->commit();
            
            $this->printStats();
            
        } catch (Exception $e) {
            $this->db->rollBack();
            echo "Error during sync, rolled back: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    
    private function syncNodes(): void {
        $nodesFile = self::JSON_PATH . 'nodes.json';
        
        if (!file_exists($nodesFile)) {
            throw new Exception("Nodes file not found: $nodesFile");
        }
        
        $data = json_decode(file_get_contents($nodesFile), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON in nodes.json: " . json_last_error_msg());
        }
        
        $nodes = $data['graph_nodes'] ?? [];
        
        if (empty($nodes)) {
            echo "Warning: No nodes found in nodes.json\n";
            return;
        }
        
        // prepare statements
        $checkStmt = $this->db->prepare("SELECT confidence FROM graph_nodes WHERE id = ?");
        
        $insertStmt = $this->db->prepare("
            INSERT INTO graph_nodes (id, type, title, attrs_json, confidence, status)
            VALUES (?, ?, ?, ?, ?, 'provisional')
        ");
        
        $updateStmt = $this->db->prepare("
            UPDATE graph_nodes 
            SET type = ?, title = ?, attrs_json = ?, confidence = ?
            WHERE id = ?
        ");
        
        $aliasCheckStmt = $this->db->prepare("
            SELECT id FROM graph_node_aliases 
            WHERE node_id = ? AND alias = ? AND source = ?
        ");
        
        $aliasInsertStmt = $this->db->prepare("
            INSERT INTO graph_node_aliases (id, node_id, alias, source, weight)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        // track id mappings for edges
        $idMapping = [];
        
        foreach ($nodes as $node) {
            try {
                $originalId = $node['id'];
                $nodeId = $this->normalizeId($originalId, self::NODE_PREFIX);
                $idMapping[$originalId] = $nodeId;  // store mapping
                
                $attrs = $node['attrs_json'] ?? [];
                $attrsJson = !empty($attrs) ? json_encode($attrs) : null;
                
                // check if node exists
                $checkStmt->execute([$nodeId]);
                $existing = $checkStmt->fetch();
                
                if ($existing) {
                    // update only if confidence increased or data changed
                    if ($node['confidence'] >= $existing['confidence']) {
                        $updateStmt->execute([
                            $node['type'],
                            $node['title'],
                            $attrsJson,
                            $node['confidence'],
                            $nodeId
                        ]);
                        $this->stats['nodes_updated']++;
                    }
                } else {
                    // insert new node
                    $insertStmt->execute([
                        $nodeId,
                        $node['type'],
                        $node['title'],
                        $attrsJson,
                        $node['confidence']
                    ]);
                    $this->stats['nodes_inserted']++;
                }
                
                // handle aliases
                if (isset($attrs['aliases']) && is_array($attrs['aliases'])) {
                    foreach ($attrs['aliases'] as $alias) {
                        // check if alias exists
                        $aliasCheckStmt->execute([$nodeId, $alias, self::DEFAULT_SOURCE]);
                        
                        if (!$aliasCheckStmt->fetch()) {
                            $aliasId = $this->generateAliasId($nodeId, $alias);
                            
                            $aliasInsertStmt->execute([
                                $aliasId,
                                $nodeId,
                                $alias,
                                self::DEFAULT_SOURCE,
                                $node['confidence'] ?? 1.0
                            ]);
                            $this->stats['aliases_inserted']++;
                        } else {
                            $this->stats['aliases_skipped']++;
                        }
                    }
                }
                
            } catch (PDOException $e) {
                $this->stats['errors'][] = "Node $originalId: " . $e->getMessage();
            }
        }
        
        // save mapping for edges
        file_put_contents('/tmp/node_id_mapping.json', json_encode($idMapping));
        
        echo sprintf("Nodes: %d inserted, %d updated\n", 
            $this->stats['nodes_inserted'], 
            $this->stats['nodes_updated']
        );
    }
    
    private function syncEdges(): void {
        $edgesFile = self::JSON_PATH . 'edges.json';
        
        if (!file_exists($edgesFile)) {
            throw new Exception("Edges file not found: $edgesFile");
        }
        
        $data = json_decode(file_get_contents($edgesFile), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON in edges.json: " . json_last_error_msg());
        }
        
        $edges = $data['graph_edges'] ?? [];
        
        if (empty($edges)) {
            echo "Warning: No edges found in edges.json\n";
            return;
        }
        
        // load node id mappings
        $idMapping = [];
        if (file_exists('/tmp/node_id_mapping.json')) {
            $idMapping = json_decode(file_get_contents('/tmp/node_id_mapping.json'), true);
        }
        
        // prepare statements
        $checkStmt = $this->db->prepare("
            SELECT id, weight FROM graph_edges 
            WHERE src_id = ? AND dst_id = ? AND type = ?
        ");
        
        $insertStmt = $this->db->prepare("
            INSERT INTO graph_edges (id, src_id, dst_id, type, weight, attrs_json)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $updateStmt = $this->db->prepare("
            UPDATE graph_edges 
            SET weight = ?, attrs_json = ?
            WHERE id = ?
        ");
        
        foreach ($edges as $edge) {
            try {
                $originalEdgeId = $edge['id'];
                $edgeId = $this->normalizeId($originalEdgeId, self::EDGE_PREFIX);
                
                // map node ids to normalized versions
                $originalSrcId = $edge['src_id'];
                $originalDstId = $edge['dst_id'];
                
                // use mapping if available, otherwise normalize
                $srcId = $idMapping[$originalSrcId] ?? $this->normalizeId($originalSrcId, self::NODE_PREFIX);
                $dstId = $idMapping[$originalDstId] ?? $this->normalizeId($originalDstId, self::NODE_PREFIX);
                
                $type = $edge['type'];
                $weight = $edge['weight'] ?? 0.5;
                $attrs = $edge['attrs_json'] ?? [];
                $attrsJson = !empty($attrs) ? json_encode($attrs) : null;
                
                // check if edge exists (unique constraint on src+dst+type)
                $checkStmt->execute([$srcId, $dstId, $type]);
                $existing = $checkStmt->fetch();
                
                if ($existing) {
                    // update if weight increased or attrs changed
                    if ($weight >= $existing['weight']) {
                        $updateStmt->execute([
                            $weight,
                            $attrsJson,
                            $existing['id']  // use existing id
                        ]);
                        $this->stats['edges_updated']++;
                    }
                } else {
                    // insert new edge
                    $insertStmt->execute([
                        $edgeId,
                        $srcId,
                        $dstId,
                        $type,
                        $weight,
                        $attrsJson
                    ]);
                    $this->stats['edges_inserted']++;
                }
                
            } catch (PDOException $e) {
                // likely foreign key constraint violation if nodes don't exist
                $this->stats['errors'][] = "Edge $originalEdgeId ($srcId->$dstId): " . $e->getMessage();
            }
        }
        
        // cleanup temp file
        if (file_exists('/tmp/node_id_mapping.json')) {
            unlink('/tmp/node_id_mapping.json');
        }
        
        echo sprintf("Edges: %d inserted, %d updated\n",
            $this->stats['edges_inserted'],
            $this->stats['edges_updated']
        );
    }
    
    private function printStats(): void {
        echo "\n=== Synchronization Complete ===\n";
        echo "Nodes inserted: " . $this->stats['nodes_inserted'] . "\n";
        echo "Nodes updated: " . $this->stats['nodes_updated'] . "\n";
        echo "Edges inserted: " . $this->stats['edges_inserted'] . "\n";
        echo "Edges updated: " . $this->stats['edges_updated'] . "\n";
        echo "Aliases inserted: " . $this->stats['aliases_inserted'] . "\n";
        echo "Aliases skipped: " . $this->stats['aliases_skipped'] . "\n";
        
        if (!empty($this->stats['errors'])) {
            echo "\nErrors encountered:\n";
            foreach (array_slice($this->stats['errors'], 0, 10) as $error) {
                echo "  - $error\n";
            }
            if (count($this->stats['errors']) > 10) {
                echo "  ... and " . (count($this->stats['errors']) - 10) . " more\n";
            }
        }
    }
}

// alternative: load from environment variables
function loadConfigFromEnv(): array {
    return [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: 3306,
        'dbname' => getenv('DB_NAME') ?: 'euno',
        'username' => getenv('DB_USER') ?: die("DB_USER environment variable required\n"),
        'password' => getenv('DB_PASS') ?: die("DB_PASS environment variable required\n")
    ];
}

// main execution
if (php_sapi_name() !== 'cli') {
    die("This script must be run from command line\n");
}

try {
    $syncer = new GraphSynchronizer();
    $syncer->sync();
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}