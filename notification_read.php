<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../index.php");
    exit;
}

$me   = (int) $_SESSION["user_id"];
$role = $_SESSION["role"] ?? "admin";

/* =========================================================
   MARK ALL AS READ
========================================================= */
if (!empty($_POST["all"])) {
    $stmt = $pdo->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE user_id = ? AND is_read = 0
    ");
    $stmt->execute([$me]);

    /* Go back to whichever page the user came from */
    $ref = $_SERVER["HTTP_REFERER"] ?? "../index.php";
    header("Location: " . $ref);
    exit;
}

/* =========================================================
   MARK ONE AS READ + REDIRECT
========================================================= */
$notif_id = (int) ($_POST["id"] ?? 0);

if ($notif_id > 0) {
    /* Fetch BEFORE updating so we get the link */
    $stmt = $pdo->prepare("
        SELECT link FROM notifications
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$notif_id, $me]);
    $notif = $stmt->fetch();

    $stmt = $pdo->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$notif_id, $me]);

    $redirect = $notif["link"] ?? "";
} else {
    $redirect = trim($_POST["redirect"] ?? "");
}

/* =========================================================
   BUILD THE FINAL REDIRECT
   - If it's an incident link, rebuild it based on role
   - Otherwise, go back to the page the user came from
========================================================= */

if ($redirect !== "" && preg_match('/id=(\d+)/', $redirect, $m)) {

    $incident_id = (int) $m[1];

    /* Detect the app base from the referer so subfolder installs work */
    $ref    = $_SERVER["HTTP_REFERER"] ?? "";
    $base   = "";

    if (preg_match('#^(.*?)/(admin|officer|technician|shared)/#', $ref, $bm)) {
        $base = $bm[1];          /* e.g. /atm_fault_system */
    }

    $final = match ($role) {
        "technician"      => $base . "/technician/technician.php?action=view&id=" . $incident_id,
        "branch_officer",
        "support_officer" => $base . "/officer/officer.php?action=incident_view&id=" . $incident_id,
        default           => $base . "/admin/incident_view.php?id=" . $incident_id,
    };

    header("Location: " . $final);
    exit;
}

/* Fallback — go back where they came from */
$ref = $_SERVER["HTTP_REFERER"] ?? "../index.php";
header("Location: " . $ref);
exit;