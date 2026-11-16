<?php

// ============================================
// 1. PAGE ACCESS AND CONFIGURATION
// ============================================

$required_role = 'employer';
$page_title = 'Find Freelancers';
$active_page = 'find-freelancers';
$page_styles = ['/assets/css/find-freelancers.css'];

require_once '../../includes/auth.php';
require_once '../../includes/send-email.php';
require_once '../../includes/email-templates.php';

date_default_timezone_set('Asia/Manila');

$token = $_SESSION['access_token'];
$employer_id = $_SESSION['user']['id'];


// ============================================
// 2. CSRF PROTECTION
// ============================================

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];


// ============================================
// 3. SUPABASE REQUEST HELPER
// ============================================

$supabase_request = function ($url, $method = 'GET', $data = null) use ($token) {

    $headers =
        "apikey: " . SUPABASE_KEY . "\r\n" .
        "Authorization: Bearer " . $token . "\r\n" .
        "Accept: application/json\r\n";

    if ($data !== null) {
        $headers .= "Content-Type: application/json\r\n";
        $headers .= "Prefer: return=representation\r\n";
    }

    $options = [
        'http' => [
            'method' => $method,
            'header' => $headers,
            'ignore_errors' => true,
            'timeout' => 15
        ]
    ];

    if ($data !== null) {
        $options['http']['content'] = json_encode($data);
    }

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    // Read the HTTP status returned by Supabase.
    $response_headers = $http_response_header ?? [];
    $http_status = 0;

    foreach ($response_headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/i', $header, $matches)) {
            $http_status = (int) $matches[1];
        }
    }

    return [
        'response' => $response,
        'status' => $http_status,
        'data' => $response !== false
            ? json_decode($response, true)
            : null
    ];
};


// ============================================
// 4. GET WORKER EMAIL FROM SUPABASE AUTH
// ============================================

$get_worker_email = function ($worker_id) {

    if (
        !defined('SUPABASE_SERVICE_ROLE_KEY') ||
        SUPABASE_SERVICE_ROLE_KEY === ''
    ) {
        error_log('Worker email lookup failed: Service role key is not configured.');
        return null;
    }

    $url = SUPABASE_URL .
        '/auth/v1/admin/users/' . rawurlencode($worker_id);

    $headers =
        "apikey: " . SUPABASE_SERVICE_ROLE_KEY . "\r\n" .
        "Authorization: Bearer " . SUPABASE_SERVICE_ROLE_KEY . "\r\n";

    $options = [
        'http' => [
            'method' => 'GET',
            'header' => $headers,
            'ignore_errors' => true,
            'timeout' => 15
        ]
    ];

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        error_log('Worker email lookup failed: Unable to connect to Supabase Auth.');
        return null;
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        error_log('Worker email lookup failed: Invalid Supabase response.');
        return null;
    }

    $email = $data['email'] ?? $data['user']['email'] ?? null;

    if (
        !is_string($email) ||
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        error_log('Worker email lookup failed: No valid email was returned.');
        return null;
    }

    return $email;
};


// ============================================
// 5. PAGE MESSAGES AND SEARCH
// ============================================

$message = $_SESSION['find_workers_message'] ?? '';
$message_type = $_SESSION['find_workers_message_type'] ?? 'info';

unset(
    $_SESSION['find_workers_message'],
    $_SESSION['find_workers_message_type']
);

$search = trim($_GET['search'] ?? '');

$error = '';
$jobs_error = '';
$invitations_error = '';
$applications_error = '';


