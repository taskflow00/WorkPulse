<?php
date_default_timezone_set('Asia/Manila');

$required_role = 'employer';
require_once '../../includes/auth.php';

// Get the logged-in employer.
$employer_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

// Get the source IDs from the URL.
$application_id = $_GET['id'] ?? '';
$invitation_id = $_GET['invitation_id'] ?? '';

// Initialize messages and data.
$message = '';
$message_type = '';

$existing_offer = null;
$application = null;
$invitation = null;
$job = [];

$worker_name = 'Worker';
$source_type = '';

// Create a CSRF token for the form.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

// Common options for authenticated Supabase GET requests.
$get_options = [
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $access_token . "\r\n",
        'ignore_errors' => true
    ]
];

// Validate UUID format.
if (
    ($application_id !== '' && !preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        $application_id
    )) ||
    ($invitation_id !== '' && !preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        $invitation_id
    ))
) {
    exit('Invalid ID format.');
}

// Determine whether the offer comes from an application or invitation.
if ($application_id !== '' && $invitation_id === '') {

    $source_type = 'application';

    // Get the application and its job details.
    $url = SUPABASE_URL .
        '/rest/v1/applications?select=id,worker_id,job_id,status,jobs(title,company_name,hourly_rate,employment_type,work_schedule,employer_id)&id=eq.' .
        urlencode($application_id);

    $context = stream_context_create($get_options);
    $response = @file_get_contents($url, false, $context);

    $applications = json_decode($response ?: '', true);

    if (!is_array($applications) || !isset($applications[0])) {
        exit('Application not found or you do not have permission to access it.');
    }

    $application = $applications[0];
    $job = $application['jobs'] ?? [];

    // Make sure this application belongs to the employer.
    if (($job['employer_id'] ?? '') !== $employer_id) {
        exit('You do not have permission to send an offer for this application.');
    }

    // Only accepted applications can receive an offer.
    if (($application['status'] ?? '') !== 'accepted') {
        exit('This application must be accepted before sending an employment offer.');
    }
} elseif ($invitation_id !== '' && $application_id === '') {

    $source_type = 'invitation';

    // Get the invitation and its job details.
    $url = SUPABASE_URL .
        '/rest/v1/invitations?select=id,worker_id,job_id,employer_id,status,jobs(title,company_name,hourly_rate,employment_type,work_schedule,employer_id)&id=eq.' .
        urlencode($invitation_id);

    $context = stream_context_create($get_options);
    $response = @file_get_contents($url, false, $context);

    $invitations = json_decode($response ?: '', true);

    if (!is_array($invitations) || !isset($invitations[0])) {
        exit('Invitation not found or you do not have permission to access it.');
    }

    $invitation = $invitations[0];
    $job = $invitation['jobs'] ?? [];

    // Make sure this invitation belongs to the employer.
    if (
        ($invitation['employer_id'] ?? '') !== $employer_id ||
        ($job['employer_id'] ?? '') !== $employer_id
    ) {
        exit('You do not have permission to send an offer for this invitation.');
    }

    // Only accepted invitations can receive an offer.
    if (($invitation['status'] ?? '') !== 'accepted') {
        exit('The worker must accept the invitation before receiving an employment offer.');
    }
} else {
    exit('Invalid request. Provide either an application ID or invitation ID, not both.');
}

// Get the worker's ID.
if ($source_type === 'application') {
    $worker_id = $application['worker_id'];
} else {
    $worker_id = $invitation['worker_id'];
}

// Get the worker's name.
$worker_url = SUPABASE_URL .
    '/rest/v1/profiles?select=full_name&id=eq.' .
    urlencode($worker_id);

$context = stream_context_create($get_options);
$worker_response = @file_get_contents($worker_url, false, $context);

$workers = json_decode($worker_response ?: '', true);

$worker_name = $workers[0]['full_name'] ?? 'Worker';

// Check whether an offer already exists for this source.
if ($source_type === 'application') {
    $offer_filter = 'application_id=eq.' . urlencode($application_id);
} else {
    $offer_filter = 'invitation_id=eq.' . urlencode($invitation_id);
}

$offer_url = SUPABASE_URL .
    '/rest/v1/employment_offers?select=id,status&' .
    $offer_filter .
    '&limit=1';

$context = stream_context_create($get_options);
$offer_response = @file_get_contents($offer_url, false, $context);

$offers = json_decode($offer_response ?: '', true);

$existing_offer = $offers[0] ?? null;


