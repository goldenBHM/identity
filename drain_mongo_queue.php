<?php

/**
 * drain_mongo_queue.php — persistent CLI daemon.
 *
 * Drains mongo_write_queue (populated by the public endpoints) and replays each
 * queued call through ConsumerDatabase. This is the only process that writes
 * events to MongoDB, which buys three things:
 *
 *   1. No user-facing request ever waits on MongoDB, so a slow or re-electing
 *      cluster cannot hold php-fpm workers or ramp the pool.
 *   2. One long-lived process means one client and one set of authenticated
 *      connections for the life of the daemon — no per-worker connection churn.
 *   3. Writes arrive as a steady trickle rather than in bursts, which matters a
 *      great deal to a cache-constrained cluster.
 *
 * Deliberately crash-only: on an unexpected error it exits non-zero and lets
 * systemd restart it, rather than carrying reconnect logic for every dependency.
 *
 * Install (see deploy/drain-mongo-queue.service):
 *   sudo cp deploy/drain-mongo-queue.service /etc/systemd/system/
 *   sudo systemctl daemon-reload
 *   sudo systemctl enable --now drain-mongo-queue
 *
 * Migration must be applied first:
 *   mysql <db> < includes/migrations/mongo_write_queue.sql
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI only\n";
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';
$env = parse_ini_file(__DIR__ . '/../environments/.env-fingerprint');
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/ConsumerDatabase.php';
require __DIR__ . '/includes/MongoWriteQueue.php';

const BATCH_SIZE          = 100;   // rows fetched from MySQL per pass
// Operations per BulkWrite. Kept small on purpose: if a batch times out, which
// operations committed is unknown, so the rows are replayed — and replaying a
// committed $push duplicates creatives. This caps that exposure per incident
// while still removing ~96% of the round trips and w=majority waits.
const BATCH_WRITE_SIZE    = 25;
const IDLE_SLEEP_SECONDS  = 1;
const MAX_ATTEMPTS        = 10;    // past this a row is parked, not retried
const BACKOFF_START       = 5;     // seconds, when the cluster is unreachable
const BACKOFF_MAX         = 60;
const OUTAGE_ALERT_AFTER  = 3;     // consecutive failed batches before alerting
const LOCK_FILE           = '/tmp/drain_mongo_queue.lock';

/**
 * Methods that can be replayed as one batched BulkWrite.
 *
 * Only single-operation writes with no reads qualify. The survey and lead-form
 * methods query first and branch on the result, so they must stay one at a time.
 *
 * Must be declared here, above the main loop. Functions are hoisted at compile
 * time but top-level const statements are not — declared further down the file
 * it would sit after exit(0) and never be defined when drainBatch() reads it.
 */
const BATCHABLE_METHODS = [
    'createBrightOffersVisitOfferEvent' => 'createBrightOffersVisitOfferEventBatch',
];

/**
 * Send a Slack alert, and never let alerting be what kills the daemon.
 *
 * Messages passed here must be stable strings: ErrorNotifier throttles on
 * md5(file + line + message), so interpolating a varying error would defeat the
 * cooldown. Volatile detail belongs in error_log.
 */
function notify($notifier, string $message): void
{
    if ($notifier === null) {
        return;
    }
    try {
        $notifier->handlePhpError(E_USER_WARNING, $message, __FILE__, __LINE__);
    } catch (Throwable $e) {
        error_log('[drain] notifier failed: ' . $e->getMessage());
    }
}

// ---------- SINGLE INSTANCE ----------
// Two drainers would duplicate work and double the connection count. The lock
// also makes a stray cron invocation harmless.
$lock = fopen(LOCK_FILE, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[drain] another instance holds the lock, exiting\n");
    exit(0);
}

// ---------- GRACEFUL SHUTDOWN ----------
// Finish the row in flight before exiting so a restart can't tear a write in half.
$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$running) {
        $running = false;
    });
    pcntl_signal(SIGINT, function () use (&$running) {
        $running = false;
    });
}

// ---------- ONE CLIENT FOR THE PROCESS LIFETIME ----------
// ConsumerDatabase caches its Manager in a static. Under php-fpm that resets
// every request; here the process is long-lived, so it finally does what its
// comment claims and holds one connection set for the life of the daemon.
$mongo = new ConsumerDatabase(
    $env['MONGO_URL'],
    $env['MONGO_DB'] ?: 'consumer_db',
    $dbBrightOffers
);

