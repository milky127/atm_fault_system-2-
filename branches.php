<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}

/* =========================================================
   ROUTING
========================================================= */

$action = $_GET["action"] ?? $_POST["action"] ?? "list";
$id     = (int) ($_GET["id"] ?? $_POST["id"] ?? 0);

/* Redirect helpers */
function go(string $qs): void {
    header("Location: branches.php" . ($qs ? "?" . $qs : ""));
    exit;
}

/* =========================================================
   POST HANDLERS
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /* ---------- SAVE (add or edit) ---------- */
    if ($action === "save") {

        $is_edit = ($id > 0);

        $branch_name = trim($_POST["branch_name"] ?? "");
        $location    = trim($_POST["location"] ?? "");
        $district    = trim($_POST["district"] ?? "");
        $phone       = trim($_POST["phone"] ?? "");

        $errors = [];

        if ($branch_name === "") {
            $errors["branch_name"] = "Branch name is required.";
        } elseif (strlen($branch_name) > 100) {
            $errors["branch_name"] = "Branch name must be 100 characters or fewer.";
        } else {
            $sql = "SELECT id FROM branches WHERE branch_name = ?";
            $p    = [$branch_name];
            if ($is_edit) {
                $sql .= " AND id <> ?";
                $p[] = $id;
            }
            $sql .= " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($p);
            if ($stmt->fetch()) {
                $errors["branch_name"] = "A branch with this name already exists.";
            }
        }

        if ($location === "") {
            $errors["location"] = "Location is required.";
        } elseif (strlen($location) > 150) {
            $errors["location"] = "Location must be 150 characters or fewer.";
        }

        if ($district !== "" && strlen($district) > 100) {
            $errors["district"] = "District must be 100 characters or fewer.";
        }
        if ($phone !== "" && strlen($phone) > 30) {
            $errors["phone"] = "Phone must be 30 characters or fewer.";
        }

        if (empty($errors)) {

            if ($is_edit) {
                $stmt = $pdo->prepare("
                    UPDATE branches
                    SET branch_name = ?, location = ?, district = ?, phone = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $branch_name,
                    $location,
                    $district !== "" ? $district : null,
                    $phone    !== "" ? $phone    : null,
                    $id,
                ]);
                go("success=updated");
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO branches (branch_name, location, district, phone)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    $branch_name,
                    $location,
                    $district !== "" ? $district : null,
                    $phone    !== "" ? $phone    : null,
                ]);
                go("success=added");
            }
        }

        /* If validation failed, fall through to form and show errors */
        $form_errors = $errors;
        $form = [
            "branch_name" => $branch_name,
            "location"    => $location,
            "district"    => $district,
            "phone"       => $phone,
        ];
        $action = $is_edit ? "edit" : "add";
    }

    /* ---------- DELETE ---------- */
    elseif ($action === "delete") {

        if ($id <= 0) {
            go("error=notfound");
        }

        $stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            go("error=notfound");
        }

        /* Block if ATMs or staff use this branch */
        $stmt = $pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM atms  WHERE branch_id = ?) AS atm_count,
                (SELECT COUNT(*) FROM users WHERE branch_id = ?) AS user_count
        ");
        $stmt->execute([$id, $id]);
        $counts = $stmt->fetch();

        if ((int) $counts["atm_count"] > 0 || (int) $counts["user_count"] > 0) {
            go("error=in_use");
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM branches WHERE id = ?");
            $stmt->execute([$id]);
        } catch (PDOException $e) {
            go("error=db");
        }

        go("success=deleted");
    }

    go();
}

/* =========================================================
   LIST VIEW DATA
========================================================= */

