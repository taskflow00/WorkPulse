<?php
$required_role = 'employer';
require_once '../../includes/auth.php';
require_once '../../includes/send-email.php';
require_once '../../includes/email-templates.php';

date_default_timezone_set('Asia/Manila');

$employer_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$headers =
    "apikey: " . SUPABASE_KEY . "\r\n" .
    "Authorization: Bearer " . $access_token . "\r\n";

// GET SELECTED MONTH.
$selected_month = $_GET['month'] ?? date('Y-m');

if (
    !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $selected_month)
) {
    $selected_month = date('Y-m');
}

$timezone = new DateTimeZone('Asia/Manila');

$month_start = new DateTime($selected_month . '-01 00:00:00', $timezone);
$month_end = clone $month_start;
$month_end->modify('+1 month');

$month_start_date = $month_start->format('Y-m-d');
$month_end_date = $month_end->format('Y-m-d');

$month_start_iso = $month_start->format('c');
$month_end_iso = $month_end->format('c');

// GET SUCCESS MESSAGE.
$message = '';

if (isset($_SESSION['timesheet_message'])) {
    $message = $_SESSION['timesheet_message'];
    unset($_SESSION['timesheet_message']);
}

$error = '';

$get_options = [
    'http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true
    ]
];

$context = stream_context_create($get_options);


