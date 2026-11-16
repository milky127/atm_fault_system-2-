<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";

if ($_SESSION["role"] !== "technician") {
    header("Location: ../auth/login.php");
    exit;
}

$me     = (int) $_SESSION["user_id"];
$action = $_GET["action"] ?? "dashboard";
$id     = (int) ($_GET["id"] ?? 0);

/* =========================================================
   POST HANDLERS
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $post_action = $_POST["action"] ?? "";
    $post_id     = (int) ($_POST["id"] ?? 0);

    /* =========================================================
       PROFILE / PASSWORD (settings) — handled separately
    ========================================================= */

    if ($post_action === "update_profile") {

        $full_name = trim($_POST["full_name"] ?? "");
        $username  = strtolower(trim($_POST["username"] ?? ""));
        $phone     = trim($_POST["phone"] ?? "");

        $errs = [];

        if ($full_name === "") $errs[] = "Full name is required.";

        if ($username === "") {
            $errs[] = "Username is required.";
        } elseif (!preg_match('/^[a-z0-9_.]{3,50}$/', $username)) {
            $errs[] = "Username must be 3–50 lowercase letters, digits, dot or underscore.";
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
            $stmt->execute([$username, $me]);
            if ($stmt->fetch()) $errs[] = "That username is already taken.";
        }

        if (empty($errs)) {
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, username = ?, phone = ? WHERE id = ?");
            $stmt->execute([$full_name, $username, $phone !== "" ? $phone : null, $me]);

            $_SESSION["full_name"] = $full_name;
            $_SESSION["username"]  = $username;

            header("Location: technician.php?action=settings&success=profile");
            exit;
        }

        /* Validation errors — fall through and show them in the view */
        $settings_errors = $errs;

        header("Location: technician.php?action=settings&error=validation");
        $_SESSION["tech_settings_errors"] = $errs;
        exit;
    }

    if ($post_action === "update_password") {

        $current = $_POST["current_password"] ?? "";
        $new     = $_POST["new_password"]     ?? "";
        $confirm = $_POST["confirm_password"] ?? "";

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$me]);
        $hash = $stmt->fetchColumn();

        $errs = [];

        if ($current === "") {
            $errs[] = "Current password is required.";
        } elseif (!password_verify($current, $hash)) {
            $errs[] = "Current password is incorrect.";
        }

        if (strlen($new) < 8) {
            $errs[] = "New password must be at least 8 characters.";
        } elseif ($new !== $confirm) {
            $errs[] = "New passwords do not match.";
        } elseif ($new === $current) {
            $errs[] = "New password must be different from the current one.";
        }

        if (empty($errs)) {
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $me]);

            header("Location: technician.php?action=settings&success=password");
            exit;
        }

        $_SESSION["tech_settings_errors"] = $errs;
        header("Location: technician.php?action=settings&error=validation");
        exit;
    }

    if ($post_id <= 0) {
        header("Location: technician.php?action=incidents");
        exit;
    }

    /* Verify this incident is assigned to me */
    $stmt = $pdo->prepare("
        SELECT * FROM incidents
        WHERE id = ? AND assigned_to = ?
        LIMIT 1
    ");
    $stmt->execute([$post_id, $me]);
    $incident = $stmt->fetch();

    if (!$incident) {
        header("Location: technician.php?action=incidents");
        exit;
    }

    $old_status = $incident["status"];

    $log = function (string $text, ?string $new_status = null) use ($pdo, $post_id, $me, $old_status) {
        $stmt = $pdo->prepare("
            INSERT INTO incident_updates
                (incident_id, user_id, old_status, status, update_text)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$post_id, $me, $old_status, $new_status, $text]);
    };

    $fail = function (string $code) use ($post_id) {
        header("Location: technician.php?action=view&id=" . $post_id . "&error=" . $code);
        exit;
    };

    switch ($post_action) {

        case "start":
            if ($old_status !== "assigned") $fail("invalid_status");

            $comment = trim($_POST["comment"] ?? "") ?: "Work started.";

            $pdo->prepare("UPDATE incidents SET status = 'in_progress' WHERE id = ?")
                ->execute([$post_id]);

                       require_once "../includes/notify.php";

            $inc_stmt = $pdo->prepare("SELECT incident_number FROM incidents WHERE id = ? LIMIT 1");
            $inc_stmt->execute([$post_id]);
            $inc_num = $inc_stmt->fetchColumn() ?: "Incident";

            notify_admins($pdo, "comment", "Work started on {$inc_num}", $comment, $post_id);
            notify_incident_others($pdo, $post_id, $me, "comment", "Work started on {$inc_num}", $comment);
        case "resolve":
            if ($old_status !== "in_progress") $fail("invalid_status");

            $resolution = trim($_POST["resolution_notes"] ?? "");
            if ($resolution === "") $fail("resolution_required");

            $comment = trim($_POST["comment"] ?? "") ?: "Marked as resolved.";

            $pdo->prepare("
                UPDATE incidents
                SET status = 'resolved', resolved_at = NOW(), resolution_notes = ?
                WHERE id = ?
            ")->execute([$resolution, $post_id]);

                        require_once "../includes/notify.php";

            $inc_stmt = $pdo->prepare("SELECT incident_number FROM incidents WHERE id = ? LIMIT 1");
            $inc_stmt->execute([$post_id]);
            $inc_num = $inc_stmt->fetchColumn() ?: "Incident";

            notify_admins(
                $pdo, "resolved",
                "Incident {$inc_num} resolved",
                "Awaiting verification. Technician notes: " . substr($resolution, 0, 100),
                $post_id
            );
            notify_incident_others(
                $pdo, $post_id, $me,
                "resolved",
                "Incident {$inc_num} resolved",
                "Awaiting verification.",
                ""
            );

        case "add_update":
            $comment = trim($_POST["comment"] ?? "");
            if ($comment === "") $fail("empty_comment");

                     require_once "../includes/notify.php";

            $inc_stmt = $pdo->prepare("SELECT incident_number FROM incidents WHERE id = ? LIMIT 1");
            $inc_stmt->execute([$post_id]);
            $inc_num = $inc_stmt->fetchColumn() ?: "Incident";

            notify_admins($pdo, "comment", "Update on {$inc_num}", $comment, $post_id);
            notify_incident_others($pdo, $post_id, $me, "comment", "Update on {$inc_num}", $comment);

        default:
            header("Location: technician.php");
            exit;
    }
}

