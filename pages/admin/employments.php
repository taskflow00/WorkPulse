<?php
$required_role = 'admin';
require_once '../../includes/auth.php';

date_default_timezone_set('Asia/Manila');

$token = $_SESSION['access_token'];


// Get search and status filters.
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'all';

if (!in_array($status, ['all', 'active', 'ended'])) {
    $status = 'all';
}


// Retrieve all employment records.
$url = SUPABASE_URL .
    '/rest/v1/employments?select=id,job_id,employer_id,worker_id,hourly_rate,employment_type,work_schedule,status,started_at,ended_at,created_at' .
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

$employments = [];
$errors = [];

if ($response === false) {

    $errors[] = 'Unable to retrieve employment records.';
} else {

    $result = json_decode($response, true);

    if (is_array($result) && !isset($result['code'])) {
        $employments = $result;
    } else {
        $errors[] = 'Unable to retrieve employment records.';
    }
}


// Retrieve profiles for worker and employer names.
$url = SUPABASE_URL .
    '/rest/v1/profiles?select=id,full_name&limit=1000';

$context = stream_context_create($options);

$response = file_get_contents($url, false, $context);

$profiles = [];

if ($response === false) {

    $errors[] = 'Unable to retrieve user profiles.';
} else {

    $result = json_decode($response, true);

    if (is_array($result) && !isset($result['code'])) {
        $profiles = $result;
    } else {
        $errors[] = 'Unable to retrieve user profiles.';
    }
}


// Retrieve job titles and company names.
$url = SUPABASE_URL .
    '/rest/v1/jobs?select=id,title,company_name&limit=1000';

$context = stream_context_create($options);

$response = file_get_contents($url, false, $context);

$jobs = [];

if ($response === false) {

    $errors[] = 'Unable to retrieve job listings.';
} else {

    $result = json_decode($response, true);

    if (is_array($result) && !isset($result['code'])) {
        $jobs = $result;
    } else {
        $errors[] = 'Unable to retrieve job listings.';
    }
}


// Create profile lookup by user ID.
$profile_names = [];

foreach ($profiles as $profile) {
    $profile_names[$profile['id']] = $profile['full_name'];
}


// Create job lookup by job ID.
$job_details = [];

foreach ($jobs as $job) {
    $job_details[$job['id']] = $job;
}


// Apply search and status filters.
$filtered_employments = [];

foreach ($employments as $employment) {

    $worker_name = $profile_names[$employment['worker_id']] ?? 'Unknown';
    $employer_name = $profile_names[$employment['employer_id']] ?? 'Unknown';

    $job = $job_details[$employment['job_id']] ?? [];

    $job_title = $job['title'] ?? 'Unknown';
    $company_name = $job['company_name'] ?? 'Unknown';

    $matches_search = true;
    $matches_status = true;

    if ($search !== '') {

        $matches_search =
            stripos($worker_name, $search) !== false ||
            stripos($employer_name, $search) !== false ||
            stripos($job_title, $search) !== false ||
            stripos($company_name, $search) !== false;
    }

    if ($status !== 'all') {
        $matches_status = $employment['status'] === $status;
    }

    if ($matches_search && $matches_status) {

        $employment['worker_name'] = $worker_name;
        $employment['employer_name'] = $employer_name;
        $employment['job_title'] = $job_title;
        $employment['company_name'] = $company_name;

        $filtered_employments[] = $employment;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>WorkPulse | Employment Management</title>
</head>

<body>

    <h1>Admin - Employment Management</h1>

    <p>
        <a href="dashboard.php">Back to Dashboard</a> |
        <a href="users.php">User Management</a> |
        <a href="jobs.php">Job Management</a> |
        <a href="applications.php">Application Management</a> |
        <a href="../../logout.php">Logout</a>
    </p>

    <hr>

    <h2>All Employment Records</h2>

    <p>
        Monitor active and ended employment relationships across WorkPulse.
    </p>


    <?php if (count($errors) > 0): ?>

        <?php foreach ($errors as $error): ?>

            <p><?php echo htmlspecialchars($error); ?></p>

        <?php endforeach; ?>

    <?php endif; ?>


    <form method="GET" action="">

        <div>

            <label>Search Employment</label>

            <input
                type="text"
                name="search"
                placeholder="Worker, employer, or job title"
                value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">

        </div>

        <div>

            <label>Employment Status</label>

            <select name="status">

                <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>
                    All Employments
                </option>

                <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>
                    Active
                </option>

                <option value="ended" <?php echo $status === 'ended' ? 'selected' : ''; ?>>
                    Ended
                </option>

            </select>

        </div>

        <button type="submit">Apply Filters</button>

        <a href="employments.php">Clear Filters</a>

    </form>

    <hr>

    <p>
        Showing <?php echo count($filtered_employments); ?> employment record(s).
        Maximum of 100 records per request.
    </p>


    <?php if (count($errors) === 0 && count($filtered_employments) > 0): ?>

        <table border="1" cellpadding="8">

            <tr>
                <th>Worker</th>
                <th>Employer</th>
                <th>Job Title</th>
                <th>Hourly Rate</th>
                <th>Employment Type</th>
                <th>Status</th>
                <th>Start Date</th>
                <th>End Date</th>
            </tr>

            <?php foreach ($filtered_employments as $employment): ?>

                <tr>

                    <td>
                        <?php echo htmlspecialchars($employment['worker_name']); ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars($employment['employer_name']); ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars($employment['job_title']); ?>
                    </td>

                    <td>
                        $<?php echo number_format((float) $employment['hourly_rate'], 2); ?>
                    </td>

                    <td>
                        <?php
                        echo htmlspecialchars(
                            ucfirst(str_replace('_', ' ', $employment['employment_type']))
                        );
                        ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars(ucfirst($employment['status'])); ?>
                    </td>

                    <td>
                        <?php
                        echo date(
                            'M d, Y h:i A',
                            strtotime($employment['started_at'])
                        );
                        ?>
                    </td>

                    <td>

                        <?php if (!empty($employment['ended_at'])): ?>

                            <?php
                            echo date(
                                'M d, Y h:i A',
                                strtotime($employment['ended_at'])
                            );
                            ?>

                        <?php else: ?>

                            Not ended

                        <?php endif; ?>

                    </td>

                </tr>

            <?php endforeach; ?>

        </table>

    <?php elseif (count($errors) === 0): ?>

        <p>No employment records found.</p>

    <?php endif; ?>

</body>

</html>