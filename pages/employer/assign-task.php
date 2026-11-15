<?php
$required_role = 'employer';
require_once '../../includes/auth.php';

$employer_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$message = '';

// Show success message after redirect.
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $message = 'Task assigned successfully!';
}

// Common headers for authenticated requests.
$headers =
    "apikey: " . SUPABASE_KEY . "\r\n" .
    "Authorization: Bearer " . $access_token . "\r\n";

// 1. Retrieve this employer's active employment records.
$url = SUPABASE_URL .
    '/rest/v1/employments?select=id,worker_id,hourly_rate,work_schedule' .
    '&employer_id=eq.' . urlencode($employer_id) .
    '&status=eq.active&order=started_at.desc';

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

$employments = (
    is_array($result) && isset($result[0])
) ? $result : [];

// 2. Retrieve worker names.
$workers = [];

if (!empty($employments)) {

    $worker_ids = array_unique(
        array_column($employments, 'worker_id')
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

// 3. Process task assignment.
if (isset($_POST['assign_task'])) {

    $employment_id = trim($_POST['employment_id'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'medium';
    $due_date = $_POST['due_date'] ?? '';
    $estimated_hours = $_POST['estimated_hours'] ?? '';

    // Find the selected active employment record.
    $selected_employment = null;

    foreach ($employments as $employment) {
        if ($employment['id'] === $employment_id) {
            $selected_employment = $employment;
            break;
        }
    }

    if ($title === '') {
        $message = 'Please enter a task title.';
    } elseif (!$selected_employment) {
        $message = 'Please select one of your active employees.';
    } elseif (!in_array($priority, ['low', 'medium', 'high'], true)) {
        $message = 'Invalid task priority.';
    } elseif (
        $estimated_hours !== '' &&
        (!is_numeric($estimated_hours) || $estimated_hours <= 0)
    ) {
        $message = 'Estimated hours must be greater than zero.';
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

        $task_url = SUPABASE_URL . '/rest/v1/tasks';

        $options = [
            'http' => [
                'method' => 'POST',
                'header' =>
                $headers .
                    "Content-Type: application/json\r\n" .
                    "Prefer: return=representation\r\n",
                'content' => json_encode($data),
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $task_response = @file_get_contents(
            $task_url,
            false,
            $context
        );

        // Get the HTTP response status.
        $response_headers = $http_response_header ?? [];
        $matches = [];

        preg_match(
            '/\s(\d{3})\s/',
            $response_headers[0] ?? '',
            $matches
        );

        $status_code = (int)($matches[1] ?? 0);
        $task_result = json_decode($task_response ?: '', true);

        if ($status_code === 201 && isset($task_result[0]['id'])) {

            // Redirect to clear the submitted form.
            header('Location: assign-task.php?success=1');
            exit;
        } else {

            $error = is_array($task_result)
                ? ($task_result['message'] ?? 'Please try again.')
                : 'Please try again.';

            $message = 'Unable to assign task. ' . $error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Task - WorkPulse</title>
</head>

<body>

    <h1>Assign a Task</h1>

    <p>Assign work to one of your active employees.</p>

    <?php if ($message !== ''): ?>
        <p><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <?php if (empty($employments)): ?>

        <p>You don't have any active employees to assign tasks to.</p>

    <?php else: ?>

        <form method="POST" action="assign-task.php">

            <label for="employment_id">Assign To</label>

            <select name="employment_id" id="employment_id" required>

                <option value="">Select an employee</option>

                <?php foreach ($employments as $employment): ?>

                    <option
                        value="<?php echo htmlspecialchars($employment['id']); ?>"
                        <?php echo (
                            ($_POST['employment_id'] ?? '') === $employment['id']
                        ) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(
                            $workers[$employment['worker_id']] ?? 'Unknown Worker'
                        ); ?>
                    </option>

                <?php endforeach; ?>

            </select>

            <br><br>

            <label for="title">Task Title</label>

            <input
                type="text"
                name="title"
                id="title"
                value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
                required>

            <br><br>

            <label for="description">Description</label>

            <textarea
                name="description"
                id="description"
                rows="5"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>

            <br><br>

            <label for="priority">Priority</label>

            <select name="priority" id="priority">

                <option value="low"
                    <?php echo ($_POST['priority'] ?? 'medium') === 'low' ? 'selected' : ''; ?>>
                    Low
                </option>

                <option value="medium"
                    <?php echo ($_POST['priority'] ?? 'medium') === 'medium' ? 'selected' : ''; ?>>
                    Medium
                </option>

                <option value="high"
                    <?php echo ($_POST['priority'] ?? 'medium') === 'high' ? 'selected' : ''; ?>>
                    High
                </option>

            </select>

            <br><br>

            <label for="due_date">Deadline</label>

            <input
                type="date"
                name="due_date"
                id="due_date"
                value="<?php echo htmlspecialchars($_POST['due_date'] ?? ''); ?>"
                min="<?php echo date('Y-m-d'); ?>">

            <br><br>

            <label for="estimated_hours">Estimated Hours (Optional)</label>

            <input
                type="number"
                name="estimated_hours"
                id="estimated_hours"
                min="0.01"
                step="0.01"
                value="<?php echo htmlspecialchars($_POST['estimated_hours'] ?? ''); ?>">

            <br><br>

            <button type="submit" name="assign_task">
                Assign Task
            </button>

        </form>

    <?php endif; ?>

    <br>

    <a href="dashboard.php">Back to Dashboard</a>

</body>

</html>