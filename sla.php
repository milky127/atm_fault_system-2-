<?php
/**
 * SLA thresholds (hours) by priority.
 */
function sla_hours(string $priority): int {
    return match ($priority) {
        "critical" => 4,
        "high"     => 24,
        "medium"   => 72,
        "low"      => 168,
        default    => 72,
    };
}

/**
 * Returns [is_overdue, hours_open, hours_overdue, sla_hours, pct_used]
 */
function sla_status(string $priority, string $reported_at, ?string $closed_at = null): array {
    $limit = sla_hours($priority);
    $start = strtotime($reported_at);
    $end   = $closed_at ? strtotime($closed_at) : time();
    $hours = max(0, ($end - $start) / 3600);
    $over  = max(0, $hours - $limit);
    $pct   = $limit > 0 ? min(200, ($hours / $limit) * 100) : 0;

    return [
        "is_overdue"    => $over > 0,
        "hours_open"    => round($hours, 1),
        "hours_overdue" => round($over, 1),
        "sla_hours"     => $limit,
        "pct_used"      => round($pct, 0),
    ];
}

/**
 * Human-readable duration.
 */
function human_duration(float $hours): string {
    if ($hours <= 0) return "0m";
    if ($hours < 1)  return round($hours * 60) . "m";
    if ($hours < 24) {
        $h = floor($hours);
        $m = round(($hours - $h) * 60);
        return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
    }
    $d = floor($hours / 24);
    $h = floor($hours % 24);
    return $h > 0 ? "{$d}d {$h}h" : "{$d}d";
}

/**
 * Count of currently-overdue incidents, optionally scoped to a branch.
 */
function overdue_count(PDO $pdo, ?int $branch_id = null): int {
    $sql = "SELECT i.priority, i.reported_at
            FROM incidents i
            INNER JOIN atms a ON i.atm_id = a.id
            WHERE i.status NOT IN ('closed','verified','resolved')";
    if ($branch_id) $sql .= " AND a.branch_id = " . (int) $branch_id;
    $count = 0;
    foreach ($pdo->query($sql)->fetchAll() as $row) {
        if (sla_status($row["priority"], $row["reported_at"])["is_overdue"]) $count++;
    }
    return $count;
}

/**
 * Renders a small SLA badge. Pass the incident's priority and reported_at.
 */
function sla_badge(string $priority, string $reported_at, ?string $closed_at = null): string {
    $s = sla_status($priority, $reported_at, $closed_at);

    if ($s["is_overdue"]) {
        return '<span class="sla-badge sla-overdue" title="SLA: '
            . $s["sla_hours"] . 'h. Overdue by ' . human_duration($s["hours_overdue"]) . '">'
            . '<i class="bi bi-exclamation-triangle-fill"></i> '
            . 'Overdue ' . human_duration($s["hours_overdue"])
            . '</span>';
    }

    if ($s["pct_used"] >= 75) {
        return '<span class="sla-badge sla-warning" title="SLA: '
            . $s["sla_hours"] . 'h. Time used: ' . $s["pct_used"] . '%">'
            . '<i class="bi bi-hourglass-split"></i> '
            . human_duration($s["hours_open"]) . ' / ' . $s["sla_hours"] . 'h'
            . '</span>';
    }

    return '<span class="sla-badge sla-ok" title="SLA: '
        . $s["sla_hours"] . 'h">'
        . '<i class="bi bi-check-circle"></i> '
        . human_duration($s["hours_open"])
        . '</span>';
}