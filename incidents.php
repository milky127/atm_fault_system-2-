<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}

/* ---------- Filters ---------- */
$search         = trim($_GET["search"] ?? "");
$status_filter  = $_GET["status"] ?? "";
$priority_filter= $_GET["priority"] ?? "";
$category_filter= $_GET["category"] ?? "";
$atm_filter     = $_GET["atm"] ?? "";

/* ---------- Main query ---------- */
$sql = "SELECT
            i.*,
            a.atm_code,
            b.branch_name,
            fc.category_name,
            u.full_name AS reporter_name,
            t.full_name AS technician_name
        FROM incidents i
        INNER JOIN atms a ON i.atm_id = a.id
        LEFT  JOIN branches b ON a.branch_id = b.id
        LEFT  JOIN fault_categories fc ON i.category_id = fc.id
        LEFT  JOIN users u ON i.reported_by = u.id
        LEFT  JOIN users t ON i.assigned_to = t.id
        WHERE 1=1";

$params = [];

if ($search !== "") {
    $sql .= " AND (
        i.incident_number LIKE :search
        OR i.title        LIKE :search
        OR i.description  LIKE :search
        OR a.atm_code     LIKE :search
    )";
    $params["search"] = "%" . $search . "%";
}

$valid_statuses = ["reported","under_review","assigned","in_progress","resolved","verified","closed"];
if (in_array($status_filter, $valid_statuses, true)) {
    $sql .= " AND i.status = :status";
    $params["status"] = $status_filter;
}

if (in_array($priority_filter, ["low","medium","high","critical"], true)) {
    $sql .= " AND i.priority = :priority";
    $params["priority"] = $priority_filter;
}

if ($category_filter !== "" && ctype_digit($category_filter)) {
    $sql .= " AND i.category_id = :category_id";
    $params["category_id"] = (int) $category_filter;
}

if ($atm_filter !== "" && ctype_digit($atm_filter)) {
    $sql .= " AND i.atm_id = :atm_id";
    $params["atm_id"] = (int) $atm_filter;
}

$sql .= " ORDER BY i.reported_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$incidents = $stmt->fetchAll();

