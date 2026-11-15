<?php
$required_role = 'worker';
$page_title = 'My Tasks';
$active_page = 'tasks';

require_once '../../includes/auth.php';

date_default_timezone_set('Asia/Manila');

$worker_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$message = '';

// Common headers for Supabase requests.
$headers =
    "apikey: " . SUPABASE_KEY . "\r\n" .
    "Authorization: Bearer " . $access_token . "\r\n";

// 1. Process task status updates.
if (isset($_POST['update_task'])) {

    $task_id = $_POST['task_id'] ?? '';
    $new_status = $_POST['new_status'] ?? '';

    // Workers can only start tasks directly.
    // Completing a task requires employer approval.
    if (
        !preg_match('/^[0-9a-fA-F-]{36}$/', $task_id) ||
        $new_status !== 'in_progress'
    ) {

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

// 2. Display success messages.
if (isset($_GET['success'])) {

    if ($_GET['success'] === 'started') {

        $message = 'Task started successfully!';
    } elseif ($_GET['success'] === 'submitted') {

        $message = 'Your work was submitted successfully! Awaiting employer review.';
    }
}

// 3. Retrieve this worker's assigned tasks.
$url = SUPABASE_URL .
    '/rest/v1/tasks?select=*' .
    '&worker_id=eq.' . urlencode($worker_id) .
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

// 4. Retrieve this worker's task submissions.
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
$response = @file_get_contents($url, false, $context);

$result = json_decode($response ?: '', true);
$submissions = is_array($result) ? $result : [];

// 5. Get the latest submission for each task.
$latest_submissions = [];

foreach ($submissions as $submission) {

    $submission_task_id = $submission['task_id'];

    // Results are newest first, so keep only the first one.
    if (!isset($latest_submissions[$submission_task_id])) {
        $latest_submissions[$submission_task_id] = $submission;
    }
}

// 6. Calculate task counts.
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

require_once '../../includes/header.php';
?>

<div class="wp-page">

    <!-- Page Heading -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">

        <div>
            <h1 class="wp-page-title">My Tasks</h1>
            <p class="wp-page-description mb-0">
                View your assigned tasks, track their progress, and submit completed work.
            </p>
        </div>

    </div>

    <!-- Success / Error Message -->
    <?php if ($message !== ''): ?>

        <div class="wp-alert <?php echo (
                                    isset($_GET['success']) &&
                                    in_array($_GET['success'], ['started', 'submitted'], true)
                                ) ? 'wp-alert-success' : 'wp-alert-danger'; ?>" role="alert">

            <i class="bi <?php echo (
                                isset($_GET['success']) &&
                                in_array($_GET['success'], ['started', 'submitted'], true)
                            ) ? 'bi-check-circle' : 'bi-exclamation-circle'; ?>"></i>

            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>

        </div>

    <?php endif; ?>

    <!-- Task Statistics -->
    <div class="wp-dashboard-stats mb-4">

        <div class="wp-stat-card">
            <div class="wp-stat-top">
                <span>Total Tasks</span>
                <div class="wp-stat-icon rose">
                    <i class="bi bi-clipboard-check"></i>
                </div>
            </div>

            <h2><?php echo $total_tasks; ?></h2>
            <small>All assigned tasks</small>
        </div>

        <div class="wp-stat-card">
            <div class="wp-stat-top">
                <span>Pending</span>
                <div class="wp-stat-icon yellow">
                    <i class="bi bi-clock"></i>
                </div>
            </div>

            <h2><?php echo $pending_tasks; ?></h2>
            <small>Waiting to be started</small>
        </div>

        <div class="wp-stat-card">
            <div class="wp-stat-top">
                <span>In Progress</span>
                <div class="wp-stat-icon blue">
                    <i class="bi bi-arrow-repeat"></i>
                </div>
            </div>

            <h2><?php echo $in_progress_tasks; ?></h2>
            <small>Currently being worked on</small>
        </div>

        <div class="wp-stat-card">
            <div class="wp-stat-top">
                <span>Completed</span>
                <div class="wp-stat-icon green">
                    <i class="bi bi-check-circle"></i>
                </div>
            </div>

            <h2><?php echo $completed_tasks; ?></h2>
            <small>Approved and completed</small>
        </div>

    </div>

    <!-- Assigned Tasks -->
    <div class="mb-3">
        <h2 class="h5 fw-bold mb-1">Assigned Tasks</h2>
        <p class="text-muted mb-0">
            Your current and previous assigned work.
        </p>
    </div>

    <?php if (empty($tasks)): ?>

        <!-- Empty State -->
        <div class="wp-card text-center py-5">

            <div class="mb-3">
                <i class="bi bi-clipboard-x display-4 text-muted"></i>
            </div>

            <h3 class="h5 fw-bold">No Assigned Tasks Yet</h3>

            <p class="text-muted mb-0">
                You don't have any tasks assigned to you right now.
                Check back later for new work.
            </p>

        </div>

    <?php else: ?>

        <div class="row g-3">

            <?php foreach ($tasks as $task): ?>

                <?php
                // Find the latest submission for this task.
                $latest_submission = $latest_submissions[$task['id']] ?? null;

                // Prepare task status label and badge.
                $task_status = $task['status'] ?? 'pending';

                $status_label = ucwords(
                    str_replace('_', ' ', $task_status)
                );

                $status_badge = 'bg-secondary';

                if ($task_status === 'pending') {
                    $status_badge = 'bg-warning text-dark';
                } elseif ($task_status === 'in_progress') {
                    $status_badge = 'bg-primary';
                } elseif ($task_status === 'completed') {
                    $status_badge = 'bg-success';
                } elseif ($task_status === 'cancelled') {
                    $status_badge = 'bg-danger';
                }

                // Prepare priority label.
                $priority = ucfirst($task['priority'] ?? 'Not specified');

                $priority_badge = 'bg-secondary';

                if (strtolower($priority) === 'high') {
                    $priority_badge = 'bg-danger';
                } elseif (strtolower($priority) === 'medium') {
                    $priority_badge = 'bg-warning text-dark';
                } elseif (strtolower($priority) === 'low') {
                    $priority_badge = 'bg-success';
                }

                // Check whether the task deadline has passed.
                $is_overdue = (
                    !empty($task['due_date']) &&
                    strtotime($task['due_date']) < strtotime(date('Y-m-d')) &&
                    !in_array($task_status, ['completed', 'cancelled'], true)
                );
                ?>

                <div class="col-12">

                    <div class="wp-card">

                        <!-- Task Header -->
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">

                            <div>
                                <h3 class="h5 fw-bold mb-2">
                                    <?php echo htmlspecialchars(
                                        $task['title'] ?? 'Untitled Task',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ); ?>
                                </h3>

                                <div class="d-flex flex-wrap gap-2">

                                    <span class="badge <?php echo $status_badge; ?>">
                                        <?php echo htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8'); ?>
                                    </span>

                                    <span class="badge <?php echo $priority_badge; ?>">
                                        <?php echo htmlspecialchars($priority, ENT_QUOTES, 'UTF-8'); ?> Priority
                                    </span>

                                    <?php if ($is_overdue): ?>
                                        <span class="badge bg-danger">
                                            Overdue
                                        </span>
                                    <?php endif; ?>

                                </div>
                            </div>

                            <div class="text-muted small">
                                <?php if (!empty($task['created_at'])): ?>
                                    <i class="bi bi-calendar3 me-1"></i>
                                    Assigned
                                    <?php echo htmlspecialchars(
                                        date('M j, Y', strtotime($task['created_at'])),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ); ?>
                                <?php endif; ?>
                            </div>

                        </div>

                        <!-- Task Description -->
                        <p class="text-muted mb-4">
                            <?php echo nl2br(htmlspecialchars(
                                $task['description'] ?? 'No description provided.',
                                ENT_QUOTES,
                                'UTF-8'
                            )); ?>
                        </p>

                        <!-- Task Details -->
                        <div class="row g-3 mb-4">

                            <div class="col-6 col-md-4">
                                <small class="text-muted d-block mb-1">
                                    <i class="bi bi-calendar-event me-1"></i>
                                    Deadline
                                </small>

                                <strong>
                                    <?php
                                    echo !empty($task['due_date'])
                                        ? htmlspecialchars(
                                            date('F j, Y', strtotime($task['due_date'])),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        )
                                        : 'No deadline';
                                    ?>
                                </strong>
                            </div>

                            <div class="col-6 col-md-4">
                                <small class="text-muted d-block mb-1">
                                    <i class="bi bi-clock me-1"></i>
                                    Estimated Hours
                                </small>

                                <strong>
                                    <?php
                                    echo isset($task['estimated_hours'])
                                        ? htmlspecialchars(
                                            (string)$task['estimated_hours'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) . ' hours'
                                        : 'Not specified';
                                    ?>
                                </strong>
                            </div>

                            <div class="col-6 col-md-4">
                                <small class="text-muted d-block mb-1">
                                    <i class="bi bi-flag me-1"></i>
                                    Task Status
                                </small>

                                <strong>
                                    <?php echo htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8'); ?>
                                </strong>
                            </div>

                        </div>

                        <hr>

                        <!-- Task Actions -->
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">

                            <div class="flex-grow-1">

                                <?php if ($task_status === 'pending'): ?>

                                    <span class="text-muted small">
                                        This task is waiting for you to start.
                                    </span>

                                <?php elseif ($task_status === 'in_progress'): ?>

                                    <?php if (
                                        $latest_submission &&
                                        $latest_submission['status'] === 'pending_review'
                                    ): ?>

                                        <div class="text-primary small">
                                            <i class="bi bi-hourglass-split me-1"></i>
                                            Your submission is awaiting employer review.

                                            <?php if (!empty($latest_submission['submitted_at'])): ?>
                                                <br>
                                                Submitted on
                                                <?php echo htmlspecialchars(
                                                    date(
                                                        'M j, Y g:i A',
                                                        strtotime($latest_submission['submitted_at'])
                                                    ),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>
                                            <?php endif; ?>
                                        </div>

                                    <?php elseif (
                                        $latest_submission &&
                                        $latest_submission['status'] === 'revision_requested'
                                    ): ?>

                                        <div class="text-danger small">
                                            <i class="bi bi-arrow-repeat me-1"></i>
                                            Your employer requested revisions.
                                        </div>

                                    <?php else: ?>

                                        <span class="text-muted small">
                                            This task is currently in progress.
                                        </span>

                                    <?php endif; ?>

                                <?php elseif ($task_status === 'completed'): ?>

                                    <span class="text-success small">
                                        <i class="bi bi-check-circle me-1"></i>
                                        Task completed and approved!
                                    </span>

                                <?php elseif ($task_status === 'cancelled'): ?>

                                    <span class="text-danger small">
                                        <i class="bi bi-x-circle me-1"></i>
                                        This task has been cancelled.
                                    </span>

                                <?php endif; ?>

                            </div>

                            <div class="d-flex flex-wrap gap-2">

                                <?php if ($task_status === 'pending'): ?>

                                    <!-- Worker can start a pending task. -->
                                    <form method="POST" action="tasks.php">

                                        <input
                                            type="hidden"
                                            name="task_id"
                                            value="<?php echo htmlspecialchars($task['id'], ENT_QUOTES, 'UTF-8'); ?>">

                                        <input
                                            type="hidden"
                                            name="new_status"
                                            value="in_progress">

                                        <button
                                            type="submit"
                                            name="update_task"
                                            value="1"
                                            class="wp-btn wp-btn-primary">

                                            <i class="bi bi-play-fill"></i>
                                            Start Task

                                        </button>

                                    </form>

                                <?php elseif (
                                    $task_status === 'in_progress' &&
                                    (
                                        !$latest_submission ||
                                        $latest_submission['status'] !== 'pending_review'
                                    )
                                ): ?>

                                    <!-- Submit or resubmit completed work. -->
                                    <a
                                        href="submit-task.php?task_id=<?php echo urlencode($task['id']); ?>"
                                        class="wp-btn wp-btn-success">

                                        <i class="bi bi-send"></i>

                                        <?php echo (
                                            $latest_submission &&
                                            $latest_submission['status'] === 'revision_requested'
                                        ) ? 'Resubmit Work' : 'Submit Completed Work'; ?>

                                    </a>

                                <?php endif; ?>

                            </div>

                        </div>

                        <!-- Employer Feedback -->
                        <?php if (
                            $latest_submission &&
                            $latest_submission['status'] === 'revision_requested' &&
                            !empty($latest_submission['employer_feedback'])
                        ): ?>

                            <div class="wp-alert wp-alert-danger mt-4 mb-0">

                                <div>
                                    <strong>
                                        <i class="bi bi-chat-left-text me-1"></i>
                                        Employer Feedback
                                    </strong>

                                    <p class="mb-0 mt-2">
                                        <?php echo nl2br(htmlspecialchars(
                                            $latest_submission['employer_feedback'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        )); ?>
                                    </p>
                                </div>

                            </div>

                        <?php endif; ?>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

    <!-- Back to Dashboard -->
    <div class="mt-4">
        <a href="dashboard.php" class="wp-btn wp-btn-outline">
            <i class="bi bi-arrow-left"></i>
            Back to Dashboard
        </a>
    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>