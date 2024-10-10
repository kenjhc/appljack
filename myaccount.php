<?php
include 'database/db.php';

if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php");
    exit();
}

$acctnum = $_SESSION['acctnum']; // Retrieve account number from session

// Initialize error message variable
$errorMessage = "";
$successMessage = "";

// Query the database for user
$query = $db->prepare("SELECT * FROM applacct WHERE acctnum = ?");
if (!$query) {
    handleDbError("Prepare failed: " . $db->error);
}
$query->bind_param("s", $acctnum);
$query->execute();
$result = $query->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
} else {
    setToastMessage('error', "User not found.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get user input
    $acctfname = $_POST['acctfname'] ?? '';
    $acctlname = $_POST['acctlname'] ?? '';
    $acctpw = $_POST['acctpw'] ?? '';

    // Validate user input
    if (empty($acctfname) || empty($acctlname)) {
        $errorMessage = "All fields are required.";
    } else {
        // Update user details in the database
        $updateQuery = $db->prepare("UPDATE applacct SET acctfname = ?, acctlname = ? WHERE acctnum = ?");
        if (!$updateQuery) {
            handleDbError("Prepare failed: " . $db->error);
        }

        $updateQuery->bind_param("ssi", $acctfname, $acctlname, $acctnum);
        if ($updateQuery->execute()) {
            $successMessage = "Account details updated successfully.";
            // Optionally, update session variables
            $_SESSION['acctfname'] = $acctfname;
            $_SESSION['acctlname'] = $acctlname;
            $_SESSION['acctname'] = $acctfname . " " . $acctlname; // Store acctnum in session
        } else {
            $errorMessage = "Error updating account details.";
        }

        $updateQuery->close();
    }
}

$query->close();
$db->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>My Account | Appljack</title>
    <?php include 'header.php'; ?>
</head>

<body>
    <?php include 'appltopnav.php'; ?>

    <?php echo renderHeader("My Account"); ?>

    <section class="job_section">
        <div class="container-fluid">
            <div class="row xml_mapping_sec second">
                <div class="col-sm-12 col-md-12">
                    <div class="add_field_form">
                        <div class="card rounded-md shadow-md">
                            <div class="card-header p-0 d-flex justify-content-between">
                                <h5 class="card-title">Edit Account Details</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($errorMessage): ?>
                                    <div class="alert alert-danger"><?= htmlspecialchars($errorMessage) ?></div>
                                <?php endif; ?>
                                <?php if ($successMessage): ?>
                                    <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
                                <?php endif; ?>
                                <div class="card styled m-4">
                                    <div class="card-body p-0">
                                        <form action="" method="post" class="p-3">
                                            <label for="acctemail" class="healthy-text text-dark-green">Email:</label>
                                            <input type="text" id="acctemail" class="light-input" name="acctemail" value="<?= htmlspecialchars($user['acctemail']) ?>" readonly>

                                            <label for="acctfname" class="healthy-text text-dark-green mt-2">First Name:</label>
                                            <input type="text" id="acctfname" class="light-input" name="acctfname" value="<?= htmlspecialchars($_SESSION['acctfname']) ?>" required>

                                            <label for="acctlname" class="healthy-text text-dark-green mt-2">Last Name:</label>
                                            <input type="text" id="acctlname" class="light-input" name="acctlname" value="<?= htmlspecialchars($_SESSION['acctlname']) ?>" required>

                                            <label for="acctpw" class="healthy-text text-dark-green mt-2">Password:</label>
                                            <input type="password" id="acctpw" class="light-input" name="acctpw" placeholder="Leave blank to keep current password">

                                            <button class="btn_green_dark mt-3 w-100 rounded-md">Update Details</button>
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

    <?php include 'footer.php'; ?>
</body>

</html>