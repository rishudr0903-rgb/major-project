<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['username'])) {
    header('Location: index.php?error=' . urlencode('Please log in first.'));
    exit;
}

$role = strtolower(trim((string) $_SESSION['role']));
$username = (string) $_SESSION['username'];
$fullName = (string) ($_SESSION['full_name'] ?? $username);

if ($role !== 'admin') {
    http_response_code(403);
    die('Access denied: Only Admin can manage Principal accounts.');
}

$courseOptions = [
    'ALL DEPARTMENTS',
    'BCA',
    'BCOM',
    'BSE(COMPUTER)',
    'BSE(BIO)',
];

$ensureCols = [
    'course' => 'ALTER TABLE users ADD COLUMN course VARCHAR(50) NULL DEFAULT \'ALL DEPARTMENTS\'',
    'mobile_number' => 'ALTER TABLE users ADD COLUMN mobile_number VARCHAR(20) NULL',
    'email' => 'ALTER TABLE users ADD COLUMN email VARCHAR(150) NULL',
];
foreach ($ensureCols as $colName => $alterSql) {
    try {
        $chkCol = $pdo->query('SHOW COLUMNS FROM users LIKE \'' . $colName . '\'');
        if (!$chkCol->fetch()) {
            $pdo->exec($alterSql);
        }
    } catch (Throwable $e) {
        // Ignore if column already exists
    }
}

function normalizePrincipalScope(string $rawScope, array $allowed): string
{
    $clean = strtoupper(trim($rawScope));
    if ($clean === '' || $clean === 'ALL') {
        return 'ALL DEPARTMENTS';
    }
    return in_array($clean, $allowed, true) ? $clean : 'ALL DEPARTMENTS';
}

function normalizePrincipalPhone10(string $phone): string
{
    $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        return substr($digits, 2);
    }
    return $digits;
}

