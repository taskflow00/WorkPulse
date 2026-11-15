<?php
$required_role = 'employer';
require_once '../../includes/auth.php';

$employer_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$page_title = 'Assign Task';
$active_page = 'tasks';
$page_styles = ['/assets/css/assign-task.css'];

$message = '';
$message_type = '';

// Generate CSRF token.
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

// Show success message after redirect.
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $message = 'Task assigned successfully!';
    $message_type = 'success';
}

// Common headers for authenticated requests.
$headers =
    "apikey: " . SUPABASE_KEY . "\r\n" .
    "Authorization: Bearer " . $access_token . "\r\n";

// Retrieve this employer's active employments.
$url = SUPABASE_URL .
    '/rest/v1/employments?select=id,worker_id,hourly_rate,work_schedule' .
    '&employer_id=eq.' . urlencode($employer_id) .
    '&status=eq.active&order=started_at.desc';

$options = [
    'http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true,
        'timeout' => 15
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($url, false, $context);
$result = json_decode($response ?: '', true);

$employments = (
    is_array($result) && isset($result[0])
) ? $result : [];

// Retrieve worker names.
$workers = [];

if (!empty($employments)) {

    $worker_ids = array_unique(array_column($employments, 'worker_id'));
    $ids = implode(',', array_map('urlencode', $worker_ids));

    $profile_url = SUPABASE_URL .
        '/rest/v1/profiles?select=id,full_name&id=in.(' . $ids . ')';

    $context = stream_context_create($options);
    $profile_response = @file_get_contents($profile_url, false, $context);
    $profiles = json_decode($profile_response ?: '', true);

    if (is_array($profiles)) {
        foreach ($profiles as $profile) {
            $workers[$profile['id']] = $profile['full_name'];
        }
    }
}

// Process task assignment.
if (isset($_POST['assign_task'])) {

    $submitted_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf_token, $submitted_token)) {

        $message = 'Invalid request. Please refresh the page and try again.';
    } else {

        $employment_id = trim($_POST['employment_id'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = $_POST['priority'] ?? 'medium';
        $due_date = $_POST['due_date'] ?? '';
        $estimated_hours = trim($_POST['estimated_hours'] ?? '');

        // Find the selected active employment.
        $selected_employment = null;

        foreach ($employments as $employment) {
            if ($employment['id'] === $employment_id) {
                $selected_employment = $employment;
                break;
            }
        }

        // Validate the submitted information.
        if ($title === '') {

            $message = 'Please enter a task title.';
        } elseif (strlen($title) > 150) {

            $message = 'Task title must not exceed 150 characters.';
        } elseif (!$selected_employment) {

            $message = 'Please select one of your active employees.';
        } elseif (!in_array($priority, ['low', 'medium', 'high'], true)) {

            $message = 'Invalid task priority.';
        } elseif (
            $estimated_hours !== '' &&
            (
                !is_numeric($estimated_hours) ||
                (float)$estimated_hours <= 0
            )
        ) {

            $message = 'Estimated hours must be greater than zero.';
        } elseif (
            $due_date !== '' &&
            (
                !DateTime::createFromFormat('Y-m-d', $due_date) ||
                DateTime::createFromFormat('Y-m-d', $due_date)->format('Y-m-d') !== $due_date
            )
        ) {

            $message = 'Please enter a valid deadline.';
        } else {

            // Prepare task information.
            $data = [
                'employment_id' => $employment_id,
                'employer_id' => $employer_id,
                'worker_id' => $selected_employment['worker_id'],
                'title' => $title,
                'description' => $description,
                'priority' => $priority,
                'due_date' => $due_date !== '' ? $due_date : null,
                'estimated_hours' => $estimated_hours !== ''
                    ? (float)$estimated_hours
                    : null
            ];

            $json_data = json_encode($data);

            if ($json_data === false) {

                $message = 'Unable to prepare task information.';
            } else {

                // Send the task to Supabase.
                $task_url = SUPABASE_URL . '/rest/v1/tasks';

                $options = [
                    'http' => [
                        'method' => 'POST',
                        'header' =>
                        $headers .
                            "Content-Type: application/json\r\n" .
                            "Prefer: return=representation\r\n",
                        'content' => $json_data,
                        'ignore_errors' => true,
                        'timeout' => 15
                    ]
                ];

                $context = stream_context_create($options);
                $task_response = @file_get_contents($task_url, false, $context);

                // Get the HTTP response status without deprecated variables.
                $status_code = 0;
                $response_headers = http_get_last_response_headers();

                if (is_array($response_headers) && isset($response_headers[0])) {
                    preg_match('/\s(\d{3})\s/', $response_headers[0], $matches);
                    $status_code = isset($matches[1]) ? (int)$matches[1] : 0;
                }

                $task_result = json_decode($task_response ?: '', true);

                if (
                    $status_code === 201 &&
                    is_array($task_result) &&
                    isset($task_result[0]['id'])
                ) {

                    header('Location: assign-task.php?success=1');
                    exit;
                } elseif ($status_code === 401 || $status_code === 403) {

                    $message = 'You are not authorized to assign this task.';
                } elseif ($status_code === 400 || $status_code === 422) {

                    $message = 'Some task information is invalid. Please review your entries.';
                } else {

                    $message = 'Unable to assign task. Please try again.';
                }
            }
        }
    }

    if ($message !== '' && $message_type === '') {
        $message_type = 'error';
    }
}

require_once '../../includes/header.php';
?>

<div class="assign-task-page">

    <div class="assign-task-heading">
        <div>
            <span class="assign-task-eyebrow">WORK MANAGEMENT</span>
            <h1>Assign a Task</h1>
            <p>Create a task and assign it to one of your active employees.</p>
        </div>

        <a href="tasks.php" class="assign-task-back">
            <i class="bi bi-arrow-left"></i>
            Back to Tasks
        </a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="assign-task-alert <?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
            <i class="bi <?php echo $message_type === 'success' ? 'bi-check-circle' : 'bi-exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($employments)): ?>

        <div class="assign-task-empty">
            <div class="assign-task-empty-icon">
                <i class="bi bi-people"></i>
            </div>

            <h2>No Active Employees</h2>
            <p>You need an active employee before you can assign tasks.</p>

            <a href="employees.php">
                View Employees
                <i class="bi bi-arrow-right"></i>
            </a>
        </div>

    <?php else: ?>

        <form method="POST" action="assign-task.php" class="assign-task-layout">

            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div class="assign-task-main">

                <section class="assign-task-card">

                    <div class="assign-task-card-heading">
                        <div class="assign-task-icon">
                            <i class="bi bi-person-check"></i>
                        </div>

                        <div>
                            <h2>Task Assignment</h2>
                            <p>Choose which employee will handle this task.</p>
                        </div>
                    </div>

                    <div class="assign-task-field">
                        <label for="employment_id">Assign To <span>*</span></label>

                        <select name="employment_id" id="employment_id" required>
                            <option value="">Select an employee</option>

                            <?php foreach ($employments as $employment): ?>
                                <option
                                    value="<?php echo htmlspecialchars($employment['id']); ?>"
                                    <?php echo ($_POST['employment_id'] ?? '') === $employment['id'] ? 'selected' : ''; ?>>

                                    <?php echo htmlspecialchars(
                                        $workers[$employment['worker_id']] ?? 'Unknown Worker'
                                    ); ?>

                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                </section>

                <section class="assign-task-card">

                    <div class="assign-task-card-heading">
                        <div class="assign-task-icon">
                            <i class="bi bi-card-text"></i>
                        </div>

                        <div>
                            <h2>Task Details</h2>
                            <p>Describe the work that needs to be completed.</p>
                        </div>
                    </div>

                    <div class="assign-task-field">
                        <label for="title">Task Title <span>*</span></label>

                        <input
                            type="text"
                            name="title"
                            id="title"
                            maxlength="150"
                            value="<?php echo htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="e.g. Build the homepage layout"
                            required>
                    </div>

                    <div class="assign-task-field">
                        <label for="description">Description</label>

                        <textarea
                            name="description"
                            id="description"
                            rows="6"
                            placeholder="Explain the task, expected output, and other instructions..."><?php echo htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>

                </section>

                <section class="assign-task-card">

                    <div class="assign-task-card-heading">
                        <div class="assign-task-icon">
                            <i class="bi bi-calendar-check"></i>
                        </div>

                        <div>
                            <h2>Schedule and Priority</h2>
                            <p>Set the task's urgency and expected completion date.</p>
                        </div>
                    </div>

                    <div class="assign-task-two-columns">

                        <div class="assign-task-field">
                            <label for="priority">Priority <span>*</span></label>

                            <select name="priority" id="priority" required>
                                <option value="low" <?php echo ($_POST['priority'] ?? 'medium') === 'low' ? 'selected' : ''; ?>>
                                    Low
                                </option>

                                <option value="medium" <?php echo ($_POST['priority'] ?? 'medium') === 'medium' ? 'selected' : ''; ?>>
                                    Medium
                                </option>

                                <option value="high" <?php echo ($_POST['priority'] ?? 'medium') === 'high' ? 'selected' : ''; ?>>
                                    High
                                </option>
                            </select>
                        </div>

                        <div class="assign-task-field">
                            <label for="due_date">Deadline</label>

                            <input
                                type="date"
                                name="due_date"
                                id="due_date"
                                value="<?php echo htmlspecialchars($_POST['due_date'] ?? ''); ?>"
                                min="<?php echo date('Y-m-d'); ?>">
                        </div>

                    </div>

                    <div class="assign-task-field">
                        <label for="estimated_hours">Estimated Hours</label>

                        <input
                            type="number"
                            name="estimated_hours"
                            id="estimated_hours"
                            min="0.01"
                            step="0.01"
                            value="<?php echo htmlspecialchars($_POST['estimated_hours'] ?? ''); ?>"
                            placeholder="e.g. 8">

                        <small>This is optional and represents the expected time needed to complete the task.</small>
                    </div>

                </section>

            </div>

            <aside class="assign-task-sidebar">

                <div class="assign-task-card assign-task-submit-card">
                    <div class="assign-task-submit-icon">
                        <i class="bi bi-send"></i>
                    </div>

                    <h2>Ready to Assign?</h2>
                    <p>Review the task details before assigning it to your employee.</p>

                    <button type="submit" name="assign_task" class="assign-task-submit">
                        <i class="bi bi-check-lg"></i>
                        Assign Task
                    </button>

                    <a href="tasks.php" class="assign-task-cancel">
                        Cancel
                    </a>
                </div>

                <div class="assign-task-card assign-task-tip">
                    <h3><i class="bi bi-lightbulb"></i> Task Tips</h3>

                    <ul>
                        <li>Give your task a clear and specific title.</li>
                        <li>Explain what the expected output should be.</li>
                        <li>Set realistic deadlines.</li>
                        <li>Choose a priority based on urgency.</li>
                    </ul>
                </div>

            </aside>

        </form>

    <?php endif; ?>

</div>

<?php require_once '../../includes/footer.php'; ?>