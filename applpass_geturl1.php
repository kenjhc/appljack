<?php
include 'database/db.php';

// Set the default error log file location
ini_set("error_log", "/chroot/home/appljack/appljack.com/html/applpass6.log");

error_log("Script started...");
 

// Extract query parameters
$custid = $_GET['c'] ?? 'default';
$feedid = $_GET['f'] ?? 'default';
$job_reference = $_GET['j'] ?? 'default';
$jobpoolid = $_GET['jpid'] ?? 'default';
$refurl = urldecode($_GET['refurl'] ?? 'no-referrer');

// Log extracted parameters
error_log("custid: " . $custid);
error_log("feedid: " . $feedid);
error_log("job_reference: " . $job_reference);
error_log("jobpoolid: " . $jobpoolid);

// Ensure critical parameters are provided
if ($job_reference === 'default' || $jobpoolid === 'default') {
    error_log("Missing critical query parameters: job_reference or jobpoolid.");
    exit;
}
 

// Fetch URL from database
$query = $db->prepare("SELECT url FROM appljobs WHERE job_reference = ? AND jobpoolid = ?");
if (!$query) {
    error_log("Prepare failed: " . $db->error);
    exit;
}
$query->bind_param("ss", $job_reference, $jobpoolid);
$query->execute();
$result = $query->get_result();
if (!$result) {
    error_log("Query execution failed: " . $db->error);
    exit;
}
$data = $result->fetch_assoc();
$jobUrl = $data['url'] ?? '/fallback-url';

error_log("Fetched URL: " . $jobUrl);

// Close the database connection
$query->close();
$db->close();

// Log the final URL and perform the redirect without appending UTM parameters
error_log("Final URL: " . $jobUrl);
header('Location: ' . $jobUrl);
exit;
?>
