<?php
$required_role = 'employer';
require_once '../../includes/auth.php';

$employerId = $_SESSION['user']['id'];
$accessToken = $_SESSION['access_token'];

$message = '';
$error = '';

// Common headers for Supabase requests.
$headers = [
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . $accessToken,
    'Content-Type: application/json'
];

// Handle timesheet approval or rejection.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['review_timesheet'])
) {
    $timesheetId = $_POST['timesheet_id'] ?? '';
    $status = $_POST['status'] ?? '';
    $comment = $_POST['comment'] ?? '';

    if (
        $timesheetId === '' ||
        !in_array($status, ['approved', 'rejected'], true)
    ) {
        $error = 'Invalid timesheet review request.';
    } else {
        $reviewData = [
            'p_timesheet_id' => $timesheetId,
            'p_status' => $status,
            'p_comment' => $comment
        ];

        $reviewOptions = [
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($reviewData),
                'ignore_errors' => true
            ]
        ];

        $reviewContext = stream_context_create($reviewOptions);

        $reviewResponse = file_get_contents(
            SUPABASE_URL . '/rest/v1/rpc/review_monthly_timesheet',
            false,
            $reviewContext
        );

        $reviewResult = json_decode($reviewResponse ?: '', true);

        if (
            is_array($reviewResult) &&
            ($reviewResult['success'] ?? false)
        ) {
            header(
                'Location: timesheets.php?message=' .
                    urlencode($reviewResult['message'])
            );
            exit;
        } else {
            $error = $reviewResult['message']
                ?? 'Unable to review timesheet.';
        }
    }
}

// Display success message after redirect.
if (isset($_GET['message'])) {
    $message = $_GET['message'];
}

// Common GET request options.
$getOptions = [
    'http' => [
        'method' => 'GET',
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true
    ]
];

// Retrieve employments belonging to this employer.
$employmentUrl = SUPABASE_URL .
    '/rest/v1/employments?select=id,worker_id,job_id' .
    '&employer_id=eq.' . urlencode($employerId);

$employmentContext = stream_context_create($getOptions);

$employmentResponse = file_get_contents(
    $employmentUrl,
    false,
    $employmentContext
);

$employments = json_decode($employmentResponse ?: '', true);

if (!is_array($employments)) {
    $employments = [];
}

// Prepare arrays.
$timesheets = [];
$workers = [];
$jobs = [];
$entries = [];

// Retrieve timesheets if the employer has employments.
if (!empty($employments)) {

    // Collect employment IDs.
    $employmentIds = [];

    foreach ($employments as $employment) {
        $employmentIds[] = $employment['id'];
    }

    $employmentList = implode(',', $employmentIds);

    // Retrieve submitted timesheets.
    $timesheetUrl = SUPABASE_URL .
        '/rest/v1/timesheets?select=*' .
        '&employment_id=in.(' . $employmentList . ')' .
        '&order=month.desc';

    $timesheetResponse = file_get_contents(
        $timesheetUrl,
        false,
        $employmentContext
    );

    $timesheets = json_decode($timesheetResponse ?: '', true);

    if (!is_array($timesheets)) {
        $timesheets = [];
    }

    // Only retrieve additional information if timesheets exist.
    if (!empty($timesheets)) {

        $workerIds = [];
        $jobIds = [];
        $timesheetIds = [];

        foreach ($employments as $employment) {
            $workerIds[] = $employment['worker_id'];
            $jobIds[] = $employment['job_id'];
        }

        foreach ($timesheets as $timesheet) {
            $timesheetIds[] = $timesheet['id'];
        }

        $workerIds = array_unique($workerIds);
        $jobIds = array_unique($jobIds);

        // Retrieve worker names.
        if (!empty($workerIds)) {

            $workerList = implode(',', $workerIds);

            $workerUrl = SUPABASE_URL .
                '/rest/v1/profiles?select=id,full_name' .
                '&id=in.(' . $workerList . ')';

            $workerResponse = file_get_contents(
                $workerUrl,
                false,
                $employmentContext
            );

            $workers = json_decode($workerResponse ?: '', true);

            if (!is_array($workers)) {
                $workers = [];
            }
        }

        // Retrieve job titles.
        if (!empty($jobIds)) {

            $jobList = implode(',', $jobIds);

            $jobUrl = SUPABASE_URL .
                '/rest/v1/jobs?select=id,title' .
                '&id=in.(' . $jobList . ')';

            $jobResponse = file_get_contents(
                $jobUrl,
                false,
                $employmentContext
            );

            $jobs = json_decode($jobResponse ?: '', true);

            if (!is_array($jobs)) {
                $jobs = [];
            }
        }

        // Retrieve daily timesheet entries.
        $timesheetList = implode(',', $timesheetIds);

        $entryUrl = SUPABASE_URL .
            '/rest/v1/timesheet_entries?select=*' .
            '&timesheet_id=in.(' . $timesheetList . ')' .
            '&order=work_date.asc';

        $entryResponse = file_get_contents(
            $entryUrl,
            false,
            $employmentContext
        );

        $entries = json_decode($entryResponse ?: '', true);

        if (!is_array($entries)) {
            $entries = [];
        }
    }
}

