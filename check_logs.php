<?php
// Check error logs - returns JSON for the test page
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Auto-detect environment
$currentPath = __DIR__;
$httpHost = $_SERVER['HTTP_HOST'] ?? '';

if (strpos($httpHost, 'dev.appljack.com') !== false) {
    $basePath = "/chroot/home/appljack/appljack.com/html/dev/";
} elseif (strpos($httpHost, 'appljack.com') !== false && strpos($httpHost, 'dev.') === false) {
    $basePath = "/chroot/home/appljack/appljack.com/html/admin/";
} else {
    $basePath = __DIR__ . DIRECTORY_SEPARATOR;
}

$response = [];

// Check CPC Log
$cpcLog = $basePath . "applpass7.log";
$response['cpc_log'] = [];

if (file_exists($cpcLog) && filesize($cpcLog) > 0) {
    $lines = file($cpcLog);
    // Get last 10 lines
    $response['cpc_log'] = array_map('trim', array_slice($lines, -10));
}

// Check CPA Log
$cpaLog = $basePath . "applpass_cpa.log";
$response['cpa_log'] = [];

if (file_exists($cpaLog) && filesize($cpaLog) > 0) {
    $lines = file($cpaLog);
    // Get last 10 lines
    $response['cpa_log'] = array_map('trim', array_slice($lines, -10));
}

// Add file info
$response['files'] = [
    'cpc_log' => [
        'path' => $cpcLog,
        'exists' => file_exists($cpcLog),
        'size' => file_exists($cpcLog) ? filesize($cpcLog) : 0
    ],
    'cpa_log' => [
        'path' => $cpaLog,
        'exists' => file_exists($cpaLog),
        'size' => file_exists($cpaLog) ? filesize($cpaLog) : 0
    ]
];

echo json_encode($response, JSON_PRETTY_PRINT);