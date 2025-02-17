<?php

include 'database/db.php';
require 'PublisherController.php';

if (!isset($_GET['id'])) {
    die('Publisher ID not provided.');
}

$deleted = deactivatePublisherById($_GET['id'], $pdo);

if ($deleted) {
    header('Location: publisherspool.php?message=Publisher deleted successfully&type=success');
    exit;
} else {
    header('Location: publisherspool.php?message=Failed to delete publisher&type=error');
    exit;
}
