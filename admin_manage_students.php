<?php
session_start();
require_once 'db.php';

$message = '';
$error = '';
$selectedStudent = null;

function adminNormalizeMobile10(string $phone): string
{
    $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        return substr($digits, 2);
    }
    return $digits;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_contact';
    $targetUsername = trim($_POST['username'] ?? '');

    if ($targetUsername === '') {
        $error = 'Enter a student username or USN.';
    } else {
        $findStmt = $pdo->prepare(
            'SELECT id, username, role, mobile_number, email
             FROM users
             WHERE username = :username AND role = :role
             LIMIT 1'
        );
        $findStmt->execute([
            ':username' => $targetUsername,
            ':role' => 'student',
        ]);
        $studentRow = $findStmt->fetch();

        if (!$studentRow) {
            $error = 'No student account found with username ' . htmlspecialchars($targetUsername, ENT_QUOTES, 'UTF-8') . '.';
        } elseif ($action === 'save_contact') {
            $mobile10 = adminNormalizeMobile10(trim($_POST['mobile_number'] ?? ''));
            $email = strtolower(trim($_POST['email'] ?? ''));

            $badMobile = ($mobile10 !== '' && preg_match('/^[6-9][0-9]{9}$/', $mobile10) !== 1);
            $badEmail = ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false);
            $bothEmpty = ($mobile10 === '' && $email === '');

            if ($badMobile || $badEmail || $bothEmpty) {
                $error = 'Enter at least a valid 10-digit mobile number or a valid email address.';
            } else {
                $updateStmt = $pdo->prepare(
                    'UPDATE users
                     SET mobile_number = :mobile_number, email = :email
                     WHERE id = :id AND role = :role'
                );
                $updateStmt->execute([
                    ':mobile_number' => ($mobile10 !== '') ? $mobile10 : null,
                    ':email' => ($email !== '') ? $email : null,
                    ':id' => $studentRow['id'],
                    ':role' => 'student',
                ]);

                $message = 'Saved contact details for student ' . htmlspecialchars((string) $studentRow['username'], ENT_QUOTES, 'UTF-8') . '.';
            }
        }

        $reloadStmt = $pdo->prepare(
            'SELECT id, username, role, mobile_number, email
             FROM users
             WHERE username = :username AND role = :role
             LIMIT 1'
        );
        $reloadStmt->execute([
            ':username' => $targetUsername,
            ':role' => 'student',
        ]);
        $selectedStudent = $reloadStmt->fetch() ?: null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Manage Student OTP Contacts</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="portal-wrapper">
        <div class="portal-header">
            <h1>PES College Portal</h1>
            <p>Admin — Student Mobile & Email for OTP</p>
        </div>

        <div class="dash-card">
            <?php if ($message !== ''): ?>
                <div class="error-msg" style="background-color:#e8f5e9; color:#1b5e20; border-color:#c8e6c9;">
                    <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="error-msg"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <h2>Add / Update Student OTP Contact</h2>
            <form method="POST" action="admin_manage_students.php">
                <input type="hidden" name="action" value="save_contact">
                <div class="input-group">
                    <label for="username">Student Username / USN</label>
                    <input type="text" id="username" name="username" value="<?php echo htmlspecialchars((string) ($selectedStudent['username'] ?? 'u21me24s0014'), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="input-group">
                    <label for="mobile_number">Student Mobile Number (10 digits)</label>
                    <input type="text" id="mobile_number" name="mobile_number" value="<?php echo htmlspecialchars((string) ($selectedStudent['mobile_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. 9739397776">
                </div>
                <div class="input-group">
                    <label for="email">Student Email Address</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars((string) ($selectedStudent['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. rishudr0903@gmail.com">
                </div>
                <button type="submit" class="login-btn">Save Student Contact</button>
            </form>

            <?php if ($selectedStudent): ?>
                <p style="margin-top:14px;">
                    Current saved record for <strong><?php echo htmlspecialchars((string) $selectedStudent['username'], ENT_QUOTES, 'UTF-8'); ?></strong>:<br>
                    Mobile: <strong><?php echo htmlspecialchars((string) ($selectedStudent['mobile_number'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></strong><br>
                    Email: <strong><?php echo htmlspecialchars((string) ($selectedStudent['email'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></strong>
                </p>
            <?php endif; ?>

            <p style="margin-top:16px; text-align:center;">
                <a href="forgot_password.php">Go to Forgot Password</a> | <a href="index.php">Back to Login</a>
            </p>
        </div>
    </div>
</body>
</html>