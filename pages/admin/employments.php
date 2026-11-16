<?php

// Page information
$required_role = 'admin';
$page_title = 'Employment Management';
$active_page = 'employments';
$page_styles = ['/assets/css/admin-employments.css'];

// Check authentication and admin role
require_once '../../includes/auth.php';

date_default_timezone_set('Asia/Manila');

$token = $_SESSION['access_token'];


// Get search and status filters
$search = isset($_GET['search']) && is_string($_GET['search'])
    ? trim($_GET['search'])
    : '';

$status = isset($_GET['status']) && is_string($_GET['status'])
    ? $_GET['status']
    : 'all';

if (!in_array($status, ['all', 'active', 'ended'], true)) {
    $status = 'all';
}


// Retrieve employment records
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
$profiles = [];
$jobs = [];
$errors = [];


// Check employment response
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


// Retrieve worker and employer names
$url = SUPABASE_URL .
    '/rest/v1/profiles?select=id,full_name&limit=1000';

$response = file_get_contents($url, false, $context);

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


// Retrieve job titles and company names
$url = SUPABASE_URL .
    '/rest/v1/jobs?select=id,title,company_name&limit=1000';

$response = file_get_contents($url, false, $context);

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


// Create profile lookup
$profile_names = [];

foreach ($profiles as $profile) {
    $profile_names[$profile['id']] = $profile['full_name'] ?? 'Unknown';
}


// Create job lookup
$job_details = [];

foreach ($jobs as $job) {
    $job_details[$job['id']] = $job;
}


// Add names and job details to employment records
$all_employments = [];

foreach ($employments as $employment) {

    $worker_name = $profile_names[$employment['worker_id']] ?? 'Unknown';
    $employer_name = $profile_names[$employment['employer_id']] ?? 'Unknown';

    $job = $job_details[$employment['job_id']] ?? [];

    $employment['worker_name'] = $worker_name;
    $employment['employer_name'] = $employer_name;
    $employment['job_title'] = $job['title'] ?? 'Unknown';
    $employment['company_name'] = $job['company_name'] ?? 'Unknown';

    $all_employments[] = $employment;
}


// Calculate employment statistics
$employment_counts = [
    'total' => count($all_employments),
    'active' => 0,
    'ended' => 0
];

foreach ($all_employments as $employment) {

    if ($employment['status'] === 'active') {
        $employment_counts['active']++;
    } elseif ($employment['status'] === 'ended') {
        $employment_counts['ended']++;
    }
}


// Apply search and status filters
$filtered_employments = [];

foreach ($all_employments as $employment) {

    $matches_search = true;
    $matches_status = true;

    if ($search !== '') {

        $matches_search =
            stripos($employment['worker_name'], $search) !== false ||
            stripos($employment['employer_name'], $search) !== false ||
            stripos($employment['job_title'], $search) !== false ||
            stripos($employment['company_name'], $search) !== false;
    }

    if ($status !== 'all') {
        $matches_status = $employment['status'] === $status;
    }

    if ($matches_search && $matches_status) {
        $filtered_employments[] = $employment;
    }
}


// Load shared layout
require_once '../../includes/header.php';

?>

