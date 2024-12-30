<?php

header("Access-Control-Allow-Origin: *");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

function logMessage($message)
{
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    $logFilePath = "/chroot/home/appljack/appljack.com/html/dev/applpass_cpa.log";
    error_log($logEntry, 3, $logFilePath);
}

logMessage("Script started");

$ipaddress = $_SERVER['REMOTE_ADDR'];
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$domain = getCurrentUrlBaseDomain();
$eventid = bin2hex(random_bytes(5));
$timestamp = date('Y-m-d H:i:s');

$eventData = [
    'eventid' => $eventid,
    'timestamp' => $timestamp,
    'userAgent' => $userAgent,
    'ipaddress' => $ipaddress,
    'domain' => $domain
];

logMessage("Generated event data: " . json_encode($eventData));

// Append the event data to the JSON file
$jsonFilePath = "/chroot/home/appljack/appljack.com/html/dev/applpass_cpa_queue.json";
file_put_contents($jsonFilePath, json_encode($eventData) . "\n", FILE_APPEND | LOCK_EX);

logMessage("Event data written to JSON file");

// Function to get the base domain from the current URL
function getCurrentUrlBaseDomain()
{
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    return $protocol . $host;
}