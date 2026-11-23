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
    die('Access denied: Only Admin can access this page.');
}

$courseOptions = [
    'BCA',
    'BCOM',
    'BSE(COMPUTER)',
    'BSE(BIO)',
];

$departmentTabs = [
    'ALL',
    'BCA',
    'BCOM',
    'BSE(COMPUTER)',
    'BSE(BIO)',
];

$ensureCols = [
    'course' => 'ALTER TABLE users ADD COLUMN course VARCHAR(50) NULL DEFAULT \'BCA\'',
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

function normalizeStudentDept(string $rawDept, array $allowed): string
{
    $clean = strtoupper(trim($rawDept));
    return in_array($clean, $allowed, true) ? $clean : 'BCA';
}

function normalizeStudentMobile10(string $phone): string
{
    $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        return substr($digits, 2);
    }
    return $digits;
}

$message = '';
$error = '';
$selectedDept = strtoupper(trim((string) ($_GET['dept'] ?? $_POST['active_dept'] ?? 'ALL')));
if (!in_array($selectedDept, $departmentTabs, true)) {
    $selectedDept = 'ALL';
}

$studentSearch = trim((string) ($_GET['s_search'] ?? ''));
$editStudentId = (int) ($_GET['edit_student_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add_student';

    if ($action === 'add_student') {
        $stuUsn = strtoupper(trim($_POST['student_username'] ?? ''));
        $stuName = trim($_POST['student_full_name'] ?? '');
        $stuDept = normalizeStudentDept($_POST['student_course'] ?? 'BCA', $courseOptions);
        $stuMobileRaw = trim($_POST['student_mobile'] ?? '');
        $stuMobile = ($stuMobileRaw !== '') ? normalizeStudentMobile10($stuMobileRaw) : '';
        $stuEmail = strtolower(trim($_POST['student_email'] ?? ''));
        $stuPass = $_POST['student_password'] ?? '';

        $invalidAdd = in_array(true, [
            $stuUsn === '',
            $stuName === '',
            ($stuMobile !== '' && preg_match('/^[6-9][0-9]{9}$/', $stuMobile) !== 1),
            ($stuEmail !== '' && filter_var($stuEmail, FILTER_VALIDATE_EMAIL) === false),
            strlen($stuPass) < 6,
        ], true);

        if ($invalidAdd) {
            $error = 'Enter Student USN/Username, Full Name, Department, optional 10-digit Mobile, optional valid Email, and Password (min 6 chars).';
        } else {
            $dupStmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(username)) = LOWER(:username) LIMIT 1');
            $dupStmt->execute([':username' => $stuUsn]);

            if ($dupStmt->fetch()) {
                $error = 'USN/Username "' . htmlspecialchars($stuUsn, ENT_QUOTES, 'UTF-8') . '" already exists.';
            } else {
                $insStmt = $pdo->prepare(
                    'INSERT INTO users (username, password_hash, role, course, full_name, mobile_number, email)
                     VALUES (:username, :password_hash, \'student\', :course, :full_name, :mobile_number, :email)'
                );
                $insStmt->execute([
                    ':username' => $stuUsn,
                    ':password_hash' => password_hash($stuPass, PASSWORD_DEFAULT),
                    ':course' => $stuDept,
                    ':full_name' => $stuName,
                    ':mobile_number' => ($stuMobile !== '') ? $stuMobile : null,
                    ':email' => ($stuEmail !== '') ? $stuEmail : null,
                ]);
                $message = 'Created student account "' . htmlspecialchars($stuUsn, ENT_QUOTES, 'UTF-8') . '" (' . htmlspecialchars($stuName, ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars($stuDept, ENT_QUOTES, 'UTF-8') . ').';
            }
        }
    } elseif ($action === 'update_student') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $stuUsn = strtoupper(trim($_POST['student_username'] ?? ''));
        $stuName = trim($_POST['student_full_name'] ?? '');
        $stuDept = normalizeStudentDept($_POST['student_course'] ?? 'BCA', $courseOptions);
        $stuMobileRaw = trim($_POST['student_mobile'] ?? '');
        $stuMobile = ($stuMobileRaw !== '') ? normalizeStudentMobile10($stuMobileRaw) : '';
        $stuEmail = strtolower(trim($_POST['student_email'] ?? ''));
        $newStuPass = $_POST['new_student_password'] ?? '';

        $invalidUpd = in_array(true, [
            $studentId <= 0,
            $stuUsn === '',
            $stuName === '',
            ($stuMobile !== '' && preg_match('/^[6-9][0-9]{9}$/', $stuMobile) !== 1),
            ($stuEmail !== '' && filter_var($stuEmail, FILTER_VALIDATE_EMAIL) === false),
            ($newStuPass !== '' && strlen($newStuPass) < 6),
        ], true);

        if ($invalidUpd) {
            $error = 'Enter a valid USN/Username, Full Name, Department, optional 10-digit Mobile, optional valid Email, and Password of at least 6 characters (if changing).';
            $editStudentId = $studentId;
        } else {
            $oldFetch = $pdo->prepare(
                'SELECT username FROM users WHERE id = :id AND LOWER(TRIM(role)) IN (\'student\', \'students\') LIMIT 1'
            );
            $oldFetch->execute([':id' => $studentId]);
            $oldRow = $oldFetch->fetch();

            $dupStmt = $pdo->prepare(
                'SELECT id FROM users WHERE LOWER(TRIM(username)) = LOWER(:username) AND id <> :id LIMIT 1'
            );
            $dupStmt->execute([
                ':username' => $stuUsn,
                ':id' => $studentId,
            ]);

            if (!$oldRow) {
                $error = 'Student account not found.';
            } elseif ($dupStmt->fetch()) {
                $error = 'Another account already uses USN/Username "' . htmlspecialchars($stuUsn, ENT_QUOTES, 'UTF-8') . '".';
                $editStudentId = $studentId;
            } else {
                $oldUsn = (string) $oldRow['username'];

                if ($newStuPass !== '') {
                    $updStmt = $pdo->prepare(
                        'UPDATE users
                         SET username = :username, full_name = :full_name, course = :course, mobile_number = :mobile_number, email = :email, password_hash = :password_hash
                         WHERE id = :id AND LOWER(TRIM(role)) IN (\'student\', \'students\')'
                    );
                    $updStmt->execute([
                        ':username' => $stuUsn,
                        ':full_name' => $stuName,
                        ':course' => $stuDept,
                        ':mobile_number' => ($stuMobile !== '') ? $stuMobile : null,
                        ':email' => ($stuEmail !== '') ? $stuEmail : null,
                        ':password_hash' => password_hash($newStuPass, PASSWORD_DEFAULT),
                        ':id' => $studentId,
                    ]);
                } else {
                    $updStmt = $pdo->prepare(
                        'UPDATE users
                         SET username = :username, full_name = :full_name, course = :course, mobile_number = :mobile_number, email = :email
                         WHERE id = :id AND LOWER(TRIM(role)) IN (\'student\', \'students\')'
                    );
                    $updStmt->execute([
                        ':username' => $stuUsn,
                        ':full_name' => $stuName,
                        ':course' => $stuDept,
                        ':mobile_number' => ($stuMobile !== '') ? $stuMobile : null,
                        ':email' => ($stuEmail !== '') ? $stuEmail : null,
                        ':id' => $studentId,
                    ]);
                }

                // Keep linked student_fees and student_academics records in sync
                try {
                    $syncFees = $pdo->prepare(
                        'UPDATE student_fees SET student_username = :new_usn, course = :course WHERE UPPER(TRIM(student_username)) = UPPER(TRIM(:old_usn))'
                    );
                    $syncFees->execute([
                        ':new_usn' => $stuUsn,
                        ':course' => $stuDept,
                        ':old_usn' => $oldUsn,
                    ]);
                } catch (Throwable $e) {
                    // Ignore if table not created yet
                }

                try {
                    $syncAcad = $pdo->prepare(
                        'UPDATE student_academics SET student_username = :new_usn, course = :course WHERE UPPER(TRIM(student_username)) = UPPER(TRIM(:old_usn))'
                    );
                    $syncAcad->execute([
                        ':new_usn' => $stuUsn,
                        ':course' => $stuDept,
                        ':old_usn' => $oldUsn,
                    ]);
                } catch (Throwable $e) {
                    // Ignore if table not created yet
                }

                $editStudentId = 0;
                $message = 'Updated student ' . htmlspecialchars($stuName, ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($stuUsn, ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars($stuDept, ENT_QUOTES, 'UTF-8') . ').';
            }
        }
    } elseif ($action === 'delete_student') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        if ($studentId > 0) {
            $sFetch = $pdo->prepare(
                'SELECT username, full_name FROM users WHERE id = :id AND LOWER(TRIM(role)) IN (\'student\', \'students\') LIMIT 1'
            );
            $sFetch->execute([':id' => $studentId]);
            $sTarget = $sFetch->fetch();

            if (!$sTarget) {
                $error = 'Student account not found.';
            } else {
                $sDel = $pdo->prepare(
                    'DELETE FROM users WHERE id = :id AND LOWER(TRIM(role)) IN (\'student\', \'students\')'
                );
                $sDel->execute([':id' => $studentId]);
                if ($editStudentId === $studentId) {
                    $editStudentId = 0;
                }
                $message = 'Deleted student "' . htmlspecialchars((string) ($sTarget['full_name'] ?: $sTarget['username']), ENT_QUOTES, 'UTF-8') . '" (' . htmlspecialchars((string) $sTarget['username'], ENT_QUOTES, 'UTF-8') . ').';
            }
        }
    }
}

$deptCounts = [
    'ALL' => 0,
    'BCA' => 0,
    'BCOM' => 0,
    'BSE(COMPUTER)' => 0,
    'BSE(BIO)' => 0,
];
$cntStmt = $pdo->query(
    'SELECT UPPER(TRIM(COALESCE(course, \'BCA\'))) AS dept_name, COUNT(*) AS cnt
     FROM users
     WHERE LOWER(TRIM(role)) IN (\'student\', \'students\')
     GROUP BY dept_name'
);
while ($cRow = $cntStmt->fetch()) {
    $dKey = normalizeStudentDept((string) ($cRow['dept_name'] ?? 'BCA'), $courseOptions);
    $dVal = (int) ($cRow['cnt'] ?? 0);
    $deptCounts['ALL'] += $dVal;
    $deptCounts[$dKey] += $dVal;
}

$sqlStudents = 'SELECT id, username, full_name, course, mobile_number, email
                FROM users
                WHERE LOWER(TRIM(role)) IN (\'student\', \'students\')';
$paramsStudents = [];
if ($selectedDept !== 'ALL') {
    $sqlStudents .= ' AND UPPER(TRIM(COALESCE(course, \'BCA\'))) = :dept';
    $paramsStudents[':dept'] = $selectedDept;
}
if ($studentSearch !== '') {
    $sqlStudents .= ' AND (LOWER(TRIM(username)) LIKE :sq
                     OR LOWER(TRIM(COALESCE(full_name, \'\'))) LIKE :sq
                     OR LOWER(TRIM(COALESCE(course, \'\'))) LIKE :sq
                     OR LOWER(TRIM(COALESCE(mobile_number, \'\'))) LIKE :sq
                     OR LOWER(TRIM(COALESCE(email, \'\'))) LIKE :sq)';
    $paramsStudents[':sq'] = '%' . strtolower($studentSearch) . '%';
}
$sqlStudents .= ' ORDER BY username ASC';
$studentsStmt = $pdo->prepare($sqlStudents);
$studentsStmt->execute($paramsStudents);
$students = $studentsStmt->fetchAll();

$editingStudent = null;
if ($editStudentId > 0) {
    $esStmt = $pdo->prepare(
        'SELECT id, username, full_name, course, mobile_number, email
         FROM users
         WHERE id = :id AND LOWER(TRIM(role)) IN (\'student\', \'students\')
         LIMIT 1'
    );
    $esStmt->execute([':id' => $editStudentId]);
    $editingStudent = $esStmt->fetch() ?: null;
}

$deptScopeLabel = ($selectedDept === 'ALL') ? 'All Departments' : $selectedDept;
$defaultAddDept = ($selectedDept !== 'ALL') ? $selectedDept : 'BCA';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Section — PES College Portal</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .nav-row { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:14px; }
        .nav-link-btn { display:inline-block; padding:9px 15px; border-radius:6px; background:#0b3c78; color:#fff; font-size:13px; font-weight:600; text-decoration:none; }
        .nav-link-btn.teal { background:#0f766e; }
        .nav-link-btn.amber { background:#b45309; }
        .dept-tabs-bar { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:18px; padding:10px 12px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; align-items:center; }
        .dept-tabs-label { font-size:12.5px; font-weight:700; color:#475569; margin-right:4px; }
        .dept-tab { display:inline-block; padding:7px 13px; border-radius:999px; border:1px solid #cbd5e1; background:#ffffff; color:#1e293b; font-size:12.5px; font-weight:600; text-decoration:none; }
        .dept-tab:hover { background:#f1f5f9; }
        .dept-tab.active { background:#0f766e; color:#ffffff; border-color:#0f766e; }
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
                <h1>Student Section</h1>
                <p>Welcome, <?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars(strtoupper($role), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>)</p>
            </div>
            <div>
                <a href="dashboard.php" class="logout-btn" style="background-color:#0b3c78; margin-right:8px;">Dashboard</a>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>

        <div class="nav-row">
            <a href="teachers.php" class="nav-link-btn">Teacher Section</a>
            <a href="students.php" class="nav-link-btn teal">Student Section (<?php echo (int) $deptCounts['ALL']; ?>)</a>
            <a href="fees_dashboard.php" class="nav-link-btn amber">Students & Fees Dashboard</a>
        </div>

        <div class="dept-tabs-bar">
            <span class="dept-tabs-label">Department Section:</span>
            <?php foreach ($departmentTabs as $deptOpt): ?>
                <?php
                $tabLabel = ($deptOpt === 'ALL') ? 'All Departments' : $deptOpt;
                $tabCount = (int) ($deptCounts[$deptOpt] ?? 0);
                $tabUrl = 'students.php?dept=' . urlencode($deptOpt);
                ?>
                <a href="<?php echo htmlspecialchars($tabUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dept-tab <?php echo ($selectedDept === $deptOpt) ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($tabLabel, ENT_QUOTES, 'UTF-8'); ?> (<?php echo $tabCount; ?>)
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($message !== ''): ?>
            <div class="error-msg" style="background-color:#e8f5e9; color:#1b5e20; border-color:#c8e6c9;"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="dash-card" style="margin-bottom:24px;">
            <?php if ($editingStudent): ?>
                <?php $edStuDept = normalizeStudentDept((string) ($editingStudent['course'] ?? 'BCA'), $courseOptions); ?>
                <div class="edit-panel-box">
                    <h2 style="margin-top:0;">Edit Student: <?php echo htmlspecialchars((string) $editingStudent['username'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <form method="POST" action="students.php?dept=<?php echo urlencode($selectedDept); ?>">
                        <input type="hidden" name="action" value="update_student">
                        <input type="hidden" name="active_dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="student_id" value="<?php echo (int) $editingStudent['id']; ?>">
                        <div class="input-group">
                            <label for="edit_student_username">Student USN / Username</label>
                            <input type="text" id="edit_student_username" name="student_username" value="<?php echo htmlspecialchars((string) $editingStudent['username'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="input-group">
                            <label for="edit_student_full_name">Student Full Name</label>
                            <input type="text" id="edit_student_full_name" name="student_full_name" value="<?php echo htmlspecialchars((string) ($editingStudent['full_name'] ?? $editingStudent['username']), ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="input-group">
                            <label for="edit_student_course">Department / Course</label>
                            <select id="edit_student_course" name="student_course" class="dept-select" required>
                                <?php foreach ($courseOptions as $deptOpt): ?>
                                    <option value="<?php echo htmlspecialchars($deptOpt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($edStuDept === $deptOpt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($deptOpt, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label for="edit_student_mobile">Registered Mobile Number (10 digits, Optional)</label>
                            <input type="text" id="edit_student_mobile" name="student_mobile" value="<?php echo htmlspecialchars((string) ($editingStudent['mobile_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. 9876543210">
                        </div>
                        <div class="input-group">
                            <label for="edit_student_email">Email Address (Optional)</label>
                            <input type="email" id="edit_student_email" name="student_email" value="<?php echo htmlspecialchars((string) ($editingStudent['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. student@example.com">
                        </div>
                        <div class="input-group">
                            <label for="edit_student_password">New Password (Optional, min 6 chars)</label>
                            <input type="password" id="edit_student_password" name="new_student_password" minlength="6" placeholder="Leave blank to keep current password">
                        </div>
                        <button type="submit" class="login-btn">Save Student Edit</button>
                        <a href="students.php?dept=<?php echo urlencode($selectedDept); ?>" class="logout-btn" style="display:inline-block; margin-top:10px; background-color:#64748b;">Cancel Edit</a>
                    </form>
                </div>
            <?php else: ?>
                <h2>Add New Student Account</h2>
                <form method="POST" action="students.php?dept=<?php echo urlencode($selectedDept); ?>">
                    <input type="hidden" name="action" value="add_student">
                    <input type="hidden" name="active_dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="input-group">
                        <label for="student_username">Student USN / Username</label>
                        <input type="text" id="student_username" name="student_username" placeholder="e.g. U21ME24S0018" required>
                    </div>
                    <div class="input-group">
                        <label for="student_full_name">Student Full Name</label>
                        <input type="text" id="student_full_name" name="student_full_name" placeholder="e.g. Priya Nair" required>
                    </div>
                    <div class="input-group">
                        <label for="student_course">Department / Course</label>
                        <select id="student_course" name="student_course" class="dept-select" required>
                            <?php foreach ($courseOptions as $deptOpt): ?>
                                <option value="<?php echo htmlspecialchars($deptOpt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($defaultAddDept === $deptOpt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($deptOpt, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group">
                        <label for="student_mobile">Registered Mobile Number (10 digits, Optional)</label>
                        <input type="text" id="student_mobile" name="student_mobile" placeholder="e.g. 9876543210">
                    </div>
                    <div class="input-group">
                        <label for="student_email">Email Address (Optional)</label>
                        <input type="email" id="student_email" name="student_email" placeholder="e.g. student@example.com">
                    </div>
                    <div class="input-group">
                        <label for="student_password">Set Password (min 6 characters)</label>
                        <input type="password" id="student_password" name="student_password" minlength="6" required>
                    </div>
                    <button type="submit" class="login-btn">Create Student Account</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="dash-card">
            <h2><?php echo htmlspecialchars($deptScopeLabel, ENT_QUOTES, 'UTF-8'); ?> — Students Directory (<?php echo count($students); ?>)</h2>
            <form method="GET" action="students.php" class="search-bar-row">
                <input type="hidden" name="dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="text" name="s_search" class="search-input-box" value="<?php echo htmlspecialchars($studentSearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search students by USN, name, dept, mobile, or email...">
                <button type="submit" class="search-submit-btn">Search</button>
                <?php if ($studentSearch !== ''): ?>
                    <a href="students.php?dept=<?php echo urlencode($selectedDept); ?>" class="clear-link-btn">Clear</a>
                <?php endif; ?>
            </form>
            <?php if (empty($students)): ?>
                <p>No student records found in <strong><?php echo htmlspecialchars($deptScopeLabel, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; margin-top:12px; font-size:14px;">
                        <thead>
                            <tr style="background:#f8fafc; text-align:left; border-bottom:2px solid #dce3ec;">
                                <th style="padding:10px;">USN / Username</th>
                                <th style="padding:10px;">Full Name</th>
                                <th style="padding:10px;">Department</th>
                                <th style="padding:10px;">Mobile</th>
                                <th style="padding:10px;">Email</th>
                                <th style="padding:10px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $st): ?>
                                <?php
                                $stId = (int) $st['id'];
                                $stDept = normalizeStudentDept((string) ($st['course'] ?? 'BCA'), $courseOptions);
                                ?>
                                <tr style="border-bottom:1px solid #eef2f6;">
                                    <td style="padding:10px;"><strong><?php echo htmlspecialchars((string) $st['username'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td style="padding:10px;"><?php echo htmlspecialchars((string) ($st['full_name'] ?? $st['username']), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px;"><strong><?php echo htmlspecialchars($stDept, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td style="padding:10px;"><?php echo htmlspecialchars((string) ($st['mobile_number'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px;"><?php echo htmlspecialchars((string) ($st['email'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px; white-space:nowrap;">
                                        <a href="students.php?dept=<?php echo urlencode($selectedDept); ?>&amp;edit_student_id=<?php echo $stId; ?>" class="edit-link-btn" style="margin-right:6px;">Edit</a>
                                        <form method="POST" action="students.php?dept=<?php echo urlencode($selectedDept); ?>" style="display:inline;" onsubmit="return confirm('Delete this student account?');">
                                            <input type="hidden" name="action" value="delete_student">
                                            <input type="hidden" name="active_dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="student_id" value="<?php echo $stId; ?>">
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