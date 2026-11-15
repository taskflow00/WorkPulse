<?php

// Page information
$required_role = 'admin';
$page_title = 'Application Management';
$active_page = 'applications';
$page_styles = ['/assets/css/admin-applications.css'];

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

$allowed_statuses = [
    'all',
    'pending',
    'shortlisted',
    'interview',
    'accepted',
    'rejected',
    'withdrawn'
];

// Validate status filter
if (!in_array($status, $allowed_statuses, true)) {
    $status = 'all';
}


// Retrieve applications and related information
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

$response = file_get_contents(
    $url,
    false,
    stream_context_create($options)
);

$applications = [];
$all_applications = [];
$error = '';

$application_counts = [
    'total' => 0,
    'pending' => 0,
    'shortlisted' => 0,
    'interview' => 0,
    'accepted' => 0,
    'rejected' => 0,
    'withdrawn' => 0
];


if ($response === false) {

    $error = 'Unable to connect to Supabase.';
} else {

    $result = json_decode($response, true);

    if (is_array($result) && !isset($result['code'])) {

        $all_applications = $result;

        // Count application statuses before applying filters
        foreach ($all_applications as $application) {

            $application_status = $application['status'] ?? '';

            if (isset($application_counts[$application_status])) {
                $application_counts[$application_status]++;
            }

            $application_counts['total']++;
        }


        // Apply search and status filters
        foreach ($all_applications as $application) {

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
                $matches_status = ($application['status'] ?? '') === $status;
            }

            if ($matches_search && $matches_status) {
                $applications[] = $application;
            }
        }
    } else {

        $error = 'Unable to retrieve applications.';
    }
}


// Load shared header
require_once '../../includes/header.php';

?>

