
<?php

// Start the session if it hasn't started yet.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/supabase.php';


// Get the current website address.
$protocol = (
    !empty($_SERVER['HTTPS']) &&
    $_SERVER['HTTPS'] !== 'off'
) ? 'https' : 'http';

$base_url = $protocol . '://' . $_SERVER['HTTP_HOST'];

// Login page URL.
$login_url = $base_url . '/login.php';


// Make sure the page specifies its required role.
if (
    !isset($required_role) ||
    !in_array($required_role, ['worker', 'employer', 'admin'])
) {
    die('Page access configuration is missing.');
}


// Check if the user is logged in.
if (
    !isset($_SESSION['user']['id']) ||
    !isset($_SESSION['access_token'])
) {
    header('Location: ' . $login_url);
    exit;
}


// Retrieve the latest account information from Supabase.
$user_id = $_SESSION['user']['id'];

$url = SUPABASE_URL .
    '/rest/v1/profiles?select=full_name,role,account_status,restricted_until&id=eq.' .
    urlencode($user_id);


$options = [
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $_SESSION['access_token'] . "\r\n",

        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);

$response = file_get_contents($url, false, $context);


// Stop access if the account cannot be verified.
if ($response === false) {
    die('Unable to verify your account. Please try again later.');
}

$profile = json_decode($response, true);

if (!isset($profile[0]['role'])) {

    // The account or profile could not be retrieved.
    $_SESSION = [];
    session_destroy();

    header('Location: ' . $login_url);
    exit;
}


// Get the latest account information.
$database_role = $profile[0]['role'];

$account_status = $profile[0]['account_status'] ?? 'active';

$restricted_until = $profile[0]['restricted_until'] ?? null;


// Check account restrictions.
if ($account_status === 'banned') {

    $_SESSION = [];
    session_destroy();

    header('Location: ' . $login_url . '?status=banned');
    exit;
} elseif ($account_status === 'suspended') {

    $restriction_time = !empty($restricted_until)
        ? strtotime($restricted_until)
        : false;


    // Suspension is still active.
    if (
        $restriction_time === false ||
        $restriction_time > time()
    ) {

        $_SESSION = [];
        session_destroy();

        header('Location: ' . $login_url . '?status=suspended');
        exit;
    }


    // Suspension has expired.
    // Restore the account using the service-role key.

    if (SUPABASE_SERVICE_ROLE_KEY === '') {
        die('Unable to restore your account. Please contact support.');
    }

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
                "apikey: " . SUPABASE_SERVICE_ROLE_KEY . "\r\n" .
                "Authorization: Bearer " . SUPABASE_SERVICE_ROLE_KEY . "\r\n" .
                "Prefer: return=minimal\r\n",

            'content' => json_encode($restore_data),
            'ignore_errors' => true
        ]
    ];

    $context = stream_context_create($options);

    $restore_response = file_get_contents($url, false, $context);


    // Check the restoration response.
    $restore_status = 0;

    $restore_headers = http_get_last_response_headers();

    if (
        !empty($restore_headers[0]) &&
        preg_match(
            '/\s(\d{3})(?:\s|$)/',
            $restore_headers[0],
            $status_match
        )
    ) {
        $restore_status = (int) $status_match[1];
    }


    if (
        $restore_response === false ||
        $restore_status < 200 ||
        $restore_status >= 300
    ) {
        die('Unable to restore your account. Please try again later.');
    }
}


// Update the session role using the latest database role.
$_SESSION['role'] = $database_role;


// Check whether the user has permission to access this page.
if ($database_role !== $required_role) {

    $role_pages = [
        'worker' => '/pages/worker/dashboard.php',
        'employer' => '/pages/employer/dashboard.php',
        'admin' => '/pages/admin/dashboard.php'
    ];

    header('Location: ' . $base_url . $role_pages[$database_role]);
    exit;
}

?>
