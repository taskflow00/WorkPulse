<?php

require_once __DIR__ . '/includes/session.php';

date_default_timezone_set('Asia/Manila');

$message = '';
$message_type = '';

$email = '';
$full_name = '';
$username = '';
$role = 'worker';


// ==========================================
// REGISTRATION
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {

    $full_name = trim($_POST['full_name'] ?? '');
    $username = strtolower(trim($_POST['username'] ?? ''));
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';

    if (
        $full_name === '' ||
        $username === '' ||
        $email === '' ||
        $password === ''
    ) {

        $message = 'Please complete all fields.';
        $message_type = 'danger';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = 'Please enter a valid email address.';
        $message_type = 'danger';
    } elseif (!preg_match('/^[a-z0-9_]{3,30}$/', $username)) {

        $message = 'Username must be 3–30 characters and contain only lowercase letters, numbers, and underscores.';
        $message_type = 'danger';
    } elseif (strlen($password) < 6) {

        $message = 'Password must be at least 6 characters.';
        $message_type = 'danger';
    } elseif (!in_array($role, ['worker', 'employer'], true)) {

        $message = 'Invalid account type.';
        $message_type = 'danger';
    } elseif (SUPABASE_SERVICE_ROLE_KEY === '') {

        $message = 'Registration service is not properly configured.';
        $message_type = 'danger';
    } else {

        // ==========================================
        // CHECK USERNAME AVAILABILITY
        // ==========================================

        $username_url = SUPABASE_URL .
            '/rest/v1/profiles?select=id&username=eq.' .
            rawurlencode($username) .
            '&limit=1';

        $username_options = [
            'http' => [
                'method' => 'GET',
                'header' =>
                "apikey: " . SUPABASE_SERVICE_ROLE_KEY . "\r\n" .
                    "Authorization: Bearer " . SUPABASE_SERVICE_ROLE_KEY . "\r\n" .
                    "Accept: application/json\r\n",
                'ignore_errors' => true,
                'timeout' => 20
            ]
        ];

        $username_context = stream_context_create($username_options);

        $username_response = file_get_contents(
            $username_url,
            false,
            $username_context
        );

        $username_headers = $http_response_header ?? [];

        $username_status = 0;

        foreach ($username_headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $matches)) {
                $username_status = (int) $matches[1];
            }
        }

        $username_result = json_decode($username_response ?: '', true);

        if ($username_response === false || $username_status !== 200) {

            error_log('USERNAME CHECK HTTP STATUS: ' . $username_status);
            error_log('USERNAME CHECK RESPONSE: ' . ($username_response ?: 'file_get_contents returned false'));
            error_log('USERNAME CHECK HEADERS: ' . print_r($username_headers, true));

            $message = 'Unable to check username availability. Please try again.';
            $message_type = 'danger';
        } elseif (is_array($username_result) && !empty($username_result)) {

            $message = 'That username is already taken. Please choose another one.';
            $message_type = 'danger';
        } else {

            // ==========================================
            // CHECK EXISTING EMAIL IN SUPABASE AUTH
            // ==========================================

            $email_already_registered = false;
            $email_check_failed = false;

            $page = 1;
            $per_page = 1000;

            do {

                $users_url = SUPABASE_URL .
                    '/auth/v1/admin/users?page=' . $page .
                    '&per_page=' . $per_page;

                $users_options = [
                    'http' => [
                        'method' => 'GET',
                        'header' =>
                        "Content-Type: application/json\r\n" .
                            "apikey: " . SUPABASE_SERVICE_ROLE_KEY . "\r\n" .
                            "Authorization: Bearer " . SUPABASE_SERVICE_ROLE_KEY . "\r\n",
                        'ignore_errors' => true,
                        'timeout' => 20
                    ]
                ];

                $users_context = stream_context_create($users_options);

                $users_response = file_get_contents(
                    $users_url,
                    false,
                    $users_context
                );

                $users_headers = $http_response_header ?? [];

                $users_status = 0;

                foreach ($users_headers as $header) {
                    if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $matches)) {
                        $users_status = (int) $matches[1];
                    }
                }

                if ($users_response === false || $users_status !== 200) {

                    $email_check_failed = true;
                    break;
                }

                $users_result = json_decode($users_response, true);
                $users = $users_result['users'] ?? null;

                if (!is_array($users)) {

                    $email_check_failed = true;
                    break;
                }

                foreach ($users as $existing_user) {

                    $existing_email = strtolower(
                        trim($existing_user['email'] ?? '')
                    );

                    if ($existing_email === $email) {

                        $email_already_registered = true;
                        break;
                    }
                }

                if ($email_already_registered || count($users) < $per_page) {
                    break;
                }

                $page++;
            } while (true);


            if ($email_check_failed) {

                $message = 'Unable to verify your email address right now. Please try again.';
                $message_type = 'danger';
            } elseif ($email_already_registered) {

                $message = 'We could not create an account with those details. If you already have an account, please sign in or reset your password.';
                $message_type = 'warning';
            } else {

                // ==========================================
                // SEND SIGNUP REQUEST TO SUPABASE
                // ==========================================

                $signup_url = SUPABASE_URL . '/auth/v1/signup';

                $signup_data = [
                    'email' => $email,
                    'password' => $password,
                    'data' => [
                        'full_name' => $full_name,
                        'username' => $username,
                        'role' => $role
                    ]
                ];

                $signup_options = [
                    'http' => [
                        'method' => 'POST',
                        'header' =>
                        "Content-Type: application/json\r\n" .
                            "apikey: " . SUPABASE_KEY . "\r\n",
                        'content' => json_encode($signup_data),
                        'ignore_errors' => true,
                        'timeout' => 20
                    ]
                ];

                $signup_context = stream_context_create($signup_options);

                $signup_response = file_get_contents(
                    $signup_url,
                    false,
                    $signup_context
                );

                $signup_headers = $http_response_header ?? [];

                $signup_status = 0;

                foreach ($signup_headers as $header) {
                    if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $matches)) {
                        $signup_status = (int) $matches[1];
                    }
                }

                if ($signup_response === false) {

                    $message = 'Unable to connect to the registration service. Please try again.';
                    $message_type = 'danger';
                } else {

                    $signup_result = json_decode($signup_response, true);

                    if (!is_array($signup_result)) {
                        $signup_result = [];
                    }

                    $user = $signup_result['user'] ?? [];

                    $user_id = $user['id']
                        ?? $signup_result['id']
                        ?? null;

                    $identities = $user['identities'] ?? null;

                    $email_already_registered = (
                        is_array($identities) &&
                        empty($identities)
                    );

                    if (
                        $signup_status >= 200 &&
                        $signup_status < 300 &&
                        !empty($user_id) &&
                        !$email_already_registered
                    ) {

                        // ==========================================
                        // REGISTRATION SUCCESSFUL
                        // ==========================================

                        $_SESSION['pending_verification_email'] = $email;

                        header('Location: /verify-email.php');
                        exit;
                    } elseif ($email_already_registered) {

                        $message = 'We could not create an account with those details. If you already have an account, please sign in or reset your password.';
                        $message_type = 'warning';
                    } else {

                        $error_code = $signup_result['error_code']
                            ?? $signup_result['code']
                            ?? '';

                        if ($error_code === 'over_email_send_rate_limit') {

                            $message = 'Too many registration attempts. Please wait before trying again.';
                        } elseif ($error_code === 'weak_password') {

                            $message = 'Your password is too weak. Please choose a stronger password.';
                        } else {

                            $message = 'Unable to create your account. Your username or email may already be in use. Please check your details and try again.';
                        }

                        $message_type = 'danger';
                    }
                }
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

    <title>Create Account | WorkPulse</title>

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

                <span class="showcase-label">GET STARTED TODAY</span>

                <h1>
                    Your next chapter<br>
                    <span>starts here.</span>
                </h1>

                <p>
                    Whether you're looking for work or looking for talent,
                    WorkPulse helps you move forward.
                </p>

                <div class="showcase-card">

                    <div class="showcase-card-icon">
                        <i class="bi bi-people-fill"></i>
                    </div>

                    <div>
                        <strong>Built for both sides</strong>
                        <small>Find work or find the right people.</small>
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

                    <span>JOIN WORKPULSE</span>

                    <h2>Create your account</h2>

                    <p>Fill in your details to get started.</p>

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


                <!-- REGISTRATION FORM -->
                <form method="POST" class="auth-form">

                    <div class="auth-field">

                        <label for="full_name">Full name</label>

                        <div class="auth-input">

                            <i class="bi bi-person"></i>

                            <input
                                type="text"
                                id="full_name"
                                name="full_name"
                                placeholder="Enter your full name"
                                value="<?= htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') ?>"
                                autocomplete="name"
                                required>

                        </div>

                    </div>


                    <div class="auth-field">

                        <label for="username">Username</label>

                        <div class="auth-input">

                            <i class="bi bi-at"></i>

                            <input
                                type="text"
                                id="username"
                                name="username"
                                placeholder="Choose a username"
                                value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>"
                                minlength="3"
                                maxlength="30"
                                pattern="[a-zA-Z0-9_]{3,30}"
                                autocomplete="username"
                                required>

                        </div>

                        <small>3–30 characters. Letters, numbers, and underscores only.</small>

                    </div>


                    <div class="auth-field">

                        <label for="email">Email address</label>

                        <div class="auth-input">

                            <i class="bi bi-envelope"></i>

                            <input
                                type="email"
                                id="email"
                                name="email"
                                placeholder="you@example.com"
                                value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
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
                                placeholder="At least 6 characters"
                                autocomplete="new-password"
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


                    <!-- ACCOUNT TYPE -->
                    <div class="auth-field">

                        <label>Account type</label>

                        <div class="role-options">

                            <label class="role-option">

                                <input
                                    type="radio"
                                    name="role"
                                    value="worker"
                                    <?= $role === 'worker' ? 'checked' : '' ?>>

                                <span class="role-option-content">

                                    <i class="bi bi-person-workspace"></i>

                                    <strong>Worker</strong>

                                    <small>I'm looking for work</small>

                                </span>

                            </label>


                            <label class="role-option">

                                <input
                                    type="radio"
                                    name="role"
                                    value="employer"
                                    <?= $role === 'employer' ? 'checked' : '' ?>>

                                <span class="role-option-content">

                                    <i class="bi bi-building"></i>

                                    <strong>Employer</strong>

                                    <small>I'm hiring people</small>

                                </span>

                            </label>

                        </div>

                    </div>


                    <button type="submit" name="register" class="auth-submit">

                        Create Account
                        <i class="bi bi-arrow-right"></i>

                    </button>

                </form>


                <p class="auth-switch">

                    Already have an account?

                    <a href="/login.php">Sign in</a>

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