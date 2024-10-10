<?php
include "database/db.php";

if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php"); // Redirect to login page if not authenticated
    exit();
}

// Display any error messages from form submission
$error = '';
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']); // Make sure to clear the error so it doesn't persist
}

// Check if custid is set in the URL and validate it
if (isset($_GET['custid']) && is_numeric($_GET['custid'])) {
    $custid = $_GET['custid'];
} else {
    // Handle the error or redirect the user
    die('Invalid Customer ID.');
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Create Campaigns | Appljack</title>
    <!-- Link to CSS files and other head elements -->
    <?php include 'header.php'; ?>
</head>

<body>
    <?php include 'appltopnav.php'; ?>
    <?php if ($error): ?>
        <?php
        setToastMessage('error', htmlspecialchars($error));
        ?>
    <?php endif; ?>

    <?php echo renderHeader(
        "Create a New Campaign"
    ); ?>

    <section class="job_section">
        <div class="content-container"> <!-- New container to wrap the form and hints section -->
            <div class="form-container"> <!-- Container for the form to help with styling -->
                <form action="applputfeed.php" method="post">
                    <label for="feedname">Campaign Name (required)</label>
                    <input type="text" id="feedname" name="feedname" maxlength="50" required><br>

                    <label for="feedbudget">Monthly Budget (required)</label> <br><span class="notes">NOTE: Do not include the dollar sign (Example: 35.75)</span><br>
                    <div class="input-wrapper" id="budget-wrapper">
                        <input type="text" id="feedbudget" name="feedbudget" maxlength="50" required>
                    </div><br>

                    <label for="feedcpc">CPC Amount (optional) - NOTE: Setting this will overwrite any CPC values in the inbound feed file.</label> <br><span class="notes">NOTE: Do not include the dollar sign (Example: 35.75)</span><br>
                    <div class="input-wrapper" id="cpc-wrapper">
                        <input type="text" id="feedcpc" name="feedcpc" maxlength="50">
                    </div><br>

                    <button class="btn_green w-100">Create Campaign</button>
                </form>
            </div>
            <div class="hints-container"> <!-- New "Helpful Hints" section -->
                <h2>HELPFUL HINTS</h2>
                <ul>
                    <li><b>Required fields</b> - You must include a Feed Title and Budget. The CPC value is optional if you're using the CPC values from the Job Pool itself.</li>
                    <li><b>Where do I do my filtering?</b> - This will be accomplished via the EDIT link on your campaign. You'll see this link in your campaign list.</li>
                    <li><b>4 hours</b> - It will take up to 4 hours for your feed to be ready after you create this campaign.</li>
                    <li><b>What about CPA?</b> - Setting a CPA is coming in a future version of Appljack!</li>
                </ul>
            </div>
        </div>
    </section>
    <?php include 'footer.php'; ?>
</body>

</html>