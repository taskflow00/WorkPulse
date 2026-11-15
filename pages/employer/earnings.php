<?php
$required_role = 'employer';
require_once '../../includes/auth.php';

$employerId = $_SESSION['user']['id'];
$accessToken = $_SESSION['access_token'];

// Common Supabase request headers.
$headers = [
    "apikey: " . SUPABASE_KEY,
    "Authorization: Bearer " . $accessToken,
    "Content-Type: application/json"
];

$headerText = implode("\r\n", $headers);

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => $headerText,
        'ignore_errors' => true
    ]
]);

// Initialize data.
$earningsRecords = [];
$employments = [];
$workers = [];
$jobs = [];

$workerNames = [];
$jobTitles = [];
$employmentInfo = [];

$monthlyTotals = [];

$confirmedEarnings = 0;
$pendingEarnings = 0;

$approvedHours = 0;
$pendingHours = 0;

$errorMessage = "";

// ==========================================
// 1. GET EMPLOYMENTS BELONGING TO EMPLOYER
// ==========================================

$employmentUrl = SUPABASE_URL .
    "/rest/v1/employments" .
    "?select=id,worker_id,job_id" .
    "&employer_id=eq." . urlencode($employerId);

$employmentResponse = file_get_contents($employmentUrl, false, $context);

if ($employmentResponse === false) {

    $errorMessage = "Unable to retrieve employment records.";
} else {

    $employmentData = json_decode($employmentResponse, true);

    if (!is_array($employmentData) || isset($employmentData['code'])) {

        $errorMessage = "Unable to read employment records.";
    } else {

        $employments = $employmentData;
    }
}

// ==========================================
// 2. GET EARNINGS RECORDS
// ==========================================

if ($errorMessage === "") {

    $earningsUrl = SUPABASE_URL .
        "/rest/v1/earnings_records" .
        "?select=*" .
        "&employer_id=eq." . urlencode($employerId) .
        "&timesheet_status=in.(submitted,approved)" .
        "&order=month.desc";

    $earningsResponse = file_get_contents($earningsUrl, false, $context);

    if ($earningsResponse === false) {

        $errorMessage = "Unable to retrieve earnings records.";
    } else {

        $earningsData = json_decode($earningsResponse, true);

        if (!is_array($earningsData) || isset($earningsData['code'])) {

            $errorMessage = "Unable to read earnings records.";
        } else {

            $earningsRecords = $earningsData;
        }
    }
}

// ==========================================
// 3. PREPARE EMPLOYMENT INFORMATION
// ==========================================

$workerIds = [];
$jobIds = [];

foreach ($employments as $employment) {

    $employmentInfo[$employment['id']] = $employment;

    $workerIds[] = $employment['worker_id'];
    $jobIds[] = $employment['job_id'];
}

$workerIds = array_unique($workerIds);
$jobIds = array_unique($jobIds);

// ==========================================
// 4. GET WORKER NAMES
// ==========================================

if ($errorMessage === "" && count($workerIds) > 0) {

    $workerUrl = SUPABASE_URL .
        "/rest/v1/profiles" .
        "?select=id,full_name" .
        "&id=in.(" . implode(',', $workerIds) . ")";

    $workerResponse = file_get_contents($workerUrl, false, $context);

    if ($workerResponse !== false) {

        $workerData = json_decode($workerResponse, true);

        if (is_array($workerData) && !isset($workerData['code'])) {

            $workers = $workerData;

            foreach ($workers as $worker) {
                $workerNames[$worker['id']] = $worker['full_name'];
            }
        }
    }
}

// ==========================================
// 5. GET JOB TITLES
// ==========================================

if ($errorMessage === "" && count($jobIds) > 0) {

    $jobUrl = SUPABASE_URL .
        "/rest/v1/jobs" .
        "?select=id,title" .
        "&id=in.(" . implode(',', $jobIds) . ")";

    $jobResponse = file_get_contents($jobUrl, false, $context);

    if ($jobResponse !== false) {

        $jobData = json_decode($jobResponse, true);

        if (is_array($jobData) && !isset($jobData['code'])) {

            $jobs = $jobData;

            foreach ($jobs as $job) {
                $jobTitles[$job['id']] = $job['title'];
            }
        }
    }
}

// ==========================================
// 6. CALCULATE LABOR COSTS
// ==========================================

foreach ($earningsRecords as $record) {

    $workingSeconds = (int) $record['total_working_seconds'];

    $workingHours = $workingSeconds / 3600;

    $amount = (float) $record['earnings_amount'];

    $month = $record['month'];

    // Create monthly summary if it does not exist.
    if (!isset($monthlyTotals[$month])) {

        $monthlyTotals[$month] = [
            'approved' => 0,
            'pending' => 0
        ];
    }

    // Approved timesheets are confirmed labor costs.
    if ($record['timesheet_status'] === 'approved') {

        $confirmedEarnings += $amount;
        $approvedHours += $workingHours;

        $monthlyTotals[$month]['approved'] += $amount;
    }

    // Submitted timesheets are pending labor costs.
    elseif ($record['timesheet_status'] === 'submitted') {

        $pendingEarnings += $amount;
        $pendingHours += $workingHours;

        $monthlyTotals[$month]['pending'] += $amount;
    }
}

