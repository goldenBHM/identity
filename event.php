<?php

declare(strict_types=1);

$env = parse_ini_file(__DIR__ . '/../environments/.env-fingerprint');
require_once __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/ConsumerDatabase.php';

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

$db = new ConsumerDatabase($env['MONGO_URL'], 'consumer_db', $dbBrightOffers);


switch ($eventType) {
    case 'brightoffers_visit_offer':
        $creatives = $post['creatives'] ?? [];

        $result = $db->createBrightOffersVisitOfferEvent(
            $consumerId,
            $parentBrightOffersAdId,
            $campaignId,
            $adUnitId,
            $childBrightOffersAdId,
            $parentEverflowTid,
            $childEverflowTid,
            $creatives,
            $publisherData
        );
        break;

    case 'brightoffers_visit_survey':
        $surveyId = $post['survey_id'] ?? null;
        $surveyAnswered = isset($post['survey_answered']) ? (bool)$post['survey_answered'] : false;

        $result = $db->createBrightOffersVisitSurveyEvent(
            $consumerId,
            $parentBrightOffersAdId,
            $campaignId,
            $surveyId,
            $surveyAnswered,
            $parentEverflowTid,
            $publisherData,
        );
        break;

    case 'lead_form_visit_wall':
        $requiredParams = ['form_domain'];
        validateRequestData($requiredParams, $post);
        $domain = $post['form_domain'];
        $formQuestions = $post['form_questions'] ?? [];

        $db->createLeadFormVisitWallEvent(
            $consumerId,
            $domain,
            $parentBrightOffersAdId,
            $childBrightOffersAdId,
            $parentEverflowTid,
            $childEverflowTid,
            $formQuestions,
        );
        break;

    default:
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid event type. Allowed: brightoffers_visit_offer, brightoffers_visit_survey, lead_form_visit_wall',
        ]);
        exit;
}
