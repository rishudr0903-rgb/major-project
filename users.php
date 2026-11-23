<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['username'])) {
    header('Location: index.php?error=' . urlencode('Please log in first.'));
    exit;
}

$role = $_SESSION['role'];
$username = $_SESSION['username'];
$fullName = $_SESSION['full_name'] ?? $username;

if ($role !== 'admin') {
    http_response_code(403);
    die('Access denied: Only administrators can manage user accounts.');
}

$allowedManagedRoles = ['student', 'teacher', 'principal'];
$message = '';
$error = '';

function normalizeMobile10(string $phone): string
{
    $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        return substr($digits, 2);
    }
    return $digits;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'update_user') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $updatedMobile10 = normalizeMobile10(trim($_POST['mobile_number'] ?? ''));
        $newPassword = $_POST['new_password'] ?? '';

        $badMobile = preg_match('/^[6-9][0-9]{9}$/', $updatedMobile10) !== 1;
        $badNewPass = $newPassword !== '' && strlen($newPassword) < 6;

        $badUpdate = in_array(true, [
            $targetUserId <= 0,
            $badMobile,
            $badNewPass,
        ], true);

        if ($badUpdate) {
            $error = 'Enter a valid 10-digit mobile number (and if changing password, at least 6 characters).';
        } else {
            $userStmt = $pdo->prepare('SELECT username, role FROM users WHERE id = :id LIMIT 1');
            $userStmt->execute([':id' => $targetUserId]);
            $targetUser = $userStmt->fetch();

            if (!$targetUser) {
                $error = 'Selected user account was not found.';
            } elseif (!in_array((string) $targetUser['role'], $allowedManagedRoles, true)) {
                $error = 'Mobile updates here apply to Student, Teacher, and Principal accounts.';
            } elseif ($newPassword !== '') {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $updateStmt = $pdo->prepare(
                    'UPDATE users
                     SET mobile_number = :mobile_number, password_hash = :password_hash
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    ':mobile_number' => $updatedMobile10,
                    ':password_hash' => $newHash,
                    ':id' => $targetUserId,
                ]);
                $message = 'Updated mobile number and password for ' . htmlspecialchars((string) $targetUser['username'], ENT_QUOTES, 'UTF-8') . '.';
            } else {
                $updateStmt = $pdo->prepare(
                    'UPDATE users
                     SET mobile_number = :mobile_number
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    ':mobile_number' => $updatedMobile10,
                    ':id' => $targetUserId,
                ]);
                $message = 'Updated mobile number for ' . htmlspecialchars((string) $targetUser['username'], ENT_QUOTES, 'UTF-8') . '.';
            }
        }
    } else {
        $targetRole = strtolower(trim($_POST['account_role'] ?? 'student'));
        $newUsername = strtoupper(trim($_POST['username'] ?? ''));
        $newFullName = trim($_POST['full_name'] ?? '');
        $newMobile10 = normalizeMobile10(trim($_POST['mobile_number'] ?? ''));
        $newPassword = $_POST['password'] ?? '';

        $hasError = in_array(true, [
            !in_array($targetRole, $allowedManagedRoles, true),
            $newUsername === '',
            $newFullName === '',
            preg_match('/^[6-9][0-9]{9}$/', $newMobile10) !== 1,
            strlen($newPassword) < 6,
        ], true);

        if ($hasError) {
            $error = 'Select Student, Teacher, or Principal, and enter username, full name, 10-digit mobile number, and password (min 6 characters).';
        } else {
            $checkStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
            $checkStmt->execute([':username' => $newUsername]);

            if ($checkStmt->fetch()) {
                $error = 'A user with username "' . htmlspecialchars($newUsername, ENT_QUOTES, 'UTF-8') . '" already exists.';
            } else {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $insertStmt = $pdo->prepare(
                    'INSERT INTO users (username, password_hash, role, full_name, mobile_number)
                     VALUES (:username, :password_hash, :role, :full_name, :mobile_number)'
                );
                $insertStmt->execute([
                    ':username' => $newUsername,
                    ':password_hash' => $passwordHash,
                    ':role' => $targetRole,
                    ':full_name' => $newFullName,
                    ':mobile_number' => $newMobile10,
                ]);
                $message = strtoupper($targetRole) . ' account created for ' . htmlspecialchars($newFullName, ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($newUsername, ENT_QUOTES, 'UTF-8') . ').';
            }
        }
    }
}

