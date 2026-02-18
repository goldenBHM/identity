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


$requiredParams = ['uuid', "form_type",];
validateRequestData($requiredParams, $post);

$db = new ConsumerDatabase($env['MONGO_URL'], 'consumer_db', $dbBrightOffers);

$consumerId = $post['uuid'] ?? null;
$formType = $post['form_type'] ?? null;

$allowedFormTypes = ['Survey', 'Pre Pop', 'Lead Form'];
if (!in_array($formType, $allowedFormTypes)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid form_type']);
    exit;
}
try {
    switch ($formType) {
        case 'Survey':
            $surveyId = $post['survey_id'] ?? null;
            $surveyAnswers = $post['survey_answers'] ?? [];

            $result = $db->createSurveySubmission($consumerId, $surveyId, $surveyAnswers);
            break;

        case 'Pre Pop':
            $affiliateId = $post['afid'] ?? null;
            $prepopData = $post['prepop_data'] ?? [];
            $sessionId = $post['session_id'] ?? null;

            $result = $db->createPrepopFormSubmission($consumerId, $affiliateId, $prepopData, $sessionId);
            break;

        case 'Lead Form':
            $requiredParams = ['domain', 'landing_page', 'form_answers'];
            validateRequestData($requiredParams, $post);
            $domain = $post['domain'] ?? null;
            $landingPage = $post['landing_page'] ?? null;
            $formAnswers = $post['form_answers'] ?? [];

            $result = $db->createLeadFormSubmission($consumerId, $domain, $landingPage, $formAnswers);
            break;

        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Unsupported form_type']);
            exit;
    }

    http_response_code(201);
} catch (Exception $e) {
    error_log("Form submission error ($formType): " . $e->getMessage());
    http_response_code(500);
    // echo json_encode([
    //     'status' => 'error',
    //     'message' => 'Form submission failed',
    //     'error' => $e->getMessage()
    // ]);
}
