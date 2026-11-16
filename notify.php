<?php
/**
 * Notification helper functions.
 *
 * Include this in any page that needs to create notifications.
 * Requires $pdo to be available.
 */

if (!function_exists("create_notification")) {

    /**
     * Insert a notification, building a role-appropriate link.
     */
    function create_notification(
        PDO    $pdo,
        int    $user_id,
        string $type,
        string $title,
        string $message,
        int    $incident_id = 0,
        string $custom_link = ""
    ): void {
        /* Build the link if we have an incident ID */
        $link = $custom_link;

        if ($link === "" && $incident_id > 0) {
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$user_id]);
            $role = $stmt->fetchColumn() ?: "admin";

            $link = match ($role) {
                "technician"      => "technician.php?action=view&id={$incident_id}",
                "branch_officer",
                "support_officer" => "officer.php?action=incident_view&id={$incident_id}",
                default           => "incident_view.php?id={$incident_id}",
            };
        }

        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, link)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id,
            $type,
            $title,
            $message !== "" ? $message : null,
            $link !== "" ? $link : null,
        ]);
    }
}


if (!function_exists("notify_admins")) {

    /**
     * Send a notification to every active admin.
     */
    function notify_admins(
        PDO    $pdo,
        string $type,
        string $title,
        string $message,
        int    $incident_id = 0
    ): void {
        $admins = $pdo->query("
            SELECT id FROM users
            WHERE role = 'admin' AND status = 'active'
        ")->fetchAll();

        foreach ($admins as $a) {
            create_notification($pdo, (int) $a["id"], $type, $title, $message, $incident_id);
        }
    }
}


if (!function_exists("notify_incident_others")) {

    /**
     * Notify the reporter + assigned technician of an incident,
     * skipping the user who triggered the action.
     */
    function notify_incident_others(
        PDO    $pdo,
        int    $incident_id,
        int    $except_user_id,
        string $type,
        string $title,
        string $message
    ): void {
        $stmt = $pdo->prepare("
            SELECT reported_by, assigned_to
            FROM incidents
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$incident_id]);
        $inc = $stmt->fetch();

        if (!$inc) return;

        $recipients = [];

        if (!empty($inc["reported_by"]) && (int) $inc["reported_by"] !== $except_user_id) {
            $recipients[] = (int) $inc["reported_by"];
        }
        if (!empty($inc["assigned_to"]) && (int) $inc["assigned_to"] !== $except_user_id) {
            $recipients[] = (int) $inc["assigned_to"];
        }

        foreach (array_unique($recipients) as $uid) {
            create_notification($pdo, $uid, $type, $title, $message, $incident_id);
        }
    }
}


if (!function_exists("notify_user")) {

    /**
     * Notify a specific user about an incident.
     */
    function notify_user(
        PDO    $pdo,
        int    $user_id,
        string $type,
        string $title,
        string $message,
        int    $incident_id = 0
    ): void {
        create_notification($pdo, $user_id, $type, $title, $message, $incident_id);
    }
}