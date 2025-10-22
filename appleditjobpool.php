<?php
ini_set('memory_limit', '512M'); 
error_reporting(0);
ini_set('display_errors', 0);
include 'database/db.php'; 

if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php");
    exit();
}

$error = ''; // Initialize $error to avoid undefined variable notices
$jobpoolid = $_GET['jobpoolid'] ?? ''; // Ensure $jobpoolid is initialized before use

$acctnum = $_SESSION['acctnum']; // Retrieve account number from session
$filepath = "/chroot/home/appljack/appljack.com/html$envSuffix/feedsclean/{$acctnum}-{$jobpoolid}.xml"; // Construct the file path

// Function to sanitize and reformat the field name for XML
function sanitizeFieldName($fieldName)
{
    // Remove invalid characters
    $fieldName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $fieldName);

    // Ensure it starts with a letter or underscore
    if (!preg_match('/^[a-zA-Z_]/', $fieldName)) {
        $fieldName = '_' . $fieldName;
    }

    return $fieldName;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['xml_tag'], $_POST['db_column'])) {
        $xml_tag = $_POST['xml_tag'];
        $db_column = $_POST['db_column'];

        if ($db_column === '') {
            // Remove the mapping from the database
            $stmt = $conn->prepare("DELETE FROM appldbmapping WHERE jobpoolid = ? AND xml_tag = ?");
            if (!$stmt->execute([$jobpoolid, $xml_tag])) {
                error_log("Database delete error: " . implode(", ", $stmt->errorInfo()));
            }
            $error = $stmt->rowCount() ? 'Mapping removed successfully.' : 'Failed to remove mapping.';
        } else {
            // Update the mapping in the database
            $stmt = $conn->prepare("REPLACE INTO appldbmapping (jobpoolid, xml_tag, db_column) VALUES (?, ?, ?)");
            if (!$stmt->execute([$jobpoolid, $xml_tag, $db_column])) {
                error_log("Database update error: " . implode(", ", $stmt->errorInfo()));
            }
            $error = $stmt->rowCount() ? 'Mapping updated successfully.' : 'Failed to update mapping.';
        }

        // Redirect back to the same page to show updated table
        header("Location: {$_SERVER['PHP_SELF']}?jobpoolid=$jobpoolid");
        exit();
    }

    if (isset($_POST['arbitrage'])) {
        $arbitrage = floatval($_POST['arbitrage']);
        if ($arbitrage > 100) {
            $error = "Arbitrage percentage cannot be more than 100.";
        } else {
            // Update the arbitrage value in the database
            $stmt = $conn->prepare("UPDATE appljobseed SET arbitrage = ? WHERE jobpoolid = ?");
            if (!$stmt->execute([$arbitrage, $jobpoolid])) {
                error_log("Database update error: " . implode(", ", $stmt->errorInfo()));
            }
            $error = $stmt->rowCount() ? 'Arbitrage updated successfully.' : 'Failed to update arbitrage.';
            // Redirect back to the same page to show updated value
            header("Location: {$_SERVER['PHP_SELF']}?jobpoolid=$jobpoolid");
            exit();
        }
    }

    if (isset($_POST['jobpoolname'])) {
        $jobpoolname = $_POST['jobpoolname'];
        // Update the job pool name in the database
        $stmt = $conn->prepare("UPDATE appljobseed SET jobpoolname = ? WHERE jobpoolid = ?");
        if (!$stmt->execute([$jobpoolname, $jobpoolid])) {
            error_log("Database update error: " . implode(", ", $stmt->errorInfo()));
        }
        $error = $stmt->rowCount() ? 'Job Pool Name updated successfully.' : 'Failed to update Job Pool Name.';
        // Redirect back to the same page to show updated value
        header("Location: {$_SERVER['PHP_SELF']}?jobpoolid=$jobpoolid");
        exit();
    }

    if (isset($_POST['jobpoolurl'])) {
        $jobpoolurl = $_POST['jobpoolurl'];
        // Update the job pool URL in the database
        $stmt = $conn->prepare("UPDATE appljobseed SET jobpoolurl = ? WHERE jobpoolid = ?");
        if (!$stmt->execute([$jobpoolurl, $jobpoolid])) {
            error_log("Database update error: " . implode(", ", $stmt->errorInfo()));
        }
        $error = $stmt->rowCount() ? 'Job Pool URL updated successfully.' : 'Failed to update Job Pool URL.';
        // Redirect back to the same page to show updated value
        header("Location: {$_SERVER['PHP_SELF']}?jobpoolid=$jobpoolid");
        exit();
    }

    if (isset($_POST['min_cpa'])) {
        $min_cpa = !empty($_POST['min_cpa']) ? floatval($_POST['min_cpa']) : null;
        // Update the minimum CPA filter in the database
        $stmt = $conn->prepare("UPDATE appljobseed SET min_cpa = ? WHERE jobpoolid = ?");
        if (!$stmt->execute([$min_cpa, $jobpoolid])) {
            error_log("Database update error: " . implode(", ", $stmt->errorInfo()));
        }
        $error = 'Minimum CPA Filter updated successfully.';
        // Redirect back to the same page to show updated value
        header("Location: {$_SERVER['PHP_SELF']}?jobpoolid=$jobpoolid");
        exit();
    }

    if (isset($_POST['reset_mappings']) && $_POST['reset_mappings'] === '1') {
        // Delete all mappings for the current jobpoolid
        $stmt = $conn->prepare("DELETE FROM appldbmapping WHERE jobpoolid = ?");
        if (!$stmt->execute([$jobpoolid])) {
            error_log("Database delete error: " . implode(", ", $stmt->errorInfo()));
        }
        $error = $stmt->rowCount() ? 'All mappings reset successfully.' : 'Failed to reset mappings.';
        // Redirect back to the same page to show updated value
        header("Location: {$_SERVER['PHP_SELF']}?jobpoolid=$jobpoolid");
        exit();
    }

    // Handle custom fields addition/updation
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['custom_fieldname'], $_POST['custom_staticvalue'], $_POST['custom_appljobsmap'])) {
            $custom_fieldname = sanitizeFieldName($_POST['custom_fieldname']);
            $custom_staticvalue = $_POST['custom_staticvalue'];
            $custom_appljobsmap = $_POST['custom_appljobsmap'];

            if (!empty($custom_staticvalue)) {
                $custom_appljobsmap = ''; // Clear the mapping if a static value is provided
            }

            if (isset($_POST['custom_id']) && $_POST['custom_id'] != '') {
                // Update the custom field in the database
                $custom_id = $_POST['custom_id'];
                $stmt = $conn->prepare("UPDATE applcustomfields SET fieldname = ?, staticvalue = ?, appljobsmap = ? WHERE id = ?");
                if (!$stmt->execute([$custom_fieldname, $custom_staticvalue, $custom_appljobsmap, $custom_id])) {
                    error_log("Database update error: " . implode(", ", $stmt->errorInfo()));
                }
                $error = $stmt->rowCount() ? 'Custom field updated successfully.' : 'Failed to update custom field.';
            } else {
                // Add new custom field to the database
                $stmt = $conn->prepare("INSERT INTO applcustomfields (fieldname, staticvalue, appljobsmap, acctnum, jobpoolid) VALUES (?, ?, ?, ?, ?)");
                if (!$stmt->execute([$custom_fieldname, $custom_staticvalue, $custom_appljobsmap, $acctnum, $jobpoolid])) {
                    error_log("Database insert error: " . implode(", ", $stmt->errorInfo()));
                }
                $error = $stmt->rowCount() ? 'Custom field added successfully.' : 'Failed to add custom field.';
            }

            // Redirect back to the same page to show updated table
            header("Location: {$_SERVER['PHP_SELF']}?jobpoolid=$jobpoolid");
            exit();
        }
    }


    // Handle custom field deletion
    if (isset($_POST['delete_custom_field'])) {
        $custom_id = $_POST['delete_custom_field'];
        $stmt = $conn->prepare("DELETE FROM applcustomfields WHERE id = ?");
        if (!$stmt->execute([$custom_id])) {
            error_log("Database delete error: " . implode(", ", $stmt->errorInfo()));
        }
        $error = $stmt->rowCount() ? 'Custom field deleted successfully.' : 'Failed to delete custom field.';

        // Redirect back to the same page to show updated table
        header("Location: {$_SERVER['PHP_SELF']}?jobpoolid=$jobpoolid");
        exit();
    }
}

