
<?php

require_once __DIR__ . '/includes/session.php';

// Check login status.
$user = $_SESSION['user'] ?? [];
$is_logged_in = !empty($user['id']);

// Get the user's role from the session.
$role = strtolower(trim($_SESSION['role'] ?? ''));

// Set the dashboard URL based on the user's role.
$dashboard_url = '';

switch ($role) {
    case 'worker':
        $dashboard_url = 'pages/worker/dashboard.php';
        break;

    case 'employer':
        $dashboard_url = 'pages/employer/dashboard.php';
        break;

    case 'admin':
        $dashboard_url = 'pages/admin/dashboard.php';
        break;
}

?>



<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>WorkPulse | Work Smarter. Move Forward.</title>

    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <link rel="stylesheet" href="assets/css/landing.css">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>

<body>

    <div class="wp-landing">

        <!-- NAVIGATION -->
        
<!-- NAVIGATION -->
<nav class="wp-landing-nav">

    <a href="index.php" class="wp-landing-brand">
        <span class="landing-brand-icon">
            <i class="bi bi-activity"></i>
        </span>
        WorkPulse
    </a>

    <div class="wp-landing-nav-links">

        <a href="#features">Features</a>
        <a href="#how-it-works">How It Works</a>
        <a href="pages/jobs.php">Find Jobs</a>

        <?php if ($is_logged_in): ?>

            <?php if ($dashboard_url !== ''): ?>

                <a href="<?= htmlspecialchars($dashboard_url, ENT_QUOTES, 'UTF-8') ?>"
                    class="wp-btn wp-btn-primary">
                    My Dashboard <i class="bi bi-arrow-up-right"></i>
                </a>

            <?php endif; ?>

        <?php else: ?>

            <a href="login.php">Sign In</a>

            <a href="register.php" class="wp-btn wp-btn-primary">
                Get Started <i class="bi bi-arrow-right"></i>
            </a>

        <?php endif; ?>

    </div>

</nav>



        <!-- HERO -->
        <section class="wp-hero">

            <div class="wp-hero-content">

                <span class="wp-hero-label">
                    <i class="bi bi-stars"></i>
                    A BETTER WAY TO WORK
                </span>

                <h1 class="wp-hero-title">
                    Work smarter.<br>
                    <span>Move forward.</span>
                </h1>

                <p class="wp-hero-description">
                    WorkPulse connects people with opportunities and helps
                    employers manage work, tasks, and progress in one place.
                    Your next step starts here.
                </p>

                
<div class="wp-hero-actions">

    <a href="pages/jobs.php" class="wp-btn wp-btn-primary">
        Explore Opportunities
        <i class="bi bi-arrow-right"></i>
    </a>

    <?php if ($is_logged_in && $dashboard_url !== ''): ?>

        <a href="<?= htmlspecialchars($dashboard_url, ENT_QUOTES, 'UTF-8') ?>"
            class="wp-btn wp-btn-outline">
            Go to Dashboard
        </a>

    <?php elseif (!$is_logged_in): ?>

        <a href="register.php" class="wp-btn wp-btn-outline">
            Create an Account
        </a>

    <?php endif; ?>

