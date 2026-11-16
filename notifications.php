<?php
require_once "../includes/auth_check.php";

if ($_SESSION["role"] !== "technician") {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../includes/notifications_page.php";