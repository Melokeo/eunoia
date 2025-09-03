-- USE euno;

-- Turn log for memory-only metadata; no text duplication; ties to existing messages
CREATE TABLE IF NOT EXISTS graph_turns (
  id               VARCHAR(32)  NOT NULL PRIMARY KEY,   -- 't_' + ULID
  sid              VARCHAR(64)  NOT NULL,
  user_msg_id      BIGINT UNSIGNED NOT NULL,            -- references messages.id (FK omitted)
  assistant_msg_id BIGINT UNSIGNED NULL,                -- references messages.id (FK omitted)
  ts_user          DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  ts_assistant     DATETIME(6)  NULL,
  intent           VARCHAR(64)  NULL,
  scores_json      JSON         NULL,
  detections_json  JSON         NULL,
  slots_json       JSON         NULL,
  detector_version VARCHAR(16)  NOT NULL,
  turn_version     VARCHAR(16)  NOT NULL DEFAULT 'v1',
  processed        TINYINT(1)   NOT NULL DEFAULT 0,
  KEY idx_graph_turns_sid_user (sid, ts_user),
  KEY idx_graph_turns_user_msg (user_msg_id),
  KEY idx_graph_turns_assistant_msg (assistant_msg_id),
  KEY idx_graph_turns_processed (processed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
