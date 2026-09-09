<?php
class ConsumerDatabase
{
    // Dedupes construction within a single request only — PHP statics reset
    // between requests. Connections persist across requests via the driver's
    // own per-process client registry, keyed by URI + options.
    private static $mongoClient = null;
    private $database;
    private $dbBrightOffers;

    public function __construct($mongoUri, $databaseName, $dbBrightOffers)
    {
        if (self::$mongoClient === null) {
            // These are all URI options and must go in the second argument.
            // Passed as driverOptions (third argument) libmongoc never reads
            // them, which is how socketTimeoutMS sat at its 300000 default
            // while this said 30000.
            self::$mongoClient = new MongoDB\Driver\Manager(
                $mongoUri,
                [
                    'retryWrites' => true,
                    'retryReads' => true,
                    'serverSelectionTimeoutMS' => 10000,
                    'connectTimeoutMS' => 10000,
                    // Generous on purpose. This class is now instantiated only
                    // by drain_mongo_queue.php — the endpoints enqueue instead
                    // of calling it — so there is no php-fpm worker to protect
                    // and no user waiting. A batch that exceeds this aborts
                    // with the reply unread, which loses the whole batch's work
                    // and risks replaying writes that already committed. Better
                    // to wait than to give up on 25 upserts.
                    'socketTimeoutMS' => 60000,
                    'maxPoolSize' => 5,
                ]
            );

            // error_log("Created NEW MongoDB connection - Process ID: " . getmypid());
        } else {
            // error_log("REUSING MongoDB connection - Process ID: " . getmypid());
        }

        $this->database = $databaseName;
        $this->dbBrightOffers = $dbBrightOffers;
    }

    // ==================== EVENTS COLLECTION ====================

