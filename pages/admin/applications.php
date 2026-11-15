<?php
$required_role = 'admin';
require_once '../../includes/auth.php';

date_default_timezone_set('Asia/Manila');

$token = $_SESSION['access_token'];


// Get search and status filters.
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'all';

$allowed_statuses = [
    'all',
    'pending',
    'shortlisted',
    'interview',
    'accepted',
    'rejected',
    'withdrawn'
];

// Validate the selected status.
if (!in_array($status, $allowed_statuses)) {
    $status = 'all';
}


// Retrieve applications and their related job and worker information.
$url = SUPABASE_URL .
    '/rest/v1/applications?select=id,application_statement,status,applied_at,job:jobs(title,company_name),worker:profiles(full_name)' .
    '&order=applied_at.desc&limit=100';

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

$applications = [];
$error = '';

if ($response === false) {

    $error = 'Unable to connect to Supabase.';
} else {

    $result = json_decode($response, true);

    if (is_array($result) && !isset($result['code'])) {

        // Apply search and status filters.
        foreach ($result as $application) {

            $worker_name = $application['worker']['full_name'] ?? 'Unknown';
            $job_title = $application['job']['title'] ?? 'Unknown';
            $company_name = $application['job']['company_name'] ?? 'Unknown';

            $matches_search = true;
            $matches_status = true;

            if ($search !== '') {

                $matches_search =
                    stripos($worker_name, $search) !== false ||
                    stripos($job_title, $search) !== false ||
                    stripos($company_name, $search) !== false;
            }

            if ($status !== 'all') {
                $matches_status = $application['status'] === $status;
            }

            if ($matches_search && $matches_status) {
                $applications[] = $application;
            }
        }
    } else {

        $error = 'Unable to retrieve applications.';
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>WorkPulse | Application Management</title>
</head>

<body>

    <h1>Admin - Application Management</h1>

    <p>
        <a href="dashboard.php">Back to Dashboard</a> |
        <a href="users.php">User Management</a> |
        <a href="jobs.php">Job Management</a> |
        <a href="../../logout.php">Logout</a>
    </p>

    <hr>

    <h2>All Applications</h2>

    <p>
        Monitor applications submitted across the WorkPulse platform.
    </p>


    <form method="GET" action="">

        <div>

            <label>Search Applications</label>

            <input
                type="text"
                name="search"
                placeholder="Applicant, job title, or company"
                value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">

        </div>

        <div>

            <label>Application Status</label>

            <select name="status">

                <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>
                    All Applications
                </option>

                <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>
                    Pending
                </option>

                <option value="shortlisted" <?php echo $status === 'shortlisted' ? 'selected' : ''; ?>>
                    Shortlisted
                </option>

                <option value="interview" <?php echo $status === 'interview' ? 'selected' : ''; ?>>
                    Interview
                </option>

                <option value="accepted" <?php echo $status === 'accepted' ? 'selected' : ''; ?>>
                    Accepted
                </option>

                <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>
                    Rejected
                </option>

                <option value="withdrawn" <?php echo $status === 'withdrawn' ? 'selected' : ''; ?>>
                    Withdrawn
                </option>

            </select>

        </div>

        <button type="submit">Apply Filters</button>

        <a href="applications.php">Clear Filters</a>

    </form>

    <hr>

    <p>
        Showing <?php echo count($applications); ?> application(s).
        Maximum of 100 applications per request.
    </p>


    <?php if ($error !== ''): ?>

        <p><?php echo htmlspecialchars($error); ?></p>

    <?php elseif (count($applications) > 0): ?>

        <table border="1" cellpadding="8">

            <tr>
                <th>Applicant</th>
                <th>Job Title</th>
                <th>Company</th>
                <th>Application Statement</th>
                <th>Status</th>
                <th>Date Applied</th>
            </tr>

            <?php foreach ($applications as $application): ?>

                <tr>

                    <td>
                        <?php
                        echo htmlspecialchars(
                            $application['worker']['full_name'] ?? 'Unknown'
                        );
                        ?>
                    </td>

                    <td>
                        <?php
                        echo htmlspecialchars(
                            $application['job']['title'] ?? 'Unknown'
                        );
                        ?>
                    </td>

                    <td>
                        <?php
                        echo htmlspecialchars(
                            $application['job']['company_name'] ?? 'Unknown'
                        );
                        ?>
                    </td>

                    <td>
                        <?php
                        echo nl2br(htmlspecialchars(
                            $application['application_statement'] ?? 'No statement provided'
                        ));
                        ?>
                    </td>

                    <td>
                        <?php
                        echo htmlspecialchars(
                            ucfirst($application['status'])
                        );
                        ?>
                    </td>

                    <td>
                        <?php
                        echo date(
                            'M d, Y h:i A',
                            strtotime($application['applied_at'])
                        );
                        ?>
                    </td>

                </tr>

            <?php endforeach; ?>

        </table>

    <?php else: ?>

        <p>No applications found.</p>

    <?php endif; ?>

</body>

</html>