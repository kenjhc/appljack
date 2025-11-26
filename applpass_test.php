<?php
/**
 * TEST VERSION OF applpass.php
 * This version bypasses job validation for testing purposes
 * Use this ONLY for testing the CPC/CPA toggle functionality
 */

// MUST have CORS headers at the very top
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include 'database/db.php';

// Auto-detect environment and set base path
$currentPath = __DIR__;
$httpHost = $_SERVER['HTTP_HOST'] ?? '';

// Determine environment based on domain
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

// Set the default error log file location
ini_set("error_log", $basePath . "applpass7.log");

error_log("TEST Script started...");
error_log("Environment: " . $environment);
error_log("Domain: " . $httpHost);
error_log("Base path: " . $basePath);

// Extract query parameters
$custid = $_GET['c'] ?? 'test';
$feedid = $_GET['f'] ?? 'test';
$job_reference = $_GET['j'] ?? 'test';
$jobpoolid = $_GET['jpid'] ?? 'test';
$pubid = $_GET['pub'] ?? 'test';
$refurl = urldecode($_GET['refurl'] ?? ($_SERVER['HTTP_REFERER'] ?? 'no-referrer'));

// Log extracted parameters
error_log("custid: " . $custid);
error_log("feedid: " . $feedid);
error_log("job_reference: " . $job_reference);
error_log("jobpoolid: " . $jobpoolid);

// Prepare event data to be written to the JSON file
$eventData = [
    'eventid' => bin2hex(random_bytes(5)),
    'timestamp' => date('Y-m-d H:i:s'),
    'custid' => $custid,
    'publisherid' => $pubid,
    'job_reference' => $job_reference,
    'jobpoolid' => $jobpoolid,
    'refurl' => $refurl,
    'ipaddress' => $_SERVER['REMOTE_ADDR'],
    'feedid' => $feedid,
    'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
];

// Log the event data before writing to the file
error_log("Event data to write: " . json_encode($eventData));

// Attempt to write event data to a JSON file using detected base path
$file_path = $basePath . "applpass_queue.json";
$write_result = file_put_contents($file_path, json_encode($eventData) . PHP_EOL, FILE_APPEND | LOCK_EX);

// Log the result of the file write operation
if ($write_result === false) {
    error_log("Failed to write event data to file: $file_path");
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to write to queue',
        'file' => $file_path
    ]);
} else {
    error_log("Successfully wrote event data to file: $file_path");
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Event queued successfully',
        'eventid' => $eventData['eventid'],
        'environment' => $environment,
        'file' => $file_path,
        'bytes_written' => $write_result
    ]);
}

$db->close();