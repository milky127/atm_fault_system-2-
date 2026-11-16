<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}

$id = (int) ($_GET["id"] ?? 0);
if ($id <= 0) { header("Location: incidents.php"); exit; }

/* ---------- Load incident ---------- */
$stmt = $pdo->prepare("
    SELECT i.*,
           a.atm_code, a.location AS atm_location,
           b.branch_name,
           fc.category_name,
           u.full_name AS reporter_name,
           t.full_name AS technician_name
    FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    LEFT  JOIN branches b ON a.branch_id = b.id
    LEFT  JOIN fault_categories fc ON i.category_id = fc.id
    LEFT  JOIN users u ON i.reported_by = u.id
    LEFT  JOIN users t ON i.assigned_to = t.id
    WHERE i.id = ? LIMIT 1
");
$stmt->execute([$id]);
$incident = $stmt->fetch();
if (!$incident) { header("Location: incidents.php"); exit; }

/* ---------- Timeline ---------- */
$stmt = $pdo->prepare("
    SELECT iu.*, u.full_name AS user_name
    FROM incident_updates iu
    LEFT JOIN users u ON iu.user_id = u.id
    WHERE iu.incident_id = ?
    ORDER BY iu.created_at DESC
");
$stmt->execute([$id]);
$updates = $stmt->fetchAll();

/* ---------- Technicians for dropdown ---------- */
$technicians = $pdo->query("
    SELECT id, full_name
    FROM users
    WHERE role = 'technician' AND status = 'active'
    ORDER BY full_name
")->fetchAll();

/* ---------- Helpers ---------- */
$status_badges = [
    "reported"     => ["#8E1B8F", "#fff"],
    "under_review" => ["#0D6EFD", "#fff"],
    "assigned"     => ["#D4AF37", "#252525"],
    "in_progress"  => ["#E0A800", "#252525"],
    "resolved"     => ["#198754", "#fff"],
    "verified"     => ["#0DCAF0", "#252525"],
    "closed"       => ["#495057", "#fff"],
];
$priority_badges = [
    "low"      => "bg-secondary",
    "medium"   => "bg-info text-dark",
    "high"     => "bg-warning text-dark",
    "critical" => "bg-danger",
];

[$sb_bg, $sb_fg] = $status_badges[$incident["status"]] ?? ["#6c757d", "#fff"];
$pb_class        = $priority_badges[$incident["priority"]] ?? "bg-secondary";

$success = $_GET["success"] ?? "";
$error   = $_GET["error"]   ?? "";

$active_page = "incidents";
$page_title  = $incident["incident_number"];

require_once "../includes/header.php";
require_once "../includes/sidebar.php";
?>


<div class="page-heading">
    <div>
        <h2>
            <?= htmlspecialchars($incident["incident_number"]) ?>
            <span class="badge ms-2" style="background:<?= $sb_bg ?>; color:<?= $sb_fg ?>;">
                <?= ucfirst(str_replace("_", " ", $incident["status"])) ?>
            </span>
        </h2>
        <p><?= htmlspecialchars($incident["title"]) ?></p>
    </div>
    <div>
        <a href="incidents.php" class="btn btn-outline-purple">
            <i class="bi bi-arrow-left me-2"></i> Back
        </a>
    </div>
</div>


<?php if ($success !== ""): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?= match ($success) {
            "action_done"  => "Action applied successfully.",
            "update_added" => "Comment added.",
            default        => "Done.",
        } ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error !== ""): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-circle-fill me-2"></i>
        <?= match ($error) {
            "invalid_status"      => "That action is not allowed from the current status.",
            "no_technician"       => "Please select a technician.",
            "invalid_technician"  => "Selected technician is not valid.",
            "resolution_required" => "Resolution notes are required.",
            "empty_comment"       => "Comment cannot be empty.",
            default               => "Something went wrong.",
        } ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>


<div class="row g-4 mb-4">

    <!-- ============ DETAILS ============ -->
    <div class="col-xl-7">
        <div class="dashboard-card h-100">

            <div class="card-header-custom">
                <div><h5>Incident Details</h5><p>Full information</p></div>
            </div>

            <table class="table mb-0">
                <tbody>
                    <tr>
                        <th style="width:35%;">ATM</th>
                        <td>
                            <strong><?= htmlspecialchars($incident["atm_code"]) ?></strong>
                            — <?= htmlspecialchars($incident["atm_location"]) ?>
                            <?php if ($incident["branch_name"]): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($incident["branch_name"]) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr><th>Category</th><td><?= htmlspecialchars($incident["category_name"] ?? "—") ?></td></tr>
                    <tr>
                        <th>Priority</th>
                        <td><span class="badge <?= $pb_class ?>"><?= ucfirst($incident["priority"]) ?></span></td>
                    </tr>
                    <tr>
                        <th>Status</th>
                        <td><span class="badge" style="background:<?= $sb_bg ?>; color:<?= $sb_fg ?>;">
                            <?= ucfirst(str_replace("_", " ", $incident["status"])) ?></span></td>
                    </tr>
                    <tr>
                        <th>Reported</th>
                        <td><?= htmlspecialchars($incident["reported_at"]) ?>
                            by <strong><?= htmlspecialchars($incident["reporter_name"] ?? "—") ?></strong></td>
                    </tr>
                    <tr>
                        <th>Assigned To</th>
                        <td><?= $incident["technician_name"]
                            ? "<strong>" . htmlspecialchars($incident["technician_name"]) . "</strong>"
                            : '<span class="text-muted">Unassigned</span>' ?></td>
                    </tr>
                    <?php if ($incident["assigned_at"]): ?>
                        <tr><th>Assigned At</th><td><?= htmlspecialchars($incident["assigned_at"]) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($incident["resolved_at"]): ?>
                        <tr><th>Resolved</th><td><?= htmlspecialchars($incident["resolved_at"]) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($incident["closed_at"]): ?>
                        <tr><th>Closed</th><td><?= htmlspecialchars($incident["closed_at"]) ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="card-header-custom" style="border-top:1px solid #eee;">
                <div><h5>Description</h5></div>
            </div>
            <p style="white-space:pre-wrap;"><?= htmlspecialchars($incident["description"]) ?></p>

            <?php if (!empty($incident["resolution_notes"])): ?>
                <div class="card-header-custom" style="border-top:1px solid #eee;">
                    <div><h5>Resolution Notes</h5></div>
                </div>
                <p style="white-space:pre-wrap;"><?= htmlspecialchars($incident["resolution_notes"]) ?></p>
            <?php endif; ?>

        </div>
    </div>


    <!-- ============ ACTIONS ============ -->
    <div class="col-xl-5">
        <div class="dashboard-card h-100">

            <div class="card-header-custom">
                <div><h5>Actions</h5><p>Available steps for this status</p></div>
            </div>

            <?php $st = $incident["status"]; ?>


            <?php /* ---------- MARK UNDER REVIEW ---------- */ ?>
            <?php if ($st === "reported"): ?>

                <form method="POST" action="incident_action.php" class="mb-4">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="acknowledge">

                    <label class="form-label">Comment (optional)</label>
                    <textarea name="comment" class="form-control mb-2" rows="2"
                              placeholder="Note for the record…"></textarea>

                    <button type="submit" class="btn btn-gold w-100">
                        <i class="bi bi-eye me-1"></i> Mark Under Review
                    </button>
                </form>

            <?php endif; ?>


            <?php /* ---------- ASSIGN TO TECHNICIAN ---------- */ ?>
            <?php if (in_array($st, ["reported", "under_review", "assigned", "in_progress"], true)): ?>

                <form method="POST" action="incident_action.php" class="mb-4">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="assign">

                    <label class="form-label fw-semibold">
                        <i class="bi bi-person-plus me-1"></i>
                        <?= $st === "assigned" ? "Reassign Technician" : "Assign Technician" ?>
                    </label>

                    <select name="assigned_to" class="form-select mb-2" required>
                        <option value="">— Select technician —</option>
                        <?php foreach ($technicians as $t): ?>
                            <option value="<?= (int) $t["id"] ?>"
                                <?= (int) $incident["assigned_to"] === (int) $t["id"] ? "selected" : "" ?>>
                                <?= htmlspecialchars($t["full_name"]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <?php if (count($technicians) === 0): ?>
                        <div class="alert alert-warning small mb-2">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            No active technicians.
                            <a href="user_add.php">Add one here</a>.
                        </div>
                    <?php endif; ?>

                    <label class="form-label">Comment (optional)</label>
                    <textarea name="comment" class="form-control mb-2" rows="2"
                              placeholder="Any instructions for the technician…"></textarea>

                    <button type="submit" class="btn btn-gold w-100"
                            <?= count($technicians) === 0 ? "disabled" : "" ?>>
                        <i class="bi bi-check-lg me-1"></i>
                        <?= $st === "assigned" ? "Reassign" : "Assign" ?>
                    </button>
                </form>

            <?php endif; ?>


            <?php /* ---------- START WORK ---------- */ ?>
            <?php if ($st === "assigned"): ?>

                <form method="POST" action="incident_action.php" class="mb-4">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="start">

                    <label class="form-label">Comment (optional)</label>
                    <textarea name="comment" class="form-control mb-2" rows="2"></textarea>

                    <button type="submit" class="btn btn-gold w-100">
                        <i class="bi bi-play-fill me-1"></i> Start Work
                    </button>
                </form>

            <?php endif; ?>


            <?php /* ---------- RESOLVE ---------- */ ?>
            <?php if ($st === "in_progress"): ?>

                <form method="POST" action="incident_action.php" class="mb-4">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="resolve">

                    <label class="form-label">
                        Resolution Notes <span class="text-danger">*</span>
                    </label>
                    <textarea name="resolution_notes" class="form-control mb-2"
                              rows="3" required></textarea>

                    <label class="form-label">Comment (optional)</label>
                    <textarea name="comment" class="form-control mb-2" rows="2"></textarea>

                    <button type="submit" class="btn btn-gold w-100">
                        <i class="bi bi-check2-circle me-1"></i> Mark Resolved
                    </button>
                </form>

            <?php endif; ?>


            <?php /* ---------- VERIFY ---------- */ ?>
            <?php if ($st === "resolved"): ?>

                <form method="POST" action="incident_action.php" class="mb-4">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="verify">

                    <label class="form-label">Comment (optional)</label>
                    <textarea name="comment" class="form-control mb-2" rows="2"></textarea>

                    <button type="submit" class="btn btn-gold w-100">
                        <i class="bi bi-shield-check me-1"></i> Verify Resolution
                    </button>
                </form>

            <?php endif; ?>


            <?php /* ---------- REOPEN ---------- */ ?>
            <?php if (in_array($st, ["resolved", "verified"], true)): ?>

                <form method="POST" action="incident_action.php" class="mb-4">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="reopen">

                    <label class="form-label">Comment (optional)</label>
                    <textarea name="comment" class="form-control mb-2" rows="2"
                              placeholder="Reason for reopening…"></textarea>

                    <button type="submit" class="btn btn-outline-danger w-100">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Reopen
                    </button>
                </form>

            <?php endif; ?>


            <?php /* ---------- CLOSE ---------- */ ?>
            <?php if ($st === "verified"): ?>

                <form method="POST" action="incident_action.php" class="mb-4">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="close">

                    <label class="form-label">Comment (optional)</label>
                    <textarea name="comment" class="form-control mb-2" rows="2"></textarea>

                    <button type="submit" class="btn btn-gold w-100">
                        <i class="bi bi-lock-fill me-1"></i> Close Incident
                    </button>
                </form>

            <?php endif; ?>


            <?php /* ---------- CLOSED ---------- */ ?>
            <?php if ($st === "closed"): ?>
                <div class="empty-state py-4">
                    <i class="bi bi-check2-all"></i>
                    <p>This incident is closed.</p>
                </div>
            <?php endif; ?>


            <?php /* ---------- ADD COMMENT ---------- */ ?>
            <?php if ($st !== "closed"): ?>

                <hr>

                <form method="POST" action="incident_action.php">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="add_update">

                    <label class="form-label fw-semibold">Add Comment</label>
                    <textarea name="comment" class="form-control mb-2" rows="2"
                              placeholder="Note, update, or observation…" required></textarea>

                    <button type="submit" class="btn btn-outline-purple w-100">
                        <i class="bi bi-chat-left-text me-1"></i> Add Comment
                    </button>
                </form>

            <?php endif; ?>

        </div>
    </div>

</div>


<!-- ============ TIMELINE ============ -->
<div class="dashboard-card">

    <div class="card-header-custom">
        <div><h5>Activity Timeline</h5><p>Every action taken on this incident</p></div>
    </div>

    <?php if (count($updates) > 0): ?>

        <div class="list-group list-group-flush">

            <div class="list-group-item">
                <div class="d-flex gap-3">
                    <div class="timeline-icon" style="
                        width:36px;height:36px;border-radius:50%;
                        background:#8E1B8F;color:#fff;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="bi bi-flag-fill"></i>
                    </div>
                    <div>
                        <strong>Incident reported</strong>
                        <div class="text-muted small">
                            <?= htmlspecialchars($incident["reported_at"]) ?>
                            by <?= htmlspecialchars($incident["reporter_name"] ?? "—") ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php foreach ($updates as $u): ?>
                <?php
                [$b_bg, $b_fg] = $u["status"]
                    ? ($status_badges[$u["status"]] ?? ["#6c757d", "#fff"])
                    : ["#6c757d", "#fff"];
                ?>
                <div class="list-group-item">
                    <div class="d-flex gap-3">
                        <div class="timeline-icon" style="
                            width:36px;height:36px;border-radius:50%;
                            background:var(--cbe-gold,#D4AF37);color:#252525;
                            display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi bi-arrow-repeat"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <strong><?= htmlspecialchars($u["user_name"] ?? "System") ?></strong>
                                <small class="text-muted"><?= htmlspecialchars($u["created_at"]) ?></small>
                            </div>
                            <?php if ($u["old_status"] && $u["status"]): ?>
                                <div class="mt-1 small">
                                    <span class="text-muted">
                                        <?= ucfirst(str_replace("_", " ", $u["old_status"])) ?>
                                    </span>
                                    →
                                    <span class="badge" style="background:<?= $b_bg ?>; color:<?= $b_fg ?>;">
                                        <?= ucfirst(str_replace("_", " ", $u["status"])) ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="mt-1" style="white-space:pre-wrap;"><?= htmlspecialchars($u["update_text"]) ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="empty-state py-5">
            <i class="bi bi-clock-history"></i>
            <p>No activity recorded yet.</p>
        </div>

    <?php endif; ?>

</div>


<?php require_once "../includes/footer.php"; ?>