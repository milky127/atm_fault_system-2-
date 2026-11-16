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
    header("Location: users.php?error=self_delete");
    exit;
}

/* Load user */
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: users.php?error=notfound");
    exit;
}

/* Guard: last active admin */
if ($user["role"] === "admin" && $user["status"] === "active") {
    $admin_count = (int) $pdo->query("
        SELECT COUNT(*) FROM users
        WHERE role = 'admin' AND status = 'active'
    ")->fetchColumn();

    if ($admin_count <= 1) {
        header("Location: users.php?error=last_admin");
        exit;
    }
}

/* Guard: user has assigned or reported incidents */
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM incidents
    WHERE assigned_to = ? OR reported_by = ?
");
$stmt->execute([$id, $id]);
$in_use = (int) $stmt->fetchColumn();

if ($in_use > 0) {
    header("Location: users.php?error=in_use");
    exit;
}

/* Delete */
try {
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);
} catch (PDOException $e) {
    header("Location: users.php?error=db");
    exit;
}

header("Location: users.php?success=deleted");
exit;