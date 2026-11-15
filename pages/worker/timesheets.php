<?php
$required_role = 'worker';
require_once '../../includes/auth.php';

// Use Philippine time.
date_default_timezone_set('Asia/Manila');

$worker_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

// Common Supabase headers.
$headers =
    "apikey: " . SUPABASE_KEY . "\r\n" .
    "Authorization: Bearer " . $access_token . "\r\n";

// Prepare messages.
$submission_message = $_GET['message'] ?? '';
$proof_message = $_GET['proof_message'] ?? '';
$error_message = '';

// Common GET request options.
$options = [
    'http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);

// Retrieve the worker's employments.
$url = SUPABASE_URL .
    '/rest/v1/employments?select=id,worker_id' .
    '&worker_id=eq.' . urlencode($worker_id);

$response = file_get_contents($url, false, $context);
$employments = json_decode($response ?: '', true);

if (!is_array($employments)) {
    $employments = [];
}

// Get the worker's employment ID.
$employment_id = null;

if (!empty($employments)) {
    $employment_id = $employments[0]['id'];
}

// Get the selected month.
$selected_month = $_GET['month'] ?? date('Y-m');

// Validate the selected month.
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $selected_month)) {
    $selected_month = date('Y-m');
}

// Check whether the selected month is completed.
$current_month = date('Y-m');
$is_completed_month = $selected_month < $current_month;

// Prepare arrays.
$time_sessions = [];
$daily_records = [];
$history_months = [];
$history_timesheets = [];
$existing_timesheet = null;
$daily_proofs = [];
$proof_map = [];

// Retrieve the selected month's time records.
if (!empty($employment_id)) {

    $timezone = new DateTimeZone('Asia/Manila');

    $month_start = new DateTime(
        $selected_month . '-01 00:00:00',
        $timezone
    );

    $month_end = clone $month_start;
    $month_end->modify('+1 month');

    $url = SUPABASE_URL .
        '/rest/v1/time_tracking_sessions?select=started_at,total_seconds,idle_seconds' .
        '&worker_id=eq.' . urlencode($worker_id) .
        '&employment_id=eq.' . urlencode($employment_id) .
        '&started_at=gte.' . urlencode($month_start->format('c')) .
        '&started_at=lt.' . urlencode($month_end->format('c')) .
        '&order=started_at.asc';

    $response = file_get_contents($url, false, $context);
    $time_sessions = json_decode($response ?: '', true);

    if (!is_array($time_sessions)) {
        $time_sessions = [];
    }

    // Group sessions by Philippine date.
    foreach ($time_sessions as $session) {

        $date = new DateTime($session['started_at']);
        $date->setTimezone($timezone);

        $work_date = $date->format('Y-m-d');

        if (!isset($daily_records[$work_date])) {
            $daily_records[$work_date] = [
                'working_seconds' => 0,
                'idle_seconds' => 0
            ];
        }

        $daily_records[$work_date]['working_seconds'] +=
            (int) $session['total_seconds'];

        $daily_records[$work_date]['idle_seconds'] +=
            (int) $session['idle_seconds'];
    }

    // Show newest dates first.
    krsort($daily_records);
}

