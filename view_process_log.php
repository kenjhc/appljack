<?php
/**
 * VIEW PROCESS QUEUES DEBUG LOG
 * Shows the detailed log of what happened during queue processing
 */

header('Content-Type: text/plain');

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

$logFile = $basePath . "process_queues_debug.log";

echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║          PROCESS QUEUES DEBUG LOG                                  ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";

echo "Log file: $logFile\n";
echo str_repeat("=", 70) . "\n\n";

if (file_exists($logFile)) {
    $size = filesize($logFile);
    $lines = file($logFile);

    echo "File size: $size bytes\n";
    echo "Total entries: " . count($lines) . "\n\n";

    echo str_repeat("=", 70) . "\n";
    echo "LOG CONTENTS (last 100 lines):\n";
    echo str_repeat("=", 70) . "\n\n";

    // Show last 100 lines
    $recent = array_slice($lines, -100);
    foreach ($recent as $line) {
        echo $line;
    }

    echo "\n" . str_repeat("=", 70) . "\n";
    echo "END OF LOG\n";
    echo str_repeat("=", 70) . "\n\n";

    // Add a clear button option
    if (isset($_GET['clear']) && $_GET['clear'] === 'yes') {
        file_put_contents($logFile, '');
        echo "\n✅ Log file cleared!\n\n";
    } else {
        echo "\nTo clear the log: " . $_SERVER['PHP_SELF'] . "?clear=yes\n";
    }

} else {
    echo "❌ Log file doesn't exist yet.\n\n";
    echo "The log will be created when you click 'Process Queues' in the dashboard.\n";
}