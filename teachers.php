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

try {
    $colCheckCourse = $pdo->query('SHOW COLUMNS FROM users LIKE \'course\'');
    if (!$colCheckCourse->fetch()) {
        $pdo->exec('ALTER TABLE users ADD COLUMN course VARCHAR(50) NULL DEFAULT \'BCA\'');
    }
} catch (Throwable $e) {
    // Ignore if column already exists
}

try {
    $colCheckSubjects = $pdo->query('SHOW COLUMNS FROM users LIKE \'subjects\'');
    if (!$colCheckSubjects->fetch()) {
        $pdo->exec('ALTER TABLE users ADD COLUMN subjects TEXT NULL');
    }
} catch (Throwable $e) {
    // Ignore if column already exists
}

function normalizeDeptValue(string $rawDept, array $allowed): string
{
    $clean = strtoupper(trim($rawDept));
    return in_array($clean, $allowed, true) ? $clean : 'BCA';
}

function normalizeSubjectsList(string $rawSubjects): string
{
    $parts = preg_split('/[,;\n]+/', $rawSubjects) ?: [];
    $cleanItems = [];
    foreach ($parts as $part) {
        $trimmed = trim($part);
        if ($trimmed !== '') {
            $cleanItems[] = $trimmed;
        }
    }
    return implode(', ', $cleanItems);
}

$message = '';
$error = '';
$selectedDept = strtoupper(trim((string) ($_GET['dept'] ?? $_POST['active_dept'] ?? 'ALL')));
if (!in_array($selectedDept, $departmentTabs, true)) {
    $selectedDept = 'ALL';
}