// Organize employments by ID.
$employmentMap = [];

foreach ($employments as $employment) {
    $employmentMap[$employment['id']] = $employment;
}

// Organize worker names by ID.
$workerMap = [];

foreach ($workers as $worker) {
    $workerMap[$worker['id']] = $worker['full_name'];
}

// Organize job titles by ID.
$jobMap = [];

foreach ($jobs as $job) {
    $jobMap[$job['id']] = $job['title'];
}

// Organize daily entries by timesheet ID.
$entryMap = [];

foreach ($entries as $entry) {
    $entryMap[$entry['timesheet_id']][] = $entry;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Timesheets | WorkPulse</title>

    <link rel="stylesheet" href="../../assets/css/style.css">
</head>

<body>

    <main class="container">

        <a href="dashboard.php">← Back to Dashboard</a>

        <h1>Timesheets</h1>

        <p>Review and manage submitted worker timesheets.</p>

        <?php if ($message !== ''): ?>

            <p>
                <?= htmlspecialchars($message) ?>
            </p>

        <?php endif; ?>

        <?php if ($error !== ''): ?>

            <p>
                <?= htmlspecialchars($error) ?>
            </p>

        <?php endif; ?>

        <?php if (empty($timesheets)): ?>

            <p>No submitted timesheets yet.</p>

        <?php else: ?>

            <?php foreach ($timesheets as $timesheet): ?>

                <?php
                $employment = $employmentMap[$timesheet['employment_id']] ?? [];

                $workerName = $workerMap[$employment['worker_id'] ?? ''] ?? 'Unknown Worker';

                $jobTitle = $jobMap[$employment['job_id'] ?? ''] ?? 'Unknown Job';

                $workingSeconds = (int) $timesheet['total_working_seconds'];
                $idleSeconds = (int) $timesheet['total_idle_seconds'];

                // Format monthly working time.
                $workingTime = sprintf(
                    '%02d:%02d:%02d',
                    intdiv($workingSeconds, 3600),
                    intdiv($workingSeconds % 3600, 60),
                    $workingSeconds % 60
                );

                // Format monthly idle time.
                $idleTime = sprintf(
                    '%02d:%02d:%02d',
                    intdiv($idleSeconds, 3600),
                    intdiv($idleSeconds % 3600, 60),
                    $idleSeconds % 60
                );
                ?>

                <section class="timesheet-card">

                    <h2>
                        <?= htmlspecialchars($workerName) ?>
                    </h2>

                    <p>
                        Job: <?= htmlspecialchars($jobTitle) ?>
                    </p>

                    <p>
                        Month:
                        <?= htmlspecialchars(
                            date('F Y', strtotime($timesheet['month']))
                        ) ?>
                    </p>

                    <p>
                        Status:
                        <strong>
                            <?= htmlspecialchars(
                                ucfirst($timesheet['status'])
                            ) ?>
                        </strong>
                    </p>

                    <?php if (!empty($timesheet['employer_comment'])): ?>

                        <p>
                            Previous Employer Comment:
                            <?= htmlspecialchars($timesheet['employer_comment']) ?>
                        </p>

                    <?php endif; ?>

                    <?php if (!empty($timesheet['reviewed_at'])): ?>

                        <p>
                            Reviewed:
                            <?= htmlspecialchars(
                                date(
                                    'M d, Y h:i A',
                                    strtotime($timesheet['reviewed_at'])
                                )
                            ) ?>
                        </p>

                    <?php endif; ?>

                    <h3>Monthly Summary</h3>

                    <p>
                        Total Work Hours:
                        <strong><?= $workingTime ?></strong>
                    </p>

                    <p>
                        Total Idle Hours:
                        <strong><?= $idleTime ?></strong>
                    </p>

                    <p>
                        Submitted:
                        <?= htmlspecialchars(
                            date(
                                'M d, Y h:i A',
                                strtotime($timesheet['submitted_at'])
                            )
                        ) ?>
                    </p>

                    <!-- DAILY WORK AND IDLE HOURS -->
                    <h3>Daily Work Hours</h3>

                    <?php if (!empty($entryMap[$timesheet['id']])): ?>

                        <table border="1" cellpadding="8">

                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Work Hours</th>
                                    <th>Idle Hours</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($entryMap[$timesheet['id']] as $entry): ?>

                                    <?php
                                    $dailyWorking = (int) $entry['working_seconds'];
                                    $dailyIdle = (int) $entry['idle_seconds'];

                                    $dailyWorkingTime = sprintf(
                                        '%02d:%02d:%02d',
                                        intdiv($dailyWorking, 3600),
                                        intdiv($dailyWorking % 3600, 60),
                                        $dailyWorking % 60
                                    );

                                    $dailyIdleTime = sprintf(
                                        '%02d:%02d:%02d',
                                        intdiv($dailyIdle, 3600),
                                        intdiv($dailyIdle % 3600, 60),
                                        $dailyIdle % 60
                                    );
                                    ?>

                                    <tr>

                                        <td>
                                            <?= htmlspecialchars($entry['work_date']) ?>
                                        </td>

                                        <td>
                                            <?= $dailyWorkingTime ?>
                                        </td>

                                        <td>
                                            <?= $dailyIdleTime ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    <?php else: ?>

                        <p>No daily entries found.</p>

                    <?php endif; ?>

                    <!-- APPROVE OR REJECT -->

                    <!-- APPROVE OR REJECT -->
                    <?php if ($timesheet['status'] === 'submitted'): ?>

                        <h3>Review Timesheet</h3>

                        <form method="POST">

                            <input
                                type="hidden"
                                name="review_timesheet"
                                value="1">

                            <input
                                type="hidden"
                                name="timesheet_id"
                                value="<?= htmlspecialchars($timesheet['id']) ?>">

                            <label for="comment-<?= htmlspecialchars($timesheet['id']) ?>">
                                Employer Comment
                            </label>

                            <textarea
                                id="comment-<?= htmlspecialchars($timesheet['id']) ?>"
                                name="comment"
                                rows="3"
                                placeholder="Write your feedback or reason for rejection..."></textarea>

                            <button
                                type="submit"
                                name="status"
                                value="approved">
                                Approve
                            </button>

                            <button
                                type="submit"
                                name="status"
                                value="rejected">
                                Reject
                            </button>

                        </form>

                    <?php elseif ($timesheet['status'] === 'rejected'): ?>

                        <p>
                            This timesheet was rejected. The worker may correct and resubmit it.
                        </p>

                    <?php elseif ($timesheet['status'] === 'approved'): ?>

                        <p>This timesheet has been approved and is finalized.</p>

                    <?php endif; ?>


                </section>

                <hr>

            <?php endforeach; ?>

        <?php endif; ?>

    </main>

</body>

</html>