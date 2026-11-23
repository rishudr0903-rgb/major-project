<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'], $_SESSION['role'],$_SESSION['username'])) {
    header('Location: index.php?error=' . urlencode('Please log in first.'));
    exit;
}

$role = $_SESSION['role'];$username = $_SESSION['username'];$fullName = $_SESSION['full_name'] ?? $username;
$message = '';
$error = '';$editRecord = null;
$filterUsn = $role === 'admin' ? trim($_GET['filter_usn'] ?? '') : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($role !== 'admin') {
        http_response_code(403);
        die('Access denied: Students can only view attendance records.');
    }

    $action =$_POST['action'] ?? 'save';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {$stmt = $pdo->prepare('DELETE FROM attendance_records WHERE id = :id');$stmt->execute([':id' => $id]);$message = 'Attendance record deleted.';
        } else {
            $error = 'Invalid attendance record ID.';
        }
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $targetStudent = trim($_POST['student_username'] ?? '');
        $subjectName = trim($_POST['subject_name'] ?? '');
        $classesHeld = (int) ($_POST['classes_held'] ?? 0);
        $classesAttended = (int) ($_POST['classes_attended'] ?? 0);

        if ($targetStudent === '' || $subjectName === '' || $classesHeld < 0 || $classesAttended < 0 || $classesAttended > $classesHeld) {
        } elseif ($id > 0) {$stmt = $pdo->prepare( 'UPDATE attendance_records SET student_username = :student_username, subject_name = :subject_name, classes_held = :classes_held, classes_attended = :classes_attended WHERE id = :id' );$stmt->execute([
                ':id' => $id,
                ':student_username' => $targetStudent,
                ':subject_name' => $subjectName,
                ':classes_held' => $classesHeld,
                ':classes_attended' => $classesAttended,
            ]);
            $message = 'Attendance record updated for ' . htmlspecialchars($targetStudent, ENT_QUOTES, 'UTF-8') . '.'; } else {$stmt = $pdo->prepare( 'INSERT INTO attendance_records (student_username, subject_name, classes_held, classes_attended) VALUES (:student_username, :subject_name, :classes_held, :classes_attended)' );$stmt->execute([
                ':student_username' => $targetStudent,
                ':subject_name' => $subjectName,
                ':classes_held' => $classesHeld,
                ':classes_attended' => $classesAttended,
            ]);
            $message = 'Attendance record saved for ' . htmlspecialchars($targetStudent, ENT_QUOTES, 'UTF-8') . '.';
        }
    }
}

if ($role === 'admin' && isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    if ($editId > 0) {
        $stmt =$pdo->prepare('SELECT id, student_username, subject_name, classes_held, classes_attended FROM attendance_records WHERE id = :id');
        $stmt->execute([':id' =>$editId]);
        $editRecord =$stmt->fetch() ?: null;
    }
}

