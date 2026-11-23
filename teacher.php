<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['username'])) {
    header('Location: index.php?error=' . urlencode('Please log in first.'));
    exit;
}

$role = strtolower(trim((string) $_SESSION['role']));
if ($role === 'teachers') {
    $role = 'teacher';
}

$userId = (int) $_SESSION['user_id'];
$username = (string) $_SESSION['username'];
$fullName = (string) ($_SESSION['full_name'] ?? $username);

if ($role !== 'teacher') {
    http_response_code(403);
    die('Access denied: Only Teacher can access this page.');
}

$courseOptions = [
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

function normalizeTeacherSemester(string $rawSem, array $allowed): string
{
    $clean = strtoupper(trim($rawSem));
    return in_array($clean, $allowed, true) ? $clean : 'I';
}

function normalizeTeacherMobile10(string $phone): string
{
    $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        return substr($digits, 2);
    }
    return $digits;
}

function parseTeacherSubjects(string $rawSubjects): array
{
    $parts = preg_split('/[,;\n]+/', $rawSubjects) ?: [];
    $result = [];
    $seen = [];
    foreach ($parts as $part) {
        $clean = trim($part);
        if ($clean === '') {
            continue;
        }
        $key = strtolower($clean);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $result[] = $clean;
        }
    }
    return $result;
}

function isSubjectAllowed(string $subject, array $allowedSubjects): ?string
{
    $needle = strtolower(trim($subject));
    if ($needle === '') {
        return null;
    }
    foreach ($allowedSubjects as $allowed) {
        if (strtolower(trim($allowed)) === $needle) {
            return trim($allowed);
        }
    }
    return null;
}

try {
    $colCheckUsersCourse = $pdo->query('SHOW COLUMNS FROM users LIKE \'course\'');
    if (!$colCheckUsersCourse->fetch()) {
        $pdo->exec('ALTER TABLE users ADD COLUMN course VARCHAR(50) NULL DEFAULT \'BCA\'');
    }
} catch (Throwable $e) {
    // Ignore if column already exists
}

try {
    $colCheckUsersSubjects = $pdo->query('SHOW COLUMNS FROM users LIKE \'subjects\'');
    if (!$colCheckUsersSubjects->fetch()) {
        $pdo->exec('ALTER TABLE users ADD COLUMN subjects TEXT NULL');
    }
} catch (Throwable $e) {
    // Ignore if column already exists
}

$teacherCourse = 'BCA';
$rawTeacherSubjects = '';
$tStmt = $pdo->prepare('SELECT course, subjects FROM users WHERE id = :id LIMIT 1');
$tStmt->execute([':id' => $userId]);
$tRow = $tStmt->fetch();

$dbTeacherCourse = strtoupper(trim((string) ($tRow['course'] ?? '')));
if ($dbTeacherCourse !== '' && in_array($dbTeacherCourse, $courseOptions, true)) {
    $teacherCourse = $dbTeacherCourse;
} else {
    $sessCourse = strtoupper(trim((string) ($_SESSION['course'] ?? 'BCA')));
    $teacherCourse = in_array($sessCourse, $courseOptions, true) ? $sessCourse : 'BCA';
    $saveCourse = $pdo->prepare('UPDATE users SET course = :course WHERE id = :id');
    $saveCourse->execute([
        ':course' => $teacherCourse,
        ':id' => $userId,
    ]);
}
$_SESSION['course'] = $teacherCourse;

$rawTeacherSubjects = trim((string) ($tRow['subjects'] ?? ''));
$assignedSubjects = parseTeacherSubjects($rawTeacherSubjects);
if (empty($assignedSubjects)) {
    $assignedSubjects = ['DBMS'];
    $saveSubj = $pdo->prepare('UPDATE users SET subjects = :subjects WHERE id = :id');
    $saveSubj->execute([
        ':subjects' => implode(', ', $assignedSubjects),
        ':id' => $userId,
    ]);
}
$_SESSION['subjects'] = implode(', ', $assignedSubjects);

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

$allowedTabs = ['students', 'add_academic', 'records', 'edit_academic'];
$activeTab = strtolower(trim($_GET['tab'] ?? 'students'));
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'students';
}

$studentSearch = trim((string) ($_GET['s_q'] ?? ''));
$recordSearch = trim((string) ($_GET['r_q'] ?? ''));
$recordSemFilter = strtoupper(trim((string) ($_GET['r_sem'] ?? 'ALL')));
if ($recordSemFilter !== 'ALL' && !in_array($recordSemFilter, $semesterOptions, true)) {
    $recordSemFilter = 'ALL';
}

