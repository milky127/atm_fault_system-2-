<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = (int) $_SESSION["user_id"];

/* =========================================================
   POST: UPDATE PROFILE
========================================================= */

$errors  = [];
$success = $_GET["success"] ?? "";

$form = [
    "full_name" => "",
    "username"  => "",
    "phone"     => "",
];

/* Load current user */
$stmt = $pdo->prepare("
    SELECT u.*, b.branch_name
    FROM users u
    LEFT JOIN branches b ON u.branch_id = b.id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$user_id]);
$me = $stmt->fetch();

if (!$me) {
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

$form["full_name"] = $me["full_name"];
$form["username"]  = $me["username"];
$form["phone"]     = $me["phone"] ?? "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $section = $_POST["section"] ?? "";

    /* ---------- Update profile ---------- */
    if ($section === "profile") {

        $form["full_name"] = trim($_POST["full_name"] ?? "");
        $form["username"]  = strtolower(trim($_POST["username"] ?? ""));
        $form["phone"]     = trim($_POST["phone"] ?? "");

        if ($form["full_name"] === "") {
            $errors[] = "Full name is required.";
        }

        if ($form["username"] === "") {
            $errors[] = "Username is required.";
        } elseif (!preg_match('/^[a-z0-9_.]{3,50}$/', $form["username"])) {
            $errors[] = "Username must be 3–50 lowercase letters, digits, dot or underscore.";
        } else {
            $stmt = $pdo->prepare("
                SELECT id FROM users
                WHERE username = ? AND id <> ?
                LIMIT 1
            ");
            $stmt->execute([$form["username"], $user_id]);
            if ($stmt->fetch()) {
                $errors[] = "That username is already taken.";
            }
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("
                UPDATE users
                SET full_name = ?, username = ?, phone = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $form["full_name"],
                $form["username"],
                $form["phone"] !== "" ? $form["phone"] : null,
                $user_id,
            ]);

            $_SESSION["full_name"] = $form["full_name"];
            $_SESSION["username"]  = $form["username"];

            header("Location: settings.php?success=profile");
            exit;
        }
    }

    /* ---------- Change password ---------- */
    elseif ($section === "password") {

        $current = $_POST["current_password"] ?? "";
        $new     = $_POST["new_password"]     ?? "";
        $confirm = $_POST["confirm_password"] ?? "";

        if ($current === "") {
            $errors[] = "Current password is required.";
        } elseif (!password_verify($current, $me["password"])) {
            $errors[] = "Current password is incorrect.";
        }

        if (strlen($new) < 8) {
            $errors[] = "New password must be at least 8 characters.";
        } elseif ($new !== $confirm) {
            $errors[] = "New passwords do not match.";
        } elseif ($new === $current) {
            $errors[] = "New password must be different from the current one.";
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $user_id]);

            header("Location: settings.php?success=password");
            exit;
        }
    }
}

/* =========================================================
   SYSTEM INFO
========================================================= */

$db_version = $pdo->query("SELECT VERSION()")->fetchColumn();

