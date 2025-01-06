<?php

header("Access-Control-Allow-Origin: *");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

function logMessage($message)
{
    // Get the current timestamp
    $timestamp = date('Y-m-d H:i:s');
    // Construct the log message with the timestamp and the original message
    $logEntry = "[$timestamp] $message\n";
    // Specify the path to your log file
    $logFilePath = "/chroot/home/appljack/appljack.com/html/admin/cpa-event.log";
    // Write the log entry to the file
    error_log($logEntry, 3, $logFilePath);
}

logMessage("Script started"); // Log the start of the script
// Get client IP address
$ipaddress = $_SERVER['REMOTE_ADDR'];

// Retrieve User Agent
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
logMessage("IP Address: $ipaddress, User Agent: $userAgent"); // Log client info

// Get the client url
$domain = getCurrentUrlBaseDomain();
logMessage("Domain derived: $domain"); // Log the derived domain

// Generate a random 10-digit alphanumeric event ID
try {
    $eventid = bin2hex(random_bytes(5)); // 10 characters
    logMessage("Generated event ID: $eventid"); // Log the generated event ID
} catch (Exception $e) {
    logMessage("Error generating event ID: " . $e->getMessage());
    exit;
}

// Get current timestamp
$timestamp = date('Y-m-d H:i:s');

// Connect to the database
$db = new mysqli('localhost', 'appljack_johnny', 'app1j0hnny01$', 'appljack_core');
if ($db->connect_error) {
    logMessage("Connection failed: (" . $db->connect_errno . ") " . $db->connect_error);
    exit;
} else {
    logMessage("Database connection established successfully.");
}

// Get event from applevents table
$query = $db->prepare("SELECT custid, jobid, feedid, timestamp FROM applevents WHERE useragent=? and ipaddress=? ORDER BY timestamp DESC LIMIT 1");
if (false === $query) {
    logMessage("Prepare failed: (" . $db->errno . ") " . $db->error);
    exit;
}

logMessage($userAgent . " " . $ipaddress . " " . $domain . " " . "check logs");

$refurlPattern = $domain . '%';
$query->bind_param("ss", $userAgent, $ipaddress);
logMessage("Executing query with parameters: UserAgent - $userAgent, IPAddress - $ipaddress, Domain - $domain");
$query->execute();

if ($query->error) {
    logMessage("Query execution error: (" . $query->errno . ") " . $query->error);
    exit;
}
$result = $query->get_result();

if ($result->num_rows === 0) {
    logMessage("No data found. Query: UserAgent - $userAgent, IPAddress - $ipaddress, Domain - $domain");
    exit;
}

if ($data = $result->fetch_assoc()) {

    $checkHours = check48Hours($data['timestamp']);

    if(!$checkHours) {
        exit;
    }

    // insert data in applevents table
    $insertQuery = $db->prepare("INSERT INTO applevents (eventid, timestamp, eventtype, custid, jobid, refurl, ipaddress, cpc, cpa, feedid, useragent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$insertQuery) {
        logMessage("Prepare failed: (" . $db->errno . ") " . $db->error);
        exit;
    }

    $cpc = '0.0';
    $cpa = '0.0';
    $eventType = 'secondary';

    $success = $insertQuery->bind_param("sssssssssss", $eventid, $timestamp, $eventType, $data['custid'], $data['jobid'], $domain, $ipaddress, $cpc, $cpa, $data['feedid'], $userAgent);
    if (!$success) {
        logMessage("Binding parameters failed: (" . $insertQuery->errno . ") " . $insertQuery->error);
        exit;
    }

    $success = $insertQuery->execute();
    if (!$success) {
        logMessage("Execute failed: (" . $insertQuery->errno . ") " . $insertQuery->error);
        exit;
    }

} else {
    logMessage("custid, jobid, feedid values not found for user.");
    exit;
}

// Function to get the base domain from the current URL
function getCurrentUrlBaseDomain()
{
    // Get the protocol
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://";

    // Get the host/domain from the current URL
    $host = $_SERVER['HTTP_HOST'];

    return $protocol . $host;
}

function check48Hours($dbTimestamp)
{
    $dbUnixTimestamp = strtotime($dbTimestamp);

    $currentUnixTimestamp = time();

    $timeDiffSeconds = $currentUnixTimestamp - $dbUnixTimestamp;

    $timeDiffHours = $timeDiffSeconds / 3600;

    if ($timeDiffHours > 48) {
        return false;
    } else {
        return true;
    }
}
?>