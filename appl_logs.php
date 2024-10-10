<?php
include 'database/db.php';

// Pagination variables
$limit = 50; // Number of records per page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Get filters
$logType = isset($_GET['type']) ? $db->real_escape_string($_GET['type']) : 'cronjob';
$logLevel = isset($_GET['level']) ? $db->real_escape_string($_GET['level']) : 'all';
$scriptName = isset($_GET['script']) ? $db->real_escape_string($_GET['script']) : '';

// Fetch unique script names for filter
$scriptQuery = "SELECT DISTINCT script_name FROM appl_logs ORDER BY script_name";
$scriptResult = $db->query($scriptQuery);
if (!$scriptResult) {
    handleDbError("Prepare failed: " . $db->error);
}
$scripts = $scriptResult->fetch_all(MYSQLI_ASSOC);

// Prepare SQL query
$sql = "SELECT id, log_type, log_level, script_name, message, timestamp FROM appl_logs WHERE log_type = ?";
$params = [$logType];
$types = 's';

if ($logLevel !== 'all') {
    $sql .= " AND log_level = ?";
    $params[] = $logLevel;
    $types .= 's';
}

if (!empty($scriptName)) {
    $sql .= " AND script_name = ?";
    $params[] = $scriptName;
    $types .= 's';
}

$sql .= " ORDER BY timestamp DESC LIMIT ? OFFSET ?";

// Prepare statement
$stmt = $db->prepare($sql);
if ($stmt === false) {
    die('Prepare failed: ' . htmlspecialchars($db->error));
}

// Bind parameters
$types .= 'ii'; // Add types for LIMIT and OFFSET
$params[] = $limit;
$params[] = $offset;

$bindNames = str_repeat('s', count($params) - 2) . 'ii'; // Create the correct bind_types string
$stmt->bind_param($bindNames, ...$params);

// Execute statement
$stmt->execute();
$result = $stmt->get_result();

// Get total record count for pagination
$countSql = "SELECT COUNT(*) as total FROM appl_logs WHERE log_type = ?";
$countParams = [$logType];
$countTypes = 's';

if ($logLevel !== 'all') {
    $countSql .= " AND log_level = ?";
    $countParams[] = $logLevel;
    $countTypes .= 's';
}

if (!empty($scriptName)) {
    $countSql .= " AND script_name = ?";
    $countParams[] = $scriptName;
    $countTypes .= 's';
}

$countStmt = $db->prepare($countSql);
if ($countStmt === false) {
    die('Prepare failed: ' . htmlspecialchars($db->error));
}

// Bind count parameters
$countStmt->bind_param($countTypes, ...$countParams);

// Execute count statement
$countStmt->execute();
$countResult = $countStmt->get_result()->fetch_assoc();
$totalRecords = $countResult['total'];
$totalPages = ceil($totalRecords / $limit);