$totalLaborCost = $confirmedEarnings + $pendingEarnings;
$totalWorkingHours = $approvedHours + $pendingHours;

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Employer Earnings | WorkPulse</title>
</head>

<body>

    <a href="dashboard.php">Back to Dashboard</a>

    <h1>Labor Costs</h1>

    <p>
        Monitor your workforce's labor costs using submitted
        and approved timesheets.
    </p>

    <p>Currency: USD ($)</p>

    <?php if ($errorMessage !== ""): ?>

        <p>
            <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
        </p>

    <?php else: ?>

        <!-- ==================================
             LABOR COST SUMMARY
        =================================== -->

        <h2>Labor Cost Summary</h2>

        <h3>Confirmed Labor Cost</h3>

        <p>
            $<?= number_format($confirmedEarnings, 2) ?>
        </p>

        <p>From approved timesheets.</p>


        <h3>Pending Labor Cost</h3>

        <p>
            $<?= number_format($pendingEarnings, 2) ?>
        </p>

        <p>Awaiting employer approval.</p>


        <h3>Total Labor Cost</h3>

        <p>
            $<?= number_format($totalLaborCost, 2) ?>
        </p>

        <p>Approved and submitted timesheets combined.</p>


        <!-- ==================================
             WORKING HOURS
        =================================== -->

        <h2>Working Hours</h2>

        <h3>Approved Hours</h3>

        <p>
            <?= number_format($approvedHours, 2) ?> hours
        </p>


        <h3>Pending Hours</h3>

        <p>
            <?= number_format($pendingHours, 2) ?> hours
        </p>


        <h3>Total Recorded Hours</h3>

        <p>
            <?= number_format($totalWorkingHours, 2) ?> hours
        </p>


        <!-- ==================================
             MONTHLY LABOR COST
        =================================== -->

        <h2>Monthly Labor Cost</h2>

        <?php if (count($monthlyTotals) > 0): ?>

            <table border="1">

                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Confirmed Cost</th>
                        <th>Pending Cost</th>
                        <th>Total Cost</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($monthlyTotals as $month => $totals): ?>

                        <tr>

                            <td>
                                <?= date('F Y', strtotime($month)) ?>
                            </td>

                            <td>
                                $<?= number_format($totals['approved'], 2) ?>
                            </td>

                            <td>
                                $<?= number_format($totals['pending'], 2) ?>
                            </td>

                            <td>
                                $<?= number_format(
                                        $totals['approved'] + $totals['pending'],
                                        2
                                    ) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        <?php else: ?>

            <p>No monthly labor cost records found yet.</p>

        <?php endif; ?>


        <!-- ==================================
             LABOR COST BY WORKER
        =================================== -->

        <h2>Labor Cost by Worker</h2>

        <?php if (count($earningsRecords) > 0): ?>

            <table border="1">

                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Worker</th>
                        <th>Job</th>
                        <th>Working Hours</th>
                        <th>Hourly Rate</th>
                        <th>Labor Cost</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($earningsRecords as $record): ?>

                        <?php
                        $employmentId = $record['employment_id'];

                        $employment = $employmentInfo[$employmentId] ?? [];

                        $workerId = $employment['worker_id'] ?? '';
                        $jobId = $employment['job_id'] ?? '';

                        $workerName = $workerNames[$workerId] ?? 'Unknown Worker';
                        $jobTitle = $jobTitles[$jobId] ?? 'Unknown Job';

                        $workingHours = (int) $record['total_working_seconds'] / 3600;

                        $hourlyRate = (float) $record['hourly_rate'];
                        $earningsAmount = (float) $record['earnings_amount'];

                        $status = $record['timesheet_status'];
                        ?>

                        <tr>

                            <td>
                                <?= date('F Y', strtotime($record['month'])) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($workerName, ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($jobTitle, ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <td>
                                <?= number_format($workingHours, 2) ?>
                            </td>

                            <td>
                                $<?= number_format($hourlyRate, 2) ?>/hr
                            </td>

                            <td>
                                $<?= number_format($earningsAmount, 2) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        <?php else: ?>

            <p>No earnings records found yet.</p>

        <?php endif; ?>

    <?php endif; ?>

    <hr>

    <p>
        Labor costs are calculated using working hours multiplied by
        the agreed hourly rate. Idle time is excluded.
    </p>

    <p>
        Pending labor costs are not confirmed until the employer
        approves the timesheet. These figures do not mean that
        payment has already been made.
    </p>

</body>

</html>