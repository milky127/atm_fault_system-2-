<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}

$id = (int) ($_GET["id"] ?? $_POST["id"] ?? 0);

if ($id <= 0) {
    header("Location: users.php?error=notfound");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: users.php?error=notfound");
    exit;
}

$is_self = ((int) $user["id"] === (int) $_SESSION["user_id"]);

$errors = [];

$form = [
    "full_name" => $user["full_name"],
    "username"  => $user["username"],
    "email"     => $user["email"] ?? "",
    "role"      => $user["role"],
    "branch_id" => $user["branch_id"] ?? "",
    "phone"     => $user["phone"] ?? "",
    "status"    => $user["status"],
];

$valid_roles    = ["admin", "branch_officer", "support_officer", "technician"];
$valid_statuses = ["active", "inactive"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $form["full_name"] = trim($_POST["full_name"] ?? "");
    $form["username"]  = strtolower(trim($_POST["username"] ?? ""));
    $form["email"]     = strtolower(trim($_POST["email"] ?? ""));
    $form["role"]      = $is_self ? $user["role"] : ($_POST["role"] ?? "branch_officer");
    $form["branch_id"] = trim($_POST["branch_id"] ?? "");
    $form["phone"]     = trim($_POST["phone"] ?? "");
    $form["status"]    = $is_self ? $user["status"] : ($_POST["status"] ?? "active");

    $password         = $_POST["password"] ?? "";
    $password_confirm = $_POST["password_confirm"] ?? "";

    /* Full name */
    if ($form["full_name"] === "") {
        $errors["full_name"] = "Full name is required.";
    }

    /* Username */
    if ($form["username"] === "") {
        $errors["username"] = "Username is required.";
    } elseif (!preg_match('/^[a-z0-9_.]{3,50}$/', $form["username"])) {
        $errors["username"] = "Username must be 3–50 chars: lowercase letters, digits, dot or underscore.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
        $stmt->execute([$form["username"], $id]);
        if ($stmt->fetch()) $errors["username"] = "This username is already taken.";
    }

    /* Email */
    if ($form["email"] === "") {
        $errors["email"] = "Email is required.";
    } elseif (!filter_var($form["email"], FILTER_VALIDATE_EMAIL)) {
        $errors["email"] = "Please enter a valid email address.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
        $stmt->execute([$form["email"], $id]);
        if ($stmt->fetch()) $errors["email"] = "This email is already in use.";
    }

    /* Role / status (skip if editing self) */
    if (!$is_self && !in_array($form["role"], $valid_roles, true)) {
        $errors["role"] = "Invalid role selected.";
    }
    if (!$is_self && !in_array($form["status"], $valid_statuses, true)) {
        $errors["status"] = "Invalid status selected.";
    }

    /* Branch */
    if ($form["branch_id"] !== "") {
        $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? LIMIT 1");
        $stmt->execute([(int) $form["branch_id"]]);
        if (!$stmt->fetch()) $errors["branch_id"] = "Selected branch does not exist.";
    }

    /* Password (optional) */
    if ($password !== "" || $password_confirm !== "") {
        if (strlen($password) < 8) {
            $errors["password"] = "Password must be at least 8 characters.";
        } elseif ($password !== $password_confirm) {
            $errors["password_confirm"] = "Passwords do not match.";
        }
    }

    /* Guard: don't remove the last active admin */
    if (!$is_self) {
        $was_admin_active     = ($user["role"] === "admin" && $user["status"] === "active");
        $will_be_admin_active = ($form["role"] === "admin" && $form["status"] === "active");

        if ($was_admin_active && !$will_be_admin_active) {
            $admin_count = (int) $pdo->query("
                SELECT COUNT(*) FROM users
                WHERE role = 'admin' AND status = 'active'
            ")->fetchColumn();

            if ($admin_count <= 1) {
                $errors["role"] = "Cannot remove the last active administrator.";
            }
        }
    }

    /* Update */
    if (empty($errors)) {

        if ($password !== "") {
            $stmt = $pdo->prepare("
                UPDATE users SET
                    full_name = ?, username = ?, email = ?, role = ?,
                    branch_id = ?, phone = ?, status = ?,
                    password = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $form["full_name"],
                $form["username"],
                $form["email"],
                $form["role"],
                $form["branch_id"] !== "" ? (int) $form["branch_id"] : null,
                $form["phone"] !== "" ? $form["phone"] : null,
                $form["status"],
                password_hash($password, PASSWORD_DEFAULT),
                $id,
            ]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE users SET
                    full_name = ?, username = ?, email = ?, role = ?,
                    branch_id = ?, phone = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $form["full_name"],
                $form["username"],
                $form["email"],
                $form["role"],
                $form["branch_id"] !== "" ? (int) $form["branch_id"] : null,
                $form["phone"] !== "" ? $form["phone"] : null,
                $form["status"],
                $id,
            ]);
        }

        header("Location: users.php?success=updated");
        exit;
    }
}