// ============================================
// 6. SEND DIRECT JOB INVITATION
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $worker_id = trim($_POST['worker_id'] ?? '');
    $job_id = trim($_POST['job_id'] ?? '');
    $invitation_message = trim($_POST['message'] ?? '');
    $submitted_token = $_POST['csrf_token'] ?? '';
    $search = trim($_POST['search'] ?? '');

    $redirect_url = 'find-freelancers.php';

    if ($search !== '') {
        $redirect_url .= '?search=' . urlencode($search);
    }

    $set_flash = function ($type, $text) {
        $_SESSION['find_workers_message_type'] = $type;
        $_SESSION['find_workers_message'] = $text;
    };

    $uuid_pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';


    // Validate the submitted CSRF token.

    if (
        !is_string($submitted_token) ||
        !hash_equals($csrf_token, $submitted_token)
    ) {

        $set_flash(
            'danger',
            'Your session may have expired. Please try again.'
        );
    } elseif (
        !preg_match($uuid_pattern, $worker_id) ||
        !preg_match($uuid_pattern, $job_id)
    ) {

        $set_flash(
            'danger',
            'Invalid worker or job information.'
        );
    } elseif (strlen($invitation_message) > 2000) {

        $set_flash(
            'warning',
            'Your invitation message cannot exceed 2,000 characters.'
        );
    } else {

        // --------------------------------------------
        // Verify that the selected worker is active.
        // --------------------------------------------

        $worker_check = $supabase_request(
            SUPABASE_URL .
                '/rest/v1/profiles?select=id,full_name' .
                '&id=eq.' . rawurlencode($worker_id) .
                '&role=eq.worker' .
                '&account_status=eq.active' .
                '&limit=1'
        );

        if (
            $worker_check['status'] < 200 ||
            $worker_check['status'] >= 300 ||
            !is_array($worker_check['data']) ||
            empty($worker_check['data'])
        ) {

            $set_flash(
                'danger',
                'The selected worker is no longer available.'
            );
        } else {

            // --------------------------------------------
            // Verify that the job belongs to this employer
            // and is still open.
            // --------------------------------------------

            $job_check = $supabase_request(
                SUPABASE_URL .
                    '/rest/v1/jobs?select=id,title' .
                    '&id=eq.' . rawurlencode($job_id) .
                    '&employer_id=eq.' . rawurlencode($employer_id) .
                    '&status=eq.open' .
                    '&limit=1'
            );

            if (
                $job_check['status'] < 200 ||
                $job_check['status'] >= 300 ||
                !is_array($job_check['data']) ||
                empty($job_check['data'])
            ) {

                $set_flash(
                    'danger',
                    'The selected job is unavailable or does not belong to your account.'
                );
            } else {

                // --------------------------------------------
                // CHECK 1: EXISTING APPLICATION
                //
                // Do not filter by application status.
                //
                // Pending, shortlisted, interview, accepted,
                // rejected, withdrawn, and hired applications
                // all prevent another direct invitation.
                // --------------------------------------------

                $application_check = $supabase_request(
                    SUPABASE_URL .
                        '/rest/v1/applications?select=id,status' .
                        '&job_id=eq.' . rawurlencode($job_id) .
                        '&worker_id=eq.' . rawurlencode($worker_id) .
                        '&limit=1'
                );

                if (
                    $application_check['status'] < 200 ||
                    $application_check['status'] >= 300 ||
                    !is_array($application_check['data'])
                ) {

                    $set_flash(
                        'danger',
                        'Unable to check the worker recruitment record. Please try again.'
                    );
                } elseif (!empty($application_check['data'])) {

                    $existing_status =
                        $application_check['data'][0]['status'] ?? 'existing';

                    if ($existing_status === 'rejected') {

                        $set_flash(
                            'warning',
                            'This worker was previously rejected for this job. You cannot send another invitation.'
                        );
                    } else {

                        $set_flash(
                            'warning',
                            'This worker already has a recruitment record for this job. You cannot send another invitation.'
                        );
                    }
                } else {

                    // --------------------------------------------
                    // CHECK 2: COMPLETE INVITATION HISTORY
                    //
                    // Do not filter by invitation status.
                    //
                    // Even if the old invitation was declined,
                    // cancelled, or accepted, do not send another.
                    //
                    // This also protects against an application
                    // that was deleted but whose invitation history
                    // still exists.
                    // --------------------------------------------

                    $invitation_check = $supabase_request(
                        SUPABASE_URL .
                            '/rest/v1/invitations?select=id,status' .
                            '&job_id=eq.' . rawurlencode($job_id) .
                            '&worker_id=eq.' . rawurlencode($worker_id) .
                            '&employer_id=eq.' . rawurlencode($employer_id) .
                            '&limit=1'
                    );

                    if (
                        $invitation_check['status'] < 200 ||
                        $invitation_check['status'] >= 300 ||
                        !is_array($invitation_check['data'])
                    ) {

                        $set_flash(
                            'danger',
                            'Unable to check the worker invitation history. Please try again.'
                        );
                    } elseif (!empty($invitation_check['data'])) {

                        $previous_status =
                            $invitation_check['data'][0]['status'] ?? 'previous';

                        if ($previous_status === 'declined') {

                            $set_flash(
                                'warning',
                                'This worker previously declined your invitation for this job. You cannot invite them again.'
                            );
                        } elseif ($previous_status === 'cancelled') {

                            $set_flash(
                                'warning',
                                'A previous invitation for this worker and job was cancelled. You cannot send another invitation.'
                            );
                        } else {

                            $set_flash(
                                'warning',
                                'An invitation has already been sent to this worker for this job. You cannot send another invitation.'
                            );
                        }
                    } else {

                        // --------------------------------------------
                        // SAVE THE DIRECT JOB INVITATION
                        //
                        // This does not create employment or an offer.
                        // --------------------------------------------

                        $invitation_data = [
                            'job_id' => $job_id,
                            'employer_id' => $employer_id,
                            'worker_id' => $worker_id,
                            'message' => $invitation_message !== ''
                                ? $invitation_message
                                : null,
                            'status' => 'pending'
                        ];

                        $insert_result = $supabase_request(
                            SUPABASE_URL . '/rest/v1/invitations?select=id',
                            'POST',
                            $invitation_data
                        );

                        if ($insert_result['response'] === false) {

                            $set_flash(
                                'danger',
                                'Unable to connect to Supabase. Please try again.'
                            );
                        } elseif (
                            $insert_result['status'] >= 200 &&
                            $insert_result['status'] < 300
                        ) {

                            // --------------------------------------------
                            // INVITATION SAVED. SEND EMAIL NOTIFICATION.
                            // --------------------------------------------

                            $worker_email = $get_worker_email($worker_id);

                            $employer_result = $supabase_request(
                                SUPABASE_URL .
                                    '/rest/v1/profiles?select=full_name' .
                                    '&id=eq.' . rawurlencode($employer_id) .
                                    '&limit=1'
                            );

                            $employer_name = 'An employer';

                            if (
                                $employer_result['status'] >= 200 &&
                                $employer_result['status'] < 300 &&
                                is_array($employer_result['data']) &&
                                !empty($employer_result['data'][0]['full_name'])
                            ) {
                                $employer_name =
                                    $employer_result['data'][0]['full_name'];
                            }

                            $job_title = $job_check['data'][0]['title']
                                ?? 'a job opportunity';

                            if ($worker_email !== null) {

                                $email_message = invitationEmail(
                                    $employer_name,
                                    $job_title,
                                    $invitation_message
                                );

                                $email_sent = sendEmail(
                                    $worker_email,
                                    'You have received a job invitation on WorkPulse',
                                    $email_message
                                );

                                if ($email_sent) {

                                    $set_flash(
                                        'success',
                                        'Invitation successfully sent! An email notification has also been sent to the worker.'
                                    );
                                } else {

                                    $set_flash(
                                        'warning',
                                        'Invitation successfully saved, but the email notification could not be sent.'
                                    );
                                }
                            } else {

                                $set_flash(
                                    'warning',
                                    'Invitation successfully saved, but the worker email could not be retrieved. No email notification was sent.'
                                );
                            }
                        } elseif (
                            $insert_result['status'] === 409 ||
                            (
                                is_array($insert_result['data']) &&
                                ($insert_result['data']['code'] ?? '') === '23505'
                            )
                        ) {

                            $set_flash(
                                'warning',
                                'A previous invitation already exists for this worker and job.'
                            );
                        } else {

                            $api_message = '';

                            if (is_array($insert_result['data'])) {
                                $api_message =
                                    $insert_result['data']['message'] ??
                                    $insert_result['data']['details'] ??
                                    $insert_result['data']['hint'] ??
                                    '';
                            }

                            if ($api_message === '') {
                                $api_message = 'No additional error details were returned.';
                            }

                            $set_flash(
                                'danger',
                                'Invitation failed (HTTP ' .
                                    $insert_result['status'] .
                                    '): ' . $api_message
                            );
                        }
                    }
                }
            }
        }
    }

    // Prevent duplicate form submissions.
    header('Location: ' . $redirect_url);
    exit;
}