// Close statements and database connection
$stmt->close();
$countStmt->close();
$db->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appljack Logs | ApplJack</title>
    <?php include 'header.php'; ?>
    <!-- <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet"> -->

    <style>
        .main-content {
            padding: 2rem 4rem;
        }

        th {
            background-color: #1E152A !important;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table,
        th,
        td {
            border: 1px solid black;
        }

        th,
        td {
            padding: 8px;
            text-align: left;
        }

        th {
            background-color: #f2f2f2;
        }

        /* .logs_filter {
            margin: unset !important;
        } */

        .logs_filter>div {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        input[type=text],
        input[type=password],
        input[type=email],
        select {
            min-width: 12rem;
        }

        input[type=submit],
        button[type=submit] {
            width: unset;
            padding: 10px 2rem;
            margin-top: auto;
        }

        /* Custom pagination styles */
        nav:not(#sidebar) {
            margin-top: .6rem;
        }

        .pagination {
            display: flex;
            list-style: none;
            padding: 0;
            margin: 0;
            justify-content: center;
        }

        .page-item {
            margin: 0 2px;
        }

        .page-link {
            display: block;
            padding: 0.5rem 1rem;
            text-decoration: none;
            color: #2a3d45;
            background-color: #fff;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            font-weight: 700;
            text-align: center;
        }

        .page-link:hover {
            background-color: #f8f9fa;
        }

        .page-item.active .page-link {
            background-color: #2a3d45;
            color: #fff;
            border-color: #2a3d45;
            z-index: 0;
        }

        .page-item.disabled .page-link {
            color: #6c757d;
            pointer-events: none;
            background-color: #fff;
            border-color: #dee2e6;
        }

        label {
            display: block;
        }
    </style>
</head>

<body>
    <?php include 'appltopnav.php'; ?>

    <?php echo renderHeader(
        "Application Logs"
    ); ?>

    <section class="job_section">
        <div class="">

            <form method="get" class="logs_filter mb-4 d-flex justify-between items-center">
                <div class="d-flex align-items-end">
                    <div>
                        <label for="type">Log Type:</label>
                        <select name="type" id="type" class="form-control">
                            <option value="cronjob" <?= $logType === 'cronjob' ? 'selected' : '' ?>>Cronjob</option>
                            <option value="error" <?= $logType === 'error' ? 'selected' : '' ?>>Error</option>
                            <option value="info" <?= $logType === 'info' ? 'selected' : '' ?>>Info</option>
                        </select>
                    </div>
                    <div>
                        <label for="level">Log Level:</label>
                        <select name="level" id="level" class="form-control">
                            <option value="all" <?= $logLevel === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="error" <?= $logLevel === 'error' ? 'selected' : '' ?>>Error</option>
                            <option value="warning" <?= $logLevel === 'warning' ? 'selected' : '' ?>>Warning</option>
                            <option value="success" <?= $logLevel === 'success' ? 'selected' : '' ?>>Success</option>
                        </select>
                    </div>
                    <div>
                        <label for="script">Script Name:</label>
                        <select name="script" id="script" class="form-control">
                            <option value="">All</option>
                            <?php foreach ($scripts as $script): ?>
                                <option value="<?= htmlspecialchars($script['script_name']) ?>" <?= $scriptName === $script['script_name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($script['script_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn_green py-1 px-4">Filter</button>
                </div>

                <nav aria-label="Page navigation">
                    <ul class="pagination">
                        <?php
                        if (($page > 1) && ($page > 1)) {
                        ?>
                            <li class="page-item">
                                <a class="page-link" href="?type=<?= urlencode($logType) ?>&level=<?= urlencode($logLevel) ?>&script=<?= urlencode($scriptName) ?>&page=<?= $page - 1 ?>" aria-label="Previous">
                                    &laquo;
                                </a>
                            </li>

                        <?php } ?>
                        <?php
                        if (($page - 1) > 1) {
                        ?>
                            <li class="page-item <?= 1 === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?type=<?= urlencode($logType) ?>&level=<?= urlencode($logLevel) ?>&script=<?= urlencode($scriptName) ?>&page=1">
                                    1
                                </a>
                            </li>

                            <li class="page-item">
                                <a class="page-link" href="#">
                                    ...
                                </a>
                            </li>
                        <?php
                        }

                        for ($i = ($page - 1); $i <= ($page + 1); $i++) {
                            if ($i < 1)
                                continue;
                            if ($i > $totalPages)
                                break;
                            if ($i == $page) {
                                $class = "active";
                            } else {
                                $class = "page-a-link";
                            }
                        ?>
                            <li class="page-item <?= $class ?>">
                                <a class="page-link" href="?type=<?= urlencode($logType) ?>&level=<?= urlencode($logLevel) ?>&script=<?= urlencode($scriptName) ?>&page=<?php echo $i; ?>">
                                    <?= $i ?>
                                </a>
                            </li>

                        <?php
                        }

                        if (($totalPages - ($page + 1)) >= 1) {
                        ?>
                            <li class="page-item">
                                <a class="page-link" href="#">
                                    ...
                                </a>
                            </li>
                        <?php
                        }
                        if (($totalPages - ($page + 1)) > 0) {
                            if ($page == $totalPages) {
                                $class = "active";
                            } else {
                                $class = "page-a-link";
                            }
                        ?>

                            <li class="page-item <?= $class ?>">
                                <a class="page-link" href="?type=<?= urlencode($logType) ?>&level=<?= urlencode($logLevel) ?>&script=<?= urlencode($scriptName) ?>&page=<?php echo $totalPages; ?>">
                                    <?= $totalPages ?>
                                </a>
                            </li>

                        <?php
                        }
                        ?>
                        <?php
                        if (($page < $totalPages)) {
                        ?>
                            <li class="page-item">
                                <a class="page-link" href="?type=<?= urlencode($logType) ?>&level=<?= urlencode($logLevel) ?>&script=<?= urlencode($scriptName) ?>&page=<?= $page + 1 ?>" aria-label="Next">
                                    &raquo;
                                </a>
                            </li>

                        <?php
                        }
                        ?>
                    </ul>
                </nav>
            </form>

            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Type</th>
                        <th>Level</th>
                        <th>Script/Line</th>
                        <th>Message</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $count = (($page * $limit) - ($limit - 1));
                    if ($result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()):
                            // Determine the background color based on the log level
                            $backgroundColor = '';
                            switch ($row['log_level']) {
                                case 'error':
                                    $backgroundColor = '#f8d7da'; // Light red
                                    break;
                                case 'warning':
                                    $backgroundColor = '#fff3cd'; // Light yellow
                                    break;
                                case 'success':
                                    $backgroundColor = '#d4edda'; // Light green
                                    break;
                                default:
                                    $backgroundColor = '#f8f9fa'; // Light gray for others
                                    break;
                            }
                    ?>
                            <tr style="background-color: <?= htmlspecialchars($backgroundColor) ?>;">
                                <td><?= htmlspecialchars($count) ?></td>
                                <td><?= ucwords(htmlspecialchars($row['log_type'])) ?></td>
                                <td><?= ucwords(htmlspecialchars($row['log_level'])) ?></td>
                                <td><?= htmlspecialchars($row['script_name']) ?></td>
                                <td><?= htmlspecialchars($row['message']) ?></td>
                                <td><?= htmlspecialchars($row['timestamp']) ?></td>
                            </tr>
                        <?php
                            $count++;
                        endwhile;
                    } else {
                        ?>
                        <tr>
                            <td colspan="6" class="text-center">No logs found.</td>
                        </tr>
                    <?php
                    }
                    ?>
                </tbody>
            </table>

            <nav aria-label="Page navigation">
                <ul class="pagination">
                    <?php
                    if (($page > 1) && ($page > 1)) {
                    ?>
                        <li class="page-item">
                            <a class="page-link" href="?type=<?= urlencode($logType) ?>&level=<?= urlencode($logLevel) ?>&script=<?= urlencode($scriptName) ?>&page=<?= $page - 1 ?>" aria-label="Previous">
                                &laquo;
                            </a>
                        </li>

                    <?php } ?>
                    <?php
                    if (($page - 1) > 1) {
                    ?>
                        <li class="page-item <?= 1 === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?type=<?= urlencode($logType) ?>&level=<?= urlencode($logLevel) ?>&script=<?= urlencode($scriptName) ?>&page=1">
                                1
                            </a>
                        </li>

                        <li class="page-item">
                            <a class="page-link" href="#">
                                ...
                            </a>
                        </li>
                    <?php
                    }

                    for ($i = ($page - 1); $i <= ($page + 1); $i++) {
                        if ($i < 1)
                            continue;
                        if ($i > $totalPages)
                            break;
                        if ($i == $page) {
                            $class = "active";
                        } else {
                            $class = "page-a-link";
                        }
                    ?>
                        <li class="page-item <?= $class ?>">
                            <a class="page-link" href="?type=<?= urlencode($logType) ?>&level=<?= urlencode($logLevel) ?>&script=<?= urlencode($scriptName) ?>&page=<?php echo $i; ?>">
                                <?= $i ?>
                            </a>
                        </li>

                    <?php
                    }

                    if (($totalPages - ($page + 1)) >= 1) {
                    ?>
                        <li class="page-item">
                            <a class="page-link" href="#">
                                ...
                            </a>
                        </li>
                    <?php
                    }
                    if (($totalPages - ($page + 1)) > 0) {
                        if ($page == $totalPages) {
                            $class = "active";
                        } else {
                            $class = "page-a-link";
                        }
                    ?>

                        <li class="page-item <?= $class ?>">
                            <a class="page-link" href="?type=<?= urlencode($logType) ?>&level=<?= urlencode($logLevel) ?>&script=<?= urlencode($scriptName) ?>&page=<?php echo $totalPages; ?>">
                                <?= $totalPages ?>
                            </a>
                        </li>

                    <?php
                    }
                    ?>
                    <?php
                    if (($page < $totalPages)) {
                    ?>
                        <li class="page-item">
                            <a class="page-link" href="?type=<?= urlencode($logType) ?>&level=<?= urlencode($logLevel) ?>&script=<?= urlencode($scriptName) ?>&page=<?= $page + 1 ?>" aria-label="Next">
                                &raquo;
                            </a>
                        </li>

                    <?php
                    }
                    ?>
                </ul>
            </nav>


            <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
            <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.3/dist/umd/popper.min.js"></script>
            <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
        </div>
    </section>

    <?php include 'footer.php'; ?>

</body>

</html>