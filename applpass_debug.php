<?php
/**
 * DEBUG VERSION OF applpass.php
 * This version includes extensive logging to debug CPC event processing
 */

header("Access-Control-Allow-Origin: *");

// Include debug logger
include_once 'event_debug_logger.php';
$logger = new EventDebugLogger();

$logger->log("========== CPC EVENT START ==========");

// Auto-detect environment
$currentPath = __DIR__;
$httpHost = $_SERVER['HTTP_HOST'] ?? '';

if (strpos($httpHost, 'dev.appljack.com') !== false) {
    $environment = 'DEV_SERVER';
    $basePath = "/chroot/home/appljack/appljack.com/html/dev/";
} elseif (strpos($httpHost, 'appljack.com') !== false && strpos($httpHost, 'dev.') === false) {
    $environment = 'PRODUCTION';
    $basePath = "/chroot/home/appljack/appljack.com/html/admin/";
} elseif (strpos($currentPath, '/chroot/') !== false) {
    if (strpos($currentPath, "/dev/") !== false) {
        $environment = 'DEV_SERVER';
        $basePath = "/chroot/home/appljack/appljack.com/html/dev/";
    } else {
        $environment = 'PRODUCTION';
        $basePath = "/chroot/home/appljack/appljack.com/html/admin/";
    }
} else {
    $environment = 'LOCAL_DEV';
    $basePath = __DIR__ . DIRECTORY_SEPARATOR;
}

$logger->log("Environment detected", [
    'environment' => $environment,
    'httpHost' => $httpHost,
    'basePath' => $basePath
]);

// Log all received parameters
$logger->logEventReceived('CPC', $_GET);

// Extract parameters
$custid = $_GET['c'] ?? '';
$feedid = $_GET['f'] ?? '';
$job_reference = $_GET['j'] ?? '';
$jobpoolid = $_GET['jpid'] ?? '';
$publisherid = $_GET['pub'] ?? '';
$refurl = $_SERVER['HTTP_REFERER'] ?? '';
$ipaddress = $_SERVER['REMOTE_ADDR'];
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

$logger->log("Parameters extracted", [
    'custid' => $custid,
    'feedid' => $feedid,
    'job_reference' => $job_reference,
    'jobpoolid' => $jobpoolid,
    'publisherid' => $publisherid
]);

// Validate required parameters
if (empty($custid) || empty($feedid)) {
    $logger->logError("Missing required parameters", [
        'custid' => $custid,
        'feedid' => $feedid
    ]);
    http_response_code(400);
    die("Missing required parameters");
}

// Generate event ID and timestamp
$eventid = bin2hex(random_bytes(5));
$timestamp = date('Y-m-d H:i:s');

$logger->log("Event generated", [
    'eventid' => $eventid,
    'timestamp' => $timestamp
]);

// Create event data
$eventData = [
    'eventid' => $eventid,
    'timestamp' => $timestamp,
    'custid' => $custid,
    'publisherid' => $publisherid,
    'job_reference' => $job_reference,
    'jobpoolid' => $jobpoolid,
    'refurl' => $refurl,
    'ipaddress' => $ipaddress,
    'feedid' => $feedid,
    'userAgent' => $userAgent
];

// Check if we should lookup budget_type from database
include 'database/db.php';

if ($db) {
    $feedQuery = "SELECT feedid, feedname, budget_type, cpc, cpa FROM applcustfeeds WHERE feedid = '$feedid'";
    $result = $db->query($feedQuery);

    if ($feed = $result->fetch_assoc()) {
        $logger->logDatabaseOperation('SELECT', 'applcustfeeds', $feed);

        $budgetType = $feed['budget_type'] ?? 'CPC';
        $cpcValue = $feed['cpc'];
        $cpaValue = $feed['cpa'];

        $logger->log("Feed configuration loaded", [
            'feedname' => $feed['feedname'],
            'budget_type' => $budgetType,
            'cpc' => $cpcValue,
            'cpa' => $cpaValue
        ]);

        // Log what SHOULD happen based on budget type
        if ($budgetType === 'CPA') {
            $logger->log("CPA Campaign - CPC events should be FREE", [
                'expected_cpc_charge' => 0.00,
                'reason' => 'CPA campaigns only charge for conversions'
            ]);
        } else {
            $logger->log("CPC Campaign - CPC events should be CHARGED", [
                'expected_cpc_charge' => $cpcValue,
                'reason' => 'CPC campaigns charge for clicks'
            ]);
        }
    } else {
        $logger->logError("Feed not found in database", ['feedid' => $feedid]);
    }
}

// Write to queue file
$queueFile = $basePath . "applpass_queue.json";

$logger->log("Attempting to write to queue", [
    'file' => $queueFile,
    'exists' => file_exists($queueFile),
    'writable' => is_writable($queueFile)
]);

// Check file permissions
if (!file_exists($queueFile)) {
    $logger->log("Creating new queue file", ['file' => $queueFile]);
    touch($queueFile);
    chmod($queueFile, 0666);
}

// Write event to queue
$write_result = file_put_contents($queueFile, json_encode($eventData) . "\n", FILE_APPEND | LOCK_EX);

if ($write_result === false) {
    $logger->logQueueWrite('CPC', $queueFile, false, $eventData);
    $logger->logError("Failed to write to queue", [
        'error' => error_get_last(),
        'file_exists' => file_exists($queueFile),
        'is_writable' => is_writable($queueFile),
        'permissions' => substr(sprintf('%o', fileperms($queueFile)), -4)
    ]);

    http_response_code(500);
    echo "Failed to write event to queue";
} else {
    $logger->logQueueWrite('CPC', $queueFile, true, $eventData);
    $logger->log("Queue write successful", [
        'bytes_written' => $write_result,
        'total_events_in_queue' => count(file($queueFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
    ]);

    http_response_code(200);
    echo "OK";
}

$logger->log("========== CPC EVENT END ==========\n");

// Return response for AJAX calls
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $write_result !== false,
        'eventid' => $eventid,
        'environment' => $environment,
        'queue_file' => $queueFile
    ]);
}