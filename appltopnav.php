<?php
$path = getEnvPath();
?>

<!-- Navigation bar with logo and links -->
<div class="topnav">

    <a class="logo" href="<?= $path; ?>applmasterview.php"><img src="<?= $path; ?>images/White%20logo%20-%20no%20background.png"></a>
    <div class="nav-links">
        <a href="<?= $path; ?>applmasterview.php">Master View</a>
        <a href="<?= $path; ?>applresources.php">Resources</a>
        <a href="<?= $path; ?>applaccount.php">My Account</a>

        <?php if (isset($_SESSION['acctrole']) && $_SESSION['acctrole'] === 1) { ?>
            <a href="<?= $path; ?>appladmin.php">Add accounts</a>
            <a href="<?= $path; ?>appl_logs.php">Logs</a>
        <?php } ?>

        <a href="<?= $path; ?>appllogout.php">Log Out</a>
    </div>
</div>
<div class="main-content">   