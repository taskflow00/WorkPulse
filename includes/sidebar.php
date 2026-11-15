<?php

$currentPage = basename($_SERVER['PHP_SELF']);
$userRole = strtolower(trim($user_role ?? ($_SESSION['role'] ?? '')));

// Dashboard destination based on role
$dashboardUrl = '/';

if ($userRole === 'worker') {
    $dashboardUrl = '/pages/worker/dashboard.php';
} elseif ($userRole === 'employer') {
    $dashboardUrl = '/pages/employer/dashboard.php';
} elseif ($userRole === 'admin') {
    $dashboardUrl = '/pages/admin/dashboard.php';
}

?>

<!-- Mobile sidebar backdrop -->
<div class="wp-sidebar-backdrop" id="sidebarBackdrop"></div>

<!-- Sidebar -->
<aside class="wp-sidebar" id="sidebar">

    <!-- Brand -->
    <a href="<?= $dashboardUrl ?>" class="wp-sidebar-brand text-decoration-none">

        <div class="wp-brand-mark">W</div>

        <div>
            <h1 class="wp-brand-name">WorkPulse</h1>
            <small>Work management platform</small>
        </div>

    </a>

    <!-- WORKER NAVIGATION -->
    <?php if ($userRole === 'worker'): ?>

        <nav class="wp-sidebar-nav">

            <div class="wp-sidebar-section">

                <span class="wp-sidebar-label">Workspace</span>

                <a href="/pages/worker/dashboard.php"
                    class="wp-sidebar-link <?= ($currentPage === 'dashboard.php') ? 'active' : ''; ?>">
                    <i class="bi bi-grid"></i>
                    <span>Dashboard</span>
                </a>

                <a href="/pages/jobs.php"
                    class="wp-sidebar-link <?= ($currentPage === 'jobs.php') ? 'active' : ''; ?>">
                    <i class="bi bi-search"></i>
                    <span>Find Jobs</span>
                </a>

                <a href="/pages/worker/applications.php"
                    class="wp-sidebar-link <?= ($currentPage === 'applications.php') ? 'active' : ''; ?>">
                    <i class="bi bi-file-earmark-text"></i>
                    <span>My Applications</span>
                </a>

                <a href="/pages/worker/invitations.php"
                    class="wp-sidebar-link <?= ($currentPage === 'invitations.php') ? 'active' : ''; ?>">
                    <i class="bi bi-envelope"></i>
                    <span>Invitations</span>
                </a>

                <a href="/pages/worker/offers.php"
                    class="wp-sidebar-link <?= ($currentPage === 'offers.php') ? 'active' : ''; ?>">
                    <i class="bi bi-send"></i>
                    <span>Offers</span>
                </a>

            </div>

            <div class="wp-sidebar-section">

                <span class="wp-sidebar-label">My Work</span>

                <a href="/pages/worker/employment.php"
                    class="wp-sidebar-link <?= ($currentPage === 'employment.php') ? 'active' : ''; ?>">
                    <i class="bi bi-briefcase"></i>
                    <span>Employment</span>
                </a>

                <a href="/pages/worker/tasks.php"
                    class="wp-sidebar-link <?= ($currentPage === 'tasks.php') ? 'active' : ''; ?>">
                    <i class="bi bi-list-task"></i>
                    <span>My Tasks</span>
                </a>

                <a href="/pages/worker/time-tracker.php"
                    class="wp-sidebar-link <?= ($currentPage === 'time-tracker.php') ? 'active' : ''; ?>">
                    <i class="bi bi-stopwatch"></i>
                    <span>Time Tracker</span>
                </a>

                <a href="/pages/worker/timesheets.php"
                    class="wp-sidebar-link <?= ($currentPage === 'timesheets.php') ? 'active' : ''; ?>">
                    <i class="bi bi-calendar-check"></i>
                    <span>Timesheets</span>
                </a>

                <a href="/pages/worker/earnings.php"
                    class="wp-sidebar-link <?= ($currentPage === 'earnings.php') ? 'active' : ''; ?>">
                    <i class="bi bi-wallet2"></i>
                    <span>Earnings</span>
                </a>

            </div>

            <div class="wp-sidebar-section">

                <span class="wp-sidebar-label">Account</span>

                <a href="/pages/worker/profile.php"
                    class="wp-sidebar-link <?= ($currentPage === 'profile.php') ? 'active' : ''; ?>">
                    <i class="bi bi-person"></i>
                    <span>My Profile</span>
                </a>

            </div>

        </nav>

        <!-- EMPLOYER NAVIGATION -->
    <?php elseif ($userRole === 'employer'): ?>

        <nav class="wp-sidebar-nav">

            <div class="wp-sidebar-section">

                <span class="wp-sidebar-label">Workspace</span>

                <a href="/pages/employer/dashboard.php"
                    class="wp-sidebar-link <?= ($currentPage === 'dashboard.php') ? 'active' : ''; ?>">
                    <i class="bi bi-grid"></i>
                    <span>Dashboard</span>
                </a>

                <a href="/pages/employer/find-freelancers.php"
                    class="wp-sidebar-link <?= ($currentPage === 'find-freelancers.php') ? 'active' : ''; ?>">
                    <i class="bi bi-search"></i>
                    <span>Find Freelancers</span>
                </a>

                <a href="/pages/employer/jobs.php"
                    class="wp-sidebar-link <?= ($currentPage === 'jobs.php') ? 'active' : ''; ?>">
                    <i class="bi bi-briefcase"></i>
                    <span>My Jobs</span>
                </a>

                <a href="/pages/employer/create-job.php"
                    class="wp-sidebar-link <?= ($currentPage === 'create-job.php') ? 'active' : ''; ?>">
                    <i class="bi bi-plus-circle"></i>
                    <span>Create Job</span>
                </a>

            </div>

            <div class="wp-sidebar-section">

                <span class="wp-sidebar-label">Management</span>

                <a href="/pages/employer/applications.php"
                    class="wp-sidebar-link <?= ($currentPage === 'applications.php') ? 'active' : ''; ?>">
                    <i class="bi bi-file-earmark-text"></i>
                    <span>Applications</span>
                </a>

                <a href="/pages/employer/employees.php"
                    class="wp-sidebar-link <?= ($currentPage === 'employees.php') ? 'active' : ''; ?>">
                    <i class="bi bi-people"></i>
                    <span>Employees</span>
                </a>

                <a href="/pages/employer/tasks.php"
                    class="wp-sidebar-link <?= ($currentPage === 'tasks.php') ? 'active' : ''; ?>">
                    <i class="bi bi-list-task"></i>
                    <span>Tasks</span>
                </a>

                <a href="/pages/employer/timesheets.php"
                    class="wp-sidebar-link <?= ($currentPage === 'timesheets.php') ? 'active' : ''; ?>">
                    <i class="bi bi-calendar-check"></i>
                    <span>Timesheets</span>
                </a>

                <a href="/pages/employer/earnings.php"
                    class="wp-sidebar-link <?= ($currentPage === 'earnings.php') ? 'active' : ''; ?>">
                    <i class="bi bi-wallet2"></i>
                    <span>Earnings</span>
                </a>

            </div>

            <div class="wp-sidebar-section">

                <span class="wp-sidebar-label">Account</span>

                <a href="/pages/employer/profile.php"
                    class="wp-sidebar-link <?= ($currentPage === 'profile.php') ? 'active' : ''; ?>">
                    <i class="bi bi-building"></i>
                    <span>Company Profile</span>
                </a>

            </div>

        </nav>

        <!-- ADMIN NAVIGATION -->
    <?php elseif ($userRole === 'admin'): ?>

        <nav class="wp-sidebar-nav">

            <div class="wp-sidebar-section">

                <span class="wp-sidebar-label">Administration</span>

                <a href="/pages/admin/dashboard.php"
                    class="wp-sidebar-link <?= ($currentPage === 'dashboard.php') ? 'active' : ''; ?>">
                    <i class="bi bi-grid"></i>
                    <span>Dashboard</span>
                </a>

                <a href="/pages/admin/users.php"
                    class="wp-sidebar-link <?= ($currentPage === 'users.php') ? 'active' : ''; ?>">
                    <i class="bi bi-people"></i>
                    <span>Users</span>
                </a>

                <a href="/pages/admin/jobs.php"
                    class="wp-sidebar-link <?= ($currentPage === 'jobs.php') ? 'active' : ''; ?>">
                    <i class="bi bi-briefcase"></i>
                    <span>Jobs</span>
                </a>

                <a href="/pages/admin/applications.php"
                    class="wp-sidebar-link <?= ($currentPage === 'applications.php') ? 'active' : ''; ?>">
                    <i class="bi bi-file-earmark-text"></i>
                    <span>Applications</span>
                </a>

                <a href="/pages/admin/employments.php"
                    class="wp-sidebar-link <?= ($currentPage === 'employments.php') ? 'active' : ''; ?>">
                    <i class="bi bi-person-workspace"></i>
                    <span>Employments</span>
                </a>

            </div>

        </nav>

    <?php endif; ?>

    <!-- Sidebar bottom -->
    <div class="wp-sidebar-bottom">

        <a href="/logout.php" class="wp-sidebar-logout">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </div>

</aside>