USE euno;

-- invisible “threads”, rotated silently by backend when needed
CREATE TABLE sessions (
  id         CHAR(26) PRIMARY KEY,             -- ULID (generated in PHP)
  started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  closed_at  DATETIME(6) NULL,
  title      VARCHAR(200) NULL,
  meta       JSON NULL,
  CHECK (meta IS NULL OR JSON_VALID(meta))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- append-only message log (raw content incl. fences)
CREATE TABLE messages (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id   CHAR(26) NOT NULL,
  ts           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  role         ENUM('user','assistant','system','tool') NOT NULL,
  content      MEDIUMTEXT NOT NULL,
  model        VARCHAR(64) NULL,
  tokens_in    INT NULL,
  tokens_out   INT NULL,
  is_summary   TINYINT(1) NOT NULL DEFAULT 0,
  content_hash BINARY(32) NOT NULL,                            -- PHP: hash('sha256', $content, true)
  KEY k_sid_ts (session_id, ts),
  CONSTRAINT fk_msg_sess FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
  UNIQUE KEY uq_dedupe (session_id, role, ts, content_hash)    -- idempotent writes
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- optional full-text search over content (works for latin; CJK note below)
ALTER TABLE messages ADD FULLTEXT KEY ft_content (content);