// Handle daily time proof submission.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['submit_time_proof'])
) {

    $posted_month = $_POST['month'] ?? '';
    $work_date = $_POST['work_date'] ?? '';
    $worker_note = trim($_POST['worker_note'] ?? '');

    if (
        !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $posted_month) ||
        $posted_month !== $selected_month
    ) {

        $error_message = 'Invalid month selected.';
    } elseif (empty($employment_id)) {

        $error_message = 'No employment found.';
    } elseif (!isset($daily_records[$work_date])) {

        $error_message = 'No recorded working hours found for that date.';
    } elseif (strlen($worker_note) > 1000) {

        $error_message = 'Your note must not exceed 1000 characters.';
    } elseif (
        !isset($_FILES['screenshot']) ||
        $_FILES['screenshot']['error'] !== UPLOAD_ERR_OK
    ) {

        $error_message = 'Please select a screenshot to upload.';
    } else {

        $screenshot = $_FILES['screenshot'];

        // Maximum screenshot size: 5 MB.
        if ($screenshot['size'] > 5 * 1024 * 1024) {

            $error_message = 'Screenshot must not exceed 5 MB.';
        } elseif (!is_uploaded_file($screenshot['tmp_name'])) {

            $error_message = 'Invalid uploaded file.';
        } else {

            // Check the actual file type.
            $fileInfo = finfo_open(FILEINFO_MIME_TYPE);

            $mimeType = finfo_file(
                $fileInfo,
                $screenshot['tmp_name']
            );


            // Only allow supported image types.
            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp'
            ];

            if (!isset($allowedTypes[$mimeType])) {

                $error_message = 'Only JPG, PNG, and WebP screenshots are allowed.';
            } else {

                // Generate a random filename.
                $extension = $allowedTypes[$mimeType];
                $filename = bin2hex(random_bytes(16)) . '.' . $extension;

                // Organize files by worker, employment, and date.
                $screenshotPath =
                    $worker_id . '/' .
                    $employment_id . '/' .
                    $work_date . '/' .
                    $filename;

                // Upload screenshot to private Supabase Storage.
                $uploadUrl = SUPABASE_URL .
                    '/storage/v1/object/daily-time-proofs/' .
                    $screenshotPath;

                $fileContent = file_get_contents($screenshot['tmp_name']);

                $uploadOptions = [
                    'http' => [
                        'method' => 'POST',
                        'header' => $headers .
                            "Content-Type: " . $mimeType . "\r\n" .
                            "x-upsert: false\r\n",
                        'content' => $fileContent,
                        'ignore_errors' => true
                    ]
                ];

                $uploadContext = stream_context_create($uploadOptions);

                $uploadResponse = file_get_contents(
                    $uploadUrl,
                    false,
                    $uploadContext
                );

                $uploadResult = json_decode(
                    $uploadResponse ?: '',
                    true
                );

                // Check whether the screenshot was uploaded.
                if (
                    !is_array($uploadResult) ||
                    !isset($uploadResult['Key'])
                ) {

                    $error_message = $uploadResult['message']
                        ?? $uploadResult['error']
                        ?? 'Unable to upload screenshot.';
                } else {

                    // Save screenshot information in the database.
                    $proofData = [
                        'employment_id' => $employment_id,
                        'worker_id' => $worker_id,
                        'work_date' => $work_date,
                        'screenshot_path' => $screenshotPath,
                        'worker_note' => $worker_note !== ''
                            ? $worker_note
                            : null
                    ];

                    $proofUrl = SUPABASE_URL .
                        '/rest/v1/daily_time_proofs';

                    $proofOptions = [
                        'http' => [
                            'method' => 'POST',
                            'header' => $headers .
                                "Content-Type: application/json\r\n" .
                                "Prefer: return=representation\r\n",
                            'content' => json_encode($proofData),
                            'ignore_errors' => true
                        ]
                    ];

                    $proofContext = stream_context_create($proofOptions);

                    $proofResponse = file_get_contents(
                        $proofUrl,
                        false,
                        $proofContext
                    );

                    $proofResult = json_decode(
                        $proofResponse ?: '',
                        true
                    );

                    // Check whether the proof record was saved.
                    if (
                        is_array($proofResult) &&
                        isset($proofResult[0]['id'])
                    ) {

                        // Redirect to prevent duplicate submission.
                        header(
                            'Location: timesheets.php?month=' .
                                urlencode($selected_month) .
                                '&proof_message=' .
                                urlencode('Daily time proof submitted successfully!')
                        );

                        exit;
                    } else {

                        // Remove uploaded screenshot if saving failed.
                        $deleteUrl = SUPABASE_URL .
                            '/storage/v1/object/daily-time-proofs';

                        $deleteData = [
                            'prefixes' => [$screenshotPath]
                        ];

                        $deleteOptions = [
                            'http' => [
                                'method' => 'DELETE',
                                'header' => $headers .
                                    "Content-Type: application/json\r\n",
                                'content' => json_encode($deleteData),
                                'ignore_errors' => true
                            ]
                        ];

                        $deleteContext = stream_context_create($deleteOptions);

                        file_get_contents(
                            $deleteUrl,
                            false,
                            $deleteContext
                        );

                        $error_message = $proofResult['message']
                            ?? 'Unable to save time proof information.';
                    }
                }
            }
        }
    }
}

