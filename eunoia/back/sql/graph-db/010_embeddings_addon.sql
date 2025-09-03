-- Optional embeddings addon; safe to skip or re-run
-- USE euno;

CREATE TABLE IF NOT EXISTS graph_embeddings (
  node_id    VARCHAR(32) NOT NULL PRIMARY KEY,  -- FK to graph_nodes.id
  dim        SMALLINT    NOT NULL,              -- e.g., 384, 768
  dtype      ENUM('f32','f16') NOT NULL DEFAULT 'f32',
  vec_blob   LONGBLOB    NOT NULL,              -- raw bytes; length = dim * bytes_per_comp
  updated_ts DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_emb_node FOREIGN KEY (node_id) REFERENCES graph_nodes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Helper view to expose byte length and inferred dim if needed
CREATE OR REPLACE VIEW graph_embeddings_info AS
SELECT
  e.node_id,
  n.type AS node_type,
  n.title AS node_title,
  e.dim,
  e.dtype,
  OCTET_LENGTH(e.vec_blob) AS bytes,
  e.updated_ts
FROM graph_embeddings e
JOIN graph_nodes n ON n.id = e.node_id;