$teacherSearch = trim((string) ($_GET['t_search'] ?? ''));
$editTeacherId = (int) ($_GET['edit_teacher_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add_teacher';

    if ($action === 'add_teacher') {
        $teacherUsername = trim($_POST['teacher_username'] ?? '');
        $teacherName = trim($_POST['teacher_full_name'] ?? '');
        $teacherDept = normalizeDeptValue($_POST['teacher_course'] ?? 'BCA', $courseOptions);
        $teacherSubjects = normalizeSubjectsList((string) ($_POST['teacher_subjects'] ?? ''));
        $teacherPassword = $_POST['teacher_password'] ?? '';

        $invalidTeacher = in_array(true, [
            $teacherUsername === '',
            $teacherName === '',
            $teacherSubjects === '',
            strlen($teacherPassword) < 6,
        ], true);

        if ($invalidTeacher) {
            $error = 'Enter Teacher Username, Full Name, Department, at least one Subject, and a Password (min 6 chars).';
        } else {
            $tCheck = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(username)) = LOWER(:username) LIMIT 1');
            $tCheck->execute([':username' => $teacherUsername]);

            if ($tCheck->fetch()) {
                $error = 'Username "' . htmlspecialchars($teacherUsername, ENT_QUOTES, 'UTF-8') . '" already exists.';
            } else {
                $tInsert = $pdo->prepare(
                    'INSERT INTO users (username, password_hash, role, course, subjects, full_name)
                     VALUES (:username, :password_hash, \'teacher\', :course, :subjects, :full_name)'
                );
                $tInsert->execute([
                    ':username' => $teacherUsername,
                    ':password_hash' => password_hash($teacherPassword, PASSWORD_DEFAULT),
                    ':course' => $teacherDept,
                    ':subjects' => $teacherSubjects,
                    ':full_name' => $teacherName,
                ]);
                $message = 'Created teacher login "' . htmlspecialchars($teacherUsername, ENT_QUOTES, 'UTF-8') . '" for ' . htmlspecialchars($teacherDept, ENT_QUOTES, 'UTF-8') . '.';
            }
        }
    } elseif ($action === 'update_teacher') {
        $teacherId = (int) ($_POST['teacher_id'] ?? 0);
        $teacherUsername = trim($_POST['teacher_username'] ?? '');
        $teacherName = trim($_POST['teacher_full_name'] ?? '');
        $teacherDept = normalizeDeptValue($_POST['teacher_course'] ?? 'BCA', $courseOptions);
        $teacherSubjects = normalizeSubjectsList((string) ($_POST['teacher_subjects'] ?? ''));
        $newTeacherPass = $_POST['new_teacher_password'] ?? '';

        $invalidTeacherUpd = in_array(true, [
            $teacherId <= 0,
            $teacherUsername === '',
            $teacherName === '',
            $teacherSubjects === '',
            ($newTeacherPass !== '' && strlen($newTeacherPass) < 6),
        ], true);

        if ($invalidTeacherUpd) {
            $error = 'Enter Teacher Username, Full Name, Department, Subjects, and a Password of at least 6 characters (if changing).';
            $editTeacherId = $teacherId;
        } else {
            $tDup = $pdo->prepare(
                'SELECT id FROM users WHERE LOWER(TRIM(username)) = LOWER(:username) AND id <> :id LIMIT 1'
            );
            $tDup->execute([
                ':username' => $teacherUsername,
                ':id' => $teacherId,
            ]);

            if ($tDup->fetch()) {
                $error = 'Another account already uses username "' . htmlspecialchars($teacherUsername, ENT_QUOTES, 'UTF-8') . '".';
                $editTeacherId = $teacherId;
            } elseif ($newTeacherPass !== '') {
                $tUpd = $pdo->prepare(
                    'UPDATE users
                     SET username = :username, full_name = :full_name, course = :course, subjects = :subjects, password_hash = :password_hash
                     WHERE id = :id AND LOWER(TRIM(role)) IN (\'teacher\', \'teachers\')'
                );
                $tUpd->execute([
                    ':username' => $teacherUsername,
                    ':full_name' => $teacherName,
                    ':course' => $teacherDept,
                    ':subjects' => $teacherSubjects,
                    ':password_hash' => password_hash($newTeacherPass, PASSWORD_DEFAULT),
                    ':id' => $teacherId,
                ]);
                $editTeacherId = 0;
                $message = 'Updated teacher ' . htmlspecialchars($teacherName, ENT_QUOTES, 'UTF-8') . '.';
            } else {
                $tUpd = $pdo->prepare(
                    'UPDATE users
                     SET username = :username, full_name = :full_name, course = :course, subjects = :subjects
                     WHERE id = :id AND LOWER(TRIM(role)) IN (\'teacher\', \'teachers\')'
                );
                $tUpd->execute([
                    ':username' => $teacherUsername,
                    ':full_name' => $teacherName,
                    ':course' => $teacherDept,
                    ':subjects' => $teacherSubjects,
                    ':id' => $teacherId,
                ]);
                $editTeacherId = 0;
                $message = 'Updated teacher ' . htmlspecialchars($teacherName, ENT_QUOTES, 'UTF-8') . '.';
            }
        }
    } elseif ($action === 'delete_teacher') {
        $teacherId = (int) ($_POST['teacher_id'] ?? 0);
        if ($teacherId > 0) {
            $tFetch = $pdo->prepare(
                'SELECT username, full_name FROM users WHERE id = :id AND LOWER(TRIM(role)) IN (\'teacher\', \'teachers\') LIMIT 1'
            );
            $tFetch->execute([':id' => $teacherId]);
            $tTarget = $tFetch->fetch();

            if (!$tTarget) {
                $error = 'Teacher account not found.';
            } elseif (strtolower(trim((string) $tTarget['username'])) === strtolower(trim($username))) {
                $error = 'You cannot delete your currently logged-in account.';
            } else {
                $tDel = $pdo->prepare(
                    'DELETE FROM users WHERE id = :id AND LOWER(TRIM(role)) IN (\'teacher\', \'teachers\')'
                );
                $tDel->execute([':id' => $teacherId]);
                if ($editTeacherId === $teacherId) {
                    $editTeacherId = 0;
                }
                $message = 'Deleted teacher "' . htmlspecialchars((string) ($tTarget['full_name'] ?: $tTarget['username']), ENT_QUOTES, 'UTF-8') . '".';
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
     WHERE LOWER(TRIM(role)) IN (\'teacher\', \'teachers\')
     GROUP BY dept_name'
);
while ($cRow = $cntStmt->fetch()) {
    $dKey = normalizeDeptValue((string) ($cRow['dept_name'] ?? 'BCA'), $courseOptions);
    $dVal = (int) ($cRow['cnt'] ?? 0);
    $deptCounts['ALL'] += $dVal;
    $deptCounts[$dKey] += $dVal;
}

$sqlTeachers = 'SELECT id, username, full_name, course, subjects
                FROM users
                WHERE LOWER(TRIM(role)) IN (\'teacher\', \'teachers\')';
$paramsTeachers = [];
if ($selectedDept !== 'ALL') {
    $sqlTeachers .= ' AND UPPER(TRIM(COALESCE(course, \'BCA\'))) = :dept';
    $paramsTeachers[':dept'] = $selectedDept;
}
if ($teacherSearch !== '') {
    $sqlTeachers .= ' AND (LOWER(TRIM(username)) LIKE :tq
                     OR LOWER(TRIM(COALESCE(full_name, \'\'))) LIKE :tq
                     OR LOWER(TRIM(COALESCE(course, \'\'))) LIKE :tq
                     OR LOWER(TRIM(COALESCE(subjects, \'\'))) LIKE :tq)';
    $paramsTeachers[':tq'] = '%' . strtolower($teacherSearch) . '%';
}
$sqlTeachers .= ' ORDER BY username ASC';
$teachersStmt = $pdo->prepare($sqlTeachers);
$teachersStmt->execute($paramsTeachers);
$teachers = $teachersStmt->fetchAll();

$editingTeacher = null;
if ($editTeacherId > 0) {
    $etStmt = $pdo->prepare(
        'SELECT id, username, full_name, course, subjects
         FROM users
         WHERE id = :id AND LOWER(TRIM(role)) IN (\'teacher\', \'teachers\')
         LIMIT 1'
    );
    $etStmt->execute([':id' => $editTeacherId]);
    $editingTeacher = $etStmt->fetch() ?: null;
}

$deptScopeLabel = ($selectedDept === 'ALL') ? 'All Departments' : $selectedDept;
$defaultAddDept = ($selectedDept !== 'ALL') ? $selectedDept : 'BCA';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Section — PES College Portal</title>
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
        .dept-tab.active { background:#0b3c78; color:#ffffff; border-color:#0b3c78; }
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
                <h1>Teacher Section</h1>
                <p>Welcome, <?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars(strtoupper($role), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>)</p>
            </div>
            <div>
                <a href="dashboard.php" class="logout-btn" style="background-color:#0b3c78; margin-right:8px;">Dashboard</a>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>

        <div class="nav-row">
            <a href="teachers.php" class="nav-link-btn">Teacher Section (<?php echo (int) $deptCounts['ALL']; ?>)</a>
            <a href="students.php" class="nav-link-btn teal">Student Section</a>
            <a href="fees_dashboard.php" class="nav-link-btn amber">Students & Fees Dashboard</a>
        </div>

        <div class="dept-tabs-bar">
            <span class="dept-tabs-label">Department Section:</span>
            <?php foreach ($departmentTabs as $deptOpt): ?>
                <?php
                $tabLabel = ($deptOpt === 'ALL') ? 'All Departments' : $deptOpt;
                $tabCount = (int) ($deptCounts[$deptOpt] ?? 0);
                $tabUrl = 'teachers.php?dept=' . urlencode($deptOpt);
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
            <?php if ($editingTeacher): ?>
                <?php $edTcDept = normalizeDeptValue((string) ($editingTeacher['course'] ?? 'BCA'), $courseOptions); ?>
                <div class="edit-panel-box">
                    <h2 style="margin-top:0;">Edit Teacher: <?php echo htmlspecialchars((string) $editingTeacher['username'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <form method="POST" action="teachers.php?dept=<?php echo urlencode($selectedDept); ?>">
                        <input type="hidden" name="action" value="update_teacher">
                        <input type="hidden" name="active_dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="teacher_id" value="<?php echo (int) $editingTeacher['id']; ?>">
                        <div class="input-group">
                            <label for="edit_teacher_username">Teacher Username / ID</label>
                            <input type="text" id="edit_teacher_username" name="teacher_username" value="<?php echo htmlspecialchars((string) $editingTeacher['username'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="input-group">
                            <label for="edit_teacher_full_name">Teacher Full Name</label>
                            <input type="text" id="edit_teacher_full_name" name="teacher_full_name" value="<?php echo htmlspecialchars((string) ($editingTeacher['full_name'] ?? $editingTeacher['username']), ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="input-group">
                            <label for="edit_teacher_course">Department / Course</label>
                            <select id="edit_teacher_course" name="teacher_course" class="dept-select" required>
                                <?php foreach ($courseOptions as $deptOpt): ?>
                                    <option value="<?php echo htmlspecialchars($deptOpt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($edTcDept === $deptOpt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($deptOpt, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label for="edit_teacher_subjects">Assigned Subjects (comma-separated)</label>
                            <input type="text" id="edit_teacher_subjects" name="teacher_subjects" value="<?php echo htmlspecialchars((string) ($editingTeacher['subjects'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="input-group">
                            <label for="edit_teacher_password">New Password (Optional, min 6 chars)</label>
                            <input type="password" id="edit_teacher_password" name="new_teacher_password" minlength="6" placeholder="Leave blank to keep current password">
                        </div>
                        <button type="submit" class="login-btn">Save Teacher Edit</button>
                        <a href="teachers.php?dept=<?php echo urlencode($selectedDept); ?>" class="logout-btn" style="display:inline-block; margin-top:10px; background-color:#64748b;">Cancel Edit</a>
                    </form>
                </div>
            <?php else: ?>
                <h2>Add New Teacher Account</h2>
                <form method="POST" action="teachers.php?dept=<?php echo urlencode($selectedDept); ?>">
                    <input type="hidden" name="action" value="add_teacher">
                    <input type="hidden" name="active_dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="input-group">
                        <label for="teacher_username">Teacher Username / ID</label>
                        <input type="text" id="teacher_username" name="teacher_username" placeholder="e.g. teacher_bca01" required>
                    </div>
                    <div class="input-group">
                        <label for="teacher_full_name">Teacher Full Name</label>
                        <input type="text" id="teacher_full_name" name="teacher_full_name" placeholder="e.g. Prof. Ramesh Kumar" required>
                    </div>
                    <div class="input-group">
                        <label for="teacher_course">Department / Course</label>
                        <select id="teacher_course" name="teacher_course" class="dept-select" required>
                            <?php foreach ($courseOptions as $deptOpt): ?>
                                <option value="<?php echo htmlspecialchars($deptOpt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($defaultAddDept === $deptOpt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($deptOpt, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group">
                        <label for="teacher_subjects">Assigned Subjects (comma-separated)</label>
                        <input type="text" id="teacher_subjects" name="teacher_subjects" placeholder="e.g. DBMS, Operating Systems, Java" required>
                    </div>
                    <div class="input-group">
                        <label for="teacher_password">Set Password (min 6 characters)</label>
                        <input type="password" id="teacher_password" name="teacher_password" minlength="6" required>
                    </div>
                    <button type="submit" class="login-btn">Create Teacher Account</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="dash-card">
            <h2><?php echo htmlspecialchars($deptScopeLabel, ENT_QUOTES, 'UTF-8'); ?> — Teachers Directory (<?php echo count($teachers); ?>)</h2>
            <form method="GET" action="teachers.php" class="search-bar-row">
                <input type="hidden" name="dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="text" name="t_search" class="search-input-box" value="<?php echo htmlspecialchars($teacherSearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search teachers by username, name, dept, or subject...">
                <button type="submit" class="search-submit-btn">Search</button>
                <?php if ($teacherSearch !== ''): ?>
                    <a href="teachers.php?dept=<?php echo urlencode($selectedDept); ?>" class="clear-link-btn">Clear</a>
                <?php endif; ?>
            </form>
            <?php if (empty($teachers)): ?>
                <p>No teacher records found in <strong><?php echo htmlspecialchars($deptScopeLabel, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; margin-top:12px; font-size:14px;">
                        <thead>
                            <tr style="background:#f8fafc; text-align:left; border-bottom:2px solid #dce3ec;">
                                <th style="padding:10px;">Username</th>
                                <th style="padding:10px;">Full Name</th>
                                <th style="padding:10px;">Department</th>
                                <th style="padding:10px;">Subjects</th>
                                <th style="padding:10px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teachers as $tc): ?>
                                <?php
                                $tcId = (int) $tc['id'];
                                $tcDept = normalizeDeptValue((string) ($tc['course'] ?? 'BCA'), $courseOptions);
                                ?>
                                <tr style="border-bottom:1px solid #eef2f6;">
                                    <td style="padding:10px;"><strong><?php echo htmlspecialchars((string) $tc['username'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td style="padding:10px;"><?php echo htmlspecialchars((string) ($tc['full_name'] ?? $tc['username']), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px;"><strong><?php echo htmlspecialchars($tcDept, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td style="padding:10px;"><?php echo htmlspecialchars((string) ($tc['subjects'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px; white-space:nowrap;">
                                        <a href="teachers.php?dept=<?php echo urlencode($selectedDept); ?>&amp;edit_teacher_id=<?php echo $tcId; ?>" class="edit-link-btn" style="margin-right:6px;">Edit</a>
                                        <form method="POST" action="teachers.php?dept=<?php echo urlencode($selectedDept); ?>" style="display:inline;" onsubmit="return confirm('Delete this teacher account?');">
                                            <input type="hidden" name="action" value="delete_teacher">
                                            <input type="hidden" name="active_dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="teacher_id" value="<?php echo $tcId; ?>">
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