<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: users.php");
    exit;
}

$id = (int) ($_POST["id"] ?? 0);

if ($id <= 0) {
    header("Location: users.php?error=notfound");
    exit;
}

if ($id === (int) $_SESSION["user_id"]) {
    header("Location: users.php?error=self_deactivate");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: users.php?error=notfound");
    exit;
}

$new_status = ($user["status"] === "active") ? "inactive" : "active";

/* Guard: last active admin getting deactivated */
if ($user["role"] === "admin" && $new_status === "inactive") {
    $admin_count = (int) $pdo->query("
        SELECT COUNT(*) FROM users
        WHERE role = 'admin' AND status = 'active'
    ")->fetchColumn();

    if ($admin_count <= 1) {
        header("Location: users.php?error=last_admin");
        exit;
    }
}

$stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
$stmt->execute([$new_status, $id]);

$msg = ($new_status === "active") ? "activated" : "deactivated";
header("Location: users.php?success=" . $msg);
exit;