<?php

// ==========================================
// WORKER EARNINGS
// ==========================================

// Page information
$required_role = 'worker';
$page_title = 'My Earnings';
$active_page = 'earnings';

// Check authentication and worker role
require_once '../../includes/auth.php';

// Get logged-in worker information
$worker_id = $_SESSION['user']['id'];
$token = $_SESSION['access_token'];

// ==========================================
// INITIALIZE EARNINGS DATA
// ==========================================

$earnings_records = [];

$approved_earnings = 0;
$pending_earnings = 0;

$approved_hours = 0;
$pending_hours = 0;

$error_message = '';


// ==========================================
// GET WORKER EARNINGS RECORDS
// ==========================================

// Retrieve the worker's earnings records from Supabase
$earnings_url = SUPABASE_URL .
    '/rest/v1/earnings_records?select=*' .
    '&worker_id=eq.' . urlencode($worker_id) .
    '&order=month.desc';


// Prepare the GET request
$earnings_context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $token . "\r\n" .
            "Accept: application/json\r\n",
        'ignore_errors' => true
    ]
]);


// Send request to Supabase
$earnings_response = file_get_contents(
    $earnings_url,
    false,
    $earnings_context
);


// Convert the response into a PHP array
$earnings_data = json_decode($earnings_response ?: '', true);


// Check the response
if ($earnings_response === false) {

    $error_message = 'Unable to connect to the earnings service.';
} elseif (!is_array($earnings_data) || isset($earnings_data['code'])) {

    $error_message = $earnings_data['message']
        ?? 'Unable to retrieve earnings records.';
} else {

    $earnings_records = $earnings_data;

    // Calculate earnings summaries
    foreach ($earnings_records as $record) {

        $amount = (float) ($record['earnings_amount'] ?? 0);

        $working_seconds = (int) ($record['total_working_seconds'] ?? 0);

        $working_hours = $working_seconds / 3600;

        $status = $record['timesheet_status'] ?? '';

        // Approved timesheets count as confirmed earnings
        if ($status === 'approved') {

            $approved_earnings += $amount;
            $approved_hours += $working_hours;
        }

        // Submitted timesheets count as pending earnings
        elseif ($status === 'submitted') {

            $pending_earnings += $amount;
            $pending_hours += $working_hours;
        }
    }
}


// ==========================================
// CALCULATE TOTALS
// ==========================================

$total_earnings = $approved_earnings + $pending_earnings;

$total_hours = $approved_hours + $pending_hours;

?>


<?php require_once '../../includes/header.php'; ?>


