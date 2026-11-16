<?php

require_once __DIR__ . '/includes/session.php';

date_default_timezone_set('Asia/Manila');


// ==========================================
// SESSION AND VERIFICATION DETAILS
// ==========================================

$email = $_SESSION['pending_verification_email'] ?? '';

if ($email === '') {
    header('Location: /register.php');
    exit;
}

$message = '';
$message_type = '';


// Generate CSRF token.
if (empty($_SESSION['verification_csrf'])) {
    $_SESSION['verification_csrf'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['verification_csrf'];


// ==========================================
// HELPER: GET HTTP STATUS CODE
// ==========================================

function verify_email_http_status($headers)
{
    $status = 0;

    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
            $status = (int) $matches[1];
        }
    }

    return $status;
}


// ==========================================
// HANDLE FORM SUBMISSIONS
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Check CSRF token.
    $submitted_csrf = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submitted_csrf) ||
        !hash_equals($csrf_token, $submitted_csrf)
    ) {

        $message = 'Your request could not be verified. Please refresh the page and try again.';
        $message_type = 'danger';
    } elseif (isset($_POST['verify_otp'])) {

        // ==========================================
        // VERIFY OTP
        // ==========================================

        $otp = trim($_POST['token'] ?? '');

        if (!preg_match('/^[0-9]{6}$/', $otp)) {

            $message = 'Please enter a valid 6-digit verification code.';
            $message_type = 'danger';
        } else {

            $url = SUPABASE_URL . '/auth/v1/verify';

            $data = [
                'type' => 'signup',
                'email' => $email,
                'token' => $otp
            ];

            $options = [
                'http' => [
                    'method' => 'POST',
                    'header' =>
                    "Content-Type: application/json\r\n" .
                        "apikey: " . SUPABASE_KEY . "\r\n",
                    'content' => json_encode($data),
                    'ignore_errors' => true,
                    'timeout' => 20
                ]
            ];

            $context = stream_context_create($options);

            $response = file_get_contents($url, false, $context);
            $headers = $http_response_header ?? [];

            if ($response === false) {

                $message = 'Unable to contact the verification service. Please try again.';
                $message_type = 'danger';
            } else {

                $result = json_decode($response, true);
                $http_status = verify_email_http_status($headers);

                if (
                    $http_status >= 200 &&
                    $http_status < 300 &&
                    !empty($result['user']['id'])
                ) {

                    // Verification succeeded.
                    unset($_SESSION['pending_verification_email']);
                    unset($_SESSION['verification_csrf']);

                    header('Location: /login.php?verified=1');
                    exit;
                } else {

                    $error_code = $result['code'] ?? '';

                    if (
                        in_array($error_code, [
                            'otp_expired',
                            'otp_expired_or_invalid',
                            'token_expired'
                        ], true)
                    ) {

                        $message = 'This verification code has expired. Please request a new code.';
                    } elseif (
                        in_array($error_code, [
                            'token_already_used',
                            'otp_disabled'
                        ], true)
                    ) {

                        $message = 'This verification code cannot be used. Please request a new code.';
                    } else {

                        $message = 'The verification code is incorrect or has expired. Please try again.';
                    }

                    $message_type = 'danger';
                }
            }
        }
    } elseif (isset($_POST['resend_otp'])) {

        // ==========================================
        // RESEND OTP
        // ==========================================

        $url = SUPABASE_URL . '/auth/v1/resend';

        $data = [
            'type' => 'signup',
            'email' => $email
        ];

        $options = [
            'http' => [
                'method' => 'POST',
                'header' =>
                "Content-Type: application/json\r\n" .
                    "apikey: " . SUPABASE_KEY . "\r\n",
                'content' => json_encode($data),
                'ignore_errors' => true,
                'timeout' => 20
            ]
        ];

        $context = stream_context_create($options);

        $response = file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];

        if ($response === false) {

            $message = 'Unable to contact the verification service. Please try again later.';
            $message_type = 'danger';
        } else {

            $http_status = verify_email_http_status($headers);
            $result = json_decode($response, true);

            if ($http_status >= 200 && $http_status < 300) {

                $message = 'If this account is still waiting for verification, a new code will be sent. Please check your inbox and spam folder.';
                $message_type = 'success';
            } else {

                $error_code = $result['code'] ?? '';

                if ($error_code === 'over_email_send_rate_limit') {

                    $message = 'Too many verification requests. Please wait before requesting another code.';
                } else {

                    $message = 'Unable to resend the verification code. Please try again later.';
                }

                $message_type = 'danger';
            }
        }
    }
}


