<?php

// ==========================================
// WORKER INVITATIONS
// ==========================================

$required_role = 'worker';

require_once '../../includes/auth.php';
require_once '../../includes/send-email.php';
require_once '../../includes/email-templates.php';

date_default_timezone_set('Asia/Manila');

$worker_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];


// ==========================================
// CSRF TOKEN
// ==========================================

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


// ==========================================
// HELPER FUNCTIONS
// ==========================================

$get_status_code = function ($headers) {

    $status_code = 0;

    if (!is_array($headers)) {
        return $status_code;
    }

    foreach ($headers as $header) {
        if (preg_match('/HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
            $status_code = (int) $matches[1];
        }
    }

    return $status_code;
};


$escape = function ($value) {
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
};


$to_boolean = function ($value) {

    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (bool) $value;
    }

    if (is_string($value)) {
        return in_array(
            strtolower(trim($value)),
            ['true', '1', 'yes', 'on'],
            true
        );
    }

    return false;
};


// ==========================================
// SUPABASE REQUEST HELPER
// ==========================================

$supabase_request = function (
    $url,
    $method = 'GET',
    $headers = [],
    $payload = null,
    $timeout = 20
) use ($get_status_code) {

    $request_headers = $headers;

    if ($payload !== null) {
        $request_headers[] = 'Content-Type: application/json';
    }

    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $request_headers),
            'ignore_errors' => true,
            'timeout' => $timeout
        ]
    ];

    if ($payload !== null) {

        $json_payload = json_encode($payload);

        if ($json_payload === false) {
            return [
                'success' => false,
                'status' => 0,
                'body' => false,
                'data' => null
            ];
        }

        $options['http']['content'] = $json_payload;
    }

    $context = stream_context_create($options);

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    // Capture response headers immediately.
    $response_headers = $http_response_header ?? [];

    $status = $get_status_code($response_headers);

    return [
        'success' => (
            $response !== false &&
            $status >= 200 &&
            $status < 300
        ),
        'status' => $status,
        'body' => $response,
        'data' => $response !== false
            ? json_decode($response, true)
            : null
    ];
};


// ==========================================
// SUPABASE HEADERS
// ==========================================

$worker_headers = [
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . $access_token,
    'Accept: application/json'
];


$service_headers = [];

if (
    defined('SUPABASE_SERVICE_ROLE_KEY') &&
    !empty(SUPABASE_SERVICE_ROLE_KEY)
) {

    $service_headers = [
        'apikey: ' . SUPABASE_SERVICE_ROLE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
        'Accept: application/json'
    ];
}


// ==========================================
// EMAIL NOTIFICATION HELPER
// ==========================================