// Handle monthly timesheet submission or resubmission.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['submit_timesheet'])
) {

    $posted_month = $_POST['month'] ?? '';

    if (
        !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $posted_month) ||
        $posted_month !== $selected_month
    ) {

        $error_message = 'Invalid month selected.';
    } elseif (empty($employment_id)) {

        $error_message = 'No employment found.';
    } elseif (!$is_completed_month) {

        $error_message = 'You can only submit completed months.';
    } else {

        $url = SUPABASE_URL .
            '/rest/v1/rpc/submit_monthly_timesheet';

        $data = [
            'p_employment_id' => $employment_id,
            'p_month' => $selected_month . '-01'
        ];

        $submitOptions = [
            'http' => [
                'method' => 'POST',
                'header' => $headers .
                    "Content-Type: application/json\r\n",
                'content' => json_encode($data),
                'ignore_errors' => true
            ]
        ];

        $submitContext = stream_context_create($submitOptions);

        $response = file_get_contents(
            $url,
            false,
            $submitContext
        );

        $result = json_decode($response ?: '', true);

        if (
            is_array($result) &&
            ($result['success'] ?? false)
        ) {

            header(
                'Location: timesheets.php?month=' .
                    urlencode($selected_month) .
                    '&message=' .
                    urlencode('Timesheet submitted successfully!')
            );

            exit;
        } else {

            $error_message = $result['message']
                ?? 'Failed to submit timesheet.';
        }
    }
}

// Retrieve the selected month's existing timesheet.
if (!empty($employment_id)) {

    $url = SUPABASE_URL .
        '/rest/v1/timesheets?select=id,month,status,submitted_at,reviewed_at,employer_comment' .
        '&worker_id=eq.' . urlencode($worker_id) .
        '&employment_id=eq.' . urlencode($employment_id) .
        '&month=eq.' . urlencode($selected_month . '-01') .
        '&limit=1';

    $response = file_get_contents($url, false, $context);
    $timesheets = json_decode($response ?: '', true);

    if (is_array($timesheets) && !empty($timesheets)) {
        $existing_timesheet = $timesheets[0];
    }
}

// Retrieve all recorded months for timesheet history.
if (!empty($employment_id)) {

    $historyUrl = SUPABASE_URL .
        '/rest/v1/time_tracking_sessions?select=started_at,total_seconds' .
        '&worker_id=eq.' . urlencode($worker_id) .
        '&employment_id=eq.' . urlencode($employment_id) .
        '&total_seconds=gt.0' .
        '&order=started_at.desc';

    $historyResponse = file_get_contents(
        $historyUrl,
        false,
        $context
    );

    $historySessions = json_decode(
        $historyResponse ?: '',
        true
    );

    if (is_array($historySessions)) {

        $timezone = new DateTimeZone('Asia/Manila');

        foreach ($historySessions as $session) {

            $date = new DateTime($session['started_at']);
            $date->setTimezone($timezone);

            $month = $date->format('Y-m');

            if (!isset($history_months[$month])) {
                $history_months[$month] = [
                    'working_seconds' => 0
                ];
            }

            $history_months[$month]['working_seconds'] +=
                (int) $session['total_seconds'];
        }
    }

    // Retrieve existing timesheets for this employment.
    $historyTimesheetUrl = SUPABASE_URL .
        '/rest/v1/timesheets?select=month,status,employer_comment' .
        '&worker_id=eq.' . urlencode($worker_id) .
        '&employment_id=eq.' . urlencode($employment_id) .
        '&order=month.desc';

    $historyTimesheetResponse = file_get_contents(
        $historyTimesheetUrl,
        false,
        $context
    );

    $historyTimesheets = json_decode(
        $historyTimesheetResponse ?: '',
        true
    );

    if (is_array($historyTimesheets)) {

        foreach ($historyTimesheets as $timesheet) {

            $month = date(
                'Y-m',
                strtotime($timesheet['month'])
            );

            $history_timesheets[$month] = $timesheet;
        }
    }
}

// Show newest months first.
krsort($history_months);

// Calculate selected month's totals.
$monthly_working_seconds = 0;
$monthly_idle_seconds = 0;

foreach ($daily_records as $record) {

    $monthly_working_seconds += $record['working_seconds'];
    $monthly_idle_seconds += $record['idle_seconds'];
}

