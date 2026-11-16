<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}

$id = (int) ($_GET["id"] ?? $_POST["id"] ?? 0);

if ($id <= 0) {
    header("Location: atms.php?error=notfound");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM atms WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$atm = $stmt->fetch();

if (!$atm) {
    header("Location: atms.php?error=notfound");
    exit;
}

$errors = [];

$form = [
    "atm_code"          => $atm["atm_code"],
    "branch_id"         => $atm["branch_id"] ?? "",
    "location"          => $atm["location"],
    "model"             => $atm["model"] ?? "",
    "serial_number"     => $atm["serial_number"] ?? "",
    "installation_date" => $atm["installation_date"] ?? "",
    "status"            => $atm["status"],
];

$valid_statuses = ["operational", "down", "maintenance"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $form["atm_code"]          = strtoupper(trim($_POST["atm_code"] ?? ""));
    $form["branch_id"]         = trim($_POST["branch_id"] ?? "");
    $form["location"]          = trim($_POST["location"] ?? "");
    $form["model"]             = trim($_POST["model"] ?? "");
    $form["serial_number"]     = trim($_POST["serial_number"] ?? "");
    $form["installation_date"] = trim($_POST["installation_date"] ?? "");
    $form["status"]            = $_POST["status"] ?? "operational";

    if ($form["atm_code"] === "") {
        $errors["atm_code"] = "ATM code is required.";
    }

    if ($form["branch_id"] === "") {
        $errors["branch_id"] = "Please select a branch.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? LIMIT 1");
        $stmt->execute([(int) $form["branch_id"]]);
        if (!$stmt->fetch()) {
            $errors["branch_id"] = "Selected branch does not exist.";
        }
    }

    if ($form["location"] === "") {
        $errors["location"] = "Location is required.";
    }

    if (!in_array($form["status"], $valid_statuses, true)) {
        $errors["status"] = "Invalid status selected.";
    }

    if ($form["installation_date"] !== "") {
        $d = DateTime::createFromFormat("Y-m-d", $form["installation_date"]);
        if (!$d || $d->format("Y-m-d") !== $form["installation_date"]) {
            $errors["installation_date"] = "Invalid date format.";
        }
    }

    if (!isset($errors["atm_code"])) {
        $stmt = $pdo->prepare("SELECT id FROM atms WHERE atm_code = ? AND id <> ? LIMIT 1");
        $stmt->execute([$form["atm_code"], $id]);
        if ($stmt->fetch()) {
            $errors["atm_code"] = "This ATM code is already in use.";
        }
    }

    if (empty($errors)) {

        $stmt = $pdo->prepare("
            UPDATE atms SET
                atm_code = ?, branch_id = ?, location = ?, model = ?,
                serial_number = ?, installation_date = ?, status = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $form["atm_code"],
            (int) $form["branch_id"],
            $form["location"],
            $form["model"] !== "" ? $form["model"] : null,
            $form["serial_number"] !== "" ? $form["serial_number"] : null,
            $form["installation_date"] !== "" ? $form["installation_date"] : null,
            $form["status"],
            $id,
        ]);

        header("Location: atms.php?success=updated");
        exit;
    }
}

$branches = $pdo->query("
    SELECT id, branch_name
    FROM branches
    ORDER BY branch_name
")->fetchAll();

$active_page = "atms";
$page_title  = "Edit ATM";

require_once "../includes/header.php";
require_once "../includes/sidebar.php";
?>


<div class="page-heading">

    <div>
        <h2>Edit ATM</h2>
        <p>Updating <strong><?= htmlspecialchars($atm["atm_code"]) ?></strong></p>
    </div>

    <div class="d-flex gap-2">
        <a href="atm_view.php?id=<?= $id ?>" class="btn btn-outline-secondary">
            <i class="bi bi-eye me-1"></i> View
        </a>
        <a href="atms.php" class="btn btn-outline-purple">
            <i class="bi bi-arrow-left me-2"></i> Back to ATMs
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
            <h5>ATM Information</h5>
            <p>Fields marked <span class="text-danger">*</span> are required</p>
        </div>
    </div>

    <form method="POST" action="atm_edit.php" class="row g-3">

        <input type="hidden" name="id" value="<?= $id ?>">

        <div class="col-md-6">
            <label class="form-label">ATM Code <span class="text-danger">*</span></label>
            <input
                type="text"
                name="atm_code"
                class="form-control <?= isset($errors['atm_code']) ? 'is-invalid' : '' ?>"
                value="<?= htmlspecialchars($form['atm_code']) ?>"
                required
            >
            <?php if (isset($errors['atm_code'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['atm_code']) ?></div>
            <?php endif; ?>
        </div>

        <div class="col-md-6">
            <label class="form-label">Branch <span class="text-danger">*</span></label>
            <select
                name="branch_id"
                class="form-select <?= isset($errors['branch_id']) ? 'is-invalid' : '' ?>"
                required
            >
                <option value="">— Select branch —</option>
                <?php foreach ($branches as $b): ?>
                    <option
                        value="<?= (int) $b['id'] ?>"
                        <?= (string) $form['branch_id'] === (string) $b['id'] ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($b['branch_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['branch_id'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['branch_id']) ?></div>
            <?php endif; ?>
        </div>

        <div class="col-12">
            <label class="form-label">Location <span class="text-danger">*</span></label>
            <input
                type="text"
                name="location"
                class="form-control <?= isset($errors['location']) ? 'is-invalid' : '' ?>"
                value="<?= htmlspecialchars($form['location']) ?>"
                required
            >
            <?php if (isset($errors['location'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['location']) ?></div>
            <?php endif; ?>
        </div>

        <div class="col-md-6">
            <label class="form-label">ATM Model</label>
            <input
                type="text"
                name="model"
                class="form-control"
                value="<?= htmlspecialchars($form['model']) ?>"
            >
        </div>

        <div class="col-md-6">
            <label class="form-label">Serial Number</label>
            <input
                type="text"
                name="serial_number"
                class="form-control"
                value="<?= htmlspecialchars($form['serial_number']) ?>"
            >
        </div>

        <div class="col-md-6">
            <label class="form-label">Installation Date</label>
            <input
                type="date"
                name="installation_date"
                class="form-control <?= isset($errors['installation_date']) ? 'is-invalid' : '' ?>"
                value="<?= htmlspecialchars($form['installation_date']) ?>"
            >
            <?php if (isset($errors['installation_date'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['installation_date']) ?></div>
            <?php endif; ?>
        </div>

        <div class="col-md-6">
            <label class="form-label">Status <span class="text-danger">*</span></label>
            <select name="status" class="form-select">
                <option value="operational" <?= $form['status'] === 'operational' ? 'selected' : '' ?>>Operational</option>
                <option value="maintenance" <?= $form['status'] === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                <option value="down"        <?= $form['status'] === 'down'        ? 'selected' : '' ?>>Down</option>
            </select>
        </div>

        <div class="col-12 d-flex justify-content-end gap-2 mt-2">
            <a href="atms.php" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-gold">
                <i class="bi bi-check-lg me-1"></i>
                Save Changes
            </button>
        </div>

    </form>

</div>


<?php require_once "../includes/footer.php"; ?>