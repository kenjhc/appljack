<?php
// Check queue files status - returns JSON for the test page
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

// Check CPC Queue
$cpcQueue = $basePath . "applpass_queue.json";
$response['cpc'] = [
    'file' => $cpcQueue,
    'exists' => file_exists($cpcQueue),
    'size' => 0,
    'events' => 0,
    'lastEvent' => null,
    'writable' => false
];

if (file_exists($cpcQueue)) {
    $response['cpc']['size'] = filesize($cpcQueue);
    $response['cpc']['writable'] = is_writable($cpcQueue);

    if ($response['cpc']['size'] > 0) {
        $content = file_get_contents($cpcQueue);
        $lines = array_filter(explode("\n", $content));
        $response['cpc']['events'] = count($lines);

        if (count($lines) > 0) {
            $lastLine = end($lines);
            $lastEvent = json_decode($lastLine, true);
            if ($lastEvent) {
                $response['cpc']['lastEvent'] = [
                    'id' => $lastEvent['eventid'] ?? 'unknown',
                    'time' => $lastEvent['timestamp'] ?? 'unknown'
                ];
            }
        }
    }
}

// Check CPA Queue
$cpaQueue = $basePath . "applpass_cpa_queue.json";
$response['cpa'] = [
    'file' => $cpaQueue,
    'exists' => file_exists($cpaQueue),
    'size' => 0,
    'events' => 0,
    'lastEvent' => null,
    'writable' => false
];

if (file_exists($cpaQueue)) {
    $response['cpa']['size'] = filesize($cpaQueue);
    $response['cpa']['writable'] = is_writable($cpaQueue);

    if ($response['cpa']['size'] > 0) {
        $content = file_get_contents($cpaQueue);
        $lines = array_filter(explode("\n", $content));
        $response['cpa']['events'] = count($lines);

        if (count($lines) > 0) {
            $lastLine = end($lines);
            $lastEvent = json_decode($lastLine, true);
            if ($lastEvent) {
                $response['cpa']['lastEvent'] = [
                    'id' => $lastEvent['eventid'] ?? 'unknown',
                    'time' => $lastEvent['timestamp'] ?? 'unknown'
                ];
            }
        }
    }
}

// Add environment info
$response['environment'] = [
    'host' => $httpHost,
    'path' => $basePath,
    'is_dev' => strpos($httpHost, 'dev.') !== false,
    'is_production' => strpos($httpHost, 'appljack.com') !== false && strpos($httpHost, 'dev.') === false,
    'is_local' => strpos($basePath, '/chroot/') === false
];

echo json_encode($response, JSON_PRETTY_PRINT);