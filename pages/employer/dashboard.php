<<<<<<< HEAD

=======
>>>>>>> f7f1bd69f0d5af8e65e63b6dff999b1d558d685a
<?php

// ==========================================
// EMPLOYER DASHBOARD
// ==========================================

// Page information
$required_role = 'employer';
$page_title = 'Dashboard';
$active_page = 'dashboard';

// Check authentication and employer role
require_once '../../includes/auth.php';

// Set the timezone to Philippine time
date_default_timezone_set('Asia/Manila');

// Get logged-in employer information
$user_id = $_SESSION['user']['id'];
$token = $_SESSION['access_token'];

$user_name = $profile[0]['full_name']
    ?? $_SESSION['user']['user_metadata']['full_name']
    ?? 'Employer';


// ==========================================
// GET DASHBOARD STATISTICS
// ==========================================

// Prepare the GET request settings for statistics
$stats_context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' =>
<<<<<<< HEAD
            "apikey: " . SUPABASE_KEY . "\r\n" .
=======
        "apikey: " . SUPABASE_KEY . "\r\n" .
>>>>>>> f7f1bd69f0d5af8e65e63b6dff999b1d558d685a
            "Authorization: Bearer " . $token . "\r\n" .
            "Accept: application/json\r\n" .
            "Prefer: count=exact\r\n" .
            "Range: 0-0\r\n",
        'ignore_errors' => true
    ]
]);


// ==========================================
// ACTIVE JOB LISTINGS
// ==========================================

// Get the employer's currently published jobs
$jobs_count_url = SUPABASE_URL .
    '/rest/v1/jobs?select=id' .
    '&employer_id=eq.' . urlencode($user_id) .
    '&status=eq.open';

$jobs_count_response = file_get_contents(
    $jobs_count_url,
    false,
    $stats_context
);

<<<<<<< HEAD
// Capture the response headers immediately
$jobs_count_headers = $http_response_header ?? [];

=======
>>>>>>> f7f1bd69f0d5af8e65e63b6dff999b1d558d685a
$active_jobs = '—';