if (!file_exists($filepath)) {
    $error = "XML file not found at $filepath.";
} elseif (!is_readable($filepath)) {
    $error = "XML file is not readable.";
}  else {
    $reader = new XMLReader();
    if (!$reader->open($filepath)) {
        $error = "Failed to load XML file.";
    } else {
        $firstJobData = [];
 
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && ($reader->localName === 'job' || $reader->localName === 'doc')) {
                $dom = new DOMDocument;
                $node = simplexml_import_dom($dom->importNode($reader->expand(), true));
 
                function flattenXml($xml, $prefix = '')
                {
                    $result = [];
                    foreach ($xml as $key => $value) {
                        $fullKey = $prefix ? $prefix . '.' . $key : $key;
                        if ($value->count()) {
                            $result = array_merge($result, flattenXml($value, $fullKey));
                        } else {
                            $result[$fullKey] = (string) $value;
                        }
                    }
                    return $result;
                }
                 
                $firstJobData = flattenXml($node);
                break;  
            }
        }
        $reader->close();

        if (empty($firstJobData)) {
            $error = "No job data found in XML file.";
        } else { 
            $stmt = $conn->prepare("SELECT xml_tag, db_column FROM appldbmapping WHERE jobpoolid = ?");
            $stmt->execute([$jobpoolid]);
            $mappings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);  
 
            $columns = $conn->query("SHOW COLUMNS FROM appljobs")->fetchAll(PDO::FETCH_COLUMN);
 
            foreach ($mappings as $xmlTag => $dbColumn) {
                if (isset($firstJobData[$xmlTag]) && in_array($dbColumn, $columns)) { 
                }
            }
        }
    }
}

