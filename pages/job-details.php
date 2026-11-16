<?php

// Use the shared Supabase-backed session.
// Job details remain publicly accessible.
require_once __DIR__ . '/../includes/session.php';


// ==========================================
// INITIALIZE PAGE DATA
// ==========================================

$job_id = $_GET['id'] ?? '';

$job = null;
$message = '';
$application_message = '';
$existing_resume = null;


// ==========================================
// RETRIEVE THE SELECTED JOB
// ==========================================

if (!preg_match(
    '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
    $job_id
)) {
    $message = 'Invalid job listing.';
} else {

    $url = SUPABASE_URL .
        '/rest/v1/jobs?select=*&id=eq.' . urlencode($job_id) .
        '&status=eq.open';

    $options = [
        'http' => [
            'method' => 'GET',
            'header' => "apikey: " . SUPABASE_KEY . "\r\n",
            'ignore_errors' => true
        ]
    ];

    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);

    if ($response === false) {

        $message = 'Unable to connect to the job listings.';
    } else {

        $result = json_decode($response, true);

        if (isset($result[0])) {

            $job = $result[0];
        } elseif (empty($result)) {

            $message = 'This job listing is no longer available.';
        } else {

            $message = 'Unable to retrieve the job details. ' .
                ($result['message'] ?? 'Please try again.');
        }
    }
}


// ==========================================
// GET THE WORKER'S EXISTING RESUME
// ==========================================

if (
    isset($_SESSION['user']) &&
    ($_SESSION['role'] ?? '') === 'worker'
) {

    $worker_id = $_SESSION['user']['id'];

    $profile_url = SUPABASE_URL .
        '/rest/v1/profiles?select=resume_url&id=eq.' . urlencode($worker_id);

    $options = [
        'http' => [
            'method' => 'GET',
            'header' =>
            "apikey: " . SUPABASE_KEY . "\r\n" .
                "Authorization: Bearer " . $_SESSION['access_token'] . "\r\n",
            'ignore_errors' => true
        ]
    ];

    $context = stream_context_create($options);
    $profile_response = file_get_contents($profile_url, false, $context);

    $profile_result = json_decode($profile_response ?: '', true);

    $existing_resume = $profile_result[0]['resume_url'] ?? null;
}


// ==========================================
// HANDLE JOB APPLICATION
// ==========================================

