<?php
$required_role = 'employer';
require_once '../../includes/auth.php';

$application_id = $_GET['id'] ?? '';
$message = '';
$message_type = '';

// Validate application ID.
if (!preg_match(
    '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
    $application_id
)) {
    exit('Invalid application ID.');
}

// Get the accepted application and its job details.
$url = SUPABASE_URL .
    '/rest/v1/applications?select=id,worker_id,job_id,status,jobs(title,company_name,hourly_rate,employment_type,work_schedule)&id=eq.' .
    urlencode($application_id);

$options = [
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $_SESSION['access_token'] . "\r\n"
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($url, false, $context);
$applications = json_decode($response ?: '', true);

if (!is_array($applications) || !isset($applications[0])) {
    exit('Application not found or you do not have permission to access it.');
}

$application = $applications[0];
$job = $application['jobs'] ?? [];

// Only accepted applications can receive an offer.
if ($application['status'] !== 'accepted') {
    exit('This application must be accepted before sending an employment offer.');
}

// Get the applicant's name.
$worker_url = SUPABASE_URL .
    '/rest/v1/profiles?select=full_name&id=eq.' .
    urlencode($application['worker_id']);

$options['http']['method'] = 'GET';

$context = stream_context_create($options);
$worker_response = @file_get_contents($worker_url, false, $context);
$workers = json_decode($worker_response ?: '', true);

$worker_name = $workers[0]['full_name'] ?? 'Worker';

// Check whether an offer already exists.
$offer_url = SUPABASE_URL .
    '/rest/v1/employment_offers?select=id,status&application_id=eq.' .
    urlencode($application_id);

$context = stream_context_create($options);
$offer_response = @file_get_contents($offer_url, false, $context);
$offers = json_decode($offer_response ?: '', true);

$existing_offer = $offers[0] ?? null;

// Send the employment offer.
if (isset($_POST['send_offer'])) {

    if ($existing_offer) {
        $message = 'An employment offer has already been sent for this application.';
        $message_type = 'error';
    } else {

        $hourly_rate = trim($_POST['hourly_rate'] ?? '');
        $employment_type = $_POST['employment_type'] ?? '';
        $work_schedule = trim($_POST['work_schedule'] ?? '');
        $offer_message = trim($_POST['offer_message'] ?? '');

        if (!is_numeric($hourly_rate) || $hourly_rate <= 0) {
            $message = 'Please enter a valid hourly rate.';
        } elseif (!in_array($employment_type, ['full_time', 'part_time'])) {
            $message = 'Invalid employment type.';
        } elseif ($work_schedule === '') {
            $message = 'Please enter the work schedule.';
        } else {

            $data = [
                'application_id' => $application['id'],
                'job_id' => $application['job_id'],
                'employer_id' => $_SESSION['user']['id'],
                'worker_id' => $application['worker_id'],
                'hourly_rate' => $hourly_rate,
                'employment_type' => $employment_type,
                'work_schedule' => $work_schedule,
                'offer_message' => $offer_message
            ];

            $url = SUPABASE_URL . '/rest/v1/employment_offers';

            $options = [
                'http' => [
                    'method' => 'POST',
                    'header' =>
                    "Content-Type: application/json\r\n" .
                        "apikey: " . SUPABASE_KEY . "\r\n" .
                        "Authorization: Bearer " . $_SESSION['access_token'] . "\r\n" .
                        "Prefer: return=representation\r\n",
                    'content' => json_encode($data),
                    'ignore_errors' => true
                ]
            ];

            $context = stream_context_create($options);
            $response = @file_get_contents($url, false, $context);

            preg_match(
                '/\s(\d{3})\s/',
                $http_response_header[0] ?? '',
                $matches
            );

            $status_code = (int)($matches[1] ?? 0);
            $result = json_decode($response ?: '', true);

            if ($status_code === 201) {
                $message = 'Employment offer sent successfully!';
                $message_type = 'success';
                $existing_offer = $result[0] ?? true;
            } else {
                $message = $result['message'] ?? 'Unable to send employment offer. Please try again.';
                $message_type = 'error';
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
        Applicant:
        <strong><?php echo htmlspecialchars($worker_name); ?></strong>
    </p>

    <p>
        Job:
        <strong><?php echo htmlspecialchars($job['title'] ?? ''); ?></strong>
    </p>

    <?php if ($message !== ''): ?>
        <p><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <?php if ($existing_offer): ?>

        <p>
            An offer already exists for this application.
            Status:
            <strong>
                <?php echo htmlspecialchars(
                    $existing_offer['status'] ?? 'pending'
                ); ?>
            </strong>
        </p>

    <?php else: ?>

        <form method="POST">

            <label>Hourly Rate (PHP)</label>
            <input
                type="number"
                name="hourly_rate"
                min="0.01"
                step="0.01"
                value="<?php echo htmlspecialchars(
                            $_POST['hourly_rate'] ?? $job['hourly_rate'] ?? ''
                        ); ?>"
                required>

            <br><br>

            <label>Employment Type</label>
            <select name="employment_type" required>
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

            <label>Work Schedule</label>
            <input
                type="text"
                name="work_schedule"
                placeholder="Example: Monday-Friday, 8 AM-5 PM"
                value="<?php echo htmlspecialchars(
                            $_POST['work_schedule'] ?? $job['work_schedule'] ?? ''
                        ); ?>"
                required>

            <br><br>

            <label>Message to Worker (Optional)</label>
            <textarea name="offer_message"><?php echo htmlspecialchars(
                                                $_POST['offer_message'] ?? ''
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