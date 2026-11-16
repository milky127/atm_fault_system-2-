<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}

$id = (int) ($_GET["id"] ?? 0);

if ($id <= 0) {
    header("Location: atms.php?error=notfound");
    exit;
}

/* ---------- Load ATM ---------- */
$stmt = $pdo->prepare("
    SELECT a.*, b.branch_name
    FROM atms a
    LEFT JOIN branches b ON a.branch_id = b.id
    WHERE a.id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$atm = $stmt->fetch();

if (!$atm) {
    header("Location: atms.php?error=notfound");
    exit;
}

/* ---------- Incidents for this ATM ---------- */
$stmt = $pdo->prepare("
    SELECT
        i.incident_number,
        i.title,
        i.priority,
        i.status,
        i.reported_at,
        fc.category_name,
        u.full_name AS reporter_name,
        t.full_name AS technician_name
    FROM incidents i
    LEFT JOIN fault_categories fc ON i.category_id = fc.id
    LEFT JOIN users u             ON i.reported_by = u.id
    LEFT JOIN users t             ON i.assigned_to = t.id
    WHERE i.atm_id = ?
    ORDER BY i.reported_at DESC
");
$stmt->execute([$id]);
$incidents = $stmt->fetchAll();

/* ---------- Stats ---------- */
$total_incidents = count($incidents);
$open_incidents_count = 0;
$resolved_count  = 0;

foreach ($incidents as $inc) {
    if ($inc["status"] === "closed") {
        $resolved_count++;
    } else {
        $open_incidents_count++;
    }
}

$status_class = match ($atm["status"]) {
    "operational" => "operational",
    "down"        => "down",
    default       => "maintenance",
};

$active_page = "atms";
$page_title  = $atm["atm_code"];

require_once "../includes/header.php";
require_once "../includes/sidebar.php";
?>


<div class="page-heading">

    <div>
        <h2><?= htmlspecialchars($atm["atm_code"]) ?></h2>
        <p>
            <?= htmlspecialchars($atm["location"]) ?>
            <?php if ($atm["branch_name"]): ?>
                · <?= htmlspecialchars($atm["branch_name"]) ?>
            <?php endif; ?>
        </p>
    </div>

    <div class="d-flex gap-2">
        <a href="atm_edit.php?id=<?= $id ?>" class="btn btn-gold">
            <i class="bi bi-pencil me-1"></i> Edit
        </a>
        <a href="atms.php" class="btn btn-outline-purple">
            <i class="bi bi-arrow-left me-2"></i> Back
        </a>
    </div>

</div>


<!-- ============ STATS ============ -->

<div class="row g-4 mb-4">

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>TOTAL INCIDENTS</p>
                <h3><?= $total_incidents ?></h3>
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
                <h3><?= $open_incidents_count ?></h3>
                <span class="danger-text">
                    <i class="bi bi-clock-fill"></i> Needs attention
                </span>
            </div>
            <div class="stat-icon red-icon">
                <i class="bi bi-clock-history"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>CLOSED</p>
                <h3><?= $resolved_count ?></h3>
                <span class="success-text">
                    <i class="bi bi-check-circle-fill"></i> Completed
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
                <p>CURRENT STATUS</p>
                <h3 style="font-size:1.3rem;">
                    <span class="atm-status <?= $status_class ?>">
                        <span></span> <?= ucfirst($atm["status"]) ?>
                    </span>
                </h3>
                <span>Live</span>
            </div>
            <div class="stat-icon gold-icon">
                <i class="bi bi-activity"></i>
            </div>
        </div>
    </div>

</div>


<!-- ============ DETAILS ============ -->

<div class="dashboard-card mb-4">

    <div class="card-header-custom">
        <div>
            <h5>ATM Details</h5>
            <p>Registered information</p>
        </div>
    </div>

    <table class="table mb-0">
        <tbody>

            <tr>
                <th style="width:30%;">ATM Code</th>
                <td><strong><?= htmlspecialchars($atm["atm_code"]) ?></strong></td>
            </tr>

            <tr>
                <th>Branch</th>
                <td>
                    <?php if ($atm["branch_name"]): ?>
                        <?= htmlspecialchars($atm["branch_name"]) ?>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th>Location</th>
                <td><?= htmlspecialchars($atm["location"]) ?></td>
            </tr>

            <tr>
                <th>Model</th>
                <td>
                    <?= $atm["model"]
                        ? htmlspecialchars($atm["model"])
                        : '<span class="text-muted">—</span>' ?>
                </td>
            </tr>

            <tr>
                <th>Serial Number</th>
                <td>
                    <?= $atm["serial_number"]
                        ? htmlspecialchars($atm["serial_number"])
                        : '<span class="text-muted">—</span>' ?>
                </td>
            </tr>

            <tr>
                <th>Installation Date</th>
                <td>
                    <?= $atm["installation_date"]
                        ? htmlspecialchars($atm["installation_date"])
                        : '<span class="text-muted">—</span>' ?>
                </td>
            </tr>

            <tr>
                <th>Status</th>
                <td>
                    <span class="atm-status <?= $status_class ?>">
                        <span></span> <?= ucfirst($atm["status"]) ?>
                    </span>
                </td>
            </tr>

            <tr>
                <th>Registered</th>
                <td><?= htmlspecialchars($atm["created_at"]) ?></td>
            </tr>

        </tbody>
    </table>

</div>


<!-- ============ FAULT HISTORY ============ -->

<div class="dashboard-card">

    <div class="card-header-custom">
        <div>
            <h5>Fault History</h5>
            <p>All incidents reported for this ATM</p>
        </div>
    </div>

    <div class="table-responsive">

        <table class="table atm-table align-middle">

            <thead>
                <tr>
                    <th>Incident</th>
                    <th>Category</th>
                    <th>Priority</th>
                    <th>Reported</th>
                    <th>Assigned To</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>

            <?php if (count($incidents) > 0): ?>

                <?php foreach ($incidents as $inc): ?>

                    <?php
                    $sb = "status-" . $inc["status"];

                    $pb = match ($inc["priority"]) {
                        "critical" => "bg-danger",
                        "high"     => "bg-warning text-dark",
                        "medium"   => "bg-info text-dark",
                        default    => "bg-secondary",
                    };
                    ?>

                    <tr>

                        <td>
                            <strong><?= htmlspecialchars($inc["incident_number"]) ?></strong>
                            <br>
                            <small class="text-muted">
                                <?= htmlspecialchars($inc["title"]) ?>
                            </small>
                        </td>

                        <td><?= htmlspecialchars($inc["category_name"] ?? "—") ?></td>

                        <td>
                            <span class="badge <?= $pb ?>">
                                <?= ucfirst($inc["priority"]) ?>
                            </span>
                        </td>

                        <td>
                            <?= htmlspecialchars($inc["reported_at"]) ?>
                            <?php if (!empty($inc["reporter_name"])): ?>
                                <br>
                                <small class="text-muted">
                                    by <?= htmlspecialchars($inc["reporter_name"]) ?>
                                </small>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= $inc["technician_name"]
                                ? htmlspecialchars($inc["technician_name"])
                                : '<span class="text-muted">Unassigned</span>' ?>
                        </td>

                        <td>
                            <span class="status-badge <?= $sb ?>">
                                <?= ucfirst(str_replace("_", " ", $inc["status"])) ?>
                            </span>
                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="6" class="text-center py-5">
                        <div class="empty-state">
                            <i class="bi bi-check-circle"></i>
                            <p>No incidents recorded for this ATM.</p>
                        </div>
                    </td>
                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<?php require_once "../includes/footer.php"; ?>