// ============================================
// 7. GET EMPLOYER'S OPEN JOBS
// ============================================

$url = SUPABASE_URL .
    '/rest/v1/jobs?select=id,title,company_name,hourly_rate,employment_type' .
    '&employer_id=eq.' . rawurlencode($employer_id) .
    '&status=eq.open' .
    '&order=created_at.desc&limit=100';

$result = $supabase_request($url);

$jobs = [];

if ($result['response'] === false) {

    $jobs_error = 'Unable to connect to Supabase while retrieving your jobs.';
} elseif (
    $result['status'] < 200 ||
    $result['status'] >= 300 ||
    !is_array($result['data'])
) {

    $jobs_error = 'Unable to retrieve your open job listings.';

    if (is_array($result['data'])) {
        $jobs_error .= ' ' . ($result['data']['message'] ?? '');
    }
} else {

    $jobs = $result['data'];
}


// ============================================
// 8. GET EXISTING APPLICATIONS FOR OPEN JOBS
// ============================================

$applications = [];
$application_lookup = [];

if (!empty($jobs)) {

    $job_ids = [];

    foreach ($jobs as $job) {
        if (!empty($job['id'])) {
            $job_ids[] = $job['id'];
        }
    }

    if (!empty($job_ids)) {

        $job_id_list = implode(
            ',',
            array_map('rawurlencode', $job_ids)
        );

        // Retrieve all application statuses.
        // Do not filter out rejected or closed applications.

        $url = SUPABASE_URL .
            '/rest/v1/applications?select=id,job_id,worker_id,status' .
            '&job_id=in.(' . $job_id_list . ')' .
            '&limit=2000';

        $result = $supabase_request($url);

        if ($result['response'] === false) {

            $applications_error = 'Unable to retrieve existing applications.';
        } elseif (
            $result['status'] < 200 ||
            $result['status'] >= 300 ||
            !is_array($result['data'])
        ) {

            $applications_error = 'Unable to check existing recruitment records.';
        } else {

            $applications = $result['data'];

            foreach ($applications as $application) {

                $key = $application['job_id'] .
                    ':' .
                    $application['worker_id'];

                // Any application record blocks another invitation.
                // Keep the first record found for this worker and job.

                if (!isset($application_lookup[$key])) {
                    $application_lookup[$key] = $application;
                }
            }
        }
    }
}


