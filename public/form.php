<?php

/**
 * form.php — form submission intake.
 *
 * Writes are queued to MySQL and performed by drain_mongo_queue.php, so this
 * endpoint never opens a MongoDB connection and never blocks on one. A slow or
 * re-electing cluster must not be able to hold php-fpm workers.
 *
 * The pre-queue version is kept at backup/form-2026-09-02.php.
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


$requiredParams = ['uuid', "form_type",];
validateRequestData($requiredParams, $post);

$consumerId = $post['uuid'] ?? null;
$formType = $post['form_type'] ?? null;

$allowedFormTypes = ['Survey', 'Pre Pop', 'Lead Form'];
if (!in_array($formType, $allowedFormTypes)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid form_type']);
    exit;
}

switch ($formType) {
    case 'Survey':
        $surveyId = $post['survey_id'] ?? null;
        $surveyAnswers = $post['survey_answers'] ?? [];

        $queued = MongoWriteQueue::enqueue($dbBrightOffers, 'createSurveySubmission', [
            'consumerId'    => $consumerId,
            'surveyId'      => $surveyId,
            'surveyAnswers' => $surveyAnswers,
        ], $notifier);
        break;

    case 'Pre Pop':
        $affiliateId = $post['afid'] ?? null;
        $prepopData = $post['prepop_data'] ?? [];
        $sessionId = $post['session_id'] ?? null;

        $queued = MongoWriteQueue::enqueue($dbBrightOffers, 'createPrepopFormSubmission', [
            'consumerId' => $consumerId,
            'afid'       => $affiliateId,
            'prepopData' => $prepopData,
            'sessionId'  => $sessionId,
        ], $notifier);
        break;

    case 'Lead Form':
        $requiredParams = ['domain', 'landing_page', 'form_answers'];
        validateRequestData($requiredParams, $post);
        $domain = $post['domain'] ?? null;
        $landingPage = $post['landing_page'] ?? null;
        $formAnswers = $post['form_answers'] ?? [];

        $queued = MongoWriteQueue::enqueue($dbBrightOffers, 'createLeadFormSubmission', [
            'consumerId'  => $consumerId,
            'domain'      => $domain,
            'landingPage' => $landingPage,
            'formAnswers' => $formAnswers,
        ], $notifier);
        break;

    default:
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Unsupported form_type']);
        exit;
}

// 201 now means "durably accepted" rather than "written to Mongo". A false
// return means the MySQL write failed, so the submission really was lost —
// that is the only case worth a 500.
if ($queued) {
    http_response_code(201);
} else {
    error_log("Form submission enqueue failed ({$formType}) for consumer {$consumerId}");
    http_response_code(500);
}
