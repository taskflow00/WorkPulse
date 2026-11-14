<?php
session_start();
require_once '../../config/supabase.php';

// Only employers can access this page.
if (
    !isset($_SESSION['user']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'employer'
) {
    header('Location: ../../login.php');
    exit;
}

$employer_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$message = '';

// Common headers for authenticated requests.
$headers =
    "apikey: " . SUPABASE_KEY . "\r\n" .
    "Authorization: Bearer " . $access_token . "\r\n";

// 1. Retrieve tasks assigned by this employer.
$url = SUPABASE_URL .
    '/rest/v1/tasks?select=*&employer_id=eq.' .
    urlencode($employer_id) .
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

$tasks = (
    is_array($result) && isset($result[0])
) ? $result : [];

// 2. Retrieve employee names.
$workers = [];

if (!empty($tasks)) {

    $worker_ids = array_unique(
        array_column($tasks, 'worker_id')
    );

    $ids = implode(',', array_map('urlencode', $worker_ids));

    $profile_url = SUPABASE_URL .
        '/rest/v1/profiles?select=id,full_name&id=in.(' .
        $ids . ')';

    $context = stream_context_create($options);
    $profile_response = @file_get_contents(
        $profile_url,
        false,
        $context
    );

    $profiles = json_decode($profile_response ?: '', true);

    if (is_array($profiles)) {
        foreach ($profiles as $profile) {
            $workers[$profile['id']] = $profile['full_name'];
        }
    }
}

// 3. Count task statuses.
$total_tasks = count($tasks);
$pending_tasks = 0;
$in_progress_tasks = 0;
$completed_tasks = 0;

foreach ($tasks as $task) {

    if ($task['status'] === 'pending') {
        $pending_tasks++;
    } elseif ($task['status'] === 'in_progress') {
        $in_progress_tasks++;
    } elseif ($task['status'] === 'completed') {
        $completed_tasks++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Management - WorkPulse</title>
</head>

<body>

    <h1>Task Management</h1>

    <p>Monitor the tasks assigned to your employees.</p>

    <a href="assign-task.php">Assign New Task</a>

    <hr>

    <h2>Task Overview</h2>

    <p>Total Tasks: <?php echo $total_tasks; ?></p>

    <p>Pending: <?php echo $pending_tasks; ?></p>

    <p>In Progress: <?php echo $in_progress_tasks; ?></p>

    <p>Completed: <?php echo $completed_tasks; ?></p>

    <hr>

    <h2>Assigned Tasks</h2>

    <?php if (empty($tasks)): ?>

        <p>You haven't assigned any tasks yet.</p>

    <?php else: ?>

        <?php foreach ($tasks as $task): ?>

            <hr>

            <h3>
                <?php echo htmlspecialchars($task['title']); ?>
            </h3>

            <p>
                <strong>Assigned To:</strong>
                <?php echo htmlspecialchars(
                    $workers[$task['worker_id']] ?? 'Unknown Worker'
                ); ?>
            </p>

            <p>
                <strong>Description:</strong>
                <?php echo htmlspecialchars(
                    $task['description'] ?? 'No description provided.'
                ); ?>
            </p>

            <p>
                <strong>Priority:</strong>
                <?php echo htmlspecialchars(ucfirst($task['priority'])); ?>
            </p>

            <p>
                <strong>Deadline:</strong>

                <?php echo !empty($task['due_date'])
                    ? htmlspecialchars(date(
                        'F j, Y',
                        strtotime($task['due_date'])
                    ))
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
                <?php echo htmlspecialchars(
                    ucwords(str_replace('_', ' ', $task['status']))
                ); ?>
            </p>

            <p>
                <strong>Assigned Date:</strong>
                <?php echo htmlspecialchars(date(
                    'F j, Y',
                    strtotime($task['created_at'])
                )); ?>
            </p>

        <?php endforeach; ?>

    <?php endif; ?>

    <br>

    <a href="dashboard.php">Back to Dashboard</a>

</body>

</html>