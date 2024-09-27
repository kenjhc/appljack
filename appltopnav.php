<!-- Navigation bar with logo and links -->
<!-- <div class="topnav">

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
</div> -->

<div class="wrapper d-flex align-items-stretch">
    <nav id="sidebar" class="active">
        <div class="d-flex flex-column justify-content-between sidebar_wrapper">
            <div>
                <h1><a href="applmasterview.php" class="logo">
                        <img src="/images/logo-icon.png" class="logo-sm" alt="Logo">
                        <img src="/images/white-logo.png" class="logo-lg" alt="Logo">
                    </a></h1>
                <ul class="list-unstyled components mb-5">
                    <?php if (!isset($_SESSION['acctnum'])) { ?>
                        <li>
                            <a href="appllogin.php"><span class="fa fa-sign-in-alt"></span> <span class="text"> Login </span></a>
                        </li>
                    <?php } else { ?>
                        <li class="active">
                            <a href="jobportal.php"><span class="fa fa-tachometer-alt"></span> <span class="text">Job Inventory Pool </span></a>
                        </li>
                    <?php } ?>
                </ul>
            </div>
            <div class="d-flex flex-column justify-content-between">
                <ul class="list-unstyled components mb-0">
                    <?php if (isset($_SESSION['acctrole']) && $_SESSION['acctrole'] === 1) { ?>
                        <li>
                            <a href="appladmin.php"><span class="fa fa-user-plus"></span> <span class="text"> Add Account </span></a>
                        </li>
                        <li class="active">
                            <a href="appl_logs.php"><span class="fa fa-file-alt"></span> <span class="text"> Logs </span></a>
                        </li>
                    <?php } ?>
                    <?php if (isset($_SESSION['acctnum'])) { ?>

                        <li >
                            <a href="applmasterview.php"><span class="fa fa-tachometer-alt"></span> <span class="text">Master View </span></a>
                        </li>


                        <li>
                            <a href="applresources.php"><span class="fa fa-book"></span> <span class="text">Resources </span></a>
                        </li>
                        <li>
                            <a href=""><span class="fa fa-sign-out-alt"></span> <span class="text"> My Account </span></a>
                        </li>
                        <li>
                            <a href="appllogout.php"><span class="fa fa-sign-out-alt"></span> <span class="text"> Logout </span></a>
                        </li>
                    <?php } ?>
                </ul>
            </div>
        </div>
        <button type="button" id="sidebarCollapse" class="btn btn-primary">
            <i class="fa fa-bars"></i>
            <span class="sr-only">Toggle Menu</span>
        </button>
    </nav>


    <div id="content" class="">