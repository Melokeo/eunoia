-- USE euno;

-- Fulltext on node titles
ALTER TABLE graph_nodes
  ADD FULLTEXT KEY ft_graph_nodes_title (title);

-- Add FT on aliases if missing
SET @has := (
  SELECT COUNT(1)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'graph_node_aliases'
    AND index_name = 'ft_graph_alias'
);
SET @sql := IF(@has=0, 'ALTER TABLE graph_node_aliases ADD FULLTEXT KEY ft_graph_alias (alias)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Edge type index
CREATE INDEX IF NOT EXISTS idx_graph_edges_type ON graph_edges (type);
