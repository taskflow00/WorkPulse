<?php

require_once __DIR__ . '/includes/session.php';

date_default_timezone_set('Asia/Manila');

$message = '';
$message_type = 'danger';

// ==========================================
// VERIFICATION RESEND SECURITY
// ==========================================

if (empty($_SESSION['login_verification_csrf'])) {
    $_SESSION['login_verification_csrf'] = bin2hex(random_bytes(32));
}

$login_verification_csrf = $_SESSION['login_verification_csrf'];

$status = $_GET['status'] ?? '';


// ==========================================
// PAGE MESSAGES
// ==========================================

if (isset($_GET['verified']) && $_GET['verified'] === '1') {

    $message = 'Email verified successfully! You can now sign in.';
    $message_type = 'success';
} elseif ($status === 'banned') {

    $message = 'Your account has been banned. Please contact support.';
} elseif ($status === 'suspended') {

    $message = 'Your account is temporarily suspended. Please try again later.';
}

// ==========================================
// RESEND VERIFICATION CODE FROM LOGIN
// ==========================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['resend_verification'])
) {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $submitted_csrf = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submitted_csrf) ||
        !hash_equals($login_verification_csrf, $submitted_csrf)
    ) {
        $message = 'Your request could not be verified. Please refresh the page.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } else {
        // Prevent repeated requests in the same session.
        $last_resend = (int) ($_SESSION['verification_resend_at'] ?? 0);
        $remaining = 36 - (time() - $last_resend);

        if ($remaining > 0) {
            $message = "Please wait {$remaining} seconds before requesting another code.";
            $message_type = 'warning';
        } else {
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
                    'timeout' => 15
                ]
            ];

            $context = stream_context_create($options);
            $response = file_get_contents($url, false, $context);
            $headers = $http_response_header ?? [];

            $http_status = 0;

            foreach ($headers as $header) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $matches)) {
                    $http_status = (int) $matches[1];
                }
            }

            if (
                $response !== false &&
                $http_status >= 200 &&
                $http_status < 300
            ) {
                // Restore the verification session.
                $_SESSION['pending_verification_email'] = $email;
                $_SESSION['verification_resend_at'] = time();

                header('Location: /verify-email.php');
                exit;
            } else {
                $message = 'Unable to resend the verification code. Please try again later.';
            }
        }
    }
}

