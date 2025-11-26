<?php
/**
 * PERMISSION DIAGNOSTIC AND FIX
 * Run this to diagnose and fix permission issues
 */

header('Content-Type: text/plain');

echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║          PERMISSION DIAGNOSTIC AND FIX TOOL                        ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";

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

echo "Environment Path: $basePath\n";
echo "Running as: " . (function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user()) . "\n";
echo "PHP SAPI: " . php_sapi_name() . "\n\n";

$files = [
    'applpass_queue.json',
    'applpass_cpa_queue.json'
];

foreach ($files as $filename) {
    $filepath = $basePath . $filename;

    echo str_repeat("=", 70) . "\n";
    echo "FILE: $filename\n";
    echo str_repeat("=", 70) . "\n";

    // Check if file exists
    if (file_exists($filepath)) {
        echo "Status: EXISTS\n";

        // Get detailed info
        $perms = fileperms($filepath);
        $permsOctal = substr(sprintf('%o', $perms), -4);

        echo "Permissions: $permsOctal\n";

        if (function_exists('posix_getpwuid') && function_exists('posix_getgrgid')) {
            $owner = posix_getpwuid(fileowner($filepath));
            $group = posix_getgrgid(filegroup($filepath));
            echo "Owner: " . $owner['name'] . " (UID: " . $owner['uid'] . ")\n";
            echo "Group: " . $group['name'] . " (GID: " . $group['gid'] . ")\n";
        }

        echo "Is Readable: " . (is_readable($filepath) ? "YES" : "NO") . "\n";
        echo "Is Writable: " . (is_writable($filepath) ? "YES" : "NO") . "\n";
        echo "Size: " . filesize($filepath) . " bytes\n";

        // Try to write
        echo "\nTest Write: ";
        $testData = json_encode(['test' => 'permission_check', 'time' => time()]) . "\n";
        $result = @file_put_contents($filepath, $testData, FILE_APPEND | LOCK_EX);

        if ($result !== false) {
            echo "✅ SUCCESS (wrote $result bytes)\n";
        } else {
            echo "❌ FAILED\n";
            $error = error_get_last();
            if ($error) {
                echo "Error: " . $error['message'] . "\n";
            }

            echo "\n🔧 TRYING FIXES:\n";

            // Try chmod
            echo "1. Attempting chmod 0666... ";
            if (@chmod($filepath, 0666)) {
                echo "✅ Success\n";

                // Try write again
                $result = @file_put_contents($filepath, $testData, FILE_APPEND | LOCK_EX);
                if ($result !== false) {
                    echo "   Write test: ✅ NOW WORKS!\n";
                } else {
                    echo "   Write test: ❌ Still fails\n";
                }
            } else {
                echo "❌ Failed\n";
            }
        }

    } else {
        echo "Status: DOES NOT EXIST\n\n";

        // Check if directory is writable
        $dirPath = dirname($filepath);
        echo "Directory: $dirPath\n";
        echo "Directory Writable: " . (is_writable($dirPath) ? "YES" : "NO") . "\n";

        if (is_writable($dirPath)) {
            echo "\n🔧 Attempting to create file... ";

            $result = @file_put_contents($filepath, '');
            if ($result !== false) {
                echo "✅ Created\n";

                // Set permissions
                echo "Setting permissions 0666... ";
                if (@chmod($filepath, 0666)) {
                    echo "✅ Success\n";
                } else {
                    echo "❌ Failed\n";
                }

                // Test write
                echo "Test write... ";
                $testData = json_encode(['test' => 'initial', 'time' => time()]) . "\n";
                $result = @file_put_contents($filepath, $testData, FILE_APPEND | LOCK_EX);
                if ($result !== false) {
                    echo "✅ Works!\n";
                } else {
                    echo "❌ Failed\n";
                }

            } else {
                echo "❌ FAILED\n";
                $error = error_get_last();
                if ($error) {
                    echo "Error: " . $error['message'] . "\n";
                }
            }
        } else {
            echo "\n❌ PROBLEM: Directory is not writable!\n";
            echo "This is the root cause of your issue.\n";
        }
    }

    echo "\n";
}

// Check directory permissions
echo str_repeat("=", 70) . "\n";
echo "DIRECTORY ANALYSIS\n";
echo str_repeat("=", 70) . "\n";

if (is_dir($basePath)) {
    $perms = fileperms($basePath);
    $permsOctal = substr(sprintf('%o', $perms), -4);

    echo "Directory: $basePath\n";
    echo "Permissions: $permsOctal\n";

    if (function_exists('posix_getpwuid') && function_exists('posix_getgrgid')) {
        $owner = posix_getpwuid(fileowner($basePath));
        $group = posix_getgrgid(filegroup($basePath));
        echo "Owner: " . $owner['name'] . " (UID: " . $owner['uid'] . ")\n";
        echo "Group: " . $group['name'] . " (GID: " . $group['gid'] . ")\n";
    }

    echo "Is Writable: " . (is_writable($basePath) ? "YES" : "NO") . "\n";
}

echo "\n";
echo str_repeat("=", 70) . "\n";
echo "RECOMMENDED COMMANDS (Run via SSH)\n";
echo str_repeat("=", 70) . "\n";
echo "\n";
echo "# Navigate to directory\n";
echo "cd $basePath\n\n";

echo "# Check current ownership and permissions\n";
echo "ls -la applpass*.json\n\n";

echo "# If files don't exist, create them\n";
echo "touch applpass_queue.json applpass_cpa_queue.json\n\n";

echo "# Set correct permissions\n";
echo "chmod 666 applpass_queue.json applpass_cpa_queue.json\n\n";

echo "# If that doesn't work, try changing ownership (requires sudo/root)\n";
echo "# Replace 'apache' or 'www-data' with your web server user\n";
echo "chown apache:apache applpass_queue.json applpass_cpa_queue.json\n";
echo "# OR\n";
echo "chown www-data:www-data applpass_queue.json applpass_cpa_queue.json\n\n";

echo "# If still not working, check SELinux (if enabled)\n";
echo "# View SELinux context\n";
echo "ls -Z applpass*.json\n\n";
echo "# Fix SELinux context\n";
echo "chcon -t httpd_sys_rw_content_t applpass_queue.json applpass_cpa_queue.json\n";
echo "# OR disable SELinux temporarily for testing\n";
echo "setenforce 0\n\n";

echo "# Alternative: Make directory group-writable\n";
echo "chmod 775 $basePath\n\n";

echo str_repeat("=", 70) . "\n";
echo "COMMON CAUSES\n";
echo str_repeat("=", 70) . "\n";
echo "\n";
echo "1. File owned by different user (e.g., cron user vs web server user)\n";
echo "   Fix: chown to web server user (apache/www-data/nginx)\n\n";

echo "2. Directory not writable by web server\n";
echo "   Fix: chmod 775 on directory\n\n";

echo "3. SELinux blocking writes (common on CentOS/RHEL)\n";
echo "   Fix: chcon to set correct SELinux context\n\n";

echo "4. File exists but owned by root or another user\n";
echo "   Fix: chown to web server user\n\n";

echo "5. Parent directory permissions blocking\n";
echo "   Fix: Check and fix parent directory permissions\n\n";

echo "\n";