<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Dashboard Statistics
|--------------------------------------------------------------------------
*/

$total_atms = (int) $pdo
    ->query("SELECT COUNT(*) FROM atms")
    ->fetchColumn();

$operational_atms = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM atms
        WHERE status = 'operational'
    ")
    ->fetchColumn();

$down_atms = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM atms
        WHERE status = 'down'
    ")
    ->fetchColumn();

$maintenance_atms = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM atms
        WHERE status = 'maintenance'
    ")
    ->fetchColumn();

$open_incidents = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM incidents
        WHERE status NOT IN ('closed', 'verified')
    ")
    ->fetchColumn();


/*
|--------------------------------------------------------------------------
| Recent Incidents
|--------------------------------------------------------------------------
*/

$recent_incidents = $pdo->query("
    SELECT
        i.incident_number,
        i.title,
        i.priority,
        i.status,
        i.reported_at,
        a.atm_code,
        fc.category_name
    FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    LEFT  JOIN fault_categories fc ON i.category_id = fc.id
    ORDER BY i.reported_at DESC
    LIMIT 6
")->fetchAll();


/*
|--------------------------------------------------------------------------
| Fault Statistics
|--------------------------------------------------------------------------
*/

$fault_statistics = $pdo->query("
    SELECT
        fc.category_name,
        COUNT(i.id) AS total
    FROM fault_categories fc
    LEFT JOIN incidents i ON fc.id = i.category_id
    GROUP BY fc.id, fc.category_name
    ORDER BY total DESC
    LIMIT 6
")->fetchAll();


/*
|--------------------------------------------------------------------------
| Recent ATM Status
|--------------------------------------------------------------------------
*/

$recent_atms = $pdo->query("
    SELECT
        a.atm_code,
        a.location,
        a.status,
        b.branch_name
    FROM atms a
    LEFT JOIN branches b ON a.branch_id = b.id
    ORDER BY a.created_at DESC
    LIMIT 5
")->fetchAll();


$active_page = "dashboard";
$page_title  = "Dashboard";

require_once "../includes/header.php";
require_once "../includes/sidebar.php";
?>


<div class="page-heading">

    <div>
        <h2>Dashboard</h2>
        <p>
            Welcome back. Here's what's happening
            with the ATM network.
        </p>
    </div>

    <div>
        <a href="incident_add.php" class="btn btn-gold">
            <i class="bi bi-plus-lg me-2"></i>
            Report Fault
        </a>
    </div>

</div>


<!-- =====================================================
     STATISTICS
====================================================== -->

<div class="row g-4 mb-4">

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>TOTAL ATMs</p>
                <h3><?= $total_atms ?></h3>
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
                <h3><?= $operational_atms ?></h3>
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
                <h3><?= $down_atms ?></h3>
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
                <p>OPEN INCIDENTS</p>
                <h3><?= $open_incidents ?></h3>
                <span class="gold-text">
                    <i class="bi bi-clock-fill"></i>
                    Active incidents
                </span>
            </div>
            <div class="stat-icon gold-icon">
                <i class="bi bi-activity"></i>
            </div>
        </div>
    </div>

</div>


<!-- =====================================================
     CHART + RECENT INCIDENTS
====================================================== -->

<div class="row g-4 mb-4">

    <div class="col-xl-7">
        <div class="dashboard-card h-100">

            <div class="card-header-custom">
                <div>
                    <h5>Fault Overview</h5>
                    <p>Incidents by category</p>
                </div>
                <button class="btn btn-sm btn-outline-purple">
                    This Month
                    <i class="bi bi-chevron-down"></i>
                </button>
            </div>

            <div class="chart-container">
                <canvas id="faultChart"></canvas>
            </div>

        </div>
    </div>


    <div class="col-xl-5">
        <div class="dashboard-card h-100">

            <div class="card-header-custom">
                <div>
                    <h5>Recent Incidents</h5>
                    <p>Latest reported faults</p>
                </div>
                <a href="incidents.php" class="view-all">
                    View All
                </a>
            </div>

            <div class="incident-list">

                <?php if (count($recent_incidents) > 0): ?>

                    <?php foreach ($recent_incidents as $incident): ?>

                        <div class="incident-item">

                            <div class="incident-icon">
                                <i class="bi bi-exclamation-lg"></i>
                            </div>

                            <div class="incident-info">
                                <strong>
                                    <?= htmlspecialchars($incident["incident_number"]) ?>
                                </strong>
                                <span>
                                    <?= htmlspecialchars($incident["atm_code"]) ?>
                                    ·
                                    <?= htmlspecialchars($incident["category_name"] ?? "—") ?>
                                </span>
                            </div>

                            <span class="status-badge status-<?= htmlspecialchars($incident["status"]) ?>">
                                <?= ucfirst(str_replace("_", " ", $incident["status"])) ?>
                            </span>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <p>No incidents reported yet.</p>
                    </div>

                <?php endif; ?>

            </div>

        </div>
    </div>

</div>


<!-- =====================================================
     ATM STATUS + QUICK ACTIONS
====================================================== -->

<div class="row g-4">

    <div class="col-xl-8">
        <div class="dashboard-card">

            <div class="card-header-custom">
                <div>
                    <h5>ATM Network Status</h5>
                    <p>Recently registered ATMs</p>
                </div>
                <a href="atms.php" class="view-all">
                    View All ATMs
                </a>
            </div>

            <div class="table-responsive">

                <table class="table atm-table align-middle">

                    <thead>
                        <tr>
                            <th>ATM</th>
                            <th>Branch</th>
                            <th>Location</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (count($recent_atms) > 0): ?>

                        <?php foreach ($recent_atms as $atm): ?>

                            <?php
                            $status_class = match ($atm["status"]) {
                                "operational" => "operational",
                                "down"        => "down",
                                default       => "maintenance",
                            };
                            ?>

                            <tr>

                                <td>
                                    <strong><?= htmlspecialchars($atm["atm_code"]) ?></strong>
                                </td>

                                <td>
                                    <?= $atm["branch_name"]
                                        ? htmlspecialchars($atm["branch_name"])
                                        : '<span class="text-muted">—</span>' ?>
                                </td>

                                <td><?= htmlspecialchars($atm["location"]) ?></td>

                                <td>
                                    <span class="atm-status <?= $status_class ?>">
                                        <span></span>
                                        <?= ucfirst($atm["status"]) ?>
                                    </span>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="4" class="text-center py-5">
                                <i class="bi bi-pc-display fs-2 text-muted"></i>
                                <p class="text-muted mt-2 mb-0">
                                    No ATMs registered yet.
                                </p>
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>
    </div>


    <div class="col-xl-4">
        <div class="dashboard-card">

            <div class="card-header-custom">
                <div>
                    <h5>Quick Actions</h5>
                    <p>Common operations</p>
                </div>
            </div>

            <div class="quick-actions">

                <a href="incident_add.php" class="quick-action">
                    <div class="quick-icon purple-bg">
                        <i class="bi bi-plus-circle"></i>
                    </div>
                    <div>
                        <strong>Report Fault</strong>
                        <small>Create new incident</small>
                    </div>
                    <i class="bi bi-chevron-right"></i>
                </a>

                <a href="atms.php" class="quick-action">
                    <div class="quick-icon gold-bg">
                        <i class="bi bi-pc-display"></i>
                    </div>
                    <div>
                        <strong>Manage ATMs</strong>
                        <small>View ATM network</small>
                    </div>
                    <i class="bi bi-chevron-right"></i>
                </a>

                <a href="user_add.php" class="quick-action">
                    <div class="quick-icon green-bg">
                        <i class="bi bi-person-plus"></i>
                    </div>
                    <div>
                        <strong>Add Technician</strong>
                        <small>Register support staff</small>
                    </div>
                    <i class="bi bi-chevron-right"></i>
                </a>

                <a href="reports.php" class="quick-action">
                    <div class="quick-icon blue-bg">
                        <i class="bi bi-file-earmark-bar-graph"></i>
                    </div>
                    <div>
                        <strong>Generate Report</strong>
                        <small>Analyze ATM faults</small>
                    </div>
                    <i class="bi bi-chevron-right"></i>
                </a>

            </div>

        </div>
    </div>

</div>


<?php ob_start(); ?>

<script>
const faultLabels = <?= json_encode(array_column($fault_statistics, "category_name")) ?>;
const faultData   = <?= json_encode(array_map("intval", array_column($fault_statistics, "total"))) ?>;

new Chart(document.getElementById("faultChart"), {
    type: "bar",
    data: {
        labels: faultLabels,
        datasets: [{
            label: "Reported Faults",
            data: faultData,
            backgroundColor: "#8E1B8F",
            borderRadius: 6,
            barThickness: 25
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { precision: 0 },
                grid: { color: "#eeeeee" }
            },
            x: {
                grid: { display: false }
            }
        }
    }
});
</script>

<?php
$extra_scripts = ob_get_clean();

require_once "../includes/footer.php";
?>