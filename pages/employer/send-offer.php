<?php
date_default_timezone_set('Asia/Manila');

$required_role = 'employer';
require_once '../../includes/auth.php';

$employer_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$page_title = 'Send Employment Offer';
$active_page = 'find-freelancers';
$page_styles = ['/assets/css/send-offer.css'];

$message = '';
$message_type = '';

$existing_offer = null;
$application = null;
$invitation = null;
$job = [];

$worker_name = 'Worker';
$source_type = '';

$application_id = $_GET['id'] ?? '';
$invitation_id = $_GET['invitation_id'] ?? '';

// CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

// Common authenticated GET options.
$get_options = [
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $access_token . "\r\n",
        'ignore_errors' => true,
        'timeout' => 15
    ]
];

// Validate IDs.
if (
    ($application_id !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $application_id)) ||
    ($invitation_id !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $invitation_id))
) {
    exit('Invalid ID format.');
}

// Determine the offer source.
if ($application_id !== '' && $invitation_id === '') {

    $source_type = 'application';

    $url = SUPABASE_URL .
        '/rest/v1/applications?select=id,worker_id,job_id,status,jobs(title,company_name,hourly_rate,employment_type,work_schedule,employer_id)' .
        '&id=eq.' . urlencode($application_id);

    $context = stream_context_create($get_options);
    $response = @file_get_contents($url, false, $context);
    $applications = json_decode($response ?: '', true);

    if (!is_array($applications) || !isset($applications[0])) {
        exit('Application not found or you do not have permission to access it.');
    }

    $application = $applications[0];
    $job = $application['jobs'] ?? [];

    if (($job['employer_id'] ?? '') !== $employer_id) {
        exit('You do not have permission to send an offer for this application.');
    }

    if (($application['status'] ?? '') !== 'accepted') {
        exit('This application must be accepted before sending an offer.');
    }
} elseif ($invitation_id !== '' && $application_id === '') {

    $source_type = 'invitation';

    $url = SUPABASE_URL .
        '/rest/v1/invitations?select=id,worker_id,job_id,employer_id,status,jobs(title,company_name,hourly_rate,employment_type,work_schedule,employer_id)' .
        '&id=eq.' . urlencode($invitation_id);

    $context = stream_context_create($get_options);
    $response = @file_get_contents($url, false, $context);
    $invitations = json_decode($response ?: '', true);

    if (!is_array($invitations) || !isset($invitations[0])) {
        exit('Invitation not found or you do not have permission to access it.');
    }

    $invitation = $invitations[0];
    $job = $invitation['jobs'] ?? [];

    if (
        ($invitation['employer_id'] ?? '') !== $employer_id ||
        ($job['employer_id'] ?? '') !== $employer_id
    ) {
        exit('You do not have permission to send an offer for this invitation.');
    }

    if (($invitation['status'] ?? '') !== 'accepted') {
        exit('The worker must accept the invitation before receiving an offer.');
    }
} else {
    exit('Invalid request. Provide either an application ID or invitation ID, not both.');
}

// Get worker ID.
$worker_id = $source_type === 'application'
    ? $application['worker_id']
    : $invitation['worker_id'];

// Get worker name.
$worker_url = SUPABASE_URL .
    '/rest/v1/profiles?select=full_name&id=eq.' .
    urlencode($worker_id);

$context = stream_context_create($get_options);
$worker_response = @file_get_contents($worker_url, false, $context);
$workers = json_decode($worker_response ?: '', true);

$worker_name = $workers[0]['full_name'] ?? 'Worker';

// Check existing offer.
$offer_filter = $source_type === 'application'
    ? 'application_id=eq.' . urlencode($application_id)
    : 'invitation_id=eq.' . urlencode($invitation_id);

$offer_url = SUPABASE_URL .
    '/rest/v1/employment_offers?select=id,status&' .
    $offer_filter .
    '&limit=1';

$context = stream_context_create($get_options);
$offer_response = @file_get_contents($offer_url, false, $context);
$offers = json_decode($offer_response ?: '', true);

$existing_offer = $offers[0] ?? null;

