<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

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
    'success' => true,
    'cleared' => []
];

// Clear CPC queue
$cpcQueue = $basePath . "applpass_queue.json";
if (file_exists($cpcQueue)) {
    // Backup before clearing
    $backupFile = $basePath . "applpass_queue_backup_" . date('Ymd_His') . ".json";
    copy($cpcQueue, $backupFile);

    file_put_contents($cpcQueue, '');
    $response['cleared'][] = 'CPC queue';
}

// Clear CPA queue
$cpaQueue = $basePath . "applpass_cpa_queue.json";
if (file_exists($cpaQueue)) {
    // Backup before clearing
    $backupFile = $basePath . "applpass_cpa_queue_backup_" . date('Ymd_His') . ".json";
    copy($cpaQueue, $backupFile);

    file_put_contents($cpaQueue, '');
    $response['cleared'][] = 'CPA queue';
}

$response['message'] = 'Queues cleared successfully (backups created)';

echo json_encode($response, JSON_PRETTY_PRINT);