<div class="wp-page">

    <!-- ======================================
         PAGE HEADING
    ======================================= -->

    <section class="wp-dashboard-welcome">

        <div>

            <span class="wp-dashboard-eyebrow">
                FINANCIAL OVERVIEW
            </span>

            <h1 class="wp-page-title">
                My Earnings
            </h1>

            <p class="wp-page-description">
                Track your confirmed earnings, pending earnings, and working hours.
            </p>

        </div>

    </section>


    <?php if ($error_message !== ''): ?>

        <!-- ==================================
             ERROR MESSAGE
        =================================== -->

        <div class="wp-panel">

            <div class="wp-panel-body">

                <p class="text-danger mb-0">
                    <i class="bi bi-exclamation-circle me-2"></i>
                    <?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?>
                </p>

            </div>

        </div>

    <?php else: ?>


        <!-- ==================================
             EARNINGS SUMMARY
        =================================== -->

        <section class="wp-dashboard-stats">

            <!-- Confirmed Earnings -->

            <div class="wp-stat-card">

                <div class="wp-stat-top">

                    <span>Confirmed Earnings</span>

                    <div class="wp-stat-icon green">
                        <i class="bi bi-wallet2"></i>
                    </div>

                </div>

                <h2>
                    $<?= number_format($approved_earnings, 2) ?>
                </h2>

                <small>From approved timesheets</small>

            </div>


            <!-- Pending Earnings -->

            <div class="wp-stat-card">

                <div class="wp-stat-top">

                    <span>Pending Earnings</span>

                    <div class="wp-stat-icon yellow">
                        <i class="bi bi-hourglass-split"></i>
                    </div>

                </div>

                <h2>
                    $<?= number_format($pending_earnings, 2) ?>
                </h2>

                <small>Awaiting employer approval</small>

            </div>


            <!-- Approved Hours -->

            <div class="wp-stat-card">

                <div class="wp-stat-top">

                    <span>Approved Hours</span>

                    <div class="wp-stat-icon blue">
                        <i class="bi bi-clock-history"></i>
                    </div>

                </div>

                <h2>
                    <?= number_format($approved_hours, 2) ?>
                </h2>

                <small>Hours from approved timesheets</small>

            </div>


            <!-- Pending Hours -->

            <div class="wp-stat-card">

                <div class="wp-stat-top">

                    <span>Pending Hours</span>

                    <div class="wp-stat-icon rose">
                        <i class="bi bi-clock"></i>
                    </div>

                </div>

                <h2>
                    <?= number_format($pending_hours, 2) ?>
                </h2>

                <small>Hours awaiting approval</small>

            </div>

        </section>


        <!-- ==================================
             TOTAL EARNINGS OVERVIEW
        =================================== -->

        <section class="wp-panel wp-earnings-overview">

            <div class="wp-panel-header">

                <div>

                    <h3 class="wp-panel-title">
                        Total Recorded Earnings
                    </h3>

                    <p class="wp-panel-description">
                        Combined approved and pending earnings.
                    </p>

                </div>

                <div class="wp-earnings-total-icon">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>

            </div>

            <div class="wp-panel-body">

                <h2 class="wp-earnings-total">
                    $<?= number_format($total_earnings, 2) ?>
                </h2>

                <p class="wp-earnings-total-description">
                    <?= number_format($total_hours, 2) ?> total recorded working hours
                </p>

            </div>

        </section>


        <!-- ==================================
             MONTHLY EARNINGS HISTORY
        =================================== -->

        <section class="wp-panel wp-earnings-history">

            <div class="wp-panel-header">

                <div>

                    <h3 class="wp-panel-title">
                        Earnings History
                    </h3>

                    <p class="wp-panel-description">
                        Review your monthly timesheet earnings.
                    </p>

                </div>

                <span class="wp-earnings-currency">
                    USD ($)
                </span>

            </div>


            <div class="wp-panel-body">

                <?php if (empty($earnings_records)): ?>

                    <!-- Empty State -->

                    <div class="wp-earnings-empty">

                        <div class="wp-earnings-empty-icon">
                            <i class="bi bi-wallet2"></i>
                        </div>

                        <h4>No Earnings Records Yet</h4>

                        <p>
                            Your earnings history will appear here
                            once you start submitting timesheets.
                        </p>

                    </div>

                <?php else: ?>

                    <div class="wp-earnings-table-wrapper">

                        <table class="wp-earnings-table">

                            <thead>

                                <tr>
                                    <th>Month</th>
                                    <th>Working Hours</th>
                                    <th>Hourly Rate</th>
                                    <th>Status</th>
                                    <th class="text-end">Earnings</th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($earnings_records as $record): ?>

                                    <?php

                                    // Get earnings information
                                    $month_label = 'Unknown Month';

                                    if (!empty($record['month'])) {

                                        $timestamp = strtotime($record['month']);

                                        if ($timestamp !== false) {
                                            $month_label = date('F Y', $timestamp);
                                        }
                                    }

                                    $working_seconds = (int) ($record['total_working_seconds'] ?? 0);

                                    $working_hours = $working_seconds / 3600;

                                    $hourly_rate = (float) ($record['hourly_rate'] ?? 0);

                                    $amount = (float) ($record['earnings_amount'] ?? 0);

                                    $status = $record['timesheet_status'] ?? 'unknown';


                                    // Determine status badge
                                    $status_class = 'neutral';

                                    if ($status === 'approved') {

                                        $status_class = 'success';
                                    } elseif ($status === 'submitted') {

                                        $status_class = 'warning';
                                    } elseif ($status === 'rejected') {

                                        $status_class = 'danger';
                                    } elseif ($status === 'draft') {

                                        $status_class = 'neutral';
                                    }

                                    ?>

                                    <tr>

                                        <td>
                                            <strong>
                                                <?= htmlspecialchars($month_label, ENT_QUOTES, 'UTF-8') ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= number_format($working_hours, 2) ?> hrs
                                        </td>

                                        <td>
                                            $<?= number_format($hourly_rate, 2) ?>/hr
                                        </td>

                                        <td>

                                            <span class="wp-earnings-status <?= $status_class ?>">
                                                <?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?>
                                            </span>

                                        </td>

                                        <td class="text-end">

                                            <?php if ($status === 'approved'): ?>

                                                <strong class="wp-earnings-amount">
                                                    $<?= number_format($amount, 2) ?>
                                                </strong>

                                            <?php elseif ($status === 'submitted'): ?>

                                                <strong>
                                                    $<?= number_format($amount, 2) ?>
                                                </strong>

                                            <?php else: ?>

                                                <span class="text-muted">
                                                    Not counted
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </div>

        </section>


        <!-- ==================================
             EARNINGS INFORMATION
        =================================== -->

        <section class="wp-earnings-notice">

            <div class="wp-earnings-notice-icon">
                <i class="bi bi-info-circle"></i>
            </div>

            <div>

                <strong>About your earnings</strong>

                <p>
                    Earnings are calculated using your recorded working hours
                    multiplied by your agreed hourly rate. Idle time is excluded.
                </p>

                <p>
                    Submitted timesheets are considered pending until approved
                    by your employer. Confirmed earnings do not necessarily mean
                    that payment has already been made.
                </p>

            </div>

        </section>


    <?php endif; ?>

</div>


<?php require_once '../../includes/footer.php'; ?>