// Process the employment offer form.
if (isset($_POST['send_offer'])) {

    // Validate the CSRF token.
    $submitted_token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submitted_token) ||
        !hash_equals($csrf_token, $submitted_token)
    ) {

        $message = 'Invalid form submission. Please refresh the page and try again.';
        $message_type = 'error';
    } elseif ($existing_offer) {

        $message = 'An employment offer already exists for this worker.';
        $message_type = 'error';
    } else {

        // Get the submitted form values.
        $hourly_rate = trim($_POST['hourly_rate'] ?? '');
        $employment_type = $_POST['employment_type'] ?? '';
        $work_schedule = trim($_POST['work_schedule'] ?? '');
        $start_date = trim($_POST['start_date'] ?? '');
        $offer_message = trim($_POST['offer_message'] ?? '');

        // Validate the hourly rate.
        if (!is_numeric($hourly_rate) || (float) $hourly_rate <= 0) {

            $message = 'Please enter a valid hourly rate.';
            $message_type = 'error';
        } elseif (!in_array($employment_type, ['full_time', 'part_time'], true)) {

            $message = 'Invalid employment type.';
            $message_type = 'error';
        } elseif ($work_schedule === '') {

            $message = 'Please enter the work schedule.';
            $message_type = 'error';
        } elseif ($start_date === '') {

            $message = 'Please select a proposed start date.';
            $message_type = 'error';
        } else {

            // Validate the date format.
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

                // Prepare the employment offer.
                // Only one source ID should be populated.
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
                    'hourly_rate' => round((float) $hourly_rate, 2),
                    'employment_type' => $employment_type,
                    'work_schedule' => $work_schedule,
                    'start_date' => $start_date,
                    'offer_message' => $offer_message
                ];

                // Send the offer to Supabase.
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
                        'ignore_errors' => true
                    ]
                ];

                $context = stream_context_create($post_options);
                $insert_response = @file_get_contents($insert_url, false, $context);

                // Get the HTTP response status.
                $status_code = 0;
                $response_headers = http_get_last_response_headers();

                if (is_array($response_headers) && isset($response_headers[0])) {

                    $status_parts = explode(' ', $response_headers[0]);
                    $status_code = (int) ($status_parts[1] ?? 0);
                }

                $result = json_decode($insert_response ?: '', true);

                if ($status_code === 201 && isset($result[0])) {

                    $message = 'Employment offer sent successfully!';
                    $message_type = 'success';

                    $existing_offer = $result[0];
                } else {

                    $message = $result['message'] ??
                        $result['details'] ??
                        'Unable to send employment offer. Please try again.';

                    $message_type = 'error';
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Employment Offer - WorkPulse</title>
</head>

<body>

    <h1>Send Employment Offer</h1>

    <p>
        Worker:
        <strong><?php echo htmlspecialchars($worker_name, ENT_QUOTES, 'UTF-8'); ?></strong>
    </p>

    <p>
        Job:
        <strong><?php echo htmlspecialchars($job['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
    </p>

    <p>
        Source:
        <strong>
            <?php
            echo $source_type === 'application'
                ? 'Job Application'
                : 'Direct Invitation';
            ?>
        </strong>
    </p>

    <?php if ($message !== ''): ?>

        <p class="<?php echo htmlspecialchars($message_type, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </p>

    <?php endif; ?>


    <?php if ($existing_offer): ?>

        <p>An employment offer already exists for this worker.</p>

        <p>
            Status:
            <strong>
                <?php echo htmlspecialchars(
                    $existing_offer['status'] ?? 'pending',
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>
            </strong>
        </p>

    <?php else: ?>

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

            <label for="hourly_rate">Hourly Rate (USD)</label>

            <input
                type="number"
                id="hourly_rate"
                name="hourly_rate"
                min="0.01"
                step="0.01"
                value="<?php echo htmlspecialchars(
                            $_POST['hourly_rate'] ?? $job['hourly_rate'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>"
                required>

            <br><br>

            <label for="employment_type">Employment Type</label>

            <select id="employment_type" name="employment_type" required>

                <option value="full_time"
                    <?php echo (
                        ($_POST['employment_type'] ?? $job['employment_type'] ?? '') === 'full_time'
                    ) ? 'selected' : ''; ?>>
                    Full-time
                </option>

                <option value="part_time"
                    <?php echo (
                        ($_POST['employment_type'] ?? $job['employment_type'] ?? '') === 'part_time'
                    ) ? 'selected' : ''; ?>>
                    Part-time
                </option>

            </select>

            <br><br>

            <label for="work_schedule">Work Schedule</label>

            <input
                type="text"
                id="work_schedule"
                name="work_schedule"
                placeholder="Example: Monday-Friday, 8 AM-5 PM"
                value="<?php echo htmlspecialchars(
                            $_POST['work_schedule'] ?? $job['work_schedule'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>"
                required>

            <br><br>

            <label for="start_date">Proposed Start Date</label>

            <input
                type="date"
                id="start_date"
                name="start_date"
                min="<?php echo date('Y-m-d'); ?>"
                value="<?php echo htmlspecialchars(
                            $_POST['start_date'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>"
                required>

            <br><br>

            <label for="offer_message">Message to Worker (Optional)</label>

            <textarea
                id="offer_message"
                name="offer_message"><?php echo htmlspecialchars(
                                            $_POST['offer_message'] ?? '',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ); ?></textarea>

            <br><br>

            <button type="submit" name="send_offer">
                Send Employment Offer
            </button>

        </form>

    <?php endif; ?>

    <br>

    <a href="applications.php">Back to Applications</a>

</body>

</html>