/* ---------- Filter lists ---------- */
$categories = $pdo->query("
    SELECT id, category_name FROM fault_categories ORDER BY category_name
")->fetchAll();

$atms = $pdo->query("
    SELECT id, atm_code FROM atms ORDER BY atm_code
")->fetchAll();

/* ---------- Summary ---------- */
$summary = [
    "total"       => (int) $pdo->query("SELECT COUNT(*) FROM incidents")->fetchColumn(),
    "open"        => (int) $pdo->query("SELECT COUNT(*) FROM incidents WHERE status <> 'closed'")->fetchColumn(),
    "in_progress" => (int) $pdo->query("SELECT COUNT(*) FROM incidents WHERE status IN ('assigned','in_progress')")->fetchColumn(),
    "critical"    => (int) $pdo->query("SELECT COUNT(*) FROM incidents WHERE priority = 'critical' AND status <> 'closed'")->fetchColumn(),
];

$success = $_GET["success"] ?? "";
$error   = $_GET["error"]   ?? "";

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

$active_page = "incidents";
$page_title  = "Incident Management";

require_once "../includes/header.php";
require_once "../includes/sidebar.php";
?>


<div class="page-heading">

    <div>
        <h2>Incidents</h2>
        <p>Track and manage all reported ATM faults.</p>
    </div>

    <div>
        <a href="incident_add.php" class="btn btn-gold">
            <i class="bi bi-plus-lg me-2"></i>
            Report New Fault
        </a>
    </div>

</div>


<?php if ($success !== ""): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?= match ($success) {
            "added"    => "Incident reported successfully.",
            "updated"  => "Incident updated successfully.",
            "deleted"  => "Incident deleted successfully.",
            default    => "Operation completed.",
        } ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error !== ""): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-circle-fill me-2"></i>
        <?= match ($error) {
            "notfound" => "Incident not found.",
            default    => "An error occurred.",
        } ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>


<!-- ============ SUMMARY ============ -->

<div class="row g-4 mb-4">

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>TOTAL INCIDENTS</p>
                <h3><?= $summary["total"] ?></h3>
                <span>All time</span>
            </div>
            <div class="stat-icon purple-icon">
                <i class="bi bi-exclamation-lg"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>OPEN</p>
                <h3><?= $summary["open"] ?></h3>
                <span class="danger-text">
                    <i class="bi bi-clock-fill"></i> Needs attention
                </span>
            </div>
            <div class="stat-icon red-icon">
                <i class="bi bi-hourglass-split"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>IN PROGRESS</p>
                <h3><?= $summary["in_progress"] ?></h3>
                <span class="gold-text">
                    <i class="bi bi-tools"></i> Active work
                </span>
            </div>
            <div class="stat-icon gold-icon">
                <i class="bi bi-wrench-adjustable"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>CRITICAL OPEN</p>
                <h3><?= $summary["critical"] ?></h3>
                <span class="danger-text">
                    <i class="bi bi-exclamation-triangle-fill"></i> High urgency
                </span>
            </div>
            <div class="stat-icon red-icon">
                <i class="bi bi-fire"></i>
            </div>
        </div>
    </div>

</div>


<!-- ============ FILTERS ============ -->

<div class="dashboard-card mb-4">

    <div class="card-header-custom">
        <div>
            <h5>Search &amp; Filter</h5>
            <p>Find incidents quickly</p>
        </div>
    </div>

    <form method="GET" action="incidents.php" class="row g-3">

        <div class="col-lg-4">
            <label class="form-label">Search</label>
            <div class="input-group">
                <span class="input-group-text bg-white">
                    <i class="bi bi-search"></i>
                </span>
                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Number, title, ATM, description…"
                    value="<?= htmlspecialchars($search) ?>"
                >
            </div>
        </div>

        <div class="col-lg-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">All</option>
                <option value="reported"     <?= $status_filter === "reported"     ? "selected" : "" ?>>Reported</option>
                <option value="under_review" <?= $status_filter === "under_review" ? "selected" : "" ?>>Under Review</option>
                <option value="assigned"     <?= $status_filter === "assigned"     ? "selected" : "" ?>>Assigned</option>
                <option value="in_progress"  <?= $status_filter === "in_progress"  ? "selected" : "" ?>>In Progress</option>
                <option value="resolved"     <?= $status_filter === "resolved"     ? "selected" : "" ?>>Resolved</option>
                <option value="verified"     <?= $status_filter === "verified"     ? "selected" : "" ?>>Verified</option>
                <option value="closed"       <?= $status_filter === "closed"       ? "selected" : "" ?>>Closed</option>
            </select>
        </div>

        <div class="col-lg-2">
            <label class="form-label">Priority</label>
            <select name="priority" class="form-select">
                <option value="">All</option>
                <option value="low"      <?= $priority_filter === "low"      ? "selected" : "" ?>>Low</option>
                <option value="medium"   <?= $priority_filter === "medium"   ? "selected" : "" ?>>Medium</option>
                <option value="high"     <?= $priority_filter === "high"     ? "selected" : "" ?>>High</option>
                <option value="critical" <?= $priority_filter === "critical" ? "selected" : "" ?>>Critical</option>
            </select>
        </div>

        <div class="col-lg-2">
            <label class="form-label">Category</label>
            <select name="category" class="form-select">
                <option value="">All</option>
                <?php foreach ($categories as $c): ?>
                    <option
                        value="<?= (int) $c["id"] ?>"
                        <?= (string) $category_filter === (string) $c["id"] ? "selected" : "" ?>
                    >
                        <?= htmlspecialchars($c["category_name"]) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-lg-2">
            <label class="form-label">ATM</label>
            <select name="atm" class="form-select">
                <option value="">All</option>
                <?php foreach ($atms as $a): ?>
                    <option
                        value="<?= (int) $a["id"] ?>"
                        <?= (string) $atm_filter === (string) $a["id"] ? "selected" : "" ?>
                    >
                        <?= htmlspecialchars($a["atm_code"]) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-12 d-flex justify-content-end gap-2">
            <a href="incidents.php" class="btn btn-outline-secondary">Clear</a>
            <button type="submit" class="btn btn-gold">
                <i class="bi bi-funnel me-1"></i> Apply
            </button>
        </div>

    </form>

</div>


<!-- ============ TABLE ============ -->

<div class="dashboard-card">

    <div class="card-header-custom">
        <div>
            <h5>Incident List</h5>
            <p>
                <?= count($incidents) ?>
                incident<?= count($incidents) === 1 ? "" : "s" ?>
                <?php if ($search !== "" || $status_filter !== "" || $priority_filter !== "" || $category_filter !== "" || $atm_filter !== ""): ?>
                    <span class="text-muted">(filtered)</span>
                <?php endif; ?>
            </p>
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
                    <th>Reporter</th>
                    <th>Assigned</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
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
                            <br>
                            <small class="text-muted">
                                <?= htmlspecialchars($inc["title"]) ?>
                            </small>
                        </td>

                        <td>
                            <strong><?= htmlspecialchars($inc["atm_code"]) ?></strong>
                            <?php if ($inc["branch_name"]): ?>
                                <br>
                                <small class="text-muted">
                                    <?= htmlspecialchars($inc["branch_name"]) ?>
                                </small>
                            <?php endif; ?>
                        </td>

                        <td><?= htmlspecialchars($inc["category_name"] ?? "—") ?></td>

                        <td>
                            <span class="badge <?= $pb_class ?>">
                                <?= ucfirst($inc["priority"]) ?>
                            </span>
                        </td>

                        <td>
                            <?= htmlspecialchars($inc["reporter_name"] ?? "—") ?>
                            <br>
                            <small class="text-muted">
                                <?= htmlspecialchars($inc["reported_at"]) ?>
                            </small>
                        </td>

                        <td>
                            <?= $inc["technician_name"]
                                ? htmlspecialchars($inc["technician_name"])
                                : '<span class="text-muted">Unassigned</span>' ?>
                        </td>

                        <td>
                            <span class="badge"
                                  style="background:<?= $sb_bg ?>; color:<?= $sb_fg ?>;">
                                <?= ucfirst(str_replace("_", " ", $inc["status"])) ?>
                            </span>
                        </td>

                        <td class="text-end">
                            <div class="d-inline-flex gap-1">

                                <a href="incident_view.php?id=<?= (int) $inc["id"] ?>"
                                   class="btn btn-sm btn-outline-secondary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>

                                <?php if (in_array($inc["status"], ["reported","under_review"], true)): ?>
                                    <a href="incident_edit.php?id=<?= (int) $inc["id"] ?>"
                                       class="btn btn-sm btn-outline-purple" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                <?php endif; ?>

                            </div>
                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="8" class="text-center py-5">
                        <div class="empty-state">
                            <i class="bi bi-inbox"></i>
                            <p>
                                <?php if ($search !== "" || $status_filter !== "" || $priority_filter !== "" || $category_filter !== "" || $atm_filter !== ""): ?>
                                    No incidents match your filters.
                                <?php else: ?>
                                    No incidents reported yet.
                                <?php endif; ?>
                            </p>
                            <?php if ($search === "" && $status_filter === "" && $priority_filter === "" && $category_filter === "" && $atm_filter === ""): ?>
                                <a href="incident_add.php" class="btn btn-gold mt-2">
                                    <i class="bi bi-plus-lg me-1"></i> Report your first fault
                                </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<?php require_once "../includes/footer.php"; ?>