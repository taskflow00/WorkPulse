<?php
session_start();

require_once '../../config/supabase.php';

// Only workers can access this page
if (
    !isset($_SESSION['user']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'worker'
) {
    header('Location: ../../login.php');
    exit;
}

// Get the logged-in worker's ID
$worker_id = $_SESSION['user']['id'];

// Retrieve applications and related job details
$url = SUPABASE_URL .
    '/rest/v1/applications?select=id,application_statement,status,applied_at,jobs(id,title,company_name,hourly_rate,employment_type)' .
    '&worker_id=eq.' . urlencode($worker_id) .
    '&order=applied_at.desc';

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

$applications = [];
$message = '';

if ($response === false) {

    $message = 'Unable to retrieve your applications.';
} else {

    $result = json_decode($response, true);

    // Check the HTTP response status
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $matches);
    $status_code = (int)($matches[1] ?? 0);

    if ($status_code >= 200 && $status_code < 300 && is_array($result) && array_is_list($result)) {

        $applications = $result;
    } else {

        $message = 'Something went wrong while retrieving your applications.';
    }
}
?>

<h1>My Applications</h1>

<p>Track the status of your job applications.</p>

<?php if ($message !== ''): ?>

    <p><?php echo htmlspecialchars($message); ?></p>

<?php elseif (empty($applications)): ?>

    <p>You haven't applied for any jobs yet.</p>

    <a href="../jobs.php">Browse Available Jobs</a>

<?php else: ?>

    <?php foreach ($applications as $application): ?>

        <div class="application-card">

            <?php $job = $application['jobs'] ?? null; ?>

            <?php if ($job): ?>

                <h2>
                    <?php echo htmlspecialchars($job['title']); ?>
                </h2>

                <p>
                    Company:
                    <?php echo htmlspecialchars($job['company_name']); ?>
                </p>

                <p>
                    Hourly Rate:
                    ₱<?php echo number_format((float)$job['hourly_rate'], 2); ?>/hour
                </p>

                <p>
                    Employment Type:
                    <?php echo htmlspecialchars(
                        ucwords(str_replace('_', ' ', $job['employment_type']))
                    ); ?>
                </p>

                <a href="../job-details.php?id=<?php echo urlencode($job['id']); ?>">
                    View Job
                </a>

            <?php else: ?>

                <p>Job details are no longer available.</p>

            <?php endif; ?>

            <p>
                Application Status:
                <strong>
                    <?php echo htmlspecialchars(
                        ucwords(str_replace('_', ' ', $application['status']))
                    ); ?>
                </strong>
            </p>

            <p>
                Applied:
                <?php echo htmlspecialchars(
                    date('M d, Y', strtotime($application['applied_at']))
                ); ?>
            </p>

            <?php if (!empty($application['application_statement'])): ?>

                <p><strong>Application Statement:</strong></p>

                <p>
                    <?php echo nl2br(htmlspecialchars($application['application_statement'])); ?>
                </p>

            <?php endif; ?>

            <hr>

        </div>

    <?php endforeach; ?>

<?php endif; ?>