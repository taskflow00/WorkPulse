<?php

$required_role = 'worker';
$page_title = 'Time Tracker';
$active_page = 'time-tracker';

require_once '../../includes/auth.php';

date_default_timezone_set('Asia/Manila');

$worker_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$message = '';
$message_type = 'danger';

// Default idle timeout: 5 minutes.
$idle_timeout_seconds = 300;

// =====================================
// 0. COMMON HEADERS
// =====================================

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
// 1.1 GET TIME TRACKING SETTINGS
// =====================================

// Retrieve the idle timeout configured in Supabase.
$url = SUPABASE_URL .
    '/rest/v1/time_tracking_settings?select=idle_timeout_seconds&id=eq.1&limit=1';

$options = [
    'http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($url, false, $context);

$settings = json_decode($response ?: '', true);

// Use the database value if it is valid.
if (
    is_array($settings) &&
    !empty($settings) &&
    isset($settings[0]['idle_timeout_seconds'])
) {

    $database_timeout = (int)$settings[0]['idle_timeout_seconds'];

    // Accept only values between 1 and 3600 seconds.
    if ($database_timeout >= 1 && $database_timeout <= 3600) {

        $idle_timeout_seconds = $database_timeout;
    }
}

// Convert the timeout into readable text.
if ($idle_timeout_seconds >= 60) {

    $timeout_minutes = intdiv($idle_timeout_seconds, 60);
    $timeout_remaining_seconds = $idle_timeout_seconds % 60;

    $idle_timeout_label =
        $timeout_minutes . ' minute' .
        ($timeout_minutes !== 1 ? 's' : '');

    if ($timeout_remaining_seconds > 0) {

        $idle_timeout_label .=
            ' and ' . $timeout_remaining_seconds . ' second' .
            ($timeout_remaining_seconds !== 1 ? 's' : '');
    }
} else {

    $idle_timeout_label =
        $idle_timeout_seconds . ' second' .
        ($idle_timeout_seconds !== 1 ? 's' : '');
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

    // Check whether the rollover request succeeded.
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

        // Show a one-time message after the redirect.
        $_SESSION['timer_flash'] =
            'Your tracking session has been updated for the new day.';

        header('Location: time-tracker.php');
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

        $message =
            'You need exactly one active employment to start tracking.';
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

        // Check whether the RPC action succeeded.
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

            // Prepare a success message for the next page load.
            if ($action === 'start') {

                $_SESSION['timer_flash'] =
                    'Time tracking started!';
            } elseif ($action === 'pause') {

                $_SESSION['timer_flash'] =
                    'Time tracking paused!';
            } elseif ($action === 'resume') {

                $_SESSION['timer_flash'] =
                    'Time tracking resumed!';
            } elseif ($action === 'stop') {

                $_SESSION['timer_flash'] =
                    'Time tracking stopped and saved!';
            } elseif ($action === 'rollover') {

                $_SESSION['timer_flash'] =
                    'Your tracking session has been updated for the new day.';
            }

            // Redirect to a clean URL.
            header('Location: time-tracker.php');
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
// 4. DISPLAY FLASH MESSAGE
// =====================================

if (isset($_SESSION['timer_flash'])) {

    $message = $_SESSION['timer_flash'];
    $message_type = 'success';

    // Remove it so it only appears once.
    unset($_SESSION['timer_flash']);
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


// =====================================
// 7. PREPARE STATUS LABEL AND COLOR
// =====================================

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


// =====================================
// 8. LOAD PAGE HEADER
// =====================================

require_once '../../includes/header.php';

?>

<div class="wp-page wp-time-tracker">

    <!-- =====================================
         PAGE HEADER
    ====================================== -->

    <div class="wp-page-header mb-4">

        <div>

            <h1 class="wp-page-title">Time Tracker</h1>

            <p class="wp-page-description">
                Stay focused and keep track of your working hours.
            </p>

        </div>

    </div>


    <!-- =====================================
         ALERT MESSAGE
    ====================================== -->

    <?php if ($message !== ''): ?>

        <div class="wp-alert wp-alert-<?php echo $message_type; ?>">

            <i class="bi bi-info-circle"></i>

            <?php echo htmlspecialchars(
                $message,
                ENT_QUOTES,
                'UTF-8'
            ); ?>

        </div>

    <?php endif; ?>


    <!-- =====================================
         STOPWATCH CARD
    ====================================== -->

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


            <span class="wp-timer-status wp-status-<?php
                                                    echo htmlspecialchars($session_status, ENT_QUOTES, 'UTF-8');
                                                    ?>">

                <span class="wp-status-dot"></span>

                <?php echo htmlspecialchars(
                    $status_label,
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>

            </span>

        </div>


        <div class="wp-stopwatch-body">


            <!-- =====================================
                 WORKING TIME DISPLAY
            ====================================== -->

            <div class="wp-stopwatch-ring">

                <div class="wp-stopwatch-inner">

                    <span class="wp-stopwatch-label">
                        TOTAL WORKING TIME
                    </span>

                    <h1
                        id="timer"
                        data-total-seconds="<?php echo $total_seconds; ?>"
                        data-status="<?php echo htmlspecialchars(
                                            $session_status,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ); ?>"
                        data-resumed-at="<?php echo htmlspecialchars(
                                                $last_resumed_at,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ); ?>"
                        data-last-activity-at="<?php echo htmlspecialchars(
                                                    $last_activity_at,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>"
                        data-idle-timeout-seconds="<?php echo $idle_timeout_seconds; ?>">

                        00:00:00

                    </h1>

                    <span class="wp-stopwatch-caption">
                        Hours : Minutes : Seconds
                    </span>

                </div>

            </div>


            <!-- =====================================
                 TIMER CONTROLS
            ====================================== -->

            <div class="wp-stopwatch-controls">

                <?php if (!$current_session): ?>

                    <?php if (!$employment): ?>

                        <div class="wp-alert wp-alert-danger">

                            <i class="bi bi-exclamation-circle"></i>

                            <?php if ($active_employment_count === 0): ?>

                                You need an active employment before tracking time.

                            <?php else: ?>

                                You have multiple active employments.
                                Resolve this before tracking.

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


                    <form
                        method="POST"
                        action="time-tracker.php"
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


                    <form
                        method="POST"
                        action="time-tracker.php"
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

                        <span>
                            Your session is idle. Interact with this page to continue.
                        </span>

                    </div>

                <?php endif; ?>

            </div>


            <!-- =====================================
                 ADDITIONAL TIMER INFORMATION
            ====================================== -->

            <div class="wp-stopwatch-divider"></div>

            <div class="wp-stopwatch-extra">


                <!-- IDLE TIME -->

                <div class="wp-stopwatch-extra-item">

                    <div class="wp-extra-icon wp-extra-icon-idle">
                        <i class="bi bi-cup-hot"></i>
                    </div>

                    <div>

                        <span>Idle Time</span>

                        <strong
                            id="idle-timer"
                            data-idle-seconds="<?php echo $idle_seconds; ?>"
                            data-status="<?php echo htmlspecialchars(
                                                $session_status,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ); ?>"
                            data-idle-started-at="<?php echo htmlspecialchars(
                                                        $idle_started_at,
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ); ?>">

                            00:00:00

                        </strong>

                    </div>

                </div>


                <!-- TODAY'S DATE -->

                <div class="wp-stopwatch-extra-item">

                    <div class="wp-extra-icon wp-extra-icon-date">
                        <i class="bi bi-calendar3"></i>
                    </div>

                    <div>

                        <span>Today's Date</span>

                        <strong>
                            <?php echo date('F d, Y'); ?>
                        </strong>

                    </div>

                </div>


            </div>

        </div>

    </div>


    <!-- =====================================
         HOW IT WORKS
    ====================================== -->

    <div class="wp-stopwatch-info">

        <div class="wp-stopwatch-info-icon">
            <i class="bi bi-info-circle"></i>
        </div>

        <div>

            <strong>How it works</strong>

            <p>

                Your working time is recorded while tracking is running.
                After <?php echo htmlspecialchars(
                            $idle_timeout_label,
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?> without activity, WorkPulse automatically marks
                your session as idle. When you return, you can resume or stop.

            </p>

        </div>

    </div>


    <!-- =====================================
         BACK TO DASHBOARD
    ====================================== -->

    <a href="dashboard.php" class="wp-btn wp-btn-outline mt-4">

        <i class="bi bi-arrow-left"></i>

        Back to Dashboard

    </a>

</div>


<!-- =====================================
     IDLE DETECTION MODAL
====================================== -->

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


            <span class="wp-idle-modal-label">
                SESSION PAUSED
            </span>


            <h2 id="wp-idle-title">
                Welcome back!
            </h2>


            <p>
                We noticed you were away for a while.
                Your session has been marked as idle.
            </p>


            <p class="wp-idle-modal-question">
                Would you like to continue working?
            </p>


            <div class="wp-idle-modal-actions">


                <!-- RESUME SESSION -->

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


                <!-- STOP SESSION -->

                <form
                    method="POST"
                    action="time-tracker.php"
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


<!-- =====================================
     TIME TRACKER JAVASCRIPT
====================================== -->

<script src="../../assets/js/time-tracker.js" defer></script>


<?php require_once '../../includes/footer.php'; ?>