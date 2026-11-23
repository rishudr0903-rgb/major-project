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
$filterUsn = $role === 'admin' ? trim($_GET['filter_usn'] ?? '') : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($role !== 'admin') {
        http_response_code(403);
        die('Access denied: Students can only view exam results.');
    }

    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM exam_results WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $message = 'Exam result deleted.';
        } else {
            $error = 'Invalid exam result ID.';
        }
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $targetStudent = trim($_POST['student_username'] ?? '');
        $semester = trim($_POST['semester'] ?? '');
        $subjectCode = trim($_POST['subject_code'] ?? '');
        $subjectName = trim($_POST['subject_name'] ?? '');
        $internalMarks = (float) ($_POST['internal_marks'] ?? -1);
        $grade = strtoupper(trim($_POST['grade'] ?? ''));

        if (
            $targetStudent === '' ||
            $semester === '' ||
            $subjectCode === '' ||
            $subjectName === '' ||
            $grade === '' ||
            $internalMarks < 0 ||
            $internalMarks > 100
        ) {
            $error = 'Enter valid student USN, semester, subject code, subject name, grade, and internal marks (0 to 100).';
        } elseif ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE exam_results
                 SET student_username = :student_username, semester = :semester, subject_code = :subject_code,
                     subject_name = :subject_name, internal_marks = :internal_marks, grade = :grade
                 WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $id,
                ':student_username' => $targetStudent,
                ':semester' => $semester,
                ':subject_code' => $subjectCode,
                ':subject_name' => $subjectName,
                ':internal_marks' => $internalMarks,
                ':grade' => $grade,
            ]);
            $message = 'Result updated for ' . htmlspecialchars($targetStudent, ENT_QUOTES, 'UTF-8') . '.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO exam_results (student_username, semester, subject_code, subject_name, internal_marks, grade)
                 VALUES (:student_username, :semester, :subject_code, :subject_name, :internal_marks, :grade)'
            );
            $stmt->execute([
                ':student_username' => $targetStudent,
                ':semester' => $semester,
                ':subject_code' => $subjectCode,
                ':subject_name' => $subjectName,
                ':internal_marks' => $internalMarks,
                ':grade' => $grade,
            ]);
            $message = 'Result saved for ' . htmlspecialchars($targetStudent, ENT_QUOTES, 'UTF-8') . '.';
        }
    }
}

if ($role === 'admin' && isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    if ($editId > 0) {
        $stmt = $pdo->prepare(
            'SELECT id, student_username, semester, subject_code, subject_name, internal_marks, grade
             FROM exam_results WHERE id = :id'
        );
        $stmt->execute([':id' => $editId]);
        $editRecord = $stmt->fetch() ?: null;
    }
}