$counts = [
    "users"      => (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    "branches"   => (int) $pdo->query("SELECT COUNT(*) FROM branches")->fetchColumn(),
    "atms"       => (int) $pdo->query("SELECT COUNT(*) FROM atms")->fetchColumn(),
    "incidents"  => (int) $pdo->query("SELECT COUNT(*) FROM incidents")->fetchColumn(),
    "categories" => (int) $pdo->query("SELECT COUNT(*) FROM fault_categories")->fetchColumn(),
];

$role_labels = [
    "admin"           => "Administrator",
    "branch_officer"  => "Branch Officer",
    "support_officer" => "Support Officer",
    "technician"      => "Technician",
];

$active_page = "settings";
$page_title  = "Settings";

require_once "../includes/header.php";
require_once "../includes/sidebar.php";
?>


<div class="page-heading">

    <div>
        <h2>Settings</h2>
        <p>Manage your account and view system information.</p>
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


<?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <strong>
            <i class="bi bi-exclamation-circle-fill me-1"></i>
            Please fix the following:
        </strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>


<!-- ============ SUMMARY ============ -->

<div class="row g-4 mb-4">

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>ROLE</p>
                <h3 style="font-size:1.2rem;">
                    <?= htmlspecialchars($role_labels[$me["role"]] ?? $me["role"]) ?>
                </h3>
                <span>Access level</span>
            </div>
            <div class="stat-icon purple-icon">
                <i class="bi bi-shield-fill-check"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>BRANCH</p>
                <h3 style="font-size:1.2rem;">
                    <?= $me["branch_name"]
                        ? htmlspecialchars($me["branch_name"])
                        : '<span class="text-muted">—</span>' ?>
                </h3>
                <span>Assigned branch</span>
            </div>
            <div class="stat-icon gold-icon">
                <i class="bi bi-building"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>MEMBER SINCE</p>
                <h3 style="font-size:1.2rem;">
                    <?= htmlspecialchars(date("M j, Y", strtotime($me["created_at"]))) ?>
                </h3>
                <span>Account created</span>
            </div>
            <div class="stat-icon green-icon">
                <i class="bi bi-calendar-check"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>STATUS</p>
                <h3 style="font-size:1.2rem;">
                    <span class="atm-status operational">
                        <span></span> <?= ucfirst($me["status"]) ?>
                    </span>
                </h3>
                <span>Account state</span>
            </div>
            <div class="stat-icon green-icon">
                <i class="bi bi-person-check-fill"></i>
            </div>
        </div>
    </div>

</div>


<!-- ============ FORMS ROW ============ -->

<div class="row g-4 mb-4">

    <!-- PROFILE -->
    <div class="col-xl-6">
        <div class="dashboard-card h-100">

            <div class="card-header-custom">
                <div>
                    <h5>Profile Information</h5>
                    <p>Your display name and login details</p>
                </div>
            </div>

            <form method="POST" action="settings.php" class="row g-3">
                <input type="hidden" name="section" value="profile">

                <div class="col-12">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input
                        type="text"
                        name="full_name"
                        class="form-control"
                        value="<?= htmlspecialchars($form['full_name']) ?>"
                        maxlength="100"
                        required
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label">Username <span class="text-danger">*</span></label>
                    <input
                        type="text"
                        name="username"
                        class="form-control"
                        value="<?= htmlspecialchars($form['username']) ?>"
                        maxlength="50"
                        required
                    >
                    <small class="text-muted">lowercase, no spaces</small>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input
                        type="text"
                        name="phone"
                        class="form-control"
                        value="<?= htmlspecialchars($form['phone']) ?>"
                        maxlength="30"
                    >
                </div>

                <div class="col-12">
                    <label class="form-label">Role</label>
                    <input
                        type="text"
                        class="form-control"
                        value="<?= htmlspecialchars($role_labels[$me["role"]] ?? $me["role"]) ?>"
                        disabled
                    >
                    <small class="text-muted">
                        Only an administrator can change your role.
                    </small>
                </div>

                <div class="col-12 d-flex justify-content-end mt-2">
                    <button type="submit" class="btn btn-gold">
                        <i class="bi bi-check-lg me-1"></i> Save Profile
                    </button>
                </div>
            </form>

        </div>
    </div>


    <!-- PASSWORD -->
    <div class="col-xl-6">
        <div class="dashboard-card h-100">

            <div class="card-header-custom">
                <div>
                    <h5>Change Password</h5>
                    <p>Keep your account secure</p>
                </div>
            </div>

            <form method="POST" action="settings.php" class="row g-3">
                <input type="hidden" name="section" value="password">

                <div class="col-12">
                    <label class="form-label">
                        Current Password <span class="text-danger">*</span>
                    </label>
                    <input
                        type="password"
                        name="current_password"
                        class="form-control"
                        placeholder="Enter current password"
                        required
                    >
                </div>

                <div class="col-12">
                    <label class="form-label">
                        New Password <span class="text-danger">*</span>
                    </label>
                    <input
                        type="password"
                        name="new_password"
                        class="form-control"
                        placeholder="At least 8 characters"
                        minlength="8"
                        required
                    >
                </div>

                <div class="col-12">
                    <label class="form-label">
                        Confirm New Password <span class="text-danger">*</span>
                    </label>
                    <input
                        type="password"
                        name="confirm_password"
                        class="form-control"
                        placeholder="Repeat new password"
                        minlength="8"
                        required
                    >
                </div>

                <div class="col-12">
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        You will stay logged in after changing your password.
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


<!-- ============ SYSTEM INFO ============ -->

<div class="dashboard-card mb-4">

    <div class="card-header-custom">
        <div>
            <h5>System Information</h5>
            <p>Environment details and data overview</p>
        </div>
    </div>

    <div class="row g-3">

        <div class="col-md-6">
            <table class="table mb-0">
                <tbody>
                    <tr>
                        <th style="width:45%;">Application</th>
                        <td>ATM Fault Reporting &amp; Tracking System</td>
                    </tr>
                    <tr>
                        <th>Organisation</th>
                        <td>Commercial Bank of Ethiopia</td>
                    </tr>
                    <tr>
                        <th>PHP Version</th>
                        <td><code><?= htmlspecialchars(PHP_VERSION) ?></code></td>
                    </tr>
                    <tr>
                        <th>MySQL Version</th>
                        <td><code><?= htmlspecialchars($db_version) ?></code></td>
                    </tr>
                    <tr>
                        <th>Server Time</th>
                        <td><?= htmlspecialchars(date("Y-m-d H:i:s")) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="col-md-6">
            <table class="table mb-0">
                <tbody>
                    <tr>
                        <th style="width:45%;">Users</th>
                        <td><span class="badge bg-secondary"><?= $counts["users"] ?></span></td>
                    </tr>
                    <tr>
                        <th>Branches</th>
                        <td><span class="badge bg-secondary"><?= $counts["branches"] ?></span></td>
                    </tr>
                    <tr>
                        <th>ATMs</th>
                        <td><span class="badge bg-secondary"><?= $counts["atms"] ?></span></td>
                    </tr>
                    <tr>
                        <th>Incidents</th>
                        <td><span class="badge bg-secondary"><?= $counts["incidents"] ?></span></td>
                    </tr>
                    <tr>
                        <th>Fault Categories</th>
                        <td><span class="badge bg-secondary"><?= $counts["categories"] ?></span></td>
                    </tr>
                </tbody>
            </table>
        </div>

    </div>

</div>


<!-- ============ ABOUT ============ -->

<div class="dashboard-card">

    <div class="card-header-custom">
        <div>
            <h5>About This System</h5>
            <p>Purpose and workflow</p>
        </div>
    </div>

    <p>
        This system centralises ATM fault reporting, incident tracking,
        technician assignment, and maintenance history for the Commercial
        Bank of Ethiopia ATM network.
    </p>

    <p class="mb-2"><strong>Core workflow:</strong></p>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <span class="badge" style="background:#8E1B8F;">Reported</span>
        <span class="text-muted">→</span>
        <span class="badge" style="background:#0D6EFD;">Under Review</span>
        <span class="text-muted">→</span>
        <span class="badge" style="background:#D4AF37;color:#252525;">Assigned</span>
        <span class="text-muted">→</span>
        <span class="badge" style="background:#E0A800;color:#252525;">In Progress</span>
        <span class="text-muted">→</span>
        <span class="badge" style="background:#198754;">Resolved</span>
        <span class="text-muted">→</span>
        <span class="badge" style="background:#0DCAF0;color:#252525;">Verified</span>
        <span class="text-muted">→</span>
        <span class="badge" style="background:#495057;">Closed</span>
    </div>

    <p class="mb-0 text-muted small">
        Built with PHP, MySQL, Bootstrap 5, and Chart.js.
    </p>

</div>


<?php require_once "../includes/footer.php"; ?>