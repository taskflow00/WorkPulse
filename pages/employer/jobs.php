<?php
session_start();

require_once '../../config/supabase.php';

// Check login and employer role.
if (!isset($_SESSION['access_token']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

if ($_SESSION['role'] !== 'employer') {
    if ($_SESSION['role'] === 'worker') {
        header('Location: ../worker/dashboard.php');
    } elseif ($_SESSION['role'] === 'admin') {
        header('Location: ../admin/dashboard.php');
    } else {
        header('Location: ../../login.php');
    }

    exit;
}

date_default_timezone_set('Asia/Manila');

$token = $_SESSION['access_token'];
$employer_id = $_SESSION['user']['id'];


// Create a CSRF token for form submissions.
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];


// Display messages after an action.
$message = $_SESSION['job_message'] ?? '';
unset($_SESSION['job_message']);


// Handle closing and reopening jobs.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $job_id = $_POST['job_id'] ?? '';
    $new_status = $_POST['new_status'] ?? '';
    $submitted_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf_token, $submitted_token)) {

        $_SESSION['job_message'] = 'Invalid request. Please try again.';
    } elseif (
        $job_id !== '' &&
        in_array($new_status, ['open', 'closed'])
    ) {

        // Update only the logged-in employer's job.
        $url = SUPABASE_URL . '/rest/v1/jobs?id=eq.'
            . urlencode($job_id)
            . '&employer_id=eq.'
            . urlencode($employer_id);

        $data = [
            'status' => $new_status
        ];

        $options = [
            'http' => [
                'method' => 'PATCH',
                'header' =>
                "apikey: " . SUPABASE_KEY . "\r\n" .
                    "Authorization: Bearer " . $token . "\r\n" .
                    "Content-Type: application/json\r\n" .
                    "Prefer: return=representation\r\n",
                'content' => json_encode($data),
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);

        $response = file_get_contents($url, false, $context);

        if ($response === false) {

            $_SESSION['job_message'] = 'Unable to connect to Supabase.';
        } else {

            $result = json_decode($response, true);

            if (is_array($result) && count($result) > 0) {
                $_SESSION['job_message'] = 'Job status successfully updated.';
            } else {
                $_SESSION['job_message'] = 'Unable to update this job listing.';
            }
        }
    } else {

        $_SESSION['job_message'] = 'Invalid job information.';
    }

    // Redirect to prevent form resubmission.
    header('Location: jobs.php');
    exit;
}


// Get search and status filters.
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'all';

if (!in_array($status, ['all', 'open', 'closed'])) {
    $status = 'all';
}


// Retrieve only this employer's jobs.
$url = SUPABASE_URL .
    '/rest/v1/jobs?select=id,title,company_name,hourly_rate,employment_type,status,created_at' .
    '&employer_id=eq.' . urlencode($employer_id) .
    '&order=created_at.desc&limit=100';

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

$jobs = [];
$error = '';

if ($response === false) {

    $error = 'Unable to retrieve your job listings.';
} else {

    $result = json_decode($response, true);

    if (is_array($result) && !isset($result['code'])) {

        // Apply filters to the retrieved jobs.
        foreach ($result as $job) {

            $matches_search = true;
            $matches_status = true;

            if ($search !== '') {

                $matches_search =
                    stripos($job['title'], $search) !== false ||
                    stripos($job['company_name'] ?? '', $search) !== false;
            }

            if ($status !== 'all') {
                $matches_status = $job['status'] === $status;
            }

            if ($matches_search && $matches_status) {
                $jobs[] = $job;
            }
        }
    } else {

        $error = 'Unable to retrieve your job listings.';
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>WorkPulse | My Job Listings</title>
</head>

<body>

    <h1>My Job Listings</h1>

    <p>
        <a href="dashboard.php">Back to Dashboard</a> |
        <a href="create-job.php">Create New Job</a> |
        <a href="../../logout.php">Logout</a>
    </p>

    <hr>

    <h2>Manage Your Published Jobs</h2>

    <p>
        View, edit, close, and reopen the jobs you have published.
    </p>

    <?php if ($message !== ''): ?>

        <p>
            <?php echo htmlspecialchars($message); ?>
        </p>

    <?php endif; ?>


    <form method="GET" action="">

        <label>Search Job</label>

        <input
            type="text"
            name="search"
            placeholder="Job title or company"
            value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">

        <label>Job Status</label>

        <select name="status">

            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>
                All Jobs
            </option>

            <option value="open" <?php echo $status === 'open' ? 'selected' : ''; ?>>
                Open
            </option>

            <option value="closed" <?php echo $status === 'closed' ? 'selected' : ''; ?>>
                Closed
            </option>

        </select>

        <button type="submit">Apply Filters</button>

        <a href="jobs.php">Clear Filters</a>

    </form>

    <hr>

    <p>
        Showing <?php echo count($jobs); ?> job listing(s).
        Maximum of 100 listings per request.
    </p>


    <?php if ($error !== ''): ?>

        <p><?php echo htmlspecialchars($error); ?></p>

    <?php elseif (count($jobs) > 0): ?>

        <table border="1" cellpadding="8">

            <tr>
                <th>Job Title</th>
                <th>Company</th>
                <th>Hourly Rate</th>
                <th>Employment Type</th>
                <th>Status</th>
                <th>Date Posted</th>
                <th>Actions</th>
            </tr>

            <?php foreach ($jobs as $job): ?>

                <tr>

                    <td>
                        <?php echo htmlspecialchars($job['title']); ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars($job['company_name'] ?? 'N/A'); ?>
                    </td>

                    <td>
                        $<?php echo number_format((float) $job['hourly_rate'], 2); ?>
                    </td>

                    <td>
                        <?php
                        echo htmlspecialchars(
                            ucfirst(str_replace('_', ' ', $job['employment_type']))
                        );
                        ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars(ucfirst($job['status'])); ?>
                    </td>

                    <td>
                        <?php
                        echo date(
                            'M d, Y h:i A',
                            strtotime($job['created_at'])
                        );
                        ?>
                    </td>

                    <td>

                        <a href="edit-job.php?id=<?php echo urlencode($job['id']); ?>">
                            Edit
                        </a>

                        <form method="POST" action="">

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?php echo htmlspecialchars($csrf_token); ?>">

                            <input
                                type="hidden"
                                name="job_id"
                                value="<?php echo htmlspecialchars($job['id']); ?>">

                            <?php if ($job['status'] === 'open'): ?>

                                <input
                                    type="hidden"
                                    name="new_status"
                                    value="closed">

                                <button type="submit">Close Job</button>

                            <?php else: ?>

                                <input
                                    type="hidden"
                                    name="new_status"
                                    value="open">

                                <button type="submit">Reopen Job</button>

                            <?php endif; ?>

                        </form>

                    </td>

                </tr>

            <?php endforeach; ?>

        </table>

    <?php else: ?>

        <p>You haven't published any matching job listings yet.</p>

    <?php endif; ?>

</body>

</html>