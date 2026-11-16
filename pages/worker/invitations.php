<?php

$required_role = 'worker';
require_once '../../includes/auth.php';

date_default_timezone_set('Asia/Manila');

$worker_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

// Create CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get flash message.
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

    foreach ($response_headers as $header) {

        if (preg_match('/HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
            return (int) $matches[1];
        }
    }

    return 0;
};


// ==========================================
// ACCEPT OR DECLINE INVITATION
// ==========================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['respond_invitation'])
) {

    // Check CSRF token.
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $_SESSION['invitation_message'] = 'Invalid request. Please try again.';
        $_SESSION['invitation_message_type'] = 'danger';

        header('Location: invitations.php');
        exit;
    }

    $invitation_id = trim($_POST['invitation_id'] ?? '');
    $decision = $_POST['respond_invitation'];

    // Validate invitation ID.
    if (empty($invitation_id)) {

        $_SESSION['invitation_message'] = 'Invalid invitation.';
        $_SESSION['invitation_message_type'] = 'danger';

        header('Location: invitations.php');
        exit;
    }

    // Only allow accepted or declined.
    if (!in_array($decision, ['accepted', 'declined'], true)) {

        $_SESSION['invitation_message'] = 'Invalid invitation decision.';
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
                'Interview confirmed! Please review the interview schedule and meeting details. This does not mean you have accepted a job offer.';
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
    'interview_at,interview_type,meeting_details,' .
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


// Check if invitations loaded successfully.
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

    // application_id is null for direct job invitations.
    if (empty($invitation['application_id'])) {

        $job_invitations[] = $invitation;
    } else {

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
// GROUPS FOR THE TWO TABS
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

require_once '../../includes/header.php';

?>

<div class="wp-invitations-page">

    <!-- Page heading -->
    <div class="wp-dashboard-welcome">

        <div>

            <span class="wp-dashboard-eyebrow">RECRUITMENT</span>

            <h1 class="wp-page-title">My Invitations</h1>

            <p class="wp-page-description">
                Review job opportunities and interview requests from employers.
            </p>

        </div>

    </div>


    <!-- Flash message -->
    <?php if (!empty($message)): ?>

        <div class="wp-alert wp-alert-<?= htmlspecialchars($message_type, ENT_QUOTES, 'UTF-8') ?>">

            <i class="bi bi-info-circle me-2"></i>

            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>

        </div>

    <?php endif; ?>


    <!-- Invitation statistics -->
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


    <!-- Invitation list -->
    <section class="wp-panel">

        <div class="wp-panel-header">

            <div>

                <h3 class="wp-panel-title">Received Invitations</h3>

                <p class="wp-panel-description">
                    Manage your job invitations and interview requests in one place.
                </p>

            </div>

        </div>


        <!-- Invitation tabs -->
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


        <!-- Invitation tab contents -->
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
                            <?= htmlspecialchars($group['empty_title'], ENT_QUOTES, 'UTF-8') ?>
                        </h3>

                        <p>
                            <?= htmlspecialchars($group['empty_description'], ENT_QUOTES, 'UTF-8') ?>
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


                            // Format invitation date.
                            $created_at = 'Unknown date';

                            if (!empty($invitation['created_at'])) {

                                $timestamp = strtotime($invitation['created_at']);

                                if ($timestamp !== false) {
                                    $created_at = date('M d, Y h:i A', $timestamp);
                                }
                            }


                            // Format invitation status.
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


                            // Format interview type.
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


                            // Format interview schedule.
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

                            ?>

                            <article class="wp-invitation-card">

                                <!-- Invitation heading -->
                                <div class="wp-invitation-heading">

                                    <div class="wp-invitation-icon">

                                        <i class="bi <?= $group_key === 'job'
                                                            ? 'bi-briefcase'
                                                            : 'bi-calendar-event' ?>"></i>

                                    </div>


                                    <div class="wp-invitation-title">

                                        <h4>
                                            <?= htmlspecialchars($job_title, ENT_QUOTES, 'UTF-8') ?>
                                        </h4>

                                        <p>

                                            <i class="bi bi-building"></i>

                                            <?= htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8') ?>

                                        </p>

                                    </div>


                                    <span class="wp-invitation-status <?= $status_class ?>">

                                        <?= htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8') ?>

                                    </span>

                                </div>


                                <!-- Invitation details -->
                                <div class="wp-invitation-details">

                                    <span>

                                        <i class="bi bi-person"></i>

                                        <?= htmlspecialchars($employer_name, ENT_QUOTES, 'UTF-8') ?>

                                    </span>


                                    <span>

                                        <i class="bi bi-calendar3"></i>

                                        <?= htmlspecialchars($created_at, ENT_QUOTES, 'UTF-8') ?>

                                    </span>


                                    <?php if (isset($job['hourly_rate'])): ?>

                                        <span>

                                            <i class="bi bi-cash"></i>

                                            $<?= number_format((float) $job['hourly_rate'], 2) ?>/hr

                                        </span>

                                    <?php endif; ?>


                                    <?php if (!empty($job['employment_type'])): ?>

                                        <span>

                                            <i class="bi bi-clock"></i>

                                            <?= htmlspecialchars(
                                                ucwords(str_replace('_', ' ', $job['employment_type'])),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>

                                        </span>

                                    <?php endif; ?>

                                </div>


                                <!-- Interview-specific details -->
                                <?php if ($group_key === 'interview'): ?>

                                    <div class="wp-invitation-section">

                                        <h5>Interview Details</h5>

                                        <p>
                                            <strong>Schedule:</strong>
                                            <?= htmlspecialchars($interview_date, ENT_QUOTES, 'UTF-8') ?>
                                        </p>

                                        <p>
                                            <strong>Interview Type:</strong>
                                            <?= htmlspecialchars($interview_type_label, ENT_QUOTES, 'UTF-8') ?>
                                        </p>

                                        <?php if (!empty($invitation['meeting_details'])): ?>

                                            <p>
                                                <strong>Meeting Details:</strong>
                                                <?= nl2br(htmlspecialchars(
                                                    $invitation['meeting_details'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                )) ?>
                                            </p>

                                        <?php endif; ?>

                                    </div>

                                <?php endif; ?>


                                <!-- Job description -->
                                <?php if (!empty($job['description'])): ?>

                                    <div class="wp-invitation-section">

                                        <h5>Job Description</h5>

                                        <p>
                                            <?= nl2br(htmlspecialchars(
                                                $job['description'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            )) ?>
                                        </p>

                                    </div>

                                <?php endif; ?>


                                <!-- Work schedule -->
                                <?php if (!empty($job['work_schedule'])): ?>

                                    <div class="wp-invitation-section">

                                        <h5>Work Schedule</h5>

                                        <p>
                                            <?= htmlspecialchars(
                                                $job['work_schedule'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>

                                    </div>

                                <?php endif; ?>


                                <!-- Employer message -->
                                <div class="wp-invitation-message">

                                    <div class="wp-invitation-message-heading">

                                        <i class="bi bi-chat-left-text"></i>

                                        Employer's Message

                                    </div>

                                    <p>

                                        <?= !empty($invitation['message'])
                                            ? nl2br(htmlspecialchars(
                                                $invitation['message'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ))
                                            : 'No message provided.' ?>

                                    </p>

                                </div>


                                <!-- Pending invitation actions -->
                                <?php if ($status === 'pending'): ?>

                                    <div class="wp-invitation-actions">

                                        <form method="POST" action="invitations.php">

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= htmlspecialchars(
                                                            $_SESSION['csrf_token'],
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        ) ?>">

                                            <input
                                                type="hidden"
                                                name="invitation_id"
                                                value="<?= htmlspecialchars(
                                                            $invitation['id'],
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        ) ?>">


                                            <button
                                                type="submit"
                                                name="respond_invitation"
                                                value="declined"
                                                class="wp-btn wp-btn-outline"
                                                onclick="return confirm('Are you sure you want to decline this <?= $group_key === 'job' ? 'job invitation' : 'interview invitation' ?>?');">

                                                <i class="bi bi-x-lg"></i>

                                                Decline

                                            </button>


                                            <button
                                                type="submit"
                                                name="respond_invitation"
                                                value="accepted"
                                                class="wp-btn wp-btn-success"
                                                onclick="return confirm('Are you sure you want to <?= $group_key === 'job' ? 'accept this job invitation' : 'confirm this interview' ?>?');">

                                                <i class="bi bi-check-lg"></i>

                                                <?= $group_key === 'job'
                                                    ? 'Accept Invitation'
                                                    : 'Confirm Interview' ?>

                                            </button>

                                        </form>

                                    </div>


                                    <!-- Accepted invitation feedback -->
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


                                    <!-- Declined invitation feedback -->
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


<!-- Invitation tab switching -->
<script>
    document.addEventListener('DOMContentLoaded', function() {

        const tabs = document.querySelectorAll('[data-tab]');
        const panels = document.querySelectorAll('.wp-invitation-tab-panel');

        tabs.forEach(function(tab) {

            tab.addEventListener('click', function() {

                const selectedTab = this.dataset.tab;

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