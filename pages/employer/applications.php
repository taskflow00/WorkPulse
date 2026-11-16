<?php

// ==========================================
// EMPLOYER APPLICATIONS
// ==========================================

$required_role = 'employer';
$page_title = 'Applications';
$active_page = 'applications';
$page_styles = ['/assets/css/employer-applications.css'];

require_once '../../includes/auth.php';

$employer_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$message = '';
$message_type = 'info';


// ==========================================
// SEND INTERVIEW INVITATION
// ==========================================

if (isset($_POST['send_interview_invitation'])) {

    $application_id = trim($_POST['application_id'] ?? '');
    $interview_message = trim($_POST['interview_message'] ?? '');
    $interview_at_input = trim($_POST['interview_at'] ?? '');
    $interview_type = trim($_POST['interview_type'] ?? '');
    $meeting_details = trim($_POST['meeting_details'] ?? '');

    $allowed_interview_types = [
        'online',
        'phone',
        'in_person',
        'other'
    ];

    $valid_application_id = preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        $application_id
    );

    $interview_at = null;

    if ($interview_at_input !== '') {

        try {

            $interview_date = new DateTimeImmutable(
                $interview_at_input,
                new DateTimeZone('Asia/Manila')
            );

            $current_time = new DateTimeImmutable(
                'now',
                new DateTimeZone('Asia/Manila')
            );

            if ($interview_date <= $current_time) {
                $message = 'The interview date and time must be in the future.';
            } else {
                $interview_at = $interview_date->format('Y-m-d\TH:i:sP');
            }
        } catch (Exception $error) {

            $message = 'Invalid interview date and time.';
        }
    }

    if (!$valid_application_id) {

        $message = 'Invalid application.';
    } elseif ($message === '' && $interview_at === null) {

        $message = 'Please select an interview date and time.';
    } elseif (
        $message === '' &&
        !in_array($interview_type, $allowed_interview_types, true)
    ) {

        $message = 'Please select a valid interview type.';
    } elseif (
        $message === '' &&
        in_array($interview_type, ['online', 'in_person'], true) &&
        $meeting_details === ''
    ) {

        $message = 'Please provide the meeting link or interview location.';
    }


    if ($message === '') {

        $url = SUPABASE_URL . '/rest/v1/rpc/send_application_interview_invitation';

        $data = [
            'p_application_id' => $application_id,
            'p_message' => $interview_message,
            'p_interview_at' => $interview_at,
            'p_interview_type' => $interview_type,
            'p_meeting_details' => $meeting_details
        ];

        $options = [
            'http' => [
                'method' => 'POST',
                'header' =>
                "Content-Type: application/json\r\n" .
                    "apikey: " . SUPABASE_KEY . "\r\n" .
                    "Authorization: Bearer " . $access_token . "\r\n",
                'content' => json_encode($data),
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        $response_headers = http_get_last_response_headers();

        preg_match(
            '/\s(\d{3})\s/',
            $response_headers[0] ?? '',
            $matches
        );

        $status_code = (int)($matches[1] ?? 0);

        if ($response !== false) {

            $result = json_decode($response, true);

            if (
                $status_code >= 200 &&
                $status_code < 300 &&
                !empty($result['success'])
            ) {

                if (!empty($result['already_exists'])) {
                    $message = 'An active interview invitation already exists for this applicant.';
                } else {
                    $message = 'Interview invitation sent successfully!';
                }

                $message_type = 'success';
            } else {

                $message = $result['message'] ?? 'Unable to send the interview invitation.';
                $message_type = 'error';
            }
        } else {

            $message = 'Unable to connect to Supabase.';
            $message_type = 'error';
        }
    }
}


// ==========================================
// REJECT APPLICATION
// ==========================================

if (isset($_POST['reject_application'])) {

    $application_id = trim($_POST['application_id'] ?? '');

    $valid_application_id = preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        $application_id
    );

    if (!$valid_application_id) {

        $message = 'Invalid application.';
        $message_type = 'error';
    } else {

        $url = SUPABASE_URL .
            '/rest/v1/applications?id=eq.' . urlencode($application_id) .
            '&status=in.(pending,shortlisted,interview,accepted)';

        $options = [
            'http' => [
                'method' => 'PATCH',
                'header' =>
                "Content-Type: application/json\r\n" .
                    "apikey: " . SUPABASE_KEY . "\r\n" .
                    "Authorization: Bearer " . $access_token . "\r\n" .
                    "Prefer: return=representation\r\n",
                'content' => json_encode([
                    'status' => 'rejected'
                ]),
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        $response_headers = http_get_last_response_headers();

        preg_match(
            '/\s(\d{3})\s/',
            $response_headers[0] ?? '',
            $matches
        );

        $status_code = (int)($matches[1] ?? 0);

        if ($response !== false) {

            $result = json_decode($response, true);

            if (
                $status_code >= 200 &&
                $status_code < 300 &&
                !empty($result)
            ) {

                $message = 'Application rejected successfully.';
                $message_type = 'success';
            } else {

                $message = 'Unable to reject the application.';
                $message_type = 'error';
            }
        } else {

            $message = 'Unable to connect to Supabase.';
            $message_type = 'error';
        }
    }
}


// ==========================================
// RETRIEVE EMPLOYER APPLICATIONS
// ==========================================

$url = SUPABASE_URL .
    '/rest/v1/applications?select=id,worker_id,job_id,application_statement,status,applied_at,resume_path,jobs!inner(title,company_name,hourly_rate,employment_type,work_schedule)' .
    '&jobs.employer_id=eq.' . urlencode($employer_id) .
    '&order=applied_at.desc';

$options = [
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $access_token . "\r\n",
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

$applications = [];
$profiles = [];
$invitations = [];
$employment_details = [];

if ($response === false) {

    $message = 'Unable to retrieve applications.';
    $message_type = 'error';
} else {

    $response_headers = http_get_last_response_headers();

    preg_match(
        '/\s(\d{3})\s/',
        $response_headers[0] ?? '',
        $matches
    );

    $status_code = (int)($matches[1] ?? 0);

    $result = json_decode($response, true);

    if (
        $status_code >= 200 &&
        $status_code < 300 &&
        is_array($result) &&
        array_is_list($result)
    ) {

        $applications = $result;

        $worker_ids = array_unique(
            array_column($applications, 'worker_id')
        );

        $application_ids = array_column($applications, 'id');


        // RETRIEVE WORKER PROFILES
        if (!empty($worker_ids)) {

            $profile_url = SUPABASE_URL .
                '/rest/v1/profiles?select=id,full_name,skills,experience,education,portfolio_url,preferred_hourly_rate' .
                '&id=in.(' . implode(',', $worker_ids) . ')';

            $profile_options = [
                'http' => [
                    'method' => 'GET',
                    'header' =>
                    "apikey: " . SUPABASE_KEY . "\r\n" .
                        "Authorization: Bearer " . $access_token . "\r\n",
                    'ignore_errors' => true
                ]
            ];

            $profile_context = stream_context_create($profile_options);

            $profile_response = file_get_contents(
                $profile_url,
                false,
                $profile_context
            );

            if ($profile_response !== false) {
                $profiles = json_decode($profile_response, true) ?? [];
            }
        }


        // RETRIEVE INTERVIEW INVITATIONS
        if (!empty($application_ids)) {

            $invitation_url = SUPABASE_URL .
                '/rest/v1/invitations?select=id,application_id,status,created_at,message,interview_at,interview_type,meeting_details' .
                '&employer_id=eq.' . urlencode($employer_id) .
                '&application_id=in.(' . implode(',', $application_ids) . ')' .
                '&order=created_at.desc';

            $invitation_options = [
                'http' => [
                    'method' => 'GET',
                    'header' =>
                    "apikey: " . SUPABASE_KEY . "\r\n" .
                        "Authorization: Bearer " . $access_token . "\r\n",
                    'ignore_errors' => true
                ]
            ];

            $invitation_context = stream_context_create($invitation_options);

            $invitation_response = file_get_contents(
                $invitation_url,
                false,
                $invitation_context
            );

            if ($invitation_response !== false) {
                $invitations = json_decode($invitation_response, true) ?? [];
            }


            // RETRIEVE EMPLOYMENT OFFERS
            $details_url = SUPABASE_URL .
                '/rest/v1/employment_offers?select=id,application_id,invitation_id,status,hourly_rate,start_date,expires_at,worker_response_message,created_at' .
                '&employer_id=eq.' . urlencode($employer_id) .
                '&order=created_at.desc&limit=500';

            $details_options = [
                'http' => [
                    'method' => 'GET',
                    'header' =>
                    "apikey: " . SUPABASE_KEY . "\r\n" .
                        "Authorization: Bearer " . $access_token . "\r\n",
                    'ignore_errors' => true
                ]
            ];

            $details_context = stream_context_create($details_options);

            $details_response = file_get_contents(
                $details_url,
                false,
                $details_context
            );

            if ($details_response !== false) {
                $employment_details = json_decode($details_response, true) ?? [];
            }
        }
    } else {

        $message = 'Something went wrong while retrieving applications.';
        $message_type = 'error';
    }
}


// ==========================================
// MATCH APPLICATIONS WITH PROFILES
// ==========================================

$profile_map = [];

foreach ($profiles as $profile) {
    $profile_map[$profile['id']] = $profile;
}


// ==========================================
// MATCH APPLICATIONS WITH INVITATIONS
// ==========================================

$invitation_map = [];

foreach ($invitations as $invitation) {

    if (!empty($invitation['application_id'])) {
        $invitation_map[$invitation['application_id']][] = $invitation;
    }
}


// ==========================================
// MATCH INVITATION IDS WITH APPLICATIONS
// ==========================================

$invitation_application_map = [];

foreach ($invitations as $invitation) {

    if (!empty($invitation['id']) && !empty($invitation['application_id'])) {
        $invitation_application_map[$invitation['id']] = $invitation['application_id'];
    }
}


// ==========================================
// MATCH APPLICATIONS WITH EMPLOYMENT OFFERS
// ==========================================

$details_map = [];

foreach ($employment_details as $details) {

    $matched_application_id = $details['application_id'] ?? null;

    if (
        empty($matched_application_id) &&
        !empty($details['invitation_id'])
    ) {
        $matched_application_id =
            $invitation_application_map[$details['invitation_id']] ?? null;
    }

    if (
        !empty($matched_application_id) &&
        !isset($details_map[$matched_application_id])
    ) {
        $details_map[$matched_application_id] = $details;
    }
}


// ==========================================
// APPLICATION STATISTICS
// ==========================================

$total_applications = count($applications);
$pending_count = 0;
$shortlisted_count = 0;
$interview_count = 0;
$hired_count = 0;

foreach ($applications as $application) {

    $status = $application['status'] ?? '';

    if ($status === 'pending') {
        $pending_count++;
    } elseif ($status === 'shortlisted') {
        $shortlisted_count++;
    } elseif ($status === 'interview') {
        $interview_count++;
    } elseif ($status === 'hired') {
        $hired_count++;
    }
}


// ==========================================
// LOAD SHARED DASHBOARD
// ==========================================

require_once '../../includes/header.php';

?>

<div class="wp-page employer-applications-page">

    <!-- PAGE HEADING -->

    <section class="employer-applications-heading">

        <div>
            <h1 class="wp-page-title">Applications</h1>

            <p class="wp-page-description">
                Review applicants, manage interviews, and handle employment offers.
            </p>
        </div>

    </section>


    <!-- APPLICATION STATISTICS -->

    <section class="employer-application-stats">

        <div class="employer-application-stat">
            <div class="employer-application-stat-icon stat-rose">
                <i class="bi bi-people"></i>
            </div>

            <div>
                <span>Total Applications</span>
                <h2><?= $total_applications ?></h2>
                <small>All received applications</small>
            </div>
        </div>


        <div class="employer-application-stat">
            <div class="employer-application-stat-icon stat-yellow">
                <i class="bi bi-hourglass-split"></i>
            </div>

            <div>
                <span>Pending Review</span>
                <h2><?= $pending_count ?></h2>
                <small>Awaiting evaluation</small>
            </div>
        </div>


        <div class="employer-application-stat">
            <div class="employer-application-stat-icon stat-blue">
                <i class="bi bi-chat-square-text"></i>
            </div>

            <div>
                <span>Shortlisted</span>
                <h2><?= $shortlisted_count ?></h2>
                <small>Potential candidates</small>
            </div>
        </div>


        <div class="employer-application-stat">
            <div class="employer-application-stat-icon stat-green">
                <i class="bi bi-person-check"></i>
            </div>

            <div>
                <span>Hired</span>
                <h2><?= $hired_count ?></h2>
                <small>Successfully hired</small>
            </div>
        </div>

    </section>


    <!-- FEEDBACK MESSAGE -->

    <?php if ($message !== ''): ?>

        <div class="employer-application-alert alert-<?= htmlspecialchars($message_type, ENT_QUOTES, 'UTF-8') ?>">

            <i class="bi <?= $message_type === 'success'
                                ? 'bi-check-circle-fill'
                                : ($message_type === 'error'
                                    ? 'bi-exclamation-circle-fill'
                                    : 'bi-info-circle-fill') ?>"></i>

            <span>
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </span>

        </div>

    <?php endif; ?>


    <!-- APPLICATION LIST -->

    <section class="wp-panel employer-applications-section">

        <div class="employer-applications-section-heading">

            <div>
                <h2 class="wp-panel-title">Received Applications</h2>
                <p class="wp-panel-description">
                    Review each candidate and manage their application progress.
                </p>
            </div>

            <span class="employer-applications-total">
                <?= $total_applications ?> applicant(s)
            </span>

        </div>


        <?php if (empty($applications)): ?>

            <div class="employer-applications-empty">

                <div class="employer-applications-empty-icon">
                    <i class="bi bi-person-lines-fill"></i>
                </div>

                <h3>No applications yet</h3>

                <p>
                    Applications from workers will appear here when they apply to your job postings.
                </p>

                <a href="jobs.php" class="wp-btn wp-btn-primary">
                    <i class="bi bi-briefcase"></i>
                    View My Job Posts
                </a>

            </div>

        <?php else: ?>

            <div class="employer-applications-list">

                <?php foreach ($applications as $application): ?>

                    <?php

                    $job = $application['jobs'] ?? null;

                    $worker = $profile_map[$application['worker_id']] ?? null;

                    $application_invitations =
                        $invitation_map[$application['id']] ?? [];

                    $latest_invitation =
                        $application_invitations[0] ?? null;

                    $details =
                        $details_map[$application['id']] ?? null;

                    $application_status = $application['status'] ?? 'pending';

                    $can_invite = (
                        in_array(
                            $application_status,
                            ['pending', 'shortlisted'],
                            true
                        ) ||
                        (
                            in_array(
                                $application_status,
                                ['interview', 'accepted'],
                                true
                            ) &&
                            (
                                $latest_invitation === null ||
                                ($latest_invitation['status'] ?? '') === 'declined'
                            )
                        )
                    );

                    $can_reject = in_array(
                        $application_status,
                        ['pending', 'shortlisted', 'interview', 'accepted'],
                        true
                    );

                    $can_prepare_offer = (
                        $latest_invitation !== null &&
                        ($latest_invitation['status'] ?? '') === 'accepted' &&
                        $details === null &&
                        !in_array(
                            $application_status,
                            ['rejected', 'hired'],
                            true
                        )
                    );


                    // APPLICATION STATUS LABELS

                    $status_labels = [
                        'pending' => 'Pending Review',
                        'shortlisted' => 'Shortlisted',
                        'interview' => 'Interview',
                        'accepted' => 'Proceeding to Employment',
                        'rejected' => 'Rejected',
                        'hired' => 'Hired'
                    ];

                    $status_class = 'application-status-pending';

                    if ($application_status === 'shortlisted') {
                        $status_class = 'application-status-shortlisted';
                    } elseif ($application_status === 'interview') {
                        $status_class = 'application-status-interview';
                    } elseif ($application_status === 'accepted') {
                        $status_class = 'application-status-accepted';
                    } elseif ($application_status === 'rejected') {
                        $status_class = 'application-status-rejected';
                    } elseif ($application_status === 'hired') {
                        $status_class = 'application-status-hired';
                    }

                    $worker_name = $worker['full_name'] ?? 'Unknown Applicant';
                    $worker_initial = strtoupper(substr(trim($worker_name), 0, 1));

                    ?>

                    <article class="employer-application-card">

                        <!-- APPLICANT HEADER -->

                        <div class="employer-application-top">

                            <div class="employer-applicant-identity">

                                <div class="employer-applicant-avatar">
                                    <?= htmlspecialchars($worker_initial, ENT_QUOTES, 'UTF-8') ?>
                                </div>

                                <div class="employer-applicant-name">

                                    <h3>
                                        <?= htmlspecialchars(
                                            $worker_name,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </h3>

                                    <span>
                                        <i class="bi bi-calendar3"></i>

                                        Applied
                                        <?= !empty($application['applied_at'])
                                            ? htmlspecialchars(date(
                                                'M d, Y',
                                                strtotime($application['applied_at'])
                                            ), ENT_QUOTES, 'UTF-8')
                                            : 'date unavailable' ?>
                                    </span>

                                </div>

                            </div>


                            <span class="employer-application-status <?= $status_class ?>">
                                <?= htmlspecialchars(
                                    $status_labels[$application_status] ?? ucfirst($application_status),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>

                        </div>


                        <!-- JOB INFORMATION -->

                        <?php if ($job): ?>

                            <div class="employer-application-job">

                                <div class="employer-application-job-icon">
                                    <i class="bi bi-briefcase"></i>
                                </div>

                                <div class="employer-application-job-info">

                                    <span>Applied Position</span>

                                    <strong>
                                        <?= htmlspecialchars(
                                            $job['title'] ?? 'Untitled Job',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </strong>

                                    <small>
                                        <?= htmlspecialchars(
                                            $job['company_name'] ?? 'Company not specified',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </small>

                                </div>

                            </div>


                            <div class="employer-application-job-meta">

                                <div>
                                    <span>Advertised Rate</span>
                                    <strong>
                                        $<?= number_format((float)($job['hourly_rate'] ?? 0), 2) ?>/hr
                                    </strong>
                                </div>

                                <div>
                                    <span>Employment Type</span>
                                    <strong>
                                        <?= htmlspecialchars(
                                            ucwords(str_replace('_', ' ', $job['employment_type'] ?? 'Not specified')),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </strong>
                                </div>

                                <div>
                                    <span>Work Schedule</span>
                                    <strong>
                                        <?= htmlspecialchars(
                                            ucwords(str_replace('_', ' ', $job['work_schedule'] ?? 'Not specified')),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </strong>
                                </div>

                            </div>

                        <?php endif; ?>


                        <!-- APPLICANT DETAILS -->

                        <div class="employer-application-details">

                            <div class="employer-application-details-heading">
                                <i class="bi bi-person-vcard"></i>
                                Applicant Profile
                            </div>


                            <?php if ($worker): ?>

                                <div class="employer-profile-field">

                                    <span>Skills</span>

                                    <div class="employer-skills-list">

                                        <?php

                                        $skills = $worker['skills'] ?? [];

                                        if (is_string($skills)) {
                                            $decoded_skills = json_decode($skills, true);

                                            if (is_array($decoded_skills)) {
                                                $skills = $decoded_skills;
                                            }
                                        }

                                        if (is_array($skills) && !empty($skills)):

                                            foreach ($skills as $skill):

                                                if (is_array($skill)) {
                                                    continue;
                                                }

                                        ?>

                                                <span class="employer-skill-tag">
                                                    <?= htmlspecialchars((string)$skill, ENT_QUOTES, 'UTF-8') ?>
                                                </span>

                                            <?php endforeach; ?>

                                        <?php else: ?>

                                            <span class="employer-profile-muted">
                                                Not specified
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </div>


                                <div class="employer-profile-field">

                                    <span>Experience</span>

                                    <p>
                                        <?= !empty($worker['experience'])
                                            ? nl2br(htmlspecialchars($worker['experience'], ENT_QUOTES, 'UTF-8'))
                                            : 'No experience information provided.' ?>
                                    </p>

                                </div>


                                <div class="employer-profile-field">

                                    <span>Education</span>

                                    <p>
                                        <?= !empty($worker['education'])
                                            ? nl2br(htmlspecialchars($worker['education'], ENT_QUOTES, 'UTF-8'))
                                            : 'No education information provided.' ?>
                                    </p>

                                </div>


                                <div class="employer-profile-field">

                                    <span>Preferred Hourly Rate</span>

                                    <p>
                                        <?= !empty($worker['preferred_hourly_rate'])
                                            ? '$' . number_format((float)$worker['preferred_hourly_rate'], 2) . '/hour'
                                            : 'Not specified' ?>
                                    </p>

                                </div>


                                <div class="employer-applicant-links">

                                    <?php if (!empty($worker['portfolio_url'])): ?>

                                        <a
                                            href="<?= htmlspecialchars($worker['portfolio_url'], ENT_QUOTES, 'UTF-8') ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="employer-applicant-link">

                                            <i class="bi bi-box-arrow-up-right"></i>
                                            View Portfolio

                                        </a>

                                    <?php endif; ?>


                                    <?php if (!empty($application['resume_path'])): ?>

                                        <a
                                            href="view-resume.php?id=<?= urlencode($application['id']) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="employer-applicant-link">

                                            <i class="bi bi-file-earmark-pdf"></i>
                                            View Submitted Resume

                                        </a>

                                    <?php else: ?>

                                        <span class="employer-profile-muted">
                                            No resume submitted
                                        </span>

                                    <?php endif; ?>

                                </div>

                            <?php else: ?>

                                <p class="employer-profile-muted">
                                    Applicant profile information is unavailable.
                                </p>

                            <?php endif; ?>

                        </div>


                        <!-- APPLICATION STATEMENT -->

                        <div class="employer-application-statement">

                            <div class="employer-application-details-heading">
                                <i class="bi bi-chat-left-text"></i>
                                Application Statement
                            </div>

                            <p>
                                <?= !empty($application['application_statement'])
                                    ? nl2br(htmlspecialchars(
                                        $application['application_statement'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ))
                                    : 'No application statement provided.' ?>
                            </p>

                        </div>


                        <!-- APPLICATION ACTIONS -->

                        <div class="employer-application-actions">

                            <?php if ($can_reject): ?>

                                <form method="POST" class="employer-reject-form">

                                    <input
                                        type="hidden"
                                        name="application_id"
                                        value="<?= htmlspecialchars($application['id'], ENT_QUOTES, 'UTF-8') ?>">

                                    <button
                                        type="submit"
                                        name="reject_application"
                                        class="employer-action-button employer-action-reject"
                                        onclick="return confirm('Are you sure you want to reject this applicant?');">

                                        <i class="bi bi-x-circle"></i>
                                        Reject Applicant

                                    </button>

                                </form>

                            <?php endif; ?>


                            <?php if ($can_prepare_offer): ?>

                                <a
                                    href="send-offer.php?id=<?= urlencode($application['id']) ?>"
                                    class="wp-btn wp-btn-primary">

                                    <i class="bi bi-file-earmark-check"></i>
                                    Prepare Employment Details

                                </a>

                            <?php endif; ?>

                        </div>


                        <!-- INTERVIEW HISTORY -->

                        <details class="employer-application-expand">

                            <summary>
                                <span>
                                    <i class="bi bi-calendar2-week"></i>
                                    Interview History
                                    <small><?= count($application_invitations) ?></small>
                                </span>

                                <i class="bi bi-chevron-down"></i>
                            </summary>


                            <div class="employer-expand-content">

                                <?php if (empty($application_invitations)): ?>

                                    <p class="employer-profile-muted">
                                        No interview invitations have been sent yet.
                                    </p>

                                <?php else: ?>

                                    <?php foreach ($application_invitations as $invitation): ?>

                                        <?php

                                        $invitation_labels = [
                                            'pending' => 'Awaiting Worker Response',
                                            'accepted' => 'Invitation Accepted',
                                            'declined' => 'Invitation Declined'
                                        ];

                                        $interview_labels = [
                                            'online' => 'Online Meeting',
                                            'phone' => 'Phone Call',
                                            'in_person' => 'In Person',
                                            'other' => 'Other'
                                        ];

                                        ?>

                                        <div class="employer-history-item">

                                            <div class="employer-history-item-top">

                                                <strong>
                                                    <?= htmlspecialchars(
                                                        $invitation_labels[$invitation['status']] ?? ucfirst($invitation['status']),
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>
                                                </strong>

                                                <span>
                                                    <?= !empty($invitation['created_at'])
                                                        ? htmlspecialchars(date(
                                                            'M d, Y',
                                                            strtotime($invitation['created_at'])
                                                        ), ENT_QUOTES, 'UTF-8')
                                                        : 'Date unavailable' ?>
                                                </span>

                                            </div>


                                            <?php if (!empty($invitation['interview_at'])): ?>

                                                <p>
                                                    <i class="bi bi-calendar-event"></i>

                                                    <strong>Schedule:</strong>

                                                    <?= htmlspecialchars(date(
                                                        'M d, Y - h:i A',
                                                        strtotime($invitation['interview_at'])
                                                    ), ENT_QUOTES, 'UTF-8') ?>
                                                </p>

                                            <?php endif; ?>


                                            <?php if (!empty($invitation['interview_type'])): ?>

                                                <p>
                                                    <i class="bi bi-camera-video"></i>

                                                    <strong>Type:</strong>

                                                    <?= htmlspecialchars(
                                                        $interview_labels[$invitation['interview_type']] ?? $invitation['interview_type'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>
                                                </p>

                                            <?php endif; ?>


                                            <?php if (!empty($invitation['meeting_details'])): ?>

                                                <p>
                                                    <i class="bi bi-geo-alt"></i>

                                                    <strong>Meeting Details:</strong>

                                                    <?= nl2br(htmlspecialchars(
                                                        $invitation['meeting_details'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    )) ?>
                                                </p>

                                            <?php endif; ?>


                                            <?php if (!empty($invitation['message'])): ?>

                                                <div class="employer-history-message">
                                                    <?= nl2br(htmlspecialchars(
                                                        $invitation['message'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    )) ?>
                                                </div>

                                            <?php endif; ?>

                                        </div>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </div>

                        </details>


                        <!-- EMPLOYMENT OFFER -->

                        <?php if ($details !== null): ?>

                            <details class="employer-application-expand" open>

                                <summary>
                                    <span>
                                        <i class="bi bi-file-earmark-text"></i>
                                        Employment Details
                                    </span>

                                    <i class="bi bi-chevron-down"></i>
                                </summary>


                                <div class="employer-expand-content">

                                    <div class="employer-offer-summary">

                                        <div>
                                            <span>Agreed Hourly Rate</span>
                                            <strong>
                                                $<?= number_format((float)$details['hourly_rate'], 2) ?>/hour
                                            </strong>
                                        </div>

                                        <div>
                                            <span>Start Date</span>
                                            <strong>
                                                <?= !empty($details['start_date'])
                                                    ? htmlspecialchars(date(
                                                        'M d, Y',
                                                        strtotime($details['start_date'])
                                                    ), ENT_QUOTES, 'UTF-8')
                                                    : 'Not specified' ?>
                                            </strong>
                                        </div>

                                    </div>


                                    <?php if ($details['status'] === 'pending'): ?>

                                        <div class="employer-offer-notice notice-pending">
                                            <i class="bi bi-hourglass-split"></i>
                                            Waiting for the worker to confirm the employment details.
                                        </div>

                                    <?php elseif ($details['status'] === 'revision_requested'): ?>

                                        <div class="employer-offer-notice notice-revision">
                                            <i class="bi bi-pencil-square"></i>
                                            <div>
                                                <strong>Worker requested changes</strong>

                                                <p>
                                                    <?= nl2br(htmlspecialchars(
                                                        $details['worker_response_message'] ?? 'No explanation provided.',
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    )) ?>
                                                </p>
                                            </div>
                                        </div>

                                        <a
                                            href="send-offer.php?id=<?= urlencode($application['id']) ?>"
                                            class="wp-btn wp-btn-primary wp-btn-sm">

                                            <i class="bi bi-pencil"></i>
                                            Revise Employment Details

                                        </a>

                                    <?php elseif ($details['status'] === 'accepted'): ?>

                                        <div class="employer-offer-notice notice-accepted">
                                            <i class="bi bi-check-circle"></i>
                                            Worker confirmed the employment details.
                                        </div>

                                    <?php elseif ($details['status'] === 'declined'): ?>

                                        <div class="employer-offer-notice notice-declined">
                                            <i class="bi bi-x-circle"></i>
                                            Worker declined the proposed employment details.
                                        </div>

                                    <?php endif; ?>

                                </div>

                            </details>

                        <?php endif; ?>


                        <!-- SCHEDULE INTERVIEW -->

                        <?php if ($can_invite): ?>

                            <details class="employer-application-expand employer-interview-expand">

                                <summary>
                                    <span>
                                        <i class="bi bi-calendar-plus"></i>

                                        <?= (
                                            $latest_invitation !== null &&
                                            ($latest_invitation['status'] ?? '') === 'declined'
                                        )
                                            ? 'Schedule Another Interview'
                                            : 'Schedule Interview' ?>
                                    </span>

                                    <i class="bi bi-chevron-down"></i>
                                </summary>


                                <div class="employer-expand-content">

                                    <p class="employer-interview-description">
                                        Choose an interview schedule and provide the necessary details for the applicant.
                                    </p>

                                    <form method="POST" class="employer-interview-form">

                                        <input
                                            type="hidden"
                                            name="application_id"
                                            value="<?= htmlspecialchars($application['id'], ENT_QUOTES, 'UTF-8') ?>">


                                        <div class="employer-form-field">

                                            <label>Interview Date and Time</label>

                                            <input
                                                type="datetime-local"
                                                name="interview_at"
                                                required>

                                        </div>


                                        <div class="employer-form-field">

                                            <label>Interview Type</label>

                                            <select name="interview_type" required>

                                                <option value="">Choose interview type</option>
                                                <option value="online">Online Meeting</option>
                                                <option value="phone">Phone Call</option>
                                                <option value="in_person">In Person</option>
                                                <option value="other">Other</option>

                                            </select>

                                        </div>


                                        <div class="employer-form-field">

                                            <label>Meeting Link or Location</label>

                                            <textarea
                                                name="meeting_details"
                                                rows="3"
                                                placeholder="Enter the meeting link, address, or call details."></textarea>

                                            <small>
                                                Required for online and in-person interviews.
                                            </small>

                                        </div>


                                        <div class="employer-form-field">

                                            <label>Personal Message</label>

                                            <textarea
                                                name="interview_message"
                                                rows="4"
                                                placeholder="Tell the applicant about the interview and what they should prepare."></textarea>

                                        </div>


                                        <div class="employer-interview-submit">

                                            <button
                                                type="submit"
                                                name="send_interview_invitation"
                                                class="wp-btn wp-btn-primary">

                                                <i class="bi bi-send"></i>
                                                Send Interview Invitation

                                            </button>

                                        </div>

                                    </form>

                                </div>

                            </details>

                        <?php elseif (
                            $latest_invitation !== null &&
                            ($latest_invitation['status'] ?? '') === 'pending'
                        ): ?>

                            <div class="employer-waiting-notice">
                                <i class="bi bi-clock-history"></i>
                                Waiting for the worker to respond to your interview invitation.
                            </div>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>

</div>

<?php require_once '../../includes/footer.php'; ?>