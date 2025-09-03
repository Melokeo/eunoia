USE euno;

-- Add missing columns
SET @tbl := 'graph_turns';

-- user_msg_id
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME='user_msg_id');
SET @sql := IF(@c=0,
  'ALTER TABLE graph_turns ADD COLUMN user_msg_id BIGINT UNSIGNED NOT NULL AFTER sid',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- assistant_msg_id
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME='assistant_msg_id');
SET @sql := IF(@c=0,
  'ALTER TABLE graph_turns ADD COLUMN assistant_msg_id BIGINT UNSIGNED NULL AFTER user_msg_id',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ts_user
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME='ts_user');
SET @sql := IF(@c=0,
  'ALTER TABLE graph_turns ADD COLUMN ts_user DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) AFTER assistant_msg_id',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ts_assistant
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME='ts_assistant');
SET @sql := IF(@c=0,
  'ALTER TABLE graph_turns ADD COLUMN ts_assistant DATETIME(6) NULL AFTER ts_user',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- detector_version
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME='detector_version');
SET @sql := IF(@c=0,
  'ALTER TABLE graph_turns ADD COLUMN detector_version VARCHAR(16) NOT NULL AFTER slots_json',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- turn_version
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME='turn_version');
SET @sql := IF(@c=0,
  'ALTER TABLE graph_turns ADD COLUMN turn_version VARCHAR(16) NOT NULL DEFAULT ''v1'' AFTER detector_version',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- processed
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME='processed');
SET @sql := IF(@c=0,
  'ALTER TABLE graph_turns ADD COLUMN processed TINYINT(1) NOT NULL DEFAULT 0 AFTER turn_version',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Add missing indexes
-- (sid, ts_user)
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND INDEX_NAME='idx_graph_turns_sid_user');
SET @sql := IF(@has=0,
  'ALTER TABLE graph_turns ADD KEY idx_graph_turns_sid_user (sid, ts_user)',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- processed
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND INDEX_NAME='idx_graph_turns_processed');
SET @sql := IF(@has=0,
  'ALTER TABLE graph_turns ADD KEY idx_graph_turns_processed (processed)',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- user_msg_id
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND INDEX_NAME='idx_graph_turns_user_msg');
SET @sql := IF(@has=0,
  'ALTER TABLE graph_turns ADD KEY idx_graph_turns_user_msg (user_msg_id)',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- assistant_msg_id
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND INDEX_NAME='idx_graph_turns_assistant_msg');
SET @sql := IF(@has=0,
  'ALTER TABLE graph_turns ADD KEY idx_graph_turns_assistant_msg (assistant_msg_id)',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