if ($jobs_count_response !== false) {

<<<<<<< HEAD
    foreach ($jobs_count_headers as $header) {
=======
    foreach (http_get_last_response_headers() as $header) {
>>>>>>> f7f1bd69f0d5af8e65e63b6dff999b1d558d685a

        if (stripos($header, 'Content-Range:') === 0) {

            preg_match('/\/(\d+)$/', $header, $matches);

            if (isset($matches[1])) {
<<<<<<< HEAD
                $active_jobs = (int) $matches[1];
=======
                $active_jobs = (int)$matches[1];
>>>>>>> f7f1bd69f0d5af8e65e63b6dff999b1d558d685a
            }

            break;
        }
    }
}


// ==========================================
// PENDING APPLICATIONS
// ==========================================

// Get pending applications submitted to the employer's jobs
$applications_count_url = SUPABASE_URL .
    '/rest/v1/applications?select=id,jobs!inner(id)' .
    '&jobs.employer_id=eq.' . urlencode($user_id) .
    '&status=eq.pending';

$applications_count_response = file_get_contents(
    $applications_count_url,
    false,
    $stats_context
);

<<<<<<< HEAD
// Capture the response headers immediately
$applications_count_headers = $http_response_header ?? [];

=======
>>>>>>> f7f1bd69f0d5af8e65e63b6dff999b1d558d685a
$pending_applications = '—';

if ($applications_count_response !== false) {

<<<<<<< HEAD
    foreach ($applications_count_headers as $header) {
=======
    foreach (http_get_last_response_headers() as $header) {
>>>>>>> f7f1bd69f0d5af8e65e63b6dff999b1d558d685a

        if (stripos($header, 'Content-Range:') === 0) {

            preg_match('/\/(\d+)$/', $header, $matches);

            if (isset($matches[1])) {
<<<<<<< HEAD
                $pending_applications = (int) $matches[1];
=======
                $pending_applications = (int)$matches[1];
>>>>>>> f7f1bd69f0d5af8e65e63b6dff999b1d558d685a
            }

            break;
        }
    }
}


// ==========================================
// CURRENT EMPLOYEES
// ==========================================

// Get the employer's active employment records
$employees_count_url = SUPABASE_URL .
    '/rest/v1/employments?select=id' .
    '&employer_id=eq.' . urlencode($user_id) .
    '&status=eq.active';

$employees_count_response = file_get_contents(
    $employees_count_url,
    false,
    $stats_context
);

<<<<<<< HEAD
// Capture the response headers immediately
$employees_count_headers = $http_response_header ?? [];

=======
>>>>>>> f7f1bd69f0d5af8e65e63b6dff999b1d558d685a
$current_employees = '—';

if ($employees_count_response !== false) {

<<<<<<< HEAD
    foreach ($employees_count_headers as $header) {
=======
    foreach (http_get_last_response_headers() as $header) {
>>>>>>> f7f1bd69f0d5af8e65e63b6dff999b1d558d685a

        if (stripos($header, 'Content-Range:') === 0) {

            preg_match('/\/(\d+)$/', $header, $matches);

            if (isset($matches[1])) {
<<<<<<< HEAD
                $current_employees = (int) $matches[1];
=======
                $current_employees = (int)$matches[1];
>>>>>>> f7f1bd69f0d5af8e65e63b6dff999b1d558d685a
            }

            break;
        }
    }
}


// ==========================================
// MONTHLY LABOR COSTS
// ==========================================

// Get the first day of the current month
$current_month = date('Y-m-01');

// Get approved earnings for the current month
$earnings_url = SUPABASE_URL .
    '/rest/v1/earnings_records?select=earnings_amount' .
    '&employer_id=eq.' . urlencode($user_id) .
    '&timesheet_status=eq.approved' .
    '&month=eq.' . urlencode($current_month);

// Prepare the GET request
$earnings_context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' =>
<<<<<<< HEAD
            "apikey: " . SUPABASE_KEY . "\r\n" .
=======
        "apikey: " . SUPABASE_KEY . "\r\n" .
>>>>>>> f7f1bd69f0d5af8e65e63b6dff999b1d558d685a
            "Authorization: Bearer " . $token . "\r\n" .
            "Accept: application/json\r\n",
        'ignore_errors' => true
    ]
]);

// Send request to Supabase
$earnings_response = file_get_contents(
    $earnings_url,
    false,
    $earnings_context
);

$monthly_labor_costs = '—';

if ($earnings_response !== false) {

    $earnings_records = json_decode($earnings_response, true);

    if (is_array($earnings_records) && !isset($earnings_records['message'])) {

        // Calculate the total approved earnings
        $total_earnings = 0;

        foreach ($earnings_records as $record) {

<<<<<<< HEAD
            $total_earnings += (float) ($record['earnings_amount'] ?? 0);
=======
            $total_earnings += (float)($record['earnings_amount'] ?? 0);
>>>>>>> f7f1bd69f0d5af8e65e63b6dff999b1d558d685a
        }

        $monthly_labor_costs = '$' . number_format($total_earnings, 2);
    }
}


// ==========================================
// GET RECENT APPLICATIONS
// ==========================================

// Get the employer's five most recent applications
$applications_url = SUPABASE_URL .
    '/rest/v1/applications?select=id,status,applied_at,jobs!inner(title,company_name),profiles(full_name)' .
    '&jobs.employer_id=eq.' . urlencode($user_id) .
    '&order=applied_at.desc&limit=5';

// Prepare the GET request
$applications_context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' =>
<<<<<<< HEAD
            "apikey: " . SUPABASE_KEY . "\r\n" .
=======
        "apikey: " . SUPABASE_KEY . "\r\n" .
