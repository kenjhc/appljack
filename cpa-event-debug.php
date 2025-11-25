<?php
/**
 * CPA Event Diagnostic Endpoint
 * Shows queue status, recent events, and system info for debugging
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

// Auto-detect environment and set base path
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

$queueFile = $basePath . "applpass_cpa_queue.json";
$logFile = $basePath . "applpass_cpa.log";

// Get queue file info
$queueInfo = [
    'path' => $queueFile,
    'exists' => file_exists($queueFile),
    'writable' => is_writable($queueFile) || is_writable(dirname($queueFile)),
    'size' => file_exists($queueFile) ? filesize($queueFile) : 0,
    'events_count' => 0,
    'recent_events' => []
];

if (file_exists($queueFile) && filesize($queueFile) > 0) {
    $content = file_get_contents($queueFile);
    $lines = array_filter(explode("\n", $content));
    $queueInfo['events_count'] = count($lines);

    // Get last 5 events
    $lastLines = array_slice($lines, -5);
    foreach ($lastLines as $line) {
        $event = json_decode($line, true);
        if ($event) {
            $queueInfo['recent_events'][] = $event;
        }
    }
}

// Get log file info
$logInfo = [
    'path' => $logFile,
    'exists' => file_exists($logFile),
    'size' => file_exists($logFile) ? filesize($logFile) : 0,
    'recent_entries' => []
];

if (file_exists($logFile)) {
    $logContent = file($logFile);
    $logInfo['recent_entries'] = array_slice($logContent, -15);
}

// Get current request info (for testing)
$requestInfo = [
    'your_ip' => $_SERVER['REMOTE_ADDR'],
    'your_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'N/A',
    'request_time' => date('Y-m-d H:i:s')
];

// Database check (if available)
$dbInfo = null;
$dbFile = $basePath . "database/db.php";
if (file_exists($dbFile)) {
    try {
        include_once $dbFile;
        if (isset($db) && $db instanceof mysqli) {
            // Check recent CPA events in database
            $result = $db->query("
                SELECT eventid, eventtype, feedid, cpc, cpa, ipaddress,
                       LEFT(useragent, 50) as useragent_short, timestamp
                FROM applevents
                WHERE eventtype = 'cpa'
                ORDER BY id DESC
                LIMIT 5
            ");

            $recentCPAEvents = [];
            while ($row = $result->fetch_assoc()) {
                $recentCPAEvents[] = $row;
            }

            // Check unmatched CPA events
            $deletedResult = $db->query("
                SELECT eventid, deletecode, ipaddress, timestamp
                FROM appleventsdel
                WHERE eventtype = 'cpa'
                ORDER BY id DESC
                LIMIT 5
            ");

            $unmatchedEvents = [];
            while ($row = $deletedResult->fetch_assoc()) {
                $unmatchedEvents[] = $row;
            }

            $dbInfo = [
                'connected' => true,
                'recent_cpa_events' => $recentCPAEvents,
                'unmatched_cpa_events' => $unmatchedEvents
            ];
        }
    } catch (Exception $e) {
        $dbInfo = [
            'connected' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Output response
echo json_encode([
    'environment' => $environment,
    'base_path' => $basePath,
    'queue' => $queueInfo,
    'log' => $logInfo,
    'request' => $requestInfo,
    'database' => $dbInfo,
    'instructions' => [
        '1. Fire CPC click first' => 'Visit applpass.php with job parameters from same browser',
        '2. Process CPC queue' => 'Run: node applpass_putevents2.js',
        '3. Fire CPA pixel' => 'Call: fetch("cpa-event.php") from SAME browser',
        '4. Process CPA queue' => 'Run: node applpass_cpa_putevent.js',
        '5. Verify' => 'Check database for cpa events'
    ],
    'important' => 'CPA events must match CPC clicks by EXACT IP address AND UserAgent. Use the same browser/device for both!'
], JSON_PRETTY_PRINT);
