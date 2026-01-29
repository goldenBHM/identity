<?php
// identify.php  (updated for extended fingerprint)
declare(strict_types=1);

use MongoDB\Client;

require_once __DIR__ . '/vendor/autoload.php';
$env = parse_ini_file(__DIR__ . '/../environments/.env-fingerprint');

require __DIR__ . '/includes/config.php';

// ---------- CONFIG ----------
const FP_VERSION = 'fp-1';
$HMAC_SECRET = $env['HMAC_SECRET'];
$MONGO_URL = $env['MONGO_URL'];
$COLLECTION_NAME = $env['MONGO_COLLECTION'] ?: 'consumers';
$DB_NAME = $env['MONGO_DB'] ?: 'consumer_db';

// CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: full-origin-URL, Content-Type");
    exit(0); // Terminate for preflight
}
$httpOrigin = (isset($_SERVER['HTTP_ORIGIN']) && !empty($_SERVER['HTTP_ORIGIN'])) ? $_SERVER['HTTP_ORIGIN'] : null;
header("Access-Control-Allow-Origin: $httpOrigin");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ---------- READ BODY ----------
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}


// ---------- NORMALIZE FEATURES ----------
$deviceInformation = [
    'version'  => lc($data['version'] ?? FP_VERSION),
    // 'storageQuota' => isset($data['storageQuota']) ? (int)$data['storageQuota'] : null,
    'tz'       => lc($data['tz'] ?? null),
    'lang'     => lc($data['lang'] ?? null),
    'languages'     => json_encode($data['languages'] ?? []),
    'hwc'      => isset($data['hwc']) ? (int)$data['hwc'] : null,
    'mem'      => isset($data['mem']) ? (int)$data['mem'] : null,
    'touch'    => isset($data['touch']) ? (int)$data['touch'] : null,
    'colorGamut' => lc($data['colorGamut'] ?? null),
    'pixelDepth' => isset($data['pixelDepth']) ? (int)$data['pixelDepth'] : null,
    'orientation' => lc($data['orientation'] ?? null),

    'vendor' => lc($data['vendor'] ?? null),
    'cpuClass' => lc($data['cpuClass'] ?? null),
    'cookieEnabled' => isset($data['cookieEnabled']) ? (bool)$data['cookieEnabled'] : null,
    'localStorage' => isset($data['localStorage']) ? (bool)$data['localStorage'] : null,
    'sessionStorage' => isset($data['sessionStorage']) ? (bool)$data['sessionStorage'] : null,
    'indexedDb' => isset($data['indexedDb']) ? (bool)$data['indexedDb'] : null,
    // 'plugins' => array_slice(array_map(
    //     fn($p) => lc($p['name'] ?? null),
    //     $data['plugins'] ?? []
    // ), 0, 20),
    // 'mimeTypes' => array_slice(array_map(function ($mt) {
    //     return lc(($mt['type'] ?? '') . '/' . ($mt['suffixes'] ?? ''));
    // }, $data['mimeTypes'] ?? []), 0, 20),

    'ch' => [
        'platform' => lc($data['ch']['platform'] ?? null),
        'platformVersion' => lc($data['ch']['platformVersion'] ?? null),
        'model' => lc($data['ch']['model'] ?? null),
        'uaFullVersion' => lc($data['ch']['uaFullVersion'] ?? null),
        'architecture' => lc($data['ch']['architecture'] ?? null),
    ],

    'screen' => [
        'w'  => isset($data['screen']['w']) ? (int)$data['screen']['w'] : null,
        'h'  => isset($data['screen']['h']) ? (int)$data['screen']['h'] : null,
        'dpr' => isset($data['screen']['dpr']) ? round((float)$data['screen']['dpr'], 2) : null,
        'cd' => isset($data['screen']['cd']) ? (int)$data['screen']['cd'] : null,
    ],

    'ua' => lc($data['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')),

    // new rich signals
    'canvas' => lc($data['canvas'] ?? null),
    'audio'  => lc($data['audio'] ?? null),

    'webgl' => [
        'vendor' => lc($data['webgl']['vendor'] ?? null),
        'renderer' => lc($data['webgl']['renderer'] ?? null),
        'version' => lc($data['webgl']['version'] ?? null),
        'shadingLang' => lc($data['webgl']['shadingLang'] ?? null),
        'unmaskedVendor' => lc($data['webgl']['unmaskedVendor'] ?? null),
        'unmaskedRenderer' => lc($data['webgl']['unmaskedRenderer'] ?? null),
        'extensions' => array_slice(array_map('lc', $data['webgl']['extensions'] ?? []), 0, 50),
    ],

    'fonts' => [
        'fonts' => array_slice(array_map('lc', $data['fonts']['fonts'] ?? []), 0, 20),
        // 'metrics' => $data['fonts']['metrics'] ?? null,
    ],
];
if (
    isset($data['prepopData']) &&
    is_array($data['prepopData']) &&
    (
        (isset($data['prepopData']['bhm_email']) && $data['prepopData']['bhm_email'] !== '' && $data['prepopData']['bhm_email'] !== null) ||
        (isset($data['prepopData']['bhm_phone']) && $data['prepopData']['bhm_phone'] !== '' && $data['prepopData']['bhm_phone'] !== null)
    )
) {

    // use prepopulated data directly
    $email = isset($data['prepopData']['bhm_email']) ? $data['prepopData']['bhm_email']  : null;
    $phone = isset($data['prepopData']['bhm_phone']) ? $data['prepopData']['bhm_phone']  : null;
    $norm = [
        'email' => lc($email),
        'phone' => lc($phone ? normalizeToTenDigits($phone) : null),
    ];
} else {
    $norm = $deviceInformation;

    // --- derive ua/os families (coarse) ---
    $ua_lc = $norm['ua'];
    if ($ua_lc) {
        if (str_contains($ua_lc, 'edg/'))        $norm['ua_family'] = 'edge';
        elseif (str_contains($ua_lc, 'chrome/')) $norm['ua_family'] = 'chrome';
        elseif (str_contains($ua_lc, 'safari/') && !str_contains($ua_lc, 'chrome/')) $norm['ua_family'] = 'safari';
        elseif (str_contains($ua_lc, 'firefox/')) $norm['ua_family'] = 'firefox';
        else $norm['ua_family'] = 'other';

        if (str_contains($ua_lc, 'android'))      $norm['os_family'] = 'android';
        elseif (str_contains($ua_lc, 'iphone') || str_contains($ua_lc, 'ipad')) $norm['os_family'] = 'ios';
        elseif (str_contains($ua_lc, 'windows'))  $norm['os_family'] = 'windows';
        elseif (str_contains($ua_lc, 'mac os x')) $norm['os_family'] = 'macos';
        elseif (str_contains($ua_lc, 'linux'))    $norm['os_family'] = 'linux';
        else $norm['os_family'] = 'other';
    }
}


