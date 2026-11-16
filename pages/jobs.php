<?php

// Use the same Supabase-backed session used by the rest of WorkPulse.
require_once __DIR__ . '/../includes/session.php';

// Retrieve all open job listings
$url = SUPABASE_URL .
    '/rest/v1/jobs?select=id,title,company_name,description,hourly_rate,employment_type,work_schedule,required_skills,created_at&status=eq.open&order=created_at.desc';

// Prepare the Supabase request
$options = [
    'http' => [
        'method' => 'GET',
        'header' => "apikey: " . SUPABASE_KEY . "\r\n",
        'ignore_errors' => true,
        'timeout' => 15
    ]
];

$context = stream_context_create($options);

// Send the request
$response = file_get_contents($url, false, $context);
$response_headers = $http_response_header ?? [];

$jobs = [];
$message = '';

if ($response === false) {

    $message = 'Unable to connect to the job listings. Please try again.';
} else {

    // Get the HTTP status code
    $status_code = 0;

    if (is_array($response_headers) && isset($response_headers[0])) {
        preg_match('/\s(\d{3})\s/', $response_headers[0], $matches);

        if (isset($matches[1])) {
            $status_code = (int)$matches[1];
        }
    }

    $result = json_decode($response, true);

    // Check whether Supabase successfully returned the jobs
    if ($status_code == 200 && is_array($result)) {

        $jobs = $result;
    } elseif ($status_code == 401 || $status_code == 403) {

        $message = 'Unable to access the job listings. Please try again later.';
    } else {

        $message = 'Unable to retrieve jobs. Please try again later.';
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Jobs | WorkPulse</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <link rel="stylesheet" href="../assets/css/landing.css">
    <link rel="stylesheet" href="../assets/css/marketplace.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>

<body>

    <div class="wp-landing">

        <!-- NAVIGATION -->
        <nav class="wp-landing-nav">

            <a href="../index.php" class="wp-landing-brand">
                <span class="landing-brand-icon"><i class="bi bi-activity"></i></span>
                WorkPulse
            </a>

            <div class="wp-landing-nav-links">
                <a href="../index.php">Home</a>
                <a href="jobs.php" class="marketplace-active">Find Jobs</a>

                <?php if (isset($_SESSION['user']['id'])): ?>

                    <?php if (($_SESSION['role'] ?? '') === 'worker'): ?>
                        <a href="worker/applications.php">My Applications</a>
                        <a href="worker/dashboard.php" class="wp-btn wp-btn-primary">Dashboard</a>
                    <?php elseif (($_SESSION['role'] ?? '') === 'employer'): ?>
                        <a href="employer/dashboard.php" class="wp-btn wp-btn-primary">Dashboard</a>
                    <?php elseif (($_SESSION['role'] ?? '') === 'admin'): ?>
                        <a href="admin/dashboard.php" class="wp-btn wp-btn-primary">Dashboard</a>
                    <?php endif; ?>

                <?php else: ?>

                    <a href="../login.php">Sign In</a>
                    <a href="../register.php" class="wp-btn wp-btn-primary">Get Started</a>

                <?php endif; ?>
            </div>

        </nav>


        <!-- MARKETPLACE HERO -->
        <section class="wp-hero marketplace-page-hero">

            <div class="wp-hero-content">

                <span class="wp-hero-label">
                    <i class="bi bi-briefcase"></i>
                    YOUR NEXT OPPORTUNITY
                </span>

                <h1 class="wp-hero-title">
                    Find work that<br>
                    <span>moves you forward.</span>
                </h1>

                <p class="wp-hero-description">
                    Explore opportunities, discover roles that match your skills,
                    and take the next step in your professional journey.
                </p>

                <div class="wp-hero-actions">

                    <a href="#job-search" class="wp-btn wp-btn-primary">
                        Explore Jobs
                        <i class="bi bi-arrow-down"></i>
                    </a>

                    <?php if (!isset($_SESSION['user']['id'])): ?>

                        <a href="../register.php" class="wp-btn wp-btn-outline">
                            Get Started
                        </a>

                    <?php elseif (($_SESSION['role'] ?? '') === 'worker'): ?>

                        <a href="worker/applications.php" class="wp-btn wp-btn-outline">
                            My Applications
                        </a>

                    <?php endif; ?>

                </div>

                <div class="wp-hero-trust">
                    <span>
                        <i class="bi bi-check-circle-fill"></i>
                        <?= count($jobs) ?> open opportunities
                    </span>

                    <span>
                        <i class="bi bi-check-circle-fill"></i>
                        Find your next role
                    </span>
                </div>

            </div>


            <!-- INTERACTIVE FLOATING CARD -->
            <div class="wp-hero-image">

                <div class="landing-visual">

                    <div class="landing-visual-decoration"></div>

                    <div class="landing-visual-card">

                        <div class="landing-visual-top">
                            <div class="landing-visual-logo">
                                <i class="bi bi-activity"></i>
                            </div>

                            <span>WORKPULSE WORKSPACE</span>

                            <i class="bi bi-three-dots"></i>
                        </div>

                        <div class="landing-visual-heading">
                            <small>YOUR NEXT OPPORTUNITY</small>
                            <h3>Find work.<br>Make progress.</h3>
                        </div>

                        <div class="landing-progress-card">

                            <div class="landing-progress-heading">
                                <span>Career progress</span>
                                <strong>Keep moving</strong>
                            </div>

                            <div class="landing-progress-track">
                                <span></span>
                            </div>

                            <small>
                                Discover opportunities that match your goals.
                            </small>

                        </div>

                        <div class="landing-visual-stats">

                            <div>
                                <i class="bi bi-briefcase"></i>
                                <strong>Open jobs</strong>
                                <small>Explore opportunities</small>
                            </div>

                            <div>
                                <i class="bi bi-person-check"></i>
                                <strong>Your next step</strong>
                                <small>Start applying</small>
                            </div>

                        </div>

                    </div>

                    <div class="landing-floating-icon landing-floating-one">
                        <i class="bi bi-check-lg"></i>
                    </div>

                    <div class="landing-floating-icon landing-floating-two">
                        <i class="bi bi-lightning-charge-fill"></i>
                    </div>

                </div>

            </div>

        </section>


        <!-- JOB SEARCH -->
        <main class="marketplace-main">

            <div class="marketplace-heading">
                <div>
                    <span class="landing-section-label">JOB MARKETPLACE</span>
                    <h2>Explore opportunities</h2>
                    <p>Search and filter jobs to find the right match.</p>
                </div>

                <?php if (
                    isset($_SESSION['user']['id']) &&
                    ($_SESSION['role'] ?? '') === 'worker'
                ): ?>
                    <a href="worker/applications.php" class="wp-btn wp-btn-outline">
                        <i class="bi bi-file-earmark-text"></i>
                        My Applications
                    </a>
                <?php endif; ?>
            </div>


            <?php if ($message !== ''): ?>

                <div class="wp-alert wp-alert-danger">
                    <i class="bi bi-exclamation-circle"></i>
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </div>

            <?php endif; ?>


            <!-- FILTER PANEL -->
            <section class="marketplace-filter-panel">

                <div class="marketplace-filter-heading">
                    <div>
                        <h3><i class="bi bi-sliders"></i> Find your match</h3>
                        <p>Use the filters to narrow down your search.</p>
                    </div>

                    <button type="button" id="clear-filters" class="marketplace-clear">
                        <i class="bi bi-arrow-counterclockwise"></i>
                        Clear filters
                    </button>
                </div>

                <div class="marketplace-filter-grid">

                    <div class="marketplace-search-field">
                        <label for="job-search">Search jobs</label>
                        <div class="marketplace-input-wrap">
                            <i class="bi bi-search"></i>
                            <input
                                type="text"
                                id="job-search"
                                class="wp-input"
                                placeholder="Job title, company, or keywords...">
                        </div>
                    </div>

                    <div>
                        <label class="wp-label" for="employment-filter">Employment type</label>
                        <select id="employment-filter" class="wp-select">
                            <option value="">All types</option>
                            <option value="full_time">Full-time</option>
                            <option value="part_time">Part-time</option>
                        </select>
                    </div>

                    <div>
                        <label class="wp-label" for="minimum-rate">Minimum rate (₱/hr)</label>
                        <input
                            type="number"
                            id="minimum-rate"
                            class="wp-input"
                            min="0"
                            step="0.01"
                            placeholder="Minimum">
                    </div>

                    <div>
                        <label class="wp-label" for="maximum-rate">Maximum rate (₱/hr)</label>
                        <input
                            type="number"
                            id="maximum-rate"
                            class="wp-input"
                            min="0"
                            step="0.01"
                            placeholder="Maximum">
                    </div>

                    <div>
                        <label class="wp-label" for="skill-filter">Required skill</label>
                        <input
                            type="text"
                            id="skill-filter"
                            class="wp-input"
                            placeholder="e.g. PHP, Design">
                    </div>

                </div>

            </section>


            <!-- RESULTS -->
            <div class="marketplace-results-heading">
                <div>
                    <h3>Available jobs</h3>
                    <p id="job-count"><?= count($jobs) ?> job(s) available</p>
                </div>
            </div>


            <div id="no-matching-jobs" class="marketplace-empty" style="display:none;">
                <i class="bi bi-search"></i>
                <h3>No matching jobs found</h3>
                <p>Try changing your search or clearing some filters.</p>
            </div>


            <?php if (empty($jobs) && $message === ''): ?>

                <div class="marketplace-empty">
                    <i class="bi bi-briefcase"></i>
                    <h3>No jobs available right now</h3>
                    <p>Check back later for new opportunities.</p>
                </div>

            <?php endif; ?>


            <!-- JOB CARDS -->
            <div id="job-listings" class="wp-job-grid">

                <?php foreach ($jobs as $job): ?>

                    <?php
                    $skills = $job['required_skills'] ?? [];

                    if (!is_array($skills)) {
                        $skills = [];
                    }

                    $searchable_text = strtolower(
                        $job['title'] . ' ' .
                        $job['company_name'] . ' ' .
                        $job['description']
                    );

                    $searchable_skills = strtolower(implode(' ', $skills));
                    ?>

                    <article
                        class="job-card wp-job-card"
                        data-search="<?= htmlspecialchars($searchable_text, ENT_QUOTES, 'UTF-8') ?>"
                        data-type="<?= htmlspecialchars($job['employment_type'], ENT_QUOTES, 'UTF-8') ?>"
                        data-rate="<?= htmlspecialchars((string)$job['hourly_rate'], ENT_QUOTES, 'UTF-8') ?>"
                        data-skills="<?= htmlspecialchars($searchable_skills, ENT_QUOTES, 'UTF-8') ?>">

                        <div class="wp-job-card-header">

                            <div class="marketplace-company-icon">
                                <i class="bi bi-building"></i>
                            </div>

                            <span class="wp-badge wp-badge-success">
                                <i class="bi bi-circle-fill"></i>
                                Open
                            </span>

                        </div>

                        <div class="marketplace-job-heading">
                            <h3 class="wp-job-title">
                                <?= htmlspecialchars($job['title'], ENT_QUOTES, 'UTF-8') ?>
                            </h3>

                            <p class="wp-job-company">
                                <i class="bi bi-building"></i>
                                <?= htmlspecialchars($job['company_name'], ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>

                        <div class="marketplace-job-meta">

                            <span>
                                <i class="bi bi-clock"></i>
                                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $job['employment_type'])), ENT_QUOTES, 'UTF-8') ?>
                            </span>

                            <span>
                                <i class="bi bi-calendar-week"></i>
                                <?= htmlspecialchars($job['work_schedule'] ?? 'Not specified', ENT_QUOTES, 'UTF-8') ?>
                            </span>

                        </div>

                        <p class="wp-job-description">
                            <?= htmlspecialchars(mb_strimwidth($job['description'], 0, 150, '...'), ENT_QUOTES, 'UTF-8') ?>
                        </p>

                        <div class="wp-job-skills">

                            <?php if (!empty($skills)): ?>

                                <?php foreach (array_slice($skills, 0, 5) as $skill): ?>
                                    <span class="wp-job-skill">
                                        <?= htmlspecialchars($skill, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php endforeach; ?>

                                <?php if (count($skills) > 5): ?>
                                    <span class="wp-job-skill">
                                        +<?= count($skills) - 5 ?> more
                                    </span>
                                <?php endif; ?>

                            <?php else: ?>

                                <span class="wp-job-skill">No specific skills</span>

                            <?php endif; ?>

                        </div>

                        <div class="wp-job-card-footer">

                            <div>
                                <small>Hourly rate</small>
                                <strong class="wp-job-budget">
                                    $<?= number_format((float)$job['hourly_rate'], 2) ?>
                                    <span>/hr</span>
                                </strong>
                            </div>

                            <a
                                href="job-details.php?id=<?= urlencode($job['id']) ?>"
                                class="wp-btn wp-btn-primary">
                                View Details
                                <i class="bi bi-arrow-right"></i>
                            </a>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        </main>


        <!-- FOOTER -->
        <footer class="wp-landing-footer">

            <div class="landing-footer-top">
                <a href="../index.php" class="wp-landing-brand">
                    <span class="landing-brand-icon"><i class="bi bi-activity"></i></span>
                    WorkPulse
                </a>

                <div class="landing-footer-links">
                    <a href="../index.php">Home</a>
                    <a href="jobs.php">Find Jobs</a>
                    <a href="../login.php">Sign In</a>
                    <a href="../register.php">Create Account</a>
                </div>
            </div>

            <div class="landing-footer-bottom">
                <p>© <?= date('Y') ?> WorkPulse. All rights reserved.</p>
                <p>Work smarter. Move forward.</p>
            </div>

        </footer>

    </div>

    <script src="../assets/js/script.js"></script>

</body>

</html>