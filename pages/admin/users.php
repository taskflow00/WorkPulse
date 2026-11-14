<?php
session_start();

require_once '../../config/supabase.php';

// Check if the user is logged in.
if (!isset($_SESSION['access_token']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

// Only admins can access this page.
if ($_SESSION['role'] !== 'admin') {
    if ($_SESSION['role'] === 'worker') {
        header('Location: ../worker/dashboard.php');
    } elseif ($_SESSION['role'] === 'employer') {
        header('Location: ../employer/dashboard.php');
    } else {
        header('Location: ../../login.php');
    }

    exit;
}

date_default_timezone_set('Asia/Manila');

$token = $_SESSION['access_token'];


// Get the search and role filters.
$search = trim($_GET['search'] ?? '');
$role = $_GET['role'] ?? 'all';


// Only allow valid account roles.
if (!in_array($role, ['all', 'worker', 'employer', 'admin'])) {
    $role = 'all';
}


// Build the Supabase query.
$query = 'profiles?select=id,full_name,role,created_at&order=created_at.desc&limit=100';

// Filter by role.
if ($role !== 'all') {
    $query .= '&role=eq.' . urlencode($role);
}

// Search by full name.
if ($search !== '') {
    $query .= '&full_name=ilike.' . urlencode('*' . $search . '*');
}


// Request user profiles from Supabase.
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

$context = stream_context_create($options);

$response = file_get_contents($url, false, $context);

$users = [];
$message = '';

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

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WorkPulse | User Management</title>
</head>

<body>

    <h1>Admin - User Management</h1>

    <p>
        <a href="dashboard.php">Back to Dashboard</a> |
        <a href="../../logout.php">Logout</a>
    </p>

    <hr>

    <h2>Registered Accounts</h2>

    <p>Search and filter registered WorkPulse users.</p>

    <form method="GET" action="">

        <div>
            <label>Search by Name</label>

            <input
                type="text"
                name="search"
                value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                placeholder="Enter a user's name">
        </div>

        <div>
            <label>Account Role</label>

            <select name="role">

                <option value="all" <?php echo $role === 'all' ? 'selected' : ''; ?>>
                    All Accounts
                </option>

                <option value="worker" <?php echo $role === 'worker' ? 'selected' : ''; ?>>
                    Workers
                </option>

                <option value="employer" <?php echo $role === 'employer' ? 'selected' : ''; ?>>
                    Employers
                </option>

                <option value="admin" <?php echo $role === 'admin' ? 'selected' : ''; ?>>
                    Administrators
                </option>

            </select>
        </div>

        <button type="submit">Apply Filters</button>

        <a href="users.php">Clear Filters</a>

    </form>

    <hr>

    <p>
        Showing <?php echo count($users); ?> account(s).
        Maximum of 100 accounts per request.
    </p>

    <?php if ($message !== ''): ?>

        <p><?php echo htmlspecialchars($message); ?></p>

    <?php elseif (count($users) > 0): ?>

        <table border="1" cellpadding="8">

            <tr>
                <th>Full Name</th>
                <th>Role</th>
                <th>Registration Date</th>
                <th>User ID</th>
            </tr>

            <?php foreach ($users as $user): ?>

                <tr>

                    <td>
                        <?php echo htmlspecialchars($user['full_name']); ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars(ucfirst($user['role'])); ?>
                    </td>

                    <td>
                        <?php
                        echo date(
                            'M d, Y h:i A',
                            strtotime($user['created_at'])
                        );
                        ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars($user['id']); ?>
                    </td>

                </tr>

            <?php endforeach; ?>

        </table>

    <?php else: ?>

        <p>No registered accounts found.</p>

    <?php endif; ?>

</body>

</html>