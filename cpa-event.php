<?php
/**
 * CPA Event Tracker
 * Captures conversion events and writes them to the queue for processing
 *
 * IMPORTANT: CPA events are matched to prior CPC clicks by IP + UserAgent
 * The conversion must come from the same browser/device that clicked the job link
 */

// CORS headers for cross-origin requests
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Handle preflight requests
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0); // Don't display errors in JSON response
error_reporting(E_ALL);

// Auto-detect environment and set base path
$currentPath = __DIR__;
$httpHost = $_SERVER['HTTP_HOST'] ?? '';

// Determine environment based on domain
if (strpos($httpHost, 'dev.appljack.com') !== false) {
    // Dev server: http://dev.appljack.com/
    $environment = 'DEV_SERVER';
    $basePath = "/chroot/home/appljack/appljack.com/html/dev/";
} elseif (strpos($httpHost, 'appljack.com') !== false && strpos($httpHost, 'dev.') === false) {
    // Production server: https://appljack.com/
    $environment = 'PRODUCTION';
    $basePath = "/chroot/home/appljack/appljack.com/html/admin/";
} elseif (strpos($currentPath, '/chroot/') !== false) {
    // On server but couldn't detect domain - check path
    if (strpos($currentPath, "/dev/") !== false) {
        $environment = 'DEV_SERVER';
        $basePath = "/chroot/home/appljack/appljack.com/html/dev/";
    } else {
        $environment = 'PRODUCTION';
        $basePath = "/chroot/home/appljack/appljack.com/html/admin/";
    }
} else {
    // Local development (localhost, laragon, etc.)
    $environment = 'LOCAL_DEV';
    $basePath = __DIR__ . DIRECTORY_SEPARATOR;
}

function logMessage($message)
{
    global $basePath;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    $logFilePath = $basePath . "applpass_cpa.log";
    error_log($logEntry, 3, $logFilePath);
}

logMessage("Script started");
logMessage("Environment: " . $environment);
logMessage("Domain: " . $httpHost);
logMessage("Base path: " . $basePath);

$ipaddress = $_SERVER['REMOTE_ADDR'];
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$domain = getCurrentUrlBaseDomain();
$eventid = bin2hex(random_bytes(5));
$timestamp = date('Y-m-d H:i:s');

$eventData = [
    'eventid' => $eventid,
    'timestamp' => $timestamp,
    'userAgent' => $userAgent,
    'ipaddress' => $ipaddress,
    'domain' => $domain
];

logMessage("Generated event data: " . json_encode($eventData));

// Append the event data to the JSON file using detected base path
$jsonFilePath = $basePath . "applpass_cpa_queue.json";
$write_result = file_put_contents($jsonFilePath, json_encode($eventData) . "\n", FILE_APPEND | LOCK_EX);

if ($write_result === false) {
    logMessage("ERROR: Failed to write event data to file: $jsonFilePath");
    $success = false;
    $error = "Failed to write to queue file";
} else {
    logMessage("Event data written to JSON file: $jsonFilePath ($write_result bytes)");
    $success = true;
    $error = null;
}

// Return JSON response with diagnostic info
echo json_encode([
    'success' => $success,
    'eventid' => $eventid,
    'timestamp' => $timestamp,
    'ipaddress' => $ipaddress,
    'userAgent' => substr($userAgent, 0, 100) . (strlen($userAgent) > 100 ? '...' : ''),
    'environment' => $environment,
    'queue_file' => $jsonFilePath,
    'written_bytes' => $write_result,
    'error' => $error,
    'note' => 'CPA events are matched to CPC clicks by IP + UserAgent. Ensure the conversion comes from the same browser that clicked the job link.'
]);

// Function to get the base domain from the current URL
function getCurrentUrlBaseDomain()
{
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    return $protocol . $host;
}