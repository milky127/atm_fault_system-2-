<?php
require_once "../includes/auth_check.php";
require_once "../config/database.php";
require_once "../includes/sla.php";

if (!in_array($_SESSION["role"], ["branch_officer", "support_officer"], true)) {
    header("Location: ../auth/login.php");
    exit;
}

/* ---------- Date range ---------- */
$from = trim($_GET["from"] ?? date("Y-m-01"));
$to   = trim($_GET["to"]   ?? date("Y-m-d"));

if (!DateTime::createFromFormat("Y-m-d", $from)) $from = date("Y-m-01");
if (!DateTime::createFromFormat("Y-m-d", $to))   $to   = date("Y-m-d");

$from_dt = $from . " 00:00:00";
$to_dt   = $to   . " 23:59:59";

/* ---------- Summary ---------- */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM incidents WHERE reported_at BETWEEN :from AND :to");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$total_period = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM incidents WHERE status <> 'closed' AND reported_at BETWEEN :from AND :to");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$open_period = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM incidents WHERE status = 'closed' AND reported_at BETWEEN :from AND :to");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$closed_period = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT AVG(TIMESTAMPDIFF(MINUTE, reported_at, resolved_at))
    FROM incidents
    WHERE resolved_at IS NOT NULL AND reported_at BETWEEN :from AND :to
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
    LEFT JOIN incidents i ON fc.id = i.category_id AND i.reported_at BETWEEN :from AND :to
    GROUP BY fc.id, fc.category_name
    ORDER BY total DESC
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$by_category = $stmt->fetchAll();

/* ---------- By priority ---------- */
$stmt = $pdo->prepare("SELECT priority, COUNT(*) AS total FROM incidents WHERE reported_at BETWEEN :from AND :to GROUP BY priority");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$by_priority = ["low" => 0, "medium" => 0, "high" => 0, "critical" => 0];
foreach ($stmt->fetchAll() as $r) $by_priority[$r["priority"]] = (int) $r["total"];

/* ---------- By status ---------- */
$stmt = $pdo->prepare("SELECT status, COUNT(*) AS total FROM incidents WHERE reported_at BETWEEN :from AND :to GROUP BY status");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$by_status = ["reported"=>0,"under_review"=>0,"assigned"=>0,"in_progress"=>0,"resolved"=>0,"verified"=>0,"closed"=>0];
foreach ($stmt->fetchAll() as $r) $by_status[$r["status"]] = (int) $r["total"];

