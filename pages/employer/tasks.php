<?php

// ==========================================
// EMPLOYER TASK MANAGEMENT
// ==========================================

$required_role = 'employer';
$page_title = 'Task Management';
$active_page = 'tasks';
$page_styles = ['/assets/css/employer-tasks.css'];

require_once '../../includes/auth.php';

$employer_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];


// ==========================================
// COMMON AUTHENTICATED REQUEST HEADERS
// ==========================================

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


// ==========================================
// 1. GET EMPLOYER'S ASSIGNED TASKS
// ==========================================

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


// ==========================================
// 2. GET PENDING TASK SUBMISSIONS
// ==========================================

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


// ==========================================
// 3. GET WORKER NAMES
// ==========================================

$workers = [];
$worker_ids = [];

foreach ($tasks as $task) {

    if (!empty($task['worker_id'])) {
        $worker_ids[] = $task['worker_id'];
    }
}

foreach ($pending_reviews as $submission) {

    if (!empty($submission['worker_id'])) {
        $worker_ids[] = $submission['worker_id'];
    }
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

    if (is_array($profiles) && array_is_list($profiles)) {

        foreach ($profiles as $profile) {
            $workers[$profile['id']] = $profile['full_name'];
        }
    }
}


// ==========================================
// 4. COUNT TASK STATUSES
// ==========================================

$total_tasks = count($tasks);
$pending_tasks = 0;
$in_progress_tasks = 0;
$completed_tasks = 0;

foreach ($tasks as $task) {

    $status = $task['status'] ?? 'pending';

    if ($status === 'pending') {
        $pending_tasks++;
    } elseif ($status === 'in_progress') {
        $in_progress_tasks++;
    } elseif ($status === 'completed') {
        $completed_tasks++;
    }
}

$pending_review_count = count($pending_reviews);


// ==========================================
// 5. LOAD SHARED DASHBOARD
// ==========================================

require_once '../../includes/header.php';

?>

