<?php

// ==========================================
// WORKER INVITATIONS
// ==========================================

$required_role = 'worker';

require_once '../../includes/auth.php';

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
// FLASH MESSAGE
// ==========================================

$message = $_SESSION['invitation_message'] ?? '';
$message_type = $_SESSION['invitation_message_type'] ?? 'danger';

unset(
    $_SESSION['invitation_message'],
    $_SESSION['invitation_message_type']
);


// ==========================================
// HELPER: GET HTTP STATUS CODE
// ==========================================

$get_status_code = function ($response_headers) {

    if (!is_array($response_headers)) {
        return 0;
    }

    $status_code = 0;

    foreach ($response_headers as $header) {

        if (preg_match('/HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
            $status_code = (int) $matches[1];
        }
    }

    return $status_code;
};


// ==========================================
// HELPER: ESCAPE OUTPUT
// ==========================================

$escape = function ($value) {
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
};


// ==========================================
// ACCEPT OR DECLINE INVITATION
// ==========================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['respond_invitation'])
) {

    // Validate CSRF token.
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {

        $_SESSION['invitation_message'] =
            'Invalid request. Please try again.';

        $_SESSION['invitation_message_type'] = 'danger';

        header('Location: invitations.php');
        exit;
    }

    $invitation_id = trim($_POST['invitation_id'] ?? '');
    $decision = $_POST['respond_invitation'];


    // Validate invitation ID.
    if ($invitation_id === '') {

        $_SESSION['invitation_message'] = 'Invalid invitation.';
        $_SESSION['invitation_message_type'] = 'danger';

        header('Location: invitations.php');
        exit;
    }


    // Only accept valid decisions.
    if (!in_array($decision, ['accepted', 'declined'], true)) {

        $_SESSION['invitation_message'] =
            'Invalid invitation decision.';

        $_SESSION['invitation_message_type'] = 'danger';

        header('Location: invitations.php');
        exit;
    }


    // ======================================
    // CALL SUPABASE RPC
    // ======================================

    $rpc_url = SUPABASE_URL .
        '/rest/v1/rpc/respond_to_worker_invitation';

    $headers = [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    $payload = [
        'p_invitation_id' => $invitation_id,
        'p_decision' => $decision
    ];

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => json_encode($payload),
            'ignore_errors' => true,
            'timeout' => 30
        ]
    ];

    $context = stream_context_create($options);

    $response = @file_get_contents($rpc_url, false, $context);

    $status_code = $get_status_code($http_response_header ?? []);

    $result = json_decode($response ?: '[]', true);


    // ======================================
    // HANDLE RPC RESULT
    // ======================================

    if (
        $status_code >= 200 &&
        $status_code < 300 &&
        is_array($result) &&
        !empty($result['success'])
    ) {

        $invitation_type = $result['invitation_type'] ?? '';

        if ($invitation_type === 'job' && $decision === 'accepted') {

            $_SESSION['invitation_message'] =
                'Job invitation accepted! Your application has been created or linked to this opportunity. You are not officially hired yet.';
        } elseif ($invitation_type === 'job' && $decision === 'declined') {

            $_SESSION['invitation_message'] =
                'You declined the job invitation.';
        } elseif ($invitation_type === 'interview' && $decision === 'accepted') {

            $_SESSION['invitation_message'] =
                'Interview confirmed! Please review your interview schedule and meeting details. This does not mean you have accepted a job offer.';
        } elseif ($invitation_type === 'interview' && $decision === 'declined') {

            $_SESSION['invitation_message'] =
                'You declined the interview invitation. Your application remains available for the employer to review.';
        } else {

            $_SESSION['invitation_message'] =
                'Your invitation response was saved.';
        }

        $_SESSION['invitation_message_type'] = 'success';
    } else {

        $_SESSION['invitation_message'] =
            'Unable to process your response. The invitation may have already been answered, or the request may have failed. Please refresh the page and try again.';

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

$headers = [
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . $access_token,
    'Accept: application/json'
];

$options = [
    'http' => [
        'method' => 'GET',
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 30
    ]
];

$context = stream_context_create($options);

$response = @file_get_contents($url, false, $context);

$status_code = $get_status_code($http_response_header ?? []);

$invitations = json_decode($response ?: '[]', true);


// Check whether invitations loaded successfully.
if (
    $status_code < 200 ||
    $status_code >= 300 ||
    !is_array($invitations)
) {

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

    // Direct job invitations have no application_id.
    if (empty($invitation['application_id'])) {

        $job_invitations[] = $invitation;
    } else {

        // Interview invitations are connected to applications.
        $interview_invitations[] = $invitation;
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

    <!-- PAGE HEADING -->
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

                        <h3>
                            <?= $escape($group['empty_title']) ?>
                        </h3>

                        <p>
                            <?= $escape($group['empty_description']) ?>
                        </p>

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


                            // Invitation creation date.
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


                            // Invitation status.
                            $status_class = 'neutral';
                            $status_label = ucfirst($status);

                            if ($status === 'pending') {

                                $status_class = 'warning';
                            } elseif ($status === 'accepted') {

                                $status_class = 'success';

                                $status_label = $group_key === 'job'
                                    ? 'Accepted'
                                    : 'Interview Confirmed';
                            } elseif ($status === 'declined') {

                                $status_class = 'danger';
                            }


                            // Interview type.
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


                            // Interview schedule.
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


                            // Validate the demo meeting link before displaying it.
                            $meeting_link = trim(
                                $invitation['meeting_link'] ?? ''
                            );

                            $valid_meeting_link =
                                $meeting_link !== '' &&
                                preg_match(
                                    '/^https:\/\/meet\.google\.com\/[A-Za-z0-9-]+$/',
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

                                        <h4>
                                            <?= $escape($job_title) ?>
                                        </h4>

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

                                            <?php if (!empty($meeting_link) && preg_match('/^https:\/\/meet\.google\.com\/wpx-[a-f0-9]{4}-[a-f0-9]{4}$/i', $meeting_link)): ?>

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


                                    <!-- ACCEPTED INVITATION FEEDBACK -->
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


                                    <!-- DECLINED INVITATION FEEDBACK -->
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

                                <?php endif; ?>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </div>

        <?php endforeach; ?>

    </section>

</div>

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
                    item.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });

                panels.forEach(function(panel) {
                    panel.hidden = panel.id !== selectedTab + '-panel';
                });
            });
        });
    });
</script>

<?php require_once '../../includes/footer.php'; ?>