/* ---------- Top ATMs ---------- */
$stmt = $pdo->prepare("
    SELECT a.atm_code, b.branch_name, COUNT(i.id) AS total
    FROM incidents i
    INNER JOIN atms a ON i.atm_id = a.id
    LEFT JOIN branches b ON a.branch_id = b.id
    WHERE i.reported_at BETWEEN :from AND :to
    GROUP BY a.id, a.atm_code, b.branch_name
    ORDER BY total DESC
    LIMIT 10
");
$stmt->execute(["from" => $from_dt, "to" => $to_dt]);
$top_atms = $stmt->fetchAll();

$overdue = overdue_count($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ATM Fault Report — <?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            color: #222;
            margin: 0;
            padding: 30px;
            background: #f5f5f5;
        }
        .page { max-width: 900px; margin: 0 auto; background: #fff; padding: 40px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); }

        .report-header { text-align: center; padding-bottom: 20px; border-bottom: 3px solid #D4AF37; margin-bottom: 30px; }
        .report-header h1 { color: #8E1B8F; margin: 0 0 6px 0; font-size: 24px; letter-spacing: 0.5px; }
        .report-header h2 { color: #666; margin: 0 0 10px 0; font-size: 15px; font-weight: 400; }
        .report-header p  { color: #888; margin: 0; font-size: 13px; }

        .meta { display: flex; justify-content: space-between; margin-bottom: 30px; padding: 12px 18px; background: #f8f8f8; border-radius: 8px; font-size: 12px; color: #666; }

        .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 30px; }
        .kpi { background: #f8f8f8; border-left: 3px solid #8E1B8F; padding: 16px; border-radius: 8px; }
        .kpi.kpi-danger  { border-color: #dc3545; }
        .kpi.kpi-success { border-color: #198754; }
        .kpi.kpi-gold    { border-color: #D4AF37; }
        .kpi-label { font-size: 10px; font-weight: 700; letter-spacing: 1px; color: #888; text-transform: uppercase; margin-bottom: 6px; }
        .kpi-value { font-size: 26px; font-weight: 700; color: #252525; line-height: 1; }
        .kpi-sub   { font-size: 11px; color: #999; margin-top: 4px; }

        h3.section-title {
            color: #8E1B8F;
            font-size: 15px;
            margin: 30px 0 12px 0;
            padding-bottom: 6px;
            border-bottom: 1px solid #eee;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        table { width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 13px; }
        th { background: #f8f8f8; color: #666; text-align: left; padding: 10px 12px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; border-bottom: 1px solid #e5e5e5; }
        td { padding: 10px 12px; border-bottom: 1px solid #f2f2f2; }
        tr:last-child td { border-bottom: none; }
        .text-end { text-align: right; }

        .badge-cell { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .b-low { background: #eee; color: #555; }
        .b-medium { background: #e7f1ff; color: #0d6efd; }
        .b-high { background: #fff4d6; color: #9a7200; }
        .b-critical { background: #fde2e4; color: #b02a37; }

        .overdue-alert {
            background: #fde2e4;
            color: #b02a37;
            padding: 14px 18px;
            border-radius: 8px;
            border-left: 4px solid #dc3545;
            margin-bottom: 24px;
            font-size: 13px;
        }
        .overdue-alert strong { display: block; font-size: 15px; margin-bottom: 2px; }

        .report-footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            text-align: center;
            font-size: 11px;
            color: #999;
        }

        .print-bar {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 100;
            display: flex;
            gap: 8px;
        }
        .print-bar button {
            padding: 12px 22px;
            background: #8E1B8F;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            box-shadow: 0 6px 18px rgba(142,27,143,0.35);
        }
        .print-bar button:hover { background: #681368; }
        .print-bar a {
            padding: 12px 22px;
            background: #fff;
            color: #666;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .page { box-shadow: none; padding: 20px; max-width: 100%; }
            .print-bar { display: none !important; }
            .kpi { break-inside: avoid; }
            h3.section-title { break-after: avoid; }
            table { break-inside: auto; }
            tr { break-inside: avoid; }
        }
    </style>
</head>
<body>

<div class="print-bar">
    <button type="button" onclick="window.print()">
        <i>🖨</i> Save as PDF / Print
    </button>
    <a href="reports.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">← Back to Reports</a>
</div>

<div class="page">

    <div class="report-header">
        <h1>ATM Fault Report</h1>
        <h2>Commercial Bank of Ethiopia — ATM Support Management System</h2>
        <p>Period: <?= htmlspecialchars($from) ?> → <?= htmlspecialchars($to) ?></p>
    </div>

    <div class="meta">
        <span><strong>Generated:</strong> <?= date("Y-m-d H:i:s") ?></span>
        <span><strong>By:</strong> <?= htmlspecialchars($_SESSION["full_name"]) ?></span>
    </div>

    <?php if ($overdue > 0): ?>
        <div class="overdue-alert">
            <strong>⚠ <?= $overdue ?> incident<?= $overdue === 1 ? "" : "s" ?> currently past SLA</strong>
            Requires immediate attention.
        </div>
    <?php endif; ?>

    <div class="kpi-grid">
        <div class="kpi">
            <div class="kpi-label">Total Incidents</div>
            <div class="kpi-value"><?= $total_period ?></div>
            <div class="kpi-sub">In selected period</div>
        </div>
        <div class="kpi kpi-danger">
            <div class="kpi-label">Open</div>
            <div class="kpi-value"><?= $open_period ?></div>
            <div class="kpi-sub">Still active</div>
        </div>
        <div class="kpi kpi-success">
            <div class="kpi-label">Closed</div>
            <div class="kpi-value"><?= $closed_period ?></div>
            <div class="kpi-sub">Completed</div>
        </div>
        <div class="kpi kpi-gold">
            <div class="kpi-label">Avg Resolution</div>
            <div class="kpi-value"><?= htmlspecialchars($avg_label) ?></div>
            <div class="kpi-sub">Report → resolve</div>
        </div>
    </div>

    <h3 class="section-title">Faults by Category</h3>
    <table>
        <thead><tr><th>Category</th><th class="text-end">Incidents</th></tr></thead>
        <tbody>
        <?php foreach ($by_category as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row["category_name"]) ?></td>
                <td class="text-end"><strong><?= (int) $row["total"] ?></strong></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h3 class="section-title">Faults by Priority</h3>
    <table>
        <thead><tr><th>Priority</th><th class="text-end">Count</th></tr></thead>
        <tbody>
            <tr><td><span class="badge-cell b-low">Low</span></td><td class="text-end"><strong><?= $by_priority["low"] ?></strong></td></tr>
            <tr><td><span class="badge-cell b-medium">Medium</span></td><td class="text-end"><strong><?= $by_priority["medium"] ?></strong></td></tr>
            <tr><td><span class="badge-cell b-high">High</span></td><td class="text-end"><strong><?= $by_priority["high"] ?></strong></td></tr>
            <tr><td><span class="badge-cell b-critical">Critical</span></td><td class="text-end"><strong><?= $by_priority["critical"] ?></strong></td></tr>
        </tbody>
    </table>

    <h3 class="section-title">Faults by Status</h3>
    <table>
        <thead><tr><th>Status</th><th class="text-end">Count</th></tr></thead>
        <tbody>
            <?php foreach ($by_status as $k => $v): ?>
                <tr>
                    <td><?= ucfirst(str_replace("_", " ", $k)) ?></td>
                    <td class="text-end"><strong><?= (int) $v ?></strong></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (count($top_atms) > 0): ?>
        <h3 class="section-title">Top 10 ATMs by Incidents</h3>
        <table>
            <thead><tr><th>#</th><th>ATM</th><th>Branch</th><th class="text-end">Incidents</th></tr></thead>
            <tbody>
            <?php foreach ($top_atms as $i => $a): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><strong><?= htmlspecialchars($a["atm_code"]) ?></strong></td>
                    <td><?= htmlspecialchars($a["branch_name"] ?? "—") ?></td>
                    <td class="text-end"><strong><?= (int) $a["total"] ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="report-footer">
        ATM Fault Reporting &amp; Tracking System · Commercial Bank of Ethiopia<br>
        This report is system-generated and reflects data at the time of export.
    </div>

</div>

<script>
    /* Trigger print dialog automatically after the page loads */
    window.addEventListener("load", function () {
        setTimeout(function () { window.print(); }, 400);
    });
</script>

</body>
</html>