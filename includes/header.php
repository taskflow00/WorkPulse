<?php

// Default page information
$page_title = $page_title ?? 'Dashboard';
$active_page = $active_page ?? '';

// Get user information from the authenticated session/profile
$user_name = $profile[0]['full_name']
    ?? ($_SESSION['user']['user_metadata']['full_name']
        ?? ($user_name ?? 'WorkPulse User'));

$user_email = $_SESSION['user']['email'] ?? '';
$user_role = $_SESSION['role'] ?? 'worker';

$user_initial = strtoupper(substr(trim($user_name), 0, 1));

// Profile page depends on the user's role
$profile_url = '';

if ($user_role === 'worker') {
    $profile_url = '/pages/worker/profile.php';
} elseif ($user_role === 'employer') {
    $profile_url = '/pages/employer/profile.php';
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?> | WorkPulse</title>

    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/img/favicon-16.png">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">


    <!-- Bootstrap -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet">

    <!-- WorkPulse styles -->
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/components.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">

    <?php if (isset($page_styles)): ?>
        <?php foreach ($page_styles as $style): ?>
            <link rel="stylesheet" href="<?= htmlspecialchars($style) ?>">
        <?php endforeach; ?>
    <?php endif; ?>

</head>

<body>

    <div class="wp-dashboard">

        <!-- Load the role-based sidebar -->
        <?php require_once __DIR__ . '/sidebar.php'; ?>

        <!-- Main dashboard area -->
        <main class="wp-main">

            <!-- Topbar -->
            <header class="wp-topbar">

                <div class="wp-topbar-left">

                    <button
                        type="button"
                        class="wp-icon-button wp-menu-toggle"
                        id="sidebarToggle"
                        aria-label="Open navigation">

                        <i class="bi bi-list"></i>

                    </button>

                    <div>
                        <h2 class="wp-topbar-title">
                            <?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?>
                        </h2>
                    </div>

                </div>

                <!-- User menu -->
                <div class="wp-topbar-right">

                    <div class="dropdown">

                        <button
                            type="button"
                            class="wp-topbar-user dropdown-toggle"
                            data-bs-toggle="dropdown"
                            aria-expanded="false">

                            <div class="wp-avatar">
                                <?= htmlspecialchars($user_initial, ENT_QUOTES, 'UTF-8') ?>
                            </div>

                            <div class="wp-topbar-user-info">

                                <span class="wp-topbar-user-name">
                                    <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>
                                </span>

                                <small>
                                    <?= htmlspecialchars(ucfirst($user_role), ENT_QUOTES, 'UTF-8') ?>
                                </small>

                            </div>

                        </button>

                        <ul class="dropdown-menu dropdown-menu-end wp-user-dropdown">

                            <li class="px-3 py-2">

                                <strong>
                                    <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>
                                </strong>

                                <div class="text-muted small">
                                    <?= htmlspecialchars($user_email, ENT_QUOTES, 'UTF-8') ?>
                                </div>

                            </li>

                            <li>
                                <hr class="dropdown-divider">
                            </li>

                            <?php if ($profile_url !== ''): ?>

                                <li>
                                    <a class="dropdown-item" href="<?= $profile_url ?>">
                                        <i class="bi bi-person me-2"></i>
                                        My Profile
                                    </a>
                                </li>

                            <?php endif; ?>

                            <li>
                                <a class="dropdown-item text-danger" href="/logout.php">
                                    <i class="bi bi-box-arrow-right me-2"></i>
                                    Sign Out
                                </a>
                            </li>

                        </ul>

                    </div>

                </div>

            </header>

            <!-- Individual page content starts here -->
            <div class="wp-content">