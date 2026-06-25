<?php

/**
 * Shared consumer-profile helpers.
 *
 * buildConsumerDocument() is the single source of truth for the shape of a
 * consumer document, used by both identify.php (live upsert) and
 * retry_consumers.php (replay from the durable MySQL queue) so the two can
 * never drift apart.
 *
 * enqueueFailedConsumer() persists a profile that failed to write to MongoDB
 * into the BrightOffers MySQL `consumer_retry_queue` table, so the failing
 * dependency (Mongo) is never its own backstop.
 */

/**
 * Build the consumer document inserted into the `consumers` collection.
 *
 * @param array $p Primitive fields:
 *   - fingerprint_hash (string)  : the uuid / _id
 *   - emails (array)             : e.g. ['user@example.com']
 *   - phones (array)
 *   - fingerprint_data (array)   : the canonicalized $norm
 *   - device_data (array)        : raw device information
 *   - prepop_data (array|null)
 *   - ip (string|null)
 *   - source (string|null)       : http origin
 *   - created_at_ms (int|null)   : original creation time in ms; defaults to now
 *                                  so replayed records keep their real creation time.
 */
function buildConsumerDocument(array $p): array
{
    $createdAtMs = isset($p['created_at_ms'])
        ? (int) $p['created_at_ms']
        : (int) floor(microtime(true) * 1000);

    return [
        '_id' => $p['fingerprint_hash'],
        'emails' => $p['emails'] ?? [],
        'phones' => $p['phones'] ?? [],
        'pii' => (object)[],
        'employment' => (object)[],
        'financial' => (object)[],
        'other' => (object)[],
        'profile_source' => [],
        'fingerprint_latest' => [
            'fingerprint_data' => $p['fingerprint_data'] ?? null,
            'device_data' => $p['device_data'] ?? null,
            'prepop_data' => $p['prepop_data'] ?? null,
            'ip' => $p['ip'] ?? null,
            'source' => $p['source'] ?? null,
        ],
        'created_at' => new MongoDB\BSON\UTCDateTime($createdAtMs),
        'updated_at' => new MongoDB\BSON\UTCDateTime(),
    ];
}

/**
 * Durably persist a consumer that failed to write to MongoDB.
 *
 * Idempotent on fingerprint_hash (the PK): repeated failures for the same uuid
 * de-duplicate and bump the attempt counter rather than creating new rows.
 *
 * Wrapped so a MySQL hiccup can never break the calling request.
 *
 * @param array $payloadFields The same primitives accepted by buildConsumerDocument().
 * @return bool true if the row was written/updated.
 */
function enqueueFailedConsumer(mysqli $db, string $fingerprintHash, array $payloadFields, ?string $lastError = null): bool
{
    try {
        $payloadJson = json_encode($payloadFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            error_log('enqueueFailedConsumer: failed to JSON-encode payload for ' . $fingerprintHash);
            return false;
        }

        $sql = "INSERT INTO consumer_retry_queue (fingerprint_hash, payload, last_error, created_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    payload = VALUES(payload),
                    attempts = attempts + 1,
                    last_error = VALUES(last_error),
                    last_attempt_at = NOW()";

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            error_log('enqueueFailedConsumer: prepare failed: ' . $db->error);
            return false;
        }

        $stmt->bind_param('sss', $fingerprintHash, $payloadJson, $lastError);
        $ok = $stmt->execute();
        $stmt->close();

        return (bool) $ok;
    } catch (Throwable $e) {
        // Never let the backstop break the request.
        error_log('enqueueFailedConsumer error for ' . $fingerprintHash . ': ' . $e->getMessage());
        return false;
    }
}
