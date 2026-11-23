<?php
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$selectedRole = strtolower(trim($_POST['userRole'] ?? ($_POST['role'] ?? '')));
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

$allowedRoles = ['student', 'teacher', 'admin', 'principal'];

if (!in_array($selectedRole, $allowedRoles, true) || $username === '' || $password === '') {
    header('Location: index.php?error=' . urlencode('Invalid username, password, or selected role.'));
    exit;
}

$stmt = $pdo->prepare(
    'SELECT id, username, password_hash, role, full_name
     FROM users
     WHERE username = :username AND role = :role
     LIMIT 1'
);
$stmt->execute([
    ':username' => $username,
    ':role' => $selectedRole,
]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, (string) $user['password_hash'])) {
    header('Location: index.php?error=' . urlencode('Invalid username, password, or selected role.'));
    exit;
}

session_regenerate_id(true);
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['username'] = (string) $user['username'];
$_SESSION['role'] = (string) $user['role'];
$_SESSION['full_name'] = (string) ($user['full_name'] ?? $user['username']);

header('Location: dashboard.php');
exit;