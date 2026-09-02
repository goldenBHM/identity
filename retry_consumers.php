<?php

/**
 * retry_consumers.php — CLI cron job.
 *
 * Drains the BrightOffers MySQL `consumer_retry_queue` table (populated by
 * identify.php when a MongoDB write fails) and re-upserts each consumer profile
 * into the `consumers` collection. Rows that succeed are deleted; rows that fail
 * are left in place with an incremented attempt counter for the next run.
 *
 * The upsert is idempotent ($setOnInsert keyed by _id), so re-running is safe
 * even if a profile was eventually created another way.
 *
 * Schedule (runs ahead of the 15-min ETL):
 *   *--/5 * * * * php /path/to/fingerprinting/retry_consumers.php >> /var/log/consumer_retry.log 2>&1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI only\n";
    exit(1);
}

use MongoDB\Client;

require_once __DIR__ . '/vendor/autoload.php';
$env = parse_ini_file(__DIR__ . '/../environments/.env-fingerprint');
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/ConsumerProfile.php';

$MONGO_URL = $env['MONGO_URL'];
$COLLECTION_NAME = $env['MONGO_COLLECTION'] ?: 'consumers';
$DB_NAME = $env['MONGO_DB'] ?: 'consumer_db';

const RETRY_BATCH_SIZE = 200;
const ALERT_ATTEMPT_THRESHOLD = 10; // alert once a row has failed this many times

// ---------- CONNECT TO MONGO ----------
try {
    // URI options belong in the second argument; as driverOptions (third
    // argument) libmongoc never reads them.
    $mongo = new Client(
        $MONGO_URL,
        [
            'retryWrites' => true,
            'retryReads' => true,
            'serverSelectionTimeoutMS' => 10000,
            'connectTimeoutMS' => 10000,
            'socketTimeoutMS' => 30000,
        ]
    );
    $collection = $mongo->selectDatabase($DB_NAME)->selectCollection($COLLECTION_NAME);
} catch (Throwable $e) {
    // Mongo still down — leave the queue intact and try again next run. Alert
    // too: a cron that aborts every run drains nothing and says nothing.
    error_log('[retry_consumers] Mongo connection failed, aborting run: ' . $e->getMessage());
    try {
        $notifier->handlePhpError(
            E_USER_WARNING,
            'retry_consumers: MongoDB unreachable, consumer_retry_queue is not draining',
            __FILE__,
            __LINE__
        );
    } catch (Throwable $ignored) {
        error_log('[retry_consumers] notifier failed: ' . $ignored->getMessage());
    }
    exit(1);
}

// ---------- READ THE QUEUE ----------
$result = $dbBrightOffers->query(
    "SELECT fingerprint_hash, payload, attempts FROM consumer_retry_queue
     ORDER BY created_at ASC LIMIT " . RETRY_BATCH_SIZE
);
if ($result === false) {
    error_log('[retry_consumers] queue SELECT failed: ' . $dbBrightOffers->error);
    exit(1);
}

$deleteStmt = $dbBrightOffers->prepare("DELETE FROM consumer_retry_queue WHERE fingerprint_hash = ?");
$failStmt = $dbBrightOffers->prepare(
    "UPDATE consumer_retry_queue
     SET attempts = attempts + 1, last_error = ?, last_attempt_at = NOW()
     WHERE fingerprint_hash = ?"
);

$processed = 0;
$succeeded = 0;
$failed = 0;

while ($row = $result->fetch_assoc()) {
    $processed++;
    $hash = $row['fingerprint_hash'];
    $payloadFields = json_decode($row['payload'], true);

    if (!is_array($payloadFields)) {
        $failed++;
        $err = 'invalid JSON payload';
        $failStmt->bind_param('ss', $err, $hash);
        $failStmt->execute();
        error_log("[retry_consumers] {$hash}: {$err}");
        continue;
    }

    // Ensure the document _id always matches the queue key.
    $payloadFields['fingerprint_hash'] = $hash;

    try {
        $collection->findOneAndUpdate(
            ['_id' => $hash],
            ['$setOnInsert' => buildConsumerDocument($payloadFields)],
            ['upsert' => true, 'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
        );

        // Success (or already present) — remove from the queue.
        $deleteStmt->bind_param('s', $hash);
        $deleteStmt->execute();
        $succeeded++;
    } catch (Throwable $e) {
        $failed++;
        $err = $e->getMessage();
        $failStmt->bind_param('ss', $err, $hash);
        $failStmt->execute();
        error_log("[retry_consumers] {$hash} retry failed: {$err}");

        $attempts = (int) $row['attempts'] + 1;
        if ($attempts === ALERT_ATTEMPT_THRESHOLD) {
            $notifier->handlePhpError(
                E_USER_WARNING,
                "consumer_retry_queue: {$hash} still failing after {$attempts} attempts: {$err}",
                __FILE__,
                __LINE__
            );
        }
    }
}

$summary = "[retry_consumers] processed={$processed} succeeded={$succeeded} failed={$failed}";
echo $summary . "\n";
error_log($summary);