$send_employer_notification = function (
    $invitation_id,
    $invitation_before,
    $decision,
    $invitation_type
) use (
    $supabase_request,
    $service_headers,
    $to_boolean
) {

    try {

        if (empty($service_headers)) {
            throw new Exception(
                'Supabase service role key is not configured.'
            );
        }

        if (!is_array($invitation_before)) {
            throw new Exception(
                'Invitation details were not available.'
            );
        }

        $employer_id = $invitation_before['employer_id'] ?? '';

        if (empty($employer_id)) {
            throw new Exception('Employer ID is missing.');
        }


        // ======================================
        // GET EMPLOYER EMAIL
        // ======================================

        $employer_url = SUPABASE_URL .
            '/auth/v1/admin/users/' .
            rawurlencode($employer_id);

        $employer_result = $supabase_request(
            $employer_url,
            'GET',
            $service_headers,
            null,
            20
        );

        if (!$employer_result['success']) {

            throw new Exception(
                'Unable to retrieve employer email. HTTP ' .
                    $employer_result['status'] .
                    '. Response: ' .
                    substr((string) $employer_result['body'], 0, 1000)
            );
        }

        $employer_email = $employer_result['data']['email'] ?? '';

        if (
            !is_string($employer_email) ||
            !filter_var($employer_email, FILTER_VALIDATE_EMAIL)
        ) {
            throw new Exception(
                'Employer email address is missing or invalid.'
            );
        }


        // ======================================
        // GET EMPLOYER NAME
        // ======================================

        $employer_name =
            $invitation_before['profiles']['full_name']
            ?? 'Employer';


        // ======================================
        // GET JOB TITLE
        // ======================================

        $job_title =
            $invitation_before['jobs']['title']
            ?? 'Job Opportunity';


        // ======================================
        // GET WORKER NAME
        // ======================================

        $worker_name =
            $_SESSION['user']['user_metadata']['full_name']
            ?? $_SESSION['user']['full_name']
            ?? 'Worker';


        // ======================================
        // GET INTERVIEW DATE
        // ======================================

        $interview_at =
            $invitation_before['interview_at'] ?? null;


        // ======================================
        // GENERATE EMAIL TEMPLATE
        // ======================================

        if (!function_exists('invitationResponseEmail')) {
            throw new Exception(
                'invitationResponseEmail() template is unavailable.'
            );
        }

        $email_body = invitationResponseEmail(
            $employer_name,
            $worker_name,
            $job_title,
            $invitation_type,
            $decision,
            $interview_at
        );


        // ======================================
        // SEND EMAIL
        // ======================================

        if (!isEmailConfigured()) {
            throw new Exception(
                'SMTP is not configured. Check your email configuration.'
            );
        }

        $email_subject = (
            $decision === 'accepted'
            ? 'Invitation Accepted - WorkPulse'
            : 'Invitation Declined - WorkPulse'
        );

        $email_sent = sendEmail(
            $employer_email,
            $email_subject,
            $email_body
        );

        if (!$email_sent) {

            $email_error = function_exists('getEmailError')
                ? getEmailError()
                : 'Unknown SMTP error.';

            throw new Exception(
                'SMTP failed to send the email: ' . $email_error
            );
        }

        error_log(
            'WorkPulse: Invitation response email sent successfully. ' .
                'Invitation ID: ' . $invitation_id .
                ' | Employer: ' . $employer_email
        );

        return true;
    } catch (Throwable $exception) {

        error_log(
            'WorkPulse: Employer invitation notification failed. ' .
                'Invitation ID: ' . $invitation_id .
                ' | Error: ' . $exception->getMessage()
        );

        return false;
    }
};


// ==========================================
// FLASH MESSAGE
// ==========================================

$message = $_SESSION['invitation_message'] ?? '';
$message_type = $_SESSION['invitation_message_type'] ?? 'danger';

unset(
    $_SESSION['invitation_message'],
    $_SESSION['invitation_message_type']
);


