<?php
$required_role = 'employer';
require_once '../../includes/auth.php';

date_default_timezone_set('Asia/Manila');

$employer_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$page_title = 'Review Submission';
$active_page = 'tasks';
$page_styles = ['/assets/css/review-submission.css'];

$error = '';
$message = '';

$submission_id = $_GET['id'] ?? $_POST['submission_id'] ?? '';

if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $submission_id)) {
    exit('Invalid submission ID.');
}

// Generate CSRF token.
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

// Common authenticated headers.
$headers =
    "apikey: " . SUPABASE_KEY . "\r\n" .
    "Authorization: Bearer " . $access_token . "\r\n";

// Retrieve the submission and uploaded files.
$submission_url = SUPABASE_URL .
    '/rest/v1/task_submissions?select=*,' .
    'task_submission_files(id,file_name,file_path,uploaded_at)' .
    '&id=eq.' . urlencode($submission_id) .
    '&limit=1';

$options = [
    'http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true,
        'timeout' => 15
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($submission_url, false, $context);
$submissions = json_decode($response ?: '', true);

if (!is_array($submissions) || !isset($submissions[0])) {
    exit('Submission not found.');
}

$submission = $submissions[0];

// Verify that the task belongs to this employer.
$task_url = SUPABASE_URL .
    '/rest/v1/tasks?select=*&id=eq.' .
    urlencode($submission['task_id']) .
    '&employer_id=eq.' . urlencode($employer_id) .
    '&limit=1';

$context = stream_context_create($options);
$task_response = @file_get_contents($task_url, false, $context);
$tasks = json_decode($task_response ?: '', true);

if (!is_array($tasks) || !isset($tasks[0])) {
    exit('You are not authorized to review this submission.');
}

$task = $tasks[0];

// Retrieve worker information.
$worker_url = SUPABASE_URL .
    '/rest/v1/profiles?select=id,full_name&id=eq.' .
    urlencode($submission['worker_id']) .
    '&limit=1';

$context = stream_context_create($options);
$worker_response = @file_get_contents($worker_url, false, $context);
$workers = json_decode($worker_response ?: '', true);

$worker_name = (
    is_array($workers) && isset($workers[0])
) ? $workers[0]['full_name'] : 'Unknown Worker';

// Generate temporary URLs for uploaded screenshots.
$screenshots = [];
$files = $submission['task_submission_files'] ?? [];

foreach ($files as $file) {

    $sign_url = SUPABASE_URL .
        '/storage/v1/object/sign/task-proofs/' .
        $file['file_path'];

    $sign_options = [
        'http' => [
            'method' => 'POST',
            'header' =>
            $headers .
                "Content-Type: application/json\r\n",
            'content' => json_encode([
                'expiresIn' => 3600
            ]),
            'ignore_errors' => true,
            'timeout' => 15
        ]
    ];

    $context = stream_context_create($sign_options);
    $sign_response = @file_get_contents($sign_url, false, $context);

    $signed_data = json_decode($sign_response ?: '', true);

    if (
        is_array($signed_data) &&
        !empty($signed_data['signedURL'])
    ) {
        $signed_path = $signed_data['signedURL'];

        $screenshots[] = [
            'name' => $file['file_name'],
            'url' => SUPABASE_URL . '/storage/v1' . $signed_path
        ];
    }
}

// Process employer review.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted_token = $_POST['csrf_token'] ?? '';
    $decision = $_POST['decision'] ?? '';
    $feedback = trim($_POST['feedback'] ?? '');

    if (
        !is_string($submitted_token) ||
        !hash_equals($csrf_token, $submitted_token)
    ) {

        $error = 'Invalid request. Please refresh the page and try again.';
    } elseif ($submission['status'] !== 'pending_review') {

        $error = 'This submission has already been reviewed.';
    } elseif (!in_array($decision, ['approved', 'revision_requested'], true)) {

        $error = 'Invalid review decision.';
    } elseif (
        $decision === 'revision_requested' &&
        $feedback === ''
    ) {

        $error = 'Please provide feedback before requesting revisions.';
    } elseif (strlen($feedback) > 5000) {

        $error = 'Feedback must not exceed 5,000 characters.';
    } else {

        // Call the database review function.
        $rpc_url = SUPABASE_URL .
            '/rest/v1/rpc/review_task_submission';

        $rpc_payload = [
            'p_submission_id' => $submission_id,
            'p_decision' => $decision,
            'p_feedback' => $feedback
        ];

        $rpc_options = [
            'http' => [
                'method' => 'POST',
                'header' =>
                $headers .
                    "Content-Type: application/json\r\n",
                'content' => json_encode($rpc_payload),
                'ignore_errors' => true,
                'timeout' => 15
            ]
        ];

        $context = stream_context_create($rpc_options);
        $rpc_response = @file_get_contents($rpc_url, false, $context);

        // Get HTTP response status using the PHP 8.5-compatible function.
        $http_status = 0;
        $response_headers = http_get_last_response_headers();

        if (is_array($response_headers) && isset($response_headers[0])) {
            preg_match('/\s(\d{3})\s/', $response_headers[0], $matches);
            $http_status = isset($matches[1]) ? (int)$matches[1] : 0;
        }

        if ($http_status >= 200 && $http_status < 300) {

            header('Location: tasks.php?review=' . urlencode($decision));
            exit;
        } else {

            $rpc_error = json_decode($rpc_response ?: '', true);

            $error = is_array($rpc_error)
                ? ($rpc_error['message'] ?? 'Unable to process the review. Please try again.')
                : 'Unable to connect to the review service. Please try again.';
        }
    }
}

