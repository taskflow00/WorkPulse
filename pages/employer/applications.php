<?php

// ==========================================
// 1. EMPLOYER APPLICATIONS
// ==========================================

$required_role = 'employer';
$page_title = 'Applications';
$active_page = 'applications';
$page_styles = ['/assets/css/employer-applications.css'];

require_once '../../includes/auth.php';
require_once '../../includes/send-email.php';
require_once '../../includes/email-templates.php';

date_default_timezone_set('Asia/Manila');

$employer_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$message = '';
$message_type = 'info';


// ==========================================
// 2. CSRF PROTECTION
// ==========================================

if (empty($_SESSION['employer_applications_csrf'])) {
    $_SESSION['employer_applications_csrf'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['employer_applications_csrf'];


// ==========================================
// 3. SUPABASE REQUEST HELPER
// ==========================================

$supabase_request = function (
    $url,
    $method = 'GET',
    $data = null,
    $use_service_role = false
) use ($access_token) {

    $api_key = SUPABASE_KEY;
    $authorization = $access_token;

    if ($use_service_role) {

        if (
            !defined('SUPABASE_SERVICE_ROLE_KEY') ||
            SUPABASE_SERVICE_ROLE_KEY === ''
        ) {
            return [
                'success' => false,
                'status' => 0,
                'data' => null
            ];
        }

        $api_key = SUPABASE_SERVICE_ROLE_KEY;
        $authorization = SUPABASE_SERVICE_ROLE_KEY;
    }

    $headers =
        "apikey: " . $api_key . "\r\n" .
        "Authorization: Bearer " . $authorization . "\r\n" .
        "Accept: application/json\r\n";

    if ($data !== null) {
        $headers .= "Content-Type: application/json\r\n";
    }

    if (in_array($method, ['POST', 'PATCH', 'DELETE'], true)) {
        $headers .= "Prefer: return=representation\r\n";
    }

    $options = [
        'http' => [
            'method' => $method,
            'header' => $headers,
            'ignore_errors' => true,
            'timeout' => 20
        ]
    ];

    if ($data !== null) {

        $json_data = json_encode($data);

        if ($json_data === false) {
            return [
                'success' => false,
                'status' => 0,
                'data' => null
            ];
        }

        $options['http']['content'] = $json_data;
    }

    $context = stream_context_create($options);

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    $response_headers = $http_response_header ?? [];

    $status_code = 0;

    foreach ($response_headers as $header) {

        if (preg_match('/^HTTP\/\S+\s+(\d{3})/i', $header, $matches)) {
            $status_code = (int) $matches[1];
        }
    }

    return [
        'success' => (
            $response !== false &&
            $status_code >= 200 &&
            $status_code < 300
        ),
        'status' => $status_code,
        'data' => $response !== false
            ? json_decode($response, true)
            : null
    ];
};


// ==========================================
// 4. HELPER FUNCTIONS
// ==========================================

$is_valid_uuid = function ($value) {

    return is_string($value) &&
        preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        );
};


$get_worker_email = function ($worker_id) use ($supabase_request) {

    if (empty($worker_id)) {
        return null;
    }

    $url = SUPABASE_URL .
        '/auth/v1/admin/users/' .
        rawurlencode($worker_id);

    $result = $supabase_request(
        $url,
        'GET',
        null,
        true
    );

    if (!$result['success'] || !is_array($result['data'])) {
        error_log('WorkPulse: Unable to retrieve worker email.');
        return null;
    }

    $email = $result['data']['email'] ?? null;

    if (
        !is_string($email) ||
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        return null;
    }

    return $email;
};


$get_worker_profile = function ($worker_id) use ($supabase_request) {

    $url = SUPABASE_URL .
        '/rest/v1/profiles?select=full_name' .
        '&id=eq.' . rawurlencode($worker_id) .
        '&limit=1';

    $result = $supabase_request($url);

    if (
        !$result['success'] ||
        !is_array($result['data']) ||
        empty($result['data'][0])
    ) {
        return null;
    }

    return $result['data'][0];
};


$notify_worker = function (
    $worker_id,
    $subject,
    $email_body
) use ($get_worker_email) {

    $worker_email = $get_worker_email($worker_id);

    if ($worker_email === null) {
        return false;
    }

    return sendEmail(
        $worker_email,
        $subject,
        $email_body
    );
};


// Only allow the generated WorkPulse demo meeting URL format.

$is_valid_demo_meeting_link = function ($url) {

    return is_string($url) &&
        preg_match(
            '/^https:\/\/meet\.google\.com\/wpx-[a-f0-9]{4}-[a-f0-9]{4}$/i',
            $url
        );
};


// ==========================================
// 5. HANDLE POST REQUESTS
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted_token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submitted_token) ||
        !hash_equals($csrf_token, $submitted_token)
    ) {

        $message = 'Invalid request. Please refresh the page and try again.';
        $message_type = 'error';
    } else {

        $application_id = trim($_POST['application_id'] ?? '');

        if (!$is_valid_uuid($application_id)) {

            $message = 'Invalid application.';
            $message_type = 'error';
        } else {

            // Verify that this application belongs to the employer.

            $application_url = SUPABASE_URL .
                '/rest/v1/applications?select=id,worker_id,job_id,status,application_statement,jobs!inner(title,company_name)' .
                '&id=eq.' . rawurlencode($application_id) .
                '&jobs.employer_id=eq.' . rawurlencode($employer_id) .
                '&limit=1';

            $application_result = $supabase_request($application_url);

            if (
                !$application_result['success'] ||
                empty($application_result['data'][0])
            ) {

                $message = 'Application not found or you do not have permission to manage it.';
                $message_type = 'error';
            } else {

                $application_data = $application_result['data'][0];

                $worker_id = $application_data['worker_id'];
                $application_status = $application_data['status'] ?? '';

                $job_title = $application_data['jobs']['title']
                    ?? 'the job position';

                $worker_profile = $get_worker_profile($worker_id);

                $worker_name = $worker_profile['full_name'] ?? 'Worker';


                // ==========================================
                // 5A. SEND INTERVIEW INVITATION
                // ==========================================

                if (isset($_POST['send_interview_invitation'])) {

                    $interview_message = trim(
                        $_POST['interview_message'] ?? ''
                    );

                    $interview_at_input = trim(
                        $_POST['interview_at'] ?? ''
                    );

                    $meeting_details = trim(
                        $_POST['meeting_details'] ?? ''
                    );

                    $interview_type = 'online';

                    $allowed_statuses = [
                        'pending',
                        'shortlisted',
                        'interview'
                    ];

                    if (
                        !in_array(
                            $application_status,
                            $allowed_statuses,
                            true
                        )
                    ) {

                        $message = 'This application is no longer eligible for an interview invitation.';
                        $message_type = 'error';
                    } elseif ($interview_at_input === '') {

                        $message = 'Please select an interview date and time.';
                        $message_type = 'error';
                    } elseif (strlen($interview_message) > 2000) {

                        $message = 'The interview message cannot exceed 2,000 characters.';
                        $message_type = 'error';
                    } elseif (strlen($meeting_details) > 4000) {

                        $message = 'Meeting notes cannot exceed 4,000 characters.';
                        $message_type = 'error';
                    } else {

                        $interview_at = null;

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
                                $message_type = 'error';
                            } else {

                                $interview_at = $interview_date->format(
                                    'Y-m-d\TH:i:sP'
                                );
                            }
                        } catch (Exception $exception) {

                            $message = 'Invalid interview date and time.';
                            $message_type = 'error';
                        }


                        if ($message === '' && $interview_at !== null) {

                            try {

                                // Generate a unique demo meeting link.
                                // This does not create a real meeting room.

                                $meeting_link = 'https://meet.google.com/wpx-' .
                                    bin2hex(random_bytes(2)) . '-' .
                                    bin2hex(random_bytes(2));


                                // The database RPC validates the application
                                // status and checks the latest interview.
                                // It also prevents duplicate pending invitations.

                                $rpc_url = SUPABASE_URL .
                                    '/rest/v1/rpc/send_application_interview_invitation';

                                $rpc_data = [
                                    'p_application_id' => $application_id,
                                    'p_message' => $interview_message,
                                    'p_interview_at' => $interview_at,
                                    'p_interview_type' => $interview_type,
                                    'p_meeting_details' => $meeting_details,
                                    'p_meeting_link' => $meeting_link
                                ];

                                $rpc_result = $supabase_request(
                                    $rpc_url,
                                    'POST',
                                    $rpc_data
                                );


                                if (
                                    $rpc_result['success'] &&
                                    !empty($rpc_result['data']['success'])
                                ) {

                                    if (!empty($rpc_result['data']['already_exists'])) {

                                        $message = 'An active interview invitation already exists for this applicant.';
                                        $message_type = 'success';
                                    } else {

                                        // Database action succeeded.
                                        // Email failure does not undo the invitation.

                                        $email_body = interviewInvitationEmail(
                                            $worker_name,
                                            $job_title,
                                            $interview_at,
                                            $interview_type,
                                            $meeting_details,
                                            $interview_message,
                                            $meeting_link
                                        );

                                        $email_sent = $notify_worker(
                                            $worker_id,
                                            'Interview Invitation - WorkPulse',
                                            $email_body
                                        );

                                        if ($email_sent) {

                                            $message = 'Interview invitation successfully sent. The worker has also been notified by email.';
                                        } else {

                                            $message = 'Interview invitation successfully saved, but the email notification could not be sent.';
                                        }

                                        $message_type = 'success';
                                    }
                                } else {

                                    $message = is_array($rpc_result['data'])
                                        ? (
                                            $rpc_result['data']['message'] ??
                                            'Unable to send the interview invitation.'
                                        )
                                        : 'Unable to send the interview invitation.';

                                    $message_type = 'error';
                                }
                            } catch (Throwable $exception) {

                                error_log(
                                    'WorkPulse interview invitation error: ' .
                                        $exception->getMessage()
                                );

                                $message = 'Unable to generate or send the interview invitation. Please try again.';
                                $message_type = 'error';
                            }
                        }
                    }
                }


                // ==========================================
                // 5B. REJECT APPLICATION
                // ==========================================

                elseif (isset($_POST['reject_application'])) {

                    $allowed_rejection_statuses = [
                        'pending',
                        'shortlisted',
                        'interview',
                        'accepted'
                    ];

                    if (
                        !in_array(
                            $application_status,
                            $allowed_rejection_statuses,
                            true
                        )
                    ) {

                        $message = 'This application can no longer be rejected.';
                        $message_type = 'error';
                    } else {

                        // Check whether the worker accepted a formal offer.

                        $accepted_offer_url = SUPABASE_URL .
                            '/rest/v1/employment_offers?select=id' .
                            '&application_id=eq.' . rawurlencode($application_id) .
                            '&employer_id=eq.' . rawurlencode($employer_id) .
                            '&status=eq.accepted' .
                            '&limit=1';

                        $accepted_offer_result = $supabase_request(
                            $accepted_offer_url
                        );

                        if (!$accepted_offer_result['success']) {

                            $message = 'Unable to verify the employment offer status. Please try again.';
                            $message_type = 'error';
                        } elseif (!empty($accepted_offer_result['data'])) {

                            $message = 'This applicant has already accepted an employment offer and cannot be rejected.';
                            $message_type = 'error';
                        } else {

                            $reject_url = SUPABASE_URL .
                                '/rest/v1/applications?id=eq.' .
                                rawurlencode($application_id) .
                                '&status=in.(pending,shortlisted,interview,accepted)';

                            $reject_result = $supabase_request(
                                $reject_url,
                                'PATCH',
                                ['status' => 'rejected']
                            );

                            if (
                                $reject_result['success'] &&
                                !empty($reject_result['data'][0])
                            ) {

                                // The database trigger automatically cancels
                                // pending interview invitations when the
                                // application becomes rejected.
                                //
                                // Do not manually update invitation records here.

                                $email_body = applicationStatusEmail(
                                    $worker_name,
                                    $job_title,
                                    'rejected'
                                );

                                $email_sent = $notify_worker(
                                    $worker_id,
                                    'Application Status Update - WorkPulse',
                                    $email_body
                                );

                                if ($email_sent) {

                                    $message = 'Application rejected successfully. The worker has been notified by email.';
                                } else {

                                    $message = 'Application rejected successfully, but the email notification could not be sent.';
                                }

                                $message_type = 'success';
                            } else {

                                $message = 'Unable to reject the application. It may have already changed.';
                                $message_type = 'error';
                            }
                        }
                    }
                }


                // ==========================================
                // 5C. INVALID ACTION
                // ==========================================

                else {

                    $message = 'Invalid application action.';
                    $message_type = 'error';
                }
            }
        }
    }
}


