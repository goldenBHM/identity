<?php

/**
 * event.php — analytics event intake.
 *
 * Writes are queued to MySQL and performed by drain_mongo_queue.php, so this
 * endpoint never opens a MongoDB connection and never blocks on one. A visitor's
 * page load should not wait on an analytics insert, and a slow or re-electing
 * cluster must not be able to hold php-fpm workers.
 *
 * The pre-queue version is kept at backup/event-2026-09-02.php.
 */

declare(strict_types=1);

$env = parse_ini_file(__DIR__ . '/../../environments/.env-fingerprint');
require_once __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/MongoWriteQueue.php';

// CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: full-origin-URL, Content-Type");
    exit(0); // Terminate for preflight
}
$httpOrigin = (isset($_SERVER['HTTP_ORIGIN']) && !empty($_SERVER['HTTP_ORIGIN'])) ? $_SERVER['HTTP_ORIGIN'] : null;
header("Access-Control-Allow-Origin: $httpOrigin");


// if request method is not POST, return error
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$post = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}



$requiredParams = ['event_type', 'uuid', 'p_b_adid',];
validateRequestData($requiredParams, $post);

$eventType = $post['event_type'] ?? null;
$consumerId = $post['uuid'] ?? null;

$parentBrightOffersAdId = $post['p_b_adid'] ?? null;
$childBrightOffersAdId = $post['c_b_adid'] ?? null;
$parentEverflowTid = $post['p_e_tid'] ?? null;
$childEverflowTid = $post['c_e_t_id'] ?? null;
$campaignId = $post['cam_id'] ?? null;
$adUnitId = $post['adunitid'] ?? null;
$creatives = $post['creatives'] ?? [];
$publisherData = $post['p_data'] ?? [];

// Stamped here, not in the drainer: the queue replays writes later, so the
// document must carry when the event actually happened.
$occurredAtMs = (int) floor(microtime(true) * 1000);


switch ($eventType) {
    case 'brightoffers_visit_offer':
        $creatives = $post['creatives'] ?? [];

        MongoWriteQueue::enqueue($dbBrightOffers, 'createBrightOffersVisitOfferEvent', [
            'consumerId'             => $consumerId,
            'parentBrightOffersAdId' => $parentBrightOffersAdId,
            'campaignKey'            => $campaignId,
            'adUnitId'               => $adUnitId,
            'childBrightOffersAdId'  => $childBrightOffersAdId,
            'parentEverflowTid'      => $parentEverflowTid,
            'childEverflowTid'       => $childEverflowTid,
            'creatives'              => $creatives,
            'publisherData'          => $publisherData,
            'occurredAtMs'           => $occurredAtMs,
        ], $notifier);
        break;

    case 'brightoffers_visit_survey':
        $surveyId = $post['survey_id'] ?? null;
        $surveyAnswered = isset($post['survey_answered']) ? (bool)$post['survey_answered'] : false;

        MongoWriteQueue::enqueue($dbBrightOffers, 'createBrightOffersVisitSurveyEvent', [
            'consumerId'             => $consumerId,
            'parentBrightOffersAdId' => $parentBrightOffersAdId,
            'campaignKey'            => $campaignId,
            'surveyId'               => $surveyId,
            'surveyAnswered'         => $surveyAnswered,
            'parentEverflowTid'      => $parentEverflowTid,
            'publisherData'          => $publisherData,
            'occurredAtMs'           => $occurredAtMs,
        ], $notifier);
        break;

    case 'lead_form_visit_wall':
        $requiredParams = ['form_domain'];
        validateRequestData($requiredParams, $post);
        $domain = $post['form_domain'];
        $formQuestions = $post['form_questions'] ?? [];

        MongoWriteQueue::enqueue($dbBrightOffers, 'createLeadFormVisitWallEvent', [
            'consumerId'             => $consumerId,
            'formDomain'             => $domain,
            'parentBrightOffersAdId' => $parentBrightOffersAdId,
            'childBrightOffersAdId'  => $childBrightOffersAdId,
            'parentEverflowTid'      => $parentEverflowTid,
            'childEverflowTid'       => $childEverflowTid,
            'formQuestions'          => $formQuestions,
            'occurredAtMs'           => $occurredAtMs,
        ], $notifier);
        break;

    default:
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid event type. Allowed: brightoffers_visit_offer, brightoffers_visit_survey, lead_form_visit_wall',
        ]);
        exit;
}

exit(0);
