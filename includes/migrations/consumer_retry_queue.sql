-- One-time migration: run against the BrightOffers MySQL database.
-- Durable backstop for consumer profiles that failed to write to MongoDB.
-- Drained by retry_consumers.php.

CREATE TABLE IF NOT EXISTS consumer_retry_queue (
  fingerprint_hash VARCHAR(64) NOT NULL PRIMARY KEY,
  payload          LONGTEXT    NOT NULL,           -- JSON to rebuild the consumer doc
  attempts         INT         NOT NULL DEFAULT 0,
  last_error       TEXT        NULL,
  created_at       DATETIME    NOT NULL,
  last_attempt_at  DATETIME    NULL,
  INDEX idx_created (created_at)
);
