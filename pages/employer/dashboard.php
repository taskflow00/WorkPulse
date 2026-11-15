<?php

// Page information
$required_role = 'employer';
$page_title = 'Dashboard';
$active_page = 'dashboard';

// Check authentication and employer role
require_once '../../includes/auth.php';

require_once '../../includes/header.php';

?>

<div class="wp-page">

    <!-- Welcome section -->
    <section class="wp-dashboard-welcome">

        <div>
            <span class="wp-dashboard-eyebrow">
                EMPLOYER WORKSPACE
            </span>

            <h1 class="wp-page-title">
                Welcome back!
            </h1>

            <p class="wp-page-description">
                Manage your jobs, employees, applications, and workforce activity.
            </p>
        </div>

        <a href="/pages/employer/create-job.php" class="wp-btn wp-btn-primary">
            <i class="bi bi-plus-lg"></i>
            Create a Job
        </a>

    </section>


    <!-- Dashboard statistics -->
    <section class="wp-dashboard-stats">

        <div class="wp-stat-card">

            <div class="wp-stat-top">
                <span>Active Job Listings</span>

                <div class="wp-stat-icon rose">
                    <i class="bi bi-briefcase"></i>
                </div>
            </div>

            <h2>—</h2>

            <small>Currently published jobs</small>

        </div>


        <div class="wp-stat-card">

            <div class="wp-stat-top">
                <span>Pending Applications</span>

                <div class="wp-stat-icon yellow">
                    <i class="bi bi-file-earmark-text"></i>
                </div>
            </div>

            <h2>—</h2>

            <small>Applications awaiting review</small>

        </div>


        <div class="wp-stat-card">

            <div class="wp-stat-top">
                <span>Current Employees</span>

                <div class="wp-stat-icon blue">
                    <i class="bi bi-people"></i>
                </div>
            </div>

            <h2>—</h2>

            <small>Active workforce members</small>

        </div>


        <div class="wp-stat-card">

            <div class="wp-stat-top">
                <span>Monthly Labor Costs</span>

                <div class="wp-stat-icon green">
                    <i class="bi bi-cash-stack"></i>
                </div>
            </div>

            <h2>—</h2>

            <small>Estimated monthly expenses</small>

        </div>

    </section>


    <!-- Main dashboard columns -->
    <section class="wp-dashboard-columns">

        <!-- Recent applications -->
        <div class="wp-panel wp-dashboard-main-panel">

            <div class="wp-panel-header">

                <div>
                    <h3 class="wp-panel-title">
                        Recent Applications
                    </h3>

                    <p class="wp-panel-description">
                        Review candidates applying to your job listings.
                    </p>
                </div>

                <a href="/pages/employer/applications.php"
                    class="wp-dashboard-text-link">

                    View All
                    <i class="bi bi-arrow-right"></i>

                </a>

            </div>


            <div class="wp-panel-body">

                <div class="wp-dashboard-empty">

                    <div class="wp-dashboard-empty-icon">
                        <i class="bi bi-person-lines-fill"></i>
                    </div>

                    <h4>No recent applications</h4>

                    <p>
                        Applications from interested freelancers
                        will appear here.
                    </p>

                    <a href="/pages/employer/jobs.php" class="wp-btn wp-btn-outline">
                        Manage Job Listings
                    </a>

                </div>

            </div>

        </div>


        <!-- Quick actions -->
        <div class="wp-panel wp-dashboard-side-panel">

            <div class="wp-panel-header">

                <div>
                    <h3 class="wp-panel-title">
                        Quick Actions
                    </h3>

                    <p class="wp-panel-description">
                        Manage your workforce faster.
                    </p>
                </div>

            </div>


            <div class="wp-panel-body">

                <div class="wp-quick-actions">

                    <a href="/pages/employer/create-job.php" class="wp-quick-action">

                        <div class="wp-quick-action-icon rose">
                            <i class="bi bi-plus-circle"></i>
                        </div>

                        <div>
                            <strong>Create a Job</strong>
                            <small>Publish a new opportunity</small>
                        </div>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                    <a href="/pages/employer/find-freelancers.php" class="wp-quick-action">

                        <div class="wp-quick-action-icon blue">
                            <i class="bi bi-search"></i>
                        </div>

                        <div>
                            <strong>Find Freelancers</strong>
                            <small>Discover potential workers</small>
                        </div>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                    <a href="/pages/employer/assign-task.php" class="wp-quick-action">

                        <div class="wp-quick-action-icon yellow">
                            <i class="bi bi-list-task"></i>
                        </div>

                        <div>
                            <strong>Assign a Task</strong>
                            <small>Give work to your employees</small>
                        </div>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                    <a href="/pages/employer/employees.php" class="wp-quick-action">

                        <div class="wp-quick-action-icon green">
                            <i class="bi bi-people"></i>
                        </div>

                        <div>
                            <strong>Manage Employees</strong>
                            <small>View your current workforce</small>
                        </div>

                        <i class="bi bi-chevron-right"></i>

                    </a>

                </div>

            </div>

        </div>

    </section>


    <!-- Job listings -->
    <section class="wp-panel wp-dashboard-jobs-panel">

        <div class="wp-panel-header">

            <div>
                <h3 class="wp-panel-title">
                    Your Job Listings
                </h3>

                <p class="wp-panel-description">
                    Keep track of the opportunities you've published.
                </p>
            </div>

            <a href="/pages/employer/jobs.php" class="wp-dashboard-text-link">

                Manage Jobs
                <i class="bi bi-arrow-right"></i>

            </a>

        </div>


        <div class="wp-panel-body">

            <div class="wp-dashboard-empty">

                <div class="wp-dashboard-empty-icon">
                    <i class="bi bi-briefcase"></i>
                </div>

                <h4>No job listings yet</h4>

                <p>
                    Create your first job listing to start connecting
                    with qualified freelancers.
                </p>

                <a href="/pages/employer/create-job.php" class="wp-btn wp-btn-primary">
                    <i class="bi bi-plus-lg"></i>
                    Create Your First Job
                </a>

            </div>

        </div>

    </section>

</div>

<?php

// Include shared footer
require_once '../../includes/footer.php';

?>