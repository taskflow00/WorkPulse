<?php
$required_role = 'employer';
require_once '../../includes/auth.php';

// Use Philippine time.
date_default_timezone_set('Asia/Manila');

$employerId = $_SESSION['user']['id'];
$accessToken = $_SESSION['access_token'];

$message = $_GET['message'] ?? '';
$error = '';

// Common headers for Supabase requests.
$headers = [
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . $accessToken,
    'Content-Type: application/json'
];

// Prepare GET request options.
$getOptions = [
    'http' => [
        'method' => 'GET',
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true
    ]
];

$getContext = stream_context_create($getOptions);

// Retrieve employments belonging to this employer.
$employmentUrl = SUPABASE_URL .
    '/rest/v1/employments?select=id,worker_id,job_id' .
    '&employer_id=eq.' . urlencode($employerId);

$employmentResponse = file_get_contents(
    $employmentUrl,
    false,
    $getContext
);

$employments = json_decode($employmentResponse ?: '', true);

if (!is_array($employments)) {
    $employments = [];
}

// Prepare arrays.
$proofs = [];
$workers = [];
$jobs = [];
$dailyHoursMap = [];

// Organize employments by ID.
$employmentMap = [];

foreach ($employments as $employment) {
    $employmentMap[$employment['id']] = $employment;
}

// Collect employment IDs.
$employmentIds = [];

foreach ($employments as $employment) {
    $employmentIds[] = $employment['id'];
}

// Handle employer review.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['review_proof'])
) {
    $proofId = $_POST['proof_id'] ?? '';
    $status = $_POST['status'] ?? '';
    $comment = trim($_POST['comment'] ?? '');

    if (
        empty($employmentIds) ||
        $proofId === '' ||
        !in_array(
            $status,
            ['reviewed', 'needs_clarification'],
            true
        )
    ) {
        $error = 'Invalid time proof review request.';
    } elseif (strlen($comment) > 1000) {

        $error = 'Your comment must not exceed 1000 characters.';
    } else {

        // Prepare the review information.
        $reviewData = [
            'status' => $status,
            'employer_comment' => $comment !== '' ? $comment : null,
            'reviewed_at' => date('c'),
            'reviewed_by' => $employerId
        ];

        // Only update a pending proof belonging to this employer.
        $reviewUrl = SUPABASE_URL .
            '/rest/v1/daily_time_proofs?id=eq.' . urlencode($proofId) .
            '&employment_id=in.(' . implode(',', $employmentIds) . ')' .
            '&status=eq.pending';

        $reviewOptions = [
            'http' => [
                'method' => 'PATCH',
                'header' => implode("\r\n", $headers) .
                    "\r\nPrefer: return=representation",
                'content' => json_encode($reviewData),
                'ignore_errors' => true
            ]
        ];

        $reviewContext = stream_context_create($reviewOptions);

        $reviewResponse = file_get_contents(
            $reviewUrl,
            false,
            $reviewContext
        );

        $reviewResult = json_decode(
            $reviewResponse ?: '',
            true
        );

        if (
            is_array($reviewResult) &&
            !empty($reviewResult) &&
            isset($reviewResult[0]['id'])
        ) {

            // Redirect after successful review.
            header(
                'Location: time-proofs.php?message=' .
                    urlencode('Daily time proof reviewed successfully.')
            );

            exit;
        } else {

            $error = 'Unable to review this time proof. It may already have been reviewed, or you may not have permission.';
        }
    }
}

// Retrieve success message after redirect.
if (isset($_GET['message'])) {
    $message = $_GET['message'];
}

// Retrieve daily time proofs.
if (!empty($employmentIds)) {

    $employmentList = implode(',', $employmentIds);

    $proofUrl = SUPABASE_URL .
        '/rest/v1/daily_time_proofs?select=*' .
        '&employment_id=in.(' . $employmentList . ')' .
        '&order=work_date.desc,submitted_at.desc';

    $proofResponse = file_get_contents(
        $proofUrl,
        false,
        $getContext
    );

    $proofs = json_decode($proofResponse ?: '', true);

    if (!is_array($proofs)) {
        $proofs = [];
    }

    // Collect worker and job IDs.
    $workerIds = [];
    $jobIds = [];

    foreach ($employments as $employment) {
        $workerIds[] = $employment['worker_id'];
        $jobIds[] = $employment['job_id'];
    }

    $workerIds = array_unique($workerIds);
    $jobIds = array_unique($jobIds);

    // Retrieve worker names.
    if (!empty($workerIds)) {

        $workerUrl = SUPABASE_URL .
            '/rest/v1/profiles?select=id,full_name' .
            '&id=in.(' . implode(',', $workerIds) . ')';

        $workerResponse = file_get_contents(
            $workerUrl,
            false,
            $getContext
        );

        $workers = json_decode($workerResponse ?: '', true);

        if (!is_array($workers)) {
            $workers = [];
        }
    }

    // Retrieve job titles.
    if (!empty($jobIds)) {

        $jobUrl = SUPABASE_URL .
            '/rest/v1/jobs?select=id,title' .
            '&id=in.(' . implode(',', $jobIds) . ')';

        $jobResponse = file_get_contents(
            $jobUrl,
            false,
            $getContext
        );

        $jobs = json_decode($jobResponse ?: '', true);

        if (!is_array($jobs)) {
            $jobs = [];
        }
    }
}

