<?php

// ==========================================
// EMPLOYER EARNINGS / LABOR COSTS
// ==========================================

$required_role = 'employer';
$page_title = 'Labor Costs';
$active_page = 'earnings';

require_once '../../includes/auth.php';

$employer_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

// Philippine timezone.
date_default_timezone_set('Asia/Manila');

// Common Supabase headers.
$headers =
    "apikey: " . SUPABASE_KEY . "\r\n" .
    "Authorization: Bearer " . $access_token . "\r\n" .
    "Accept: application/json\r\n";

// Common GET request options.
$getOptions = [
    'http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true
    ]
];

$context = stream_context_create($getOptions);

// ==========================================
// INITIALIZE DATA
// ==========================================

$employments = [];
$earnings_records = [];

$employment_map = [];
$worker_names = [];
$job_titles = [];

$monthly_totals = [];

$confirmed_earnings = 0;
$pending_earnings = 0;

$approved_hours = 0;
$pending_hours = 0;

$error_message = '';


// ==========================================
// 1. GET EMPLOYER EMPLOYMENTS
// ==========================================

$employment_url = SUPABASE_URL .
    '/rest/v1/employments?select=id,worker_id,job_id' .
    '&employer_id=eq.' . urlencode($employer_id);

$employment_response = file_get_contents(
    $employment_url,
    false,
    $context
);

$employment_data = json_decode(
    $employment_response ?: '',
    true
);

if (
    $employment_response === false ||
    !is_array($employment_data) ||
    isset($employment_data['code'])
) {

    $error_message = 'Unable to retrieve employment records.';
} else {

    $employments = $employment_data;
}


// ==========================================
// 2. GET EARNINGS RECORDS
// ==========================================

if ($error_message === '') {

    $earnings_url = SUPABASE_URL .
        '/rest/v1/earnings_records?select=*' .
        '&employer_id=eq.' . urlencode($employer_id) .
        '&timesheet_status=in.(submitted,approved)' .
        '&order=month.desc';

    $earnings_response = file_get_contents(
        $earnings_url,
        false,
        $context
    );

    $earnings_data = json_decode(
        $earnings_response ?: '',
        true
    );

    if (
        $earnings_response === false ||
        !is_array($earnings_data) ||
        isset($earnings_data['code'])
    ) {

        $error_message = 'Unable to retrieve earnings records.';
    } else {

        $earnings_records = $earnings_data;
    }
}


// ==========================================
// 3. PREPARE EMPLOYMENT INFORMATION
// ==========================================

$worker_ids = [];
$job_ids = [];

foreach ($employments as $employment) {

    $employment_map[$employment['id']] = $employment;

    $worker_ids[] = $employment['worker_id'];
    $job_ids[] = $employment['job_id'];
}

$worker_ids = array_unique($worker_ids);
$job_ids = array_unique($job_ids);


// ==========================================
// 4. GET WORKER NAMES
// ==========================================

if ($error_message === '' && !empty($worker_ids)) {

    $worker_url = SUPABASE_URL .
        '/rest/v1/profiles?select=id,full_name' .
        '&id=in.(' . implode(',', $worker_ids) . ')';

    $worker_response = file_get_contents(
        $worker_url,
        false,
        $context
    );

    $worker_data = json_decode(
        $worker_response ?: '',
        true
    );

    if (is_array($worker_data) && !isset($worker_data['code'])) {

        foreach ($worker_data as $worker) {

            $worker_names[$worker['id']] = $worker['full_name'];
        }
    }
}


// ==========================================
// 5. GET JOB TITLES
// ==========================================

if ($error_message === '' && !empty($job_ids)) {

    $job_url = SUPABASE_URL .
        '/rest/v1/jobs?select=id,title' .
        '&id=in.(' . implode(',', $job_ids) . ')';

    $job_response = file_get_contents(
        $job_url,
        false,
        $context
    );

    $job_data = json_decode(
        $job_response ?: '',
        true
    );

    if (is_array($job_data) && !isset($job_data['code'])) {

        foreach ($job_data as $job) {

            $job_titles[$job['id']] = $job['title'];
        }
    }
}


// ==========================================
// 6. CALCULATE LABOR COSTS
// ==========================================

