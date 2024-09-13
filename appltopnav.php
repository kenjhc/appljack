<!-- Navigation bar with logo and links -->
<div class="topnav">

    <a class="logo" href="/admin/applmasterview.php"><img src="/admin/images/White%20logo%20-%20no%20background.png"></a>
    <div class="nav-links">
        <a href="/admin/applmasterview.php">Master View</a>
        <a href="/admin/applresources.php">Resources</a>
        <a href="/admin/applaccount.php">My Account</a>

        <?php if (isset($_SESSION['acctrole']) && $_SESSION['acctrole'] === 1) { ?>
            <a href="/admin/appladmin.php">Add accounts</a>
            <a href="/admin/appl_logs.php">Logs</a>
        <?php } ?>

        <a href="/admin/appllogout.php">Log Out</a>
    </div>
</div>
<div class="main-content">