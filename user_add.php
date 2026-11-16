<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}

$errors = [];

$form = [
    "full_name" => "",
    "username"  => "",
    "email"     => "",
    "role"      => "branch_officer",
    "branch_id" => "",
    "phone"     => "",
    "status"    => "active",
];

$valid_roles    = ["admin", "branch_officer", "support_officer", "technician"];
$valid_statuses = ["active", "inactive"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $form["full_name"] = trim($_POST["full_name"] ?? "");
    $form["username"]  = strtolower(trim($_POST["username"] ?? ""));
    $form["email"]     = strtolower(trim($_POST["email"] ?? ""));
    $form["role"]      = $_POST["role"] ?? "branch_officer";
    $form["branch_id"] = trim($_POST["branch_id"] ?? "");
    $form["phone"]     = trim($_POST["phone"] ?? "");
    $form["status"]    = $_POST["status"] ?? "active";

    $password         = $_POST["password"] ?? "";
    $password_confirm = $_POST["password_confirm"] ?? "";

    /* ---------- Full name ---------- */
    if ($form["full_name"] === "") {
        $errors["full_name"] = "Full name is required.";
    } elseif (strlen($form["full_name"]) > 100) {
        $errors["full_name"] = "Full name must be 100 characters or fewer.";
    }

    /* ---------- Username ---------- */
    if ($form["username"] === "") {
        $errors["username"] = "Username is required.";
    } elseif (!preg_match('/^[a-z0-9_.]{3,50}$/', $form["username"])) {
        $errors["username"] = "Username must be 3–50 chars: lowercase letters, digits, dot or underscore.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$form["username"]]);
        if ($stmt->fetch()) $errors["username"] = "This username is already taken.";
    }

    /* ---------- Email ---------- */
    if ($form["email"] === "") {
        $errors["email"] = "Email is required.";
    } elseif (!filter_var($form["email"], FILTER_VALIDATE_EMAIL)) {
        $errors["email"] = "Please enter a valid email address.";
    } elseif (strlen($form["email"]) > 150) {
        $errors["email"] = "Email must be 150 characters or fewer.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$form["email"]]);
        if ($stmt->fetch()) $errors["email"] = "This email is already in use.";
    }

    /* ---------- Role / status ---------- */
    if (!in_array($form["role"], $valid_roles, true)) {
        $errors["role"] = "Invalid role selected.";
    }
    if (!in_array($form["status"], $valid_statuses, true)) {
        $errors["status"] = "Invalid status selected.";
    }

    /* ---------- Branch ---------- */
    if ($form["branch_id"] !== "") {
        $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? LIMIT 1");
        $stmt->execute([(int) $form["branch_id"]]);
        if (!$stmt->fetch()) $errors["branch_id"] = "Selected branch does not exist.";
    }

    /* ---------- Password ---------- */
    if ($password === "") {
        $errors["password"] = "Password is required.";
    } elseif (strlen($password) < 8) {
        $errors["password"] = "Password must be at least 8 characters.";
    } elseif ($password !== $password_confirm) {
        $errors["password_confirm"] = "Passwords do not match.";
    }

    /* ---------- Insert ---------- */
    if (empty($errors)) {

        $stmt = $pdo->prepare("
            INSERT INTO users
                (full_name, username, email, password, role, branch_id, phone, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $form["full_name"],
            $form["username"],
            $form["email"],
            password_hash($password, PASSWORD_DEFAULT),
            $form["role"],
            $form["branch_id"] !== "" ? (int) $form["branch_id"] : null,
            $form["phone"] !== "" ? $form["phone"] : null,
            $form["status"],
        ]);

        header("Location: users.php?success=added");
        exit;
    }
}

$branches = $pdo->query("
    SELECT id, branch_name FROM branches ORDER BY branch_name
")->fetchAll();

$active_page = "users";
$page_title  = "Add New User";

require_once "../includes/header.php";
require_once "../includes/sidebar.php";
?>


<div class="page-heading">

    <div>
        <h2>Add New User</h2>
        <p>Create a new system account.</p>
    </div>

    <div>
        <a href="users.php" class="btn btn-outline-purple">
            <i class="bi bi-arrow-left me-2"></i>
            Back to Users
        </a>
    </div>

</div>


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
            <p>Fields marked <span class="text-danger">*</span> are required</p>
        </div>
    </div>

    <form method="POST" action="user_add.php" class="row g-3">

        <!-- Full Name -->
        <div class="col-md-6">
            <label class="form-label">
                Full Name <span class="text-danger">*</span>
            </label>
            <input
                type="text"
                name="full_name"
                class="form-control <?= isset($errors['full_name']) ? 'is-invalid' : '' ?>"
                value="<?= htmlspecialchars($form['full_name']) ?>"
                placeholder="e.g. Abebe Kebede"
                maxlength="100"
                required
            >
            <?php if (isset($errors['full_name'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['full_name']) ?></div>
            <?php endif; ?>
        </div>

        <!-- Username -->
        <div class="col-md-6">
            <label class="form-label">
                Username <span class="text-danger">*</span>
            </label>
            <input
                type="text"
                name="username"
                class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>"
                value="<?= htmlspecialchars($form['username']) ?>"
                placeholder="lowercase, no spaces"
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
                placeholder="e.g. abebe@cbe.com.et"
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
                placeholder="+251 …"
                maxlength="30"
            >
        </div>

        <!-- Role -->
        <div class="col-md-6">
            <label class="form-label">
                Role <span class="text-danger">*</span>
            </label>
            <select name="role" class="form-select">
                <option value="admin"           <?= $form['role'] === 'admin'           ? 'selected' : '' ?>>Administrator</option>
                <option value="branch_officer"  <?= $form['role'] === 'branch_officer'  ? 'selected' : '' ?>>Branch Officer</option>
                <option value="support_officer" <?= $form['role'] === 'support_officer' ? 'selected' : '' ?>>Support Officer</option>
                <option value="technician"      <?= $form['role'] === 'technician'      ? 'selected' : '' ?>>Technician</option>
            </select>
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
            <label class="form-label">
                Status <span class="text-danger">*</span>
            </label>
            <select name="status" class="form-select">
                <option value="active"   <?= $form['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $form['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>

        <div class="col-12"><hr></div>

        <!-- Password -->
        <div class="col-md-6">
            <label class="form-label">
                Password <span class="text-danger">*</span>
            </label>
            <input
                type="password"
                name="password"
                class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                placeholder="At least 8 characters"
                minlength="8"
                required
            >
            <?php if (isset($errors['password'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['password']) ?></div>
            <?php endif; ?>
        </div>

        <div class="col-md-6">
            <label class="form-label">
                Confirm Password <span class="text-danger">*</span>
            </label>
            <input
                type="password"
                name="password_confirm"
                class="form-control <?= isset($errors['password_confirm']) ? 'is-invalid' : '' ?>"
                placeholder="Repeat password"
                minlength="8"
                required
            >
            <?php if (isset($errors['password_confirm'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['password_confirm']) ?></div>
            <?php endif; ?>
        </div>

        <div class="col-12 d-flex justify-content-end gap-2 mt-2">
            <a href="users.php" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-gold">
                <i class="bi bi-check-lg me-1"></i>
                Create User
            </button>
        </div>

    </form>

</div>


<?php require_once "../includes/footer.php"; ?>