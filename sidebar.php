<?php
$active_page = $active_page ?? "dashboard";
$role        = $_SESSION["role"] ?? "admin";

$role_labels = [
    "admin"           => "Administrator",
    "branch_officer"  => "Branch Officer",
    "support_officer" => "Support Officer",
    "technician"      => "Technician",
];
$current_role_label = $role_labels[$role] ?? "User";

if (!function_exists("nav_active")) {
    function nav_active(string $page, string $current): string {
        return $page === $current ? "active" : "";
    }
}

/* ---- Role-aware routing ---- */
$is_tech    = ($role === "technician");
$is_officer = in_array($role, ["branch_officer", "support_officer"], true);

if ($is_tech) {
    $url_dashboard = "technician.php";
    $url_incidents = "technician.php?action=incidents";
    $url_settings  = "technician.php?action=settings";
    $url_atms      = "#";
    $url_reports   = "#";
    $url_techs     = "#";
    $url_branches  = "#";
    $url_users     = "#";
} elseif ($is_officer) {
    $url_dashboard = "officer.php";
    $url_incidents = "officer.php?action=incidents";
    $url_atms      = "officer.php?action=atms";
    $url_settings  = "officer.php?action=settings";
    $url_reports   = "reports.php";
    $url_techs     = "#";
    $url_branches  = "#";
    $url_users     = "#";
} else {
    $url_dashboard = "dashboard.php";
    $url_incidents = "incidents.php";
    $url_atms      = "atms.php";
    $url_reports   = "reports.php";
    $url_settings  = "settings.php";
    $url_techs     = "users.php?role=technician";
    $url_branches  = "branches.php";
    $url_users     = "users.php";
}

/* ---- Badge count ---- */
$badge_count = 0;
if ($is_tech) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM incidents
        WHERE assigned_to = ? AND status IN ('assigned','in_progress')
    ");
    $stmt->execute([(int) $_SESSION["user_id"]]);
    $badge_count = (int) $stmt->fetchColumn();
} else {
    $badge_count = (int) ($open_incidents ?? 0);
}
?>


<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside class="sidebar">

    <div class="sidebar-brand">
        <div class="sidebar-logo">
            <img src="../assets/images/cbe-logo.png" alt="CBE">
        </div>
    </div>

    <div class="sidebar-menu">

        <p class="menu-title">MAIN MENU</p>

        <a href="<?= $url_dashboard ?>"
           class="sidebar-link <?= nav_active('dashboard', $active_page) ?>">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <?php if (!$is_tech): ?>
            <a href="<?= $url_atms ?>"
               class="sidebar-link <?= nav_active('atms', $active_page) ?>">
                <i class="bi bi-pc-display"></i>
                <span>ATMs</span>
            </a>
        <?php endif; ?>

        <a href="<?= $url_incidents ?>"
           class="sidebar-link <?= nav_active('incidents', $active_page) ?>">
            <i class="bi bi-exclamation-triangle"></i>
            <span><?= $is_tech ? "My Incidents" : "Incidents" ?></span>
            <?php if ($badge_count > 0): ?>
                <span class="notification-badge"><?= $badge_count ?></span>
            <?php endif; ?>
        </a>

        <?php if ($role === "admin"): ?>
            <a href="<?= $url_techs ?>"
               class="sidebar-link <?= nav_active('technicians', $active_page) ?>">
                <i class="bi bi-tools"></i>
                <span>Technicians</span>
            </a>
        <?php endif; ?>

        <?php if (!$is_tech): ?>
            <p class="menu-title mt-4">MANAGEMENT</p>
        <?php endif; ?>

        <?php if ($role === "admin"): ?>
            <a href="<?= $url_branches ?>"
               class="sidebar-link <?= nav_active('branches', $active_page) ?>">
                <i class="bi bi-building"></i>
                <span>Branches</span>
            </a>
        <?php endif; ?>

        <?php if (!$is_tech): ?>
            <a href="<?= $url_reports ?>"
               class="sidebar-link <?= nav_active('reports', $active_page) ?>">
                <i class="bi bi-bar-chart-fill"></i>
                <span>Reports</span>
            </a>
        <?php endif; ?>

        <?php if ($role === "admin"): ?>
            <a href="<?= $url_users ?>"
               class="sidebar-link <?= nav_active('users', $active_page) ?>">
                <i class="bi bi-people-fill"></i>
                <span>Users</span>
            </a>
        <?php endif; ?>

        <p class="menu-title mt-4">SYSTEM</p>

        <a href="<?= $url_settings ?>"
           class="sidebar-link <?= nav_active('settings', $active_page) ?>">
            <i class="bi bi-gear-fill"></i>
            <span>Settings</span>
        </a>

    </div>

    <div class="sidebar-bottom">
        <a href="../auth/logout.php" class="sidebar-link logout-link">
            <i class="bi bi-box-arrow-left"></i>
            <span>Logout</span>
        </a>
    </div>

</aside>


<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<main class="main-content">

    <header class="topbar">

        <div class="topbar-left">
            <button class="mobile-menu-btn" type="button">
                <i class="bi bi-list"></i>
            </button>

            <div class="system-title">
                <h1 class="title-main">ATM Support</h1>
                <h1 class="title-sub">Management System</h1>
                <div class="title-line"></div>
            </div>
        </div>

        <!-- Bell sits right before the user profile -->
        <div class="topbar-right">
            <?php require __DIR__ . "/notification_bell.php"; ?>

            <div class="user-profile">
                <div class="user-avatar">
                    <?= strtoupper(substr($_SESSION["full_name"], 0, 1)) ?>
                </div>
                <div class="user-info">
                    <strong><?= htmlspecialchars($_SESSION["full_name"]) ?></strong>
                    <small><?= htmlspecialchars($current_role_label) ?></small>
                </div>
                <i class="bi bi-chevron-down"></i>
            </div>
        </div>

    </header>

    <div class="dashboard-container">