>>>>>>> f7f1bd69f0d5af8e65e63b6dff999b1d558d685a
            "Authorization: Bearer " . $token . "\r\n" .
            "Accept: application/json\r\n",
        'ignore_errors' => true
    ]
]);

// Send request to Supabase
$applications_response = file_get_contents(
    $applications_url,
    false,
    $applications_context
);

// Convert the response into a PHP array
$recent_applications = [];

if ($applications_response !== false) {

    $recent_applications = json_decode($applications_response, true);

    if (!is_array($recent_applications) || isset($recent_applications['message'])) {
        $recent_applications = [];
    }
}


// ==========================================
// GET RECENT JOB LISTINGS
// ==========================================

// Get the employer's five most recently created jobs
$jobs_url = SUPABASE_URL .
    '/rest/v1/jobs?select=id,title,company_name,status,employment_type,hourly_rate,created_at' .
    '&employer_id=eq.' . urlencode($user_id) .
    '&order=created_at.desc&limit=5';

// Prepare the GET request
$jobs_context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' =>
<<<<<<< HEAD
            "apikey: " . SUPABASE_KEY . "\r\n" .
=======
        "apikey: " . SUPABASE_KEY . "\r\n" .
>>>>>>> f7f1bd69f0d5af8e65e63b6dff999b1d558d685a
            "Authorization: Bearer " . $token . "\r\n" .
            "Accept: application/json\r\n",
        'ignore_errors' => true
    ]
]);

// Send request to Supabase
$jobs_response = file_get_contents(
    $jobs_url,
    false,
    $jobs_context
);

// Convert the response into a PHP array
$recent_jobs = [];

if ($jobs_response !== false) {

    $recent_jobs = json_decode($jobs_response, true);

    if (!is_array($recent_jobs) || isset($recent_jobs['message'])) {
        $recent_jobs = [];
    }
}


// Include shared dashboard header
require_once '../../includes/header.php';

?>