if ($role === 'admin') {
    if ($filterUsn !== '') {
        $stmt =$pdo->prepare(
            'SELECT id, student_username, subject_name, classes_held, classes_attended
             FROM attendance_records
             WHERE student_username LIKE :usn
             ORDER BY id DESC'
        );
        $stmt->execute([':usn' => '\%' .$filterUsn . '%']);
        $records =$stmt->fetchAll();
    } else {
        $stmt =$pdo->query('SELECT id, student_username, subject_name, classes_held, classes_attended FROM attendance_records ORDER BY id DESC');
        $records =$stmt->fetchAll();
    }
} else {
    $stmt =$pdo->prepare('SELECT id, student_username, subject_name, classes_held, classes_attended FROM attendance_records WHERE student_username = :username ORDER BY id DESC');
    $stmt->execute([':username' =>$username]);
    $records =$stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance — PES College Portal</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="portal-wrapper dashboard-wrapper">
        <div class="dashboard-topbar">
            <div>
                <h1>Attendance</h1>
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
                <h2><?php echo $editRecord ? 'Edit Attendance Record (Admin Only)' : 'Add Attendance Record (Admin Only)'; ?></h2>
                <form method="POST" action="attendance.php<?php echo $filterUsn !== '' ? '?filter_usn=' . urlencode($filterUsn) : ''; ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo (int) ($editRecord['id'] ?? 0); ?>">
                    <div class="input-group">
                        <label for="student_username">Student USN</label>
                        <input type="text" id="student_username" name="student_username" placeholder="e.g. U21ME24S0014" value="<?php echo htmlspecialchars($editRecord['student_username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="input-group">
                        <label for="subject_name">Subject Name</label>
                        <input type="text" id="subject_name" name="subject_name" placeholder="e.g. Thermodynamics" value="<?php echo htmlspecialchars($editRecord['subject_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="input-group">
                        <label for="classes_held">Classes Held</label>
                        <input type="number" id="classes_held" name="classes_held" min="0" value="<?php echo (int) ($editRecord['classes_held'] ?? 0); ?>" required>
                    </div>
                    <div class="input-group">
                        <label for="classes_attended">Classes Attended</label>
                        <input type="number" id="classes_attended" name="classes_attended" min="0" value="<?php echo (int) ($editRecord['classes_attended'] ?? 0); ?>" required>
                    </div>
                    <button type="submit" class="login-btn"><?php echo $editRecord ? 'Update Attendance' : 'Save Attendance'; ?></button>
                    <?php if ($editRecord): ?>
                        <a href="attendance.php" class="logout-btn" style="display:inline-block; margin-top:10px; background-color:#64748b;">Cancel Edit</a>
                    <?php endif; ?>
                </form>
            </div>
        <?php endif; ?>

        <div class="dash-card">
            <h2><?php echo $role === 'admin' ? 'All Attendance Records' : 'My Subject Attendance (View Only)'; ?></h2>

            <?php if ($role === 'admin'): ?>
                <form method="GET" action="attendance.php" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:10px; margin-bottom:14px;">
                    <input type="text" name="filter_usn" placeholder="Filter by Student USN (e.g. U21ME24S0014)" value="<?php echo htmlspecialchars($filterUsn, ENT_QUOTES, 'UTF-8'); ?>" style="padding:8px 12px; border:1px solid #dce3ec; border-radius:6px; min-width:220px;">
                    <button type="submit" class="login-btn" style="width:auto; padding:8px 16px;">Filter</button>
                    <?php if ($filterUsn !== ''): ?>
                        <a href="attendance.php" class="logout-btn" style="background-color:#64748b; padding:8px 14px;">Clear</a>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

            <?php if (empty($records)): ?>
                <p><?php echo $filterUsn !== '' ? 'No attendance records found matching "' . htmlspecialchars($filterUsn, ENT_QUOTES, 'UTF-8') . '".' : 'No attendance records found yet.'; ?></p>
            <?php else: ?>
                <table style="width:100%; border-collapse:collapse; margin-top:12px; font-size:14px;">
                    <thead>
                        <tr style="background:#f8fafc; text-align:left; border-bottom:2px solid #dce3ec;">
                            <?php if ($role === 'admin'): ?><th style="padding:10px;">Student USN</th><?php endif; ?>
                            <th style="padding:10px;">Subject</th>
                            <th style="padding:10px;">Attended / Held</th>
                            <th style="padding:10px;">Percentage</th>
                            <?php if ($role === 'admin'): ?><th style="padding:10px;">Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as$row): ?>
                            <?php
                            $held = (int)$row['classes_held'];
                            $attended = (int)$row['classes_attended'];
                            $pct =$held > 0 ? round(($attended / $held) * 100, 1) : 0;
                            ?>
                            <tr style="border-bottom:1px solid #eef2f6;">
                                <?php if ($role === 'admin'): ?><td style="padding:10px;"><?php echo htmlspecialchars($row['student_username'], ENT_QUOTES, 'UTF-8'); ?></td><?php endif; ?>
                                <td style="padding:10px;"><?php echo htmlspecialchars($row['subject_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="padding:10px;"><?php echo $attended; ?> / <?php echo$held; ?></td>
                                <td style="padding:10px;"><strong><?php echo $pct; ?>%</strong></td>
                                <?php if ($role === 'admin'): ?>
                                    <td style="padding:10px; white-space:nowrap;">
                                        <a href="attendance.php?edit=<?php echo (int) $row['id']; ?><?php echo $filterUsn !== '' ? '&filter_usn=' . urlencode($filterUsn) : ''; ?>" style="margin-right:8px; color:#0b3c78; font-weight:600; text-decoration:none;">Edit</a>
                                        <form method="POST" action="attendance.php<?php echo $filterUsn !== '' ? '?filter_usn=' . urlencode($filterUsn) : ''; ?>" style="display:inline;" onsubmit="return confirm('Delete this attendance record?');">
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