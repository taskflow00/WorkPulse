<?php
// ==========================================
// EMPLOYER EMPLOYEES
// ==========================================

$required_role = 'employer';
$page_title = 'My Employees';
$active_page = 'employees';
$page_styles = ['/assets/css/employees.css'];

require_once '../../includes/auth.php';

date_default_timezone_set('Asia/Manila');

$employer_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$message = $_SESSION['employee_message'] ?? '';
$message_type = $_SESSION['employee_message_type'] ?? 'success';

unset($_SESSION['employee_message']);
unset($_SESSION['employee_message_type']);


// ==========================================
// CSRF TOKEN
// ==========================================

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];


// ==========================================
// SUPABASE HEADERS
// ==========================================

$headers =
    "apikey: " . SUPABASE_KEY . "\r\n" .
    "Authorization: Bearer " . $access_token . "\r\n";


// ==========================================
// HANDLE END EMPLOYMENT
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $employment_id = $_POST['employment_id'] ?? '';
    $submitted_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf_token, $submitted_token)) {

        $_SESSION['employee_message'] = 'Invalid request. Please try again.';
        $_SESSION['employee_message_type'] = 'error';
    } elseif (!preg_match('/^[a-f0-9-]{36}$/i', $employment_id)) {

        $_SESSION['employee_message'] = 'Invalid employment record.';
        $_SESSION['employee_message_type'] = 'error';
    } else {

        // Only end an active employment belonging to this employer.
        $url = SUPABASE_URL .
            '/rest/v1/employments?id=eq.' . urlencode($employment_id) .
            '&employer_id=eq.' . urlencode($employer_id) .
            '&status=eq.active';

        $data = [
            'status' => 'ended',
            'ended_at' => date('c')
        ];

        $options = [
            'http' => [
                'method' => 'PATCH',
                'header' =>
                $headers .
                    "Content-Type: application/json\r\n" .
                    "Prefer: return=representation\r\n",
                'content' => json_encode($data),
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);


        $status_code = 0;
        $response_headers = http_get_last_response_headers();

        if (is_array($response_headers) && isset($response_headers[0])) {
            preg_match('/\s(\d{3})\s/', $response_headers[0], $matches);
            $status_code = isset($matches[1]) ? (int)$matches[1] : 0;
        }

        $result = json_decode($response ?: '', true);

        if ($status_code >= 200 && $status_code < 300 && is_array($result) && count($result) > 0) {

            $_SESSION['employee_message'] = 'Employment successfully ended.';
            $_SESSION['employee_message_type'] = 'success';
        } elseif ($status_code >= 200 && $status_code < 300) {

            $_SESSION['employee_message'] = 'No employment record was updated. Check its status and employer ownership.';
            $_SESSION['employee_message_type'] = 'error';
        } else {

            $_SESSION['employee_message'] = 'Supabase error: ' . ($result['message'] ?? $response ?? 'Unknown error');
            $_SESSION['employee_message_type'] = 'error';
        }
    }

    // Prevent form resubmission.
    header('Location: employees.php');
    exit;
}


// ==========================================
// GET EMPLOYMENT RECORDS
// ==========================================

$employments = [];
$workers = [];
$jobs = [];
$error = '';

$url = SUPABASE_URL .
    '/rest/v1/employments?select=*&employer_id=eq.' .
    urlencode($employer_id) .
    '&order=started_at.desc';

