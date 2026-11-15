<?php
$required_role = 'employer';
require_once '../../includes/auth.php';

date_default_timezone_set('Asia/Manila');

$employer_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$message = '';
$error = '';

$submission_id = $_GET['id'] ?? $_POST['submission_id'] ?? '';

if (!preg_match('/^[0-9a-fA-F-]{36}$/', $submission_id)) {
    exit('Invalid submission ID.');
}

// Common authenticated headers.
$headers =
    "apikey: " . SUPABASE_KEY . "\r\n" .
    "Authorization: Bearer " . $access_token . "\r\n";

// --------------------------------------------------
// 1. Retrieve the submission.
// --------------------------------------------------

$submission_url = SUPABASE_URL .
    '/rest/v1/task_submissions?select=*,' .
    'task_submission_files(id,file_name,file_path,uploaded_at)' .
    '&id=eq.' . urlencode($submission_id) .
    '&limit=1';

$get_options = [
    'http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true
    ]
];

$context = stream_context_create($get_options);

$response = @file_get_contents(
    $submission_url,
    false,
    $context
);

$submissions = json_decode($response ?: '', true);

if (!is_array($submissions) || !isset($submissions[0])) {
    exit('Submission not found.');
}

$submission = $submissions[0];

// --------------------------------------------------
// 2. Verify that the task belongs to this employer.
// --------------------------------------------------

$task_url = SUPABASE_URL .
    '/rest/v1/tasks?select=*&id=eq.' .
    urlencode($submission['task_id']) .
    '&employer_id=eq.' . urlencode($employer_id) .
    '&limit=1';

$context = stream_context_create($get_options);

$task_response = @file_get_contents(
    $task_url,
    false,
    $context
);

$tasks = json_decode($task_response ?: '', true);

if (!is_array($tasks) || !isset($tasks[0])) {
    exit('You are not authorized to review this submission.');
}

$task = $tasks[0];

// --------------------------------------------------
// 3. Retrieve worker information.
// --------------------------------------------------

$worker_url = SUPABASE_URL .
    '/rest/v1/profiles?select=id,full_name&id=eq.' .
    urlencode($submission['worker_id']) .
    '&limit=1';

$context = stream_context_create($get_options);

$worker_response = @file_get_contents(
    $worker_url,
    false,
    $context
);

$workers = json_decode($worker_response ?: '', true);

$worker_name = (
    is_array($workers) && isset($workers[0])
)
    ? $workers[0]['full_name']
    : 'Unknown Worker';

// --------------------------------------------------
// 4. Generate temporary screenshot URLs.
// --------------------------------------------------

$screenshots = [];

$files = $submission['task_submission_files'] ?? [];

foreach ($files as $file) {

    $file_path = $file['file_path'];

    $sign_url = SUPABASE_URL .
        '/storage/v1/object/sign/task-proofs/' .
        $file_path;

    $sign_options = [
        'http' => [
            'method' => 'POST',
            'header' =>
            $headers .
                "Content-Type: application/json\r\n",
            'content' => json_encode([
                'expiresIn' => 3600
            ]),
            'ignore_errors' => true
        ]
    ];

    $context = stream_context_create($sign_options);

    $sign_response = @file_get_contents(
        $sign_url,
        false,
        $context
    );

    $signed_data = json_decode(
        $sign_response ?: '',
        true
    );

    if (
        is_array($signed_data) &&
        !empty($signed_data['signedURL'])
    ) {
        $signed_path = $signed_data['signedURL'];

        $screenshots[] = [
            'name' => $file['file_name'],
            'url' => SUPABASE_URL .
                '/storage/v1' . $signed_path
        ];
    }
}

// --------------------------------------------------
// 5. Process employer decision.
// --------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $decision = $_POST['decision'] ?? '';
    $feedback = trim($_POST['feedback'] ?? '');

    if ($submission['status'] !== 'pending_review') {

        $error = 'This submission has already been reviewed.';
    } elseif (!in_array(
        $decision,
        ['approved', 'revision_requested'],
        true
    )) {

        $error = 'Invalid review decision.';
    } elseif (
        $decision === 'revision_requested' &&
        $feedback === ''
    ) {

        $error = 'Please provide feedback before requesting revisions.';
    } else {

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
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($rpc_options);

        $rpc_response = @file_get_contents(
            $rpc_url,
            false,
            $context
        );

        preg_match(
            '/\s(\d{3})\s/',
            $http_response_header[0] ?? '',
            $matches
        );

        $http_status = (int) ($matches[1] ?? 0);

        if ($http_status >= 200 && $http_status < 300) {

            header('Location: tasks.php?review=' . $decision);
            exit;
        } else {

            $rpc_error = json_decode(
                $rpc_response ?: '',
                true
            );

            $error = $rpc_error['message']
                ?? 'Unable to process the review. Please try again.';
        }
    }
}