// Process offer submission.
if (isset($_POST['send_offer'])) {

    $submitted_token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submitted_token) ||
        !hash_equals($csrf_token, $submitted_token)
    ) {

        $message = 'Invalid form submission. Refresh the page and try again.';
        $message_type = 'error';
    } elseif ($existing_offer) {

        $message = 'An employment offer already exists for this worker.';
        $message_type = 'error';
    } else {

        $hourly_rate = trim($_POST['hourly_rate'] ?? '');
        $employment_type = $_POST['employment_type'] ?? '';
        $work_schedule = trim($_POST['work_schedule'] ?? '');
        $start_date = trim($_POST['start_date'] ?? '');
        $offer_message = trim($_POST['offer_message'] ?? '');

        if (!is_numeric($hourly_rate) || (float)$hourly_rate <= 0) {

            $message = 'Please enter a valid hourly rate.';
            $message_type = 'error';
        } elseif (!in_array($employment_type, ['full_time', 'part_time'], true)) {

            $message = 'Invalid employment type.';
            $message_type = 'error';
        } elseif ($work_schedule === '') {

            $message = 'Please enter the work schedule.';
            $message_type = 'error';
        } elseif (strlen($work_schedule) > 255) {

            $message = 'Work schedule must not exceed 255 characters.';
            $message_type = 'error';
        } elseif ($start_date === '') {

            $message = 'Please select a proposed start date.';
            $message_type = 'error';
        } elseif (strlen($offer_message) > 5000) {

            $message = 'Offer message must not exceed 5,000 characters.';
            $message_type = 'error';
        } else {

            $date_object = DateTimeImmutable::createFromFormat('!Y-m-d', $start_date);

            if (
                !$date_object ||
                $date_object->format('Y-m-d') !== $start_date
            ) {

                $message = 'Please enter a valid start date.';
                $message_type = 'error';
            } elseif ($date_object < new DateTimeImmutable('today')) {

                $message = 'The start date cannot be in the past.';
                $message_type = 'error';
            } else {

                // Prepare employment offer.
                $data = [
                    'application_id' => $source_type === 'application'
                        ? $application['id']
                        : null,

                    'invitation_id' => $source_type === 'invitation'
                        ? $invitation['id']
                        : null,

                    'job_id' => $source_type === 'application'
                        ? $application['job_id']
                        : $invitation['job_id'],

                    'employer_id' => $employer_id,
                    'worker_id' => $worker_id,
                    'hourly_rate' => round((float)$hourly_rate, 2),
                    'employment_type' => $employment_type,
                    'work_schedule' => $work_schedule,
                    'start_date' => $start_date,
                    'offer_message' => $offer_message
                ];

                $insert_url = SUPABASE_URL . '/rest/v1/employment_offers';

                $post_options = [
                    'http' => [
                        'method' => 'POST',
                        'header' =>
                        "Content-Type: application/json\r\n" .
                            "apikey: " . SUPABASE_KEY . "\r\n" .
                            "Authorization: Bearer " . $access_token . "\r\n" .
                            "Prefer: return=representation\r\n",
                        'content' => json_encode($data),
                        'ignore_errors' => true,
                        'timeout' => 15
                    ]
                ];

                $context = stream_context_create($post_options);
                $insert_response = @file_get_contents($insert_url, false, $context);

                // Get HTTP response status.
                $status_code = 0;
                $response_headers = http_get_last_response_headers();

                if (is_array($response_headers) && isset($response_headers[0])) {
                    preg_match('/\s(\d{3})\s/', $response_headers[0], $matches);
                    $status_code = isset($matches[1]) ? (int)$matches[1] : 0;
                }

                $result = json_decode($insert_response ?: '', true);

                if ($status_code >= 200 && $status_code < 300 && isset($result[0])) {

                    $message = 'Employment offer sent successfully!';
                    $message_type = 'success';
                    $existing_offer = $result[0];
                } else {

                    $message = is_array($result)
                        ? ($result['message'] ?? $result['details'] ?? 'Unable to send employment offer.')
                        : 'Unable to connect to the offer service. Please try again.';

                    $message_type = 'error';
                }
            }
        }
    }
}

require_once '../../includes/header.php';
?>