/* =========================================================
   PRESENTATION HELPERS
========================================================= */
/* =========================================================
   SETTINGS VIEW DATA
========================================================= */

if ($action === "settings") {

    $stmt = $pdo->prepare("
        SELECT u.*, b.branch_name
        FROM users u
        LEFT JOIN branches b ON u.branch_id = b.id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$me]);
    $me_user = $stmt->fetch();

    $settings_errors = $_SESSION["tech_settings_errors"] ?? [];
    unset($_SESSION["tech_settings_errors"]);
}
$status_badges = [
    "reported"     => ["#8E1B8F", "#fff"],
    "under_review" => ["#0D6EFD", "#fff"],
    "assigned"     => ["#D4AF37", "#252525"],
    "in_progress"  => ["#E0A800", "#252525"],
    "resolved"     => ["#198754", "#fff"],
    "verified"     => ["#0DCAF0", "#252525"],
    "closed"       => ["#495057", "#fff"],
];

$priority_badges = [
    "low"      => "bg-secondary",
    "medium"   => "bg-info text-dark",
    "high"     => "bg-warning text-dark",
    "critical" => "bg-danger",
];

$success = $_GET["success"] ?? "";
$error   = $_GET["error"]   ?? "";

/* =========================================================
   DATA PER VIEW
========================================================= */

/* ---- Dashboard stats ---- */
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'assigned'    THEN 1 ELSE 0 END) AS assigned,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
        SUM(CASE WHEN status IN ('resolved','verified') THEN 1 ELSE 0 END) AS awaiting
    FROM incidents
    WHERE assigned_to = ?
");
$stmt->execute([$me]);
$stats = $stmt->fetch();

$total_assigned = (int) $stats["total"];
$count_assigned = (int) $stats["assigned"];
$count_progress = (int) $stats["in_progress"];
$count_awaiting = (int) $stats["awaiting"];

