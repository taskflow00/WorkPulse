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

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Invitations | WorkPulse</title>
</head>

<body>

    <h1>My Invitations</h1>

    <p>
        Review job invitations sent directly to you by employers.
    </p>

    <a href="dashboard.php">Back to Dashboard</a>

    <hr>

    <h2>
        Pending Invitations: <?php echo $pending_count; ?>
    </h2>

    <?php if (!empty($message)): ?>

        <p>
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </p>

    <?php endif; ?>


    <?php if (empty($invitations)): ?>

        <p>You don't have any invitations yet.</p>

    <?php else: ?>

        <?php foreach ($invitations as $invitation): ?>

            <?php
            // Get job and employer information.
            $job = $invitation['jobs'] ?? null;
            $employer = $invitation['profiles'] ?? null;

            $status = $invitation['status'] ?? '';

            $employer_name = $employer['full_name'] ?? 'Unknown employer';

            $company_name = $job['company_name']
                ?? $employer['company_name']
                ?? 'Not specified';

            // Format invitation date.
            $created_at = 'Unknown date';

            if (!empty($invitation['created_at'])) {
                $created_at = date(
                    'M d, Y h:i A',
                    strtotime($invitation['created_at'])
                );
            }
            ?>

            <section>

                <hr>

                <h2>
                    <?php echo htmlspecialchars($job['title'] ?? 'Job no longer available', ENT_QUOTES, 'UTF-8'); ?>
                </h2>

                <p>
                    <strong>Company:</strong>
                    <?php echo htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8'); ?>
                </p>

                <p>
                    <strong>Invited by:</strong>
                    <?php echo htmlspecialchars($employer_name, ENT_QUOTES, 'UTF-8'); ?>
                </p>

                <p>
                    <strong>Invitation date:</strong>
                    <?php echo htmlspecialchars($created_at, ENT_QUOTES, 'UTF-8'); ?>
                </p>


                <?php if (!empty($job['description'])): ?>

                    <h3>Job Description</h3>

                    <p>
                        <?php echo nl2br(htmlspecialchars($job['description'], ENT_QUOTES, 'UTF-8')); ?>
                    </p>

                <?php endif; ?>


                <?php if (isset($job['hourly_rate'])): ?>

                    <p>
                        <strong>Hourly Rate:</strong>
                        $<?php echo number_format((float) $job['hourly_rate'], 2); ?>/hour
                    </p>

                <?php endif; ?>


                <?php if (!empty($job['employment_type'])): ?>

                    <p>
                        <strong>Employment Type:</strong>
                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $job['employment_type'])), ENT_QUOTES, 'UTF-8'); ?>
                    </p>

                <?php endif; ?>


                <?php if (!empty($job['work_schedule'])): ?>

                    <p>
                        <strong>Work Schedule:</strong>
                        <?php echo htmlspecialchars($job['work_schedule'], ENT_QUOTES, 'UTF-8'); ?>
                    </p>

                <?php endif; ?>


                <h3>Employer's Message</h3>

                <p>
                    <?php
                    if (!empty($invitation['message'])) {
                        echo nl2br(htmlspecialchars($invitation['message'], ENT_QUOTES, 'UTF-8'));
                    } else {
                        echo 'No message provided.';
                    }
                    ?>
                </p>


                <p>
                    <strong>Status:</strong>
                    <?php echo htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8'); ?>
                </p>


                <?php if ($status === 'pending'): ?>

                    <form method="POST" action="invitations.php">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

                        <input
                            type="hidden"
                            name="invitation_id"
                            value="<?php echo htmlspecialchars($invitation['id'], ENT_QUOTES, 'UTF-8'); ?>">

                        <button
                            type="submit"
                            name="respond_invitation"
                            value="accepted"
                            onclick="return confirm('Are you sure you want to accept this invitation?');">
                            Accept Invitation
                        </button>

                        <button
                            type="submit"
                            name="respond_invitation"
                            value="declined"
                            onclick="return confirm('Are you sure you want to decline this invitation?');">
                            Decline Invitation
                        </button>

                    </form>


                <?php elseif ($status === 'accepted'): ?>

                    <p>
                        You accepted this invitation. The employer can now
                        proceed with the next recruitment step.
                    </p>

                    <p>
                        <strong>Note:</strong>
                        You are not officially hired yet. Wait for a formal employment offer.
                    </p>


                <?php elseif ($status === 'declined'): ?>

                    <p>You declined this invitation.</p>

                <?php endif; ?>

            </section>

        <?php endforeach; ?>

    <?php endif; ?>

</body>

</html>