<?php
/* Shared notifications page — included by role-specific wrappers */

require_once __DIR__ . "/../config/database.php";

$me = (int) $_SESSION["user_id"];

/* ---------- Filters ---------- */
$filter = $_GET["filter"] ?? "all";       // all | unread | read
if (!in_array($filter, ["all", "unread", "read"], true)) $filter = "all";

$page     = max(1, (int) ($_GET["page"] ?? 1));
$per_page = 25;
$offset   = ($page - 1) * $per_page;

$where  = "user_id = ?";
$params = [$me];

if ($filter === "unread") $where .= " AND is_read = 0";
if ($filter === "read")   $where .= " AND is_read = 1";

/* ---------- Stats ---------- */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$stmt->execute([$me]);
$total_count = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$me]);
$unread_count = (int) $stmt->fetchColumn();

$read_count = $total_count - $unread_count;

/* ---------- Count filtered ---------- */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE $where");
$stmt->execute($params);
$filtered_total = (int) $stmt->fetchColumn();
$total_pages    = max(1, (int) ceil($filtered_total / $per_page));
if ($page > $total_pages) $page = $total_pages;

/* ---------- Fetch page ---------- */
$stmt = $pdo->prepare("
    SELECT id, type, title, message, link, is_read, created_at
    FROM notifications
    WHERE $where
    ORDER BY created_at DESC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$notifications = $stmt->fetchAll();

/* ---------- Group by date ---------- */
$groups = [];
$today     = date("Y-m-d");
$yesterday = date("Y-m-d", strtotime("-1 day"));
$week_ago  = strtotime("-7 days");
$month_ago = strtotime("-30 days");

foreach ($notifications as $n) {
    $ts      = strtotime($n["created_at"]);
    $n_date  = date("Y-m-d", $ts);

    if ($n_date === $today)        $group = "Today";
    elseif ($n_date === $yesterday) $group = "Yesterday";
    elseif ($ts >= $week_ago)      $group = "Earlier this week";
    elseif ($ts >= $month_ago)     $group = "Earlier this month";
    else                           $group = "Older";

    $groups[$group][] = $n;
}

$read_endpoint = "../includes/notification_read.php";

$active_page = "notifications";
$page_title  = "Notifications";

require_once __DIR__ . "/header.php";
require_once __DIR__ . "/sidebar.php";
?>


<div class="page-heading">
    <div>
        <h2>Notifications</h2>
        <p>Every update on your incidents and assignments.</p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($unread_count > 0): ?>
            <form method="POST" action="<?= $read_endpoint ?>" class="m-0">
                <input type="hidden" name="all" value="1">
                <button type="submit" class="btn btn-gold">
                    <i class="bi bi-check2-all me-1"></i> Mark all as read
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>


<!-- ============ SUMMARY ============ -->

<div class="row g-4 mb-4">

    <div class="col-xl-4 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>TOTAL</p>
                <h3><?= $total_count ?></h3>
                <span>All notifications</span>
            </div>
            <div class="stat-icon purple-icon">
                <i class="bi bi-bell-fill"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-4 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>UNREAD</p>
                <h3><?= $unread_count ?></h3>
                <span class="danger-text">
                    <i class="bi bi-circle-fill"></i> New
                </span>
            </div>
            <div class="stat-icon red-icon">
                <i class="bi bi-exclamation-circle-fill"></i>
            </div>
        </div>
    </div>

    <div class="col-xl-4 col-md-6">
        <div class="stat-card">
            <div class="stat-content">
                <p>READ</p>
                <h3><?= $read_count ?></h3>
                <span class="success-text">
                    <i class="bi bi-check-circle-fill"></i> Seen
                </span>
            </div>
            <div class="stat-icon green-icon">
                <i class="bi bi-check2-all"></i>
            </div>
        </div>
    </div>

</div>


<!-- ============ FILTER TABS ============ -->

<div class="dashboard-card mb-4">
    <div class="notif-tabs">
        <a href="?filter=all"
           class="notif-tab <?= $filter === "all" ? "active" : "" ?>">
            All
            <span class="notif-tab-count"><?= $total_count ?></span>
        </a>
        <a href="?filter=unread"
           class="notif-tab <?= $filter === "unread" ? "active" : "" ?>">
            Unread
            <?php if ($unread_count > 0): ?>
                <span class="notif-tab-count danger"><?= $unread_count ?></span>
            <?php endif; ?>
        </a>
        <a href="?filter=read"
           class="notif-tab <?= $filter === "read" ? "active" : "" ?>">
            Read
            <span class="notif-tab-count"><?= $read_count ?></span>
        </a>
    </div>
</div>


<!-- ============ LIST ============ -->

<div class="dashboard-card">

    <?php if (count($notifications) > 0): ?>

        <?php foreach ($groups as $group_name => $items): ?>

            <div class="notif-group">

                <div class="notif-group-header">
                    <span><?= htmlspecialchars($group_name) ?></span>
                    <span class="notif-group-count"><?= count($items) ?></span>
                </div>

                <?php foreach ($items as $n): ?>

                    <?php
                    $icon = match ($n["type"]) {
                        "new_incident"  => "bi-exclamation-triangle-fill",
                        "assigned"      => "bi-person-plus-fill",
                        "resolved"      => "bi-check-circle-fill",
                        "verified"      => "bi-shield-check",
                        "closed"        => "bi-lock-fill",
                        "comment"       => "bi-chat-left-text-fill",
                        "reopened"      => "bi-arrow-counterclockwise",
                        default         => "bi-bell-fill",
                    };
                    $color = match ($n["type"]) {
                        "new_incident"  => "#8E1B8F",
                        "assigned"      => "#D4AF37",
                        "resolved"      => "#198754",
                        "verified"      => "#0DCAF0",
                        "closed"        => "#495057",
                        "comment"       => "#0D6EFD",
                        "reopened"      => "#DC3545",
                        default         => "#6c757d",
                    };
                    ?>

                    <form method="POST" action="<?= $read_endpoint ?>" class="notif-row-form">
                        <input type="hidden" name="id" value="<?= (int) $n["id"] ?>">
                        <input type="hidden" name="redirect" value="<?= htmlspecialchars($n["link"] ?? "") ?>">

                        <button type="submit"
                                class="notif-row <?= $n["is_read"] ? "" : "unread" ?>">

                            <div class="notif-row-icon"
                                 style="background:<?= $color ?>1A; color:<?= $color ?>;">
                                <i class="bi <?= $icon ?>"></i>
                            </div>

                            <div class="notif-row-body">
                                <div class="notif-row-head">
                                    <strong><?= htmlspecialchars($n["title"]) ?></strong>
                                    <?php if (!$n["is_read"]): ?>
                                        <span class="notif-row-dot" style="background:<?= $color ?>;"></span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($n["message"])): ?>
                                    <p><?= htmlspecialchars($n["message"]) ?></p>
                                <?php endif; ?>

                                <small>
                                    <i class="bi bi-clock me-1"></i>
                                    <?= htmlspecialchars(date("M j, Y — H:i", strtotime($n["created_at"]))) ?>
                                </small>
                            </div>

                            <?php if (!empty($n["link"])): ?>
                                <div class="notif-row-arrow">
                                    <i class="bi bi-chevron-right"></i>
                                </div>
                            <?php endif; ?>

                        </button>
                    </form>

                <?php endforeach; ?>

            </div>

        <?php endforeach; ?>

    <?php else: ?>

        <div class="empty-state py-5">
            <i class="bi bi-bell-slash"></i>
            <p>
                <?php if ($filter === "unread"): ?>
                    No unread notifications.
                <?php elseif ($filter === "read"): ?>
                    No read notifications yet.
                <?php else: ?>
                    You don't have any notifications yet.
                <?php endif; ?>
            </p>
        </div>

    <?php endif; ?>

</div>


<!-- ============ PAGINATION ============ -->

<?php if ($total_pages > 1): ?>

    <div class="notif-pagination">

        <?php if ($page > 1): ?>
            <a href="?filter=<?= $filter ?>&page=<?= $page - 1 ?>" class="notif-page-btn">
                <i class="bi bi-chevron-left"></i> Previous
            </a>
        <?php endif; ?>

        <span class="notif-page-info">
            Page <?= $page ?> of <?= $total_pages ?>
        </span>

        <?php if ($page < $total_pages): ?>
            <a href="?filter=<?= $filter ?>&page=<?= $page + 1 ?>" class="notif-page-btn">
                Next <i class="bi bi-chevron-right"></i>
            </a>
        <?php endif; ?>

    </div>

<?php endif; ?>


<?php require_once __DIR__ . "/footer.php"; ?>