-- USE euno;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Nodes
CREATE TABLE IF NOT EXISTS graph_nodes (
  id            VARCHAR(32)  NOT NULL PRIMARY KEY,   -- 'n_' + ULID
  type          VARCHAR(32)  NOT NULL,
  title         VARCHAR(255) NOT NULL,
  attrs_json    JSON         NULL,
  confidence    DOUBLE       NOT NULL DEFAULT 0.1,
  status        ENUM('provisional','promoted') NOT NULL DEFAULT 'provisional',
  created_ts    DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_ts    DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  emb_vec       BLOB         NULL,
  KEY idx_graph_nodes_type (type),
  KEY idx_graph_nodes_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Aliases
CREATE TABLE IF NOT EXISTS graph_node_aliases (
  id         VARCHAR(32)  NOT NULL PRIMARY KEY,      -- 'a_' + ULID
  node_id    VARCHAR(32)  NOT NULL,
  alias      VARCHAR(255) NOT NULL,
  source     VARCHAR(32)  NOT NULL,                  -- span|summary|merge|manual
  weight     DOUBLE       NOT NULL DEFAULT 1.0,
  created_ts DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_alias_node FOREIGN KEY (node_id) REFERENCES graph_nodes(id) ON DELETE CASCADE,
  KEY idx_graph_alias_node (node_id, weight),
  FULLTEXT KEY ft_graph_alias (alias)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Edges
CREATE TABLE IF NOT EXISTS graph_edges (
  id         VARCHAR(32)  NOT NULL PRIMARY KEY,      -- 'e_' + ULID
  src_id     VARCHAR(32)  NOT NULL,
  dst_id     VARCHAR(32)  NOT NULL,
  type       VARCHAR(32)  NOT NULL,
  weight     DOUBLE       NOT NULL DEFAULT 0.5,
  attrs_json JSON         NULL,
  created_ts DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_ts DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_edge_src FOREIGN KEY (src_id) REFERENCES graph_nodes(id) ON DELETE CASCADE,
  CONSTRAINT fk_edge_dst FOREIGN KEY (dst_id) REFERENCES graph_nodes(id) ON DELETE CASCADE,
  UNIQUE KEY uq_graph_edges (src_id, dst_id, type),
  KEY idx_graph_edges_src_type (src_id, type),
  KEY idx_graph_edges_dst_type (dst_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Evidence (binds to existing messages)
CREATE TABLE IF NOT EXISTS graph_evidence (
  id            VARCHAR(32)  NOT NULL PRIMARY KEY,   -- 'v_' + ULID
  subject_kind  ENUM('node','edge') NOT NULL,
  subject_id    VARCHAR(32)  NOT NULL,
  msg_id        BIGINT UNSIGNED NOT NULL,            -- references euno.messages.id (FK omitted to avoid type mismatch)
  span_json     JSON         NULL,
  created_ts    DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  KEY idx_graph_evidence_msg (msg_id),
  KEY idx_graph_evidence_subject (subject_kind, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Config KV
CREATE TABLE IF NOT EXISTS graph_config_kv (
  ns         VARCHAR(32)  NOT NULL,
  `key`      VARCHAR(64)  NOT NULL,
  value_json JSON         NOT NULL,
  updated_ts DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (ns, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Queue
CREATE TABLE IF NOT EXISTS graph_queue_jobs (
  id             VARCHAR(32)  NOT NULL PRIMARY KEY,   -- 'q_' + ULID
  kind           VARCHAR(32)  NOT NULL,
  payload_json   JSON         NULL,
  state          ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  priority       INT          NOT NULL DEFAULT 0,
  not_before_ts  DATETIME(6)  NULL,
  created_ts     DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_ts     DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  attempts       INT          NOT NULL DEFAULT 0,
  last_error     TEXT         NULL,
  KEY idx_graph_jobs_state_prio (state, priority, not_before_ts),
  KEY idx_graph_jobs_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS graph_job_runs (
  id          VARCHAR(32)  NOT NULL PRIMARY KEY,     -- 'r_' + ULID
  job_id      VARCHAR(32)  NOT NULL,
  state       ENUM('running','done','failed') NOT NULL,
  started_ts  DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  finished_ts DATETIME(6)  NULL,
  info_json   JSON         NULL,
  KEY idx_graph_runs_job (job_id),
  CONSTRAINT fk_runs_job FOREIGN KEY (job_id) REFERENCES graph_queue_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
