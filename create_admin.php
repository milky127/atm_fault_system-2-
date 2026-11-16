<?php

require_once "config/database.php";

$full_name = "System Administrator";
$username = "admin";
$password = "admin123";
$role = "admin";

$hashed_password = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("
    INSERT INTO users
    (full_name, username, password, role, status)
    VALUES
    (:full_name, :username, :password, :role, 'active')
");

$stmt->execute([
    "full_name" => $full_name,
    "username" => $username,
    "password" => $hashed_password,
    "role" => $role
]);

echo "<h2>Admin account created successfully.</h2>";
echo "<p>Username: <strong>admin</strong></p>";
echo "<p>Password: <strong>admin123</strong></p>";
echo "<p><strong>Delete create_admin.php now for security.</strong></p>";

?>