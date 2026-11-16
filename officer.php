<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";

$role = $_SESSION["role"];
if (!in_array($role, ["branch_officer", "support_officer"], true)) {
    header("Location: ../auth/login.php");
    exit;
}

$me     = (int) $_SESSION["user_id"];
$action = $_GET["action"] ?? "dashboard";
$id     = (int) ($_GET["id"] ?? 0);

/* =========================================================
   SCOPE
========================================================= */
$my_branch = null;
if ($role === "branch_officer") {
    $stmt = $pdo->prepare("SELECT branch_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$me]);
    $b = $stmt->fetchColumn();
    $my_branch = ($b !== null && $b !== false) ? (int) $b : null;
}
$scoped         = ($role === "branch_officer" && $my_branch !== null);
$branch_id_safe = $scoped ? (int) $my_branch : 0;
$scope_label    = $scoped ? "your branch" : "the network";

/* =========================================================
   POST HANDLERS
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $post_action = $_POST["action"] ?? "";

    /* ---- Update Profile ---- */
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
            header("Location: officer.php?action=settings&success=profile");
            exit;
        }

        $_SESSION["officer_settings_errors"] = $errs;
        header("Location: officer.php?action=settings&error=validation");
        exit;
    }

    /* ---- Update Password ---- */
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
            header("Location: officer.php?action=settings&success=password");
            exit;
        }

        $_SESSION["officer_settings_errors"] = $errs;
        header("Location: officer.php?action=settings&error=validation");
        exit;
    }

    /* ---- Add Update to Incident ---- */
    if ($post_action === "add_update") {
        $pid     = (int) ($_POST["id"] ?? 0);
        $comment = trim($_POST["comment"] ?? "");

        if ($pid <= 0) { header("Location: officer.php?action=incidents"); exit; }
        if ($comment === "") {
            header("Location: officer.php?action=incident_view&id={$pid}&error=empty_comment");
            exit;
        }

        $stmt = $pdo->prepare("SELECT status FROM incidents WHERE id = ? LIMIT 1");
        $stmt->execute([$pid]);
        $cur_status = $stmt->fetchColumn();
        if ($cur_status === false) { header("Location: officer.php?action=incidents"); exit; }

        $stmt = $pdo->prepare("
            INSERT INTO incident_updates (incident_id, user_id, old_status, status, update_text)
            VALUES (?, ?, NULL, NULL, ?)
        ");
        $stmt->execute([$pid, $me, $comment]);

        header("Location: officer.php?action=incident_view&id={$pid}&success=update_added");
        exit;
    }

    /* ---- Create Incident ---- */
     /* ---- Create Incident ---- */
    if ($post_action === "create_incident") {
        $atm_id      = trim($_POST["atm_id"] ?? "");
        $category_id = trim($_POST["category_id"] ?? "");
        $priority    = $_POST["priority"] ?? "medium";
        $title       = trim($_POST["title"] ?? "");
        $description = trim($_POST["description"] ?? "");

        $errs = [];
        if ($atm_id === "")       $errs["atm_id"]       = "Please select an ATM.";
        if ($category_id === "")  $errs["category_id"]  = "Please select a category.";
        if (!in_array($priority, ["low","medium","high","critical"], true)) {
            $errs["priority"] = "Invalid priority.";
        }
        if ($title === "")        $errs["title"]        = "Title is required.";
        if ($description === "")  $errs["description"]  = "Description is required.";

        if (empty($errs)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO incidents
                        (incident_number, title, atm_id, reported_by,
                         category_id, priority, description, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'reported')
                ");
                $stmt->execute([
                    "TEMP-" . uniqid(),
                    $title,
                    (int) $atm_id,
                    $me,
                    (int) $category_id,
                    $priority,
                    $description,
                ]);

                            require_once "../includes/notify.php";

            $atm_stmt = $pdo->prepare("SELECT atm_code FROM atms WHERE id = ? LIMIT 1");
            $atm_stmt->execute([(int) $atm_id]);
            $atm_code = $atm_stmt->fetchColumn() ?: "ATM";

            notify_admins(
                $pdo,
                "new_incident",
                "New incident reported",
                "{$number} — {$title} ({$atm_code})",
                $new_id
            );

            } catch (Throwable $e) {
                echo "<pre style='padding:20px;background:#fff3cd;border:2px solid #d97706;font-family:monospace;'>";
                echo "❌ <strong>INSERT FAILED</strong>\n\n";
                echo "Error: " . htmlspecialchars($e->getMessage()) . "\n\n";
                echo "Data submitted:\n";
                print_r([
                    "incident_number" => "TEMP-..." ,
                    "title"           => $title,
                    "atm_id"          => (int) $atm_id,
                    "reported_by"     => $me,
                    "category_id"     => (int) $category_id,
                    "priority"        => $priority,
                    "description"     => $description,
                    "status"          => "reported",
                ]);
                echo "</pre>";
                exit;
            }
        }

        $_SESSION["officer_incident_errors"] = $errs;
        $_SESSION["officer_incident_form"]   = [
            "atm_id" => $atm_id, "category_id" => $category_id,
            "priority" => $priority, "title" => $title, "description" => $description,
        ];
        header("Location: officer.php?action=incident_add");
        exit;
    }

}

/* =========================================================
   PRESENTATION HELPERS
========================================================= */
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
    "low" => "bg-secondary", "medium" => "bg-info text-dark",
    "high" => "bg-warning text-dark", "critical" => "bg-danger",
];

$success = $_GET["success"] ?? "";
$error   = $_GET["error"]   ?? "";

/* =========================================================
   DATA PER VIEW
========================================================= */

