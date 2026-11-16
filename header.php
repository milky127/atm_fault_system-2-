<?php
/**
 * Document head + opening <body>.
 *
 * Set these before including:
 *   $page_title  (string)
 *   $active_page (string)   -- used by sidebar.php
 *
 * Also computes $open_incidents so any page can use the badge count.
 */

if (!isset($pdo)) {
    require_once __DIR__ . "/../config/database.php";
}

$page_title = $page_title ?? "Dashboard";

if (!isset($open_incidents)) {
    $open_incidents = (int) $pdo
        ->query("
            SELECT COUNT(*)
            FROM incidents
            WHERE status NOT IN ('closed', 'verified')
        ")
        ->fetchColumn();
}
?>
<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>

        <?= htmlspecialchars($page_title) ?>
        | ATM Fault Management System

    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

</head>


<body class="dashboard-body">