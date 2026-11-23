<?php
session_start();
require_once 'db.php';

$courseOptions = [
    'BCA',
    'BCOM',
    'BSE(COMPUTER)',
    'BSE(BIO)',
];

$roleConfigs = [
    'student' => [
        'tab_label' => 'Student',
        'heading' => 'Student Password Reset',
        'username_label' => 'Username / USN',
        'username_placeholder' => 'e.g. U21ME24S0015 or BCOM001',
        'show_course' => true,
    ],
    'teacher' => [
        'tab_label' => 'Teachers',
        'heading' => 'Teachers Password Reset',
        'username_label' => 'Teacher Username',
        'username_placeholder' => 'Enter your teacher username',
        'show_course' => true,
    ],
    'principal' => [
        'tab_label' => 'Principal',
        'heading' => 'Principal Password Reset',
        'username_label' => 'Principal Username',
        'username_placeholder' => 'Enter your principal username',
        'show_course' => false,
    ],
    'admin' => [
        'tab_label' => 'Admin',
        'heading' => 'Admin Password Reset',
        'username_label' => 'Admin Username',
        'username_placeholder' => 'Enter your admin username',
        'show_course' => true,
    ],
];

function normalizeResetRole(string $rawRole): string
{
    $clean = strtolower(trim($rawRole));
    if ($clean === 'teachers') {
        return 'teacher';
    }
    if ($clean === 'students' || $clean === 'stu') {
        return 'student';
    }
    if ($clean === 'principals') {
        return 'principal';
    }
    if (in_array($clean, ['student', 'teacher', 'principal', 'admin'], true)) {
        return $clean;
    }
    return 'student';
}

