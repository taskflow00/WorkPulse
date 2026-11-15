<?php

// ==========================================
// WORKER APPLICATIONS
// ==========================================

// Page information
$required_role = 'worker';
$page_title = 'My Applications';
$active_page = 'applications';

// Check authentication and worker role
require_once '../../includes/auth.php';

// Get logged-in worker information
$worker_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];


// ==========================================
// GET WORKER APPLICATIONS
// ==========================================

// Retrieve applications and related job details
$url = SUPABASE_URL .
    '/rest/v1/applications?select=id,application_statement,status,applied_at,jobs(id,title,company_name,hourly_rate,employment_type)' .
    '&worker_id=eq.' . urlencode($worker_id) .
    '&order=applied_at.desc';

// Prepare the GET request
$options = [
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $access_token . "\r\n" .
            "Accept: application/json\r\n",
        'ignore_errors' => true
    ]
];

// Send request to Supabase
$context = stream_context_create($options);

$http_response_header = [];

$response = file_get_contents(
    $url,
    false,
    $context
);

// Prepare application variables
$applications = [];
$message = '';


// Convert the response into a PHP array
if ($response === false) {

    $message = 'Unable to connect to the applications service.';
} else {

    $result = json_decode($response, true);

    // Check the HTTP response status
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $matches);

    $status_code = (int)($matches[1] ?? 0);

    if (
        $status_code >= 200 &&
        $status_code < 300 &&
        is_array($result) &&
        array_is_list($result)
    ) {

        $applications = $result;
    } else {

        $message = 'Something went wrong while retrieving your applications.';
    }
}


// Count applications
$total_applications = count($applications);


// Include shared dashboard header
require_once '../../includes/header.php';

?>


<div class="wp-page">

    <!-- ======================================
         PAGE INTRODUCTION
    ======================================= -->

    <section class="wp-dashboard-welcome">

        <div>

            <span class="wp-dashboard-eyebrow">
                WORKER WORKSPACE
            </span>

            <h1 class="wp-page-title">
                My Applications
            </h1>

            <p class="wp-page-description">
                Track your submitted applications and stay updated
                on your job opportunities.
            </p>

        </div>

        <a href="/pages/jobs.php" class="wp-btn wp-btn-primary">

            <i class="bi bi-search"></i>

            Find More Jobs

        </a>

    </section>


    <!-- ======================================
         APPLICATION SUMMARY
    ======================================= -->

    <section class="wp-dashboard-stats">

        <div class="wp-stat-card">

            <div class="wp-stat-top">

                <span>Total Applications</span>

                <div class="wp-stat-icon rose">
                    <i class="bi bi-file-earmark-text"></i>
                </div>

            </div>

            <h2><?= $total_applications ?></h2>

            <small>Jobs you have applied for</small>

        </div>

    </section>


    <!-- ======================================
         APPLICATION LIST
    ======================================= -->

    <section class="wp-panel">

        <div class="wp-panel-header">

            <div>

                <h3 class="wp-panel-title">
                    Submitted Applications
                </h3>

                <p class="wp-panel-description">
                    Your most recent applications appear first.
                </p>

            </div>

            <span class="wp-badge wp-badge-neutral">
                <?= $total_applications ?>
                <?= $total_applications === 1 ? 'Application' : 'Applications' ?>
            </span>

        </div>


        <div class="wp-panel-body">


            <!-- ==================================
                 ERROR MESSAGE
            =================================== -->

            <?php if ($message !== ''): ?>

                <div class="wp-alert wp-alert-danger">

                    <i class="bi bi-exclamation-circle me-2"></i>

                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>

                </div>


                <!-- ==================================
                 EMPTY STATE
            =================================== -->

            <?php elseif (empty($applications)): ?>

                <div class="wp-dashboard-empty">

                    <div class="wp-dashboard-empty-icon">

                        <i class="bi bi-file-earmark-text"></i>

                    </div>

                    <h4>No applications yet</h4>

                    <p>
                        You haven't applied for any jobs.
                        Explore available opportunities and submit
                        your first application.
                    </p>

                    <a href="/pages/jobs.php" class="wp-btn wp-btn-primary">

                        <i class="bi bi-search"></i>

                        Browse Available Jobs

                    </a>

                </div>


                <!-- ==================================
                 APPLICATION RECORDS
            =================================== -->

            <?php else: ?>

                <div class="wp-application-list">

                    <?php foreach ($applications as $application): ?>

                        <?php

                        // Get related job information
                        $job = $application['jobs'] ?? null;

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

                        // Format application status
                        $status_label = ucwords(
                            str_replace('_', ' ', $status)
                        );

                        // Format application date
                        $applied_date = 'Date unavailable';

                        if (!empty($application['applied_at'])) {

                            $timestamp = strtotime($application['applied_at']);

                            if ($timestamp !== false) {
                                $applied_date = date('M d, Y', $timestamp);
                            }
                        }

                        ?>

                        <div class="wp-application-item">

                            <!-- Job Icon -->
                            <div class="wp-application-item-icon">

                                <i class="bi bi-briefcase"></i>

                            </div>


                            <!-- Application Information -->
                            <div class="wp-application-item-content">

                                <div class="wp-application-item-heading">

                                    <h4>

                                        <?= htmlspecialchars(
                                            $job['title'] ?? 'Job no longer available',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                    </h4>

                                    <span class="wp-application-status <?= $status_class ?>">

                                        <?= htmlspecialchars(
                                            $status_label,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                    </span>

                                </div>


                                <!-- Company Name -->
                                <p class="wp-application-company">

                                    <i class="bi bi-building"></i>

                                    <?= htmlspecialchars(
                                        $job['company_name'] ?? 'Company information unavailable',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </p>


                                <!-- Application Details -->
                                <?php if ($job): ?>

                                    <div class="wp-application-details">

                                        <span>

                                            <i class="bi bi-cash"></i>

                                            $<?= number_format((float)($job['hourly_rate'] ?? 0), 2) ?>/hr

                                        </span>

                                        <span>

                                            <i class="bi bi-clock"></i>

                                            <?= htmlspecialchars(
                                                ucwords(str_replace(
                                                    '_',
                                                    ' ',
                                                    $job['employment_type'] ?? 'Not specified'
                                                )),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>

                                        </span>

                                        <span>

                                            <i class="bi bi-calendar"></i>

                                            Applied <?= htmlspecialchars(
                                                        $applied_date,
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>

                                        </span>

                                    </div>

                                <?php else: ?>

                                    <div class="wp-application-details">

                                        <span>

                                            <i class="bi bi-calendar"></i>

                                            Applied <?= htmlspecialchars(
                                                        $applied_date,
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>

                                        </span>

                                    </div>

                                <?php endif; ?>


                                <!-- Application Statement -->
                                <?php if (!empty($application['application_statement'])): ?>

                                    <div class="mt-3">

                                        <small class="text-muted fw-semibold">
                                            Your Application Statement
                                        </small>

                                        <p class="mt-1 mb-0 small">
                                            <?= nl2br(htmlspecialchars(
                                                $application['application_statement'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            )) ?>
                                        </p>

                                    </div>

                                <?php endif; ?>


                                <!-- View Job -->
                                <?php if (!empty($job['id'])): ?>

                                    <div class="mt-3">

                                        <a
                                            href="/pages/job-details.php?id=<?= urlencode($job['id']) ?>"
                                            class="wp-dashboard-text-link">

                                            View Job Details

                                            <i class="bi bi-arrow-right"></i>

                                        </a>

                                    </div>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </div>

    </section>

</div>


<?php

// Include shared dashboard footer
require_once '../../includes/footer.php';

?>