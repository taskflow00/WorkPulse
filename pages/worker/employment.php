<?php
$required_role = 'worker';
require_once '../../includes/auth.php';

$worker_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$message = '';

// Retrieve the worker's employment records.
$url = SUPABASE_URL .
    '/rest/v1/employments?select=*&worker_id=eq.' .
    urlencode($worker_id) .
    '&order=started_at.desc';

$options = [
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $access_token . "\r\n",
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($url, false, $context);
$employments = json_decode($response ?: '', true);

if (!is_array($employments)) {
    $employments = [];
    $message = 'Unable to retrieve employment records.';
}

// Retrieve employer profiles.
$employers = [];

if (!empty($employments)) {

    $employer_ids = array_unique(array_column($employments, 'employer_id'));

    $ids = implode(',', array_map('urlencode', $employer_ids));

    $profile_url = SUPABASE_URL .
        '/rest/v1/profiles?select=id,full_name,company_name&id=in.(' .
        $ids . ')';

    $context = stream_context_create($options);
    $profile_response = @file_get_contents($profile_url, false, $context);
    $profiles = json_decode($profile_response ?: '', true);

    if (is_array($profiles)) {
        foreach ($profiles as $profile) {
            $employers[$profile['id']] = $profile;
        }
    }
}

// Retrieve job titles.
$jobs = [];

if (!empty($employments)) {

    $job_ids = array_unique(array_column($employments, 'job_id'));

    $ids = implode(',', array_map('urlencode', $job_ids));

    $job_url = SUPABASE_URL .
        '/rest/v1/jobs?select=id,title,company_name&id=in.(' .
        $ids . ')';

    $context = stream_context_create($options);
    $job_response = @file_get_contents($job_url, false, $context);
    $job_results = json_decode($job_response ?: '', true);

    if (is_array($job_results)) {
        foreach ($job_results as $job) {
            $jobs[$job['id']] = $job;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Employment - WorkPulse</title>
</head>

<body>

    <h1>My Employment</h1>

    <p>View your current and previous employment records.</p>

    <?php if ($message !== ''): ?>
        <p><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <?php if (empty($employments)): ?>

        <p>You don't have any employment records yet.</p>

    <?php else: ?>

        <?php foreach ($employments as $employment): ?>

            <?php
            $employer = $employers[$employment['employer_id']] ?? [];
            $job = $jobs[$employment['job_id']] ?? [];

            $company_name = $employer['company_name']
                ?? $job['company_name']
                ?? 'Not specified';
            ?>

            <hr>

            <h2>
                <?php echo htmlspecialchars($job['title'] ?? 'Employment'); ?>
            </h2>

            <p>
                Company:
                <?php echo htmlspecialchars($company_name); ?>
            </p>

            <p>
                Employer:
                <?php echo htmlspecialchars(
                    $employer['full_name'] ?? 'Not available'
                ); ?>
            </p>

            <p>
                Hourly Rate:
                <strong>
                    ₱<?php echo number_format(
                            (float)$employment['hourly_rate'],
                            2
                        ); ?>
                </strong>
            </p>

            <p>
                Employment Type:
                <?php echo htmlspecialchars(
                    ucwords(str_replace(
                        '_',
                        ' ',
                        $employment['employment_type']
                    ))
                ); ?>
            </p>

            <p>
                Work Schedule:
                <?php echo htmlspecialchars($employment['work_schedule']); ?>
            </p>

            <p>
                Start Date:
                <?php echo date(
                    'F d, Y',
                    strtotime($employment['started_at'])
                ); ?>
            </p>

            <p>
                Status:
                <strong>
                    <?php echo htmlspecialchars(
                        ucfirst($employment['status'])
                    ); ?>
                </strong>
            </p>

            <?php if ($employment['status'] === 'ended' && !empty($employment['ended_at'])): ?>
                <p>
                    End Date:
                    <?php echo date(
                        'F d, Y',
                        strtotime($employment['ended_at'])
                    ); ?>
                </p>
            <?php endif; ?>

        <?php endforeach; ?>

    <?php endif; ?>

    <br>
    <a href="dashboard.php">Back to Dashboard</a>

</body>

</html>