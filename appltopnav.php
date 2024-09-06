<!-- Navigation bar with logo and links -->
<div class="topnav">

    <a class="logo" href="/applmasterview.php"><img src="/images/White%20logo%20-%20no%20background.png"></a>
    <div class="nav-links">
        <a href="/applmasterview.php">Master View</a>
        <a href="/applresources.php">Resources</a>
        <a href="/applaccount.php">My Account</a>

        <?php if (isset($_SESSION['acctrole']) && $_SESSION['acctrole'] === 1) { ?>
            <a href="/appladmin.php">Add accounts</a>
            <a href="/appl_logs.php">Logs</a>
        <?php } ?>

        <a href="/appllogout.php">Log Out</a>
    </div>
</div>
<div class="main-content">