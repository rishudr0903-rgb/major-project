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
    die('Access denied: Only Admin can access the Students & Fees Dashboard.');
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS student_fees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_username VARCHAR(100) NOT NULL UNIQUE,
        course VARCHAR(50) NOT NULL DEFAULT \'BCA\',
        total_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        paid_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        updated_by VARCHAR(100) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS student_fee_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        receipt_no VARCHAR(60) NOT NULL,
        student_username VARCHAR(100) NOT NULL,
        student_name VARCHAR(150) NOT NULL DEFAULT \'\',
        course VARCHAR(50) NOT NULL DEFAULT \'BCA\',
        total_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        previous_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        new_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        amount_change DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        balance_after DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        payment_mode VARCHAR(40) NOT NULL DEFAULT \'UPI / Cash / Bank\',
        remarks VARCHAR(255) NULL,
        recorded_by VARCHAR(100) NULL,
        recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$departmentOptions = [
    'ALL',
    'BCA',
    'BCOM',
    'BSE(COMPUTER)',
    'BSE(BIO)',
];

$paymentModes = [
    'UPI',
    'Cash',
    'Bank Transfer / NEFT',
    'Card / POS',
    'DD / Cheque',
    'Fee Adjustment',
];

$message = '';
$error = '';
$selectedDept = strtoupper(trim((string) ($_GET['dept'] ?? $_POST['active_dept'] ?? 'ALL')));
if (!in_array($selectedDept, $departmentOptions, true)) {
    $selectedDept = 'ALL';
}

$feeSearch = trim((string) ($_GET['q'] ?? ''));
$historySearch = trim((string) ($_GET['hq'] ?? ''));