// REVIEW MONTHLY TIMESHEET.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['review_timesheet'])
) {

    $timesheet_id = $_POST['timesheet_id'] ?? '';
    $status = $_POST['status'] ?? '';
    $comment = trim($_POST['comment'] ?? '');

    if (
        $timesheet_id === '' ||
        !in_array($status, ['approved', 'rejected'])
    ) {

        $error = 'Invalid timesheet review request.';
    } elseif (strlen($comment) > 1000) {

        $error = 'Comment must not exceed 1000 characters.';
    } else {

        $url = SUPABASE_URL . '/rest/v1/rpc/review_monthly_timesheet';

        $data = [
            'p_timesheet_id' => $timesheet_id,
            'p_status' => $status,
            'p_comment' => $comment
        ];

        $review_options = [
            'http' => [
                'method' => 'POST',
                'header' => $headers .
                    "Content-Type: application/json\r\n",
                'content' => json_encode($data),
                'ignore_errors' => true
            ]
        ];

        $review_context = stream_context_create($review_options);

        $response = file_get_contents($url, false, $review_context);
        $result = json_decode($response ?: '', true);


        if (
            is_array($result) &&
            ($result['success'] ?? false)
        ) {

            // SEND WORKER EMAIL NOTIFICATION.
            if (isEmailConfigured()) {

                // Get the reviewed timesheet and calculated earnings.
                $timesheet_url = SUPABASE_URL .
                    '/rest/v1/earnings_records?select=timesheet_id,worker_id,month,total_working_seconds,hourly_rate,earnings_amount,timesheet_status' .
                    '&timesheet_id=eq.' . urlencode($timesheet_id);

                $timesheet_response = file_get_contents(
                    $timesheet_url,
                    false,
                    $context
                );

                $earnings_records = json_decode(
                    $timesheet_response ?: '',
                    true
                );

                if (
                    is_array($earnings_records) &&
                    !empty($earnings_records[0])
                ) {

                    $record = $earnings_records[0];

                    // Only send an approval email for an approved timesheet.
                    if ($record['timesheet_status'] === $status) {

                        $worker_id = $record['worker_id'];

                        // Get worker name.
                        $profile_url = SUPABASE_URL .
                            '/rest/v1/profiles?select=full_name' .
                            '&id=eq.' . urlencode($worker_id);

                        $profile_response = file_get_contents(
                            $profile_url,
                            false,
                            $context
                        );

                        $profile_data = json_decode(
                            $profile_response ?: '',
                            true
                        );

                        $worker_name = $profile_data[0]['full_name'] ?? 'Worker';

                        // Get worker email through Supabase Auth Admin API.
                        $service_key = defined('SUPABASE_SERVICE_ROLE_KEY')
                            ? SUPABASE_SERVICE_ROLE_KEY
                            : '';

                        if ($service_key !== '') {

                            $user_url = SUPABASE_URL .
                                '/auth/v1/admin/users/' .
                                urlencode($worker_id);

                            $user_options = [
                                'http' => [
                                    'method' => 'GET',
                                    'header' =>
                                    "apikey: " . $service_key . "\r\n" .
                                        "Authorization: Bearer " . $service_key . "\r\n",
                                    'ignore_errors' => true
                                ]
                            ];

                            $user_context = stream_context_create($user_options);

                            $user_response = file_get_contents(
                                $user_url,
                                false,
                                $user_context
                            );

                            $user_data = json_decode(
                                $user_response ?: '',
                                true
                            );

                            $worker_email = $user_data['email'] ?? '';

                            if ($worker_email !== '') {

                                $month_label = date(
                                    'F Y',
                                    strtotime($record['month'])
                                );

                                if ($status === 'approved') {

                                    $working_hours = number_format(
                                        (int) $record['total_working_seconds'] / 3600,
                                        2
                                    );

                                    $email_message = timesheetApprovedEmail(
                                        $worker_name,
                                        $month_label,
                                        $working_hours,
                                        $record['hourly_rate'],
                                        $record['earnings_amount']
                                    );

                                    $email_subject = 'Timesheet Approved - ' . $month_label;
                                } else {

                                    $email_message = timesheetRejectedEmail(
                                        $worker_name,
                                        $month_label,
                                        $comment
                                    );

                                    $email_subject = 'Timesheet Rejected - ' . $month_label;
                                }

                                if (!sendEmail(
                                    $worker_email,
                                    $email_subject,
                                    $email_message
                                )) {
                                    error_log(
                                        'WorkPulse timesheet email failed: ' .
                                            getEmailError()
                                    );
                                }
                            } else {
                                error_log(
                                    'WorkPulse timesheet notification skipped: worker email not found.'
                                );
                            }
                        } else {
                            error_log(
                                'WorkPulse timesheet notification skipped: service role key is not configured.'
                            );
                        }
                    }
                } else {
                    error_log(
                        'WorkPulse timesheet notification failed: earnings record not found.'
                    );
                }
            }

            $_SESSION['timesheet_message'] =
                $result['message'] ?? 'Timesheet successfully reviewed.';

            header(
                'Location: timesheets.php?month=' .
                    urlencode($_POST['selected_month'] ?? $selected_month)
            );

            exit;
        } else {

            $error = $result['message'] ?? 'Unable to review timesheet.';
        }
    }
}


// PREPARE ARRAYS.
$employments = [];
$timesheets = [];
$workers = [];
$jobs = [];
$entries = [];
$proofs = [];
$time_sessions = [];

$employment_map = [];
$worker_map = [];
$job_map = [];
$entry_map = [];
$live_daily_records = [];


// GET EMPLOYMENTS.
$url = SUPABASE_URL .
    '/rest/v1/employments?select=id,worker_id,job_id' .
    '&employer_id=eq.' . urlencode($employer_id);

$response = file_get_contents($url, false, $context);
$employments = json_decode($response ?: '', true);

if (!is_array($employments)) {
    $employments = [];
}


