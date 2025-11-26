<?php
/**
 * DEBUG SCRIPT FOR CPC EVENT FAILURE
 * Run this to diagnose why CPC events are failing
 */

header('Content-Type: text/plain');

echo "CPC EVENT FAILURE DIAGNOSTIC\n";
echo "=====================================\n\n";

// Test 1: Check if applpass.php exists and is readable
echo "TEST 1: Check applpass.php\n";
echo "---------------------------------\n";

$files = [
    'applpass.php' => 'CPC Event Handler',
    'cpa-event.php' => 'CPA Event Handler',
    'database/db.php' => 'Database Connection'
];

foreach ($files as $file => $label) {
    if (file_exists($file)) {
        $size = filesize($file);
        $perms = substr(sprintf('%o', fileperms($file)), -4);
        echo "✅ $label exists - Size: {$size}B, Perms: $perms\n";

        // Check for syntax errors
        $output = [];
        $return_var = 0;
        exec("php -l $file 2>&1", $output, $return_var);
        if ($return_var === 0) {
            echo "   ✅ No syntax errors\n";
        } else {
            echo "   ❌ SYNTAX ERROR: " . implode("\n   ", $output) . "\n";
        }
    } else {
        echo "❌ $label NOT FOUND\n";
    }
}

echo "\n";

// Test 2: Check environment detection
echo "TEST 2: Environment Detection\n";
echo "---------------------------------\n";

$currentPath = __DIR__;
$httpHost = $_SERVER['HTTP_HOST'] ?? '';

echo "Current Path: $currentPath\n";
echo "HTTP Host: $httpHost\n";

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

echo "Detected Environment: $environment\n";
echo "Base Path: $basePath\n\n";

// Test 3: Check queue files
echo "TEST 3: Queue File Permissions\n";
echo "---------------------------------\n";

$queueFiles = [
    'applpass_queue.json' => 'CPC Queue',
    'applpass_cpa_queue.json' => 'CPA Queue'
];

$needsFix = false;

foreach ($queueFiles as $file => $label) {
    $fullPath = $basePath . $file;
    echo "$label ($fullPath):\n";

    if (!file_exists($fullPath)) {
        echo "  ❌ File doesn't exist - Creating...\n";
        if (@touch($fullPath)) {
            chmod($fullPath, 0666);
            echo "  ✅ File created with permissions 0666\n";
        } else {
            echo "  ❌ FAILED to create file\n";
            $needsFix = true;
        }
    } else {
        $perms = substr(sprintf('%o', fileperms($fullPath)), -4);
        $writable = is_writable($fullPath);
        $size = filesize($fullPath);

        echo "  Exists: ✅\n";
        echo "  Size: {$size} bytes\n";
        echo "  Permissions: $perms\n";
        echo "  Writable: " . ($writable ? "✅ YES" : "❌ NO") . "\n";

        if (!$writable) {
            echo "  🔧 Fixing permissions...\n";
            if (@chmod($fullPath, 0666)) {
                echo "  ✅ Fixed permissions to 0666\n";
            } else {
                echo "  ❌ FAILED to fix permissions\n";
                $needsFix = true;
            }
        }
    }
    echo "\n";
}

if ($needsFix) {
    echo "⚠️  MANUAL FIX REQUIRED:\n";
    echo "Run these commands:\n";
    echo "cd $basePath\n";
    echo "touch applpass_queue.json applpass_cpa_queue.json\n";
    echo "chmod 666 applpass_queue.json applpass_cpa_queue.json\n";
    echo "\n";
}

// Test 4: Test CPC Event URL
echo "TEST 4: Test CPC Event Processing\n";
echo "---------------------------------\n";

// Simulate a CPC event locally
$testEvent = [
    'eventid' => 'test_' . uniqid(),
    'timestamp' => date('Y-m-d H:i:s'),
    'custid' => '9706023615',
    'feedid' => 'aaa7c0e9ef',
    'job_reference' => '10081',
    'jobpoolid' => '2244384563',
    'test' => true
];

echo "Test Event Data:\n";
foreach ($testEvent as $key => $value) {
    echo "  $key: $value\n";
}

echo "\nAttempting to write to CPC queue...\n";
$cpcQueue = $basePath . "applpass_queue.json";
$result = @file_put_contents($cpcQueue, json_encode($testEvent) . "\n", FILE_APPEND | LOCK_EX);

if ($result !== false) {
    echo "✅ Successfully wrote test event to queue ($result bytes)\n";
} else {
    echo "❌ FAILED to write test event\n";
    $error = error_get_last();
    if ($error) {
        echo "Error: " . $error['message'] . "\n";
    }
}

// Test 5: Check database connection
echo "\nTEST 5: Database Connection\n";
echo "---------------------------------\n";

if (file_exists('database/db.php')) {
    include 'database/db.php';

    if (isset($db) && $db) {
        if ($db->ping()) {
            echo "✅ Database connection successful\n";

            // Check for test feed
            $result = $db->query("SELECT feedid, feedname, budget_type, cpc, cpa FROM applcustfeeds WHERE feedid = 'aaa7c0e9ef'");
            if ($result && $row = $result->fetch_assoc()) {
                echo "✅ Test feed found:\n";
                echo "   Feed: " . $row['feedname'] . "\n";
                echo "   Budget Type: " . ($row['budget_type'] ?? 'NULL') . "\n";
                echo "   CPC: $" . $row['cpc'] . "\n";
                echo "   CPA: $" . $row['cpa'] . "\n";
            } else {
                echo "❌ Test feed not found\n";
            }
        } else {
            echo "❌ Database connection failed\n";
        }
    } else {
        echo "❌ Database object not created\n";
    }
} else {
    echo "❌ Database file not found\n";
}

// Test 6: Check for errors in applpass.php
echo "\nTEST 6: Check applpass.php Content\n";
echo "---------------------------------\n";

if (file_exists('applpass.php')) {
    $content = file_get_contents('applpass.php');

    // Check for common issues
    $issues = [];

    if (strpos($content, 'die(') !== false || strpos($content, 'exit(') !== false) {
        $issues[] = "Contains die() or exit() statements that might stop execution";
    }

    if (strpos($content, 'error_reporting(0)') !== false) {
        $issues[] = "Error reporting is disabled";
    }

    if (!strpos($content, 'header("Access-Control-Allow-Origin') !== false) {
        $issues[] = "Missing CORS headers";
    }

    if (strpos($content, '/chroot/home/appljack/appljack.com/html/admin/') !== false &&
        strpos($content, 'dev.appljack.com') === false) {
        $issues[] = "May have hardcoded production paths";
    }

    if (count($issues) > 0) {
        echo "⚠️  Potential issues found:\n";
        foreach ($issues as $issue) {
            echo "   - $issue\n";
        }
    } else {
        echo "✅ No obvious issues detected\n";
    }
}

// Summary
echo "\n=====================================\n";
echo "SUMMARY\n";
echo "=====================================\n";

echo "\nTo test CPC event manually, use curl:\n";
echo "curl -v \"http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/applpass.php?c=9706023615&f=aaa7c0e9ef&j=10081&jpid=2244384563\"\n";

echo "\nTo check the response:\n";
echo "1. Look for HTTP response code (should be 200)\n";
echo "2. Check if anything is written to: $cpcQueue\n";
echo "3. Check error logs: tail -f " . $basePath . "applpass7.log\n";

echo "\nIf fetch is failing, check:\n";
echo "1. File permissions (should be 644 or 755 for PHP files)\n";
echo "2. .htaccess rules that might block access\n";
echo "3. PHP errors: tail -f /var/log/apache2/error.log\n";

echo "\n";