</div>


                <div class="wp-hero-trust">
                    <span><i class="bi bi-check-circle-fill"></i> Discover opportunities</span>
                    <span><i class="bi bi-check-circle-fill"></i> Manage your progress</span>
                </div>

            </div>


            <!-- HERO VISUAL -->
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
                            <small>YOUR WORK OVERVIEW</small>
                            <h3>Make progress<br>every day.</h3>
                        </div>

                        <div class="landing-progress-card">

                            <div class="landing-progress-heading">
                                <span>Work progress</span>
                                <strong>On track</strong>
                            </div>

                            <div class="landing-progress-track">
                                <span></span>
                            </div>

                            <small>Stay organized and keep moving forward.</small>

                        </div>

                        <div class="landing-visual-stats">

                            <div>
                                <i class="bi bi-briefcase"></i>
                                <strong>Opportunities</strong>
                                <small>Find your next job</small>
                            </div>

                            <div>
                                <i class="bi bi-clock-history"></i>
                                <strong>Time tracking</strong>
                                <small>Track your work</small>
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


        <!-- INTRODUCTION -->
        <section class="landing-intro">

            <span class="landing-section-label">ONE PLATFORM, TWO SIDES</span>

            <h2>Work and opportunities,<br>connected in one place.</h2>

            <p>
                Whether you're looking for your next opportunity or managing
                a team, WorkPulse gives you the tools to stay organized
                and focused on what matters.
            </p>

        </section>


        <!-- USER TYPES -->
        <section class="landing-audience">

            <div class="landing-audience-card">

                <div class="landing-audience-icon">
                    <i class="bi bi-person-workspace"></i>
                </div>

                <span>FOR WORKERS</span>

                <h3>Find opportunities.<br>Build your progress.</h3>

                <p>
                    Explore available jobs, submit applications, manage
                    assigned tasks, track your working hours, and review
                    your timesheets.
                </p>

                <a href="pages/jobs.php">
                    Explore Jobs <i class="bi bi-arrow-right"></i>
                </a>

            </div>


            <div class="landing-audience-card landing-audience-employer">

                <div class="landing-audience-icon">
                    <i class="bi bi-building"></i>
                </div>

                <span>FOR EMPLOYERS</span>

                <h3>Manage work.<br>Keep everything organized.</h3>

                <p>
                    Publish job opportunities, find workers, assign tasks,
                    review submissions, and monitor working hours and
                    timesheets.
                </p>

                <a href="register.php">
                    Get Started <i class="bi bi-arrow-right"></i>
                </a>

            </div>

        </section>


        <!-- FEATURES -->
        <section class="wp-landing-section" id="features">

            <div class="wp-section-heading">

                <span class="landing-section-label">WHAT WORKPULSE OFFERS</span>

                <h2>Everything you need to stay productive.</h2>

                <p>
                    A connected workspace designed to make work easier
                    to organize, manage, and track.
                </p>

            </div>


            <div class="wp-feature-grid">

                <div class="wp-feature-card">

                    <div class="wp-feature-icon">
                        <i class="bi bi-search"></i>
                    </div>

                    <h3>Job Marketplace</h3>

                    <p>
                        Discover available opportunities and connect
                        with employers looking for skilled workers.
                    </p>

                </div>


                <div class="wp-feature-card">

                    <div class="wp-feature-icon">
                        <i class="bi bi-kanban"></i>
                    </div>

                    <h3>Task Management</h3>

                    <p>
                        Organize assignments, deadlines, and submissions
                        to keep work moving in the right direction.
                    </p>

                </div>


                <div class="wp-feature-card">

                    <div class="wp-feature-icon">
                        <i class="bi bi-stopwatch"></i>
                    </div>

                    <h3>Time Tracking</h3>

                    <p>
                        Record working sessions and keep track of
                        time spent on assigned work.
                    </p>

                </div>


                <div class="wp-feature-card">

                    <div class="wp-feature-icon">
                        <i class="bi bi-calendar-check"></i>
                    </div>

                    <h3>Timesheets</h3>

                    <p>
                        Organize recorded work hours into monthly
                        timesheets for employer review.
                    </p>

                </div>


                <div class="wp-feature-card">

                    <div class="wp-feature-icon">
                        <i class="bi bi-file-earmark-check"></i>
                    </div>

                    <h3>Work Submissions</h3>

                    <p>
                        Submit completed tasks, receive feedback,
                        and handle requested revisions.
                    </p>

                </div>


                <div class="wp-feature-card">

                    <div class="wp-feature-icon">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>

                    <h3>Work Overview</h3>

                    <p>
                        Keep important work information, tasks,
                        and progress accessible from your dashboard.
                    </p>

                </div>

            </div>

        </section>


        <!-- HOW IT WORKS -->
        <section class="landing-how" id="how-it-works">

            <div class="wp-section-heading">

                <span class="landing-section-label">GETTING STARTED</span>

                <h2>Simple steps. Better workflow.</h2>

                <p>
                    Getting started with WorkPulse is straightforward.
                </p>

            </div>


            <div class="wp-steps-grid">

                <div class="wp-step">

                    <div class="wp-step-number">01</div>

                    <h3>Create your account</h3>

                    <p>
                        Register as a worker or employer and set up
                        your WorkPulse account.
                    </p>

                </div>


                <div class="wp-step">

                    <div class="wp-step-number">02</div>

                    <h3>Connect and get started</h3>

                    <p>
                        Workers discover opportunities while employers
                        publish jobs and connect with talent.
                    </p>

                </div>


                <div class="wp-step">

                    <div class="wp-step-number">03</div>

                    <h3>Manage your work</h3>

                    <p>
                        Organize tasks, track working hours, and
                        monitor progress through your dashboard.
                    </p>

                </div>

            </div>

        </section>


        <!-- CALL TO ACTION -->
        <section class="wp-landing-cta">

            <span>YOUR NEXT STEP STARTS HERE</span>

            <h2>Ready to move forward?</h2>

            <p>
                Join WorkPulse and make your work experience
                more organized and productive.
            </p>

            <?php if (!$is_logged_in): ?>

                <a href="register.php" class="wp-btn landing-cta-button">
                    Create Your Account
                    <i class="bi bi-arrow-right"></i>
                </a>

            <?php else: ?>

                <a href="<?= htmlspecialchars($dashboard_url) ?>" class="wp-btn landing-cta-button">
                    Go to Your Dashboard
                    <i class="bi bi-arrow-right"></i>
                </a>

            <?php endif; ?>

        </section>


        <!-- FOOTER -->
        <footer class="wp-landing-footer">

            <div class="landing-footer-top">

                <a href="index.php" class="wp-landing-brand">
                    <span class="landing-brand-icon">
                        <i class="bi bi-activity"></i>
                    </span>
                    WorkPulse
                </a>

                <div class="landing-footer-links">
                    <a href="pages/jobs.php">Find Jobs</a>
                    <a href="login.php">Sign In</a>
                    <a href="register.php">Create Account</a>
                </div>

            </div>

            <div class="landing-footer-bottom">
                <p>© <?= date('Y') ?> WorkPulse. All rights reserved.</p>
                <p>Work smarter. Move forward.</p>
            </div>

        </footer>

    </div>

</body>

</html>