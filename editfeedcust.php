<?php
include 'database/db.php';
 
if (!isset($_SESSION['acctnum']) || !isset($_SESSION['custid'])) {
    header("Location: appllogin.php");
    exit();
}


$feedid = $_GET['feedid'] ?? $_SESSION['feedid'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reset'])) {
        // Reset session variables related to selections
        $_SESSION['includedCompanies'] = [];
        $_SESSION['excludedCompanies'] = [];
        $feedid = $_POST['feedid'] ?? 'default';  // Ensure there's a fallback or default if not set
        header("Location: " . $_SERVER['PHP_SELF'] . "?feedid=" . urlencode($feedid));
        exit();
    }

    setToastMessage('error', "Submitted feedid: " . htmlspecialchars($_POST['feedid'] ?? 'Not set'));

    $includedCompanies = [];
    $excludedCompanies = [];

    // Process 'include' selections
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'include_') === 0 && $value == 'on') {
            $companyName = substr($key, 8); // Extracts the company name part
            $companyName = str_replace('_', ' ', $companyName); // Replace underscores with spaces
            $includedCompanies[] = $companyName;
        } else if (strpos($key, 'exclude_') === 0 && $value == 'on') {
            $companyName = substr($key, 8); // Extracts the company name part
            $companyName = str_replace('_', ' ', $companyName); // Replace underscores with spaces
            $excludedCompanies[] = $companyName;
        }
    }

    // Store the company inclusion and exclusion lists in the session
    $_SESSION['includedCompanies'] = $includedCompanies;
    $_SESSION['excludedCompanies'] = $excludedCompanies;

    // Redirect back to the edit feed page with the current feed ID
    $feedid = $_POST['feedid'] ?? 'FallbackID';
    header("Location: editfeed.php?feedid=$feedid");
    exit();
}


// Fetch jobpoolid using custid
$jobpoolid = null;
try {
    $stmt = $pdo->prepare("SELECT jobpoolid FROM applcust WHERE custid = :custid");
    $stmt->execute([':custid' => $_SESSION['custid']]);
    $jobpoolResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $jobpoolid = $jobpoolResult['jobpoolid'] ?? null;

    if (!$jobpoolid) {
        setToastMessage('warning', "No jobpoolid found for the given custid.");
        header("Location: applmasterview.php");

        exit();  // or handle differently depending on your application flow
    }
} catch (PDOException $e) {
    setToastMessage('error', "Database error: " . $e->getMessage());
}

// Use jobpoolid to fetch companies
if ($jobpoolid) {
    try {
        $stmt = $pdo->prepare("SELECT DISTINCT company FROM appljobs WHERE jobpoolid = ?");
        $stmt->execute([$jobpoolid]);
        $companies = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($companies)) {
            setToastMessage('error', "No companies found for jobpoolid: " . $jobpoolid);
        } else {
            setToastMessage('error', "Companies loaded successfully.");
        }
    } catch (PDOException $e) { 
        setToastMessage('error', "Database error: " . $e->getMessage());
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Edit Customer List | Appljack</title>
    <?php include 'header.php'; ?>
    <script>
        function toggleCheckbox(element, companyName, otherType) {
            // Get the other checkbox by constructing its name and check if it should be unchecked
            var otherCheckbox = document.getElementsByName(otherType + '_' + companyName)[0];
            if (element.checked) {
                otherCheckbox.checked = false;
            }
        }
    </script>


</head>

<body>
    <?php include 'appltopnav.php'; ?>
    <h1>Edit Customer List</h1>
    <?php echo htmlspecialchars($feedid); ?>
    <form action="editfeedcust.php" method="post" id="customerForm">
        <table>
            <tr>
                <th>Customer Name</th>
                <th>Include</th>
                <th>Exclude</th>
            </tr>
            <?php foreach ($companies as $company): ?>
                <tr>
                    <td><?php echo htmlspecialchars($company); ?></td>
                    <td><input type="checkbox" name="include_<?php echo htmlspecialchars(str_replace(' ', '_', $company)); ?>" class="include" onclick="toggleCheckbox(this, '<?php echo htmlspecialchars(str_replace(' ', '_', $company)); ?>', 'exclude');"></td>
                    <td><input type="checkbox" name="exclude_<?php echo htmlspecialchars(str_replace(' ', '_', $company)); ?>" class="exclude" onclick="toggleCheckbox(this, '<?php echo htmlspecialchars(str_replace(' ', '_', $company)); ?>', 'include');"></td>
                </tr>

            <?php endforeach; ?>
        </table>
        <br>
        <input type="hidden" name="feedid" value="<?php echo htmlspecialchars($feedid); ?>">
        <input type="submit" value="Submit"> <input type="submit" name="reset" value="Reset All">
    </form>

    <?php include 'footer.php'; ?>
</body>

</html>