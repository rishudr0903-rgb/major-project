<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['username'])) {
    header('Location: index.php?error=' . urlencode('Please log in first.'));
    exit;
}

$role = strtolower(trim((string) $_SESSION['role']));
if ($role === 'principals') {
    $role = 'principal';
}

$userId = (int) $_SESSION['user_id'];
$username = (string) $_SESSION['username'];
$fullName = (string) ($_SESSION['full_name'] ?? $username);

if ($role !== 'principal') {
    http_response_code(403);
    die('Access denied: Only Principal can access this page.');
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

$semesterOptions = [
    'I',
    'II',
    'III',
    'IV',
    'V',
    'VI',
];

function normalizePrincipalDept(string $rawDept, array $allowed): string
{
    $clean = strtoupper(trim($rawDept));
    return in_array($clean, $allowed, true) ? $clean : 'BCA';
}

function normalizePrincipalSemester(string $rawSem, array $allowed): string
{
    $clean = strtoupper(trim($rawSem));
    return in_array($clean, $allowed, true) ? $clean : 'I';
}

function normalizePrincipalMobile10(string $phone): string
{
    $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        return substr($digits, 2);
    }
    return $digits;
}

function normalizePrincipalSubjectsList(string $rawSubjects): string
{
    $parts = preg_split('/[,;\n]+/', $rawSubjects) ?: [];
    $cleanItems = [];
    $seen = [];
    foreach ($parts as $part) {
        $trimmed = trim($part);
        if ($trimmed === '') {
            continue;
        }
        $key = strtolower($trimmed);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $cleanItems[] = $trimmed;
        }
    }
    return implode(', ', $cleanItems);
}

