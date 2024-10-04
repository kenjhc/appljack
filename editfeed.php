<?php
include 'database/db.php';

if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php");
    exit();
}

$feedid = $_GET['feedid'] ?? $_POST['feedid'] ?? '';

// Fetch the activepubs field value from the database for the current feed
$activePubs = [];
if ($feedid) {
    $stmt = $pdo->prepare("SELECT activepubs FROM applcustfeeds WHERE feedid = ? AND custid = ?");
    $stmt->execute([$feedid, $_SESSION['custid']]);
    $result = $stmt->fetch();
    if ($result) {
        // Check if activepubs is not null before exploding
        if (!is_null($result['activepubs'])) {
            $activePubs = explode(',', $result['activepubs']);  // Convert activepubs to an array of publisher IDs
        }
    }
}

// Fetch available publishers for the current account
$stmt = $pdo->prepare("SELECT publisherid, publishername FROM applpubs WHERE acctnum = ?");
$stmt->execute([$_SESSION['acctnum']]);
$publishers = $stmt->fetchAll();

// Initialize empty arrays for companies
$includeCompanies = [];
$excludeCompanies = [];
$currentActivePubs = explode(',', $feed['activepubs'] ?? '');

// Function to handle include/exclude logic
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

// Flag to display the "Update Successful" message
$updateSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle feed update fields
    $feedname = filter_input(INPUT_POST, 'feedname', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $feedbudget = filter_input(INPUT_POST, 'feedbudget', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $feedcpc = filter_input(INPUT_POST, 'feedcpc', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $cpa_amount = filter_input(INPUT_POST, 'cpa_amount', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $dailybudget = filter_input(INPUT_POST, 'dailybudget', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    if (empty($feedbudget)) {
        setToastMessage('error', "Please provide a valid budget.");
    }

    $feedcpc = $feedcpc === '' ? null : $feedcpc;
    $cpa_amount = $cpa_amount === '' ? null : $cpa_amount;

    // Handle keywords
    $keywords_include = filter_input(INPUT_POST, 'keywords_include', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $keywords_exclude = filter_input(INPUT_POST, 'keywords_exclude', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    $keywords_include_array = array_filter(array_map('trim', explode(',', $keywords_include)), 'strlen');
    $keywords_exclude_array = array_filter(array_map('trim', explode(',', $keywords_exclude)), 'strlen');

    if (!empty($keywords_exclude_array)) {
        $keywords_exclude_array = array_map(function ($kw) {
            return "NOT " . $kw;
        }, $keywords_exclude_array);
    }

    $custquerykws = implode(', ', array_merge($keywords_include_array, $keywords_exclude_array));

    // Handle companies
    $company_include = filter_input(INPUT_POST, 'company_include', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $company_exclude = filter_input(INPUT_POST, 'company_exclude', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    $company_include_array = array_filter(array_map('trim', explode(',', $company_include)), 'strlen');
    $company_exclude_array = array_filter(array_map('trim', explode(',', $company_exclude)), 'strlen');

    if (!empty($company_exclude_array)) {
        $company_exclude_array = array_map(function ($co) {
            return "NOT " . $co;
        }, $company_exclude_array);
    }

    $custqueryco = implode(', ', array_merge($company_include_array, $company_exclude_array));

    // Handle industries
    $industry_include = filter_input(INPUT_POST, 'industry_include', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $industry_exclude = filter_input(INPUT_POST, 'industry_exclude', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    $industry_include_array = array_filter(array_map('trim', explode(',', $industry_include)), 'strlen');
    $industry_exclude_array = array_filter(array_map('trim', explode(',', $industry_exclude)), 'strlen');

    if (!empty($industry_exclude_array)) {
        $industry_exclude_array = array_map(function ($item) {
            return "NOT " . $item;
        }, $industry_exclude_array);
    }

    $custqueryindustry = implode(', ', array_merge($industry_include_array, $industry_exclude_array));

    // Handle cities
    $city_include = filter_input(INPUT_POST, 'city_include', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $city_exclude = filter_input(INPUT_POST, 'city_exclude', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    $city_include_array = array_filter(array_map('trim', explode(',', $city_include)), 'strlen');
    $city_exclude_array = array_filter(array_map('trim', explode(',', $city_exclude)), 'strlen');

    if (!empty($city_exclude_array)) {
        $city_exclude_array = array_map(function ($item) {
            return "NOT " . $item;
        }, $city_exclude_array);
    }

    $custquerycity = implode(', ', array_merge($city_include_array, $city_exclude_array));

    // Handle states
    $state_include = filter_input(INPUT_POST, 'state_include', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $state_exclude = filter_input(INPUT_POST, 'state_exclude', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    $state_include_array = array_filter(array_map('trim', explode(',', $state_include)), 'strlen');
    $state_exclude_array = array_filter(array_map('trim', explode(',', $state_exclude)), 'strlen');

    if (!empty($state_exclude_array)) {
        $state_exclude_array = array_map(function ($item) {
            return "NOT " . $item;
        }, $state_exclude_array);
    }

    $custquerystate = implode(', ', array_merge($state_include_array, $state_exclude_array));

    // Handle custom fields
    $customFields = [];
    for ($i = 1; $i <= 5; $i++) {
        $customInclude = filter_input(INPUT_POST, "custom_field_{$i}_include", FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $customExclude = filter_input(INPUT_POST, "custom_field_{$i}_exclude", FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        $customIncludeArray = array_filter(array_map('trim', explode(',', $customInclude)), 'strlen');
        $customExcludeArray = array_filter(array_map('trim', explode(',', $customExclude)), 'strlen');

        if (!empty($customExcludeArray)) {
            $customExcludeArray = array_map(function ($item) {
                return "NOT " . $item;
            }, $customExcludeArray);
        }

        $customFields[$i] = implode(', ', array_merge($customIncludeArray, $customExcludeArray));
    }

    // Handle publisher actions
    $publisherActions = $_POST['publisher_action'] ?? [];
    $currentActivePubs = explode(',', $feed['activepubs'] ?? '');
    $updatedActivePubs = $currentActivePubs;

    foreach ($publisherActions as $publisherId => $action) {
        $publisherId = trim($publisherId);
        if ($action === 'add' && !in_array($publisherId, $updatedActivePubs)) {
            $updatedActivePubs[] = $publisherId;
        } elseif ($action === 'remove') {
            $updatedActivePubs = array_diff($updatedActivePubs, [$publisherId]);
        }
    }

    $updatedActivePubs = array_filter($updatedActivePubs, 'strlen');
    $updatedActivePubsStr = implode(',', $updatedActivePubs);

    // Update the applcustfeeds table with the new data
    try {
        $stmt = $pdo->prepare("UPDATE applcustfeeds
                               SET feedname = ?, budget = ?, budgetdaily = ?, cpc = ?, cpa = ?,
                                   custquerykws = ?, custqueryco = ?, custqueryindustry = ?,
                                   custquerycity = ?, custquerystate = ?, custquerycustom1 = ?,
                                   custquerycustom2 = ?, custquerycustom3 = ?, custquerycustom4 = ?,
                                   custquerycustom5 = ?, activepubs = ?
                               WHERE feedid = ? AND custid = ?");
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
            $updatedActivePubsStr,
            $feedid,
            $_SESSION['custid']
        ]);

        // Redirect back to the page with the feedid
        setToastMessage('success', "Updated Successfully.");
        header("Location: editfeed.php?feedid=" . urlencode($feedid));
        exit();
    } catch (PDOException $e) {
        setToastMessage('error', "Database error: " . $e->getMessage());
        exit();
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

    // Initialize empty arrays if no data is found
    $includeCompanies = [];
    $excludeCompanies = [];

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
    // Check if the keys exist in the $feed array and are not null before calling extractIncludeExclude
    $includeIndustry = $excludeIndustry = $includeCity = $excludeCity = $includeState = $excludeState = [];

    // Handle Industry
    if (isset($feed['custqueryindustry']) && !empty($feed['custqueryindustry'])) {
        list($includeIndustry, $excludeIndustry) = extractIncludeExclude($feed['custqueryindustry']);
    }

    // Handle City
    if (isset($feed['custquerycity']) && !empty($feed['custquerycity'])) {
        list($includeCity, $excludeCity) = extractIncludeExclude($feed['custquerycity']);
    }

    // Handle State
    if (isset($feed['custquerystate']) && !empty($feed['custquerystate'])) {
        list($includeState, $excludeState) = extractIncludeExclude($feed['custquerystate']);
    }

    $customFields = [];

    for ($i = 1; $i <= 5; $i++) {
        // Check if the custom field exists in the $feed array and is not empty
        $customFieldKey = "custquerycustom{$i}";

        if (isset($feed[$customFieldKey]) && !empty($feed[$customFieldKey])) {
            // If the field exists and is not empty, process it with extractIncludeExclude
            list($includeCustom, $excludeCustom) = extractIncludeExclude($feed[$customFieldKey]);
            $customFields[$i] = ['include' => $includeCustom, 'exclude' => $excludeCustom];
        } else {
            // Set default empty values if the field is missing or empty
            $customFields[$i] = ['include' => [], 'exclude' => []];
        }
    }
}
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
    <?php echo renderHeader(
        "Edit Campaign (" . htmlspecialchars($feed['feedname'] ?? '') . ") - Status: " . htmlspecialchars($feed['status'] ?? ''),
        "<a href='applportal.php'>
            <p class='mb-0 fs-6 text-white'>< Back to your portal</p>
        </a>"
    ); ?>
    <section class="job_section">
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

        <div class="container">
            <!-- Stop/Start Campaign Section -->
            <div class="row mb-4">
                <div class="col-12 d-flex justify-content-center">
                    <form action="changecampaignstatus.php" method="post" class="text-center">
                        <input type="hidden" name="feedid" value="<?= htmlspecialchars($feedid); ?>">
                        <?php if (isset($feed['status']) && $feed['status'] === 'active'): ?>
                            <button type="submit" name="action" value="stop" class="btn btn-danger">Stop Campaign</button>
                        <?php else: ?>
                            <button type="submit" name="action" value="start" class="btn btn-success">Start Campaign</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Campaign Form -->
            <div class="card p-4">
                <form action="editfeed.php?feedid=<?= htmlspecialchars($feedid); ?>" method="post">
                    <input type="hidden" name="feedid" value="<?= htmlspecialchars($feedid); ?>">

                    <!-- Title & Budget Section -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h4>Title & Budget</h4>
                            <div class="form-group">
                                <label for="feedname">Feed Name (required)</label>
                                <input type="text" id="feedname" name="feedname" class="form-control" required value="<?= htmlspecialchars($feed['feedname'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="feedbudget">Monthly Budget (required)</label>
                                <input type="text" id="feedbudget" name="feedbudget" class="form-control" value="<?= htmlspecialchars($feed['budget'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="budgetdaily">Daily Budget</label>
                                <input type="text" id="budgetdaily" name="budgetdaily" class="form-control" value="<?= htmlspecialchars($feed['budgetdaily'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="feedcpc">CPC Amount</label>
                                <input type="text" id="feedcpc" name="feedcpc" class="form-control" value="<?= htmlspecialchars($feed['cpc'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="cpa_amount">CPA Amount</label>
                                <input type="text" id="cpa_amount" name="cpa_amount" class="form-control" value="<?= htmlspecialchars($feed['cpa'] ?? ''); ?>">
                            </div>
                        </div>

                        <!-- Keywords Section -->
                        <div class="col-md-6">
                            <h4>Keywords</h4>
                            <div class="form-group">
                                <label for="keywords_include">Keywords Include</label>
                                <input type="text" id="keywords_include" name="keywords_include" class="form-control" value="<?= htmlspecialchars(implode(', ', $includeKeywords)); ?>">
                            </div>
                            <div class="form-group">
                                <label for="keywords_exclude">Keywords Exclude</label>
                                <input type="text" id="keywords_exclude" name="keywords_exclude" class="form-control" value="<?= htmlspecialchars(implode(', ', $excludeKeywords)); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Industry Section -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h4>Industry</h4>
                            <div class="form-group">
                                <label for="industry_include">Industry Include (<a target="_blank" href="<?= getUrl(1) ?>editfeedindustry.php?feedid=<?= htmlspecialchars($feedid); ?>">View all industries</a>)</label>
                                <input type="text" id="industry_include" name="industry_include" class="form-control" value="<?= htmlspecialchars(implode(', ', $includeIndustry)); ?>">
                            </div>
                            <div class="form-group">
                                <label for="industry_exclude">Industry Exclude (<a target="_blank" href="<?= getUrl(1) ?>editfeedindustry.php?feedid=<?= htmlspecialchars($feedid); ?>">View all industries</a>)</label>
                                <input type="text" id="industry_exclude" name="industry_exclude" class="form-control" value="<?= htmlspecialchars(implode(', ', $excludeIndustry)); ?>">
                            </div>
                        </div>

                        <!-- Geography Section -->
                        <div class="col-md-6">
                            <h4>Geography</h4>
                            <div class="form-group">
                                <label for="city_include">City Include</label>
                                <input type="text" id="city_include" name="city_include" class="form-control" value="<?= htmlspecialchars(implode(', ', $includeCity)); ?>">
                            </div>
                            <div class="form-group">
                                <label for="city_exclude">City Exclude</label>
                                <input type="text" id="city_exclude" name="city_exclude" class="form-control" value="<?= htmlspecialchars(implode(', ', $excludeCity)); ?>">
                            </div>
                            <div class="form-group">
                                <label for="state_include">State Include</label>
                                <input type="text" id="state_include" name="state_include" class="form-control" value="<?= htmlspecialchars(implode(', ', $includeState)); ?>">
                            </div>
                            <div class="form-group">
                                <label for="state_exclude">State Exclude</label>
                                <input type="text" id="state_exclude" name="state_exclude" class="form-control" value="<?= htmlspecialchars(implode(', ', $excludeState)); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Company Section -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h4>Company</h4>
                            <div class="form-group">
                                <label for="company_include">Company Include (<a target="_blank" href="<?= getUrl(1) ?>editfeedcust.php?feedid=<?= htmlspecialchars($feedid); ?>">View all companies</a>)</label>
                                <input type="text" id="company_include" name="company_include" class="form-control" value="<?= htmlspecialchars($displayIncludeCompanies); ?>">
                            </div>
                            <div class="form-group">
                                <label for="company_exclude">Company Exclude (<a target="_blank" href="<?= getUrl(1) ?>editfeedcust.php?feedid=<?= htmlspecialchars($feedid); ?>">View all companies</a>)</label>
                                <input type="text" id="company_exclude" name="company_exclude" class="form-control" value="<?= htmlspecialchars($displayExcludeCompanies); ?>">
                            </div>
                        </div>

                        <!-- Custom Fields Section -->
                        <div class="col-md-6">
                            <h4>Custom Fields</h4>
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <div class="form-group">
                                    <label for="custom_field_<?= $i ?>_include">Custom Field <?= $i ?> Include</label>
                                    <input type="text" id="custom_field_<?= $i ?>_include" name="custom_field_<?= $i ?>_include" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="custom_field_<?= $i ?>_exclude">Custom Field <?= $i ?> Exclude</label>
                                    <input type="text" id="custom_field_<?= $i ?>_exclude" name="custom_field_<?= $i ?>_exclude" class="form-control">
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <!-- Publishers Section -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <h4>Assign to Publishers</h4>
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>Publisher Name</th>
                                        <th>Publisher ID</th>
                                        <th>Add</th>
                                        <th>Remove</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($publishers as $publisher): ?>
                                        <?php $publisherId = $publisher['publisherid']; ?>
                                        <tr>
                                            <td><?= htmlspecialchars($publisher['publishername']); ?></td>
                                            <td><?= htmlspecialchars($publisherId); ?></td>
                                            <td>
                                                <label>
                                                    <input type="radio" name="publisher_action[<?= $publisherId; ?>]" value="add" <?= in_array($publisherId, $activePubs) ? 'checked' : ''; ?>> Add
                                                </label>
                                            </td>
                                            <td>
                                                <label>
                                                    <input type="radio" name="publisher_action[<?= $publisherId; ?>]" value="remove" <?= !in_array($publisherId, $activePubs) ? 'checked' : ''; ?>> Remove
                                                </label>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="text-center">
                        <button type="submit" class="btn btn-primary">Update Campaign</button>
                    </div>
                </form>
            </div>
        </div>

    </section>
    <?php include 'footer.php'; ?>
</body>

</html>