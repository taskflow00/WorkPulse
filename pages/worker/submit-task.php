<?php
$required_role = 'worker';
$page_title = 'Submit Completed Work';
$active_page = 'tasks';

require_once '../../includes/auth.php';

date_default_timezone_set('Asia/Manila');

$worker_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$message = '';
$submission_message = '';

// Common Supabase headers.
$headers =
    "apikey: " . SUPABASE_KEY . "\r\n" .
    "Authorization: Bearer " . $access_token . "\r\n";

// 1. Get the task ID from the URL.
$task_id = $_GET['task_id'] ?? '';

if (!preg_match('/^[0-9a-fA-F-]{36}$/', $task_id)) {
    exit('Invalid task ID.');
}

// 2. Retrieve the task and verify ownership.
$url = SUPABASE_URL .
    '/rest/v1/tasks?select=id,title,description,status,worker_id' .
    '&id=eq.' . urlencode($task_id) .
    '&worker_id=eq.' . urlencode($worker_id);

$options = [
    'http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($url, false, $context);

$response_headers = $http_response_header ?? [];

preg_match(
    '/\s(\d{3})\s/',
    $response_headers[0] ?? '',
    $matches
);

$status_code = (int)($matches[1] ?? 0);
$result = json_decode($response ?: '', true);

if ($status_code !== 200 || !is_array($result) || empty($result)) {
    exit('Task not found or you do not have permission to access it.');
}

$task = $result[0];

// Only tasks currently in progress can be submitted.
if ($task['status'] !== 'in_progress') {
    exit('This task is not available for submission.');
}

// 3. Retrieve the latest submission for this task.
$url = SUPABASE_URL .
    '/rest/v1/task_submissions?select=id,status,message,submitted_at,employer_feedback' .
    '&task_id=eq.' . urlencode($task_id) .
    '&worker_id=eq.' . urlencode($worker_id) .
    '&order=submitted_at.desc&limit=1';

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

$latest_submission = (
    is_array($result) && !empty($result)
) ? $result[0] : null;

// 4. Process the submission form.
if (isset($_POST['submit_work'])) {

    $submission_message = trim($_POST['submission_message'] ?? '');

    // Prevent another submission while the previous one is being reviewed.
    if (
        $latest_submission &&
        $latest_submission['status'] === 'pending_review'
    ) {
        $message = 'Your previous submission is still awaiting employer review.';
    } elseif ($submission_message === '') {

        $message = 'Please describe the work you completed.';
    } elseif (strlen($submission_message) > 5000) {

        $message = 'Your message cannot exceed 5,000 characters.';
    } elseif (
        !isset($_FILES['screenshots']) ||
        !isset($_FILES['screenshots']['name']) ||
        empty($_FILES['screenshots']['name'][0])
    ) {

        $message = 'Please upload at least one screenshot.';
    } else {

        $file_count = count($_FILES['screenshots']['name']);

        if ($file_count > 5) {

            $message = 'You can upload a maximum of 5 screenshots.';
        } else {

            // Generate a UUID for the submission.
            $random_id = bin2hex(random_bytes(16));

            $submission_id =
                substr($random_id, 0, 8) . '-' .
                substr($random_id, 8, 4) . '-' .
                substr($random_id, 12, 4) . '-' .
                substr($random_id, 16, 4) . '-' .
                substr($random_id, 20, 12);

            $uploaded_files = [];
            $uploaded_paths = [];
            $upload_error = '';

            // Folder structure:
            // worker_id/task_id/submission_id/filename
            $folder_path =
                $worker_id . '/' .
                $task_id . '/' .
                $submission_id . '/';

            // 5. Validate and upload each screenshot.
            for ($i = 0; $i < $file_count; $i++) {

                $file_error = $_FILES['screenshots']['error'][$i];
                $temporary_file = $_FILES['screenshots']['tmp_name'][$i];
                $file_size = $_FILES['screenshots']['size'][$i];

                if ($file_error !== UPLOAD_ERR_OK) {
                    $upload_error = 'One of your screenshots could not be uploaded.';
                    break;
                }

                if (
                    !is_uploaded_file($temporary_file) ||
                    $file_size <= 0 ||
                    $file_size > 5242880
                ) {
                    $upload_error = 'Each screenshot must be smaller than 5 MB.';
                    break;
                }

                // Check the actual file MIME type.
                $file_info = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($file_info, $temporary_file);

                $allowed_types = [
                    'image/png' => 'png',
                    'image/jpeg' => 'jpg',
                    'image/webp' => 'webp'
                ];

                if (!isset($allowed_types[$mime_type])) {
                    $upload_error = 'Only PNG, JPG, JPEG, and WebP images are allowed.';
                    break;
                }

                // Generate a safe random filename.
                $stored_file_name =
                    bin2hex(random_bytes(16)) .
                    '.' . $allowed_types[$mime_type];

                $file_path = $folder_path . $stored_file_name;

                // Read the image contents.
                $file_contents = file_get_contents($temporary_file);

                if ($file_contents === false) {
                    $upload_error = 'Unable to read one of your screenshots.';
                    break;
                }

                // Upload the screenshot to Supabase Storage.
                $upload_url = SUPABASE_URL .
                    '/storage/v1/object/task-proofs/' .
                    implode('/', array_map('rawurlencode', explode('/', $file_path)));

                $upload_options = [
                    'http' => [
                        'method' => 'POST',
                        'header' =>
                        $headers .
                            "Content-Type: " . $mime_type . "\r\n" .
                            "x-upsert: false\r\n",
                        'content' => $file_contents,
                        'ignore_errors' => true
                    ]
                ];

                $upload_context = stream_context_create($upload_options);
                $upload_response = @file_get_contents(
                    $upload_url,
                    false,
                    $upload_context
                );

                $upload_headers = $http_response_header ?? [];

                preg_match(
                    '/\s(\d{3})\s/',
                    $upload_headers[0] ?? '',
                    $upload_matches
                );

                $upload_status = (int)($upload_matches[1] ?? 0);

                if ($upload_status !== 200 && $upload_status !== 201) {

                    $upload_result = json_decode($upload_response ?: '', true);

                    $upload_error = is_array($upload_result)
                        ? (
                            $upload_result['message'] ??
                            $upload_result['error'] ??
                            'A screenshot upload failed.'
                        )
                        : 'A screenshot upload failed.';

                    break;
                }

                // Remember uploaded files for the RPC.
                $uploaded_paths[] = $file_path;

                $uploaded_files[] = [
                    'path' => $file_path,
                    'name' => $stored_file_name
                ];
            }

            // 6. If uploading failed, clean up previously uploaded files.
            if ($upload_error !== '') {

                foreach ($uploaded_paths as $uploaded_path) {

                    $delete_url = SUPABASE_URL .
                        '/storage/v1/object/task-proofs/' .
                        implode(
                            '/',
                            array_map('rawurlencode', explode('/', $uploaded_path))
                        );

                    $delete_options = [
                        'http' => [
                            'method' => 'DELETE',
                            'header' => $headers,
                            'ignore_errors' => true
                        ]
                    ];

                    $delete_context = stream_context_create($delete_options);
                    @file_get_contents($delete_url, false, $delete_context);
                }

                $message = $upload_error;
            } else {

                // 7. Register the submission and screenshots in the database.
                $rpc_url = SUPABASE_URL .
                    '/rest/v1/rpc/submit_task_work';

                $rpc_data = [
                    'p_task_id' => $task_id,
                    'p_submission_id' => $submission_id,
                    'p_message' => $submission_message,
                    'p_files' => $uploaded_files
                ];

                $rpc_options = [
                    'http' => [
                        'method' => 'POST',
                        'header' =>
                        $headers .
                            "Content-Type: application/json\r\n",
                        'content' => json_encode($rpc_data),
                        'ignore_errors' => true
                    ]
                ];

                $rpc_context = stream_context_create($rpc_options);
                $rpc_response = @file_get_contents(
                    $rpc_url,
                    false,
                    $rpc_context
                );

                $rpc_headers = $http_response_header ?? [];

                preg_match(
                    '/\s(\d{3})\s/',
                    $rpc_headers[0] ?? '',
                    $rpc_matches
                );

                $rpc_status = (int)($rpc_matches[1] ?? 0);
                $rpc_result = json_decode($rpc_response ?: '', true);

                if (
                    $rpc_status === 200 &&
                    ($rpc_result['success'] ?? false)
                ) {

                    header('Location: tasks.php?success=submitted');
                    exit;
                } else {

                    // The submission was not registered.
                    // Delete the uploaded files so the worker can retry.
                    foreach ($uploaded_paths as $uploaded_path) {

                        $delete_url = SUPABASE_URL .
                            '/storage/v1/object/task-proofs/' .
                            implode(
                                '/',
                                array_map('rawurlencode', explode('/', $uploaded_path))
                            );

                        $delete_options = [
                            'http' => [
                                'method' => 'DELETE',
                                'header' => $headers,
                                'ignore_errors' => true
                            ]
                        ];

                        $delete_context = stream_context_create($delete_options);
                        @file_get_contents($delete_url, false, $delete_context);
                    }

                    $message = is_array($rpc_result)
                        ? (
                            $rpc_result['message'] ??
                            $rpc_result['error'] ??
                            'Unable to submit your work.'
                        )
                        : 'Unable to submit your work. Please try again.';
                }
            }
        }
    }
}

require_once '../../includes/header.php';
?>

<div class="wp-page">

    <!-- Page Heading -->
    <div class="wp-page-header">
        <div>
            <h1 class="wp-page-title">Submit Completed Work</h1>
            <p class="wp-page-description">
                Submit your completed task and provide screenshots as proof of your work.
            </p>
        </div>

        <a href="tasks.php" class="wp-btn wp-btn-outline">
            <i class="bi bi-arrow-left"></i>
            Back to My Tasks
        </a>
    </div>

    <!-- Error Message -->
    <?php if ($message !== ''): ?>

        <div class="wp-alert wp-alert-danger" role="alert">
            <i class="bi bi-exclamation-circle"></i>
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>

    <?php endif; ?>

    <!-- Task Information -->
    <div class="wp-card wp-form-panel">

        <div class="d-flex align-items-center gap-2 mb-3">
            <i class="bi bi-clipboard-check"></i>
            <strong>Task Details</strong>
        </div>

        <h2 class="mb-2">
            <?php echo htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8'); ?>
        </h2>

        <p class="text-muted mb-0">
            <?php echo nl2br(htmlspecialchars(
                $task['description'] ?? 'No description provided.',
                ENT_QUOTES,
                'UTF-8'
            )); ?>
        </p>

    </div>

    <!-- Submission Form -->
    <div class="wp-card wp-form-panel mt-4">

        <?php if (
            $latest_submission &&
            $latest_submission['status'] === 'pending_review'
        ): ?>

            <div class="wp-alert wp-alert-success mb-0">
                <i class="bi bi-clock-history"></i>

                <div>
                    <strong>Submission Awaiting Review</strong>
                    <p class="mb-0">
                        Your work has already been submitted and is awaiting employer review.
                        You cannot submit another version until the employer reviews it.
                    </p>
                </div>
            </div>

        <?php else: ?>

            <div class="mb-4">
                <h2 class="h5 mb-1">Your Submission</h2>
                <p class="text-muted mb-0">
                    Describe what you completed and attach screenshots showing your work.
                </p>
            </div>

            <form
                method="POST"
                action="submit-task.php?task_id=<?php echo urlencode($task_id); ?>"
                enctype="multipart/form-data">

                <div class="wp-form-group">
                    <label for="submission_message" class="wp-label">
                        Work Description
                    </label>

                    <textarea
                        id="submission_message"
                        name="submission_message"
                        class="wp-textarea"
                        rows="6"
                        maxlength="5000"
                        placeholder="Describe the work you completed..."
                        required><?php echo htmlspecialchars($submission_message, ENT_QUOTES, 'UTF-8'); ?></textarea>

                    <small class="wp-form-hint">
                        Explain what you completed. Maximum 5,000 characters.
                    </small>
                </div>

                <div class="wp-form-group">
                    <label for="screenshots" class="wp-label">
                        Screenshots / Proof of Work
                    </label>

                    <input
                        type="file"
                        id="screenshots"
                        name="screenshots[]"
                        class="wp-input"
                        accept="image/png,image/jpeg,image/webp"
                        multiple
                        required>

                    <small class="wp-form-hint">
                        Upload 1 to 5 screenshots. Each image must be 5 MB or smaller.
                        Accepted formats: PNG, JPG, JPEG, and WebP.
                    </small>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">

                    <button
                        type="submit"
                        name="submit_work"
                        value="1"
                        class="wp-btn wp-btn-success">

                        <i class="bi bi-send"></i>
                        Submit for Review

                    </button>

                    <a href="tasks.php" class="wp-btn wp-btn-outline">
                        Cancel
                    </a>

                </div>

            </form>

        <?php endif; ?>

    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>