// Fail at startup rather than on the first batch. A missing batch method — or a
// constant declared below exit(0), where top-level const statements never run —
// otherwise surfaces as a crash loop minutes after deploy.
foreach (BATCHABLE_METHODS as $single => $batch) {
    foreach ([$single, $batch] as $required) {
        if (!method_exists($mongo, $required)) {
            fwrite(STDERR, "[drain] startup check failed: ConsumerDatabase::{$required}() does not exist\n");
            notify($notifier, 'drain_mongo_queue: startup check failed, batch method missing');
            exit(1);
        }
    }
}

fwrite(STDOUT, '[drain] started, pid ' . getmypid() . "\n");

$backoff = 0;
$consecutiveOutages = 0;

while ($running) {
    try {
        // After repeated failures, stop batching and replay one row at a time.
        // A batch that keeps timing out retries the same rows forever without
        // charging attempts — correct during a real outage, but a livelock if
        // the batch is simply too large to finish inside socketTimeoutMS. One
        // op is ~25x less work, so if anything can get through, a single row
        // can; and if singles fail too it is a genuine outage and backing off
        // indefinitely is the right behaviour.
        $forceSingly = $consecutiveOutages >= 2;

        [$processed, $clusterDown] = drainBatch($dbBrightOffers, $mongo, $notifier, $forceSingly);

        if ($clusterDown) {
            // Not the rows' fault — don't burn their attempt budget. Back off
            // and stop hammering a cluster that is already struggling.
            $backoff = $backoff === 0 ? BACKOFF_START : min($backoff * 2, BACKOFF_MAX);
            $consecutiveOutages++;
            fwrite(STDERR, "[drain] cluster unreachable, backing off {$backoff}s\n");

            // Alert only once the outage is sustained — a single blip during a
            // normal election is not worth waking anyone. The notifier's own
            // 10-minute throttle keeps this to one Slack post per outage.
            if ($consecutiveOutages === OUTAGE_ALERT_AFTER) {
                notify($notifier, 'drain_mongo_queue: MongoDB unreachable, queue is not draining');
            }

            sleep($backoff);
            continue;
        }

        if ($consecutiveOutages >= OUTAGE_ALERT_AFTER) {
            notify($notifier, 'drain_mongo_queue: MongoDB reachable again, queue draining');
        }

        $backoff = 0;
        $consecutiveOutages = 0;

        // A full batch means there is more waiting: loop straight back round to
        // catch up. Anything less means the queue is drained, so idle briefly.
        if ($processed < BATCH_SIZE) {
            sleep(IDLE_SLEEP_SECONDS);
        }
    } catch (Throwable $e) {
        // Crash-only: let systemd restart us with a clean slate. Alert first —
        // a daemon that dies quietly means the queue silently stops draining.
        fwrite(STDERR, '[drain] fatal: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
        error_log('[drain] fatal: ' . get_class($e) . ': ' . $e->getMessage());
        // Include the exception class but not the message: the class is
        // low-cardinality so the notifier's throttle still works, while giving
        // the alert enough to act on without opening the journal.
        notify($notifier, 'drain_mongo_queue: daemon exited on a fatal ' . get_class($e));
        exit(1);
    }
}

fwrite(STDOUT, "[drain] shutting down cleanly\n");
exit(0);


/**
 * Does this exception, or anything it wraps, indicate a connection failure?
 *
 * A bulk write that times out surfaces as BulkWriteException, which extends
 * ServerException — NOT ConnectionException. Matching on the top-level class
 * therefore misses the most common failure path entirely, so the backoff never
 * fires and every row in the batch burns an attempt instead. Walk the chain.
 */
function isConnectionFailure(Throwable $e): bool
{
    for ($x = $e; $x !== null; $x = $x->getPrevious()) {
        if ($x instanceof MongoDB\Driver\Exception\ConnectionException) {
            return true;
        }
    }

    // The driver does not always chain the cause as a previous exception — the
    // bulk write wrapper only names it in the message. Observed in production:
    // "Bulk write failed due to previous ...ConnectionTimeoutException: ..."
    $message = $e->getMessage();

    foreach ([
        'ConnectionTimeoutException',
        'ConnectionException',
        // Replica-set state changes arrive as server errors, not connection
        // errors, but mean the same thing here: there is no primary to write to,
        // and it is not this row's fault. Charging attempts for these would park
        // the queue during an ordinary failover. Seen in production as
        // "Not primary so we cannot begin or continue a transaction".
        'not primary',
        'not master',        // pre-5.0 wording for the same condition
        'NotWritablePrimary',
        'node is recovering',
        'ReplicaSetNoPrimary',
        'No suitable servers found',
    ] as $needle) {
        if (stripos($message, $needle) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Process up to BATCH_SIZE queued writes.
 *
 * Rows are consumed in strict id order. Leading runs of the same batchable
 * method are sent as one BulkWrite; everything else is replayed one at a time.
 * Grouping by *consecutive run* rather than by method keeps global insertion
 * order intact — which matters because $push order determines the order of the
 * creatives array.
 *
 * @return array{0:int,1:bool} [rows processed, cluster looked unreachable]
 */
function drainBatch(mysqli $sql, ConsumerDatabase $mongo, $notifier, bool $forceSingly = false): array
{
    $res = $sql->query(
        "SELECT id, method, args, attempts FROM mongo_write_queue
         WHERE attempts < " . MAX_ATTEMPTS . "
         ORDER BY id ASC
         LIMIT " . BATCH_SIZE
    );
    if ($res === false) {
        throw new RuntimeException('queue SELECT failed: ' . $sql->error);
    }

    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();

    if (!$rows) {
        return [0, false];
    }

    $del  = $sql->prepare("DELETE FROM mongo_write_queue WHERE id = ?");
    $fail = $sql->prepare(
        "UPDATE mongo_write_queue
         SET attempts = attempts + 1, last_error = ?, last_attempt_at = NOW()
         WHERE id = ?"
    );
    if ($del === false || $fail === false) {
        throw new RuntimeException('queue prepare failed: ' . $sql->error);
    }

    $processed = 0;
    $i = 0;
    $total = count($rows);

    while ($i < $total) {
        if (!$GLOBALS['running']) {
            break; // SIGTERM arrived — stop cleanly between rows
        }

        // Take the leading run of consecutive rows sharing one method.
        $method = (string) $rows[$i]['method'];
        $run = [];
        while (
            $i < $total
            && (string) $rows[$i]['method'] === $method
            && count($run) < BATCH_WRITE_SIZE
        ) {
            $run[] = $rows[$i];
            $i++;
        }

        if (!$forceSingly && count($run) > 1 && isset(BATCHABLE_METHODS[$method])) {
            [$done, $clusterDown] = drainRunBatched($run, $method, $mongo, $del, $fail, $notifier);
        } else {
            [$done, $clusterDown] = drainRunSingly($run, $mongo, $del, $fail, $notifier);
        }

        $processed += $done;

        if ($clusterDown) {
            $del->close();
            $fail->close();
            return [$processed, true];
        }
    }

    $del->close();
    $fail->close();

    return [$processed, false];
}

/**
 * Replay a run of same-method rows as one BulkWrite.
 *
 * @return array{0:int,1:bool} [rows resolved, cluster looked unreachable]
 */
function drainRunBatched(
    array $run,
    string $method,
    ConsumerDatabase $mongo,
    mysqli_stmt $del,
    mysqli_stmt $fail,
    $notifier
): array {
    $argSets = [];
    foreach ($run as $row) {
        $args = json_decode((string) $row['args'], true);
        if (!is_array($args)) {
            // A row we cannot decode must be charged an attempt individually,
            // so drop the whole run to the per-row path rather than guessing
            // which rows a partial batch covered.
            return drainRunSingly($run, $mongo, $del, $fail, $notifier);
        }
        $argSets[] = $args;
    }

    $batchMethod = BATCHABLE_METHODS[$method];

    try {
        $mongo->{$batchMethod}($argSets);

        // Whole batch committed.
        foreach ($run as $row) {
            $id = (int) $row['id'];
            $del->bind_param('i', $id);
            $del->execute();
        }

        return [count($run), false];
    } catch (Throwable $e) {
        if (isConnectionFailure($e)) {
            // Which operations committed is unknown, so leave every row queued
            // and back off. Replaying a committed $push duplicates creatives —
            // that is why BATCH_WRITE_SIZE is small.
            fwrite(STDERR, '[drain] connection error in batch of ' . count($run)
                . ' ' . $method . ': ' . $e->getMessage() . "\n");
            error_log('[drain] batch abandoned (' . count($run) . ' rows, ' . $method . '): ' . $e->getMessage());
            notify($notifier, 'drain_mongo_queue: batch abandoned, MongoDB write unreachable (' . get_class($e) . ')');
            return [0, true];
        }

        if ($e instanceof MongoDB\Driver\Exception\BulkWriteException) {
            $errors = $e->getWriteResult()->getWriteErrors();

            if ($errors) {
                // The BulkWrite is ordered, so execution stopped at the first
                // error: everything before it committed, everything after it
                // never ran and stays queued for the next pass.
                $failedIndex = $errors[0]->getIndex();

                for ($k = 0; $k < $failedIndex; $k++) {
                    $id = (int) $run[$k]['id'];
                    $del->bind_param('i', $id);
                    $del->execute();
                }

                chargeAttempt($run[$failedIndex], $method, $errors[0]->getMessage(), $fail, $notifier);

                return [$failedIndex + 1, false];
            }
        }

        // Unknown failure with no usable per-operation detail. Fall back to
        // replaying the run individually so one bad row cannot block the rest.
        fwrite(STDERR, '[drain] batch of ' . count($run) . ' ' . $method
            . ' failed, retrying singly: ' . $e->getMessage() . "\n");
        error_log("[drain] batch of " . count($run) . " {$method} failed, retrying singly: " . $e->getMessage());
        notify($notifier, 'drain_mongo_queue: batch failed opaquely, replaying singly (' . get_class($e) . ')');

        return drainRunSingly($run, $mongo, $del, $fail, $notifier);
    }
}

/**
 * Replay rows one at a time — the original path, used for non-batchable
 * methods, single-row runs, and as the fallback when a batch fails opaquely.
 *
 * @return array{0:int,1:bool} [rows resolved, cluster looked unreachable]
 */
function drainRunSingly(
    array $run,
    ConsumerDatabase $mongo,
    mysqli_stmt $del,
    mysqli_stmt $fail,
    $notifier
): array {
    $processed = 0;

    foreach ($run as $row) {
        if (!$GLOBALS['running']) {
            break;
        }

        $id     = (int) $row['id'];
        $method = (string) $row['method'];
        $args   = json_decode((string) $row['args'], true);

        try {
            if (!in_array($method, MongoWriteQueue::ALLOWED_METHODS, true)) {
                throw new RuntimeException("method not allowed: {$method}");
            }
            if (!is_array($args)) {
                throw new RuntimeException('args did not decode to an object');
            }

            // Named-argument spread: keys must match the method's parameter
            // names, so a signature change fails loudly instead of shifting
            // arguments into the wrong positions.
            $mongo->{$method}(...$args);

            // No exception means the write landed. The boolean return is not a
            // success signal — createBrightOffersVisitOfferEvent returns false
            // for a legitimate no-op when the document already matched.
            $del->bind_param('i', $id);
            $del->execute();
            $processed++;
        } catch (Throwable $e) {
            if (isConnectionFailure($e)) {
                // Cluster-level: every remaining row would fail the same way, and
                // counting that against them would park the whole queue during a
                // transient outage. Leave this row untouched and signal backoff.
                fwrite(STDERR, "[drain] connection error on id={$id}: " . $e->getMessage() . "\n");
                error_log("[drain] connection error on id={$id} {$method}: " . $e->getMessage());
                notify($notifier, 'drain_mongo_queue: row abandoned, MongoDB write unreachable (' . get_class($e) . ')');
                return [$processed, true];
            }

            chargeAttempt($row, $method, $e->getMessage(), $fail, $notifier);
            $processed++;
        }
    }

    return [$processed, false];
}

/**
 * Record a row-level failure and alert once the row is about to be parked.
 */
function chargeAttempt(array $row, string $method, string $message, mysqli_stmt $fail, $notifier): void
{
    $id       = (int) $row['id'];
    $attempts = (int) $row['attempts'];
    $err      = substr($message, 0, 2000);

    $fail->bind_param('si', $err, $id);
    $fail->execute();

    error_log("[drain] id={$id} {$method} failed (attempt " . ($attempts + 1) . "): {$err}");

    if ($attempts + 1 === MAX_ATTEMPTS) {
        notify($notifier, 'mongo_write_queue: a row was parked after ' . MAX_ATTEMPTS . ' failed attempts');
    }
}
