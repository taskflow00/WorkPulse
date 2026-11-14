<?php
session_start();

require_once 'config/supabase.php';


$message = '';

if (isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if ($email == '' || $password == '') {
        $message = 'Please enter your email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } else {
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
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            $message = 'Unable to connect to Supabase.';
        } else {
            $result = json_decode($response, true);

            if (isset($result['access_token'])) {

                $_SESSION['access_token'] = $result['access_token'];
                $_SESSION['user'] = $result['user'];

                $user_id = $result['user']['id'];
                $url = SUPABASE_URL . '/rest/v1/profiles?select=role&id=eq.' . $user_id;

                $options = [
                    'http' => [
                        'method' => 'GET',
                        'header' =>
                        "apikey: " . SUPABASE_KEY . "\r\n" .
                            "Authorization: Bearer " . $result['access_token'] . "\r\n"
                    ]
                ];

                $context = stream_context_create($options);
                $profile_response = file_get_contents($url, false, $context);

                $profile = json_decode($profile_response, true);

                if (isset($profile[0]['role'])) {
                    $_SESSION['role'] = $profile[0]['role'];

                    // Check if the user came from a job details page
                    $redirect = $_GET['redirect'] ?? '';

                    if (
                        $_SESSION['role'] === 'worker' &&
                        preg_match(
                            '/^pages\/job-details\.php\?id=[0-9a-f-]{36}$/i',
                            $redirect
                        )
                    ) {

                        header('Location: ' . $redirect);
                        exit;
                    } elseif ($_SESSION['role'] === 'worker') {

                        header('Location: pages/worker/dashboard.php');
                        exit;
                    } elseif ($_SESSION['role'] === 'employer') {

                        header('Location: pages/employer/dashboard.php');
                        exit;
                    } elseif ($_SESSION['role'] === 'admin') {

                        header('Location: pages/admin/dashboard.php');
                        exit;
                    }
                } else {
                    $message = 'Unable to retrieve your account profile.';
                }


                $message = 'Login successful!';
            } else {
                $message = 'Login failed. ' .
                    ($result['msg'] ?? $result['message'] ?? 'Invalid email or password.');
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
    <title>WorkPulse | Login</title>
</head>

<body>

    <h1>WorkPulse</h1>
    <h2>Login to your account</h2>

    <?php if ($message != ''): ?>
        <p><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <form method="POST" action="">
        <div>
            <label>Email</label>
            <input type="email" name="email" required>
        </div>

        <div>
            <label>Password</label>
            <input type="password" name="password" required>
        </div>

        <button type="submit" name="login">Login</button>
    </form>

    <p>Don't have an account? <a href="register.php">Register here</a></p>

</body>

</html>