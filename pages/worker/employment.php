<?php
$required_role = 'worker';
require_once '../../includes/auth.php';

$worker_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$message = '';

// Retrieve the worker's employment records.
$url = SUPABASE_URL .
    '/rest/v1/employments?select=*&worker_id=eq.' .
    urlencode($worker_id) .
    '&order=started_at.desc';

$options = [
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $access_token . "\r\n",
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($url, false, $context);
$employments = json_decode($response ?: '', true);

if (!is_array($employments)) {
    $employments = [];
    $message = 'Unable to retrieve employment records.';
}

// Retrieve employer profiles.
$employers = [];

if (!empty($employments)) {

    $employer_ids = array_unique(array_column($employments, 'employer_id'));

    $ids = implode(',', array_map('urlencode', $employer_ids));

    $profile_url = SUPABASE_URL .
        '/rest/v1/profiles?select=id,full_name,company_name&id=in.(' .
        $ids . ')';

    $context = stream_context_create($options);
    $profile_response = @file_get_contents($profile_url, false, $context);
    $profiles = json_decode($profile_response ?: '', true);

    if (is_array($profiles)) {
        foreach ($profiles as $profile) {
            $employers[$profile['id']] = $profile;
        }
    }
}

// Retrieve job titles.
$jobs = [];

if (!empty($employments)) {

    $job_ids = array_unique(array_column($employments, 'job_id'));

    $ids = implode(',', array_map('urlencode', $job_ids));

    $job_url = SUPABASE_URL .
        '/rest/v1/jobs?select=id,title,company_name&id=in.(' .
        $ids . ')';

    $context = stream_context_create($options);
    $job_response = @file_get_contents($job_url, false, $context);
    $job_results = json_decode($job_response ?: '', true);

    if (is_array($job_results)) {
        foreach ($job_results as $job) {
            $jobs[$job['id']] = $job;
        }
    }
}
?>


<?php
$page_title = 'My Employment';
$active_page = 'employment';

require_once '../../includes/header.php';

// Count employment records.
$total_employments = count($employments);
$active_employments = 0;
$ended_employments = 0;

foreach ($employments as $employment) {
    if ($employment['status'] === 'active') {
        $active_employments++;
    } elseif ($employment['status'] === 'ended') {
        $ended_employments++;
    }
}
?>

<div class="wp-page wp-employment-page">

    <div class="wp-page-header">
        <div>
            <h1 class="wp-page-title">My Employment</h1>
            <p class="wp-page-description">
                View your current and previous employment records.
            </p>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="wp-employment-alert">
            <i class="bi bi-exclamation-circle"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Employment Summary -->
    <div class="wp-employment-stats">

        <div class="wp-employment-stat">
            <div class="wp-employment-stat-icon">
                <i class="bi bi-briefcase"></i>
            </div>
            <div>
                <span>Total Employment</span>
                <h3><?php echo $total_employments; ?></h3>
            </div>
        </div>

        <div class="wp-employment-stat">
            <div class="wp-employment-stat-icon active">
                <i class="bi bi-check-circle"></i>
            </div>
            <div>
                <span>Active</span>
                <h3><?php echo $active_employments; ?></h3>
            </div>
        </div>

        <div class="wp-employment-stat">
            <div class="wp-employment-stat-icon ended">
                <i class="bi bi-clock-history"></i>
            </div>
            <div>
                <span>Previous Employment</span>
                <h3><?php echo $ended_employments; ?></h3>
            </div>
        </div>

    </div>

    <!-- Employment Records -->
    <div class="wp-employment-section">

        <div class="wp-employment-section-heading">
            <div>
                <h2>Employment History</h2>
                <p>Your employment records and details.</p>
            </div>

            <span class="wp-employment-count">
                <?php echo $total_employments; ?> Records
            </span>
        </div>

        <?php if (empty($employments)): ?>

            <div class="wp-employment-empty">
                <div class="wp-employment-empty-icon">
                    <i class="bi bi-briefcase"></i>
                </div>

                <h3>No Employment Records Yet</h3>

                <p>
                    Your employment history will appear here once an employer
                    officially hires you.
                </p>

                <a href="jobs.php" class="wp-btn wp-btn-primary">
                    <i class="bi bi-search"></i>
                    Browse Jobs
                </a>
            </div>

        <?php else: ?>

            <div class="wp-employment-list">

                <?php foreach ($employments as $employment): ?>

                    <?php
                    $employer = $employers[$employment['employer_id']] ?? [];
                    $job = $jobs[$employment['job_id']] ?? [];

                    $company_name = $employer['company_name']
                        ?? $job['company_name']
                        ?? 'Not specified';

                    $status = $employment['status'] ?? 'unknown';

                    $status_class = $status === 'active'
                        ? 'active'
                        : ($status === 'ended' ? 'ended' : 'neutral');

                    $employment_type = ucwords(str_replace(
                        '_',
                        ' ',
                        $employment['employment_type'] ?? 'Not specified'
                    ));

                    $start_date = !empty($employment['started_at'])
                        ? date('F d, Y', strtotime($employment['started_at']))
                        : 'Not available';
                    ?>

                    <div class="wp-employment-card">

                        <div class="wp-employment-card-top">

                            <div class="wp-employment-company-icon">
                                <i class="bi bi-building"></i>
                            </div>

                            <div class="wp-employment-main">

                                <h3>
                                    <?php echo htmlspecialchars($job['title'] ?? 'Employment'); ?>
                                </h3>

                                <p>
                                    <?php echo htmlspecialchars($company_name); ?>
                                </p>

                            </div>

                            <span class="wp-employment-status <?php echo $status_class; ?>">
                                <span></span>
                                <?php echo htmlspecialchars(ucfirst($status)); ?>
                            </span>

                        </div>

                        <div class="wp-employment-details">

                            <div class="wp-employment-detail">
                                <span>Employer</span>
                                <strong>
                                    <?php echo htmlspecialchars($employer['full_name'] ?? 'Not available'); ?>
                                </strong>
                            </div>

                            <div class="wp-employment-detail">
                                <span>Hourly Rate</span>
                                <strong>
                                    $<?php echo number_format((float)($employment['hourly_rate'] ?? 0), 2); ?>
                                </strong>
                            </div>

                            <div class="wp-employment-detail">
                                <span>Employment Type</span>
                                <strong>
                                    <?php echo htmlspecialchars($employment_type); ?>
                                </strong>
                            </div>

                            <div class="wp-employment-detail">
                                <span>Work Schedule</span>
                                <strong>
                                    <?php echo htmlspecialchars($employment['work_schedule'] ?? 'Not specified'); ?>
                                </strong>
                            </div>

                            <div class="wp-employment-detail">
                                <span>Start Date</span>
                                <strong><?php echo $start_date; ?></strong>
                            </div>

                            <?php if ($status === 'ended' && !empty($employment['ended_at'])): ?>
                                <div class="wp-employment-detail">
                                    <span>End Date</span>
                                    <strong>
                                        <?php echo date('F d, Y', strtotime($employment['ended_at'])); ?>
                                    </strong>
                                </div>
                            <?php endif; ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>