$ensureUserCols = [
    'course' => 'ALTER TABLE users ADD COLUMN course VARCHAR(50) NULL DEFAULT \'BCA\'',
    'subjects' => 'ALTER TABLE users ADD COLUMN subjects TEXT NULL',
    'mobile_number' => 'ALTER TABLE users ADD COLUMN mobile_number VARCHAR(20) NULL',
    'email' => 'ALTER TABLE users ADD COLUMN email VARCHAR(150) NULL',
];
foreach ($ensureUserCols as $colName => $alterSql) {
    try {
        $chkCol = $pdo->query('SHOW COLUMNS FROM users LIKE \'' . $colName . '\'');
        if (!$chkCol->fetch()) {
            $pdo->exec($alterSql);
        }
    } catch (Throwable $e) {
        // Ignore if column already exists
    }
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS student_academics (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_username VARCHAR(100) NOT NULL,
        course VARCHAR(50) NOT NULL DEFAULT \'BCA\',
        semester VARCHAR(16) NOT NULL DEFAULT \'I\',
        subject_name VARCHAR(120) NOT NULL,
        se_marks DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        ia_marks DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        marks_obtained DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        max_marks DECIMAL(6,2) NOT NULL DEFAULT 100.00,
        min_marks DECIMAL(6,2) NOT NULL DEFAULT 40.00,
        result_status VARCHAR(16) NOT NULL DEFAULT \'Pass\',
        classes_attended INT NOT NULL DEFAULT 0,
        total_classes INT NOT NULL DEFAULT 0,
        attendance_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        updated_by VARCHAR(100) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_student_username (student_username),
        INDEX idx_course (course),
        INDEX idx_semester (semester)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$acadExtraCols = [
    'course' => 'ALTER TABLE student_academics ADD COLUMN course VARCHAR(50) NOT NULL DEFAULT \'BCA\'',
    'semester' => 'ALTER TABLE student_academics ADD COLUMN semester VARCHAR(16) NOT NULL DEFAULT \'I\'',
    'se_marks' => 'ALTER TABLE student_academics ADD COLUMN se_marks DECIMAL(6,2) NOT NULL DEFAULT 0.00',
    'ia_marks' => 'ALTER TABLE student_academics ADD COLUMN ia_marks DECIMAL(6,2) NOT NULL DEFAULT 0.00',
    'min_marks' => 'ALTER TABLE student_academics ADD COLUMN min_marks DECIMAL(6,2) NOT NULL DEFAULT 40.00',
    'result_status' => 'ALTER TABLE student_academics ADD COLUMN result_status VARCHAR(16) NOT NULL DEFAULT \'Pass\'',
];
foreach ($acadExtraCols as $colName => $alterSql) {
    try {
        $chkCol = $pdo->query('SHOW COLUMNS FROM student_academics LIKE \'' . $colName . '\'');
        if (!$chkCol->fetch()) {
            $pdo->exec($alterSql);
        }
    } catch (Throwable $e) {
        // Ignore if column already exists
    }
}

$message = '';
$error = '';

$allowedTabs = ['teachers', 'students', 'add_academic', 'records', 'edit_academic'];
$activeTab = strtolower(trim($_GET['tab'] ?? 'teachers'));
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'teachers';
}

$selectedDept = strtoupper(trim((string) ($_GET['dept'] ?? $_POST['active_dept'] ?? 'ALL')));
if (!in_array($selectedDept, $departmentTabs, true)) {
    $selectedDept = 'ALL';
}

$teacherSearch = trim((string) ($_GET['t_search'] ?? ''));
$studentSearch = trim((string) ($_GET['s_q'] ?? ''));
$recordSearch = trim((string) ($_GET['r_q'] ?? ''));
$recordSemFilter = strtoupper(trim((string) ($_GET['r_sem'] ?? 'ALL')));
if ($recordSemFilter !== 'ALL' && !in_array($recordSemFilter, $semesterOptions, true)) {
    $recordSemFilter = 'ALL';
}

$editTeacherId = (int) ($_GET['edit_teacher_id'] ?? 0);
$editStudentId = (int) ($_GET['edit_student_id'] ?? 0);
$editId = (int) ($_GET['edit_id'] ?? 0);
$prefillUsn = strtoupper(trim($_GET['usn'] ?? ''));
$prefillCourse = normalizePrincipalDept((string) ($_GET['course'] ?? ($selectedDept !== 'ALL' ? $selectedDept : 'BCA')), $courseOptions);

if ($editId > 0) {
    $activeTab = 'edit_academic';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_teacher') {
        $activeTab = 'teachers';
        $tUsername = trim($_POST['teacher_username'] ?? '');
        $tName = trim($_POST['teacher_full_name'] ?? '');
        $tDept = normalizePrincipalDept($_POST['teacher_course'] ?? 'BCA', $courseOptions);
        $tSubjects = normalizePrincipalSubjectsList((string) ($_POST['teacher_subjects'] ?? ''));
        $tPassword = $_POST['teacher_password'] ?? '';

        if ($tUsername === '' || $tName === '' || $tSubjects === '' || strlen($tPassword) < 6) {
            $error = 'Enter Teacher Username, Full Name, Department, at least one Subject, and Password (min 6 chars).';
        } else {
            $chk = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(username)) = LOWER(:username) LIMIT 1');
            $chk->execute([':username' => $tUsername]);
            if ($chk->fetch()) {
                $error = 'Username "' . htmlspecialchars($tUsername, ENT_QUOTES, 'UTF-8') . '" already exists.';
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO users (username, password_hash, role, course, subjects, full_name)
                     VALUES (:username, :password_hash, \'teacher\', :course, :subjects, :full_name)'
                );
                $ins->execute([
                    ':username' => $tUsername,
                    ':password_hash' => password_hash($tPassword, PASSWORD_DEFAULT),
                    ':course' => $tDept,
                    ':subjects' => $tSubjects,
                    ':full_name' => $tName,
                ]);
                $message = 'Created teacher "' . htmlspecialchars($tUsername, ENT_QUOTES, 'UTF-8') . '" (' . htmlspecialchars($tDept, ENT_QUOTES, 'UTF-8') . ').';
            }
        }
    } elseif ($action === 'update_teacher') {
        $activeTab = 'teachers';
        $tId = (int) ($_POST['teacher_id'] ?? 0);
        $tUsername = trim($_POST['teacher_username'] ?? '');
        $tName = trim($_POST['teacher_full_name'] ?? '');
        $tDept = normalizePrincipalDept($_POST['teacher_course'] ?? 'BCA', $courseOptions);
        $tSubjects = normalizePrincipalSubjectsList((string) ($_POST['teacher_subjects'] ?? ''));
        $newPass = $_POST['new_teacher_password'] ?? '';

        if ($tId <= 0 || $tUsername === '' || $tName === '' || $tSubjects === '' || ($newPass !== '' && strlen($newPass) < 6)) {
            $error = 'Enter valid Teacher Username, Full Name, Department, Subjects, and Password (min 6 chars if changing).';
            $editTeacherId = $tId;
        } else {
            $dup = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(username)) = LOWER(:username) AND id <> :id LIMIT 1');
            $dup->execute([':username' => $tUsername, ':id' => $tId]);
            if ($dup->fetch()) {
                $error = 'Username "' . htmlspecialchars($tUsername, ENT_QUOTES, 'UTF-8') . '" is already used by another account.';
                $editTeacherId = $tId;
            } else {
                if ($newPass !== '') {
                    $upd = $pdo->prepare(
                        'UPDATE users SET username = :username, full_name = :full_name, course = :course, subjects = :subjects, password_hash = :password_hash
                         WHERE id = :id AND LOWER(TRIM(role)) IN (\'teacher\', \'teachers\')'
                    );
                    $upd->execute([
                        ':username' => $tUsername,
                        ':full_name' => $tName,
                        ':course' => $tDept,
                        ':subjects' => $tSubjects,
                        ':password_hash' => password_hash($newPass, PASSWORD_DEFAULT),
                        ':id' => $tId,
                    ]);
                } else {
                    $upd = $pdo->prepare(
                        'UPDATE users SET username = :username, full_name = :full_name, course = :course, subjects = :subjects
                         WHERE id = :id AND LOWER(TRIM(role)) IN (\'teacher\', \'teachers\')'
                    );
                    $upd->execute([
                        ':username' => $tUsername,
                        ':full_name' => $tName,
                        ':course' => $tDept,
                        ':subjects' => $tSubjects,
                        ':id' => $tId,
                    ]);
                }
                $editTeacherId = 0;
                $message = 'Updated teacher ' . htmlspecialchars($tName, ENT_QUOTES, 'UTF-8') . '.';
            }
        }
    } elseif ($action === 'delete_teacher') {
        $activeTab = 'teachers';
        $tId = (int) ($_POST['teacher_id'] ?? 0);
        if ($tId > 0) {
            $fetch = $pdo->prepare('SELECT username, full_name FROM users WHERE id = :id AND LOWER(TRIM(role)) IN (\'teacher\', \'teachers\') LIMIT 1');
            $fetch->execute([':id' => $tId]);
            $target = $fetch->fetch();
            if (!$target) {
                $error = 'Teacher account not found.';
            } elseif (strtolower(trim((string) $target['username'])) === strtolower(trim($username))) {
                $error = 'You cannot delete your currently logged-in account.';
            } else {
                $del = $pdo->prepare('DELETE FROM users WHERE id = :id AND LOWER(TRIM(role)) IN (\'teacher\', \'teachers\')');
                $del->execute([':id' => $tId]);
                if ($editTeacherId === $tId) {
                    $editTeacherId = 0;
                }
                $message = 'Deleted teacher "' . htmlspecialchars((string) ($target['full_name'] ?: $target['username']), ENT_QUOTES, 'UTF-8') . '".';
            }
        }
    } elseif ($action === 'add_student') {
        $activeTab = 'students';
        $sUsn = strtoupper(trim($_POST['username'] ?? ''));
        $sName = trim($_POST['full_name'] ?? '');
        $sDept = normalizePrincipalDept($_POST['course'] ?? 'BCA', $courseOptions);
        $sMobileRaw = trim($_POST['mobile_number'] ?? '');
        $sMobile = ($sMobileRaw !== '') ? normalizePrincipalMobile10($sMobileRaw) : '';
        $sEmail = strtolower(trim($_POST['email'] ?? ''));
        $sPass = $_POST['password'] ?? '';

        $invalidAdd = in_array(true, [
            $sUsn === '',
            $sName === '',
            ($sMobile !== '' && preg_match('/^[6-9][0-9]{9}$/', $sMobile) !== 1),
            ($sEmail !== '' && filter_var($sEmail, FILTER_VALIDATE_EMAIL) === false),
            strlen($sPass) < 6,
        ], true);

        if ($invalidAdd) {
            $error = 'Enter USN/Username, Full Name, Department, optional 10-digit Mobile, optional valid Email, and Password (min 6 chars).';
        } else {
            $chk = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(username)) = LOWER(:username) LIMIT 1');
            $chk->execute([':username' => $sUsn]);
            if ($chk->fetch()) {
                $error = 'USN/Username "' . htmlspecialchars($sUsn, ENT_QUOTES, 'UTF-8') . '" already exists.';
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO users (username, password_hash, role, course, full_name, mobile_number, email)
                     VALUES (:username, :password_hash, \'student\', :course, :full_name, :mobile_number, :email)'
                );
                $ins->execute([
                    ':username' => $sUsn,
                    ':password_hash' => password_hash($sPass, PASSWORD_DEFAULT),
                    ':course' => $sDept,
                    ':full_name' => $sName,
                    ':mobile_number' => ($sMobile !== '') ? $sMobile : null,
                    ':email' => ($sEmail !== '') ? $sEmail : null,
                ]);
                $message = 'Added student ' . htmlspecialchars($sName, ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($sUsn, ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars($sDept, ENT_QUOTES, 'UTF-8') . ').';
            }
        }
    } elseif ($action === 'update_student') {
        $activeTab = 'students';
        $sId = (int) ($_POST['student_id'] ?? 0);
        $sUsn = strtoupper(trim($_POST['username'] ?? ''));
        $sName = trim($_POST['full_name'] ?? '');
        $sDept = normalizePrincipalDept($_POST['course'] ?? 'BCA', $courseOptions);
        $sMobileRaw = trim($_POST['mobile_number'] ?? '');
        $sMobile = ($sMobileRaw !== '') ? normalizePrincipalMobile10($sMobileRaw) : '';
        $sEmail = strtolower(trim($_POST['email'] ?? ''));
        $newPass = $_POST['new_password'] ?? '';

        $invalidUpd = in_array(true, [
            $sId <= 0,
            $sUsn === '',
            $sName === '',
            ($sMobile !== '' && preg_match('/^[6-9][0-9]{9}$/', $sMobile) !== 1),
            ($sEmail !== '' && filter_var($sEmail, FILTER_VALIDATE_EMAIL) === false),
            ($newPass !== '' && strlen($newPass) < 6),
        ], true);

        if ($invalidUpd) {
            $error = 'Enter valid USN, Full Name, Department, optional 10-digit Mobile, optional valid Email, and Password (min 6 chars if changing).';
            $editStudentId = $sId;
        } else {
            $oldFetch = $pdo->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
            $oldFetch->execute([':id' => $sId]);
            $oldRow = $oldFetch->fetch();

            $dup = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(username)) = LOWER(:username) AND id <> :id LIMIT 1');
            $dup->execute([':username' => $sUsn, ':id' => $sId]);

            if (!$oldRow) {
                $error = 'Student account not found.';
            } elseif ($dup->fetch()) {
                $error = 'Another account already uses USN/Username "' . htmlspecialchars($sUsn, ENT_QUOTES, 'UTF-8') . '".';
                $editStudentId = $sId;
            } else {
                $oldUsn = (string) $oldRow['username'];
                if ($newPass !== '') {
                    $upd = $pdo->prepare(
                        'UPDATE users SET username = :username, course = :course, full_name = :full_name, mobile_number = :mobile_number, email = :email, role = \'student\', password_hash = :password_hash
                         WHERE id = :id'
                    );
                    $upd->execute([
                        ':username' => $sUsn,
                        ':course' => $sDept,
                        ':full_name' => $sName,
                        ':mobile_number' => ($sMobile !== '') ? $sMobile : null,
                        ':email' => ($sEmail !== '') ? $sEmail : null,
                        ':password_hash' => password_hash($newPass, PASSWORD_DEFAULT),
                        ':id' => $sId,
                    ]);
                } else {
                    $upd = $pdo->prepare(
                        'UPDATE users SET username = :username, course = :course, full_name = :full_name, mobile_number = :mobile_number, email = :email, role = \'student\'
                         WHERE id = :id'
                    );
                    $upd->execute([
                        ':username' => $sUsn,
                        ':course' => $sDept,
                        ':full_name' => $sName,
                        ':mobile_number' => ($sMobile !== '') ? $sMobile : null,
                        ':email' => ($sEmail !== '') ? $sEmail : null,
                        ':id' => $sId,
                    ]);
                }

                try {
                    $syncAcad = $pdo->prepare('UPDATE student_academics SET student_username = :new_usn, course = :course WHERE UPPER(TRIM(student_username)) = UPPER(TRIM(:old_usn))');
                    $syncAcad->execute([':new_usn' => $sUsn, ':course' => $sDept, ':old_usn' => $oldUsn]);
                } catch (Throwable $e) {
                    // Ignore
                }

                $editStudentId = 0;
                $message = 'Updated student ' . htmlspecialchars($sUsn, ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($sDept, ENT_QUOTES, 'UTF-8') . ').';
            }
        }
    } elseif ($action === 'add_academic') {
        $activeTab = 'add_academic';
        $targetUsn = strtoupper(trim($_POST['student_username'] ?? ''));
        $chosenDept = normalizePrincipalDept($_POST['course'] ?? 'BCA', $courseOptions);
        $chosenSemester = normalizePrincipalSemester((string) ($_POST['semester'] ?? 'I'), $semesterOptions);
        $subjectName = trim($_POST['subject_name'] ?? '');
        $seMarks = (float) ($_POST['se_marks'] ?? -1);
        $iaMarks = (float) ($_POST['ia_marks'] ?? -1);
        $maxMarks = (float) ($_POST['max_marks'] ?? 100);
        $classesAttended = (int) ($_POST['classes_attended'] ?? -1);
        $totalClasses = (int) ($_POST['total_classes'] ?? -1);

        $marksScored = round($seMarks + $iaMarks, 2);
        $minMarks = round($maxMarks * 0.40, 2);

        $invalidAddAcad = in_array(true, [
            $targetUsn === '',
            $subjectName === '',
            $seMarks < 0,
            $iaMarks < 0,
            $maxMarks <= 0,
            $marksScored > $maxMarks,
            $classesAttended < 0,
            $totalClasses <= 0,
            $classesAttended > $totalClasses,
        ], true);

        if ($invalidAddAcad) {
            $error = 'Enter valid Student USN, Department, Semester, Subject, SE Marks, IA Marks (SE + IA cannot exceed Max Marks), and Attendance.';
        } else {
            $resultStatus = ($marksScored >= $minMarks) ? 'Pass' : 'Fail';
            $attendancePct = round(($classesAttended / $totalClasses) * 100, 2);
            $insAcad = $pdo->prepare(
                'INSERT INTO student_academics
                    (student_username, course, semester, subject_name, se_marks, ia_marks, marks_obtained, max_marks, min_marks, result_status, classes_attended, total_classes, attendance_pct, updated_by)
                 VALUES
                    (:student_username, :course, :semester, :subject_name, :se_marks, :ia_marks, :marks_obtained, :max_marks, :min_marks, :result_status, :classes_attended, :total_classes, :attendance_pct, :updated_by)'
            );
            $insAcad->execute([
                ':student_username' => $targetUsn,
                ':course' => $chosenDept,
                ':semester' => $chosenSemester,
                ':subject_name' => $subjectName,
                ':se_marks' => $seMarks,
                ':ia_marks' => $iaMarks,
                ':marks_obtained' => $marksScored,
                ':max_marks' => $maxMarks,
                ':min_marks' => $minMarks,
                ':result_status' => $resultStatus,
                ':classes_attended' => $classesAttended,
                ':total_classes' => $totalClasses,
                ':attendance_pct' => $attendancePct,
                ':updated_by' => $username,
            ]);
            $message = 'Saved ' . htmlspecialchars($targetUsn, ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($chosenDept, ENT_QUOTES, 'UTF-8') . ' · Sem ' . htmlspecialchars($chosenSemester, ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars($subjectName, ENT_QUOTES, 'UTF-8') . ') — ' . $marksScored . '/' . $maxMarks . ' (' . $resultStatus . ').';
        }
    } elseif ($action === 'update_academic') {
        $recordId = (int) ($_POST['record_id'] ?? 0);
        $chosenDept = normalizePrincipalDept($_POST['course'] ?? 'BCA', $courseOptions);
        $chosenSemester = normalizePrincipalSemester((string) ($_POST['semester'] ?? 'I'), $semesterOptions);
        $subjectName = trim($_POST['subject_name'] ?? '');
        $seMarks = (float) ($_POST['se_marks'] ?? -1);
        $iaMarks = (float) ($_POST['ia_marks'] ?? -1);
        $maxMarks = (float) ($_POST['max_marks'] ?? 100);
        $classesAttended = (int) ($_POST['classes_attended'] ?? -1);
        $totalClasses = (int) ($_POST['total_classes'] ?? -1);

        $marksScored = round($seMarks + $iaMarks, 2);
        $minMarks = round($maxMarks * 0.40, 2);

        $invalidUpdAcad = in_array(true, [
            $recordId <= 0,
            $subjectName === '',
            $seMarks < 0,
            $iaMarks < 0,
            $maxMarks <= 0,
            $marksScored > $maxMarks,
            $classesAttended < 0,
            $totalClasses <= 0,
            $classesAttended > $totalClasses,
        ], true);

        if ($invalidUpdAcad) {
            $error = 'Enter valid Department, Semester, Subject, SE Marks, IA Marks (SE + IA cannot exceed Max Marks), and Attendance.';
            $editId = $recordId;
            $activeTab = 'edit_academic';
        } else {
            $resultStatus = ($marksScored >= $minMarks) ? 'Pass' : 'Fail';
            $attendancePct = round(($classesAttended / $totalClasses) * 100, 2);
            $updAcad = $pdo->prepare(
                'UPDATE student_academics
                 SET course = :course,
                     semester = :semester,
                     subject_name = :subject_name,
                     se_marks = :se_marks,
                     ia_marks = :ia_marks,
                     marks_obtained = :marks_obtained,
                     max_marks = :max_marks,
                     min_marks = :min_marks,
                     result_status = :result_status,
                     classes_attended = :classes_attended,
                     total_classes = :total_classes,
                     attendance_pct = :attendance_pct,
                     updated_by = :updated_by
                 WHERE id = :id'
            );
            $updAcad->execute([
                ':course' => $chosenDept,
                ':semester' => $chosenSemester,
                ':subject_name' => $subjectName,
                ':se_marks' => $seMarks,
                ':ia_marks' => $iaMarks,
                ':marks_obtained' => $marksScored,
                ':max_marks' => $maxMarks,
                ':min_marks' => $minMarks,
                ':result_status' => $resultStatus,
                ':classes_attended' => $classesAttended,
                ':total_classes' => $totalClasses,
                ':attendance_pct' => $attendancePct,
                ':updated_by' => $username,
                ':id' => $recordId,
            ]);
            $editId = 0;
            $activeTab = 'records';
            $message = 'Updated record #' . $recordId . ' (' . htmlspecialchars($chosenDept, ENT_QUOTES, 'UTF-8') . ' · Sem ' . htmlspecialchars($chosenSemester, ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars($subjectName, ENT_QUOTES, 'UTF-8') . ').';
        }
    } elseif ($action === 'delete_academic') {
        $activeTab = 'records';
        $recordId = (int) ($_POST['record_id'] ?? 0);
        if ($recordId > 0) {
            $delStmt = $pdo->prepare('DELETE FROM student_academics WHERE id = :id');
            $delStmt->execute([':id' => $recordId]);
            if ($delStmt->rowCount() > 0) {
                if ($editId === $recordId) {
                    $editId = 0;
                }
                $message = 'Deleted marks & attendance record #' . $recordId . '.';
            } else {
                $error = 'Record not found.';
            }
        }
    }
}

$sqlTeachers = 'SELECT id, username, full_name, course, subjects
                FROM users
                WHERE LOWER(TRIM(role)) IN (\'teacher\', \'teachers\')';
$paramsTeachers = [];
if ($selectedDept !== 'ALL') {
    $sqlTeachers .= ' AND UPPER(TRIM(COALESCE(course, \'BCA\'))) = :tdept';
    $paramsTeachers[':tdept'] = $selectedDept;
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
    $etStmt = $pdo->prepare('SELECT id, username, full_name, course, subjects FROM users WHERE id = :id AND LOWER(TRIM(role)) IN (\'teacher\', \'teachers\') LIMIT 1');
    $etStmt->execute([':id' => $editTeacherId]);
    $editingTeacher = $etStmt->fetch() ?: null;
}

$sqlStudents = 'SELECT id, username, full_name, role, UPPER(TRIM(COALESCE(course, \'BCA\'))) AS course, mobile_number, email
                FROM users
                WHERE (LOWER(TRIM(role)) IN (\'student\', \'students\', \'stu\')
                   OR LOWER(TRIM(role)) LIKE \'%stud%\'
                   OR UPPER(TRIM(username)) LIKE \'U21ME%\')';
$paramsStudents = [];
if ($selectedDept !== 'ALL') {
    $sqlStudents .= ' AND UPPER(TRIM(COALESCE(course, \'BCA\'))) = :sdept';
    $paramsStudents[':sdept'] = $selectedDept;
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
    $esStmt = $pdo->prepare('SELECT id, username, full_name, role, UPPER(TRIM(COALESCE(course, \'BCA\'))) AS course, mobile_number, email FROM users WHERE id = :id LIMIT 1');
    $esStmt->execute([':id' => $editStudentId]);
    $editingStudent = $esStmt->fetch() ?: null;
}

$sqlAcademics = 'SELECT id, student_username, UPPER(TRIM(COALESCE(course, \'BCA\'))) AS course, semester, subject_name, se_marks, ia_marks, marks_obtained, max_marks, min_marks, result_status, classes_attended, total_classes, attendance_pct, updated_by
                 FROM student_academics
                 WHERE 1=1';
$paramsAcademics = [];
if ($selectedDept !== 'ALL') {
    $sqlAcademics .= ' AND UPPER(TRIM(COALESCE(course, \'BCA\'))) = :adept';
    $paramsAcademics[':adept'] = $selectedDept;
}
if ($recordSemFilter !== 'ALL') {
    $sqlAcademics .= ' AND UPPER(TRIM(COALESCE(semester, \'I\'))) = :rsem';
    $paramsAcademics[':rsem'] = $recordSemFilter;
}
if ($recordSearch !== '') {
    $sqlAcademics .= ' AND (LOWER(TRIM(student_username)) LIKE :rq
                       OR LOWER(TRIM(COALESCE(subject_name, \'\'))) LIKE :rq
                       OR LOWER(TRIM(COALESCE(course, \'\'))) LIKE :rq
                       OR LOWER(TRIM(COALESCE(result_status, \'\'))) LIKE :rq)';
    $paramsAcademics[':rq'] = '%' . strtolower($recordSearch) . '%';
}
$sqlAcademics .= ' ORDER BY id DESC';
$academicsStmt = $pdo->prepare($sqlAcademics);
$academicsStmt->execute($paramsAcademics);
$academics = $academicsStmt->fetchAll();

$editingRecord = null;
if ($editId > 0) {
    $editStmt = $pdo->prepare('SELECT id, student_username, UPPER(TRIM(COALESCE(course, \'BCA\'))) AS course, semester, subject_name, se_marks, ia_marks, marks_obtained, max_marks, min_marks, result_status, classes_attended, total_classes, attendance_pct FROM student_academics WHERE id = :id LIMIT 1');
    $editStmt->execute([':id' => $editId]);
    $editingRecord = $editStmt->fetch() ?: null;
}

$deptScopeLabel = ($selectedDept === 'ALL') ? 'All Departments' : $selectedDept;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Principal Portal — PES College Portal</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .dept-tabs-bar { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; padding:10px 12px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; align-items:center; }
        .dept-tabs-label { font-size:12.5px; font-weight:700; color:#475569; margin-right:4px; }
        .dept-tab { display:inline-block; padding:7px 13px; border-radius:999px; border:1px solid #cbd5e1; background:#ffffff; color:#1e293b; font-size:12.5px; font-weight:600; text-decoration:none; }
        .dept-tab.active { background:#0b3c78; color:#ffffff; border-color:#0b3c78; }
        .section-selector { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:18px; }
        .section-tab { display:inline-block; padding:10px 15px; border-radius:6px; border:1px solid #cbd5e1; background:#ffffff; color:#1e293b; font-weight:600; font-size:13.5px; text-decoration:none; }
        .section-tab.active { background:#0f766e; color:#ffffff; border-color:#0f766e; }
        .dept-select { width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:14px; background:#ffffff; color:#1e293b; }
        .auto-calc-box { background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; padding:10px 14px; margin-bottom:14px; font-size:13px; display:flex; flex-wrap:wrap; gap:16px; align-items:center; }
        .badge-pass { color:#15803d; font-weight:700; }
        .badge-fail { color:#b91c1c; font-weight:700; }
        .filter-bar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:12px 0; }
        .filter-input { flex:1 1 220px; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; background:#ffffff; color:#0f172a; }
        .filter-select { padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; background:#ffffff; color:#0f172a; }
        .filter-btn { background:#0b3c78; color:#ffffff; border:none; padding:8px 14px; border-radius:6px; font-size:12.5px; font-weight:600; cursor:pointer; }
        .clear-btn { display:inline-block; padding:8px 12px; border-radius:6px; background:#e2e8f0; color:#1e293b; text-decoration:none; font-size:12.5px; font-weight:600; }
        .edit-link-btn { display:inline-block; background:#0b3c78; color:#fff; padding:5px 10px; border-radius:4px; text-decoration:none; font-size:12px; }
        .del-btn { background:#c62828; color:#fff; border:none; padding:5px 10px; border-radius:4px; cursor:pointer; font-size:12px; }
        .edit-panel-box { background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:16px; margin-bottom:18px; }
    </style>
</head>
<body>
    <div class="portal-wrapper dashboard-wrapper">
        <div class="dashboard-topbar">
            <div>
                <h1>Principal Portal — All Departments</h1>
                <p>Welcome, <?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars(strtoupper($role), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>)</p>
            </div>
            <div>
                <a href="dashboard.php" class="logout-btn" style="background-color:#0b3c78; margin-right:8px;">Dashboard</a>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>

        <div class="dept-tabs-bar">
            <span class="dept-tabs-label">Department Filter:</span>
            <?php foreach ($departmentTabs as $deptOpt): ?>
                <?php
                $tabLabel = ($deptOpt === 'ALL') ? 'All Departments' : $deptOpt;
                $tabUrl = 'principal.php?tab=' . urlencode($activeTab) . '&dept=' . urlencode($deptOpt);
                ?>
                <a href="<?php echo htmlspecialchars($tabUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dept-tab <?php echo ($selectedDept === $deptOpt) ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($tabLabel, ENT_QUOTES, 'UTF-8'); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="section-selector">
            <a href="principal.php?tab=teachers&amp;dept=<?php echo urlencode($selectedDept); ?>" class="section-tab <?php echo ($activeTab === 'teachers') ? 'active' : ''; ?>">1. Teachers (<?php echo count($teachers); ?>)</a>
            <a href="principal.php?tab=students&amp;dept=<?php echo urlencode($selectedDept); ?>" class="section-tab <?php echo ($activeTab === 'students') ? 'active' : ''; ?>">2. Students (<?php echo count($students); ?>)</a>
            <a href="principal.php?tab=add_academic&amp;dept=<?php echo urlencode($selectedDept); ?>" class="section-tab <?php echo ($activeTab === 'add_academic') ? 'active' : ''; ?>">3. Add Marks & Attendance</a>
            <a href="principal.php?tab=records&amp;dept=<?php echo urlencode($selectedDept); ?>" class="section-tab <?php echo ($activeTab === 'records') ? 'active' : ''; ?>">4. All Marks & Attendance (<?php echo count($academics); ?>)</a>
            <?php if ($activeTab === 'edit_academic'): ?>
                <span class="section-tab active">5. Edit Marks & Attendance</span>
            <?php endif; ?>
        </div>

        <?php if ($message !== ''): ?>
            <div class="error-msg" style="background-color:#e8f5e9; color:#1b5e20; border-color:#c8e6c9;">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($activeTab === 'teachers'): ?>
            <div class="dash-card" style="margin-bottom:24px;">
                <?php if ($editingTeacher): ?>
                    <?php $edTcDept = normalizePrincipalDept((string) ($editingTeacher['course'] ?? 'BCA'), $courseOptions); ?>
                    <div class="edit-panel-box">
                        <h2 style="margin-top:0;">Edit Teacher: <?php echo htmlspecialchars((string) $editingTeacher['username'], ENT_QUOTES, 'UTF-8'); ?></h2>
                        <form method="POST" action="principal.php?tab=teachers&amp;dept=<?php echo urlencode($selectedDept); ?>">
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
                            <a href="principal.php?tab=teachers&amp;dept=<?php echo urlencode($selectedDept); ?>" class="logout-btn" style="display:inline-block; margin-top:10px; background-color:#64748b;">Cancel Edit</a>
                        </form>
                    </div>
                <?php else: ?>
                    <h2>Add Teacher Account (<?php echo htmlspecialchars($deptScopeLabel, ENT_QUOTES, 'UTF-8'); ?>)</h2>
                    <form method="POST" action="principal.php?tab=teachers&amp;dept=<?php echo urlencode($selectedDept); ?>">
                        <input type="hidden" name="action" value="add_teacher">
                        <input type="hidden" name="active_dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="input-group">
                            <label for="teacher_username">Teacher Username / ID</label>
                            <input type="text" id="teacher_username" name="teacher_username" placeholder="e.g. BCOMTECH002" required>
                        </div>
                        <div class="input-group">
                            <label for="teacher_full_name">Teacher Full Name</label>
                            <input type="text" id="teacher_full_name" name="teacher_full_name" placeholder="e.g. Prof. Ramesh Kumar" required>
                        </div>
                        <div class="input-group">
                            <label for="teacher_course">Department / Course</label>
                            <select id="teacher_course" name="teacher_course" class="dept-select" required>
                                <?php foreach ($courseOptions as $deptOpt): ?>
                                    <option value="<?php echo htmlspecialchars($deptOpt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($selectedDept === $deptOpt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($deptOpt, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label for="teacher_subjects">Assigned Subjects (comma-separated)</label>
                            <input type="text" id="teacher_subjects" name="teacher_subjects" placeholder="e.g. Accounting, Business Studies" required>
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
                <form method="GET" action="principal.php" class="filter-bar">
                    <input type="hidden" name="tab" value="teachers">
                    <input type="hidden" name="dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="text" name="t_search" class="filter-input" value="<?php echo htmlspecialchars($teacherSearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search teachers by username, name, dept, or subject...">
                    <button type="submit" class="filter-btn">Search</button>
                    <?php if ($teacherSearch !== ''): ?>
                        <a href="principal.php?tab=teachers&amp;dept=<?php echo urlencode($selectedDept); ?>" class="clear-btn">Clear</a>
                    <?php endif; ?>
                </form>
                <?php if (empty($teachers)): ?>
                    <p>No teacher records found.</p>
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
                                    $tcDept = normalizePrincipalDept((string) ($tc['course'] ?? 'BCA'), $courseOptions);
                                    ?>
                                    <tr style="border-bottom:1px solid #eef2f6;">
                                        <td style="padding:10px;"><strong><?php echo htmlspecialchars((string) $tc['username'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                        <td style="padding:10px;"><?php echo htmlspecialchars((string) ($tc['full_name'] ?? $tc['username']), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="padding:10px;"><strong><?php echo htmlspecialchars($tcDept, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                        <td style="padding:10px;"><?php echo htmlspecialchars((string) ($tc['subjects'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="padding:10px; white-space:nowrap;">
                                            <a href="principal.php?tab=teachers&amp;dept=<?php echo urlencode($selectedDept); ?>&amp;edit_teacher_id=<?php echo $tcId; ?>" class="edit-link-btn" style="margin-right:6px;">Edit</a>
                                            <form method="POST" action="principal.php?tab=teachers&amp;dept=<?php echo urlencode($selectedDept); ?>" style="display:inline;" onsubmit="return confirm('Delete this teacher account?');">
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
        <?php elseif ($activeTab === 'students'): ?>
            <div class="dash-card" style="margin-bottom:24px;">
                <h2><?php echo $editingStudent ? 'Edit Student Details' : 'Add New Student (' . htmlspecialchars($deptScopeLabel, ENT_QUOTES, 'UTF-8') . ')'; ?></h2>
                <?php if ($editingStudent): ?>
                    <?php $edStuDept = normalizePrincipalDept((string) ($editingStudent['course'] ?? 'BCA'), $courseOptions); ?>
                    <form method="POST" action="principal.php?tab=students&amp;dept=<?php echo urlencode($selectedDept); ?>">
                        <input type="hidden" name="action" value="update_student">
                        <input type="hidden" name="active_dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="student_id" value="<?php echo (int) $editingStudent['id']; ?>">
                        <div class="input-group">
                            <label for="edit_stu_usn">Student USN / Username</label>
                            <input type="text" id="edit_stu_usn" name="username" value="<?php echo htmlspecialchars((string) $editingStudent['username'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="input-group">
                            <label for="edit_stu_course">Department / Course</label>
                            <select id="edit_stu_course" name="course" class="dept-select" required>
                                <?php foreach ($courseOptions as $deptOpt): ?>
                                    <option value="<?php echo htmlspecialchars($deptOpt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($edStuDept === $deptOpt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($deptOpt, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label for="edit_stu_name">Student Full Name</label>
                            <input type="text" id="edit_stu_name" name="full_name" value="<?php echo htmlspecialchars((string) $editingStudent['full_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="input-group">
                            <label for="edit_stu_mobile">Registered Mobile Number (10 digits)</label>
                            <input type="text" id="edit_stu_mobile" name="mobile_number" value="<?php echo htmlspecialchars((string) ($editingStudent['mobile_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="input-group">
                            <label for="edit_stu_email">Email Address</label>
                            <input type="email" id="edit_stu_email" name="email" value="<?php echo htmlspecialchars((string) ($editingStudent['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="input-group">
                            <label for="edit_stu_pass">New Password (Optional, min 6 chars)</label>
                            <input type="password" id="edit_stu_pass" name="new_password" minlength="6" placeholder="Leave blank to keep current password">
                        </div>
                        <button type="submit" class="login-btn">Save Student Changes</button>
                        <a href="principal.php?tab=students&amp;dept=<?php echo urlencode($selectedDept); ?>" class="logout-btn" style="display:inline-block; margin-top:10px; background-color:#64748b;">Cancel Edit</a>
                    </form>
                <?php else: ?>
                    <form method="POST" action="principal.php?tab=students&amp;dept=<?php echo urlencode($selectedDept); ?>">
                        <input type="hidden" name="action" value="add_student">
                        <input type="hidden" name="active_dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="input-group">
                            <label for="username">Student USN / Username</label>
                            <input type="text" id="username" name="username" placeholder="e.g. BCOM003" required>
                        </div>
                        <div class="input-group">
                            <label for="course">Department / Course</label>
                            <select id="course" name="course" class="dept-select" required>
                                <?php foreach ($courseOptions as $deptOpt): ?>
                                    <option value="<?php echo htmlspecialchars($deptOpt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($selectedDept === $deptOpt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($deptOpt, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label for="full_name">Student Full Name</label>
                            <input type="text" id="full_name" name="full_name" placeholder="e.g. Ananya Sharma" required>
                        </div>
                        <div class="input-group">
                            <label for="mobile_number">Registered Mobile Number (Optional, 10 digits)</label>
                            <input type="text" id="mobile_number" name="mobile_number" placeholder="e.g. 9876543210">
                        </div>
                        <div class="input-group">
                            <label for="email">Email Address (Optional)</label>
                            <input type="email" id="email" name="email" placeholder="e.g. student@example.com">
                        </div>
                        <div class="input-group">
                            <label for="password">Initial Password (min 6 characters)</label>
                            <input type="password" id="password" name="password" minlength="6" required>
                        </div>
                        <button type="submit" class="login-btn">Add Student</button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="dash-card">
                <h2><?php echo htmlspecialchars($deptScopeLabel, ENT_QUOTES, 'UTF-8'); ?> — Student Details (<?php echo count($students); ?>)</h2>
                <form method="GET" action="principal.php" class="filter-bar">
                    <input type="hidden" name="tab" value="students">
                    <input type="hidden" name="dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="text" name="s_q" class="filter-input" value="<?php echo htmlspecialchars($studentSearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search students by USN, name, dept, mobile, or email...">
                    <button type="submit" class="filter-btn">Search</button>
                    <?php if ($studentSearch !== ''): ?>
                        <a href="principal.php?tab=students&amp;dept=<?php echo urlencode($selectedDept); ?>" class="clear-btn">Clear</a>
                    <?php endif; ?>
                </form>

                <?php if (empty($students)): ?>
                    <p>No student accounts found.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table style="width:100%; border-collapse:collapse; margin-top:12px; font-size:14px;">
                            <thead>
                                <tr style="background:#f8fafc; text-align:left; border-bottom:2px solid #dce3ec;">
                                    <th style="padding:10px;">USN / Username</th>
                                    <th style="padding:10px;">Course</th>
                                    <th style="padding:10px;">Student Name</th>
                                    <th style="padding:10px;">Mobile</th>
                                    <th style="padding:10px;">Email</th>
                                    <th style="padding:10px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $st): ?>
                                    <?php
                                    $stUsn = (string) $st['username'];
                                    $stCourse = normalizePrincipalDept((string) ($st['course'] ?? 'BCA'), $courseOptions);
                                    ?>
                                    <tr style="border-bottom:1px solid #eef2f6;">
                                        <td style="padding:10px;"><strong><?php echo htmlspecialchars($stUsn, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                        <td style="padding:10px;"><strong><?php echo htmlspecialchars($stCourse, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                        <td style="padding:10px;"><?php echo htmlspecialchars((string) ($st['full_name'] ?? $stUsn), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="padding:10px;"><?php echo htmlspecialchars((string) ($st['mobile_number'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="padding:10px;"><?php echo htmlspecialchars((string) ($st['email'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="padding:10px; white-space:nowrap;">
                                            <a href="principal.php?tab=students&amp;dept=<?php echo urlencode($selectedDept); ?>&amp;edit_student_id=<?php echo (int) $st['id']; ?>" class="edit-link-btn" style="margin-right:6px;">Edit Student</a>
                                            <a href="principal.php?tab=add_academic&amp;dept=<?php echo urlencode($selectedDept); ?>&amp;usn=<?php echo urlencode($stUsn); ?>&amp;course=<?php echo urlencode($stCourse); ?>" style="display:inline-block; background:#0f766e; color:#fff; padding:5px 10px; border-radius:4px; text-decoration:none; font-size:12px;">Marks & Attendance</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php elseif ($activeTab === 'add_academic'): ?>
            <div class="dash-card">
                <h2>Add Marks & Attendance (<?php echo htmlspecialchars($deptScopeLabel, ENT_QUOTES, 'UTF-8'); ?>)</h2>
                <form method="POST" action="principal.php?tab=add_academic&amp;dept=<?php echo urlencode($selectedDept); ?>" id="addMarksForm">
                    <input type="hidden" name="action" value="add_academic">
                    <input type="hidden" name="active_dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="input-group">
                        <label for="add_student_username">Student USN / Username</label>
                        <input type="text" id="add_student_username" name="student_username" value="<?php echo htmlspecialchars($prefillUsn, ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. BCOM001" required>
                    </div>
                    <div class="input-group">
                        <label for="add_course">Department / Course</label>
                        <select id="add_course" name="course" class="dept-select" required>
                            <?php foreach ($courseOptions as $deptOpt): ?>
                                <option value="<?php echo htmlspecialchars($deptOpt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($prefillCourse === $deptOpt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($deptOpt, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group">
                        <label for="add_semester">Semester</label>
                        <select id="add_semester" name="semester" class="dept-select" required>
                            <?php foreach ($semesterOptions as $semOpt): ?>
                                <option value="<?php echo htmlspecialchars($semOpt, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($semOpt, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group">
                        <label for="add_subject_name">Subject Name</label>
                        <input type="text" id="add_subject_name" name="subject_name" placeholder="e.g. BUSINESS / DBMS" required>
                    </div>
                    <div class="input-group">
                        <label for="add_se_marks">SE Marks (Semester End)</label>
                        <input type="number" step="0.01" min="0" id="add_se_marks" name="se_marks" placeholder="e.g. 48" required>
                    </div>
                    <div class="input-group">
                        <label for="add_ia_marks">IA Marks (Internal Assessment)</label>
                        <input type="number" step="0.01" min="0" id="add_ia_marks" name="ia_marks" placeholder="e.g. 22" required>
                    </div>
                    <div class="input-group">
                        <label for="add_max_marks">Maximum Marks</label>
                        <input type="number" step="0.01" min="1" id="add_max_marks" name="max_marks" value="100" required>
                    </div>
                    <div class="auto-calc-box">
                        <span>Marks Scored (SE + IA): <strong id="addScoredPreview">0.00</strong></span>
                        <span>Min Pass Marks (40%): <strong id="addMinPreview">40.00</strong></span>
                        <span>Auto Status: <strong id="addStatusPreview" class="badge-fail">Fail</strong></span>
                    </div>
                    <div class="input-group">
                        <label for="add_classes_attended">Classes Attended</label>
                        <input type="number" min="0" id="add_classes_attended" name="classes_attended" placeholder="e.g. 98" required>
                    </div>
                    <div class="input-group">
                        <label for="add_total_classes">Total Classes</label>
                        <input type="number" min="1" id="add_total_classes" name="total_classes" placeholder="e.g. 100" required>
                    </div>
                    <button type="submit" class="login-btn">Add Marks & Attendance</button>
                </form>
            </div>
        <?php elseif ($activeTab === 'edit_academic'): ?>
            <div class="dash-card">
                <h2>Edit Marks & Attendance</h2>
                <?php if (!$editingRecord): ?>
                    <p>Record not found. <a href="principal.php?tab=records&amp;dept=<?php echo urlencode($selectedDept); ?>" style="color:#0b3c78; font-weight:600;">Back to All Marks & Attendance</a></p>
                <?php else: ?>
                    <?php
                    $currentDept = normalizePrincipalDept((string) ($editingRecord['course'] ?? 'BCA'), $courseOptions);
                    $currentSem = normalizePrincipalSemester((string) ($editingRecord['semester'] ?? 'I'), $semesterOptions);
                    $curScored = (float) ($editingRecord['marks_obtained'] ?? 0);
                    $curMax = (float) ($editingRecord['max_marks'] ?? 100);
                    $curSe = isset($editingRecord['se_marks']) ? (float) $editingRecord['se_marks'] : round($curScored * 0.75, 2);
                    $curIa = isset($editingRecord['ia_marks']) ? (float) $editingRecord['ia_marks'] : round($curScored - $curSe, 2);
                    $curMin = isset($editingRecord['min_marks']) ? (float) $editingRecord['min_marks'] : round($curMax * 0.40, 2);
                    $curStatus = ($curScored >= $curMin) ? 'Pass' : 'Fail';
                    ?>
                    <form method="POST" action="principal.php?tab=edit_academic&amp;edit_id=<?php echo (int) $editingRecord['id']; ?>&amp;dept=<?php echo urlencode($selectedDept); ?>" id="editMarksForm">
                        <input type="hidden" name="action" value="update_academic">
                        <input type="hidden" name="active_dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="record_id" value="<?php echo (int) $editingRecord['id']; ?>">
                        <div class="input-group">
                            <label>Student USN / Username</label>
                            <input type="text" value="<?php echo htmlspecialchars((string) $editingRecord['student_username'], ENT_QUOTES, 'UTF-8'); ?>" disabled>
                        </div>
                        <div class="input-group">
                            <label for="edit_course">Department / Course</label>
                            <select id="edit_course" name="course" class="dept-select" required>
                                <?php foreach ($courseOptions as $deptOpt): ?>
                                    <option value="<?php echo htmlspecialchars($deptOpt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($currentDept === $deptOpt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($deptOpt, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label for="edit_semester">Semester</label>
                            <select id="edit_semester" name="semester" class="dept-select" required>
                                <?php foreach ($semesterOptions as $semOpt): ?>
                                    <option value="<?php echo htmlspecialchars($semOpt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($currentSem === $semOpt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($semOpt, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label for="edit_subject_name">Subject Name</label>
                            <input type="text" id="edit_subject_name" name="subject_name" value="<?php echo htmlspecialchars((string) $editingRecord['subject_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="input-group">
                            <label for="edit_se_marks">SE Marks (Semester End)</label>
                            <input type="number" step="0.01" min="0" id="edit_se_marks" name="se_marks" value="<?php echo htmlspecialchars((string) $curSe, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="input-group">
                            <label for="edit_ia_marks">IA Marks (Internal Assessment)</label>
                            <input type="number" step="0.01" min="0" id="edit_ia_marks" name="ia_marks" value="<?php echo htmlspecialchars((string) $curIa, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="input-group">
                            <label for="edit_max_marks">Maximum Marks</label>
                            <input type="number" step="0.01" min="1" id="edit_max_marks" name="max_marks" value="<?php echo htmlspecialchars((string) $curMax, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="auto-calc-box">
                            <span>Marks Scored (SE + IA): <strong id="editScoredPreview"><?php echo number_format($curScored, 2); ?></strong></span>
                            <span>Min Pass Marks (40%): <strong id="editMinPreview"><?php echo number_format($curMin, 2); ?></strong></span>
                            <span>Auto Status: <strong id="editStatusPreview" class="<?php echo ($curStatus === 'Pass') ? 'badge-pass' : 'badge-fail'; ?>"><?php echo htmlspecialchars($curStatus, ENT_QUOTES, 'UTF-8'); ?></strong></span>
                        </div>
                        <div class="input-group">
                            <label for="edit_classes_attended">Classes Attended</label>
                            <input type="number" min="0" id="edit_classes_attended" name="classes_attended" value="<?php echo htmlspecialchars((string) $editingRecord['classes_attended'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="input-group">
                            <label for="edit_total_classes">Total Classes</label>
                            <input type="number" min="1" id="edit_total_classes" name="total_classes" value="<?php echo htmlspecialchars((string) $editingRecord['total_classes'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <button type="submit" class="login-btn">Save Changes</button>
                        <a href="principal.php?tab=records&amp;dept=<?php echo urlencode($selectedDept); ?>" class="logout-btn" style="display:inline-block; margin-top:10px; background-color:#64748b;">Back to Records</a>
                    </form>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="dash-card">
                <h2><?php echo htmlspecialchars($deptScopeLabel, ENT_QUOTES, 'UTF-8'); ?> — Student Marks & Attendance (<?php echo count($academics); ?>)</h2>
                <form method="GET" action="principal.php" class="filter-bar">
                    <input type="hidden" name="tab" value="records">
                    <input type="hidden" name="dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                    <select name="r_sem" class="filter-select">
                        <option value="ALL" <?php echo ($recordSemFilter === 'ALL') ? 'selected' : ''; ?>>All Semesters</option>
                        <?php foreach ($semesterOptions as $semOpt): ?>
                            <option value="<?php echo htmlspecialchars($semOpt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($recordSemFilter === $semOpt) ? 'selected' : ''; ?>>Sem <?php echo htmlspecialchars($semOpt, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="r_q" class="filter-input" value="<?php echo htmlspecialchars($recordSearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search by USN, dept, subject, or status...">
                    <button type="submit" class="filter-btn">Filter</button>
                    <?php if ($recordSemFilter !== 'ALL' || $recordSearch !== ''): ?>
                        <a href="principal.php?tab=records&amp;dept=<?php echo urlencode($selectedDept); ?>" class="clear-btn">Clear</a>
                    <?php endif; ?>
                </form>

                <?php if (empty($academics)): ?>
                    <p>No marks or attendance records match this filter.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table style="width:100%; border-collapse:collapse; margin-top:12px; font-size:14px;">
                            <thead>
                                <tr style="background:#f8fafc; text-align:left; border-bottom:2px solid #dce3ec;">
                                    <th style="padding:10px;">Student USN</th>
                                    <th style="padding:10px;">Dept</th>
                                    <th style="padding:10px;">Sem</th>
                                    <th style="padding:10px;">Subject</th>
                                    <th style="padding:10px;">SE / IA</th>
                                    <th style="padding:10px;">Marks Scored</th>
                                    <th style="padding:10px;">Status</th>
                                    <th style="padding:10px;">Attendance</th>
                                    <th style="padding:10px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($academics as $ac): ?>
                                    <?php
                                    $rowId = (int) $ac['id'];
                                    $rowDept = normalizePrincipalDept((string) ($ac['course'] ?? 'BCA'), $courseOptions);
                                    $rowSem = normalizePrincipalSemester((string) ($ac['semester'] ?? 'I'), $semesterOptions);
                                    $rowScored = (float) ($ac['marks_obtained'] ?? 0);
                                    $rowMax = (float) ($ac['max_marks'] ?? 100);
                                    $rowMin = isset($ac['min_marks']) ? (float) $ac['min_marks'] : round($rowMax * 0.40, 2);
                                    $rowSe = isset($ac['se_marks']) ? (float) $ac['se_marks'] : round($rowScored * 0.75, 2);
                                    $rowIa = isset($ac['ia_marks']) ? (float) $ac['ia_marks'] : round($rowScored - $rowSe, 2);
                                    $rowStatus = ($rowScored >= $rowMin) ? 'Pass' : 'Fail';
                                    ?>
                                    <tr style="border-bottom:1px solid #eef2f6;">
                                        <td style="padding:10px;"><strong><?php echo htmlspecialchars((string) $ac['student_username'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                        <td style="padding:10px;"><strong><?php echo htmlspecialchars($rowDept, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                        <td style="padding:10px;"><strong><?php echo htmlspecialchars($rowSem, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                        <td style="padding:10px;"><?php echo htmlspecialchars((string) $ac['subject_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="padding:10px;"><?php echo htmlspecialchars((string) $rowSe, ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string) $rowIa, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="padding:10px;"><strong><?php echo htmlspecialchars((string) $rowScored, ENT_QUOTES, 'UTF-8'); ?></strong> / <?php echo htmlspecialchars((string) $rowMax, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="padding:10px;"><span class="<?php echo ($rowStatus === 'Pass') ? 'badge-pass' : 'badge-fail'; ?>"><?php echo htmlspecialchars($rowStatus, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <td style="padding:10px;"><?php echo htmlspecialchars((string) $ac['classes_attended'], ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string) $ac['total_classes'], ENT_QUOTES, 'UTF-8'); ?> (<strong><?php echo htmlspecialchars((string) $ac['attendance_pct'], ENT_QUOTES, 'UTF-8'); ?>%</strong>)</td>
                                        <td style="padding:10px; white-space:nowrap;">
                                            <a href="principal.php?tab=edit_academic&amp;edit_id=<?php echo $rowId; ?>&amp;dept=<?php echo urlencode($selectedDept); ?>" class="edit-link-btn" style="margin-right:6px;">Edit</a>
                                            <form method="POST" action="principal.php?tab=records&amp;dept=<?php echo urlencode($selectedDept); ?>" style="display:inline;" onsubmit="return confirm('Delete this marks and attendance record?');">
                                                <input type="hidden" name="action" value="delete_academic">
                                                <input type="hidden" name="active_dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="record_id" value="<?php echo $rowId; ?>">
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
        <?php endif; ?>
    </div>

    <script>
        (function () {
            function bindCalculator(seId, iaId, maxId, scoredId, minId, statusId) {
                const seEl = document.getElementById(seId);
                const iaEl = document.getElementById(iaId);
                const maxEl = document.getElementById(maxId);
                const scoredEl = document.getElementById(scoredId);
                const minEl = document.getElementById(minId);
                const statusEl = document.getElementById(statusId);
                if (!seEl || !iaEl || !maxEl || !scoredEl || !minEl || !statusEl) {
                    return;
                }
                function refresh() {
                    const seVal = parseFloat(seEl.value) || 0;
                    const iaVal = parseFloat(iaEl.value) || 0;
                    const maxVal = parseFloat(maxEl.value) || 100;
                    const scored = seVal + iaVal;
                    const minPass = maxVal * 0.40;
                    const passed = scored >= minPass;

                    scoredEl.textContent = scored.toFixed(2);
                    minEl.textContent = minPass.toFixed(2);
                    statusEl.textContent = passed ? 'Pass' : 'Fail';
                    statusEl.className = passed ? 'badge-pass' : 'badge-fail';
                }
                seEl.addEventListener('input', refresh);
                iaEl.addEventListener('input', refresh);
                maxEl.addEventListener('input', refresh);
                refresh();
            }

            bindCalculator('add_se_marks', 'add_ia_marks', 'add_max_marks', 'addScoredPreview', 'addMinPreview', 'addStatusPreview');
            bindCalculator('edit_se_marks', 'edit_ia_marks', 'edit_max_marks', 'editScoredPreview', 'editMinPreview', 'editStatusPreview');
        })();
    </script>
</body>
</html>