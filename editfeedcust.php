<?php
include 'database/db.php';

if (!isset($_SESSION['acctnum'], $_SESSION['custid'])) {
    header("Location: appllogin.php");
    exit();
}

$custid = $_SESSION['custid'];
$feedid = $_GET['feedid'] ?? $_SESSION['feedid'] ?? '';
$_SESSION['feedid'] = $feedid;

echo "<p style='display:none;'>".$custid."</p>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reset'])) {
        $_SESSION['includedCompanies'][$feedid] = [];
        $_SESSION['excludedCompanies'][$feedid] = [];

        // Clear from DB
        try {
            $stmt = $pdo->prepare("UPDATE applcustfeeds SET custqueryco = '' WHERE feedid = :feedid AND custid = :custid");
            $stmt->execute([
                ':feedid' => $feedid,
                ':custid' => $custid
            ]);
        } catch (PDOException $e) {
            setToastMessage('error', "Database error: " . $e->getMessage());
        }

        header("Location: " . $_SERVER['PHP_SELF'] . "?feedid=" . urlencode($feedid));
        exit();
    }

    // Save new selections from form
    $includedCompanies = $_POST['include'] ?? [];
    $excludedCompanies = $_POST['exclude'] ?? [];

    $_SESSION['includedCompanies'][$feedid] = $includedCompanies;
    $_SESSION['excludedCompanies'][$feedid] = $excludedCompanies;

    // Build the string for DB like: "CompanyA,CompanyB,NOT CompanyC"
    $allCompanies = array_merge($includedCompanies, array_map(fn($c) => 'NOT ' . $c, $excludedCompanies));
    $custqueryco = implode(',', $allCompanies);

    try {
        $stmt = $pdo->prepare("UPDATE applcustfeeds SET custqueryco = :custqueryco WHERE feedid = :feedid AND custid = :custid");
        $stmt->execute([
            ':custqueryco' => $custqueryco,
            ':feedid' => $feedid,
            ':custid' => $custid
        ]);
    } catch (PDOException $e) {
        setToastMessage('error', "Database error: " . $e->getMessage());
    }

    header("Location: editfeed.php?feedid=" . urlencode($feedid) . "&custid=" . urlencode($custid));
    exit();
}

// Fetch companies
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT appljobs.company
        FROM applcust
        LEFT JOIN appljobs ON appljobs.jobpoolid = applcust.jobpoolid
        WHERE applcust.custid = :custid
    ");
    $stmt->execute([':custid' => $custid]);
    $companies = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'company');
} catch (PDOException $e) {
    setToastMessage('error', "Database error: " . $e->getMessage());
    $companies = [];
}

// Load already selected companies from DB (if any)
$included = [];
$excluded = [];
try {
    $stmt = $pdo->prepare("SELECT custqueryco FROM applcustfeeds WHERE feedid = ? AND custid = ?");
    $stmt->execute([$feedid, $custid]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result && !empty($result['custqueryco'])) {
        $companyList = explode(',', $result['custqueryco']);
        foreach ($companyList as $company) {
            $company = trim($company);
            if (stripos($company, 'NOT ') === 0) {
                $excluded[] = substr($company, 4);
            } else {
                $included[] = $company;
            }
        }
    }
} catch (PDOException $e) {
    setToastMessage('error', "Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Company List | Appljack</title>
    <?php include 'header.php'; ?>
    <script>
        function toggleCheckbox(element, companyName, otherType) {
            const other = document.querySelector(`[name="${otherType}[]"][value="${companyName}"]`);
            if (element.checked && other) {
                other.checked = false;
            }
        }
    </script>
</head>
<body>
<?php include 'appltopnav.php'; ?>
<?php echo renderHeader("Edit Customer List"); ?>

<section class="job_section">
    <h3>Feed: <?php echo htmlspecialchars($feedid); ?></h3>

    <form method="post" action="editfeedcust.php?feedid=<?php echo urlencode($feedid); ?>">
        <table>
            <tr>
                <th>Company</th>
                <th>Include</th>
                <th>Exclude</th>
            </tr>
            <?php foreach ($companies as $company): 
                $companyEscaped = htmlspecialchars($company);
                $isIncluded = in_array($company, $included);
                $isExcluded = in_array($company, $excluded);
            ?>
                <tr>
                    <td><?php echo $companyEscaped; ?></td>
                    <td>
                        <input type="checkbox" name="include[]" value="<?php echo $companyEscaped; ?>"
                               <?php echo $isIncluded ? 'checked' : ''; ?>
                               onclick="toggleCheckbox(this, '<?php echo $companyEscaped; ?>', 'exclude')">
                    </td>
                    <td>
                        <input type="checkbox" name="exclude[]" value="<?php echo $companyEscaped; ?>"
                               <?php echo $isExcluded ? 'checked' : ''; ?>
                               onclick="toggleCheckbox(this, '<?php echo $companyEscaped; ?>', 'include')">
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <input type="hidden" name="feedid" value="<?php echo htmlspecialchars($feedid); ?>">
        <br>
        <input type="submit" value="Submit">
        <input type="submit" name="reset" value="Reset All">
    </form>
</section>

<?php include 'footer.php'; ?>
</body>
</html>