<div class="wp-page admin-applications-page">

    <!-- Page heading -->
    <section class="wp-page-heading admin-applications-heading">

        <div>
            <span class="admin-applications-eyebrow">PLATFORM ADMINISTRATION</span>

            <h1 class="wp-page-title">Application Management</h1>

            <p class="wp-page-description">
                Monitor job applications submitted across WorkPulse.
            </p>
        </div>

        <div class="admin-applications-total">
            <i class="bi bi-file-earmark-text"></i>

            <div>
                <strong><?= number_format($application_counts['total']) ?></strong>
                <span>Total Applications</span>
            </div>
        </div>

    </section>


    <!-- Error message -->
    <?php if ($error !== ''): ?>

        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>

    <?php endif; ?>


    <!-- Application statistics -->
    <section class="admin-applications-stats">

        <div class="admin-application-stat">
            <div class="admin-application-stat-icon pending">
                <i class="bi bi-hourglass-split"></i>
            </div>

            <div>
                <span>Pending</span>
                <strong><?= number_format($application_counts['pending']) ?></strong>
            </div>
        </div>


        <div class="admin-application-stat">
            <div class="admin-application-stat-icon interview">
                <i class="bi bi-calendar-event"></i>
            </div>

            <div>
                <span>Interview</span>
                <strong><?= number_format($application_counts['interview']) ?></strong>
            </div>
        </div>


        <div class="admin-application-stat">
            <div class="admin-application-stat-icon accepted">
                <i class="bi bi-check-circle"></i>
            </div>

            <div>
                <span>Accepted</span>
                <strong><?= number_format($application_counts['accepted']) ?></strong>
            </div>
        </div>


        <div class="admin-application-stat">
            <div class="admin-application-stat-icon rejected">
                <i class="bi bi-x-circle"></i>
            </div>

            <div>
                <span>Rejected</span>
                <strong><?= number_format($application_counts['rejected']) ?></strong>
            </div>
        </div>

    </section>


    <!-- Applications panel -->
    <section class="wp-panel admin-applications-panel">

        <div class="wp-panel-header admin-applications-panel-header">

            <div>
                <h3 class="wp-panel-title">All Applications</h3>

                <p class="wp-panel-description">
                    Review applicant details and application statuses.
                </p>
            </div>

            <span class="admin-applications-count">
                <?= number_format(count($applications)) ?> results
            </span>

        </div>


        <div class="wp-panel-body">

            <!-- Search and filters -->
            <form method="GET" action="" class="admin-applications-filters">

                <div class="admin-applications-search">

                    <i class="bi bi-search"></i>

                    <input
                        type="text"
                        name="search"
                        placeholder="Search applicant, job, or company..."
                        value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">

                </div>


                <select name="status" class="form-select admin-applications-status-filter">

                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>
                        All Applications
                    </option>

                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>
                        Pending
                    </option>

                    <option value="shortlisted" <?= $status === 'shortlisted' ? 'selected' : '' ?>>
                        Shortlisted
                    </option>

                    <option value="interview" <?= $status === 'interview' ? 'selected' : '' ?>>
                        Interview
                    </option>

                    <option value="accepted" <?= $status === 'accepted' ? 'selected' : '' ?>>
                        Accepted
                    </option>

                    <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>
                        Rejected
                    </option>

                    <option value="withdrawn" <?= $status === 'withdrawn' ? 'selected' : '' ?>>
                        Withdrawn
                    </option>

                </select>


                <button type="submit" class="wp-btn wp-btn-primary">
                    <i class="bi bi-funnel"></i>
                    Filter
                </button>


                <?php if ($search !== '' || $status !== 'all'): ?>

                    <a href="applications.php" class="wp-btn wp-btn-secondary">
                        Clear
                    </a>

                <?php endif; ?>

            </form>


            <!-- Applications table -->
            <?php if ($error === '' && count($applications) > 0): ?>

                <div class="table-responsive admin-applications-table-wrapper">

                    <table class="admin-applications-table">

                        <thead>
                            <tr>
                                <th>Applicant</th>
                                <th>Job Applied For</th>
                                <th>Application Statement</th>
                                <th>Status</th>
                                <th>Date Applied</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($applications as $application): ?>

                                <?php
                                $application_status = $application['status'] ?? 'unknown';

                                $status_classes = [
                                    'pending' => 'pending',
                                    'shortlisted' => 'shortlisted',
                                    'interview' => 'interview',
                                    'accepted' => 'accepted',
                                    'rejected' => 'rejected',
                                    'withdrawn' => 'withdrawn'
                                ];

                                $status_class = $status_classes[$application_status] ?? 'unknown';

                                $worker_name = $application['worker']['full_name'] ?? 'Unknown Applicant';
                                $job_title = $application['job']['title'] ?? 'Unknown Job';
                                $company_name = $application['job']['company_name'] ?? 'Unknown Company';
                                ?>

                                <tr>

                                    <td>
                                        <div class="admin-application-applicant">
                                            <div class="admin-application-avatar">
                                                <?= htmlspecialchars(strtoupper(substr(trim($worker_name), 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                            </div>

                                            <strong>
                                                <?= htmlspecialchars($worker_name, ENT_QUOTES, 'UTF-8') ?>
                                            </strong>
                                        </div>
                                    </td>


                                    <td>
                                        <div class="admin-application-job">
                                            <strong>
                                                <?= htmlspecialchars($job_title, ENT_QUOTES, 'UTF-8') ?>
                                            </strong>

                                            <span>
                                                <?= htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </div>
                                    </td>


                                    <td>
                                        <?php if (!empty($application['application_statement'])): ?>

                                            <details class="admin-application-statement">

                                                <summary>View statement</summary>

                                                <p>
                                                    <?= nl2br(htmlspecialchars(
                                                        $application['application_statement'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    )) ?>
                                                </p>

                                            </details>

                                        <?php else: ?>

                                            <span class="text-muted">No statement provided</span>

                                        <?php endif; ?>
                                    </td>


                                    <td>
                                        <span class="admin-application-status <?= $status_class ?>">
                                            <?= htmlspecialchars(ucfirst($application_status), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>


                                    <td>
                                        <span class="admin-application-date">
                                            <?= !empty($application['applied_at'])
                                                ? htmlspecialchars(date('M d, Y', strtotime($application['applied_at'])), ENT_QUOTES, 'UTF-8')
                                                : '—' ?>
                                        </span>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php elseif ($error === ''): ?>

                <div class="admin-applications-empty">

                    <div class="admin-applications-empty-icon">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>

                    <h4>No applications found</h4>

                    <p>
                        Try changing your search or application status filter.
                    </p>

                    <?php if ($search !== '' || $status !== 'all'): ?>

                        <a href="applications.php" class="wp-btn wp-btn-secondary">
                            Clear Filters
                        </a>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </div>

    </section>

</div>

<?php require_once '../../includes/footer.php'; ?>