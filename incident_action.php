<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";
require_once "../includes/notify.php";
require_once "../includes/mailer.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: incidents.php");
    exit;
}

$id     = (int) ($_POST["id"] ?? 0);
$action = $_POST["action"] ?? "";

if ($id <= 0) {
    header("Location: incidents.php?error=notfound");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM incidents WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$incident = $stmt->fetch();

if (!$incident) {
    header("Location: incidents.php?error=notfound");
    exit;
}

$user_id    = (int) $_SESSION["user_id"];
$old_status = $incident["status"];
$inc_num    = $incident["incident_number"];

function log_update(PDO $pdo, int $incident_id, int $user_id,
                    ?string $old_status, ?string $new_status, string $text): void
{
    $stmt = $pdo->prepare("
        INSERT INTO incident_updates
            (incident_id, user_id, old_status, status, update_text)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$incident_id, $user_id, $old_status, $new_status, $text]);
}

function fail(int $id, string $code): void
{
    header("Location: incident_view.php?id=" . $id . "&error=" . $code);
    exit;
}

switch ($action) {

    /* =========================================================
       ACKNOWLEDGE — Reported → Under Review
    ========================================================= */
    case "acknowledge":
        if ($old_status !== "reported") fail($id, "invalid_status");

        $comment = trim($_POST["comment"] ?? "") ?: "Incident acknowledged and under review.";

        $pdo->prepare("
            UPDATE incidents
            SET status = 'under_review', acknowledged_at = NOW()
            WHERE id = ?
        ")->execute([$id]);

        log_update($pdo, $id, $user_id, $old_status, "under_review", $comment);

        notify_incident_others(
            $pdo, $id, $user_id,
            "comment",
            "Incident {$inc_num} under review",
            $comment
        );
        break;


       /* =========================================================
       ASSIGN — → Assigned to technician
    ========================================================= */
    case "assign":
        if (!in_array($old_status, ["reported","under_review","assigned","in_progress"], true)) {
            fail($id, "invalid_status");
        }

        $tech_id = (int) ($_POST["assigned_to"] ?? 0);
        if ($tech_id <= 0) fail($id, "no_technician");

        $check = $pdo->prepare("
            SELECT id FROM users
            WHERE id = ? AND role = 'technician' AND status = 'active'
            LIMIT 1
        ");
        $check->execute([$tech_id]);
        if (!$check->fetch()) fail($id, "invalid_technician");

        $comment = trim($_POST["comment"] ?? "") ?: "Assigned to technician.";

        $pdo->prepare("
            UPDATE incidents
            SET status = 'assigned', assigned_to = ?, assigned_at = NOW()
            WHERE id = ?
        ")->execute([$tech_id, $id]);

        log_update($pdo, $id, $user_id, $old_status, "assigned", $comment);

        /* ---------- Notify the technician (in-app) ---------- */
        notify_user(
            $pdo,
            $tech_id,
            "assigned",
            "New incident assigned to you",
            "{$inc_num} — {$incident['title']}",
            $id
        );

        /* ---------- Notify the reporter (in-app) ---------- */
        if (!empty($incident["reported_by"]) && (int) $incident["reported_by"] !== $user_id) {
            notify_user(
                $pdo,
                (int) $incident["reported_by"],
                "assigned",
                "Incident {$inc_num} assigned",
                "A technician has been assigned to your incident.",
                $id
            );
        }

        /* ---------- Send email to the technician ---------- */
        $stmt = $pdo->prepare("
            SELECT u.email, u.full_name, a.atm_code
            FROM users u
            LEFT JOIN incidents i ON i.id = ?
            LEFT JOIN atms a ON a.id = i.atm_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id, $tech_id]);
        $tech_info = $stmt->fetch();

        if ($tech_info && !empty($tech_info["email"])) {
            email_assignment(
                $tech_info["email"],
                $tech_info["full_name"],
                $inc_num,
                $incident["title"],
                $tech_info["atm_code"] ?? "ATM",
                $incident["priority"]
            );
        }
        break;
    /* =========================================================
       START — Assigned → In Progress
    ========================================================= */
    case "start":
        if ($old_status !== "assigned") fail($id, "invalid_status");
        $comment = trim($_POST["comment"] ?? "") ?: "Work started.";

        $pdo->prepare("UPDATE incidents SET status = 'in_progress' WHERE id = ?")
            ->execute([$id]);

        log_update($pdo, $id, $user_id, $old_status, "in_progress", $comment);

        notify_incident_others(
            $pdo, $id, $user_id,
            "comment",
            "Work started on {$inc_num}",
            $comment
        );
        break;

    /* =========================================================
       RESOLVE — In Progress → Resolved
    ========================================================= */
    case "resolve":
        if ($old_status !== "in_progress") fail($id, "invalid_status");

        $resolution = trim($_POST["resolution_notes"] ?? "");
        if ($resolution === "") fail($id, "resolution_required");

        $comment = trim($_POST["comment"] ?? "") ?: "Marked as resolved.";

        $pdo->prepare("
            UPDATE incidents
            SET status = 'resolved', resolved_at = NOW(), resolution_notes = ?
            WHERE id = ?
        ")->execute([$resolution, $id]);

        log_update($pdo, $id, $user_id, $old_status, "resolved", $comment);

        notify_incident_others(
            $pdo, $id, $user_id,
            "resolved",
            "Incident {$inc_num} resolved",
            "Awaiting verification."
        );
        break;

    /* =========================================================
       VERIFY — Resolved → Verified
    ========================================================= */
    case "verify":
        if ($old_status !== "resolved") fail($id, "invalid_status");

        $comment = trim($_POST["comment"] ?? "") ?: "Resolution verified.";

        $pdo->prepare("
            UPDATE incidents
            SET status = 'verified', verified_at = NOW()
            WHERE id = ?
        ")->execute([$id]);

        log_update($pdo, $id, $user_id, $old_status, "verified", $comment);

        notify_incident_others(
            $pdo, $id, $user_id,
            "verified",
            "Incident {$inc_num} verified",
            "Resolution confirmed."
        );
        break;

    /* =========================================================
       CLOSE — Verified → Closed
    ========================================================= */
    case "close":
        if ($old_status !== "verified") fail($id, "invalid_status");

        $comment = trim($_POST["comment"] ?? "") ?: "Incident closed.";

        $pdo->prepare("
            UPDATE incidents
            SET status = 'closed', closed_at = NOW()
            WHERE id = ?
        ")->execute([$id]);

        log_update($pdo, $id, $user_id, $old_status, "closed", $comment);

        notify_incident_others(
            $pdo, $id, $user_id,
            "closed",
            "Incident {$inc_num} closed",
            "Ticket closed."
        );
        break;

    /* =========================================================
       REOPEN — Resolved or Verified → In Progress
    ========================================================= */
    case "reopen":
        if (!in_array($old_status, ["resolved","verified"], true)) {
            fail($id, "invalid_status");
        }

        $comment = trim($_POST["comment"] ?? "") ?: "Reopened — issue not fully resolved.";

        $pdo->prepare("
            UPDATE incidents
            SET status = 'in_progress',
                resolved_at = NULL,
                verified_at = NULL
            WHERE id = ?
        ")->execute([$id]);

        log_update($pdo, $id, $user_id, $old_status, "in_progress", $comment);

        notify_incident_others(
            $pdo, $id, $user_id,
            "reopened",
            "Incident {$inc_num} reopened",
            $comment
        );
        break;

    /* =========================================================
       ADD UPDATE — comment only
    ========================================================= */
    case "add_update":
        $comment = trim($_POST["comment"] ?? "");
        if ($comment === "") fail($id, "empty_comment");

        log_update($pdo, $id, $user_id, null, null, $comment);

        notify_incident_others(
            $pdo, $id, $user_id,
            "comment",
            "New comment on {$inc_num}",
            $comment
        );

        header("Location: incident_view.php?id=" . $id . "&success=update_added");
        exit;

    default:
        header("Location: incidents.php");
        exit;
}

header("Location: incident_view.php?id=" . $id . "&success=action_done");
exit;