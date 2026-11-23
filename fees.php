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
$message = '';
$error = '';
$editRecord = null;
$allowedStatuses = ['Paid', 'Partial', 'Pending'];
$filterUsn = ($role === 'admin') ? trim($_GET['filter_usn'] ?? '') : '';

$targetStudentParam = ($role === 'admin') ? trim($_GET['student_usn'] ?? '') : '';
$contactUsnInput = ($role === 'admin')
    ? trim($_POST['contact_student_usn'] ?? ($targetStudentParam !== '' ? $targetStudentParam : ($filterUsn !== '' ? $filterUsn : 'U21ME24S0014')))
    : '';
$contactNameInput = '';
$contactMobileInput = '';
$contactEmailInput = '';
$savedStudentContact = null;

$pwdUsnInput = ($role === 'admin')
    ? trim($_POST['pwd_student_usn'] ?? ($targetStudentParam !== '' ? $targetStudentParam : ($filterUsn !== '' ? $filterUsn : 'U21ME24S0014')))
    : '';

function normalizeAdminMobile10(string $phone): string
{
    $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        return substr($digits, 2);
    }
    return $digits;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($role !== 'admin') {
        http_response_code(403);
        die('Access denied: Only admins can manage student details or fee records.');
    }

    $action = $_POST['action'] ?? 'save';

    if ($action === 'set_password') {
        $targetStudent = trim($_POST['pwd_student_usn'] ?? '');
        $newPassword = $_POST['new_student_password'] ?? '';
        $confirmPassword = $_POST['confirm_student_password'] ?? '';

        $pwdUsnInput = $targetStudent;
        $contactUsnInput = $targetStudent;
        $shortPass = (strlen($newPassword) < 6);
        $mismatchPass = ($newPassword !== $confirmPassword);

        if ($targetStudent === '' || $shortPass || $mismatchPass) {
            $error = 'Enter a Student USN and a matching new password of at least 6 characters.';
        } else {
            $checkStmt = $pdo->prepare(
                'SELECT id, username, full_name, role, mobile_number, email
                 FROM users
                 WHERE LOWER(username) = LOWER(:username) AND role = :role
                 LIMIT 1'
            );
            $checkStmt->execute([
                ':username' => $targetStudent,
                ':role' => 'student',
            ]);
            $studentUser = $checkStmt->fetch();
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

            if (!$studentUser) {
                $insStmt = $pdo->prepare(
                    'INSERT INTO users (username, full_name, role, mobile_number, email, password_hash)
                     VALUES (:username, :full_name, :role, NULL, NULL, :password_hash)'
                );
                $insStmt->execute([
                    ':username' => $targetStudent,
                    ':full_name' => $targetStudent,
                    ':role' => 'student',
                    ':password_hash' => $newHash,
                ]);
                $message = 'Created student account for ' . htmlspecialchars($targetStudent, ENT_QUOTES, 'UTF-8') . ' and set password.';
            } else {
                $pwdStmt = $pdo->prepare(
                    'UPDATE users
                     SET password_hash = :password_hash
                     WHERE id = :id AND role = :role'
                );
                $pwdStmt->execute([
                    ':password_hash' => $newHash,
                    ':id' => $studentUser['id'],
                    ':role' => 'student',
                ]);
                $message = 'Password updated for student ' . htmlspecialchars((string) $studentUser['username'], ENT_QUOTES, 'UTF-8') . '.';
            }

            $reloadStmt = $pdo->prepare(
                'SELECT username, full_name, mobile_number, email
                 FROM users
                 WHERE LOWER(username) = LOWER(:username) AND role = :role
                 LIMIT 1'
            );
            $reloadStmt->execute([
                ':username' => $targetStudent,
                ':role' => 'student',
            ]);
            $savedStudentContact = $reloadStmt->fetch() ?: null;
            if ($savedStudentContact) {
                $contactUsnInput = (string) $savedStudentContact['username'];
                $pwdUsnInput = (string) $savedStudentContact['username'];
                $contactNameInput = (string) ($savedStudentContact['full_name'] ?? '');
                $contactMobileInput = (string) ($savedStudentContact['mobile_number'] ?? '');
                $contactEmailInput = (string) ($savedStudentContact['email'] ?? '');
            }
        }
    } elseif ($action === 'save_contact') {
        $targetStudent = trim($_POST['contact_student_usn'] ?? '');
        $studentFullName = trim($_POST['student_full_name'] ?? '');
        $mobile10 = normalizeAdminMobile10(trim($_POST['student_mobile'] ?? ''));
        $email = strtolower(trim($_POST['student_email'] ?? ''));

        $contactUsnInput = $targetStudent;
        $pwdUsnInput = $targetStudent;
        $contactNameInput = $studentFullName;
        $contactMobileInput = trim($_POST['student_mobile'] ?? '');
        $contactEmailInput = trim($_POST['student_email'] ?? '');

        $badMobile = ($mobile10 !== '' && preg_match('/^[6-9][0-9]{9}$/', $mobile10) !== 1);
        $badEmail = ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false);

        if ($targetStudent === '' || $badMobile || $badEmail) {
            $error = 'Enter a valid Student USN, a 10-digit mobile number (or leave blank), and a valid email address (or leave blank).';
        } else {
            $checkStmt = $pdo->prepare(
                'SELECT id, username, full_name, role, mobile_number, email
                 FROM users
                 WHERE LOWER(username) = LOWER(:username) AND role = :role
                 LIMIT 1'
            );
            $checkStmt->execute([
                ':username' => $targetStudent,
                ':role' => 'student',
            ]);
            $studentUser = $checkStmt->fetch();
            $resolvedName = ($studentFullName !== '') ? $studentFullName : $targetStudent;

            if (!$studentUser) {
                $defaultHash = password_hash($targetStudent, PASSWORD_DEFAULT);
                $insStmt = $pdo->prepare(
                    'INSERT INTO users (username, full_name, role, mobile_number, email, password_hash)
                     VALUES (:username, :full_name, :role, :mobile_number, :email, :password_hash)'
                );
                $insStmt->execute([
                    ':username' => $targetStudent,
                    ':full_name' => $resolvedName,
                    ':role' => 'student',
                    ':mobile_number' => ($mobile10 !== '') ? $mobile10 : null,
                    ':email' => ($email !== '') ? $email : null,
                    ':password_hash' => $defaultHash,
                ]);
                $message = 'Created new student account for ' . htmlspecialchars($targetStudent, ENT_QUOTES, 'UTF-8') . ' and saved mobile & email.';
            } else {
                $finalName = ($studentFullName !== '') ? $studentFullName : (string) ($studentUser['full_name'] ?? $studentUser['username']);
                $updStmt = $pdo->prepare(
                    'UPDATE users
                     SET full_name = :full_name, mobile_number = :mobile_number, email = :email
                     WHERE id = :id AND role = :role'
                );
                $updStmt->execute([
                    ':full_name' => $finalName,
                    ':mobile_number' => ($mobile10 !== '') ? $mobile10 : null,
                    ':email' => ($email !== '') ? $email : null,
                    ':id' => $studentUser['id'],
                    ':role' => 'student',
                ]);
                $message = 'Saved OTP mobile & email for student ' . htmlspecialchars((string) $studentUser['username'], ENT_QUOTES, 'UTF-8') . '.';
            }

            $reloadStmt = $pdo->prepare(
                'SELECT username, full_name, mobile_number, email
                 FROM users
                 WHERE LOWER(username) = LOWER(:username) AND role = :role
                 LIMIT 1'
            );
            $reloadStmt->execute([
                ':username' => $targetStudent,
                ':role' => 'student',
            ]);
            $savedStudentContact = $reloadStmt->fetch() ?: null;
            if ($savedStudentContact) {
                $contactUsnInput = (string) $savedStudentContact['username'];
                $pwdUsnInput = (string) $savedStudentContact['username'];
                $contactNameInput = (string) ($savedStudentContact['full_name'] ?? '');
                $contactMobileInput = (string) ($savedStudentContact['mobile_number'] ?? '');
                $contactEmailInput = (string) ($savedStudentContact['email'] ?? '');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM fee_records WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $message = 'Fee record deleted.';
        } else {
            $error = 'Invalid fee record ID.';
        }
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $targetStudent = trim($_POST['student_username'] ?? '');
        $academicTerm = trim($_POST['academic_term'] ?? '');
        $feeType = trim($_POST['fee_type'] ?? '');
        $totalAmount = (float) ($_POST['total_amount'] ?? -1);
        $paidAmount = (float) ($_POST['paid_amount'] ?? -1);
        $status = trim($_POST['status'] ?? 'Pending');

        if (
            $targetStudent === '' ||
            $academicTerm === '' ||
            $feeType === '' ||
            $totalAmount < 0 ||
            $paidAmount < 0 ||
            $paidAmount > $totalAmount ||
            !in_array($status, $allowedStatuses, true)
        ) {
            $error = 'Enter valid student USN, academic term, fee type, amounts (paid cannot exceed total), and status.';
        } elseif ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE fee_records
                 SET student_username = :student_username, academic_term = :academic_term, fee_type = :fee_type,
                     total_amount = :total_amount, paid_amount = :paid_amount, status = :status
                 WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $id,
                ':student_username' => $targetStudent,
                ':academic_term' => $academicTerm,
                ':fee_type' => $feeType,
                ':total_amount' => $totalAmount,
                ':paid_amount' => $paidAmount,
                ':status' => $status,
            ]);
            $message = 'Fee record updated for ' . htmlspecialchars($targetStudent, ENT_QUOTES, 'UTF-8') . '.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO fee_records (student_username, academic_term, fee_type, total_amount, paid_amount, status)
                 VALUES (:student_username, :academic_term, :fee_type, :total_amount, :paid_amount, :status)'
            );
            $stmt->execute([
                ':student_username' => $targetStudent,
                ':academic_term' => $academicTerm,
                ':fee_type' => $feeType,
                ':total_amount' => $totalAmount,
                ':paid_amount' => $paidAmount,
                ':status' => $status,
            ]);
            $message = 'Fee record saved for ' . htmlspecialchars($targetStudent, ENT_QUOTES, 'UTF-8') . '.';
        }
    }
}