<div class="send-offer-page">

    <div class="offer-page-heading">

        <div>
            <span class="offer-eyebrow">RECRUITMENT</span>
            <h1>Employment Offer</h1>
            <p>Prepare an employment offer for your selected worker.</p>
        </div>

        <a href="applications.php" class="offer-back-link">
            <i class="bi bi-arrow-left"></i>
            Back to Applications
        </a>

    </div>

    <?php if ($message !== ''): ?>
        <div class="offer-alert offer-alert-<?php echo htmlspecialchars($message_type, ENT_QUOTES, 'UTF-8'); ?>">
            <i class="bi bi-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="offer-layout">

        <main class="offer-main">

            <section class="offer-card">

                <div class="offer-card-heading">
                    <div class="offer-icon">
                        <i class="bi bi-person-check"></i>
                    </div>

                    <div>
                        <h2>Selected Worker</h2>
                        <p>Review the worker and job before preparing the offer.</p>
                    </div>
                </div>

                <div class="offer-worker">

                    <div class="offer-worker-avatar">
                        <i class="bi bi-person"></i>
                    </div>

                    <div>
                        <h3><?php echo htmlspecialchars($worker_name, ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p><?php echo htmlspecialchars($job['title'] ?? 'Job position', ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>

                    <span class="offer-source-badge">
                        <?php echo $source_type === 'application' ? 'Application' : 'Invitation'; ?>
                    </span>

                </div>

                <div class="offer-job-details">

                    <div>
                        <span>Company</span>
                        <strong><?php echo htmlspecialchars($job['company_name'] ?? 'Your Company', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>

                    <div>
                        <span>Job Rate</span>
                        <strong>
                            <?php echo isset($job['hourly_rate']) ? '$' . number_format((float)$job['hourly_rate'], 2) . '/hr' : 'Not specified'; ?>
                        </strong>
                    </div>

                </div>

            </section>

            <?php if ($existing_offer): ?>

                <section class="offer-card offer-existing-card">

                    <div class="offer-existing-icon">
                        <i class="bi bi-send-check"></i>
                    </div>

                    <h2>Offer Already Exists</h2>
                    <p>An employment offer has already been created for this worker.</p>

                    <div class="offer-existing-status">
                        Status:
                        <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $existing_offer['status'] ?? 'pending')), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>

                </section>

            <?php else: ?>

                <section class="offer-card">

                    <div class="offer-card-heading">
                        <div class="offer-icon">
                            <i class="bi bi-file-earmark-text"></i>
                        </div>

                        <div>
                            <h2>Offer Details</h2>
                            <p>Complete the terms of employment.</p>
                        </div>
                    </div>

                    <form method="POST" class="offer-form">

                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="offer-form-grid">

                            <div class="offer-field">
                                <label for="hourly_rate">Hourly Rate (USD) <span>*</span></label>

                                <div class="offer-input-wrapper">
                                    <span class="offer-input-prefix">$</span>
                                    <input
                                        type="number"
                                        id="hourly_rate"
                                        name="hourly_rate"
                                        min="0.01"
                                        step="0.01"
                                        placeholder="0.00"
                                        value="<?php echo htmlspecialchars($_POST['hourly_rate'] ?? $job['hourly_rate'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        required>
                                </div>
                            </div>

                            <div class="offer-field">
                                <label for="employment_type">Employment Type <span>*</span></label>

                                <select id="employment_type" name="employment_type" required>
                                    <option value="full_time"
                                        <?php echo ($_POST['employment_type'] ?? $job['employment_type'] ?? '') === 'full_time' ? 'selected' : ''; ?>>
                                        Full-time
                                    </option>

                                    <option value="part_time"
                                        <?php echo ($_POST['employment_type'] ?? $job['employment_type'] ?? '') === 'part_time' ? 'selected' : ''; ?>>
                                        Part-time
                                    </option>
                                </select>
                            </div>

                            <div class="offer-field offer-field-full">
                                <label for="work_schedule">Work Schedule <span>*</span></label>

                                <input
                                    type="text"
                                    id="work_schedule"
                                    name="work_schedule"
                                    maxlength="255"
                                    placeholder="Example: Monday-Friday, 8 AM-5 PM"
                                    value="<?php echo htmlspecialchars($_POST['work_schedule'] ?? $job['work_schedule'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    required>
                            </div>

                            <div class="offer-field offer-field-full">
                                <label for="start_date">Proposed Start Date <span>*</span></label>

                                <input
                                    type="date"
                                    id="start_date"
                                    name="start_date"
                                    min="<?php echo date('Y-m-d'); ?>"
                                    value="<?php echo htmlspecialchars($_POST['start_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    required>
                            </div>

                            <div class="offer-field offer-field-full">
                                <label for="offer_message">Message to Worker</label>

                                <textarea
                                    id="offer_message"
                                    name="offer_message"
                                    maxlength="5000"
                                    rows="5"
                                    placeholder="Add any additional information or welcome message..."><?php echo htmlspecialchars($_POST['offer_message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>

                                <small>Optional · Maximum 5,000 characters</small>
                            </div>

                        </div>

                        <div class="offer-form-footer">
                            <span><i class="bi bi-shield-check"></i> Review the terms before sending.</span>

                            <button type="submit" name="send_offer" class="offer-submit-button">
                                <i class="bi bi-send"></i>
                                Send Employment Offer
                            </button>
                        </div>

                    </form>

                </section>

            <?php endif; ?>

        </main>

        <aside class="offer-sidebar">

            <section class="offer-tip-card">
                <div class="offer-tip-heading">
                    <i class="bi bi-lightbulb"></i>
                    <h3>Before Sending</h3>
                </div>

                <ul>
                    <li>Confirm the hourly rate is correct.</li>
                    <li>Clearly specify the work schedule.</li>
                    <li>Choose a realistic start date.</li>
                    <li>Include any important employment details.</li>
                </ul>
            </section>

            <section class="offer-info-card">
                <i class="bi bi-info-circle"></i>
                <div>
                    <strong>What happens next?</strong>
                    <p>The worker can review your offer and decide whether to accept or decline it.</p>
                </div>
            </section>

        </aside>

    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>