$message = '';
$error = '';
$selectedRole = normalizeResetRole((string) ($_POST['role'] ?? $_GET['role'] ?? 'student'));
$selectedCourse = strtoupper(trim((string) ($_POST['course'] ?? 'BCA')));
if (!in_array($selectedCourse, $courseOptions, true)) {
    $selectedCourse = 'BCA';
}
$enteredUsername = trim((string) ($_POST['username'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($enteredUsername === '' || $newPassword === '' || $confirmPassword === '') {
        $error = 'Please fill in Username, New Password, and Confirm Password.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'New password must be at least 6 characters long.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New Password and Confirm Password do not match.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, username, role, course
             FROM users
             WHERE LOWER(TRIM(username)) = LOWER(:username)
             LIMIT 1'
        );
        $stmt->execute([':username' => $enteredUsername]);
        $userRow = $stmt->fetch();

        $dbRole = normalizeResetRole((string) ($userRow['role'] ?? ''));
        $dbCourse = strtoupper(trim((string) ($userRow['course'] ?? '')));

        if (!$userRow || $dbRole !== $selectedRole) {
            $error = 'No ' . htmlspecialchars($roleConfigs[$selectedRole]['tab_label'], ENT_QUOTES, 'UTF-8') . ' account found with that username.';
        } elseif ($selectedRole !== 'principal' && $dbCourse !== '' && $dbCourse !== 'ALL DEPARTMENTS' && $dbCourse !== $selectedCourse) {
            $error = 'Selected department does not match this account.';
        } else {
            $upd = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
            $upd->execute([
                ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':id' => (int) $userRow['id'],
            ]);
            $message = 'Password updated for ' . htmlspecialchars((string) $userRow['username'], ENT_QUOTES, 'UTF-8') . '. You can now log in.';
            $enteredUsername = '';
        }
    }
}

$activeCfg = $roleConfigs[$selectedRole];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — PES College Portal</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            margin: 0;
            padding: 20px 14px;
            background: #e8eef5;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #0f172a;
        }

        .portal-header {
            text-align: center;
            max-width: 780px;
            margin: 0 auto 18px;
        }

        .portal-header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 800;
            color: #0f172a;
        }

        .portal-header p {
            margin: 4px 0 0;
            font-size: 14px;
            color: #475569;
        }

        .login-shell {
            display: grid;
            grid-template-columns: 225px 1fr;
            max-width: 780px;
            width: 100%;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.12);
            border: 1px solid #cbd5e1;
            border-left: 5px solid #0b3c78;
        }

        .role-panel {
            background: #f8fafc;
            border-right: 1px solid #e2e8f0;
            padding: 18px 14px;
        }

        .role-panel-heading {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.06em;
            color: #64748b;
            margin-bottom: 12px;
            padding-left: 4px;
        }

        .role-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .role-btn {
            width: 100%;
            min-height: 44px;
            padding: 10px 14px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #1e293b;
            font-size: 14px;
            font-weight: 600;
            text-align: left;
            cursor: pointer;
            transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
        }

        .role-btn:hover {
            background: #eff6ff;
            border-color: #93c5fd;
        }

        .role-btn.is-active {
            background: #0b3c78;
            color: #ffffff;
            border-color: #0b3c78;
        }

        .login-form-panel {
            padding: 24px 26px;
        }

        .login-form-panel h2 {
            margin: 0 0 16px;
            font-size: 20px;
            color: #0b3c78;
        }

        .alert-error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 14px;
            font-size: 13.5px;
            font-weight: 500;
        }

        .alert-success {
            background: #f0fdf4;
            color: #15803d;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 14px;
            font-size: 13.5px;
            font-weight: 600;
        }

        .form-field {
            margin-bottom: 14px;
        }

        .form-field label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }

        .form-control {
            width: 100%;
            min-height: 44px;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 16px;
            color: #0f172a;
            background: #ffffff;
            box-sizing: border-box;
        }

        .submit-btn {
            width: 100%;
            min-height: 46px;
            padding: 11px 16px;
            border: none;
            border-radius: 8px;
            background: #0b3c78;
            color: #ffffff;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 4px;
        }

        .submit-btn:hover {
            background: #092e5c;
        }

        .back-row {
            margin: 14px 0 0;
            font-size: 13px;
            color: #475569;
            text-align: center;
        }

        .back-row a {
            color: #0b3c78;
            font-weight: 700;
            text-decoration: none;
        }

        @media (max-width: 640px) {
            body {
                padding: 12px 10px;
            }

            .portal-header h1 {
                font-size: 22px;
            }

            .login-shell {
                grid-template-columns: 1fr;
                border-radius: 10px;
            }

            .role-panel {
                border-right: none;
                border-bottom: 1px solid #e2e8f0;
                padding: 12px;
            }

            .role-list {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }

            .role-btn {
                text-align: center;
                padding: 10px 8px;
                font-size: 13.5px;
            }

            .login-form-panel {
                padding: 18px 16px;
            }
        }
    </style>