$branches = $pdo->query("
    SELECT id, branch_name FROM branches ORDER BY branch_name
")->fetchAll();

$active_page = "users";
$page_title  = "Edit User";

require_once "../includes/header.php";
require_once "../includes/sidebar.php";
?>


<div class="page-heading">

    <div>
        <h2>Edit User</h2>
        <p>Updating <strong><?= htmlspecialchars($user["full_name"]) ?></strong></p>
    </div>

    <div>
        <a href="users.php" class="btn btn-outline-purple">
            <i class="bi bi-arrow-left me-2"></i>
            Back to Users
        </a>
    </div>

</div>


<?php if ($is_self): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle-fill me-2"></i>
        You are editing your own account. Role and status cannot be changed here.
    </div>
<?php endif; ?>


<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <strong>
            <i class="bi bi-exclamation-circle-fill me-1"></i>
            Please fix the following:
        </strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>


<div class="dashboard-card">

    <div class="card-header-custom">
        <div>
            <h5>Account Information</h5>
            <p>Leave password blank to keep the current one</p>
        </div>
    </div>

    <form method="POST" action="user_edit.php" class="row g-3">

        <input type="hidden" name="id" value="<?= $id ?>">

        <!-- Full Name -->
        <div class="col-md-6">
            <label class="form-label">Full Name <span class="text-danger">*</span></label>
            <input
                type="text"
                name="full_name"
                class="form-control <?= isset($errors['full_name']) ? 'is-invalid' : '' ?>"
                value="<?= htmlspecialchars($form['full_name']) ?>"
                maxlength="100"
                required
            >
            <?php if (isset($errors['full_name'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['full_name']) ?></div>
            <?php endif; ?>
        </div>

        <!-- Username -->
        <div class="col-md-6">
            <label class="form-label">Username <span class="text-danger">*</span></label>
            <input
                type="text"
                name="username"
                class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>"
                value="<?= htmlspecialchars($form['username']) ?>"
                maxlength="50"
                required
            >
            <?php if (isset($errors['username'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['username']) ?></div>
            <?php endif; ?>
        </div>

        <!-- Email -->
        <div class="col-md-6">
            <label class="form-label">
                <i class="bi bi-envelope me-1"></i>
                Email <span class="text-danger">*</span>
            </label>
            <input
                type="email"
                name="email"
                class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                value="<?= htmlspecialchars($form['email']) ?>"
                maxlength="150"
                required
            >
            <?php if (isset($errors['email'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['email']) ?></div>
            <?php endif; ?>
            <small class="text-muted">Used for assignment notifications</small>
        </div>

        <!-- Phone -->
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

        <!-- Role -->
        <div class="col-md-6">
            <label class="form-label">Role <span class="text-danger">*</span></label>
            <select name="role" class="form-select" <?= $is_self ? 'disabled' : '' ?>>
                <option value="admin"           <?= $form['role'] === 'admin'           ? 'selected' : '' ?>>Administrator</option>
                <option value="branch_officer"  <?= $form['role'] === 'branch_officer'  ? 'selected' : '' ?>>Branch Officer</option>
                <option value="support_officer" <?= $form['role'] === 'support_officer' ? 'selected' : '' ?>>Support Officer</option>
                <option value="technician"      <?= $form['role'] === 'technician'      ? 'selected' : '' ?>>Technician</option>
            </select>
            <?php if ($is_self): ?>
                <input type="hidden" name="role" value="<?= htmlspecialchars($form['role']) ?>">
            <?php endif; ?>
            <?php if (isset($errors['role'])): ?>
                <div class="text-danger small mt-1"><?= htmlspecialchars($errors['role']) ?></div>
            <?php endif; ?>
        </div>

        <!-- Branch -->
        <div class="col-md-6">
            <label class="form-label">Branch</label>
            <select name="branch_id" class="form-select <?= isset($errors['branch_id']) ? 'is-invalid' : '' ?>">
                <option value="">— None / not applicable —</option>
                <?php foreach ($branches as $b): ?>
                    <option
                        value="<?= (int) $b["id"] ?>"
                        <?= (string) $form["branch_id"] === (string) $b["id"] ? "selected" : "" ?>
                    >
                        <?= htmlspecialchars($b["branch_name"]) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['branch_id'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['branch_id']) ?></div>
            <?php endif; ?>
        </div>

        <!-- Status -->
        <div class="col-md-6">
            <label class="form-label">Status <span class="text-danger">*</span></label>
            <select name="status" class="form-select" <?= $is_self ? 'disabled' : '' ?>>
                <option value="active"   <?= $form['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $form['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
            <?php if ($is_self): ?>
                <input type="hidden" name="status" value="<?= htmlspecialchars($form['status']) ?>">
            <?php endif; ?>
        </div>

        <div class="col-12"><hr></div>

        <div class="col-12">
            <p class="text-muted mb-0">
                <i class="bi bi-shield-lock me-1"></i>
                Leave both password fields blank to keep the current password.
            </p>
        </div>

        <!-- New Password -->
        <div class="col-md-6">
            <label class="form-label">New Password</label>
            <input
                type="password"
                name="password"
                class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                placeholder="Leave blank to keep current"
                minlength="8"
            >
            <?php if (isset($errors['password'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['password']) ?></div>
            <?php endif; ?>
        </div>

        <!-- Confirm New Password -->
        <div class="col-md-6">
            <label class="form-label">Confirm New Password</label>
            <input
                type="password"
                name="password_confirm"
                class="form-control <?= isset($errors['password_confirm']) ? 'is-invalid' : '' ?>"
                placeholder="Repeat new password"
                minlength="8"
            >
            <?php if (isset($errors['password_confirm'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['password_confirm']) ?></div>
            <?php endif; ?>
        </div>

        <div class="col-12 d-flex justify-content-end gap-2 mt-2">
            <a href="users.php" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-gold">
                <i class="bi bi-check-lg me-1"></i>
                Save Changes
            </button>
        </div>

    </form>

</div>


<?php require_once "../includes/footer.php"; ?>