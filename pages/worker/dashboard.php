<?php

// Page information
$page_title = 'Dashboard';
$active_page = 'dashboard';

// Temporary user information
// Later, we will get these from the logged-in user's session.
$user_name = 'Arnold Nava';
$user_role = 'worker';

// Worker navigation links
$sidebar_links = [

    [
        'label' => 'Dashboard',
        'href' => '/pages/worker/dashboard.php',
        'icon' => 'bi-grid',
        'key' => 'dashboard'
    ],

    [
        'label' => 'Find Jobs',
        'href' => '/pages/worker/marketplace.php',
        'icon' => 'bi-search',
        'key' => 'marketplace'
    ],

    [
        'label' => 'My Applications',
        'href' => '/pages/worker/applications.php',
        'icon' => 'bi-file-earmark-text',
        'key' => 'applications'
    ],

    [
        'label' => 'My Employment',
        'href' => '/pages/worker/employment.php',
        'icon' => 'bi-briefcase',
        'key' => 'employment'
    ],

    [
        'label' => 'My Profile',
        'href' => '/pages/worker/profile.php',
        'icon' => 'bi-person',
        'key' => 'profile'
    ]

];

// Include shared header
require_once '../../includes/header.php';

?>

<!-- Dashboard content -->
<div class="wp-page">

    <h1 class="wp-page-title">
        Welcome back, <?= htmlspecialchars($user_name) ?>!
    </h1>

    <p class="wp-page-description">
        Here's what's happening with your work today.
    </p>

    <div class="wp-panel">

        <div class="wp-panel-body">
            Your dashboard content will go here.
        </div>

    </div>

</div>

<?php

// Include shared footer
require_once '../../includes/footer.php';

?>