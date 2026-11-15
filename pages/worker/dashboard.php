<?php

// ==========================================
// WORKER DASHBOARD
// ==========================================

// Page information
$required_role = 'worker';
$page_title = 'Dashboard';
$active_page = 'dashboard';

// Check authentication and worker role
require_once '../../includes/auth.php';

// Set the timezone to Philippine time
date_default_timezone_set('Asia/Manila');

// Get logged-in worker information
$user_id = $_SESSION['user']['id'];
$token = $_SESSION['access_token'];

$user_name = $profile[0]['full_name']
    ?? ($_SESSION['user']['user_metadata']['full_name'] ?? 'Worker');


// ==========================================
// PREPARE SUPABASE REQUESTS
// ==========================================

// Common headers for normal GET requests
$headers =
    "apikey: " . SUPABASE_KEY . "\r\n" .
    "Authorization: Bearer " . $token . "\r\n" .
    "Accept: application/json\r\n";

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true
    ]
]);

// Headers for requests that need exact record counts
$stats_context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' =>
        $headers .
            "Prefer: count=exact\r\n" .
            "Range: 0-0\r\n",
        'ignore_errors' => true
    ]
]);


// ==========================================
// INITIALIZE DASHBOARD DATA
// ==========================================

$recent_applications = [];
$recent_tasks = [];

$active_employment = '—';
$total_applications = '—';
$pending_offers = '—';
$tasks_in_progress = '—';

$approved_earnings = 0;
$pending_earnings = 0;


// ==========================================
// GET RECENT APPLICATIONS
// ==========================================

// Get the worker's five most recent applications
$applications_url = SUPABASE_URL .
    '/rest/v1/applications?select=id,status,applied_at,jobs(title,company_name,hourly_rate,employment_type)' .
    '&worker_id=eq.' . urlencode($user_id) .
    '&order=applied_at.desc&limit=5';

$applications_response = file_get_contents(
    $applications_url,
    false,
    $context
);

if ($applications_response !== false) {

    $applications_data = json_decode($applications_response, true);

    if (is_array($applications_data) && !isset($applications_data['code'])) {

        $recent_applications = $applications_data;
    }
}


// ==========================================
// ACTIVE EMPLOYMENT
// ==========================================

// Count the worker's active employments
$employment_url = SUPABASE_URL .
    '/rest/v1/employments?select=id' .
    '&worker_id=eq.' . urlencode($user_id) .
    '&status=eq.active';

$employment_response = file_get_contents(
    $employment_url,
    false,
    $stats_context
);

if ($employment_response !== false) {

    // Get the response headers from this request
    $employment_headers = http_get_last_response_headers();

    foreach ($employment_headers as $header) {

        if (stripos($header, 'Content-Range:') === 0) {

            preg_match('/\/(\d+)$/', $header, $matches);

            if (isset($matches[1])) {
                $active_employment = (int) $matches[1];
            }

            break;
        }
    }
}


// ==========================================
// TOTAL APPLICATIONS
// ==========================================

// Count all applications submitted by the worker
$applications_count_url = SUPABASE_URL .
    '/rest/v1/applications?select=id' .
    '&worker_id=eq.' . urlencode($user_id);

$applications_count_response = file_get_contents(
    $applications_count_url,
    false,
    $stats_context
);

if ($applications_count_response !== false) {

    // Get the response headers from this request
    $applications_headers = http_get_last_response_headers();

    foreach ($applications_headers as $header) {

        if (stripos($header, 'Content-Range:') === 0) {

            preg_match('/\/(\d+)$/', $header, $matches);

            if (isset($matches[1])) {
                $total_applications = (int) $matches[1];
            }

            break;
        }
    }
}


// ==========================================
// PENDING OFFERS
// ==========================================

// Count pending offers that have not expired
$offers_url = SUPABASE_URL .
    '/rest/v1/employment_offers?select=id' .
    '&worker_id=eq.' . urlencode($user_id) .
    '&status=eq.pending' .
    '&expires_at=gt.' . urlencode(gmdate('Y-m-d\TH:i:s\Z'));

