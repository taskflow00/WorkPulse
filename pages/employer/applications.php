<?php
session_start();

require_once '../../config/supabase.php';

// Only employers can access this page
if (
    !isset($_SESSION['user']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'employer'
) {
    header('Location: ../../login.php');
    exit;
}

$employer_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$message = '';

// Update application status
if (isset($_POST['update_status'])) {

    $application_id = $_POST['application_id'] ?? '';
    $new_status = $_POST['status'] ?? '';

    $allowed_statuses = [
        'shortlisted',
        'interview',
        'accepted',
        'rejected'
    ];

    if (
        !preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $application_id
        ) ||
        !in_array($new_status, $allowed_statuses, true)
    ) {
        $message = 'Invalid application update.';
    } else {

        $url = SUPABASE_URL .
            '/rest/v1/applications?id=eq.' . urlencode($application_id);

        $data = [
            'status' => $new_status
        ];

        $options = [
            'http' => [
                'method' => 'PATCH',
                'header' =>
                "Content-Type: application/json\r\n" .
                    "apikey: " . SUPABASE_KEY . "\r\n" .
                    "Authorization: Bearer " . $access_token . "\r\n" .
                    "Prefer: return=representation\r\n",
                'content' => json_encode($data),
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        if ($response !== false) {

            $result = json_decode($response, true);

            preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $matches);
            $status_code = (int)($matches[1] ?? 0);

            if (
                $status_code >= 200 &&
                $status_code < 300 &&
                !empty($result)
            ) {
                $message = 'Application status updated successfully.';
            } else {
                $message = 'Unable to update the application status.';
            }
        } else {
            $message = 'Unable to connect to Supabase.';
        }
    }
}

// Retrieve applications visible to this employer
$url = SUPABASE_URL .
    '/rest/v1/applications?select=id,worker_id,job_id,application_statement,status,applied_at,resume_path,jobs(title,company_name)' .
    '&order=applied_at.desc';

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
$response = file_get_contents($url, false, $context);

$applications = [];
$profiles = [];

if ($response === false) {

    $message = 'Unable to retrieve applications.';
} else {

    $result = json_decode($response, true);

    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $matches);
    $status_code = (int)($matches[1] ?? 0);

    if (
        $status_code >= 200 &&
        $status_code < 300 &&
        is_array($result) &&
        array_is_list($result)
    ) {
        $applications = $result;

        // Get unique worker IDs
        $worker_ids = array_unique(
            array_column($applications, 'worker_id')
        );

        // Retrieve applicant profiles
        if (!empty($worker_ids)) {

            $profile_url = SUPABASE_URL .
                '/rest/v1/profiles?select=id,full_name,skills,experience,education,portfolio_url,preferred_hourly_rate' .
                '&id=in.(' . implode(',', $worker_ids) . ')';

            $profile_options = [
                'http' => [
                    'method' => 'GET',
                    'header' =>
                    "apikey: " . SUPABASE_KEY . "\r\n" .
                        "Authorization: Bearer " . $access_token . "\r\n",
                    'ignore_errors' => true
                ]
            ];

            $profile_context = stream_context_create($profile_options);
            $profile_response = file_get_contents(
                $profile_url,
                false,
                $profile_context
            );

            if ($profile_response !== false) {

                $profiles = json_decode($profile_response, true) ?? [];
            }
        }
    } else {

        $message = 'Something went wrong while retrieving applications.';
    }
}

// Match each application with its worker profile
$profile_map = [];

foreach ($profiles as $profile) {
    $profile_map[$profile['id']] = $profile;
}
?>

<h1>Applications Received</h1>

<p>Review people who applied to your job postings.</p>

<?php if ($message !== ''): ?>
    <p><?php echo htmlspecialchars($message); ?></p>
<?php endif; ?>

<?php if (empty($applications)): ?>

    <p>You haven't received any applications yet.</p>

<?php else: ?>

    <?php foreach ($applications as $application): ?>

        <?php
        $job = $application['jobs'] ?? null;
        $worker = $profile_map[$application['worker_id']] ?? null;
        ?>

        <div class="application-card">

            <h2>
                <?php echo htmlspecialchars(
                    $worker['full_name'] ?? 'Unknown Applicant'
                ); ?>
            </h2>

            <?php if ($job): ?>

                <p>
                    Applied for:
                    <strong>
                        <?php echo htmlspecialchars($job['title']); ?>
                    </strong>
                </p>

                <p>
                    Company:
                    <?php echo htmlspecialchars($job['company_name']); ?>
                </p>

            <?php endif; ?>

            <?php if ($worker): ?>

                <p><strong>Skills:</strong></p>

                <p>
                    <?php echo htmlspecialchars(
                        implode(', ', $worker['skills'] ?? [])
                    ); ?>
                </p>

                <p><strong>Experience:</strong></p>

                <p>
                    <?php echo nl2br(htmlspecialchars(
                        $worker['experience'] ?? 'Not provided'
                    )); ?>
                </p>

                <p><strong>Education:</strong></p>

                <p>
                    <?php echo nl2br(htmlspecialchars(
                        $worker['education'] ?? 'Not provided'
                    )); ?>
                </p>

                <?php if (!empty($worker['portfolio_url'])): ?>

                    <a
                        href="<?php echo htmlspecialchars($worker['portfolio_url']); ?>"
                        target="_blank"
                        rel="noopener noreferrer">
                        View Portfolio
                    </a>

                <?php endif; ?>

                <?php if (!empty($application['resume_path'])): ?>

                    <p>
                        <a href="view-resume.php?id=<?php echo urlencode($application['id']); ?>"
                            target="_blank"
                            rel="noopener noreferrer">
                            View Submitted Resume
                        </a>
                    </p>

                <?php else: ?>

                    <p>No resume submitted.</p>

                <?php endif; ?>

            <?php endif; ?>

            <hr>

            <h3>Application Statement</h3>

            <p>
                <?php echo nl2br(htmlspecialchars(
                    $application['application_statement'] ?? 'No statement provided.'
                )); ?>
            </p>

            <p>
                <strong>Applied:</strong>
                <?php echo htmlspecialchars(
                    date('M d, Y', strtotime($application['applied_at']))
                ); ?>
            </p>

            <p>
                <strong>Status:</strong>
                <?php echo htmlspecialchars(
                    ucwords(str_replace('_', ' ', $application['status']))
                ); ?>
            </p>

            <?php if ($application['status'] === 'accepted'): ?>
                <a href="send-offer.php?id=<?php echo urlencode($application['id']); ?>">
                    Send Employment Offer
                </a>
            <?php endif; ?>

            <form method="POST">

                <input
                    type="hidden"
                    name="application_id"
                    value="<?php echo htmlspecialchars($application['id']); ?>">

                <label>Update Application Status</label>

                <select name="status" required>
                    <option value="shortlisted">Shortlisted</option>
                    <option value="interview">Interview</option>
                    <option value="accepted">Selected – Ready for Offer</option>
                    <option value="rejected">Rejected</option>
                </select>

                <button type="submit" name="update_status">
                    Update Status
                </button>

            </form>

            <hr>

        </div>

    <?php endforeach; ?>

<?php endif; ?>