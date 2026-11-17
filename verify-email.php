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
// RESEND COOLDOWN
// ==========================================

$resend_cooldown_seconds = 36;

$last_resend_at = (int) ($_SESSION['verification_resend_at'] ?? 0);

$resend_wait = max(
    0,
    $resend_cooldown_seconds - (time() - $last_resend_at)
);


// ==========================================
// HELPER: GET HTTP STATUS CODE
// ==========================================

function verify_email_http_status($headers)
{
    $status = 0;

    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $matches)) {
            $status = (int) $matches[1];
        }
    }

    return $status;
}


// ==========================================
// HELPER: SUPABASE HTTP REQUEST
// ==========================================

function verify_email_supabase_request(
    $endpoint,
    $method = 'POST',
    $data = null,
    $access_token = null,
    $use_service_role = false
) {
    $api_key = SUPABASE_KEY;
    $authorization_token = $access_token;

    if ($use_service_role) {
        $api_key = SUPABASE_SERVICE_ROLE_KEY;
        $authorization_token = SUPABASE_SERVICE_ROLE_KEY;
    }

    $headers = [
        "Content-Type: application/json",
        "apikey: " . $api_key
    ];

    if (!empty($authorization_token)) {
        $headers[] = "Authorization: Bearer " . $authorization_token;
    }

    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'ignore_errors' => true,
            'timeout' => 20
        ]
    ];

    if ($data !== null) {
        $options['http']['content'] = json_encode($data);
    }

    $context = stream_context_create($options);

    $response = file_get_contents(
        SUPABASE_URL . $endpoint,
        false,
        $context
    );

    $headers = $http_response_header ?? [];

    if ($response === false) {
        return [
            'success' => false,
            'status' => 0,
            'result' => []
        ];
    }

    $result = json_decode($response, true);

    if (!is_array($result)) {
        $result = [];
    }

    $http_status = verify_email_http_status($headers);

    return [
        'success' => $http_status >= 200 && $http_status < 300,
        'status' => $http_status,
        'result' => $result
    ];
}


