<?php
$path = getEnvPath();
?>
 
<div class="wrapper d-flex align-items-stretch">
    <nav id="sidebar" class="aactive">
        <div class="d-flex flex-column justify-content-between sidebar_wrapper">
            <div>
                <h1><a href="<?= $path; ?>applmasterview.php" class="logo">
                        <img src="images/logo-icon.png" class="logo-sm" alt="Logo">
                        <img src="images/white-logo.png" class="logo-lg" alt="Logo">
                    </a></h1>
                <ul class="list-unstyled components mb-5">
                    <?php if (!isset($_SESSION['acctnum'])) { ?>
                        <li>
                            <a href="<?= $path; ?>appllogin.php"><span class="fa fa-sign-in-alt"></span> <span class="text"> Login </span></a>
                        </li>
                    <?php } else { ?>
                        <li class="active">
                            <a href="<?= $path; ?>applmasterview.php">
                                <span class="fa fa-tachometer-alt"></span>
                                <span class="text">Campaigns Overview </span>
                            </a>
                        </li>
                        <li>
                            <a href="<?= $path; ?>jobinventorypool.php">
                                <span class="fa fa-briefcase"></span>
                                <span class="text">Job Inventory Pool </span>
                            </a>
                        </li>
                        <li>
                            <a href="<?= $path; ?>custaccountspool.php">
                                <span class="fa fa-users"></span>
                                <span class="text">Customer Accounts </span>
                            </a>
                        </li>
                        <li>
                            <a href="<?= $path; ?>publisherspool.php">
                                <span class="fa fa-book"></span>
                                <span class="text">Publishers </span>
                            </a>
                        </li>
                    <?php } ?>

                </ul>
            </div>
            <div class="d-flex flex-column justify-content-between">
                <ul class="list-unstyled components mb-0">

                    <?php if (isset($_SESSION['acctnum'])) { ?>
                        <li>
                            <a href="<?= $path; ?>applresources.php"><span class="fa fa-book"></span> <span class="text">Resources </span></a>
                        </li>
                        <?php if (isset($_SESSION['acctrole']) && $_SESSION['acctrole'] === 1) { ?>
                            <li>
                                <a href="<?= $path; ?>appladmin.php"><span class="fa fa-user-plus"></span> <span class="text"> Add Account </span></a>
                            </li>
                            <li class="active">
                                <a href="<?= $path; ?>appl_logs.php"><span class="fa fa-file-alt"></span> <span class="text"> Logs </span></a>
                            </li>
                        <?php } ?>
                        <li>
                            <a href="<?= $path; ?>applaccount.php"><span class="fa fa-sign-out-alt"></span> <span class="text"> My Account </span></a>
                        </li>
                        <li>
                            <a href="<?= $path; ?>appllogout.php"><span class="fa fa-sign-out-alt"></span> <span class="text"> Logout </span></a>
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