// Clean & canonicalize
$norm = array_filter_recursive($norm);
ksort_recursive($norm);
$canonical = json_encode($norm, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// ---------- FINGERPRINT HASH ----------
$hash_raw = hash_hmac('sha256', $canonical, $HMAC_SECRET, true);
$fingerprint_hash = 'v1_' . substr(rtrim(strtr(base64_encode($hash_raw), '+/', '-_'), '='), 0, 33);

// ---------- OPTIONAL DB MAPPING ----------
$visitor_id = $fingerprint_hash;
$confidence = 0.95;

$savedToDb = false;

try {
    $mongo = new Client($MONGO_URL);
    $collection = $mongo->selectDatabase($DB_NAME)->selectCollection($COLLECTION_NAME);
} catch (Throwable $e) {
    $notifier->handlePhpError(
        E_USER_WARNING,
        'MongoDB insert failed: ' . $e->getMessage(),
        __FILE__,
        __LINE__
    );

    http_response_code(500);
    echo json_encode(['error' => 'MongoDB connection failed', 'detail' => $e->getMessage()]);
    exit;
}

try {

    $dataToInsert = [
        '_id' => $fingerprint_hash,
        "emails" => [],
        "phones" => [],
        "pii" => (object)[],
        "employment" => (object)[],
        "financial" => (object)[],
        "other" => (object)[],
        "profile_source" => [],
        "fingerprint_latest" => [
            'fingerprint_data' => $norm,
            'device_data' => $deviceInformation,
            'prepop_data' => $data['prepopData'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'source' => $httpOrigin,
        ],
        'created_at' => new MongoDB\BSON\UTCDateTime(),
        'updated_at' => new MongoDB\BSON\UTCDateTime(),
    ];

    //find or create fingerprint record
    $document = $collection->findOneAndUpdate(
        ['_id' => $fingerprint_hash],
        [
            '$setOnInsert' => $dataToInsert,
        ],
        ['upsert' => true, 'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]

    );

    if ($document) {
        $savedToDb = true;
    }
} catch (Throwable $e) {
    error_log('MongoDB insert failed: ' . $e->getMessage());

    $notifier->handlePhpError(
        E_USER_WARNING,
        'MongoDB insert failed: ' . $e->getMessage(),
        __FILE__,
        __LINE__
    );
}

// ---------- RESPOND ----------
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'visitor_id' => $visitor_id,
    'confidence' => $confidence,
    'version'    => FP_VERSION,
    'saved_to_db' => $savedToDb,
    // 'used'       => $norm,
]);
