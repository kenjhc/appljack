<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

include 'database/db.php';

// Auto-detect environment
$currentPath = __DIR__;
$httpHost = $_SERVER['HTTP_HOST'] ?? '';

// Determine environment
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

// Check database connection
$dbConnection = false;
$budgetColumn = false;

try {
    if ($db && $db->ping()) {
        $dbConnection = true;

        // Check for budget_type column
        $result = $db->query("SHOW COLUMNS FROM applcustfeeds LIKE 'budget_type'");
        if ($result && $result->num_rows > 0) {
            $budgetColumn = true;
        }
    }
} catch (Exception $e) {
    $dbConnection = false;
}

// Check queue files
$cpcQueueFile = $basePath . "applpass_queue.json";
$cpaQueueFile = $basePath . "applpass_cpa_queue.json";

$response = [
    'environment' => $environment,
    'httpHost' => $httpHost,
    'basePath' => $basePath,
    'dbConnection' => $dbConnection,
    'budgetColumn' => $budgetColumn,
    'files' => [
        'cpcQueue' => [
            'path' => $cpcQueueFile,
            'exists' => file_exists($cpcQueueFile),
            'writable' => is_writable($cpcQueueFile)
        ],
        'cpaQueue' => [
            'path' => $cpaQueueFile,
            'exists' => file_exists($cpaQueueFile),
            'writable' => is_writable($cpaQueueFile)
        ]
    ],
    'timestamp' => date('Y-m-d H:i:s')
];

echo json_encode($response, JSON_PRETTY_PRINT);