// Organize worker names by ID.
$workerMap = [];

foreach ($workers as $worker) {
    $workerMap[$worker['id']] = $worker['full_name'];
}

// Organize job titles by ID.
$jobMap = [];

foreach ($jobs as $job) {
    $jobMap[$job['id']] = $job['title'];
}

// Retrieve recorded work and idle hours for each proof.
$timezone = new DateTimeZone('Asia/Manila');

foreach ($proofs as $index => $proof) {

    $employmentId = $proof['employment_id'];
    $workerId = $proof['worker_id'];
    $workDate = $proof['work_date'];

    // Use the date and employment as the cache key.
    $cacheKey = $employmentId . '_' . $workDate;

    // Only retrieve sessions once for each employment and date.
    if (!isset($dailyHoursMap[$cacheKey])) {

        $dayStart = new DateTime(
            $workDate . ' 00:00:00',
            $timezone
        );

        $dayEnd = clone $dayStart;
        $dayEnd->modify('+1 day');

        $sessionUrl = SUPABASE_URL .
            '/rest/v1/time_tracking_sessions?select=started_at,total_seconds,idle_seconds' .
            '&employment_id=eq.' . urlencode($employmentId) .
            '&worker_id=eq.' . urlencode($workerId) .
            '&started_at=gte.' . urlencode($dayStart->format('c')) .
            '&started_at=lt.' . urlencode($dayEnd->format('c'));

        $sessionResponse = file_get_contents(
            $sessionUrl,
            false,
            $getContext
        );

        $sessions = json_decode($sessionResponse ?: '', true);

        if (!is_array($sessions)) {
            $sessions = [];
        }

        $workingSeconds = 0;
        $idleSeconds = 0;

        foreach ($sessions as $session) {

            $workingSeconds += (int) $session['total_seconds'];
            $idleSeconds += (int) $session['idle_seconds'];
        }

        $dailyHoursMap[$cacheKey] = [
            'working_seconds' => $workingSeconds,
            'idle_seconds' => $idleSeconds
        ];
    }

    // Attach the daily totals to this proof.
    $proofs[$index]['working_seconds'] =
        $dailyHoursMap[$cacheKey]['working_seconds'];

    $proofs[$index]['idle_seconds'] =
        $dailyHoursMap[$cacheKey]['idle_seconds'];

    // Generate a temporary signed URL for the private screenshot.
    $pathParts = explode('/', $proof['screenshot_path']);

    $encodedPath = implode(
        '/',
        array_map('rawurlencode', $pathParts)
    );

    $signUrl = SUPABASE_URL .
        '/storage/v1/object/sign/daily-time-proofs/' .
        $encodedPath;

    $signOptions = [
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => json_encode([
                'expiresIn' => 3600
            ]),
            'ignore_errors' => true
        ]
    ];

    $signContext = stream_context_create($signOptions);

    $signResponse = file_get_contents(
        $signUrl,
        false,
        $signContext
    );

    $signResult = json_decode($signResponse ?: '', true);

    $signedPath = $signResult['signedURL'] ?? '';

    if ($signedPath !== '') {

        if (strpos($signedPath, 'http') === 0) {
            $proofs[$index]['screenshot_url'] = $signedPath;
        } else {
            $proofs[$index]['screenshot_url'] =
                SUPABASE_URL . '/storage/v1' . $signedPath;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Daily Time Proofs | WorkPulse</title>

    <link rel="stylesheet" href="../../assets/css/style.css">
</head>

<body>

    <main class="container">

        <a href="dashboard.php">← Back to Dashboard</a>

        <h1>Daily Time Proofs</h1>

        <p>
            Review screenshots submitted by your workers
            to support their recorded working hours.
        </p>

        <?php if ($message !== ''): ?>

            <p>
                <?= htmlspecialchars($message) ?>
            </p>

        <?php endif; ?>

        <?php if ($error !== ''): ?>

            <p>
                <?= htmlspecialchars($error) ?>
            </p>

        <?php endif; ?>

        <?php if (empty($proofs)): ?>

            <p>No daily time proofs have been submitted yet.</p>

        <?php else: ?>

            <?php foreach ($proofs as $proof): ?>

                <?php
                // Get worker and job information.
                $employment = $employmentMap[$proof['employment_id']] ?? [];

                $workerName = $workerMap[$employment['worker_id'] ?? '']
                    ?? 'Unknown Worker';

                $jobTitle = $jobMap[$employment['job_id'] ?? '']
                    ?? 'Unknown Job';

                // Format daily working time.
                $workingSeconds = $proof['working_seconds'];

                $workingTime = sprintf(
                    '%02d:%02d:%02d',
                    intdiv($workingSeconds, 3600),
                    intdiv($workingSeconds % 3600, 60),
                    $workingSeconds % 60
                );

                // Format daily idle time.
                $idleSeconds = $proof['idle_seconds'];

                $idleTime = sprintf(
                    '%02d:%02d:%02d',
                    intdiv($idleSeconds, 3600),
                    intdiv($idleSeconds % 3600, 60),
                    $idleSeconds % 60
                );

                // Prepare a readable status.
                $statusLabel = ucwords(
                    str_replace('_', ' ', $proof['status'])
                );
                ?>

                <section class="timesheet-card">

                    <h2>
                        <?= htmlspecialchars($workerName) ?>
                    </h2>

                    <p>
                        Job:
                        <?= htmlspecialchars($jobTitle) ?>
                    </p>

                    <p>
                        Work Date:
                        <strong>
                            <?= htmlspecialchars($proof['work_date']) ?>
                        </strong>
                    </p>

                    <h3>Recorded Hours</h3>

                    <p>
                        Work Hours:
                        <strong><?= $workingTime ?></strong>
                    </p>

                    <p>
                        Idle Hours:
                        <strong><?= $idleTime ?></strong>
                    </p>

                    <h3>Worker's Screenshot</h3>

                    <?php if (!empty($proof['screenshot_url'])): ?>

                        <p>
                            <a
                                href="<?= htmlspecialchars($proof['screenshot_url']) ?>"
                                target="_blank"
                                rel="noopener noreferrer">
                                Open Screenshot
                            </a>
                        </p>

                        <img
                            src="<?= htmlspecialchars($proof['screenshot_url']) ?>"
                            alt="Worker's time proof screenshot"
                            style="max-width: 100%; height: auto; max-height: 400px;">

                    <?php else: ?>

                        <p>Screenshot is temporarily unavailable.</p>

                    <?php endif; ?>

                    <?php if (!empty($proof['worker_note'])): ?>

                        <h3>Worker's Note</h3>

                        <p>
                            <?= nl2br(htmlspecialchars($proof['worker_note'])) ?>
                        </p>

                    <?php endif; ?>

                    <h3>Review Status</h3>

                    <p>
                        <strong><?= htmlspecialchars($statusLabel) ?></strong>
                    </p>

                    <?php if (!empty($proof['employer_comment'])): ?>

                        <h3>Your Previous Comment</h3>

                        <p>
                            <?= nl2br(htmlspecialchars($proof['employer_comment'])) ?>
                        </p>

                    <?php endif; ?>

                    <?php if ($proof['status'] === 'pending'): ?>

                        <h3>Review This Proof</h3>

                        <form method="POST">

                            <input
                                type="hidden"
                                name="review_proof"
                                value="1">

                            <input
                                type="hidden"
                                name="proof_id"
                                value="<?= htmlspecialchars($proof['id']) ?>">

                            <label for="comment-<?= htmlspecialchars($proof['id']) ?>">
                                Employer Comment (Optional)
                            </label>

                            <textarea
                                id="comment-<?= htmlspecialchars($proof['id']) ?>"
                                name="comment"
                                rows="3"
                                maxlength="1000"
                                placeholder="Write feedback or explain what needs clarification..."></textarea>

                            <br><br>

                            <button
                                type="submit"
                                name="status"
                                value="reviewed">
                                Mark as Reviewed
                            </button>

                            <button
                                type="submit"
                                name="status"
                                value="needs_clarification">
                                Needs Clarification
                            </button>

                        </form>

                    <?php else: ?>

                        <p>This proof has already been reviewed.</p>

                    <?php endif; ?>

                </section>

                <hr>

            <?php endforeach; ?>

        <?php endif; ?>

        <a href="dashboard.php">Back to Dashboard</a>

    </main>

</body>

</html>