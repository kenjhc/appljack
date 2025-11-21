<?php
/**
 * RESET TEST ENVIRONMENT
 * Clears queues and logs for fresh testing
 */

header('Content-Type: text/plain');

$basePath = __DIR__ . DIRECTORY_SEPARATOR;

echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║          RESET TEST ENVIRONMENT                                    ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";

// Clear queue files
$files = [
    'applpass_queue.json' => 'CPC Queue',
    'applpass_cpa_queue.json' => 'CPA Queue',
    'process_queues_debug.log' => 'Debug Log'
];

foreach ($files as $file => $label) {
    $fullPath = $basePath . $file;
    if (file_exists($fullPath)) {
        $size = filesize($fullPath);
        file_put_contents($fullPath, '');
        echo "✅ Cleared $label ($size bytes → 0 bytes)\n";
    } else {
        touch($fullPath);
        chmod($fullPath, 0666);
        echo "✅ Created $label\n";
    }
}

echo "\n✅ Test environment reset!\n\n";
echo "Next steps:\n";
echo "1. Run: http://localhost/appljack/test_local.php\n";
echo "2. Use dashboard: http://localhost/appljack/test_dashboard.html\n";
echo "\n";