$message = '';
$error = '';
$principalSearch = trim((string) ($_GET['p_search'] ?? ''));
$editPrincipalId = (int) ($_GET['edit_principal_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add_principal';

    if ($action === 'add_principal') {
        $pUsername = trim($_POST['principal_username'] ?? '');
        $pName = trim($_POST['principal_full_name'] ?? '');
        $pScope = normalizePrincipalScope($_POST['principal_course'] ?? 'ALL DEPARTMENTS', $courseOptions);
        $pMobileRaw = trim($_POST['principal_mobile'] ?? '');
        $pMobile = ($pMobileRaw !== '') ? normalizePrincipalPhone10($pMobileRaw) : '';
        $pEmail = strtolower(trim($_POST['principal_email'] ?? ''));
        $pPassword = $_POST['principal_password'] ?? '';

        $invalidAdd = in_array(true, [
            $pUsername === '',
            $pName === '',
            ($pMobile !== '' && preg_match('/^[6-9][0-9]{9}$/', $pMobile) !== 1),
            ($pEmail !== '' && filter_var($pEmail, FILTER_VALIDATE_EMAIL) === false),
            strlen($pPassword) < 6,
        ], true);

        if ($invalidAdd) {
            $error = 'Enter Principal Username, Full Name, optional 10-digit Mobile, optional valid Email, and Password (min 6 chars).';
        } else {
            $dupStmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(username)) = LOWER(:username) LIMIT 1');
            $dupStmt->execute([':username' => $pUsername]);

            if ($dupStmt->fetch()) {
                $error = 'Username "' . htmlspecialchars($pUsername, ENT_QUOTES, 'UTF-8') . '" already exists.';
            } else {
                $insStmt = $pdo->prepare(
                    'INSERT INTO users (username, password_hash, role, course, full_name, mobile_number, email)
                     VALUES (:username, :password_hash, \'principal\', :course, :full_name, :mobile_number, :email)'
                );
                $insStmt->execute([
                    ':username' => $pUsername,
                    ':password_hash' => password_hash($pPassword, PASSWORD_DEFAULT),
                    ':course' => $pScope,
                    ':full_name' => $pName,
                    ':mobile_number' => ($pMobile !== '') ? $pMobile : null,
                    ':email' => ($pEmail !== '') ? $pEmail : null,
                ]);
                $message = 'Created Principal account "' . htmlspecialchars($pUsername, ENT_QUOTES, 'UTF-8') . '" (' . htmlspecialchars($pName, ENT_QUOTES, 'UTF-8') . ').';
            }
        }
    } elseif ($action === 'update_principal') {
        $pId = (int) ($_POST['principal_id'] ?? 0);
        $pUsername = trim($_POST['principal_username'] ?? '');
        $pName = trim($_POST['principal_full_name'] ?? '');
        $pScope = normalizePrincipalScope($_POST['principal_course'] ?? 'ALL DEPARTMENTS', $courseOptions);
        $pMobileRaw = trim($_POST['principal_mobile'] ?? '');
        $pMobile = ($pMobileRaw !== '') ? normalizePrincipalPhone10($pMobileRaw) : '';
        $pEmail = strtolower(trim($_POST['principal_email'] ?? ''));
        $newPPass = $_POST['new_principal_password'] ?? '';

        $invalidUpd = in_array(true, [
            $pId <= 0,
            $pUsername === '',
            $pName === '',
            ($pMobile !== '' && preg_match('/^[6-9][0-9]{9}$/', $pMobile) !== 1),
            ($pEmail !== '' && filter_var($pEmail, FILTER_VALIDATE_EMAIL) === false),
            ($newPPass !== '' && strlen($newPPass) < 6),
        ], true);

        if ($invalidUpd) {
            $error = 'Enter valid Principal Username, Full Name, optional 10-digit Mobile, optional valid Email, and Password (min 6 chars if changing).';
            $editPrincipalId = $pId;
        } else {
            $dupStmt = $pdo->prepare(
                'SELECT id FROM users WHERE LOWER(TRIM(username)) = LOWER(:username) AND id <> :id LIMIT 1'
            );
            $dupStmt->execute([
                ':username' => $pUsername,
                ':id' => $pId,
            ]);

            if ($dupStmt->fetch()) {
                $error = 'Another account already uses username "' . htmlspecialchars($pUsername, ENT_QUOTES, 'UTF-8') . '".';
                $editPrincipalId = $pId;
            } elseif ($newPPass !== '') {
                $updStmt = $pdo->prepare(
                    'UPDATE users
                     SET username = :username, full_name = :full_name, course = :course, mobile_number = :mobile_number, email = :email, role = \'principal\', password_hash = :password_hash
                     WHERE id = :id AND LOWER(TRIM(role)) IN (\'principal\', \'principals\')'
                );
                $updStmt->execute([
                    ':username' => $pUsername,
                    ':full_name' => $pName,
                    ':course' => $pScope,
                    ':mobile_number' => ($pMobile !== '') ? $pMobile : null,
                    ':email' => ($pEmail !== '') ? $pEmail : null,
                    ':password_hash' => password_hash($newPPass, PASSWORD_DEFAULT),
                    ':id' => $pId,
                ]);
                $editPrincipalId = 0;
                $message = 'Updated Principal account and password for ' . htmlspecialchars($pUsername, ENT_QUOTES, 'UTF-8') . '.';
            } else {
                $updStmt = $pdo->prepare(
                    'UPDATE users
                     SET username = :username, full_name = :full_name, course = :course, mobile_number = :mobile_number, email = :email, role = \'principal\'
                     WHERE id = :id AND LOWER(TRIM(role)) IN (\'principal\', \'principals\')'
                );
                $updStmt->execute([
                    ':username' => $pUsername,
                    ':full_name' => $pName,
                    ':course' => $pScope,
                    ':mobile_number' => ($pMobile !== '') ? $pMobile : null,
                    ':email' => ($pEmail !== '') ? $pEmail : null,
                    ':id' => $pId,
                ]);
                $editPrincipalId = 0;
                $message = 'Updated Principal account ' . htmlspecialchars($pUsername, ENT_QUOTES, 'UTF-8') . '.';
            }
        }
    } elseif ($action === 'delete_principal') {
        $pId = (int) ($_POST['principal_id'] ?? 0);
        if ($pId > 0) {
            $fetch = $pdo->prepare(
                'SELECT username, full_name FROM users WHERE id = :id AND LOWER(TRIM(role)) IN (\'principal\', \'principals\') LIMIT 1'
            );
            $fetch->execute([':id' => $pId]);
            $target = $fetch->fetch();

            if (!$target) {
                $error = 'Principal account not found.';
            } elseif (strtolower(trim((string) $target['username'])) === strtolower(trim($username))) {
                $error = 'You cannot delete your currently logged-in account.';
            } else {
                $del = $pdo->prepare(
                    'DELETE FROM users WHERE id = :id AND LOWER(TRIM(role)) IN (\'principal\', \'principals\')'
                );
                $del->execute([':id' => $pId]);
                if ($editPrincipalId === $pId) {
                    $editPrincipalId = 0;
                }
                $message = 'Deleted Principal "' . htmlspecialchars((string) ($target['full_name'] ?: $target['username']), ENT_QUOTES, 'UTF-8') . '".';
            }
        }
    }
}

$sqlPrincipals = 'SELECT id, username, full_name, course, mobile_number, email
                  FROM users
                  WHERE LOWER(TRIM(role)) IN (\'principal\', \'principals\')';
$paramsPrincipals = [];
if ($principalSearch !== '') {
    $sqlPrincipals .= ' AND (LOWER(TRIM(username)) LIKE :pq
                       OR LOWER(TRIM(COALESCE(full_name, \'\'))) LIKE :pq
                       OR LOWER(TRIM(COALESCE(course, \'\'))) LIKE :pq
                       OR LOWER(TRIM(COALESCE(mobile_number, \'\'))) LIKE :pq
                       OR LOWER(TRIM(COALESCE(email, \'\'))) LIKE :pq)';
    $paramsPrincipals[':pq'] = '%' . strtolower($principalSearch) . '%';
}
$sqlPrincipals .= ' ORDER BY username ASC';
$pStmt = $pdo->prepare($sqlPrincipals);
$pStmt->execute($paramsPrincipals);
$principals = $pStmt->fetchAll();

$editingPrincipal = null;
if ($editPrincipalId > 0) {
    $epStmt = $pdo->prepare(
        'SELECT id, username, full_name, course, mobile_number, email
         FROM users
         WHERE id = :id AND LOWER(TRIM(role)) IN (\'principal\', \'principals\')
         LIMIT 1'
    );
    $epStmt->execute([':id' => $editPrincipalId]);
    $editingPrincipal = $epStmt->fetch() ?: null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Principal Accounts — PES College Portal</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .nav-row { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:18px; }
        .nav-link-btn { display:inline-block; padding:9px 15px; border-radius:6px; background:#0b3c78; color:#fff; font-size:13px; font-weight:600; text-decoration:none; }
        .nav-link-btn.teal { background:#0f766e; }
        .nav-link-btn.amber { background:#b45309; }
        .nav-link-btn.indigo { background:#3730a3; }
        .dept-select { width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:14px; background:#fff; color:#1e293b; }
        .search-bar-row { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:10px 0 12px; }
        .search-input-box { flex:1 1 240px; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; background:#fff; color:#0f172a; }
        .search-submit-btn { background:#0b3c78; color:#fff; border:none; padding:8px 14px; border-radius:6px; font-size:12.5px; font-weight:600; cursor:pointer; }
        .clear-link-btn { display:inline-block; padding:8px 12px; border-radius:6px; background:#e2e8f0; color:#1e293b; text-decoration:none; font-size:12.5px; font-weight:600; }
        .edit-link-btn { display:inline-block; background:#0b3c78; color:#fff; padding:6px 10px; border-radius:4px; text-decoration:none; font-size:12px; }
        .del-btn { background:#c62828; color:#fff; border:none; padding:6px 10px; border-radius:4px; cursor:pointer; font-size:12px; }
        .edit-panel-box { background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:16px; margin-bottom:18px; }
    </style>
</head>
<body>
    <div class="portal-wrapper dashboard-wrapper">
        <div class="dashboard-topbar">
            <div>
                <h1>Principal Accounts (Admin)</h1>
                <p>Welcome, <?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars(strtoupper($role), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>)</p>
            </div>
            <div>
                <a href="dashboard.php" class="logout-btn" style="background-color:#0b3c78; margin-right:8px;">Dashboard</a>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>

        <div class="nav-row">
            <a href="principals.php" class="nav-link-btn indigo">Principal Accounts (<?php echo count($principals); ?>)</a>
            <a href="principal.php" class="nav-link-btn" style="background:#1e3a8a;">Open Principal Portal</a>
            <a href="teachers.php" class="nav-link-btn">Teacher Section</a>
            <a href="students.php" class="nav-link-btn teal">Student Section</a>
            <a href="fees_dashboard.php" class="nav-link-btn amber">Students & Fees Dashboard</a>
        </div>

        <?php if ($message !== ''): ?>
            <div class="error-msg" style="background-color:#e8f5e9; color:#1b5e20; border-color:#c8e6c9;"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="dash-card" style="margin-bottom:24px;">
            <?php if ($editingPrincipal): ?>
                <?php $edScope = normalizePrincipalScope((string) ($editingPrincipal['course'] ?? 'ALL DEPARTMENTS'), $courseOptions); ?>
                <div class="edit-panel-box">
                    <h2 style="margin-top:0;">Edit Principal: <?php echo htmlspecialchars((string) $editingPrincipal['username'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <form method="POST" action="principals.php">
                        <input type="hidden" name="action" value="update_principal">
                        <input type="hidden" name="principal_id" value="<?php echo (int) $editingPrincipal['id']; ?>">
                        <div class="input-group">
                            <label for="edit_principal_username">Principal Username / ID</label>
                            <input type="text" id="edit_principal_username" name="principal_username" value="<?php echo htmlspecialchars((string) $editingPrincipal['username'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="input-group">
                            <label for="edit_principal_full_name">Principal Full Name</label>
                            <input type="text" id="edit_principal_full_name" name="principal_full_name" value="<?php echo htmlspecialchars((string) ($editingPrincipal['full_name'] ?? $editingPrincipal['username']), ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="input-group">
                            <label for="edit_principal_course">Default Scope</label>
                            <select id="edit_principal_course" name="principal_course" class="dept-select" required>
                                <?php foreach ($courseOptions as $opt): ?>
                                    <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($edScope === $opt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label for="edit_principal_mobile">Mobile Number (10 digits, Optional)</label>
                            <input type="text" id="edit_principal_mobile" name="principal_mobile" value="<?php echo htmlspecialchars((string) ($editingPrincipal['mobile_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. 9876543210">
                        </div>
                        <div class="input-group">
                            <label for="edit_principal_email">Email Address (Optional)</label>
                            <input type="email" id="edit_principal_email" name="principal_email" value="<?php echo htmlspecialchars((string) ($editingPrincipal['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. principal@pes.edu">
                        </div>
                        <div class="input-group">
                            <label for="edit_principal_password">New Password (Optional, min 6 chars)</label>
                            <input type="password" id="edit_principal_password" name="new_principal_password" minlength="6" placeholder="Leave blank to keep current password">
                        </div>
                        <button type="submit" class="login-btn">Save Principal Changes</button>
                        <a href="principals.php" class="logout-btn" style="display:inline-block; margin-top:10px; background-color:#64748b;">Cancel Edit</a>
                    </form>
                </div>
            <?php else: ?>
                <h2>Create Principal Account</h2>
                <form method="POST" action="principals.php">
                    <input type="hidden" name="action" value="add_principal">
                    <div class="input-group">
                        <label for="principal_username">Principal Username / ID</label>
                        <input type="text" id="principal_username" name="principal_username" placeholder="e.g. principal_pes" required>
                    </div>
                    <div class="input-group">
                        <label for="principal_full_name">Principal Full Name</label>
                        <input type="text" id="principal_full_name" name="principal_full_name" placeholder="e.g. Dr. Suresh Rao" required>
                    </div>
                    <div class="input-group">
                        <label for="principal_course">Default Scope</label>
                        <select id="principal_course" name="principal_course" class="dept-select" required>
                            <?php foreach ($courseOptions as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group">
                        <label for="principal_mobile">Mobile Number (10 digits, Optional)</label>
                        <input type="text" id="principal_mobile" name="principal_mobile" placeholder="e.g. 9876543210">
                    </div>
                    <div class="input-group">
                        <label for="principal_email">Email Address (Optional)</label>
                        <input type="email" id="principal_email" name="principal_email" placeholder="e.g. principal@pes.edu">
                    </div>
                    <div class="input-group">
                        <label for="principal_password">Set Password (min 6 characters)</label>
                        <input type="password" id="principal_password" name="principal_password" minlength="6" required>
                    </div>
                    <button type="submit" class="login-btn">Create Principal Account</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="dash-card">
            <h2>Principals Directory (<?php echo count($principals); ?>)</h2>
            <form method="GET" action="principals.php" class="search-bar-row">
                <input type="text" name="p_search" class="search-input-box" value="<?php echo htmlspecialchars($principalSearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search principals by username, name, mobile, or email...">
                <button type="submit" class="search-submit-btn">Search</button>
                <?php if ($principalSearch !== ''): ?>
                    <a href="principals.php" class="clear-link-btn">Clear</a>
                <?php endif; ?>
            </form>
            <?php if (empty($principals)): ?>
                <p>No Principal accounts created yet.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; margin-top:12px; font-size:14px;">
                        <thead>
                            <tr style="background:#f8fafc; text-align:left; border-bottom:2px solid #dce3ec;">
                                <th style="padding:10px;">Username</th>
                                <th style="padding:10px;">Full Name</th>
                                <th style="padding:10px;">Scope</th>
                                <th style="padding:10px;">Mobile</th>
                                <th style="padding:10px;">Email</th>
                                <th style="padding:10px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($principals as $pr): ?>
                                <?php $prId = (int) $pr['id']; ?>
                                <tr style="border-bottom:1px solid #eef2f6;">
                                    <td style="padding:10px;"><strong><?php echo htmlspecialchars((string) $pr['username'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td style="padding:10px;"><?php echo htmlspecialchars((string) ($pr['full_name'] ?? $pr['username']), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px;"><strong><?php echo htmlspecialchars((string) ($pr['course'] ?: 'ALL DEPARTMENTS'), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td style="padding:10px;"><?php echo htmlspecialchars((string) ($pr['mobile_number'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px;"><?php echo htmlspecialchars((string) ($pr['email'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px; white-space:nowrap;">
                                        <a href="principals.php?edit_principal_id=<?php echo $prId; ?>" class="edit-link-btn" style="margin-right:6px;">Edit</a>
                                        <form method="POST" action="principals.php" style="display:inline;" onsubmit="return confirm('Delete this Principal account?');">
                                            <input type="hidden" name="action" value="delete_principal">
                                            <input type="hidden" name="principal_id" value="<?php echo $prId; ?>">
                                            <button type="submit" class="del-btn">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>