    <?php
declare(strict_types=1);
session_start();
require_once 'db.php';

$targetUsername = 'teacher01';
$targetFullName = 'PES Teacher 01';
$targetRole = 'teacher';
$targetPassword = 'Teacher@123';
$targetMobile = '9739397776';
$targetEmail = 'rishudr0903@gmail.com';

$message = '';
$error = '';
$currentRow = null;

try {
    $hash = password_hash($targetPassword, PASSWORD_DEFAULT);

    $findStmt = $pdo->prepare(
        'SELECT id, username, full_name, role, mobile_number, email, password_hash
         FROM users
         WHERE LOWER(username) = LOWER(:username)
         LIMIT 1'
    );
    $findStmt->execute([':username' => $targetUsername]);
    $existing = $findStmt->fetch();

    if ($existing) {
        $updStmt = $pdo->prepare(
            'UPDATE users
             SET full_name = :full_name,
                 role = :role,
                 mobile_number = :mobile_number,
                 email = :email,
                 password_hash = :password_hash
             WHERE id = :id'
        );
        $updStmt->execute([
            ':full_name' => $targetFullName,
            ':role' => $targetRole,
            ':mobile_number' => $targetMobile,
            ':email' => $targetEmail,
            ':password_hash' => $hash,
            ':id' => $existing['id'],
        ]);
        $message = 'Updated existing user "' . $targetUsername . '" with role "' . $targetRole . '" and password "' . $targetPassword . '".';
    } else {
        $insStmt = $pdo->prepare(
            'INSERT INTO users (username, full_name, role, mobile_number, email, password_hash)
             VALUES (:username, :full_name, :role, :mobile_number, :email, :password_hash)'
        );
        $insStmt->execute([
            ':username' => $targetUsername,
            ':full_name' => $targetFullName,
            ':role' => $targetRole,
            ':mobile_number' => $targetMobile,
            ':email' => $targetEmail,
            ':password_hash' => $hash,
        ]);
        $message = 'Inserted new user "' . $targetUsername . '" with role "' . $targetRole . '" and password "' . $targetPassword . '".';
    }

    $verifyStmt = $pdo->prepare(
        'SELECT id, username, full_name, role, mobile_number, email, password_hash
         FROM users
         WHERE LOWER(username) = LOWER(:username)
         LIMIT 1'
    );
    $verifyStmt->execute([':username' => $targetUsername]);
    $currentRow = $verifyStmt->fetch() ?: null;
} catch (Throwable $e) {
    $error = 'Database error: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Teacher Account — PES College Portal</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="portal-wrapper">
        <div style="text-align:center; margin-bottom:20px;">
            <h1>PES College Portal</h1>
            <p>Teacher Account Provisioner</p>
        </div>

        <div class="dash-card">
            <h2>Teacher Credentials</h2>
            <?php if ($message !== ''): ?>
                <div class="error-msg" style="background-color:#e8f5e9; color:#1b5e20; border-color:#c8e6c9;">
                    <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="error-msg"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <p><strong>Role:</strong> Teachers (`teacher`)</p>
            <p><strong>Username:</strong> <?php echo htmlspecialchars($targetUsername, ENT_QUOTES, 'UTF-8'); ?></p>
            <p><strong>Password:</strong> <?php echo htmlspecialchars($targetPassword, ENT_QUOTES, 'UTF-8'); ?></p>

            <?php if ($currentRow): ?>
                <p><strong>DB ID:</strong> <?php echo (int) $currentRow['id']; ?></p>
                <p><strong>DB Role:</strong> <?php echo htmlspecialchars((string) $currentRow['role'], ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Password Hash Check:</strong> <?php echo password_verify($targetPassword, (string) $currentRow['password_hash']) ? 'MATCHES (Valid)' : 'FAILED'; ?></p>
            <?php endif; ?>

            <div style="margin-top:16px;">
                <a href="http://localhost/pes-portal/index.php" class="login-btn" style="display:inline-block; text-align:center; text-decoration:none;">Go to Login Page</a>
            </div>
        </div>
    </div>
</body>
</html>