// ==========================================
// HANDLE FORM SUBMISSIONS
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

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

            $response = verify_email_supabase_request(
                '/auth/v1/verify',
                'POST',
                [
                    'type' => 'signup',
                    'email' => $email,
                    'token' => $otp
                ]
            );

            if (!$response['success']) {

                $result = $response['result'];

                $error_code = $result['error_code']
                    ?? $result['code']
                    ?? '';

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
                } elseif ($response['status'] === 0) {

                    $message = 'Unable to contact the verification service. Please try again.';
                } else {

                    $message = 'The verification code is incorrect or has expired. Please try again.';
                }

                $message_type = 'danger';
            } else {

                // ==========================================
                // GET AUTHENTICATED USER SESSION
                // ==========================================

                $auth_result = $response['result'];

                $auth_user = $auth_result['user'] ?? [];
                $access_token = $auth_result['access_token'] ?? '';
                $refresh_token = $auth_result['refresh_token'] ?? '';
                $expires_in = (int) ($auth_result['expires_in'] ?? 3600);

                if (
                    empty($auth_user['id']) ||
                    empty($access_token)
                ) {
                    $message = 'Your email was verified, but automatic login could not be completed. Please log in manually.';
                    $message_type = 'warning';

                    unset($_SESSION['pending_verification_email']);
                    unset($_SESSION['verification_csrf']);
                } else {

                    // ==========================================
                    // GET USER PROFILE AND ROLE
                    // ==========================================

                    $user_id = $auth_user['id'];

                    $profile_response = verify_email_supabase_request(
                        '/rest/v1/profiles?select=role,account_status,restricted_until&id=eq.' . urlencode($user_id),
                        'GET',
                        null,
                        $access_token
                    );

                    $profile = $profile_response['result'][0] ?? null;

                    if (
                        !$profile_response['success'] ||
                        !is_array($profile)
                    ) {
                        unset($_SESSION['pending_verification_email']);
                        unset($_SESSION['verification_csrf']);

                        header('Location: /login.php?verified=1&auto_login=failed');
                        exit;
                    }

                    $role = $profile['role'] ?? '';
                    $account_status = $profile['account_status'] ?? 'active';
                    $restricted_until = $profile['restricted_until'] ?? null;

                    // ==========================================
                    // CHECK ACCOUNT RESTRICTIONS
                    // ==========================================

                    if ($account_status === 'banned') {

                        unset($_SESSION['pending_verification_email']);
                        unset($_SESSION['verification_csrf']);

                        header('Location: /login.php?status=banned');
                        exit;
                    }

                    if ($account_status === 'suspended') {

                        $restriction_timestamp = !empty($restricted_until)
                            ? strtotime($restricted_until)
                            : false;

                        if (
                            $restriction_timestamp === false ||
                            $restriction_timestamp > time()
                        ) {
                            unset($_SESSION['pending_verification_email']);
                            unset($_SESSION['verification_csrf']);

                            header('Location: /login.php?status=suspended');
                            exit;
                        }

                        // Suspension has expired.
                        // Restore the account using the service role key.

                        $restore_response = verify_email_supabase_request(
                            '/rest/v1/profiles?id=eq.' . urlencode($user_id),
                            'PATCH',
                            [
                                'account_status' => 'active',
                                'restriction_reason' => null,
                                'restricted_until' => null,
                                'restricted_by' => null
                            ],
                            null,
                            true
                        );

                        if (!$restore_response['success']) {

                            unset($_SESSION['pending_verification_email']);
                            unset($_SESSION['verification_csrf']);

                            header('Location: /login.php?verified=1&auto_login=failed');
                            exit;
                        }
                    }

                    // ==========================================
                    // CHECK USER ROLE
                    // ==========================================

                    $allowed_roles = [
                        'worker',
                        'employer',
                        'admin'
                    ];

                    if (!in_array($role, $allowed_roles, true)) {

                        unset($_SESSION['pending_verification_email']);
                        unset($_SESSION['verification_csrf']);

                        header('Location: /login.php?verified=1&auto_login=failed');
                        exit;
                    }

                    // ==========================================
                    // CREATE WORKPULSE LOGIN SESSION
                    // ==========================================

                    session_regenerate_id(true);

                    $_SESSION['access_token'] = $access_token;
                    $_SESSION['refresh_token'] = $refresh_token;
                    $_SESSION['token_expires_at'] = time() + $expires_in;

                    $_SESSION['user'] = $auth_user;
                    $_SESSION['role'] = $role;

                    // Remove verification-only session values.
                    unset($_SESSION['pending_verification_email']);
                    unset($_SESSION['verification_csrf']);
                    unset($_SESSION['verification_resend_at']);

                    // ==========================================
                    // REDIRECT TO ROLE DASHBOARD
                    // ==========================================

                    if ($role === 'worker') {

                        header('Location: /pages/worker/dashboard.php');
                    } elseif ($role === 'employer') {

                        header('Location: /pages/employer/dashboard.php');
                    } elseif ($role === 'admin') {

                        header('Location: /pages/admin/dashboard.php');
                    }

                    exit;
                }
            }
        }
    } elseif (isset($_POST['resend_otp'])) {

        // ==========================================
        // RESEND OTP
        // ==========================================

        $last_resend_at = (int) ($_SESSION['verification_resend_at'] ?? 0);

        $resend_wait = max(
            0,
            36 - (time() - $last_resend_at)
        );

        if ($resend_wait > 0) {

            $message = "Please wait {$resend_wait} seconds before requesting another verification code.";
            $message_type = 'warning';
        } else {

            $_SESSION['verification_resend_at'] = time();

            $response = verify_email_supabase_request(
                '/auth/v1/resend',
                'POST',
                [
                    'type' => 'signup',
                    'email' => $email
                ]
            );

            if ($response['success']) {

                $message = 'A new verification code has been requested. Please check your inbox and spam folder.';
                $message_type = 'success';
            } elseif ($response['status'] === 0) {

                $message = 'Unable to contact the verification service. Please try again later.';
                $message_type = 'danger';
            } else {

                $result = $response['result'];

                $error_code = $result['error_code']
                    ?? $result['code']
                    ?? '';

                if ($error_code === 'over_email_send_rate_limit') {

                    $message = $result['msg']
                        ?? 'Too many requests. Please wait before trying again.';
                } else {

                    $message = 'Unable to resend the verification code. Please try again later.';
                }

                $message_type = 'danger';
            }
        }
    }
}


// ==========================================
// UPDATE COOLDOWN BEFORE DISPLAY
// ==========================================

$last_resend_at = (int) ($_SESSION['verification_resend_at'] ?? 0);

$resend_wait = max(
    0,
    36 - (time() - $last_resend_at)
);


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

    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/img/favicon-16.png">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">

    <link rel="stylesheet" href="/assets/css/auth.css">

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
                            id="resend-otp-button"
                            class="auth-submit resend-submit"
                            data-remaining="<?= $resend_wait ?>"
                            <?= $resend_wait > 0 ? 'disabled' : '' ?>>

                            <i class="bi bi-arrow-clockwise" id="resend-otp-icon"></i>

                            <span id="resend-otp-label">
                                <?= $resend_wait > 0
                                    ? "Resend in {$resend_wait}s"
                                    : "Resend Verification Code" ?>
                            </span>

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

    <script>
        document.addEventListener('DOMContentLoaded', function() {

            const button = document.getElementById('resend-otp-button');
            const label = document.getElementById('resend-otp-label');
            const icon = document.getElementById('resend-otp-icon');

            if (!button || !label || !icon) {
                return;
            }

            let remaining = parseInt(button.dataset.remaining, 10) || 0;

            function updateButton() {

                if (remaining > 0) {

                    button.disabled = true;
                    button.classList.add('is-counting');

                    label.textContent = `Resend in ${remaining}s`;

                } else {

                    button.disabled = false;
                    button.classList.remove('is-counting');

                    label.textContent = 'Resend Verification Code';
                }
            }

            updateButton();

            if (remaining > 0) {

                const countdown = setInterval(function() {

                    remaining--;

                    updateButton();

                    if (remaining <= 0) {
                        clearInterval(countdown);
                    }

                }, 1000);
            }

        });
    </script>

</body>

</html>