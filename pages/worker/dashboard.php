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

$user_name = $_SESSION['user']['user_metadata']['full_name'] ?? 'Worker';


// ==========================================
// GET RECENT APPLICATIONS
// ==========================================

// Get the worker's five most recent applications
$applications_url = SUPABASE_URL .
    '/rest/v1/applications?select=id,status,applied_at,jobs(title,company_name,hourly_rate,employment_type)' .
    '&worker_id=eq.' . urlencode($user_id) .
    '&order=applied_at.desc&limit=5';

// Prepare the GET request
$applications_context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
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

    if (!is_array($recent_applications)) {
        $recent_applications = [];
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

        <div class="wp-stat-card">

            <div class="wp-stat-top">

                <span>Active Employment</span>

                <div class="wp-stat-icon rose">
                    <i class="bi bi-briefcase"></i>
                </div>

            </div>

            <h2>—</h2>

            <small>Current employment status</small>

        </div>


        <div class="wp-stat-card">

            <div class="wp-stat-top">

                <span>Applications</span>

                <div class="wp-stat-icon blue">
                    <i class="bi bi-file-earmark-text"></i>
                </div>

            </div>

            <h2>—</h2>

            <small>Total submitted applications</small>

        </div>


        <div class="wp-stat-card">

            <div class="wp-stat-top">

                <span>Pending Offers</span>

                <div class="wp-stat-icon yellow">
                    <i class="bi bi-envelope-open"></i>
                </div>

            </div>

            <h2>—</h2>

            <small>Offers awaiting your response</small>

        </div>


        <div class="wp-stat-card">

            <div class="wp-stat-top">

                <span>Tasks In Progress</span>

                <div class="wp-stat-icon green">
                    <i class="bi bi-list-check"></i>
                </div>

            </div>

            <h2>—</h2>

            <small>Your ongoing assignments</small>

        </div>

    </section>


    <!-- ======================================
         MAIN DASHBOARD COLUMNS
    ======================================= -->

    <section class="wp-dashboard-columns">


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

                            // Get the related job information
                            $job = $application['jobs'] ?? [];

                            $job_title = $job['title'] ?? 'Untitled Job';
                            $company_name = $job['company_name'] ?? 'Unknown Company';
                            $hourly_rate = $job['hourly_rate'] ?? 0;
                            $employment_type = $job['employment_type'] ?? 'Not specified';

                            // Get application status
                            $status = $application['status'] ?? 'pending';

                            // Determine status badge color
                            $status_class = 'neutral';

                            if ($status === 'accepted' || $status === 'hired') {

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
                                            ₱<?= number_format((float)$hourly_rate, 2) ?>/hr
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
                                    <?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?>
                                </span>

                            </div>

                        <?php endforeach; ?>

                    </div>


                <?php else: ?>

                    <!-- Empty state when there are no applications -->
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
                            <small>Review employer invitations</small>
                        </div>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                    <a href="/pages/worker/profile.php" class="wp-quick-action">

                        <div class="wp-quick-action-icon green">
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