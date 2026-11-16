<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}

/* ---------- Filters ---------- */
$search        = trim($_GET["search"] ?? "");
$status_filter = $_GET["status"] ?? "";
$branch_filter = $_GET["branch"] ?? "";

/* ---------- Main query ---------- */
$sql = "SELECT a.*, b.branch_name
        FROM atms a
        LEFT JOIN branches b ON a.branch_id = b.id
        WHERE 1=1";

$params = [];

if ($search !== "") {
    $sql .= " AND (
        a.atm_code        LIKE :search
        OR a.location     LIKE :search
        OR a.serial_number LIKE :search
        OR b.branch_name  LIKE :search
    )";
    $params["search"] = "%" . $search . "%";
}

if (in_array($status_filter, ["operational", "down", "maintenance"], true)) {
    $sql .= " AND a.status = :status";
    $params["status"] = $status_filter;
}

if ($branch_filter !== "" && ctype_digit($branch_filter)) {
    $sql .= " AND a.branch_id = :branch_id";
    $params["branch_id"] = (int) $branch_filter;
}

$sql .= " ORDER BY a.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$atms = $stmt->fetchAll();

/* ---------- Incident counts per ATM ---------- */
$incident_counts = [];
if (count($atms) > 0) {
    $ids          = array_column($atms, "id");
    $placeholders = implode(",", array_fill(0, count($ids), "?"));

    $stmt = $pdo->prepare("
        SELECT atm_id,
               COUNT(*) AS total,
               SUM(CASE WHEN status <> 'closed' THEN 1 ELSE 0 END) AS open_count
        FROM incidents
        WHERE atm_id IN ($placeholders)
        GROUP BY atm_id
    ");
    $stmt->execute($ids);

    foreach ($stmt->fetchAll() as $row) {
        $incident_counts[(int) $row["atm_id"]] = [
            "total"      => (int) $row["total"],
            "open_count" => (int) $row["open_count"],
        ];
    }
}

/* ---------- Branches for filter dropdown ---------- */
$branches = $pdo->query("
    SELECT id, branch_name
    FROM branches
    ORDER BY branch_name
")->fetchAll();

/* ---------- Summary cards ---------- */
$summary = [
    "total"       => (int) $pdo->query("SELECT COUNT(*) FROM atms")->fetchColumn(),
    "operational" => (int) $pdo->query("SELECT COUNT(*) FROM atms WHERE status = 'operational'")->fetchColumn(),
    "down"        => (int) $pdo->query("SELECT COUNT(*) FROM atms WHERE status = 'down'")->fetchColumn(),
    "maintenance" => (int) $pdo->query("SELECT COUNT(*) FROM atms WHERE status = 'maintenance'")->fetchColumn(),
];

$success = $_GET["success"] ?? "";
$error   = $_GET["error"]   ?? "";

$active_page = "atms";
$page_title  = "ATM Management";

require_once "../includes/header.php";
require_once "../includes/sidebar.php";
?>


<div class="page-heading">

    <div>
        <h2>ATM Management</h2>
        <p>View, add and manage all ATMs in the network.</p>
    </div>

    <div>
        <a href="atm_add.php" class="btn btn-gold">
            <i class="bi bi-plus-lg me-2"></i>
            Add New ATM
        </a>
    </div>

</div>


<?php if ($success !== ""): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?= match ($success) {
            "added"   => "ATM added successfully.",
            "updated" => "ATM updated successfully.",
            "deleted" => "ATM deleted successfully.",
            default   => "Operation completed.",
        } ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>


<?php if ($error !== ""): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-circle-fill me-2"></i>
        <?= match ($error) {
            "notfound"       => "ATM not found.",
            "open_incidents" => "Cannot delete this ATM — it still has open incidents.",
            "delete"         => "Failed to delete ATM. Please try again.",
            "db"             => "Database error. Please try again.",
            default          => "An error occurred.",
        } ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>


<!-- ============ SUMMARY ============ -->

<div class="row g-4 mb-4">

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>TOTAL ATMs</p>
                <h3><?= $summary["total"] ?></h3>
                <span>Registered ATMs</span>
            </div>
            <div class="stat-icon purple-icon">
                <i class="bi bi-pc-display"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>OPERATIONAL</p>
                <h3><?= $summary["operational"] ?></h3>
                <span class="success-text">
                    <i class="bi bi-check-circle-fill"></i>
                    Running normally
                </span>
            </div>
            <div class="stat-icon green-icon">
                <i class="bi bi-check-lg"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>ATM DOWN</p>
                <h3><?= $summary["down"] ?></h3>
                <span class="danger-text">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    Requires attention
                </span>
            </div>
            <div class="stat-icon red-icon">
                <i class="bi bi-exclamation-triangle-fill"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>MAINTENANCE</p>
                <h3><?= $summary["maintenance"] ?></h3>
                <span class="gold-text">
                    <i class="bi bi-tools"></i>
                    Under maintenance
                </span>
            </div>
            <div class="stat-icon gold-icon">
                <i class="bi bi-tools"></i>
            </div>
        </div>
    </div>

</div>


<!-- ============ FILTERS ============ -->

<div class="dashboard-card mb-4">

    <div class="card-header-custom">
        <div>
            <h5>Search &amp; Filter</h5>
            <p>Find ATMs quickly</p>
        </div>
    </div>

    <form method="GET" action="atms.php" class="row g-3">

        <div class="col-lg-5">
            <label class="form-label">Search</label>
            <div class="input-group">
                <span class="input-group-text bg-white">
                    <i class="bi bi-search"></i>
                </span>
                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="ATM code, location, serial…"
                    value="<?= htmlspecialchars($search) ?>"
                >
            </div>
        </div>

        <div class="col-lg-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">All statuses</option>
                <option value="operational" <?= $status_filter === "operational" ? "selected" : "" ?>>Operational</option>
                <option value="down"        <?= $status_filter === "down"        ? "selected" : "" ?>>Down</option>
                <option value="maintenance" <?= $status_filter === "maintenance" ? "selected" : "" ?>>Maintenance</option>
            </select>
        </div>

        <div class="col-lg-3">
            <label class="form-label">Branch</label>
            <select name="branch" class="form-select">
                <option value="">All branches</option>
                <?php foreach ($branches as $b): ?>
                    <option
                        value="<?= (int) $b["id"] ?>"
                        <?= (string) $branch_filter === (string) $b["id"] ? "selected" : "" ?>
                    >
                        <?= htmlspecialchars($b["branch_name"]) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-lg-1 d-flex align-items-end">
            <button type="submit" class="btn btn-gold w-100">
                <i class="bi bi-funnel"></i>
            </button>
        </div>

    </form>

</div>


<!-- ============ TABLE ============ -->

<div class="dashboard-card">

    <div class="card-header-custom">

        <div>
            <h5>ATM List</h5>
            <p>
                <?= count($atms) ?>
                ATM<?= count($atms) === 1 ? "" : "s" ?>
                <?php if ($search !== "" || $status_filter !== "" || $branch_filter !== ""): ?>
                    <span class="text-muted">(filtered)</span>
                <?php endif; ?>
            </p>
        </div>

        <?php if ($search !== "" || $status_filter !== "" || $branch_filter !== ""): ?>
            <a href="atms.php" class="view-all">
                <i class="bi bi-x-circle me-1"></i>
                Clear filters
            </a>
        <?php endif; ?>

    </div>

    <div class="table-responsive">

        <table class="table atm-table align-middle">

            <thead>
                <tr>
                    <th>ATM Code</th>
                    <th>Branch</th>
                    <th>Location</th>
                    <th>Model</th>
                    <th>Incidents</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>

            <tbody>

            <?php if (count($atms) > 0): ?>

                <?php foreach ($atms as $atm): ?>

                    <?php
                    $status_class = match ($atm["status"]) {
                        "operational" => "operational",
                        "down"        => "down",
                        default       => "maintenance",
                    };

                    $counts = $incident_counts[(int) $atm["id"]]
                            ?? ["total" => 0, "open_count" => 0];
                    ?>

                    <tr>

                        <td>
                            <strong><?= htmlspecialchars($atm["atm_code"]) ?></strong>
                            <?php if (!empty($atm["serial_number"])): ?>
                                <br>
                                <small class="text-muted">
                                    SN: <?= htmlspecialchars($atm["serial_number"]) ?>
                                </small>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($atm["branch_name"]): ?>
                                <?= htmlspecialchars($atm["branch_name"]) ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>

                        <td><?= htmlspecialchars($atm["location"]) ?></td>

                        <td>
                            <?= $atm["model"]
                                ? htmlspecialchars($atm["model"])
                                : '<span class="text-muted">—</span>' ?>
                        </td>

                        <td>
                            <?php if ($counts["total"] > 0): ?>
                                <span class="badge bg-secondary"><?= $counts["total"] ?></span>
                                <?php if ($counts["open_count"] > 0): ?>
                                    <span class="badge bg-danger ms-1"><?= $counts["open_count"] ?> open</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="atm-status <?= $status_class ?>">
                                <span></span>
                                <?= ucfirst($atm["status"]) ?>
                            </span>
                        </td>

                        <td class="text-end">
                            <div class="d-inline-flex gap-1">

                                <a href="atm_view.php?id=<?= (int) $atm["id"] ?>"
                                   class="btn btn-sm btn-outline-secondary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>

                                <a href="atm_edit.php?id=<?= (int) $atm["id"] ?>"
                                   class="btn btn-sm btn-outline-purple" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>

                                <form method="POST" action="atm_delete.php" class="d-inline"
                                      onsubmit="return confirmDelete('<?= htmlspecialchars($atm['atm_code'], ENT_QUOTES) ?>', <?= $counts['total'] ?>);">
                                    <input type="hidden" name="id" value="<?= (int) $atm["id"] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>

                            </div>
                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="7" class="text-center py-5">

                        <div class="empty-state">

                            <i class="bi bi-pc-display"></i>

                            <p>
                                <?php if ($search !== "" || $status_filter !== "" || $branch_filter !== ""): ?>
                                    No ATMs match your filters.
                                <?php else: ?>
                                    No ATMs registered yet.
                                <?php endif; ?>
                            </p>

                            <?php if ($search === "" && $status_filter === "" && $branch_filter === ""): ?>
                                <a href="atm_add.php" class="btn btn-gold mt-2">
                                    <i class="bi bi-plus-lg me-1"></i>
                                    Add your first ATM
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


<?php

$extra_scripts = <<<'HTML'
<script>
function confirmDelete(atmCode, incidentCount) {
    let msg = "Delete ATM " + atmCode + "?";
    if (incidentCount > 0) {
        msg += "\n\nThis will also remove " + incidentCount +
               " incident record(s) from its history.";
    }
    msg += "\n\nThis action cannot be undone.";
    return confirm(msg);
}
</script>
HTML;

require_once "../includes/footer.php";