// ==========================================
// ACCEPT OR DECLINE INVITATION
// ==========================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['respond_invitation'])
) {

    // ======================================
    // 1. VALIDATE CSRF
    // ======================================

    if (
        empty($_POST['csrf_token']) ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $_POST['csrf_token']
        )
    ) {

        $_SESSION['invitation_message'] =
            'Invalid request. Please refresh the page and try again.';

        $_SESSION['invitation_message_type'] = 'danger';

        header('Location: invitations.php');
        exit;
    }


    // ======================================
    // 2. VALIDATE INPUT
    // ======================================

    $invitation_id = trim($_POST['invitation_id'] ?? '');
    $decision = $_POST['respond_invitation'];

    if (
        !preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $invitation_id
        )
    ) {

        $_SESSION['invitation_message'] = 'Invalid invitation.';
        $_SESSION['invitation_message_type'] = 'danger';

        header('Location: invitations.php');
        exit;
    }

    if (!in_array($decision, ['accepted', 'declined'], true)) {

        $_SESSION['invitation_message'] =
            'Invalid invitation decision.';

        $_SESSION['invitation_message_type'] = 'danger';

        header('Location: invitations.php');
        exit;
    }


    // ======================================
    // 3. GET INVITATION DETAILS BEFORE RPC
    // ======================================

    $invitation_before = null;

    if (empty($service_headers)) {

        error_log(
            'WorkPulse: Cannot retrieve invitation notification details. ' .
                'SUPABASE_SERVICE_ROLE_KEY is not configured.'
        );
    } else {

        $invitation_lookup_url = SUPABASE_URL .
            '/rest/v1/invitations?' .
            'select=id,employer_id,worker_id,application_id,status,' .
            'interview_at,interview_type,meeting_details,meeting_link,' .
            'jobs(title),' .
            'profiles!invitations_employer_id_fkey(full_name)' .
            '&id=eq.' . rawurlencode($invitation_id) .
            '&worker_id=eq.' . rawurlencode($worker_id) .
            '&limit=1';

        $lookup = $supabase_request(
            $invitation_lookup_url,
            'GET',
            $service_headers
        );

        if (
            $lookup['success'] &&
            is_array($lookup['data']) &&
            !empty($lookup['data'][0])
        ) {

            $invitation_before = $lookup['data'][0];
        } else {

            error_log(
                'WorkPulse: Invitation lookup failed. ' .
                    'Invitation ID: ' . $invitation_id .
                    ' | HTTP Status: ' . $lookup['status'] .
                    ' | Response: ' .
                    substr((string) $lookup['body'], 0, 1000)
            );
        }
    }


    // ======================================
    // 4. CALL SUPABASE RPC
    // ======================================

    $rpc_url = SUPABASE_URL .
        '/rest/v1/rpc/respond_to_worker_invitation';

    $rpc = $supabase_request(
        $rpc_url,
        'POST',
        $worker_headers,
        [
            'p_invitation_id' => $invitation_id,
            'p_decision' => $decision
        ],
        30
    );

    $result = $rpc['data'];


    // ======================================
    // 5. HANDLE RPC RESPONSE
    // ======================================

    // Support both JSON object and single-row array responses.

    $rpc_data = $result;

    if (
        is_array($result) &&
        isset($result[0]) &&
        is_array($result[0])
    ) {
        $rpc_data = $result[0];
    }


    $rpc_success = $rpc['success'];

    if (
        is_array($rpc_data) &&
        array_key_exists('success', $rpc_data)
    ) {

        $rpc_success = (
            $rpc_success &&
            $to_boolean($rpc_data['success'])
        );
    }


    if ($rpc_success) {

        $invitation_type =
            $rpc_data['invitation_type'] ?? 'job';

        $response_changed = (
            is_array($rpc_data) &&
            array_key_exists('response_changed', $rpc_data)
        )
            ? $to_boolean($rpc_data['response_changed'])
            : false;


        // ==================================
        // 6. SEND EMPLOYER EMAIL
        // ==================================

        $email_notification_failed = false;

        if ($response_changed) {

            $email_sent = $send_employer_notification(
                $invitation_id,
                $invitation_before,
                $decision,
                $invitation_type
            );

            if (!$email_sent) {
                $email_notification_failed = true;
            }
        } else {

            // No change means this request should not send
            // another email notification.

            error_log(
                'WorkPulse: Invitation response was not changed. ' .
                    'Email notification skipped. ' .
                    'Invitation ID: ' . $invitation_id
            );
        }


        // ==================================
        // 7. SUCCESS MESSAGE
        // ==================================

        if (
            $invitation_type === 'job' &&
            $decision === 'accepted'
        ) {

            $success_message =
                'Job invitation accepted! Your application has been created or linked to this opportunity. You are not officially hired yet.';
        } elseif (
            $invitation_type === 'job' &&
            $decision === 'declined'
        ) {

            $success_message =
                'You declined the job invitation.';
        } elseif (
            $invitation_type === 'interview' &&
            $decision === 'accepted'
        ) {

            $success_message =
                'Interview confirmed! Please review your interview schedule and meeting details. This does not mean you have accepted a job offer.';
        } elseif (
            $invitation_type === 'interview' &&
            $decision === 'declined'
        ) {

            $success_message =
                'You declined the interview invitation. Your application remains available for the employer to review.';
        } else {

            $success_message =
                'Your invitation response was saved.';
        }


        if ($email_notification_failed) {

            $_SESSION['invitation_message'] =
                $success_message .
                ' However, the email notification could not be sent to the employer. Your response was still saved.';

            $_SESSION['invitation_message_type'] = 'warning';
        } else {

            $_SESSION['invitation_message'] = $success_message;
            $_SESSION['invitation_message_type'] = 'success';
        }
    } else {

        // ==================================
        // 8. RPC ERROR
        // ==================================

        $error_message = 'Unknown Supabase error.';

        if (is_array($rpc_data)) {

            $error_message =
                $rpc_data['message']
                ?? $rpc_data['error']
                ?? $rpc_data['details']
                ?? $error_message;
        }

        error_log(
            'WorkPulse: Invitation RPC failed. ' .
                'Invitation ID: ' . $invitation_id .
                ' | HTTP Status: ' . $rpc['status'] .
                ' | Response: ' .
                substr((string) $rpc['body'], 0, 1500)
        );


        if (
            stripos($error_message, 'cancelled') !== false ||
            stripos($error_message, 'canceled') !== false ||
            stripos($error_message, 'no longer active') !== false ||
            stripos($error_message, 'no longer in the recruitment process') !== false
        ) {

            $_SESSION['invitation_message'] =
                'This invitation is no longer available because the related application has been closed.';
        } elseif (
            stripos($error_message, 'already been responded') !== false
        ) {

            $_SESSION['invitation_message'] =
                'You have already responded to this invitation. Please refresh the page.';
        } elseif (
            $rpc['status'] === 401 ||
            $rpc['status'] === 403
        ) {

            $_SESSION['invitation_message'] =
                'Your session or account does not have permission to respond. Please log in again.';
        } else {

            $_SESSION['invitation_message'] =
                'Unable to process your response. Please try again.';
        }

        $_SESSION['invitation_message_type'] = 'danger';
    }


    header('Location: invitations.php');
    exit;
}


