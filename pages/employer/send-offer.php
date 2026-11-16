<?php
date_default_timezone_set('Asia/Manila');

$required_role = 'employer';
require_once '../../includes/auth.php';
require_once '../../includes/send-email.php';
require_once '../../includes/email-templates.php';

$employer_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$page_title = 'Send Employment Offer';
$active_page = 'applications';
$page_styles = ['/assets/css/send-offer.css'];

$message = '';
$message_type = '';

$application = null;
$job = [];
$worker_name = 'Worker';
$latest_offer = null;
$offer_mode = 'create';

$application_id = $_GET['id'] ?? '';

if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $application_id)) {
    exit('Invalid application ID.');
}

// CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

// Simple Supabase REST request helper.
$supabase_request = function ($method, $url, $data = null) use ($access_token) {

    $headers =
        "apikey: " . SUPABASE_KEY . "\r\n" .
        "Authorization: Bearer " . $access_token . "\r\n";

    if ($data !== null) {
        $headers .= "Content-Type: application/json\r\n";
    }

    if ($method === 'POST') {
        $headers .= "Prefer: return=representation\r\n";
    } elseif ($method === 'PATCH') {
        $headers .= "Prefer: return=representation\r\n";
    }

    $options = [
        'http' => [
            'method' => $method,
            'header' => $headers,
            'ignore_errors' => true,
            'timeout' => 15
        ]
    ];

    if ($data !== null) {
        $options['http']['content'] = json_encode($data);
    }

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    // Read the response headers immediately after file_get_contents().
    $response_headers = $http_response_header ?? [];

    $status_code = 0;

    foreach ($response_headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
            $status_code = (int)$matches[1];
        }
    }

    return [
        'status' => $status_code,
        'body' => $response ?: '',
        'data' => json_decode($response ?: '', true)
    ];
};

// --------------------------------------------------
// 1. LOAD APPLICATION
// --------------------------------------------------

$application_url = SUPABASE_URL .
    '/rest/v1/applications?select=id,worker_id,job_id,status,source,jobs(title,company_name,hourly_rate,employment_type,work_schedule,employer_id)' .
    '&id=eq.' . urlencode($application_id) .
    '&limit=1';

$application_result = $supabase_request('GET', $application_url);

if (
    $application_result['status'] < 200 ||
    $application_result['status'] >= 300 ||
    !isset($application_result['data'][0])
) {
    exit('Application not found or you do not have permission to access it.');
}

$application = $application_result['data'][0];
$job = $application['jobs'] ?? [];

if (($job['employer_id'] ?? '') !== $employer_id) {
    exit('You do not have permission to send an offer for this application.');
}

if (($application['status'] ?? '') !== 'accepted') {
    exit('This application must be accepted before sending an employment offer.');
}

$worker_id = $application['worker_id'];

// --------------------------------------------------
// 2. LOAD WORKER NAME
// --------------------------------------------------

$worker_url = SUPABASE_URL .
    '/rest/v1/profiles?select=full_name&id=eq.' .
    urlencode($worker_id) .
    '&limit=1';

$worker_result = $supabase_request('GET', $worker_url);

if (isset($worker_result['data'][0]['full_name'])) {
    $worker_name = $worker_result['data'][0]['full_name'];
}

// --------------------------------------------------
// 3. LOAD OFFER HISTORY
// --------------------------------------------------

$offers_url = SUPABASE_URL .
    '/rest/v1/employment_offers?select=id,status,hourly_rate,employment_type,work_schedule,start_date,offer_message,expires_at,created_at' .
    '&application_id=eq.' . urlencode($application_id) .
    '&employer_id=eq.' . urlencode($employer_id) .
    '&order=created_at.desc' .
    '&limit=100';

$offers_result = $supabase_request('GET', $offers_url);

if (
    $offers_result['status'] < 200 ||
    $offers_result['status'] >= 300
) {
    exit('Unable to load employment offer history. Please try again.');
}

$offer_history = $offers_result['data'] ?? [];
$latest_offer = $offer_history[0] ?? null;

