<?php
include 'database/db.php';

if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php");
    exit();
}

$feedid = $_GET['feedid'] ?? $_POST['feedid'] ?? '';

function extractIncludeExclude($query)
{
    $allItems = explode(', ', $query ?? '');
    $includeItems = [];
    $excludeItems = [];
 
    foreach ($allItems as $item) {
        if (strpos(trim($item), 'NOT ') === 0) {
            $excludeItems[] = trim(substr($item, 4));
        } else {
            $includeItems[] = trim($item);
        }
    }

    return [$includeItems, $excludeItems];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $feedname = filter_input(INPUT_POST, 'feedname', FILTER_SANITIZE_STRING);
    $feedbudget = filter_input(INPUT_POST, 'feedbudget', FILTER_SANITIZE_STRING);
    $feedcpc = filter_input(INPUT_POST, 'feedcpc', FILTER_SANITIZE_STRING);
    $cpa_amount = filter_input(INPUT_POST, 'cpa_amount', FILTER_SANITIZE_STRING);
    $dailybudget = filter_input(INPUT_POST, 'dailybudget', FILTER_SANITIZE_STRING);

    if (empty($feedbudget)) {
        setToastMessage('error', "Please provide a valid budget.");
    }

    // Handle the optional feedcpc and cpa fields
    $feedcpc = $feedcpc === '' ? null : $feedcpc;
    $cpa_amount = $cpa_amount === '' ? null : $cpa_amount;

    $keywords_include = filter_input(INPUT_POST, 'keywords_include', FILTER_SANITIZE_STRING);
    $keywords_exclude = filter_input(INPUT_POST, 'keywords_exclude', FILTER_SANITIZE_STRING);

    $keywords_include_array = array_filter(array_map('trim', explode(',', $keywords_include)), 'strlen');
    $keywords_exclude_array = array_filter(array_map('trim', explode(',', $keywords_exclude)), 'strlen');

    if (!empty($keywords_exclude_array)) {
        $keywords_exclude_array = array_map(function ($kw) {
            return "NOT " . $kw;
        }, $keywords_exclude_array);
    }

    $custquerykws = implode(', ', array_merge($keywords_include_array, $keywords_exclude_array));

    $company_include = filter_input(INPUT_POST, 'company_include', FILTER_SANITIZE_STRING);
    $company_exclude = filter_input(INPUT_POST, 'company_exclude', FILTER_SANITIZE_STRING);

    $company_include_array = array_filter(array_map('trim', explode(',', $company_include)), 'strlen');
    $company_exclude_array = array_filter(array_map('trim', explode(',', $company_exclude)), 'strlen');

    if (!empty($company_exclude_array)) {
        $company_exclude_array = array_map(function ($co) {
            return "NOT " . $co;
        }, $company_exclude_array);
    }

    $custqueryco = implode(', ', array_merge($company_include_array, $company_exclude_array));

    $industry_include = filter_input(INPUT_POST, 'industry_include', FILTER_SANITIZE_STRING);
    $industry_exclude = filter_input(INPUT_POST, 'industry_exclude', FILTER_SANITIZE_STRING);

    $industry_include_array = array_filter(array_map('trim', explode(',', $industry_include)), 'strlen');
    $industry_exclude_array = array_filter(array_map('trim', explode(',', $industry_exclude)), 'strlen');

    if (!empty($industry_exclude_array)) {
        $industry_exclude_array = array_map(function ($item) {
            return "NOT " . $item;
        }, $industry_exclude_array);
    }

    $custqueryindustry = implode(', ', array_merge($industry_include_array, $industry_exclude_array));

    $city_include = filter_input(INPUT_POST, 'city_include', FILTER_SANITIZE_STRING);
    $city_exclude = filter_input(INPUT_POST, 'city_exclude', FILTER_SANITIZE_STRING);

    $city_include_array = array_filter(array_map('trim', explode(',', $city_include)), 'strlen');
    $city_exclude_array = array_filter(array_map('trim', explode(',', $city_exclude)), 'strlen');

    if (!empty($city_exclude_array)) {
        $city_exclude_array = array_map(function ($item) {
            return "NOT " . $item;
        }, $city_exclude_array);
    }

    $custquerycity = implode(', ', array_merge($city_include_array, $city_exclude_array));

    $state_include = filter_input(INPUT_POST, 'state_include', FILTER_SANITIZE_STRING);
    $state_exclude = filter_input(INPUT_POST, 'state_exclude', FILTER_SANITIZE_STRING);

    $state_include_array = array_filter(array_map('trim', explode(',', $state_include)), 'strlen');
    $state_exclude_array = array_filter(array_map('trim', explode(',', $state_exclude)), 'strlen');

    if (!empty($state_exclude_array)) {
        $state_exclude_array = array_map(function ($item) {
            return "NOT " . $item;
        }, $state_exclude_array);
    }

    $custquerystate = implode(', ', array_merge($state_include_array, $state_exclude_array));

    $customFields = [];
    for ($i = 1; $i <= 5; $i++) {
        $customInclude = filter_input(INPUT_POST, "custom_field_{$i}_include", FILTER_SANITIZE_STRING);
        $customExclude = filter_input(INPUT_POST, "custom_field_{$i}_exclude", FILTER_SANITIZE_STRING);

        $customIncludeArray = array_filter(array_map('trim', explode(',', $customInclude)), 'strlen');
        $customExcludeArray = array_filter(array_map('trim', explode(',', $customExclude)), 'strlen');

        if (!empty($customExcludeArray)) {
            $customExcludeArray = array_map(function ($item) {
                return "NOT " . $item;
            }, $customExcludeArray);
        }

        $customFields[$i] = implode(', ', array_merge($customIncludeArray, $customExcludeArray));
    }

    try {
        $stmt = $pdo->prepare("UPDATE applcustfeeds SET feedname = ?, budget = ?, budgetdaily = ?, cpc = ?, cpa = ?, custquerykws = ?, custqueryco = ?, custqueryindustry = ?, custquerycity = ?, custquerystate = ?, custquerycustom1 = ?, custquerycustom2 = ?, custquerycustom3 = ?, custquerycustom4 = ?, custquerycustom5 = ? WHERE feedid = ? AND custid = ?");
        $stmt->execute([
            $feedname,
            $feedbudget,
            $dailybudget,
            $feedcpc,
            $cpa_amount,
            $custquerykws,
            $custqueryco,
            $custqueryindustry,
            $custquerycity,
            $custquerystate,
            $customFields[1],
            $customFields[2],
            $customFields[3],
            $customFields[4],
            $customFields[5],
            $feedid,
            $_SESSION['custid']
        ]);
        header("Location: applportal.php");
        exit();
    } catch (PDOException $e) {
        setToastMessage('error', "Database error: " . $e->getMessage());
        header("Location: applmasterview.php");
        exit;
    }
} else {
    $feed = null;
    if ($feedid) {
        $stmt = $pdo->prepare("SELECT * FROM applcustfeeds WHERE feedid = ? AND custid = ?");
        $stmt->execute([$feedid, $_SESSION['custid']]);
        $feed = $stmt->fetch();
    }

    if (!$feed) {
        setToastMessage('error', "No feed found with the provided ID.");
    }

    $allKeywords = explode(', ', $feed['custquerykws'] ?? '');
    $includeKeywords = [];
    $excludeKeywords = [];

    foreach ($allKeywords as $keyword) {
        if (strpos(trim($keyword), 'NOT ') === 0) {
            $excludeKeywords[] = trim(substr($keyword, 4)); // Remove "NOT " prefix
        } else {
            $includeKeywords[] = trim($keyword);
        }
    }

    $allCompanies = explode(', ', $feed['custqueryco'] ?? '');
    $includeCompanies = [];
    $excludeCompanies = [];

    foreach ($allCompanies as $company) {
        if (strpos(trim($company), 'NOT ') === 0) {
            $excludeCompanies[] = trim(substr($company, 4)); // Remove "NOT " prefix
        } else {
            $includeCompanies[] = trim($company);
        }
    }


    // Repeat this pattern for industry, city, state and custom fields
    list($includeIndustry, $excludeIndustry) = extractIncludeExclude($feed['custqueryindustry']);
    list($includeCity, $excludeCity) = extractIncludeExclude($feed['custquerycity']);
    list($includeState, $excludeState) = extractIncludeExclude($feed['custquerystate']);
    $customFields = [];
    for ($i = 1; $i <= 5; $i++) {
        list($includeCustom, $excludeCustom) = extractIncludeExclude($feed["custquerycustom{$i}"]);
        $customFields[$i] = ['include' => $includeCustom, 'exclude' => $excludeCustom];
    }
}

