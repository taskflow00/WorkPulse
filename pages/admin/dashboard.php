<?php

// Page information
$required_role = 'admin';
$page_title = 'Dashboard';
$active_page = 'dashboard';

// Check authentication and admin role
require_once '../../includes/auth.php';

$token = $_SESSION['access_token'];

// Dashboard counts
$dashboard_counts = [
    'workers' => 0,
    'employers' => 0,
    'jobs' => 0,
    'active_employments' => 0,
    'pending_applications' => 0,
    'pending_timesheets' => 0,
    'pending_proofs' => 0
];

// Supabase queries for dashboard statistics
$queries = [
    'workers' => 'profiles?select=id&role=eq.worker',
    'employers' => 'profiles?select=id&role=eq.employer',
    'jobs' => 'jobs?select=id',
    'active_employments' => 'employments?select=id&status=eq.active',
    'pending_applications' => 'applications?select=id&status=eq.pending',
    'pending_timesheets' => 'timesheets?select=id&status=eq.submitted',
    'pending_proofs' => 'daily_time_proofs?select=id&status=eq.pending'
];

$dashboard_error = '';

// Retrieve dashboard counts
foreach ($queries as $key => $query) {

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

    if ($response === false) {
        $dashboard_error = 'Unable to connect to Supabase.';
        continue;
    }

    $result = json_decode($response, true);

    if (is_array($result) && !isset($result['code'])) {
        $dashboard_counts[$key] = count($result);
    } else {
        $dashboard_error = 'Unable to retrieve some dashboard information.';
    }
}


// Retrieve the five most recent jobs
$url = SUPABASE_URL .
    '/rest/v1/jobs?select=id,title,company_name,status,created_at' .
    '&order=created_at.desc&limit=5';

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

$jobs_response = file_get_contents($url, false, $context);

$recent_jobs = [];

if ($jobs_response !== false) {

    $result = json_decode($jobs_response, true);

    if (is_array($result) && !isset($result['code'])) {
        $recent_jobs = $result;
    } else {
        $dashboard_error = 'Unable to retrieve recent job listings.';
    }
} else {
    $dashboard_error = 'Unable to retrieve recent job listings.';
}


// Display administrator name
$admin_name = $_SESSION['user']['user_metadata']['full_name']
    ?? 'Administrator';


// Load shared header
require_once '../../includes/header.php';

?>

