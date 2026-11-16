<?php
$required_role = 'worker';
$page_title = 'Employment Offers';
$active_page = 'offers';

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

// Count employment offers by status.
$total_offers = count($offers);
$pending_offers = 0;
$accepted_offers = 0;
$declined_offers = 0;

foreach ($offers as $offer) {

    $is_expired = (
        ($offer['status'] ?? '') === 'pending' &&
        !empty($offer['expires_at']) &&
        strtotime($offer['expires_at']) <= time()
    );

    if (!$is_expired && $offer['status'] === 'pending') {
        $pending_offers++;
    } elseif ($offer['status'] === 'accepted') {
        $accepted_offers++;
    } elseif ($offer['status'] === 'declined') {
        $declined_offers++;
    }
}

require_once '../../includes/header.php';
?>

<div class="wp-page wp-offers-page">

    <!-- Page Heading -->
    <div class="wp-page-header">
        <div>
            <h1 class="wp-page-title">Employment Offers</h1>
            <p class="wp-page-description">
                Review and respond to employment offers sent by employers.
            </p>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message !== ''): ?>
        <div class="wp-offers-alert <?php echo htmlspecialchars($message_type); ?>" role="alert">
            <i class="bi <?php echo $message_type === 'success'
                                ? 'bi-check-circle'
                                : 'bi-exclamation-circle'; ?>"></i>

            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <!-- Employment Warning -->
    <?php if ($employment_check_failed): ?>

        <div class="wp-offers-warning" role="alert">
            <i class="bi bi-exclamation-triangle"></i>

            <div>
                <strong>Unable to verify your employment status</strong>
                <p>
                    Please refresh the page before accepting an offer.
                    You can still review and decline offers.
                </p>
            </div>
        </div>

    <?php elseif ($has_active_employment): ?>

        <div class="wp-offers-warning" role="alert">
            <i class="bi bi-info-circle"></i>

            <div>
                <strong>You already have an active employment</strong>
                <p>
                    You must end your current employment before accepting
                    another offer. You can still review and decline other offers.
                </p>

                <a href="employment.php">
                    View My Employment
                    <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        </div>

    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="wp-offers-stats">

        <div class="wp-offers-stat">
            <div class="wp-offers-stat-icon">
                <i class="bi bi-envelope"></i>
            </div>
            <div>
                <span>Total Offers</span>
                <h3><?php echo $total_offers; ?></h3>
            </div>
        </div>

        <div class="wp-offers-stat">
            <div class="wp-offers-stat-icon pending">
                <i class="bi bi-hourglass-split"></i>
            </div>
            <div>
                <span>Pending</span>
                <h3><?php echo $pending_offers; ?></h3>
            </div>
        </div>

        <div class="wp-offers-stat">
            <div class="wp-offers-stat-icon accepted">
                <i class="bi bi-check-circle"></i>
            </div>
            <div>
                <span>Accepted</span>
                <h3><?php echo $accepted_offers; ?></h3>
            </div>
        </div>

        <div class="wp-offers-stat">
            <div class="wp-offers-stat-icon declined">
                <i class="bi bi-x-circle"></i>
            </div>
            <div>
                <span>Declined</span>
                <h3><?php echo $declined_offers; ?></h3>
            </div>
        </div>

    </div>

    <!-- Offers List -->
    <div class="wp-offers-section">

        <div class="wp-offers-section-heading">
            <div>
                <h2>Your Offers</h2>
                <p>Employment opportunities sent directly to you.</p>
            </div>

            <span class="wp-offers-count">
                <?php echo $total_offers; ?> Offers
            </span>
        </div>

        <?php if (empty($offers)): ?>

            <div class="wp-offers-empty">
                <div class="wp-offers-empty-icon">
                    <i class="bi bi-envelope-open"></i>
                </div>

                <h3>No Employment Offers Yet</h3>

                <p>
                    When an employer sends you an employment offer,
                    it will appear here.
                </p>

                <a href="jobs.php" class="wp-btn wp-btn-primary">
                    <i class="bi bi-search"></i>
                    Browse Jobs
                </a>
            </div>

        <?php else: ?>

            <div class="wp-offers-list">

                <?php foreach ($offers as $offer): ?>

                    <?php
                    $job = $offer['jobs'] ?? [];

                    $is_expired = (
                        $offer['status'] === 'pending' &&
                        !empty($offer['expires_at']) &&
                        strtotime($offer['expires_at']) <= time()
                    );

                    $display_status = $is_expired
                        ? 'Expired'
                        : ucfirst($offer['status']);

                    $status_class = $is_expired
                        ? 'expired'
                        : ($offer['status'] === 'pending'
                            ? 'pending'
                            : ($offer['status'] === 'accepted'
                                ? 'accepted'
                                : ($offer['status'] === 'declined'
                                    ? 'declined'
                                    : 'neutral')));

                    $employment_type = ucwords(str_replace(
                        '_',
                        ' ',
                        $offer['employment_type'] ?? 'Not specified'
                    ));
                    ?>

                    <div class="wp-offer-card">

                        <!-- Offer Heading -->
                        <div class="wp-offer-card-top">

                            <div class="wp-offer-company-icon">
                                <i class="bi bi-building"></i>
                            </div>

                            <div class="wp-offer-main">
                                <h3>
                                    <?php echo htmlspecialchars($job['title'] ?? 'Employment Offer'); ?>
                                </h3>

                                <p>
                                    <?php echo htmlspecialchars($job['company_name'] ?? 'Not specified'); ?>
                                </p>
                            </div>

                            <span class="wp-offer-status <?php echo $status_class; ?>">
                                <span></span>
                                <?php echo htmlspecialchars($display_status); ?>
                            </span>

                        </div>

                        <!-- Offer Details -->
                        <div class="wp-offer-details">

                            <div class="wp-offer-detail">
                                <span>Hourly Rate</span>
                                <strong>
                                    $<?php echo number_format((float)$offer['hourly_rate'], 2); ?>
                                </strong>
                            </div>

                            <div class="wp-offer-detail">
                                <span>Employment Type</span>
                                <strong>
                                    <?php echo htmlspecialchars($employment_type); ?>
                                </strong>
                            </div>

                            <div class="wp-offer-detail">
                                <span>Work Schedule</span>
                                <strong>
                                    <?php echo htmlspecialchars($offer['work_schedule'] ?? 'Not specified'); ?>
                                </strong>
                            </div>

                            <div class="wp-offer-detail">
                                <span>Date Sent</span>
                                <strong>
                                    <?php echo !empty($offer['created_at'])
                                        ? date('M d, Y', strtotime($offer['created_at']))
                                        : 'Not available'; ?>
                                </strong>
                            </div>

                            <div class="wp-offer-detail">
                                <span>Expiration Date</span>
                                <strong>
                                    <?php echo !empty($offer['expires_at'])
                                        ? date('M d, Y', strtotime($offer['expires_at']))
                                        : 'Not specified'; ?>
                                </strong>
                            </div>

                        </div>

                        <!-- Employer Message -->
                        <?php if (!empty($offer['offer_message'])): ?>

                            <div class="wp-offer-message">
                                <span>
                                    <i class="bi bi-chat-left-text"></i>
                                    Employer's Message
                                </span>

                                <p>
                                    <?php echo nl2br(htmlspecialchars($offer['offer_message'], ENT_QUOTES, 'UTF-8')); ?>
                                </p>
                            </div>

                        <?php endif; ?>

                        <!-- Offer Actions -->
                        <?php if ($offer['status'] === 'pending' && !$is_expired): ?>

                            <div class="wp-offer-actions">

                                <form method="POST" class="wp-offer-response-form">

                                    <input
                                        type="hidden"
                                        name="offer_id"
                                        value="<?php echo htmlspecialchars($offer['id']); ?>">

                                    <input
                                        type="hidden"
                                        name="decision"
                                        value="accepted">

                                    <button
                                        type="submit"
                                        name="respond_offer"
                                        value="1"
                                        class="wp-btn wp-btn-success"
                                        <?php echo ($has_active_employment || $employment_check_failed) ? 'disabled' : ''; ?>
                                        onclick="return confirm('Are you sure you want to accept this employment offer?');">

                                        <i class="bi bi-check-lg"></i>
                                        Accept Offer

                                    </button>

                                </form>

                                <form method="POST" class="wp-offer-response-form">

                                    <input
                                        type="hidden"
                                        name="offer_id"
                                        value="<?php echo htmlspecialchars($offer['id']); ?>">

                                    <input
                                        type="hidden"
                                        name="decision"
                                        value="declined">

                                    <button
                                        type="submit"
                                        name="respond_offer"
                                        value="1"
                                        class="wp-btn wp-btn-danger"
                                        onclick="return confirm('Are you sure you want to decline this employment offer?');">

                                        <i class="bi bi-x-lg"></i>
                                        Decline Offer

                                    </button>

                                </form>

                            </div>

                        <?php elseif ($is_expired): ?>

                            <div class="wp-offer-feedback">
                                <i class="bi bi-clock-history"></i>
                                This offer has expired and can no longer be accepted.
                            </div>

                        <?php elseif ($offer['status'] === 'accepted'): ?>

                            <div class="wp-offer-feedback accepted">
                                <i class="bi bi-check-circle"></i>
                                You accepted this employment offer.
                            </div>

                        <?php elseif ($offer['status'] === 'declined'): ?>

                            <div class="wp-offer-feedback declined">
                                <i class="bi bi-x-circle"></i>
                                You declined this employment offer.
                            </div>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>