// Retrieve submitted daily time proofs.
if (!empty($employment_id)) {

    $month_start = new DateTime(
        $selected_month . '-01 00:00:00',
        new DateTimeZone('Asia/Manila')
    );

    $month_end = clone $month_start;
    $month_end->modify('+1 month');

    $proofUrl = SUPABASE_URL .
        '/rest/v1/daily_time_proofs?select=*' .
        '&worker_id=eq.' . urlencode($worker_id) .
        '&employment_id=eq.' . urlencode($employment_id) .
        '&work_date=gte.' . urlencode($month_start->format('Y-m-d')) .
        '&work_date=lt.' . urlencode($month_end->format('Y-m-d')) .
        '&order=work_date.desc,submitted_at.desc';

    $proofResponse = file_get_contents(
        $proofUrl,
        false,
        $context
    );

    $daily_proofs = json_decode($proofResponse ?: '', true);

    if (!is_array($daily_proofs)) {
        $daily_proofs = [];
    }

    // Generate temporary private links for screenshots.
    foreach ($daily_proofs as $index => $proof) {

        $encodedPath = implode(
            '/',
            array_map(
                'rawurlencode',
                explode('/', $proof['screenshot_path'])
            )
        );

        $signUrl = SUPABASE_URL .
            '/storage/v1/object/sign/daily-time-proofs/' .
            $encodedPath;

        $signOptions = [
            'http' => [
                'method' => 'POST',
                'header' => $headers .
                    "Content-Type: application/json\r\n",
                'content' => json_encode([
                    'expiresIn' => 3600
                ]),
                'ignore_errors' => true
            ]
        ];

        $signContext = stream_context_create($signOptions);

        $signResponse = file_get_contents(
            $signUrl,
            false,
            $signContext
        );

        $signResult = json_decode($signResponse ?: '', true);

        $signedPath = $signResult['signedURL'] ?? '';

        if ($signedPath !== '') {

            if (strpos($signedPath, 'http') === 0) {

                $daily_proofs[$index]['screenshot_url'] = $signedPath;
            } else {

                $daily_proofs[$index]['screenshot_url'] =
                    SUPABASE_URL . '/storage/v1' . $signedPath;
            }
        }

        // Organize proofs by work date.
        $proof_map[$proof['work_date']][] = $daily_proofs[$index];
    }
}

// Format time values.
function formatTimesheetDuration($seconds)
{
    $seconds = (int) $seconds;

    return sprintf(
        '%02d:%02d:%02d',
        intdiv($seconds, 3600),
        intdiv($seconds % 3600, 60),
        $seconds % 60
    );
}

// Set shared WorkPulse layout settings.
$page_title = 'My Timesheets';
$active_page = 'timesheets';
$page_styles = [
    '/assets/css/timesheets.css'
];

// Load the shared dashboard layout.
require_once '../../includes/header.php';

?>