// ==========================================
// GET WORKER INVITATIONS
// ==========================================

$url = SUPABASE_URL .
    '/rest/v1/invitations?' .
    'select=id,job_id,employer_id,application_id,message,status,created_at,' .
    'interview_at,interview_type,meeting_details,meeting_link,' .
    'jobs(id,title,company_name,description,hourly_rate,employment_type,work_schedule),' .
    'profiles!invitations_employer_id_fkey(id,full_name,company_name,company_description)' .
    '&worker_id=eq.' . rawurlencode($worker_id) .
    '&order=created_at.desc' .
    '&limit=200';

$invitations_response = $supabase_request(
    $url,
    'GET',
    $worker_headers,
    null,
    30
);

$status_code = $invitations_response['status'];
$invitations = $invitations_response['data'];


// ==========================================
// CHECK INVITATION RESPONSE
// ==========================================

if (
    !$invitations_response['success'] ||
    !is_array($invitations)
) {

    error_log(
        'WorkPulse: Unable to load worker invitations. ' .
            'HTTP Status: ' . $status_code .
            ' | Response: ' .
            substr((string) $invitations_response['body'], 0, 1000)
    );

    $invitations = [];

    $message = 'Unable to load your invitations. Please refresh the page.';
    $message_type = 'danger';
}


// ==========================================
// SEPARATE JOB AND INTERVIEW INVITATIONS
// ==========================================

$job_invitations = [];
$interview_invitations = [];

$pending_count = 0;
$accepted_count = 0;

foreach ($invitations as $invitation) {

    // Use interview-specific fields instead of application_id.
    // A job invitation may acquire an application_id after acceptance.

    $is_interview = (
        !empty($invitation['interview_at']) ||
        !empty($invitation['interview_type']) ||
        !empty($invitation['meeting_details']) ||
        !empty($invitation['meeting_link'])
    );

    if ($is_interview) {
        $interview_invitations[] = $invitation;
    } else {
        $job_invitations[] = $invitation;
    }

    if (($invitation['status'] ?? '') === 'pending') {
        $pending_count++;
    }

    if (($invitation['status'] ?? '') === 'accepted') {
        $accepted_count++;
    }
}


