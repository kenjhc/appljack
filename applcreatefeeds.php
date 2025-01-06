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
        <div class="container-fluid">
            <div class="row xml_mapping_sec second">
                <div class="col-sm-12 col-md-6">
                    <div class="add_field_form">
                        <div class="card rounded-md shadow-md">
                            <div class="card-body">
                                <div class="card styled m-4 p-4">
                                    <div class="card-body p-0">
                                        <form action="applputfeed.php" method="post">
                                            <div>
                                                <label class="healthy-text text-dark-green mt-3" for="feedname">Campaign Name (required)</label>
                                                <input type="text" id="feedname" name="feedname" maxlength="50" class="light-input" required>
                                            </div>
                                            <div>
                                                <div class="d-flex justify-content-center gap-2 my-3 flex-column">
                                                    <label class="healthy-text text-dark-green mb-0" for="feedbudget">Monthly Budget (required)</label> <span class="notes">NOTE: Do not include the dollar sign (Example: 35.75)</span>
                                                </div>
                                                <div class="input-wrapper feed" id="budget-wrapper">
                                                    <input type="text" id="feedbudget" name="feedbudget" class="light-input" maxlength="50" required>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center justify-content-between gap-3">
                                                <div class="w-100 my-3">
                                                    <label class="healthy-text text-dark-green mb-0" for="cpc_adjust">CPC Adjust (%)</label>
                                                    <input type="number" step="0.01" placeholder="0.01" min="0" max="100.00" class="light-input" name="arbcampcpc">
                                                </div>
                                                <div class="w-100 my-3">
                                                    <label class="healthy-text text-dark-green mb-0" for="cpa_adjust">CPA Adjust (%)</label>
                                                    <input type="number" step="0.01" placeholder="0.01" min="0" max="100.00" class="light-input" name="arbcampcpa">
                                                </div>
                                            </div>
                                            <div>
                                                <div class="d-flex justify-content-center gap-2 my-3 flex-column">
                                                    <label class="healthy-text text-dark-green mb-0" for="feedcpc">CPC Amount (optional) - NOTE: Setting this will overwrite any CPC values in the inbound feed file.</label> <span class="notes">NOTE: Do not include the dollar sign (Example: 35.75)</span>
                                                </div>
                                                <div class="input-wrapper feed" id="cpc-wrapper">
                                                    <input type="text" id="feedcpc" name="feedcpc" class="light-input" maxlength="50">
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center justify-content-between gap-3">
                                                <div class="w-100 my-3">
                                                    <label class="healthy-text text-dark-green mb-0" for="start_date">Start Date</label>
                                                    <input type="date" id="startdate" name="startdate" class="form-control" value="" >

                                                </div>

                                         
                                                <div class="w-100 my-3">
                                                    <label class="healthy-text text-dark-green mb-0" for="end_date">End Date</label>
                                                    <input type="date" id="enddate" name="enddate" class="form-control" value="" >
                                                </div>
                                            </div>
                                            <button class="btn_green_dark w-100 mt-4">Create Campaign</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-12 col-md-6">
                    <div class="add_field_form">
                        <div class="card rounded-md shadow-md">
                            <div class="card-body">
                                <div class="card styled m-4 p-4">
                                    <div class="card-body p-0">
                                        <div class="hints-container">
                                            <h4 class="job_title text-center fw-bold">HELPFUL HINTS</h4>
                                            <ul>
                                                <li><b class="text-dark-green fw-bold">Required fields</b><br> You must include a Feed Title and Budget. The CPC value is optional if you're using the CPC values from the Job Pool itself.</li>
                                                <li><b class="text-dark-green fw-bold">Where do I do my filtering?</b> <br>This will be accomplished via the EDIT link on your campaign. You'll see this link in your campaign list.</li>
                                                <li><b class="text-dark-green fw-bold">4 hours</b> <br>It will take up to 4 hours for your feed to be ready after you create this campaign.</li>
                                                <li><b class="text-dark-green fw-bold">What about CPA?</b><br> Setting a CPA is coming in a future version of Appljack!</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php include 'footer.php'; ?>
</body>

</html>