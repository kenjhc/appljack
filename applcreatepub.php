<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'database/db.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php"); // Redirect to login page if not authenticated
    exit();
}

$error = '';
$success = '';
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']); // Clear the error so it doesn't persist
}
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']); // Clear the success message
}

$acctnum = $_SESSION['acctnum'];

// Handle form submission to create a new publisher
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['publishername'])) {
        $publishername = $_POST['publishername'];
        $publisherid = bin2hex(random_bytes(5)); // Generate a random 10-character alphanumeric string

        $stmt = $conn->prepare("INSERT INTO applpubs (publisherid, publishername, acctnum) VALUES (?, ?, ?)");

        if ($stmt->execute([$publisherid, $publishername, $acctnum])) {
            $_SESSION['success'] = "$publishername Created Successfully.";
        } else {
            $_SESSION['error'] = "Error creating publisher.";
        }

        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } else {
        $_SESSION['error'] = "Publisher Name is required.";
    }
}

// Handle deletion of a publisher
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];

    // Fetch the publisher name before deletion for the success message
    $stmt = $conn->prepare("SELECT publishername FROM applpubs WHERE publisherid = ? AND acctnum = ?");
    $stmt->execute([$delete_id, $acctnum]);
    $publishername = $stmt->fetchColumn();

    $stmt = $conn->prepare("DELETE FROM applpubs WHERE publisherid = ? AND acctnum = ?");
    if ($stmt->execute([$delete_id, $acctnum])) {
        $_SESSION['success'] = "$publishername removed.";
    } else {
        $_SESSION['error'] = "Error removing publisher.";
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Fetch current publishers
$publishers = [];
$stmt = $conn->prepare("SELECT publisherid, publishername FROM applpubs WHERE acctnum = ?");
$stmt->execute([$acctnum]);
$publishers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Publisher | Appljack</title>
    <?php include 'header.php'; ?>
</head>
<body>
<?php include 'appltopnav.php'; ?>

<div class="container">
    <div class="left-column">
        <h2>Create a New Publisher</h2>

        <?php if ($error): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <?php if ($success): ?>
            <p class="success"><?php echo htmlspecialchars($success); ?></p>
        <?php endif; ?>

        <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="post">
            <label for="publishername">Publisher Name (required)</label>
            <input type="text" id="publishername" name="publishername" maxlength="255" required><br>

            <input type="submit" value="Create New Publisher">
        </form>
    </div>

    <div class="right-column">
        <h2>Current Publishers</h2>
        <?php if (empty($publishers)): ?>
            <p>No publishers created yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Publisher Name</th>
                        <th>Publisher ID</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($publishers as $publisher): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($publisher['publishername']); ?></td>
                            <td><?php echo htmlspecialchars($publisher['publisherid']); ?></td>
                            <td><a href="<?php echo $_SERVER['PHP_SELF']; ?>?delete_id=<?php echo htmlspecialchars($publisher['publisherid']); ?>" onclick="return confirm('Are you sure you want to delete this publisher?');">Delete</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>