// --------------------------------------------------
// 6. Display review page.
// --------------------------------------------------

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Review Submission - WorkPulse</title>
</head>

<body>

    <a href="tasks.php">Back to Task Management</a>

    <h1>Review Submission</h1>

    <p>Review the worker's completed work before making a decision.</p>

    <?php if ($error !== ''): ?>

        <p style="color: red;">
            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </p>

    <?php endif; ?>

    <hr>

    <h2>Task Information</h2>

    <p>
        <strong>Task:</strong>

        <?php echo htmlspecialchars(
            $task['title'],
            ENT_QUOTES,
            'UTF-8'
        ); ?>
    </p>

    <p>
        <strong>Worker:</strong>

        <?php echo htmlspecialchars(
            $worker_name,
            ENT_QUOTES,
            'UTF-8'
        ); ?>
    </p>

    <p>
        <strong>Task Status:</strong>

        <?php echo htmlspecialchars(
            ucfirst(str_replace('_', ' ', $task['status'])),
            ENT_QUOTES,
            'UTF-8'
        ); ?>
    </p>

    <hr>

    <h2>Worker's Submission</h2>

    <p>
        <strong>Submitted At:</strong>

        <?php echo htmlspecialchars(date(
            'F j, Y g:i A',
            strtotime($submission['submitted_at'])
        )); ?>
    </p>

    <p><strong>Message:</strong></p>

    <p>
        <?php echo nl2br(htmlspecialchars(
            $submission['message'],
            ENT_QUOTES,
            'UTF-8'
        )); ?>
    </p>

    <hr>

    <h2>Uploaded Screenshots</h2>

    <?php if (empty($screenshots)): ?>

        <p>No screenshots could be loaded.</p>

    <?php else: ?>

        <?php foreach ($screenshots as $screenshot): ?>

            <div style="margin-bottom: 25px;">

                <p>
                    <strong>
                        <?php echo htmlspecialchars(
                            $screenshot['name'],
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>
                    </strong>
                </p>

                <a
                    href="<?php echo htmlspecialchars(
                                $screenshot['url'],
                                ENT_QUOTES,
                                'UTF-8'
                            ); ?>"
                    target="_blank"
                    rel="noopener noreferrer">
                    <img
                        src="<?php echo htmlspecialchars(
                                    $screenshot['url'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>"
                        alt="Submitted screenshot"
                        style="max-width: 100%; height: auto; max-height: 400px;">
                </a>

                <p>
                    <a
                        href="<?php echo htmlspecialchars(
                                    $screenshot['url'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>"
                        target="_blank"
                        rel="noopener noreferrer">
                        Open Full Image
                    </a>
                </p>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

    <hr>

    <?php if ($submission['status'] === 'pending_review'): ?>

        <h2>Review Decision</h2>

        <form method="POST">

            <input
                type="hidden"
                name="submission_id"
                value="<?php echo htmlspecialchars($submission_id); ?>">

            <label for="feedback">
                Feedback for Worker
            </label>

            <br>

            <textarea
                id="feedback"
                name="feedback"
                rows="5"
                cols="50"
                placeholder="Write feedback here if revisions are needed..."></textarea>

            <br><br>

            <button
                type="submit"
                name="decision"
                value="approved">
                Approve Work
            </button>

            <button
                type="submit"
                name="decision"
                value="revision_requested">
                Request Revisions
            </button>

        </form>

    <?php else: ?>

        <h2>Review Completed</h2>

        <p>
            <strong>Status:</strong>

            <?php echo htmlspecialchars(
                ucwords(str_replace('_', ' ', $submission['status']))
            ); ?>
        </p>

        <?php if (!empty($submission['employer_feedback'])): ?>

            <p>
                <strong>Employer Feedback:</strong>
            </p>

            <p>
                <?php echo nl2br(htmlspecialchars(
                    $submission['employer_feedback'],
                    ENT_QUOTES,
                    'UTF-8'
                )); ?>
            </p>

        <?php endif; ?>

    <?php endif; ?>

    <br>

    <a href="tasks.php">Back to Task Management</a>

</body>

</html>