/* ---- Active incidents (dashboard) ---- */
$stmt = $pdo->prepare("
    SELECT
        i.id, i.incident_number, i.title, i.priority, i.status, i.reported_at,
        a.atm_code, a.location AS atm_location,
        b.branch_name, fc.category_name
    FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    LEFT  JOIN branches b ON a.branch_id = b.id
    LEFT  JOIN fault_categories fc ON i.category_id = fc.id
    WHERE i.assigned_to = ?
      AND i.status IN ('assigned','in_progress')
    ORDER BY
        FIELD(i.priority,'critical','high','medium','low'),
        i.reported_at ASC
");
$stmt->execute([$me]);
$active = $stmt->fetchAll();

/* ---- Recently completed (dashboard) ---- */
$stmt = $pdo->prepare("
    SELECT i.id, i.incident_number, i.title, i.status, i.resolved_at, a.atm_code
    FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    WHERE i.assigned_to = ?
      AND i.status IN ('resolved','verified','closed')
    ORDER BY i.resolved_at DESC
    LIMIT 5
");
$stmt->execute([$me]);
$recent = $stmt->fetchAll();

/* ---- List view ---- */
$status_filter   = $_GET["status"]   ?? "";
$priority_filter = $_GET["priority"] ?? "";

$valid_statuses   = ["assigned","in_progress","resolved","verified","closed"];
$valid_priorities = ["low","medium","high","critical"];

$sql = "SELECT
            i.id, i.incident_number, i.title, i.priority, i.status,
            i.reported_at, i.resolved_at,
            a.atm_code, a.location AS atm_location,
            b.branch_name, fc.category_name
        FROM incidents i
        INNER JOIN atms a ON i.atm_id = a.id
        LEFT  JOIN branches b ON a.branch_id = b.id
        LEFT  JOIN fault_categories fc ON i.category_id = fc.id
        WHERE i.assigned_to = ?";
$params = [$me];

if (in_array($status_filter, $valid_statuses, true)) {
    $sql .= " AND i.status = ?";
    $params[] = $status_filter;
}
if (in_array($priority_filter, $valid_priorities, true)) {
    $sql .= " AND i.priority = ?";
    $params[] = $priority_filter;
}

$sql .= " ORDER BY
            FIELD(i.status,'in_progress','assigned','resolved','verified','closed'),
            FIELD(i.priority,'critical','high','medium','low'),
            i.reported_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$incidents = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT status, COUNT(*) AS total
    FROM incidents
    WHERE assigned_to = ?
    GROUP BY status
");
$stmt->execute([$me]);
$counts = ["assigned"=>0, "in_progress"=>0, "resolved"=>0, "verified"=>0, "closed"=>0];
foreach ($stmt->fetchAll() as $r) {
    $counts[$r["status"]] = (int) $r["total"];
}

/* ---- View incident ---- */
if ($action === "view") {
    if ($id <= 0) { header("Location: technician.php?action=incidents"); exit; }

    $stmt = $pdo->prepare("
        SELECT i.*,
               a.atm_code, a.location AS atm_location, a.model,
               b.branch_name,
               fc.category_name,
               u.full_name AS reporter_name
        FROM incidents i
        INNER JOIN atms a ON i.atm_id = a.id
        LEFT  JOIN branches b ON a.branch_id = b.id
        LEFT  JOIN fault_categories fc ON i.category_id = fc.id
        LEFT  JOIN users u ON i.reported_by = u.id
        WHERE i.id = ? AND i.assigned_to = ?
        LIMIT 1
    ");
    $stmt->execute([$id, $me]);
    $incident = $stmt->fetch();

    if (!$incident) { header("Location: technician.php?action=incidents"); exit; }

    $stmt = $pdo->prepare("
        SELECT iu.*, u.full_name AS user_name
        FROM incident_updates iu
        LEFT JOIN users u ON iu.user_id = u.id
        WHERE iu.incident_id = ?
        ORDER BY iu.created_at DESC
    ");
    $stmt->execute([$id]);
    $updates = $stmt->fetchAll();
}

/* =========================================================
   PAGE TITLE
========================================================= */

$active_page = ($action === "view" || $action === "incidents") ? "incidents" : "dashboard";
$page_title  = match ($action) {
    "incidents" => "My Incidents",
    "view"      => $incident["incident_number"] ?? "Incident",
    default     => "Technician Dashboard",
};

require_once "../includes/header.php";
require_once "../includes/sidebar.php";
?>


<?php /* =========================================================
       DASHBOARD
   ========================================================= */ ?>

<?php if ($action === "dashboard"): ?>


<div class="page-heading">
    <div>
        <h2>Welcome, <?= htmlspecialchars($_SESSION["full_name"]) ?></h2>
        <p>Here are your assigned incidents and today's priorities.</p>
    </div>
    <div>
        <a href="technician.php?action=incidents" class="btn btn-gold">
            <i class="bi bi-list-check me-2"></i> All My Incidents
        </a>
    </div>
</div>


<?php if ($success !== ""): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?= match ($success) {
            "started"      => "Work started. Incident marked In Progress.",
            "resolved"     => "Incident marked Resolved. Awaiting verification.",
            "update_added" => "Update added.",
            default        => "Done.",
        } ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error !== ""): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-circle-fill me-2"></i>
        <?= match ($error) {
            "invalid_status"      => "That action is not allowed for this status.",
            "resolution_required" => "Resolution notes are required.",
            "empty_comment"       => "Update text cannot be empty.",
            default               => "Something went wrong.",
        } ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>


<div class="row g-4 mb-4">

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>TOTAL ASSIGNED</p>
                <h3><?= $total_assigned ?></h3>
                <span>All time</span>
            </div>
            <div class="stat-icon purple-icon"><i class="bi bi-list-check"></i></div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>WAITING</p>
                <h3><?= $count_assigned ?></h3>
                <span class="gold-text"><i class="bi bi-hourglass"></i> Ready to start</span>
            </div>
            <div class="stat-icon gold-icon"><i class="bi bi-hourglass-split"></i></div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>IN PROGRESS</p>
                <h3><?= $count_progress ?></h3>
                <span class="danger-text"><i class="bi bi-wrench"></i> Active work</span>
            </div>
            <div class="stat-icon red-icon"><i class="bi bi-wrench-adjustable"></i></div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>AWAITING VERIFY</p>
                <h3><?= $count_awaiting ?></h3>
                <span class="success-text"><i class="bi bi-check-circle"></i> Resolved</span>
            </div>
            <div class="stat-icon green-icon"><i class="bi bi-check2-circle"></i></div>
        </div>
    </div>

</div>


<div class="dashboard-card mb-4">
    <div class="card-header-custom">
        <div>
            <h5>My Active Incidents</h5>
            <p><?= count($active) ?> incident<?= count($active) === 1 ? "" : "s" ?> needing attention</p>
        </div>
        <a href="technician.php?action=incidents" class="view-all">View All</a>
    </div>

    <?php if (count($active) > 0): ?>
        <div class="table-responsive">
            <table class="table atm-table align-middle">
                <thead>
                    <tr>
                        <th>Incident</th>
                        <th>ATM</th>
                        <th>Category</th>
                        <th>Priority</th>
                        <th>Reported</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($active as $inc): ?>
                    <?php
                    [$sb_bg, $sb_fg] = $status_badges[$inc["status"]] ?? ["#6c757d", "#fff"];
                    $pb_class        = $priority_badges[$inc["priority"]] ?? "bg-secondary";
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($inc["incident_number"]) ?></strong>
                            <br><small class="text-muted"><?= htmlspecialchars($inc["title"]) ?></small>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($inc["atm_code"]) ?></strong>
                            <br><small class="text-muted"><?= htmlspecialchars($inc["atm_location"]) ?></small>
                        </td>
                        <td><?= htmlspecialchars($inc["category_name"] ?? "—") ?></td>
                        <td><span class="badge <?= $pb_class ?>"><?= ucfirst($inc["priority"]) ?></span></td>
                        <td><?= htmlspecialchars($inc["reported_at"]) ?></td>
                        <td>
                            <span class="badge" style="background:<?= $sb_bg ?>; color:<?= $sb_fg ?>;">
                                <?= ucfirst(str_replace("_", " ", $inc["status"])) ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <a href="technician.php?action=view&id=<?= (int) $inc["id"] ?>"
                               class="btn btn-sm btn-gold">
                                <i class="bi bi-arrow-right"></i> Open
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state py-5">
            <i class="bi bi-check2-all"></i>
            <p>No active incidents. You're all caught up.</p>
        </div>
    <?php endif; ?>
</div>


<div class="dashboard-card">
    <div class="card-header-custom">
        <div><h5>Recently Completed</h5><p>Your last 5 resolutions</p></div>
    </div>

    <?php if (count($recent) > 0): ?>
        <div class="table-responsive">
            <table class="table atm-table align-middle">
                <thead>
                    <tr><th>Incident</th><th>ATM</th><th>Resolved</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $r): ?>
                    <?php [$sb_bg, $sb_fg] = $status_badges[$r["status"]] ?? ["#6c757d", "#fff"]; ?>
                    <tr>
                        <td>
                            <a href="technician.php?action=view&id=<?= (int) $r["id"] ?>">
                                <strong><?= htmlspecialchars($r["incident_number"]) ?></strong>
                            </a>
                            <br><small class="text-muted"><?= htmlspecialchars($r["title"]) ?></small>
                        </td>
                        <td><?= htmlspecialchars($r["atm_code"]) ?></td>
                        <td><?= htmlspecialchars($r["resolved_at"] ?? "—") ?></td>
                        <td>
                            <span class="badge" style="background:<?= $sb_bg ?>; color:<?= $sb_fg ?>;">
                                <?= ucfirst(str_replace("_", " ", $r["status"])) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state py-5"><i class="bi bi-inbox"></i><p>No completed incidents yet.</p></div>
    <?php endif; ?>
</div>


<?php /* =========================================================
       INCIDENT LIST
   ========================================================= */ ?>

<?php elseif ($action === "incidents"): ?>


<div class="page-heading">
    <div>
        <h2>My Incidents</h2>
        <p>All incidents assigned to you.</p>
    </div>
    <div>
        <a href="technician.php" class="btn btn-outline-purple">
            <i class="bi bi-arrow-left me-2"></i> Dashboard
        </a>
    </div>
</div>


<div class="row g-4 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>WAITING</p><h3><?= $counts["assigned"] ?></h3>
                <span class="gold-text"><i class="bi bi-hourglass"></i> Ready to start</span>
            </div>
            <div class="stat-icon gold-icon"><i class="bi bi-hourglass-split"></i></div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>IN PROGRESS</p><h3><?= $counts["in_progress"] ?></h3>
                <span class="danger-text"><i class="bi bi-wrench"></i> Active</span>
            </div>
            <div class="stat-icon red-icon"><i class="bi bi-wrench-adjustable"></i></div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>RESOLVED</p><h3><?= $counts["resolved"] + $counts["verified"] ?></h3>
                <span class="success-text"><i class="bi bi-check-circle"></i> Awaiting close</span>
            </div>
            <div class="stat-icon green-icon"><i class="bi bi-check2-circle"></i></div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>CLOSED</p><h3><?= $counts["closed"] ?></h3><span>Completed</span>
            </div>
            <div class="stat-icon purple-icon"><i class="bi bi-archive"></i></div>
        </div>
    </div>
</div>


<div class="dashboard-card mb-4">
    <div class="card-header-custom">
        <div><h5>Filter</h5><p>Narrow down your incidents</p></div>
    </div>

    <form method="GET" action="technician.php" class="row g-3">
        <input type="hidden" name="action" value="incidents">

        <div class="col-md-5">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">All statuses</option>
                <option value="assigned"    <?= $status_filter === "assigned"    ? "selected" : "" ?>>Assigned</option>
                <option value="in_progress" <?= $status_filter === "in_progress" ? "selected" : "" ?>>In Progress</option>
                <option value="resolved"    <?= $status_filter === "resolved"    ? "selected" : "" ?>>Resolved</option>
                <option value="verified"    <?= $status_filter === "verified"    ? "selected" : "" ?>>Verified</option>
                <option value="closed"      <?= $status_filter === "closed"      ? "selected" : "" ?>>Closed</option>
            </select>
        </div>

        <div class="col-md-5">
            <label class="form-label">Priority</label>
            <select name="priority" class="form-select">
                <option value="">All priorities</option>
                <option value="low"      <?= $priority_filter === "low"      ? "selected" : "" ?>>Low</option>
                <option value="medium"   <?= $priority_filter === "medium"   ? "selected" : "" ?>>Medium</option>
                <option value="high"     <?= $priority_filter === "high"     ? "selected" : "" ?>>High</option>
                <option value="critical" <?= $priority_filter === "critical" ? "selected" : "" ?>>Critical</option>
            </select>
        </div>

        <div class="col-md-2 d-flex align-items-end gap-2">
            <a href="technician.php?action=incidents" class="btn btn-outline-secondary flex-fill">Clear</a>
            <button type="submit" class="btn btn-gold flex-fill"><i class="bi bi-funnel"></i></button>
        </div>
    </form>
</div>


<div class="dashboard-card">
    <div class="card-header-custom">
        <div>
            <h5>Assigned Incidents</h5>
            <p><?= count($incidents) ?> incident<?= count($incidents) === 1 ? "" : "s" ?></p>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table atm-table align-middle">
            <thead>
                <tr>
                    <th>Incident</th>
                    <th>ATM</th>
                    <th>Category</th>
                    <th>Priority</th>
                    <th>Reported</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (count($incidents) > 0): ?>
                <?php foreach ($incidents as $inc): ?>
                    <?php
                    [$sb_bg, $sb_fg] = $status_badges[$inc["status"]] ?? ["#6c757d", "#fff"];
                    $pb_class        = $priority_badges[$inc["priority"]] ?? "bg-secondary";
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($inc["incident_number"]) ?></strong>
                            <br><small class="text-muted"><?= htmlspecialchars($inc["title"]) ?></small>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($inc["atm_code"]) ?></strong>
                            <br><small class="text-muted"><?= htmlspecialchars($inc["atm_location"]) ?></small>
                        </td>
                        <td><?= htmlspecialchars($inc["category_name"] ?? "—") ?></td>
                        <td><span class="badge <?= $pb_class ?>"><?= ucfirst($inc["priority"]) ?></span></td>
                        <td><?= htmlspecialchars($inc["reported_at"]) ?></td>
                        <td>
                            <span class="badge" style="background:<?= $sb_bg ?>; color:<?= $sb_fg ?>;">
                                <?= ucfirst(str_replace("_", " ", $inc["status"])) ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <a href="technician.php?action=view&id=<?= (int) $inc["id"] ?>"
                               class="btn btn-sm btn-gold"><i class="bi bi-arrow-right"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7" class="text-center py-5">
                    <div class="empty-state"><i class="bi bi-inbox"></i>
                        <p>No incidents match your filters.</p></div>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>


<?php /* =========================================================
       SINGLE INCIDENT VIEW
   ========================================================= */ ?>

<?php elseif ($action === "view" && isset($incident)): ?>

<?php
[$sb_bg, $sb_fg] = $status_badges[$incident["status"]] ?? ["#6c757d", "#fff"];
$pb_class        = $priority_badges[$incident["priority"]] ?? "bg-secondary";
?>


<div class="page-heading">
    <div>
        <h2>
            <?= htmlspecialchars($incident["incident_number"]) ?>
            <span class="badge ms-2" style="background:<?= $sb_bg ?>; color:<?= $sb_fg ?>;">
                <?= ucfirst(str_replace("_", " ", $incident["status"])) ?>
            </span>
        </h2>
        <p><?= htmlspecialchars($incident["title"]) ?></p>
    </div>
    <div>
        <a href="technician.php?action=incidents" class="btn btn-outline-purple">
            <i class="bi bi-arrow-left me-2"></i> Back
        </a>
    </div>
</div>


<?php if ($success !== ""): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?= match ($success) {
            "started"      => "Work started. Incident marked In Progress.",
            "resolved"     => "Incident marked Resolved. Awaiting verification.",
            "update_added" => "Update added.",
            default        => "Done.",
        } ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error !== ""): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-circle-fill me-2"></i>
        <?= match ($error) {
            "invalid_status"      => "That action is not allowed for this status.",
            "resolution_required" => "Resolution notes are required.",
            "empty_comment"       => "Update text cannot be empty.",
            default               => "Something went wrong.",
        } ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>


<div class="row g-4 mb-4">

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>PRIORITY</p>
                <h3 style="font-size:1.3rem;">
                    <span class="badge <?= $pb_class ?>"><?= ucfirst($incident["priority"]) ?></span>
                </h3>
                <span>Severity</span>
            </div>
            <div class="stat-icon red-icon"><i class="bi bi-flag-fill"></i></div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>REPORTED</p>
                <h3 style="font-size:1.1rem;"><?= htmlspecialchars($incident["reported_at"]) ?></h3>
                <span>by <?= htmlspecialchars($incident["reporter_name"] ?? "—") ?></span>
            </div>
            <div class="stat-icon purple-icon"><i class="bi bi-clock-history"></i></div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>CATEGORY</p>
                <h3 style="font-size:1.1rem;"><?= htmlspecialchars($incident["category_name"] ?? "—") ?></h3>
                <span>Fault type</span>
            </div>
            <div class="stat-icon green-icon"><i class="bi bi-tag-fill"></i></div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>BRANCH</p>
                <h3 style="font-size:1.1rem;">
                    <?= $incident["branch_name"]
                        ? htmlspecialchars($incident["branch_name"])
                        : '<span class="text-muted">—</span>' ?>
                </h3>
                <span>Location</span>
            </div>
            <div class="stat-icon gold-icon"><i class="bi bi-geo-alt-fill"></i></div>
        </div>
    </div>

</div>


<div class="row g-4 mb-4">

    <div class="col-xl-7">
        <div class="dashboard-card h-100">
            <div class="card-header-custom">
                <div><h5>Incident Details</h5><p>Full information</p></div>
            </div>

            <table class="table mb-0">
                <tbody>
                    <tr><th style="width:35%;">ATM</th>
                        <td>
                            <strong><?= htmlspecialchars($incident["atm_code"]) ?></strong>
                            — <?= htmlspecialchars($incident["atm_location"]) ?>
                            <?php if (!empty($incident["model"])): ?>
                                <br><small class="text-muted">Model: <?= htmlspecialchars($incident["model"]) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr><th>Branch</th>
                        <td><?= $incident["branch_name"]
                            ? htmlspecialchars($incident["branch_name"])
                            : '<span class="text-muted">—</span>' ?></td>
                    </tr>
                    <tr><th>Category</th><td><?= htmlspecialchars($incident["category_name"] ?? "—") ?></td></tr>
                    <tr><th>Priority</th>
                        <td><span class="badge <?= $pb_class ?>"><?= ucfirst($incident["priority"]) ?></span></td>
                    </tr>
                    <tr><th>Reported</th>
                        <td><?= htmlspecialchars($incident["reported_at"]) ?>
                            by <strong><?= htmlspecialchars($incident["reporter_name"] ?? "—") ?></strong></td>
                    </tr>
                </tbody>
            </table>

            <div class="card-header-custom" style="border-top:1px solid #eee;">
                <div><h5>Description</h5></div>
            </div>
            <p style="white-space:pre-wrap;"><?= htmlspecialchars($incident["description"]) ?></p>
        </div>
    </div>


    <div class="col-xl-5">
        <div class="dashboard-card h-100">
            <div class="card-header-custom">
                <div><h5>Actions</h5><p>Available steps</p></div>
            </div>

            <?php $st = $incident["status"]; ?>

            <?php if ($st === "assigned"): ?>
                <form method="POST" action="technician.php" class="mb-3">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="start">

                    <label class="form-label">Comment (optional)</label>
                    <textarea name="comment" class="form-control mb-2" rows="2"
                              placeholder="Starting diagnostics…"></textarea>

                    <button type="submit" class="btn btn-gold w-100">
                        <i class="bi bi-play-fill me-1"></i> Start Work
                    </button>
                </form>

            <?php elseif ($st === "in_progress"): ?>
                <form method="POST" action="technician.php">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="resolve">

                    <label class="form-label">Resolution Notes <span class="text-danger">*</span></label>
                    <textarea name="resolution_notes" class="form-control mb-2" rows="4"
                              placeholder="Describe what you did to fix the issue…" required></textarea>

                    <label class="form-label">Comment (optional)</label>
                    <textarea name="comment" class="form-control mb-2" rows="2"></textarea>

                    <button type="submit" class="btn btn-gold w-100">
                        <i class="bi bi-check2-circle me-1"></i> Mark Resolved
                    </button>
                </form>

            <?php elseif ($st === "resolved"): ?>
                <div class="alert alert-info mb-0">
                    <i class="bi bi-hourglass-split me-1"></i>
                    Awaiting verification by an administrator.
                </div>

            <?php elseif ($st === "verified"): ?>
                <div class="alert alert-success mb-0">
                    <i class="bi bi-shield-check me-1"></i>
                    Verified. Waiting to be closed by admin.
                </div>

            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-check2-all"></i>
                    <p>This incident is closed.</p>
                </div>
            <?php endif; ?>


            <?php if ($st !== "closed"): ?>
                <hr>

                <form method="POST" action="technician.php">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="add_update">

                    <label class="form-label fw-semibold">Add Progress Update</label>
                    <textarea name="comment" class="form-control mb-2" rows="2"
                              placeholder="Short note — no status change…" required></textarea>

                    <button type="submit" class="btn btn-outline-purple w-100">
                        <i class="bi bi-chat-left-text me-1"></i> Add Update
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

</div>


<div class="dashboard-card">
    <div class="card-header-custom">
        <div><h5>Activity Timeline</h5><p>Every action taken on this incident</p></div>
    </div>

    <?php if (count($updates) > 0): ?>
        <div class="list-group list-group-flush">

            <div class="list-group-item">
                <div class="d-flex gap-3">
                    <div class="timeline-icon" style="
                        width:36px;height:36px;border-radius:50%;
                        background:#8E1B8F;color:#fff;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="bi bi-flag-fill"></i>
                    </div>
                    <div>
                        <strong>Incident reported</strong>
                        <div class="text-muted small">
                            <?= htmlspecialchars($incident["reported_at"]) ?>
                            by <?= htmlspecialchars($incident["reporter_name"] ?? "—") ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php foreach ($updates as $u): ?>
                <?php
                [$b_bg, $b_fg] = $u["status"]
                    ? ($status_badges[$u["status"]] ?? ["#6c757d", "#fff"])
                    : ["#6c757d", "#fff"];
                ?>
                <div class="list-group-item">
                    <div class="d-flex gap-3">
                        <div class="timeline-icon" style="
                            width:36px;height:36px;border-radius:50%;
                            background:var(--cbe-gold,#D4AF37);color:#252525;
                            display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi bi-arrow-repeat"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <strong><?= htmlspecialchars($u["user_name"] ?? "System") ?></strong>
                                <small class="text-muted"><?= htmlspecialchars($u["created_at"]) ?></small>
                            </div>
                            <?php if ($u["old_status"] && $u["status"]): ?>
                                <div class="mt-1 small">
                                    <span class="text-muted">
                                        <?= ucfirst(str_replace("_", " ", $u["old_status"])) ?>
                                    </span>
                                    →
                                    <span class="badge" style="background:<?= $b_bg ?>; color:<?= $b_fg ?>;">
                                        <?= ucfirst(str_replace("_", " ", $u["status"])) ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="mt-1" style="white-space:pre-wrap;"><?= htmlspecialchars($u["update_text"]) ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state py-5"><i class="bi bi-clock-history"></i><p>No activity recorded yet.</p></div>
    <?php endif; ?>
</div>


<?php /* =========================================================
       SETTINGS
   ========================================================= */ ?>

<?php elseif ($action === "settings"): ?>


<div class="page-heading">
    <div>
        <h2>Settings</h2>
        <p>Update your account details and password.</p>
    </div>
    <div>
        <a href="technician.php" class="btn btn-outline-purple">
            <i class="bi bi-arrow-left me-2"></i> Back to Dashboard
        </a>
    </div>
</div>


<?php if ($success !== ""): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?= match ($success) {
            "profile"  => "Profile updated successfully.",
            "password" => "Password changed successfully.",
            default    => "Settings saved.",
        } ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($settings_errors)): ?>
    <div class="alert alert-danger">
        <strong><i class="bi bi-exclamation-circle-fill me-1"></i> Please fix the following:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($settings_errors as $e): ?>
                <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>


<div class="row g-4 mb-4">

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>ROLE</p>
                <h3 style="font-size:1.2rem;">Technician</h3>
                <span>Access level</span>
            </div>
            <div class="stat-icon purple-icon"><i class="bi bi-shield-fill-check"></i></div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>BRANCH</p>
                <h3 style="font-size:1.2rem;">
                    <?= $me_user["branch_name"]
                        ? htmlspecialchars($me_user["branch_name"])
                        : '<span class="text-muted">—</span>' ?>
                </h3>
                <span>Assigned branch</span>
            </div>
            <div class="stat-icon gold-icon"><i class="bi bi-building"></i></div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>MEMBER SINCE</p>
                <h3 style="font-size:1.2rem;">
                    <?= htmlspecialchars(date("M j, Y", strtotime($me_user["created_at"]))) ?>
                </h3>
                <span>Account created</span>
            </div>
            <div class="stat-icon green-icon"><i class="bi bi-calendar-check"></i></div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>STATUS</p>
                <h3 style="font-size:1.2rem;">
                    <span class="atm-status operational">
                        <span></span> <?= ucfirst($me_user["status"]) ?>
                    </span>
                </h3>
                <span>Account state</span>
            </div>
            <div class="stat-icon green-icon"><i class="bi bi-person-check-fill"></i></div>
        </div>
    </div>

</div>


<div class="row g-4">

    <div class="col-xl-6">
        <div class="dashboard-card h-100">
            <div class="card-header-custom">
                <div><h5>Profile Information</h5><p>Your display name and login details</p></div>
            </div>

            <form method="POST" action="technician.php" class="row g-3">
                <input type="hidden" name="action" value="update_profile">

                <div class="col-12">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control"
                           value="<?= htmlspecialchars($me_user["full_name"]) ?>"
                           maxlength="100" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control"
                           value="<?= htmlspecialchars($me_user["username"]) ?>"
                           maxlength="50" required>
                    <small class="text-muted">lowercase, no spaces</small>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= htmlspecialchars($me_user["phone"] ?? "") ?>"
                           maxlength="30">
                </div>

                <div class="col-12">
                    <label class="form-label">Role</label>
                    <input type="text" class="form-control" value="Technician" disabled>
                    <small class="text-muted">Only an administrator can change your role.</small>
                </div>

                <div class="col-12 d-flex justify-content-end mt-2">
                    <button type="submit" class="btn btn-gold">
                        <i class="bi bi-check-lg me-1"></i> Save Profile
                    </button>
                </div>
            </form>
        </div>
    </div>


    <div class="col-xl-6">
        <div class="dashboard-card h-100">
            <div class="card-header-custom">
                <div><h5>Change Password</h5><p>Keep your account secure</p></div>
            </div>

            <form method="POST" action="technician.php" class="row g-3">
                <input type="hidden" name="action" value="update_password">

                <div class="col-12">
                    <label class="form-label">Current Password <span class="text-danger">*</span></label>
                    <input type="password" name="current_password" class="form-control"
                           placeholder="Enter current password" required>
                </div>

                <div class="col-12">
                    <label class="form-label">New Password <span class="text-danger">*</span></label>
                    <input type="password" name="new_password" class="form-control"
                           placeholder="At least 8 characters" minlength="8" required>
                </div>

                <div class="col-12">
                    <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                    <input type="password" name="confirm_password" class="form-control"
                           placeholder="Repeat new password" minlength="8" required>
                </div>

                <div class="col-12">
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        You'll stay logged in after changing your password.
                    </div>
                </div>

                <div class="col-12 d-flex justify-content-end mt-2">
                    <button type="submit" class="btn btn-gold">
                        <i class="bi bi-shield-lock me-1"></i> Update Password
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>


<?php endif; ?>


<?php require_once "../includes/footer.php"; ?>