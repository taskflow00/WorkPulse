<?php
$required_role = 'worker';
require_once '../../includes/auth.php';

$worker_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$message = '';
$message_type = '';

// Check whether the worker already has an active employment.
$url = SUPABASE_URL .
    '/rest/v1/employments?select=id&worker_id=eq.' .
    urlencode($worker_id) .
    '&status=eq.active&limit=1';

$options = [
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $access_token . "\r\n",
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
$active_employments = json_decode($response ?: '', true);

// Fail safely if we cannot verify the worker's employment status.
$employment_check_failed = !(
    $status_code >= 200 &&
    $status_code < 300 &&
    is_array($active_employments)
);

$has_active_employment = (
    !$employment_check_failed &&
    !empty($active_employments)
);

// Handle accepting or declining an offer.
if (isset($_POST['respond_offer'])) {

    $offer_id = $_POST['offer_id'] ?? '';
    $decision = $_POST['decision'] ?? '';

    if (!preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        $offer_id
    )) {
        $message = 'Invalid offer.';
        $message_type = 'error';
    } elseif (!in_array($decision, ['accepted', 'declined'])) {
        $message = 'Invalid response.';
        $message_type = 'error';
    } elseif ($decision === 'accepted' && $employment_check_failed) {
        $message = 'We could not verify your current employment. Please refresh the page and try again.';
        $message_type = 'error';
    } elseif ($decision === 'accepted' && $has_active_employment) {
        $message = 'You already have an active employment. Please end your current employment before accepting another offer.';
        $message_type = 'error';
    } else {

        // Call the secure Supabase database function.
        $url = SUPABASE_URL .
            '/rest/v1/rpc/respond_to_employment_offer';

        $data = [
            'p_offer_id' => $offer_id,
            'p_decision' => $decision
        ];

        $options = [
            'http' => [
                'method' => 'POST',
                'header' =>
                "Content-Type: application/json\r\n" .
                    "apikey: " . SUPABASE_KEY . "\r\n" .
                    "Authorization: Bearer " . $access_token . "\r\n",
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

        if ($status_code >= 200 && $status_code < 300) {

            if ($decision === 'accepted') {
                $message = 'You have accepted the employment offer!';
                $has_active_employment = true;
            } else {
                $message = 'You have declined the employment offer.';
            }

            $message_type = 'success';
        } else {

            // Translate the database's duplicate active employment error.
            $error_text = ($result['message'] ?? '') . ' ' .
                ($result['details'] ?? '') . ' ' .
                ($result['hint'] ?? '');

            if (
                ($result['code'] ?? '') === '23505' ||
                stripos($error_text, 'employments_one_active_per_worker_idx') !== false
            ) {
                $message = 'You already have an active employment. Please end your current employment before accepting another offer.';
                $message_type = 'error';
                $has_active_employment = true;
            } else {
                $message = $decision === 'accepted'
                    ? 'Unable to accept this offer. Please try again.'
                    : 'Unable to decline this offer. Please try again.';

                $message_type = 'error';
            }
        }
    }
}

// Retrieve offers belonging to this worker.
$url = SUPABASE_URL .
    '/rest/v1/employment_offers?select=*,jobs(title,company_name)' .
    '&worker_id=eq.' . urlencode($worker_id) .
    '&order=created_at.desc';

$options = [
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $access_token . "\r\n",
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($url, false, $context);
$offers = json_decode($response ?: '', true);

if (!is_array($offers)) {
    $offers = [];
    $message = 'Unable to retrieve employment offers.';
    $message_type = 'error';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employment Offers - WorkPulse</title>
</head>

<body>

    <h1>Employment Offers</h1>

    <p>Review employment offers sent by employers.</p>

    <?php if ($message !== ''): ?>
        <p
            class="<?php echo htmlspecialchars($message_type); ?>"
            role="alert">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </p>
    <?php endif; ?>

    <?php if ($employment_check_failed): ?>

        <p class="warning" role="alert">
            We couldn't verify your current employment status.
            Please refresh the page before accepting an offer.
            You can still decline offers.
        </p>

    <?php elseif ($has_active_employment): ?>

        <div class="warning" role="alert">
            <strong>You already have an active employment.</strong>

            <p>
                You must end your current employment before accepting another offer.
                You can still review and decline other offers.
            </p>

            <a href="employment.php">View My Employment</a>
        </div>

    <?php endif; ?>

    <?php if (empty($offers)): ?>

        <p>You don't have any employment offers yet.</p>

    <?php else: ?>

        <?php foreach ($offers as $offer): ?>

            <?php
            $job = $offer['jobs'] ?? [];

            $is_expired = (
                $offer['status'] === 'pending' &&
                strtotime($offer['expires_at']) <= time()
            );
            ?>

            <hr>

            <h2>
                <?php echo htmlspecialchars($job['title'] ?? 'Employment Offer'); ?>
            </h2>

            <p>
                Company:
                <?php echo htmlspecialchars($job['company_name'] ?? 'Not specified'); ?>
            </p>

            <p>
                Hourly Rate:
                <strong>
                    $<?php echo number_format((float)$offer['hourly_rate'], 2); ?>
                </strong>
            </p>

            <p>
                Employment Type:
                <?php echo htmlspecialchars(
                    str_replace('_', ' ', $offer['employment_type'])
                ); ?>
            </p>

            <p>
                Work Schedule:
                <?php echo htmlspecialchars($offer['work_schedule']); ?>
            </p>

            <?php if (!empty($offer['offer_message'])): ?>
                <p>
                    Employer's Message:
                    <?php echo nl2br(htmlspecialchars($offer['offer_message'])); ?>
                </p>
            <?php endif; ?>

            <p>
                Sent:
                <?php echo date('M d, Y', strtotime($offer['created_at'])); ?>
            </p>

            <p>
                Expires:
                <?php echo date('M d, Y', strtotime($offer['expires_at'])); ?>
            </p>

            <p>
                Status:
                <strong>
                    <?php
                    echo htmlspecialchars(
                        $is_expired ? 'Expired' : $offer['status']
                    );
                    ?>
                </strong>
            </p>

            <?php if ($offer['status'] === 'pending' && !$is_expired): ?>

                <form method="POST">

                    <input
                        type="hidden"
                        name="offer_id"
                        value="<?php echo htmlspecialchars($offer['id']); ?>">

                    <?php if (!$has_active_employment && !$employment_check_failed): ?>

                        <button
                            type="submit"
                            name="respond_offer"
                            value="1"
                            onclick="this.form.querySelector('[name=decision]').value='accepted'; return confirm('Are you sure you want to accept this employment offer?');">
                            Accept Offer
                        </button>

                    <?php else: ?>

                        <button type="button" disabled>
                            Accept Unavailable
                        </button>

                    <?php endif; ?>

                    <button
                        type="submit"
                        name="respond_offer"
                        value="1"
                        onclick="this.form.querySelector('[name=decision]').value='declined'; return confirm('Are you sure you want to decline this employment offer?');">
                        Decline Offer
                    </button>

                    <input type="hidden" name="decision" value="">

                </form>

            <?php endif; ?>

        <?php endforeach; ?>

    <?php endif; ?>

    <br>
    <a href="dashboard.php">Back to Dashboard</a>

</body>

</html>