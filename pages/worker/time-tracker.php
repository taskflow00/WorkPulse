<?php
session_start();
require_once '../../config/supabase.php';

date_default_timezone_set('Asia/Manila');

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

// Only allow tracking when exactly one active employment exists.
if (count($employments) === 1) {
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

            $error = $result['message']
                ?? 'Please try again.';

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

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Time Tracker - WorkPulse</title>
</head>

<body>

    <h1>Time Tracker</h1>

    <p>Track your working hours with WorkPulse.</p>

    <?php if ($message !== ''): ?>

        <p>
            <?php echo htmlspecialchars($message); ?>
        </p>

    <?php endif; ?>

    <hr>

    <h2>Work Session</h2>

    <?php if ($current_session): ?>

        <p>
            Status:
            <?php echo htmlspecialchars(ucfirst($session_status)); ?>
        </p>

    <?php else: ?>

        <p>No active tracking session.</p>

    <?php endif; ?>

    <p>Working Time</p>

    <h1
        id="timer"
        data-total-seconds="<?php echo $total_seconds; ?>"
        data-status="<?php echo htmlspecialchars($session_status); ?>"
        data-resumed-at="<?php echo htmlspecialchars($last_resumed_at); ?>"
        data-last-activity-at="<?php echo htmlspecialchars($last_activity_at); ?>">
        00:00:00
    </h1>

    <p>Idle Time</p>

    <h2
        id="idle-timer"
        data-idle-seconds="<?php echo $idle_seconds; ?>"
        data-status="<?php echo htmlspecialchars($session_status); ?>"
        data-idle-started-at="<?php echo htmlspecialchars($idle_started_at); ?>">
        00:00:00
    </h2>

    <?php if (!$current_session): ?>

        <?php if (!$employment): ?>

            <p>
                You need exactly one active employment
                before you can track your working hours.
            </p>

        <?php else: ?>

            <form method="POST" action="time-tracker.php">

                <button
                    type="submit"
                    name="timer_action"
                    value="start">
                    Start Tracking
                </button>

            </form>

        <?php endif; ?>

    <?php elseif ($session_status === 'running'): ?>

        <form method="POST" action="time-tracker.php">

            <button type="submit" name="timer_action" value="pause">
                Pause
            </button>

            <button
                type="submit"
                name="timer_action"
                value="stop"
                onclick="return confirm('Are you sure you want to stop tracking?');">
                Stop
            </button>

        </form>

    <?php elseif ($session_status === 'paused'): ?>

        <form method="POST" action="time-tracker.php">

            <button type="submit" name="timer_action" value="resume">
                Resume
            </button>

            <button
                type="submit"
                name="timer_action"
                value="stop"
                onclick="return confirm('Are you sure you want to stop tracking?');">
                Stop
            </button>

        </form>

    <?php elseif ($session_status === 'idle'): ?>

        <p>You have been marked as idle.</p>

        <p>Interact with this page when you're ready to continue.</p>

        <div id="idle-prompt" hidden>

            <p>You're back! Would you like to resume tracking?</p>

            <form method="POST" action="time-tracker.php">

                <button type="submit" name="timer_action" value="resume">
                    Resume Tracking
                </button>

                <button
                    type="submit"
                    name="timer_action"
                    value="stop"
                    onclick="return confirm('Are you sure you want to stop tracking?');">
                    Stop Tracking
                </button>

            </form>

        </div>

    <?php endif; ?>

    <br>

    <a href="dashboard.php">Back to Dashboard</a>

    <script src="../../assets/js/time-tracker.js" defer></script>

</body>

</html>