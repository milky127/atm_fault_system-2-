<?php
if (!isset($pdo) || empty($_SESSION["user_id"])) return;

$bell_me = (int) $_SESSION["user_id"];

/* Read the current folder so the "View all" link points correctly */
$bell_folder = basename(dirname($_SERVER["SCRIPT_FILENAME"] ?? ""));

$bell_view_all_url = match ($bell_folder) {
    "admin"      => "notifications.php",
    "officer"    => "notifications.php",
    "technician" => "notifications.php",
    default      => "notifications.php",
};

$stmt = $pdo->prepare("
    SELECT id, type, title, message, link, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY is_read ASC, created_at DESC
    LIMIT 8
");
$stmt->execute([$bell_me]);
$bell_items = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$bell_me]);
$bell_unread = (int) $stmt->fetchColumn();

$bell_read_endpoint = "../includes/notification_read.php";
?>

<div class="notif-wrapper" id="notifWrapper">

    <button class="topbar-icon notif-bell-btn" type="button" id="notifBellBtn" aria-label="Notifications">
        <i class="bi bi-bell"></i>
        <?php if ($bell_unread > 0): ?>
            <span class="notif-count"><?= $bell_unread > 9 ? "9+" : $bell_unread ?></span>
        <?php endif; ?>
    </button>

    <div class="notif-dropdown" id="notifDropdown">

        <div class="notif-header">
            <div>
                <strong>Notifications</strong>
                <?php if ($bell_unread > 0): ?>
                    <small class="text-muted ms-2"><?= $bell_unread ?> unread</small>
                <?php endif; ?>
            </div>
            <?php if ($bell_unread > 0): ?>
                <form method="POST" action="<?= $bell_read_endpoint ?>" class="d-inline m-0">
                    <input type="hidden" name="all" value="1">
                    <button type="submit" class="btn btn-sm btn-link p-0 text-decoration-none notif-mark-all">
                        Mark all read
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <div class="notif-list">

            <?php if (count($bell_items) > 0): ?>

                <?php foreach ($bell_items as $n): ?>
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

                    <form method="POST" action="<?= $bell_read_endpoint ?>" class="notif-item-form">
                        <input type="hidden" name="id" value="<?= (int) $n["id"] ?>">
                        <input type="hidden" name="redirect" value="<?= htmlspecialchars($n["link"] ?? "") ?>">

                        <button type="submit"
                                class="notif-item <?= $n["is_read"] ? "" : "unread" ?>"
                                style="border-left-color: <?= $color ?>;">

                            <div class="notif-icon"
                                 style="background:<?= $color ?>1A; color:<?= $color ?>;">
                                <i class="bi <?= $icon ?>"></i>
                            </div>

                            <div class="notif-body">
                                <strong><?= htmlspecialchars($n["title"]) ?></strong>
                                <?php if (!empty($n["message"])): ?>
                                    <p><?= htmlspecialchars($n["message"]) ?></p>
                                <?php endif; ?>
                                <small><?= htmlspecialchars(date("M j, H:i", strtotime($n["created_at"]))) ?></small>
                            </div>

                        </button>
                    </form>
                <?php endforeach; ?>

            <?php else: ?>

                <div class="notif-empty">
                    <i class="bi bi-bell-slash"></i>
                    <p>No notifications yet</p>
                </div>

            <?php endif; ?>

        </div>

        <div class="notif-footer">
            <a href="<?= $bell_view_all_url ?>" class="notif-view-all">
                <span>View all notifications</span>
                <i class="bi bi-arrow-right"></i>
            </a>
        </div>

    </div>
</div>

<script>
(function () {
    const wrapper = document.getElementById("notifWrapper");
    const btn     = document.getElementById("notifBellBtn");
    if (!wrapper || !btn) return;

    btn.addEventListener("click", function (e) {
        e.stopPropagation();
        wrapper.classList.toggle("open");
    });

    document.addEventListener("click", function (e) {
        if (!wrapper.contains(e.target)) {
            wrapper.classList.remove("open");
        }
    });

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") wrapper.classList.remove("open");
    });
})();
</script>