// ==========================================
// ALERT ICON
// ==========================================

$alert_icons = [
    'success' => 'bi-check-circle-fill',
    'danger' => 'bi-exclamation-circle-fill',
    'warning' => 'bi-exclamation-triangle-fill',
    'info' => 'bi-info-circle-fill'
];

$alert_icon = $alert_icons[$message_type] ?? 'bi-info-circle-fill';

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Verify Email | WorkPulse</title>

    <link rel="stylesheet" href="/assets/css/auth.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>

<body class="auth-page">

    <div class="auth-layout">

        <!-- LEFT PANEL -->
        <section class="auth-showcase">

            <a href="/index.php" class="auth-brand">
                <span class="brand-icon">
                    <i class="bi bi-briefcase-fill"></i>
                </span>
                WorkPulse
            </a>

            <div class="showcase-content">

                <span class="showcase-label">ONE LAST STEP</span>

                <h1>
                    Verify your<br>
                    <span>email address.</span>
                </h1>

                <p>
                    Confirm your email to secure your account
                    and get started with WorkPulse.
                </p>

                <div class="showcase-card">

                    <div class="showcase-card-icon">
                        <i class="bi bi-shield-check"></i>
                    </div>

                    <div>
                        <strong>Your account, protected</strong>
                        <small>Email verification helps keep your account secure.</small>
                    </div>

                </div>

            </div>

            <div class="showcase-footer">
                © 2026 WorkPulse. All rights reserved.
            </div>

        </section>


        <!-- RIGHT PANEL -->
        <main class="auth-main">

            <div class="auth-form-container">

                <div class="mobile-brand">
                    <i class="bi bi-briefcase-fill"></i>
                    WorkPulse
                </div>

                <div class="auth-heading">

                    <span>EMAIL VERIFICATION</span>

                    <h2>Check your inbox</h2>

                    <p>
                        We sent a 6-digit verification code to:
                    </p>

                    <strong class="verification-email">
                        <?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>
                    </strong>

                </div>


                <!-- ALERT MESSAGE -->
                <?php if ($message !== ''): ?>

                    <div class="auth-alert alert-<?= htmlspecialchars($message_type, ENT_QUOTES, 'UTF-8') ?>">

                        <i class="bi <?= htmlspecialchars($alert_icon, ENT_QUOTES, 'UTF-8') ?>"></i>

                        <span>
                            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                        </span>

                    </div>

                <?php endif; ?>


                <!-- OTP FORM -->
                <form method="POST" class="auth-form">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="auth-field">

                        <label for="token">Verification code</label>

                        <div class="auth-input">

                            <i class="bi bi-shield-lock"></i>

                            <input
                                type="text"
                                id="token"
                                name="token"
                                placeholder="Enter 6-digit code"
                                inputmode="numeric"
                                pattern="[0-9]{6}"
                                maxlength="6"
                                autocomplete="one-time-code"
                                required
                                autofocus>

                        </div>

                    </div>


                    <button
                        type="submit"
                        name="verify_otp"
                        class="auth-submit">

                        Verify Email
                        <i class="bi bi-arrow-right"></i>

                    </button>

                </form>


                <!-- RESEND OTP -->
                <div class="auth-resend-section">

                    <div class="auth-resend-heading">

                        <strong>Didn't receive the code?</strong>

                        <p>
                            Check your spam folder or request a new verification code.
                        </p>

                    </div>

                    <form method="POST">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                        <button
                            type="submit"
                            name="resend_otp"
                            class="auth-submit">

                            <i class="bi bi-arrow-clockwise"></i>
                            Resend Verification Code

                        </button>

                    </form>

                </div>


                <p class="auth-switch">

                    Wrong email address?

                    <a href="/register.php">Register again</a>

                </p>

            </div>

        </main>

    </div>

</body>

</html>