// HTML and form continue here...
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Edit Campaign | Appljack</title>
    <?php include 'header.php'; ?>
</head>

<body>
    <?php include 'appltopnav.php'; ?>
    <a href="applportal.php"><-- Back to your portal</a>
            <h1>Edit Campaign (<?php echo htmlspecialchars($feed['feedname'] ?? ''); ?>) - Status: <?php echo htmlspecialchars($feed['status'] ?? ''); ?></h1>
            <?php
            // Assuming $feed is the array of data fetched from the database for the current feed
            $defaultIncludeCompanies = implode(', ', $includeCompanies);
            $defaultExcludeCompanies = implode(', ', $excludeCompanies);

            // Check for user selections stored in session
            $sessionIncludeCompanies = isset($_SESSION['includedCompanies']) ? implode(', ', $_SESSION['includedCompanies']) : null;
            $sessionExcludeCompanies = isset($_SESSION['excludedCompanies']) ? implode(', ', $_SESSION['excludedCompanies']) : null;

            // Determine which values to display in the form fields
            $displayIncludeCompanies = $sessionIncludeCompanies ?: $defaultIncludeCompanies;
            $displayExcludeCompanies = $sessionExcludeCompanies ?: $defaultExcludeCompanies;

            // Clear session variables to ensure they don't override database defaults on subsequent visits
            unset($_SESSION['includedCompanies'], $_SESSION['excludedCompanies']);
            ?>

            <form action="changecampaignstatus.php" method="post" class="stopcampaigncontainer">
                <input type="hidden" name="feedid" value="<?php echo htmlspecialchars($feedid); ?>">
                <?php if (isset($feed['status']) && $feed['status'] === 'active'): ?>
                    <button type="submit" name="action" value="stop" style="background-color: red; color: white;">Stop Campaign</button>
                <?php else: ?>
                    <button type="submit" name="action" value="start" style="background-color: green; color: white;">Start Campaign</button>
                <?php endif; ?>
            </form>


            <div class="campaign-container">
                <form action="editfeed.php" method="post">
                    <input type="hidden" name="feedid" value="<?php echo htmlspecialchars($feedid); ?>">

                    <div class="campaign-edit-row">
                        <div class="campaign-edit-column">
                            <h2>Title & Budget</h2>
                            <label for="feedname">Feed Name (required)</label>
                            <input type="text" id="feedname" name="feedname" required value="<?php echo htmlspecialchars($feed['feedname'] ?? ''); ?>">
                            <label for="feedbudget">Monthly Budget (required)</label>
                            <input type="text" id="feedbudget" name="feedbudget" value="<?php echo htmlspecialchars($feed['budget'] ?? ''); ?>">
                            <label for="budgetdaily">Daily Budget</label>
                            <input type="text" id="budgetdaily" name="budgetdaily" value="<?php echo htmlspecialchars($feed['budgetdaily'] ?? ''); ?>">
                            <label for="feedcpc">CPC Amount (Override or Manually Set the CPC)</label>
                            <input type="text" id="feedcpc" name="feedcpc" value="<?php echo htmlspecialchars($feed['cpc'] ?? ''); ?>">
                            <label for="cpa_amount">CPA Amount</label>
                            <input type="text" id="cpa_amount" name="cpa_amount" value="<?php echo htmlspecialchars($feed['cpa'] ?? ''); ?>">
                        </div>


                        <div class="campaign-edit-column">
                            <h2>Keywords</h2>
                            <label for="keywords_include">Keywords Include</label>
                            <input type="text" id="keywords_include" name="keywords_include" value="<?php echo htmlspecialchars(implode(', ', $includeKeywords)); ?>">
                            <label for="keywords_exclude">Keywords Exclude</label>
                            <input type="text" id="keywords_exclude" name="keywords_exclude" value="<?php echo htmlspecialchars(implode(', ', $excludeKeywords)); ?>">
                        </div>

                        <div class="campaign-edit-column">
                            <h2>Industry</h2>
                            <label for="industry_include">Industry Include</label> <a href="/editfeedindustry.php?feedid=<?php echo htmlspecialchars($feedid); ?>">View all industries</a>
                            <input type="text" id="industry_include" name="industry_include" value="<?php echo htmlspecialchars(implode(', ', $includeIndustry)); ?>">
                            <label for="industry_exclude">Industry Exclude</label> <a href="/editfeedindustry.php?feedid=<?php echo htmlspecialchars($feedid); ?>">View all industries</a>
                            <input type="text" id="industry_exclude" name="industry_exclude" value="<?php echo htmlspecialchars(implode(', ', $excludeIndustry)); ?>">
                        </div>
                    </div>

                    <div class="campaign-edit-row">
                        <div class="campaign-edit-column">
                            <h2>Geography</h2>
                            <label for="city_include">City Include</label>
                            <input type="text" id="city_include" name="city_include" value="<?php echo htmlspecialchars(implode(', ', $includeCity)); ?>">
                            <label for="city_exclude">City Exclude</label>
                            <input type="text" id="city_exclude" name="city_exclude" value="<?php echo htmlspecialchars(implode(', ', $excludeCity)); ?>">
                            <label for="state_include">State Include</label>
                            <input type="text" id="state_include" name="state_include" value="<?php echo htmlspecialchars(implode(', ', $includeState)); ?>">
                            <label for="state_exclude">State Exclude</label>
                            <input type="text" id="state_exclude" name="state_exclude" value="<?php echo htmlspecialchars(implode(', ', $excludeState)); ?>">
                        </div>

                        <div class="campaign-edit-column">
                            <h2>Company</h2>
                            <label for="company_include">Company Include</label> <a href="/editfeedcust.php?feedid=<?php echo htmlspecialchars($feedid); ?>">View all companies</a>
                            <input type="text" id="company_include" name="company_include" value="<?php echo htmlspecialchars($displayIncludeCompanies); ?>">
                            <label for="company_exclude">Company Exclude</label> <a href="/editfeedcust.php?feedid=<?php echo htmlspecialchars($feedid); ?>">View all companies</a>
                            <input type="text" id="company_exclude" name="company_exclude" value="<?php echo htmlspecialchars($displayExcludeCompanies); ?>">
                        </div>

                        <div class="campaign-edit-column">
                            <h2>Custom Fields</h2>
                            <!-- Repeat pattern for each custom field -->
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <label for="custom_field_<?= $i ?>_include">Custom Field <?= $i ?> Include</label>
                                <input type="text" id="custom_field_<?= $i ?>_include" name="custom_field_<?= $i ?>_include">
                                <label for="custom_field_<?= $i ?>_exclude">Custom Field <?= $i ?> Exclude</label>
                                <input type="text" id="custom_field_<?= $i ?>_exclude" name="custom_field_<?= $i ?>_exclude">
                            <?php endfor; ?>
                        </div>
                    </div>

                    <input type="submit" value="Update Campaign">
                </form>
            </div>
            <?php include 'footer.php'; ?>
</body>

</html>