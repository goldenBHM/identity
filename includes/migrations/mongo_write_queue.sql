-- One-time migration: run against the BrightOffers MySQL database.
--
-- Write-behind queue for MongoDB writes. Populated by the public endpoints,
-- drained by drain_mongo_queue.php.
--
-- Deliberately keyed on an AUTO_INCREMENT id rather than a natural key.
-- consumer_retry_queue uses fingerprint_hash as its PK because a consumer maps
-- to exactly one document and the upsert is idempotent on _id. Events are the
-- opposite: one consumer generates many of them, so a natural key would make
-- queued rows silently overwrite each other.
--
-- Rows are deleted on success. Rows that keep failing accumulate `attempts`
-- and are parked (skipped by the drainer) once they pass MAX_ATTEMPTS, so one
-- poison row can never block the queue. Park them, don't drop them — they are
-- the record of what was lost.

CREATE TABLE IF NOT EXISTS mongo_write_queue (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  method          VARCHAR(64)     NOT NULL,           -- ConsumerDatabase method to replay
  args            LONGTEXT        NOT NULL,           -- JSON object keyed by parameter name
  attempts        INT             NOT NULL DEFAULT 0,
  last_error      TEXT            NULL,
  created_at      DATETIME        NOT NULL,
  last_attempt_at DATETIME        NULL,
  KEY idx_drain (attempts, id),                       -- serves: WHERE attempts < N ORDER BY id
  KEY idx_created (created_at)
);

-- Health check — anything here with a high attempts count needs a human:
--   SELECT method, COUNT(*), MAX(attempts), MIN(created_at)
--   FROM mongo_write_queue GROUP BY method;
