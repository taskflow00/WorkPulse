<?php
$required_role = 'worker';
$page_title = 'Time Tracker';
$active_page = 'time-tracker';

require_once '../../includes/auth.php';

date_default_timezone_set('Asia/Manila');

$worker_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$message = '';

// Common headers.
$headers =
    "apikey: " . SUPABASE_KEY . "\r\n" .
    "Authorization: Bearer " . $access_token . "\r\n";

// Check if the request came from JavaScript.
$is_ajax = (
    ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
);

// =====================================
// 1. GET ACTIVE EMPLOYMENT
// =====================================

$url = SUPABASE_URL .
    '/rest/v1/employments?select=id,work_schedule' .
    '&worker_id=eq.' . urlencode($worker_id) .
    '&status=eq.active';

$options = [
    'http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($url, false, $context);

$employments = json_decode($response ?: '', true);

if (!is_array($employments)) {
    $employments = [];
}

$employment = null;
$active_employment_count = count($employments);

// Only allow tracking when exactly one active employment exists.
if ($active_employment_count === 1) {
    $employment = $employments[0];
}

// =====================================
// 2. CHECK DAILY ROLLOVER
// =====================================

$posted_action = $_POST['timer_action'] ?? null;

// Do not check rollover twice when the action itself is rollover.
if ($posted_action !== 'rollover') {

    $url = SUPABASE_URL .
        '/rest/v1/rpc/rollover_time_tracking_session';

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => $headers .
                "Content-Type: application/json\r\n",
            'content' => '{}',
            'ignore_errors' => true
        ]
    ];

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    $rollover_result = json_decode($response ?: '', true);

    if (
        !is_array($rollover_result) ||
        !($rollover_result['success'] ?? false)
    ) {

        $error = $rollover_result['message']
            ?? 'Unable to check daily rollover.';

        if ($is_ajax) {

            header('Content-Type: application/json');

            echo json_encode([
                'success' => false,
                'message' => $error
            ]);

            exit;
        }

        $message = $error;

        // Do not process the timer action if rollover failed.
        unset($_POST['timer_action']);
    } elseif (
        (int)($rollover_result['rolled_over'] ?? 0) > 0 &&
        $posted_action !== null
    ) {

        // A rollover happened while an action was submitted.
        // Reload the page before processing another action.

        if ($is_ajax) {

            header('Content-Type: application/json');

            echo json_encode([
                'success' => true,
                'reload' => true
            ]);

            exit;
        }

        header('Location: time-tracker.php?rolled_over=1');
        exit;
    }
}

// =====================================
// 3. PROCESS TIMER ACTIONS
// =====================================

if (isset($_POST['timer_action'])) {

    $action = $_POST['timer_action'];

    // Match the button action with its Supabase RPC.
    $function_name = '';

    if ($action === 'start') {
        $function_name = 'start_time_tracking';
    } elseif ($action === 'pause') {
        $function_name = 'pause_time_tracking';
    } elseif ($action === 'resume') {
        $function_name = 'resume_time_tracking';
    } elseif ($action === 'stop') {
        $function_name = 'stop_time_tracking';
    } elseif ($action === 'activity') {
        $function_name = 'record_time_tracking_activity';
    } elseif ($action === 'mark_idle') {
        $function_name = 'mark_time_tracking_idle';
    } elseif ($action === 'rollover') {
        $function_name = 'rollover_time_tracking_session';
    }

    if ($function_name === '') {

        $message = 'Invalid timer action.';
    } elseif ($action === 'start' && !$employment) {

        $message = 'You need exactly one active employment to start tracking.';
    } else {

        // Most RPC functions do not need additional data.
        $data = '{}';

        // Starting a session requires an employment ID.
        if ($action === 'start') {

            $data = json_encode([
                'p_employment_id' => $employment['id'],
                'p_task_id' => null
            ]);
        }

        // Call the Supabase RPC.
        $url = SUPABASE_URL .
            '/rest/v1/rpc/' . $function_name;

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => $headers .
                    "Content-Type: application/json\r\n",
                'content' => $data,
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        $result = json_decode($response ?: '', true);

        if (
            is_array($result) &&
            ($result['success'] ?? false)
        ) {

            // Return the result to JavaScript.
            if ($is_ajax) {

                header('Content-Type: application/json');

                echo json_encode($result);

                exit;
            }

            // Refresh after normal form submissions.
            header(
                'Location: time-tracker.php?success=' .
                    urlencode($action)
            );

            exit;
        } else {

            $error = is_array($result)
                ? ($result['message'] ?? 'Please try again.')
                : 'Please try again.';

            if ($is_ajax) {

                header('Content-Type: application/json');

                echo json_encode([
                    'success' => false,
                    'message' => $error
                ]);

                exit;
            }

            $message = 'Timer action failed. ' . $error;
        }
    }

    // Return invalid action errors to JavaScript as JSON too.
    if ($is_ajax) {

        header('Content-Type: application/json');

        echo json_encode([
            'success' => false,
            'message' => $message
        ]);

        exit;
    }
}