$usersStmt = $pdo->query(
    'SELECT id, username, role, full_name, mobile_number
     FROM users
     ORDER BY id DESC'
);
$usersList = $usersStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users — PES College Portal</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="portal-wrapper dashboard-wrapper">
        <div class="dashboard-topbar">
            <div>
                <h1>Manage Student, Teacher & Principal Accounts</h1>
                <p><?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars(strtoupper($role), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>)</p>
            </div>
            <div>
                <a href="dashboard.php" class="logout-btn" style="background-color:#0b3c78; margin-right:8px;">Dashboard</a>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="error-msg" style="background-color:#e8f5e9; color:#1b5e20; border-color:#c8e6c9;">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="dash-card" style="margin-bottom:20px;">
            <h2>Create Account with Mobile Number (Admin Only)</h2>
            <form method="POST" action="users.php">
                <input type="hidden" name="action" value="create">
                <div class="input-group">
                    <label for="account_role">Role</label>
                    <select id="account_role" name="account_role" required style="width:100%; padding:10px; border:1px solid #dce3ec; border-radius:6px;">
                        <option value="student">Student</option>
                        <option value="teacher">Teacher</option>
                        <option value="principal">Principal</option>
                    </select>
                </div>
                <div class="input-group">
                    <label for="username">Username / USN / ID</label>
                    <input type="text" id="username" name="username" placeholder="e.g. U21ME24S0016 or TCH01" required>
                </div>
                <div class="input-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" placeholder="e.g. Dr. Ramesh Kulkarni" required>
                </div>
                <div class="input-group">
                    <label for="mobile_number">Registered Mobile Number (10 digits)</label>
                    <input type="text" id="mobile_number" name="mobile_number" placeholder="e.g. 9876543210" required>
                </div>
                <div class="input-group">
                    <label for="password">Initial Password (min 6 characters)</label>
                    <input type="password" id="password" name="password" minlength="6" required>
                </div>
                <button type="submit" class="login-btn">Create Account</button>
            </form>
        </div>

        <div class="dash-card">
            <h2>Existing Portal Accounts</h2>
            <?php if (empty($usersList)): ?>
                <p>No users found.</p>
            <?php else: ?>
                <table style="width:100%; border-collapse:collapse; margin-top:12px; font-size:14px;">
                    <thead>
                        <tr style="background:#f8fafc; text-align:left; border-bottom:2px solid #dce3ec;">
                            <th style="padding:10px;">Username / ID</th>
                            <th style="padding:10px;">Full Name</th>
                            <th style="padding:10px;">Mobile</th>
                            <th style="padding:10px;">Role</th>
                            <th style="padding:10px;">Update Mobile / Reset Password</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usersList as $u): ?>
                            <?php $isEditableRole = in_array((string) $u['role'], $allowedManagedRoles, true); ?>
                            <tr style="border-bottom:1px solid #eef2f6;">
                                <td style="padding:10px;"><strong><?php echo htmlspecialchars((string) $u['username'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td style="padding:10px;"><?php echo htmlspecialchars((string) $u['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="padding:10px;"><?php echo htmlspecialchars((string) ($u['mobile_number'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="padding:10px;"><?php echo htmlspecialchars(strtoupper((string) $u['role']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="padding:10px;">
                                    <?php if ($isEditableRole): ?>
                                        <form method="POST" action="users.php" style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                                            <input type="hidden" name="action" value="update_user">
                                            <input type="hidden" name="user_id" value="<?php echo (int) $u['id']; ?>">
                                            <input type="text" name="mobile_number" placeholder="10-digit mobile" value="<?php echo htmlspecialchars((string) ($u['mobile_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required style="padding:6px 8px; border:1px solid #dce3ec; border-radius:4px; font-size:13px; width:135px;">
                                            <input type="password" name="new_password" placeholder="Optional new pass" minlength="6" style="padding:6px 8px; border:1px solid #dce3ec; border-radius:4px; font-size:13px; width:145px;">
                                            <button type="submit" style="background:#0b3c78; color:#fff; border:none; padding:6px 10px; border-radius:4px; cursor:pointer; font-size:12px;">Update</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color:#64748b; font-size:12px;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>