// GET EMPLOYMENT-RELATED INFORMATION.
if (!empty($employments)) {

    $employment_ids = [];
    $worker_ids = [];
    $job_ids = [];

    foreach ($employments as $employment) {

        $employment_ids[] = $employment['id'];
        $worker_ids[] = $employment['worker_id'];
        $job_ids[] = $employment['job_id'];

        $employment_map[$employment['id']] = $employment;
    }

    $employment_list = implode(',', array_unique($employment_ids));
    $worker_list = implode(',', array_unique($worker_ids));
    $job_list = implode(',', array_unique($job_ids));


    // GET WORKER NAMES.
    $url = SUPABASE_URL .
        '/rest/v1/profiles?select=id,full_name' .
        '&id=in.(' . $worker_list . ')';

    $response = file_get_contents($url, false, $context);
    $workers = json_decode($response ?: '', true);

    if (!is_array($workers)) {
        $workers = [];
    }

    foreach ($workers as $worker) {
        $worker_map[$worker['id']] = $worker['full_name'];
    }


    // GET JOB TITLES.
    $url = SUPABASE_URL .
        '/rest/v1/jobs?select=id,title' .
        '&id=in.(' . $job_list . ')';

    $response = file_get_contents($url, false, $context);
    $jobs = json_decode($response ?: '', true);

    if (!is_array($jobs)) {
        $jobs = [];
    }

    foreach ($jobs as $job) {
        $job_map[$job['id']] = $job['title'];
    }


    // GET MONTHLY TIMESHEETS.
    $url = SUPABASE_URL .
        '/rest/v1/timesheets?select=*' .
        '&employment_id=in.(' . $employment_list . ')' .
        '&month=eq.' . urlencode($month_start_date) .
        '&order=month.desc';

    $response = file_get_contents($url, false, $context);
    $timesheets = json_decode($response ?: '', true);

    if (!is_array($timesheets)) {
        $timesheets = [];
    }


    // GET DAILY TIMESHEET ENTRIES.
    if (!empty($timesheets)) {

        $timesheet_ids = [];

        foreach ($timesheets as $timesheet) {
            $timesheet_ids[] = $timesheet['id'];
        }

        $timesheet_list = implode(',', $timesheet_ids);

        $url = SUPABASE_URL .
            '/rest/v1/timesheet_entries?select=*' .
            '&timesheet_id=in.(' . $timesheet_list . ')' .
            '&order=work_date.asc';

        $response = file_get_contents($url, false, $context);
        $entries = json_decode($response ?: '', true);

        if (!is_array($entries)) {
            $entries = [];
        }

        foreach ($entries as $entry) {
            $entry_map[$entry['timesheet_id']][] = $entry;
        }
    }


    // GET LIVE TIME TRACKING SESSIONS.
    // This works independently of monthly timesheet submission.
    $url = SUPABASE_URL .
        '/rest/v1/time_tracking_sessions?select=employment_id,worker_id,started_at,total_seconds,idle_seconds' .
        '&employment_id=in.(' . $employment_list . ')' .
        '&started_at=gte.' . urlencode($month_start_iso) .
        '&started_at=lt.' . urlencode($month_end_iso) .
        '&order=started_at.desc';

    $response = file_get_contents($url, false, $context);
    $time_sessions = json_decode($response ?: '', true);

    if (!is_array($time_sessions)) {
        $time_sessions = [];
    }

    // GROUP LIVE SESSIONS BY EMPLOYMENT AND MANILA DATE.
    foreach ($time_sessions as $session) {

        $date = new DateTime($session['started_at']);
        $date->setTimezone($timezone);

        $work_date = $date->format('Y-m-d');
        $employment_id = $session['employment_id'];

        if (!isset($live_daily_records[$employment_id])) {
            $live_daily_records[$employment_id] = [];
        }

        if (!isset($live_daily_records[$employment_id][$work_date])) {
            $live_daily_records[$employment_id][$work_date] = [
                'working_seconds' => 0,
                'idle_seconds' => 0
            ];
        }

        $live_daily_records[$employment_id][$work_date]['working_seconds'] +=
            (int) $session['total_seconds'];

        $live_daily_records[$employment_id][$work_date]['idle_seconds'] +=
            (int) $session['idle_seconds'];
    }

    // SORT DAILY RECORDS BY DATE.
    foreach ($live_daily_records as $employment_id => $records) {
        krsort($live_daily_records[$employment_id]);
    }


    // GET DAILY TIME PROOFS.
    $url = SUPABASE_URL .
        '/rest/v1/daily_time_proofs?select=*' .
        '&employment_id=in.(' . $employment_list . ')' .
        '&work_date=gte.' . urlencode($month_start_date) .
        '&work_date=lt.' . urlencode($month_end_date) .
        '&order=work_date.desc,created_at.desc';

    $response = file_get_contents($url, false, $context);
    $proofs = json_decode($response ?: '', true);

    if (!is_array($proofs)) {
        $proofs = [];
    }

    // GENERATE SIGNED SCREENSHOT LINKS.
    foreach ($proofs as $index => $proof) {

        $employment_id = $proof['employment_id'];

        $employment = $employment_map[$employment_id] ?? [];

        $proofs[$index]['worker_name'] =
            $worker_map[$employment['worker_id'] ?? ''] ?? 'Unknown Worker';

        $proofs[$index]['job_title'] =
            $job_map[$employment['job_id'] ?? ''] ?? 'Unknown Job';

        if (empty($proof['screenshot_path'])) {
            continue;
        }

        $encoded_path = implode(
            '/',
            array_map(
                'rawurlencode',
                explode('/', $proof['screenshot_path'])
            )
        );

        $sign_url = SUPABASE_URL .
            '/storage/v1/object/sign/daily-time-proofs/' .
            $encoded_path;

        $sign_options = [
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

        $sign_context = stream_context_create($sign_options);

        $sign_response = file_get_contents(
            $sign_url,
            false,
            $sign_context
        );

        $sign_result = json_decode($sign_response ?: '', true);

        if (!empty($sign_result['signedURL'])) {

            $signed_url = $sign_result['signedURL'];

            if (strpos($signed_url, 'http') !== 0) {
                $signed_url = SUPABASE_URL . '/storage/v1' . $signed_url;
            }

            $proofs[$index]['screenshot_url'] = $signed_url;
        }
    }
}


// FORMAT SECONDS INTO HH:MM:SS.
function formatEmployerTimesheetDuration($seconds)
{
    $seconds = (int) $seconds;

    return sprintf(
        '%02d:%02d:%02d',
        intdiv($seconds, 3600),
        intdiv($seconds % 3600, 60),
        $seconds % 60
    );
}


// PAGE SETTINGS.
$page_title = 'Timesheets';
$active_page = 'timesheets';
$page_styles = ['/assets/css/timesheets.css'];

require_once '../../includes/header.php';
?>

<div class="wp-timesheets-page">

    <div class="wp-timesheets-heading">
        <div>
            <span class="wp-timesheets-eyebrow">EMPLOYER WORKSPACE</span>
            <h1>Timesheets</h1>
            <p>Review worker hours, view supporting time proofs, and manage monthly submissions.</p>
        </div>

        <div class="wp-timesheets-heading-icon">
            <i class="bi bi-calendar2-check"></i>
        </div>
    </div>


    <?php if ($message !== ''): ?>
        <div class="wp-timesheets-alert success">
            <i class="bi bi-check-circle-fill"></i>
            <p><?= htmlspecialchars($message) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="wp-timesheets-alert error">
            <i class="bi bi-exclamation-circle-fill"></i>
            <p><?= htmlspecialchars($error) ?></p>
        </div>
    <?php endif; ?>


    <!-- MONTH FILTER -->
    <section class="wp-timesheet-panel">

        <div class="wp-timesheet-panel-header">
            <div>
                <h2>Filter by Month</h2>
                <p>Select a month to view worker hours, timesheets, and time proofs.</p>
            </div>

            <i class="bi bi-calendar3"></i>
        </div>

        <form method="GET" class="wp-proof-form">

            <div class="full-width">
                <label for="month">Select Month</label>

                <input
                    type="month"
                    id="month"
                    name="month"
                    value="<?= htmlspecialchars($selected_month) ?>"
                    required>
            </div>

            <div class="full-width">
                <button type="submit" class="wp-btn wp-btn-primary">
                    <i class="bi bi-funnel"></i>
                    Apply Filter
                </button>
            </div>

        </form>

    </section>


    <?php if (empty($employments)): ?>

        <section class="wp-timesheet-panel">
            <div class="wp-timesheets-empty">
                <div class="wp-timesheets-empty-icon">
                    <i class="bi bi-people"></i>
                </div>

                <h3>No Workers Found</h3>
                <p>Your worker timesheets will appear here once you have active employments.</p>
            </div>
        </section>

    <?php else: ?>


        <!-- DAILY WORK HOURS -->
        <section class="wp-timesheet-panel">

            <div class="wp-timesheet-panel-header">
                <div>
                    <h2>Daily Work Hours</h2>
                    <p>
                        Recorded working and idle hours for
                        <?= htmlspecialchars(date('F Y', strtotime($month_start_date))) ?>.
                    </p>
                </div>

                <i class="bi bi-clock"></i>
            </div>

            <?php
            $has_daily_records = false;

            foreach ($live_daily_records as $records) {
                if (!empty($records)) {
                    $has_daily_records = true;
                    break;
                }
            }
            ?>

            <?php if (!$has_daily_records): ?>

                <div class="wp-timesheets-empty">
                    <div class="wp-timesheets-empty-icon">
                        <i class="bi bi-calendar-minus"></i>
                    </div>

                    <h3>No Recorded Work Hours</h3>
                    <p>No worker time tracking records were found for this month.</p>
                </div>

            <?php else: ?>

                <?php foreach ($employments as $employment): ?>

                    <?php
                    $employment_id = $employment['id'];
                    $records = $live_daily_records[$employment_id] ?? [];

                    if (empty($records)) {
                        continue;
                    }

                    $worker_name =
                        $worker_map[$employment['worker_id']] ?? 'Unknown Worker';

                    $job_title =
                        $job_map[$employment['job_id']] ?? 'Unknown Job';
                    ?>

                    <article class="wp-proof-card">

                        <div class="wp-proof-card-header">
                            <div>
                                <h3><?= htmlspecialchars($worker_name) ?></h3>
                                <p><?= htmlspecialchars($job_title) ?></p>
                            </div>
                        </div>

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

                                    <?php foreach ($records as $date => $record): ?>

                                        <tr>
                                            <td>
                                                <?= htmlspecialchars(date('M d, Y', strtotime($date))) ?>
                                            </td>

                                            <td>
                                                <strong>
                                                    <?= formatEmployerTimesheetDuration($record['working_seconds']) ?>
                                                </strong>
                                            </td>

                                            <td>
                                                <?= formatEmployerTimesheetDuration($record['idle_seconds']) ?>
                                            </td>
                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>
                            </table>
                        </div>

                    </article>

                <?php endforeach; ?>

            <?php endif; ?>

        </section>


        <!-- WORKER MONTHLY TIMESHEETS -->
        <section class="wp-timesheet-panel">

            <div class="wp-timesheet-panel-header">
                <div>
                    <h2>Worker Timesheets</h2>
                    <p>
                        Monthly submissions for
                        <?= htmlspecialchars(date('F Y', strtotime($month_start_date))) ?>.
                    </p>
                </div>

                <i class="bi bi-clock-history"></i>
            </div>

            <?php if (empty($timesheets)): ?>

                <div class="wp-timesheets-empty">
                    <div class="wp-timesheets-empty-icon">
                        <i class="bi bi-calendar-x"></i>
                    </div>

                    <h3>No Timesheets Yet</h3>
                    <p>No monthly timesheets have been submitted for this month.</p>
                </div>

            <?php else: ?>

                <?php foreach ($timesheets as $timesheet): ?>

                    <?php
                    $employment = $employment_map[$timesheet['employment_id']] ?? [];

                    $worker_name =
                        $worker_map[$employment['worker_id'] ?? ''] ?? 'Unknown Worker';

                    $job_title =
                        $job_map[$employment['job_id'] ?? ''] ?? 'Unknown Job';

                    $working_seconds = (int) $timesheet['total_working_seconds'];
                    $idle_seconds = (int) $timesheet['total_idle_seconds'];

                    $status = $timesheet['status'];
                    $status_class = 'pending';

                    if ($status === 'approved') {
                        $status_class = 'approved';
                    } elseif ($status === 'rejected') {
                        $status_class = 'rejected';
                    } elseif ($status === 'submitted') {
                        $status_class = 'submitted';
                    }
                    ?>

                    <article class="wp-proof-card">

                        <div class="wp-proof-card-header">
                            <div>
                                <h3><?= htmlspecialchars($worker_name) ?></h3>
                                <p><?= htmlspecialchars($job_title) ?></p>
                            </div>

                            <span class="wp-timesheet-status <?= $status_class ?>">
                                <?= htmlspecialchars(ucfirst($status)) ?>
                            </span>
                        </div>

                        <p>
                            <strong>Month:</strong>
                            <?= htmlspecialchars(date('F Y', strtotime($timesheet['month']))) ?>
                        </p>

                        <!-- MONTHLY SUMMARY -->
                        <div class="wp-monthly-summary">

                            <div class="wp-summary-card">
                                <div class="wp-summary-icon">
                                    <i class="bi bi-clock-history"></i>
                                </div>

                                <div>
                                    <span>Total Working Hours</span>
                                    <strong>
                                        <?= formatEmployerTimesheetDuration($working_seconds) ?>
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
                                        <?= formatEmployerTimesheetDuration($idle_seconds) ?>
                                    </strong>
                                </div>
                            </div>

                        </div>


                        <!-- SUBMISSION DETAILS -->
                        <p>
                            <strong>Submitted:</strong>

                            <?= !empty($timesheet['submitted_at'])
                                ? htmlspecialchars(date('M d, Y h:i A', strtotime($timesheet['submitted_at'])))
                                : '—' ?>
                        </p>

                        <?php if (!empty($timesheet['reviewed_at'])): ?>
                            <p>
                                <strong>Reviewed:</strong>
                                <?= htmlspecialchars(date('M d, Y h:i A', strtotime($timesheet['reviewed_at']))) ?>
                            </p>
                        <?php endif; ?>


                        <!-- EMPLOYER COMMENT -->
                        <?php if (!empty($timesheet['employer_comment'])): ?>
                            <div class="wp-timesheet-feedback">
                                <strong>Employer Comment</strong>
                                <p><?= nl2br(htmlspecialchars($timesheet['employer_comment'])) ?></p>
                            </div>
                        <?php endif; ?>


                        <!-- DAILY TIMESHEET ENTRIES -->
                        <h3>Submitted Daily Work Hours</h3>

                        <?php if (!empty($entry_map[$timesheet['id']])): ?>

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

                                        <?php foreach ($entry_map[$timesheet['id']] as $entry): ?>

                                            <tr>
                                                <td>
                                                    <?= htmlspecialchars(date('M d, Y', strtotime($entry['work_date']))) ?>
                                                </td>

                                                <td>
                                                    <?= formatEmployerTimesheetDuration($entry['working_seconds']) ?>
                                                </td>

                                                <td>
                                                    <?= formatEmployerTimesheetDuration($entry['idle_seconds']) ?>
                                                </td>
                                            </tr>

                                        <?php endforeach; ?>

                                    </tbody>
                                </table>
                            </div>

                        <?php else: ?>

                            <p class="wp-timesheets-muted">No daily entries found.</p>

                        <?php endif; ?>


                        <!-- REVIEW ACTIONS -->
                        <?php if ($status === 'submitted'): ?>

                            <h3>Review Timesheet</h3>

                            <form method="POST" class="wp-proof-form">

                                <input
                                    type="hidden"
                                    name="timesheet_id"
                                    value="<?= htmlspecialchars($timesheet['id']) ?>">

                                <input
                                    type="hidden"
                                    name="selected_month"
                                    value="<?= htmlspecialchars($selected_month) ?>">

                                <div class="full-width">
                                    <label for="comment-<?= htmlspecialchars($timesheet['id']) ?>">
                                        Employer Comment
                                    </label>

                                    <textarea
                                        id="comment-<?= htmlspecialchars($timesheet['id']) ?>"
                                        name="comment"
                                        rows="3"
                                        maxlength="1000"
                                        placeholder="Write feedback or reason for rejection..."></textarea>
                                </div>

                                <div class="full-width">

                                    <button
                                        type="submit"
                                        name="review_timesheet"
                                        value="1"
                                        class="wp-btn wp-btn-primary"
                                        onclick="this.form.status.value='approved'">

                                        <i class="bi bi-check-circle"></i>
                                        Approve
                                    </button>

                                    <button
                                        type="submit"
                                        name="review_timesheet"
                                        value="1"
                                        class="wp-btn wp-btn-primary"
                                        onclick="this.form.status.value='rejected'">

                                        <i class="bi bi-x-circle"></i>
                                        Reject
                                    </button>

                                    <input type="hidden" name="status" value="">

                                </div>

                            </form>

                        <?php elseif ($status === 'rejected'): ?>

                            <div class="wp-timesheets-alert error">
                                <i class="bi bi-exclamation-circle-fill"></i>
                                <p>This timesheet was rejected. The worker may correct and resubmit it.</p>
                            </div>

                        <?php elseif ($status === 'approved'): ?>

                            <div class="wp-timesheets-alert success">
                                <i class="bi bi-check-circle-fill"></i>
                                <p>This timesheet has been approved and finalized.</p>
                            </div>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            <?php endif; ?>

        </section>


        <!-- DAILY TIME PROOFS -->
        <section class="wp-timesheet-panel">

            <div class="wp-timesheet-panel-header">
                <div>
                    <h2>Daily Time Proofs</h2>
                    <p>
                        Worker screenshots submitted for
                        <?= htmlspecialchars(date('F Y', strtotime($month_start_date))) ?>.
                    </p>
                </div>

                <i class="bi bi-images"></i>
            </div>

            <?php if (empty($proofs)): ?>

                <div class="wp-timesheets-empty">
                    <div class="wp-timesheets-empty-icon">
                        <i class="bi bi-image"></i>
                    </div>

                    <h3>No Daily Time Proofs</h3>
                    <p>No workers have uploaded time proofs for this month.</p>
                </div>

            <?php else: ?>

                <div class="wp-proof-list">

                    <?php foreach ($proofs as $proof): ?>

                        <article class="wp-proof-card">

                            <div class="wp-proof-card-header">
                                <div>
                                    <h3><?= htmlspecialchars($proof['worker_name']) ?></h3>
                                    <p><?= htmlspecialchars($proof['job_title']) ?></p>
                                </div>

                                <span class="wp-timesheet-status submitted">
                                    <?= htmlspecialchars(date('M d, Y', strtotime($proof['work_date']))) ?>
                                </span>
                            </div>

                            <p>
                                <strong>Submitted:</strong>

                                <?= !empty($proof['created_at'])
                                    ? htmlspecialchars(date('M d, Y h:i A', strtotime($proof['created_at'])))
                                    : 'N/A' ?>
                            </p>

                            <?php if (!empty($proof['worker_note'])): ?>
                                <div class="wp-proof-detail">
                                    <strong>Worker Note</strong>
                                    <p><?= nl2br(htmlspecialchars($proof['worker_note'])) ?></p>
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
                                    Screenshot unavailable.
                                </p>

                            <?php endif; ?>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </section>

    <?php endif; ?>

</div>

<?php require_once '../../includes/footer.php'; ?>