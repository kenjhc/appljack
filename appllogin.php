<?php
include 'database/db.php';

// Check if the form is submitted
if (isset($_POST['login'])) {

    // Get user input from form
    $custemail = $db->real_escape_string($_POST['acctemail']);
    $custpw = $_POST['acctpw'];

    // Query the database for user
    $query = $db->prepare("SELECT acctfname, acctlname, acctnum, acctpw, acctrole FROM applacct WHERE acctemail = ?");
    if (!$query) {
        handleDbError("Prepare failed: " . $db->error);
    }
    $query->bind_param("s", $custemail);
    echo "asd";
    $query->execute();

    $result = $query->get_result();
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        // Use password_verify() to check if the entered password matches the hashed password in the database
        if (password_verify($custpw, $user['acctpw'])) {
            // Authentication successful
            $_SESSION['acctnum'] = $user['acctnum']; // Store acctnum in session
            $_SESSION['acctrole'] = $user['acctrole']; // Store acctnum in session
            $_SESSION['acctfname'] = $user['acctfname']; // Store acctnum in session
            $_SESSION['acctlname'] = $user['acctlname']; // Store acctnum in session
            $_SESSION['acctname'] = $user['acctfname'] . " " . $user['acctlname']; // Store acctnum in session
            setToastMessage('success', "Login successfully.");
            header("Location: applmasterview.php"); // Redirect to portal.php
            exit();
        } else {
            setToastMessage('error', "Email or password not matched.");
        }
    } else {
        setToastMessage('error', "User not found.");
    }
    $query->close();
    $db->close();
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Appljack Customer Login | ApplJack</title>
    <?php include 'header.php'; ?>
</head>
<style>
    body {
        background-color: #f7f7f7;
    }

    .main {
        height: 86vh;
    }

    .login-container {
        max-width: 500px;
        margin: 100px auto;
        padding: 40px;
        background-color: #f7f7f7;
        border-radius: 10px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
    }

    .logo {
        text-align: center;
        margin-bottom: 20px;
    }

    .logo img {
        width: 100%;
        /* Adjust logo size */
    }

    .btn-custom {
        background-color: #2b6f76;
        /* Change button color */
        color: white;
    }

    .btn-custom:hover {
        background-color: #2a656b;
        /* Button hover color */
    }

    label {
        color: #2a3d44 !important;
        font-size: 16px !important;
        margin: 0;
        font-weight: 600;
    }

    input {
        border: 2px solid #2a3d44 !important;
        border-radius: 6px !important;
    }
</style>

<body>
    <div class="main">
        <div class="login-container rounded-md shadow-md">
            <div class="logo">
                <img src="./images/dark-logo.png" alt="Appljack Logo">
            </div>
            <form method="post" action="appllogin.php">
                <div class="mb-3">
                    <label for="email" class="form-label">Email:</label>
                    <input type="email" class="form-control py-4" id="email" name="acctemail" placeholder="Enter your email" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password:</label>
                    <input type="password" class="form-control py-4" id="password" name="acctpw" placeholder="Enter your password" required>
                </div>
                <button class="btn_green_dark rounded-md w-100 py-3" name="login">Login</button>
            </form>
        </div>
    </div>

    <?php include 'footer.php'; ?>
</body>

</html>