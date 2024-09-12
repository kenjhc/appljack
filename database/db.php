<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();

include __DIR__ . "/../utils/misc_func.php";

$env = loadEnv(__DIR__ . '/../.env');
function loadEnv($file)
{
    if (!file_exists($file)) {
        throw new Exception("The .env file is missing.");
    }

    $vars = [];
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        list($key, $value) = explode('=', $line, 2);
        $vars[trim($key)] = trim($value);
    }

    return $vars;
}

// Database configuration from .env file
$host = $env['DB_HOST'];
$db   = $env['DB_DATABASE'];
$user = $env['DB_USERNAME'];
$pass = $env['DB_PASSWORD'];
$charset = $env['DB_CHARSET'];

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    // Establish a PDO connection
    $pdo = new PDO($dsn, $user, $pass, $options);
    $conn = $pdo;

    $db = new mysqli($host, $user, $pass, $db);
    $db->set_charset($charset);

    if ($db->connect_error) {
        error_log("[" . date('Y-m-d H:i:s') . "] Connection failed: (" . $db->connect_errno . ") " . $db->connect_error);
        echo "Error! Something wrong with database connection.";
        exit;
    }
} catch (PDOException $e) {
    // Handle connection error
    die('Database connection failed: ' . $e->getMessage());
}

function checkPermissions(array $restrictedPages, array $roleRestrictedPages)
{
    // printit($restrictedPages);
    // printit(basename($_SERVER['PHP_SELF']), 1);
    if ((in_array(basename($_SERVER['PHP_SELF']), $restrictedPages) && !isset($_SESSION['acctnum'])) || (in_array(basename($_SERVER['PHP_SELF']), $roleRestrictedPages) && (!isset($_SESSION['acctrole']) || isset($_SESSION['acctrole']) && $_SESSION['acctrole'] !== 1)) || (in_array(basename($_SERVER['PHP_SELF']), $roleRestrictedPages) && (in_array(basename($_SERVER['PHP_SELF']), $restrictedPages) && !isset($_SESSION['acctnum'])))) {
        setToastMessage('error', 'You don\'t have permission to access this page.');
        header('Location: applmasterview.php');
        exit();
    }
}


// Custom error handling function
function handleDbError($errorMessage)
{
    // Check if the URL starts with 'dev.'
    if (strpos($_SERVER['HTTP_HOST'], 'dev.') === 0) {
        // Display the full error message
        echo "Database error: " . htmlspecialchars($errorMessage);
    } else {
        // Log the error message
        error_log("[" . date('Y-m-d H:i:s') . "] " . $errorMessage);
        // Display a user-friendly message
        echo "A database error occurred. Please try again later.";
    }
    exit;
}
 