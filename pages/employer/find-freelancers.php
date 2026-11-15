<?php

// Only employers can access this page.
$required_role = 'employer';
require_once '../../includes/auth.php';

date_default_timezone_set('Asia/Manila');

$token = $_SESSION['access_token'];
$employer_id = $_SESSION['user']['id'];


// ============================================
// 1. CSRF PROTECTION
// ============================================

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];


// ============================================
// 2. PAGE MESSAGES
// ============================================

$message = $_SESSION['find_workers_message'] ?? '';
unset($_SESSION['find_workers_message']);

$search = trim($_GET['search'] ?? '');


// ============================================
// 3. SEND WORKER INVITATION
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $worker_id = trim($_POST['worker_id'] ?? '');
    $job_id = trim($_POST['job_id'] ?? '');
    $invitation_message = trim($_POST['message'] ?? '');
    $submitted_token = $_POST['csrf_token'] ?? '';

    $redirect_url = 'find-freelancers.php';

    if ($search !== '') {
        $redirect_url .= '?search=' . urlencode($search);
    }

    // Validate the CSRF token.
    if (
        !is_string($submitted_token) ||
        !hash_equals($csrf_token, $submitted_token)
    ) {

        $_SESSION['find_workers_message'] =
            'Invalid request. Please try again.';
    } elseif (
        !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $worker_id) ||
        !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $job_id)
    ) {

        $_SESSION['find_workers_message'] =
            'Invalid worker or job information.';
    } elseif (strlen($invitation_message) > 2000) {

        $_SESSION['find_workers_message'] =
            'Your invitation message is too long. Maximum: 2000 characters.';
    } else {

        // Prepare the invitation.
        $data = [
            'job_id' => $job_id,
            'employer_id' => $employer_id,
            'worker_id' => $worker_id,
            'message' => $invitation_message !== ''
                ? $invitation_message
                : null,
            'status' => 'pending'
        ];

        // Insert the invitation into Supabase.
        $url = SUPABASE_URL . '/rest/v1/invitations';

        $options = [
            'http' => [
                'method' => 'POST',
                'header' =>
                "apikey: " . SUPABASE_KEY . "\r\n" .
                    "Authorization: Bearer " . $token . "\r\n" .
                    "Content-Type: application/json\r\n" .
                    "Prefer: return=representation\r\n",
                'content' => json_encode($data),
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        if ($response === false) {

            $_SESSION['find_workers_message'] =
                'Unable to connect to Supabase.';
        } else {

            $result = json_decode($response, true);

            // Get HTTP response status.
            $http_status = 0;
            $response_headers = http_get_last_response_headers();

            if (isset($response_headers[0])) {
                $status_parts = explode(' ', $response_headers[0]);
                $http_status = (int) ($status_parts[1] ?? 0);
            }

            if (
                $http_status >= 200 &&
                $http_status < 300 &&
                is_array($result) &&
                count($result) > 0
            ) {

                $_SESSION['find_workers_message'] =
                    'Invitation successfully sent!';
            } elseif (
                $http_status === 409 ||
                (is_array($result) && ($result['code'] ?? '') === '23505')
            ) {

                $_SESSION['find_workers_message'] =
                    'You already have a pending or accepted invitation for this worker and job.';
            } elseif ($http_status === 403) {

                $_SESSION['find_workers_message'] =
                    'You are not allowed to invite this worker or use this job listing.';
            } else {

                $_SESSION['find_workers_message'] =
                    'Unable to send the invitation. Please try again.';
            }
        }
    }

    // Redirect to prevent form resubmission.
    header('Location: ' . $redirect_url);
    exit;
}


// ============================================
// 4. GET EMPLOYER'S OPEN JOBS
// ============================================

$url = SUPABASE_URL .
    '/rest/v1/jobs?select=id,title,company_name,hourly_rate,employment_type' .
    '&employer_id=eq.' . urlencode($employer_id) .
    '&status=eq.open' .
    '&order=created_at.desc&limit=100';

$options = [
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $token . "\r\n",
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

$jobs = [];
$error = '';

if ($response === false) {

    $error = 'Unable to retrieve your open job listings.';
} else {

    $result = json_decode($response, true);

    if (is_array($result) && !isset($result['code'])) {
        $jobs = $result;
    } else {
        $error = 'Unable to retrieve your open job listings.';
    }
}


// ============================================
// 5. GET ACTIVE WORKER PROFILES
// ============================================

$url = SUPABASE_URL .
    '/rest/v1/profiles?select=id,full_name,bio,skills,experience,education,resume_url,portfolio_url,preferred_hourly_rate,created_at' .
    '&role=eq.worker' .
    '&account_status=eq.active' .
    '&order=created_at.desc&limit=100';

$options = [
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $token . "\r\n",
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

$workers = [];

if ($response === false) {

    $error = 'Unable to retrieve worker profiles.';
} else {

    $result = json_decode($response, true);

    if (is_array($result) && !isset($result['code'])) {
        $workers = $result;
    } else {
        $error = 'Unable to retrieve worker profiles.';
    }
}


// ============================================
// 6. GET EMPLOYER'S INVITATION HISTORY
// ============================================

$url = SUPABASE_URL .
    '/rest/v1/invitations?select=id,job_id,worker_id,status,created_at' .
    '&employer_id=eq.' . urlencode($employer_id) .
    '&order=created_at.desc&limit=500';

$options = [
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $token . "\r\n",
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

$invitations = [];
$invitations_error = '';

if ($response === false) {

    $invitations_error = 'Unable to retrieve invitation history.';
} else {

    $result = json_decode($response, true);

    if (is_array($result) && !isset($result['code'])) {
        $invitations = $result;
    } else {
        $invitations_error = 'Unable to retrieve invitation history.';
    }
}


// ============================================
// 7. GET EXISTING FORMAL OFFERS
// ============================================

$url = SUPABASE_URL .
    '/rest/v1/employment_offers?select=id,invitation_id,status' .
    '&employer_id=eq.' . urlencode($employer_id) .
    '&invitation_id=not.is.null' .
    '&order=created_at.desc&limit=500';

$options = [
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $token . "\r\n",
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

$existing_offers = [];
$offers_error = '';

if ($response === false) {

    $offers_error = 'Unable to check existing employment offers.';
} else {

    $result = json_decode($response, true);

    if (is_array($result) && !isset($result['code'])) {
        $existing_offers = $result;
    } else {
        $offers_error = 'Unable to check existing employment offers.';
    }
}


// ============================================
// 8. GET THE LATEST INVITATION STATUS
// ============================================

// Invitations are returned newest first.
// Only the latest invitation for each job/worker pair is needed.

$latest_invitations = [];

foreach ($invitations as $invitation) {

    $key = $invitation['job_id'] . ':' . $invitation['worker_id'];

    if (!isset($latest_invitations[$key])) {
        $latest_invitations[$key] = $invitation;
    }
}


// ============================================
// 9. MAP EXISTING OFFERS TO INVITATIONS
// ============================================

$invitation_offers = [];

foreach ($existing_offers as $offer) {

    if (!empty($offer['invitation_id'])) {

        $invitation_offers[$offer['invitation_id']] = $offer;
    }
}


// ============================================
// 10. FILTER WORKERS
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

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>WorkPulse | Find Freelancers</title>
</head>

<body>

    <h1>Find Freelancers</h1>

    <p>
        <a href="dashboard.php">Back to Dashboard</a> |
        <a href="jobs.php">My Job Listings</a> |
        <a href="applications.php">Manage Applications</a> |
        <a href="../../logout.php">Logout</a>
    </p>

    <hr>

    <h2>Discover Workers</h2>

    <p>
        Browse registered workers and invite people who match your job requirements.
    </p>


    <!-- PAGE MESSAGE -->

    <?php if ($message !== ''): ?>

        <p>
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </p>

    <?php endif; ?>


    <!-- SEARCH WORKERS -->

    <form method="GET" action="">

        <label for="search">Search Workers</label>

        <input
            type="text"
            id="search"
            name="search"
            placeholder="Name, skills, or experience"
            value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">

        <button type="submit">Search</button>

        <a href="find-freelancers.php">Clear Search</a>

    </form>

    <hr>


    <?php if ($error !== ''): ?>

        <p>
            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </p>

    <?php elseif ($invitations_error !== ''): ?>

        <p>
            <?php echo htmlspecialchars($invitations_error, ENT_QUOTES, 'UTF-8'); ?>
        </p>

    <?php elseif (count($filtered_workers) > 0): ?>

        <p>
            Showing <?php echo count($filtered_workers); ?> worker(s).
            Maximum of 100 worker profiles per request.
        </p>


        <?php foreach ($filtered_workers as $worker): ?>

            <?php

            $worker_id = $worker['id'];

            $skills = $worker['skills'] ?? [];

            if (is_array($skills)) {
                $skills_text = implode(', ', $skills);
            } else {
                $skills_text = (string) $skills;
            }

            ?>

            <h3>
                <?php echo htmlspecialchars($worker['full_name'], ENT_QUOTES, 'UTF-8'); ?>
            </h3>

            <p>
                <strong>About:</strong>
                <?php echo htmlspecialchars($worker['bio'] ?? 'No biography provided.', ENT_QUOTES, 'UTF-8'); ?>
            </p>

            <p>
                <strong>Skills:</strong>
                <?php echo htmlspecialchars($skills_text !== '' ? $skills_text : 'Not specified', ENT_QUOTES, 'UTF-8'); ?>
            </p>

            <p>
                <strong>Experience:</strong>
                <?php echo nl2br(htmlspecialchars($worker['experience'] ?? 'Not specified', ENT_QUOTES, 'UTF-8')); ?>
            </p>

            <p>
                <strong>Education:</strong>
                <?php echo nl2br(htmlspecialchars($worker['education'] ?? 'Not specified', ENT_QUOTES, 'UTF-8')); ?>
            </p>

            <p>
                <strong>Preferred Hourly Rate:</strong>

                <?php if ($worker['preferred_hourly_rate'] !== null): ?>

                    $<?php echo number_format((float) $worker['preferred_hourly_rate'], 2); ?>/hr

                <?php else: ?>

                    Not specified

                <?php endif; ?>

            </p>


            <?php if (
                !empty($worker['portfolio_url']) &&
                filter_var($worker['portfolio_url'], FILTER_VALIDATE_URL) &&
                preg_match('/^https?:\/\//i', $worker['portfolio_url'])
            ): ?>

                <p>
                    <a
                        href="<?php echo htmlspecialchars($worker['portfolio_url'], ENT_QUOTES, 'UTF-8'); ?>"
                        target="_blank"
                        rel="noopener noreferrer">
                        View Portfolio
                    </a>
                </p>

            <?php endif; ?>


            <?php if (
                !empty($worker['resume_url']) &&
                filter_var($worker['resume_url'], FILTER_VALIDATE_URL) &&
                preg_match('/^https?:\/\//i', $worker['resume_url'])
            ): ?>

                <p>
                    <a
                        href="<?php echo htmlspecialchars($worker['resume_url'], ENT_QUOTES, 'UTF-8'); ?>"
                        target="_blank"
                        rel="noopener noreferrer">
                        View Resume
                    </a>
                </p>

            <?php endif; ?>


            <!-- INVITATION HISTORY FOR THIS WORKER -->

            <h4>Invitation History</h4>

            <?php if (count($jobs) > 0): ?>

                <?php foreach ($jobs as $job): ?>

                    <?php
                    $key = $job['id'] . ':' . $worker['id'];

                    $previous_invitation = $latest_invitations[$key] ?? null;
                    ?>

                    <?php if ($previous_invitation !== null): ?>

                        <p>
                            <strong>
                                <?php echo htmlspecialchars($job['title'], ENT_QUOTES, 'UTF-8'); ?>:
                            </strong>

                            <?php if ($previous_invitation['status'] === 'pending'): ?>

                                <strong>Pending</strong> — Waiting for worker's response.

                            <?php elseif ($previous_invitation['status'] === 'accepted'): ?>

                                <strong>Accepted</strong>

                                <?php
                                $sent_offer = $invitation_offers[$previous_invitation['id']] ?? null;
                                ?>

                                <?php if ($sent_offer !== null): ?>

                                    <br>
                                    Formal offer already sent.

                                    Status:
                                    <strong>
                                        <?php echo htmlspecialchars(
                                            ucfirst($sent_offer['status']),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ); ?>
                                    </strong>

                                <?php elseif ($offers_error === ''): ?>

                                    <br>
                                    <a href="send-offer.php?invitation_id=<?php echo urlencode($previous_invitation['id']); ?>">
                                        Send Employment Offer
                                    </a>

                                <?php else: ?>

                                    <br>
                                    Unable to check whether an offer already exists. Please refresh the page.

                                <?php endif; ?>

                            <?php elseif ($previous_invitation['status'] === 'declined'): ?>

                                <strong>Declined</strong> — You may invite this worker again.

                            <?php endif; ?>
                        </p>

                    <?php endif; ?>

                <?php endforeach; ?>

            <?php endif; ?>


            <h4>Invite This Worker</h4>


            <?php if (count($jobs) === 0): ?>

                <p>
                    You need an open job listing before you can invite a worker.
                    <a href="create-job.php">Create a Job</a>
                </p>

            <?php elseif ($offers_error !== '' && count($invitations) > 0): ?>

                <p>
                    Existing offer status is unavailable.
                    Refresh the page before sending another invitation.
                </p>

            <?php else: ?>

                <form method="POST" action="">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                    <input
                        type="hidden"
                        name="worker_id"
                        value="<?php echo htmlspecialchars($worker['id'], ENT_QUOTES, 'UTF-8'); ?>">

                    <input
                        type="hidden"
                        name="search"
                        value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">

                    <label for="job_<?php echo htmlspecialchars($worker['id'], ENT_QUOTES, 'UTF-8'); ?>">
                        Select Job
                    </label>

                    <select
                        name="job_id"
                        id="job_<?php echo htmlspecialchars($worker['id'], ENT_QUOTES, 'UTF-8'); ?>"
                        required>

                        <option value="">Choose an open job</option>

                        <?php foreach ($jobs as $job): ?>

                            <?php

                            $key = $job['id'] . ':' . $worker['id'];

                            $previous_invitation = $latest_invitations[$key] ?? null;

                            $already_invited = $previous_invitation !== null &&
                                in_array(
                                    $previous_invitation['status'],
                                    ['pending', 'accepted'],
                                    true
                                );

                            ?>

                            <option
                                value="<?php echo htmlspecialchars($job['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                <?php echo $already_invited ? 'disabled' : ''; ?>>

                                <?php
                                echo htmlspecialchars(
                                    $job['title'] .
                                        ' - $' .
                                        number_format((float) $job['hourly_rate'], 2) .
                                        '/hr',
                                    ENT_QUOTES,
                                    'UTF-8'
                                );

                                if ($previous_invitation !== null) {

                                    if ($previous_invitation['status'] === 'pending') {
                                        echo ' (Invitation Pending)';
                                    } elseif ($previous_invitation['status'] === 'accepted') {
                                        echo ' (Invitation Accepted)';
                                    } elseif ($previous_invitation['status'] === 'declined') {
                                        echo ' (Previously Declined - You Can Re-invite)';
                                    }
                                }
                                ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                    <br><br>

                    <label for="message_<?php echo htmlspecialchars($worker['id'], ENT_QUOTES, 'UTF-8'); ?>">
                        Personal Message
                    </label>

                    <br>

                    <textarea
                        name="message"
                        id="message_<?php echo htmlspecialchars($worker['id'], ENT_QUOTES, 'UTF-8'); ?>"
                        rows="4"
                        maxlength="2000"
                        placeholder="Introduce your company and explain why you would like to work with this person."></textarea>

                    <br><br>

                    <button type="submit">
                        Send Invitation
                    </button>

                </form>

            <?php endif; ?>

            <hr>

        <?php endforeach; ?>

    <?php else: ?>

        <p>No workers found.</p>

        <?php if ($search !== ''): ?>

            <p>Try searching with another name, skill, or keyword.</p>

        <?php endif; ?>

    <?php endif; ?>

</body>

</html>