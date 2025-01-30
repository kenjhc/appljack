<?php
// PublisherController.php
// Function to fetch publisher details by ID

if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php");
    exit();
}
function getPublisherById($id, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM applpubs WHERE publisherid = ? AND acctnum = ?");
    $stmt->execute([$id, $_SESSION['acctnum']]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Function to update a publisher by ID
function updatePublisher($id, $data, $pdo) {
    $stmt = $pdo->prepare("UPDATE applpubs SET publishername = ?, publisher_contact_name = ?, publisher_contact_email = ? WHERE publisherid = ?");
    $stmt->execute([
        $data['publishername'],
        $data['publisher_contact_name'],
        $data['publisher_contact_email'],
        $id
    ]);
    return $stmt->rowCount();
}

// Function to delete a publisher by ID
function deletePublisherById($id, $pdo) {
    $stmt = $pdo->prepare("DELETE FROM applpubs WHERE publisherid = ?");
    $stmt->execute([$id]);
    return $stmt->rowCount();
}
