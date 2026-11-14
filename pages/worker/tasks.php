<?php
session_start();
require_once '../../config/supabase.php';

// Only workers can access this page.
if (
    !isset($_SESSION['user']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'worker'
) {
    header('Location: ../../login.php');
    exit;
}

$worker_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$message = '';

// Show messages after redirect.
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'started') {
        $message = 'Task started successfully!';
    } elseif ($_GET['success'] === 'completed') {
        $message = 'Task marked as completed!';
    }
}

// Common headers.
$headers =
    "apikey: " . SUPABASE_KEY . "\r\n" .
    "Authorization: Bearer " . $access_token . "\r\n";

// 1. Process task status updates.
if (isset($_POST['update_task'])) {

    $task_id = $_POST['task_id'] ?? '';
    $new_status = $_POST['new_status'] ?? '';

    if (!in_array($new_status, ['in_progress', 'completed'], true)) {
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

            header(
                'Location: tasks.php?success=' .
                    ($new_status === 'in_progress' ? 'started' : 'completed')
            );
            exit;
        } else {

            $message = is_array($result)
                ? ($result['message'] ?? 'Unable to update task.')
                : 'Unable to update task. Please try again.';
        }
    }
}

// 2. Retrieve this worker's tasks.
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
        <p><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <?php if (empty($tasks)): ?>

        <p>You don't have any assigned tasks yet.</p>

    <?php else: ?>

        <?php foreach ($tasks as $task): ?>

            <hr>

            <h2>
                <?php echo htmlspecialchars($task['title']); ?>
            </h2>

            <p>
                <strong>Description:</strong>
                <?php echo htmlspecialchars($task['description'] ?? 'No description provided.'); ?>
            </p>

            <p>
                <strong>Priority:</strong>
                <?php echo htmlspecialchars(ucfirst($task['priority'])); ?>
            </p>

            <p>
                <strong>Deadline:</strong>
                <?php echo !empty($task['due_date'])
                    ? htmlspecialchars(date('F j, Y', strtotime($task['due_date'])))
                    : 'No deadline'; ?>
            </p>

            <p>
                <strong>Estimated Hours:</strong>
                <?php echo $task['estimated_hours'] !== null
                    ? htmlspecialchars($task['estimated_hours']) . ' hours'
                    : 'Not specified'; ?>
            </p>

            <p>
                <strong>Status:</strong>
                <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $task['status']))); ?>
            </p>

            <?php if ($task['status'] === 'pending'): ?>

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

                <form method="POST" action="tasks.php">
                    <input
                        type="hidden"
                        name="task_id"
                        value="<?php echo htmlspecialchars($task['id']); ?>">

                    <input
                        type="hidden"
                        name="new_status"
                        value="completed">

                    <button type="submit" name="update_task">
                        Complete Task
                    </button>
                </form>

            <?php elseif ($task['status'] === 'completed'): ?>

                <p>Task completed.</p>

            <?php endif; ?>

        <?php endforeach; ?>

    <?php endif; ?>

    <br>

    <a href="dashboard.php">Back to Dashboard</a>

</body>

</html>