<div class="wp-timesheets-page">

    <!-- PAGE HEADING -->
    <div class="wp-timesheets-heading">

        <div>
            <span class="wp-timesheets-eyebrow">MY WORKSPACE</span>

            <h1>My Timesheets</h1>

            <p>
                Track your working hours, submit time proof,
                and manage your monthly timesheets.
            </p>
        </div>

        <div class="wp-timesheets-heading-icon">
            <i class="bi bi-calendar2-check"></i>
        </div>

    </div>

    <!-- NOTIFICATION MESSAGES -->
    <?php if ($submission_message !== ''): ?>

        <div class="wp-timesheets-alert success">
            <i class="bi bi-check-circle-fill"></i>
            <p><?= htmlspecialchars($submission_message) ?></p>
        </div>

    <?php endif; ?>

    <?php if ($proof_message !== ''): ?>

        <div class="wp-timesheets-alert success">
            <i class="bi bi-check-circle-fill"></i>
            <p><?= htmlspecialchars($proof_message) ?></p>
        </div>

    <?php endif; ?>

    <?php if ($error_message !== ''): ?>

        <div class="wp-timesheets-alert error">
            <i class="bi bi-exclamation-circle-fill"></i>
            <p><?= htmlspecialchars($error_message) ?></p>
        </div>

    <?php endif; ?>

    <?php if (empty($employments)): ?>

        <!-- NO EMPLOYMENT -->
        <section class="wp-timesheet-panel">

            <div class="wp-timesheets-empty">

                <div class="wp-timesheets-empty-icon">
                    <i class="bi bi-briefcase"></i>
                </div>

                <h3>No Employment Found</h3>

                <p>
                    You don't have any employment records yet.
                    Your timesheets will appear here once you have an employment.
                </p>

            </div>

        </section>

    <?php else: ?>

        <!-- MONTH SELECTOR -->
        <section class="wp-month-selector">

            <div>
                <label for="month">Select Month</label>

                <input
                    type="month"
                    name="month"
                    id="month"
                    form="month-filter"
                    value="<?= htmlspecialchars($selected_month) ?>"
                    required>
            </div>

            <form method="GET" id="month-filter">

                <input
                    type="hidden"
                    name="month"
                    id="selected-month-hidden"
                    value="<?= htmlspecialchars($selected_month) ?>">

                <button type="submit" class="wp-btn wp-btn-primary">
                    <i class="bi bi-calendar3"></i>
                    View Month
                </button>

            </form>

        </section>

        <!-- TIMESHEET HISTORY -->
        <section class="wp-timesheet-panel">

            <div class="wp-timesheet-panel-header">

                <div>
                    <h2>Timesheet History</h2>

                    <p>
                        View your previous working months and submission status.
                    </p>
                </div>

                <i class="bi bi-clock-history"></i>

            </div>

            <?php if (empty($history_months)): ?>

                <div class="wp-timesheets-empty">

                    <div class="wp-timesheets-empty-icon">
                        <i class="bi bi-calendar-x"></i>
                    </div>

                    <h3>No Recorded Months</h3>

                    <p>
                        Your recorded working months will appear here.
                    </p>

                </div>

            <?php else: ?>

                <div class="wp-timesheets-table-wrapper">

                    <table class="wp-timesheets-table">

                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Total Working Hours</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($history_months as $month => $record): ?>

                                <?php
                                $month_timesheet = $history_timesheets[$month] ?? null;

                                $month_status = $month_timesheet['status'] ?? null;

                                $month_is_completed = $month < date('Y-m');

                                $history_work = (int) $record['working_seconds'];

                                $history_work_time = formatTimesheetDuration(
                                    $history_work
                                );

                                $month_label = date(
                                    'F Y',
                                    strtotime($month . '-01')
                                );
                                ?>

                                <tr>

                                    <td>
                                        <?= htmlspecialchars($month_label) ?>
                                    </td>

                                    <td>
                                        <strong><?= $history_work_time ?></strong>
                                    </td>

                                    <td>

                                        <?php if ($month_status === 'approved'): ?>

                                            <span class="wp-timesheet-status approved">
                                                Approved
                                            </span>

                                        <?php elseif ($month_status === 'submitted'): ?>

                                            <span class="wp-timesheet-status submitted">
                                                Awaiting Review
                                            </span>

                                        <?php elseif ($month_status === 'rejected'): ?>

                                            <span class="wp-timesheet-status rejected">
                                                Rejected
                                            </span>

                                        <?php elseif (!$month_is_completed): ?>

                                            <span class="wp-timesheet-status progress">
                                                In Progress
                                            </span>

                                        <?php else: ?>

                                            <span class="wp-timesheet-status pending">
                                                Not Submitted
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td class="wp-table-action">

                                        <?php if (
                                            $month_is_completed &&
                                            $history_work > 0 &&
                                            $month_status === 'rejected'
                                        ): ?>

                                            <form
                                                method="POST"
                                                action="timesheets.php?month=<?= urlencode($month) ?>">

                                                <input
                                                    type="hidden"
                                                    name="month"
                                                    value="<?= htmlspecialchars($month) ?>">

                                                <button
                                                    type="submit"
                                                    name="submit_timesheet"
                                                    class="wp-btn wp-btn-outline">

                                                    <i class="bi bi-arrow-repeat"></i>
                                                    Resubmit

                                                </button>

                                            </form>

                                        <?php elseif (
                                            $month_is_completed &&
                                            $history_work > 0 &&
                                            empty($month_timesheet)
                                        ): ?>

                                            <form
                                                method="POST"
                                                action="timesheets.php?month=<?= urlencode($month) ?>">

                                                <input
                                                    type="hidden"
                                                    name="month"
                                                    value="<?= htmlspecialchars($month) ?>">

                                                <button
                                                    type="submit"
                                                    name="submit_timesheet"
                                                    class="wp-btn wp-btn-primary">

                                                    <i class="bi bi-send"></i>
                                                    Submit

                                                </button>

                                            </form>

                                        <?php else: ?>

                                            <span class="wp-timesheets-no-action">
                                                No action needed
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                                <?php if (
                                    $month_status === 'rejected' &&
                                    !empty($month_timesheet['employer_comment'])
                                ): ?>

                                    <tr>
                                        <td colspan="4">

                                            <div class="wp-timesheet-feedback">

                                                <strong>
                                                    <i class="bi bi-chat-left-text"></i>
                                                    Employer Feedback
                                                </strong>

                                                <p>
                                                    <?= nl2br(
                                                        htmlspecialchars(
                                                            $month_timesheet['employer_comment']
                                                        )
                                                    ) ?>
                                                </p>

                                            </div>

                                        </td>
                                    </tr>

                                <?php endif; ?>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </section>

        <!-- DAILY WORK HOURS -->
        <section class="wp-timesheet-panel">

            <div class="wp-timesheet-panel-header">

                <div>
                    <h2>Daily Work Hours</h2>

                    <p>
                        Your recorded working and idle hours for
                        <?= htmlspecialchars(
                            date('F Y', strtotime($selected_month . '-01'))
                        ) ?>.
                    </p>
                </div>

                <i class="bi bi-clock"></i>

            </div>

            <?php if (empty($daily_records)): ?>

                <div class="wp-timesheets-empty">

                    <div class="wp-timesheets-empty-icon">
                        <i class="bi bi-calendar-minus"></i>
                    </div>

                    <h3>No Time Records</h3>

                    <p>
                        There are no recorded working hours for this month.
                    </p>

                </div>

            <?php else: ?>

                <div class="wp-timesheets-table-wrapper">

                    <table class="wp-timesheets-table">

                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Working Hours</th>
                                <th>Idle Hours</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($daily_records as $date => $record): ?>

                                <tr>

                                    <td>
                                        <?= htmlspecialchars(
                                            date('M d, Y', strtotime($date))
                                        ) ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= formatTimesheetDuration(
                                                $record['working_seconds']
                                            ) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= formatTimesheetDuration(
                                            $record['idle_seconds']
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </section>

        <!-- DAILY TIME PROOF UPLOAD -->
        <section class="wp-timesheet-panel">

            <div class="wp-timesheet-panel-header">

                <div>
                    <h2>Daily Time Proof</h2>

                    <p>
                        Upload a screenshot to support your recorded working hours.
                    </p>
                </div>

                <i class="bi bi-cloud-arrow-up"></i>

            </div>

            <?php if (!empty($daily_records)): ?>

                <form
                    method="POST"
                    enctype="multipart/form-data"
                    class="wp-proof-form">

                    <input
                        type="hidden"
                        name="month"
                        value="<?= htmlspecialchars($selected_month) ?>">

                    <div>
                        <label for="work_date">Work Date</label>

                        <select name="work_date" id="work_date" required>

                            <option value="">
                                Choose a recorded date
                            </option>

                            <?php foreach ($daily_records as $date => $record): ?>

                                <option value="<?= htmlspecialchars($date) ?>">
                                    <?= htmlspecialchars(
                                        date('F d, Y', strtotime($date))
                                    ) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div>
                        <label for="screenshot">Screenshot</label>

                        <input
                            type="file"
                            name="screenshot"
                            id="screenshot"
                            accept="image/png,image/jpeg,image/webp"
                            required>

                        <small class="wp-form-hint">
                            JPG, PNG, or WebP. Maximum file size: 5 MB.
                        </small>
                    </div>

                    <div class="full-width">

                        <label for="worker_note">
                            Note <span>(Optional)</span>
                        </label>

                        <textarea
                            name="worker_note"
                            id="worker_note"
                            rows="3"
                            maxlength="1000"
                            placeholder="Add a short explanation about your screenshot..."></textarea>

                    </div>

                    <div class="full-width">

                        <button
                            type="submit"
                            name="submit_time_proof"
                            class="wp-btn wp-btn-primary">

                            <i class="bi bi-upload"></i>
                            Submit Time Proof

                        </button>

                    </div>

                </form>

            <?php else: ?>

                <p class="wp-timesheets-muted">
                    You need recorded working hours before submitting time proof.
                </p>

            <?php endif; ?>

        </section>

        <!-- SUBMITTED DAILY PROOFS -->
        <section class="wp-timesheet-panel">

            <div class="wp-timesheet-panel-header">

                <div>
                    <h2>My Submitted Time Proofs</h2>

                    <p>
                        Screenshots and feedback for
                        <?= htmlspecialchars(
                            date('F Y', strtotime($selected_month . '-01'))
                        ) ?>.
                    </p>
                </div>

                <i class="bi bi-file-earmark-image"></i>

            </div>

            <?php if (empty($daily_proofs)): ?>

                <div class="wp-timesheets-empty">

                    <div class="wp-timesheets-empty-icon">
                        <i class="bi bi-images"></i>
                    </div>

                    <h3>No Time Proofs Yet</h3>

                    <p>
                        Your submitted screenshots will appear here.
                    </p>

                </div>

            <?php else: ?>

                <div class="wp-proof-list">

                    <?php foreach ($daily_proofs as $proof): ?>

                        <article class="wp-proof-card">

                            <div class="wp-proof-card-header">

                                <div>
                                    <h3>
                                        <?= htmlspecialchars(
                                            date(
                                                'F d, Y',
                                                strtotime($proof['work_date'])
                                            )
                                        ) ?>
                                    </h3>

                                    <p>
                                        Submitted:
                                        <?= htmlspecialchars(
                                            $proof['submitted_at'] ?? 'N/A'
                                        ) ?>
                                    </p>
                                </div>

                                <?php
                                $proof_status = $proof['status'] ?? 'pending';

                                $proof_status_class = 'pending';

                                if ($proof_status === 'approved') {
                                    $proof_status_class = 'approved';
                                } elseif ($proof_status === 'rejected') {
                                    $proof_status_class = 'rejected';
                                } elseif ($proof_status === 'submitted') {
                                    $proof_status_class = 'submitted';
                                }
                                ?>

                                <span class="wp-timesheet-status <?= $proof_status_class ?>">
                                    <?= htmlspecialchars(
                                        ucwords(str_replace('_', ' ', $proof_status))
                                    ) ?>
                                </span>

                            </div>

                            <?php if (!empty($proof['worker_note'])): ?>

                                <div class="wp-proof-detail">

                                    <strong>Your Note</strong>

                                    <p>
                                        <?= nl2br(
                                            htmlspecialchars($proof['worker_note'])
                                        ) ?>
                                    </p>

                                </div>

                            <?php endif; ?>

                            <?php if (!empty($proof['employer_comment'])): ?>

                                <div class="wp-timesheet-feedback">

                                    <strong>Employer Feedback</strong>

                                    <p>
                                        <?= nl2br(
                                            htmlspecialchars($proof['employer_comment'])
                                        ) ?>
                                    </p>

                                </div>

                            <?php endif; ?>

                            <?php if (!empty($proof['screenshot_url'])): ?>

                                <a
                                    href="<?= htmlspecialchars($proof['screenshot_url']) ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="wp-proof-image-link">

                                    <i class="bi bi-image"></i>
                                    View Screenshot

                                    <i class="bi bi-box-arrow-up-right"></i>

                                </a>

                            <?php else: ?>

                                <p class="wp-timesheets-muted">
                                    Screenshot preview is temporarily unavailable.
                                </p>

                            <?php endif; ?>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </section>

        <!-- MONTHLY SUMMARY -->
        <section class="wp-timesheet-panel">

            <div class="wp-timesheet-panel-header">

                <div>
                    <h2>Monthly Summary</h2>

                    <p>
                        Your total recorded hours for
                        <?= htmlspecialchars(
                            date('F Y', strtotime($selected_month . '-01'))
                        ) ?>.
                    </p>
                </div>

                <i class="bi bi-bar-chart"></i>

            </div>

            <div class="wp-monthly-summary">

                <div class="wp-summary-card">

                    <div class="wp-summary-icon">
                        <i class="bi bi-clock-history"></i>
                    </div>

                    <div>
                        <span>Total Working Hours</span>

                        <strong>
                            <?= formatTimesheetDuration(
                                $monthly_working_seconds
                            ) ?>
                        </strong>
                    </div>

                </div>

                <div class="wp-summary-card">

                    <div class="wp-summary-icon idle">
                        <i class="bi bi-cup-hot"></i>
                    </div>

                    <div>
                        <span>Total Idle Hours</span>

                        <strong>
                            <?= formatTimesheetDuration(
                                $monthly_idle_seconds
                            ) ?>
                        </strong>
                    </div>

                </div>

            </div>

        </section>

        <!-- MONTHLY TIMESHEET STATUS -->
        <section class="wp-timesheet-panel">

            <div class="wp-timesheet-panel-header">

                <div>
                    <h2>Monthly Timesheet Status</h2>

                    <p>
                        Submission and employer review information.
                    </p>
                </div>

                <i class="bi bi-clipboard-check"></i>

            </div>

            <div class="wp-current-timesheet-status">

                <?php if (!empty($existing_timesheet)): ?>

                    <?php
                    $current_status = $existing_timesheet['status'];

                    $current_status_class = 'pending';

                    if ($current_status === 'approved') {
                        $current_status_class = 'approved';
                    } elseif ($current_status === 'rejected') {
                        $current_status_class = 'rejected';
                    } elseif ($current_status === 'submitted') {
                        $current_status_class = 'submitted';
                    }
                    ?>

                    <p>
                        <strong>Status:</strong>

                        <span class="wp-timesheet-status <?= $current_status_class ?>">
                            <?= htmlspecialchars(
                                ucfirst($current_status)
                            ) ?>
                        </span>
                    </p>

                    <?php if (!empty($existing_timesheet['submitted_at'])): ?>

                        <p>
                            <strong>Submitted:</strong>
                            <?= htmlspecialchars(
                                $existing_timesheet['submitted_at']
                            ) ?>
                        </p>

                    <?php endif; ?>

                    <?php if (!empty($existing_timesheet['reviewed_at'])): ?>

                        <p>
                            <strong>Last Reviewed:</strong>
                            <?= htmlspecialchars(
                                $existing_timesheet['reviewed_at']
                            ) ?>
                        </p>

                    <?php endif; ?>

                    <?php if (!empty($existing_timesheet['employer_comment'])): ?>

                        <div class="wp-timesheet-feedback">

                            <strong>
                                <i class="bi bi-chat-left-text"></i>
                                Employer Feedback
                            </strong>

                            <p>
                                <?= nl2br(
                                    htmlspecialchars(
                                        $existing_timesheet['employer_comment']
                                    )
                                ) ?>
                            </p>

                        </div>

                    <?php endif; ?>

                <?php else: ?>

                    <p>
                        No monthly timesheet has been submitted for this month.
                    </p>

                <?php endif; ?>

                <!-- SUBMISSION RULE -->
                <?php if (
                    !empty($time_sessions) &&
                    $is_completed_month &&
                    (
                        empty($existing_timesheet) ||
                        $existing_timesheet['status'] === 'rejected'
                    )
                ): ?>

                    <?php if (
                        !empty($existing_timesheet) &&
                        $existing_timesheet['status'] === 'rejected'
                    ): ?>

                        <div class="wp-timesheets-alert error">
                            <i class="bi bi-exclamation-circle-fill"></i>

                            <p>
                                Your timesheet was rejected.
                                Review your employer's feedback before resubmitting.
                            </p>
                        </div>

                    <?php endif; ?>

                    <div class="wp-timesheet-actions">

                        <form method="POST">

                            <input
                                type="hidden"
                                name="month"
                                value="<?= htmlspecialchars($selected_month) ?>">

                            <button
                                type="submit"
                                name="submit_timesheet"
                                class="wp-btn wp-btn-primary">

                                <?php if (
                                    !empty($existing_timesheet) &&
                                    $existing_timesheet['status'] === 'rejected'
                                ): ?>

                                    <i class="bi bi-arrow-repeat"></i>
                                    Resubmit Timesheet

                                <?php else: ?>

                                    <i class="bi bi-send"></i>
                                    Submit Monthly Timesheet

                                <?php endif; ?>

                            </button>

                        </form>

                    </div>

                <?php elseif (!$is_completed_month): ?>

                    <div class="wp-timesheets-alert info">
                        <i class="bi bi-info-circle-fill"></i>

                        <p>
                            You can submit your monthly timesheet after the month has ended.
                        </p>
                    </div>

                <?php elseif (!empty($existing_timesheet)): ?>

                    <?php if ($existing_timesheet['status'] === 'submitted'): ?>

                        <div class="wp-timesheets-alert info">
                            <i class="bi bi-hourglass-split"></i>

                            <p>
                                Your timesheet is awaiting employer review.
                            </p>
                        </div>

                    <?php elseif ($existing_timesheet['status'] === 'approved'): ?>

                        <div class="wp-timesheets-alert success">
                            <i class="bi bi-check-circle-fill"></i>

                            <p>
                                Your timesheet has been approved.
                                No further action is required.
                            </p>
                        </div>

                    <?php endif; ?>

                <?php endif; ?>

            </div>

        </section>

    <?php endif; ?>

</div>

<script>
    const monthInput = document.getElementById('month');
    const hiddenMonthInput = document.getElementById('selected-month-hidden');

    if (monthInput && hiddenMonthInput) {
        monthInput.addEventListener('change', function() {
            hiddenMonthInput.value = this.value;
        });
    }
</script>

<?php require_once '../../includes/footer.php'; ?>