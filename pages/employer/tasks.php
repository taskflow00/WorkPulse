<?php
$required_role = 'employer';
require_once '../../includes/auth.php';

$employer_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

// Common headers for authenticated requests.
$headers =
    "apikey: " . SUPABASE_KEY . "\r\n" .
    "Authorization: Bearer " . $access_token . "\r\n";

$options = [
    'http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true
    ]
];

// --------------------------------------------------
// 1. Retrieve tasks assigned by this employer.
// --------------------------------------------------

$url = SUPABASE_URL .
    '/rest/v1/tasks?select=*&employer_id=eq.' .
    urlencode($employer_id) .
    '&order=created_at.desc';

$context = stream_context_create($options);
$response = @file_get_contents($url, false, $context);

$result = json_decode($response ?: '', true);

$tasks = is_array($result) && array_is_list($result)
    ? $result
    : [];

$task_lookup = [];

foreach ($tasks as $task) {
    $task_lookup[$task['id']] = $task;
}

// --------------------------------------------------
// 2. Retrieve pending task submissions.
// --------------------------------------------------

$pending_reviews = [];

if (!empty($task_lookup)) {

    $task_ids = array_keys($task_lookup);

    $ids = implode(',', array_map('urlencode', $task_ids));

    $submission_url = SUPABASE_URL .
        '/rest/v1/task_submissions?select=' .
        'id,task_id,worker_id,message,status,submitted_at,' .
        'task_submission_files(id,file_name,file_path,uploaded_at)' .
        '&task_id=in.(' . $ids . ')' .
        '&status=eq.pending_review' .
        '&order=submitted_at.desc';

    $context = stream_context_create($options);

    $submission_response = @file_get_contents(
        $submission_url,
        false,
        $context
    );

    $submission_result = json_decode(
        $submission_response ?: '',
        true
    );

    if (
        is_array($submission_result) &&
        array_is_list($submission_result)
    ) {
        $pending_reviews = $submission_result;
    }
}

// --------------------------------------------------
// 3. Retrieve worker names.
// --------------------------------------------------

$workers = [];

$worker_ids = [];

foreach ($tasks as $task) {
    $worker_ids[] = $task['worker_id'];
}

foreach ($pending_reviews as $submission) {
    $worker_ids[] = $submission['worker_id'];
}

$worker_ids = array_unique($worker_ids);

if (!empty($worker_ids)) {

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

// --------------------------------------------------
// 4. Count task statuses.
// --------------------------------------------------

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

$pending_review_count = count($pending_reviews);
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

    <!-- Task Overview -->

    <h2>Task Overview</h2>

    <p>Total Tasks: <?php echo $total_tasks; ?></p>

    <p>Pending: <?php echo $pending_tasks; ?></p>

    <p>In Progress: <?php echo $in_progress_tasks; ?></p>

    <p>Completed: <?php echo $completed_tasks; ?></p>

    <hr>

    <!-- Pending Reviews -->

    <h2>Pending Reviews</h2>

    <p>
        Submissions waiting for your approval:
        <strong><?php echo $pending_review_count; ?></strong>
    </p>

    <?php if (empty($pending_reviews)): ?>

        <p>No submissions are waiting for review.</p>

    <?php else: ?>

        <?php foreach ($pending_reviews as $submission): ?>

            <?php
            $task = $task_lookup[$submission['task_id']] ?? null;

            $worker_name = $workers[$submission['worker_id']]
                ?? 'Unknown Worker';

            $files = $submission['task_submission_files'] ?? [];
            ?>

            <hr>

            <h3>
                <?php echo htmlspecialchars(
                    $task['title'] ?? 'Unknown Task',
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>
            </h3>

            <p>
                <strong>Submitted By:</strong>

                <?php echo htmlspecialchars(
                    $worker_name,
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>
            </p>

            <p>
                <strong>Submitted At:</strong>

                <?php echo htmlspecialchars(date(
                    'F j, Y g:i A',
                    strtotime($submission['submitted_at'])
                )); ?>
            </p>

            <p>
                <strong>Worker's Message:</strong>
            </p>

            <p>
                <?php echo nl2br(htmlspecialchars(
                    $submission['message'],
                    ENT_QUOTES,
                    'UTF-8'
                )); ?>
            </p>

            <p>
                <strong>Screenshots:</strong>
                <?php echo count($files); ?> uploaded
            </p>

            <a href="review-submission.php?id=<?php echo urlencode(
                                                    $submission['id']
                                                ); ?>">
                Review Submission
            </a>

        <?php endforeach; ?>

    <?php endif; ?>

    <hr>

    <!-- Assigned Tasks -->

    <h2>Assigned Tasks</h2>

    <?php if (empty($tasks)): ?>

        <p>You haven't assigned any tasks yet.</p>

    <?php else: ?>

        <?php foreach ($tasks as $task): ?>

            <hr>

            <h3>
                <?php echo htmlspecialchars(
                    $task['title'],
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>
            </h3>

            <p>
                <strong>Assigned To:</strong>

                <?php echo htmlspecialchars(
                    $workers[$task['worker_id']] ?? 'Unknown Worker',
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>
            </p>

            <p>
                <strong>Description:</strong>

                <?php echo htmlspecialchars(
                    $task['description'] ?? 'No description provided.',
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>
            </p>

            <p>
                <strong>Priority:</strong>

                <?php echo htmlspecialchars(
                    ucfirst($task['priority']),
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>
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
                    ucwords(str_replace('_', ' ', $task['status'])),
                    ENT_QUOTES,
                    'UTF-8'
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