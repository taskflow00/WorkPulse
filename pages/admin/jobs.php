<?php

// Page information
$required_role = 'admin';
$page_title = 'Job Management';
$active_page = 'jobs';
$page_styles = ['/assets/css/admin-jobs.css'];

// Check authentication and admin role
require_once '../../includes/auth.php';

date_default_timezone_set('Asia/Manila');

$token = $_SESSION['access_token'];
$message = '';
$message_type = 'success';


// Create CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];


// Handle closing or reopening a job
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $job_id = isset($_POST['job_id']) && is_string($_POST['job_id'])
        ? $_POST['job_id']
        : '';

    $new_status = isset($_POST['new_status']) && is_string($_POST['new_status'])
        ? $_POST['new_status']
        : '';

    $submitted_token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        ? $_POST['csrf_token']
        : '';

    // Verify CSRF token
    if (!hash_equals($csrf_token, $submitted_token)) {

        $message = 'Invalid request. Please try again.';
        $message_type = 'danger';
    } elseif (!preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        $job_id
    )) {

        $message = 'Invalid job ID.';
        $message_type = 'danger';
    } elseif (!in_array($new_status, ['open', 'closed'], true)) {

        $message = 'Invalid job status.';
        $message_type = 'danger';
    } else {

        // Only update if the job still has its expected current status.
        $expected_status = $new_status === 'closed' ? 'open' : 'closed';

        $url = SUPABASE_URL .
            '/rest/v1/jobs?id=eq.' . urlencode($job_id) .
            '&status=eq.' . urlencode($expected_status);

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

        $response = file_get_contents(
            $url,
            false,
            stream_context_create($options)
        );

        if ($response === false) {

            $message = 'Unable to connect to Supabase.';
            $message_type = 'danger';
        } else {

            $result = json_decode($response, true);

            if (is_array($result) && count($result) > 0 && !isset($result['code'])) {

                $message = 'Job listing successfully updated.';
                $message_type = 'success';
            } elseif (is_array($result) && isset($result['code'])) {

                $message = 'Supabase error: ' . ($result['message'] ?? 'Unable to update job.');
                $message_type = 'danger';
            } else {

                $message = 'The job was not updated. It may have already been changed.';
                $message_type = 'warning';
            }
        }
    }
}


// Get search and status filters
$search = isset($_GET['search']) && is_string($_GET['search'])
    ? trim($_GET['search'])
    : '';

$status = isset($_GET['status']) && is_string($_GET['status'])
    ? $_GET['status']
    : 'all';

// Validate status filter
if (!in_array($status, ['all', 'open', 'closed'], true)) {
    $status = 'all';
}


// Build Supabase query
$query = 'jobs?select=id,title,company_name,hourly_rate,employment_type,status,created_at' .
    '&order=created_at.desc&limit=100';

if ($status !== 'all') {
    $query .= '&status=eq.' . urlencode($status);
}


// Retrieve job listings
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

$response = file_get_contents(
    $url,
    false,
    stream_context_create($options)
);

$jobs = [];
$error = '';

if ($response === false) {

    $error = 'Unable to connect to Supabase.';
} else {

    $result = json_decode($response, true);

    if (is_array($result) && !isset($result['code'])) {

        // Apply search filter in PHP
        foreach ($result as $job) {

            $job_title = $job['title'] ?? '';
            $company_name = $job['company_name'] ?? '';

            if (
                $search === '' ||
                stripos($job_title, $search) !== false ||
                stripos($company_name, $search) !== false
            ) {
                $jobs[] = $job;
            }
        }
    } else {

        $error = 'Unable to retrieve job listings.';
    }
}


// Load shared header
require_once '../../includes/header.php';

?>