require_once '../../includes/header.php';
?>

<div class="review-submission-page">

    <div class="review-submission-heading">
        <div>
            <span class="review-eyebrow">TASK MANAGEMENT</span>
            <h1>Review Submission</h1>
            <p>Review the submitted work and provide your decision.</p>
        </div>

        <a href="tasks.php" class="review-back-link">
            <i class="bi bi-arrow-left"></i>
            Back to Tasks
        </a>
    </div>

    <?php if ($error !== ''): ?>
        <div class="review-alert">
            <i class="bi bi-exclamation-circle"></i>
            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="review-layout">

        <main class="review-main">

            <section class="review-card">

                <div class="review-card-heading">
                    <div class="review-icon">
                        <i class="bi bi-clipboard-check"></i>
                    </div>

                    <div>
                        <h2>Task Information</h2>
                        <p>Details about the assigned task.</p>
                    </div>
                </div>

                <h3 class="review-task-title">
                    <?php echo htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8'); ?>
                </h3>

                <?php if (!empty($task['description'])): ?>
                    <p class="review-task-description">
                        <?php echo nl2br(htmlspecialchars($task['description'], ENT_QUOTES, 'UTF-8')); ?>
                    </p>
                <?php endif; ?>

                <div class="review-task-meta">

                    <div>
                        <span>Assigned Worker</span>
                        <strong><?php echo htmlspecialchars($worker_name, ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>

                    <div>
                        <span>Task Status</span>
                        <strong>
                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $task['status'])), ENT_QUOTES, 'UTF-8'); ?>
                        </strong>
                    </div>

                    <div>
                        <span>Submission Status</span>
                        <strong class="review-status review-status-<?php echo htmlspecialchars($submission['status'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $submission['status'])), ENT_QUOTES, 'UTF-8'); ?>
                        </strong>
                    </div>

                </div>

            </section>

            <section class="review-card">

                <div class="review-card-heading">
                    <div class="review-icon">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>

                    <div>
                        <h2>Worker's Submission</h2>
                        <p>Review the worker's message and supporting files.</p>
                    </div>
                </div>

                <div class="review-submitted-date">
                    <i class="bi bi-calendar3"></i>

                    Submitted:
                    <?php
                    echo !empty($submission['submitted_at'])
                        ? htmlspecialchars(date('F j, Y g:i A', strtotime($submission['submitted_at'])), ENT_QUOTES, 'UTF-8')
                        : 'Date unavailable';
                    ?>
                </div>

                <div class="review-message">
                    <h3>Submission Message</h3>

                    <p>
                        <?php echo nl2br(htmlspecialchars($submission['message'] ?? 'No message provided.', ENT_QUOTES, 'UTF-8')); ?>
                    </p>
                </div>

            </section>

            <section class="review-card">

                <div class="review-card-heading">
                    <div class="review-icon">
                        <i class="bi bi-images"></i>
                    </div>

                    <div>
                        <h2>Uploaded Files</h2>
                        <p>Images submitted by the worker as proof of completion.</p>
                    </div>
                </div>

                <?php if (empty($screenshots)): ?>

                    <div class="review-no-files">
                        <i class="bi bi-image"></i>
                        <p>No uploaded screenshots could be loaded.</p>
                    </div>

                <?php else: ?>

                    <div class="review-files-grid">

                        <?php foreach ($screenshots as $screenshot): ?>

                            <a
                                href="<?php echo htmlspecialchars($screenshot['url'], ENT_QUOTES, 'UTF-8'); ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="review-file">

                                <img
                                    src="<?php echo htmlspecialchars($screenshot['url'], ENT_QUOTES, 'UTF-8'); ?>"
                                    alt="<?php echo htmlspecialchars($screenshot['name'], ENT_QUOTES, 'UTF-8'); ?>">

                                <div class="review-file-name">
                                    <i class="bi bi-image"></i>
                                    <span><?php echo htmlspecialchars($screenshot['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </div>

                            </a>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </section>

        </main>

        <aside class="review-sidebar">

            <?php if ($submission['status'] === 'pending_review'): ?>

                <section class="review-card review-decision-card">

                    <div class="review-decision-icon">
                        <i class="bi bi-check2-square"></i>
                    </div>

                    <h2>Review Decision</h2>
                    <p>Choose whether to approve the work or request revisions.</p>

                    <form method="POST" action="review-submission.php?id=<?php echo urlencode($submission_id); ?>">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                        <input
                            type="hidden"
                            name="submission_id"
                            value="<?php echo htmlspecialchars($submission_id, ENT_QUOTES, 'UTF-8'); ?>">

                        <label for="feedback">Feedback for Worker</label>

                        <textarea
                            id="feedback"
                            name="feedback"
                            rows="6"
                            maxlength="5000"
                            placeholder="Explain what needs to be improved if revisions are required..."><?php echo htmlspecialchars($_POST['feedback'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>

                        <button
                            type="submit"
                            name="decision"
                            value="approved"
                            class="review-approve-button">
                            <i class="bi bi-check-circle"></i>
                            Approve Work
                        </button>

                        <button
                            type="submit"
                            name="decision"
                            value="revision_requested"
                            class="review-revision-button">
                            <i class="bi bi-arrow-repeat"></i>
                            Request Revisions
                        </button>

                    </form>

                </section>

            <?php else: ?>

                <section class="review-card review-completed-card">

                    <div class="review-completed-icon">
                        <i class="bi bi-check-circle"></i>
                    </div>

                    <h2>Review Completed</h2>

                    <p>This submission has already been reviewed.</p>

                    <span class="review-status review-status-<?php echo htmlspecialchars($submission['status'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $submission['status'])), ENT_QUOTES, 'UTF-8'); ?>
                    </span>

                    <?php if (!empty($submission['employer_feedback'])): ?>

                        <div class="review-feedback">
                            <strong>Employer Feedback</strong>

                            <p>
                                <?php echo nl2br(htmlspecialchars($submission['employer_feedback'], ENT_QUOTES, 'UTF-8')); ?>
                            </p>
                        </div>

                    <?php endif; ?>

                </section>

            <?php endif; ?>

            <div class="review-tip-card">
                <h3><i class="bi bi-lightbulb"></i> Review Tips</h3>

                <ul>
                    <li>Check whether the submitted work meets the task requirements.</li>
                    <li>Review the uploaded screenshots.</li>
                    <li>Give clear and constructive feedback.</li>
                    <li>Request revisions if the work needs improvement.</li>
                </ul>
            </div>

        </aside>

    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>