// Determine whether to create, revise, or wait.
if ($latest_offer) {

    $latest_status = $latest_offer['status'] ?? '';

    if ($latest_status === 'revision_requested') {
        $offer_mode = 'revise';
    } elseif (in_array($latest_status, ['pending', 'accepted'], true)) {
        $offer_mode = 'blocked';
    } elseif (in_array($latest_status, ['declined', 'withdrawn'], true)) {
        // A declined or withdrawn offer remains in history.
        // A new offer will receive a new ID.
        $offer_mode = 'create';
    }
}

// --------------------------------------------------
// 3B. EMAIL THE WORKER ABOUT THE OFFER
// --------------------------------------------------

// The worker email is stored in Supabase Auth.
// Reading it needs the server-side service-role key.

$get_worker_email = function ($user_id) {

    if (
        !defined('SUPABASE_SERVICE_ROLE_KEY') ||
        SUPABASE_SERVICE_ROLE_KEY === ''
    ) {
        error_log('Worker email lookup failed: Service role key is not configured.');
        return null;
    }

    $options = [
        'http' => [
            'method' => 'GET',
            'header' =>
            "apikey: " . SUPABASE_SERVICE_ROLE_KEY . "\r\n" .
                "Authorization: Bearer " . SUPABASE_SERVICE_ROLE_KEY . "\r\n",
            'ignore_errors' => true,
            'timeout' => 15
        ]
    ];

    $response = @file_get_contents(
        SUPABASE_URL . '/auth/v1/admin/users/' . rawurlencode($user_id),
        false,
        stream_context_create($options)
    );

    $data = $response !== false ? json_decode($response, true) : null;

    $email = is_array($data)
        ? ($data['email'] ?? $data['user']['email'] ?? null)
        : null;

    if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    return $email;
};


// Sends the offer email and returns a short sentence for the page message.

$email_offer_to_worker = function ($saved_offer, $is_revision) use (
    $get_worker_email,
    $supabase_request,
    $employer_id,
    $worker_id,
    $worker_name,
    $job
) {

    $worker_email = $get_worker_email($worker_id);

    if ($worker_email === null) {
        return ' However, the worker email address could not be retrieved, so no email was sent. Check SUPABASE_SERVICE_ROLE_KEY.';
    }

    // Use the employer name, then the company name.

    $employer_name = $job['company_name'] ?? 'Your employer';

    $employer_result = $supabase_request(
        'GET',
        SUPABASE_URL .
            '/rest/v1/profiles?select=full_name,company_name' .
            '&id=eq.' . urlencode($employer_id) .
            '&limit=1'
    );

    if (!empty($employer_result['data'][0]['full_name'])) {
        $employer_name = $employer_result['data'][0]['full_name'];
    }

    $email_body = employmentOfferEmail(
        $worker_name,
        $employer_name,
        $job['title'] ?? 'the job position',
        $saved_offer['hourly_rate'] ?? 0,
        $saved_offer['employment_type'] ?? '',
        $saved_offer['start_date'] ?? '',
        $is_revision,
        $saved_offer['offer_message'] ?? ''
    );

    $email_sent = sendEmail(
        $worker_email,
        $is_revision
            ? 'Your Employment Offer Was Revised - WorkPulse'
            : 'New Employment Offer - WorkPulse',
        $email_body
    );

    if ($email_sent) {
        return ' The worker has also been notified by email.';
    }

    return ' However, the email notification could not be sent. Please check the SMTP settings.';
};


// --------------------------------------------------
// 4. PROCESS FORM SUBMISSION
// --------------------------------------------------

