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

<body>

    <?php include 'appltopnav.php'; ?>
    <?php echo renderHeader(
        "Login"
    ); ?>
    <section class="login_sec">
        <div class="container-fluid">

            <div class="px-4 px-md-5 py-4">
                <div class="row">
                    <div class="col-md-8 mx-auto">
                        <form action="appllogin.php" method="post">
                            <label for="email" class="m-0">Email:</label>
                            <input type="email" id="email" name="acctemail" required>
                            <label for="pw" class="m-0">Password:</label>
                            <input type="password" id="pw" name="acctpw" required>
                            <button type="submit" name="login">Login</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php include 'footer.php'; ?>
</body>

</html>