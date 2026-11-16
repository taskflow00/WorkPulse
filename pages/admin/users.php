<?php

$page_title = 'User Management';
$active_page = 'users';
$page_styles = ['/assets/css/admin-users.css'];

$required_role = 'admin';
require_once '../../includes/auth.php';


date_default_timezone_set('Asia/Manila');

$token = $_SESSION['access_token'];
$admin_id = $_SESSION['user']['id'];


// Create a CSRF token for account management forms.
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];


// Get search and role filters.
$search = isset($_GET['search']) && is_string($_GET['search'])
    ? trim($_GET['search'])
    : '';

$role = isset($_GET['role']) && is_string($_GET['role'])
    ? $_GET['role']
    : 'all';


// Only allow valid roles.
if (!in_array($role, ['all', 'worker', 'employer', 'admin'], true)) {
    $role = 'all';
}


// Default suspension date: seven days from now.
$default_suspend_until = date(
    'Y-m-d\TH:i',
    time() + (7 * 24 * 60 * 60)
);


// Handle account management actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $message = '';

    // Verify CSRF token.
    if (
        !isset($_POST['csrf_token']) ||
        !is_string($_POST['csrf_token']) ||
        !hash_equals($csrf_token, $_POST['csrf_token'])
    ) {

        $message = 'Invalid request. Please try again.';
    } elseif (SUPABASE_SERVICE_ROLE_KEY === '') {

        $message = 'Supabase service role key is missing from your configuration.';
    } else {

        $action = isset($_POST['action']) && is_string($_POST['action'])
            ? $_POST['action']
            : '';

        $target_id = isset($_POST['user_id']) && is_string($_POST['user_id'])
            ? $_POST['user_id']
            : '';

        $reason = isset($_POST['reason']) && is_string($_POST['reason'])
            ? trim($_POST['reason'])
            : '';


        // Validate the requested action.
        if (!in_array($action, ['suspend', 'ban', 'restore'], true)) {

            $message = 'Invalid account management action.';
        } elseif (!preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $target_id
        )) {

            $message = 'Invalid user ID.';
        } elseif ($target_id === $admin_id) {

            $message = 'You cannot restrict your own administrator account.';
        } else {

            // Retrieve the selected account using the service role key.
            $target_url = SUPABASE_URL .
                '/rest/v1/profiles?select=id,role,account_status&id=eq.' .
                urlencode($target_id);

            $target_options = [
                'http' => [
                    'method' => 'GET',
                    'header' =>
                    "apikey: " . SUPABASE_SERVICE_ROLE_KEY . "\r\n" .
                        "Authorization: Bearer " . SUPABASE_SERVICE_ROLE_KEY . "\r\n",
                    'ignore_errors' => true
                ]
            ];

            $target_response = @file_get_contents(
                $target_url,
                false,
                stream_context_create($target_options)
            );

            $target_headers = http_get_last_response_headers();
            $target_status = 0;

            if ($target_headers !== false && isset($target_headers[0])) {
                preg_match('/\s(\d{3})(?:\s|$)/', $target_headers[0], $matches);
                $target_status = (int) ($matches[1] ?? 0);
            }

            $target_data = json_decode($target_response ?: '', true);


            if (
                $target_response === false ||
                $target_status < 200 ||
                $target_status >= 300 ||
                !is_array($target_data) ||
                !isset($target_data[0])
            ) {

                $message = 'Unable to retrieve the selected account.';
            } elseif ($target_data[0]['role'] === 'admin') {

                $message = 'Administrator accounts cannot be restricted here.';
            } else {

                $target_status_value = $target_data[0]['account_status'] ?? 'active';

                $profile_data = [];
                $ban_duration = '';


                // Only active accounts can receive new restrictions.
                if (
                    in_array($action, ['suspend', 'ban'], true) &&
                    $target_status_value !== 'active'
                ) {

                    $message = 'Only active accounts can be suspended or banned.';
                } elseif (
                    $action === 'restore' &&
                    !in_array($target_status_value, ['suspended', 'banned'], true)
                ) {

                    $message = 'This account is already active.';
                } else {


                    // Prepare temporary suspension information.
                    if ($action === 'suspend') {

                        $suspend_until = isset($_POST['restricted_until']) &&
                            is_string($_POST['restricted_until'])
                            ? $_POST['restricted_until']
                            : '';

                        $suspension_date = DateTime::createFromFormat(
                            'Y-m-d\TH:i',
                            $suspend_until,
                            new DateTimeZone('Asia/Manila')
                        );

                        if (
                            $reason === '' ||
                            strlen($reason) > 500 ||
                            !$suspension_date ||
                            $suspension_date->format('Y-m-d\TH:i') !== $suspend_until ||
                            $suspension_date->getTimestamp() <= time()
                        ) {

                            $message = 'Enter a valid future suspension date and a reason (maximum 500 characters).';
                        } else {

                            // Temporary suspension is handled by WorkPulse.
                            // Do not apply a Supabase Auth ban.
                            $profile_data = [
                                'account_status' => 'suspended',
                                'restriction_reason' => $reason,
                                'restricted_until' => $suspension_date->format('c'),
                                'restricted_by' => $admin_id
                            ];

                            // Run the automatic cleanup after redirect.
                            // This also repairs old Supabase Auth bans.
                            unset($_SESSION['legacy_suspension_bans_checked']);
                        }


                        // Prepare permanent ban information.
                    } elseif ($action === 'ban') {

                        if ($reason === '' || strlen($reason) > 500) {

                            $message = 'Please provide a reason for the ban (maximum 500 characters).';
                        } else {

                            // Approximately 100 years.
                            $ban_duration = '876000h';

                            $profile_data = [
                                'account_status' => 'banned',
                                'restriction_reason' => $reason,
                                'restricted_until' => null,
                                'restricted_by' => $admin_id
                            ];
                        }


                        // Prepare account restoration.
                    } elseif ($action === 'restore') {

                        $profile_data = [
                            'account_status' => 'active',
                            'restriction_reason' => null,
                            'restricted_until' => null,
                            'restricted_by' => null
                        ];
                    }


                    // Continue only if the action was valid.
                    if (!empty($profile_data)) {

                        $auth_url = SUPABASE_URL .
                            '/auth/v1/admin/users/' .
                            urlencode($target_id);


                        /*
                         * RESTORE ACCOUNT
                         *
                         * Remove the Supabase Auth ban FIRST.
                         * Only activate the WorkPulse profile if Auth succeeds.
                         */
                        if ($action === 'restore') {

                            $auth_options = [
                                'http' => [
                                    'method' => 'PUT',
                                    'header' =>
                                    "apikey: " . SUPABASE_SERVICE_ROLE_KEY . "\r\n" .
                                        "Authorization: Bearer " . SUPABASE_SERVICE_ROLE_KEY . "\r\n" .
                                        "Content-Type: application/json\r\n",

                                    'content' => json_encode([
                                        'ban_duration' => 'none'
                                    ]),

                                    'ignore_errors' => true
                                ]
                            ];

                            $auth_response = @file_get_contents(
                                $auth_url,
                                false,
                                stream_context_create($auth_options)
                            );

                            $auth_headers = http_get_last_response_headers();
                            $auth_status = 0;

                            if ($auth_headers !== false && isset($auth_headers[0])) {
                                preg_match('/\s(\d{3})(?:\s|$)/', $auth_headers[0], $matches);
                                $auth_status = (int) ($matches[1] ?? 0);
                            }

                            $auth_error = json_decode($auth_response ?: '', true);
                            $auth_error_message = '';

                            if (is_array($auth_error)) {
                                $auth_error_message =
                                    $auth_error['msg'] ??
                                    $auth_error['message'] ??
                                    $auth_error['error_description'] ??
                                    $auth_error['error'] ??
                                    '';
                            }

                            if (
                                $auth_response === false ||
                                $auth_status < 200 ||
                                $auth_status >= 300
                            ) {

                                if ($auth_error_message === '') {
                                    $auth_error_message = 'No error details returned by Supabase.';
                                }

                                $message = 'Unable to remove the Supabase Auth ban (HTTP ' .
                                    $auth_status . '): ' . $auth_error_message;
                            }
                        }


                        /*
                         * UPDATE WORKPULSE PROFILE
                         *
                         * Suspension: mark profile suspended first.
                         * Permanent ban: mark profile banned first.
                         * Restoration: activate only after Auth succeeds.
                         */
                        if ($message === '') {

                            $profile_url = SUPABASE_URL .
                                '/rest/v1/profiles?id=eq.' .
                                urlencode($target_id);

                            $profile_options = [
                                'http' => [
                                    'method' => 'PATCH',
                                    'header' =>
                                    "apikey: " . SUPABASE_SERVICE_ROLE_KEY . "\r\n" .
                                        "Authorization: Bearer " . SUPABASE_SERVICE_ROLE_KEY . "\r\n" .
                                        "Content-Type: application/json\r\n" .
                                        "Prefer: return=minimal\r\n",

                                    'content' => json_encode($profile_data),
                                    'ignore_errors' => true
                                ]
                            ];

                            $profile_response = @file_get_contents(
                                $profile_url,
                                false,
                                stream_context_create($profile_options)
                            );

                            $profile_headers = http_get_last_response_headers();
                            $profile_status = 0;

                            if ($profile_headers !== false && isset($profile_headers[0])) {
                                preg_match('/\s(\d{3})(?:\s|$)/', $profile_headers[0], $matches);
                                $profile_status = (int) ($matches[1] ?? 0);
                            }

                            if (
                                $profile_response === false ||
                                $profile_status < 200 ||
                                $profile_status >= 300
                            ) {

                                $message = 'Unable to update the WorkPulse account status.';
                            } elseif ($action === 'suspend') {

                                // No Auth ban is applied.
                                // Automatic cleanup will remove any previous ban.
                                $message = 'Account successfully suspended. WorkPulse will enforce the suspension until its expiration.';
                            } elseif ($action === 'restore') {

                                $message = 'Account successfully restored.';
                            }
                        }


                        /*
                         * PERMANENT BAN
                         *
                         * The WorkPulse profile is already marked as banned.
                         * Now apply the permanent Supabase Auth ban.
                         */
                        if ($message === '' && $action === 'ban') {

                            $auth_options = [
                                'http' => [
                                    'method' => 'PUT',
                                    'header' =>
                                    "apikey: " . SUPABASE_SERVICE_ROLE_KEY . "\r\n" .
                                        "Authorization: Bearer " . SUPABASE_SERVICE_ROLE_KEY . "\r\n" .
                                        "Content-Type: application/json\r\n",

                                    'content' => json_encode([
                                        'ban_duration' => $ban_duration
                                    ]),

                                    'ignore_errors' => true
                                ]
                            ];

                            $auth_response = @file_get_contents(
                                $auth_url,
                                false,
                                stream_context_create($auth_options)
                            );

                            $auth_headers = http_get_last_response_headers();
                            $auth_status = 0;

                            if ($auth_headers !== false && isset($auth_headers[0])) {
                                preg_match('/\s(\d{3})(?:\s|$)/', $auth_headers[0], $matches);
                                $auth_status = (int) ($matches[1] ?? 0);
                            }

                            $auth_error = json_decode($auth_response ?: '', true);
                            $auth_error_message = '';

                            if (is_array($auth_error)) {
                                $auth_error_message =
                                    $auth_error['msg'] ??
                                    $auth_error['message'] ??
                                    $auth_error['error_description'] ??
                                    $auth_error['error'] ??
                                    '';
                            }

                            if (
                                $auth_response === false ||
                                $auth_status < 200 ||
                                $auth_status >= 300
                            ) {

                                if ($auth_error_message === '') {
                                    $auth_error_message = 'No error details returned by Supabase.';
                                }

                                $message = 'WorkPulse ban saved, but Supabase Auth failed (HTTP ' .
                                    $auth_status . '): ' . $auth_error_message;
                            } else {

                                $message = 'Account successfully banned.';
                            }
                        }
                    }
                }
            }
        }
    }


    // Save the result and redirect to prevent duplicate submissions.
    $_SESSION['user_management_message'] = $message;

    $redirect_query = [];

    if ($search !== '') {
        $redirect_query['search'] = $search;
    }

    if ($role !== 'all') {
        $redirect_query['role'] = $role;
    }

    header(
        'Location: users.php' .
            (!empty($redirect_query) ? '?' . http_build_query($redirect_query) : '')
    );

    exit;
}