/* ---- Dashboard stats ---- */
$total_atms = (int) $pdo->query("
    SELECT COUNT(*) FROM atms a
    " . ($scoped ? "WHERE a.branch_id = {$branch_id_safe}" : "") . "
")->fetchColumn();

$operational_atms = (int) $pdo->query("
    SELECT COUNT(*) FROM atms a
    " . ($scoped ? "WHERE a.branch_id = {$branch_id_safe} AND a.status = 'operational'"
                 : "WHERE a.status = 'operational'") . "
")->fetchColumn();

$down_atms = (int) $pdo->query("
    SELECT COUNT(*) FROM atms a
    " . ($scoped ? "WHERE a.branch_id = {$branch_id_safe} AND a.status = 'down'"
                 : "WHERE a.status = 'down'") . "
")->fetchColumn();

$open_incidents = (int) $pdo->query("
    SELECT COUNT(*) FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    " . ($scoped ? "WHERE a.branch_id = {$branch_id_safe} AND" : "WHERE") . " i.status NOT IN ('closed','verified')
")->fetchColumn();

/* ---- Chart data ---- */
$cat_sql = "
    SELECT fc.category_name, COUNT(i.id) AS total
    FROM fault_categories fc
    LEFT JOIN incidents i
        ON fc.id = i.category_id
        " . ($scoped ? "AND i.atm_id IN (SELECT id FROM atms WHERE branch_id = {$branch_id_safe})" : "") . "
    GROUP BY fc.id, fc.category_name
    ORDER BY total DESC
";
$fault_statistics = $pdo->query($cat_sql)->fetchAll();
$chart_labels = json_encode(array_column($fault_statistics, "category_name"));
$chart_data   = json_encode(array_map("intval", array_column($fault_statistics, "total")));

/* ---- Recent open incidents ---- */
$recent_open = $pdo->query("
    SELECT i.id, i.incident_number, i.title, i.priority, i.status, i.reported_at,
           a.atm_code, fc.category_name
    FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    LEFT  JOIN fault_categories fc ON i.category_id = fc.id
    " . ($scoped ? "WHERE a.branch_id = {$branch_id_safe} AND" : "WHERE") . " i.status NOT IN ('closed','verified')
    ORDER BY FIELD(i.priority,'critical','high','medium','low'), i.reported_at DESC
    LIMIT 6
")->fetchAll();

/* ---- My recent reports ---- */
$stmt = $pdo->prepare("
    SELECT i.id, i.incident_number, i.title, i.status, i.priority, i.reported_at, a.atm_code
    FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    WHERE i.reported_by = ?
    ORDER BY i.reported_at DESC LIMIT 5
");
$stmt->execute([$me]);
$my_reports = $stmt->fetchAll();

/* ---- Recent ATMs ---- */
$recent_atms = $pdo->query("
    SELECT a.id, a.atm_code, a.location, a.status, b.branch_name,
           (SELECT COUNT(*) FROM incidents i
            WHERE i.atm_id = a.id AND i.status NOT IN ('closed','verified')) AS open_count
    FROM atms a
    LEFT JOIN branches b ON a.branch_id = b.id
    " . ($scoped ? "WHERE a.branch_id = {$branch_id_safe}" : "") . "
    ORDER BY a.created_at DESC LIMIT 5
")->fetchAll();

/* ---- ATM list (atms view) ---- */
if ($action === "atms") {
    $search        = trim($_GET["search"] ?? "");
    $status_filter = $_GET["status"] ?? "";

    $sql = "SELECT a.*, b.branch_name FROM atms a
            LEFT JOIN branches b ON a.branch_id = b.id WHERE 1=1";
    $params = [];
    if ($scoped) { $sql .= " AND a.branch_id = ?"; $params[] = $branch_id_safe; }
    if ($search !== "") {
        $sql .= " AND (a.atm_code LIKE ? OR a.location LIKE ? OR a.serial_number LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
    }
    if (in_array($status_filter, ["operational","down","maintenance"], true)) {
        $sql .= " AND a.status = ?"; $params[] = $status_filter;
    }
    $sql .= " ORDER BY a.atm_code";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $atms = $stmt->fetchAll();

    $atm_summary = [
        "total"       => (int) $pdo->query("SELECT COUNT(*) FROM atms a " . ($scoped ? "WHERE branch_id = {$branch_id_safe}" : ""))->fetchColumn(),
        "operational" => (int) $pdo->query("SELECT COUNT(*) FROM atms a " . ($scoped ? "WHERE branch_id = {$branch_id_safe} AND status='operational'" : "WHERE status='operational'"))->fetchColumn(),
        "down"        => (int) $pdo->query("SELECT COUNT(*) FROM atms a " . ($scoped ? "WHERE branch_id = {$branch_id_safe} AND status='down'" : "WHERE status='down'"))->fetchColumn(),
        "maintenance" => (int) $pdo->query("SELECT COUNT(*) FROM atms a " . ($scoped ? "WHERE branch_id = {$branch_id_safe} AND status='maintenance'" : "WHERE status='maintenance'"))->fetchColumn(),
    ];
}

/* ---- ATM view ---- */
if ($action === "atm_view") {
    if ($id <= 0) { header("Location: officer.php?action=atms"); exit; }
    $stmt = $pdo->prepare("
        SELECT a.*, b.branch_name FROM atms a
        LEFT JOIN branches b ON a.branch_id = b.id
        WHERE a.id = ? LIMIT 1
    ");
    $stmt->execute([$id]);
    $atm = $stmt->fetch();
    if (!$atm) { header("Location: officer.php?action=atms"); exit; }

    $stmt = $pdo->prepare("
        SELECT i.incident_number, i.title, i.priority, i.status, i.reported_at,
               fc.category_name, u.full_name AS reporter_name
        FROM incidents i
        LEFT JOIN fault_categories fc ON i.category_id = fc.id
        LEFT JOIN users u ON i.reported_by = u.id
        WHERE i.atm_id = ?
        ORDER BY i.reported_at DESC
    ");
    $stmt->execute([$id]);
    $atm_incidents = $stmt->fetchAll();
}

/* ---- Incidents list ---- */
if ($action === "incidents") {
    $search          = trim($_GET["search"] ?? "");
    $status_filter   = $_GET["status"] ?? "";
    $priority_filter = $_GET["priority"] ?? "";
    $mine_only       = isset($_GET["mine"]) && $_GET["mine"] === "1";

    $sql = "SELECT i.id, i.incident_number, i.title, i.priority, i.status, i.reported_at,
                   a.atm_code, b.branch_name, fc.category_name,
                   u.full_name AS reporter_name, t.full_name AS technician_name
            FROM incidents i
            INNER JOIN atms a ON i.atm_id = a.id
            LEFT  JOIN branches b ON a.branch_id = b.id
            LEFT  JOIN fault_categories fc ON i.category_id = fc.id
            LEFT  JOIN users u ON i.reported_by = u.id
            LEFT  JOIN users t ON i.assigned_to = t.id
            WHERE 1=1";
    $params = [];
    if ($scoped) { $sql .= " AND a.branch_id = ?"; $params[] = $branch_id_safe; }
    if ($mine_only) { $sql .= " AND i.reported_by = ?"; $params[] = $me; }
    if ($search !== "") {
        $sql .= " AND (i.incident_number LIKE ? OR i.title LIKE ? OR a.atm_code LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
    }
    if (in_array($status_filter, ["reported","under_review","assigned","in_progress","resolved","verified","closed"], true)) {
        $sql .= " AND i.status = ?"; $params[] = $status_filter;
    }
    if (in_array($priority_filter, ["low","medium","high","critical"], true)) {
        $sql .= " AND i.priority = ?"; $params[] = $priority_filter;
    }
    $sql .= " ORDER BY i.reported_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $incidents = $stmt->fetchAll();
}

/* ---- Incident view ---- */
if ($action === "incident_view") {
    if ($id <= 0) { header("Location: officer.php?action=incidents"); exit; }

    $stmt = $pdo->prepare("
        SELECT i.*, a.atm_code, a.location AS atm_location, b.branch_name,
               fc.category_name, u.full_name AS reporter_name, t.full_name AS technician_name
        FROM incidents i
        INNER JOIN atms a ON i.atm_id = a.id
        LEFT  JOIN branches b ON a.branch_id = b.id
        LEFT  JOIN fault_categories fc ON i.category_id = fc.id
        LEFT  JOIN users u ON i.reported_by = u.id
        LEFT  JOIN users t ON i.assigned_to = t.id
        WHERE i.id = ? LIMIT 1
    ");
    $stmt->execute([$id]);
    $incident = $stmt->fetch();
    if (!$incident) { header("Location: officer.php?action=incidents"); exit; }

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

/* ---- Incident add form data ---- */
if ($action === "incident_add") {
    $atms       = $pdo->query("SELECT a.id, a.atm_code, a.location FROM atms a " . ($scoped ? "WHERE a.branch_id = {$branch_id_safe}" : "") . " ORDER BY a.atm_code")->fetchAll();
    $categories = $pdo->query("SELECT id, category_name FROM fault_categories ORDER BY category_name")->fetchAll();

    $incident_errors = $_SESSION["officer_incident_errors"] ?? [];
    $incident_form   = $_SESSION["officer_incident_form"]   ?? ["atm_id"=>$_GET["atm_id"] ?? "","category_id"=>"","priority"=>"medium","title"=>"","description"=>""];
    unset($_SESSION["officer_incident_errors"], $_SESSION["officer_incident_form"]);
}

/* ---- Settings data ---- */
if ($action === "settings") {
    $stmt = $pdo->prepare("
        SELECT u.*, b.branch_name FROM users u
        LEFT JOIN branches b ON u.branch_id = b.id
        WHERE u.id = ? LIMIT 1
    ");
    $stmt->execute([$me]);
    $me_user = $stmt->fetch();

    $settings_errors = $_SESSION["officer_settings_errors"] ?? [];
    unset($_SESSION["officer_settings_errors"]);
}

/* =========================================================
   PAGE TITLE
========================================================= */
$active_page = match ($action) {
    "atms", "atm_view"                  => "atms",
    "incidents", "incident_view", "incident_add" => "incidents",
    "settings"                          => "settings",
    default                             => "dashboard",
};

$page_title = match ($action) {
    "atms"          => "ATMs",
    "atm_view"      => $atm["atm_code"] ?? "ATM",
    "incidents"     => "Incidents",
    "incident_view" => $incident["incident_number"] ?? "Incident",
    "incident_add"  => "Report Fault",
    "settings"      => "Settings",
    default         => "Officer Dashboard",
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
        <p>Tracking ATM faults across <strong><?= htmlspecialchars($scope_label) ?></strong>.</p>
    </div>
    <div>
        <a href="officer.php?action=incident_add" class="btn btn-gold">
            <i class="bi bi-plus-lg me-2"></i> Report Fault
        </a>
    </div>
</div>

<?php if ($success !== ""): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i> Done.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="stat-content"><p>TOTAL ATMs</p><h3><?= $total_atms ?></h3><span>In scope</span></div><div class="stat-icon purple-icon"><i class="bi bi-pc-display"></i></div></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="stat-content"><p>OPERATIONAL</p><h3><?= $operational_atms ?></h3><span class="success-text"><i class="bi bi-check-circle-fill"></i> Running</span></div><div class="stat-icon green-icon"><i class="bi bi-check-lg"></i></div></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="stat-content"><p>ATM DOWN</p><h3><?= $down_atms ?></h3><span class="danger-text"><i class="bi bi-exclamation-circle-fill"></i> Needs attention</span></div><div class="stat-icon red-icon"><i class="bi bi-exclamation-triangle-fill"></i></div></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="stat-content"><p>OPEN INCIDENTS</p><h3><?= $open_incidents ?></h3><span class="gold-text"><i class="bi bi-clock-fill"></i> Active</span></div><div class="stat-icon gold-icon"><i class="bi bi-activity"></i></div></div></div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <div class="dashboard-card h-100">
            <div class="card-header-custom"><div><h5>Faults by Category</h5><p>Distribution across fault types</p></div></div>
            <div class="chart-container"><canvas id="faultChart"></canvas></div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="dashboard-card h-100">
            <div class="card-header-custom"><div><h5>Recent Open Incidents</h5><p>Requiring attention</p></div><a href="officer.php?action=incidents" class="view-all">View All</a></div>
            <?php if (count($recent_open) > 0): ?>
                <div class="incident-list">
                    <?php foreach ($recent_open as $inc): ?>
                        <?php
                        [$sb_bg, $sb_fg] = $status_badges[$inc["status"]] ?? ["#6c757d","#fff"];
                        $pb_class = $priority_badges[$inc["priority"]] ?? "bg-secondary";
                        ?>
                        <a href="officer.php?action=incident_view&id=<?= (int) $inc["id"] ?>" class="incident-item text-decoration-none text-reset">
                            <div class="incident-icon"><i class="bi bi-exclamation-lg"></i></div>
                            <div class="incident-info">
                                <strong><?= htmlspecialchars($inc["incident_number"]) ?></strong>
                                <span><?= htmlspecialchars($inc["atm_code"]) ?> · <?= htmlspecialchars($inc["category_name"] ?? "—") ?></span>
                            </div>
                            <span class="badge <?= $pb_class ?> me-2"><?= ucfirst($inc["priority"]) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state"><i class="bi bi-check-circle"></i><p>No open incidents in scope.</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <div class="dashboard-card h-100">
            <div class="card-header-custom"><div><h5>My Recent Reports</h5><p>Incidents you reported</p></div></div>
            <?php if (count($my_reports) > 0): ?>
                <div class="incident-list">
                    <?php foreach ($my_reports as $inc): ?>
                        <?php [$sb_bg, $sb_fg] = $status_badges[$inc["status"]] ?? ["#6c757d","#fff"]; ?>
                        <a href="officer.php?action=incident_view&id=<?= (int) $inc["id"] ?>" class="incident-item text-decoration-none text-reset">
                            <div class="incident-icon"><i class="bi bi-exclamation-lg"></i></div>
                            <div class="incident-info">
                                <strong><?= htmlspecialchars($inc["incident_number"]) ?></strong>
                                <span><?= htmlspecialchars($inc["atm_code"]) ?> · <?= htmlspecialchars($inc["title"]) ?></span>
                            </div>
                            <span class="badge" style="background:<?= $sb_bg ?>;color:<?= $sb_fg ?>;"><?= ucfirst(str_replace("_"," ",$inc["status"])) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state"><i class="bi bi-inbox"></i><p>You haven't reported any incidents yet.</p>
                    <a href="officer.php?action=incident_add" class="btn btn-gold mt-2"><i class="bi bi-plus-lg me-1"></i> Report your first fault</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="dashboard-card h-100">
            <div class="card-header-custom"><div><h5>Quick Actions</h5><p>Common operations</p></div></div>
            <div class="quick-actions">
                <a href="officer.php?action=incident_add" class="quick-action"><div class="quick-icon purple-bg"><i class="bi bi-plus-circle"></i></div><div><strong>Report Fault</strong><small>Create new incident</small></div><i class="bi bi-chevron-right"></i></a>
                <a href="officer.php?action=incidents"    class="quick-action"><div class="quick-icon gold-bg"><i class="bi bi-exclamation-triangle"></i></div><div><strong>Browse Incidents</strong><small>Search and filter</small></div><i class="bi bi-chevron-right"></i></a>
                <a href="officer.php?action=atms"         class="quick-action"><div class="quick-icon green-bg"><i class="bi bi-pc-display"></i></div><div><strong>View ATMs</strong><small>Browse the network</small></div><i class="bi bi-chevron-right"></i></a>
                <a href="reports.php"                     class="quick-action"><div class="quick-icon blue-bg"><i class="bi bi-file-earmark-bar-graph"></i></div><div><strong>View Reports</strong><small>Analytics and trends</small></div><i class="bi bi-chevron-right"></i></a>
            </div>
        </div>
    </div>
</div>

<div class="dashboard-card">
    <div class="card-header-custom">
        <div><h5>ATM Network Status</h5><p><?= $scoped ? "ATMs at your branch" : "Recently registered ATMs" ?></p></div>
        <a href="officer.php?action=atms" class="view-all">View All ATMs</a>
    </div>
    <div class="table-responsive">
        <table class="table atm-table align-middle">
            <thead><tr><th>ATM</th><th>Branch</th><th>Location</th><th>Incidents</th><th>Status</th><th class="text-end">View</th></tr></thead>
            <tbody>
            <?php if (count($recent_atms) > 0): ?>
                <?php foreach ($recent_atms as $a): ?>
                    <?php $status_class = match ($a["status"]) { "operational"=>"operational", "down"=>"down", default=>"maintenance" }; ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($a["atm_code"]) ?></strong></td>
                        <td><?= $a["branch_name"] ? htmlspecialchars($a["branch_name"]) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= htmlspecialchars($a["location"]) ?></td>
                        <td><?php if ((int) $a["open_count"] > 0): ?><span class="badge bg-danger"><?= (int) $a["open_count"] ?> open</span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                        <td><span class="atm-status <?= $status_class ?>"><span></span> <?= ucfirst($a["status"]) ?></span></td>
                        <td class="text-end"><a href="officer.php?action=atm_view&id=<?= (int) $a["id"] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6" class="text-center py-5"><div class="empty-state"><i class="bi bi-pc-display"></i><p>No ATMs in scope.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>


<?php /* =========================================================
       ATMS LIST
   ========================================================= */ ?>

<?php elseif ($action === "atms"): ?>

<div class="page-heading">
    <div><h2>ATM Network</h2><p>Browse ATMs across <?= htmlspecialchars($scope_label) ?>.</p></div>
    <div><a href="officer.php" class="btn btn-outline-purple"><i class="bi bi-arrow-left me-2"></i> Dashboard</a></div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="stat-content"><p>TOTAL ATMs</p><h3><?= $atm_summary["total"] ?></h3><span>In scope</span></div><div class="stat-icon purple-icon"><i class="bi bi-pc-display"></i></div></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="stat-content"><p>OPERATIONAL</p><h3><?= $atm_summary["operational"] ?></h3><span class="success-text"><i class="bi bi-check-circle-fill"></i> Running</span></div><div class="stat-icon green-icon"><i class="bi bi-check-lg"></i></div></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="stat-content"><p>DOWN</p><h3><?= $atm_summary["down"] ?></h3><span class="danger-text"><i class="bi bi-exclamation-circle-fill"></i> Needs attention</span></div><div class="stat-icon red-icon"><i class="bi bi-exclamation-triangle-fill"></i></div></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="stat-content"><p>MAINTENANCE</p><h3><?= $atm_summary["maintenance"] ?></h3><span class="gold-text"><i class="bi bi-tools"></i> In service</span></div><div class="stat-icon gold-icon"><i class="bi bi-tools"></i></div></div></div>
</div>

<div class="dashboard-card mb-4">
    <div class="card-header-custom"><div><h5>Search &amp; Filter</h5><p>Find ATMs quickly</p></div></div>
    <form method="GET" action="officer.php" class="row g-3">
        <input type="hidden" name="action" value="atms">
        <div class="col-lg-6"><label class="form-label">Search</label>
            <div class="input-group"><span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control" placeholder="Code, location, serial…" value="<?= htmlspecialchars($search) ?>"></div></div>
        <div class="col-lg-4"><label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">All statuses</option>
                <option value="operational" <?= $status_filter === "operational" ? "selected" : "" ?>>Operational</option>
                <option value="down"        <?= $status_filter === "down"        ? "selected" : "" ?>>Down</option>
                <option value="maintenance" <?= $status_filter === "maintenance" ? "selected" : "" ?>>Maintenance</option>
            </select></div>
        <div class="col-lg-2 d-flex align-items-end"><button type="submit" class="btn btn-gold w-100"><i class="bi bi-funnel"></i></button></div>
    </form>
</div>

<div class="dashboard-card">
    <div class="card-header-custom"><div><h5>ATM List</h5><p><?= count($atms) ?> ATM<?= count($atms) === 1 ? "" : "s" ?></p></div>
        <?php if ($search !== "" || $status_filter !== ""): ?><a href="officer.php?action=atms" class="view-all"><i class="bi bi-x-circle me-1"></i> Clear</a><?php endif; ?></div>
    <div class="table-responsive">
        <table class="table atm-table align-middle">
            <thead><tr><th>ATM</th><th>Branch</th><th>Location</th><th>Model</th><th>Status</th><th class="text-end">View</th></tr></thead>
            <tbody>
            <?php if (count($atms) > 0): ?>
                <?php foreach ($atms as $a): ?>
                    <?php $status_class = match ($a["status"]) { "operational"=>"operational", "down"=>"down", default=>"maintenance" }; ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($a["atm_code"]) ?></strong><?php if (!empty($a["serial_number"])): ?><br><small class="text-muted">SN: <?= htmlspecialchars($a["serial_number"]) ?></small><?php endif; ?></td>
                        <td><?= $a["branch_name"] ? htmlspecialchars($a["branch_name"]) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= htmlspecialchars($a["location"]) ?></td>
                        <td><?= $a["model"] ? htmlspecialchars($a["model"]) : '<span class="text-muted">—</span>' ?></td>
                        <td><span class="atm-status <?= $status_class ?>"><span></span> <?= ucfirst($a["status"]) ?></span></td>
                        <td class="text-end"><a href="officer.php?action=atm_view&id=<?= (int) $a["id"] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6" class="text-center py-5"><div class="empty-state"><i class="bi bi-pc-display"></i><p>No ATMs match your filters.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>


<?php /* =========================================================
       ATM VIEW
   ========================================================= */ ?>

<?php elseif ($action === "atm_view"): ?>

<?php $status_class = match ($atm["status"]) { "operational"=>"operational", "down"=>"down", default=>"maintenance" }; ?>

<div class="page-heading">
    <div><h2><?= htmlspecialchars($atm["atm_code"]) ?></h2>
        <p><?= htmlspecialchars($atm["location"]) ?><?php if ($atm["branch_name"]): ?> · <?= htmlspecialchars($atm["branch_name"]) ?><?php endif; ?></p></div>
    <div class="d-flex gap-2">
        <a href="officer.php?action=incident_add&atm_id=<?= $id ?>" class="btn btn-gold"><i class="bi bi-plus-lg me-2"></i> Report Fault</a>
        <a href="officer.php?action=atms" class="btn btn-outline-purple"><i class="bi bi-arrow-left me-2"></i> Back</a>
    </div>
</div>

<div class="dashboard-card mb-4">
    <div class="card-header-custom"><div><h5>ATM Details</h5><p>Registered information</p></div></div>
    <table class="table mb-0"><tbody>
        <tr><th style="width:30%;">ATM Code</th><td><strong><?= htmlspecialchars($atm["atm_code"]) ?></strong></td></tr>
        <tr><th>Branch</th><td><?= $atm["branch_name"] ? htmlspecialchars($atm["branch_name"]) : '<span class="text-muted">—</span>' ?></td></tr>
        <tr><th>Location</th><td><?= htmlspecialchars($atm["location"]) ?></td></tr>
        <tr><th>Model</th><td><?= $atm["model"] ? htmlspecialchars($atm["model"]) : '<span class="text-muted">—</span>' ?></td></tr>
        <tr><th>Serial Number</th><td><?= $atm["serial_number"] ? htmlspecialchars($atm["serial_number"]) : '<span class="text-muted">—</span>' ?></td></tr>
        <tr><th>Installation Date</th><td><?= $atm["installation_date"] ? htmlspecialchars($atm["installation_date"]) : '<span class="text-muted">—</span>' ?></td></tr>
        <tr><th>Status</th><td><span class="atm-status <?= $status_class ?>"><span></span> <?= ucfirst($atm["status"]) ?></span></td></tr>
    </tbody></table>
</div>

<div class="dashboard-card">
    <div class="card-header-custom"><div><h5>Fault History</h5><p>Incidents for this ATM</p></div></div>
    <div class="table-responsive">
        <table class="table atm-table align-middle">
            <thead><tr><th>Incident</th><th>Category</th><th>Priority</th><th>Reported</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (count($atm_incidents) > 0): ?>
                <?php foreach ($atm_incidents as $inc): ?>
                    <?php
                    [$sb_bg, $sb_fg] = $status_badges[$inc["status"]] ?? ["#6c757d","#fff"];
                    $pb_class = $priority_badges[$inc["priority"]] ?? "bg-secondary";
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($inc["incident_number"]) ?></strong><br><small class="text-muted"><?= htmlspecialchars($inc["title"]) ?></small></td>
                        <td><?= htmlspecialchars($inc["category_name"] ?? "—") ?></td>
                        <td><span class="badge <?= $pb_class ?>"><?= ucfirst($inc["priority"]) ?></span></td>
                        <td><?= htmlspecialchars($inc["reported_at"]) ?></td>
                        <td><span class="badge" style="background:<?= $sb_bg ?>;color:<?= $sb_fg ?>;"><?= ucfirst(str_replace("_"," ",$inc["status"])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="5" class="text-center py-5"><div class="empty-state"><i class="bi bi-check-circle"></i><p>No incidents recorded.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>


<?php /* =========================================================
       INCIDENTS LIST
   ========================================================= */ ?>

<?php elseif ($action === "incidents"): ?>

<div class="page-heading">
    <div><h2>Incidents</h2><p>Browse incidents across <?= htmlspecialchars($scope_label) ?>.</p></div>
    <div><a href="officer.php?action=incident_add" class="btn btn-gold"><i class="bi bi-plus-lg me-2"></i> Report Fault</a></div>
</div>

<div class="dashboard-card mb-4">
    <div class="card-header-custom"><div><h5>Search &amp; Filter</h5><p>Find incidents quickly</p></div></div>
    <form method="GET" action="officer.php" class="row g-3">
        <input type="hidden" name="action" value="incidents">
        <div class="col-lg-4"><label class="form-label">Search</label>
            <div class="input-group"><span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control" placeholder="Number, title, ATM…" value="<?= htmlspecialchars($search) ?>"></div></div>
        <div class="col-lg-3"><label class="form-label">Status</label>
            <select name="status" class="form-select"><option value="">All</option>
                <?php foreach (["reported","under_review","assigned","in_progress","resolved","verified","closed"] as $s): ?>
                    <option value="<?= $s ?>" <?= $status_filter === $s ? "selected" : "" ?>><?= ucfirst(str_replace("_"," ",$s)) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="col-lg-3"><label class="form-label">Priority</label>
            <select name="priority" class="form-select"><option value="">All</option>
                <?php foreach (["low","medium","high","critical"] as $p): ?>
                    <option value="<?= $p ?>" <?= $priority_filter === $p ? "selected" : "" ?>><?= ucfirst($p) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="col-lg-2 d-flex align-items-end gap-2">
            <a href="officer.php?action=incidents" class="btn btn-outline-secondary flex-fill">Clear</a>
            <button type="submit" class="btn btn-gold flex-fill"><i class="bi bi-funnel"></i></button></div>
        <div class="col-12">
            <div class="form-check">
                <input type="checkbox" name="mine" value="1" class="form-check-input" id="mineCheck" <?= $mine_only ? "checked" : "" ?>>
                <label class="form-check-label" for="mineCheck">Only incidents I reported</label>
            </div>
        </div>
    </form>
</div>

<div class="dashboard-card">
    <div class="card-header-custom"><div><h5>Incident List</h5><p><?= count($incidents) ?> incident<?= count($incidents) === 1 ? "" : "s" ?></p></div></div>
    <div class="table-responsive">
        <table class="table atm-table align-middle">
            <thead><tr><th>Incident</th><th>ATM</th><th>Category</th><th>Priority</th><th>Reporter</th><th>Technician</th><th>Status</th><th class="text-end">View</th></tr></thead>
            <tbody>
            <?php if (count($incidents) > 0): ?>
                <?php foreach ($incidents as $inc): ?>
                    <?php
                    [$sb_bg, $sb_fg] = $status_badges[$inc["status"]] ?? ["#6c757d","#fff"];
                    $pb_class = $priority_badges[$inc["priority"]] ?? "bg-secondary";
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($inc["incident_number"]) ?></strong><br><small class="text-muted"><?= htmlspecialchars($inc["title"]) ?></small></td>
                        <td><strong><?= htmlspecialchars($inc["atm_code"]) ?></strong><?php if ($inc["branch_name"]): ?><br><small class="text-muted"><?= htmlspecialchars($inc["branch_name"]) ?></small><?php endif; ?></td>
                        <td><?= htmlspecialchars($inc["category_name"] ?? "—") ?></td>
                        <td><span class="badge <?= $pb_class ?>"><?= ucfirst($inc["priority"]) ?></span></td>
                        <td><?= htmlspecialchars($inc["reporter_name"] ?? "—") ?></td>
                        <td><?= $inc["technician_name"] ? htmlspecialchars($inc["technician_name"]) : '<span class="text-muted">Unassigned</span>' ?></td>
                        <td><span class="badge" style="background:<?= $sb_bg ?>;color:<?= $sb_fg ?>;"><?= ucfirst(str_replace("_"," ",$inc["status"])) ?></span></td>
                        <td class="text-end"><a href="officer.php?action=incident_view&id=<?= (int) $inc["id"] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="8" class="text-center py-5"><div class="empty-state"><i class="bi bi-inbox"></i><p>No incidents match your filters.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>


<?php /* =========================================================
       INCIDENT VIEW
   ========================================================= */ ?>

<?php elseif ($action === "incident_view" && isset($incident)): ?>

<?php
[$sb_bg, $sb_fg] = $status_badges[$incident["status"]] ?? ["#6c757d","#fff"];
$pb_class        = $priority_badges[$incident["priority"]] ?? "bg-secondary";
?>

<div class="page-heading">
    <div>
        <h2><?= htmlspecialchars($incident["incident_number"]) ?>
            <span class="badge ms-2" style="background:<?= $sb_bg ?>;color:<?= $sb_fg ?>;"><?= ucfirst(str_replace("_"," ",$incident["status"])) ?></span>
        </h2>
        <p><?= htmlspecialchars($incident["title"]) ?></p>
    </div>
    <div><a href="officer.php?action=incidents" class="btn btn-outline-purple"><i class="bi bi-arrow-left me-2"></i> Back</a></div>
</div>

<?php if ($success !== ""): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?= match ($success) { "created" => "Fault reported successfully.", "update_added" => "Update added.", default => "Done." } ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error !== ""): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-circle-fill me-2"></i>
        <?= $error === "empty_comment" ? "Update text cannot be empty." : "Something went wrong." ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <div class="dashboard-card h-100">
            <div class="card-header-custom"><div><h5>Incident Details</h5><p>Full information</p></div></div>
            <table class="table mb-0"><tbody>
                <tr><th style="width:35%;">ATM</th><td><strong><?= htmlspecialchars($incident["atm_code"]) ?></strong> — <?= htmlspecialchars($incident["atm_location"]) ?><?php if ($incident["branch_name"]): ?><br><small class="text-muted"><?= htmlspecialchars($incident["branch_name"]) ?></small><?php endif; ?></td></tr>
                <tr><th>Category</th><td><?= htmlspecialchars($incident["category_name"] ?? "—") ?></td></tr>
                <tr><th>Priority</th><td><span class="badge <?= $pb_class ?>"><?= ucfirst($incident["priority"]) ?></span></td></tr>
                <tr><th>Status</th><td><span class="badge" style="background:<?= $sb_bg ?>;color:<?= $sb_fg ?>;"><?= ucfirst(str_replace("_"," ",$incident["status"])) ?></span></td></tr>
                <tr><th>Reported</th><td><?= htmlspecialchars($incident["reported_at"]) ?> by <strong><?= htmlspecialchars($incident["reporter_name"] ?? "—") ?></strong></td></tr>
                <tr><th>Assigned To</th><td><?= $incident["technician_name"] ? htmlspecialchars($incident["technician_name"]) : '<span class="text-muted">Unassigned</span>' ?></td></tr>
                <?php if ($incident["resolved_at"]): ?><tr><th>Resolved</th><td><?= htmlspecialchars($incident["resolved_at"]) ?></td></tr><?php endif; ?>
                <?php if ($incident["closed_at"]): ?><tr><th>Closed</th><td><?= htmlspecialchars($incident["closed_at"]) ?></td></tr><?php endif; ?>
            </tbody></table>
            <div class="card-header-custom" style="border-top:1px solid #eee;"><div><h5>Description</h5></div></div>
            <p style="white-space:pre-wrap;"><?= htmlspecialchars($incident["description"]) ?></p>
            <?php if (!empty($incident["resolution_notes"])): ?>
                <div class="card-header-custom" style="border-top:1px solid #eee;"><div><h5>Resolution Notes</h5></div></div>
                <p style="white-space:pre-wrap;"><?= htmlspecialchars($incident["resolution_notes"]) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="dashboard-card h-100">
            <div class="card-header-custom"><div><h5>Add Update</h5><p>Share progress or notes</p></div></div>
            <?php if ($incident["status"] !== "closed"): ?>
                <form method="POST" action="officer.php">
                    <input type="hidden" name="action" value="add_update">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <label class="form-label fw-semibold">Update Text</label>
                    <textarea name="comment" class="form-control mb-3" rows="5" placeholder="Add a note, observation, or update…" required></textarea>
                    <button type="submit" class="btn btn-gold w-100"><i class="bi bi-chat-left-text me-1"></i> Add Update</button>
                </form>
            <?php else: ?>
                <div class="empty-state"><i class="bi bi-lock"></i><p>This incident is closed.</p></div>
            <?php endif; ?>
            <hr>
            <div class="alert alert-info mb-0 small"><i class="bi bi-info-circle me-1"></i> Assignment and resolution are handled by administrators.</div>
        </div>
    </div>
</div>

<div class="dashboard-card">
    <div class="card-header-custom"><div><h5>Activity Timeline</h5><p>All updates on this incident</p></div></div>
    <?php if (count($updates) > 0): ?>
        <div class="list-group list-group-flush">
            <div class="list-group-item">
                <div class="d-flex gap-3">
                    <div class="timeline-icon" style="width:36px;height:36px;border-radius:50%;background:#8E1B8F;color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="bi bi-flag-fill"></i></div>
                    <div><strong>Incident reported</strong>
                        <div class="text-muted small"><?= htmlspecialchars($incident["reported_at"]) ?> by <?= htmlspecialchars($incident["reporter_name"] ?? "—") ?></div></div>
                </div>
            </div>
            <?php foreach ($updates as $u): ?>
                <?php [$b_bg, $b_fg] = $u["status"] ? ($status_badges[$u["status"]] ?? ["#6c757d","#fff"]) : ["#6c757d","#fff"]; ?>
                <div class="list-group-item">
                    <div class="d-flex gap-3">
                        <div class="timeline-icon" style="width:36px;height:36px;border-radius:50%;background:var(--cbe-gold,#D4AF37);color:#252525;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="bi bi-arrow-repeat"></i></div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <strong><?= htmlspecialchars($u["user_name"] ?? "System") ?></strong>
                                <small class="text-muted"><?= htmlspecialchars($u["created_at"]) ?></small>
                            </div>
                            <?php if ($u["old_status"] && $u["status"]): ?>
                                <div class="mt-1 small">
                                    <span class="text-muted"><?= ucfirst(str_replace("_"," ",$u["old_status"])) ?></span> →
                                    <span class="badge" style="background:<?= $b_bg ?>;color:<?= $b_fg ?>;"><?= ucfirst(str_replace("_"," ",$u["status"])) ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="mt-1" style="white-space:pre-wrap;"><?= htmlspecialchars($u["update_text"]) ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state py-5"><i class="bi bi-clock-history"></i><p>No activity yet.</p></div>
    <?php endif; ?>
</div>


<?php /* =========================================================
       INCIDENT ADD
   ========================================================= */ ?>

<?php elseif ($action === "incident_add"): ?>

<div class="page-heading">
    <div><h2>Report New Fault</h2><p>Submit a new ATM fault report.</p></div>
    <div><a href="officer.php?action=incidents" class="btn btn-outline-purple"><i class="bi bi-arrow-left me-2"></i> Back</a></div>
</div>

<?php if (!empty($incident_errors)): ?>
    <div class="alert alert-danger">
        <strong><i class="bi bi-exclamation-circle-fill me-1"></i> Please fix:</strong>
        <ul class="mb-0 mt-2"><?php foreach ($incident_errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="dashboard-card">
    <div class="card-header-custom"><div><h5>Fault Information</h5><p>Fields marked <span class="text-danger">*</span> are required</p></div></div>
    <form method="POST" action="officer.php" class="row g-3">
        <input type="hidden" name="action" value="create_incident">
        <div class="col-md-6">
            <label class="form-label">ATM <span class="text-danger">*</span></label>
            <select name="atm_id" class="form-select" required>
                <option value="">— Select ATM —</option>
                <?php foreach ($atms as $a): ?>
                    <option value="<?= (int) $a["id"] ?>" <?= (string) $incident_form["atm_id"] === (string) $a["id"] ? "selected" : "" ?>>
                        <?= htmlspecialchars($a["atm_code"]) ?> — <?= htmlspecialchars($a["location"]) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Category <span class="text-danger">*</span></label>
            <select name="category_id" class="form-select" required>
                <option value="">— Select category —</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c["id"] ?>" <?= (string) $incident_form["category_id"] === (string) $c["id"] ? "selected" : "" ?>>
                        <?= htmlspecialchars($c["category_name"]) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label">Title <span class="text-danger">*</span></label>
            <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($incident_form["title"]) ?>" maxlength="150" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Priority <span class="text-danger">*</span></label>
            <select name="priority" class="form-select">
                <?php foreach (["low","medium","high","critical"] as $p): ?>
                    <option value="<?= $p ?>" <?= $incident_form["priority"] === $p ? "selected" : "" ?>><?= ucfirst($p) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label">Description <span class="text-danger">*</span></label>
            <textarea name="description" class="form-control" rows="5" required><?= htmlspecialchars($incident_form["description"]) ?></textarea>
        </div>
        <div class="col-12 d-flex justify-content-end gap-2 mt-2">
            <a href="officer.php?action=incidents" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-gold"><i class="bi bi-send me-1"></i> Submit Fault Report</button>
        </div>
    </form>
</div>


<?php /* =========================================================
       SETTINGS
   ========================================================= */ ?>

<?php elseif ($action === "settings"): ?>

<div class="page-heading">
    <div><h2>Settings</h2><p>Update your account details and password.</p></div>
    <div><a href="officer.php" class="btn btn-outline-purple"><i class="bi bi-arrow-left me-2"></i> Back</a></div>
</div>

<?php if ($success !== ""): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?= match ($success) { "profile" => "Profile updated successfully.", "password" => "Password changed successfully.", default => "Saved." } ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($settings_errors)): ?>
    <div class="alert alert-danger">
        <strong><i class="bi bi-exclamation-circle-fill me-1"></i> Please fix:</strong>
        <ul class="mb-0 mt-2"><?php foreach ($settings_errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-6">
        <div class="dashboard-card h-100">
            <div class="card-header-custom"><div><h5>Profile Information</h5><p>Your display name and login details</p></div></div>
            <form method="POST" action="officer.php" class="row g-3">
                <input type="hidden" name="action" value="update_profile">
                <div class="col-12"><label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($me_user["full_name"]) ?>" maxlength="100" required></div>
                <div class="col-md-6"><label class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($me_user["username"]) ?>" maxlength="50" required>
                    <small class="text-muted">lowercase, no spaces</small></div>
                <div class="col-md-6"><label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($me_user["phone"] ?? "") ?>" maxlength="30"></div>
                <div class="col-12"><label class="form-label">Role</label>
                    <input type="text" class="form-control" value="<?= $role === "branch_officer" ? "Branch Officer" : "Support Officer" ?>" disabled></div>
                <div class="col-12 d-flex justify-content-end mt-2">
                    <button type="submit" class="btn btn-gold"><i class="bi bi-check-lg me-1"></i> Save Profile</button>
                </div>
            </form>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="dashboard-card h-100">
            <div class="card-header-custom"><div><h5>Change Password</h5><p>Keep your account secure</p></div></div>
            <form method="POST" action="officer.php" class="row g-3">
                <input type="hidden" name="action" value="update_password">
                <div class="col-12"><label class="form-label">Current Password <span class="text-danger">*</span></label>
                    <input type="password" name="current_password" class="form-control" required></div>
                <div class="col-12"><label class="form-label">New Password <span class="text-danger">*</span></label>
                    <input type="password" name="new_password" class="form-control" minlength="8" required></div>
                <div class="col-12"><label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                    <input type="password" name="confirm_password" class="form-control" minlength="8" required></div>
                <div class="col-12"><div class="alert alert-warning mb-0"><i class="bi bi-info-circle me-1"></i> You'll stay logged in after changing your password.</div></div>
                <div class="col-12 d-flex justify-content-end mt-2">
                    <button type="submit" class="btn btn-gold"><i class="bi bi-shield-lock me-1"></i> Update Password</button>
                </div>
            </form>
        </div>
    </div>
</div>


<?php endif; ?>


<?php if ($action === "dashboard"): ?>
<?php ob_start(); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById("faultChart"), {
    type: "bar",
    data: {
        labels: <?= $chart_labels ?>,
        datasets: [{
            label: "Incidents",
            data: <?= $chart_data ?>,
            backgroundColor: "#8E1B8F",
            borderRadius: 6,
            barThickness: 26
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: "#eeeeee" } },
            x: { grid: { display: false }, ticks: { font: { size: 10 } } }
        }
    }
});
</script>
<?php
$extra_scripts = ob_get_clean();
endif;

require_once "../includes/footer.php";
?>