// ==========================================
// LOGIN
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {

        $message = 'Please enter your email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = 'Please enter a valid email address.';
    } else {

        // ==========================================
        // SUPABASE AUTHENTICATION
        // ==========================================

        $url = SUPABASE_URL . '/auth/v1/token?grant_type=password';

        $data = [
            'email' => $email,
            'password' => $password
        ];

        $options = [
            'http' => [
                'method' => 'POST',
                'header' =>
                "Content-Type: application/json\r\n" .
                    "apikey: " . SUPABASE_KEY . "\r\n",
                'content' => json_encode($data),
                'ignore_errors' => true,
                'timeout' => 15
            ]
        ];

        $context = stream_context_create($options);

        $response = file_get_contents($url, false, $context);

        if ($response === false) {

            $message = 'Unable to connect to Supabase. Please try again.';
        } else {

            $result = json_decode($response, true);

            if (!is_array($result)) {
                $result = [];
            }


            // ==========================================
            // CHECK EMAIL VERIFICATION
            // ==========================================

            $auth_error_code = $result['code'] ?? '';

            if ($auth_error_code === 'email_not_confirmed') {

                // Keep the email in the session for the OTP page.
                $_SESSION['pending_verification_email'] = $email;

                header('Location: /verify-email.php');
                exit;
            }


            // ==========================================
            // CHECK AUTHENTICATION RESULT
            // ==========================================

            if (
                !empty($result['access_token']) &&
                !empty($result['user']['id'])
            ) {

                $access_token = $result['access_token'];
                $user_id = $result['user']['id'];

                $user_headers =
                    "apikey: " . SUPABASE_KEY . "\r\n" .
                    "Authorization: Bearer " . $access_token . "\r\n" .
                    "Accept: application/json\r\n";


                // ==========================================
                // RETRIEVE USER PROFILE
                // ==========================================

                $url = SUPABASE_URL .
                    '/rest/v1/profiles?select=role,account_status,restricted_until&id=eq.' .
                    urlencode($user_id);

                $options = [
                    'http' => [
                        'method' => 'GET',
                        'header' => $user_headers,
                        'ignore_errors' => true,
                        'timeout' => 15
                    ]
                ];

                $context = stream_context_create($options);

                $profile_response = file_get_contents($url, false, $context);

                $profile_headers = $http_response_header ?? [];

                if ($profile_response === false) {

                    $message = 'Unable to connect to the profile service. Please try again.';
                } else {

                    $profile_status = 0;

                    foreach ($profile_headers as $header) {

                        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                            $profile_status = (int) $matches[1];
                        }
                    }


                    if ($profile_status !== 200) {

                        $message = 'Unable to retrieve your account profile. HTTP ' .
                            $profile_status . '.';
                    } else {

                        $profile = json_decode($profile_response, true);


                        if (
                            !is_array($profile) ||
                            !isset($profile[0]['role'])
                        ) {

                            $message = 'No usable account profile was returned. Please contact support.';
                        } else {

                            $role = $profile[0]['role'];

                            $account_status = $profile[0]['account_status'] ?? 'active';

                            $restricted_until = $profile[0]['restricted_until'] ?? null;


                            // ==========================================
                            // CHECK ACCOUNT RESTRICTIONS
                            // ==========================================

                            if ($account_status === 'banned') {

                                $message = 'Your account has been banned. Please contact support.';
                            } elseif ($account_status === 'suspended') {

                                $restriction_time = !empty($restricted_until)
                                    ? strtotime($restricted_until)
                                    : false;


                                if (
                                    $restriction_time === false ||
                                    $restriction_time > time()
                                ) {

                                    if ($restriction_time === false) {

                                        $message = 'Your account is temporarily suspended, but no end time is currently available. Please contact support.';
                                    } else {

                                        $remaining_seconds = $restriction_time - time();

                                        $days = intdiv($remaining_seconds, 86400);
                                        $hours = intdiv($remaining_seconds % 86400, 3600);
                                        $minutes = intdiv($remaining_seconds % 3600, 60);

                                        $remaining_time = [];

                                        if ($days > 0) {
                                            $remaining_time[] = $days . ' day' . ($days > 1 ? 's' : '');
                                        }

                                        if ($hours > 0) {
                                            $remaining_time[] = $hours . ' hour' . ($hours > 1 ? 's' : '');
                                        }

                                        if ($minutes > 0) {
                                            $remaining_time[] = $minutes . ' minute' . ($minutes > 1 ? 's' : '');
                                        }

                                        if (empty($remaining_time)) {
                                            $remaining_time[] = 'less than a minute';
                                        }

                                        $formatted_remaining = implode(', ', $remaining_time);

                                        $formatted_end = date('M j, Y g:i A', $restriction_time);

                                        $message = 'Your account is temporarily suspended. You can log in again in ' .
                                            $formatted_remaining .
                                            '. Suspension ends on ' .
                                            $formatted_end . '.';
                                    }
                                } else {

                                    // ==========================================
                                    // RESTORE EXPIRED SUSPENSION
                                    // ==========================================

                                    $service_role_key = defined('SUPABASE_SERVICE_ROLE_KEY')
                                        ? SUPABASE_SERVICE_ROLE_KEY
                                        : '';

                                    if ($service_role_key === '') {

                                        $message = 'Unable to restore your account. Please contact support.';
                                    } else {

                                        $url = SUPABASE_URL .
                                            '/rest/v1/profiles?id=eq.' .
                                            urlencode($user_id);

                                        $restore_data = [
                                            'account_status' => 'active',
                                            'restriction_reason' => null,
                                            'restricted_until' => null,
                                            'restricted_by' => null
                                        ];

                                        $options = [
                                            'http' => [
                                                'method' => 'PATCH',
                                                'header' =>
                                                "Content-Type: application/json\r\n" .
                                                    "apikey: " . $service_role_key . "\r\n" .
                                                    "Authorization: Bearer " . $service_role_key . "\r\n" .
                                                    "Prefer: return=minimal\r\n",
                                                'content' => json_encode($restore_data),
                                                'ignore_errors' => true,
                                                'timeout' => 15
                                            ]
                                        ];

                                        $context = stream_context_create($options);

                                        $restore_response = file_get_contents($url, false, $context);

                                        $restore_headers = $http_response_header ?? [];

                                        $restore_status = 0;

                                        foreach ($restore_headers as $header) {

                                            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                                                $restore_status = (int) $matches[1];
                                            }
                                        }

                                        if (
                                            $restore_response === false ||
                                            $restore_status < 200 ||
                                            $restore_status >= 300
                                        ) {

                                            $message = 'Unable to restore your account. Please try again later.';
                                        } else {

                                            $account_status = 'active';
                                        }
                                    }
                                }
                            } elseif ($account_status !== 'active') {

                                $message = 'Your account cannot log in. Please contact support.';
                            }


                            // ==========================================
                            // CONTINUE LOGIN IF ACCOUNT IS ACTIVE
                            // ==========================================

                            if ($message === '') {

                                session_regenerate_id(true);

                                $_SESSION['access_token'] = $result['access_token'];

                                $_SESSION['refresh_token'] = $result['refresh_token'] ?? '';

                                $_SESSION['token_expires_at'] = time() +
                                    (int) ($result['expires_in'] ?? 3600);

                                $_SESSION['user'] = $result['user'];

                                $_SESSION['role'] = $role;


                                // ==========================================
                                // ROLE-BASED REDIRECT
                                // ==========================================

                                $redirect = $_GET['redirect'] ?? '';

                                if (
                                    $role === 'worker' &&
                                    preg_match(
                                        '~^pages/job-details\.php\?id=([0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$~i',
                                        $redirect,
                                        $matches
                                    )
                                ) {

                                    header(
                                        'Location: pages/job-details.php?id=' .
                                            urlencode($matches[1])
                                    );

                                    exit;
                                } elseif ($role === 'worker') {

                                    header('Location: pages/worker/dashboard.php');
                                    exit;
                                } elseif ($role === 'employer') {

                                    header('Location: pages/employer/dashboard.php');
                                    exit;
                                } elseif ($role === 'admin') {

                                    header('Location: pages/admin/dashboard.php');
                                    exit;
                                } else {

                                    // Do not keep an authenticated session for an unknown role.
                                    unset(
                                        $_SESSION['access_token'],
                                        $_SESSION['refresh_token'],
                                        $_SESSION['token_expires_at'],
                                        $_SESSION['user'],
                                        $_SESSION['role']
                                    );

                                    $message = 'Your account role is not recognized.';
                                }
                            }
                        }
                    }
                }
            } else {

                $message = 'Login failed. Invalid email or password.';
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Sign In | WorkPulse</title>

    <link rel="stylesheet" href="/assets/css/auth.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>

<body class="auth-page">

    <div class="auth-layout">

        <section class="auth-showcase">

            <a href="/index.php" class="auth-brand">
                <span class="brand-icon">
                    <i class="bi bi-briefcase-fill"></i>
                </span>
                WorkPulse
            </a>

            <div class="showcase-content">

                <span class="showcase-label">YOUR WORK. YOUR FUTURE.</span>

                <h1>
                    Find opportunities.<br>
                    <span>Make progress.</span>
                </h1>

                <p>
                    Connect with opportunities, manage your work, and build your professional journey with WorkPulse.
                </p>

                <div class="showcase-card">

                    <div class="showcase-card-icon">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>

                    <div>
                        <strong>Everything in one place</strong>
                        <small>Jobs, tasks, and work progress.</small>
                    </div>

                </div>

            </div>

            <div class="showcase-footer">
                © 2026 WorkPulse. All rights reserved.
            </div>

        </section>


        <main class="auth-main">

            <div class="auth-form-container">

                <div class="mobile-brand">
                    <i class="bi bi-briefcase-fill"></i> WorkPulse
                </div>

                <div class="auth-heading">

                    <span>WELCOME BACK</span>

                    <h2>Sign in to your account</h2>

                    <p>Enter your details to continue to WorkPulse.</p>

                </div>


                <!-- ALERT MESSAGE -->
                <?php if ($message !== ''): ?>

                    <div class="auth-alert alert-<?= htmlspecialchars($message_type, ENT_QUOTES, 'UTF-8') ?>">

                        <i class="bi <?= $message_type === 'success'
                                            ? 'bi-check-circle-fill'
                                            : 'bi-exclamation-circle' ?>"></i>

                        <span>
                            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                        </span>

                    </div>

                <?php endif; ?>


                <form method="POST" class="auth-form">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($login_verification_csrf, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="auth-field">

                        <label for="email">Email address</label>

                        <div class="auth-input">

                            <i class="bi bi-envelope"></i>

                            <input
                                type="email"
                                id="email"
                                name="email"
                                placeholder="you@example.com"
                                value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                autocomplete="email"
                                required>

                        </div>

                    </div>


                    <div class="auth-field">

                        <label for="password">Password</label>

                        <div class="auth-input">

                            <i class="bi bi-lock"></i>

                            <input
                                type="password"
                                id="password"
                                name="password"
                                placeholder="Enter your password"
                                autocomplete="current-password"
                                required>

                            <button
                                type="button"
                                class="password-toggle"
                                onclick="togglePassword('password', this)"
                                aria-label="Show password">

                                <i class="bi bi-eye"></i>

                            </button>

                        </div>

                    </div>


                    <button type="submit" name="login" class="auth-submit">

                        Sign In
                        <i class="bi bi-arrow-right"></i>

                    </button>

                    <button
                        type="submit"
                        name="resend_verification"
                        class="auth-submit resend-submit"
                        formnovalidate>

                        <i class="bi bi-envelope-arrow-up"></i>
                        Resend Verification Code

                    </button>

                </form>


                <p class="auth-switch">

                    Don't have an account?

                    <a href="/register.php">Create an account</a>

                </p>

            </div>

        </main>

    </div>


    <script>
        function togglePassword(id, button) {

            const input = document.getElementById(id);
            const icon = button.querySelector('i');

            input.type = input.type === 'password' ? 'text' : 'password';

            icon.classList.toggle('bi-eye');
            icon.classList.toggle('bi-eye-slash');
        }
    </script>

</body>

</html>