// ==========================================
// 6. RETRIEVE EMPLOYER APPLICATIONS
// ==========================================

$applications_url = SUPABASE_URL .
    '/rest/v1/applications?select=id,worker_id,job_id,application_statement,status,applied_at,resume_path,source,invitation_id,jobs!inner(title,company_name,hourly_rate,employment_type,work_schedule)' .
    '&jobs.employer_id=eq.' . rawurlencode($employer_id) .
    '&order=applied_at.desc&limit=1000';

$applications_result = $supabase_request($applications_url);

$applications = [];
$profiles = [];
$all_invitations = [];
$interview_invitations = [];
$employment_details = [];

if (!$applications_result['success']) {

    $message = 'Unable to retrieve applications. Please refresh the page.';
    $message_type = 'error';
} elseif (is_array($applications_result['data'])) {

    $applications = $applications_result['data'];

    $worker_ids = array_unique(
        array_filter(array_column($applications, 'worker_id'))
    );

    $application_ids = array_unique(
        array_filter(array_column($applications, 'id'))
    );


    // ==========================================
    // 6A. RETRIEVE WORKER PROFILES
    // ==========================================

    if (!empty($worker_ids)) {

        $profile_url = SUPABASE_URL .
            '/rest/v1/profiles?select=id,full_name,skills,experience,education,portfolio_url,preferred_hourly_rate' .
            '&id=in.(' .
            implode(',', array_map('rawurlencode', $worker_ids)) .
            ')';

        $profile_result = $supabase_request($profile_url);

        if (
            $profile_result['success'] &&
            is_array($profile_result['data'])
        ) {

            $profiles = $profile_result['data'];
        } else {

            error_log('WorkPulse: Unable to retrieve applicant profiles.');
        }
    }


    // ==========================================
    // 6B. RETRIEVE INTERVIEW INVITATIONS
    // ==========================================

    if (!empty($application_ids)) {

        $invitation_url = SUPABASE_URL .
            '/rest/v1/invitations?select=id,application_id,status,created_at,message,interview_at,interview_type,meeting_details,meeting_link' .
            '&employer_id=eq.' . rawurlencode($employer_id) .
            '&application_id=in.(' .
            implode(',', array_map('rawurlencode', $application_ids)) .
            ')' .
            '&order=created_at.desc&limit=1000';

        $invitation_result = $supabase_request($invitation_url);

        if (
            $invitation_result['success'] &&
            is_array($invitation_result['data'])
        ) {

            $all_invitations = $invitation_result['data'];

            // Keep interview invitations only.
            // Direct job invitations are not interview history.

            $interview_invitations = array_values(
                array_filter(
                    $all_invitations,
                    function ($invitation) {

                        return !empty($invitation['interview_at']) ||
                            !empty($invitation['interview_type']) ||
                            !empty($invitation['meeting_details']) ||
                            !empty($invitation['meeting_link']);
                    }
                )
            );
        } else {

            error_log('WorkPulse: Unable to retrieve interview history.');
        }
    }


    // ==========================================
    // 6C. RETRIEVE EMPLOYMENT OFFERS
    // ==========================================

    $offers_url = SUPABASE_URL .
        '/rest/v1/employment_offers?select=id,application_id,invitation_id,status,hourly_rate,start_date,expires_at,worker_response_message,created_at' .
        '&employer_id=eq.' . rawurlencode($employer_id) .
        '&order=created_at.desc&limit=1000';

    $offers_result = $supabase_request($offers_url);

    if (
        $offers_result['success'] &&
        is_array($offers_result['data'])
    ) {

        $employment_details = $offers_result['data'];
    } else {

        error_log('WorkPulse: Unable to retrieve employment offers.');
    }
}


