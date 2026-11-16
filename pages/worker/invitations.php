<?php
$required_role = 'worker';
require_once '../../includes/auth.php';

// Get logged-in worker information.
$worker_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

// Set timezone.
date_default_timezone_set('Asia/Manila');

// Create CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get flash message.
$message = $_SESSION['invitation_message'] ?? '';
unset($_SESSION['invitation_message']);


// ==========================================
// ACCEPT OR DECLINE INVITATION
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_invitation'])) {

    // Check CSRF token.
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $_SESSION['invitation_message'] = 'Invalid request. Please try again.';
        header('Location: invitations.php');
        exit;
    }

    // Get form values.
    $invitation_id = trim($_POST['invitation_id'] ?? '');
    $decision = $_POST['respond_invitation'];

    // Check invitation ID.
    if (empty($invitation_id)) {
        $_SESSION['invitation_message'] = 'Invalid invitation.';
        header('Location: invitations.php');
        exit;
    }

    // Only allow accepted or declined.
    if ($decision !== 'accepted' && $decision !== 'declined') {
        $_SESSION['invitation_message'] = 'Invalid decision.';
        header('Location: invitations.php');
        exit;
    }

    // Supabase invitation URL.
    // Only update this worker's own pending invitation.
    $update_url = SUPABASE_URL .
        '/rest/v1/invitations?id=eq.' . rawurlencode($invitation_id) .
        '&worker_id=eq.' . rawurlencode($worker_id) .
        '&status=eq.pending';

    // Request headers.
    $headers = [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json',
        'Prefer: return=representation'
    ];

    // PATCH request options.
    $options = [
        'http' => [
            'method' => 'PATCH',
            'header' => implode("\r\n", $headers),
            'content' => json_encode([
                'status' => $decision
            ]),
            'ignore_errors' => true
        ]
    ];

    // Send request to Supabase.
    $context = stream_context_create($options);
    $response = file_get_contents($update_url, false, $context);

    // Get HTTP status code.
    $status_code = 0;

    $response_headers = http_get_last_response_headers();

    if (isset($response_headers[0])) {
        $status_parts = explode(' ', $response_headers[0]);
        $status_code = (int) ($status_parts[1] ?? 0);
    }

    // Convert response into PHP array.
    $updated_invitation = json_decode($response ?: '[]', true);

    // Check if Supabase updated the invitation.
    if (
        $status_code >= 200 &&
        $status_code < 300 &&
        !empty($updated_invitation)
    ) {

        if ($decision === 'accepted') {
            $_SESSION['invitation_message'] =
                'Invitation accepted! The employer can now proceed with the next recruitment step.';
        } else {
            $_SESSION['invitation_message'] =
                'You declined the invitation.';
        }
    } else {
        $_SESSION['invitation_message'] =
            'Unable to update your invitation. Please try again.';
    }

    // Return to invitations page.
    header('Location: invitations.php');
    exit;
}


// ==========================================
// GET WORKER INVITATIONS
// ==========================================

// Supabase URL.
$url = SUPABASE_URL .
    '/rest/v1/invitations?' .
    'select=id,job_id,employer_id,message,status,created_at,' .
    'jobs(id,title,company_name,description,hourly_rate,employment_type,work_schedule),' .
    'profiles!invitations_employer_id_fkey(id,full_name,company_name,company_description)' .
    '&worker_id=eq.' . rawurlencode($worker_id) .
    '&order=created_at.desc' .
    '&limit=200';

// Request headers.
$headers = [
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . $access_token,
    'Accept: application/json'
];

// GET request options.
$options = [
    'http' => [
        'method' => 'GET',
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true
    ]
];

// Send request to Supabase.
$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

// Get HTTP status code.
$status_code = 0;

$response_headers = http_get_last_response_headers();

if (isset($response_headers[0])) {
    $status_parts = explode(' ', $response_headers[0]);
    $status_code = (int) ($status_parts[1] ?? 0);
}

// Convert JSON response into PHP array.
$invitations = json_decode($response ?: '[]', true);

// Check if invitations were loaded successfully.
if (
    $status_code < 200 ||
    $status_code >= 300 ||
    !is_array($invitations)
) {
    $invitations = [];
    $message = 'Unable to load your invitations. Please refresh the page.';
}


// ==========================================
// COUNT PENDING INVITATIONS
// ==========================================

$pending_count = 0;

