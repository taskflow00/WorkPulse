<?php
$required_role = 'admin';
require_once '../../includes/auth.php';

date_default_timezone_set('Asia/Manila');

$token = $_SESSION['access_token'];

$message = '';


// Handle closing or reopening a job.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $job_id = $_POST['job_id'] ?? '';
    $new_status = $_POST['new_status'] ?? '';

    // Only allow open or closed statuses.
    if ($job_id !== '' && in_array($new_status, ['open', 'closed'])) {

        $url = SUPABASE_URL . '/rest/v1/jobs?id=eq.' . urlencode($job_id);

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
            $message = 'Unable to connect to Supabase.';
        } else {

            $result = json_decode($response, true);

            if (is_array($result) && count($result) > 0) {
                $message = 'Job listing successfully updated.';
            } else {
                $message = 'Unable to update the job listing.';
            }
        }
    } else {
        $message = 'Invalid job information.';
    }
}


// Get search and status filters.
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'all';

// Validate the selected status.
if (!in_array($status, ['all', 'open', 'closed'])) {
    $status = 'all';
}


// Build the Supabase query.
$query = 'jobs?select=id,title,company_name,hourly_rate,employment_type,status,created_at&order=created_at.desc&limit=100';

// Filter by status.
if ($status !== 'all') {
    $query .= '&status=eq.' . urlencode($status);
}

// Search by job title or company name.
if ($search !== '') {
    $search_filter = '(title.ilike.*' . $search . '*,company_name.ilike.*' . $search . '*)';

    $query .= '&or=' . urlencode($search_filter);
}


// Retrieve job listings.
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

$jobs = [];

if ($response === false) {
    $message = 'Unable to retrieve job listings.';
} else {

    $result = json_decode($response, true);

    if (is_array($result) && !isset($result['code'])) {
        $jobs = $result;
    } else {
        $message = 'Unable to retrieve job listings.';
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>WorkPulse | Job Management</title>
</head>

<body>

    <h1>Admin - Job Management</h1>

    <p>
        <a href="dashboard.php">Back to Dashboard</a> |
        <a href="users.php">User Management</a> |
        <a href="../../logout.php">Logout</a>
    </p>

    <hr>

    <h2>All Job Listings</h2>

    <p>View and manage job listings published by employers.</p>

    <?php if ($message !== ''): ?>
        <p><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>


    <form method="GET" action="">

        <div>

            <label>Search Job</label>

            <input
                type="text"
                name="search"
                placeholder="Job title or company"
                value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">

        </div>

        <div>

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

        </div>

        <button type="submit">Apply Filters</button>

        <a href="jobs.php">Clear Filters</a>

    </form>

    <hr>

    <p>
        Showing <?php echo count($jobs); ?> job listing(s).
        Maximum of 100 listings per request.
    </p>


    <?php if (count($jobs) > 0): ?>

        <table border="1" cellpadding="8">

            <tr>
                <th>Job Title</th>
                <th>Company</th>
                <th>Hourly Rate</th>
                <th>Employment Type</th>
                <th>Status</th>
                <th>Date Posted</th>
                <th>Action</th>
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

                        <form method="POST" action="">

                            <input
                                type="hidden"
                                name="job_id"
                                value="<?php echo htmlspecialchars($job['id']); ?>">

                            <?php if ($job['status'] === 'open'): ?>

                                <input
                                    type="hidden"
                                    name="new_status"
                                    value="closed">

                                <button type="submit">
                                    Close Job
                                </button>

                            <?php else: ?>

                                <input
                                    type="hidden"
                                    name="new_status"
                                    value="open">

                                <button type="submit">
                                    Reopen Job
                                </button>

                            <?php endif; ?>

                        </form>

                    </td>

                </tr>

            <?php endforeach; ?>

        </table>

    <?php else: ?>

        <p>No job listings found.</p>

    <?php endif; ?>

</body>

</html>