$options = [
    'http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($url, false, $context);
$result = json_decode($response ?: '', true);

if (is_array($result) && !isset($result['code'])) {
    $employments = $result;
} else {
    $error = 'Unable to retrieve your employee records.';
}


// ==========================================
// GET WORKER PROFILES
// ==========================================

if (!empty($employments)) {

    $worker_ids = array_unique(
        array_column($employments, 'worker_id')
    );

    $ids = implode(',', array_map('urlencode', $worker_ids));

    $profile_url = SUPABASE_URL .
        '/rest/v1/profiles?select=id,full_name,skills,experience,education,portfolio_url&id=in.(' .
        $ids . ')';

    $context = stream_context_create($options);
    $profile_response = @file_get_contents($profile_url, false, $context);
    $profile_results = json_decode($profile_response ?: '', true);

    if (is_array($profile_results)) {

        foreach ($profile_results as $worker) {
            $workers[$worker['id']] = $worker;
        }
    }
}


// ==========================================
// GET RELATED JOBS
// ==========================================

if (!empty($employments)) {

    $job_ids = array_unique(
        array_column($employments, 'job_id')
    );

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


// ==========================================
// SUMMARY COUNTS
// ==========================================

$total_employees = count($employments);
$active_count = 0;
$ended_count = 0;

foreach ($employments as $employment) {

    if (($employment['status'] ?? '') === 'active') {
        $active_count++;
    } elseif (($employment['status'] ?? '') === 'ended') {
        $ended_count++;
    }
}


// ==========================================
// SHARED DASHBOARD HEADER
// ==========================================

require_once '../../includes/header.php';

?>

<div class="wp-page employees-page">

    <!-- Page heading -->
    <div class="employees-heading">

        <div>
            <div class="employees-eyebrow">
                <i class="bi bi-people"></i>
                TEAM MANAGEMENT
            </div>

            <h1 class="wp-page-title">My Employees</h1>

            <p class="wp-page-description">
                View your hired workers and manage their employment records.
            </p>
        </div>

        <a href="find-freelancers.php" class="wp-btn wp-btn-primary employees-find-btn">
            <i class="bi bi-person-plus"></i>
            Find Workers
        </a>

    </div>


    <!-- Session message -->
    <?php if ($message !== ''): ?>

        <div class="employees-alert employees-alert-<?= htmlspecialchars($message_type, ENT_QUOTES, 'UTF-8') ?>">
            <i class="bi bi-<?= $message_type === 'error' ? 'exclamation-circle-fill' : 'check-circle-fill' ?>"></i>

            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>

    <?php endif; ?>


    <!-- Summary cards -->
    <div class="employees-summary-grid">

        <div class="employees-summary-card">
            <div class="employees-summary-icon employees-icon-total">
                <i class="bi bi-people"></i>
            </div>

            <div>
                <span>Total Employees</span>
                <h3><?= $total_employees ?></h3>
                <small>All employment records</small>
            </div>
        </div>


        <div class="employees-summary-card">
            <div class="employees-summary-icon employees-icon-active">
                <i class="bi bi-person-check"></i>
            </div>

            <div>
                <span>Active Employees</span>
                <h3><?= $active_count ?></h3>
                <small>Currently employed</small>
            </div>
        </div>


        <div class="employees-summary-card">
            <div class="employees-summary-icon employees-icon-ended">
                <i class="bi bi-person-dash"></i>
            </div>

            <div>
                <span>Ended Employment</span>
                <h3><?= $ended_count ?></h3>
                <small>Previous employment records</small>
            </div>
        </div>

    </div>


    <!-- Employee list -->
    <section class="employees-panel">

        <div class="employees-panel-heading">

            <div>
                <h2>Employee Directory</h2>
                <p>Your current and previous employees.</p>
            </div>

            <span class="employees-count">
                <?= $total_employees ?> record<?= $total_employees === 1 ? '' : 's' ?>
            </span>

        </div>


        <?php if ($error !== ''): ?>

            <div class="employees-empty-state">

                <div class="employees-empty-icon employees-error-icon">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>

                <h3>Unable to Load Employees</h3>

                <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>

            </div>


        <?php elseif (empty($employments)): ?>

            <div class="employees-empty-state">

                <div class="employees-empty-icon">
                    <i class="bi bi-people"></i>
                </div>

                <h3>No Employees Yet</h3>

                <p>
                    You haven't hired any workers yet. Find a worker and start building your team.
                </p>

                <a href="find-freelancers.php" class="wp-btn wp-btn-primary">
                    <i class="bi bi-search"></i>
                    Find Workers
                </a>

            </div>


        <?php else: ?>

            <div class="employees-list">

                <?php foreach ($employments as $employment): ?>

                    <?php
                    $worker = $workers[$employment['worker_id']] ?? [];
                    $job = $jobs[$employment['job_id']] ?? [];

                    $employment_status = $employment['status'] ?? 'unknown';
                    $is_active = $employment_status === 'active';

                    $worker_name = $worker['full_name'] ?? 'Unknown Worker';

                    $initials = '';
                    $name_parts = preg_split('/\s+/', trim($worker_name));

                    foreach (array_slice($name_parts, 0, 2) as $part) {
                        $initials .= strtoupper(substr($part, 0, 1));
                    }

                    if ($initials === '') {
                        $initials = '?';
                    }

                    $skills = $worker['skills'] ?? [];

                    if (!is_array($skills)) {
                        $skills = [];
                    }
                    ?>

                    <article class="employee-card">

                        <!-- Employee identity -->
                        <div class="employee-card-top">

                            <div class="employee-identity">

                                <div class="employee-avatar">
                                    <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
                                </div>

                                <div class="employee-identity-details">

                                    <h3>
                                        <?= htmlspecialchars($worker_name, ENT_QUOTES, 'UTF-8') ?>
                                    </h3>

                                    <span class="employee-position">
                                        <i class="bi bi-briefcase"></i>
                                        <?= htmlspecialchars(
                                            $job['title'] ?? 'Position unavailable',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </span>

                                </div>

                            </div>


                            <span class="employee-status <?= $is_active ? 'employee-status-active' : 'employee-status-ended' ?>">
                                <span></span>
                                <?= htmlspecialchars(ucfirst($employment_status), ENT_QUOTES, 'UTF-8') ?>
                            </span>

                        </div>


                        <!-- Employment details -->
                        <div class="employee-details-grid">

                            <div class="employee-detail">
                                <span>Hourly Rate</span>
                                <strong>
                                    ₱<?= number_format((float) ($employment['hourly_rate'] ?? 0), 2) ?>
                                </strong>
                            </div>


                            <div class="employee-detail">
                                <span>Employment Type</span>
                                <strong>
                                    <?= htmlspecialchars(
                                        ucwords(str_replace('_', ' ', $employment['employment_type'] ?? 'N/A')),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </strong>
                            </div>


                            <div class="employee-detail">
                                <span>Work Schedule</span>
                                <strong>
                                    <?= htmlspecialchars($employment['work_schedule'] ?? 'Not specified', ENT_QUOTES, 'UTF-8') ?>
                                </strong>
                            </div>


                            <div class="employee-detail">
                                <span>Started On</span>
                                <strong>
                                    <?= !empty($employment['started_at'])
                                        ? date('M d, Y', strtotime($employment['started_at']))
                                        : 'Not available' ?>
                                </strong>
                            </div>

                        </div>


                        <!-- Skills -->
                        <?php if (!empty($skills)): ?>

                            <div class="employee-skills">

                                <span class="employee-section-label">Skills</span>

                                <div class="employee-skill-list">

                                    <?php foreach (array_slice($skills, 0, 6) as $skill): ?>

                                        <span>
                                            <?= htmlspecialchars((string) $skill, ENT_QUOTES, 'UTF-8') ?>
                                        </span>

                                    <?php endforeach; ?>

                                    <?php if (count($skills) > 6): ?>
                                        <span class="employee-more-skills">
                                            +<?= count($skills) - 6 ?> more
                                        </span>
                                    <?php endif; ?>

                                </div>

                            </div>

                        <?php endif; ?>


                        <!-- Additional profile details -->
                        <details class="employee-more-details">

                            <summary>
                                <i class="bi bi-person-lines-fill"></i>
                                View Profile Details
                                <i class="bi bi-chevron-down employee-details-chevron"></i>
                            </summary>

                            <div class="employee-profile-details">

                                <div>
                                    <span>Experience</span>
                                    <p>
                                        <?= nl2br(htmlspecialchars(
                                            $worker['experience'] ?? 'Not provided',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        )) ?>
                                    </p>
                                </div>

                                <div>
                                    <span>Education</span>
                                    <p>
                                        <?= nl2br(htmlspecialchars(
                                            $worker['education'] ?? 'Not provided',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        )) ?>
                                    </p>
                                </div>

                                <?php if (!empty($worker['portfolio_url'])): ?>

                                    <a
                                        href="<?= htmlspecialchars($worker['portfolio_url'], ENT_QUOTES, 'UTF-8') ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="employee-portfolio-link">

                                        <i class="bi bi-box-arrow-up-right"></i>
                                        View Portfolio

                                    </a>

                                <?php endif; ?>

                            </div>

                        </details>


                        <!-- Employment actions -->
                        <div class="employee-card-footer">

                            <?php if (!$is_active && !empty($employment['ended_at'])): ?>

                                <span class="employee-ended-date">
                                    <i class="bi bi-calendar-check"></i>
                                    Ended <?= date('M d, Y', strtotime($employment['ended_at'])) ?>
                                </span>

                            <?php else: ?>

                                <span class="employee-active-note">
                                    <i class="bi bi-check-circle"></i>
                                    Currently employed
                                </span>

                            <?php endif; ?>


                            <?php if ($is_active): ?>

                                <form
                                    method="POST"
                                    action="employees.php"
                                    class="employee-end-form"
                                    onsubmit="return confirm('Are you sure you want to end this employment? This will mark the employee as no longer active. Their employment history will be preserved.');">

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                                    <input
                                        type="hidden"
                                        name="employment_id"
                                        value="<?= htmlspecialchars($employment['id'], ENT_QUOTES, 'UTF-8') ?>">

                                    <button type="submit" class="employee-end-btn">
                                        <i class="bi bi-person-dash"></i>
                                        End Employment
                                    </button>

                                </form>

                            <?php endif; ?>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>

</div>

<?php require_once '../../includes/footer.php'; ?>