// ==========================================
// 7. CREATE LOOKUP ARRAYS
// ==========================================

$profile_map = [];

foreach ($profiles as $profile) {

    if (!empty($profile['id'])) {
        $profile_map[$profile['id']] = $profile;
    }
}


$invitation_map = [];

foreach ($interview_invitations as $invitation) {

    $matched_application_id = $invitation['application_id'] ?? null;

    if (!empty($matched_application_id)) {

        if (!isset($invitation_map[$matched_application_id])) {
            $invitation_map[$matched_application_id] = [];
        }

        $invitation_map[$matched_application_id][] = $invitation;
    }
}


// Map invitation IDs to application IDs.
// This supports older invitation-linked employment offers.

$invitation_application_map = [];

foreach ($all_invitations as $invitation) {

    if (
        !empty($invitation['id']) &&
        !empty($invitation['application_id'])
    ) {

        $invitation_application_map[$invitation['id']] =
            $invitation['application_id'];
    }
}


// ==========================================
// 8. CREATE LATEST OFFER LOOKUP
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

    // Offers are ordered newest first.
    // Keep only the latest offer for each application.

    if (
        !empty($matched_application_id) &&
        !isset($details_map[$matched_application_id])
    ) {

        $details_map[$matched_application_id] = $details;
    }
}


