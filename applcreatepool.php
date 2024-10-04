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

    <h1 class="main-heading">Create a New Job Pool</h1>
    <div class="content-container">
        <div class="form-container">
            <form action="applputpool.php" method="post">
                <input type="hidden" name="jobpoolid" value="<?php echo $jobpoolid; ?>">
                <input type="hidden" name="acctnum" value="<?php echo $_SESSION['acctnum']; ?>">
 
                <label for="jobpoolname">Job Pool Name (required)</label>
                <input type="text" id="jobpoolname" name="jobpoolname" maxlength="50" required><br>

                <label for="jobpoolfiletype">Job Pool File Type</label><br>
                <input type="radio" id="xml" name="jobpoolfiletype" value="xml" required>
                <label for="xml">XML</label><br>
                <input type="radio" id="csv" name="jobpoolfiletype" value="csv" required>
                <label for="csv">CSV</label><br>

                <label for="jobpoolurl">Job Pool URL (required)</label>
                <input type="text" id="jobpoolurl" name="jobpoolurl" maxlength="200" required><br>

                <label for="arbitrage">Arbitrage %</label>
                <input type="number" id="arbitrage" name="arbitrage" step="0.01" max="100"><br>

                <input type="submit" value="Create Job Pool">
            </form>
        </div>
        <!-- Additional hints or information can be added here if necessary -->
    </div>

    <?php include 'footer.php'; ?>
</body>

</html>