    /**
     * Create or Update BrightOffers visit offer event
     * If matching event exists, append to creatives array
     */
    public function createBrightOffersVisitOfferEvent(
        $consumerId,
        $parentBrightOffersAdId,
        $campaignKey,
        $adUnitId,
        $childBrightOffersAdId = null,
        $parentEverflowTid = null,
        $childEverflowTid = null,
        $creatives = [],
        $publisherData = [],
        $occurredAtMs = null
    ) {
        try {
            [$filter, $update] = $this->buildVisitOfferEventOp(
                $consumerId,
                $parentBrightOffersAdId,
                $campaignKey,
                $adUnitId,
                $childBrightOffersAdId,
                $parentEverflowTid,
                $childEverflowTid,
                $creatives,
                $publisherData,
                $occurredAtMs
            );

            $bulk = new MongoDB\Driver\BulkWrite();
            $bulk->update($filter, $update, ['multi' => false, 'upsert' => true]);
            $result = self::$mongoClient->executeBulkWrite("{$this->database}.events", $bulk);

            return $result->getUpsertedCount() > 0 || $result->getModifiedCount() > 0;
        } catch (Throwable $e) {
            // Rethrow: this is only reached from drain_mongo_queue.php, which
            // needs a real failure to know the row must stay queued. Returning
            // false here would make the drainer delete a write that never
            // landed. Callers on the request path enqueue instead of calling
            // this directly, so nothing user-facing sees this exception.
            error_log("MongoDB error in createBrightOffersVisitOfferEvent: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Replay many visit-offer events in a single round trip.
     *
     * Same operation as createBrightOffersVisitOfferEvent — an upsert per event,
     * each with its own filter and update document — just batched into one
     * BulkWrite. That amortises the network round trip and the w=majority
     * acknowledgement across the whole batch, which is where nearly all of the
     * per-write cost lives when the drainer is 0.2% CPU and 99.8% waiting.
     *
     * The BulkWrite is ordered (the driver's default), so operations apply
     * sequentially in the order added. Two batched rows targeting the same event
     * document behave exactly as two separate round trips would: the first
     * upserts it, the second appends its creatives.
     *
     * Only used by drain_mongo_queue.php. On failure it throws, and the caller
     * maps MongoDB\Driver\WriteError::getIndex() back to queue rows to decide
     * which committed.
     *
     * @param array $argSets One associative array per event, keyed by the
     *                       parameter names of createBrightOffersVisitOfferEvent.
     * @return MongoDB\Driver\WriteResult Batch totals, not per-row outcomes.
     */
    public function createBrightOffersVisitOfferEventBatch(array $argSets): MongoDB\Driver\WriteResult
    {
        if (!$argSets) {
            throw new InvalidArgumentException('createBrightOffersVisitOfferEventBatch: empty batch');
        }

        $bulk = new MongoDB\Driver\BulkWrite();

        foreach ($argSets as $args) {
            [$filter, $update] = $this->buildVisitOfferEventOp(
                $args['consumerId'] ?? null,
                $args['parentBrightOffersAdId'] ?? null,
                $args['campaignKey'] ?? null,
                $args['adUnitId'] ?? null,
                $args['childBrightOffersAdId'] ?? null,
                $args['parentEverflowTid'] ?? null,
                $args['childEverflowTid'] ?? null,
                $args['creatives'] ?? [],
                $args['publisherData'] ?? [],
                $args['occurredAtMs'] ?? null
            );

            $bulk->update($filter, $update, ['multi' => false, 'upsert' => true]);
        }

        return self::$mongoClient->executeBulkWrite("{$this->database}.events", $bulk);
    }

    /**
     * Build the filter and update for one visit-offer upsert.
     *
     * Shared by the single and batched paths so the two can never drift.
     */
    private function buildVisitOfferEventOp(
        $consumerId,
        $parentBrightOffersAdId,
        $campaignKey,
        $adUnitId,
        $childBrightOffersAdId,
        $parentEverflowTid,
        $childEverflowTid,
        $creatives,
        $publisherData,
        $occurredAtMs
    ): array {
        // validateRequestData() uses isset(), so "" gets through — and an empty
        // key would make every such event upsert into one shared document.
        if ($parentBrightOffersAdId === null || $parentBrightOffersAdId === '') {
            throw new InvalidArgumentException(
                'createBrightOffersVisitOfferEvent: parent_brightoffers_ad_id is required'
            );
        }

        $filter = ['parent_brightoffers_ad_id' => $parentBrightOffersAdId];

        // An upsert seeds the new document from the filter's equality fields, so
        // everything dropped from the filter has to be set here instead.
        $update = [
            '$setOnInsert' => [
                'consumer_id' => $consumerId,
                'child_brightoffers_ad_id' => $childBrightOffersAdId,
                'parent_everflow_transaction_id' => $parentEverflowTid,
                'child_everflow_transaction_id' => $childEverflowTid,
                'event_source' => 'BrightOffers',
                'event_type' => 'brightoffers_visit_offer',
                'timestamp' => $this->nowPst($occurredAtMs),
                'event_specific_data.campaign_key' => $campaignKey,
                'event_specific_data.ad_unit_id' => $adUnitId,
                'event_specific_data.publisher_specific_data' => $publisherData,
            ]
        ];

        if (empty($creatives)) {
            // Initialize the array on insert; no-op on update
            $update['$setOnInsert']['event_specific_data.creatives'] = [];
        } else {
            // $push runs on both insert (creates the array) and update (appends)
            $update['$push'] = [
                'event_specific_data.creatives' => ['$each' => $creatives]
            ];
        }

        return [$filter, $update];
    }

    /**
     * Create or Update BrightOffers visit survey event
     * If matching event exists (by parent_brightoffers_ad_id + consumer_id), update fields
     */
    public function createBrightOffersVisitSurveyEvent(
        $consumerId,
        $parentBrightOffersAdId,
        $campaignKey,
        $surveyId,
        $surveyAnswered,
        $parentEverflowTid = null,
        $publisherData = [],
        $occurredAtMs = null,
    ) {
        try {
            // validateRequestData() uses isset(), so "" gets through — and an
            // empty key would collapse every such event into one document.
            if ($parentBrightOffersAdId === null || $parentBrightOffersAdId === '') {
                throw new InvalidArgumentException(
                    'createBrightOffersVisitSurveyEvent: parent_brightoffers_ad_id is required'
                );
            }

            $filter = ['parent_brightoffers_ad_id' => $parentBrightOffersAdId];

            $childBrightOffersAdId = null;
            if ($surveyAnswered) {
                $query = new MongoDB\Driver\Query($filter, ['limit' => 1]);
                $cursor = self::$mongoClient->executeQuery("{$this->database}.events", $query);
                $existingEvent = current($cursor->toArray());

                // find the child ad_id from brightoffers db 
                $sql = "SELECT lsa.`ad_id_response`, lsa.`survey_question_option_id`, sqo.survey_question_id, sqo.value FROM log_bright_offersv25_survey_question_answers lsa
                        LEFT JOIN bright_offersv25_survey_questions_options sqo ON sqo.survey_question_option_id = lsa.survey_question_option_id
                 WHERE ad_id_request = ?";
                $stmt = $this->dbBrightOffers->prepare($sql);
                $stmt->bind_param('s', $parentBrightOffersAdId);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                $childBrightOffersAdId = $rows[0]['ad_id_response'] ?? null;

                $formAnswers = [];
                foreach ($rows as $row) {
                    $formAnswers[] = [
                        'question_id' => $row['survey_question_id'] ?? null,
                        'question_option_id' => $row['survey_question_option_id'] ?? null,
                        'question_option_value' => $row['value'] ?? null
                    ];
                }
                // log form submission

                $this->createSurveySubmission($consumerId, $surveyId, $formAnswers, $occurredAtMs);

                if ($existingEvent) {
                    // the survey visit event already exists
                    $bulk = new MongoDB\Driver\BulkWrite();

                    $updateFields = [];

                    // Update child_brightoffers_ad_id if provided
                    if ($childBrightOffersAdId !== null) {
                        $updateFields['child_brightoffers_ad_id'] = $childBrightOffersAdId;
                    }

                    // Update everflow transaction IDs if provided
                    if ($parentEverflowTid !== null) {
                        $updateFields['parent_everflow_transaction_id'] = $parentEverflowTid;
                    }


                    if ($surveyId !== null) {
                        $updateFields['event_specific_data.survey_id'] = $surveyId;
                    }

                    if (!empty($updateFields)) {
                        $bulk->update(
                            $filter,
                            ['$set' => $updateFields],
                            ['multi' => false, 'upsert' => false]
                        );

                        $result = self::$mongoClient->executeBulkWrite("{$this->database}.events", $bulk);
                        return $result->getModifiedCount() > 0;
                    }

                    return false; // No fields to update
                }
            }

            // Event doesn't exist - create new. parent_brightoffers_ad_id comes
            // from the filter.
            $event = [
                'consumer_id' => $consumerId,
                'child_brightoffers_ad_id' => $childBrightOffersAdId,
                'parent_everflow_transaction_id' => $parentEverflowTid,
                'child_everflow_transaction_id' => null,
                'timestamp' => $this->nowPst($occurredAtMs),
                'event_source' => 'BrightOffers',
                'event_type' => 'brightoffers_visit_survey',
                'event_specific_data' => [
                    'campaign_key' => $campaignKey,
                    'survey_id' => $surveyId,
                    'publisher_specific_data' => $publisherData
                ]
            ];

            // Upsert rather than insert: the write queue is at-least-once, so a
            // replay after a lost response would otherwise duplicate the event.
            $bulk = new MongoDB\Driver\BulkWrite();
            $bulk->update($filter, ['$setOnInsert' => $event], ['multi' => false, 'upsert' => true]);
            $result = self::$mongoClient->executeBulkWrite("{$this->database}.events", $bulk);

            return $result->getUpsertedCount() > 0;
        } catch (Throwable $e) {
            // Rethrow so the drainer keeps the row queued — see the note in
            // createBrightOffersVisitOfferEvent.
            error_log("MongoDB error in createBrightOffersVisitSurveyEvent: " . $e->getMessage());
            throw $e;
        }
    }


    /**
     * Create or Update Lead Form visit wall event
     * If matching event exists (by consumer_id + parent_everflow_transaction_id), append to form_questions
     * @param array $formQuestions - Single question object (not array of objects)
     */
    public function createLeadFormVisitWallEvent(
        $consumerId,
        $formDomain,
        $parentBrightOffersAdId = null,
        $childBrightOffersAdId = null,
        $parentEverflowTid = null,
        $childEverflowTid = null,
        $formQuestions = [],
        $occurredAtMs = null
    ) {
        try {
            // Check if event already exists (by consumer_id + parent_everflow_transaction_id)
            $filter = [
                'consumer_id' => $consumerId,
                'parent_everflow_transaction_id' => $parentEverflowTid,
                'event_source' => 'Lead Forms',
                'event_type' => 'lead_form_visit_wall'
            ];

            $query = new MongoDB\Driver\Query($filter, ['limit' => 1]);
            $cursor = self::$mongoClient->executeQuery("{$this->database}.events", $query);
            $existingEvent = current($cursor->toArray());

            if ($existingEvent) {
                // Event exists - append single question object to form_questions array
                $bulk = new MongoDB\Driver\BulkWrite();

                if ($formQuestions) {
                    $updateFields = [
                        '$push' => [
                            'event_specific_data.form_questions' => $formQuestions // Push single object
                        ]
                    ];
                }

                // Update other fields if provided
                $setFields = [];
                if ($childBrightOffersAdId !== null) {
                    $setFields['child_brightoffers_ad_id'] = $childBrightOffersAdId;
                }
                if ($childEverflowTid !== null) {
                    $setFields['child_everflow_transaction_id'] = $childEverflowTid;
                }
                if ($formDomain !== null) {
                    $setFields['event_specific_data.form_domain'] = $formDomain;
                }

                if (!empty($setFields)) {
                    $updateFields['$set'] = $setFields;
                }

                $bulk->update(
                    $filter,
                    $updateFields,
                    ['multi' => false, 'upsert' => false]
                );

                $result = self::$mongoClient->executeBulkWrite("{$this->database}.events", $bulk);
                return $result->getModifiedCount() > 0;
            } else {
                // Event doesn't exist - create new with question wrapped in array
                $event = [
                    'consumer_id' => $consumerId,
                    'parent_brightoffers_ad_id' => $parentBrightOffersAdId,
                    'child_brightoffers_ad_id' => $childBrightOffersAdId,
                    'parent_everflow_transaction_id' => $parentEverflowTid,
                    'child_everflow_transaction_id' => $childEverflowTid,
                    'timestamp' => $this->nowPst($occurredAtMs),
                    'event_source' => 'Lead Forms',
                    'event_type' => 'lead_form_visit_wall',
                    'event_specific_data' => [
                        'form_domain' => $formDomain,
                        'form_questions' => [$formQuestions] // Wrap single object in array
                    ]
                ];

                return $this->insertEvent($event);
            }
        } catch (Throwable $e) {
            // Rethrow so the drainer keeps the row queued — see the note in
            // createBrightOffersVisitOfferEvent.
            error_log("MongoDB error in createLeadFormVisitWallEvent: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generic event insert
     */
    private function insertEvent($event)
    {
        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->insert($event);
        $result = self::$mongoClient->executeBulkWrite("{$this->database}.events", $bulk);
        return $result->getInsertedCount() > 0;
    }

    // ==================== FORMS COLLECTION ====================

    /**
     * Create or Update Lead Form submission
     * If matching form exists (by consumer_id + domain), append to form_answers
     * @param string $consumerId - Consumer UUID
     * @param string $domain - Form domain
     * @param string $landingPage - Landing page URL
     * @param object|array $formAnswers - Form answers as object (e.g., {"email": "test@example.com", "phone": "+1234567890"})
     * @return bool - Success status
     */
    public function createLeadFormSubmission($consumerId, $domain, $landingPage, $formAnswers = [], $occurredAtMs = null)
    {
        try {
            // Check if form already exists (by consumer_id + domain)
            $filter = [
                'consumer_id' => $consumerId,
                'form_type' => 'Lead Form',
                'form_specific_data.domain' => $domain
            ];

            $query = new MongoDB\Driver\Query($filter, ['limit' => 1]);
            $cursor = self::$mongoClient->executeQuery("{$this->database}.forms", $query);
            $existingForm = current($cursor->toArray());

            if ($existingForm) {
                // Form exists - append to form_answers
                $bulk = new MongoDB\Driver\BulkWrite();

                // Push new form answers to the array
                $bulk->update(
                    $filter,
                    [
                        '$push' => [
                            'form_specific_data.form_answers' => ['$each' => $formAnswers]
                        ],
                    ],
                    ['multi' => false, 'upsert' => false]
                );

                $result = self::$mongoClient->executeBulkWrite("{$this->database}.forms", $bulk);
                return $result->getModifiedCount() > 0;
            } else {
                // Form doesn't exist - create new with form_answers as array
                $form = [
                    'consumer_id' => $consumerId,
                    'form_type' => 'Lead Form',
                    'timestamp' => $this->nowPst($occurredAtMs),
                    'form_specific_data' => [
                        'domain' => $domain,
                        'landing_page' => $landingPage,
                        'form_answers' => $formAnswers
                    ]
                ];

                return $this->insertForm($form);
            }
        } catch (Throwable $e) {
            // Rethrow so the drainer keeps the row queued — see the note in
            // createBrightOffersVisitOfferEvent.
            error_log("MongoDB error in createLeadFormSubmission: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create Pre Pop form submission
     */
    public function createPrepopFormSubmission($consumerId, $afid, $prepopData = [], $sessionId = null, $occurredAtMs = null)
    {
        try {
            $prepopDataInsert = [];
            foreach ($prepopData as $key => $value) {
                $prepopDataInsert[] = [
                    'field_name' => $key,
                    'field_value' => $value
                ];
            }
            $form = [
                'consumer_id' => $consumerId,
                'form_type' => 'Pre Pop',
                'timestamp' => $this->nowPst($occurredAtMs),
                'form_specific_data' => [
                    'publisher_id' => $afid,
                    'pre_pop_data' => $prepopDataInsert
                ],
                'session_id' => $sessionId ?? null
            ];

            return $this->insertForm($form);
        } catch (Throwable $e) {
            // Rethrow so the drainer keeps the row queued — see the note in
            // createBrightOffersVisitOfferEvent.
            error_log("MongoDB error in createPrepopFormSubmission: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create Survey submission
     */
    public function createSurveySubmission($consumerId, $surveyId, $surveyAnswers = [], $occurredAtMs = null)
    {
        try {
            $form = [
                'consumer_id' => $consumerId,
                'form_type' => 'Survey',
                'timestamp' => $this->nowPst($occurredAtMs),
                'form_specific_data' => [
                    'survey_id' => $surveyId,
                    'survey_answers' => $surveyAnswers
                ]
            ];

            return $this->insertForm($form);
        } catch (Throwable $e) {
            // Rethrow so the drainer keeps the row queued — see the note in
            // createBrightOffersVisitOfferEvent.
            //
            // Note this method is also called from within
            // createBrightOffersVisitSurveyEvent, which previously swallowed a
            // failure here and still wrote the event. It now fails the whole
            // survey event so the drainer retries both together.
            error_log("MongoDB error in createSurveySubmission: " . $e->getMessage());
            throw $e;
        }
    }


    /**
     * Generic form insert
     */
    private function insertForm($form)
    {
        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->insert($form);
        $result = self::$mongoClient->executeBulkWrite("{$this->database}.forms", $bulk);
        return $result->getInsertedCount() > 0;
    }


    // ==================== UTILITY METHODS ====================

    /**
     * Returns a UTCDateTime in PST/PDT (America/Los_Angeles).
     * Stores the local PST clock time so timestamps read correctly without timezone conversion.
     *
     * Pass $occurredAtMs to stamp when the event actually happened rather than
     * when this runs. Writes now go through the write-behind queue, so "now" is
     * drain time — which during a backlog can be hours after the user action.
     * Every queued call carries a request-time millisecond epoch so replayed
     * documents keep their real timestamps and time-series stay accurate.
     *
     * The UTC offset is resolved for that instant rather than for the current
     * moment, so a backlog spanning a DST change is still stamped correctly.
     */
    private function nowPst(?int $occurredAtMs = null): MongoDB\BSON\UTCDateTime
    {
        $ms = $occurredAtMs ?? (int) floor(microtime(true) * 1000);
        $offset = (new DateTimeZone('America/Los_Angeles'))
            ->getOffset(new DateTimeImmutable('@' . intdiv($ms, 1000)));

        return new MongoDB\BSON\UTCDateTime($ms + $offset * 1000);
    }
}