// ============================================
// 9. GET ACTIVE WORKER PROFILES
// ============================================

$url = SUPABASE_URL .
    '/rest/v1/profiles?select=id,full_name,bio,skills,experience,education,resume_url,portfolio_url,preferred_hourly_rate,created_at' .
    '&role=eq.worker' .
    '&account_status=eq.active' .
    '&order=created_at.desc&limit=100';

$result = $supabase_request($url);

$workers = [];

if ($result['response'] === false) {

    $error = 'Unable to connect to Supabase while retrieving worker profiles.';
} elseif (
    $result['status'] < 200 ||
    $result['status'] >= 300 ||
    !is_array($result['data'])
) {

    $error = 'Unable to retrieve worker profiles.';

    if (is_array($result['data'])) {
        $error .= ' ' . ($result['data']['message'] ?? '');
    }
} else {

    $workers = $result['data'];
}


// ============================================
// 10. GET EMPLOYER'S COMPLETE INVITATION HISTORY
// ============================================

$url = SUPABASE_URL .
    '/rest/v1/invitations?select=id,job_id,worker_id,status,created_at' .
    '&employer_id=eq.' . rawurlencode($employer_id) .
    '&order=created_at.desc&limit=5000';

$result = $supabase_request($url);

$invitations = [];

if ($result['response'] === false) {

    $invitations_error = 'Unable to retrieve invitation history.';
} elseif (
    $result['status'] < 200 ||
    $result['status'] >= 300 ||
    !is_array($result['data'])
) {

    $invitations_error = 'Unable to retrieve invitation history.';

    if (is_array($result['data'])) {
        $invitations_error .= ' ' . ($result['data']['message'] ?? '');
    }
} else {

    $invitations = $result['data'];
}


// ============================================
// 11. GET THE LATEST INVITATION PER JOB/WORKER
// ============================================

$latest_invitations = [];

foreach ($invitations as $invitation) {

    $key = $invitation['job_id'] .
        ':' .
        $invitation['worker_id'];

    // Invitations are ordered newest first.
    // Keep the latest one for display purposes.

    if (!isset($latest_invitations[$key])) {
        $latest_invitations[$key] = $invitation;
    }
}


