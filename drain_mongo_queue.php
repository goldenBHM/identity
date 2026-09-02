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

const BATCH_SIZE          = 100;
const IDLE_SLEEP_SECONDS  = 1;
const MAX_ATTEMPTS        = 10;    // past this a row is parked, not retried
const BACKOFF_START       = 5;     // seconds, when the cluster is unreachable
const BACKOFF_MAX         = 60;
const OUTAGE_ALERT_AFTER  = 3;     // consecutive failed batches before alerting
const LOCK_FILE           = '/tmp/drain_mongo_queue.lock';

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

fwrite(STDOUT, '[drain] started, pid ' . getmypid() . "\n");

$backoff = 0;
$consecutiveOutages = 0;

while ($running) {
    try {
        [$processed, $clusterDown] = drainBatch($dbBrightOffers, $mongo, $notifier);

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
        fwrite(STDERR, '[drain] fatal: ' . $e->getMessage() . "\n");
        error_log('[drain] fatal: ' . $e->getMessage());
        notify($notifier, 'drain_mongo_queue: daemon exited on a fatal error');
        exit(1);
    }
}

fwrite(STDOUT, "[drain] shutting down cleanly\n");
exit(0);


/**
 * Process up to BATCH_SIZE queued writes.
 *
 * @return array{0:int,1:bool} [rows processed, cluster looked unreachable]
 */
function drainBatch(mysqli $sql, ConsumerDatabase $mongo, $notifier): array
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

    foreach ($rows as $row) {
        if (!$GLOBALS['running']) {
            break; // SIGTERM arrived — stop cleanly between rows
        }

        $id       = (int) $row['id'];
        $method   = (string) $row['method'];
        $attempts = (int) $row['attempts'];
        $args     = json_decode((string) $row['args'], true);

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
        } catch (MongoDB\Driver\Exception\ConnectionException $e) {
            // Cluster-level: every remaining row would fail the same way, and
            // counting that against them would park the whole queue during a
            // transient outage. Leave this row untouched and signal backoff.
            fwrite(STDERR, "[drain] connection error on id={$id}: " . $e->getMessage() . "\n");
            $del->close();
            $fail->close();
            return [$processed, true];
        } catch (Throwable $e) {
            // Row-level: bad payload, validation failure, unknown method.
            $err = substr($e->getMessage(), 0, 2000);
            $fail->bind_param('si', $err, $id);
            $fail->execute();
            $processed++;
            error_log("[drain] id={$id} {$method} failed (attempt " . ($attempts + 1) . "): {$err}");

            if ($attempts + 1 === MAX_ATTEMPTS) {
                $notifier->handlePhpError(
                    E_USER_WARNING,
                    "mongo_write_queue: id={$id} {$method} parked after " . MAX_ATTEMPTS . " attempts: {$err}",
                    __FILE__,
                    __LINE__
                );
            }
        }
    }

    $del->close();
    $fail->close();

    return [$processed, false];
}
