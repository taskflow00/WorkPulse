<?php
// Get the current user's information.
$user = $_SESSION['user'] ?? [];
$role = $_SESSION['role'] ?? '';

$user_name = $user['full_name'] ?? $user['name'] ?? 'User';

// Set the page title if the page did not provide one.
$page_title = $page_title ?? 'Dashboard';
$active_page = $active_page ?? '';

// Find the correct path depending on where the page is located.
// Pages inside /pages/ need to go up two folders.
$base_path = (strpos($_SERVER['SCRIPT_NAME'], '/pages/') !== false) ? '../../' : '';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= htmlspecialchars($page_title) ?> | WorkPulse</title>

    <link rel="stylesheet" href="<?= $base_path ?>assets/css/style.css">
    <link rel="stylesheet" href="<?= $base_path ?>assets/css/layout.css">
</head>

<body>

    <div class="app-layout">

        <!-- Sidebar -->
        <aside class="sidebar">

            <div class="sidebar-brand">
                <h2>WorkPulse<span>.</span></h2>
                <small>Manage Work. Track Time. Grow Together.</small>
            </div>

            <div class="sidebar-section">
                <p class="sidebar-label">WORKSPACE</p>

                <?php if ($role === 'worker'): ?>

                    <a href="<?= $base_path ?>pages/worker/dashboard.php"
                        class="nav-link <?= $active_page === 'dashboard' ? 'active' : '' ?>">
                        Dashboard
                    </a>

                    <a href="<?= $base_path ?>pages/worker/offers.php"
                        class="nav-link <?= $active_page === 'offers' ? 'active' : '' ?>">
                        Employment Offers
                    </a>

                    <a href="<?= $base_path ?>pages/worker/employment.php"
                        class="nav-link <?= $active_page === 'employment' ? 'active' : '' ?>">
                        My Employment
                    </a>

                    <a href="<?= $base_path ?>pages/worker/tasks.php"
                        class="nav-link <?= $active_page === 'tasks' ? 'active' : '' ?>">
                        My Tasks
                    </a>

                    <a href="<?= $base_path ?>pages/worker/time-tracker.php"
                        class="nav-link <?= $active_page === 'tracker' ? 'active' : '' ?>">
                        Time Tracker
                    </a>

                    <a href="<?= $base_path ?>pages/worker/timesheets.php"
                        class="nav-link <?= $active_page === 'timesheets' ? 'active' : '' ?>">
                        My Timesheets
                    </a>

                    <a href="<?= $base_path ?>pages/worker/earnings.php"
                        class="nav-link <?= $active_page === 'timesheets' ? 'active' : '' ?>">
                        My Earnings
                    </a>



                <?php endif; ?>

            </div>

            <div class="sidebar-bottom">
                <a href="<?= $base_path ?>logout.php" class="logout-link">
                    Log Out
                </a>
            </div>

        </aside>

        <!-- Main Area -->
        <div class="app-main">

            <!-- Topbar -->
            <header class="topbar">

                <div>
                    <h3><?= htmlspecialchars($page_title) ?></h3>
                    <p>Welcome back, <?= htmlspecialchars($user_name) ?>!</p>
                </div>

                <div class="topbar-user">
                    <span><?= htmlspecialchars($user_name) ?></span>
                </div>

            </header>

            <!-- Page Content -->
            <main class="page-content">