$formUsn = '';
$formTotalFee = '';
$formPaidFee = '';
$formMode = 'UPI';
$formRemarks = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_fee';

    if ($action === 'delete_history') {
        $historyId = (int) ($_POST['history_id'] ?? 0);
        if ($historyId > 0) {
            $delHist = $pdo->prepare('DELETE FROM student_fee_payments WHERE id = :id');
            $delHist->execute([':id' => $historyId]);
            $message = 'Deleted payment history log entry #' . $historyId . '.';
        }
    } else {
        $targetUsn = strtoupper(trim($_POST['student_username'] ?? ''));
        $totalFee = (float) ($_POST['total_fee'] ?? -1);
        $paidFee = (float) ($_POST['paid_fee'] ?? -1);
        $paymentMode = trim((string) ($_POST['payment_mode'] ?? 'UPI'));
        $remarks = trim((string) ($_POST['remarks'] ?? ''));

        $formUsn = $targetUsn;
        $formTotalFee = ($_POST['total_fee'] ?? '');
        $formPaidFee = ($_POST['paid_fee'] ?? '');
        $formMode = $paymentMode;
        $formRemarks = $remarks;

        if (!in_array($paymentMode, $paymentModes, true)) {
            $paymentMode = 'UPI';
            $formMode = 'UPI';
        }

        $stuStmt = $pdo->prepare(
            'SELECT username, full_name, course
             FROM users
             WHERE UPPER(TRIM(username)) = :usn
               AND LOWER(TRIM(role)) IN (\'student\', \'students\')
             LIMIT 1'
        );
        $stuStmt->execute([':usn' => $targetUsn]);
        $stuRow = $stuStmt->fetch();

        if (!$stuRow) {
            $error = 'Student USN "' . htmlspecialchars($targetUsn, ENT_QUOTES, 'UTF-8') . '" was not found in Student Section.';
        } elseif ($totalFee <= 0 || $paidFee < 0 || $paidFee > $totalFee) {
            $error = 'Enter a valid Total Fee greater than 0 and Paid Fee between 0 and Total Fee.';
        } else {
            $prevStmt = $pdo->prepare(
                'SELECT paid_fee FROM student_fees WHERE UPPER(TRIM(student_username)) = :usn LIMIT 1'
            );
            $prevStmt->execute([':usn' => $targetUsn]);
            $prevPaidVal = $prevStmt->fetchColumn();
            $previousPaid = ($prevPaidVal !== false) ? (float) $prevPaidVal : 0.0;

            $courseVal = strtoupper(trim((string) ($stuRow['course'] ?? 'BCA')));
            if ($courseVal === '') {
                $courseVal = 'BCA';
            }
            $studentFullName = trim((string) ($stuRow['full_name'] ?? $targetUsn));
            if ($studentFullName === '') {
                $studentFullName = $targetUsn;
            }

            $upsert = $pdo->prepare(
                'INSERT INTO student_fees (student_username, course, total_fee, paid_fee, updated_by)
                 VALUES (:usn, :course, :total_fee, :paid_fee, :updated_by)
                 ON DUPLICATE KEY UPDATE
                    course = VALUES(course),
                    total_fee = VALUES(total_fee),
                    paid_fee = VALUES(paid_fee),
                    updated_by = VALUES(updated_by)'
            );
            $upsert->execute([
                ':usn' => $targetUsn,
                ':course' => $courseVal,
                ':total_fee' => $totalFee,
                ':paid_fee' => $paidFee,
                ':updated_by' => $username,
            ]);

            $amountChange = $paidFee - $previousPaid;
            $balanceAfter = max(0.0, $totalFee - $paidFee);
            $receiptNo = 'RCPT-' . date('Ymd-His') . '-' . strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', $targetUsn), -4));

            $logStmt = $pdo->prepare(
                'INSERT INTO student_fee_payments
                 (receipt_no, student_username, student_name, course, total_fee, previous_paid, new_paid, amount_change, balance_after, payment_mode, remarks, recorded_by)
                 VALUES
                 (:receipt_no, :student_username, :student_name, :course, :total_fee, :previous_paid, :new_paid, :amount_change, :balance_after, :payment_mode, :remarks, :recorded_by)'
            );
            $logStmt->execute([
                ':receipt_no' => $receiptNo,
                ':student_username' => $targetUsn,
                ':student_name' => $studentFullName,
                ':course' => $courseVal,
                ':total_fee' => $totalFee,
                ':previous_paid' => $previousPaid,
                ':new_paid' => $paidFee,
                ':amount_change' => $amountChange,
                ':balance_after' => $balanceAfter,
                ':payment_mode' => $paymentMode,
                ':remarks' => ($remarks !== '' ? $remarks : 'Fee record updated'),
                ':recorded_by' => $username,
            ]);

            $message = 'Saved fee record for ' . htmlspecialchars($studentFullName, ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($targetUsn, ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars($courseVal, ENT_QUOTES, 'UTF-8') . ') · Receipt ' . htmlspecialchars($receiptNo, ENT_QUOTES, 'UTF-8') . '.';
            $formUsn = '';
            $formTotalFee = '';
            $formPaidFee = '';
            $formMode = 'UPI';
            $formRemarks = '';
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
$countStmt = $pdo->query(
    'SELECT UPPER(TRIM(COALESCE(f.course, u.course, \'BCA\'))) AS dept_name, COUNT(*) AS cnt
     FROM users u
     LEFT JOIN student_fees f ON UPPER(TRIM(f.student_username)) = UPPER(TRIM(u.username))
     WHERE LOWER(TRIM(u.role)) IN (\'student\', \'students\')
     GROUP BY dept_name'
);
while ($cRow = $countStmt->fetch()) {
    $dName = (string) ($cRow['dept_name'] ?? 'BCA');
    $dCnt = (int) ($cRow['cnt'] ?? 0);
    $deptCounts['ALL'] += $dCnt;
    if (isset($deptCounts[$dName])) {
        $deptCounts[$dName] += $dCnt;
    }
}

$sql = 'SELECT u.username, u.full_name, UPPER(TRIM(COALESCE(f.course, u.course, \'BCA\'))) AS course,
               COALESCE(f.total_fee, 0.00) AS total_fee,
               COALESCE(f.paid_fee, 0.00) AS paid_fee
        FROM users u
        LEFT JOIN student_fees f ON UPPER(TRIM(f.student_username)) = UPPER(TRIM(u.username))
        WHERE LOWER(TRIM(u.role)) IN (\'student\', \'students\')';
$params = [];
if ($selectedDept !== 'ALL') {
    $sql .= ' AND UPPER(TRIM(COALESCE(f.course, u.course, \'BCA\'))) = :dept';
    $params[':dept'] = $selectedDept;
}
if ($feeSearch !== '') {
    $sql .= ' AND (LOWER(TRIM(u.username)) LIKE :q OR LOWER(TRIM(COALESCE(u.full_name, \'\'))) LIKE :q OR LOWER(TRIM(COALESCE(f.course, u.course, \'\'))) LIKE :q)';
    $params[':q'] = '%' . strtolower($feeSearch) . '%';
}
$sql .= ' ORDER BY u.username ASC';
$rowsStmt = $pdo->prepare($sql);
$rowsStmt->execute($params);
$feeRows = $rowsStmt->fetchAll();

$totalExpected = 0.0;
$totalCollected = 0.0;
foreach ($feeRows as $fr) {
    $totalExpected += (float) $fr['total_fee'];
    $totalCollected += (float) $fr['paid_fee'];
}
$totalPending = max(0.0, $totalExpected - $totalCollected);

$histSql = 'SELECT id, receipt_no, student_username, student_name, course, total_fee,
                   previous_paid, new_paid, amount_change, balance_after, payment_mode, remarks, recorded_by, recorded_at
            FROM student_fee_payments
            WHERE 1=1';
$histParams = [];
if ($selectedDept !== 'ALL') {
    $histSql .= ' AND UPPER(TRIM(course)) = :hdept';
    $histParams[':hdept'] = $selectedDept;
}
if ($historySearch !== '') {
    $histSql .= ' AND (LOWER(TRIM(receipt_no)) LIKE :hq
                  OR LOWER(TRIM(student_username)) LIKE :hq
                  OR LOWER(TRIM(student_name)) LIKE :hq
                  OR LOWER(TRIM(payment_mode)) LIKE :hq
                  OR LOWER(TRIM(COALESCE(remarks, \'\'))) LIKE :hq)';
    $histParams[':hq'] = '%' . strtolower($historySearch) . '%';
}
$histSql .= ' ORDER BY recorded_at DESC, id DESC LIMIT 100';
$histStmt = $pdo->prepare($histSql);
$histStmt->execute($histParams);
$historyRows = $histStmt->fetchAll();

$deptScopeLabel = ($selectedDept === 'ALL') ? 'All Departments' : $selectedDept;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Students & Fees Dashboard — PES College Portal</title>
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
        .kpi-strip { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:20px; }
        .kpi-card { background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:14px; }
        .kpi-card span { display:block; font-size:12px; color:#475569; margin-bottom:4px; }
        .kpi-card strong { font-size:18px; color:#0f172a; }
        .dept-select { width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:14px; background:#fff; color:#1e293b; }
        .ledger-row { cursor:pointer; transition:background-color 0.15s ease; }
        .ledger-row:hover { background:#f1f5f9; }
        .ledger-row.selected-row { background:#eff6ff; outline:2px solid #0b3c78; }
        .fill-btn { background:#0b3c78; color:#fff; border:none; padding:6px 11px; border-radius:4px; font-size:12px; font-weight:600; cursor:pointer; }
        .fill-btn:hover { background:#092e5c; }
        .selected-banner { display:none; margin-bottom:14px; padding:10px 12px; border-radius:6px; background:#eff6ff; border:1px solid #93c5fd; color:#1e3a8a; font-size:13px; }
        .delta-pos { color:#15803d; font-weight:600; }
        .delta-neg { color:#b91c1c; font-weight:600; }
        .delta-zero { color:#475569; font-weight:600; }
        .del-btn { background:#c62828; color:#fff; border:none; padding:5px 9px; border-radius:4px; cursor:pointer; font-size:12px; }
        .del-btn:hover { background:#991b1b; }
    </style>
</head>
<body>
    <div class="portal-wrapper dashboard-wrapper">
        <div class="dashboard-topbar">
            <div>
                <h1>Students & Fees Dashboard</h1>
                <p>Welcome, <?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars(strtoupper($role), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>)</p>
            </div>
            <div>
                <a href="dashboard.php" class="logout-btn" style="background-color:#0b3c78; margin-right:8px;">Dashboard</a>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>

        <div class="nav-row">
            <a href="teachers.php" class="nav-link-btn">Teacher Section</a>
            <a href="students.php" class="nav-link-btn teal">Student Section</a>
            <a href="fees_dashboard.php" class="nav-link-btn amber">Students & Fees Dashboard</a>
        </div>

        <div class="dept-tabs-bar">
            <span class="dept-tabs-label">Department Section:</span>
            <?php foreach ($departmentOptions as $deptOpt): ?>
                <?php
                $tabLabel = ($deptOpt === 'ALL') ? 'All Departments' : $deptOpt;
                $tabCount = (int) ($deptCounts[$deptOpt] ?? 0);
                $tabUrl = 'fees_dashboard.php?dept=' . urlencode($deptOpt);
                ?>
                <a href="<?php echo htmlspecialchars($tabUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dept-tab <?php echo ($selectedDept === $deptOpt) ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($tabLabel, ENT_QUOTES, 'UTF-8'); ?> (<?php echo $tabCount; ?>)
                </a>
            <?php endforeach; ?>
        </div>

        <div class="kpi-strip">
            <div class="kpi-card"><span><?php echo htmlspecialchars($deptScopeLabel, ENT_QUOTES, 'UTF-8'); ?> — Total Fees</span><strong>₹<?php echo number_format($totalExpected, 2); ?></strong></div>
            <div class="kpi-card"><span><?php echo htmlspecialchars($deptScopeLabel, ENT_QUOTES, 'UTF-8'); ?> — Total Paid</span><strong>₹<?php echo number_format($totalCollected, 2); ?></strong></div>
            <div class="kpi-card"><span><?php echo htmlspecialchars($deptScopeLabel, ENT_QUOTES, 'UTF-8'); ?> — Balance Due</span><strong>₹<?php echo number_format($totalPending, 2); ?></strong></div>
            <div class="kpi-card"><span><?php echo htmlspecialchars($deptScopeLabel, ENT_QUOTES, 'UTF-8'); ?> — Transactions</span><strong><?php echo count($historyRows); ?></strong></div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="error-msg" style="background-color:#e8f5e9; color:#1b5e20; border-color:#c8e6c9;"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div id="fee-update-card" class="dash-card" style="margin-bottom:24px;">
            <h2>Update Student Fee & Log Payment</h2>
            <div id="selectedStudentBanner" class="selected-banner"></div>
            <form id="feeUpdateForm" method="POST" action="fees_dashboard.php?dept=<?php echo urlencode($selectedDept); ?>">
                <input type="hidden" name="action" value="save_fee">
                <input type="hidden" name="active_dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="input-group">
                    <label for="student_username">Student USN / Username</label>
                    <input type="text" id="student_username" name="student_username" value="<?php echo htmlspecialchars((string) $formUsn, ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. U21ME24S0015" required>
                </div>
                <div class="input-group">
                    <label for="total_fee">Total Fee (₹)</label>
                    <input type="number" step="0.01" min="1" id="total_fee" name="total_fee" value="<?php echo htmlspecialchars((string) $formTotalFee, ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. 65000" required>
                </div>
                <div class="input-group">
                    <label for="paid_fee">Cumulative Paid Fee (₹)</label>
                    <input type="number" step="0.01" min="0" id="paid_fee" name="paid_fee" value="<?php echo htmlspecialchars((string) $formPaidFee, ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. 40000" required>
                </div>
                <div class="input-group">
                    <label for="payment_mode">Payment Mode</label>
                    <select id="payment_mode" name="payment_mode" class="dept-select" required>
                        <?php foreach ($paymentModes as $modeOpt): ?>
                            <option value="<?php echo htmlspecialchars($modeOpt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($formMode === $modeOpt) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($modeOpt, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <label for="remarks">Remarks / Transaction Ref (Optional)</label>
                    <input type="text" id="remarks" name="remarks" value="<?php echo htmlspecialchars((string) $formRemarks, ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. 1st Installment UPI Ref #482910">
                </div>
                <button type="submit" class="login-btn">Save Fee & Log Receipt</button>
            </form>
        </div>

        <div class="dash-card" style="margin-bottom:24px;">
            <h2><?php echo htmlspecialchars($deptScopeLabel, ENT_QUOTES, 'UTF-8'); ?> — Student Fee Ledger (<?php echo count($feeRows); ?>)</h2>
            <p style="margin:4px 0 10px; font-size:13px; color:#475569;">Click any student row or <strong>Fill Form</strong> button to load their USN and current fees into the update form.</p>
            <form method="GET" action="fees_dashboard.php" style="display:flex; gap:8px; margin:10px 0 14px;">
                <input type="hidden" name="dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($historySearch !== ''): ?>
                    <input type="hidden" name="hq" value="<?php echo htmlspecialchars($historySearch, ENT_QUOTES, 'UTF-8'); ?>">
                <?php endif; ?>
                <input type="text" name="q" value="<?php echo htmlspecialchars($feeSearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search ledger by USN, name, or dept..." style="flex:1; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px;">
                <button type="submit" style="background:#0b3c78; color:#fff; border:none; padding:8px 14px; border-radius:6px; cursor:pointer;">Search</button>
                <?php if ($feeSearch !== ''): ?>
                    <a href="fees_dashboard.php?dept=<?php echo urlencode($selectedDept); ?><?php echo ($historySearch !== '') ? ('&hq=' . urlencode($historySearch)) : ''; ?>" style="padding:8px 12px; border-radius:6px; background:#e2e8f0; color:#1e293b; text-decoration:none; font-size:13px;">Clear</a>
                <?php endif; ?>
            </form>
            <div style="overflow-x:auto;">
                <?php if (empty($feeRows)): ?>
                    <p>No students found in <strong><?php echo htmlspecialchars($deptScopeLabel, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
                <?php else: ?>
                    <table style="width:100%; border-collapse:collapse; font-size:14px;">
                        <thead>
                            <tr style="background:#f8fafc; text-align:left; border-bottom:2px solid #dce3ec;">
                                <th style="padding:10px;">USN</th>
                                <th style="padding:10px;">Student Name</th>
                                <th style="padding:10px;">Dept</th>
                                <th style="padding:10px;">Total Fee</th>
                                <th style="padding:10px;">Paid</th>
                                <th style="padding:10px;">Balance</th>
                                <th style="padding:10px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($feeRows as $fr): ?>
                                <?php
                                $rowUsn = (string) $fr['username'];
                                $rowName = (string) ($fr['full_name'] ?? $rowUsn);
                                $rowCourse = (string) $fr['course'];
                                $tFee = (float) $fr['total_fee'];
                                $pFee = (float) $fr['paid_fee'];
                                $balFee = max(0.0, $tFee - $pFee);
                                $rawTotalNum = ($tFee > 0) ? number_format($tFee, 2, '.', '') : '';
                                $rawPaidNum = number_format($pFee, 2, '.', '');
                                ?>
                                <tr class="ledger-row"
                                    style="border-bottom:1px solid #eef2f6;"
                                    data-usn="<?php echo htmlspecialchars($rowUsn, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-name="<?php echo htmlspecialchars($rowName, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-course="<?php echo htmlspecialchars($rowCourse, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-total="<?php echo htmlspecialchars($rawTotalNum, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-paid="<?php echo htmlspecialchars($rawPaidNum, ENT_QUOTES, 'UTF-8'); ?>"
                                    onclick="fillFeeFormFromRow(this)">
                                    <td style="padding:10px;"><strong><?php echo htmlspecialchars($rowUsn, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td style="padding:10px;"><?php echo htmlspecialchars($rowName, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px;"><?php echo htmlspecialchars($rowCourse, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px;">₹<?php echo number_format($tFee, 2); ?></td>
                                    <td style="padding:10px;">₹<?php echo number_format($pFee, 2); ?></td>
                                    <td style="padding:10px;"><strong>₹<?php echo number_format($balFee, 2); ?></strong></td>
                                    <td style="padding:10px; white-space:nowrap;">
                                        <button type="button" class="fill-btn" onclick="event.stopPropagation(); fillFeeFormFromRow(this.closest('tr'));">Fill Form</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div id="payment-history" class="dash-card">
            <h2><?php echo htmlspecialchars($deptScopeLabel, ENT_QUOTES, 'UTF-8'); ?> — Payment History Log (<?php echo count($historyRows); ?>)</h2>
            <form method="GET" action="fees_dashboard.php#payment-history" style="display:flex; gap:8px; margin:10px 0 14px;">
                <input type="hidden" name="dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($feeSearch !== ''): ?>
                    <input type="hidden" name="q" value="<?php echo htmlspecialchars($feeSearch, ENT_QUOTES, 'UTF-8'); ?>">
                <?php endif; ?>
                <input type="text" name="hq" value="<?php echo htmlspecialchars($historySearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search history by receipt no, USN, student name, mode, or remarks..." style="flex:1; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px;">
                <button type="submit" style="background:#0b3c78; color:#fff; border:none; padding:8px 14px; border-radius:6px; cursor:pointer;">Search</button>
                <?php if ($historySearch !== ''): ?>
                    <a href="fees_dashboard.php?dept=<?php echo urlencode($selectedDept); ?><?php echo ($feeSearch !== '') ? ('&q=' . urlencode($feeSearch)) : ''; ?>#payment-history" style="padding:8px 12px; border-radius:6px; background:#e2e8f0; color:#1e293b; text-decoration:none; font-size:13px;">Clear</a>
                <?php endif; ?>
            </form>

            <?php if (empty($historyRows)): ?>
                <p>No fee payment history logged yet for <strong><?php echo htmlspecialchars($deptScopeLabel, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:13.5px;">
                        <thead>
                            <tr style="background:#f8fafc; text-align:left; border-bottom:2px solid #dce3ec;">
                                <th style="padding:10px;">Date & Time</th>
                                <th style="padding:10px;">Receipt No</th>
                                <th style="padding:10px;">USN & Student</th>
                                <th style="padding:10px;">Mode</th>
                                <th style="padding:10px;">Prev Paid</th>
                                <th style="padding:10px;">Change</th>
                                <th style="padding:10px;">New Paid</th>
                                <th style="padding:10px;">Balance</th>
                                <th style="padding:10px;">Remarks & Admin</th>
                                <th style="padding:10px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historyRows as $hr): ?>
                                <?php
                                $delta = (float) $hr['amount_change'];
                                $deltaClass = 'delta-zero';
                                $deltaPrefix = '';
                                if ($delta > 0.0001) {
                                    $deltaClass = 'delta-pos';
                                    $deltaPrefix = '+';
                                } elseif ($delta < -0.0001) {
                                    $deltaClass = 'delta-neg';
                                }
                                ?>
                                <tr style="border-bottom:1px solid #eef2f6;">
                                    <td style="padding:10px; white-space:nowrap;"><?php echo htmlspecialchars((string) $hr['recorded_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px; white-space:nowrap;"><strong><?php echo htmlspecialchars((string) $hr['receipt_no'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td style="padding:10px;">
                                        <strong><?php echo htmlspecialchars((string) $hr['student_username'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                        <span><?php echo htmlspecialchars((string) $hr['student_name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars((string) $hr['course'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                                    </td>
                                    <td style="padding:10px; white-space:nowrap;"><?php echo htmlspecialchars((string) $hr['payment_mode'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px; white-space:nowrap;">₹<?php echo number_format((float) $hr['previous_paid'], 2); ?></td>
                                    <td style="padding:10px; white-space:nowrap;"><span class="<?php echo $deltaClass; ?>"><?php echo $deltaPrefix; ?>₹<?php echo number_format($delta, 2); ?></span></td>
                                    <td style="padding:10px; white-space:nowrap;"><strong>₹<?php echo number_format((float) $hr['new_paid'], 2); ?></strong></td>
                                    <td style="padding:10px; white-space:nowrap;">₹<?php echo number_format((float) $hr['balance_after'], 2); ?></td>
                                    <td style="padding:10px;">
                                        <?php echo htmlspecialchars((string) ($hr['remarks'] ?? ''), ENT_QUOTES, 'UTF-8'); ?><br>
                                        <small style="color:#64748b;">By: <?php echo htmlspecialchars((string) ($hr['recorded_by'] ?? 'admin'), ENT_QUOTES, 'UTF-8'); ?></small>
                                    </td>
                                    <td style="padding:10px; white-space:nowrap;">
                                        <form method="POST" action="fees_dashboard.php?dept=<?php echo urlencode($selectedDept); ?>#payment-history" style="display:inline;" onsubmit="return confirm('Delete this payment log entry?');">
                                            <input type="hidden" name="action" value="delete_history">
                                            <input type="hidden" name="active_dept" value="<?php echo htmlspecialchars($selectedDept, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="history_id" value="<?php echo (int) $hr['id']; ?>">
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

    <script>
        function fillFeeFormFromRow(rowEl) {
            if (!rowEl) return;

            var usnInput = document.getElementById('student_username');
            var totalInput = document.getElementById('total_fee');
            var paidInput = document.getElementById('paid_fee');
            var banner = document.getElementById('selectedStudentBanner');
            var updateCard = document.getElementById('fee-update-card');

            var usn = rowEl.getAttribute('data-usn') || '';
            var studentName = rowEl.getAttribute('data-name') || usn;
            var course = rowEl.getAttribute('data-course') || '';
            var totalFee = rowEl.getAttribute('data-total') || '';
            var paidFee = rowEl.getAttribute('data-paid') || '0.00';

            if (usnInput) usnInput.value = usn;
            if (totalInput) totalInput.value = totalFee;
            if (paidInput) paidInput.value = paidFee;

            document.querySelectorAll('.ledger-row.selected-row').forEach(function (item) {
                item.classList.remove('selected-row');
            });
            rowEl.classList.add('selected-row');

            if (banner) {
                banner.style.display = 'block';
                banner.innerHTML = 'Loaded <strong>' + studentName + '</strong> (' + usn + (course ? ' · ' + course : '') + ') — Total: <strong>' + (totalFee ? ('₹' + totalFee) : 'Not set') + '</strong>, Paid: <strong>₹' + paidFee + '</strong>.';
            }

            if (updateCard) {
                updateCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            if (paidInput) {
                paidInput.focus();
            }
        }
    </script>
</body>
</html>