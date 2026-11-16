<?php

session_start();

require_once "../config/database.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    header("Location: login.php");

    exit;

}

$username = trim($_POST["username"] ?? "");
$password = $_POST["password"] ?? "";


if ($username === "" || $password === "") {

    header("Location: login.php?error=1");

    exit;

}


$stmt = $pdo->prepare("
    SELECT *
    FROM users
    WHERE username = :username
    AND status = 'active'
    LIMIT 1
");

$stmt->execute([
    "username" => $username
]);

$user = $stmt->fetch();


if (
    $user &&
    password_verify(
        $password,
        $user["password"]
    )
) {

    session_regenerate_id(true);

    $_SESSION["user_id"] =
        $user["id"];

    $_SESSION["full_name"] =
        $user["full_name"];

    $_SESSION["username"] =
        $user["username"];

    $_SESSION["role"] =
        $user["role"];


    switch ($user["role"]) {

        case "admin":

            header(
                "Location: ../admin/dashboard.php"
            );

            break;

        case "branch_officer":

        case "support_officer":

            header(
                "Location: ../officer/officer.php"
            );

            break;


                case "technician":

            header(
                "Location: ../technician/technician.php"
            );

            break;


        default:

            session_destroy();

            header(
                "Location: login.php?error=1"
            );

            break;

    }

    exit;

}


header("Location: login.php?error=1");

exit;

?>