<?php
$required_role = 'worker';
require_once '../../includes/auth.php';

$worker_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$message = '';

if (isset($_GET['success'])) {
    if ($_GET['success'] === 'started') {
        $message = 'Task started successfully!';
    } elseif ($_GET['success'] === 'submitted') {
        $message = 'Your work was submitted successfully! Awaiting employer review.';
    }
}

// Common headers for Supabase requests.
$headers =
    "apikey: " . SUPABASE_KEY . "\r\n" .
    "Authorization: Bearer " . $access_token . "\r\n";

// 1. Process task status updates.
if (isset($_POST['update_task'])) {

    $task_id = $_POST['task_id'] ?? '';
    $new_status = $_POST['new_status'] ?? '';

    // Workers can only start tasks directly.
    // Completing a task now requires employer approval.
    if ($new_status !== 'in_progress') {

        $message = 'Invalid task action.';
    } else {

        $url = SUPABASE_URL . '/rest/v1/rpc/update_task_status';

        $data = [
            'p_task_id' => $task_id,
            'p_status' => $new_status
        ];

        $options = [
            'http' => [
                'method' => 'POST',
                'header' =>
                $headers .
                    "Content-Type: application/json\r\n",
                'content' => json_encode($data),
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        $response_headers = $http_response_header ?? [];
        $matches = [];

        preg_match(
            '/\s(\d{3})\s/',
            $response_headers[0] ?? '',
            $matches
        );

        $status_code = (int)($matches[1] ?? 0);
        $result = json_decode($response ?: '', true);

        if ($status_code === 200 && ($result['success'] ?? false)) {

            header('Location: tasks.php?success=started');
            exit;
        } else {

            $message = is_array($result)
                ? ($result['message'] ?? $result['msg'] ?? 'Unable to update task.')
                : 'Unable to update task. Please try again.';
        }
    }
}

// 2. Retrieve this worker's assigned tasks.
$url = SUPABASE_URL .
    '/rest/v1/tasks?select=*&worker_id=eq.' .
    urlencode($worker_id) .
    '&order=created_at.desc';

$options = [
    'http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($url, false, $context);

$result = json_decode($response ?: '', true);
$tasks = is_array($result) ? $result : [];

// 3. Retrieve this worker's task submissions.
$url = SUPABASE_URL .
    '/rest/v1/task_submissions?select=id,task_id,message,status,submitted_at,employer_feedback,reviewed_at' .
    '&worker_id=eq.' . urlencode($worker_id) .
    '&order=submitted_at.desc';

$options = [
    'http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

$result = json_decode($response ?: '', true);
$submissions = is_array($result) ? $result : [];

// Get the latest submission for each task.
$latest_submissions = [];

foreach ($submissions as $submission) {

    $submission_task_id = $submission['task_id'];

    // Results are newest first, so keep only the first one.
    if (!isset($latest_submissions[$submission_task_id])) {
        $latest_submissions[$submission_task_id] = $submission;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Tasks - WorkPulse</title>
</head>

<body>

    <h1>My Tasks</h1>

    <p>View and manage the tasks assigned to you.</p>

    <?php if ($message !== ''): ?>

        <p>
            <?php echo htmlspecialchars($message); ?>
        </p>

    <?php endif; ?>



    <?php if (empty($tasks)): ?>

        <p>You don't have any assigned tasks yet.</p>

    <?php else: ?>

        <?php foreach ($tasks as $task): ?>

            <?php
            // Find the latest submission for this task.
            $latest_submission = $latest_submissions[$task['id']] ?? null;
            ?>

            <hr>

            <h2>
                <?php echo htmlspecialchars($task['title']); ?>
            </h2>

            <p>
                <strong>Description:</strong>

                <?php echo htmlspecialchars(
                    $task['description'] ?? 'No description provided.'
                ); ?>
            </p>

            <p>
                <strong>Priority:</strong>

                <?php echo htmlspecialchars(
                    ucfirst($task['priority'] ?? 'Not specified')
                ); ?>
            </p>

            <p>
                <strong>Deadline:</strong>

                <?php
                echo !empty($task['due_date'])
                    ? htmlspecialchars(date('F j, Y', strtotime($task['due_date'])))
                    : 'No deadline';
                ?>
            </p>

            <p>
                <strong>Estimated Hours:</strong>

                <?php
                echo isset($task['estimated_hours'])
                    ? htmlspecialchars((string)$task['estimated_hours']) . ' hours'
                    : 'Not specified';
                ?>
            </p>

            <p>
                <strong>Status:</strong>

                <?php
                echo htmlspecialchars(
                    ucwords(str_replace('_', ' ', $task['status']))
                );
                ?>
            </p>


            <?php if ($task['status'] === 'pending'): ?>

                <!-- Worker can start a pending task. -->
                <form method="POST" action="tasks.php">

                    <input
                        type="hidden"
                        name="task_id"
                        value="<?php echo htmlspecialchars($task['id']); ?>">

                    <input
                        type="hidden"
                        name="new_status"
                        value="in_progress">

                    <button type="submit" name="update_task">
                        Start Task
                    </button>

                </form>


            <?php elseif ($task['status'] === 'in_progress'): ?>


                <?php if (
                    $latest_submission &&
                    $latest_submission['status'] === 'pending_review'
                ): ?>

                    <!-- Submission is waiting for employer review. -->
                    <p>
                        <strong>Submission Status:</strong>
                        Awaiting employer review.
                    </p>

                    <p>
                        <strong>Submitted On:</strong>

                        <?php echo htmlspecialchars(
                            date(
                                'F j, Y g:i A',
                                strtotime($latest_submission['submitted_at'])
                            )
                        ); ?>
                    </p>


                <?php elseif (
                    $latest_submission &&
                    $latest_submission['status'] === 'revision_requested'
                ): ?>

                    <!-- Employer requested changes. -->
                    <p>
                        <strong>Submission Status:</strong>
                        Revisions requested.
                    </p>

                    <p>
                        <strong>Employer Feedback:</strong>
                    </p>

                    <p>
                        <?php echo nl2br(htmlspecialchars(
                            $latest_submission['employer_feedback'] ?? 'No feedback provided.'
                        )); ?>
                    </p>

                    <a href="submit-task.php?task_id=<?php echo urlencode($task['id']); ?>">
                        Resubmit Work
                    </a>


                <?php else: ?>

                    <!-- No submission yet. -->
                    <a href="submit-task.php?task_id=<?php echo urlencode($task['id']); ?>">
                        Submit Completed Work
                    </a>

                <?php endif; ?>


            <?php elseif ($task['status'] === 'completed'): ?>

                <p>
                    Task completed and approved!
                </p>


            <?php elseif ($task['status'] === 'cancelled'): ?>

                <p>
                    This task has been cancelled.
                </p>

            <?php endif; ?>

        <?php endforeach; ?>

    <?php endif; ?>


    <br>

    <a href="dashboard.php">Back to Dashboard</a>

</body>

</html>