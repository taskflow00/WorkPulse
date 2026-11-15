<?php
require_once 'config/supabase.php';

$message = '';
$message_type = '';

if (isset($_POST['register'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';

    // Basic validation
    if ($full_name === '' || $email === '' || $password === '') {
        $message = 'Please complete all fields.';
        $message_type = 'danger';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $message_type = 'danger';
    } elseif (strlen($password) < 6) {
        $message = 'Password must be at least 6 characters.';
        $message_type = 'danger';
    } elseif (!in_array($role, ['worker', 'employer'], true)) {
        $message = 'Invalid account type.';
        $message_type = 'danger';
    } else {

        $url = SUPABASE_URL . '/auth/v1/signup';

        $data = [
            'email' => $email,
            'password' => $password,
            'data' => [
                'full_name' => $full_name,
                'role' => $role
            ]
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
            $message_type = 'danger';
        } else {
            $result = json_decode($response, true);

            if (isset($result['id']) || isset($result['user']['id'])) {
                $message = 'Registration successful! Please check your email for the verification link.';
                $message_type = 'success';
            } else {
                $error_message = $result['msg']
                    ?? $result['message']
                    ?? $result['error_description']
                    ?? $result['error']
                    ?? 'Please try again.';

                $message = 'Registration failed. ' . $error_message;
                $message_type = 'danger';
            }
        }
    }
}

// Select the appropriate Bootstrap alert icon
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

                        <i class="bi <?= $alert_icon ?>"></i>

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
                                value="<?= htmlspecialchars($_POST['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                autocomplete="name"
                                required>

                        </div>

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
                                    <?= ($_POST['role'] ?? 'worker') === 'worker' ? 'checked' : '' ?>>

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
                                    <?= ($_POST['role'] ?? '') === 'employer' ? 'checked' : '' ?>>

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