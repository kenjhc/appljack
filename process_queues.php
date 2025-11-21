<?php
/**
 * PROCESS QUEUE FILES - PHP VERSION
 * This processes queue files without needing Node.js
 * Use this for testing when Node.js scripts aren't working
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

include 'database/db.php';

// Auto-detect environment
$currentPath = __DIR__;
$httpHost = $_SERVER['HTTP_HOST'] ?? '';

if (strpos($httpHost, 'dev.appljack.com') !== false) {
    $basePath = "/chroot/home/appljack/appljack.com/html/dev/";
} elseif (strpos($httpHost, 'appljack.com') !== false && strpos($httpHost, 'dev.') === false) {
    $basePath = "/chroot/home/appljack/appljack.com/html/admin/";
} elseif (strpos($currentPath, '/chroot/') !== false) {
    if (strpos($currentPath, "/dev/") !== false) {
        $basePath = "/chroot/home/appljack/appljack.com/html/dev/";
    } else {
        $basePath = "/chroot/home/appljack/appljack.com/html/admin/";
    }
} else {
    $basePath = __DIR__ . DIRECTORY_SEPARATOR;
}

$response = [
    'cpc' => ['processed' => 0, 'errors' => []],
    'cpa' => ['processed' => 0, 'errors' => []],
];

// Process CPC Queue
$cpcQueue = $basePath . "applpass_queue.json";
$cpcBackup = $basePath . "applpass_queue_backup.json";

if (file_exists($cpcQueue) && filesize($cpcQueue) > 0) {
    $content = file_get_contents($cpcQueue);
    $lines = array_filter(explode("\n", $content));

    foreach ($lines as $line) {
        $event = json_decode($line, true);
        if (!$event) continue;

        // Get feed configuration
        $feedId = $event['feedid'] ?? '';
        if ($feedId) {
            $result = $db->query("SELECT budget_type, cpc, cpa FROM applcustfeeds WHERE feedid = '$feedId'");
            if ($feed = $result->fetch_assoc()) {
                $budgetType = $feed['budget_type'] ?? 'CPC';

                // Calculate CPC value based on budget type
                if ($budgetType === 'CPA') {
                    $cpcValue = 0.00; // CPA campaigns don't charge for clicks
                } else {
                    $cpcValue = $feed['cpc']; // Use feed's CPC value
                }

                // Ensure all required fields have values
                $custid = $db->real_escape_string($event['custid'] ?? 'test');
                $jobRef = $db->real_escape_string($event['job_reference'] ?? 'test');
                $jobPoolId = $db->real_escape_string($event['jobpoolid'] ?? 'test');
                $ipaddress = $db->real_escape_string($event['ipaddress'] ?? '127.0.0.1');
                $eventid = $db->real_escape_string($event['eventid'] ?? uniqid('evt_'));
                $timestamp = $db->real_escape_string($event['timestamp'] ?? date('Y-m-d H:i:s'));

                // Insert into database
                $insertQuery = "INSERT INTO applevents (
                    eventid, eventtype, custid, feedid, job_reference,
                    jobpoolid, cpc, cpa, ipaddress, timestamp
                ) VALUES (
                    '$eventid',
                    'click',
                    '$custid',
                    '$feedId',
                    '$jobRef',
                    '$jobPoolId',
                    $cpcValue,
                    0.00,
                    '$ipaddress',
                    '$timestamp'
                )";

                if ($db->query($insertQuery)) {
                    $response['cpc']['processed']++;
                } else {
                    $response['cpc']['errors'][] = $db->error;
                }
            }
        }
    }

    // Move processed events to backup
    if ($response['cpc']['processed'] > 0) {
        file_put_contents($cpcBackup, $content, FILE_APPEND | LOCK_EX);
        file_put_contents($cpcQueue, ''); // Clear the queue
    }
}

// Process CPA Queue
$cpaQueue = $basePath . "applpass_cpa_queue.json";
$cpaBackup = $basePath . "applpass_cpa_backup.json";

if (file_exists($cpaQueue) && filesize($cpaQueue) > 0) {
    $content = file_get_contents($cpaQueue);
    $lines = array_filter(explode("\n", $content));

    foreach ($lines as $line) {
        $event = json_decode($line, true);
        if (!$event) continue;

        // For CPA events, we need to find the related feed
        // This is a simplified version - you might need to adjust based on your logic
        $domain = $event['domain'] ?? '';

        // Get a default feed for testing (you might want to improve this logic)
        $result = $db->query("SELECT feedid, budget_type, cpc, cpa FROM applcustfeeds WHERE status = 'active' LIMIT 1");
        if ($feed = $result->fetch_assoc()) {
            $budgetType = $feed['budget_type'] ?? 'CPC';
            $feedId = $feed['feedid'];

            // Calculate CPA value based on budget type
            if ($budgetType === 'CPC') {
                $cpaValue = 0.00; // CPC campaigns don't charge for conversions
            } else {
                $cpaValue = $feed['cpa']; // Use feed's CPA value
            }

            // Ensure all required fields have values
            $eventid = $db->real_escape_string($event['eventid'] ?? uniqid('evt_'));
            $ipaddress = $db->real_escape_string($event['ipaddress'] ?? '127.0.0.1');
            $timestamp = $db->real_escape_string($event['timestamp'] ?? date('Y-m-d H:i:s'));
            $custid = $db->real_escape_string($event['custid'] ?? 'test');

            // Insert into database (CPA events need custid field)
            $insertQuery = "INSERT INTO applevents (
                eventid, eventtype, custid, feedid, cpc, cpa, ipaddress, timestamp
            ) VALUES (
                '$eventid',
                'conversion',
                '$custid',
                '$feedId',
                0.00,
                $cpaValue,
                '$ipaddress',
                '$timestamp'
            )";

            if ($db->query($insertQuery)) {
                $response['cpa']['processed']++;
            } else {
                $response['cpa']['errors'][] = $db->error;
            }
        }
    }

    // Move processed events to backup
    if ($response['cpa']['processed'] > 0) {
        file_put_contents($cpaBackup, $content, FILE_APPEND | LOCK_EX);
        file_put_contents($cpaQueue, ''); // Clear the queue
    }
}

$response['timestamp'] = date('Y-m-d H:i:s');

echo json_encode($response, JSON_PRETTY_PRINT);