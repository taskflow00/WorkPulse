<?php
session_start();
require_once '../../config/supabase.php';

// Use Philippine time.
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

// Common headers.
$headers =
    "apikey: " . SUPABASE_KEY . "\r\n" .
    "Authorization: Bearer " . $access_token . "\r\n";

// Prepare messages.
$submission_message = $_GET['message'] ?? '';
$proof_message = '';
$error_message = '';

// Retrieve the worker's employments.
$url = SUPABASE_URL .
    '/rest/v1/employments?select=id,worker_id' .
    '&worker_id=eq.' . urlencode($worker_id);

$options = [
    'http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
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

// Prepare arrays for time records and daily totals.
$time_sessions = [];
$daily_records = [];

// Retrieve the worker's time records.
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

    // Make sure the selected month is valid.
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

                    // Save the screenshot information in the database.
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

                        // Redirect to prevent duplicate submission on refresh.
                        header(
                            'Location: timesheets.php?month=' .
                                urlencode($selected_month) .
                                '&proof_message=' .
                                urlencode('Daily time proof submitted successfully!')
                        );

                        exit;
                    } else {

                        // Remove the uploaded screenshot if saving failed.
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

// Handle monthly timesheet submission.
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
    } else {

        $url = SUPABASE_URL .
            '/rest/v1/rpc/submit_monthly_timesheet';

        $data = [
            'p_employment_id' => $employment_id,
            'p_month' => $selected_month . '-01'
        ];

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => $headers .
                    "Content-Type: application/json\r\n",
                'content' => json_encode($data),
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

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

// Display proof success message after redirect.
if (isset($_GET['proof_message'])) {
    $proof_message = $_GET['proof_message'];
}

// Retrieve the existing monthly timesheet.
$existing_timesheet = null;

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

// Prepare monthly totals.
$monthly_working_seconds = 0;
$monthly_idle_seconds = 0;

foreach ($daily_records as $record) {

    $monthly_working_seconds += $record['working_seconds'];
    $monthly_idle_seconds += $record['idle_seconds'];
}

// Retrieve submitted daily time proofs.
$daily_proofs = [];
$proof_map = [];

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
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Timesheets - WorkPulse</title>

    <link rel="stylesheet" href="../../assets/css/style.css">
</head>

<body>

    <main class="container">

        <a href="dashboard.php">← Back to Dashboard</a>

        <h1>My Timesheets</h1>

        <p>View your daily working hours and submit time proof.</p>

        <?php if ($submission_message !== ''): ?>

            <p>
                <?= htmlspecialchars($submission_message) ?>
            </p>

        <?php endif; ?>

        <?php if ($proof_message !== ''): ?>

            <p>
                <?= htmlspecialchars($proof_message) ?>
            </p>

        <?php endif; ?>

        <?php if ($error_message !== ''): ?>

            <p>
                <?= htmlspecialchars($error_message) ?>
            </p>

        <?php endif; ?>

        <?php if (empty($employments)): ?>

            <p>You don't have any employments yet.</p>

        <?php else: ?>

            <!-- MONTH SELECTOR -->
            <form method="GET">

                <label for="month">Select Month:</label>

                <input
                    type="month"
                    name="month"
                    id="month"
                    value="<?= htmlspecialchars($selected_month) ?>"
                    required>

                <button type="submit">View Timesheet</button>

            </form>

            <!-- DAILY WORK AND IDLE HOURS -->
            <h2>Daily Work Hours</h2>

            <?php if (empty($daily_records)): ?>

                <p>No time records found for this month.</p>

            <?php else: ?>

                <table border="1" cellpadding="10">

                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Work Hours</th>
                            <th>Idle Hours</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($daily_records as $date => $record): ?>

                            <?php
                            $work = $record['working_seconds'];
                            $idle = $record['idle_seconds'];

                            $work_time = sprintf(
                                '%02d:%02d:%02d',
                                intdiv($work, 3600),
                                intdiv($work % 3600, 60),
                                $work % 60
                            );

                            $idle_time = sprintf(
                                '%02d:%02d:%02d',
                                intdiv($idle, 3600),
                                intdiv($idle % 3600, 60),
                                $idle % 60
                            );
                            ?>

                            <tr>
                                <td>
                                    <?= htmlspecialchars($date) ?>
                                </td>

                                <td><?= $work_time ?></td>

                                <td><?= $idle_time ?></td>
                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

            <!-- DAILY TIME PROOF UPLOAD -->
            <h2>Daily Time Proof</h2>

            <p>
                Upload a screenshot to support your recorded working hours.
                You can submit proof even if the month is not yet completed.
            </p>

            <?php if (!empty($daily_records)): ?>

                <form method="POST" enctype="multipart/form-data">

                    <input
                        type="hidden"
                        name="month"
                        value="<?= htmlspecialchars($selected_month) ?>">

                    <label for="work_date">Select Work Date:</label>

                    <select name="work_date" id="work_date" required>

                        <option value="">Choose a recorded date</option>

                        <?php foreach ($daily_records as $date => $record): ?>

                            <option value="<?= htmlspecialchars($date) ?>">
                                <?= htmlspecialchars($date) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                    <br><br>

                    <label for="screenshot">Screenshot:</label>

                    <input
                        type="file"
                        name="screenshot"
                        id="screenshot"
                        accept="image/png,image/jpeg,image/webp"
                        required>

                    <p>
                        <small>
                            PNG, JPG, or WebP. Maximum file size: 5 MB.
                        </small>
                    </p>

                    <label for="worker_note">Note (Optional):</label>

                    <textarea
                        name="worker_note"
                        id="worker_note"
                        rows="3"
                        maxlength="1000"
                        placeholder="Add a short explanation about your screenshot..."></textarea>

                    <br><br>

                    <button type="submit" name="submit_time_proof">
                        Submit Time Proof
                    </button>

                </form>

            <?php else: ?>

                <p>
                    You need recorded working hours before submitting time proof.
                </p>

            <?php endif; ?>

            <!-- SUBMITTED DAILY PROOFS -->
            <h2>My Submitted Time Proofs</h2>

            <?php if (empty($daily_proofs)): ?>

                <p>You haven't submitted any time proofs for this month.</p>

            <?php else: ?>

                <?php foreach ($daily_proofs as $proof): ?>

                    <section class="timesheet-card">

                        <h3>
                            <?= htmlspecialchars($proof['work_date']) ?>
                        </h3>

                        <p>
                            Status:
                            <strong>
                                <?= htmlspecialchars(
                                    ucwords(str_replace('_', ' ', $proof['status']))
                                ) ?>
                            </strong>
                        </p>

                        <?php if (!empty($proof['worker_note'])): ?>

                            <p>
                                Your Note:
                                <?= htmlspecialchars($proof['worker_note']) ?>
                            </p>

                        <?php endif; ?>

                        <?php if (!empty($proof['employer_comment'])): ?>

                            <p>
                                Employer Comment:
                                <?= htmlspecialchars($proof['employer_comment']) ?>
                            </p>

                        <?php endif; ?>

                        <?php if (!empty($proof['screenshot_url'])): ?>

                            <p>
                                <a
                                    href="<?= htmlspecialchars($proof['screenshot_url']) ?>"
                                    target="_blank"
                                    rel="noopener noreferrer">
                                    View My Screenshot
                                </a>
                            </p>

                        <?php else: ?>

                            <p>Screenshot preview is temporarily unavailable.</p>

                        <?php endif; ?>

                        <hr>

                    </section>

                <?php endforeach; ?>

            <?php endif; ?>

            <!-- MONTHLY SUMMARY -->
            <h2>Monthly Summary</h2>

            <?php
            $monthly_work = sprintf(
                '%02d:%02d:%02d',
                intdiv($monthly_working_seconds, 3600),
                intdiv($monthly_working_seconds % 3600, 60),
                $monthly_working_seconds % 60
            );

            $monthly_idle = sprintf(
                '%02d:%02d:%02d',
                intdiv($monthly_idle_seconds, 3600),
                intdiv($monthly_idle_seconds % 3600, 60),
                $monthly_idle_seconds % 60
            );
            ?>

            <p>
                Total Work Hours:
                <strong><?= $monthly_work ?></strong>
            </p>

            <p>
                Total Idle Hours:
                <strong><?= $monthly_idle ?></strong>
            </p>

            <!-- EXISTING MONTHLY TIMESHEET STATUS -->
            <h2>Monthly Timesheet Status</h2>

            <?php if (!empty($existing_timesheet)): ?>

                <p>
                    Status:
                    <strong>
                        <?= htmlspecialchars(
                            ucfirst($existing_timesheet['status'])
                        ) ?>
                    </strong>
                </p>

                <?php if (!empty($existing_timesheet['submitted_at'])): ?>

                    <p>
                        Submitted:
                        <?= htmlspecialchars($existing_timesheet['submitted_at']) ?>
                    </p>

                <?php endif; ?>

                <?php if (!empty($existing_timesheet['reviewed_at'])): ?>

                    <p>
                        Reviewed:
                        <?= htmlspecialchars($existing_timesheet['reviewed_at']) ?>
                    </p>

                <?php endif; ?>

                <?php if (!empty($existing_timesheet['employer_comment'])): ?>

                    <p>
                        Employer Comment:
                        <?= htmlspecialchars($existing_timesheet['employer_comment']) ?>
                    </p>

                <?php endif; ?>

            <?php else: ?>

                <p>No monthly timesheet has been submitted for this month.</p>

            <?php endif; ?>

            <!-- EXISTING MONTHLY SUBMISSION RULE -->
            <?php if (
                !empty($time_sessions) &&
                $is_completed_month &&
                empty($existing_timesheet)
            ): ?>

                <form method="POST">

                    <input
                        type="hidden"
                        name="month"
                        value="<?= htmlspecialchars($selected_month) ?>">

                    <button type="submit" name="submit_timesheet">
                        Submit Monthly Timesheet
                    </button>

                </form>

            <?php elseif (!$is_completed_month): ?>

                <p>
                    You can submit your monthly timesheet after the month has ended.
                </p>

            <?php elseif (!empty($existing_timesheet)): ?>

                <p>This month's timesheet has already been submitted.</p>

            <?php endif; ?>

        <?php endif; ?>

        <br>

        <a href="dashboard.php">Back to Dashboard</a>

    </main>

</body>

</html>