// ==========================================
// 9. APPLICATION STATISTICS
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
// 10. LOAD SHARED HEADER
// ==========================================

require_once '../../includes/header.php';

?>

<div class="wp-page employer-applications-page">

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
                    Applications from workers will appear here when they apply to your job postings or accept your direct invitations.
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

                    // Invitations are ordered newest first.
                    $latest_invitation =
                        $application_invitations[0] ?? null;

                    $details =
                        $details_map[$application['id']] ?? null;

                    $application_status = $application['status'] ?? 'pending';

                    $offer_status = $details['status'] ?? null;


                    // APPLICATION SOURCE

                    $application_source = $application['source'] ?? 'job_application';

                    $source_label = $application_source === 'direct_invitation'
                        ? 'Direct Invitation'
                        : 'Job Application';


                    // ==========================================
                    // INTERVIEW PERMISSION
                    // ==========================================

                    // A new interview can only be scheduled when:
                    // 1. The application is active.
                    // 2. There is no previous interview invitation,
                    //    OR the latest interview invitation was declined.
                    //
                    // Pending, accepted, and cancelled invitations
                    // must not allow another interview invitation.

                    $latest_invitation_status =
                        $latest_invitation['status'] ?? null;

                    $can_invite = (
                        in_array(
                            $application_status,
                            ['pending', 'shortlisted', 'interview'],
                            true
                        ) &&
                        (
                            $latest_invitation === null ||
                            $latest_invitation_status === 'declined'
                        )
                    );


                    // ==========================================
                    // REJECTION PERMISSION
                    // ==========================================

                    $can_reject = (
                        in_array(
                            $application_status,
                            ['pending', 'shortlisted', 'interview', 'accepted'],
                            true
                        ) &&
                        $offer_status !== 'accepted'
                    );


                    // ==========================================
                    // EMPLOYMENT OFFER PERMISSION
                    // ==========================================

                    // Application accepted = employer approved the candidate.
                    // Offer accepted = worker accepted the employment terms.

                    $can_prepare_offer = (
                        $application_status === 'accepted' &&
                        (
                            $details === null ||
                            in_array(
                                $offer_status,
                                ['declined', 'withdrawn'],
                                true
                            )
                        )
                    );

                    $can_revise_offer = (
                        $application_status === 'accepted' &&
                        $offer_status === 'revision_requested'
                    );


                    // ==========================================
                    // APPLICATION STATUS LABELS
                    // ==========================================

                    $status_labels = [
                        'pending' => 'Pending Review',
                        'shortlisted' => 'Shortlisted',
                        'interview' => 'Interview',
                        'accepted' => 'Approved for Offer',
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

                    $worker_initial = strtoupper(
                        substr(trim($worker_name), 0, 1)
                    );

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
                                        <?= htmlspecialchars($worker_name, ENT_QUOTES, 'UTF-8') ?>
                                    </h3>

                                    <span>
                                        <i class="bi bi-calendar3"></i>

                                        Applied
                                        <?= !empty($application['applied_at'])
                                            ? htmlspecialchars(
                                                date('M d, Y', strtotime($application['applied_at'])),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            )
                                            : 'date unavailable' ?>
                                    </span>

                                    <small class="employer-application-source">
                                        <?= htmlspecialchars($source_label, ENT_QUOTES, 'UTF-8') ?>
                                    </small>

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
                                        <?= isset($worker['preferred_hourly_rate']) &&
                                            $worker['preferred_hourly_rate'] !== null
                                            ? '$' . number_format((float)$worker['preferred_hourly_rate'], 2) . '/hour'
                                            : 'Not specified' ?>
                                    </p>

                                </div>


                                <div class="employer-applicant-links">

                                    <?php if (
                                        !empty($worker['portfolio_url']) &&
                                        filter_var($worker['portfolio_url'], FILTER_VALIDATE_URL) &&
                                        preg_match('/^https?:\/\//i', $worker['portfolio_url'])
                                    ): ?>

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
                                        name="csrf_token"
                                        value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

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

                                    <?= in_array($offer_status, ['declined', 'withdrawn'], true)
                                        ? 'Send New Employment Offer'
                                        : 'Prepare Employment Offer' ?>

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
                                            'accepted' => 'Interview Accepted',
                                            'declined' => 'Interview Declined',
                                            'cancelled' => 'Cancelled - Application Closed'
                                        ];

                                        $interview_labels = [
                                            'online' => 'Online Meeting'
                                        ];

                                        $invitation_status =
                                            $invitation['status'] ?? 'unknown';

                                        $meeting_link =
                                            $invitation['meeting_link'] ?? '';

                                        $valid_meeting_link =
                                            $is_valid_demo_meeting_link($meeting_link);

                                        ?>

                                        <div class="employer-history-item">

                                            <div class="employer-history-item-top">

                                                <strong>
                                                    <?= htmlspecialchars(
                                                        $invitation_labels[$invitation_status] ?? ucfirst($invitation_status),
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>
                                                </strong>

                                                <span>
                                                    <?= !empty($invitation['created_at'])
                                                        ? htmlspecialchars(
                                                            date('M d, Y', strtotime($invitation['created_at'])),
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        )
                                                        : 'Date unavailable' ?>
                                                </span>

                                            </div>


                                            <?php if ($invitation_status === 'cancelled'): ?>

                                                <p>
                                                    <i class="bi bi-info-circle"></i>
                                                    This interview invitation was cancelled because the related application was closed.
                                                </p>

                                            <?php endif; ?>


                                            <?php if (!empty($invitation['interview_at'])): ?>

                                                <p>
                                                    <i class="bi bi-calendar-event"></i>

                                                    <strong>Schedule:</strong>

                                                    <?= htmlspecialchars(
                                                        date('M d, Y - h:i A', strtotime($invitation['interview_at'])),
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>
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


                                            <?php if ($valid_meeting_link): ?>

                                                <p>
                                                    <i class="bi bi-camera-video"></i>

                                                    <strong>Meeting Link:</strong>

                                                    <a
                                                        href="<?= htmlspecialchars($meeting_link, ENT_QUOTES, 'UTF-8') ?>"
                                                        target="_blank"
                                                        rel="noopener noreferrer">
                                                        Open Demo Meeting Link
                                                    </a>

                                                    <small>(Demo link only; not a real meeting room.)</small>
                                                </p>

                                            <?php endif; ?>


                                            <?php if (!empty($invitation['meeting_details'])): ?>

                                                <p>
                                                    <i class="bi bi-info-circle"></i>

                                                    <strong>Additional Notes:</strong>

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
                                        Employment Offer
                                    </span>

                                    <i class="bi bi-chevron-down"></i>
                                </summary>


                                <div class="employer-expand-content">

                                    <div class="employer-offer-summary">

                                        <div>
                                            <span>Proposed Hourly Rate</span>

                                            <strong>
                                                $<?= number_format((float)$details['hourly_rate'], 2) ?>/hour
                                            </strong>
                                        </div>

                                        <div>
                                            <span>Start Date</span>

                                            <strong>
                                                <?= !empty($details['start_date'])
                                                    ? htmlspecialchars(
                                                        date('M d, Y', strtotime($details['start_date'])),
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    )
                                                    : 'Not specified' ?>
                                            </strong>
                                        </div>

                                    </div>


                                    <?php if ($offer_status === 'pending'): ?>

                                        <div class="employer-offer-notice notice-pending">
                                            <i class="bi bi-hourglass-split"></i>
                                            Waiting for the worker to respond to the employment offer.
                                        </div>


                                    <?php elseif ($offer_status === 'revision_requested'): ?>

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


                                        <?php if ($can_revise_offer): ?>

                                            <a
                                                href="send-offer.php?id=<?= urlencode($application['id']) ?>"
                                                class="wp-btn wp-btn-primary wp-btn-sm">

                                                <i class="bi bi-pencil"></i>
                                                Revise Employment Offer

                                            </a>

                                        <?php endif; ?>


                                    <?php elseif ($offer_status === 'accepted'): ?>

                                        <div class="employer-offer-notice notice-accepted">
                                            <i class="bi bi-check-circle"></i>
                                            Worker accepted the employment offer.
                                        </div>


                                    <?php elseif ($offer_status === 'declined'): ?>

                                        <div class="employer-offer-notice notice-declined">
                                            <i class="bi bi-x-circle"></i>
                                            Worker declined the employment offer.
                                        </div>


                                    <?php elseif ($offer_status === 'withdrawn'): ?>

                                        <div class="employer-offer-notice notice-declined">
                                            <i class="bi bi-x-circle"></i>
                                            This employment offer was withdrawn.
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
                                        Choose an interview schedule. WorkPulse will generate a demo meeting link automatically. It will not create a real meeting room.
                                    </p>


                                    <form method="POST" class="employer-interview-form">

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

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

                                            <input
                                                type="hidden"
                                                name="interview_type"
                                                value="online">

                                            <input
                                                type="text"
                                                value="Online Meeting"
                                                disabled>

                                        </div>


                                        <div class="employer-form-field">

                                            <label>Additional Meeting Notes (Optional)</label>

                                            <textarea
                                                name="meeting_details"
                                                rows="3"
                                                maxlength="4000"
                                                placeholder="Add instructions or anything the applicant should prepare."></textarea>

                                        </div>


                                        <div class="employer-form-field">

                                            <label>Personal Message</label>

                                            <textarea
                                                name="interview_message"
                                                rows="4"
                                                maxlength="2000"
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