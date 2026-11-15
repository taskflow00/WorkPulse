<?php

$required_role = 'admin';

require_once '../../includes/auth.php';

$token = $_SESSION['access_token'];


// Dashboard counts.
$dashboard_counts = [
    'workers' => 0,
    'employers' => 0,
    'jobs' => 0,
    'active_employments' => 0,
    'pending_applications' => 0,
    'pending_timesheets' => 0,
    'pending_proofs' => 0
];

// Database queries for each dashboard metric.
$queries = [
    'workers' => 'profiles?select=id&role=eq.worker',
    'employers' => 'profiles?select=id&role=eq.employer',
    'jobs' => 'jobs?select=id',
    'active_employments' => 'employments?select=id&status=eq.active',
    'pending_applications' => 'applications?select=id&status=eq.pending',
    'pending_timesheets' => 'timesheets?select=id&status=eq.submitted',
    'pending_proofs' => 'daily_time_proofs?select=id&status=eq.pending'
];

$dashboard_error = '';

// Retrieve each count from Supabase.
foreach ($queries as $key => $query) {

    $url = SUPABASE_URL . '/rest/v1/' . $query;

    $options = [
        'http' => [
            'method' => 'GET',
            'header' =>
            "apikey: " . SUPABASE_KEY . "\r\n" .
                "Authorization: Bearer " . $token . "\r\n",
            'ignore_errors' => true
        ]
    ];

    $context = stream_context_create($options);

    $response = file_get_contents($url, false, $context);

    if ($response === false) {
        $dashboard_error = 'Unable to connect to Supabase.';
        continue;
    }

    $result = json_decode($response, true);

    if (is_array($result) && !isset($result['code'])) {
        $dashboard_counts[$key] = count($result);
    } else {
        $dashboard_error = 'Unable to retrieve some dashboard information.';
    }
}


// Retrieve the five most recent jobs.
$url = SUPABASE_URL .
    '/rest/v1/jobs?select=id,title,company_name,status,created_at' .
    '&order=created_at.desc&limit=5';

$options = [
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $token . "\r\n",
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);

$jobs_response = file_get_contents($url, false, $context);

$recent_jobs = [];

if ($jobs_response !== false) {
    $result = json_decode($jobs_response, true);

    if (is_array($result) && !isset($result['code'])) {
        $recent_jobs = $result;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WorkPulse | Admin Dashboard</title>
</head>

<body>

    <h1>WorkPulse Admin Portal</h1>

    <p>Welcome, Administrator!</p>

    <p>
        <a href="../../logout.php">Logout</a>
        <a href="applications.php">Application Management</a>
        <a href="employments.php">Employment Management</a>
        <a href="users.php">Users Management</a>

    </p>

    <hr>

    <h2>Platform Overview</h2>

    <?php if ($dashboard_error !== ''): ?>
        <p>
            <?php echo htmlspecialchars($dashboard_error); ?>
        </p>
    <?php endif; ?>

    <h3>Total Workers</h3>
    <p><?php echo $dashboard_counts['workers']; ?></p>

    <h3>Total Employers</h3>
    <p><?php echo $dashboard_counts['employers']; ?></p>

    <h3>Total Job Listings</h3>
    <p><?php echo $dashboard_counts['jobs']; ?></p>

    <h3>Active Employments</h3>
    <p><?php echo $dashboard_counts['active_employments']; ?></p>

    <h3>Pending Applications</h3>
    <p><?php echo $dashboard_counts['pending_applications']; ?></p>

    <h3>Pending Timesheets</h3>
    <p><?php echo $dashboard_counts['pending_timesheets']; ?></p>

    <h3>Pending Time Proofs</h3>
    <p><?php echo $dashboard_counts['pending_proofs']; ?></p>

    <hr>

    <h2>Recent Job Listings</h2>

    <?php if (count($recent_jobs) > 0): ?>

        <table border="1" cellpadding="8">

            <tr>
                <th>Job Title</th>
                <th>Company</th>
                <th>Status</th>
                <th>Date Posted</th>
            </tr>

            <?php foreach ($recent_jobs as $job): ?>

                <tr>
                    <td>
                        <?php echo htmlspecialchars($job['title']); ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars($job['company_name']); ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars($job['status']); ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars($job['created_at']); ?>
                    </td>
                </tr>

            <?php endforeach; ?>

        </table>

    <?php else: ?>

        <p>No job listings found.</p>

    <?php endif; ?>

</body>

</html>