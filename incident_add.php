<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}

$errors = [];

$form = [
    "atm_id"      => "",
    "category_id" => "",
    "priority"    => "medium",
    "title"       => "",
    "description" => "",
];

$valid_priorities = ["low","medium","high","critical"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $form["atm_id"]      = trim($_POST["atm_id"] ?? "");
    $form["category_id"] = trim($_POST["category_id"] ?? "");
    $form["priority"]    = $_POST["priority"] ?? "medium";
    $form["title"]       = trim($_POST["title"] ?? "");
    $form["description"] = trim($_POST["description"] ?? "");

    if ($form["atm_id"] === "") {
        $errors["atm_id"] = "Please select an ATM.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM atms WHERE id = ? LIMIT 1");
        $stmt->execute([(int) $form["atm_id"]]);
        if (!$stmt->fetch()) {
            $errors["atm_id"] = "Selected ATM does not exist.";
        }
    }

    if ($form["category_id"] === "") {
        $errors["category_id"] = "Please select a fault category.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM fault_categories WHERE id = ? LIMIT 1");
        $stmt->execute([(int) $form["category_id"]]);
        if (!$stmt->fetch()) {
            $errors["category_id"] = "Selected category does not exist.";
        }
    }

    if (!in_array($form["priority"], $valid_priorities, true)) {
        $errors["priority"] = "Invalid priority.";
    }

    if ($form["title"] === "") {
        $errors["title"] = "Title is required.";
    } elseif (strlen($form["title"]) > 150) {
        $errors["title"] = "Title must be 150 characters or fewer.";
    }

    if ($form["description"] === "") {
        $errors["description"] = "Description is required.";
    }

    if (empty($errors)) {

        $stmt = $pdo->prepare("
            INSERT INTO incidents
                (incident_number, title, atm_id, reported_by, category_id,
                 priority, description, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'reported')
        ");

        $stmt->execute([
            "TEMP-" . uniqid(),
            $form["title"],
            (int) $form["atm_id"],
            (int) $_SESSION["user_id"],
            (int) $form["category_id"],
            $form["priority"],
            $form["description"],
        ]);

        $new_id = (int) $pdo->lastInsertId();
        $number = "INC-" . str_pad((string) $new_id, 4, "0", STR_PAD_LEFT);

        $pdo->prepare("UPDATE incidents SET incident_number = ? WHERE id = ?")
            ->execute([$number, $new_id]);

        header("Location: incident_view.php?id=" . $new_id . "&success=created");
        exit;
    }
}

$atms = $pdo->query("
    SELECT a.id, a.atm_code, a.location, b.branch_name
    FROM atms a
    LEFT JOIN branches b ON a.branch_id = b.id
    ORDER BY a.atm_code
")->fetchAll();

$categories = $pdo->query("
    SELECT id, category_name
    FROM fault_categories
    ORDER BY category_name
")->fetchAll();

$active_page = "incidents";
$page_title  = "Report New Fault";

require_once "../includes/header.php";
require_once "../includes/sidebar.php";
?>


<div class="page-heading">

    <div>
        <h2>Report New Fault</h2>
        <p>Submit a new ATM fault report.</p>
    </div>

    <div>
        <a href="incidents.php" class="btn btn-outline-purple">
            <i class="bi bi-arrow-left me-2"></i>
            Back to Incidents
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
            <h5>Fault Information</h5>
            <p>Fields marked <span class="text-danger">*</span> are required</p>
        </div>
    </div>

    <form method="POST" action="incident_add.php" class="row g-3">

        <div class="col-md-6">
            <label class="form-label">
                ATM <span class="text-danger">*</span>
            </label>
            <select
                name="atm_id"
                class="form-select <?= isset($errors['atm_id']) ? 'is-invalid' : '' ?>"
                required
            >
                <option value="">— Select ATM —</option>
                <?php foreach ($atms as $a): ?>
                    <option
                        value="<?= (int) $a["id"] ?>"
                        <?= (string) $form["atm_id"] === (string) $a["id"] ? "selected" : "" ?>
                    >
                        <?= htmlspecialchars($a["atm_code"]) ?>
                        — <?= htmlspecialchars($a["location"]) ?>
                        <?php if ($a["branch_name"]): ?>
                            (<?= htmlspecialchars($a["branch_name"]) ?>)
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['atm_id'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['atm_id']) ?></div>
            <?php endif; ?>
        </div>

        <div class="col-md-6">
            <label class="form-label">
                Category <span class="text-danger">*</span>
            </label>
            <select
                name="category_id"
                class="form-select <?= isset($errors['category_id']) ? 'is-invalid' : '' ?>"
                required
            >
                <option value="">— Select category —</option>
                <?php foreach ($categories as $c): ?>
                    <option
                        value="<?= (int) $c["id"] ?>"
                        <?= (string) $form["category_id"] === (string) $c["id"] ? "selected" : "" ?>
                    >
                        <?= htmlspecialchars($c["category_name"]) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['category_id'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['category_id']) ?></div>
            <?php endif; ?>
        </div>

        <div class="col-12">
            <label class="form-label">
                Title <span class="text-danger">*</span>
            </label>
            <input
                type="text"
                name="title"
                class="form-control <?= isset($errors['title']) ? 'is-invalid' : '' ?>"
                value="<?= htmlspecialchars($form['title']) ?>"
                placeholder="e.g. ATM not dispensing cash"
                maxlength="150"
                required
            >
            <?php if (isset($errors['title'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['title']) ?></div>
            <?php endif; ?>
        </div>

        <div class="col-md-6">
            <label class="form-label">
                Priority <span class="text-danger">*</span>
            </label>
            <select name="priority" class="form-select">
                <option value="low"      <?= $form["priority"] === "low"      ? "selected" : "" ?>>Low</option>
                <option value="medium"   <?= $form["priority"] === "medium"   ? "selected" : "" ?>>Medium</option>
                <option value="high"     <?= $form["priority"] === "high"     ? "selected" : "" ?>>High</option>
                <option value="critical" <?= $form["priority"] === "critical" ? "selected" : "" ?>>Critical</option>
            </select>
        </div>

        <div class="col-12">
            <label class="form-label">
                Description <span class="text-danger">*</span>
            </label>
            <textarea
                name="description"
                class="form-control <?= isset($errors['description']) ? 'is-invalid' : '' ?>"
                rows="5"
                placeholder="Describe the problem in detail…"
                required
            ><?= htmlspecialchars($form['description']) ?></textarea>
            <?php if (isset($errors['description'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['description']) ?></div>
            <?php endif; ?>
        </div>

        <div class="col-12 d-flex justify-content-end gap-2 mt-2">
            <a href="incidents.php" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-gold">
                <i class="bi bi-send me-1"></i>
                Submit Fault Report
            </button>
        </div>

    </form>

</div>


<?php require_once "../includes/footer.php"; ?>