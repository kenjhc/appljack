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