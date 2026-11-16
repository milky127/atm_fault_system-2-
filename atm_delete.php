<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}

/* POST only */
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: atms.php");
    exit;
}

$id = (int) ($_POST["id"] ?? 0);

if ($id <= 0) {
    header("Location: atms.php?error=notfound");
    exit;
}

/* Check exists */
$stmt = $pdo->prepare("SELECT atm_code FROM atms WHERE id = ? LIMIT 1");
$stmt->execute([$id]);

if (!$stmt->fetch()) {
    header("Location: atms.php?error=notfound");
    exit;
}

/* Block if any incident is not yet closed */
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM incidents
    WHERE atm_id = ?
      AND status <> 'closed'
");
$stmt->execute([$id]);

if ((int) $stmt->fetchColumn() > 0) {
    header("Location: atms.php?error=open_incidents");
    exit;
}

/* Delete */
try {
    $stmt = $pdo->prepare("DELETE FROM atms WHERE id = ?");
    $stmt->execute([$id]);
} catch (PDOException $e) {
    header("Location: atms.php?error=db");
    exit;
}

header("Location: atms.php?success=deleted");
exit;