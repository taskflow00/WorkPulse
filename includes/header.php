<?php
// Default values if the page does not provide them
$page_title = $page_title ?? 'Dashboard';
$active_page = $active_page ?? '';

$user_name = $user_name ?? 'WorkPulse User';
$user_role = $user_role ?? 'User';

$sidebar_links = $sidebar_links ?? [];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= htmlspecialchars($page_title) ?> | WorkPulse
    </title>

    <!-- Bootstrap CSS -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet">

    <!-- WorkPulse CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/components.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
</head>

<body>

    <div class="wp-dashboard">

        <!-- Mobile sidebar background -->
        <div
            class="wp-sidebar-backdrop"
            id="sidebarBackdrop"></div>

        <!-- Sidebar -->
        <aside class="wp-sidebar" id="sidebar">

            <!-- Brand -->
            <div class="wp-sidebar-brand">
                <div class="wp-brand-mark">W</div>

                <h1 class="wp-brand-name">
                    WorkPulse
                </h1>
            </div>

            <!-- Navigation label -->
            <div class="wp-sidebar-label">
                Menu
            </div>

            <!-- Navigation links -->
            <nav class="wp-sidebar-nav">

                <?php foreach ($sidebar_links as $link): ?>

                    <a
                        href="<?= htmlspecialchars($link['href']) ?>"
                        class="wp-sidebar-link <?= ($active_page == $link['key']) ? 'active' : '' ?>">

                        <?php if (!empty($link['icon'])): ?>
                            <i class="bi <?= htmlspecialchars($link['icon']) ?>"></i>
                        <?php endif; ?>

                        <span>
                            <?= htmlspecialchars($link['label']) ?>
                        </span>

                    </a>

                <?php endforeach; ?>

            </nav>

            <!-- User information -->
            <div class="wp-sidebar-bottom">

                <div class="wp-user-menu">

                    <div class="wp-avatar">
                        <?= strtoupper(substr($user_name, 0, 1)) ?>
                    </div>

                    <div>
                        <div class="wp-user-name">
                            <?= htmlspecialchars($user_name) ?>
                        </div>

                        <div class="wp-user-role">
                            <?= htmlspecialchars(ucfirst($user_role)) ?>
                        </div>
                    </div>

                </div>

            </div>

        </aside>

        <!-- Main content -->
        <main class="wp-main">

            <!-- Top navigation -->
            <header class="wp-topbar">

                <div class="wp-topbar-left">

                    <!-- Mobile menu button -->
                    <button
                        type="button"
                        class="wp-icon-button wp-menu-toggle"
                        id="sidebarToggle"
                        aria-label="Open navigation">
                        <i class="bi bi-list fs-5"></i>
                    </button>

                    <h2 class="wp-topbar-title">
                        <?= htmlspecialchars($page_title) ?>
                    </h2>

                </div>

                <!-- Right side of topbar -->
                <div class="wp-topbar-right">

                    <button
                        type="button"
                        class="wp-icon-button"
                        aria-label="Notifications">
                        <i class="bi bi-bell"></i>
                    </button>

                </div>

            </header>

            <!-- Individual page content starts here -->
            <div class="wp-content">