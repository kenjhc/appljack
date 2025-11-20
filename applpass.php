<?php
include 'database/db.php';

// Set the default error log file location
ini_set("error_log", __DIR__ . DIRECTORY_SEPARATOR . "applpass7.log");

error_log("Script started...");

// Extract query parameters
$custid = $_GET['c'] ?? 'default';
$feedid = $_GET['f'] ?? 'default';
$job_reference = $_GET['j'] ?? 'default';
$jobpoolid = $_GET['jpid'] ?? 'default';
$pubid = $_GET['pub'] ?? 'default';
$refurl = urldecode($_GET['refurl'] ?? ($_SERVER['HTTP_REFERER'] ?? 'no-referrer'));

// Log extracted parameters
error_log("custid: " . $custid);
error_log("feedid: " . $feedid);
error_log("job_reference: " . $job_reference);
error_log("jobpoolid: " . $jobpoolid);
error_log("pubid: " . $pubid);

// Ensure critical parameters are provided
if ($job_reference === 'default' || $jobpoolid === 'default') {
    error_log("Missing critical query parameters: job_reference or jobpoolid.");
    setToastMessage('error', "Error: Required parameters not provided.");
    exit;
}

// Fetch URL from database
$query = $db->prepare("SELECT url FROM appljobs WHERE job_reference = ? AND jobpoolid = ?");
if (!$query) {
    error_log("Prepare failed: " . $db->error);
    setToastMessage('error', "Error: Query preparation failed.");
    exit;
}
$query->bind_param("ss", $job_reference, $jobpoolid);
$query->execute();
$result = $query->get_result();
if (!$result) {
    error_log("Query execution failed: " . $db->error);
    setToastMessage('error', "Error: Query execution failed.");
    exit;
}
$data = $result->fetch_assoc();

// Check if job was found
if (!$data || empty($data['url'])) {
    error_log("ERROR: Job not found for job_reference: $job_reference, jobpoolid: $jobpoolid");
    error_log("This URL is invalid. Please check if the job exists in the database.");
    echo "Error: Job not found. The job may have expired or been removed.";
    exit;
}

$jobUrl = $data['url'];
error_log("Fetched URL: " . $jobUrl);

// Close the database connection
$query->close();
$db->close();

// Prepare event data to be written to the JSON file
$eventData = [
    'eventid' => bin2hex(random_bytes(5)), // Generate random event ID
    'timestamp' => date('Y-m-d H:i:s'),
    'custid' => $custid,
    'publisherid' => $pubid,
    'job_reference' => $job_reference,
    'jobpoolid' => $jobpoolid,
    'refurl' => $refurl,
    'ipaddress' => $_SERVER['REMOTE_ADDR'],
    'feedid' => $feedid,
    'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
];

// Log the event data before writing to the file
error_log("Event data to write: " . json_encode($eventData));

// Attempt to write event data to a JSON file
$file_path = __DIR__ . DIRECTORY_SEPARATOR . "applpass_queue.json";
$write_result = file_put_contents($file_path, json_encode($eventData) . PHP_EOL, FILE_APPEND | LOCK_EX);

// Log the result of the file write operation
if ($write_result === false) {
    error_log("Failed to write event data to file: $file_path");
} else {
    error_log("Successfully wrote event data to file: $file_path");
}

// Extract existing query parameters from the current URL
$existingParams = $_SERVER['QUERY_STRING'];

// Remove specific parameters that will be replaced by UTM parameters
parse_str($existingParams, $queryParams);
unset($queryParams['c'], $queryParams['f'], $queryParams['j'], $queryParams['jpid']);

// Extract existing parameters from the job URL
$jobUrlQuery = parse_url($jobUrl, PHP_URL_QUERY);
parse_str($jobUrlQuery, $jobUrlParams);

// Merge existing job URL parameters with the current URL's remaining parameters
$mergedParams = array_merge($jobUrlParams, $queryParams);

// Prepare UTM parameters
$utm_parameters = http_build_query([
    'utm_source' => 'appljack',
    'utm_medium' => $custid,
    'utm_campaign' => $feedid,
    'utm_original_job_id' => $job_reference
]);

// Combine all parameters together
$finalQuery = http_build_query($mergedParams) . '&' . $utm_parameters;

// Construct the final URL
$finalUrl = strtok($jobUrl, '?') . '?' . $finalQuery;

// Log the final URL with UTM and existing parameters
error_log("Final URL with UTM and existing parameters: " . $finalUrl);

// Redirect to the final URL with UTM and existing parameters
header('Location: ' . $finalUrl);
exit;
