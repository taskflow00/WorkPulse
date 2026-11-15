<?php
// ==========================================
// EMPLOYER JOB LISTINGS
// ==========================================

$required_role = 'employer';
$page_title = 'My Job Listings';
$active_page = 'jobs';
$page_styles = ['/assets/css/employer-jobs.css'];

require_once '../../includes/auth.php';

date_default_timezone_set('Asia/Manila');

$token = $_SESSION['access_token'];
$employer_id = $_SESSION['user']['id'];


// ==========================================
// CSRF TOKEN
// ==========================================

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];


// ==========================================
// SESSION MESSAGE
// ==========================================

$message = $_SESSION['job_message'] ?? '';
unset($_SESSION['job_message']);


// ==========================================
// HANDLE JOB STATUS UPDATE
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $job_id = $_POST['job_id'] ?? '';
    $new_status = $_POST['new_status'] ?? '';
    $submitted_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf_token, $submitted_token)) {

        $_SESSION['job_message'] = 'Invalid request. Please try again.';
    } elseif (
        $job_id !== '' &&
        in_array($new_status, ['open', 'closed'], true)
    ) {

        // Update only the logged-in employer's job.
        $url = SUPABASE_URL . '/rest/v1/jobs?id=eq.'
            . urlencode($job_id)
            . '&employer_id=eq.'
            . urlencode($employer_id);

        $data = [
            'status' => $new_status
        ];

        $options = [
            'http' => [
                'method' => 'PATCH',
                'header' =>
                "apikey: " . SUPABASE_KEY . "\r\n" .
                    "Authorization: Bearer " . $token . "\r\n" .
                    "Content-Type: application/json\r\n" .
                    "Prefer: return=representation\r\n",
                'content' => json_encode($data),
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        if ($response === false) {

            $_SESSION['job_message'] = 'Unable to connect to Supabase.';
        } else {

            $result = json_decode($response, true);

            if (is_array($result) && count($result) > 0) {
                $_SESSION['job_message'] = 'Job status successfully updated.';
            } else {
                $_SESSION['job_message'] = 'Unable to update this job listing.';
            }
        }
    } else {

        $_SESSION['job_message'] = 'Invalid job information.';
    }

    // Prevent form resubmission.
    header('Location: jobs.php');
    exit;
}


// ==========================================
// SEARCH AND STATUS FILTERS
// ==========================================

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'all';

if (!in_array($status, ['all', 'open', 'closed'], true)) {
    $status = 'all';
}


// ==========================================
// GET EMPLOYER'S JOB LISTINGS
// ==========================================

$url = SUPABASE_URL .
    '/rest/v1/jobs?select=id,title,company_name,hourly_rate,employment_type,status,created_at' .
    '&employer_id=eq.' . urlencode($employer_id) .
    '&order=created_at.desc&limit=100';

$options = [
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $token . "\r\n",
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

$jobs = [];
$all_jobs = [];
$error = '';

if ($response === false) {

    $error = 'Unable to retrieve your job listings.';
} else {

    $result = json_decode($response, true);

    if (is_array($result) && !isset($result['code'])) {

        $all_jobs = $result;

        // Apply search and status filters.
        foreach ($all_jobs as $job) {

            $matches_search = true;
            $matches_status = true;

            if ($search !== '') {

                $matches_search =
                    stripos($job['title'] ?? '', $search) !== false ||
                    stripos($job['company_name'] ?? '', $search) !== false;
            }

            if ($status !== 'all') {
                $matches_status = ($job['status'] ?? '') === $status;
            }

            if ($matches_search && $matches_status) {
                $jobs[] = $job;
            }
        }
    } else {

        $error = 'Unable to retrieve your job listings.';
    }
}


// ==========================================
// SUMMARY COUNTS
// ==========================================

$total_jobs = count($all_jobs);
$open_jobs = 0;
$closed_jobs = 0;

foreach ($all_jobs as $job) {

    if (($job['status'] ?? '') === 'open') {
        $open_jobs++;
    } elseif (($job['status'] ?? '') === 'closed') {
        $closed_jobs++;
    }
}


// ==========================================
// SHARED DASHBOARD HEADER
// ==========================================

require_once '../../includes/header.php';

?>

<div class="wp-page employer-jobs-page">

    <!-- Page heading -->
    <div class="jobs-page-heading">

        <div>
            <div class="jobs-eyebrow">
                <i class="bi bi-briefcase"></i>
                JOB MANAGEMENT
            </div>

            <h1 class="wp-page-title">My Job Listings</h1>

            <p class="wp-page-description">
                Manage your published jobs and control which opportunities are available to workers.
            </p>
        </div>

        <a href="create-job.php" class="wp-btn wp-btn-primary jobs-create-btn">
            <i class="bi bi-plus-lg"></i>
            Create New Job
        </a>

    </div>


    <!-- Session message -->
    <?php if ($message !== ''): ?>

        <div class="jobs-alert" role="alert">
            <i class="bi bi-info-circle-fill"></i>
            <span><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></span>
        </div>

    <?php endif; ?>


    <!-- Summary cards -->
    <div class="jobs-summary-grid">

        <div class="jobs-summary-card">
            <div class="jobs-summary-icon jobs-icon-total">
                <i class="bi bi-briefcase"></i>
            </div>

            <div>
                <span class="jobs-summary-label">Total Listings</span>
                <h3><?= $total_jobs ?></h3>
                <span class="jobs-summary-note">Your published jobs</span>
            </div>
        </div>


        <div class="jobs-summary-card">
            <div class="jobs-summary-icon jobs-icon-open">
                <i class="bi bi-check-circle"></i>
            </div>

            <div>
                <span class="jobs-summary-label">Open Jobs</span>
                <h3><?= $open_jobs ?></h3>
                <span class="jobs-summary-note">Currently accepting applicants</span>
            </div>
        </div>


        <div class="jobs-summary-card">
            <div class="jobs-summary-icon jobs-icon-closed">
                <i class="bi bi-lock"></i>
            </div>

            <div>
                <span class="jobs-summary-label">Closed Jobs</span>
                <h3><?= $closed_jobs ?></h3>
                <span class="jobs-summary-note">Not accepting applicants</span>
            </div>
        </div>

    </div>


    <!-- Job listings panel -->
    <section class="jobs-listings-panel">

        <div class="jobs-panel-heading">

            <div>
                <h2>All Job Listings</h2>
                <p>Search, filter, and manage your job opportunities.</p>
            </div>

            <span class="jobs-result-count">
                <?= count($jobs) ?> result<?= count($jobs) === 1 ? '' : 's' ?>
            </span>

        </div>


        <!-- Search and filters -->
        <form method="GET" action="jobs.php" class="jobs-filter-form">

            <div class="jobs-search-field">

                <i class="bi bi-search"></i>

                <input
                    type="text"
                    name="search"
                    placeholder="Search by job title or company..."
                    value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">

            </div>


            <div class="jobs-status-field">

                <select name="status" aria-label="Filter by job status">

                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>
                        All statuses
                    </option>

                    <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>
                        Open
                    </option>

                    <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>
                        Closed
                    </option>

                </select>

            </div>


            <button type="submit" class="wp-btn wp-btn-primary jobs-filter-btn">
                <i class="bi bi-funnel"></i>
                Apply Filters
            </button>


            <?php if ($search !== '' || $status !== 'all'): ?>

                <a href="jobs.php" class="jobs-clear-filter">
                    Clear filters
                </a>

            <?php endif; ?>

        </form>


        <!-- Results -->
        <?php if ($error !== ''): ?>

            <div class="jobs-empty-state">

                <div class="jobs-empty-icon jobs-empty-error">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>

                <h3>Unable to Load Jobs</h3>

                <p>
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </p>

            </div>


        <?php elseif (count($jobs) > 0): ?>

            <div class="jobs-table-wrapper">

                <table class="jobs-table">

                    <thead>
                        <tr>
                            <th>JOB DETAILS</th>
                            <th>HOURLY RATE</th>
                            <th>EMPLOYMENT TYPE</th>
                            <th>STATUS</th>
                            <th>DATE POSTED</th>
                            <th class="text-end">ACTIONS</th>
                        </tr>
                    </thead>


                    <tbody>

                        <?php foreach ($jobs as $job): ?>

                            <?php
                            $job_status = $job['status'] ?? 'closed';

                            $status_class = $job_status === 'open'
                                ? 'jobs-status-open'
                                : 'jobs-status-closed';

                            $status_label = ucfirst($job_status);
                            ?>

                            <tr>

                                <!-- Job details -->
                                <td class="jobs-details-cell">

                                    <div class="jobs-title">
                                        <?= htmlspecialchars(
                                            $job['title'] ?? 'Untitled Job',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </div>

                                    <div class="jobs-company">
                                        <i class="bi bi-building"></i>

                                        <?= htmlspecialchars(
                                            $job['company_name'] ?? 'No company name',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </div>

                                </td>


                                <!-- Hourly rate -->
                                <td>

                                    <span class="jobs-rate">
                                        $<?= number_format((float) ($job['hourly_rate'] ?? 0), 2) ?>
                                    </span>

                                    <span class="jobs-cell-subtext">per hour</span>

                                </td>


                                <!-- Employment type -->
                                <td>

                                    <span class="jobs-employment-type">
                                        <?= htmlspecialchars(
                                            ucfirst(str_replace(
                                                '_',
                                                ' ',
                                                $job['employment_type'] ?? 'N/A'
                                            )),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </span>

                                </td>


                                <!-- Status -->
                                <td>

                                    <span class="jobs-status <?= $status_class ?>">
                                        <span class="jobs-status-dot"></span>
                                        <?= htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8') ?>
                                    </span>

                                </td>


                                <!-- Date posted -->
                                <td>

                                    <span class="jobs-date">
                                        <?= !empty($job['created_at'])
                                            ? date('M d, Y', strtotime($job['created_at']))
                                            : 'N/A' ?>
                                    </span>

                                </td>


                                <!-- Actions -->
                                <td>

                                    <div class="jobs-actions">

                                        <a
                                            href="edit-job.php?id=<?= urlencode($job['id']) ?>"
                                            class="jobs-action-btn jobs-edit-btn"
                                            title="Edit job">

                                            <i class="bi bi-pencil"></i>
                                            <span>Edit</span>

                                        </a>


                                        <form method="POST" action="jobs.php" class="m-0">

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                                            <input
                                                type="hidden"
                                                name="job_id"
                                                value="<?= htmlspecialchars($job['id'], ENT_QUOTES, 'UTF-8') ?>">


                                            <?php if ($job_status === 'open'): ?>

                                                <input
                                                    type="hidden"
                                                    name="new_status"
                                                    value="closed">

                                                <button
                                                    type="submit"
                                                    class="jobs-action-btn jobs-close-btn"
                                                    title="Close job">

                                                    <i class="bi bi-x-circle"></i>
                                                    <span>Close</span>

                                                </button>

                                            <?php else: ?>

                                                <input
                                                    type="hidden"
                                                    name="new_status"
                                                    value="open">

                                                <button
                                                    type="submit"
                                                    class="jobs-action-btn jobs-reopen-btn"
                                                    title="Reopen job">

                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                    <span>Reopen</span>

                                                </button>

                                            <?php endif; ?>

                                        </form>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>


            <div class="jobs-table-footer">
                <span>
                    <i class="bi bi-info-circle"></i>
                    Showing up to 100 of your most recently created job listings.
                </span>
            </div>


        <?php else: ?>

            <div class="jobs-empty-state">

                <div class="jobs-empty-icon">
                    <i class="bi bi-briefcase"></i>
                </div>

                <?php if ($search !== '' || $status !== 'all'): ?>

                    <h3>No Matching Jobs</h3>

                    <p>
                        We couldn't find any job listings matching your search or selected filters.
                    </p>

                    <a href="jobs.php" class="wp-btn wp-btn-outline">
                        <i class="bi bi-arrow-counterclockwise"></i>
                        Clear Filters
                    </a>

                <?php else: ?>

                    <h3>No Job Listings Yet</h3>

                    <p>
                        You haven't published any jobs. Create your first listing to start finding workers.
                    </p>

                    <a href="create-job.php" class="wp-btn wp-btn-primary">
                        <i class="bi bi-plus-lg"></i>
                        Create Your First Job
                    </a>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </section>

</div>

<?php require_once '../../includes/footer.php'; ?>