<div class="wp-page employer-tasks-page">

    <!-- ======================================
         PAGE HEADING
    ======================================= -->

    <section class="employer-tasks-heading">

        <div>
            <h1 class="wp-page-title">Task Management</h1>

            <p class="wp-page-description">
                Manage assigned tasks, monitor employee progress, and review submitted work.
            </p>
        </div>

        <a href="assign-task.php" class="wp-btn wp-btn-primary">
            <i class="bi bi-plus-lg"></i>
            Assign New Task
        </a>

    </section>


    <!-- ======================================
         TASK OVERVIEW
    ======================================= -->

    <section class="employer-task-stats">

        <div class="employer-task-stat">

            <div class="employer-task-stat-icon stat-rose">
                <i class="bi bi-list-task"></i>
            </div>

            <div>
                <span>Total Tasks</span>
                <h2><?= $total_tasks ?></h2>
                <small>All assigned tasks</small>
            </div>

        </div>


        <div class="employer-task-stat">

            <div class="employer-task-stat-icon stat-yellow">
                <i class="bi bi-hourglass-split"></i>
            </div>

            <div>
                <span>Pending</span>
                <h2><?= $pending_tasks ?></h2>
                <small>Waiting to be started</small>
            </div>

        </div>


        <div class="employer-task-stat">

            <div class="employer-task-stat-icon stat-blue">
                <i class="bi bi-arrow-repeat"></i>
            </div>

            <div>
                <span>In Progress</span>
                <h2><?= $in_progress_tasks ?></h2>
                <small>Currently being worked on</small>
            </div>

        </div>


        <div class="employer-task-stat">

            <div class="employer-task-stat-icon stat-green">
                <i class="bi bi-check-circle"></i>
            </div>

            <div>
                <span>Completed</span>
                <h2><?= $completed_tasks ?></h2>
                <small>Successfully completed</small>
            </div>

        </div>

    </section>


    <!-- ======================================
         PENDING SUBMISSION REVIEWS
    ======================================= -->

    <section class="wp-panel employer-task-section">

        <div class="employer-section-heading">

            <div class="employer-section-title-group">

                <div class="employer-section-icon">
                    <i class="bi bi-clipboard-check"></i>
                </div>

                <div>
                    <h2 class="wp-panel-title">Pending Reviews</h2>
                    <p class="wp-panel-description">
                        Review work submitted by your employees.
                    </p>
                </div>

            </div>

            <span class="employer-review-count">
                <?= $pending_review_count ?> awaiting review
            </span>

        </div>


        <?php if (empty($pending_reviews)): ?>

            <div class="employer-task-empty">

                <div class="employer-empty-icon">
                    <i class="bi bi-check2-circle"></i>
                </div>

                <h3>You're all caught up!</h3>

                <p>
                    There are no task submissions waiting for your review.
                </p>

            </div>

        <?php else: ?>

            <div class="employer-review-list">

                <?php foreach ($pending_reviews as $submission): ?>

                    <?php

                    $task = $task_lookup[$submission['task_id']] ?? null;

                    $worker_name = $workers[$submission['worker_id']]
                        ?? 'Unknown Worker';

                    $files = $submission['task_submission_files'] ?? [];

                    ?>

                    <article class="employer-review-card">

                        <div class="employer-review-top">

                            <div class="employer-review-task-icon">
                                <i class="bi bi-file-earmark-check"></i>
                            </div>

                            <div class="employer-review-info">

                                <h3>
                                    <?= htmlspecialchars(
                                        $task['title'] ?? 'Unknown Task',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </h3>

                                <span>
                                    <i class="bi bi-person"></i>

                                    <?= htmlspecialchars(
                                        $worker_name,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>

                            </div>

                            <span class="employer-status status-review">
                                Pending Review
                            </span>

                        </div>


                        <div class="employer-review-details">

                            <div>
                                <span class="employer-detail-label">
                                    Submitted At
                                </span>

                                <strong>
                                    <?= !empty($submission['submitted_at'])
                                        ? htmlspecialchars(date(
                                            'M d, Y · h:i A',
                                            strtotime($submission['submitted_at'])
                                        ), ENT_QUOTES, 'UTF-8')
                                        : 'Not available' ?>
                                </strong>
                            </div>

                            <div>
                                <span class="employer-detail-label">
                                    Uploaded Files
                                </span>

                                <strong>
                                    <i class="bi bi-paperclip"></i>
                                    <?= count($files) ?> file(s)
                                </strong>
                            </div>

                        </div>


                        <div class="employer-review-message">

                            <span class="employer-detail-label">
                                Worker's Message
                            </span>

                            <p>
                                <?= !empty($submission['message'])
                                    ? nl2br(htmlspecialchars(
                                        $submission['message'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ))
                                    : 'No message provided.' ?>
                            </p>

                        </div>


                        <div class="employer-review-actions">

                            <a
                                href="review-submission.php?id=<?= urlencode($submission['id']) ?>"
                                class="wp-btn wp-btn-primary wp-btn-sm">

                                <i class="bi bi-eye"></i>
                                Review Submission

                            </a>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>


    <!-- ======================================
         ASSIGNED TASKS
    ======================================= -->

    <section class="wp-panel employer-task-section">

        <div class="employer-section-heading">

            <div class="employer-section-title-group">

                <div class="employer-section-icon">
                    <i class="bi bi-kanban"></i>
                </div>

                <div>
                    <h2 class="wp-panel-title">Assigned Tasks</h2>
                    <p class="wp-panel-description">
                        View and monitor the tasks you've assigned to your employees.
                    </p>
                </div>

            </div>

            <span class="employer-task-count">
                <?= $total_tasks ?> task(s)
            </span>

        </div>


        <?php if (empty($tasks)): ?>

            <div class="employer-task-empty">

                <div class="employer-empty-icon">
                    <i class="bi bi-clipboard-plus"></i>
                </div>

                <h3>No tasks assigned yet</h3>

                <p>
                    Start by assigning a task to one of your employees.
                </p>

                <a href="assign-task.php" class="wp-btn wp-btn-primary">
                    <i class="bi bi-plus-lg"></i>
                    Assign Your First Task
                </a>

            </div>

        <?php else: ?>

            <div class="employer-assigned-grid">

                <?php foreach ($tasks as $task): ?>

                    <?php

                    $status = $task['status'] ?? 'pending';

                    $status_class = 'status-pending';

                    if ($status === 'completed') {
                        $status_class = 'status-completed';
                    } elseif ($status === 'in_progress') {
                        $status_class = 'status-progress';
                    }

                    $worker_name = $workers[$task['worker_id'] ?? '']
                        ?? 'Unknown Worker';

                    $priority = $task['priority'] ?? 'Normal';

                    $priority_class = 'priority-normal';

                    if (strtolower($priority) === 'high') {
                        $priority_class = 'priority-high';
                    } elseif (strtolower($priority) === 'medium') {
                        $priority_class = 'priority-medium';
                    } elseif (strtolower($priority) === 'low') {
                        $priority_class = 'priority-low';
                    }

                    ?>

                    <article class="employer-assigned-card">

                        <!-- Card heading -->
                        <div class="employer-assigned-top">

                            <div class="employer-task-heading">

                                <span class="employer-task-card-label">
                                    ASSIGNED TASK
                                </span>

                                <h3>
                                    <?= htmlspecialchars(
                                        $task['title'] ?? 'Untitled Task',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </h3>

                            </div>

                            <span class="employer-status <?= $status_class ?>">
                                <?= htmlspecialchars(
                                    ucwords(str_replace('_', ' ', $status)),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>

                        </div>


                        <!-- Description -->
                        <p class="employer-assigned-description">
                            <?= htmlspecialchars(
                                $task['description'] ?? 'No description provided.',
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </p>


                        <!-- Worker -->
                        <div class="employer-assigned-worker">

                            <div class="employer-worker-avatar">
                                <i class="bi bi-person-fill"></i>
                            </div>

                            <div>
                                <span>Assigned to</span>

                                <strong>
                                    <?= htmlspecialchars(
                                        $worker_name,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </strong>
                            </div>

                        </div>


                        <!-- Task metadata -->
                        <div class="employer-assigned-meta">

                            <div class="employer-meta-item">

                                <span>
                                    <i class="bi bi-flag"></i>
                                    Priority
                                </span>

                                <strong class="<?= $priority_class ?>">
                                    <?= htmlspecialchars(
                                        ucfirst($priority),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </strong>

                            </div>


                            <div class="employer-meta-item">

                                <span>
                                    <i class="bi bi-calendar-event"></i>
                                    Deadline
                                </span>

                                <strong>
                                    <?= !empty($task['due_date'])
                                        ? htmlspecialchars(date(
                                            'M d, Y',
                                            strtotime($task['due_date'])
                                        ), ENT_QUOTES, 'UTF-8')
                                        : 'No deadline' ?>
                                </strong>

                            </div>


                            <div class="employer-meta-item">

                                <span>
                                    <i class="bi bi-clock"></i>
                                    Estimated Hours
                                </span>

                                <strong>
                                    <?= isset($task['estimated_hours'])
                                        ? htmlspecialchars(
                                            (string) $task['estimated_hours'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) . ' hours'
                                        : 'Not specified' ?>
                                </strong>

                            </div>


                            <div class="employer-meta-item">

                                <span>
                                    <i class="bi bi-calendar-plus"></i>
                                    Assigned Date
                                </span>

                                <strong>
                                    <?= !empty($task['created_at'])
                                        ? htmlspecialchars(date(
                                            'M d, Y',
                                            strtotime($task['created_at'])
                                        ), ENT_QUOTES, 'UTF-8')
                                        : 'Not available' ?>
                                </strong>

                            </div>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>

</div>

<?php require_once '../../includes/footer.php'; ?>