if ($role === 'admin') {
    if ($filterUsn !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, student_username, semester, subject_code, subject_name, internal_marks, grade
             FROM exam_results
             WHERE student_username LIKE :usn
             ORDER BY id DESC'
        );
        $stmt->execute([':usn' => '%' . $filterUsn . '%']);
        $records = $stmt->fetchAll();
    } else {
        $stmt = $pdo->query(
            'SELECT id, student_username, semester, subject_code, subject_name, internal_marks, grade
             FROM exam_results ORDER BY id DESC'
        );
        $records = $stmt->fetchAll();
    }
} else {
    $stmt = $pdo->prepare(
        'SELECT id, student_username, semester, subject_code, subject_name, internal_marks, grade
         FROM exam_results WHERE student_username = :username ORDER BY id DESC'
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
    <title>Exam Results — PES College Portal</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="portal-wrapper dashboard-wrapper">
        <div class="dashboard-topbar">
            <div>
                <h1>Exam Results & Hall Ticket</h1>
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

        <?php if ($role === 'admin'): ?>
            <div class="dash-card" style="margin-bottom:20px;">
                <h2><?php echo $editRecord ? 'Edit Exam Result (Admin Only)' : 'Add Exam Result (Admin Only)'; ?></h2>
                <form method="POST" action="results.php<?php echo $filterUsn !== '' ? '?filter_usn=' . urlencode($filterUsn) : ''; ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo (int) ($editRecord['id'] ?? 0); ?>">
                    <div class="input-group">
                        <label for="student_username">Student USN</label>
                        <input type="text" id="student_username" name="student_username" placeholder="e.g. U21ME24S0014" value="<?php echo htmlspecialchars($editRecord['student_username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="input-group">
                        <label for="semester">Semester</label>
                        <input type="text" id="semester" name="semester" placeholder="e.g. Sem 3" value="<?php echo htmlspecialchars($editRecord['semester'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="input-group">
                        <label for="subject_code">Subject Code</label>
                        <input type="text" id="subject_code" name="subject_code" placeholder="e.g. 21ME31" value="<?php echo htmlspecialchars($editRecord['subject_code'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="input-group">
                        <label for="subject_name">Subject Name</label>
                        <input type="text" id="subject_name" name="subject_name" placeholder="e.g. Manufacturing Processes" value="<?php echo htmlspecialchars($editRecord['subject_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="input-group">
                        <label for="internal_marks">Internal Marks (out of 100)</label>
                        <input type="number" step="0.01" id="internal_marks" name="internal_marks" min="0" max="100" value="<?php echo htmlspecialchars((string) ($editRecord['internal_marks'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="input-group">
                        <label for="grade">Grade</label>
                        <input type="text" id="grade" name="grade" placeholder="e.g. A+" value="<?php echo htmlspecialchars($editRecord['grade'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <button type="submit" class="login-btn"><?php echo $editRecord ? 'Update Result' : 'Save Result'; ?></button>
                    <?php if ($editRecord): ?>
                        <a href="results.php" class="logout-btn" style="display:inline-block; margin-top:10px; background-color:#64748b;">Cancel Edit</a>
                    <?php endif; ?>
                </form>
            </div>
        <?php endif; ?>

        <div class="dash-card">
            <h2><?php echo $role === 'admin' ? 'All Exam Results' : 'My Exam Results (View Only)'; ?></h2>

            <?php if ($role === 'admin'): ?>
                <form method="GET" action="results.php" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:10px; margin-bottom:14px;">
                    <input type="text" name="filter_usn" placeholder="Filter by Student USN (e.g. U21ME24S0014)" value="<?php echo htmlspecialchars($filterUsn, ENT_QUOTES, 'UTF-8'); ?>" style="padding:8px 12px; border:1px solid #dce3ec; border-radius:6px; min-width:220px;">
                    <button type="submit" class="login-btn" style="width:auto; padding:8px 16px;">Filter</button>
                    <?php if ($filterUsn !== ''): ?>
                        <a href="results.php" class="logout-btn" style="background-color:#64748b; padding:8px 14px;">Clear</a>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

            <?php if (empty($records)): ?>
                <p><?php echo $filterUsn !== '' ? 'No exam results found matching "' . htmlspecialchars($filterUsn, ENT_QUOTES, 'UTF-8') . '".' : 'No exam results published yet.'; ?></p>
            <?php else: ?>
                <table style="width:100%; border-collapse:collapse; margin-top:12px; font-size:14px;">
                    <thead>
                        <tr style="background:#f8fafc; text-align:left; border-bottom:2px solid #dce3ec;">
                            <?php if ($role === 'admin'): ?><th style="padding:10px;">Student USN</th><?php endif; ?>
                            <th style="padding:10px;">Semester</th>
                            <th style="padding:10px;">Subject</th>
                            <th style="padding:10px;">Marks</th>
                            <th style="padding:10px;">Grade</th>
                            <?php if ($role === 'admin'): ?><th style="padding:10px;">Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $row): ?>
                            <tr style="border-bottom:1px solid #eef2f6;">
                                <?php if ($role === 'admin'): ?><td style="padding:10px;"><?php echo htmlspecialchars($row['student_username'], ENT_QUOTES, 'UTF-8'); ?></td><?php endif; ?>
                                <td style="padding:10px;"><?php echo htmlspecialchars($row['semester'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="padding:10px;"><?php echo htmlspecialchars($row['subject_code'] . ' - ' . $row['subject_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="padding:10px;"><?php echo htmlspecialchars((string) $row['internal_marks'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="padding:10px;"><strong><?php echo htmlspecialchars($row['grade'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <?php if ($role === 'admin'): ?>
                                    <td style="padding:10px; white-space:nowrap;">
                                        <a href="results.php?edit=<?php echo (int) $row['id']; ?><?php echo $filterUsn !== '' ? '&filter_usn=' . urlencode($filterUsn) : ''; ?>" style="margin-right:8px; color:#0b3c78; font-weight:600; text-decoration:none;">Edit</a>
                                        <form method="POST" action="results.php<?php echo $filterUsn !== '' ? '?filter_usn=' . urlencode($filterUsn) : ''; ?>" style="display:inline;" onsubmit="return confirm('Delete this exam result?');">
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