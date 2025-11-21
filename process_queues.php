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

// Initialize logging
function logToFile($message, $basePath) {
    $logFile = $basePath . "process_queues_debug.log";
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

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

logToFile("========== PROCESS QUEUES START ==========", $basePath);
logToFile("Environment: $basePath", $basePath);

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
                // custid is bigint - must be numeric (default to 0 for test events)
                $custid = intval($event['custid'] ?? 0);
                $jobRef = $db->real_escape_string($event['job_reference'] ?? 'test');
                $jobPoolId = $db->real_escape_string($event['jobpoolid'] ?? 'test');
                $ipaddress = $db->real_escape_string($event['ipaddress'] ?? '127.0.0.1');
                $eventid = $db->real_escape_string($event['eventid'] ?? uniqid('evt_'));
                $timestamp = $db->real_escape_string($event['timestamp'] ?? date('Y-m-d H:i:s'));
                $refurl = $db->real_escape_string($event['refurl'] ?? 'https://click');
                $useragent = $db->real_escape_string($event['userAgent'] ?? 'Test User Agent');

                // Insert into database (with all required fields)
                $insertQuery = "INSERT INTO applevents (
                    eventid, eventtype, custid, feedid, jobid, refurl, useragent,
                    ipaddress, cpc, cpa, timestamp
                ) VALUES (
                    '$eventid',
                    'cpc',
                    '$custid',
                    '$feedId',
                    '$jobRef',
                    '$refurl',
                    '$useragent',
                    '$ipaddress',
                    $cpcValue,
                    0.00,
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

logToFile("Checking CPA queue: $cpaQueue", $basePath);

if (file_exists($cpaQueue) && filesize($cpaQueue) > 0) {
    $content = file_get_contents($cpaQueue);
    $lines = array_filter(explode("\n", $content));

    logToFile("CPA Queue has " . count($lines) . " events to process", $basePath);

    foreach ($lines as $lineNum => $line) {
        logToFile("Processing CPA event #" . ($lineNum + 1), $basePath);

        $event = json_decode($line, true);
        if (!$event) {
            logToFile("ERROR: Failed to decode JSON: $line", $basePath);
            continue;
        }

        logToFile("Event data: " . json_encode($event), $basePath);

        // For CPA events, we need to find the related feed
        // This is a simplified version - you might need to adjust based on your logic
        $domain = $event['domain'] ?? '';

        logToFile("Looking for active feed...", $basePath);

        // Get a default feed for testing (you might want to improve this logic)
        $result = $db->query("SELECT feedid, budget_type, cpc, cpa FROM applcustfeeds WHERE status = 'active' LIMIT 1");
        if ($feed = $result->fetch_assoc()) {
            $budgetType = $feed['budget_type'] ?? 'CPC';
            $feedId = $feed['feedid'];

            logToFile("Found feed: $feedId, budget_type: $budgetType", $basePath);

            // Calculate CPA value based on budget type
            if ($budgetType === 'CPC') {
                $cpaValue = 0.00; // CPC campaigns don't charge for conversions
                logToFile("CPC campaign - CPA value set to 0.00", $basePath);
            } else {
                $cpaValue = $feed['cpa']; // Use feed's CPA value
                logToFile("CPA campaign - CPA value set to $cpaValue", $basePath);
            }

            // Ensure all required fields have values
            // IMPORTANT: eventid max length is 20 chars (char(20) column)
            $rawEventId = $event['eventid'] ?? uniqid('evt_');
            if (strlen($rawEventId) > 20) {
                $rawEventId = substr($rawEventId, 0, 20); // Truncate to 20 chars
                logToFile("⚠️ Event ID truncated to 20 chars: $rawEventId", $basePath);
            }
            $eventid = $db->real_escape_string($rawEventId);
            $ipaddress = $db->real_escape_string($event['ipaddress'] ?? '127.0.0.1');
            $timestamp = $db->real_escape_string($event['timestamp'] ?? date('Y-m-d H:i:s'));

            // custid is bigint - must be numeric (default to 0 for test events)
            $custid = intval($event['custid'] ?? 0);

            $jobid = $db->real_escape_string($event['job_reference'] ?? 'cpa_event');
            $refurl = $db->real_escape_string($event['refurl'] ?? $event['domain'] ?? 'https://conversion');
            $useragent = $db->real_escape_string($event['userAgent'] ?? 'CPA Conversion Event');

            logToFile("Prepared fields: eventid=$eventid, custid=$custid, jobid=$jobid, ipaddress=$ipaddress", $basePath);

            // Insert into database (with all required fields)
            $insertQuery = "INSERT INTO applevents (
                eventid, eventtype, custid, feedid, jobid, refurl, useragent,
                cpc, cpa, ipaddress, timestamp
            ) VALUES (
                '$eventid',
                'cpa',
                '$custid',
                '$feedId',
                '$jobid',
                '$refurl',
                '$useragent',
                0.00,
                $cpaValue,
                '$ipaddress',
                '$timestamp'
            )";

            logToFile("Executing query: $insertQuery", $basePath);

            if ($db->query($insertQuery)) {
                $response['cpa']['processed']++;
                logToFile("✅ SUCCESS: Event inserted into database", $basePath);
            } else {
                $error = $db->error;
                $response['cpa']['errors'][] = $error;
                logToFile("❌ FAILED: " . $error, $basePath);
            }
        } else {
            logToFile("❌ ERROR: No active feed found in database", $basePath);
        }
    }

    // Move processed events to backup
    if ($response['cpa']['processed'] > 0) {
        logToFile("Moving processed events to backup", $basePath);
        file_put_contents($cpaBackup, $content, FILE_APPEND | LOCK_EX);
        file_put_contents($cpaQueue, ''); // Clear the queue
        logToFile("Queue cleared", $basePath);
    } else {
        logToFile("No events were successfully processed - queue NOT cleared", $basePath);
    }
} else {
    logToFile("CPA queue is empty or doesn't exist", $basePath);
}

logToFile("========== PROCESS COMPLETE ==========", $basePath);
logToFile("CPC processed: " . $response['cpc']['processed'], $basePath);
logToFile("CPA processed: " . $response['cpa']['processed'], $basePath);
logToFile("CPC errors: " . count($response['cpc']['errors']), $basePath);
logToFile("CPA errors: " . count($response['cpa']['errors']), $basePath);

$response['timestamp'] = date('Y-m-d H:i:s');
$response['log_file'] = $basePath . "process_queues_debug.log";

echo json_encode($response, JSON_PRETTY_PRINT);