foreach ($invitations as $invitation) {

    if (($invitation['status'] ?? '') === 'pending') {
        $pending_count++;
    }
}
?>


<?php

require_once '../../includes/header.php';

?>

<div class="wp-invitations-page">

    <!-- Page heading -->
    <div class="wp-dashboard-welcome">

        <div>
            <span class="wp-dashboard-eyebrow">RECRUITMENT</span>

            <h1 class="wp-page-title">My Invitations</h1>

            <p class="wp-page-description">
                Review opportunities employers have sent directly to you.
            </p>
        </div>

    </div>

    <!-- Flash message -->
    <?php if (!empty($message)): ?>

        <div class="wp-alert wp-alert-danger">
            <i class="bi bi-exclamation-circle me-2"></i>
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

            <h2>
                <?php
                $accepted_count = 0;

                foreach ($invitations as $invitation) {
                    if (($invitation['status'] ?? '') === 'accepted') {
                        $accepted_count++;
                    }
                }

                echo $accepted_count;
                ?>
            </h2>

            <small>Invitations you've accepted</small>

        </div>

    </div>

    <!-- Invitation list -->
    <section class="wp-panel">

        <div class="wp-panel-header">

            <div>
                <h3 class="wp-panel-title">Received Invitations</h3>

                <p class="wp-panel-description">
                    Opportunities from employers interested in working with you.
                </p>
            </div>

        </div>

        <?php if (empty($invitations)): ?>

            <div class="wp-empty-state">

                <i class="bi bi-envelope-open"></i>

                <h3>No Invitations Yet</h3>

                <p>
                    When employers invite you to their job opportunities,
                    they will appear here.
                </p>

                <a href="jobs.php" class="wp-btn wp-btn-primary">
                    <i class="bi bi-search"></i>
                    Browse Jobs
                </a>

            </div>

        <?php else: ?>

            <div class="wp-invitation-list">

                <?php foreach ($invitations as $invitation): ?>

                    <?php

                    $job = $invitation['jobs'] ?? null;
                    $employer = $invitation['profiles'] ?? null;

                    $status = $invitation['status'] ?? '';

                    $employer_name = $employer['full_name'] ?? 'Unknown employer';

                    $company_name = $job['company_name']
                        ?? $employer['company_name']
                        ?? 'Not specified';

                    $job_title = $job['title'] ?? 'Job no longer available';

                    $created_at = 'Unknown date';

                    if (!empty($invitation['created_at'])) {
                        $created_at = date(
                            'M d, Y h:i A',
                            strtotime($invitation['created_at'])
                        );
                    }

                    $status_class = 'neutral';

                    if ($status === 'pending') {
                        $status_class = 'warning';
                    } elseif ($status === 'accepted') {
                        $status_class = 'success';
                    } elseif ($status === 'declined') {
                        $status_class = 'danger';
                    }

                    ?>

                    <article class="wp-invitation-card">

                        <!-- Invitation heading -->
                        <div class="wp-invitation-heading">

                            <div class="wp-invitation-icon">
                                <i class="bi bi-briefcase"></i>
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
                                <?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?>
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

                        <!-- Invitation actions -->
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
                                        onclick="return confirm('Are you sure you want to decline this invitation?');">

                                        <i class="bi bi-x-lg"></i>
                                        Decline

                                    </button>

                                    <button
                                        type="submit"
                                        name="respond_invitation"
                                        value="accepted"
                                        class="wp-btn wp-btn-success"
                                        onclick="return confirm('Are you sure you want to accept this invitation?');">

                                        <i class="bi bi-check-lg"></i>
                                        Accept Invitation

                                    </button>

                                </form>

                            </div>

                        <?php elseif ($status === 'accepted'): ?>

                            <div class="wp-invitation-feedback success">

                                <i class="bi bi-check-circle-fill"></i>

                                <div>
                                    <strong>Invitation Accepted</strong>

                                    <p>
                                        The employer can now proceed with the next
                                        recruitment step. You are not officially hired yet.
                                    </p>
                                </div>

                            </div>

                        <?php elseif ($status === 'declined'): ?>

                            <div class="wp-invitation-feedback neutral">

                                <i class="bi bi-info-circle-fill"></i>

                                <div>
                                    <strong>Invitation Declined</strong>

                                    <p>You declined this invitation.</p>
                                </div>

                            </div>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>

</div>

<?php require_once '../../includes/footer.php'; ?>