<div class="wp-page">

    <!-- Welcome section -->
    <section class="wp-dashboard-welcome">

        <div>

            <span class="wp-dashboard-eyebrow">
                ADMINISTRATOR WORKSPACE
            </span>

            <h1 class="wp-page-title">
                Welcome back, <?= htmlspecialchars($admin_name, ENT_QUOTES, 'UTF-8') ?>!
            </h1>

            <p class="wp-page-description">
                Monitor platform activity and manage the WorkPulse community.
            </p>

        </div>

        <a href="/pages/admin/users.php" class="wp-btn wp-btn-primary">
            <i class="bi bi-people"></i>
            Manage Users
        </a>

    </section>


    <!-- Error message -->
    <?php if ($dashboard_error !== ''): ?>

        <div class="alert alert-warning mb-4" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?= htmlspecialchars($dashboard_error, ENT_QUOTES, 'UTF-8') ?>
        </div>

    <?php endif; ?>


    <!-- Platform statistics -->
    <section class="wp-dashboard-stats">

        <!-- Workers -->
        <div class="wp-stat-card">

            <div class="wp-stat-top">
                <span>Total Workers</span>

                <div class="wp-stat-icon rose">
                    <i class="bi bi-person-workspace"></i>
                </div>
            </div>

            <h2><?= number_format($dashboard_counts['workers']) ?></h2>

            <small>Registered worker accounts</small>

        </div>


        <!-- Employers -->
        <div class="wp-stat-card">

            <div class="wp-stat-top">
                <span>Total Employers</span>

                <div class="wp-stat-icon blue">
                    <i class="bi bi-building"></i>
                </div>
            </div>

            <h2><?= number_format($dashboard_counts['employers']) ?></h2>

            <small>Registered employer accounts</small>

        </div>


        <!-- Jobs -->
        <div class="wp-stat-card">

            <div class="wp-stat-top">
                <span>Job Listings</span>

                <div class="wp-stat-icon yellow">
                    <i class="bi bi-briefcase"></i>
                </div>
            </div>

            <h2><?= number_format($dashboard_counts['jobs']) ?></h2>

            <small>Total published job records</small>

        </div>


        <!-- Active employments -->
        <div class="wp-stat-card">

            <div class="wp-stat-top">
                <span>Active Employments</span>

                <div class="wp-stat-icon green">
                    <i class="bi bi-person-check"></i>
                </div>
            </div>

            <h2><?= number_format($dashboard_counts['active_employments']) ?></h2>

            <small>Currently active employment records</small>

        </div>


        <!-- Pending applications -->
        <div class="wp-stat-card">

            <div class="wp-stat-top">
                <span>Pending Applications</span>

                <div class="wp-stat-icon yellow">
                    <i class="bi bi-file-earmark-text"></i>
                </div>
            </div>

            <h2><?= number_format($dashboard_counts['pending_applications']) ?></h2>

            <small>Applications awaiting review</small>

        </div>


        <!-- Pending timesheets -->
        <div class="wp-stat-card">

            <div class="wp-stat-top">
                <span>Pending Timesheets</span>

                <div class="wp-stat-icon blue">
                    <i class="bi bi-calendar-check"></i>
                </div>
            </div>

            <h2><?= number_format($dashboard_counts['pending_timesheets']) ?></h2>

            <small>Submitted timesheets</small>

        </div>


        <!-- Pending proofs -->
        <div class="wp-stat-card">

            <div class="wp-stat-top">
                <span>Pending Time Proofs</span>

                <div class="wp-stat-icon rose">
                    <i class="bi bi-clock-history"></i>
                </div>
            </div>

            <h2><?= number_format($dashboard_counts['pending_proofs']) ?></h2>

            <small>Time records awaiting review</small>

        </div>

    </section>


    <!-- Main dashboard content -->
    <section class="wp-dashboard-admin-grid">


        <!-- Recent job listings -->
        <div class="wp-panel">

            <div class="wp-panel-header">

                <div>
                    <h3 class="wp-panel-title">
                        Recent Job Listings
                    </h3>

                    <p class="wp-panel-description">
                        Latest opportunities published on WorkPulse.
                    </p>
                </div>

                <a href="/pages/admin/jobs.php" class="wp-dashboard-text-link">
                    View All
                    <i class="bi bi-arrow-right"></i>
                </a>

            </div>


            <div class="wp-panel-body wp-admin-table-wrapper">

                <?php if (count($recent_jobs) > 0): ?>

                    <table class="wp-admin-table">

                        <thead>
                            <tr>
                                <th>Job Details</th>
                                <th>Company</th>
                                <th>Status</th>
                                <th>Date Posted</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($recent_jobs as $job): ?>

                                <?php

                                $job_status = $job['status'] ?? 'unknown';

                                $status_class = 'neutral';

                                if ($job_status === 'open') {
                                    $status_class = 'success';
                                } elseif ($job_status === 'in_progress') {
                                    $status_class = 'info';
                                } elseif ($job_status === 'completed') {
                                    $status_class = 'success';
                                } elseif ($job_status === 'cancelled') {
                                    $status_class = 'danger';
                                }

                                ?>

                                <tr>

                                    <td>
                                        <strong>
                                            <?= htmlspecialchars($job['title'] ?? 'Untitled Job', ENT_QUOTES, 'UTF-8') ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($job['company_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                    </td>

                                    <td>
                                        <span class="wp-badge wp-badge-<?= $status_class ?>">
                                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $job_status)), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= !empty($job['created_at'])
                                            ? htmlspecialchars(date('M d, Y', strtotime($job['created_at'])), ENT_QUOTES, 'UTF-8')
                                            : '—' ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php else: ?>

                    <div class="wp-dashboard-empty">

                        <div class="wp-dashboard-empty-icon">
                            <i class="bi bi-briefcase"></i>
                        </div>

                        <h4>No job listings found</h4>

                        <p>
                            Job listings will appear here once employers
                            publish opportunities.
                        </p>

                    </div>

                <?php endif; ?>

            </div>

        </div>


        <!-- Platform management -->
        <div class="wp-panel">

            <div class="wp-panel-header">

                <div>
                    <h3 class="wp-panel-title">
                        Platform Management
                    </h3>

                    <p class="wp-panel-description">
                        Important administration shortcuts.
                    </p>
                </div>

            </div>


            <div class="wp-panel-body">

                <div class="wp-quick-actions">

                    <a href="/pages/admin/users.php" class="wp-quick-action">

                        <div class="wp-quick-action-icon rose">
                            <i class="bi bi-people"></i>
                        </div>

                        <div>
                            <strong>Manage Users</strong>
                            <small>Review worker and employer accounts</small>
                        </div>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                    <a href="/pages/admin/jobs.php" class="wp-quick-action">

                        <div class="wp-quick-action-icon blue">
                            <i class="bi bi-briefcase"></i>
                        </div>

                        <div>
                            <strong>Manage Jobs</strong>
                            <small>Monitor platform job listings</small>
                        </div>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                    <a href="/pages/admin/applications.php" class="wp-quick-action">

                        <div class="wp-quick-action-icon yellow">
                            <i class="bi bi-file-earmark-text"></i>
                        </div>

                        <div>
                            <strong>Applications</strong>
                            <small>Review application activity</small>
                        </div>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                    <a href="/pages/admin/employments.php" class="wp-quick-action">

                        <div class="wp-quick-action-icon green">
                            <i class="bi bi-person-check"></i>
                        </div>

                        <div>
                            <strong>Employments</strong>
                            <small>Monitor active work agreements</small>
                        </div>

                        <i class="bi bi-chevron-right"></i>

                    </a>

                </div>

            </div>

        </div>

    </section>

</div>

<?php

// Load shared footer
require_once '../../includes/footer.php';

?>