<div class="wp-page">

    <!-- Welcome section -->
    <section class="wp-dashboard-welcome">

        <div>

            <span class="wp-dashboard-eyebrow">
                EMPLOYER WORKSPACE
            </span>

            <h1 class="wp-page-title">
                Welcome back,
                <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>!
            </h1>

            <p class="wp-page-description">
                Manage your jobs, employees, applications, and workforce activity.
            </p>

        </div>

        <a href="/pages/employer/create-job.php" class="wp-btn wp-btn-primary">
            <i class="bi bi-plus-lg"></i>
            Create a Job
        </a>

    </section>


    <!-- Dashboard statistics -->
    <section class="wp-dashboard-stats">

        <div class="wp-stat-card">

            <div class="wp-stat-top">

                <span>Active Job Listings</span>

                <div class="wp-stat-icon rose">
                    <i class="bi bi-briefcase"></i>
                </div>

            </div>

            <h2><?= $active_jobs ?></h2>

            <small>Currently published jobs</small>

        </div>


        <div class="wp-stat-card">

            <div class="wp-stat-top">

                <span>Pending Applications</span>

                <div class="wp-stat-icon yellow">
                    <i class="bi bi-file-earmark-text"></i>
                </div>

            </div>

            <h2><?= $pending_applications ?></h2>

            <small>Applications awaiting review</small>

        </div>


        <div class="wp-stat-card">

            <div class="wp-stat-top">

                <span>Current Employees</span>

                <div class="wp-stat-icon blue">
                    <i class="bi bi-people"></i>
                </div>

            </div>

            <h2><?= $current_employees ?></h2>

            <small>Active workforce members</small>

        </div>


        <div class="wp-stat-card">

            <div class="wp-stat-top">

                <span>Monthly Labor Costs</span>

                <div class="wp-stat-icon green">
                    <i class="bi bi-cash-stack"></i>
                </div>

            </div>

            <h2><?= htmlspecialchars($monthly_labor_costs, ENT_QUOTES, 'UTF-8') ?></h2>

            <small>Approved labor costs this month</small>

        </div>

    </section>


    <!-- Main dashboard columns -->
    <section class="wp-dashboard-columns">

        <!-- Recent applications -->
        <div class="wp-panel wp-dashboard-main-panel">

            <div class="wp-panel-header">

                <div>

                    <h3 class="wp-panel-title">
                        Recent Applications
                    </h3>

                    <p class="wp-panel-description">
                        Review candidates applying to your job listings.
                    </p>

                </div>

                <a href="/pages/employer/applications.php"
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

                            // Get the related job information
                            $job = $application['jobs'] ?? [];

                            $job_title = $job['title'] ?? 'Untitled Job';
                            $company_name = $job['company_name'] ?? 'Unknown Company';

                            // Get the applicant's information
                            $worker = $application['profiles'] ?? [];

                            $worker_name = $worker['full_name'] ?? 'Unknown Applicant';

                            // Get application status
                            $status = $application['status'] ?? 'pending';

                            // Determine status badge color
                            $status_class = 'neutral';

                            if ($status === 'accepted') {

                                $status_class = 'success';
<<<<<<< HEAD

                            } elseif ($status === 'pending') {

                                $status_class = 'warning';

                            } elseif ($status === 'shortlisted' || $status === 'interview') {

                                $status_class = 'info';

=======
                            } elseif ($status === 'pending') {

                                $status_class = 'warning';
                            } elseif ($status === 'shortlisted' || $status === 'interview') {

                                $status_class = 'info';
>>>>>>> f7f1bd69f0d5af8e65e63b6dff999b1d558d685a
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
                                        <?= htmlspecialchars($worker_name, ENT_QUOTES, 'UTF-8') ?>
                                    </h4>

                                    <p>
                                        Applied for:
                                        <?= htmlspecialchars($job_title, ENT_QUOTES, 'UTF-8') ?>
                                    </p>

                                    <div class="wp-application-meta">

                                        <span>
                                            <?= htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8') ?>
                                        </span>

                                        <span>
                                            Applied <?= htmlspecialchars($applied_date, ENT_QUOTES, 'UTF-8') ?>
                                        </span>

                                    </div>

                                </div>


                                <span class="wp-application-status <?= $status_class ?>">
                                    <?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?>
                                </span>

                            </div>

                        <?php endforeach; ?>

                    </div>


                <?php else: ?>

                    <!-- Empty state when there are no applications -->
                    <div class="wp-dashboard-empty">

                        <div class="wp-dashboard-empty-icon">
                            <i class="bi bi-person-lines-fill"></i>
                        </div>

                        <h4>No recent applications</h4>

                        <p>
                            Applications from interested workers
                            will appear here.
                        </p>

                        <a href="/pages/employer/jobs.php" class="wp-btn wp-btn-outline">
                            Manage Job Listings
                        </a>

                    </div>

                <?php endif; ?>

            </div>

        </div>


        <!-- Quick actions -->
        <div class="wp-panel wp-dashboard-side-panel">

            <div class="wp-panel-header">

                <div>

                    <h3 class="wp-panel-title">
                        Quick Actions
                    </h3>

                    <p class="wp-panel-description">
                        Manage your workforce faster.
                    </p>

                </div>

            </div>


            <div class="wp-panel-body">

                <div class="wp-quick-actions">

                    <a href="/pages/employer/create-job.php" class="wp-quick-action">

                        <div class="wp-quick-action-icon rose">
                            <i class="bi bi-plus-circle"></i>
                        </div>

                        <div>
                            <strong>Create a Job</strong>
                            <small>Publish a new opportunity</small>
                        </div>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                    <a href="/pages/employer/find-freelancers.php" class="wp-quick-action">

                        <div class="wp-quick-action-icon blue">
                            <i class="bi bi-search"></i>
                        </div>

                        <div>
                            <strong>Find Freelancers</strong>
                            <small>Discover potential workers</small>
                        </div>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                    <a href="/pages/employer/assign-task.php" class="wp-quick-action">

                        <div class="wp-quick-action-icon yellow">
                            <i class="bi bi-list-task"></i>
                        </div>

                        <div>
                            <strong>Assign a Task</strong>
                            <small>Give work to your employees</small>
                        </div>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                    <a href="/pages/employer/employees.php" class="wp-quick-action">

                        <div class="wp-quick-action-icon green">
                            <i class="bi bi-people"></i>
                        </div>

                        <div>
                            <strong>Manage Employees</strong>
                            <small>View your current workforce</small>
                        </div>

                        <i class="bi bi-chevron-right"></i>

                    </a>

                </div>

            </div>

        </div>

    </section>


    <!-- Job listings -->
    <section class="wp-panel wp-dashboard-jobs-panel">

        <div class="wp-panel-header">

            <div>

                <h3 class="wp-panel-title">
                    Your Job Listings
                </h3>

                <p class="wp-panel-description">
                    Keep track of the opportunities you've published.
                </p>

            </div>

            <a href="/pages/employer/jobs.php" class="wp-dashboard-text-link">

                Manage Jobs
                <i class="bi bi-arrow-right"></i>

            </a>

        </div>


        <div class="wp-panel-body">

            <?php if (!empty($recent_jobs)): ?>

                <div class="wp-application-list">

                    <?php foreach ($recent_jobs as $job): ?>

                        <?php

                        // Get job information
                        $job_title = $job['title'] ?? 'Untitled Job';
                        $company_name = $job['company_name'] ?? 'Unknown Company';
                        $hourly_rate = $job['hourly_rate'] ?? 0;
                        $employment_type = $job['employment_type'] ?? 'Not specified';
                        $job_status = $job['status'] ?? 'closed';

                        // Determine job status badge color
                        $job_status_class = 'neutral';

                        if ($job_status === 'open') {
                            $job_status_class = 'success';
                        }

                        // Format job creation date
                        $created_date = 'Date unavailable';

                        if (!empty($job['created_at'])) {

                            $timestamp = strtotime($job['created_at']);

                            if ($timestamp !== false) {
                                $created_date = date('M d, Y', $timestamp);
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
<<<<<<< HEAD
                                        ₱<?= number_format((float) $hourly_rate, 2) ?>/hr
=======
                                        ₱<?= number_format((float)$hourly_rate, 2) ?>/hr
>>>>>>> f7f1bd69f0d5af8e65e63b6dff999b1d558d685a
                                    </span>

                                    <span>
                                        <?= htmlspecialchars(
                                            ucwords(str_replace('_', ' ', $employment_type)),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </span>

                                    <span>
                                        Created <?= htmlspecialchars($created_date, ENT_QUOTES, 'UTF-8') ?>
                                    </span>

                                </div>

                            </div>


                            <span class="wp-application-status <?= $job_status_class ?>">
                                <?= htmlspecialchars(ucfirst($job_status), ENT_QUOTES, 'UTF-8') ?>
                            </span>

                        </div>

                    <?php endforeach; ?>

                </div>


            <?php else: ?>

                <!-- Empty state when the employer has no job listings -->
                <div class="wp-dashboard-empty">

                    <div class="wp-dashboard-empty-icon">
                        <i class="bi bi-briefcase"></i>
                    </div>

                    <h4>No job listings yet</h4>

                    <p>
                        Create your first job listing to start connecting
                        with qualified workers.
                    </p>

                    <a href="/pages/employer/create-job.php" class="wp-btn wp-btn-primary">
                        <i class="bi bi-plus-lg"></i>
                        Create Your First Job
                    </a>

                </div>

            <?php endif; ?>

        </div>

    </section>

</div>


<?php

// Include shared footer
require_once '../../includes/footer.php';

<<<<<<< HEAD
?>
=======
?>
>>>>>>> f7f1bd69f0d5af8e65e63b6dff999b1d558d685a