$offers_response = file_get_contents(
    $offers_url,
    false,
    $stats_context
);

if ($offers_response !== false) {

    // Get the response headers from this request
    $offers_headers = http_get_last_response_headers();

    foreach ($offers_headers as $header) {

        if (stripos($header, 'Content-Range:') === 0) {

            preg_match('/\/(\d+)$/', $header, $matches);

            if (isset($matches[1])) {
                $pending_offers = (int) $matches[1];
            }

            break;
        }
    }
}


// ==========================================
// TASKS IN PROGRESS
// ==========================================

// Count tasks currently being worked on
$tasks_count_url = SUPABASE_URL .
    '/rest/v1/tasks?select=id' .
    '&worker_id=eq.' . urlencode($user_id) .
    '&status=eq.in_progress';

$tasks_count_response = file_get_contents(
    $tasks_count_url,
    false,
    $stats_context
);

if ($tasks_count_response !== false) {

    // Get the response headers from this request
    $tasks_headers = http_get_last_response_headers();

    foreach ($tasks_headers as $header) {

        if (stripos($header, 'Content-Range:') === 0) {

            preg_match('/\/(\d+)$/', $header, $matches);

            if (isset($matches[1])) {
                $tasks_in_progress = (int) $matches[1];
            }

            break;
        }
    }
}


// ==========================================
// GET RECENT TASKS
// ==========================================

// Get the worker's five most recent tasks
$tasks_url = SUPABASE_URL .
    '/rest/v1/tasks?select=id,title,description,priority,due_date,status,estimated_hours' .
    '&worker_id=eq.' . urlencode($user_id) .
    '&status=not.eq.cancelled' .
    '&order=due_date.asc.nullslast&limit=5';

$tasks_response = file_get_contents(
    $tasks_url,
    false,
    $context
);

if ($tasks_response !== false) {

    $tasks_data = json_decode($tasks_response, true);

    if (is_array($tasks_data) && !isset($tasks_data['code'])) {

        $recent_tasks = $tasks_data;
    }
}


// ==========================================
// GET EARNINGS SUMMARY
// ==========================================

// Retrieve approved and submitted earnings
$earnings_url = SUPABASE_URL .
    '/rest/v1/earnings_records?select=earnings_amount,timesheet_status' .
    '&worker_id=eq.' . urlencode($user_id) .
    '&timesheet_status=in.(approved,submitted)';

$earnings_response = file_get_contents(
    $earnings_url,
    false,
    $context
);

if ($earnings_response !== false) {

    $earnings_data = json_decode($earnings_response, true);

    if (is_array($earnings_data) && !isset($earnings_data['code'])) {

        foreach ($earnings_data as $record) {

            $amount = (float) ($record['earnings_amount'] ?? 0);

            // Add approved earnings
            if ($record['timesheet_status'] === 'approved') {

                $approved_earnings += $amount;
            }

            // Add pending earnings
            elseif ($record['timesheet_status'] === 'submitted') {

                $pending_earnings += $amount;
            }
        }
    }
}


// Include shared dashboard header
require_once '../../includes/header.php';

?>


