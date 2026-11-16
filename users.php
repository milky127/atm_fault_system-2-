<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}

/* ---------- Filters ---------- */
$search        = trim($_GET["search"] ?? "");
$role_filter   = $_GET["role"] ?? "";
$status_filter = $_GET["status"] ?? "";

$valid_roles = ["admin", "branch_officer", "support_officer", "technician"];

/* ---------- Main query ---------- */
$sql = "SELECT u.*, b.branch_name
        FROM users u
        LEFT JOIN branches b ON u.branch_id = b.id
        WHERE 1=1";

$params = [];

if ($search !== "") {
    $sql .= " AND (
        u.full_name LIKE :search
        OR u.username LIKE :search
        OR u.phone    LIKE :search
        OR b.branch_name LIKE :search
    )";
    $params["search"] = "%" . $search . "%";
}

if (in_array($role_filter, $valid_roles, true)) {
    $sql .= " AND u.role = :role";
    $params["role"] = $role_filter;
}

if (in_array($status_filter, ["active", "inactive"], true)) {
    $sql .= " AND u.status = :status";
    $params["status"] = $status_filter;
}

$sql .= " ORDER BY u.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

/* ---------- Branches for filter ---------- */
$branches = $pdo->query("
    SELECT id, branch_name FROM branches ORDER BY branch_name
")->fetchAll();

/* ---------- Summary ---------- */
$summary = [
    "total"      => (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    "admins"     => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn(),
    "officers"   => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('branch_officer','support_officer')")->fetchColumn(),
    "technicians"=> (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'technician'")->fetchColumn(),
];

$success = $_GET["success"] ?? "";
$error   = $_GET["error"]   ?? "";

$role_labels = [
    "admin"           => "Administrator",
    "branch_officer"  => "Branch Officer",
    "support_officer" => "Support Officer",
    "technician"      => "Technician",
];

$role_colors = [
    "admin"           => "purple-bg",
    "branch_officer"  => "blue-bg",
    "support_officer" => "gold-bg",
    "technician"      => "green-bg",
];

$active_page = ($role_filter === "technician") ? "technicians" : "users";
$page_title  = ($role_filter === "technician") ? "Technicians" : "User Management";

require_once "../includes/header.php";
require_once "../includes/sidebar.php";
?>


<div class="page-heading">

    <div>
        <h2>
            <?= $role_filter === "technician" ? "Technicians" : "User Management" ?>
        </h2>
        <p>
            <?php if ($role_filter === "technician"): ?>
                Manage technician accounts and assignments.
            <?php else: ?>
                Manage system users, roles and access.
            <?php endif; ?>
        </p>
    </div>

    <div>
        <a href="user_add.php" class="btn btn-gold">
            <i class="bi bi-person-plus me-2"></i>
            Add New User
        </a>
    </div>

</div>


<?php if ($success !== ""): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?= match ($success) {
            "added"      => "User created successfully.",
            "updated"    => "User updated successfully.",
            "deleted"    => "User deleted successfully.",
            "activated"  => "User activated.",
            "deactivated"=> "User deactivated.",
            default      => "Operation completed.",
        } ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error !== ""): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-circle-fill me-2"></i>
        <?= match ($error) {
            "notfound"       => "User not found.",
            "self_delete"    => "You cannot delete your own account.",
            "self_deactivate"=> "You cannot deactivate your own account.",
            "self_role"      => "You cannot change your own role.",
            "last_admin"     => "Cannot remove the last active administrator.",
            "in_use"         => "This user has assigned incidents and cannot be deleted. Deactivate instead.",
            "db"             => "Database error.",
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
                <p>TOTAL USERS</p>
                <h3><?= $summary["total"] ?></h3>
                <span>Registered accounts</span>
            </div>
            <div class="stat-icon purple-icon">
                <i class="bi bi-people-fill"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>ADMINS</p>
                <h3><?= $summary["admins"] ?></h3>
                <span>Full access</span>
            </div>
            <div class="stat-icon red-icon">
                <i class="bi bi-shield-fill"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>OFFICERS</p>
                <h3><?= $summary["officers"] ?></h3>
                <span class="gold-text">
                    <i class="bi bi-person-badge"></i>
                    Branch &amp; support
                </span>
            </div>
            <div class="stat-icon gold-icon">
                <i class="bi bi-person-badge"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>TECHNICIANS</p>
                <h3><?= $summary["technicians"] ?></h3>
                <span class="success-text">
                    <i class="bi bi-tools"></i>
                    Field support
                </span>
            </div>
            <div class="stat-icon green-icon">
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
            <p>Find users quickly</p>
        </div>
    </div>

    <form method="GET" action="users.php" class="row g-3">

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
                    placeholder="Name, username, phone, branch…"
                    value="<?= htmlspecialchars($search) ?>"
                >
            </div>
        </div>

        <div class="col-lg-3">
            <label class="form-label">Role</label>
            <select name="role" class="form-select">
                <option value="">All roles</option>
                <option value="admin"           <?= $role_filter === "admin"           ? "selected" : "" ?>>Administrator</option>
                <option value="branch_officer"  <?= $role_filter === "branch_officer"  ? "selected" : "" ?>>Branch Officer</option>
                <option value="support_officer" <?= $role_filter === "support_officer" ? "selected" : "" ?>>Support Officer</option>
                <option value="technician"      <?= $role_filter === "technician"      ? "selected" : "" ?>>Technician</option>
            </select>
        </div>

        <div class="col-lg-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">All</option>
                <option value="active"   <?= $status_filter === "active"   ? "selected" : "" ?>>Active</option>
                <option value="inactive" <?= $status_filter === "inactive" ? "selected" : "" ?>>Inactive</option>
            </select>
        </div>

        <div class="col-lg-2 d-flex align-items-end gap-2">
            <a href="users.php" class="btn btn-outline-secondary flex-fill">Clear</a>
            <button type="submit" class="btn btn-gold flex-fill">
                <i class="bi bi-funnel"></i>
            </button>
        </div>

    </form>

</div>


<!-- ============ TABLE ============ -->

<div class="dashboard-card">

    <div class="card-header-custom">
        <div>
            <h5>
                <?= $role_filter === "technician" ? "Technician List" : "User List" ?>
            </h5>
            <p>
                <?= count($users) ?>
                user<?= count($users) === 1 ? "" : "s" ?>
                <?php if ($search !== "" || $role_filter !== "" || $status_filter !== ""): ?>
                    <span class="text-muted">(filtered)</span>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="table-responsive">

        <table class="table atm-table align-middle">

            <thead>
                <tr>
                    <th>User</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Branch</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>

            <tbody>

            <?php if (count($users) > 0): ?>

                <?php foreach ($users as $u): ?>

                    <?php
                    $initial = strtoupper(substr($u["full_name"], 0, 1));
                    $is_self = ((int) $u["id"] === (int) $_SESSION["user_id"]);
                    ?>

                    <tr>

                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="user-avatar" style="
                                    width:36px;height:36px;font-size:14px;
                                    background:var(--cbe-purple,#8E1B8F);
                                    color:#fff;border-radius:50%;
                                    display:flex;align-items:center;justify-content:center;
                                    flex-shrink:0;">
                                    <?= $initial ?>
                                </div>
                                <div>
                                    <strong><?= htmlspecialchars($u["full_name"]) ?></strong>
                                    <?php if ($is_self): ?>
                                        <span class="badge bg-secondary ms-1">You</span>
                                    <?php endif; ?>
                                    <br>
                                    <small class="text-muted">
                                        Joined <?= htmlspecialchars(date("M j, Y", strtotime($u["created_at"]))) ?>
                                    </small>
                                </div>
                            </div>
                        </td>

                        <td><code><?= htmlspecialchars($u["username"]) ?></code></td>

                        <td>
                            <span class="badge
                                <?= $u["role"] === "admin"           ? "bg-dark" : "" ?>
                                <?= $u["role"] === "branch_officer"  ? "bg-primary" : "" ?>
                                <?= $u["role"] === "support_officer" ? "bg-warning text-dark" : "" ?>
                                <?= $u["role"] === "technician"      ? "bg-success" : "" ?>">
                                <?= htmlspecialchars($role_labels[$u["role"]] ?? $u["role"]) ?>
                            </span>
                        </td>
<td>
    <?= !empty($u["email"])
        ? '<a href="mailto:' . htmlspecialchars($u["email"]) . '">' . htmlspecialchars($u["email"]) . '</a>'
        : '<span class="text-muted">—</span>' ?>
</td>
                        <td>
                            <?= $u["branch_name"]
                                ? htmlspecialchars($u["branch_name"])
                                : '<span class="text-muted">—</span>' ?>
                        </td>

                        <td>
                            <?= $u["phone"]
                                ? htmlspecialchars($u["phone"])
                                : '<span class="text-muted">—</span>' ?>
                        </td>

                        <td>
                            <?php if ($u["status"] === "active"): ?>
                                <span class="atm-status operational">
                                    <span></span> Active
                                </span>
                            <?php else: ?>
                                <span class="atm-status down">
                                    <span></span> Inactive
                                </span>
                            <?php endif; ?>
                        </td>

                        <td class="text-end">
                            <div class="d-inline-flex gap-1">

                                <a href="user_edit.php?id=<?= (int) $u["id"] ?>"
                                   class="btn btn-sm btn-outline-purple" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>

                                <?php if (!$is_self): ?>

                                    <form method="POST" action="user_toggle.php" class="d-inline">
                                        <input type="hidden" name="id" value="<?= (int) $u["id"] ?>">
                                        <?php if ($u["status"] === "active"): ?>
                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-secondary"
                                                    title="Deactivate">
                                                <i class="bi bi-pause-fill"></i>
                                            </button>
                                        <?php else: ?>
                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-success"
                                                    title="Activate">
                                                <i class="bi bi-play-fill"></i>
                                            </button>
                                        <?php endif; ?>
                                    </form>

                                    <form method="POST" action="user_delete.php" class="d-inline"
                                          onsubmit="return confirmDelete('<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>');">
                                        <input type="hidden" name="id" value="<?= (int) $u["id"] ?>">
                                        <button type="submit"
                                                class="btn btn-sm btn-outline-danger"
                                                title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>

                                <?php endif; ?>

                            </div>
                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="7" class="text-center py-5">
                        <div class="empty-state">
                            <i class="bi bi-people"></i>
                            <p>
                                <?php if ($search !== "" || $role_filter !== "" || $status_filter !== ""): ?>
                                    No users match your filters.
                                <?php else: ?>
                                    No users registered yet.
                                <?php endif; ?>
                            </p>
                            <?php if ($search === "" && $role_filter === "" && $status_filter === ""): ?>
                                <a href="user_add.php" class="btn btn-gold mt-2">
                                    <i class="bi bi-person-plus me-1"></i>
                                    Add your first user
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
function confirmDelete(fullName, username) {
    return confirm(
        "Delete user " + fullName + " (" + username + ")?\n\n" +
        "This cannot be undone."
    );
}
</script>
HTML;

require_once "../includes/footer.php";