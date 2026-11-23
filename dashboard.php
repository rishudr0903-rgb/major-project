<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['username'])) {
    header('Location: index.php?error=' . urlencode('Please log in first.'));
    exit;
}

$role = strtolower(trim((string) $_SESSION['role']));
$userId = (int) $_SESSION['user_id'];
$username = trim((string) $_SESSION['username']);
$fullName = trim((string) ($_SESSION['full_name'] ?? $username));

if ($role === 'teachers') {
    $role = 'teacher';
} elseif ($role === 'students') {
    $role = 'student';
} elseif ($role === 'principals') {
    $role = 'principal';
}

$message = '';
$error = '';

$semesterOptions = ['I', 'II', 'III', 'IV', 'V', 'VI'];
$selectedSemester = strtoupper(trim((string) ($_GET['semester'] ?? 'ALL')));
if ($selectedSemester !== 'ALL' && !in_array($selectedSemester, $semesterOptions, true)) {
    $selectedSemester = 'ALL';
}

$studentCourse = 'BCA';
$studentMobile = '';
$studentEmail = '';
$feeSummary = null;
$academicRows = [];
$availableSemesters = [];
$currentPrincipal = null;

function computeGradeLetter(float $scored, float $maxMarks): string
{
    if ($maxMarks <= 0) {
        return 'N/A';
    }
    $pct = ($scored / $maxMarks) * 100.0;
    if ($pct >= 90.0) {
        return 'O';
    }
    if ($pct >= 80.0) {
        return 'A+';
    }
    if ($pct >= 70.0) {
        return 'A';
    }
    if ($pct >= 60.0) {
        return 'B+';
    }
    if ($pct >= 50.0) {
        return 'B';
    }
    if ($pct >= 40.0) {
        return 'C';
    }
    return 'F';
}