<div class="wp-page admin-jobs-page">

    <!-- Page heading -->
    <section class="wp-page-heading admin-jobs-heading">

        <div>
            <span class="admin-jobs-eyebrow">PLATFORM ADMINISTRATION</span>

            <h1 class="wp-page-title">Job Management</h1>

            <p class="wp-page-description">
                Monitor and manage job listings published by employers.
            </p>
        </div>

        <div class="admin-jobs-total">
            <i class="bi bi-briefcase"></i>
            <div>
                <strong><?= number_format(count($jobs)) ?></strong>
                <span>Listings found</span>
            </div>
        </div>

    </section>


    <!-- Feedback message -->
    <?php if ($message !== ''): ?>

        <div class="alert alert-<?= htmlspecialchars($message_type) ?> alert-dismissible fade show" role="alert">

            <i class="bi bi-info-circle me-2"></i>

            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>

            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>

        </div>

    <?php endif; ?>


    <?php if ($error !== ''): ?>

        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>

    <?php endif; ?>


    <!-- Job listings panel -->
    <section class="wp-panel admin-jobs-panel">

        <div class="wp-panel-header admin-jobs-panel-header">

            <div>
                <h3 class="wp-panel-title">All Job Listings</h3>
                <p class="wp-panel-description">
                    Search listings and manage their availability.
                </p>
            </div>

            <span class="admin-jobs-count">
                <?= number_format(count($jobs)) ?> results
            </span>

        </div>


        <div class="wp-panel-body">

            <!-- Search and filters -->
            <form method="GET" action="" class="admin-jobs-filters">

                <div class="admin-jobs-search">

                    <i class="bi bi-search"></i>

                    <input
                        type="text"
                        name="search"
                        placeholder="Search job title or company..."
                        value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">

                </div>


                <select name="status" class="form-select admin-jobs-status-filter">

                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>
                        All Statuses
                    </option>

                    <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>
                        Open
                    </option>

                    <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>
                        Closed
                    </option>

                </select>


                <button type="submit" class="wp-btn wp-btn-primary">
                    <i class="bi bi-funnel"></i>
                    Filter
                </button>


                <?php if ($search !== '' || $status !== 'all'): ?>

                    <a href="jobs.php" class="wp-btn wp-btn-secondary">
                        Clear
                    </a>

                <?php endif; ?>

            </form>


            <!-- Jobs table -->
            <?php if (count($jobs) > 0): ?>

                <div class="table-responsive admin-jobs-table-wrapper">

                    <table class="admin-jobs-table">

                        <thead>
                            <tr>
                                <th>Job Details</th>
                                <th>Hourly Rate</th>
                                <th>Employment Type</th>
                                <th>Status</th>
                                <th>Date Posted</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($jobs as $job): ?>

                                <?php
                                $job_status = $job['status'] ?? 'unknown';

                                $status_class = $job_status === 'open'
                                    ? 'success'
                                    : 'secondary';
                                ?>

                                <tr>

                                    <td>
                                        <div class="admin-jobs-title">
                                            <strong>
                                                <?= htmlspecialchars($job['title'] ?? 'Untitled Job', ENT_QUOTES, 'UTF-8') ?>
                                            </strong>

                                            <span>
                                                <?= htmlspecialchars($job['company_name'] ?? 'No company specified', ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </div>
                                    </td>


                                    <td>
                                        <strong class="admin-jobs-rate">
                                            $<?= number_format((float) ($job['hourly_rate'] ?? 0), 2) ?>
                                        </strong>
                                        <small>/ hour</small>
                                    </td>


                                    <td>
                                        <?= htmlspecialchars(
                                            ucfirst(str_replace('_', ' ', $job['employment_type'] ?? 'Not specified')),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </td>


                                    <td>
                                        <span class="badge rounded-pill text-bg-<?= $status_class ?>">
                                            <?= htmlspecialchars(ucfirst($job_status), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>


                                    <td>
                                        <?= !empty($job['created_at'])
                                            ? htmlspecialchars(date('M d, Y', strtotime($job['created_at'])), ENT_QUOTES, 'UTF-8')
                                            : '—' ?>
                                    </td>


                                    <td class="text-end">

                                        <?php if ($job_status === 'open' || $job_status === 'closed'): ?>

                                            <form
                                                method="POST"
                                                action=""
                                                class="admin-jobs-action-form"
                                                onsubmit="return confirm('Are you sure you want to <?= $job_status === 'open' ? 'close' : 'reopen' ?> this job listing?');">

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                                                <input
                                                    type="hidden"
                                                    name="job_id"
                                                    value="<?= htmlspecialchars($job['id'], ENT_QUOTES, 'UTF-8') ?>">

                                                <input
                                                    type="hidden"
                                                    name="new_status"
                                                    value="<?= $job_status === 'open' ? 'closed' : 'open' ?>">

                                                <button
                                                    type="submit"
                                                    class="admin-jobs-action-btn <?= $job_status === 'open' ? 'close-job' : 'reopen-job' ?>">

                                                    <i class="bi <?= $job_status === 'open' ? 'bi-lock' : 'bi-unlock' ?>"></i>

                                                    <?= $job_status === 'open' ? 'Close Job' : 'Reopen Job' ?>

                                                </button>

                                            </form>

                                        <?php else: ?>

                                            <span class="text-muted small">No action available</span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="admin-jobs-empty">

                    <div class="admin-jobs-empty-icon">
                        <i class="bi bi-briefcase"></i>
                    </div>

                    <h4>No job listings found</h4>

                    <p>
                        Try changing your search or status filter.
                    </p>

                    <?php if ($search !== '' || $status !== 'all'): ?>
                        <a href="jobs.php" class="wp-btn wp-btn-secondary">
                            Clear Filters
                        </a>
                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </div>

    </section>

</div>

<?php require_once '../../includes/footer.php'; ?>