</head>
<body>
    <header class="portal-header">
        <h1>PES College Portal</h1>
        <p>Reset Account Password</p>
    </header>

    <div class="login-shell">
        <aside class="role-panel" aria-label="Select User Type">
            <div class="role-panel-heading">SELECT USER TYPE</div>
            <div class="role-list" role="tablist">
                <?php foreach ($roleConfigs as $rKey => $rCfg): ?>
                    <button
                        type="button"
                        class="role-btn <?php echo ($selectedRole === $rKey) ? 'is-active' : ''; ?>"
                        data-role="<?php echo htmlspecialchars($rKey, ENT_QUOTES, 'UTF-8'); ?>"
                        data-label="<?php echo htmlspecialchars($rCfg['heading'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-username-label="<?php echo htmlspecialchars($rCfg['username_label'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-username-placeholder="<?php echo htmlspecialchars($rCfg['username_placeholder'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-show-course="<?php echo $rCfg['show_course'] ? '1' : '0'; ?>">
                        <?php echo htmlspecialchars($rCfg['tab_label'], ENT_QUOTES, 'UTF-8'); ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </aside>

        <section class="login-form-panel">
            <h2 id="resetFormTitle"><?php echo htmlspecialchars($activeCfg['heading'], ENT_QUOTES, 'UTF-8'); ?></h2>

            <?php if ($message !== ''): ?>
                <div class="alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="POST" action="forgot_password.php" class="login-form">
                <input type="hidden" name="role" id="selectedRoleInput" value="<?php echo htmlspecialchars($selectedRole, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-field" id="courseFieldGroup" <?php echo $activeCfg['show_course'] ? '' : 'style="display:none;"'; ?>>
                    <label for="courseSelect">Select Course / Department</label>
                    <select id="courseSelect" name="course" class="form-control" <?php echo $activeCfg['show_course'] ? 'required' : 'disabled'; ?>>
                        <?php foreach ($courseOptions as $deptOpt): ?>
                            <option value="<?php echo htmlspecialchars($deptOpt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($selectedCourse === $deptOpt) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($deptOpt, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="usernameInput" id="usernameFieldLabel"><?php echo htmlspecialchars($activeCfg['username_label'], ENT_QUOTES, 'UTF-8'); ?></label>
                    <input
                        type="text"
                        id="usernameInput"
                        name="username"
                        class="form-control"
                        value="<?php echo htmlspecialchars($enteredUsername, ENT_QUOTES, 'UTF-8'); ?>"
                        placeholder="<?php echo htmlspecialchars($activeCfg['username_placeholder'], ENT_QUOTES, 'UTF-8'); ?>"
                        required>
                </div>

                <div class="form-field">
                    <label for="newPasswordInput">New Password (min 6 characters)</label>
                    <input type="password" id="newPasswordInput" name="new_password" class="form-control" minlength="6" placeholder="Enter new password" required>
                </div>

                <div class="form-field">
                    <label for="confirmPasswordInput">Confirm New Password</label>
                    <input type="password" id="confirmPasswordInput" name="confirm_password" class="form-control" minlength="6" placeholder="Re-enter new password" required>
                </div>

                <button type="submit" class="submit-btn">Reset Password</button>

                <p class="back-row">
                    Remembered your password? <a href="index.php">Back to Login</a>
                </p>
            </form>
        </section>
    </div>

    <script>
    (function () {
        const buttons = document.querySelectorAll('.role-btn');
        const roleInput = document.getElementById('selectedRoleInput');
        const titleEl = document.getElementById('resetFormTitle');
        const courseGroup = document.getElementById('courseFieldGroup');
        const courseSelect = document.getElementById('courseSelect');
        const usernameLabel = document.getElementById('usernameFieldLabel');
        const usernameInput = document.getElementById('usernameInput');

        function applyRole(btn) {
            if (!btn) return;
            const role = btn.getAttribute('data-role') || 'student';
            const heading = btn.getAttribute('data-label') || 'Password Reset';
            const uLabel = btn.getAttribute('data-username-label') || 'Username';
            const uPlaceholder = btn.getAttribute('data-username-placeholder') || 'Enter username';
            const showCourse = btn.getAttribute('data-show-course') === '1';

            buttons.forEach(function (b) {
                b.classList.toggle('is-active', b === btn);
            });

            if (roleInput) roleInput.value = role;
            if (titleEl) titleEl.textContent = heading;
            if (usernameLabel) usernameLabel.textContent = uLabel;
            if (usernameInput) usernameInput.placeholder = uPlaceholder;

            if (courseGroup) {
                courseGroup.style.display = showCourse ? 'block' : 'none';
            }
            if (courseSelect) {
                courseSelect.required = showCourse;
                courseSelect.disabled = !showCourse;
            }
        }

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                applyRole(btn);
            });
        });

        const activeBtn = document.querySelector('.role-btn.is-active');
        if (activeBtn) {
            applyRole(activeBtn);
        }
    })();
    </script>
</body>
</html>