<?php
class ConsumerDatabase
{
    private static $mongoClient = null; // Static to reuse across requests
    private $database;
    private $dbBrightOffers;

    public function __construct($mongoUri, $databaseName, $dbBrightOffers)
    {
        // Reuse existing connection if available
        if (self::$mongoClient === null) {
            self::$mongoClient = new MongoDB\Driver\Manager(
                $mongoUri,
                [
                    'retryWrites' => true,
                    'retryReads' => true,
                ],
                [
                    'serverSelectionTimeoutMS' => 10000,
                    'connectTimeoutMS' => 10000,
                    'socketTimeoutMS' => 30000,
                    'maxPoolSize' => 100,
                ]
            );

            error_log("Created NEW MongoDB connection - Process ID: " . getmypid());
        } else {
            error_log("REUSING MongoDB connection - Process ID: " . getmypid());
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
        $publisherData = []
    ) {
        try {
            $filter = [
                'consumer_id' => $consumerId,
                'parent_brightoffers_ad_id' => $parentBrightOffersAdId,
                'child_brightoffers_ad_id' => $childBrightOffersAdId,
                'parent_everflow_transaction_id' => $parentEverflowTid,
                'child_everflow_transaction_id' => $childEverflowTid,
                'event_source' => 'BrightOffers',
                'event_type' => 'brightoffers_visit_offer'
            ];

            // Atomic upsert: $setOnInsert only fires on insert, eliminating the
            // read-then-write race condition when two requests arrive simultaneously.
            $update = [
                '$setOnInsert' => [
                    'timestamp' => $this->nowPst(),
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

            $bulk = new MongoDB\Driver\BulkWrite();
            $bulk->update($filter, $update, ['multi' => false, 'upsert' => true]);
            $result = self::$mongoClient->executeBulkWrite("{$this->database}.events", $bulk);

            return $result->getUpsertedCount() > 0 || $result->getModifiedCount() > 0;
        } catch (MongoDB\Driver\Exception\ConnectionTimeoutException $e) {
            error_log("MongoDB connection timeout in createBrightOffersVisitOfferEvent: " . $e->getMessage());
            trigger_error("MongoDB insert failed: " . $e->getMessage(), E_USER_WARNING);
            return false;
        } catch (Exception $e) {
            error_log("MongoDB error in createBrightOffersVisitOfferEvent: " . $e->getMessage());
            return false;
        }
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
    ) {
        try {
            $childBrightOffersAdId = null;
            if ($surveyAnswered) {
                // Check if event already exists (by parent_brightoffers_ad_id + consumer_id)
                $filter = [
                    'consumer_id' => $consumerId,
                    'parent_brightoffers_ad_id' => $parentBrightOffersAdId,
                    'event_source' => 'BrightOffers',
                    'event_type' => 'brightoffers_visit_survey'
                ];

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

                $this->createSurveySubmission($consumerId, $surveyId, $formAnswers);

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

            // Event doesn't exist - create new
            $event = [
                'consumer_id' => $consumerId,
                'parent_brightoffers_ad_id' => $parentBrightOffersAdId,
                'child_brightoffers_ad_id' => $childBrightOffersAdId,
                'parent_everflow_transaction_id' => $parentEverflowTid,
                'child_everflow_transaction_id' => null,
                'timestamp' => $this->nowPst(),
                'event_source' => 'BrightOffers',
                'event_type' => 'brightoffers_visit_survey',
                'event_specific_data' => [
                    'campaign_key' => $campaignKey,
                    'survey_id' => $surveyId,
                    'publisher_specific_data' => $publisherData
                ]
            ];

            return $this->insertEvent($event);
        } catch (MongoDB\Driver\Exception\ConnectionTimeoutException $e) {
            error_log("MongoDB connection timeout in createBrightOffersVisitSurveyEvent: " . $e->getMessage());
            trigger_error("MongoDB insert failed: " . $e->getMessage(), E_USER_WARNING);
            return false;
        } catch (Exception $e) {
            error_log("MongoDB error in createBrightOffersVisitSurveyEvent: " . $e->getMessage());
            return false;
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
        $formQuestions = []
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
                    'timestamp' => $this->nowPst(),
                    'event_source' => 'Lead Forms',
                    'event_type' => 'lead_form_visit_wall',
                    'event_specific_data' => [
                        'form_domain' => $formDomain,
                        'form_questions' => [$formQuestions] // Wrap single object in array
                    ]
                ];

                return $this->insertEvent($event);
            }
        } catch (MongoDB\Driver\Exception\ConnectionTimeoutException $e) {
            error_log("MongoDB connection timeout in createLeadFormVisitWallEvent: " . $e->getMessage());
            trigger_error("MongoDB insert failed: " . $e->getMessage(), E_USER_WARNING);
            return false;
        } catch (Exception $e) {
            error_log("MongoDB error in createLeadFormVisitWallEvent: " . $e->getMessage());
            return false;
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
    public function createLeadFormSubmission($consumerId, $domain, $landingPage, $formAnswers = [])
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
                    'timestamp' => $this->nowPst(),
                    'form_specific_data' => [
                        'domain' => $domain,
                        'landing_page' => $landingPage,
                        'form_answers' => $formAnswers
                    ]
                ];

                return $this->insertForm($form);
            }
        } catch (MongoDB\Driver\Exception\ConnectionTimeoutException $e) {
            error_log("MongoDB connection timeout in createLeadFormSubmission: " . $e->getMessage());
            return false;
        } catch (Exception $e) {
            error_log("MongoDB error in createLeadFormSubmission: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create Lead Form submission
     */
    public function createPrepopFormSubmission($consumerId, $afid, $prepopData = [], $sessionId)
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
                'timestamp' => $this->nowPst(),
                'form_specific_data' => [
                    'publisher_id' => $afid,
                    'pre_pop_data' => $prepopDataInsert
                ],
                'session_id' => $sessionId ?? null
            ];

            return $this->insertForm($form);
        } catch (Exception $e) {
            error_log("MongoDB error in createPrepopFormSubmission: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create Survey submission
     */
    public function createSurveySubmission($consumerId, $surveyId, $surveyAnswers = [])
    {
        try {
            $form = [
                'consumer_id' => $consumerId,
                'form_type' => 'Survey',
                'timestamp' => $this->nowPst(),
                'form_specific_data' => [
                    'survey_id' => $surveyId,
                    'survey_answers' => $surveyAnswers
                ]
            ];

            return $this->insertForm($form);
        } catch (Exception $e) {
            error_log("MongoDB error in createSurveySubmission: " . $e->getMessage());
            return false;
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
     * Returns a UTCDateTime representing the current time in PST/PDT (America/Los_Angeles).
     * Stores the local PST clock time so timestamps read correctly without timezone conversion.
     */
    private function nowPst(): MongoDB\BSON\UTCDateTime
    {
        $pst = new DateTime('now', new DateTimeZone('America/Los_Angeles'));
        return new MongoDB\BSON\UTCDateTime((time() + $pst->getOffset()) * 1000);
    }

}
