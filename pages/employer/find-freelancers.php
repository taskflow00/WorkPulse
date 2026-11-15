<?php
$required_role = 'employer';
require_once '../../includes/auth.php';

$token = $_SESSION['access_token'];
$employer_id = $_SESSION['user']['id'];

$page_title = 'Find Workers';
$active_page = 'find-freelancers';
$page_styles = ['/assets/css/find-freelancers.css'];

date_default_timezone_set('Asia/Manila');

// Generate CSRF token.
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

$message = $_SESSION['find_workers_message'] ?? '';
unset($_SESSION['find_workers_message']);

$search = trim($_GET['search'] ?? '');
$error = '';
$invitations_error = '';
$offers_error = '';

// Common request headers.
$headers =
    "apikey: " . SUPABASE_KEY . "\r\n" .
    "Authorization: Bearer " . $token . "\r\n";

// Handle worker invitation.
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

    if (!is_string($submitted_token) || !hash_equals($csrf_token, $submitted_token)) {

        $_SESSION['find_workers_message'] = 'Invalid request. Please try again.';
    } elseif (
        !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $worker_id) ||
        !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $job_id)
    ) {

        $_SESSION['find_workers_message'] = 'Invalid worker or job information.';
    } elseif (strlen($invitation_message) > 2000) {

        $_SESSION['find_workers_message'] = 'Your invitation message is too long.';
    } else {

        $data = [
            'job_id' => $job_id,
            'employer_id' => $employer_id,
            'worker_id' => $worker_id,
            'message' => $invitation_message !== '' ? $invitation_message : null,
            'status' => 'pending'
        ];

        $url = SUPABASE_URL . '/rest/v1/invitations';

        $options = [
            'http' => [
                'method' => 'POST',
                'header' =>
                $headers .
                    "Content-Type: application/json\r\n" .
                    "Prefer: return=representation\r\n",
                'content' => json_encode($data),
                'ignore_errors' => true,
                'timeout' => 15
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        if ($response === false) {

            $_SESSION['find_workers_message'] = 'Unable to connect to Supabase.';
        } else {

            $result = json_decode($response, true);
            $response_headers = http_get_last_response_headers();

            $http_status = 0;

            if (is_array($response_headers) && isset($response_headers[0])) {
                preg_match('/\s(\d{3})\s/', $response_headers[0], $matches);
                $http_status = isset($matches[1]) ? (int)$matches[1] : 0;
            }

            if (
                $http_status >= 200 &&
                $http_status < 300 &&
                is_array($result) &&
                count($result) > 0
            ) {

                $_SESSION['find_workers_message'] = 'Invitation successfully sent!';
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

    header('Location: ' . $redirect_url);
    exit;
}

// Retrieve employer's open jobs.
$url = SUPABASE_URL .
    '/rest/v1/jobs?select=id,title,company_name,hourly_rate,employment_type' .
    '&employer_id=eq.' . urlencode($employer_id) .
    '&status=eq.open&order=created_at.desc&limit=100';

$options = [
    'http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true,
        'timeout' => 15
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

$jobs = [];

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

// Retrieve active worker profiles.
$url = SUPABASE_URL .
    '/rest/v1/profiles?select=id,full_name,bio,skills,experience,education,resume_url,portfolio_url,preferred_hourly_rate,created_at' .
    '&role=eq.worker&account_status=eq.active' .
    '&order=created_at.desc&limit=100';

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

// Retrieve invitation history.
$url = SUPABASE_URL .
    '/rest/v1/invitations?select=id,job_id,worker_id,status,created_at' .
    '&employer_id=eq.' . urlencode($employer_id) .
    '&order=created_at.desc&limit=500';

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

$invitations = [];

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

// Retrieve formal offers.
$url = SUPABASE_URL .
    '/rest/v1/employment_offers?select=id,invitation_id,status' .
    '&employer_id=eq.' . urlencode($employer_id) .
    '&invitation_id=not.is.null' .
    '&order=created_at.desc&limit=500';

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

$existing_offers = [];

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

// Keep the latest invitation for each worker/job pair.
$latest_invitations = [];

foreach ($invitations as $invitation) {

    $key = $invitation['job_id'] . ':' . $invitation['worker_id'];

    if (!isset($latest_invitations[$key])) {
        $latest_invitations[$key] = $invitation;
    }
}

// Map offers to their invitations.
$invitation_offers = [];

foreach ($existing_offers as $offer) {

    if (!empty($offer['invitation_id'])) {
        $invitation_offers[$offer['invitation_id']] = $offer;
    }
}

// Filter workers by name, bio, skills, experience, and education.
$filtered_workers = [];

foreach ($workers as $worker) {

    $skills = $worker['skills'] ?? [];

    $skills_text = is_array($skills)
        ? implode(', ', $skills)
        : (string)$skills;

    $search_text =
        ($worker['full_name'] ?? '') . ' ' .
        ($worker['bio'] ?? '') . ' ' .
        $skills_text . ' ' .
        ($worker['experience'] ?? '') . ' ' .
        ($worker['education'] ?? '');

    if ($search === '' || stripos($search_text, $search) !== false) {
        $filtered_workers[] = $worker;
    }
}

require_once '../../includes/header.php';
?>

<div class="find-workers-page">

    <div class="find-workers-heading">
        <div>
            <span class="find-workers-eyebrow">TALENT DISCOVERY</span>
            <h1>Find Workers</h1>
            <p>Discover skilled workers and invite the right people to your open positions.</p>
        </div>

        <a href="jobs.php" class="find-workers-jobs-link">
            <i class="bi bi-briefcase"></i>
            My Job Listings
        </a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="find-workers-alert">
            <i class="bi bi-info-circle"></i>
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <section class="find-workers-search-card">

        <div class="find-workers-search-heading">
            <div>
                <h2>Discover talent</h2>
                <p>Search by name, skills, experience, or education.</p>
            </div>

            <span class="find-workers-count">
                <?php echo count($filtered_workers); ?> workers
            </span>
        </div>

        <form method="GET" action="" class="find-workers-search-form">
            <div class="find-workers-search-input">
                <i class="bi bi-search"></i>
                <input
                    type="text"
                    name="search"
                    value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="Try PHP, graphic design, web development..."
                    aria-label="Search workers">
            </div>

            <button type="submit">
                Search
            </button>

            <?php if ($search !== ''): ?>
                <a href="find-freelancers.php" class="find-workers-clear">
                    Clear
                </a>
            <?php endif; ?>
        </form>

    </section>

    <?php if ($error !== ''): ?>

        <div class="find-workers-empty">
            <i class="bi bi-exclamation-circle"></i>
            <h2>Unable to load workers</h2>
            <p><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>

    <?php elseif ($invitations_error !== ''): ?>

        <div class="find-workers-alert">
            <i class="bi bi-exclamation-triangle"></i>
            <?php echo htmlspecialchars($invitations_error, ENT_QUOTES, 'UTF-8'); ?>
        </div>

    <?php elseif (empty($filtered_workers)): ?>

        <div class="find-workers-empty">
            <i class="bi bi-people"></i>
            <h2>No workers found</h2>
            <p>
                <?php echo $search !== ''
                    ? 'Try another name, skill, or keyword.'
                    : 'There are currently no active worker profiles to display.'; ?>
            </p>
        </div>

    <?php else: ?>

        <div class="find-workers-results-heading">
            <h2>Available Workers</h2>
            <span>Showing up to 100 profiles</span>
        </div>

        <div class="find-workers-grid">

            <?php foreach ($filtered_workers as $worker): ?>

                <?php
                $worker_id = $worker['id'];

                $skills = $worker['skills'] ?? [];
                $skills = is_array($skills) ? $skills : [];

                $worker_initial = strtoupper(
                    substr($worker['full_name'] ?? 'W', 0, 1)
                );
                ?>

                <article class="find-worker-card">

                    <div class="find-worker-top">
                        <div class="find-worker-avatar">
                            <?php echo htmlspecialchars($worker_initial, ENT_QUOTES, 'UTF-8'); ?>
                        </div>

                        <div class="find-worker-identity">
                            <h3>
                                <?php echo htmlspecialchars($worker['full_name'] ?? 'Unnamed Worker', ENT_QUOTES, 'UTF-8'); ?>
                            </h3>
                            <span>
                                <i class="bi bi-circle-fill"></i>
                                Active Worker
                            </span>
                        </div>
                    </div>

                    <div class="find-worker-bio">
                        <?php echo htmlspecialchars(
                            $worker['bio'] ?? 'This worker has not added a biography yet.',
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>
                    </div>

                    <div class="find-worker-rate">
                        <span>Preferred Hourly Rate</span>

                        <strong>
                            <?php if ($worker['preferred_hourly_rate'] !== null): ?>
                                $<?php echo number_format((float)$worker['preferred_hourly_rate'], 2); ?>
                                <small>/hr</small>
                            <?php else: ?>
                                Not specified
                            <?php endif; ?>
                        </strong>
                    </div>

                    <div class="find-worker-section">
                        <h4><i class="bi bi-tools"></i> Skills</h4>

                        <?php if (!empty($skills)): ?>
                            <div class="find-worker-skills">
                                <?php foreach (array_slice($skills, 0, 8) as $skill): ?>
                                    <span><?php echo htmlspecialchars((string)$skill, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endforeach; ?>

                                <?php if (count($skills) > 8): ?>
                                    <span>+<?php echo count($skills) - 8; ?> more</span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p class="find-worker-muted">No skills listed.</p>
                        <?php endif; ?>
                    </div>

                    <div class="find-worker-section">
                        <h4><i class="bi bi-briefcase"></i> Experience</h4>
                        <p>
                            <?php echo nl2br(htmlspecialchars(
                                $worker['experience'] ?? 'Not specified',
                                ENT_QUOTES,
                                'UTF-8'
                            )); ?>
                        </p>
                    </div>

                    <div class="find-worker-section">
                        <h4><i class="bi bi-mortarboard"></i> Education</h4>
                        <p>
                            <?php echo nl2br(htmlspecialchars(
                                $worker['education'] ?? 'Not specified',
                                ENT_QUOTES,
                                'UTF-8'
                            )); ?>
                        </p>
                    </div>

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
                                <i class="bi bi-globe"></i> Portfolio
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
                                <i class="bi bi-file-earmark-text"></i> Resume
                            </a>
                        <?php endif; ?>

                    </div>

                    <div class="find-worker-invitation-history">
                        <h4><i class="bi bi-clock-history"></i> Invitation History</h4>

                        <?php
                        $worker_has_history = false;
                        ?>

                        <?php foreach ($jobs as $job): ?>

                            <?php
                            $key = $job['id'] . ':' . $worker_id;
                            $previous_invitation = $latest_invitations[$key] ?? null;
                            ?>

                            <?php if ($previous_invitation !== null): ?>

                                <?php $worker_has_history = true; ?>

                                <div class="find-worker-history-item">
                                    <span>
                                        <?php echo htmlspecialchars($job['title'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>

                                    <strong class="status-<?php echo htmlspecialchars($previous_invitation['status'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars(ucfirst($previous_invitation['status']), ENT_QUOTES, 'UTF-8'); ?>
                                    </strong>

                                    <?php if ($previous_invitation['status'] === 'accepted'): ?>

                                        <?php $sent_offer = $invitation_offers[$previous_invitation['id']] ?? null; ?>

                                        <?php if ($sent_offer !== null): ?>
                                            <small>
                                                Offer: <?php echo htmlspecialchars(ucfirst($sent_offer['status']), ENT_QUOTES, 'UTF-8'); ?>
                                            </small>
                                        <?php elseif ($offers_error === ''): ?>
                                            <a href="send-offer.php?invitation_id=<?php echo urlencode($previous_invitation['id']); ?>">
                                                Send Employment Offer
                                            </a>
                                        <?php endif; ?>

                                    <?php endif; ?>
                                </div>

                            <?php endif; ?>

                        <?php endforeach; ?>

                        <?php if (!$worker_has_history): ?>
                            <p class="find-worker-muted">No previous invitations for your open jobs.</p>
                        <?php endif; ?>

                        <?php if ($offers_error !== ''): ?>
                            <p class="find-worker-muted">
                                Unable to verify existing offers. Refresh before sending another invitation.
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="find-worker-invite">

                        <h4>Invite this worker</h4>

                        <?php if (empty($jobs)): ?>

                            <p>You need an open job listing before inviting workers.</p>

                            <a href="create-job.php" class="find-worker-create-job">
                                Create a Job
                            </a>

                        <?php elseif ($offers_error !== '' && !empty($invitations)): ?>

                            <p>Refresh the page before sending another invitation.</p>

                        <?php else: ?>

                            <form method="POST" action="">

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

                                    <option value="">Choose a job</option>

                                    <?php foreach ($jobs as $job): ?>

                                        <?php
                                        $key = $job['id'] . ':' . $worker_id;
                                        $previous_invitation = $latest_invitations[$key] ?? null;

                                        $already_invited = $previous_invitation !== null &&
                                            in_array($previous_invitation['status'], ['pending', 'accepted'], true);
                                        ?>

                                        <option
                                            value="<?php echo htmlspecialchars($job['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                            <?php echo $already_invited ? 'disabled' : ''; ?>>

                                            <?php
                                            echo htmlspecialchars(
                                                $job['title'] .
                                                    ' - $' .
                                                    number_format((float)$job['hourly_rate'], 2) .
                                                    '/hr',
                                                ENT_QUOTES,
                                                'UTF-8'
                                            );

                                            if ($already_invited) {
                                                echo ' (Already Invited)';
                                            } elseif ($previous_invitation !== null && $previous_invitation['status'] === 'declined') {
                                                echo ' (Previously Declined - Re-invite)';
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

                                <button type="submit" class="find-worker-invite-button">
                                    <i class="bi bi-send"></i>
                                    Send Invitation
                                </button>

                            </form>

                        <?php endif; ?>

                    </div>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>

<?php require_once '../../includes/footer.php'; ?>