// Display the result of the previous action.
$message = $_SESSION['user_management_message'] ?? '';
unset($_SESSION['user_management_message']);

$cleanup_warning = '';


// AUTOMATIC CLEANUP OF OLD TEMPORARY SUSPENSION BANS
//
// Previous versions of WorkPulse applied Supabase Auth bans to temporary
// suspensions. Remove those bans automatically.
//
// Only accounts marked as suspended are affected.
// Permanently banned accounts are NOT touched.

if (!isset($_SESSION['legacy_suspension_bans_checked'])) {

    if (SUPABASE_SERVICE_ROLE_KEY === '') {

        $cleanup_warning = 'Automatic suspension cleanup could not run because the service role key is missing.';
    } else {

        // Retrieve all suspended account IDs.
        $cleanup_url = SUPABASE_URL .
            '/rest/v1/profiles?select=id&account_status=eq.suspended&limit=1000';

        $cleanup_options = [
            'http' => [
                'method' => 'GET',
                'header' =>
                "apikey: " . SUPABASE_SERVICE_ROLE_KEY . "\r\n" .
                    "Authorization: Bearer " . SUPABASE_SERVICE_ROLE_KEY . "\r\n",
                'ignore_errors' => true
            ]
        ];

        $cleanup_response = @file_get_contents(
            $cleanup_url,
            false,
            stream_context_create($cleanup_options)
        );

        $cleanup_headers = http_get_last_response_headers();
        $cleanup_status = 0;

        if ($cleanup_headers !== false && isset($cleanup_headers[0])) {
            preg_match('/\s(\d{3})(?:\s|$)/', $cleanup_headers[0], $matches);
            $cleanup_status = (int) ($matches[1] ?? 0);
        }

        $suspended_accounts = json_decode($cleanup_response ?: '', true);


        if (
            $cleanup_response === false ||
            $cleanup_status < 200 ||
            $cleanup_status >= 300 ||
            !is_array($suspended_accounts)
        ) {

            $cleanup_warning = 'Unable to check existing suspended accounts for old Supabase Auth bans. Cleanup will be retried on your next visit.';
        } else {

            $cleanup_failed = 0;

            // Remove the old Auth ban from every suspended account.
            foreach ($suspended_accounts as $suspended_user) {

                if (empty($suspended_user['id'])) {
                    continue;
                }

                $auth_url = SUPABASE_URL .
                    '/auth/v1/admin/users/' .
                    urlencode($suspended_user['id']);

                $auth_options = [
                    'http' => [
                        'method' => 'PUT',
                        'header' =>
                        "apikey: " . SUPABASE_SERVICE_ROLE_KEY . "\r\n" .
                            "Authorization: Bearer " . SUPABASE_SERVICE_ROLE_KEY . "\r\n" .
                            "Content-Type: application/json\r\n",

                        'content' => json_encode([
                            'ban_duration' => 'none'
                        ]),

                        'ignore_errors' => true
                    ]
                ];

                $auth_response = @file_get_contents(
                    $auth_url,
                    false,
                    stream_context_create($auth_options)
                );

                $auth_headers = http_get_last_response_headers();
                $auth_status = 0;

                if ($auth_headers !== false && isset($auth_headers[0])) {
                    preg_match('/\s(\d{3})(?:\s|$)/', $auth_headers[0], $matches);
                    $auth_status = (int) ($matches[1] ?? 0);
                }

                if (
                    $auth_response === false ||
                    $auth_status < 200 ||
                    $auth_status >= 300
                ) {
                    $cleanup_failed++;
                }
            }


            // Mark cleanup complete only if every request succeeded.
            if ($cleanup_failed === 0) {

                $_SESSION['legacy_suspension_bans_checked'] = true;
            } else {

                $cleanup_warning = 'Automatic cleanup could not remove old Supabase Auth bans from ' .
                    $cleanup_failed .
                    ' suspended account(s). WorkPulse will retry on your next visit.';
            }
        }
    }
}


