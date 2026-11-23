<?php
session_start();

$message = '';
$error = '';
$devOtpDisplay = '';

function normalizeMobile(string $phone): string
{
    $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        return substr($digits, 2);
    }
    return $digits;
}

/**
 * Sends a 6-digit OTP via Fast2SMS if FAST2SMS_API_KEY is configured,
 * or falls back to local testing mode so you can verify immediately on XAMPP.
 */
function dispatchMobileOtp(string $mobile10, string $otpCode, string &$devOtpOut): bool
{
    $apiKey = getenv('FAST2SMS_API_KEY') ?: '';

    // Local test fallback when no live SMS API key is set yet
    if ($apiKey === '') {
        $devOtpOut = $otpCode;
        return true;
    }

    if (!function_exists('curl_init')) {
        return false;
    }

    $payload = json_encode([
        'route' => 'q',
        'message' => 'Your PES Portal OTP is ' . $otpCode . '. Valid for 10 minutes.',
        'language' => 'english',
        'flash' => 0,
        'numbers' => $mobile10,
    ]);

    $ch = curl_init('https://www.fast2sms.com/dev/bulkV2');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'authorization: ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $rawResponse = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($rawResponse === false || $curlError !== '' || $httpStatus >= 400) {
        return false;
    }

    $decoded = json_decode((string) $rawResponse, true);
    return is_array($decoded) && (($decoded['return'] ?? false) === true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'send';

    if ($action === 'send') {
        $mobile10 = normalizeMobile(trim($_POST['mobile'] ?? ''));

        if (preg_match('/^[6-9][0-9]{9}$/', $mobile10) !== 1) {
            $error = 'Enter a valid 10-digit Indian mobile number starting with 6, 7, 8, or 9.';
        } else {
            $otpCode = (string) random_int(100000, 999999);

            $_SESSION['test_otp'] = [
                'mobile' => $mobile10,
                'otp_hash' => password_hash($otpCode, PASSWORD_DEFAULT),
                'expires_at' => time() + 600,
            ];

            if (dispatchMobileOtp($mobile10, $otpCode, $devOtpDisplay)) {
                $message = 'OTP generated for +91 ' . htmlspecialchars($mobile10, ENT_QUOTES, 'UTF-8') . ' (valid for 10 minutes).';
            } else {
                $error = 'Failed to send OTP. Check cURL in XAMPP or your SMS API key.';
            }
        }
    } elseif ($action === 'verify') {
        $enteredOtp = trim($_POST['otp_code'] ?? '');
        $savedOtp = $_SESSION['test_otp'] ?? null;

        if (!$savedOtp || time() > (int) $savedOtp['expires_at']) {
            $error = 'OTP expired or not requested yet. Send a new OTP first.';
        } elseif (!password_verify($enteredOtp, (string) $savedOtp['otp_hash'])) {
            $error = 'Incorrect OTP. Please try again.';
        } else {
            unset($_SESSION['test_otp']);
            $message = 'OTP verified successfully for +91 ' . htmlspecialchars((string) $savedOtp['mobile'], ENT_QUOTES, 'UTF-8') . '!';
        }
    }
}

$activeMobile = $_SESSION['test_otp']['mobile'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send & Verify Mobile OTP</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="portal-wrapper">
        <div class="portal-header">
            <h1>Mobile OTP Test</h1>
            <p>Send and verify a 6-digit OTP</p>
        </div>

        <div class="dash-card">
            <?php if ($message !== ''): ?>
                <div class="error-msg" style="background-color:#e8f5e9; color:#1b5e20; border-color:#c8e6c9;">
                    <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <?php if ($devOtpDisplay !== ''): ?>
                <div class="error-msg" style="background-color:#eef6ff; color:#0b3c78; border-color:#bdd7ff;">
                    <strong>Test OTP:</strong> <?php echo htmlspecialchars($devOtpDisplay, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="error-msg"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="POST" action="send_otp.php">
                <input type="hidden" name="action" value="send">
                <div class="input-group">
                    <label for="mobile">Mobile Number (10 digits)</label>
                    <input type="text" id="mobile" name="mobile" placeholder="e.g. 9876543210" value="<?php echo htmlspecialchars($activeMobile, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <button type="submit" class="login-btn">Send OTP</button>
            </form>

            <hr style="margin:20px 0; border:none; border-top:1px solid #eef2f6;">

            <form method="POST" action="send_otp.php">
                <input type="hidden" name="action" value="verify">
                <div class="input-group">
                    <label for="otp_code">Enter 6-Digit OTP</label>
                    <input type="text" id="otp_code" name="otp_code" maxlength="6" pattern="[0-9]{6}" placeholder="123456" required>
                </div>
                <button type="submit" class="login-btn" style="background-color:#0b3c78;">Verify OTP</button>
            </form>

            <p style="margin-top:16px; text-align:center;">
                <a href="dashboard.php">Back to Dashboard</a>
            </p>
        </div>
    </div>
</body>
</html>