if (isset($_POST['apply'])) {

    if (
        !isset($_SESSION['user']) ||
        ($_SESSION['role'] ?? '') !== 'worker'
    ) {

        $application_message = 'Please log in using a worker account to apply.';
    } elseif ($job === null) {

        $application_message = 'This job is no longer available.';
    } else {

        $worker_id = $_SESSION['user']['id'];

        $application_statement = trim(
            $_POST['application_statement'] ?? ''
        );

        // Use the existing profile resume by default.
        $resume_path = $existing_resume;


        // ==========================================
        // HANDLE RESUME UPLOAD
        // ==========================================

        if (
            isset($_FILES['resume']) &&
            $_FILES['resume']['error'] !== UPLOAD_ERR_NO_FILE
        ) {

            $file = $_FILES['resume'];

            $extension = strtolower(
                pathinfo($file['name'], PATHINFO_EXTENSION)
            );

            $allowed_types = [
                'pdf' => 'application/pdf',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];

            if ($file['error'] !== UPLOAD_ERR_OK) {

                $application_message = 'Resume upload failed.';
            } elseif ($file['size'] > 5 * 1024 * 1024) {

                $application_message = 'Resume must not exceed 5 MB.';
            } elseif ($file['size'] <= 0) {

                $application_message = 'Please select a valid resume file.';
            } else {

                // Check the actual file type.
                $file_info = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($file_info, $file['tmp_name']);


                if (
                    !isset($allowed_types[$extension]) ||
                    $mime_type !== $allowed_types[$extension]
                ) {

                    $application_message = 'Only PDF, DOC, and DOCX files are allowed.';
                } else {

                    // Create a unique Supabase Storage path.
                    $resume_path = $worker_id .
                        '/resume-' . time() . '.' . $extension;

                    $storage_url = SUPABASE_URL .
                        '/storage/v1/object/resumes/' . $resume_path;

                    $file_content = file_get_contents($file['tmp_name']);

                    $options = [
                        'http' => [
                            'method' => 'POST',
                            'header' =>
                            "apikey: " . SUPABASE_KEY . "\r\n" .
                                "Authorization: Bearer " . $_SESSION['access_token'] . "\r\n" .
                                "Content-Type: " . $mime_type . "\r\n" .
                                "x-upsert: true\r\n",
                            'content' => $file_content,
                            'ignore_errors' => true
                        ]
                    ];

                    $context = stream_context_create($options);

                    $upload_response = file_get_contents(
                        $storage_url,
                        false,
                        $context
                    );

                    // Capture the headers immediately after this request.
                    $upload_headers = $http_response_header ?? [];

                    $status_code = 0;

                    foreach ($upload_headers as $header) {

                        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                            $status_code = (int) $matches[1];
                        }
                    }

                    if (
                        $upload_response === false ||
                        $status_code < 200 ||
                        $status_code >= 300
                    ) {

                        $application_message = 'Unable to upload your resume.';
                    } else {

                        // Update the worker's profile with the new resume.
                        $profile_url = SUPABASE_URL .
                            '/rest/v1/profiles?id=eq.' . urlencode($worker_id);

                        $options = [
                            'http' => [
                                'method' => 'PATCH',
                                'header' =>
                                "Content-Type: application/json\r\n" .
                                    "apikey: " . SUPABASE_KEY . "\r\n" .
                                    "Authorization: Bearer " . $_SESSION['access_token'] . "\r\n" .
                                    "Prefer: return=representation\r\n",
                                'content' => json_encode([
                                    'resume_url' => $resume_path
                                ]),
                                'ignore_errors' => true
                            ]
                        ];

                        $context = stream_context_create($options);

                        $profile_update = file_get_contents(
                            $profile_url,
                            false,
                            $context
                        );

                        // Capture the headers immediately after this request.
                        $profile_update_headers = $http_response_header ?? [];

                        $status_code = 0;

                        foreach ($profile_update_headers as $header) {

                            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                                $status_code = (int) $matches[1];
                            }
                        }

                        if (
                            $profile_update === false ||
                            $status_code < 200 ||
                            $status_code >= 300
                        ) {

                            $application_message = 'Resume uploaded, but profile update failed. Please try again.';
                        } else {

                            $existing_resume = $resume_path;
                        }
                    }
                }
            }
        }


        // ==========================================
        // CHECK IF A RESUME IS AVAILABLE
        // ==========================================

        if (
            $application_message === '' &&
            empty($resume_path)
        ) {

            $application_message = 'Please upload your Resume / CV before applying.';
        }


        // ==========================================
        // SUBMIT THE APPLICATION
        // ==========================================

        if ($application_message === '') {

            $data = [
                'job_id' => $job['id'],
                'worker_id' => $worker_id,
                'application_statement' => $application_statement,
                'status' => 'pending',
                'resume_path' => $resume_path
            ];

            $url = SUPABASE_URL . '/rest/v1/applications';

            $options = [
                'http' => [
                    'method' => 'POST',
                    'header' =>
                    "Content-Type: application/json\r\n" .
                        "apikey: " . SUPABASE_KEY . "\r\n" .
                        "Authorization: Bearer " . $_SESSION['access_token'] . "\r\n" .
                        "Prefer: return=representation\r\n",
                    'content' => json_encode($data),
                    'ignore_errors' => true
                ]
            ];

            $context = stream_context_create($options);

            $response = file_get_contents(
                $url,
                false,
                $context
            );

            // Capture the headers immediately after this request.
            $application_headers = $http_response_header ?? [];

            $status_code = 0;

            foreach ($application_headers as $header) {

                if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                    $status_code = (int) $matches[1];
                }
            }

            $result = json_decode($response ?: '', true);

            if ($status_code === 201) {

                $application_message = 'Application submitted successfully!';
            } elseif (
                isset($result['code']) &&
                $result['code'] === '23505'
            ) {

                $application_message = 'You have already applied for this job.';
            } else {

                $application_message = 'Application failed. ' .
                    ($result['message'] ?? 'Please try again.');
            }
        }
    }
}

?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= $job
            ? htmlspecialchars($job['title'], ENT_QUOTES, 'UTF-8') . ' | WorkPulse'
            : 'Job Details | WorkPulse' ?>
    </title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <link rel="stylesheet" href="../assets/css/landing.css">
    <link rel="stylesheet" href="../assets/css/marketplace.css">

    <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>

