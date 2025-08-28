-- seed one debug session (MariaDB 10.11)
START TRANSACTION;
use euno;

-- 1) session id (26-char ULID or any unique string for testing)
SET @sid = '01K3RYAJKE73RV6JEYQPQ5G0V4';

-- 2) ensure session exists
INSERT IGNORE INTO sessions (id, started_at)
VALUES (@sid, NOW(6));

-- 3) append messages in intended order (id preserves order)
INSERT INTO messages
  (session_id, ts, role, content, model, tokens_in, tokens_out, is_summary, content_hash)
VALUES
  (@sid, NOW(6), 'assistant', '', NULL, NULL, NULL, 0, UNHEX(SHA2('',256)));

COMMIT;
