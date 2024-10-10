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
        $_SESSION['includedIndustries'] = [];
        $_SESSION['excludedIndustries'] = [];
        $feedid = $_POST['feedid'] ?? 'default';  // Ensure there's a fallback or default if not set
        header("Location: " . $_SERVER['PHP_SELF'] . "?feedid=" . urlencode($feedid));
        exit();
    }

    $includedIndustries = [];
    $excludedIndustries = [];

    // Process 'include' selections
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'include_') === 0 && $value == 'on') {
            $industryName = substr($key, 8); // Extracts the industry name part
            $industryName = str_replace('_', ' ', $industryName); // Replace underscores with spaces
            $includedIndustries[] = $industryName;
        } else if (strpos($key, 'exclude_') === 0 && $value == 'on') {
            $industryName = substr($key, 8); // Extracts the industry name part
            $industryName = str_replace('_', ' ', $industryName); // Replace underscores with spaces
            $excludedIndustries[] = $industryName;
        }
    }

    // Store the industry inclusion and exclusion lists in the session
    $_SESSION['includedIndustries'] = $includedIndustries;
    $_SESSION['excludedIndustries'] = $excludedIndustries;

    // Prepare the value for custqueryindustry
    $allIndustries = array_merge($includedIndustries, array_map(function ($industry) {
        return 'NOT ' . $industry;
    }, $excludedIndustries));
    $custqueryindustry = implode(',', $allIndustries);

    // Update the applcustfeeds table with the selected industries
    try {
        $stmt = $pdo->prepare("UPDATE applcustfeeds SET custqueryindustry = :custqueryindustry WHERE feedid = :feedid AND custid = :custid");
        $stmt->execute([
            ':custqueryindustry' => $custqueryindustry,
            ':feedid' => $feedid,
            ':custid' => $_SESSION['custid']
        ]);
    } catch (PDOException $e) {
        setToastMessage('error', "Database error: " . $e->getMessage());
        header("Location: applmasterview.php");
        exit;
    }

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
    header("Location: applmasterview.php");
    exit;
}

// Use jobpoolid to fetch industries
$industries = [];
if ($jobpoolid) {
    try {
        $stmt = $pdo->prepare("SELECT DISTINCT industry FROM appljobs WHERE jobpoolid = ?");
        $stmt->execute([$jobpoolid]);
        $industries = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($industries)) {
            setToastMessage('error', "No industries found for jobpoolid: " . $jobpoolid);
        } else {
            setToastMessage('error', "Industries loaded successfully.");
        }
    } catch (PDOException $e) {
        setToastMessage('error', "Database error: " . $e->getMessage());
    }
}

// Fetch included and excluded industries from applcustfeeds table
$includedIndustries = [];
$excludedIndustries = [];
if ($feedid) {
    try {
        $stmt = $pdo->prepare("SELECT custqueryindustry FROM applcustfeeds WHERE feedid = ? AND custid = ?");
        $stmt->execute([$feedid, $_SESSION['custid']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $industriesList = explode(',', $result['custqueryindustry']);
            foreach ($industriesList as $industry) {
                $industry = trim($industry);
                if (strpos($industry, 'NOT ') === 0) {
                    $excludedIndustries[] = substr($industry, 4); // Remove 'NOT ' prefix
                } else {
                    $includedIndustries[] = $industry;
                }
            }
        }
    } catch (PDOException $e) {
        setToastMessage('error', "Database error: " . $e->getMessage());
        header("Location: applmasterview.php");
        exit;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Edit Industry List | Appljack</title>
    <?php include 'header.php'; ?>
    <script>
        function toggleCheckbox(element, industryName, otherType) {
            // Get the other checkbox by constructing its name and check if it should be unchecked
            var otherCheckbox = document.getElementsByName(otherType + '_' + industryName)[0];
            if (element.checked) {
                otherCheckbox.checked = false;
            }
        }
    </script>
</head>

<body>
    <?php include 'appltopnav.php'; ?>
    <?php echo renderHeader(
        "Edit Industry List"
    ); ?>
    <section class="job_section">
        <h3>Feed: <?php echo htmlspecialchars($feedid); ?></h3>
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="industryForm">
            <table>
                <tr>
                    <th>Industry Name</th>
                    <th>Include</th>
                    <th>Exclude</th>
                </tr>
                <?php foreach ($industries as $industry): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($industry ?? ''); ?></td>
                        <td><input type="checkbox" name="include_<?php echo htmlspecialchars(str_replace(' ', '_', $industry)); ?>" class="include" <?php echo in_array($industry, $includedIndustries) ? 'checked' : ''; ?> onclick="toggleCheckbox(this, '<?php echo htmlspecialchars(str_replace(' ', '_', $industry)); ?>', 'exclude');"></td>
                        <td><input type="checkbox" name="exclude_<?php echo htmlspecialchars(str_replace(' ', '_', $industry)); ?>" class="exclude" <?php echo in_array($industry, $excludedIndustries) ? 'checked' : ''; ?> onclick="toggleCheckbox(this, '<?php echo htmlspecialchars(str_replace(' ', '_', $industry)); ?>', 'include');"></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <br>
            <input type="hidden" name="feedid" value="<?php echo htmlspecialchars($feedid); ?>">
            <input type="submit" value="Submit"> <input type="submit" name="reset" value="Reset All">
        </form>
    </section>
    <?php include 'footer.php'; ?>
</body>

</html>