// Build the Supabase profiles query.
$query = 'profiles?select=id,full_name,role,created_at,account_status,restriction_reason,restricted_until&order=created_at.desc&limit=100';


// Filter by role.
if ($role !== 'all') {
    $query .= '&role=eq.' . urlencode($role);
}


// Search by full name.
if ($search !== '') {
    $query .= '&full_name=ilike.' . urlencode('*' . $search . '*');
}


// Retrieve profiles from Supabase.
$url = SUPABASE_URL . '/rest/v1/' . $query;

$options = [
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $token . "\r\n",
        'ignore_errors' => true
    ]
];

$response = @file_get_contents(
    $url,
    false,
    stream_context_create($options)
);

$users = [];

if ($response === false) {

    $message = 'Unable to connect to Supabase.';
} else {

    $result = json_decode($response, true);

    if (is_array($result) && !isset($result['code'])) {
        $users = $result;
    } else {
        $message = 'Unable to retrieve user accounts.';
    }
}

// Load shared header
require_once '../../includes/header.php';

?>


<div class="wp-page admin-users-page">

    <section class="admin-users-heading">

        <div>
            <span class="admin-users-eyebrow">PLATFORM ADMINISTRATION</span>
            <h1 class="wp-page-title">User Management</h1>
            <p class="wp-page-description">
                Manage registered accounts, review account statuses, and enforce platform rules.
            </p>
        </div>

        <div class="admin-users-total">
            <i class="bi bi-people"></i>
            <div>
                <strong><?= number_format(count($users)) ?></strong>
                <span>Accounts Found</span>
            </div>
        </div>

    </section>


    <?php if ($message !== ''): ?>

        <div class="alert alert-info" role="alert">
            <i class="bi bi-info-circle me-2"></i>
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>

    <?php endif; ?>


    <?php if ($cleanup_warning !== ''): ?>

        <div class="alert alert-warning" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?= htmlspecialchars($cleanup_warning, ENT_QUOTES, 'UTF-8') ?>
        </div>

    <?php endif; ?>


    <section class="wp-panel admin-users-panel">

        <div class="wp-panel-header">
            <div>
                <h3 class="wp-panel-title">Registered Accounts</h3>
                <p class="wp-panel-description">
                    Search users and manage their access to WorkPulse.
                </p>
            </div>
        </div>


        <div class="wp-panel-body">

            <!-- Search and filters -->
            <form method="GET" action="" class="admin-users-filters">

                <div class="admin-users-search">
                    <i class="bi bi-search"></i>

                    <input
                        type="text"
                        name="search"
                        placeholder="Search by user's name..."
                        value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                </div>


                <select name="role" class="form-select admin-users-role-filter">

                    <option value="all" <?= $role === 'all' ? 'selected' : '' ?>>
                        All Accounts
                    </option>

                    <option value="worker" <?= $role === 'worker' ? 'selected' : '' ?>>
                        Workers
                    </option>

                    <option value="employer" <?= $role === 'employer' ? 'selected' : '' ?>>
                        Employers
                    </option>

                    <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>
                        Administrators
                    </option>

                </select>


                <button type="submit" class="wp-btn wp-btn-primary">
                    <i class="bi bi-funnel"></i>
                    Filter
                </button>


                <?php if ($search !== '' || $role !== 'all'): ?>

                    <a href="users.php" class="wp-btn wp-btn-secondary">
                        Clear
                    </a>

                <?php endif; ?>

            </form>


            <!-- User table -->
            <?php if (count($users) > 0): ?>

                <div class="table-responsive admin-users-table-wrapper">

                    <table class="admin-users-table">

                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Registered</th>
                                <th>Account Status</th>
                                <th>Account Management</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($users as $user): ?>

                                <?php
                                $user_id = $user['id'];
                                $user_role = $user['role'];
                                $account_status = $user['account_status'] ?? 'active';

                                $is_self = $user_id === $admin_id;
                                $is_admin = $user_role === 'admin';

                                $is_expired_suspension =
                                    $account_status === 'suspended' &&
                                    !empty($user['restricted_until']) &&
                                    strtotime($user['restricted_until']) <= time();

                                $status_class = $account_status === 'active'
                                    ? 'active'
                                    : ($account_status === 'banned' ? 'banned' : 'suspended');
                                ?>

                                <tr>

                                    <!-- User -->
                                    <td>
                                        <div class="admin-user-identity">

                                            <div class="admin-user-avatar">
                                                <?= htmlspecialchars(
                                                    strtoupper(substr(trim($user['full_name'] ?? 'U'), 0, 1)),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </div>

                                            <div class="admin-user-details">
                                                <strong>
                                                    <?= htmlspecialchars(
                                                        $user['full_name'] ?? 'Unnamed User',
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>
                                                </strong>

                                                <span>
                                                    <?= htmlspecialchars($user_id, ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            </div>

                                        </div>
                                    </td>


                                    <!-- Role -->
                                    <td>
                                        <span class="admin-user-role <?= htmlspecialchars($user_role, ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(ucfirst($user_role), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>


                                    <!-- Registration date -->
                                    <td>
                                        <span class="admin-user-date">
                                            <?= !empty($user['created_at'])
                                                ? htmlspecialchars(date('M d, Y', strtotime($user['created_at'])), ENT_QUOTES, 'UTF-8')
                                                : '—' ?>
                                        </span>
                                    </td>


                                    <!-- Account status -->
                                    <td>

                                        <span class="admin-user-status <?= $status_class ?>">
                                            <span class="admin-user-status-dot"></span>

                                            <?php if ($is_expired_suspension): ?>
                                                Suspension Expired
                                            <?php else: ?>
                                                <?= htmlspecialchars(ucfirst($account_status), ENT_QUOTES, 'UTF-8') ?>
                                            <?php endif; ?>

                                        </span>


                                        <?php if ($account_status === 'suspended' && !empty($user['restricted_until'])): ?>

                                            <span class="admin-user-restriction-date">
                                                Until:
                                                <?= htmlspecialchars(
                                                    date('M d, Y h:i A', strtotime($user['restricted_until'])),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </span>

                                        <?php endif; ?>


                                        <?php if (!empty($user['restriction_reason'])): ?>

                                            <details class="admin-user-reason">
                                                <summary>View reason</summary>
                                                <p>
                                                    <?= htmlspecialchars(
                                                        $user['restriction_reason'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>
                                                </p>
                                            </details>

                                        <?php endif; ?>

                                    </td>


                                    <!-- Account actions -->
                                    <td class="admin-user-actions">

                                        <?php if ($is_self || $is_admin): ?>

                                            <span class="admin-user-protected">
                                                <i class="bi bi-shield-lock"></i>
                                                Protected account
                                            </span>


                                        <?php elseif ($account_status === 'active'): ?>

                                            <details class="admin-user-manage">

                                                <summary class="admin-user-manage-btn">
                                                    <i class="bi bi-shield"></i>
                                                    Manage Account
                                                    <i class="bi bi-chevron-down"></i>
                                                </summary>


                                                <div class="admin-user-manage-content">

                                                    <!-- Suspend -->
                                                    <form method="POST" action="" class="admin-user-action-form">

                                                        <input
                                                            type="hidden"
                                                            name="csrf_token"
                                                            value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                                                        <input
                                                            type="hidden"
                                                            name="user_id"
                                                            value="<?= htmlspecialchars($user_id, ENT_QUOTES, 'UTF-8') ?>">

                                                        <input type="hidden" name="action" value="suspend">

                                                        <strong>
                                                            <i class="bi bi-clock-history"></i>
                                                            Temporary Suspension
                                                        </strong>

                                                        <label>Suspension until</label>

                                                        <input
                                                            type="datetime-local"
                                                            name="restricted_until"
                                                            value="<?= htmlspecialchars($default_suspend_until, ENT_QUOTES, 'UTF-8') ?>"
                                                            required>

                                                        <label>Reason</label>

                                                        <input
                                                            type="text"
                                                            name="reason"
                                                            placeholder="Reason for suspension"
                                                            maxlength="500"
                                                            required>

                                                        <button type="submit" class="admin-user-action-btn suspend">
                                                            Suspend Account
                                                        </button>

                                                    </form>


                                                    <div class="admin-user-action-divider"></div>


                                                    <!-- Permanent ban -->
                                                    <form
                                                        method="POST"
                                                        action=""
                                                        class="admin-user-action-form"
                                                        onsubmit="return confirm('Are you sure you want to permanently ban this account?');">

                                                        <input
                                                            type="hidden"
                                                            name="csrf_token"
                                                            value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                                                        <input
                                                            type="hidden"
                                                            name="user_id"
                                                            value="<?= htmlspecialchars($user_id, ENT_QUOTES, 'UTF-8') ?>">

                                                        <input type="hidden" name="action" value="ban">

                                                        <strong>
                                                            <i class="bi bi-ban"></i>
                                                            Permanent Ban
                                                        </strong>

                                                        <label>Reason</label>

                                                        <input
                                                            type="text"
                                                            name="reason"
                                                            placeholder="Reason for permanent ban"
                                                            maxlength="500"
                                                            required>

                                                        <button type="submit" class="admin-user-action-btn ban">
                                                            Permanently Ban
                                                        </button>

                                                    </form>

                                                </div>

                                            </details>


                                        <?php elseif (in_array($account_status, ['suspended', 'banned'], true)): ?>

                                            <form
                                                method="POST"
                                                action=""
                                                class="admin-user-restore-form"
                                                onsubmit="return confirm('Restore this account and allow the user to access WorkPulse again?');">

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                                                <input
                                                    type="hidden"
                                                    name="user_id"
                                                    value="<?= htmlspecialchars($user_id, ENT_QUOTES, 'UTF-8') ?>">

                                                <input type="hidden" name="action" value="restore">

                                                <button type="submit" class="admin-user-action-btn restore">
                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                    Restore Account
                                                </button>

                                            </form>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="admin-users-empty">

                    <div class="admin-users-empty-icon">
                        <i class="bi bi-people"></i>
                    </div>

                    <h4>No accounts found</h4>

                    <p>
                        Try changing your search or role filter.
                    </p>

                    <?php if ($search !== '' || $role !== 'all'): ?>

                        <a href="users.php" class="wp-btn wp-btn-secondary">
                            Clear Filters
                        </a>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </div>

    </section>

</div>

<?php require_once '../../includes/footer.php'; ?>