// =====================================
// 4. DISPLAY SUCCESS MESSAGES
// =====================================

if (isset($_GET['success'])) {

    $success = $_GET['success'];

    if ($success === 'start') {
        $message = 'Time tracking started!';
    } elseif ($success === 'pause') {
        $message = 'Time tracking paused!';
    } elseif ($success === 'resume') {
        $message = 'Time tracking resumed!';
    } elseif ($success === 'stop') {
        $message = 'Time tracking stopped and saved!';
    } elseif ($success === 'rollover') {
        $message = 'Daily rollover completed!';
    }
}

if (isset($_GET['rolled_over'])) {
    $message = 'Your previous working session was saved through daily rollover.';
}

// =====================================
// 5. GET CURRENT TRACKING SESSION
// =====================================

$url = SUPABASE_URL .
    '/rest/v1/time_tracking_sessions?select=*' .
    '&worker_id=eq.' . urlencode($worker_id) .
    '&status=in.(running,paused,idle)' .
    '&limit=1';

$options = [
    'http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($url, false, $context);

$sessions = json_decode($response ?: '', true);

$current_session = null;

if (is_array($sessions) && !empty($sessions)) {
    $current_session = $sessions[0];
}

// =====================================
// 6. GET SESSION VALUES
// =====================================

$total_seconds = (int)($current_session['total_seconds'] ?? 0);
$idle_seconds = (int)($current_session['idle_seconds'] ?? 0);

$session_status = $current_session['status'] ?? 'stopped';

$last_resumed_at = $current_session['last_resumed_at'] ?? '';
$last_activity_at = $current_session['last_activity_at'] ?? '';
$idle_started_at = $current_session['idle_started_at'] ?? '';

// Prepare status label and color.
$status_label = ucfirst($session_status);
$status_color = 'secondary';
$status_icon = 'bi-stop-circle';

if ($session_status === 'running') {
    $status_color = 'success';
    $status_icon = 'bi-play-circle';
} elseif ($session_status === 'paused') {
    $status_color = 'warning';
    $status_icon = 'bi-pause-circle';
} elseif ($session_status === 'idle') {
    $status_color = 'danger';
    $status_icon = 'bi-hourglass-split';
}

require_once '../../includes/header.php';
?>

<div class="wp-page wp-time-tracker">

    <div class="wp-page-header mb-4">
        <div>
            <h1 class="wp-page-title">Time Tracker</h1>
            <p class="wp-page-description">
                Stay focused and keep track of your working hours.
            </p>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="wp-alert <?php echo (
                                    isset($_GET['success']) || isset($_GET['rolled_over'])
                                ) ? 'wp-alert-success' : 'wp-alert-danger'; ?>">
            <i class="bi bi-info-circle"></i>
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="wp-stopwatch-card">

        <div class="wp-stopwatch-top">
            <div class="wp-stopwatch-brand">
                <div class="wp-stopwatch-icon">
                    <i class="bi bi-stopwatch"></i>
                </div>

                <div>
                    <h2>WorkPulse Stopwatch</h2>
                    <p>Your personal work timer</p>
                </div>
            </div>

            <span class="wp-timer-status wp-status-<?php echo htmlspecialchars($session_status); ?>">
                <span class="wp-status-dot"></span>
                <?php echo htmlspecialchars($status_label); ?>
            </span>
        </div>

        <div class="wp-stopwatch-body">

            <div class="wp-stopwatch-ring">
                <div class="wp-stopwatch-inner">

                    <span class="wp-stopwatch-label">
                        TOTAL WORKING TIME
                    </span>

                    <h1
                        id="timer"
                        data-total-seconds="<?php echo $total_seconds; ?>"
                        data-status="<?php echo htmlspecialchars($session_status); ?>"
                        data-resumed-at="<?php echo htmlspecialchars($last_resumed_at); ?>"
                        data-last-activity-at="<?php echo htmlspecialchars($last_activity_at); ?>">
                        00:00:00
                    </h1>

                    <span class="wp-stopwatch-caption">
                        Hours : Minutes : Seconds
                    </span>

                </div>
            </div>

            <div class="wp-stopwatch-controls">

                <?php if (!$current_session): ?>

                    <?php if (!$employment): ?>

                        <div class="wp-alert wp-alert-danger">
                            <i class="bi bi-exclamation-circle"></i>

                            <?php if ($active_employment_count === 0): ?>
                                You need an active employment before tracking time.
                            <?php else: ?>
                                You have multiple active employments. Resolve this before tracking.
                            <?php endif; ?>
                        </div>

                    <?php else: ?>

                        <form method="POST" action="time-tracker.php">
                            <button
                                type="submit"
                                name="timer_action"
                                value="start"
                                class="wp-stopwatch-button wp-stopwatch-start">
                                <i class="bi bi-play-fill"></i>
                                Start Tracking
                            </button>
                        </form>

                    <?php endif; ?>

                <?php elseif ($session_status === 'running'): ?>

                    <form method="POST" action="time-tracker.php">
                        <button
                            type="submit"
                            name="timer_action"
                            value="pause"
                            class="wp-stopwatch-button wp-stopwatch-pause">
                            <i class="bi bi-pause-fill"></i>
                            Pause
                        </button>
                    </form>

                    <form method="POST" action="time-tracker.php"
                        onsubmit="return confirm('Stop your current work session?');">
                        <button
                            type="submit"
                            name="timer_action"
                            value="stop"
                            class="wp-stopwatch-button wp-stopwatch-stop">
                            <i class="bi bi-stop-fill"></i>
                            Stop
                        </button>
                    </form>

                <?php elseif ($session_status === 'paused'): ?>

                    <form method="POST" action="time-tracker.php">
                        <button
                            type="submit"
                            name="timer_action"
                            value="resume"
                            class="wp-stopwatch-button wp-stopwatch-start">
                            <i class="bi bi-play-fill"></i>
                            Resume
                        </button>
                    </form>

                    <form method="POST" action="time-tracker.php"
                        onsubmit="return confirm('Stop your current work session?');">
                        <button
                            type="submit"
                            name="timer_action"
                            value="stop"
                            class="wp-stopwatch-button wp-stopwatch-stop">
                            <i class="bi bi-stop-fill"></i>
                            Stop
                        </button>
                    </form>

                <?php elseif ($session_status === 'idle'): ?>

                    <div class="wp-idle-page-notice">
                        <i class="bi bi-moon-stars"></i>
                        <span>Your session is idle. Interact with this page to continue.</span>
                    </div>

                <?php endif; ?>

            </div>

            <div class="wp-stopwatch-divider"></div>

            <div class="wp-stopwatch-extra">

                <div class="wp-stopwatch-extra-item">
                    <div class="wp-extra-icon wp-extra-icon-idle">
                        <i class="bi bi-cup-hot"></i>
                    </div>

                    <div>
                        <span>Idle Time</span>
                        <strong
                            id="idle-timer"
                            data-idle-seconds="<?php echo $idle_seconds; ?>"
                            data-status="<?php echo htmlspecialchars($session_status); ?>"
                            data-idle-started-at="<?php echo htmlspecialchars($idle_started_at); ?>">
                            00:00:00
                        </strong>
                    </div>
                </div>

                <div class="wp-stopwatch-extra-item">
                    <div class="wp-extra-icon wp-extra-icon-date">
                        <i class="bi bi-calendar3"></i>
                    </div>

                    <div>
                        <span>Today's Date</span>
                        <strong><?php echo date('F d, Y'); ?></strong>
                    </div>
                </div>

            </div>

        </div>
    </div>

    <div class="wp-stopwatch-info">
        <div class="wp-stopwatch-info-icon">
            <i class="bi bi-info-circle"></i>
        </div>

        <div>
            <strong>How it works</strong>
            <p>
                Your working time is recorded while tracking is running.
                After five minutes without activity, WorkPulse marks your
                session as idle. When you return, you can resume or stop.
            </p>
        </div>
    </div>

    <a href="dashboard.php" class="wp-btn wp-btn-outline mt-4">
        <i class="bi bi-arrow-left"></i>
        Back to Dashboard
    </a>