// Fetch current arbitrage value, job pool name, job pool URL, and min_cpa
$stmt = $conn->prepare("SELECT arbitrage, jobpoolname, jobpoolurl, min_cpa FROM appljobseed WHERE jobpoolid = ?");
$stmt->execute([$jobpoolid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$currentArbitrage = $row['arbitrage'] ?? '';
$currentJobPoolName = $row['jobpoolname'] ?? '';
$currentJobPoolURL = $row['jobpoolurl'] ?? '';
$currentMinCpa = $row['min_cpa'] ?? '';

// Fetch custom fields for the current jobpoolid
$stmt = $conn->prepare("SELECT id, fieldname, staticvalue, appljobsmap FROM applcustomfields WHERE jobpoolid = ?");
$stmt->execute([$jobpoolid]);
$customFields = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>View Job XML Mapping | Appljack</title>
    <?php include 'header.php'; ?>
    <script>
        function confirmReset() {
            if (confirm("This will delete all the XML field mappings. Proceed with caution.")) {
                document.getElementById('resetMappingsForm').submit();
            }
        }

        function toggleDropdown(inputId, dropdownId) {
            const inputField = document.getElementById(inputId);
            const dropdown = document.getElementById(dropdownId);

            inputField.addEventListener('input', function() {
                dropdown.disabled = inputField.value.trim() !== "";
            });

            // Initialize the state on page load
            dropdown.disabled = inputField.value.trim() !== "";
        }

        document.addEventListener('DOMContentLoaded', function() {
            toggleDropdown('custom_staticvalue', 'custom_appljobsmap');
        });
    </script>
</head>

<body>

    <?php include 'appltopnav.php'; ?>

    <?php echo renderHeader(
        "Edit Settings for Job Pool #" . htmlspecialchars($jobpoolid),
        '<button onclick="confirmReset()" class="reset-pool-btn">Reset All Mappings</button>',
        0
    ); ?>
    <section class="job_section">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-12 col-md-6">
                    <div class="job_card">

                        <form action="" method="post">
                            <p class="job_title">Edit Job Pool Name</p>
                            <input type="text" class="job_input" name="jobpoolname" placeholder="Enter Job Pool Name" value="<?= htmlspecialchars($currentJobPoolName) ?>" required>
                            <button type="submit" class="update_btn">Update Job Pool Name </button>
                        </form>
                    </div>
                </div>

                <div class="col-sm-12 col-md-6">
                    <div class="job_card">
                        <form action="" method="post">
                            <p class="job_title">Edit Job Pool URL</p>
                            <input type="text" class="job_input" name="jobpoolurl" placeholder="Enter Job Pool URL" value="<?= htmlspecialchars($currentJobPoolURL) ?>" required>
                            <button class="update_btn" type="submit">Update Job Pool URL </button>
                        </form>
                    </div>
                </div>

                <div class="col-sm-12 col-md-6">
                    <div class="job_card">
                        <form action="" method="post">
                            <p class="job_title">Edit Minimum CPA Filter</p>
                            <input type="number" class="job_input" name="min_cpa" placeholder="e.g., 2.50" step="0.01" min="0" value="<?= htmlspecialchars($currentMinCpa) ?>">
                            <small class="form-text text-muted" style="display: block; margin-top: 5px; font-size: 0.85em;">Jobs with CPA below this value will not be imported. Leave blank to import all jobs.</small>
                            <button class="update_btn" type="submit">Update Minimum CPA Filter </button>
                        </form>
                    </div>
                </div>

                <!-- <div class="col-sm-12 col-md-4">
                    <div class="job_card">
                        <form action="" method="post">
                            <p class="job_title">Set Arbitrage %</p>
                            <input type="text" class="job_input" name="arbitrage" placeholder="Enter Arbitrage " step="0.01" max="100" value="<?= htmlspecialchars($currentArbitrage) ?>" required>
                            <button class="update_btn" type="submit">Set Arbitrage % </button>
                        </form>
                    </div>
                </div> -->
            </div>
            <div class="row xml_mapping_sec">
                <div class="col-sm-12 col-md-12">
                    <div class="">
                        <div class="card">
                            <div class="card-header p-0 d-flex justify-content-between">
                                <h5 class="card-title">XML Mappings for Job Pool #<?= htmlspecialchars($jobpoolid) ?></h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <?php if ($error): ?>
                                        <p class="error"><?= htmlspecialchars($error); ?></p>
                                    <?php else: ?>
                                        <div class="custom_padding p-4">
                                            <table id="zero_config" class="table table-striped table-bordered mb-0">
                                                <thead>
                                                    <tr>
                                                        <th width="15%">XML Node</th>
                                                        <th width="30%">Value</th>
                                                        <th width="15%">Database Column</th>
                                                        <th width="40%">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if ($firstJobData): foreach ($firstJobData as $nodeName => $nodeValue):
                                                            $mappedColumn = $mappings[$nodeName] ?? ($nodeName);
                                                            $color = $mappedColumn ? 'blue' : (in_array($nodeName, $columns) ? 'green' : 'red');
                                                    ?>
                                                            <tr>
                                                                <td class="healthy-text"><?= htmlspecialchars($nodeName) ?></td>
                                                                <td class="bit-healthy-text">
                                                                    <div class="short-text">
                                                                        <?php if (strlen($nodeValue) > 100): ?>
                                                                            <span class="short"><?= htmlspecialchars(substr($nodeValue, 0, 100)) ?></span>
                                                                            <span class="dots">...</span>
                                                                            <span class="more-text"><?= htmlspecialchars($nodeValue) ?></span>
                                                                            <a href="#" class="read-more">Read More</a>
                                                                        <?php else: ?>
                                                                            <?= htmlspecialchars($nodeValue) ?>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </td>
                                                                <td class="healthy-text">
                                                                    <?php
                                                                    if ($mappedColumn && $mappedColumn !== $nodeName) {
                                                                        echo 'Mapped';
                                                                    } elseif (in_array($nodeName, $columns)) {
                                                                        echo 'Matched';
                                                                    } else {
                                                                        echo 'No Match';
                                                                    }
                                                                    ?>
                                                                </td>
                                                                <?php
                                                                // Assuming $conn is your database connection

                                                                // Fetch column names from appljobs table
                                                                try {
                                                                    $columnsQuery = $conn->query("SHOW COLUMNS FROM appljobs");
                                                                    $columns = $columnsQuery->fetchAll(PDO::FETCH_COLUMN);
                                                                } catch (PDOException $e) {
                                                                    $error = "Failed to fetch columns from appljobs: " . $e->getMessage();
                                                                }

                                                                // Predefined $columnLabels array
                                                                $columnLabels = [
                                                                    'job_reference' => 'Unique Job Id - REQUIRED',
                                                                    'posted_at' => 'Date Posted',
                                                                    'title' => 'Job Title',
                                                                    'city' => 'Job Location - City',
                                                                    'state' => 'Job Location - State',
                                                                    'country' => 'Job Location - Country',
                                                                    'zip' => 'Job Location - Zip Code',
                                                                    'title' => 'Job Title',
                                                                    'company' => 'Company Name',
                                                                    'category' => 'Category (ex: Industry)',
                                                                    'url' => 'Apply Button URL',
                                                                    'body' => 'Job Description',
                                                                    'custom1' => 'Custom Field 1',
                                                                    'custom2' => 'Custom Field 2',
                                                                    'custom3' => 'Custom Field 3',
                                                                    'custom4' => 'Custom Field 4',
                                                                    'custom5' => 'Custom Field 5',
                                                                    'cpc' => 'CPC',
                                                                    'cpa' => 'CPA',
                                                                    'job_type' => 'Employment Type (ex: Full Time)',
                                                                    'logo' => 'Company Logo URL',
                                                                    'location'=> 'Job Location - Full Address',
                                                                    'industry' => 'Job Industry'

                                                                    // Add more mappings as needed
                                                                ];

                                                                // Check if $columns is populated
                                                                if (!empty($columns)) {
                                                                    // Special options to display first
                                                                    $specialOptions = [
                                                                        '' => 'Remove Mapping',
                                                                        'job_reference' => $columnLabels['job_reference'] // Use the label from your array
                                                                    ];

                                                                    // Sort the rest alphabetically by their label
                                                                    $otherOptions = [];
                                                                    foreach ($columns as $column) {
                                                                        if (!in_array($column, ['id', 'feedId', 'jobpoolid', 'acctnum', 'html_jobs', 'mobile_friendly_apply', 'custid', ])) {
                                                                            $label = isset($columnLabels[$column]) ? $columnLabels[$column] : $column;
                                                                            $otherOptions[$column] = $label;
                                                                        }
                                                                    }
                                                                    asort($otherOptions); // Sort by label (value)

                                                                    // Display dropdown
                                                                    ?>
                                                                    <td>
                                                                        <form method="post">
                                                                            <div class="d-flex align-items-center justify-content-center gap-3">
                                                                                <input type="hidden" name="xml_tag" value="<?= htmlspecialchars($nodeName) ?>">
                                                                                <select name="db_column" class="w-75 light-input">
                                                                                    <!-- Special options: "Remove Mapping" and "Unique Job Id - REQUIRED" -->
                                                                                    <?php foreach ($specialOptions as $value => $label): ?>
                                                                                        <option value="<?= htmlspecialchars($value) ?>" <?= $value === $mappedColumn ? 'selected' : '' ?>>
                                                                                            <?= htmlspecialchars($label) ?>
                                                                                        </option>
                                                                                    <?php endforeach; ?>

                                                                                    <!-- Other options (sorted alphabetically by label) -->
                                                                                    <?php foreach ($otherOptions as $value => $label): ?>
                                                                                        <option value="<?= htmlspecialchars($value) ?>" <?= $value === $mappedColumn ? 'selected' : '' ?>>
                                                                                            <?= htmlspecialchars($label) ?>
                                                                                        </option>
                                                                                    <?php endforeach; ?>
                                                                                </select>
                                                                                <button class="btn_green_dark w-50">Set Mapping</button>
                                                                            </div>
                                                                        </form>
                                                                    </td>
                                                                    <?php
                                                                } else {
                                                                    // Handle the case where $columns is empty or an error occurred
                                                                    echo "<p>Error: Unable to load column data.</p>";
                                                                }
                                                                     ?>
                                                            </tr>
                                                        <?php endforeach;
                                                    else: ?>
                                                        <tr>
                                                            <td colspan="4">No job data available in the XML file.</td>
                                                        </tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            <!-- another section  -->
            <div class="row xml_mapping_sec second">
                <div class="col-sm-12 col-md-12">
                    <div class="">
                        <div class="card">
                            <div class="card-header p-0 d-flex justify-content-between">
                                <h5 class="card-title">Custom Fields and Mappings</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <?php if ($customFields): ?>
                                        <div class="custom_padding p-4">
                                            <table
                                                id="zero_config"
                                                class="table table-striped table-bordered mb-0">
                                                <thead>
                                                    <tr>
                                                        <th width="15%">Field Name</th>
                                                        <th width="15%">Static Value</th>
                                                        <th width="15%">App Jobs Map</th>
                                                        <th width="55%">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($customFields as $field): ?>
                                                        <tr>
                                                            <td class="healthy-text"><?= htmlspecialchars($field['fieldname']) ?></td>
                                                            <td class="bit-healthy-text"><?= htmlspecialchars($field['staticvalue']) ?></td>
                                                            <td class="healthy-text"><?= htmlspecialchars($field['appljobsmap']) ?></td>
                                                            <td>
                                                                <form action="" method="post">
                                                                    <div class="row">
                                                                        <!-- First row: 3 inputs -->
                                                                        <div class="col-md-4">
                                                                            <input type="hidden" name="custom_id" value="<?= htmlspecialchars($field['id']) ?>">
                                                                            <input type="text" class="form-control light-input" name="custom_fieldname" value="<?= htmlspecialchars($field['fieldname']) ?>" required>
                                                                        </div>
                                                                        <div class="col-md-4">
                                                                            <input type="text" class="form-control light-input" id="custom_staticvalue_<?= htmlspecialchars($field['id']) ?>" name="custom_staticvalue" value="<?= htmlspecialchars($field['staticvalue']) ?>" oninput="toggleDropdown('custom_staticvalue_<?= htmlspecialchars($field['id']) ?>', 'custom_appljobsmap_<?= htmlspecialchars($field['id']) ?>')">
                                                                        </div>
                                                                        <div class="col-md-4">
                                                                            <select class="form-control light-input" id="custom_appljobsmap_<?= htmlspecialchars($field['id']) ?>" name="custom_appljobsmap" <?= !empty($field['staticvalue']) ? 'disabled' : '' ?>>
                                                                                <option value="">Remove Mapping</option>
                                                                                <?php foreach ($columns as $column): ?>
                                                                                    <?php if (!in_array($column, ['id', 'feedId', 'jobpoolid', 'acctnum'])): ?>
                                                                                        <option value="<?= htmlspecialchars($column) ?>" <?= $column === $field['appljobsmap'] ? 'selected' : '' ?>>
                                                                                            <?= htmlspecialchars($column) ?>
                                                                                        </option>
                                                                                    <?php endif; ?>
                                                                                <?php endforeach; ?>
                                                                            </select>
                                                                        </div>
                                                                    </div>

                                                                    <!-- Second row: Update and Delete buttons -->
                                                                    <div class="row mt-2">
                                                                        <div class="col-md-6">
                                                                            <button class="btn_green_dark px-4 w-100">Update</button>
                                                                        </div>
                                                                        <div class="col-md-6">
                                                                            <form action="" method="post" style="display:inline;">
                                                                                <input type="hidden" name="delete_custom_field" value="<?= htmlspecialchars($field['id']) ?>">
                                                                                <button class="btn_green_dark w-100" onclick="return confirm('Are you sure you want to delete this custom field?');">Delete</button>
                                                                            </form>
                                                                        </div>
                                                                    </div>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>

                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <p class="error">No Custom Fields or Mappings for This Job Pool.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- another section  -->
            <div class="row xml_mapping_sec second">
                <div class="col-sm-12 col-md-12">
                    <div class="add_field_form">
                        <div class="card">
                            <div class="card-header p-0 d-flex justify-content-between">
                                <h5 class="card-title">Add Custom Field</h5>
                            </div>
                            <div class="card-body">
                                <div class="card styled m-4">
                                    <div class="card-body p-0">
                                        <form action="" method="post" class="p-3">
                                            <label for="custom_fieldname" class="healthy-text text-dark-green mt-2">Field Name:</label>
                                            <input type="text" id="custom_fieldname" class=" light-input" name="custom_fieldname" required>
                                            <label for="custom_staticvalue" class="healthy-text text-dark-green mt-2">Static Value:</label>
                                            <input type="text" id="custom_staticvalue" class=" light-input" name="custom_staticvalue" oninput="toggleDropdown('custom_staticvalue', 'custom_appljobsmap')">
                                            <input type="hidden" id="hidden_custom_appljobsmap" class=" light-input" name="custom_appljobsmap" value="">
                                            <label for="custom_appljobsmap" class="healthy-text text-dark-green mt-2">App Jobs Map:</label>
                                            <select id="custom_appljobsmap" name="custom_appljobsmap" class="light-input">
                                                <option value="">Select Mapping</option>
                                                <?php foreach ($columns as $column): if (!in_array($column, ['id', 'feedId', 'jobpoolid', 'acctnum'])): ?>
                                                        <option value="<?= htmlspecialchars($column) ?>"><?= htmlspecialchars($column) ?></option>
                                                <?php endif;
                                                endforeach; ?>
                                            </select>
                                            <button class="btn_green_dark mt-3 w-100">Add Custom Field</button>
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

    <form id="resetMappingsForm" action="" method="post" style="display:none;">
        <input type="hidden" name="reset_mappings" value="1">
    </form>

    <?php include 'footer.php'; ?>

    <script>
        document.getElementById('custom_staticvalue').addEventListener('input', function() {
            const dropdown = document.getElementById('custom_appljobsmap');
            const hiddenDropdown = document.getElementById('hidden_custom_appljobsmap');
            if (this.value.trim() !== "") {
                dropdown.disabled = true;
                hiddenDropdown.value = dropdown.value; // Set hidden input value
            } else {
                dropdown.disabled = false;
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const readMoreLinks = document.querySelectorAll('.read-more');
            readMoreLinks.forEach(link => {
                link.addEventListener('click', function(event) {
                    event.preventDefault();
                    const shortText = this.previousElementSibling.previousElementSibling; // short text
                    const moreText = this.previousElementSibling; // full text
                    const dots = this.previousElementSibling.previousElementSibling.previousElementSibling; // dots

                    if (moreText.style.display === 'none' || moreText.style.display === '') {
                        moreText.style.display = 'inline';
                        dots.style.display = 'none';
                        shortText.style.display = 'none';
                        this.textContent = 'Read Less';
                    } else {
                        moreText.style.display = 'none';
                        dots.style.display = 'inline';
                        shortText.style.display = 'inline';
                        this.textContent = 'Read More';
                    }
                });
            });
        });
    </script>

</body>

</html>