if (isset($_POST['send_offer'])) {

    $submitted_token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submitted_token) ||
        !hash_equals($csrf_token, $submitted_token)
    ) {
        $message = 'Invalid form submission. Refresh the page and try again.';
        $message_type = 'error';
    } elseif ($offer_mode === 'blocked') {

        $message = 'An active or accepted employment offer already exists.';
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

                $offer_data = [
                    'hourly_rate' => round((float)$hourly_rate, 2),
                    'employment_type' => $employment_type,
                    'work_schedule' => $work_schedule,
                    'start_date' => $start_date,
                    'offer_message' => $offer_message
                ];

                // Revision: update the existing offer.
                if ($offer_mode === 'revise') {

                    $update_url = SUPABASE_URL .
                        '/rest/v1/employment_offers?id=eq.' .
                        urlencode($latest_offer['id']) .
                        '&application_id=eq.' . urlencode($application_id) .
                        '&employer_id=eq.' . urlencode($employer_id) .
                        '&status=eq.revision_requested';

                    // Return the offer to pending after revision.
                    $offer_data['status'] = 'pending';

                    $save_result = $supabase_request(
                        'PATCH',
                        $update_url,
                        $offer_data
                    );

                    if (
                        $save_result['status'] >= 200 &&
                        $save_result['status'] < 300 &&
                        isset($save_result['data'][0])
                    ) {

                        $latest_offer = $save_result['data'][0];
                        $offer_mode = 'blocked';

                        $message = 'Employment offer revised and sent successfully!' .
                            $email_offer_to_worker($latest_offer, true);
                        $message_type = 'success';
                    } else {

                        $message = $save_result['data']['message'] ??
                            'Unable to revise the employment offer. Please try again.';

                        $message_type = 'error';
                    }
                } else {

                    // New offer: preserve previous declined/withdrawn records.
                    $offer_data['application_id'] = $application_id;
                    $offer_data['invitation_id'] = null;
                    $offer_data['job_id'] = $application['job_id'];
                    $offer_data['employer_id'] = $employer_id;
                    $offer_data['worker_id'] = $worker_id;
                    $offer_data['status'] = 'pending';

                    $insert_url = SUPABASE_URL . '/rest/v1/employment_offers';

                    $save_result = $supabase_request(
                        'POST',
                        $insert_url,
                        $offer_data
                    );

                    if (
                        $save_result['status'] >= 200 &&
                        $save_result['status'] < 300 &&
                        isset($save_result['data'][0])
                    ) {

                        $latest_offer = $save_result['data'][0];
                        $offer_mode = 'blocked';

                        $message = 'Employment offer sent successfully!' .
                            $email_offer_to_worker($latest_offer, false);
                        $message_type = 'success';
                    } else {

                        $message = $save_result['data']['message'] ??
                            'Unable to send employment offer. Please try again.';

                        $message_type = 'error';
                    }
                }
            }
        }
    }
}

// --------------------------------------------------
// 5. FORM DEFAULT VALUES
// --------------------------------------------------

$form_rate = $_POST['hourly_rate']
    ?? ($offer_mode === 'revise'
        ? ($latest_offer['hourly_rate'] ?? '')
        : ($job['hourly_rate'] ?? ''));

$form_type = $_POST['employment_type']
    ?? ($offer_mode === 'revise'
        ? ($latest_offer['employment_type'] ?? 'full_time')
        : ($job['employment_type'] ?? 'full_time'));

$form_schedule = $_POST['work_schedule']
    ?? ($offer_mode === 'revise'
        ? ($latest_offer['work_schedule'] ?? '')
        : ($job['work_schedule'] ?? ''));

$form_start_date = $_POST['start_date']
    ?? ($offer_mode === 'revise'
        ? ($latest_offer['start_date'] ?? '')
        : '');

$form_message = $_POST['offer_message']
    ?? ($offer_mode === 'revise'
        ? ($latest_offer['offer_message'] ?? '')
        : '');

