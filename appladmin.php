<?php

include 'database/db.php';


// Establish the connection
try {
    // Ensure the connection is established
    if (!$conn) {
        throw new Exception("Database connection failed.");
    }

    // Handle the form submission
    if ($_SERVER["REQUEST_METHOD"] == "POST") {

        try {
            // Check if the email already exists
            $stmt = $conn->prepare("SELECT acctemail FROM applacct WHERE acctemail = :acctemail");
            $stmt->execute(['acctemail' => $_POST['email']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                setToastMessage('error', 'An account with this email already exists.');
            } else {
                // Securely hash the password
                $hashedPassword = password_hash($_POST['password'], PASSWORD_DEFAULT);


                if (!empty($_POST['existing_account']) && isset($_POST['existing_account'])) {
                    // If an existing account is selected, use that acctnum
                    $acctnum = $_POST['existing_account'];
                } else {
                    do {
                        $acctnum = mt_rand(1000000000, 9999999999);
                        // Check if the acctnum already exists
                        $stmt = $conn->prepare("SELECT acctnum FROM applacct WHERE acctnum = :acctnum");
                        $stmt->execute(['acctnum' => $acctnum]);
                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    } while ($result);
                }

                // Determine acctrole based on is_admin checkbox
                $acctrole = isset($_POST['is_admin']) && $_POST['is_admin'] == '1' ? 1 : 0;

                // Prepare SQL and bind parameters
                $stmt = $conn->prepare("INSERT INTO applacct (acctnum, acctemail, acctpw, acctfname, acctlname, acctrole) 
                                            VALUES (:acctnum, :acctemail, :acctpw, :acctfname, :acctlname, :acctrole)");
                $stmt->bindParam(':acctnum', $acctnum);
                $stmt->bindParam(':acctemail', $_POST['email']);
                $stmt->bindParam(':acctpw', $hashedPassword);
                $stmt->bindParam(':acctfname', $_POST['fname']);
                $stmt->bindParam(':acctlname', $_POST['lname']);
                $stmt->bindParam(':acctrole', $acctrole);

                // Execute the query
                $stmt->execute();

                setToastMessage('success', 'New account created successfully with account number: ' . $acctnum);
            }
        } catch (PDOException $e) {
            setToastMessage('error', 'Error: ' . $e->getMessage());
        }
    }

    // Fetch existing accounts for dropdown
    $stmt = $conn->prepare("SELECT acctnum, acctemail FROM applacct");
    $stmt->execute();
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    setToastMessage('error', 'Error: ' . $e->getMessage());
} finally {
    // Close the connection only when done
    $conn = null;
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Create Account | Appljack</title>
    <?php include 'header.php'; ?>
</head>

<body>
    <?php include 'appltopnav.php'; ?>
    <?php echo renderHeader(
        "Create account"
    ); ?>
    <div class="job_section">
        <div class="row">
            <div class="col-md-8 mx-auto">
                <form method="post">
                    <label for="existingAccount">Existing Accounts:</label><br>
                    <select id="existingAccount" name="existing_account">
                        <option value="">Select an existing account</option>
                        <?php foreach ($accounts as $account): ?>
                            <option value="<?= htmlspecialchars($account['acctnum']) ?>">
                                <?= htmlspecialchars($account['acctemail']) ?> (<?= htmlspecialchars($account['acctnum']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select><br><br>
                    <label for="email">Email:</label><br>
                    <input type="email" id="email" name="email" required><br>
                    <label for="password">Password:</label><br>
                    <input type="password" id="password" name="password" required><br><br>
                    <label for="fname">First Name:</label><br>
                    <input type="text" id="fname" name="fname" required><br><br>
                    <label for="lname">Last Name:</label><br>
                    <input type="text" id="lname" name="lname" required><br><br>
                    <div class="d-flex items-center gap-2">
                        <label for="isAdmin">Is Admin:</label>
                        <input type="checkbox" id="isAdmin" name="is_admin" value="1"><br><br>
                    </div>
                    <button class="btn_green w-100">Create Account</button>
                </form>
            </div>
        </div>
    </div>
    <?php include 'footer.php'; ?>
</body>

</html>