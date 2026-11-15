<?php
$required_role = 'employer';
require_once '../../includes/auth.php';

$employer_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$message = '';
$employments = [];
$workers = [];
$jobs = [];

// Common headers for authenticated Supabase requests.
$headers =
    "apikey: " . SUPABASE_KEY . "\r\n" .
    "Authorization: Bearer " . $access_token . "\r\n";

// 1. Retrieve this employer's employment records.
$url = SUPABASE_URL .
    '/rest/v1/employments?select=*&employer_id=eq.' .
    urlencode($employer_id) .
    '&order=started_at.desc';

$options = [
    'http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($url, false, $context);
$result = json_decode($response ?: '', true);

if (is_array($result)) {
    $employments = $result;
} else {
    $message = 'Unable to retrieve employees.';
}

// 2. Retrieve the hired workers' profiles.
if (!empty($employments)) {

    $worker_ids = array_unique(
        array_column($employments, 'worker_id')
    );

    $ids = implode(',', array_map('urlencode', $worker_ids));

    $profile_url = SUPABASE_URL .
        '/rest/v1/profiles?select=id,full_name,skills,experience,education,portfolio_url&id=in.(' .
        $ids . ')';

    $context = stream_context_create($options);
    $profile_response = @file_get_contents($profile_url, false, $context);
    $profile_results = json_decode($profile_response ?: '', true);

    if (is_array($profile_results)) {
        foreach ($profile_results as $worker) {
            $workers[$worker['id']] = $worker;
        }
    }
}

// 3. Retrieve the related job information.
if (!empty($employments)) {

    $job_ids = array_unique(
        array_column($employments, 'job_id')
    );

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

$active_count = 0;

foreach ($employments as $employment) {
    if ($employment['status'] === 'active') {
        $active_count++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Employees - WorkPulse</title>
</head>

<body>

    <h1>My Employees</h1>

    <p>Manage and view the workers you have hired.</p>

    <h2>Employee Overview</h2>

    <p>
        Total Employment Records:
        <strong><?php echo count($employments); ?></strong>
    </p>

    <p>
        Active Employees:
        <strong><?php echo $active_count; ?></strong>
    </p>

    <?php if ($message !== ''): ?>

        <p><?php echo htmlspecialchars($message); ?></p>

    <?php elseif (empty($employments)): ?>

        <p>You haven't hired any workers yet.</p>

    <?php else: ?>

        <h2>Employee List</h2>

        <?php foreach ($employments as $employment): ?>

            <?php
            $worker = $workers[$employment['worker_id']] ?? [];
            $job = $jobs[$employment['job_id']] ?? [];
            ?>

            <hr>

            <h3>
                <?php echo htmlspecialchars(
                    $worker['full_name'] ?? 'Unknown Worker'
                ); ?>
            </h3>

            <p>
                Position:
                <?php echo htmlspecialchars($job['title'] ?? 'Not available'); ?>
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
                Skills:
                <?php echo htmlspecialchars(
                    implode(', ', $worker['skills'] ?? [])
                ); ?>
            </p>

            <p>
                Experience:
                <?php echo htmlspecialchars(
                    $worker['experience'] ?? 'Not provided'
                ); ?>
            </p>

            <p>
                Education:
                <?php echo htmlspecialchars(
                    $worker['education'] ?? 'Not provided'
                ); ?>
            </p>

            <?php if (!empty($worker['portfolio_url'])): ?>
                <p>
                    Portfolio:
                    <a
                        href="<?php echo htmlspecialchars($worker['portfolio_url']); ?>"
                        target="_blank"
                        rel="noopener noreferrer">
                        View Portfolio
                    </a>
                </p>
            <?php endif; ?>

            <p>
                Started:
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

            <?php if (
                $employment['status'] === 'ended' &&
                !empty($employment['ended_at'])
            ): ?>
                <p>
                    Ended:
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