<div class="wp-page">

    <!-- ======================================
         WELCOME SECTION
    ======================================= -->

    <section class="wp-dashboard-welcome">

        <div>

            <span class="wp-dashboard-eyebrow">
                WORKER WORKSPACE
            </span>

            <h1 class="wp-page-title">
                Welcome back,
                <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>!
            </h1>

            <p class="wp-page-description">
                Here's an overview of your work, applications, and opportunities.
            </p>

        </div>

        <a href="/pages/jobs.php" class="wp-btn wp-btn-primary">
            <i class="bi bi-search"></i>
            Explore Jobs
        </a>

    </section>


    <!-- ======================================
         DASHBOARD STATISTICS
    ======================================= -->

    <section class="wp-dashboard-stats">

        <!-- Active Employment -->

        <div class="wp-stat-card">

            <div class="wp-stat-top">

                <span>Active Employment</span>

                <div class="wp-stat-icon rose">
                    <i class="bi bi-briefcase"></i>
                </div>

            </div>

            <h2><?= $active_employment ?></h2>

            <small>Current active employments</small>

        </div>


        <!-- Applications -->

        <div class="wp-stat-card">

            <div class="wp-stat-top">

                <span>Applications</span>

                <div class="wp-stat-icon blue">
                    <i class="bi bi-file-earmark-text"></i>
                </div>

            </div>

            <h2><?= $total_applications ?></h2>

            <small>Total submitted applications</small>

        </div>


        <!-- Pending Offers -->

        <div class="wp-stat-card">

            <div class="wp-stat-top">

                <span>Pending Offers</span>

                <div class="wp-stat-icon yellow">
                    <i class="bi bi-envelope-open"></i>
                </div>

            </div>

            <h2><?= $pending_offers ?></h2>

            <small>Offers awaiting your response</small>

        </div>


        <!-- Tasks In Progress -->

        <div class="wp-stat-card">

            <div class="wp-stat-top">

                <span>Tasks In Progress</span>

                <div class="wp-stat-icon green">
                    <i class="bi bi-list-check"></i>
                </div>

            </div>

            <h2><?= $tasks_in_progress ?></h2>

            <small>Your ongoing assignments</small>

        </div>

    </section>


    <!-- ======================================
         EARNINGS OVERVIEW
    ======================================= -->

    <section class="wp-dashboard-columns wp-worker-earnings-overview">

        <div class="wp-panel">

            <div class="wp-panel-header">

                <div>

                    <h3 class="wp-panel-title">
                        Confirmed Earnings
                    </h3>

                    <p class="wp-panel-description">
                        Earnings from approved timesheets.
                    </p>

                </div>

                <div class="wp-stat-icon green">
                    <i class="bi bi-wallet2"></i>
                </div>

            </div>

            <div class="wp-panel-body">

                <h2 class="wp-worker-earnings-amount">
                    $<?= number_format($approved_earnings, 2) ?>
                </h2>

                <a href="/pages/worker/earnings.php" class="wp-dashboard-text-link">
                    View Earnings
                    <i class="bi bi-arrow-right"></i>
                </a>

            </div>

        </div>


        <div class="wp-panel">

            <div class="wp-panel-header">

                <div>

                    <h3 class="wp-panel-title">
                        Pending Earnings
                    </h3>

                    <p class="wp-panel-description">
                        Earnings awaiting employer approval.
                    </p>

                </div>

                <div class="wp-stat-icon yellow">
                    <i class="bi bi-hourglass-split"></i>
                </div>

            </div>

            <div class="wp-panel-body">

                <h2 class="wp-worker-earnings-amount">
                    $<?= number_format($pending_earnings, 2) ?>
                </h2>

                <a href="/pages/worker/timesheets.php" class="wp-dashboard-text-link">
                    View Timesheets
                    <i class="bi bi-arrow-right"></i>
                </a>

            </div>

        </div>

    </section>


    <!-- ======================================
         RECENT TASKS
    ======================================= -->

    <section class="wp-panel wp-worker-dashboard-tasks">

        <div class="wp-panel-header">

            <div>

                <h3 class="wp-panel-title">
                    My Recent Tasks
                </h3>

                <p class="wp-panel-description">
                    Keep track of your assigned work and upcoming deadlines.
                </p>

            </div>

            <a href="/pages/worker/tasks.php" class="wp-dashboard-text-link">
                View All
                <i class="bi bi-arrow-right"></i>
            </a>

        </div>


        <div class="wp-panel-body">

            <?php if (!empty($recent_tasks)): ?>

                <div class="wp-application-list">

                    <?php foreach ($recent_tasks as $task): ?>

                        <?php

                        $task_title = $task['title'] ?? 'Untitled Task';

                        $task_description = $task['description'] ?? '';

                        $task_status = $task['status'] ?? 'pending';

                        $task_priority = $task['priority'] ?? 'medium';

                        // Determine task status color
                        $task_status_class = 'neutral';

                        if ($task_status === 'completed') {

                            $task_status_class = 'success';
                        } elseif ($task_status === 'in_progress') {

                            $task_status_class = 'info';
                        } elseif ($task_status === 'pending') {

                            $task_status_class = 'warning';
                        }

                        // Determine task priority color
                        $priority_class = 'neutral';

                        if ($task_priority === 'high') {

                            $priority_class = 'danger';
                        } elseif ($task_priority === 'medium') {

                            $priority_class = 'warning';
                        } elseif ($task_priority === 'low') {

                            $priority_class = 'info';
                        }

                        // Format due date
                        $due_date_label = 'No deadline';

                        if (!empty($task['due_date'])) {

                            $due_timestamp = strtotime($task['due_date']);

                            if ($due_timestamp !== false) {
                                $due_date_label = date('M d, Y', $due_timestamp);
                            }
                        }

                        ?>

                        <div class="wp-application-row">

                            <div class="wp-application-info">

                                <h4>
                                    <?= htmlspecialchars($task_title, ENT_QUOTES, 'UTF-8') ?>
                                </h4>

                                <?php if ($task_description !== ''): ?>

                                    <p>
                                        <?= htmlspecialchars($task_description, ENT_QUOTES, 'UTF-8') ?>
                                    </p>

                                <?php endif; ?>

                                <div class="wp-application-meta">

                                    <span>
                                        <i class="bi bi-calendar-event"></i>
                                        <?= htmlspecialchars($due_date_label, ENT_QUOTES, 'UTF-8') ?>
                                    </span>

                                    <span>
                                        <i class="bi bi-flag"></i>
                                        <?= htmlspecialchars(ucfirst($task_priority), ENT_QUOTES, 'UTF-8') ?> priority
                                    </span>

                                    <?php if (!empty($task['estimated_hours'])): ?>

                                        <span>
                                            <i class="bi bi-clock"></i>
                                            <?= number_format((float)$task['estimated_hours'], 1) ?> estimated hours
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </div>


                            <div class="wp-worker-task-badges">

                                <span class="wp-application-status <?= $priority_class ?>">
                                    <?= htmlspecialchars(ucfirst($task_priority), ENT_QUOTES, 'UTF-8') ?>
                                </span>

                                <span class="wp-application-status <?= $task_status_class ?>">
                                    <?= htmlspecialchars(
                                        ucwords(str_replace('_', ' ', $task_status)),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <!-- Empty state -->

                <div class="wp-dashboard-empty">

                    <div class="wp-dashboard-empty-icon">
                        <i class="bi bi-list-check"></i>
                    </div>

                    <h4>No assigned tasks yet</h4>

                    <p>
                        Tasks assigned by your employer will appear here.
                        Once you have an active employment, you can start
                        working on your assignments.
                    </p>

                </div>

            <?php endif; ?>

        </div>

    </section>


    <!-- ======================================
         MAIN DASHBOARD COLUMNS
    ======================================= -->

    <section class="wp-dashboard-columns wp-worker-dashboard-bottom">


        <!-- ==================================
             RECENT APPLICATIONS
        =================================== -->

        <div class="wp-panel wp-dashboard-main-panel">

            <div class="wp-panel-header">

                <div>

                    <h3 class="wp-panel-title">
                        Recent Applications
                    </h3>

                    <p class="wp-panel-description">
                        Keep track of your submitted applications.
                    </p>

                </div>

                <a href="/pages/worker/applications.php"
                    class="wp-dashboard-text-link">

                    View All
                    <i class="bi bi-arrow-right"></i>

                </a>

            </div>


            <div class="wp-panel-body">

                <?php if (!empty($recent_applications)): ?>

                    <div class="wp-application-list">

                        <?php foreach ($recent_applications as $application): ?>

                            <?php

                            // Get related job information
                            $job = $application['jobs'] ?? [];

                            $job_title = $job['title'] ?? 'Untitled Job';

                            $company_name = $job['company_name'] ?? 'Unknown Company';

                            $hourly_rate = (float) ($job['hourly_rate'] ?? 0);

                            $employment_type = $job['employment_type'] ?? 'Not specified';

                            // Get application status
                            $status = $application['status'] ?? 'pending';

                            // Determine status badge color
                            $status_class = 'neutral';

                            if ($status === 'accepted') {

                                $status_class = 'success';
                            } elseif ($status === 'pending') {

                                $status_class = 'warning';
                            } elseif ($status === 'shortlisted' || $status === 'interview') {

                                $status_class = 'info';
                            } elseif ($status === 'rejected' || $status === 'withdrawn') {

                                $status_class = 'danger';
                            }

                            // Format application date
                            $applied_date = 'Date unavailable';

                            if (!empty($application['applied_at'])) {

                                $timestamp = strtotime($application['applied_at']);

                                if ($timestamp !== false) {
                                    $applied_date = date('M d, Y', $timestamp);
                                }
                            }

                            ?>

                            <div class="wp-application-row">

                                <div class="wp-application-info">

                                    <h4>
                                        <?= htmlspecialchars($job_title, ENT_QUOTES, 'UTF-8') ?>
                                    </h4>

                                    <p>
                                        <?= htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8') ?>
                                    </p>

                                    <div class="wp-application-meta">

                                        <span>
                                            $<?= number_format($hourly_rate, 2) ?>/hr
                                        </span>

                                        <span>
                                            <?= htmlspecialchars(
                                                ucwords(str_replace('_', ' ', $employment_type)),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </span>

                                        <span>
                                            Applied <?= htmlspecialchars($applied_date, ENT_QUOTES, 'UTF-8') ?>
                                        </span>

                                    </div>

                                </div>


                                <span class="wp-application-status <?= $status_class ?>">
                                    <?= htmlspecialchars(
                                        ucfirst($status),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <!-- Empty state -->

                    <div class="wp-dashboard-empty">

                        <div class="wp-dashboard-empty-icon">
                            <i class="bi bi-file-earmark-text"></i>
                        </div>

                        <h4>No recent applications</h4>

                        <p>
                            Your submitted applications will appear here.
                            Start exploring opportunities that match your skills.
                        </p>

                        <a href="/pages/jobs.php" class="wp-btn wp-btn-outline">
                            Find Opportunities
                        </a>

                    </div>

                <?php endif; ?>

            </div>

        </div>


        <!-- ==================================
             QUICK ACTIONS
        =================================== -->

        <div class="wp-panel wp-dashboard-side-panel">

            <div class="wp-panel-header">

                <div>

                    <h3 class="wp-panel-title">
                        Quick Actions
                    </h3>

                    <p class="wp-panel-description">
                        Get things done faster.
                    </p>

                </div>

            </div>


            <div class="wp-panel-body">

                <div class="wp-quick-actions">


                    <a href="/pages/jobs.php" class="wp-quick-action">

                        <div class="wp-quick-action-icon rose">
                            <i class="bi bi-search"></i>
                        </div>

                        <div>
                            <strong>Find Jobs</strong>
                            <small>Explore available opportunities</small>
                        </div>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                    <a href="/pages/worker/applications.php" class="wp-quick-action">

                        <div class="wp-quick-action-icon blue">
                            <i class="bi bi-file-earmark-text"></i>
                        </div>

                        <div>
                            <strong>My Applications</strong>
                            <small>Check your application progress</small>
                        </div>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                    <a href="/pages/worker/offers.php" class="wp-quick-action">

                        <div class="wp-quick-action-icon yellow">
                            <i class="bi bi-envelope-open"></i>
                        </div>

                        <div>
                            <strong>View Offers</strong>
                            <small>Review employer offers</small>
                        </div>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                    <a href="/pages/worker/time-tracker.php" class="wp-quick-action">

                        <div class="wp-quick-action-icon green">
                            <i class="bi bi-stopwatch"></i>
                        </div>

                        <div>
                            <strong>Time Tracker</strong>
                            <small>Track your working hours</small>
                        </div>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                    <a href="/pages/worker/profile.php" class="wp-quick-action">

                        <div class="wp-quick-action-icon rose">
                            <i class="bi bi-person"></i>
                        </div>

                        <div>
                            <strong>Update Profile</strong>
                            <small>Keep your professional profile updated</small>
                        </div>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                </div>

            </div>

        </div>


    </section>

</div>


<?php

// Include shared dashboard footer
require_once '../../includes/footer.php';

?>