<body>

    <div class="wp-landing">

        <!-- NAVIGATION -->
        <nav class="wp-landing-nav">

            <a href="../index.php" class="wp-landing-brand">
                <span class="landing-brand-icon">
                    <i class="bi bi-activity"></i>
                </span>
                WorkPulse
            </a>

            <div class="wp-landing-nav-links">

                <a href="../index.php">Home</a>
                <a href="jobs.php" class="marketplace-active">Find Jobs</a>

                <?php if (isset($_SESSION['user']['id'])): ?>

                    <?php if (($_SESSION['role'] ?? '') === 'worker'): ?>

                        <a href="worker/applications.php">My Applications</a>
                        <a href="worker/dashboard.php" class="wp-btn wp-btn-primary">
                            Dashboard
                        </a>

                    <?php elseif (($_SESSION['role'] ?? '') === 'employer'): ?>

                        <a href="employer/dashboard.php" class="wp-btn wp-btn-primary">
                            Dashboard
                        </a>

                    <?php elseif (($_SESSION['role'] ?? '') === 'admin'): ?>

                        <a href="admin/dashboard.php" class="wp-btn wp-btn-primary">
                            Dashboard
                        </a>

                    <?php endif; ?>

                <?php else: ?>

                    <a href="../login.php">Sign In</a>
                    <a href="../register.php" class="wp-btn wp-btn-primary">
                        Get Started
                    </a>

                <?php endif; ?>

            </div>

        </nav>


        <main class="job-details-page">

            <a href="jobs.php" class="job-back-link">
                <i class="bi bi-arrow-left"></i>
                Back to opportunities
            </a>


            <?php if ($message !== ''): ?>

                <div class="marketplace-empty job-not-found">

                    <i class="bi bi-briefcase"></i>

                    <h2>Job unavailable</h2>

                    <p>
                        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                    </p>

                    <a href="jobs.php" class="wp-btn wp-btn-primary">
                        Browse Available Jobs
                    </a>

                </div>


            <?php elseif ($job !== null): ?>

                <!-- JOB DETAILS HERO -->
                <section class="wp-hero job-detail-hero">

                    <div class="wp-hero-content">

                        <span class="wp-hero-label">
                            <i class="bi bi-briefcase"></i>
                            OPEN OPPORTUNITY
                        </span>

                        <h1 class="wp-hero-title">
                            <?= htmlspecialchars($job['title'], ENT_QUOTES, 'UTF-8') ?>
                        </h1>

                        <p class="wp-hero-description job-detail-company">
                            <i class="bi bi-building"></i>
                            <?= htmlspecialchars($job['company_name'], ENT_QUOTES, 'UTF-8') ?>
                        </p>

                        <div class="job-detail-hero-meta">

                            <span>
                                <i class="bi bi-clock"></i>
                                <?= htmlspecialchars(
                                    ucwords(str_replace('_', ' ', $job['employment_type'])),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>

                            <span>
                                <i class="bi bi-calendar-week"></i>
                                <?= htmlspecialchars(
                                    $job['work_schedule'] ?? 'Not specified',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>

                            <strong>
                                $<?= number_format((float) $job['hourly_rate'], 2) ?>/hr
                            </strong>

                        </div>

                        <div class="wp-hero-actions">

                            <a href="#apply" class="wp-btn wp-btn-primary">
                                Apply for this job
                                <i class="bi bi-arrow-right"></i>
                            </a>

                            <a href="jobs.php" class="wp-btn wp-btn-outline">
                                Browse other jobs
                            </a>

                        </div>

                    </div>


                    <!-- FLOATING VISUAL -->
                    <div class="wp-hero-image">

                        <div class="landing-visual">

                            <div class="landing-visual-decoration"></div>

                            <div class="landing-visual-card">

                                <div class="landing-visual-top">

                                    <div class="landing-visual-logo">
                                        <i class="bi bi-activity"></i>
                                    </div>

                                    <span>WORKPULSE WORKSPACE</span>

                                    <i class="bi bi-three-dots"></i>

                                </div>

                                <div class="landing-visual-heading">

                                    <small>YOUR NEXT OPPORTUNITY</small>

                                    <h3>
                                        Make your next<br>
                                        move count.
                                    </h3>

                                </div>

                                <div class="landing-progress-card">

                                    <div class="landing-progress-heading">
                                        <span>Opportunity status</span>
                                        <strong>Now open</strong>
                                    </div>

                                    <div class="landing-progress-track">
                                        <span></span>
                                    </div>

                                    <small>
                                        Take the next step in your professional journey.
                                    </small>

                                </div>

                                <div class="landing-visual-stats">

                                    <div>
                                        <i class="bi bi-building"></i>
                                        <strong>Employer</strong>
                                        <small>Connect with teams</small>
                                    </div>

                                    <div>
                                        <i class="bi bi-send"></i>
                                        <strong>Apply</strong>
                                        <small>Share your experience</small>
                                    </div>

                                </div>

                            </div>

                            <div class="landing-floating-icon landing-floating-one">
                                <i class="bi bi-check-lg"></i>
                            </div>

                            <div class="landing-floating-icon landing-floating-two">
                                <i class="bi bi-lightning-charge-fill"></i>
                            </div>

                        </div>

                    </div>

                </section>


                <!-- JOB DETAILS CONTENT -->
                <div class="job-details-layout job-details-refresh-layout">


                    <section class="wp-job-details-section">

                        <h2>
                            <i class="bi bi-card-text"></i>
                            About this job
                        </h2>

                        <div class="job-details-description">
                            <?= nl2br(htmlspecialchars(
                                $job['description'] ?? '',
                                ENT_QUOTES,
                                'UTF-8'
                            )) ?>
                        </div>

                    </section>


                    <section class="wp-job-details-section">

                        <h2>
                            <i class="bi bi-list-check"></i>
                            Requirements
                        </h2>

                        <div class="job-details-description">
                            <?= nl2br(htmlspecialchars(
                                $job['requirements'] ?? 'No specific requirements.',
                                ENT_QUOTES,
                                'UTF-8'
                            )) ?>
                        </div>

                    </section>


                    <section class="wp-job-details-section">

                        <h2>
                            <i class="bi bi-tools"></i>
                            Required skills
                        </h2>

                        <div class="wp-job-skills job-details-skills">

                            <?php if (!empty($job['required_skills'])): ?>

                                <?php foreach ($job['required_skills'] as $skill): ?>

                                    <span class="wp-job-skill">
                                        <?= htmlspecialchars($skill, ENT_QUOTES, 'UTF-8') ?>
                                    </span>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <p>No specific skills required.</p>

                            <?php endif; ?>

                        </div>

                    </section>


                    <section class="wp-job-details-section">

                        <h2>
                            <i class="bi bi-camera-video"></i>
                            Interview information
                        </h2>

                        <p>
                            <?= !empty($job['interview_required'])
                                ? 'An interview is required for this position.'
                                : 'No interview is required for this position.' ?>
                        </p>

                    </section>


                    <!-- APPLICATION FORM -->
                    <section class="wp-job-details-section job-application-section" id="apply">

                        <div class="job-application-heading">

                            <div>

                                <span class="landing-section-label">
                                    TAKE THE NEXT STEP
                                </span>

                                <h2>Apply for this position</h2>

                                <p>
                                    Submit your application to be considered for this opportunity.
                                </p>

                            </div>

                            <div class="job-application-icon">
                                <i class="bi bi-send"></i>
                            </div>

                        </div>


                        <?php if ($application_message !== ''): ?>

                            <div class="wp-alert <?= strpos($application_message, 'successfully') !== false
                                                        ? 'wp-alert-success'
                                                        : 'wp-alert-danger' ?>">

                                <?= htmlspecialchars(
                                    $application_message,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            </div>

                        <?php endif; ?>


                        <?php if (
                            isset($_SESSION['user']['id']) &&
                            ($_SESSION['role'] ?? '') === 'worker'
                        ): ?>

                            <form method="POST"
                                enctype="multipart/form-data"
                                class="job-application-form">

                                <div class="wp-form-group">

                                    <label for="application_statement" class="wp-label">
                                        Application statement
                                        <span class="job-optional">(Optional)</span>
                                    </label>

                                    <textarea
                                        name="application_statement"
                                        id="application_statement"
                                        class="wp-textarea"
                                        rows="6"
                                        placeholder="Introduce yourself and explain why you're interested in this position..."></textarea>

                                </div>


                                <div class="wp-form-group">

                                    <label for="resume" class="wp-label">
                                        Resume / CV
                                        <span class="job-required">(Required)</span>
                                    </label>


                                    <?php if (!empty($existing_resume)): ?>

                                        <div class="existing-resume">

                                            <i class="bi bi-file-earmark-check"></i>

                                            <div>
                                                <strong>Existing resume</strong>

                                                <small>
                                                    <?= htmlspecialchars(
                                                        basename($existing_resume),
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>
                                                </small>
                                            </div>

                                        </div>

                                        <p class="wp-form-hint">
                                            You can keep your existing resume or upload a replacement.
                                        </p>

                                    <?php else: ?>

                                        <p class="wp-form-hint">
                                            Please upload your resume before applying.
                                        </p>

                                    <?php endif; ?>


                                    <input
                                        type="file"
                                        name="resume"
                                        id="resume"
                                        class="wp-input job-resume-input"
                                        accept=".pdf,.doc,.docx"
                                        <?= empty($existing_resume) ? 'required' : '' ?>>

                                    <small class="wp-form-hint">
                                        Accepted formats: PDF, DOC, DOCX. Maximum size: 5 MB.
                                    </small>

                                </div>


                                <button type="submit"
                                    name="apply"
                                    class="wp-btn wp-btn-primary job-apply-button">

                                    Submit Application
                                    <i class="bi bi-arrow-right"></i>

                                </button>

                            </form>


                        <?php elseif (!isset($_SESSION['user']['id'])): ?>

                            <div class="job-login-prompt">

                                <i class="bi bi-person-lock"></i>

                                <div>
                                    <strong>Sign in to apply</strong>

                                    <p>
                                        You need a worker account to submit an application.
                                    </p>
                                </div>

                            </div>

                            <a href="../login.php?redirect=<?= urlencode(
                                                                'pages/job-details.php?id=' . $job['id']
                                                            ) ?>"
                                class="wp-btn wp-btn-primary job-apply-button">

                                Log In to Apply
                                <i class="bi bi-arrow-right"></i>

                            </a>


                        <?php else: ?>

                            <div class="job-login-prompt">

                                <i class="bi bi-info-circle"></i>

                                <div>
                                    <strong>Worker account required</strong>

                                    <p>
                                        Only worker accounts can apply for job opportunities.
                                    </p>
                                </div>

                            </div>

                            <a href="../logout.php?redirect=<?= urlencode(
                                                                'pages/job-details.php?id=' . $job['id']
                                                            ) ?>"
                                class="wp-btn wp-btn-outline">

                                Switch Account

                            </a>

                        <?php endif; ?>

                    </section>

                </div>


                <!-- JOB SUMMARY SIDEBAR -->
                <aside class="job-details-sidebar">

                    <section class="job-summary-card">

                        <span class="landing-section-label">
                            JOB OVERVIEW
                        </span>

                        <h3>Position details</h3>


                        <div class="job-summary-item">

                            <span>
                                <i class="bi bi-cash-stack"></i>
                                Hourly rate
                            </span>

                            <strong class="job-summary-rate">
                                $<?= number_format((float) $job['hourly_rate'], 2) ?>/hr
                            </strong>

                        </div>


                        <div class="job-summary-item">

                            <span>
                                <i class="bi bi-clock"></i>
                                Employment type
                            </span>

                            <strong>
                                <?= htmlspecialchars(
                                    ucwords(str_replace('_', ' ', $job['employment_type'])),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </strong>

                        </div>


                        <div class="job-summary-item">

                            <span>
                                <i class="bi bi-calendar-week"></i>
                                Work schedule
                            </span>

                            <strong>
                                <?= htmlspecialchars(
                                    $job['work_schedule'] ?? 'Not specified',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </strong>

                        </div>


                        <div class="job-summary-item">

                            <span>
                                <i class="bi bi-camera-video"></i>
                                Interview
                            </span>

                            <strong>
                                <?= !empty($job['interview_required'])
                                    ? 'Required'
                                    : 'Not required' ?>
                            </strong>

                        </div>


                        <div class="job-summary-divider"></div>


                        <a href="#apply" class="wp-btn wp-btn-primary job-summary-apply">
                            Apply for this job
                            <i class="bi bi-arrow-right"></i>
                        </a>

                        <p class="job-summary-note">
                            <i class="bi bi-shield-check"></i>
                            Your application will be submitted securely.
                        </p>

                    </section>

                </aside>

            <?php endif; ?>

        </main>


        <!-- FOOTER -->
        <footer class="wp-landing-footer">

            <div class="landing-footer-top">

                <a href="../index.php" class="wp-landing-brand">

                    <span class="landing-brand-icon">
                        <i class="bi bi-activity"></i>
                    </span>

                    WorkPulse

                </a>


                <div class="landing-footer-links">

                    <a href="../index.php">Home</a>
                    <a href="jobs.php">Find Jobs</a>
                    <a href="../login.php">Sign In</a>
                    <a href="../register.php">Create Account</a>

                </div>

            </div>


            <div class="landing-footer-bottom">

                <p>
                    © <?= date('Y') ?> WorkPulse. All rights reserved.
                </p>

                <p>
                    Work smarter. Move forward.
                </p>

            </div>

        </footer>

    </div>

</body>

</html>