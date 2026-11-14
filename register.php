<?php
require_once 'config/supabase.php';

$message = '';

if (isset($_POST['register'])) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role'];

    // Basic validation
    if ($full_name == '' || $email == '' || $password == '') {
        $message = 'Please complete all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $message = 'Password must be at least 6 characters.';
    } elseif (!in_array($role, ['worker', 'employer'])) {
        $message = 'Invalid account type.';
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
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            $message = 'Unable to connect to Supabase. Please try again.';
        } else {
            $result = json_decode($response, true);

            if (isset($result['id']) || isset($result['user']['id'])) {
                $message = 'Registration successful! Please check your email.';
            } else {
                $message = 'Registration failed. ' .
                    ($result['msg'] ?? $result['message'] ?? 'Please try again.');
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
    <title>WorkPulse - Register</title>
</head>

<body>

    <h1>Create a WorkPulse Account</h1>

    <?php if ($message != ''): ?>
        <p><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <form method="POST">
        <label>Full Name</label><br>
        <input type="text" name="full_name" required><br><br>

        <label>Email</label><br>
        <input type="email" name="email" required><br><br>

        <label>Password</label><br>
        <input type="password" name="password" required><br><br>

        <label>Account Type</label><br>
        <select name="role" required>
            <option value="worker">Worker</option>
            <option value="employer">Employer</option>
        </select><br><br>

        <button type="submit" name="register">Register</button>
    </form>

</body>

</html>