<?php

require_once __DIR__ . '/includes/session.php';

date_default_timezone_set('Asia/Manila');

$message = '';

$status = $_GET['status'] ?? '';
if ($status === 'banned') {
    $message = 'Your account has been banned. Please contact support.';
} elseif ($status === 'suspended') {
    $message = 'Your account is temporarily suspended. Please try again later.';
}


// LOGIN
if (isset($_POST['login'])) {

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validate inputs
    if ($email == '' || $password == '') {

        $message = 'Please enter your email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = 'Please enter a valid email address.';
    } else {

        // Supabase login endpoint
        $url = SUPABASE_URL . '/auth/v1/token?grant_type=password';

        $data = [
            'email' => $email,
            'password' => $password
        ];

        // Authenticate the user using the public key
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

            $message = 'Unable to connect to Supabase.';
        } else {

            $result = json_decode($response, true);

            // Check authentication result
            if (
                isset($result['access_token']) &&
                isset($result['user']['id'])
            ) {

                $access_token = $result['access_token'];
                $user_id = $result['user']['id'];

                // Prepare authenticated user headers
                $user_headers =
                    "apikey: " . SUPABASE_KEY . "\r\n" .
                    "Authorization: Bearer " . $access_token . "\r\n" .
                    "Accept: application/json\r\n";

                // Retrieve the user's profile
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

                // Check whether PHP connected to Supabase
                if ($profile_response === false) {

                    $error = error_get_last();

                    $message = 'Unable to connect to the profile service. ' .
                        ($error['message'] ?? '');
                } else {

                   // Get the HTTP status code
                    $profile_status = 0;

                    foreach ($http_response_header as $header) {

                        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {

                            $profile_status = (int) $matches[1];
                        }
                    }
                    
                    // Check Supabase response
                    if ($profile_status != 200) {

                        $message = 'Unable to retrieve your account profile. HTTP ' .
                            $profile_status . '. Supabase response: ' .
                            $profile_response;
                    } else {

                        $profile = json_decode($profile_response, true);

                        // Check if the profile exists
                        if (
                            !is_array($profile) ||
                            !isset($profile[0]['role'])
                        ) {

                            $message = 'No usable account profile was returned. ' .
                                'Please check your profiles table and RLS policies.';
                        } else {

                            $role = $profile[0]['role'];

                            $account_status = $profile[0]['account_status'] ?? 'active';

                            $restricted_until = $profile[0]['restricted_until'] ?? null;


                            // CHECK ACCOUNT RESTRICTIONS

                            if ($account_status == 'banned') {

                                $message = 'Your account has been banned. Please contact support.';
                            } elseif ($account_status == 'suspended') {

                                $restriction_time = !empty($restricted_until)
                                    ? strtotime($restricted_until)
                                    : false;


                                // Suspension is still active
                                if (
                                    $restriction_time === false ||
                                    $restriction_time > time()
                                ) {

                                    if ($restriction_time === false) {

                                        $message = 'Your account is temporarily suspended, but no end time is currently available. Please contact support.';
                                    } else {

                                        // Calculate remaining suspension time
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

                                        // Format suspension end date
                                        $formatted_end = date('M j, Y g:i A', $restriction_time);

                                        $message = 'Your account is temporarily suspended. You can log in again in ' .
                                            $formatted_remaining .
                                            '. Suspension ends on ' .
                                            $formatted_end . '.';
                                    }
                                } else {

                                    // Suspension has expired.
                                    // Restore the account using the service-role key.

                                    $service_role_key = defined('SUPABASE_SERVICE_ROLE_KEY')
                                        ? SUPABASE_SERVICE_ROLE_KEY
                                        : '';

                                    if ($service_role_key == '') {

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

                                        // Check restoration HTTP status
                                        $restore_status = 0;

                                        $restore_headers = http_get_last_response_headers();

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
                            } elseif ($account_status != 'active') {

                                $message = 'Your account cannot log in. Please contact support.';
                            }


                            // CONTINUE LOGIN IF ACCOUNT IS ACTIVE
                            if ($message == '') {

                                // Generate a new session ID after successful login.
                                session_regenerate_id(true);

                                // Store the Supabase authentication tokens server-side.
                                $_SESSION['access_token'] = $result['access_token'];

                                $_SESSION['refresh_token'] = $result['refresh_token'];

                                $_SESSION['token_expires_at'] = time() +
                                    (int) ($result['expires_in'] ?? 3600);

                                // Store the authenticated user.
                                $_SESSION['user'] = $result['user'];

                                $_SESSION['role'] = $role;

                                // Check if the user came from a job details page
                                $redirect = $_GET['redirect'] ?? '';

                                if (
                                    $role == 'worker' &&
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
                                } elseif ($role == 'worker') {

                                    header('Location: pages/worker/dashboard.php');

                                    exit;
                                } elseif ($role == 'employer') {

                                    header('Location: pages/employer/dashboard.php');

                                    exit;
                                } elseif ($role == 'admin') {

                                    header('Location: pages/admin/dashboard.php');

                                    exit;
                                } else {

                                    $message = 'Your account role is not recognized.';
                                }
                            }
                        }
                    }
                }
            } else {

                $message = 'Login failed. ' .
                    ($result['error_description'] ??
                        $result['msg'] ??
                        $result['message'] ??
                        'Invalid email or password.');
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
                <span class="brand-icon"><i class="bi bi-briefcase-fill"></i></span>
                WorkPulse
            </a>

            <div class="showcase-content">
                <span class="showcase-label">YOUR WORK. YOUR FUTURE.</span>
                <h1>Find opportunities.<br><span>Make progress.</span></h1>
                <p>Connect with opportunities, manage your work, and build your professional journey with WorkPulse.</p>

                <div class="showcase-card">
                    <div class="showcase-card-icon"><i class="bi bi-check-circle-fill"></i></div>
                    <div>
                        <strong>Everything in one place</strong>
                        <small>Jobs, tasks, and work progress.</small>
                    </div>
                </div>
            </div>

            <div class="showcase-footer">© 2026 WorkPulse. All rights reserved.</div>
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

                <?php if ($message != ''): ?>
                    <div class="auth-alert">
                        <i class="bi bi-exclamation-circle"></i>
                        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="auth-form">

                    <div class="auth-field">
                        <label for="email">Email address</label>
                        <div class="auth-input">
                            <i class="bi bi-envelope"></i>
                            <input type="email" id="email" name="email"
                                placeholder="you@example.com"
                                value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                autocomplete="email" required>
                        </div>
                    </div>

                    <div class="auth-field">
                        <label for="password">Password</label>
                        <div class="auth-input">
                            <i class="bi bi-lock"></i>
                            <input type="password" id="password" name="password"
                                placeholder="Enter your password"
                                autocomplete="current-password" required>
                            <button type="button" class="password-toggle"
                                onclick="togglePassword('password', this)"
                                aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" name="login" class="auth-submit">
                        Sign In <i class="bi bi-arrow-right"></i>
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