<?php
session_start();
require_once '../../config/supabase.php';

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

// Prepare earnings variables.
$earnings_records = [];
$approved_earnings = 0;
$pending_earnings = 0;
$approved_hours = 0;
$pending_hours = 0;
$error_message = '';

// Retrieve the worker's earnings records.
$url = SUPABASE_URL .
    '/rest/v1/earnings_records?select=*' .
    '&worker_id=eq.' . urlencode($worker_id) .
    '&order=month.desc';

$options = [
    'http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);

$response = file_get_contents($url, false, $context);

$result = json_decode($response ?: '', true);

// Check the response.
if ($response === false) {

    $error_message = 'Unable to connect to the earnings service.';
} elseif (!is_array($result) || isset($result['message'])) {

    $error_message = $result['message']
        ?? 'Unable to retrieve earnings records.';
} else {

    $earnings_records = $result;

    // Calculate earnings summaries.
    foreach ($earnings_records as $record) {

        $amount = (float) $record['earnings_amount'];
        $working_seconds = (int) $record['total_working_seconds'];

        // Only approved timesheets count as confirmed earnings.
        if ($record['timesheet_status'] === 'approved') {

            $approved_earnings += $amount;
            $approved_hours += $working_seconds / 3600;

            // Submitted timesheets count as pending earnings.
        } elseif ($record['timesheet_status'] === 'submitted') {

            $pending_earnings += $amount;
            $pending_hours += $working_seconds / 3600;
        }
    }
}

// Format earnings as USD.
$approved_earnings_formatted = number_format(
    $approved_earnings,
    2
);

$pending_earnings_formatted = number_format(
    $pending_earnings,
    2
);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Earnings - WorkPulse</title>

    <link rel="stylesheet" href="../../assets/css/style.css">
</head>

<body>

    <main class="container">

        <a href="dashboard.php">← Back to Dashboard</a>

        <h1>My Earnings</h1>

        <p>
            Track your earnings from your approved and submitted timesheets.
            All amounts are displayed in USD.
        </p>

        <?php if ($error_message !== ''): ?>

            <p>
                <?= htmlspecialchars($error_message) ?>
            </p>

        <?php else: ?>

            <!-- EARNINGS SUMMARY -->
            <h2>Earnings Summary</h2>

            <section class="earnings-summary">

                <div class="earnings-card">

                    <h3>Confirmed Earnings</h3>

                    <p>
                        <strong>
                            $<?= $approved_earnings_formatted ?>
                        </strong>
                    </p>

                    <small>
                        From approved timesheets
                    </small>

                </div>

                <div class="earnings-card">

                    <h3>Pending Earnings</h3>

                    <p>
                        <strong>
                            $<?= $pending_earnings_formatted ?>
                        </strong>
                    </p>

                    <small>
                        Awaiting employer approval
                    </small>

                </div>

            </section>

            <!-- WORKING HOURS SUMMARY -->
            <h2>Working Hours</h2>

            <p>
                Approved Hours:
                <strong>
                    <?= number_format($approved_hours, 2) ?> hours
                </strong>
            </p>

            <p>
                Pending Hours:
                <strong>
                    <?= number_format($pending_hours, 2) ?> hours
                </strong>
            </p>

            <!-- MONTHLY EARNINGS HISTORY -->
            <h2>Monthly Earnings History</h2>

            <?php if (empty($earnings_records)): ?>

                <p>
                    No earnings records found yet.
                </p>

            <?php else: ?>

                <table border="1" cellpadding="10">

                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Working Hours</th>
                            <th>Hourly Rate</th>
                            <th>Status</th>
                            <th>Earnings</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($earnings_records as $record): ?>

                            <?php
                            $month = new DateTime($record['month']);

                            $month_label = $month->format('F Y');

                            $working_seconds = (int)
                            $record['total_working_seconds'];

                            $working_hours = $working_seconds / 3600;

                            $hourly_rate = (float)
                            $record['hourly_rate'];

                            $amount = (float)
                            $record['earnings_amount'];

                            $status = $record['timesheet_status'];
                            ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars($month_label) ?>
                                </td>

                                <td>
                                    <?= number_format($working_hours, 2) ?>
                                    hours
                                </td>

                                <td>
                                    $<?= number_format($hourly_rate, 2) ?>/hr
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        ucfirst($status)
                                    ) ?>
                                </td>

                                <td>

                                    <?php if ($status === 'approved'): ?>

                                        <strong>
                                            $<?= number_format($amount, 2) ?>
                                        </strong>

                                    <?php elseif ($status === 'submitted'): ?>

                                        $<?= number_format($amount, 2) ?>
                                        (Pending)

                                    <?php else: ?>

                                        Not counted

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

            <p>
                <small>
                    Earnings are calculated from working hours,
                    excluding idle time. Pending earnings are not
                    confirmed until the employer approves the timesheet.
                    These amounts do not indicate that payment has
                    already been made.
                </small>
            </p>

        <?php endif; ?>

        <br>

        <a href="dashboard.php">Back to Dashboard</a>

    </main>

</body>

</html>