$studentDirectory = [];
if ($role === 'admin') {
    $dirStmt = $pdo->query(
        "SELECT username, full_name, mobile_number, email
         FROM users
         WHERE role = 'student'
         ORDER BY username ASC"
    );
    $studentDirectory = $dirStmt->fetchAll();

    if (!$savedStudentContact && $contactUsnInput !== '') {
        $lookupStmt = $pdo->prepare(
            'SELECT username, full_name, mobile_number, email
             FROM users
             WHERE LOWER(username) = LOWER(:username) AND role = :role
             LIMIT 1'
        );
        $lookupStmt->execute([
            ':username' => $contactUsnInput,
            ':role' => 'student',
        ]);
        $foundStudent = $lookupStmt->fetch();
        if ($foundStudent) {
            $savedStudentContact = $foundStudent;
            if ($contactNameInput === '') {
                $contactNameInput = (string) ($foundStudent['full_name'] ?? '');
            }
            if ($contactMobileInput === '') {
                $contactMobileInput = (string) ($foundStudent['mobile_number'] ?? '');
            }
            if ($contactEmailInput === '') {
                $contactEmailInput = (string) ($foundStudent['email'] ?? '');
            }
        }
    }
}

if ($role === 'admin' && isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    if ($editId > 0) {
        $stmt = $pdo->prepare(
            'SELECT id, student_username, academic_term, fee_type, total_amount, paid_amount, status
             FROM fee_records WHERE id = :id'
        );
        $stmt->execute([':id' => $editId]);
        $editRecord = $stmt->fetch() ?: null;
    }
}