</div>

<!-- Idle Detection Modal -->
<?php if ($session_status === 'idle'): ?>

    <div
        id="idle-prompt"
        class="wp-idle-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="wp-idle-title"
        hidden>

        <div class="wp-idle-modal-backdrop"></div>

        <div class="wp-idle-modal-content">

            <div class="wp-idle-modal-icon">
                <i class="bi bi-cup-hot"></i>
            </div>

            <span class="wp-idle-modal-label">SESSION PAUSED</span>

            <h2 id="wp-idle-title">Welcome back!</h2>

            <p>
                We noticed you were away for a while.
                Your session has been marked as idle.
            </p>

            <p class="wp-idle-modal-question">
                Would you like to continue working?
            </p>

            <div class="wp-idle-modal-actions">

                <form method="POST" action="time-tracker.php">
                    <button
                        type="submit"
                        name="timer_action"
                        value="resume"
                        class="wp-stopwatch-button wp-stopwatch-start">
                        <i class="bi bi-play-fill"></i>
                        Resume Tracking
                    </button>
                </form>

                <form method="POST" action="time-tracker.php"
                    onsubmit="return confirm('Are you sure you want to stop this session?');">
                    <button
                        type="submit"
                        name="timer_action"
                        value="stop"
                        class="wp-stopwatch-button wp-stopwatch-stop">
                        <i class="bi bi-stop-fill"></i>
                        Stop Session
                    </button>
                </form>

            </div>

            <span class="wp-idle-modal-footer">
                Your recorded working time is safe.
            </span>

        </div>

    </div>

<?php endif; ?>

<script src="../../assets/js/time-tracker.js" defer></script>

<?php require_once '../../includes/footer.php'; ?>