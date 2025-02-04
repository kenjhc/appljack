<?php
function printit($val,  $shouldDie = false)
{
    echo "<pre>";
    print_r($val);
    echo "</pre>";

    if ($shouldDie) {
        die();
    }
}

function getUrl($withPath = 0)
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    return ($protocol . $host) . ($withPath ? getEnvPathUpdated() : '');
}

function setToastMessage($type, $message)
{
    $_SESSION['toast_type'] = $type;
    $_SESSION['toast_message'] = $message;
}

function displayToastMessage()
{
    if (isset($_SESSION['toast_type']) && isset($_SESSION['toast_message'])) {

        $toastType = json_encode($_SESSION['toast_type']);
        $toastMessage = json_encode($_SESSION['toast_message']);

        echo "<script>
                $(document).ready(function() {
                    toastr[$toastType]($toastMessage);
                });
              </script>";
        unset($_SESSION['toast_type']);
        unset($_SESSION['toast_message']);
    }
}

function getEnvPath()
{
    $currentPath = __DIR__;

    if (strpos($currentPath, "admin") !== false) {
        return "/admin/";
    } elseif (strpos($currentPath, "dev") !== false) {
        return "/";
    } elseif (strpos($currentPath, "appljack") !== false) {
        return "/";
    } else {
        return "unknown";
    }
}

function getEnvPathUpdated()
    {
        $currentPath = __DIR__;
        if (strpos($currentPath, "/dev/") !== false) {
            return "/dev/";
        } elseif (strpos($currentPath, "/admin/") !== false) {
            return "/admin/";
        } else {
            return "/dev/";
        }
    }
function renderHeader($pageTitle, $subtitle = '', $notificationCount = 0)
{
    $userName = $_SESSION["acctname"] ?? "N/A";
?>
    <div class="page-heading">
        <h1>
            <?php echo $pageTitle; ?>
            <?php if (!empty($subtitle)): ?>
                <small><?php echo $subtitle; ?></small>
            <?php endif; ?>
        </h1>

        <?php if ($pageTitle !== "Login") { ?>
            <div class="d-flex align-items-center">

                <!-- Notification Button -->
                <div class="notification_wrapper">
                    <button class="notify_btn">
                        <i class="fa-regular fa-bell <?= !$notificationCount ? 'pr-0 p-1' : '' ?>"></i>
                        <?php if ($notificationCount) { ?>
                            <span><?php echo $notificationCount; ?></span>
                        <?php } ?>
                    </button>
                </div>

                <!-- Profile Dropdown -->
                <div class="account dropdown">
                    <button class="btn d-flex align-items-center text-white dropdown-toggle" type="button" id="dropdownMenuButton1" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="profile_img">
                            <img src="images/user.jpg" alt="profile_img">
                        </span>
                        <div class="d-flex flex-column justify-content-start align-items-start">
                            <span class="title"><?php echo $userName; ?></span>
                        </div>
                    </button>
                    <ul class="dropdown-menu" aria-labelledby="dropdownMenuButton1">
                        <!-- <li><a class="dropdown-item" href="#">Profile</a></li>
                    <li><a class="dropdown-item" href="#">Accounts</a></li>
                    <li><a class="dropdown-item" href="#">Users</a></li> -->
                        <li><a class="dropdown-item" href="<?= getEnvPath(); ?>appllogout.php">Logout</a></li>
                    </ul>
                </div>
            </div>
        <?php
        }
        ?>
    </div>
<?php
}

function getAppEnv()
{
    $currentPath = __DIR__;

    if (strpos($currentPath, "admin") !== false) {
        return "PRODUCTION"; // If directory includes 'admin'
    } elseif (strpos($currentPath, "dev") !== false) {
        return "DEVELOPMENT"; // If directory includes 'dev'
    } elseif (strpos($currentPath, "appljack") !== false) {
        return "PRODUCTION"; // Root of the appljack project
    } else {
        return "unknown"; // If none match
    }
}

$envClean = getAppEnv() == "DEVELOPMENT" ? "dev." : "";
$envSuffix = getAppEnv() == "DEVELOPMENT" ? "/dev" : "";