if ($role === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_principal_credentials') {
    $pUsername = trim((string) ($_POST['principal_username'] ?? ''));
    $pFullName = trim((string) ($_POST['principal_full_name'] ?? ''));
    $pPassword = (string) ($_POST['principal_password'] ?? '');

    if ($pUsername === '' || $pFullName === '' || strlen($pPassword) < 6) {
        $error = 'Enter Principal Username, Full Name, and a Password of at least 6 characters.';
    } else {
        $existingStmt = $pdo->query(
            'SELECT id, username FROM users WHERE LOWER(TRIM(role)) IN (\'principal\', \'principals\') ORDER BY id ASC LIMIT 1'
        );
        $existingPrincipal = $existingStmt->fetch();

        if ($existingPrincipal) {
            $targetId = (int) $existingPrincipal['id'];
            $dupStmt = $pdo->prepare(
                'SELECT id FROM users WHERE LOWER(TRIM(username)) = LOWER(:u) AND id <> :id LIMIT 1'
            );
            $dupStmt->execute([':u' => $pUsername, ':id' => $targetId]);

            if ($dupStmt->fetch()) {
                $error = 'Username "' . htmlspecialchars($pUsername, ENT_QUOTES, 'UTF-8') . '" is already used by another account.';
            } else {
                $updStmt = $pdo->prepare(
                    'UPDATE users
                     SET username = :username,
                         full_name = :full_name,
                         course = \'ALL DEPARTMENTS\',
                         role = \'principal\',
                         password_hash = :password_hash
                     WHERE id = :id'
                );
                $updStmt->execute([
                    ':username' => $pUsername,
                    ':full_name' => $pFullName,
                    ':password_hash' => password_hash($pPassword, PASSWORD_DEFAULT),
                    ':id' => $targetId,
                ]);
                $message = 'Updated Principal login credentials — Username: "' . htmlspecialchars($pUsername, ENT_QUOTES, 'UTF-8') . '" (' . htmlspecialchars($pFullName, ENT_QUOTES, 'UTF-8') . ').';
            }
        } else {
            $dupStmt = $pdo->prepare(
                'SELECT id FROM users WHERE LOWER(TRIM(username)) = LOWER(:u) LIMIT 1'
            );
            $dupStmt->execute([':u' => $pUsername]);

            if ($dupStmt->fetch()) {
                $error = 'Username "' . htmlspecialchars($pUsername, ENT_QUOTES, 'UTF-8') . '" is already used by another account.';
            } else {
                $insStmt = $pdo->prepare(
                    'INSERT INTO users (username, password_hash, role, course, full_name)
                     VALUES (:username, :password_hash, \'principal\', \'ALL DEPARTMENTS\', :full_name)'
                );
                $insStmt->execute([
                    ':username' => $pUsername,
                    ':password_hash' => password_hash($pPassword, PASSWORD_DEFAULT),
                    ':full_name' => $pFullName,
                ]);
                $message = 'Created Principal login credentials — Username: "' . htmlspecialchars($pUsername, ENT_QUOTES, 'UTF-8') . '" (' . htmlspecialchars($pFullName, ENT_QUOTES, 'UTF-8') . ').';
            }
        }
    }
}

if ($role === 'admin') {
    $pShowStmt = $pdo->query(
        'SELECT id, username, full_name FROM users WHERE LOWER(TRIM(role)) IN (\'principal\', \'principals\') ORDER BY id ASC LIMIT 1'
    );
    $currentPrincipal = $pShowStmt->fetch() ?: null;
} elseif ($role === 'student') {
    $uStmt = $pdo->prepare('SELECT username, full_name, course, mobile_number, email FROM users WHERE id = :id LIMIT 1');
    $uStmt->execute([':id' => $userId]);
    $uRow = $uStmt->fetch() ?: [];

    $studentUsn = trim((string) ($uRow['username'] ?? $username));
    $fullName = trim((string) ($uRow['full_name'] ?? $fullName));
    $studentCourse = strtoupper(trim((string) ($uRow['course'] ?? 'BCA')));
    if ($studentCourse === '') {
        $studentCourse = 'BCA';
    }
    $studentMobile = trim((string) ($uRow['mobile_number'] ?? ''));
    $studentEmail = trim((string) ($uRow['email'] ?? ''));

    try {
        $feeStmt = $pdo->prepare(
            'SELECT course, total_fee, paid_fee, updated_by, updated_at
             FROM student_fees
             WHERE UPPER(TRIM(student_username)) = UPPER(TRIM(:usn))
             LIMIT 1'
        );
        $feeStmt->execute([':usn' => $studentUsn]);
        $feeSummary = $feeStmt->fetch() ?: null;
    } catch (Throwable $e) {
        $feeSummary = null;
    }

    try {
        $semStmt = $pdo->prepare(
            'SELECT DISTINCT UPPER(TRIM(COALESCE(semester, \'I\'))) AS sem_code
             FROM student_academics
             WHERE UPPER(TRIM(student_username)) = UPPER(TRIM(:usn))
             ORDER BY sem_code ASC'
        );
        $semStmt->execute([':usn' => $studentUsn]);
        while ($sRow = $semStmt->fetch()) {
            $code = strtoupper(trim((string) ($sRow['sem_code'] ?? '')));
            if ($code !== '') {
                $availableSemesters[] = $code;
            }
        }
    } catch (Throwable $e) {
        $availableSemesters = [];
    }

    try {
        $acadSql = 'SELECT id, student_username, course, semester, subject_name,
                         se_marks, ia_marks, marks_obtained, max_marks, min_marks,
                         result_status, classes_attended, total_classes, attendance_pct,
                         updated_by, updated_at
                  FROM student_academics
                  WHERE UPPER(TRIM(student_username)) = UPPER(TRIM(:usn))';
        $acadParams = [':usn' => $studentUsn];
        if ($selectedSemester !== 'ALL') {
            $acadSql .= ' AND UPPER(TRIM(COALESCE(semester, \'I\'))) = :sem';
            $acadParams[':sem'] = $selectedSemester;
        }
        $acadSql .= ' ORDER BY semester ASC, id ASC';
        $acadStmt = $pdo->prepare($acadSql);
        $acadStmt->execute($acadParams);
        $academicRows = $acadStmt->fetchAll();
    } catch (Throwable $e) {
        $academicRows = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — PES College Portal</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .role-nav-bar { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:20px; }
        .role-nav-btn { display:inline-block; padding:9px 15px; border-radius:6px; background:#0b3c78; color:#fff; font-size:13px; font-weight:600; text-decoration:none; }
        .role-nav-btn.teal { background:#0f766e; }
        .role-nav-btn.amber { background:#b45309; }
        .role-nav-btn.indigo { background:#3730a3; }
        .sem-filter-row { display:flex; flex-wrap:wrap; gap:8px; margin:10px 0 14px; }
        .sem-pill { display:inline-block; padding:6px 12px; border-radius:999px; border:1px solid #cbd5e1; background:#f8fafc; color:#1e293b; font-size:12.5px; font-weight:600; text-decoration:none; }
        .sem-pill.active { background:#0b3c78; color:#fff; border-color:#0b3c78; }
        .pass-txt { color:#15803d; font-weight:700; }
        .fail-txt { color:#b91c1c; font-weight:700; }

        @media (max-width: 640px) {
            .mobile-stack-table,
            .mobile-stack-table tbody,
            .mobile-stack-table tr,
            .mobile-stack-table td {
                display: block;
                width: 100%;
            }

            .mobile-stack-table thead {
                display: none;
            }

            .mobile-stack-table tr {
                background: #f8fafc;
                border: 1px solid #cbd5e1;
                border-radius: 10px;
                padding: 10px 12px;
                margin-bottom: 12px;
            }

            .mobile-stack-table td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 12px;
                padding: 6px 0;
                border-bottom: 1px solid #e2e8f0;
                font-size: 13px;
                text-align: right;
            }

            .mobile-stack-table td:last-child {
                border-bottom: none;
            }

            .mobile-stack-table td::before {
                content: attr(data-label);
                font-weight: 700;
                color: #475569;
                text-align: left;
                flex: 0 0 auto;
            }
        }
    </style>
</head>
<body>
    <div class="portal-wrapper dashboard-wrapper">
        <div class="dashboard-topbar">
            <div>
                <h1>PES College Portal — <?php echo htmlspecialchars(strtoupper($role), ENT_QUOTES, 'UTF-8'); ?> Dashboard</h1>
                <p>Welcome, <?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?><?php echo ($role === 'student') ? (' · ' . htmlspecialchars($studentCourse, ENT_QUOTES, 'UTF-8')) : ''; ?>)</p>
            </div>
            <div>
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

        <?php if ($role === 'admin'): ?>
            <div class="role-nav-bar">
                <a href="principals.php" class="role-nav-btn indigo">Principal Accounts</a>
                <a href="principal.php" class="role-nav-btn" style="background:#1e3a8a;">Principal Portal</a>
                <a href="teachers.php" class="role-nav-btn">Teacher Section</a>
                <a href="students.php" class="role-nav-btn teal">Student Section</a>
                <a href="fees_dashboard.php" class="role-nav-btn amber">Students & Fees Dashboard</a>
            </div>

            <div class="dash-card" style="margin-bottom:20px;">
                <h2>Set / Change Principal Username & Password</h2>
                <?php if ($currentPrincipal): ?>
                    <p style="margin:6px 0 14px; font-size:13.5px; color:#475569;">
                        Current Principal Account: <strong><?php echo htmlspecialchars((string) $currentPrincipal['username'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        (<?php echo htmlspecialchars((string) ($currentPrincipal['full_name'] ?? $currentPrincipal['username']), ENT_QUOTES, 'UTF-8'); ?>)
                    </p>
                <?php else: ?>
                    <p style="margin:6px 0 14px; font-size:13.5px; color:#475569;">
                        No Principal account is set yet. Create one below.
                    </p>
                <?php endif; ?>
                <form method="POST" action="dashboard.php">
                    <input type="hidden" name="action" value="set_principal_credentials">
                    <div class="input-group">
                        <label for="principal_username">Principal Username</label>
                        <input type="text" id="principal_username" name="principal_username" value="<?php echo htmlspecialchars((string) ($currentPrincipal['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. principal_pes" required>
                    </div>
                    <div class="input-group">
                        <label for="principal_full_name">Principal Full Name</label>
                        <input type="text" id="principal_full_name" name="principal_full_name" value="<?php echo htmlspecialchars((string) ($currentPrincipal['full_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. Dr. Suresh Rao" required>
                    </div>
                    <div class="input-group">
                        <label for="principal_password">Set New Password (min 6 characters)</label>
                        <input type="password" id="principal_password" name="principal_password" minlength="6" placeholder="Enter new password for Principal" required>
                    </div>
                    <button type="submit" class="login-btn">Save Principal Username & Password</button>
                </form>
            </div>

            <div class="dash-card">
                <h2>Administrator Overview</h2>
                <p>Manage teachers, students, fee records, and Principal login credentials from here.</p>
            </div>
        <?php elseif ($role === 'principal'): ?>
            <div class="role-nav-bar">
                <a href="principal.php" class="role-nav-btn indigo">Open Principal Portal</a>
                <a href="principal.php?tab=teachers" class="role-nav-btn">Manage Teachers</a>
                <a href="principal.php?tab=students" class="role-nav-btn teal">Manage Students</a>
                <a href="principal.php?tab=records" class="role-nav-btn amber">All Marks & Attendance</a>
            </div>
            <div class="dash-card">
                <h2>Principal Workspace</h2>
                <p>Open <a href="principal.php">Principal Portal</a> to manage teachers, students, and marks & attendance across all departments.</p>
            </div>
        <?php elseif ($role === 'teacher'): ?>
            <div class="role-nav-bar">
                <a href="teacher.php?tab=students" class="role-nav-btn">Student Details</a>
                <a href="teacher.php?tab=add_academic" class="role-nav-btn teal">Add Marks & Attendance</a>
                <a href="teacher.php?tab=records" class="role-nav-btn amber">All Marks & Attendance</a>
            </div>
            <div class="dash-card">
                <h2>Faculty Workspace</h2>
                <p>Open <a href="teacher.php?tab=records">All Marks & Attendance</a> to review or edit student marks and attendance.</p>
            </div>
        <?php else: ?>
            <div class="dash-card" style="margin-bottom:20px;">
                <h2>Student Profile & Fee Summary</h2>
                <p><strong>USN:</strong> <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?> · <strong>Course:</strong> <?php echo htmlspecialchars($studentCourse, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php if ($studentMobile !== '' || $studentEmail !== ''): ?>
                    <p><strong>Mobile:</strong> <?php echo htmlspecialchars($studentMobile ?: 'Not set', ENT_QUOTES, 'UTF-8'); ?> · <strong>Email:</strong> <?php echo htmlspecialchars($studentEmail ?: 'Not set', ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <?php if ($feeSummary): ?>
                    <?php
                    $tFee = (float) ($feeSummary['total_fee'] ?? 0);
                    $pFee = (float) ($feeSummary['paid_fee'] ?? 0);
                    $bFee = max(0.0, $tFee - $pFee);
                    ?>
                    <p><strong>Total Fee:</strong> ₹<?php echo number_format($tFee, 2); ?> · <strong>Paid:</strong> ₹<?php echo number_format($pFee, 2); ?> · <strong>Balance Due:</strong> ₹<?php echo number_format($bFee, 2); ?></p>
                <?php endif; ?>
            </div>

            <div class="dash-card">
                <h2>Semester Exam Result & Attendance</h2>
                <div class="sem-filter-row">
                    <a href="dashboard.php?semester=ALL" class="sem-pill <?php echo ($selectedSemester === 'ALL') ? 'active' : ''; ?>">ALL</a>
                    <?php
                    $pillsToRender = !empty($availableSemesters) ? $availableSemesters : ['I'];
                    foreach ($pillsToRender as $semCode):
                    ?>
                        <a href="dashboard.php?semester=<?php echo urlencode($semCode); ?>" class="sem-pill <?php echo ($selectedSemester === $semCode) ? 'active' : ''; ?>">
                            <?php echo htmlspecialchars($semCode, ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($academicRows)): ?>
                    <p>No academic marks or attendance records have been posted yet.</p>
                <?php else: ?>
                    <table class="mobile-stack-table" style="width:100%; border-collapse:collapse; margin-top:10px; font-size:14px;">
                        <thead>
                            <tr style="background:#f8fafc; text-align:left; border-bottom:2px solid #dce3ec;">
                                <th style="padding:10px;">Sem</th>
                                <th style="padding:10px;">Subject</th>
                                <th style="padding:10px;">SE</th>
                                <th style="padding:10px;">IA</th>
                                <th style="padding:10px;">Total Marks</th>
                                <th style="padding:10px;">Grade</th>
                                <th style="padding:10px;">Result</th>
                                <th style="padding:10px;">Attendance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($academicRows as $row): ?>
                                <?php
                                $semVal = strtoupper(trim((string) ($row['semester'] ?? 'I')));
                                $scoredVal = (float) ($row['marks_obtained'] ?? 0);
                                $maxVal = (float) ($row['max_marks'] ?? 100);
                                $minVal = isset($row['min_marks']) ? (float) $row['min_marks'] : round($maxVal * 0.40, 2);
                                $seVal = isset($row['se_marks']) ? (float) $row['se_marks'] : 0;
                                $iaVal = isset($row['ia_marks']) ? (float) $row['ia_marks'] : 0;
                                $gradeVal = computeGradeLetter($scoredVal, $maxVal);
                                $statusVal = ($scoredVal >= $minVal) ? 'Pass' : 'Fail';
                                $attDone = (int) ($row['classes_attended'] ?? 0);
                                $attTotal = (int) ($row['total_classes'] ?? 0);
                                $attPct = (float) ($row['attendance_pct'] ?? 0);
                                ?>
                                <tr style="border-bottom:1px solid #eef2f6;">
                                    <td data-label="Sem" style="padding:10px;"><strong><?php echo htmlspecialchars($semVal, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td data-label="Subject" style="padding:10px;"><?php echo htmlspecialchars((string) $row['subject_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="SE" style="padding:10px;"><?php echo htmlspecialchars((string) $seVal, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="IA" style="padding:10px;"><?php echo htmlspecialchars((string) $iaVal, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="Total Marks" style="padding:10px;"><strong><?php echo htmlspecialchars((string) $scoredVal, ENT_QUOTES, 'UTF-8'); ?></strong> / <?php echo htmlspecialchars((string) $maxVal, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="Grade" style="padding:10px;"><strong><?php echo htmlspecialchars($gradeVal, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td data-label="Result" style="padding:10px;"><span class="<?php echo ($statusVal === 'Pass') ? 'pass-txt' : 'fail-txt'; ?>"><?php echo htmlspecialchars($statusVal, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td data-label="Attendance" style="padding:10px;"><?php echo $attDone; ?> / <?php echo $attTotal; ?> (<strong><?php echo number_format($attPct, 2); ?>%</strong>)</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>