<div class="wp-page admin-employments-page">

    <!-- Page heading -->
    <section class="admin-employments-heading">

        <div>
            <span class="admin-employments-eyebrow">PLATFORM ADMINISTRATION</span>

            <h1 class="wp-page-title">Employment Management</h1>

            <p class="wp-page-description">
                Monitor active and ended employment relationships across WorkPulse.
            </p>
        </div>

        <div class="admin-employments-total">
            <i class="bi bi-people"></i>

            <div>
                <strong><?= number_format($employment_counts['total']) ?></strong>
                <span>Total Records</span>
            </div>
        </div>

    </section>


    <!-- Error messages -->
    <?php if (count($errors) > 0): ?>

        <div class="alert alert-danger" role="alert">

            <strong>
                <i class="bi bi-exclamation-triangle me-2"></i>
                Unable to load all employment information
            </strong>

            <?php foreach ($errors as $error): ?>
                <p class="mb-0 mt-1">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php endforeach; ?>

        </div>

    <?php endif; ?>


    <!-- Statistics -->
    <section class="admin-employments-stats">

        <div class="admin-employment-stat">
            <div class="admin-employment-stat-icon total">
                <i class="bi bi-briefcase"></i>
            </div>

            <div>
                <span>Total Records</span>
                <strong><?= number_format($employment_counts['total']) ?></strong>
            </div>
        </div>


        <div class="admin-employment-stat">
            <div class="admin-employment-stat-icon active">
                <i class="bi bi-check-circle"></i>
            </div>

            <div>
                <span>Active</span>
                <strong><?= number_format($employment_counts['active']) ?></strong>
            </div>
        </div>


        <div class="admin-employment-stat">
            <div class="admin-employment-stat-icon ended">
                <i class="bi bi-clock-history"></i>
            </div>

            <div>
                <span>Ended</span>
                <strong><?= number_format($employment_counts['ended']) ?></strong>
            </div>
        </div>

    </section>


    <!-- Employment records -->
    <section class="wp-panel admin-employments-panel">

        <div class="wp-panel-header admin-employments-panel-header">

            <div>
                <h3 class="wp-panel-title">All Employment Records</h3>

                <p class="wp-panel-description">
                    View workers, employers, job assignments, and employment history.
                </p>
            </div>

            <span class="admin-employments-count">
                <?= number_format(count($filtered_employments)) ?> results
            </span>

        </div>


        <div class="wp-panel-body">

            <!-- Search and filters -->
            <form method="GET" action="" class="admin-employments-filters">

                <div class="admin-employments-search">
                    <i class="bi bi-search"></i>

                    <input
                        type="text"
                        name="search"
                        placeholder="Search worker, employer, or job..."
                        value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                </div>


                <select name="status" class="form-select admin-employments-status-filter">

                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>
                        All Employments
                    </option>

                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>
                        Active
                    </option>

                    <option value="ended" <?= $status === 'ended' ? 'selected' : '' ?>>
                        Ended
                    </option>

                </select>


                <button type="submit" class="wp-btn wp-btn-primary">
                    <i class="bi bi-funnel"></i>
                    Filter
                </button>


                <?php if ($search !== '' || $status !== 'all'): ?>

                    <a href="employments.php" class="wp-btn wp-btn-secondary">
                        Clear
                    </a>

                <?php endif; ?>

            </form>


            <!-- Table -->
            <?php if (count($errors) === 0 && count($filtered_employments) > 0): ?>

                <div class="table-responsive admin-employments-table-wrapper">

                    <table class="admin-employments-table">

                        <thead>
                            <tr>
                                <th>Worker</th>
                                <th>Employer</th>
                                <th>Job</th>
                                <th>Hourly Rate</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Started</th>
                                <th>Ended</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($filtered_employments as $employment): ?>

                                <?php
                                $employment_status = $employment['status'] ?? 'unknown';

                                $status_class = $employment_status === 'active'
                                    ? 'active'
                                    : ($employment_status === 'ended' ? 'ended' : 'unknown');

                                $employment_type = ucfirst(
                                    str_replace('_', ' ', $employment['employment_type'] ?? 'Unknown')
                                );
                                ?>

                                <tr>

                                    <td>
                                        <div class="admin-employment-person">
                                            <div class="admin-employment-avatar">
                                                <?= htmlspecialchars(
                                                    strtoupper(substr(trim($employment['worker_name']), 0, 1)),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </div>

                                            <strong>
                                                <?= htmlspecialchars($employment['worker_name'], ENT_QUOTES, 'UTF-8') ?>
                                            </strong>
                                        </div>
                                    </td>


                                    <td>
                                        <span class="admin-employment-name">
                                            <?= htmlspecialchars($employment['employer_name'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>


                                    <td>
                                        <div class="admin-employment-job">
                                            <strong>
                                                <?= htmlspecialchars($employment['job_title'], ENT_QUOTES, 'UTF-8') ?>
                                            </strong>

                                            <span>
                                                <?= htmlspecialchars($employment['company_name'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </div>
                                    </td>


                                    <td class="admin-employment-rate">
                                        $<?= number_format((float) $employment['hourly_rate'], 2) ?>
                                    </td>


                                    <td>
                                        <?= htmlspecialchars($employment_type, ENT_QUOTES, 'UTF-8') ?>
                                    </td>


                                    <td>
                                        <span class="admin-employment-status <?= $status_class ?>">
                                            <?= htmlspecialchars(ucfirst($employment_status), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>


                                    <td class="admin-employment-date">
                                        <?= !empty($employment['started_at'])
                                            ? htmlspecialchars(date('M d, Y', strtotime($employment['started_at'])), ENT_QUOTES, 'UTF-8')
                                            : '—' ?>
                                    </td>


                                    <td class="admin-employment-date">
                                        <?= !empty($employment['ended_at'])
                                            ? htmlspecialchars(date('M d, Y', strtotime($employment['ended_at'])), ENT_QUOTES, 'UTF-8')
                                            : '—' ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php elseif (count($errors) === 0): ?>

                <div class="admin-employments-empty">

                    <div class="admin-employments-empty-icon">
                        <i class="bi bi-people"></i>
                    </div>

                    <h4>No employment records found</h4>

                    <p>
                        Try changing your search or employment status filter.
                    </p>

                    <?php if ($search !== '' || $status !== 'all'): ?>

                        <a href="employments.php" class="wp-btn wp-btn-secondary">
                            Clear Filters
                        </a>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </div>

    </section>

</div>

<?php require_once '../../includes/footer.php'; ?>