if ($action === "list") {

    $search = trim($_GET["search"] ?? "");

    $sql = "SELECT
                b.*,
                (SELECT COUNT(*) FROM atms  a WHERE a.branch_id = b.id) AS atm_count,
                (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id) AS user_count
            FROM branches b
            WHERE 1=1";

    $params = [];

    if ($search !== "") {
        $sql .= " AND (
            b.branch_name LIKE :search
            OR b.location   LIKE :search
            OR b.district   LIKE :search
            OR b.phone      LIKE :search
        )";
        $params["search"] = "%" . $search . "%";
    }

    $sql .= " ORDER BY b.branch_name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $branches = $stmt->fetchAll();

    $summary = [
        "total"      => (int) $pdo->query("SELECT COUNT(*) FROM branches")->fetchColumn(),
        "with_atms"  => (int) $pdo->query("
            SELECT COUNT(DISTINCT branch_id) FROM atms WHERE branch_id IS NOT NULL
        ")->fetchColumn(),
        "districts"  => (int) $pdo->query("
            SELECT COUNT(DISTINCT district) FROM branches
            WHERE district IS NOT NULL AND district <> ''
        ")->fetchColumn(),
        "unassigned" => (int) $pdo->query("
            SELECT COUNT(*) FROM users
            WHERE branch_id IS NULL AND role <> 'admin'
        ")->fetchColumn(),
    ];

    $success = $_GET["success"] ?? "";
    $error   = $_GET["error"]   ?? "";
}

/* =========================================================
   FORM VIEW DATA (add / edit)
========================================================= */

if ($action === "add" || $action === "edit") {

    if ($action === "edit") {
        if ($id <= 0) {
            go("error=notfound");
        }

        $stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $branch = $stmt->fetch();

        if (!$branch) {
            go("error=notfound");
        }
    }

    /* Pre-fill form on first GET, overridden by POST if validation failed */
    if (!isset($form)) {
        if ($action === "edit") {
            $form = [
                "branch_name" => $branch["branch_name"],
                "location"    => $branch["location"],
                "district"    => $branch["district"] ?? "",
                "phone"       => $branch["phone"]    ?? "",
            ];
        } else {
            $form = [
                "branch_name" => "",
                "location"    => "",
                "district"    => "",
                "phone"       => "",
            ];
        }
    }
    $form_errors = $form_errors ?? [];
}

/* =========================================================
   DETAIL VIEW DATA
========================================================= */

if ($action === "view") {

    if ($id <= 0) {
        go("error=notfound");
    }

    $stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $branch = $stmt->fetch();

    if (!$branch) {
        go("error=notfound");
    }

    /* ATMs in this branch */
    $stmt = $pdo->prepare("
        SELECT a.*,
               (SELECT COUNT(*) FROM incidents i
                WHERE i.atm_id = a.id AND i.status <> 'closed') AS open_incidents
        FROM atms a
        WHERE a.branch_id = ?
        ORDER BY a.atm_code
    ");
    $stmt->execute([$id]);
    $atms = $stmt->fetchAll();

    /* Staff in this branch */
    $stmt = $pdo->prepare("
        SELECT id, full_name, username, role, phone, status
        FROM users
        WHERE branch_id = ?
        ORDER BY full_name
    ");
    $stmt->execute([$id]);
    $staff = $stmt->fetchAll();

    /* Recent incidents across this branch's ATMs */
    $stmt = $pdo->prepare("
        SELECT
            i.incident_number,
            i.title,
            i.priority,
            i.status,
            i.reported_at,
            a.atm_code
        FROM incidents i
        INNER JOIN atms a ON i.atm_id = a.id
        WHERE a.branch_id = ?
        ORDER BY i.reported_at DESC
        LIMIT 10
    ");
    $stmt->execute([$id]);
    $recent_incidents = $stmt->fetchAll();

    $role_labels = [
        "admin"           => "Administrator",
        "branch_officer"  => "Branch Officer",
        "support_officer" => "Support Officer",
        "technician"      => "Technician",
    ];

    $priority_badges = [
        "low"      => "bg-secondary",
        "medium"   => "bg-info text-dark",
        "high"     => "bg-warning text-dark",
        "critical" => "bg-danger",
    ];

    $status_badges = [
        "reported"     => ["#8E1B8F", "#fff"],
        "under_review" => ["#0D6EFD", "#fff"],
        "assigned"     => ["#D4AF37", "#252525"],
        "in_progress"  => ["#E0A800", "#252525"],
        "resolved"     => ["#198754", "#fff"],
        "verified"     => ["#0DCAF0", "#252525"],
        "closed"       => ["#495057", "#fff"],
    ];
}

/* =========================================================
   PAGE TITLES
========================================================= */

$active_page = "branches";

switch ($action) {
    case "add":    $page_title = "Add New Branch"; break;
    case "edit":   $page_title = "Edit Branch";    break;
    case "view":   $page_title = "Branch Details"; break;
    default:       $page_title = "Branch Management";
}

require_once "../includes/header.php";
require_once "../includes/sidebar.php";
?>


<?php /* =========================================================
       LIST VIEW
   ========================================================= */ ?>

<?php if ($action === "list"): ?>


<div class="page-heading">

    <div>
        <h2>Branches</h2>
        <p>Manage all bank branches in the network.</p>
    </div>

    <div>
        <a href="branches.php?action=add" class="btn btn-gold">
            <i class="bi bi-plus-lg me-2"></i>
            Add New Branch
        </a>
    </div>

</div>


<?php if ($success !== ""): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?= match ($success) {
            "added"   => "Branch added successfully.",
            "updated" => "Branch updated successfully.",
            "deleted" => "Branch deleted successfully.",
            default   => "Operation completed.",
        } ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error !== ""): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-circle-fill me-2"></i>
        <?= match ($error) {
            "notfound" => "Branch not found.",
            "in_use"   => "This branch has ATMs or staff assigned and cannot be deleted.",
            "db"       => "Database error.",
            default    => "An error occurred.",
        } ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>


<div class="row g-4 mb-4">

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>TOTAL BRANCHES</p>
                <h3><?= $summary["total"] ?></h3>
                <span>Registered branches</span>
            </div>
            <div class="stat-icon purple-icon">
                <i class="bi bi-building"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>WITH ATMs</p>
                <h3><?= $summary["with_atms"] ?></h3>
                <span class="success-text">
                    <i class="bi bi-pc-display"></i>
                    Hosting ATMs
                </span>
            </div>
            <div class="stat-icon green-icon">
                <i class="bi bi-pc-display"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>DISTRICTS</p>
                <h3><?= $summary["districts"] ?></h3>
                <span>Covered areas</span>
            </div>
            <div class="stat-icon gold-icon">
                <i class="bi bi-geo-alt-fill"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>UNASSIGNED STAFF</p>
                <h3><?= $summary["unassigned"] ?></h3>
                <span class="gold-text">
                    <i class="bi bi-person-dash"></i>
                    No branch set
                </span>
            </div>
            <div class="stat-icon red-icon">
                <i class="bi bi-person-dash"></i>
            </div>
        </div>
    </div>

</div>


<div class="dashboard-card mb-4">

    <div class="card-header-custom">
        <div>
            <h5>Search</h5>
            <p>Find branches quickly</p>
        </div>
    </div>

    <form method="GET" action="branches.php" class="row g-3">

        <input type="hidden" name="action" value="list">

        <div class="col-lg-10">
            <div class="input-group">
                <span class="input-group-text bg-white">
                    <i class="bi bi-search"></i>
                </span>
                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Branch name, location, district, phone…"
                    value="<?= htmlspecialchars($search) ?>"
                >
            </div>
        </div>

        <div class="col-lg-2 d-flex gap-2">
            <a href="branches.php" class="btn btn-outline-secondary flex-fill">Clear</a>
            <button type="submit" class="btn btn-gold flex-fill">
                <i class="bi bi-funnel"></i>
            </button>
        </div>

    </form>

</div>


<div class="dashboard-card">

    <div class="card-header-custom">
        <div>
            <h5>Branch List</h5>
            <p>
                <?= count($branches) ?>
                branch<?= count($branches) === 1 ? "" : "es" ?>
                <?php if ($search !== ""): ?>
                    <span class="text-muted">(filtered)</span>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="table-responsive">

        <table class="table atm-table align-middle">

            <thead>
                <tr>
                    <th>Branch</th>
                    <th>Location</th>
                    <th>District</th>
                    <th>Phone</th>
                    <th>ATMs</th>
                    <th>Staff</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>

            <tbody>

            <?php if (count($branches) > 0): ?>

                <?php foreach ($branches as $b): ?>

                    <tr>

                        <td>
                            <strong><?= htmlspecialchars($b["branch_name"]) ?></strong>
                            <br>
                            <small class="text-muted">
                                Added <?= htmlspecialchars(date("M j, Y", strtotime($b["created_at"]))) ?>
                            </small>
                        </td>

                        <td>
                            <i class="bi bi-geo-alt me-1 text-muted"></i>
                            <?= htmlspecialchars($b["location"]) ?>
                        </td>

                        <td>
                            <?= $b["district"]
                                ? htmlspecialchars($b["district"])
                                : '<span class="text-muted">—</span>' ?>
                        </td>

                        <td>
                            <?= $b["phone"]
                                ? htmlspecialchars($b["phone"])
                                : '<span class="text-muted">—</span>' ?>
                        </td>

                        <td>
                            <?php if ((int) $b["atm_count"] > 0): ?>
                                <span class="badge bg-secondary"><?= (int) $b["atm_count"] ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ((int) $b["user_count"] > 0): ?>
                                <span class="badge bg-secondary"><?= (int) $b["user_count"] ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>

                        <td class="text-end">
                            <div class="d-inline-flex gap-1">

                                <a href="branches.php?action=view&id=<?= (int) $b["id"] ?>"
                                   class="btn btn-sm btn-outline-secondary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>

                                <a href="branches.php?action=edit&id=<?= (int) $b["id"] ?>"
                                   class="btn btn-sm btn-outline-purple" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>

                                <form method="POST" action="branches.php" class="d-inline"
                                      onsubmit="return confirmDelete('<?= htmlspecialchars($b['branch_name'], ENT_QUOTES) ?>', <?= (int) $b['atm_count'] ?>, <?= (int) $b['user_count'] ?>);">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $b["id"] ?>">
                                    <button type="submit"
                                            class="btn btn-sm btn-outline-danger"
                                            title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>

                            </div>
                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="7" class="text-center py-5">
                        <div class="empty-state">
                            <i class="bi bi-building"></i>
                            <p>
                                <?php if ($search !== ""): ?>
                                    No branches match your search.
                                <?php else: ?>
                                    No branches registered yet.
                                <?php endif; ?>
                            </p>
                            <?php if ($search === ""): ?>
                                <a href="branches.php?action=add" class="btn btn-gold mt-2">
                                    <i class="bi bi-plus-lg me-1"></i>
                                    Add your first branch
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


<?php /* =========================================================
       ADD / EDIT FORM
   ========================================================= */ ?>

<?php elseif ($action === "add" || $action === "edit"): ?>


<div class="page-heading">

    <div>
        <h2><?= $action === "edit" ? "Edit Branch" : "Add New Branch" ?></h2>
        <p>
            <?php if ($action === "edit"): ?>
                Updating <strong><?= htmlspecialchars($branch["branch_name"]) ?></strong>
            <?php else: ?>
                Register a new bank branch.
            <?php endif; ?>
        </p>
    </div>

    <div class="d-flex gap-2">
        <?php if ($action === "edit"): ?>
            <a href="branches.php?action=view&id=<?= $id ?>"
               class="btn btn-outline-secondary">
                <i class="bi bi-eye me-1"></i> View
            </a>
        <?php endif; ?>
        <a href="branches.php" class="btn btn-outline-purple">
            <i class="bi bi-arrow-left me-2"></i> Back to Branches
        </a>
    </div>

</div>


<?php if (!empty($form_errors)): ?>
    <div class="alert alert-danger">
        <strong>
            <i class="bi bi-exclamation-circle-fill me-1"></i>
            Please fix the following:
        </strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($form_errors as $e): ?>
                <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>


<div class="dashboard-card">

    <div class="card-header-custom">
        <div>
            <h5>Branch Information</h5>
            <p>Fields marked <span class="text-danger">*</span> are required</p>
        </div>
    </div>

    <form method="POST" action="branches.php" class="row g-3">

        <input type="hidden" name="action" value="save">
        <?php if ($action === "edit"): ?>
            <input type="hidden" name="id" value="<?= $id ?>">
        <?php endif; ?>

        <div class="col-12">
            <label class="form-label">
                Branch Name <span class="text-danger">*</span>
            </label>
            <input
                type="text"
                name="branch_name"
                class="form-control <?= isset($form_errors['branch_name']) ? 'is-invalid' : '' ?>"
                value="<?= htmlspecialchars($form['branch_name']) ?>"
                placeholder="e.g. Dessie Main Branch"
                maxlength="100"
                required
            >
            <?php if (isset($form_errors['branch_name'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($form_errors['branch_name']) ?></div>
            <?php endif; ?>
        </div>

        <div class="col-md-6">
            <label class="form-label">
                Location <span class="text-danger">*</span>
            </label>
            <input
                type="text"
                name="location"
                class="form-control <?= isset($form_errors['location']) ? 'is-invalid' : '' ?>"
                value="<?= htmlspecialchars($form['location']) ?>"
                placeholder="e.g. Dessie, Piassa"
                maxlength="150"
                required
            >
            <?php if (isset($form_errors['location'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($form_errors['location']) ?></div>
            <?php endif; ?>
        </div>

        <div class="col-md-6">
            <label class="form-label">District</label>
            <input
                type="text"
                name="district"
                class="form-control <?= isset($form_errors['district']) ? 'is-invalid' : '' ?>"
                value="<?= htmlspecialchars($form['district']) ?>"
                placeholder="e.g. South Wollo"
                maxlength="100"
            >
            <?php if (isset($form_errors['district'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($form_errors['district']) ?></div>
            <?php endif; ?>
        </div>

        <div class="col-md-6">
            <label class="form-label">Phone</label>
            <input
                type="text"
                name="phone"
                class="form-control <?= isset($form_errors['phone']) ? 'is-invalid' : '' ?>"
                value="<?= htmlspecialchars($form['phone']) ?>"
                placeholder="+251 33 …"
                maxlength="30"
            >
            <?php if (isset($form_errors['phone'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($form_errors['phone']) ?></div>
            <?php endif; ?>
        </div>

        <div class="col-12 d-flex justify-content-end gap-2 mt-2">
            <a href="branches.php" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-gold">
                <i class="bi bi-check-lg me-1"></i>
                <?= $action === "edit" ? "Save Changes" : "Save Branch" ?>
            </button>
        </div>

    </form>

</div>


<?php /* =========================================================
       DETAIL VIEW
   ========================================================= */ ?>

<?php elseif ($action === "view"): ?>


<div class="page-heading">

    <div>
        <h2><?= htmlspecialchars($branch["branch_name"]) ?></h2>
        <p>
            <i class="bi bi-geo-alt me-1"></i>
            <?= htmlspecialchars($branch["location"]) ?>
            <?php if ($branch["district"]): ?>
                · <?= htmlspecialchars($branch["district"]) ?>
            <?php endif; ?>
        </p>
    </div>

    <div class="d-flex gap-2">
        <a href="branches.php?action=edit&id=<?= $id ?>" class="btn btn-gold">
            <i class="bi bi-pencil me-1"></i> Edit
        </a>
        <a href="branches.php" class="btn btn-outline-purple">
            <i class="bi bi-arrow-left me-2"></i> Back
        </a>
    </div>

</div>


<div class="row g-4 mb-4">

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>ATMs</p>
                <h3><?= count($atms) ?></h3>
                <span>Installed at this branch</span>
            </div>
            <div class="stat-icon purple-icon">
                <i class="bi bi-pc-display"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>STAFF</p>
                <h3><?= count($staff) ?></h3>
                <span>Assigned users</span>
            </div>
            <div class="stat-icon gold-icon">
                <i class="bi bi-people-fill"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>RECENT INCIDENTS</p>
                <h3><?= count($recent_incidents) ?></h3>
                <span>Last 10 shown below</span>
            </div>
            <div class="stat-icon red-icon">
                <i class="bi bi-exclamation-triangle"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>PHONE</p>
                <h3 style="font-size:1.1rem;">
                    <?= $branch["phone"]
                        ? htmlspecialchars($branch["phone"])
                        : '<span class="text-muted">—</span>' ?>
                </h3>
                <span>Contact</span>
            </div>
            <div class="stat-icon green-icon">
                <i class="bi bi-telephone-fill"></i>
            </div>
        </div>
    </div>

</div>


<div class="dashboard-card mb-4">

    <div class="card-header-custom">
        <div>
            <h5>Branch Details</h5>
            <p>Registered information</p>
        </div>
    </div>

    <table class="table mb-0">
        <tbody>
            <tr>
                <th style="width:30%;">Branch Name</th>
                <td><strong><?= htmlspecialchars($branch["branch_name"]) ?></strong></td>
            </tr>
            <tr>
                <th>Location</th>
                <td><?= htmlspecialchars($branch["location"]) ?></td>
            </tr>
            <tr>
                <th>District</th>
                <td>
                    <?= $branch["district"]
                        ? htmlspecialchars($branch["district"])
                        : '<span class="text-muted">—</span>' ?>
                </td>
            </tr>
            <tr>
                <th>Phone</th>
                <td>
                    <?= $branch["phone"]
                        ? htmlspecialchars($branch["phone"])
                        : '<span class="text-muted">—</span>' ?>
                </td>
            </tr>
            <tr>
                <th>Registered</th>
                <td><?= htmlspecialchars($branch["created_at"]) ?></td>
            </tr>
        </tbody>
    </table>

</div>


<div class="row g-4 mb-4">

    <div class="col-xl-6">
        <div class="dashboard-card h-100">

            <div class="card-header-custom">
                <div>
                    <h5>ATMs at this Branch</h5>
                    <p><?= count($atms) ?> ATM<?= count($atms) === 1 ? "" : "s" ?></p>
                </div>
            </div>

            <?php if (count($atms) > 0): ?>
                <div class="table-responsive">
                    <table class="table atm-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>ATM Code</th>
                                <th>Location</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($atms as $a): ?>
                            <?php
                            $status_class = match ($a["status"]) {
                                "operational" => "operational",
                                "down"        => "down",
                                default       => "maintenance",
                            };
                            ?>
                            <tr>
                                <td>
                                    <a href="atm_view.php?id=<?= (int) $a["id"] ?>">
                                        <strong><?= htmlspecialchars($a["atm_code"]) ?></strong>
                                    </a>
                                    <?php if ((int) $a["open_incidents"] > 0): ?>
                                        <span class="badge bg-danger ms-1">
                                            <?= (int) $a["open_incidents"] ?> open
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($a["location"]) ?></td>
                                <td>
                                    <span class="atm-status <?= $status_class ?>">
                                        <span></span> <?= ucfirst($a["status"]) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state py-4">
                    <i class="bi bi-pc-display"></i>
                    <p>No ATMs assigned to this branch yet.</p>
                    <a href="atm_add.php" class="btn btn-gold mt-2">
                        <i class="bi bi-plus-lg me-1"></i> Add ATM
                    </a>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <div class="col-xl-6">
        <div class="dashboard-card h-100">

            <div class="card-header-custom">
                <div>
                    <h5>Staff at this Branch</h5>
                    <p><?= count($staff) ?> user<?= count($staff) === 1 ? "" : "s" ?></p>
                </div>
            </div>

            <?php if (count($staff) > 0): ?>
                <div class="table-responsive">
                    <table class="table atm-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($staff as $s): ?>
                            <tr>
                                <td>
                                    <a href="user_edit.php?id=<?= (int) $s["id"] ?>">
                                        <strong><?= htmlspecialchars($s["full_name"]) ?></strong>
                                    </a>
                                    <br>
                                    <small class="text-muted">
                                        @<?= htmlspecialchars($s["username"]) ?>
                                    </small>
                                </td>
                                <td>
                                    <?= htmlspecialchars($role_labels[$s["role"]] ?? $s["role"]) ?>
                                </td>
                                <td>
                                    <?php if ($s["status"] === "active"): ?>
                                        <span class="atm-status operational">
                                            <span></span> Active
                                        </span>
                                    <?php else: ?>
                                        <span class="atm-status down">
                                            <span></span> Inactive
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state py-4">
                    <i class="bi bi-people"></i>
                    <p>No staff assigned to this branch yet.</p>
                    <a href="user_add.php" class="btn btn-gold mt-2">
                        <i class="bi bi-person-plus me-1"></i> Add User
                    </a>
                </div>
            <?php endif; ?>

        </div>
    </div>

</div>


<div class="dashboard-card">

    <div class="card-header-custom">
        <div>
            <h5>Recent Incidents</h5>
            <p>Last 10 incidents across all ATMs in this branch</p>
        </div>
    </div>

    <?php if (count($recent_incidents) > 0): ?>

        <div class="table-responsive">
            <table class="table atm-table align-middle">
                <thead>
                    <tr>
                        <th>Incident</th>
                        <th>ATM</th>
                        <th>Priority</th>
                        <th>Reported</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recent_incidents as $inc): ?>
                    <?php
                    [$sb_bg, $sb_fg] = $status_badges[$inc["status"]] ?? ["#6c757d", "#fff"];
                    $pb_class        = $priority_badges[$inc["priority"]] ?? "bg-secondary";
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($inc["incident_number"]) ?></strong>
                            <br>
                            <small class="text-muted">
                                <?= htmlspecialchars($inc["title"]) ?>
                            </small>
                        </td>
                        <td><?= htmlspecialchars($inc["atm_code"]) ?></td>
                        <td>
                            <span class="badge <?= $pb_class ?>">
                                <?= ucfirst($inc["priority"]) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($inc["reported_at"]) ?></td>
                        <td>
                            <span class="badge"
                                  style="background:<?= $sb_bg ?>; color:<?= $sb_fg ?>;">
                                <?= ucfirst(str_replace("_", " ", $inc["status"])) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php else: ?>

        <div class="empty-state py-4">
            <i class="bi bi-check-circle"></i>
            <p>No incidents recorded for this branch.</p>
        </div>

    <?php endif; ?>

</div>


<?php endif; ?>


<?php

if ($action === "list") {

    $extra_scripts = <<<'HTML'
<script>
function confirmDelete(branchName, atmCount, userCount) {
    let msg = "Delete branch " + branchName + "?";
    if (atmCount > 0 || userCount > 0) {
        msg += "\n\nThis branch is currently in use:";
        if (atmCount > 0)  msg += "\n  • " + atmCount  + " ATM(s) assigned";
        if (userCount > 0) msg += "\n  • " + userCount + " staff assigned";
        msg += "\n\nDeletion will be blocked.";
    } else {
        msg += "\n\nThis action cannot be undone.";
    }
    return confirm(msg);
}
</script>
HTML;

}

require_once "../includes/footer.php";