<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}

$id = (int) ($_GET["id"] ?? $_POST["id"] ?? 0);

if ($id <= 0) {
    header("Location: incidents.php?error=notfound");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM incidents WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$incident = $stmt->fetch();

if (!$incident) {
    header("Location: incidents.php?error=notfound");
    exit;
}

/* Only allow editing while reported or under_review */
if (!in_array($incident["status"], ["reported", "under_review"], true)) {
    header("Location: incident_view.php?id=" . $id . "&error=locked");
    exit;
}

$errors = [];

$form = [
    "category_id" => $incident["category_id"],
    "priority"    => $incident["priority"],
    "title"       => $incident["title"],
    "description" => $incident["description"],
];

$valid_priorities = ["low","medium","high","critical"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $form["category_id"] = trim($_POST["category_id"] ?? "");
    $form["priority"]    = $_POST["priority"] ?? "medium";
    $form["title"]       = trim($_POST["title"] ?? "");
    $form["description"] = trim($_POST["description"] ?? "");

    if ($form["category_id"] === "") {
        $errors["category_id"] = "Please select a category.";
    }

    if (!in_array($form["priority"], $valid_priorities, true)) {
        $errors["priority"] = "Invalid priority.";
    }

    if ($form["title"] === "") {
        $errors["title"] = "Title is required.";
    }

    if ($form["description"] === "") {
        $errors["description"] = "Description is required.";
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            UPDATE incidents SET
                category_id = ?, priority = ?, title = ?, description = ?
            WHERE id = ?
        ");
        $stmt->execute([
            (int) $form["category_id"],
            $form["priority"],
            $form["title"],
            $form["description"],
            $id,
        ]);

        header("Location: incident_view.php?id=" . $id . "&success=updated");
        exit;
    }
}

$categories = $pdo->query("
    SELECT id, category_name FROM fault_categories ORDER BY category_name
")->fetchAll();

$active_page = "incidents";
$page_title  = "Edit Incident";

require_once "../includes/header.php";
require_once "../includes/sidebar.php";
?>


<div class="page-heading">

    <div>
        <h2>Edit Incident</h2>
        <p>Updating <strong><?= htmlspecialchars($incident["incident_number"]) ?></strong></p>
    </div>

    <div class="d-flex gap-2">
        <a href="incident_view.php?id=<?= $id ?>" class="btn btn-outline-secondary">
            <i class="bi bi-eye me-1"></i> View
        </a>
        <a href="incidents.php" class="btn btn-outline-purple">
            <i class="bi bi-arrow-left me-2"></i> Back
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
            <h5>Incident Information</h5>
            <p>Editable only while <em>Reported</em> or <em>Under Review</em></p>
        </div>
    </div>

    <form method="POST" action="incident_edit.php" class="row g-3">

        <input type="hidden" name="id" value="<?= $id ?>">

        <div class="col-md-6">
            <label class="form-label">Category <span class="text-danger">*</span></label>
            <select name="category_id" class="form-select <?= isset($errors['category_id']) ? 'is-invalid' : '' ?>" required>
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

        <div class="col-md-6">
            <label class="form-label">Priority <span class="text-danger">*</span></label>
            <select name="priority" class="form-select">
                <option value="low"      <?= $form["priority"] === "low"      ? "selected" : "" ?>>Low</option>
                <option value="medium"   <?= $form["priority"] === "medium"   ? "selected" : "" ?>>Medium</option>
                <option value="high"     <?= $form["priority"] === "high"     ? "selected" : "" ?>>High</option>
                <option value="critical" <?= $form["priority"] === "critical" ? "selected" : "" ?>>Critical</option>
            </select>
        </div>

        <div class="col-12">
            <label class="form-label">Title <span class="text-danger">*</span></label>
            <input
                type="text"
                name="title"
                class="form-control <?= isset($errors['title']) ? 'is-invalid' : '' ?>"
                value="<?= htmlspecialchars($form['title']) ?>"
                maxlength="150"
                required
            >
            <?php if (isset($errors['title'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['title']) ?></div>
            <?php endif; ?>
        </div>

        <div class="col-12">
            <label class="form-label">Description <span class="text-danger">*</span></label>
            <textarea
                name="description"
                class="form-control <?= isset($errors['description']) ? 'is-invalid' : '' ?>"
                rows="5"
                required
            ><?= htmlspecialchars($form['description']) ?></textarea>
            <?php if (isset($errors['description'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['description']) ?></div>
            <?php endif; ?>
        </div>

        <div class="col-12 d-flex justify-content-end gap-2 mt-2">
            <a href="incident_view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-gold">
                <i class="bi bi-check-lg me-1"></i>
                Save Changes
            </button>
        </div>

    </form>

</div>


<?php require_once "../includes/footer.php"; ?>