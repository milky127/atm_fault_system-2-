<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";

$role = $_SESSION["role"];
if (!in_array($role, ["branch_officer", "support_officer"], true)) {
    header("Location: ../auth/login.php");
    exit;
}

$me = (int) $_SESSION["user_id"];

/* ---------- Scope ---------- */
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

/* ---------- Date range ---------- */
$from = trim($_GET["from"] ?? date("Y-m-01"));
$to   = trim($_GET["to"]   ?? date("Y-m-d"));

if (!DateTime::createFromFormat("Y-m-d", $from)) $from = date("Y-m-01");
if (!DateTime::createFromFormat("Y-m-d", $to))   $to   = date("Y-m-d");

$from_dt = $from . " 00:00:00";
$to_dt   = $to   . " 23:59:59";

/* Build the scope fragment */
$scope_atm  = $scoped ? " AND a.branch_id = {$branch_id_safe}" : "";
$scope_atm2 = $scoped ? " AND atm_id IN (SELECT id FROM atms WHERE branch_id = {$branch_id_safe})" : "";

/* =========================================================
   SUMMARY STATS
========================================================= */

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    WHERE i.reported_at BETWEEN :from AND :to {$scope_atm}
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$total_period = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    WHERE i.status <> 'closed'
      AND i.reported_at BETWEEN :from AND :to {$scope_atm}
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$open_period = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    WHERE i.status = 'closed'
      AND i.reported_at BETWEEN :from AND :to {$scope_atm}
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$closed_period = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT AVG(TIMESTAMPDIFF(MINUTE, i.reported_at, i.resolved_at))
    FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    WHERE i.resolved_at IS NOT NULL
      AND i.reported_at BETWEEN :from AND :to {$scope_atm}
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$avg_minutes = $stmt->fetchColumn();
$avg_minutes = $avg_minutes !== null ? (int) round($avg_minutes) : 0;
$avg_label   = $avg_minutes > 0
    ? intdiv($avg_minutes, 60) . "h " . ($avg_minutes % 60) . "m"
    : "—";

/* =========================================================
   FAULTS BY CATEGORY
========================================================= */