// ============================================
// 12. FILTER WORKERS
// ============================================

$filtered_workers = [];

foreach ($workers as $worker) {

    $skills = $worker['skills'] ?? [];

    if (is_array($skills)) {
        $skills_text = implode(', ', $skills);
    } else {
        $skills_text = (string) $skills;
    }

    $search_text =
        ($worker['full_name'] ?? '') . ' ' .
        ($worker['bio'] ?? '') . ' ' .
        $skills_text . ' ' .
        ($worker['experience'] ?? '') . ' ' .
        ($worker['education'] ?? '');

    if (
        $search === '' ||
        stripos($search_text, $search) !== false
    ) {
        $filtered_workers[] = $worker;
    }
}


// ============================================
// 13. LOAD SHARED HEADER
// ============================================

require_once '../../includes/header.php';

?>

<div class="find-workers-page">

    <!-- PAGE HEADING -->
    <div class="find-workers-heading">

        <div>
            <span class="find-workers-eyebrow">
                TALENT DISCOVERY
            </span>

            <h1>Find Freelancers</h1>

            <p>
                Discover skilled workers and invite the right people to your open positions.
            </p>
        </div>

        <a href="jobs.php" class="find-workers-jobs-link">
            <i class="bi bi-briefcase"></i>
            My Job Listings
        </a>

    </div>


    <!-- SUCCESS AND ERROR MESSAGES -->
    <?php if ($message !== ''): ?>

        <?php

        $message_icon = 'bi-info-circle';

        if ($message_type === 'success') {
            $message_icon = 'bi-check-circle-fill';
        } elseif ($message_type === 'warning') {
            $message_icon = 'bi-exclamation-triangle-fill';
        } elseif ($message_type === 'danger') {
            $message_icon = 'bi-exclamation-circle-fill';
        }

        ?>

        <div class="find-workers-alert find-workers-alert-<?php echo htmlspecialchars($message_type, ENT_QUOTES, 'UTF-8'); ?>">

            <i class="bi <?php echo $message_icon; ?>"></i>

            <span>
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            </span>

        </div>

    <?php endif; ?>


    <!-- SEARCH SECTION -->
    <section class="find-workers-search-card">

        <div class="find-workers-search-heading">

            <div>
                <h2>Discover Talent</h2>

                <p>
                    Search by name, skills, experience, or education.
                </p>
            </div>

            <span class="find-workers-count">
                <?php echo count($filtered_workers); ?> workers
            </span>

        </div>

        <form
            method="GET"
            action="find-freelancers.php"
            class="find-workers-search-form">

            <div class="find-workers-search-input">

                <i class="bi bi-search"></i>

                <input
                    type="search"
                    name="search"
                    id="search"
                    placeholder="Search workers..."
                    value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">

            </div>

            <button type="submit">
                Search
            </button>

            <?php if ($search !== ''): ?>

                <a href="find-freelancers.php" class="find-workers-clear">
                    Clear Search
                </a>

            <?php endif; ?>

        </form>

    </section>


    <!-- DATA ERRORS -->
    <?php if ($error !== ''): ?>

        <div class="find-workers-alert find-workers-alert-danger">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>

    <?php endif; ?>


    <?php if ($jobs_error !== ''): ?>

        <div class="find-workers-alert find-workers-alert-warning">
            <i class="bi bi-exclamation-triangle"></i>

            <span>
                <?php echo htmlspecialchars($jobs_error, ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>

    <?php endif; ?>


    <?php if ($invitations_error !== ''): ?>

        <div class="find-workers-alert find-workers-alert-warning">
            <i class="bi bi-clock-history"></i>

            <span>
                <?php echo htmlspecialchars($invitations_error, ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>

    <?php endif; ?>


    <?php if ($applications_error !== ''): ?>

        <div class="find-workers-alert find-workers-alert-warning">
            <i class="bi bi-people"></i>

            <span>
                <?php echo htmlspecialchars($applications_error, ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>

    <?php endif; ?>


    <!-- WORKER RESULTS -->
    <?php if ($error === ''): ?>

        <div class="find-workers-results-heading">

            <h2>Available Workers</h2>

            <span>
                Showing <?php echo count($filtered_workers); ?> worker(s)
            </span>

        </div>


        <?php if (!empty($filtered_workers)): ?>

            <div class="find-workers-grid">

                <?php foreach ($filtered_workers as $worker): ?>

                    <?php

                    $worker_id = $worker['id'];

                    $skills = $worker['skills'] ?? [];

                    if (!is_array($skills)) {
                        $skills = [];
                    }

                    $worker_initial = strtoupper(
                        substr(trim($worker['full_name'] ?? 'W'), 0, 1)
                    );

                    ?>

                    <article class="find-worker-card">


                        <!-- WORKER NAME AND AVATAR -->
                        <div class="find-worker-top">

                            <div class="find-worker-avatar">
                                <?php echo htmlspecialchars($worker_initial, ENT_QUOTES, 'UTF-8'); ?>
                            </div>

                            <div class="find-worker-identity">

                                <h3>
                                    <?php echo htmlspecialchars(
                                        $worker['full_name'] ?? 'Unnamed Worker',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ); ?>
                                </h3>

                                <span>
                                    <i class="bi bi-circle-fill"></i>
                                    Active Worker
                                </span>

                            </div>

                        </div>


                        <!-- BIO -->
                        <div class="find-worker-bio">

                            <?php echo nl2br(htmlspecialchars(
                                $worker['bio'] ?? 'This worker has not added a biography yet.',
                                ENT_QUOTES,
                                'UTF-8'
                            )); ?>

                        </div>


                        <!-- HOURLY RATE -->
                        <div class="find-worker-rate">

                            <span>Preferred Hourly Rate</span>

                            <strong>

                                <?php if (($worker['preferred_hourly_rate'] ?? null) !== null): ?>

                                    $<?php echo number_format(
                                            (float) $worker['preferred_hourly_rate'],
                                            2
                                        ); ?>

                                    <small>/hr</small>

                                <?php else: ?>

                                    Not specified

                                <?php endif; ?>

                            </strong>

                        </div>


                        <!-- SKILLS -->
                        <div class="find-worker-section">

                            <h4>
                                <i class="bi bi-tools"></i>
                                Skills
                            </h4>

                            <?php if (!empty($skills)): ?>

                                <div class="find-worker-skills">

                                    <?php foreach (array_slice($skills, 0, 8) as $skill): ?>

                                        <span>
                                            <?php echo htmlspecialchars(
                                                (string) $skill,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ); ?>
                                        </span>

                                    <?php endforeach; ?>

                                    <?php if (count($skills) > 8): ?>

                                        <span>
                                            +<?php echo count($skills) - 8; ?> more
                                        </span>

                                    <?php endif; ?>

                                </div>

                            <?php else: ?>

                                <p class="find-worker-muted">
                                    No skills listed.
                                </p>

                            <?php endif; ?>

                        </div>


                        <!-- EXPERIENCE -->
                        <div class="find-worker-section">

                            <h4>
                                <i class="bi bi-briefcase"></i>
                                Experience
                            </h4>

                            <p>
                                <?php echo nl2br(htmlspecialchars(
                                    $worker['experience'] ?? 'Not specified',
                                    ENT_QUOTES,
                                    'UTF-8'
                                )); ?>
                            </p>

                        </div>


                        <!-- EDUCATION -->
                        <div class="find-worker-section">

                            <h4>
                                <i class="bi bi-mortarboard"></i>
                                Education
                            </h4>

                            <p>
                                <?php echo nl2br(htmlspecialchars(
                                    $worker['education'] ?? 'Not specified',
                                    ENT_QUOTES,
                                    'UTF-8'
                                )); ?>
                            </p>

                        </div>


                        <!-- PORTFOLIO AND RESUME -->
                        <div class="find-worker-links">

                            <?php if (
                                !empty($worker['portfolio_url']) &&
                                filter_var($worker['portfolio_url'], FILTER_VALIDATE_URL) &&
                                preg_match('/^https?:\/\//i', $worker['portfolio_url'])
                            ): ?>

                                <a
                                    href="<?php echo htmlspecialchars($worker['portfolio_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                    target="_blank"
                                    rel="noopener noreferrer">

                                    <i class="bi bi-globe"></i>
                                    Portfolio

                                </a>

                            <?php endif; ?>


                            <?php if (
                                !empty($worker['resume_url']) &&
                                filter_var($worker['resume_url'], FILTER_VALIDATE_URL) &&
                                preg_match('/^https?:\/\//i', $worker['resume_url'])
                            ): ?>

                                <a
                                    href="<?php echo htmlspecialchars($worker['resume_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                    target="_blank"
                                    rel="noopener noreferrer">

                                    <i class="bi bi-file-earmark-text"></i>
                                    Resume

                                </a>

                            <?php endif; ?>

                        </div>


                        <!-- INVITATION HISTORY -->
                        <div class="find-worker-invitation-history">

                            <h4>
                                <i class="bi bi-clock-history"></i>
                                Invitation History
                            </h4>

                            <?php $worker_has_history = false; ?>

                            <?php foreach ($jobs as $job): ?>

                                <?php

                                $key = $job['id'] . ':' . $worker_id;

                                $previous_invitation =
                                    $latest_invitations[$key] ?? null;

                                ?>

                                <?php if ($previous_invitation !== null): ?>

                                    <?php $worker_has_history = true; ?>

                                    <div class="find-worker-history-item">

                                        <span>
                                            <?php echo htmlspecialchars(
                                                $job['title'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ); ?>
                                        </span>

                                        <strong class="status-<?php echo htmlspecialchars(
                                                                    preg_replace('/[^a-z_]/', '', $previous_invitation['status']),
                                                                    ENT_QUOTES,
                                                                    'UTF-8'
                                                                ); ?>">

                                            <?php echo htmlspecialchars(
                                                ucfirst($previous_invitation['status']),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ); ?>

                                        </strong>

                                        <small>
                                            Sent
                                            <?php echo !empty($previous_invitation['created_at'])
                                                ? htmlspecialchars(
                                                    date('M d, Y', strtotime($previous_invitation['created_at'])),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                )
                                                : 'date unavailable'; ?>
                                        </small>

                                        <?php if ($previous_invitation['status'] === 'accepted'): ?>

                                            <small>
                                                Worker accepted the invitation. Review the candidate in Employer Applications.
                                            </small>

                                        <?php elseif ($previous_invitation['status'] === 'pending'): ?>

                                            <small>
                                                Waiting for the worker's response.
                                            </small>

                                        <?php elseif ($previous_invitation['status'] === 'declined'): ?>

                                            <small>
                                                The worker declined this invitation. Another invitation cannot be sent for this job.
                                            </small>

                                        <?php elseif ($previous_invitation['status'] === 'cancelled'): ?>

                                            <small>
                                                This invitation was cancelled. Another invitation cannot be sent for this job.
                                            </small>

                                        <?php endif; ?>

                                    </div>

                                <?php endif; ?>

                            <?php endforeach; ?>


                            <?php if (!$worker_has_history): ?>

                                <p class="find-worker-muted">
                                    No previous invitations for your open jobs.
                                </p>

                            <?php endif; ?>


                            <?php if ($invitations_error !== ''): ?>

                                <p class="find-worker-muted">
                                    Invitation history is currently unavailable.
                                </p>

                            <?php endif; ?>

                        </div>


                        <!-- INVITE WORKER -->
                        <div class="find-worker-invite">

                            <h4>
                                <i class="bi bi-send"></i>
                                Invite This Worker
                            </h4>

                            <p class="find-worker-muted">
                                An invitation lets the worker express interest and enter your recruitment process. It does not hire the worker or create an employment offer.
                            </p>


                            <?php if ($jobs_error !== ''): ?>

                                <p class="find-worker-muted">
                                    Your job listings could not be loaded. Please refresh the page.
                                </p>

                            <?php elseif (empty($jobs)): ?>

                                <p class="find-worker-muted">
                                    You need an open job listing before inviting workers.
                                </p>

                                <a href="create-job.php" class="find-worker-create-job">
                                    <i class="bi bi-plus-circle"></i>
                                    Create a Job
                                </a>

                            <?php elseif ($applications_error !== ''): ?>

                                <p class="find-worker-muted">
                                    Existing recruitment records could not be checked. Please refresh the page before sending an invitation.
                                </p>

                            <?php elseif ($invitations_error !== ''): ?>

                                <p class="find-worker-muted">
                                    Invitation history could not be checked. Sending invitations is temporarily disabled to prevent duplicate invitations.
                                </p>

                            <?php else: ?>

                                <form
                                    method="POST"
                                    action="find-freelancers.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>">

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                                    <input
                                        type="hidden"
                                        name="worker_id"
                                        value="<?php echo htmlspecialchars($worker_id, ENT_QUOTES, 'UTF-8'); ?>">

                                    <input
                                        type="hidden"
                                        name="search"
                                        value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">

                                    <label for="job_<?php echo htmlspecialchars($worker_id, ENT_QUOTES, 'UTF-8'); ?>">
                                        Select an open job
                                    </label>

                                    <select
                                        name="job_id"
                                        id="job_<?php echo htmlspecialchars($worker_id, ENT_QUOTES, 'UTF-8'); ?>"
                                        required>

                                        <option value="">
                                            Choose a job
                                        </option>

                                        <?php foreach ($jobs as $job): ?>

                                            <?php

                                            $key = $job['id'] . ':' . $worker_id;

                                            $previous_invitation =
                                                $latest_invitations[$key] ?? null;

                                            $existing_application =
                                                $application_lookup[$key] ?? null;

                                            // Any existing application blocks another invitation.
                                            $already_in_recruitment =
                                                $existing_application !== null;

                                            // Any previous invitation blocks another invitation.
                                            $has_previous_invitation =
                                                $previous_invitation !== null;

                                            $option_disabled =
                                                $already_in_recruitment ||
                                                $has_previous_invitation;

                                            ?>

                                            <option
                                                value="<?php echo htmlspecialchars($job['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                                <?php echo $option_disabled ? 'disabled' : ''; ?>>

                                                <?php

                                                echo htmlspecialchars(
                                                    $job['title'] .
                                                        ' - $' .
                                                        number_format((float) ($job['hourly_rate'] ?? 0), 2) .
                                                        '/hr',
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                );

                                                if ($already_in_recruitment) {

                                                    $existing_status =
                                                        $existing_application['status'] ?? 'existing';

                                                    if ($existing_status === 'rejected') {
                                                        echo ' (Previously Rejected)';
                                                    } elseif ($existing_status === 'hired') {
                                                        echo ' (Already Hired)';
                                                    } else {
                                                        echo ' (Already in Recruitment)';
                                                    }
                                                } elseif ($previous_invitation !== null) {

                                                    $previous_status =
                                                        $previous_invitation['status'] ?? 'previous';

                                                    if ($previous_status === 'pending') {
                                                        echo ' (Invitation Pending)';
                                                    } elseif ($previous_status === 'accepted') {
                                                        echo ' (Invitation Accepted)';
                                                    } elseif ($previous_status === 'declined') {
                                                        echo ' (Previously Declined)';
                                                    } elseif ($previous_status === 'cancelled') {
                                                        echo ' (Invitation Cancelled)';
                                                    } else {
                                                        echo ' (Previously Invited)';
                                                    }
                                                }

                                                ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>


                                    <label for="message_<?php echo htmlspecialchars($worker_id, ENT_QUOTES, 'UTF-8'); ?>">
                                        Personal Message
                                    </label>

                                    <textarea
                                        name="message"
                                        id="message_<?php echo htmlspecialchars($worker_id, ENT_QUOTES, 'UTF-8'); ?>"
                                        rows="3"
                                        maxlength="2000"
                                        placeholder="Introduce your project and explain why you would like to work with this person."></textarea>


                                    <button
                                        type="submit"
                                        class="find-worker-invite-button">

                                        <i class="bi bi-send"></i>
                                        Send Invitation

                                    </button>

                                </form>

                            <?php endif; ?>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>


        <?php else: ?>

            <!-- EMPTY WORKER RESULTS -->
            <div class="find-workers-empty">

                <i class="bi bi-people"></i>

                <h2>No Workers Found</h2>

                <p>

                    <?php if ($search !== ''): ?>

                        We couldn't find workers matching your search.
                        Try another name, skill, or keyword.

                    <?php else: ?>

                        There are currently no active worker profiles available.

                    <?php endif; ?>

                </p>

            </div>

        <?php endif; ?>

    <?php endif; ?>

</div>


<?php

// ============================================
// 14. LOAD SHARED FOOTER
// ============================================

require_once '../../includes/footer.php';

?>