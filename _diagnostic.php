<?php
session_start();

echo "<pre style='font-family:monospace;font-size:14px;padding:20px;'>";
echo "=== DIAGNOSTIC ===\n\n";

/* 1. Session check */
echo "--- 1. SESSION ---\n";
if (empty($_SESSION)) {
    echo "❌ No session — you are not logged in.\n";
    echo "   → Go to /atm_fault_system/auth/login.php and log in first.\n";
} else {
    echo "✅ Logged in as: " . ($_SESSION['full_name'] ?? '?') . "\n";
    echo "   Role: " . ($_SESSION['role'] ?? '?') . "\n";
    echo "   User ID: " . ($_SESSION['user_id'] ?? '?') . "\n";
}
echo "\n";

/* 2. File existence */
echo "--- 2. FILES IN includes/ ---\n";
$root = __DIR__ . "/../includes/";
foreach (["auth_check.php","header.php","sidebar.php","footer.php"] as $f) {
    echo (file_exists($root . $f) ? "✅ " : "❌ ") . "../includes/$f\n";
}
echo "\n";

/* 3. Config */
echo "--- 3. CONFIG ---\n";
$db = __DIR__ . "/../config/database.php";
echo (file_exists($db) ? "✅ " : "❌ ") . "../config/database.php\n";
echo "\n";

/* 4. DB connect */
echo "--- 4. DATABASE ---\n";
try {
    require_once __DIR__ . "/../config/database.php";
    echo "✅ Connected\n";
    $v = $pdo->query("SELECT VERSION()")->fetchColumn();
    echo "   MySQL: $v\n";
    $c = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    echo "   Users in DB: $c\n";
    $i = $pdo->query("SELECT COUNT(*) FROM incidents")->fetchColumn();
    echo "   Incidents in DB: $i\n";
} catch (Throwable $e) {
    echo "❌ " . $e->getMessage() . "\n";
}
echo "\n";

/* 5. Files in officer/ */
echo "--- 5. FILES IN officer/ ---\n";
$required = [
    "dashboard.php","atms.php","atm_view.php",
    "incidents.php","incident_add.php","incident_view.php","incident_action.php",
    "reports.php","settings.php"
];
foreach ($required as $f) {
    $exists = file_exists(__DIR__ . "/" . $f);
    echo ($exists ? "✅ " : "❌ MISSING: ") . "officer/$f\n";
}
echo "\n";

/* 6. Logo */
echo "--- 6. LOGO ---\n";
$jpg = __DIR__ . "/../assets/images/cbe-logo.jpg";
$png = __DIR__ . "/../assets/images/cbe-logo.png";
echo (file_exists($jpg) ? "✅ " : "❌ ") . "cbe-logo.jpg\n";
echo (file_exists($png) ? "✅ " : "❌ ") . "cbe-logo.png\n";
echo "\n";

/* 7. Simulate sidebar */
echo "--- 7. TEST SIDEBAR INCLUDE ---\n";
if (!empty($_SESSION) && isset($pdo)) {
    $active_page = "dashboard";
    $page_title  = "Test";
    ob_start();
    try {
        include __DIR__ . "/../includes/sidebar.php";
        $out = ob_get_clean();
        if (strpos($out, 'class="sidebar"') !== false) {
            echo "✅ sidebar.php rendered successfully (" . strlen($out) . " bytes)\n";
        } else {
            echo "⚠️  sidebar.php ran but produced no <aside class='sidebar'>\n";
            echo "   First 500 chars of output:\n";
            echo htmlspecialchars(substr($out, 0, 500)) . "\n";
        }
    } catch (Throwable $e) {
        ob_end_clean();
        echo "❌ " . $e->getMessage() . "\n";
    }
} else {
    echo "⏭  Skipped — not logged in\n";
}

echo "</pre>";