<?php
// Start the session
session_start();

// Check if the user is logged in. This assumes 'custid' is set in your session upon login.
if (!isset($_SESSION['acctnum'])) {
    // If the user is not logged in, redirect them to the login page.
    header("Location: appllogin.php");
    exit();
}

// Proceed with logout logic if the user is logged in
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session.
session_destroy();

// Redirect to login page
header("Location: appllogin.php");
exit();
?>