$stmt = $pdo->prepare("
    SELECT fc.category_name, COUNT(i.id) AS total
    FROM fault_categories fc
    LEFT JOIN incidents i
        ON fc.id = i.category_id
       AND i.reported_at BETWEEN :from AND :to
       {$scope_atm2}
    GROUP BY fc.id, fc.category_name
    ORDER BY total DESC, fc.category_name
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$by_category = $stmt->fetchAll();

/* =========================================================
   FAULTS BY PRIORITY
========================================================= */

$stmt = $pdo->prepare("
    SELECT i.priority, COUNT(*) AS total
    FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    WHERE i.reported_at BETWEEN :from AND :to {$scope_atm}
    GROUP BY i.priority
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$by_priority = ["low" => 0, "medium" => 0, "high" => 0, "critical" => 0];
foreach ($stmt->fetchAll() as $r) {
    $by_priority[$r["priority"]] = (int) $r["total"];
}

/* =========================================================
   FAULTS BY STATUS
========================================================= */

$stmt = $pdo->prepare("
    SELECT i.status, COUNT(*) AS total
    FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    WHERE i.reported_at BETWEEN :from AND :to {$scope_atm}
    GROUP BY i.status
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$status_order = ["reported","under_review","assigned","in_progress","resolved","verified","closed"];
$by_status = array_fill_keys($status_order, 0);
foreach ($stmt->fetchAll() as $r) {
    $by_status[$r["status"]] = (int) $r["total"];
}

/* =========================================================
   TOP 10 ATMs
========================================================= */

$stmt = $pdo->prepare("
    SELECT a.atm_code, a.location, b.branch_name,
           COUNT(i.id) AS total,
           SUM(CASE WHEN i.status <> 'closed' THEN 1 ELSE 0 END) AS open_count
    FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    LEFT  JOIN branches b ON a.branch_id = b.id
    WHERE i.reported_at BETWEEN :from AND :to {$scope_atm}
    GROUP BY a.id, a.atm_code, a.location, b.branch_name
    ORDER BY total DESC
    LIMIT 10
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$top_atms = $stmt->fetchAll();

/* =========================================================
   TOP BRANCHES
========================================================= */

$stmt = $pdo->prepare("
    SELECT COALESCE(b.branch_name, 'Unassigned') AS branch_name,
           COUNT(i.id) AS total
    FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    LEFT  JOIN branches b ON a.branch_id = b.id
    WHERE i.reported_at BETWEEN :from AND :to
          " . ($scoped ? "AND a.branch_id = {$branch_id_safe}" : "") . "
    GROUP BY b.id, b.branch_name
    ORDER BY total DESC
    LIMIT 8
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$top_branches = $stmt->fetchAll();

/* =========================================================
   MONTHLY TREND (last 12 months, ignores date filter)
========================================================= */

$stmt = $pdo->prepare("
    SELECT DATE_FORMAT(i.reported_at, '%Y-%m') AS ym, COUNT(*) AS total
    FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    WHERE i.reported_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    {$scope_atm}
    GROUP BY ym
    ORDER BY ym
");
$stmt->execute();
$trend_map = [];
foreach ($stmt->fetchAll() as $r) {
    $trend_map[$r["ym"]] = (int) $r["total"];
}

$trend_labels = [];
$trend_data   = [];
for ($i = 11; $i >= 0; $i--) {
    $key = date("Y-m", strtotime("-{$i} months"));
    $trend_labels[] = date("M Y", strtotime($key . "-01"));
    $trend_data[]   = $trend_map[$key] ?? 0;
}

/* =========================================================
   NEEDS ATTENTION
========================================================= */

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    WHERE i.assigned_to IS NULL
      AND i.status IN ('reported','under_review')
      AND i.reported_at BETWEEN :from AND :to {$scope_atm}
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$unassigned_count = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    WHERE i.status IN ('assigned','in_progress')
      AND DATEDIFF(NOW(), i.reported_at) > 7
      AND i.reported_at BETWEEN :from AND :to {$scope_atm}
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$stuck_count = (int) $stmt->fetchColumn();

/* =========================================================
   CHART DATA
========================================================= */

$cat_labels = json_encode(array_column($by_category, "category_name"));
$cat_data   = json_encode(array_map("intval", array_column($by_category, "total")));

$active_page = "reports";
$page_title  = "Reports & Analytics";

require_once "../includes/header.php";
require_once "../includes/sidebar.php";
?>


<div class="page-heading">
  <div class="d-flex gap-2">
    <a href="report_pdf.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>"
       class="btn btn-gold">
        <i class="bi bi-file-earmark-pdf me-1"></i> Export PDF
    </a>
    <a href="report_print.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>"
       target="_blank"
       class="btn btn-outline-secondary">
        <i class="bi bi-printer me-1"></i> Print
    </a>
</div>
</div>


<!-- ============ DATE FILTER ============ -->

<div class="dashboard-card mb-4 no-print">
    <div class="card-header-custom">
        <div>
            <h5>Date Range</h5>
            <p>Showing <strong><?= htmlspecialchars($from) ?></strong> to <strong><?= htmlspecialchars($to) ?></strong></p>
        </div>
    </div>

    <form method="GET" action="reports.php" class="row g-3">
        <div class="col-md-4">
            <label class="form-label">From</label>
            <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">To</label>
            <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>">
        </div>
        <div class="col-md-4 d-flex align-items-end gap-2">
            <button type="submit" class="btn btn-gold flex-fill"><i class="bi bi-funnel me-1"></i> Apply</button>
            <a href="reports.php" class="btn btn-outline-secondary">Reset</a>
        </div>
        <div class="col-12">
            <div class="d-flex flex-wrap gap-2 mt-1">
                <a href="reports.php?from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-sm btn-outline-purple">Today</a>
                <a href="reports.php?from=<?= date('Y-m-d', strtotime('monday this week')) ?>&to=<?= date('Y-m-d') ?>" class="btn btn-sm btn-outline-purple">This Week</a>
                <a href="reports.php?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-sm btn-outline-purple">This Month</a>
                <a href="reports.php?from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-sm btn-outline-purple">This Year</a>
            </div>
        </div>
    </form>
</div>


<!-- ============ SUMMARY ============ -->

<div class="row g-4 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><div class="stat-content"><p>TOTAL INCIDENTS</p><h3><?= $total_period ?></h3><span>In selected period</span></div>
            <div class="stat-icon purple-icon"><i class="bi bi-exclamation-lg"></i></div></div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><div class="stat-content"><p>OPEN</p><h3><?= $open_period ?></h3>
            <span class="danger-text"><i class="bi bi-clock-fill"></i> Still active</span></div>
            <div class="stat-icon red-icon"><i class="bi bi-hourglass-split"></i></div></div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><div class="stat-content"><p>CLOSED</p><h3><?= $closed_period ?></h3>
            <span class="success-text"><i class="bi bi-check-circle-fill"></i> Resolved &amp; closed</span></div>
            <div class="stat-icon green-icon"><i class="bi bi-check-lg"></i></div></div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card"><div class="stat-content"><p>AVG RESOLUTION</p><h3><?= htmlspecialchars($avg_label) ?></h3><span>From report to resolve</span></div>
            <div class="stat-icon gold-icon"><i class="bi bi-stopwatch"></i></div></div>
    </div>
</div>


<!-- ============ CHART ROW 1 ============ -->

<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <div class="dashboard-card h-100">
            <div class="card-header-custom"><div><h5>Faults by Category</h5><p>Incidents per fault type</p></div></div>
            <div style="position:relative; height:320px;"><canvas id="catChart"></canvas></div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="dashboard-card h-100">
            <div class="card-header-custom"><div><h5>By Priority</h5><p>Severity distribution</p></div></div>
            <div style="position:relative; height:320px;"><canvas id="prioChart"></canvas></div>
        </div>
    </div>
</div>


<!-- ============ CHART ROW 2 ============ -->

<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <div class="dashboard-card h-100">
            <div class="card-header-custom"><div><h5>Monthly Trend</h5><p>Incidents reported over the last 12 months</p></div></div>
            <div style="position:relative; height:300px;"><canvas id="trendChart"></canvas></div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="dashboard-card h-100">
            <div class="card-header-custom"><div><h5>Top ATMs</h5><p>Highest incident counts</p></div></div>
            <?php if (count($top_atms) > 0): ?>
                <div class="table-responsive">
                    <table class="table atm-table align-middle mb-0">
                        <thead><tr><th>#</th><th>ATM</th><th class="text-end">Total</th><th class="text-end">Open</th></tr></thead>
                        <tbody>
                        <?php foreach ($top_atms as $i => $a): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= htmlspecialchars($a["atm_code"]) ?></strong><br><small class="text-muted"><?= htmlspecialchars($a["branch_name"] ?? "—") ?></small></td>
                                <td class="text-end"><span class="badge bg-secondary"><?= (int) $a["total"] ?></span></td>
                                <td class="text-end">
                                    <?php if ((int) $a["open_count"] > 0): ?>
                                        <span class="badge bg-danger"><?= (int) $a["open_count"] ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state py-5"><i class="bi bi-inbox"></i><p>No incidents in this period.</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>


<!-- ============ STATUS + BRANCHES ============ -->

<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <div class="dashboard-card h-100">
            <div class="card-header-custom"><div><h5>By Status</h5><p>Where incidents are in the workflow</p></div></div>
            <div style="position:relative; height:260px;"><canvas id="statusChart"></canvas></div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="dashboard-card h-100">
            <div class="card-header-custom"><div><h5>Top Branches</h5><p>Incidents by branch</p></div></div>
            <?php if (count($top_branches) > 0): ?>
                <div style="position:relative; height:260px;"><canvas id="branchChart"></canvas></div>
            <?php else: ?>
                <div class="empty-state py-5"><i class="bi bi-building"></i><p>No incidents in this period.</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>


<!-- ============ NEEDS ATTENTION ============ -->

<div class="dashboard-card mb-4">
    <div class="card-header-custom"><div><h5>Needs Attention</h5><p>Operational flags</p></div></div>
    <div class="p-3">
        <div class="d-flex align-items-center justify-content-between py-3 border-bottom">
            <div><strong>Unassigned incidents</strong><div class="text-muted small">Reported but no technician yet</div></div>
            <span class="badge <?= $unassigned_count > 0 ? 'bg-warning text-dark' : 'bg-secondary' ?>"><?= $unassigned_count ?></span>
        </div>
        <div class="d-flex align-items-center justify-content-between py-3 border-bottom">
            <div><strong>Stuck &gt; 7 days</strong><div class="text-muted small">Assigned or in progress too long</div></div>
            <span class="badge <?= $stuck_count > 0 ? 'bg-danger' : 'bg-secondary' ?>"><?= $stuck_count ?></span>
        </div>
        <div class="d-flex align-items-center justify-content-between py-3">
            <div><strong>Critical open</strong><div class="text-muted small">Priority = critical, not closed</div></div>
            <span class="badge bg-danger"><?= (int) $by_priority["critical"] ?></span>
        </div>
    </div>
</div>


<!-- ============ PRINT CSS ============ -->

<style>
@media print {
    .sidebar, .topbar, .no-print, .btn, .page-heading > div:last-child { display: none !important; }
    .main-content { margin-left: 0 !important; }
    .dashboard-card { box-shadow: none !important; border: 1px solid #ddd !important; page-break-inside: avoid; }
}
</style>


<?php ob_start(); ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

<script>
const purple = "#8E1B8F";
const gold   = "#D4AF37";

new Chart(document.getElementById("catChart"), {
    type: "bar",
    data: {
        labels: <?= $cat_labels ?>,
        datasets: [{
            label: "Incidents",
            data: <?= $cat_data ?>,
            backgroundColor: purple,
            borderRadius: 6,
            barThickness: 26
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: "#eeeeee" } },
            x: { grid: { display: false } }
        }
    }
});

new Chart(document.getElementById("prioChart"), {
    type: "doughnut",
    data: {
        labels: ["Low", "Medium", "High", "Critical"],
        datasets: [{
            data: [<?= (int) $by_priority["low"] ?>, <?= (int) $by_priority["medium"] ?>, <?= (int) $by_priority["high"] ?>, <?= (int) $by_priority["critical"] ?>],
            backgroundColor: ["#adb5bd", "#0D6EFD", "#E0A800", "#DC3545"],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: "bottom" } },
        cutout: "65%"
    }
});

new Chart(document.getElementById("trendChart"), {
    type: "line",
    data: {
        labels: <?= json_encode($trend_labels) ?>,
        datasets: [{
            label: "Incidents",
            data: <?= json_encode($trend_data) ?>,
            borderColor: purple,
            backgroundColor: "rgba(142,27,143,0.12)",
            borderWidth: 3,
            tension: 0.3,
            fill: true,
            pointBackgroundColor: gold,
            pointBorderColor: purple,
            pointRadius: 4
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: "#eeeeee" } },
            x: { grid: { display: false } }
        }
    }
});

new Chart(document.getElementById("statusChart"), {
    type: "bar",
    data: {
        labels: ["Reported","Under Review","Assigned","In Progress","Resolved","Verified","Closed"],
        datasets: [{
            data: [
                <?= (int) $by_status["reported"] ?>,
                <?= (int) $by_status["under_review"] ?>,
                <?= (int) $by_status["assigned"] ?>,
                <?= (int) $by_status["in_progress"] ?>,
                <?= (int) $by_status["resolved"] ?>,
                <?= (int) $by_status["verified"] ?>,
                <?= (int) $by_status["closed"] ?>
            ],
            backgroundColor: ["#8E1B8F","#0D6EFD","#D4AF37","#E0A800","#198754","#0DCAF0","#495057"],
            borderRadius: 6, barThickness: 22
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: "#eeeeee" } },
            x: { grid: { display: false } }
        }
    }
});

<?php if (count($top_branches) > 0): ?>
new Chart(document.getElementById("branchChart"), {
    type: "bar",
    data: {
        labels: <?= json_encode(array_column($top_branches, "branch_name")) ?>,
        datasets: [{
            data: <?= json_encode(array_map("intval", array_column($top_branches, "total"))) ?>,
            backgroundColor: gold,
            borderRadius: 6, barThickness: 22
        }]
    },
    options: {
        indexAxis: "y",
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: "#eeeeee" } },
            y: { grid: { display: false } }
        }
    }
});
<?php endif; ?>
</script>

<?php
$extra_scripts = ob_get_clean();
require_once "../includes/footer.php";
?>