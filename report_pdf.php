<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";
require_once "../includes/sla.php";

if (!in_array($_SESSION["role"], ["branch_officer", "support_officer"], true)) {
    header("Location: ../auth/login.php");
    exit;
}

require_once __DIR__ . "/../libs/dompdf/autoload.inc.php";
use Dompdf\Dompdf;
use Dompdf\Options;

$me   = (int) $_SESSION["user_id"];
$role = $_SESSION["role"];

/* ---------- Scope ---------- */
$my_branch = null;
if ($role === "branch_officer") {
    $stmt = $pdo->prepare("SELECT branch_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$me]);
    $b = $stmt->fetchColumn();
    $my_branch = ($b !== null && $b !== false) ? (int) $b : null;
}
$scoped         = ($role === "branch_officer" && $my_branch !== null);
$branch_id_safe = $scoped ? (int) $my_branch : 0;
$scope_label    = $scoped ? "Your Branch" : "Network-Wide";
$scope_sql      = $scoped ? " AND a.branch_id = {$branch_id_safe}" : "";

/* ---------- Date range ---------- */
$from = trim($_GET["from"] ?? date("Y-m-01"));
$to   = trim($_GET["to"]   ?? date("Y-m-d"));

if (!DateTime::createFromFormat("Y-m-d", $from)) $from = date("Y-m-01");
if (!DateTime::createFromFormat("Y-m-d", $to))   $to   = date("Y-m-d");

$from_dt = $from . " 00:00:00";
$to_dt   = $to   . " 23:59:59";

/* ---------- Summary ---------- */
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    WHERE i.reported_at BETWEEN :from AND :to {$scope_sql}
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$total_period = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    WHERE i.status <> 'closed' AND i.reported_at BETWEEN :from AND :to {$scope_sql}
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$open_period = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    WHERE i.status = 'closed' AND i.reported_at BETWEEN :from AND :to {$scope_sql}
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$closed_period = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT AVG(TIMESTAMPDIFF(MINUTE, i.reported_at, i.resolved_at))
    FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    WHERE i.resolved_at IS NOT NULL
      AND i.reported_at BETWEEN :from AND :to {$scope_sql}
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$avg_minutes = (int) round((float) $stmt->fetchColumn());
$avg_label = $avg_minutes > 0
    ? intdiv($avg_minutes, 60) . "h " . ($avg_minutes % 60) . "m"
    : "—";

/* ---------- By category ---------- */
$stmt = $pdo->prepare("
    SELECT fc.category_name, COUNT(i.id) AS total
    FROM fault_categories fc
    LEFT JOIN incidents i ON fc.id = i.category_id
        AND i.reported_at BETWEEN :from AND :to
        " . ($scoped ? "AND i.atm_id IN (SELECT id FROM atms WHERE branch_id = {$branch_id_safe})" : "") . "
    GROUP BY fc.id, fc.category_name
    ORDER BY total DESC
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$by_category = $stmt->fetchAll();

/* ---------- By priority ---------- */
$stmt = $pdo->prepare("
    SELECT i.priority, COUNT(*) AS total
    FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    WHERE i.reported_at BETWEEN :from AND :to {$scope_sql}
    GROUP BY i.priority
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$by_priority = ["low" => 0, "medium" => 0, "high" => 0, "critical" => 0];
foreach ($stmt->fetchAll() as $r) $by_priority[$r["priority"]] = (int) $r["total"];

/* ---------- By status ---------- */
$stmt = $pdo->prepare("
    SELECT i.status, COUNT(*) AS total
    FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    WHERE i.reported_at BETWEEN :from AND :to {$scope_sql}
    GROUP BY i.status
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$by_status = ["reported"=>0,"under_review"=>0,"assigned"=>0,"in_progress"=>0,"resolved"=>0,"verified"=>0,"closed"=>0];
foreach ($stmt->fetchAll() as $r) $by_status[$r["status"]] = (int) $r["total"];

/* ---------- Top ATMs ---------- */
$stmt = $pdo->prepare("
    SELECT a.atm_code, b.branch_name, COUNT(i.id) AS total
    FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    LEFT JOIN branches b ON a.branch_id = b.id
    WHERE i.reported_at BETWEEN :from AND :to {$scope_sql}
    GROUP BY a.id, a.atm_code, b.branch_name
    ORDER BY total DESC
    LIMIT 10
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$top_atms = $stmt->fetchAll();

$overdue = overdue_count($pdo, $scoped ? $branch_id_safe : null);

/* ---------- Build HTML ---------- */
$html = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 30px 40px; }
    body { font-family: DejaVu Sans, Arial, sans-serif; color: #222; font-size: 11px; }
    .hdr { text-align: center; padding-bottom: 14px; border-bottom: 3px solid #D4AF37; margin-bottom: 20px; }
    .hdr h1 { color: #8E1B8F; margin: 0 0 4px 0; font-size: 20px; }
    .hdr h2 { color: #666; margin: 0 0 6px 0; font-size: 12px; font-weight: normal; }
    .hdr p  { color: #888; margin: 0; font-size: 10px; }

    .meta { width: 100%; margin-bottom: 18px; padding: 8px 12px; background: #f6f6f6; font-size: 10px; color: #666; }
    .meta td { padding: 2px 6px; }

    .kpi { width: 100%; margin-bottom: 22px; border-collapse: separate; border-spacing: 8px 0; }
    .kpi td { width: 25%; background: #f6f6f6; border-left: 3px solid #8E1B8F; padding: 10px 12px; }
    .kpi .kpi-lbl { font-size: 9px; font-weight: bold; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
    .kpi .kpi-val { font-size: 22px; font-weight: bold; color: #252525; }
    .kpi .kpi-sub { font-size: 9px; color: #999; margin-top: 2px; }
    .kpi .kpi-danger  { border-left-color: #dc3545; }
    .kpi .kpi-success { border-left-color: #198754; }
    .kpi .kpi-gold    { border-left-color: #D4AF37; }

    h3 { color: #8E1B8F; font-size: 12px; margin: 20px 0 8px 0; padding-bottom: 4px; border-bottom: 1px solid #eee; text-transform: uppercase; letter-spacing: 0.6px; }

    table.data { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    table.data th { background: #f6f6f6; color: #555; text-align: left; padding: 7px 10px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: 1px solid #e0e0e0; }
    table.data td { padding: 7px 10px; border-bottom: 1px solid #f0f0f0; }
    .text-end { text-align: right; }

    .overdue { background: #fde2e4; color: #b02a37; padding: 10px 14px; border-left: 4px solid #dc3545; margin-bottom: 16px; font-size: 11px; }
    .overdue strong { display: block; font-size: 13px; margin-bottom: 2px; }

    .footer { margin-top: 30px; padding-top: 14px; border-top: 1px solid #eee; text-align: center; font-size: 9px; color: #999; }
</style>
</head>
<body>

    <div class="hdr">
        <h1>ATM Fault Report</h1>
        <h2>Commercial Bank of Ethiopia — ATM Support Management System</h2>
        <p>Period: ' . htmlspecialchars($from) . ' &rarr; ' . htmlspecialchars($to) . ' · Scope: ' . htmlspecialchars($scope_label) . '</p>
    </div>

    <table class="meta">
        <tr>
            <td><strong>Generated:</strong> ' . date("Y-m-d H:i:s") . '</td>
            <td style="text-align:right;"><strong>By:</strong> ' . htmlspecialchars($_SESSION["full_name"]) . '</td>
        </tr>
    </table>';

if ($overdue > 0) {
    $html .= '
    <div class="overdue">
        <strong>⚠ ' . $overdue . ' incident' . ($overdue === 1 ? "" : "s") . ' currently past SLA</strong>
        Requires immediate attention.
    </div>';
}

$html .= '
    <table class="kpi">
        <tr>
            <td><div class="kpi-lbl">Total Incidents</div><div class="kpi-val">' . $total_period . '</div><div class="kpi-sub">In selected period</div></td>
            <td class="kpi-danger"><div class="kpi-lbl">Open</div><div class="kpi-val">' . $open_period . '</div><div class="kpi-sub">Still active</div></td>
            <td class="kpi-success"><div class="kpi-lbl">Closed</div><div class="kpi-val">' . $closed_period . '</div><div class="kpi-sub">Completed</div></td>
            <td class="kpi-gold"><div class="kpi-lbl">Avg Resolution</div><div class="kpi-val">' . htmlspecialchars($avg_label) . '</div><div class="kpi-sub">Report &rarr; resolve</div></td>
        </tr>
    </table>

    <h3>Faults by Category</h3>
    <table class="data">
        <thead><tr><th>Category</th><th class="text-end">Incidents</th></tr></thead>
        <tbody>';
foreach ($by_category as $row) {
    $html .= '<tr><td>' . htmlspecialchars($row["category_name"]) . '</td><td class="text-end"><strong>' . (int) $row["total"] . '</strong></td></tr>';
}
$html .= '
        </tbody>
    </table>

    <h3>Faults by Priority</h3>
    <table class="data">
        <thead><tr><th>Priority</th><th class="text-end">Count</th></tr></thead>
        <tbody>
            <tr><td>Low</td><td class="text-end"><strong>' . $by_priority["low"] . '</strong></td></tr>
            <tr><td>Medium</td><td class="text-end"><strong>' . $by_priority["medium"] . '</strong></td></tr>
            <tr><td>High</td><td class="text-end"><strong>' . $by_priority["high"] . '</strong></td></tr>
            <tr><td>Critical</td><td class="text-end"><strong>' . $by_priority["critical"] . '</strong></td></tr>
        </tbody>
    </table>

    <h3>Faults by Status</h3>
    <table class="data">
        <thead><tr><th>Status</th><th class="text-end">Count</th></tr></thead>
        <tbody>';
foreach ($by_status as $k => $v) {
    $html .= '<tr><td>' . ucfirst(str_replace("_", " ", $k)) . '</td><td class="text-end"><strong>' . (int) $v . '</strong></td></tr>';
}
$html .= '
        </tbody>
    </table>';

if (count($top_atms) > 0) {
    $html .= '
    <h3>Top 10 ATMs by Incidents</h3>
    <table class="data">
        <thead><tr><th>#</th><th>ATM</th><th>Branch</th><th class="text-end">Incidents</th></tr></thead>
        <tbody>';
    foreach ($top_atms as $i => $a) {
        $html .= '<tr><td>' . ($i + 1) . '</td><td><strong>' . htmlspecialchars($a["atm_code"]) . '</strong></td><td>' . htmlspecialchars($a["branch_name"] ?? "—") . '</td><td class="text-end"><strong>' . (int) $a["total"] . '</strong></td></tr>';
    }
    $html .= '</tbody></table>';
}

$html .= '
    <div class="footer">
        ATM Fault Reporting &amp; Tracking System · Commercial Bank of Ethiopia<br>
        This report is system-generated and reflects data at the time of export.
    </div>

</body>
</html>';

/* ---------- Generate PDF ---------- */
$options = new Options();
$options->set("isRemoteEnabled", false);
$options->set("defaultFont", "DejaVu Sans");

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper("A4", "portrait");
$dompdf->render();

$filename = "ATM_Fault_Report_" . $from . "_to_" . $to . ".pdf";

$dompdf->stream($filename, ["Attachment" => true]);
exit;