<?php
include 'database/db.php';
require 'PublisherController.php';
try {
    $custCompaniesStmt = $pdo->prepare("SELECT * FROM applpubs");
    $custCompaniesStmt->execute();
    $custCompanies = $custCompaniesStmt->fetchAll();
} catch (PDOException $e) {
    setToastMessage('error', "Database error: " . $e->getMessage());
    header("Location: applmasterview.php");
    exit;
}
if (!isset($_GET['publisherid'])) {
    die('Publisher ID not provided.');
}

$publisher = getPublisherById($_GET['publisherid'], $pdo);

if (!$publisher) {
    die('Publisher not found.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>View Publisher</title>
    <?php include 'header.php'; ?>
</head>

<body>
<?php include 'appltopnav.php'; ?>
      <?php
    ob_start();
    ?>

    <form action="view_publisher.php" method="get" class="customer-info-dates ml-5" style="min-width: 28rem;">
        <div class="form-group mb-0 border-white">
            <label for="publisherid" class="no-wrap text-white">Switch Publisher Account:</label>
            <select name="publisherid" id="publisherid" class="form-control" onchange="this.form.submit()">
                <?php foreach ($custCompanies as $company): ?>
                    <option value="<?= htmlspecialchars($company['publisherid']) ?>" <?= isset($_GET['publisherid']) && $_GET['publisherid'] == $company['publisherid'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($company['publishername']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php
    $formContent = ob_get_clean();

    echo renderHeader("Publisher Portal", $formContent);
    ?>

    <section class="job_section py-4">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0 bold text-white">Publisher Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-4">
                                <h6 class="text-muted">Publisher Information</h6>
                                <hr>
                                <p><strong>Name:</strong> <?= htmlspecialchars($publisher['publishername']) ?></p>
                                <p><strong>ID:</strong> <?= htmlspecialchars($publisher['publisherid']) ?></p>
                                <p><strong>Contact Name:</strong> <?= htmlspecialchars($publisher['publisher_contact_name'] ?? 'N/A') ?></p>
                                <p><strong>Contact Email:</strong> <?= htmlspecialchars($publisher['publisher_contact_email'] ?? 'N/A') ?></p>
                            </div>
                            <div class="text-center">
                                <a href="publisherspool.php" class="btn btn-secondary">Back to List</a>
                                <a href="edit_publisher.php?id=<?= htmlspecialchars($publisher['publisherid']) ?>" class="btn btn-success">Edit Publisher</a>
                                <a href="delete_publisher.php?id=<?= htmlspecialchars($publisher['publisherid']) ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this publisher?');">Delete Publisher</a>
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