$prefillUsn = strtoupper(trim($_GET['usn'] ?? ''));
$editStudentId = (int) ($_GET['edit_student_id'] ?? 0);
$editId = (int) ($_GET['edit_id'] ?? 0);
if ($editId > 0) {
    $activeTab = 'edit_academic';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add_student';

    if ($action === 'add_student') {
        $activeTab = 'students';
        $studentUsername = strtoupper(trim($_POST['username'] ?? ''));
        $studentName = trim($_POST['full_name'] ?? '');
        $studentMobileRaw = trim($_POST['mobile_number'] ?? '');
        $studentMobile = ($studentMobileRaw !== '') ? normalizeTeacherMobile10($studentMobileRaw) : '';
        $studentEmail = strtolower(trim($_POST['email'] ?? ''));
        $initialPassword = $_POST['password'] ?? '';

        $invalidCreate = in_array(true, [
            $studentUsername === '',
            $studentName === '',
            ($studentMobile !== '' && preg_match('/^[6-9][0-9]{9}$/', $studentMobile) !== 1),
            ($studentEmail !== '' && filter_var($studentEmail, FILTER_VALIDATE_EMAIL) === false),
            strlen($initialPassword) < 6,
        ], true);

        if ($invalidCreate) {
            $error = 'Enter USN/Username, Full Name, optional valid 10-digit Mobile, optional valid Email, and an initial password (min 6 chars).';
        } else {
            $check = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(username)) = LOWER(:username) LIMIT 1');
            $check->execute([':username' => $studentUsername]);

            if ($check->fetch()) {
                $error = 'Username/USN "' . htmlspecialchars($studentUsername, ENT_QUOTES, 'UTF-8') . '" already exists.';
            } else {
                $hash = password_hash($initialPassword, PASSWORD_DEFAULT);
                $insert = $pdo->prepare(
                    'INSERT INTO users (username, password_hash, role, course, full_name, mobile_number, email)
                     VALUES (:username, :password_hash, \'student\', :course, :full_name, :mobile_number, :email)'
                );
                $insert->execute([
                    ':username' => $studentUsername,
                    ':password_hash' => $hash,
                    ':course' => $teacherCourse,
                    ':full_name' => $studentName,
                    ':mobile_number' => ($studentMobile !== '') ? $studentMobile : null,
                    ':email' => ($studentEmail !== '') ? $studentEmail : null,
                ]);
                $message = 'Added student ' . htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($studentUsername, ENT_QUOTES, 'UTF-8') . ') to ' . htmlspecialchars($teacherCourse, ENT_QUOTES, 'UTF-8') . '.';
            }
        }
    } elseif ($action === 'update_student') {
        $activeTab = 'students';
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $updatedUsn = strtoupper(trim($_POST['username'] ?? ''));
        $updatedName = trim($_POST['full_name'] ?? '');
        $updatedMobileRaw = trim($_POST['mobile_number'] ?? '');
        $updatedMobile = ($updatedMobileRaw !== '') ? normalizeTeacherMobile10($updatedMobileRaw) : '';
        $updatedEmail = strtolower(trim($_POST['email'] ?? ''));
        $newPassword = $_POST['new_password'] ?? '';

        $targetStudentCheck = $pdo->prepare(
            'SELECT id, username, course FROM users WHERE id = :id LIMIT 1'
        );
        $targetStudentCheck->execute([':id' => $studentId]);
        $targetStudentRow = $targetStudentCheck->fetch();
        $targetStudentCourse = strtoupper(trim((string) ($targetStudentRow['course'] ?? '')));

        if (!$targetStudentRow || ($targetStudentCourse !== '' && $targetStudentCourse !== $teacherCourse)) {
            http_response_code(403);
            $error = 'Access denied: You can only edit students in your own department (' . htmlspecialchars($teacherCourse, ENT_QUOTES, 'UTF-8') . ').';
            $editStudentId = 0;
        } else {
            $invalidUpdate = in_array(true, [
                $studentId <= 0,
                $updatedUsn === '',
                $updatedName === '',
                ($updatedMobile !== '' && preg_match('/^[6-9][0-9]{9}$/', $updatedMobile) !== 1),
                ($updatedEmail !== '' && filter_var($updatedEmail, FILTER_VALIDATE_EMAIL) === false),
                ($newPassword !== '' && strlen($newPassword) < 6),
            ], true);

            if ($invalidUpdate) {
                $error = 'Enter a valid USN, Student Name, optional 10-digit Mobile, optional valid Email, and password of at least 6 characters (if changing).';
                $editStudentId = $studentId;
            } else {
                $dupCheck = $pdo->prepare(
                    'SELECT id FROM users WHERE LOWER(TRIM(username)) = LOWER(:username) AND id <> :id LIMIT 1'
                );
                $dupCheck->execute([
                    ':username' => $updatedUsn,
                    ':id' => $studentId,
                ]);

                if ($dupCheck->fetch()) {
                    $error = 'Another user already uses USN/Username "' . htmlspecialchars($updatedUsn, ENT_QUOTES, 'UTF-8') . '".';
                    $editStudentId = $studentId;
                } else {
                    $oldUsn = (string) $targetStudentRow['username'];

                    if ($newPassword !== '') {
                        $stmt = $pdo->prepare(
                            'UPDATE users
                             SET username = :username, course = :course, full_name = :full_name, mobile_number = :mobile_number, email = :email, role = \'student\', password_hash = :password_hash
                             WHERE id = :id'
                        );
                        $stmt->execute([
                            ':username' => $updatedUsn,
                            ':course' => $teacherCourse,
                            ':full_name' => $updatedName,
                            ':mobile_number' => ($updatedMobile !== '') ? $updatedMobile : null,
                            ':email' => ($updatedEmail !== '') ? $updatedEmail : null,
                            ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                            ':id' => $studentId,
                        ]);
                    } else {
                        $stmt = $pdo->prepare(
                            'UPDATE users
                             SET username = :username, course = :course, full_name = :full_name, mobile_number = :mobile_number, email = :email, role = \'student\'
                             WHERE id = :id'
                        );
                        $stmt->execute([
                            ':username' => $updatedUsn,
                            ':course' => $teacherCourse,
                            ':full_name' => $updatedName,
                            ':mobile_number' => ($updatedMobile !== '') ? $updatedMobile : null,
                            ':email' => ($updatedEmail !== '') ? $updatedEmail : null,
                            ':id' => $studentId,
                        ]);
                    }

                    if (strtoupper($oldUsn) !== $updatedUsn) {
                        $syncAcad = $pdo->prepare(
                            'UPDATE student_academics SET student_username = :new_usn WHERE UPPER(TRIM(student_username)) = UPPER(TRIM(:old_usn)) AND UPPER(TRIM(course)) = :course'
                        );
                        $syncAcad->execute([
                            ':new_usn' => $updatedUsn,
                            ':old_usn' => $oldUsn,
                            ':course' => $teacherCourse,
                        ]);
                    }

                    $editStudentId = 0;
                    $message = 'Updated student ' . htmlspecialchars($updatedUsn, ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($teacherCourse, ENT_QUOTES, 'UTF-8') . ').';
                }
            }
        }
    } elseif ($action === 'add_academic') {
        $activeTab = 'add_academic';
        $targetUsn = strtoupper(trim($_POST['student_username'] ?? ''));
        $chosenSemester = normalizeTeacherSemester((string) ($_POST['semester'] ?? 'I'), $semesterOptions);
        $rawSubject = trim($_POST['subject_name'] ?? '');
        $canonicalSubject = isSubjectAllowed($rawSubject, $assignedSubjects);
        $seMarks = (float) ($_POST['se_marks'] ?? -1);
        $iaMarks = (float) ($_POST['ia_marks'] ?? -1);
        $maxMarks = (float) ($_POST['max_marks'] ?? 100);
        $classesAttended = (int) ($_POST['classes_attended'] ?? -1);
        $totalClasses = (int) ($_POST['total_classes'] ?? -1);

        $marksScored = round($seMarks + $iaMarks, 2);
        $minMarks = round($maxMarks * 0.40, 2);

        if ($canonicalSubject === null) {
            http_response_code(403);
            $error = 'Access denied: Subject "' . htmlspecialchars($rawSubject, ENT_QUOTES, 'UTF-8') . '" is not assigned to your account (' . htmlspecialchars(implode(', ', $assignedSubjects), ENT_QUOTES, 'UTF-8') . ').';
        } else {
            $invalidAddAcad = in_array(true, [
                $targetUsn === '',
                $seMarks < 0,
                $iaMarks < 0,
                $maxMarks <= 0,
                $marksScored > $maxMarks,
                $classesAttended < 0,
                $totalClasses <= 0,
                $classesAttended > $totalClasses,
            ], true);

            if ($invalidAddAcad) {
                $error = 'Enter valid Student USN, Semester, SE Marks, IA Marks (total SE + IA cannot exceed Max Marks), and Attendance (0 to total classes).';
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
                    ':course' => $teacherCourse,
                    ':semester' => $chosenSemester,
                    ':subject_name' => $canonicalSubject,
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
                $message = 'Saved ' . htmlspecialchars($targetUsn, ENT_QUOTES, 'UTF-8') . ' (Sem ' . htmlspecialchars($chosenSemester, ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars($canonicalSubject, ENT_QUOTES, 'UTF-8') . ') — Scored: ' . $marksScored . '/' . $maxMarks . ' (' . $resultStatus . ').';
            }
        }
    } elseif ($action === 'update_academic') {
        $recordId = (int) ($_POST['record_id'] ?? 0);
        $chosenSemester = normalizeTeacherSemester((string) ($_POST['semester'] ?? 'I'), $semesterOptions);
        $rawSubject = trim($_POST['subject_name'] ?? '');
        $canonicalSubject = isSubjectAllowed($rawSubject, $assignedSubjects);
        $seMarks = (float) ($_POST['se_marks'] ?? -1);
        $iaMarks = (float) ($_POST['ia_marks'] ?? -1);
        $maxMarks = (float) ($_POST['max_marks'] ?? 100);
        $classesAttended = (int) ($_POST['classes_attended'] ?? -1);
        $totalClasses = (int) ($_POST['total_classes'] ?? -1);

        $marksScored = round($seMarks + $iaMarks, 2);
        $minMarks = round($maxMarks * 0.40, 2);

        $recCheck = $pdo->prepare('SELECT id, course, subject_name FROM student_academics WHERE id = :id LIMIT 1');
        $recCheck->execute([':id' => $recordId]);
        $recRow = $recCheck->fetch();
        $recCourse = strtoupper(trim((string) ($recRow['course'] ?? $teacherCourse)));

        if (!$recRow || ($recCourse !== '' && $recCourse !== $teacherCourse)) {
            http_response_code(403);
            $error = 'Access denied: You can only edit marks & attendance for your own department (' . htmlspecialchars($teacherCourse, ENT_QUOTES, 'UTF-8') . ').';
            $editId = 0;
            $activeTab = 'records';
        } elseif ($canonicalSubject === null) {
            http_response_code(403);
            $error = 'Access denied: You can only set subjects assigned to your account (' . htmlspecialchars(implode(', ', $assignedSubjects), ENT_QUOTES, 'UTF-8') . ').';
            $editId = $recordId;
            $activeTab = 'edit_academic';
        } else {
            $invalidUpdAcad = in_array(true, [
                $recordId <= 0,
                $seMarks < 0,
                $iaMarks < 0,
                $maxMarks <= 0,
                $marksScored > $maxMarks,
                $classesAttended < 0,
                $totalClasses <= 0,
                $classesAttended > $totalClasses,
            ], true);

            if ($invalidUpdAcad) {
                $error = 'Enter valid Semester, SE Marks, IA Marks (total SE + IA cannot exceed Max Marks), and Attendance (0 to total classes) to save.';
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
                    ':course' => $teacherCourse,
                    ':semester' => $chosenSemester,
                    ':subject_name' => $canonicalSubject,
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
                $message = 'Updated record #' . $recordId . ' (Sem ' . htmlspecialchars($chosenSemester, ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars($canonicalSubject, ENT_QUOTES, 'UTF-8') . ') — Scored: ' . $marksScored . '/' . $maxMarks . ' (' . $resultStatus . ').';
            }
        }
    } elseif ($action === 'delete_academic') {
        $activeTab = 'records';
        $recordId = (int) ($_POST['record_id'] ?? 0);
        if ($recordId > 0) {
            $delStmt = $pdo->prepare(
                'DELETE FROM student_academics WHERE id = :id AND UPPER(TRIM(course)) = :course'
            );
            $delStmt->execute([
                ':id' => $recordId,
                ':course' => $teacherCourse,
            ]);
            if ($delStmt->rowCount() > 0) {
                if ($editId === $recordId) {
                    $editId = 0;
                }
                $message = 'Deleted marks & attendance record #' . $recordId . '.';
            } else {
                $error = 'Record not found or belongs to another department.';
            }
        }
    }
}

$sqlStudents = 'SELECT id, username, full_name, role, course, mobile_number, email
                FROM users
                WHERE (LOWER(TRIM(role)) IN (\'student\', \'students\', \'stu\')
                   OR LOWER(TRIM(role)) LIKE \'%stud%\'
                   OR UPPER(TRIM(username)) LIKE \'U21ME%\')
                  AND UPPER(TRIM(COALESCE(course, \'\'))) = :course';
$paramsStudents = [':course' => $teacherCourse];
if ($studentSearch !== '') {
    $sqlStudents .= ' AND (LOWER(TRIM(username)) LIKE :sq
                      OR LOWER(TRIM(COALESCE(full_name, \'\'))) LIKE :sq
                      OR LOWER(TRIM(COALESCE(mobile_number, \'\'))) LIKE :sq
                      OR LOWER(TRIM(COALESCE(email, \'\'))) LIKE :sq)';
    $paramsStudents[':sq'] = '%' . strtolower($studentSearch) . '%';
}
$sqlStudents .= ' ORDER BY username ASC';
$studentsStmt = $pdo->prepare($sqlStudents);
$studentsStmt->execute($paramsStudents);
$students = $studentsStmt->fetchAll();

$editingStudent = null;
if ($activeTab === 'students' && $editStudentId > 0) {
    $esStmt = $pdo->prepare(
        'SELECT id, username, full_name, role, course, mobile_number, email
         FROM users
         WHERE id = :id AND UPPER(TRIM(COALESCE(course, \'\'))) = :course
         LIMIT 1'
    );
    $esStmt->execute([
        ':id' => $editStudentId,
        ':course' => $teacherCourse,
    ]);
    $editingStudent = $esStmt->fetch() ?: null;
}

$sqlAcademics = 'SELECT id, student_username, course, semester, subject_name, se_marks, ia_marks, marks_obtained, max_marks, min_marks, result_status, classes_attended, total_classes, attendance_pct, updated_by
                 FROM student_academics
                 WHERE UPPER(TRIM(course)) = :course';
$paramsAcademics = [':course' => $teacherCourse];
if ($recordSemFilter !== 'ALL') {
    $sqlAcademics .= ' AND UPPER(TRIM(COALESCE(semester, \'I\'))) = :rsem';
    $paramsAcademics[':rsem'] = $recordSemFilter;
}
if ($recordSearch !== '') {
    $sqlAcademics .= ' AND (LOWER(TRIM(student_username)) LIKE :rq
                       OR LOWER(TRIM(COALESCE(subject_name, \'\'))) LIKE :rq
                       OR LOWER(TRIM(COALESCE(result_status, \'\'))) LIKE :rq)';
    $paramsAcademics[':rq'] = '%' . strtolower($recordSearch) . '%';
}
$sqlAcademics .= ' ORDER BY id DESC';
$academicsStmt = $pdo->prepare($sqlAcademics);
$academicsStmt->execute($paramsAcademics);
$academics = $academicsStmt->fetchAll();

$editingRecord = null;
if ($activeTab === 'edit_academic' && $editId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT id, student_username, course, semester, subject_name, se_marks, ia_marks, marks_obtained, max_marks, min_marks, result_status, classes_attended, total_classes, attendance_pct
         FROM student_academics
         WHERE id = :id AND UPPER(TRIM(course)) = :course
         LIMIT 1'
    );
    $editStmt->execute([
        ':id' => $editId,
        ':course' => $teacherCourse,
    ]);
    $editingRecord = $editStmt->fetch() ?: null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher — Students, Marks & Attendance — PES College Portal</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .section-selector {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 16px;
        }
        .section-tab {
            display: inline-block;
            padding: 10px 16px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #1e293b;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
        }
        .section-tab.active {
            background: #0b3c78;
            color: #ffffff;
            border-color: #0b3c78;
        }
        .scope-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 18px;
        }
        .scope-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 999px;
            background: #eff6ff;
            color: #0b3c78;
            border: 1px solid #bfdbfe;
            font-size: 13px;
            font-weight: 600;
        }
        .scope-badge.subj {
            background: #f0fdfa;
            color: #0f766e;
            border-color: #99f6e4;
        }
        .subject-select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            background: #ffffff;
            color: #1e293b;
        }
        .auto-calc-box {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 14px;
            font-size: 13px;
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: center;
        }
        .badge-pass {
            color: #15803d;
            font-weight: 700;
        }
        .badge-fail {
            color: #b91c1c;
            font-weight: 700;
        }
        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            margin: 12px 0;
        }
        .filter-input {
            flex: 1 1 220px;
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 13px;
            background: #ffffff;
            color: #0f172a;
        }
        .filter-select {
            padding: 8px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 13px;
            background: #ffffff;
            color: #0f172a;
        }
        .filter-btn {
            background: #0b3c78;
            color: #ffffff;
            border: none;
            padding: 8px 14px;
            border-radius: 6px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
        }
        .clear-btn {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 6px;
            background: #e2e8f0;
            color: #1e293b;
            text-decoration: none;
            font-size: 12.5px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="portal-wrapper dashboard-wrapper">
        <div class="dashboard-topbar">
            <div>
                <h1>Teacher — Students, Marks & Attendance</h1>
                <p>Welcome, <?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars(strtoupper($role), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?> · Department: <?php echo htmlspecialchars($teacherCourse, ENT_QUOTES, 'UTF-8'); ?>)</p>
            </div>
            <div>
                <a href="dashboard.php" class="logout-btn" style="background-color:#0b3c78; margin-right:8px;">Dashboard</a>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>

        <div class="scope-bar">
            <span class="scope-badge">Department: <?php echo htmlspecialchars($teacherCourse, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="scope-badge subj">Assigned Subjects: <?php echo htmlspecialchars(implode(', ', $assignedSubjects), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>

        <div class="section-selector">
            <a href="teacher.php?tab=students" class="section-tab <?php echo ($activeTab === 'students') ? 'active' : ''; ?>">1. Student Details (<?php echo count($students); ?>)</a>
            <a href="teacher.php?tab=add_academic" class="section-tab <?php echo ($activeTab === 'add_academic') ? 'active' : ''; ?>">2. Add Marks & Attendance</a>
            <a href="teacher.php?tab=records" class="section-tab <?php echo ($activeTab === 'records') ? 'active' : ''; ?>">3. All Marks & Attendance (<?php echo count($academics); ?>)</a>
            <?php if ($activeTab === 'edit_academic'): ?>
                <span class="section-tab active">4. Edit Marks & Attendance</span>
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

        <?php if ($activeTab === 'students'): ?>
            <div class="dash-card" style="margin-bottom:24px;">
                <h2><?php echo $editingStudent ? 'Edit Student Details (' . htmlspecialchars($teacherCourse, ENT_QUOTES, 'UTF-8') . ')' : 'Add New Student (' . htmlspecialchars($teacherCourse, ENT_QUOTES, 'UTF-8') . ')'; ?></h2>
                <?php if ($editingStudent): ?>
                    <form method="POST" action="teacher.php?tab=students">
                        <input type="hidden" name="action" value="update_student">
                        <input type="hidden" name="student_id" value="<?php echo (int) $editingStudent['id']; ?>">
                        <div class="input-group">
                            <label for="edit_stu_usn">Student USN / Username</label>
                            <input type="text" id="edit_stu_usn" name="username" value="<?php echo htmlspecialchars((string) $editingStudent['username'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="input-group">
                            <label>Department / Course</label>
                            <input type="text" value="<?php echo htmlspecialchars($teacherCourse, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                        </div>
                        <div class="input-group">
                            <label for="edit_stu_name">Student Full Name</label>
                            <input type="text" id="edit_stu_name" name="full_name" value="<?php echo htmlspecialchars((string) $editingStudent['full_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="input-group">
                            <label for="edit_stu_mobile">Registered Mobile Number (10 digits)</label>
                            <input type="text" id="edit_stu_mobile" name="mobile_number" placeholder="e.g. 9876543210" value="<?php echo htmlspecialchars((string) ($editingStudent['mobile_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="input-group">
                            <label for="edit_stu_email">Email Address</label>
                            <input type="email" id="edit_stu_email" name="email" placeholder="e.g. student@example.com" value="<?php echo htmlspecialchars((string) ($editingStudent['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="input-group">
                            <label for="edit_stu_pass">New Password (Optional, min 6 chars)</label>
                            <input type="password" id="edit_stu_pass" name="new_password" minlength="6" placeholder="Leave blank to keep current password">
                        </div>
                        <button type="submit" class="login-btn">Save Student Changes</button>
                        <a href="teacher.php?tab=students" class="logout-btn" style="display:inline-block; margin-top:10px; background-color:#64748b;">Cancel Edit</a>
                    </form>
                <?php else: ?>
                    <form method="POST" action="teacher.php?tab=students">
                        <input type="hidden" name="action" value="add_student">
                        <div class="input-group">
                            <label for="username">Student USN / Username</label>
                            <input type="text" id="username" name="username" placeholder="e.g. U21ME24S0017" required>
                        </div>
                        <div class="input-group">
                            <label>Department / Course</label>
                            <input type="text" value="<?php echo htmlspecialchars($teacherCourse, ENT_QUOTES, 'UTF-8'); ?>" disabled>
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
                <h2><?php echo htmlspecialchars($teacherCourse, ENT_QUOTES, 'UTF-8'); ?> Student Details</h2>
                <form method="GET" action="teacher.php" class="filter-bar">
                    <input type="hidden" name="tab" value="students">
                    <input type="text" name="s_q" class="filter-input" value="<?php echo htmlspecialchars($studentSearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search students by USN, name, mobile, or email...">
                    <button type="submit" class="filter-btn">Search</button>
                    <?php if ($studentSearch !== ''): ?>
                        <a href="teacher.php?tab=students" class="clear-btn">Clear</a>
                    <?php endif; ?>
                </form>

                <?php if (empty($students)): ?>
                    <p>No matching student accounts found in <?php echo htmlspecialchars($teacherCourse, ENT_QUOTES, 'UTF-8'); ?>.</p>
                <?php else: ?>
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
                                <?php $stUsn = (string) $st['username']; ?>
                                <tr style="border-bottom:1px solid #eef2f6;">
                                    <td style="padding:10px;"><strong><?php echo htmlspecialchars($stUsn, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td style="padding:10px;"><strong><?php echo htmlspecialchars($teacherCourse, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td style="padding:10px;"><?php echo htmlspecialchars((string) ($st['full_name'] ?? $stUsn), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px;"><?php echo htmlspecialchars((string) ($st['mobile_number'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px;"><?php echo htmlspecialchars((string) ($st['email'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px; white-space:nowrap;">
                                        <a href="teacher.php?tab=students&amp;edit_student_id=<?php echo (int) $st['id']; ?>" style="display:inline-block; background:#0b3c78; color:#fff; padding:5px 10px; border-radius:4px; text-decoration:none; font-size:12px; margin-right:6px;">Edit Student</a>
                                        <a href="teacher.php?tab=add_academic&amp;usn=<?php echo urlencode($stUsn); ?>" style="display:inline-block; background:#0f766e; color:#fff; padding:5px 10px; border-radius:4px; text-decoration:none; font-size:12px;">Marks & Attendance</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php elseif ($activeTab === 'add_academic'): ?>
            <div class="dash-card">
                <h2>Add Marks & Attendance (<?php echo htmlspecialchars($teacherCourse, ENT_QUOTES, 'UTF-8'); ?>)</h2>
                <form method="POST" action="teacher.php?tab=add_academic" id="addMarksForm">
                    <input type="hidden" name="action" value="add_academic">
                    <div class="input-group">
                        <label for="add_student_username">Student USN / Username</label>
                        <input type="text" id="add_student_username" name="student_username" value="<?php echo htmlspecialchars($prefillUsn, ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. U21ME24S0016" required>
                    </div>
                    <div class="input-group">
                        <label>Department / Course</label>
                        <input type="text" value="<?php echo htmlspecialchars($teacherCourse, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                    </div>
                    <div class="input-group">
                        <label for="add_semester">Semester</label>
                        <select id="add_semester" name="semester" class="subject-select" required>
                            <?php foreach ($semesterOptions as $semOpt): ?>
                                <option value="<?php echo htmlspecialchars($semOpt, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($semOpt, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group">
                        <label for="add_subject_name">Assigned Subject</label>
                        <select id="add_subject_name" name="subject_name" class="subject-select" required>
                            <?php foreach ($assignedSubjects as $subOpt): ?>
                                <option value="<?php echo htmlspecialchars($subOpt, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($subOpt, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
                    <div class="auto-calc-box" id="addAutoCalcBox">
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
                <h2>Edit Marks & Attendance (<?php echo htmlspecialchars($teacherCourse, ENT_QUOTES, 'UTF-8'); ?>)</h2>
                <?php if (!$editingRecord): ?>
                    <p>Record not found in <?php echo htmlspecialchars($teacherCourse, ENT_QUOTES, 'UTF-8'); ?>. <a href="teacher.php?tab=records" style="color:#0b3c78; font-weight:600;">Back to All Marks & Attendance</a></p>
                <?php else: ?>
                    <?php
                    $currentSem = normalizeTeacherSemester((string) ($editingRecord['semester'] ?? 'I'), $semesterOptions);
                    $currentSubj = (string) $editingRecord['subject_name'];
                    $matchedSubj = isSubjectAllowed($currentSubj, $assignedSubjects);
                    $editSubjectChoices = $assignedSubjects;
                    if ($matchedSubj === null && trim($currentSubj) !== '') {
                        $editSubjectChoices[] = $currentSubj;
                    }
                    $curScored = (float) ($editingRecord['marks_obtained'] ?? 0);
                    $curMax = (float) ($editingRecord['max_marks'] ?? 100);
                    $curSe = isset($editingRecord['se_marks']) ? (float) $editingRecord['se_marks'] : round($curScored * 0.75, 2);
                    $curIa = isset($editingRecord['ia_marks']) ? (float) $editingRecord['ia_marks'] : round($curScored - $curSe, 2);
                    $curMin = isset($editingRecord['min_marks']) ? (float) $editingRecord['min_marks'] : round($curMax * 0.40, 2);
                    $curStatus = ($curScored >= $curMin) ? 'Pass' : 'Fail';
                    ?>
                    <form method="POST" action="teacher.php?tab=edit_academic&amp;edit_id=<?php echo (int) $editingRecord['id']; ?>" id="editMarksForm">
                        <input type="hidden" name="action" value="update_academic">
                        <input type="hidden" name="record_id" value="<?php echo (int) $editingRecord['id']; ?>">
                        <div class="input-group">
                            <label>Student USN / Username</label>
                            <input type="text" value="<?php echo htmlspecialchars((string) $editingRecord['student_username'], ENT_QUOTES, 'UTF-8'); ?>" disabled>
                        </div>
                        <div class="input-group">
                            <label>Department / Course</label>
                            <input type="text" value="<?php echo htmlspecialchars($teacherCourse, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                        </div>
                        <div class="input-group">
                            <label for="edit_semester">Semester</label>
                            <select id="edit_semester" name="semester" class="subject-select" required>
                                <?php foreach ($semesterOptions as $semOpt): ?>
                                    <option value="<?php echo htmlspecialchars($semOpt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($currentSem === $semOpt) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($semOpt, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label for="edit_subject_name">Assigned Subject</label>
                            <select id="edit_subject_name" name="subject_name" class="subject-select" required>
                                <?php foreach ($editSubjectChoices as $subOpt): ?>
                                    <?php $isSelected = (strtolower(trim($subOpt)) === strtolower(trim($currentSubj))); ?>
                                    <option value="<?php echo htmlspecialchars($subOpt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subOpt, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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
                        <div class="auto-calc-box" id="editAutoCalcBox">
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
                        <a href="teacher.php?tab=records" class="logout-btn" style="display:inline-block; margin-top:10px; background-color:#64748b;">Back to Records</a>
                    </form>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="dash-card">
                <h2><?php echo htmlspecialchars($teacherCourse, ENT_QUOTES, 'UTF-8'); ?> Student Marks & Attendance</h2>
                <form method="GET" action="teacher.php" class="filter-bar">
                    <input type="hidden" name="tab" value="records">
                    <select name="r_sem" class="filter-select">
                        <option value="ALL" <?php echo ($recordSemFilter === 'ALL') ? 'selected' : ''; ?>>All Semesters</option>
                        <?php foreach ($semesterOptions as $semOpt): ?>
                            <option value="<?php echo htmlspecialchars($semOpt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($recordSemFilter === $semOpt) ? 'selected' : ''; ?>>
                                Sem <?php echo htmlspecialchars($semOpt, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="r_q" class="filter-input" value="<?php echo htmlspecialchars($recordSearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search by USN, subject, or status...">
                    <button type="submit" class="filter-btn">Filter</button>
                    <?php if ($recordSemFilter !== 'ALL' || $recordSearch !== ''): ?>
                        <a href="teacher.php?tab=records" class="clear-btn">Clear</a>
                    <?php endif; ?>
                </form>

                <?php if (empty($academics)): ?>
                    <p>No student marks or attendance records match this filter for <?php echo htmlspecialchars($teacherCourse, ENT_QUOTES, 'UTF-8'); ?>.</p>
                <?php else: ?>
                    <table style="width:100%; border-collapse:collapse; margin-top:12px; font-size:14px;">
                        <thead>
                            <tr style="background:#f8fafc; text-align:left; border-bottom:2px solid #dce3ec;">
                                <th style="padding:10px;">Student USN</th>
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
                                $rowSem = normalizeTeacherSemester((string) ($ac['semester'] ?? 'I'), $semesterOptions);
                                $rowScored = (float) ($ac['marks_obtained'] ?? 0);
                                $rowMax = (float) ($ac['max_marks'] ?? 100);
                                $rowMin = isset($ac['min_marks']) ? (float) $ac['min_marks'] : round($rowMax * 0.40, 2);
                                $rowSe = isset($ac['se_marks']) ? (float) $ac['se_marks'] : round($rowScored * 0.75, 2);
                                $rowIa = isset($ac['ia_marks']) ? (float) $ac['ia_marks'] : round($rowScored - $rowSe, 2);
                                $rowStatus = ($rowScored >= $rowMin) ? 'Pass' : 'Fail';
                                ?>
                                <tr style="border-bottom:1px solid #eef2f6;">
                                    <td style="padding:10px;"><strong><?php echo htmlspecialchars((string) $ac['student_username'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td style="padding:10px;"><strong><?php echo htmlspecialchars($rowSem, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td style="padding:10px;"><?php echo htmlspecialchars((string) $ac['subject_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px;"><?php echo htmlspecialchars((string) $rowSe, ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string) $rowIa, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px;"><strong><?php echo htmlspecialchars((string) $rowScored, ENT_QUOTES, 'UTF-8'); ?></strong> / <?php echo htmlspecialchars((string) $rowMax, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px;"><span class="<?php echo ($rowStatus === 'Pass') ? 'badge-pass' : 'badge-fail'; ?>"><?php echo htmlspecialchars($rowStatus, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td style="padding:10px;"><?php echo htmlspecialchars((string) $ac['classes_attended'], ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string) $ac['total_classes'], ENT_QUOTES, 'UTF-8'); ?> (<strong><?php echo htmlspecialchars((string) $ac['attendance_pct'], ENT_QUOTES, 'UTF-8'); ?>%</strong>)</td>
                                    <td style="padding:10px; white-space:nowrap;">
                                        <a href="teacher.php?tab=edit_academic&amp;edit_id=<?php echo $rowId; ?>" style="display:inline-block; background:#0b3c78; color:#fff; padding:5px 10px; border-radius:4px; text-decoration:none; font-size:12px; margin-right:6px;">Edit</a>
                                        <form method="POST" action="teacher.php?tab=records" style="display:inline;" onsubmit="return confirm('Delete this marks and attendance record?');">
                                            <input type="hidden" name="action" value="delete_academic">
                                            <input type="hidden" name="record_id" value="<?php echo $rowId; ?>">
                                            <button type="submit" style="background:#c62828; color:#fff; border:none; padding:5px 10px; border-radius:4px; cursor:pointer; font-size:12px;">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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