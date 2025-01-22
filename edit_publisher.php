<?php
include 'database/db.php';
require 'PublisherController.php';

if (!isset($_GET['id'])) {
    die('Publisher ID not provided.');
}

$publisher = getPublisherById($_GET['id'], $pdo);

if (!$publisher) {
    die('Publisher not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $updated = updatePublisher($_GET['id'], $_POST, $pdo);

    if ($updated) {
        header('Location: publisherspool.php?message=Publisher updated successfully&type=success');
        exit;
    } else {
        header('Location: publisherspool.php?message=Failed to update publisher&type=error');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Edit Publisher</title>
    <?php include 'header.php'; ?>
</head>

<body>
    <?php include 'appltopnav.php'; ?>
    <?php echo renderHeader("Publishers"); ?>

    <section class="job_section py-4">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold text-white">Edit Publisher</h5>
                        <a href="publisherspool.php" class="btn btn-primary btn-sm">Back</a>
                    </div>
                    <div class="card-body">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger" role="alert">
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="form-group mb-3">
                                <label for="publishername" class="form-label"><strong>Publisher Name:</strong></label>
                                <input type="text" id="publishername" name="publishername" class="form-control" 
                                       value="<?= htmlspecialchars($publisher['publishername']) ?>" required>
                            </div>

                            <div class="form-group mb-3">
                                <label for="publisher_contact_name" class="form-label"><strong>Contact Name:</strong></label>
                                <input type="text" id="publisher_contact_name" name="publisher_contact_name" 
                                       class="form-control" 
                                       value="<?= htmlspecialchars($publisher['publisher_contact_name'] ?? '') ?>">
                            </div>

                            <div class="form-group mb-3">
                                <label for="publisher_contact_email" class="form-label"><strong>Contact Email:</strong></label>
                                <input type="email" id="publisher_contact_email" name="publisher_contact_email" 
                                       class="form-control" 
                                       value="<?= htmlspecialchars($publisher['publisher_contact_email'] ?? '') ?>">
                            </div>

                            <div class="text-end">
                                <button type="submit" class="btn btn-primary">Save</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>


    <?php include 'footer.php'; ?>
</body>


</html>