foreach ($earnings_records as $record) {

    $working_seconds = (int) $record['total_working_seconds'];

    $working_hours = $working_seconds / 3600;

    $amount = (float) $record['earnings_amount'];

    $month = $record['month'];

    $status = $record['timesheet_status'];

    // Prepare monthly totals.
    if (!isset($monthly_totals[$month])) {

        $monthly_totals[$month] = [
            'approved' => 0,
            'pending' => 0
        ];
    }

    // Approved timesheets are confirmed labor costs.
    if ($status === 'approved') {

        $confirmed_earnings += $amount;
        $approved_hours += $working_hours;

        $monthly_totals[$month]['approved'] += $amount;
    }

    // Submitted timesheets are pending labor costs.
    elseif ($status === 'submitted') {

        $pending_earnings += $amount;
        $pending_hours += $working_hours;

        $monthly_totals[$month]['pending'] += $amount;
    }
}

// Newest month first.
krsort($monthly_totals);

// Calculate combined totals.
$total_labor_cost = $confirmed_earnings + $pending_earnings;

$total_working_hours = $approved_hours + $pending_hours;


// ==========================================
// LOAD SHARED DASHBOARD HEADER
// ==========================================

require_once '../../includes/header.php';

?>


<div class="wp-page">

    <!-- PAGE HEADING -->

    <section class="wp-dashboard-welcome">

        <div>

            <span class="wp-dashboard-eyebrow">
                FINANCIAL OVERVIEW
            </span>

            <h1 class="wp-page-title">
                Labor Costs
            </h1>

            <p class="wp-page-description">
                Monitor your workforce's earnings and monthly labor costs.
            </p>

        </div>

        <span class="wp-earnings-currency">
            USD ($)
        </span>

    </section>


    <?php if ($error_message !== ''): ?>

        <section class="wp-panel">

            <div class="wp-panel-body">

                <p class="text-danger mb-0">
                    <i class="bi bi-exclamation-circle me-2"></i>
                    <?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?>
                </p>

            </div>

        </section>

    <?php else: ?>


        <!-- LABOR COST SUMMARY -->

        <section class="wp-dashboard-stats">

            <div class="wp-stat-card">

                <div class="wp-stat-top">

                    <span>Confirmed Labor Cost</span>

                    <div class="wp-stat-icon green">
                        <i class="bi bi-wallet2"></i>
                    </div>

                </div>

                <h2>
                    $<?= number_format($confirmed_earnings, 2) ?>
                </h2>

                <small>From approved timesheets</small>

            </div>


            <div class="wp-stat-card">

                <div class="wp-stat-top">

                    <span>Pending Labor Cost</span>

                    <div class="wp-stat-icon yellow">
                        <i class="bi bi-hourglass-split"></i>
                    </div>

                </div>

                <h2>
                    $<?= number_format($pending_earnings, 2) ?>
                </h2>

                <small>Awaiting approval</small>

            </div>


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


        <!-- TOTAL LABOR COST -->

        <section class="wp-panel wp-earnings-overview">

            <div class="wp-panel-header">

                <div>

                    <h3 class="wp-panel-title">
                        Total Recorded Labor Cost
                    </h3>

                    <p class="wp-panel-description">
                        Combined approved and pending labor costs.
                    </p>

                </div>

                <div class="wp-earnings-total-icon">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>

            </div>

            <div class="wp-panel-body">

                <h2 class="wp-earnings-total">
                    $<?= number_format($total_labor_cost, 2) ?>
                </h2>

                <p class="wp-earnings-total-description">
                    <?= number_format($total_working_hours, 2) ?>
                    total recorded working hours
                </p>

            </div>

        </section>


        <!-- MONTHLY LABOR COST -->

        <section class="wp-panel wp-earnings-history">

            <div class="wp-panel-header">

                <div>

                    <h3 class="wp-panel-title">
                        Monthly Labor Costs
                    </h3>

                    <p class="wp-panel-description">
                        Compare confirmed and pending costs each month.
                    </p>

                </div>

            </div>

            <div class="wp-panel-body">

                <?php if (empty($monthly_totals)): ?>

                    <div class="wp-earnings-empty">

                        <div class="wp-earnings-empty-icon">
                            <i class="bi bi-calendar3"></i>
                        </div>

                        <h4>No Monthly Records Yet</h4>

                        <p>
                            Monthly labor costs will appear once workers submit timesheets.
                        </p>

                    </div>

                <?php else: ?>

                    <div class="wp-earnings-table-wrapper">

                        <table class="wp-earnings-table">

                            <thead>

                                <tr>
                                    <th>Month</th>
                                    <th>Confirmed Cost</th>
                                    <th>Pending Cost</th>
                                    <th class="text-end">Total Cost</th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($monthly_totals as $month => $totals): ?>

                                    <?php
                                    $month_total =
                                        $totals['approved'] +
                                        $totals['pending'];
                                    ?>

                                    <tr>

                                        <td>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    date('F Y', strtotime($month)),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            $<?= number_format($totals['approved'], 2) ?>
                                        </td>

                                        <td>
                                            $<?= number_format($totals['pending'], 2) ?>
                                        </td>

                                        <td class="text-end">
                                            <strong>
                                                $<?= number_format($month_total, 2) ?>
                                            </strong>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </div>

        </section>


        <!-- LABOR COST BY WORKER -->

        <section class="wp-panel wp-earnings-history">

            <div class="wp-panel-header">

                <div>

                    <h3 class="wp-panel-title">
                        Labor Cost by Worker
                    </h3>

                    <p class="wp-panel-description">
                        Review each worker's recorded hours and labor costs.
                    </p>

                </div>

            </div>

            <div class="wp-panel-body">

                <?php if (empty($earnings_records)): ?>

                    <div class="wp-earnings-empty">

                        <div class="wp-earnings-empty-icon">
                            <i class="bi bi-people"></i>
                        </div>

                        <h4>No Earnings Records Yet</h4>

                        <p>
                            Worker labor costs will appear here after timesheets are submitted.
                        </p>

                    </div>

                <?php else: ?>

                    <div class="wp-earnings-table-wrapper">

                        <table class="wp-earnings-table">

                            <thead>

                                <tr>
                                    <th>Month</th>
                                    <th>Worker</th>
                                    <th>Job</th>
                                    <th>Working Hours</th>
                                    <th>Hourly Rate</th>
                                    <th>Status</th>
                                    <th class="text-end">Labor Cost</th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($earnings_records as $record): ?>

                                    <?php

                                    $employment = $employment_map[$record['employment_id']] ?? [];

                                    $worker_id = $record['worker_id']
                                        ?? ($employment['worker_id'] ?? '');

                                    $job_id = $employment['job_id'] ?? '';

                                    $worker_name = $worker_names[$worker_id]
                                        ?? 'Unknown Worker';

                                    $job_title = $job_titles[$job_id]
                                        ?? 'Unknown Job';

                                    $working_hours =
                                        (int) $record['total_working_seconds'] / 3600;

                                    $hourly_rate = (float) $record['hourly_rate'];

                                    $amount = (float) $record['earnings_amount'];

                                    $status = $record['timesheet_status'];

                                    $status_class = $status === 'approved'
                                        ? 'success'
                                        : 'warning';

                                    ?>

                                    <tr>

                                        <td>
                                            <?= htmlspecialchars(
                                                date('F Y', strtotime($record['month'])),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $worker_name,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                $job_title,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= number_format($working_hours, 2) ?> hrs
                                        </td>

                                        <td>
                                            $<?= number_format($hourly_rate, 2) ?>/hr
                                        </td>

                                        <td>
                                            <span class="wp-earnings-status <?= $status_class ?>">
                                                <?= htmlspecialchars(
                                                    ucfirst($status),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </span>
                                        </td>

                                        <td class="text-end">

                                            <strong class="wp-earnings-amount">
                                                $<?= number_format($amount, 2) ?>
                                            </strong>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </div>

        </section>


        <!-- INFORMATION NOTICE -->

        <section class="wp-earnings-notice">

            <div class="wp-earnings-notice-icon">
                <i class="bi bi-info-circle"></i>
            </div>

            <div>

                <strong>About labor costs</strong>

                <p>
                    Labor costs are calculated using recorded working hours
                    multiplied by each worker's agreed hourly rate. Idle time is excluded.
                </p>

                <p>
                    Pending costs are based on submitted timesheets and are
                    not confirmed until approved. These figures do not mean
                    that payments have already been released.
                </p>

            </div>

        </section>


    <?php endif; ?>

</div>


<?php require_once '../../includes/footer.php'; ?>