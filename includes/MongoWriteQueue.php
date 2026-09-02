<?php

/**
 * Write-behind queue for MongoDB writes.
 *
 * Public endpoints call MongoWriteQueue::enqueue() and return immediately, so
 * no user-facing request ever waits on MongoDB. drain_mongo_queue.php runs as a
 * persistent daemon and replays each queued call through ConsumerDatabase, so
 * the Mongo logic lives in exactly one place instead of being duplicated here.
 *
 * Arguments are stored as a JSON object keyed by parameter name and replayed
 * with PHP 8 named-argument spreading. Positional arrays would silently mismap
 * if a method signature were ever reordered; named arguments fail loudly.
 */
class MongoWriteQueue
{
    /**
     * ConsumerDatabase methods the drainer is allowed to invoke.
     *
     * Queue rows are data, and data must never be able to name an arbitrary
     * method to call. Both enqueue() and the drainer check against this list.
     */
    public const ALLOWED_METHODS = [
        'createBrightOffersVisitOfferEvent',
        'createBrightOffersVisitSurveyEvent',
        'createLeadFormVisitWallEvent',
        'createLeadFormSubmission',
        'createPrepopFormSubmission',
        'createSurveySubmission',
    ];

    /**
     * Queue a MongoDB write for the drainer to perform.
     *
     * Every failure path here means an event was lost, so each one both logs and
     * raises a Slack alert through the notifier.
     *
     * @param array $args     JSON-serialisable arguments keyed by parameter name.
     * @param mixed $notifier ErrorNotifier from config.php, or null to log only.
     * @return bool true if the row was written. Callers should log a false
     *              return but must not fail the request over it.
     */
    public static function enqueue(mysqli $db, string $method, array $args, $notifier = null): bool
    {
        try {
            if (!in_array($method, self::ALLOWED_METHODS, true)) {
                self::fail($notifier, "refusing to enqueue unknown method {$method}");
                return false;
            }

            $json = json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                self::fail($notifier, "could not encode args for {$method}", json_last_error_msg());
                return false;
            }

            $stmt = $db->prepare(
                "INSERT INTO mongo_write_queue (method, args, created_at) VALUES (?, ?, NOW())"
            );
            if ($stmt === false) {
                self::fail($notifier, "queue INSERT prepare failed for {$method}", $db->error);
                return false;
            }

            $stmt->bind_param('ss', $method, $json);
            $ok = $stmt->execute();
            $err = $ok ? null : $db->error;
            $stmt->close();

            if (!$ok) {
                self::fail($notifier, "queue INSERT failed for {$method}", $err);
            }

            return (bool) $ok;
        } catch (Throwable $e) {
            // The queue is a durability mechanism, not a dependency. A MySQL
            // hiccup must never take down the endpoint.
            self::fail($notifier, "enqueue threw for {$method}", $e->getMessage());
            return false;
        }
    }

    /**
     * Log a lost write and alert on it.
     *
     * $summary is deliberately stable and free of per-request detail:
     * ErrorNotifier throttles on md5(file + line + message), so interpolating a
     * varying error string would defeat the 10-minute cooldown and post to Slack
     * once per failed request. Volatile detail goes to the log only.
     */
    private static function fail($notifier, string $summary, ?string $detail = null): void
    {
        error_log('MongoWriteQueue: ' . $summary . ($detail !== null && $detail !== '' ? ' — ' . $detail : ''));

        if ($notifier === null) {
            return;
        }

        try {
            $notifier->handlePhpError(E_USER_WARNING, 'MongoWriteQueue: ' . $summary, __FILE__, __LINE__);
        } catch (Throwable $e) {
            // The notifier writes to the same MySQL server that may have just
            // failed, and it does not guard its own prepare(), so it can throw.
            // Alerting must never be the thing that breaks the request.
            error_log('MongoWriteQueue: notifier failed: ' . $e->getMessage());
        }
    }
}