$escape = function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

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
        <div class="offer-alert offer-alert-<?php echo $escape($message_type); ?>">
            <i class="bi bi-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <?php echo $escape($message); ?>
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
                        <h3><?php echo $escape($worker_name); ?></h3>
                        <p><?php echo $escape($job['title'] ?? 'Job position'); ?></p>
                    </div>

                    <span class="offer-source-badge">
                        <?php
                        echo ($application['source'] ?? 'job_application') === 'direct_invitation'
                            ? 'Direct Invitation'
                            : 'Job Application';
                        ?>
                    </span>

                </div>

                <div class="offer-job-details">

                    <div>
                        <span>Company</span>
                        <strong><?php echo $escape($job['company_name'] ?? 'Your Company'); ?></strong>
                    </div>

                    <div>
                        <span>Job Rate</span>
                        <strong>
                            <?php
                            echo isset($job['hourly_rate'])
                                ? '$' . number_format((float)$job['hourly_rate'], 2) . '/hr'
                                : 'Not specified';
                            ?>
                        </strong>
                    </div>

                </div>

            </section>

            <?php if ($offer_mode === 'blocked'): ?>

                <section class="offer-card offer-existing-card">

                    <div class="offer-existing-icon">
                        <i class="bi bi-send-check"></i>
                    </div>

                    <?php if (($latest_offer['status'] ?? '') === 'accepted'): ?>

                        <h2>Offer Accepted</h2>
                        <p>The worker has accepted this employment offer.</p>

                    <?php else: ?>

                        <h2>Offer Awaiting Response</h2>
                        <p>The worker has not responded to the current employment offer yet.</p>

                    <?php endif; ?>

                    <div class="offer-existing-status">
                        Status:
                        <strong>
                            <?php
                            echo $escape(
                                ucwords(str_replace('_', ' ', $latest_offer['status'] ?? 'pending'))
                            );
                            ?>
                        </strong>
                    </div>

                </section>

            <?php else: ?>

                <section class="offer-card">

                    <div class="offer-card-heading">
                        <div class="offer-icon">
                            <i class="bi bi-file-earmark-text"></i>
                        </div>

                        <div>
                            <h2>
                                <?php echo $offer_mode === 'revise' ? 'Revise Employment Offer' : 'Offer Details'; ?>
                            </h2>

                            <p>
                                <?php
                                echo $offer_mode === 'revise'
                                    ? 'Update the terms requested by the worker.'
                                    : 'Complete the terms of employment.';
                                ?>
                            </p>
                        </div>
                    </div>

                    <form method="POST" class="offer-form">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?php echo $escape($csrf_token); ?>">

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
                                        value="<?php echo $escape($form_rate); ?>"
                                        required>
                                </div>
                            </div>

                            <div class="offer-field">
                                <label for="employment_type">Employment Type <span>*</span></label>

                                <select id="employment_type" name="employment_type" required>
                                    <option value="full_time"
                                        <?php echo $form_type === 'full_time' ? 'selected' : ''; ?>>
                                        Full-time
                                    </option>

                                    <option value="part_time"
                                        <?php echo $form_type === 'part_time' ? 'selected' : ''; ?>>
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
                                    value="<?php echo $escape($form_schedule); ?>"
                                    required>
                            </div>

                            <div class="offer-field offer-field-full">
                                <label for="start_date">Proposed Start Date <span>*</span></label>

                                <input
                                    type="date"
                                    id="start_date"
                                    name="start_date"
                                    min="<?php echo date('Y-m-d'); ?>"
                                    value="<?php echo $escape($form_start_date); ?>"
                                    required>
                            </div>

                            <div class="offer-field offer-field-full">
                                <label for="offer_message">Message to Worker</label>

                                <textarea
                                    id="offer_message"
                                    name="offer_message"
                                    maxlength="5000"
                                    rows="5"
                                    placeholder="Add any additional information or welcome message..."><?php echo $escape($form_message); ?></textarea>

                                <small>Optional · Maximum 5,000 characters</small>
                            </div>

                        </div>

                        <div class="offer-form-footer">
                            <span>
                                <i class="bi bi-shield-check"></i>
                                Review the terms before sending.
                            </span>

                            <button type="submit" name="send_offer" class="offer-submit-button">
                                <i class="bi bi-send"></i>

                                <?php
                                echo $offer_mode === 'revise'
                                    ? 'Send Revised Offer'
                                    : 'Send Employment Offer';
                                ?>
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
                    <p>The worker can review your offer and decide whether to accept, decline, or request changes.</p>
                </div>
            </section>

        </aside>

    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>