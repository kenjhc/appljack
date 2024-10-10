<?php
include "database/db.php";

if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php"); // Redirect to login page if not authenticated
    exit();
}

$error = '';
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']); // Clear the error so it doesn't persist
}
// this has also been changed by Ken
// Generate a random 10-digit job pool ID
$jobpoolid = mt_rand(1000000000, 9999999999);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Create Job Pool | Appljack</title>
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
        "Create a New Job Pool",
    ); ?>
    <section class="job_section">
        <div class="container-fluid">
            <div class="row xml_mapping_sec second">
                <div class="col-sm-12 col-md-12">
                    <div class="add_field_form">
                        <div class="card rounded-md shadow-md">
                            <div class="card-header p-0 d-flex justify-content-between">
                                <h5 class="card-title">Create a New Job Pool </h5>
                            </div>
                            <div class="card-body">
                                <div class="card styled m-4 p-4">
                                    <div class="card-body p-0">
                                        <form action="applputpool.php" method="post">
                                            <input type="hidden" name="jobpoolid" value="<?php echo $jobpoolid; ?>">
                                            <input type="hidden" name="acctnum" value="<?php echo $_SESSION['acctnum']; ?>"> 
                                            <div class="my-3">
                                                <label for="jobpoolname">Job Pool Name (required)</label>
                                                <input type="text" id="jobpoolname" name="jobpoolname" maxlength="50" class="light-input" required>
                                            </div>
                                            <label for="jobpoolfiletype">Job Pool File Type</label><br>
                                            <div class="cust-check">
                                                <div class="custom-radio">
                                                    <input type="radio" id="xml" name="jobpoolfiletype" value="xml" required>
                                                    <label for="xml">
                                                        <i class="far fa-circle"></i>
                                                        <i class="fas fa-check-circle"></i>
                                                        XML
                                                    </label>
                                                </div>
                                                <div class="custom-radio">
                                                    <input type="radio" id="csv" name="jobpoolfiletype" value="csv" required>
                                                    <label for="csv">
                                                        <i class="far fa-circle"></i>
                                                        <i class="fas fa-check-circle"></i> CSV
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="mt-3">
                                                <label for="jobpoolurl">Job Pool URL (required)</label>
                                                <input type="text" id="jobpoolurl" name="jobpoolurl" maxlength="200" class="light-input" required>
                                            </div>
                                            <div class="mt-3"> 
                                                <label for="arbitrage">Arbitrage %</label>
                                                <input type="number" id="arbitrage" name="arbitrage" step="0.01" max="100" class="light-input">
                                            </div> 
                                            <button class="btn_green_dark w-100 mt-3">Create Job Pool</button>
                                        </form>
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