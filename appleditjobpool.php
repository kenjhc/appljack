<?php

include 'database/db.php';
 
if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php");
    exit();
} 

$error = ''; // Initialize $error to avoid undefined variable notices
$jobpoolid = $_GET['jobpoolid'] ?? ''; // Ensure $jobpoolid is initialized before use
  
$acctnum = $_SESSION['acctnum']; // Retrieve account number from session
$filepath = "/chroot/home/appljack/appljack.com/html/feedsclean/{$acctnum}-{$jobpoolid}.xml"; // Construct the file path

// Function to sanitize and reformat the field name for XML
function sanitizeFieldName($fieldName) {
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
} else {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($filepath);
    if ($xml === false) {
        $error = "Failed to load XML file.";
        foreach (libxml_get_errors() as $libxmlError) {
            $error .= "<br>" . htmlspecialchars($libxmlError->message);
        }
        libxml_clear_errors();
    } else {
        // Check for <job> or <doc> nodes
        $jobs = $xml->xpath('//job | //doc');
        if (empty($jobs)) {
            $error = "No job data found in XML file.";
            error_log("No job data found in XML file: " . file_get_contents($filepath));
        } else {
            $firstJob = $jobs[0];
            // Flatten the XML structure
            function flattenXml($xml, $prefix = '') {
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
            $flattenedJob = flattenXml($firstJob);

            // Fetch existing mappings from the database
            $stmt = $conn->prepare("SELECT xml_tag, db_column FROM appldbmapping WHERE jobpoolid = ?");
            $stmt->execute([$jobpoolid]);
            $mappings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // Fetch as associative array
            // Fetch column names from appljobs table
            $columns = $conn->query("SHOW COLUMNS FROM appljobs")->fetchAll(PDO::FETCH_COLUMN);
        }
    }
}

// Fetch current arbitrage value, job pool name, and job pool URL
$stmt = $conn->prepare("SELECT arbitrage, jobpoolname, jobpoolurl FROM appljobseed WHERE jobpoolid = ?");
$stmt->execute([$jobpoolid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$currentArbitrage = $row['arbitrage'] ?? '';
$currentJobPoolName = $row['jobpoolname'] ?? '';
$currentJobPoolURL = $row['jobpoolurl'] ?? '';

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

<h1>Edit Settings for Job Pool #<?= htmlspecialchars($jobpoolid) ?>
    <button onclick="confirmReset()">Reset All Mappings</button>
</h1>

<div class="edit-jobpoolname-form-container">
    <h2>Edit Job Pool Name</h2>
    <form action="" method="post">
        <label for="jobpoolname">Job Pool Name:</label>
        <input type="text" id="jobpoolname" name="jobpoolname" value="<?= htmlspecialchars($currentJobPoolName) ?>" required>
        <button type="submit">Update Job Pool Name</button>
    </form>
</div>

<div class="edit-jobpoolurl-form-container">
    <h2>Edit Job Pool URL</h2>
    <form action="" method="post">
        <label for="jobpoolurl">Job Pool URL:</label>
        <input type="text" id="jobpoolurl" name="jobpoolurl" value="<?= htmlspecialchars($currentJobPoolURL) ?>" required>
        <button type="submit">Update Job Pool URL</button>
    </form>
</div>

<div class="arbitrage-form-container">
    <h2>Set Arbitrage %</h2>
    <form action="" method="post">
        <label for="arbitrage">Arbitrage:</label>
        <input type="number" id="arbitrage" name="arbitrage" step="0.01" max="100" value="<?= htmlspecialchars($currentArbitrage) ?>" required>
        <span>%</span>
        <button type="submit">Set Arbitrage</button>
    </form>
</div>

<h2>XML Mappings for Job Pool #<?= htmlspecialchars($jobpoolid) ?></h2>
 
<?php if ($error): ?>
    <p class="error"><?= htmlspecialchars($error); ?></p>
<?php else: ?>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>XML Node</th>
                    <th>Value</th>
                    <th>Database Column</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($flattenedJob): foreach ($flattenedJob as $nodeName => $nodeValue):
                    $mappedColumn = $mappings[$nodeName] ?? ($nodeName);
                    $color = $mappedColumn ? 'blue' : (in_array($nodeName, $columns) ? 'green' : 'red');
                ?>
                <tr>
                    <td><?= htmlspecialchars($nodeName) ?></td>
                    <td><?= htmlspecialchars($nodeValue) ?></td>
                    <td style="color: <?= $color ?>;">
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
                    <td>
                        <form action="" method="post">
                            <input type="hidden" name="xml_tag" value="<?= htmlspecialchars($nodeName) ?>">
                            <select name="db_column">
                                <option value="">Remove Mapping</option>
                                <?php
                                    foreach ($columns as $column):
                                        if (!in_array($column, ['id', 'feedId', 'jobpoolid', 'acctnum'])):
                                ?>
                                    <option value="<?= htmlspecialchars($column) ?>" <?= $column === $mappedColumn ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($column) ?>
                                    </option>
                                <?php
                                        endif;
                                    endforeach;
                                ?>
                            </select>
                            <button type="submit">Set Mapping</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                    <tr>
                        <td colspan="4">No job data available in the XML file.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<h2>Custom Fields and Mappings</h2>
<?php if ($customFields): ?>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Field Name</th>
                    <th>Static Value</th>
                    <th>App Jobs Map</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customFields as $field): ?>
                <tr>
                    <td><?= htmlspecialchars($field['fieldname']) ?></td>
                    <td><?= htmlspecialchars($field['staticvalue']) ?></td>
                    <td><?= htmlspecialchars($field['appljobsmap']) ?></td>
                    <td>
                        <form action="" method="post">
                            <input type="hidden" name="custom_id" value="<?= htmlspecialchars($field['id']) ?>">
                            <input type="text" name="custom_fieldname" value="<?= htmlspecialchars($field['fieldname']) ?>" required>
                            <input type="text" id="custom_staticvalue_<?= htmlspecialchars($field['id']) ?>" name="custom_staticvalue" value="<?= htmlspecialchars($field['staticvalue']) ?>" oninput="toggleDropdown('custom_staticvalue_<?= htmlspecialchars($field['id']) ?>', 'custom_appljobsmap_<?= htmlspecialchars($field['id']) ?>')">
                            <select id="custom_appljobsmap_<?= htmlspecialchars($field['id']) ?>" name="custom_appljobsmap" <?= !empty($field['staticvalue']) ? 'disabled' : '' ?>>
                                <option value="">Remove Mapping</option>
                                <?php
                                    foreach ($columns as $column):
                                        if (!in_array($column, ['id', 'feedId', 'jobpoolid', 'acctnum'])):
                                ?>
                                    <option value="<?= htmlspecialchars($column) ?>" <?= $column === $field['appljobsmap'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($column) ?>
                                    </option>
                                <?php
                                        endif;
                                    endforeach;
                                ?>
                            </select>
                            <button type="submit">Update</button>
                        </form>
                        <form action="" method="post" style="display:inline;">
                            <input type="hidden" name="delete_custom_field" value="<?= htmlspecialchars($field['id']) ?>">
                            <button type="submit" onclick="return confirm('Are you sure you want to delete this custom field?');">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <p>No Custom Fields or Mappings for This Job Pool.</p>
<?php endif; ?>

<h2>Add Custom Field</h2>
<form action="" method="post">
    <label for="custom_fieldname">Field Name:</label>
    <input type="text" id="custom_fieldname" name="custom_fieldname" required>
    <label for="custom_staticvalue">Static Value:</label>
    <input type="text" id="custom_staticvalue" name="custom_staticvalue" oninput="toggleDropdown('custom_staticvalue', 'custom_appljobsmap')">
    <input type="hidden" id="hidden_custom_appljobsmap" name="custom_appljobsmap" value="">
    <label for="custom_appljobsmap">App Jobs Map:</label>
    <select id="custom_appljobsmap" name="custom_appljobsmap">
        <option value="">Select Mapping</option>
        <?php foreach ($columns as $column): if (!in_array($column, ['id', 'feedId', 'jobpoolid', 'acctnum'])): ?>
            <option value="<?= htmlspecialchars($column) ?>"><?= htmlspecialchars($column) ?></option>
        <?php endif; endforeach; ?>
    </select>
    <button type="submit">Add Custom Field</button>
</form>
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
</script>


<form id="resetMappingsForm" action="" method="post" style="display:none;">
    <input type="hidden" name="reset_mappings" value="1">
</form>

<?php include 'footer.php'; ?>
</body>
</html>