if ($role === 'admin') {
    if ($filterUsn !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, student_username, academic_term, fee_type, total_amount, paid_amount, status
             FROM fee_records
             WHERE student_username LIKE :usn
             ORDER BY id DESC'
        );
        $stmt->execute([':usn' => '%' . $filterUsn . '%']);
        $records = $stmt->fetchAll();
    } else {
        $stmt = $pdo->query(
            'SELECT id, student_username, academic_term, fee_type, total_amount, paid_amount, status
             FROM fee_records ORDER BY id DESC'
        );
        $records = $stmt->fetchAll();
    }
} else {
    $stmt = $pdo->prepare(
        'SELECT id, student_username, academic_term, fee_type, total_amount, paid_amount, status
         FROM fee_records WHERE student_username = :username ORDER BY id DESC'
    );
    $stmt->execute([':username' => $username]);
    $records = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee & Profile Details — PES College Portal</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="portal-wrapper dashboard-wrapper">
        <div class="dashboard-topbar">
            <div>
                <h1>Fee & Profile Details</h1>
                <p><?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars(strtoupper($role), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>)</p>
            </div>
            <div>
                <a href="http://localhost/pes-portal/dashboard.php" class="logout-btn" style="background-color:#0b3c78; margin-right:8px;">Dashboard</a>
                <a href="http://localhost/pes-portal/logout.php" class="logout-btn">Logout</a>
            </div>
        </div>

        <div class="dash-card" style="margin-bottom:20px;">
            <h2>Profile Summary</h2>
            <p><strong>Name:</strong> <?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?></p>
            <p><strong>USN / Username:</strong> <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></p>
            <p><strong>Role:</strong> <?php echo htmlspecialchars(strtoupper($role), ENT_QUOTES, 'UTF-8'); ?></p>
        </div>

        <?php if ($message !== ''): ?>
            <div class="error-msg" style="background-color:#e8f5e9; color:#1b5e20; border-color:#c8e6c9;">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($role === 'admin'): ?>
            <div class="dash-card" style="margin-bottom:20px;">
                <h2>Student Mobile & Email for OTP (Admin Only)</h2>
                <form method="POST" action="fees.php<?php echo $filterUsn !== '' ? '?filter_usn=' . urlencode($filterUsn) : ''; ?>">
                    <input type="hidden" name="action" value="save_contact">
                    <div class="input-group">
                        <label for="contact_student_usn">Student USN (Any Existing or New Student)</label>
                        <input type="text" id="contact_student_usn" name="contact_student_usn" placeholder="e.g. U21ME24S0015" value="<?php echo htmlspecialchars($contactUsnInput, ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="input-group">
                        <label for="student_full_name">Student Full Name (Optional)</label>
                        <input type="text" id="student_full_name" name="student_full_name" placeholder="e.g. Student Name" value="<?php echo htmlspecialchars($contactNameInput, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="input-group">
                        <label for="student_mobile">Student Mobile Number (10 digits)</label>
                        <input type="text" id="student_mobile" name="student_mobile" placeholder="e.g. 9739397776" value="<?php echo htmlspecialchars($contactMobileInput, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="input-group">
                        <label for="student_email">Student Email Address</label>
                        <input type="email" id="student_email" name="student_email" placeholder="e.g. rishudr0903@gmail.com" value="<?php echo htmlspecialchars($contactEmailInput, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <button type="submit" class="login-btn">Save Student Contact</button>
                </form>
                <?php if ($savedStudentContact): ?>
                    <p style="margin-top:12px; font-size:14px;">
                        Saved for <strong><?php echo htmlspecialchars((string) $savedStudentContact['username'], ENT_QUOTES, 'UTF-8'); ?></strong>:
                        Mobile: <strong><?php echo htmlspecialchars((string) ($savedStudentContact['mobile_number'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></strong> ·
                        Email: <strong><?php echo htmlspecialchars((string) ($savedStudentContact['email'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </p>
                <?php endif; ?>
            </div>

            <div class="dash-card" style="margin-bottom:20px;">
                <h2>Set / Reset Student Password (Admin Only)</h2>
                <form method="POST" action="fees.php<?php echo $filterUsn !== '' ? '?filter_usn=' . urlencode($filterUsn) : ''; ?>">
                    <input type="hidden" name="action" value="set_password">
                    <div class="input-group">
                        <label for="pwd_student_usn">Student USN (Any Existing or New Student)</label>
                        <input type="text" id="pwd_student_usn" name="pwd_student_usn" placeholder="e.g. U21ME24S0015" value="<?php echo htmlspecialchars($pwdUsnInput, ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="input-group">
                        <label for="new_student_password">New Student Password (min 6 characters)</label>
                        <input type="password" id="new_student_password" name="new_student_password" minlength="6" required>
                    </div>
                    <div class="input-group">
                        <label for="confirm_student_password">Confirm New Student Password</label>
                        <input type="password" id="confirm_student_password" name="confirm_student_password" minlength="6" required>
                    </div>
                    <button type="submit" class="login-btn">Set Student Password</button>
                </form>
            </div>

            <div class="dash-card" style="margin-bottom:20px;">
                <h2>All Students — Edit OTP Contact or Password (Admin Only)</h2>
                <?php if (empty($studentDirectory)): ?>
                    <p>No student accounts found yet.</p>
                <?php else: ?>
                    <table style="width:100%; border-collapse:collapse; margin-top:10px; font-size:14px;">
                        <thead>
                            <tr style="background:#f8fafc; text-align:left; border-bottom:2px solid #dce3ec;">
                                <th style="padding:10px;">Student USN</th>
                                <th style="padding:10px;">Mobile</th>
                                <th style="padding:10px;">Email</th>
                                <th style="padding:10px;">Manage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($studentDirectory as $stu): ?>
                                <tr style="border-bottom:1px solid #eef2f6;">
                                    <td style="padding:10px;"><strong><?php echo htmlspecialchars((string) $stu['username'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td style="padding:10px;"><?php echo htmlspecialchars((string) ($stu['mobile_number'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px;"><?php echo htmlspecialchars((string) ($stu['email'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px;">
                                        <a href="fees.php?student_usn=<?php echo urlencode((string) $stu['username']); ?><?php echo $filterUsn !== '' ? '&filter_usn=' . urlencode($filterUsn) : ''; ?>" style="color:#0b3c78; font-weight:600; text-decoration:none;">Edit Contact / Password</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="dash-card" style="margin-bottom:20px;">
                <h2><?php echo $editRecord ? 'Edit Fee Payment Record (Admin Only)' : 'Add Fee Payment Record (Admin Only)'; ?></h2>
                <form method="POST" action="fees.php<?php echo $filterUsn !== '' ? '?filter_usn=' . urlencode($filterUsn) : ''; ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo (int) ($editRecord['id'] ?? 0); ?>">
                    <div class="input-group">
                        <label for="student_username">Student USN</label>
                        <input type="text" id="student_username" name="student_username" placeholder="e.g. U21ME24S0014" value="<?php echo htmlspecialchars($editRecord['student_username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="input-group">
                        <label for="academic_term">Academic Term</label>
                        <input type="text" id="academic_term" name="academic_term" placeholder="e.g. 2026-27 Odd Sem" value="<?php echo htmlspecialchars($editRecord['academic_term'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="input-group">
                        <label for="fee_type">Fee Type</label>
                        <input type="text" id="fee_type" name="fee_type" placeholder="e.g. Tuition Fee" value="<?php echo htmlspecialchars($editRecord['fee_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="input-group">
                        <label for="total_amount">Total Amount (₹)</label>
                        <input type="number" step="0.01" id="total_amount" name="total_amount" min="0" value="<?php echo htmlspecialchars((string) ($editRecord['total_amount'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="input-group">
                        <label for="paid_amount">Paid Amount (₹)</label>
                        <input type="number" step="0.01" id="paid_amount" name="paid_amount" min="0" value="<?php echo htmlspecialchars((string) ($editRecord['paid_amount'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="input-group">
                        <label for="status">Payment Status</label>
                        <?php $currentStatus = $editRecord['status'] ?? 'Pending'; ?>
                        <select id="status" name="status" required style="width:100%; padding:10px; border:1px solid #dce3ec; border-radius:6px;">
                            <option value="Paid" <?php echo $currentStatus === 'Paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="Partial" <?php echo $currentStatus === 'Partial' ? 'selected' : ''; ?>>Partial</option>
                            <option value="Pending" <?php echo $currentStatus === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        </select>
                    </div>
                    <button type="submit" class="login-btn"><?php echo $editRecord ? 'Update Fee Record' : 'Save Fee Record'; ?></button>
                    <?php if ($editRecord): ?>
                        <a href="http://localhost/pes-portal/fees.php" class="logout-btn" style="display:inline-block; margin-top:10px; background-color:#64748b;">Cancel Edit</a>
                    <?php endif; ?>
                </form>
            </div>
        <?php endif; ?>

        <div class="dash-card">
            <h2><?php echo $role === 'admin' ? 'All Fee Records' : 'My Fee Payment Status (View Only)'; ?></h2>

            <?php if ($role === 'admin'): ?>
                <form method="GET" action="fees.php" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:10px; margin-bottom:14px;">
                    <input type="text" name="filter_usn" placeholder="Filter by Student USN (e.g. U21ME24S0014)" value="<?php echo htmlspecialchars($filterUsn, ENT_QUOTES, 'UTF-8'); ?>" style="padding:8px 12px; border:1px solid #dce3ec; border-radius:6px; min-width:220px;">
                    <button type="submit" class="login-btn" style="width:auto; padding:8px 16px;">Filter</button>
                    <?php if ($filterUsn !== ''): ?>
                        <a href="fees.php" class="logout-btn" style="background-color:#64748b; padding:8px 14px;">Clear</a>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

            <?php if (empty($records)): ?>
                <p><?php echo $filterUsn !== '' ? 'No fee records found matching "' . htmlspecialchars($filterUsn, ENT_QUOTES, 'UTF-8') . '".' : 'No fee records found yet.'; ?></p>
            <?php else: ?>
                <table style="width:100%; border-collapse:collapse; margin-top:12px; font-size:14px;">
                    <thead>
                        <tr style="background:#f8fafc; text-align:left; border-bottom:2px solid #dce3ec;">
                            <?php if ($role === 'admin'): ?><th style="padding:10px;">Student USN</th><?php endif; ?>
                            <th style="padding:10px;">Term</th>
                            <th style="padding:10px;">Fee Type</th>
                            <th style="padding:10px;">Paid / Total (₹)</th>
                            <th style="padding:10px;">Status</th>
                            <?php if ($role === 'admin'): ?><th style="padding:10px;">Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $row): ?>
                            <tr style="border-bottom:1px solid #eef2f6;">
                                <?php if ($role === 'admin'): ?><td style="padding:10px;"><?php echo htmlspecialchars($row['student_username'], ENT_QUOTES, 'UTF-8'); ?></td><?php endif; ?>
                                <td style="padding:10px;"><?php echo htmlspecialchars($row['academic_term'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="padding:10px;"><?php echo htmlspecialchars($row['fee_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="padding:10px;">₹<?php echo htmlspecialchars(number_format((float) $row['paid_amount'], 2), ENT_QUOTES, 'UTF-8'); ?> / ₹<?php echo htmlspecialchars(number_format((float) $row['total_amount'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="padding:10px;"><strong><?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <?php if ($role === 'admin'): ?>
                                    <td style="padding:10px; white-space:nowrap;">
                                        <a href="fees.php?edit=<?php echo (int) $row['id']; ?><?php echo $filterUsn !== '' ? '&filter_usn=' . urlencode($filterUsn) : ''; ?>" style="margin-right:8px; color:#0b3c78; font-weight:600; text-decoration:none;">Edit</a>
                                        <form method="POST" action="fees.php<?php echo $filterUsn !== '' ? '?filter_usn=' . urlencode($filterUsn) : ''; ?>" style="display:inline;" onsubmit="return confirm('Delete this fee record?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                            <button type="submit" style="background:#c62828; color:#fff; border:none; padding:5px 10px; border-radius:4px; cursor:pointer; font-size:12px;">Delete</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>