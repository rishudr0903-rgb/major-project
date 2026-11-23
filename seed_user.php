<?php
require 'db.php';

$username = 'U21ME24S0014';
$fullName = 'Test Student';
$email = 'u21me24s0014@pescollege.edu.in';
$plainPassword = 'ChangeMe@123';
$role = 'student';
$hash = password_hash($plainPassword, PASSWORD_DEFAULT);

$stmt = $pdo->prepare(
    "INSERT IGNORE INTO users (username, full_name, email, password_hash, role)
     VALUES (:username, :full_name, :email, :password_hash, :role)"
);
$stmt->execute([
    ':username' => $username,
    ':full_name' => $fullName,
    ':email' => $email,
    ':password_hash' => $hash,
    ':role' => $role
]);

echo "Test user ready.";