// ==========================================
// INVITATION GROUPS
// ==========================================

$invitation_groups = [

    'job' => [
        'title' => 'Job Invitations',
        'description' => 'Direct job opportunities sent to you by employers.',
        'empty_title' => 'No Job Invitations Yet',
        'empty_description' => 'When employers invite you to their job opportunities, they will appear here.',
        'invitations' => $job_invitations
    ],

    'interview' => [
        'title' => 'Interview Invitations',
        'description' => 'Interview requests connected to your job applications.',
        'empty_title' => 'No Interview Invitations Yet',
        'empty_description' => 'Interview requests from employers will appear here.',
        'invitations' => $interview_invitations
    ]
];


// ==========================================
// PAGE HEADER
// ==========================================

require_once '../../includes/header.php';

?>

<div class="wp-invitations-page">

    <div class="wp-dashboard-welcome">
        <div>
            <span class="wp-dashboard-eyebrow">RECRUITMENT</span>
            <h1 class="wp-page-title">My Invitations</h1>
            <p class="wp-page-description">
                Review job opportunities and interview requests from employers.
            </p>
        </div>
    </div>


    <!-- FLASH MESSAGE -->

    <?php if (!empty($message)): ?>

        <div class="wp-alert wp-alert-<?= $escape($message_type) ?>">
            <i class="bi bi-info-circle me-2"></i>
            <?= $escape($message) ?>
        </div>

    <?php endif; ?>


    <!-- INVITATION STATISTICS -->

    <div class="wp-dashboard-stats">

        <div class="wp-stat-card">
            <div class="wp-stat-top">
                Total Invitations
                <span class="wp-stat-icon rose">
                    <i class="bi bi-envelope"></i>
                </span>
            </div>
            <h2><?= count($invitations) ?></h2>
            <small>All invitations received</small>
        </div>

        <div class="wp-stat-card">
            <div class="wp-stat-top">
                Pending Invitations
                <span class="wp-stat-icon yellow">
                    <i class="bi bi-clock-history"></i>
                </span>
            </div>
            <h2><?= $pending_count ?></h2>
            <small>Waiting for your response</small>
        </div>

        <div class="wp-stat-card">
            <div class="wp-stat-top">
                Accepted Invitations
                <span class="wp-stat-icon green">
                    <i class="bi bi-check-circle"></i>
                </span>
            </div>
            <h2><?= $accepted_count ?></h2>
            <small>Invitations you've accepted</small>
        </div>

    </div>


    <!-- RECEIVED INVITATIONS -->

    <section class="wp-panel">

        <div class="wp-panel-header">
            <div>
                <h3 class="wp-panel-title">Received Invitations</h3>
                <p class="wp-panel-description">
                    Manage your job invitations and interview requests in one place.
                </p>
            </div>
        </div>


        <!-- INVITATION TABS -->

        <div class="wp-invitation-tabs" role="tablist">

            <button
                type="button"
                class="wp-btn wp-btn-primary wp-invitation-tab active"
                id="job-tab"
                role="tab"
                aria-selected="true"
                aria-controls="job-panel"
                data-tab="job">

                <i class="bi bi-briefcase"></i>
                Job Invitations
                (<?= count($job_invitations) ?>)

            </button>

            <button
                type="button"
                class="wp-btn wp-btn-outline wp-invitation-tab"
                id="interview-tab"
                role="tab"
                aria-selected="false"
                aria-controls="interview-panel"
                data-tab="interview">

                <i class="bi bi-calendar-event"></i>
                Interview Invitations
                (<?= count($interview_invitations) ?>)

            </button>

        </div>


        <!-- INVITATION TAB CONTENT -->

        <?php foreach ($invitation_groups as $group_key => $group): ?>

            <div
                class="wp-invitation-tab-panel"
                id="<?= $group_key ?>-panel"
                role="tabpanel"
                aria-labelledby="<?= $group_key ?>-tab"
                <?= $group_key === 'interview' ? 'hidden' : '' ?>>

                <?php if (empty($group['invitations'])): ?>

                    <div class="wp-empty-state">

                        <i class="bi <?= $group_key === 'job'
                                            ? 'bi-envelope-open'
                                            : 'bi-calendar-x' ?>"></i>

                        <h3><?= $escape($group['empty_title']) ?></h3>

                        <p><?= $escape($group['empty_description']) ?></p>

                        <?php if ($group_key === 'job'): ?>

                            <a href="jobs.php" class="wp-btn wp-btn-primary">
                                <i class="bi bi-search"></i>
                                Browse Jobs
                            </a>

                        <?php endif; ?>

                    </div>

                <?php else: ?>

                    <div class="wp-invitation-list">

                        <?php foreach ($group['invitations'] as $invitation): ?>

                            <?php

                            $job = $invitation['jobs'] ?? null;
                            $employer = $invitation['profiles'] ?? null;

                            $status = $invitation['status'] ?? 'unknown';

                            $employer_name = $employer['full_name']
                                ?? 'Unknown employer';

                            $company_name = $job['company_name']
                                ?? $employer['company_name']
                                ?? 'Not specified';

                            $job_title = $job['title']
                                ?? 'Job no longer available';


                            // INVITATION DATE

                            $created_at = 'Unknown date';

                            if (!empty($invitation['created_at'])) {

                                $timestamp = strtotime($invitation['created_at']);

                                if ($timestamp !== false) {
                                    $created_at = date(
                                        'M d, Y h:i A',
                                        $timestamp
                                    );
                                }
                            }


                            // STATUS

                            $status_class = 'neutral';
                            $status_label = ucfirst($status);

                            if ($status === 'pending') {

                                $status_class = 'warning';
                                $status_label = 'Pending';
                            } elseif ($status === 'accepted') {

                                $status_class = 'success';

                                $status_label = $group_key === 'job'
                                    ? 'Accepted'
                                    : 'Interview Confirmed';
                            } elseif ($status === 'declined') {

                                $status_class = 'danger';
                                $status_label = 'Declined';
                            } elseif ($status === 'cancelled') {

                                $status_class = 'neutral';
                                $status_label = 'Cancelled';
                            }


                            // INTERVIEW TYPE

                            $interview_type = $invitation['interview_type'] ?? '';

                            $interview_type_labels = [
                                'online' => 'Online Interview',
                                'phone' => 'Phone Interview',
                                'in_person' => 'In-Person Interview',
                                'other' => 'Other'
                            ];

                            $interview_type_label =
                                $interview_type_labels[$interview_type]
                                ?? 'Not specified';


                            // INTERVIEW SCHEDULE

                            $interview_date = 'Not scheduled';

                            if (!empty($invitation['interview_at'])) {

                                $timestamp = strtotime($invitation['interview_at']);

                                if ($timestamp !== false) {
                                    $interview_date = date(
                                        'M d, Y h:i A',
                                        $timestamp
                                    );
                                }
                            }


                            // MEETING LINK

                            $meeting_link = trim(
                                $invitation['meeting_link'] ?? ''
                            );

                            $valid_meeting_link =
                                $meeting_link !== '' &&
                                preg_match(
                                    '/^https:\/\/meet\.google\.com\/wpx-[a-f0-9]{4}-[a-f0-9]{4}$/i',
                                    $meeting_link
                                );

                            ?>


                            <article class="wp-invitation-card">


                                <!-- INVITATION HEADING -->

                                <div class="wp-invitation-heading">

                                    <div class="wp-invitation-icon">
                                        <i class="bi <?= $group_key === 'job'
                                                            ? 'bi-briefcase'
                                                            : 'bi-calendar-event' ?>"></i>
                                    </div>

                                    <div class="wp-invitation-title">

                                        <h4><?= $escape($job_title) ?></h4>

                                        <p>
                                            <i class="bi bi-building"></i>
                                            <?= $escape($company_name) ?>
                                        </p>

                                    </div>

                                    <span class="wp-invitation-status <?= $escape($status_class) ?>">
                                        <?= $escape($status_label) ?>
                                    </span>

                                </div>


                                <!-- INVITATION DETAILS -->

                                <div class="wp-invitation-details">

                                    <span>
                                        <i class="bi bi-person"></i>
                                        <?= $escape($employer_name) ?>
                                    </span>

                                    <span>
                                        <i class="bi bi-calendar3"></i>
                                        <?= $escape($created_at) ?>
                                    </span>

                                    <?php if (isset($job['hourly_rate'])): ?>

                                        <span>
                                            <i class="bi bi-cash"></i>
                                            $<?= number_format(
                                                    (float) $job['hourly_rate'],
                                                    2
                                                ) ?>/hr
                                        </span>

                                    <?php endif; ?>

                                    <?php if (!empty($job['employment_type'])): ?>

                                        <span>
                                            <i class="bi bi-clock"></i>
                                            <?= $escape(
                                                ucwords(
                                                    str_replace(
                                                        '_',
                                                        ' ',
                                                        $job['employment_type']
                                                    )
                                                )
                                            ) ?>
                                        </span>

                                    <?php endif; ?>

                                </div>


                                <!-- INTERVIEW DETAILS -->

                                <?php if ($group_key === 'interview'): ?>

                                    <div class="wp-invitation-section">

                                        <h5>Interview Details</h5>

                                        <p>
                                            <strong>Schedule:</strong>
                                            <?= $escape($interview_date) ?>
                                        </p>

                                        <p>
                                            <strong>Interview Type:</strong>
                                            <?= $escape($interview_type_label) ?>
                                        </p>

                                        <?php if (!empty($invitation['meeting_details'])): ?>

                                            <p>
                                                <strong>Additional Notes:</strong>
                                                <?= nl2br(
                                                    $escape($invitation['meeting_details'])
                                                ) ?>
                                            </p>

                                        <?php endif; ?>

                                        <?php if ($valid_meeting_link): ?>

                                            <div class="wp-invitation-meeting-link">

                                                <p>
                                                    <strong>
                                                        <i class="bi bi-camera-video"></i>
                                                        Online Meeting
                                                    </strong>
                                                </p>

                                                <a
                                                    href="<?= $escape($meeting_link) ?>"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="wp-meeting-url">

                                                    <?= $escape($meeting_link) ?>

                                                    <i class="bi bi-box-arrow-up-right"></i>

                                                </a>

                                                <small>
                                                    Demo link only. This does not open a real meeting room.
                                                </small>

                                            </div>

                                        <?php endif; ?>

                                    </div>

                                <?php endif; ?>


                                <!-- JOB DESCRIPTION -->

                                <?php if (!empty($job['description'])): ?>

                                    <div class="wp-invitation-section">

                                        <h5>Job Description</h5>

                                        <p>
                                            <?= nl2br(
                                                $escape($job['description'])
                                            ) ?>
                                        </p>

                                    </div>

                                <?php endif; ?>


                                <!-- WORK SCHEDULE -->

                                <?php if (!empty($job['work_schedule'])): ?>

                                    <div class="wp-invitation-section">

                                        <h5>Work Schedule</h5>

                                        <p>
                                            <?= $escape($job['work_schedule']) ?>
                                        </p>

                                    </div>

                                <?php endif; ?>


                                <!-- EMPLOYER MESSAGE -->

                                <div class="wp-invitation-message">

                                    <div class="wp-invitation-message-heading">
                                        <i class="bi bi-chat-left-text"></i>
                                        Employer's Message
                                    </div>

                                    <p>
                                        <?= !empty($invitation['message'])
                                            ? nl2br($escape($invitation['message']))
                                            : 'No message provided.' ?>
                                    </p>

                                </div>


                                <!-- PENDING INVITATION ACTIONS -->

                                <?php if ($status === 'pending'): ?>

                                    <div class="wp-invitation-actions">

                                        <form method="POST" action="invitations.php">

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= $escape($_SESSION['csrf_token']) ?>">

                                            <input
                                                type="hidden"
                                                name="invitation_id"
                                                value="<?= $escape($invitation['id']) ?>">

                                            <button
                                                type="submit"
                                                name="respond_invitation"
                                                value="declined"
                                                class="wp-btn wp-btn-outline"
                                                onclick="return confirm('Are you sure you want to decline this <?= $group_key === 'job'
                                                                                                                    ? 'job invitation'
                                                                                                                    : 'interview invitation' ?>?');">

                                                <i class="bi bi-x-lg"></i>
                                                Decline

                                            </button>

                                            <button
                                                type="submit"
                                                name="respond_invitation"
                                                value="accepted"
                                                class="wp-btn wp-btn-success"
                                                onclick="return confirm('Are you sure you want to <?= $group_key === 'job'
                                                                                                        ? 'accept this job invitation'
                                                                                                        : 'confirm this interview' ?>?');">

                                                <i class="bi bi-check-lg"></i>

                                                <?= $group_key === 'job'
                                                    ? 'Accept Invitation'
                                                    : 'Confirm Interview' ?>

                                            </button>

                                        </form>

                                    </div>


                                    <!-- ACCEPTED INVITATION -->

                                <?php elseif ($status === 'accepted'): ?>

                                    <div class="wp-invitation-feedback success">

                                        <i class="bi bi-check-circle-fill"></i>

                                        <div>

                                            <strong>
                                                <?= $group_key === 'job'
                                                    ? 'Job Invitation Accepted'
                                                    : 'Interview Confirmed' ?>
                                            </strong>

                                            <p>
                                                <?= $group_key === 'job'
                                                    ? 'Your application has been created or linked to this job. The employer can now review your application. You are not officially hired yet.'
                                                    : 'You confirmed your attendance. This does not mean you have accepted a job offer.' ?>
                                            </p>

                                        </div>

                                    </div>


                                    <!-- DECLINED INVITATION -->

                                <?php elseif ($status === 'declined'): ?>

                                    <div class="wp-invitation-feedback neutral">

                                        <i class="bi bi-info-circle-fill"></i>

                                        <div>

                                            <strong>
                                                <?= $group_key === 'job'
                                                    ? 'Job Invitation Declined'
                                                    : 'Interview Invitation Declined' ?>
                                            </strong>

                                            <p>
                                                <?= $group_key === 'job'
                                                    ? 'You declined this job opportunity.'
                                                    : 'You declined the interview request. Your application status is not automatically rejected.' ?>
                                            </p>

                                        </div>

                                    </div>


                                    <!-- CANCELLED INVITATION -->

                                <?php elseif ($status === 'cancelled'): ?>

                                    <div class="wp-invitation-feedback neutral">

                                        <i class="bi bi-x-circle-fill"></i>

                                        <div>

                                            <strong>Invitation Cancelled</strong>

                                            <p>
                                                This invitation is no longer available because the related application has been closed by the employer. You can no longer respond to it.
                                            </p>

                                        </div>

                                    </div>

                                <?php endif; ?>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </div>

        <?php endforeach; ?>

    </section>

</div>


<!-- ==========================================
     INVITATION TAB SCRIPT
========================================== -->

<script>
    document.addEventListener('DOMContentLoaded', function() {

        const tabs = document.querySelectorAll('[data-tab]');
        const panels = document.querySelectorAll('.wp-invitation-tab-panel');

        tabs.forEach(function(tab) {

            tab.addEventListener('click', function() {

                const selectedTab = tab.dataset.tab;

                tabs.forEach(function(item) {

                    const isActive = item.dataset.tab === selectedTab;

                    item.classList.toggle('active', isActive);
                    item.classList.toggle('wp-btn-primary', isActive);
                    item.classList.toggle('wp-btn-outline', !isActive);

                    item.setAttribute(
                        'aria-selected',
                        isActive ? 'true' : 'false'
                    );

                });

                panels.forEach(function(panel) {
                    panel.hidden = panel.id !== selectedTab + '-panel';
                });

            });

        });

    });
</script>

<?php require_once '../../includes/footer.php'; ?>