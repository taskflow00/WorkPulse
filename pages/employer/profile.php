<?php

$required_role = 'employer';
$page_title = 'My Profile';
$active_page = 'profile';
$page_styles = ['/assets/css/employer-profile.css'];

require_once '../../includes/auth.php';

$user_id = $_SESSION['user']['id'];
$token = $_SESSION['access_token'];

$message = '';
$message_type = 'success';

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];


// ==========================================
// UPDATE PROFILE
// ==========================================

if (isset($_POST['update_profile'])) {

    $submitted_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf_token, $submitted_token)) {

        $message = 'Invalid request. Please try again.';
        $message_type = 'danger';
    } else {

        $full_name = trim($_POST['full_name'] ?? '');
        $bio = trim($_POST['bio'] ?? '');

        if ($full_name === '') {

            $message = 'Full name is required.';
            $message_type = 'danger';
        } elseif (strlen($full_name) > 100) {

            $message = 'Full name must not exceed 100 characters.';
            $message_type = 'danger';
        } else {

            $avatar_path = null;

            // ==========================================
            // UPLOAD PROFILE PICTURE
            // ==========================================

            if (
                isset($_FILES['avatar']) &&
                $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE
            ) {

                $file = $_FILES['avatar'];

                if ($file['error'] !== UPLOAD_ERR_OK) {

                    $message = 'There was a problem uploading your picture.';
                    $message_type = 'danger';
                } elseif ($file['size'] > 2 * 1024 * 1024) {

                    $message = 'Profile picture must not exceed 2 MB.';
                    $message_type = 'danger';
                } else {

                    $file_info = finfo_open(FILEINFO_MIME_TYPE);
                    $mime_type = finfo_file($file_info, $file['tmp_name']);
                    finfo_close($file_info);

                    $allowed_types = [
                        'image/jpeg',
                        'image/png',
                        'image/webp'
                    ];

                    if (!in_array($mime_type, $allowed_types, true)) {

                        $message = 'Only JPG, PNG, and WebP images are allowed.';
                        $message_type = 'danger';
                    } else {

                        // Each employer has their own avatar path
                        $avatar_path = $user_id . '/profile-avatar';

                        $storage_url = SUPABASE_URL .
                            '/storage/v1/object/avatars/' . $avatar_path;

                        $options = [
                            'http' => [
                                'method' => 'POST',
                                'header' =>
                                "apikey: " . SUPABASE_KEY . "\r\n" .
                                    "Authorization: Bearer " . $token . "\r\n" .
                                    "Content-Type: " . $mime_type . "\r\n" .
                                    "x-upsert: true\r\n",
                                'content' => file_get_contents($file['tmp_name']),
                                'ignore_errors' => true
                            ]
                        ];

                        $context = stream_context_create($options);
                        $upload_response = file_get_contents(
                            $storage_url,
                            false,
                            $context
                        );

                        $response_headers = http_get_last_response_headers();
                        $status_code = 0;

                        if (
                            is_array($response_headers) &&
                            isset($response_headers[0])
                        ) {
                            preg_match('/\s(\d{3})\s/', $response_headers[0], $matches);
                            $status_code = (int)($matches[1] ?? 0);
                        }

                        if (
                            $upload_response === false ||
                            $status_code < 200 ||
                            $status_code >= 300
                        ) {
                            $message = 'Profile picture upload failed. Please try again.';
                            $message_type = 'danger';
                            $avatar_path = null;
                        }
                    }
                }
            }

            // ==========================================
            // SAVE PROFILE INFORMATION
            // ==========================================

            if ($message === '') {

                $data = [
                    'full_name' => $full_name,
                    'bio' => $bio
                ];

                // Update avatar only when a new picture was uploaded
                if ($avatar_path !== null) {
                    $data['avatar_url'] = $avatar_path;
                }

                $url = SUPABASE_URL .
                    '/rest/v1/profiles?id=eq.' . urlencode($user_id);

                $options = [
                    'http' => [
                        'method' => 'PATCH',
                        'header' =>
                        "Content-Type: application/json\r\n" .
                            "apikey: " . SUPABASE_KEY . "\r\n" .
                            "Authorization: Bearer " . $token . "\r\n" .
                            "Prefer: return=representation\r\n",
                        'content' => json_encode($data),
                        'ignore_errors' => true
                    ]
                ];

                $context = stream_context_create($options);
                $response = file_get_contents($url, false, $context);

                $result = json_decode($response ?: '', true);

                if (isset($result[0]['id'])) {

                    $message = 'Your profile has been updated successfully!';
                    $message_type = 'success';
                } else {

                    $message = 'Unable to update your profile. Please try again.';
                    $message_type = 'danger';
                }
            }
        }
    }
}


// ==========================================
// GET CURRENT PROFILE
// ==========================================

$url = SUPABASE_URL .
    '/rest/v1/profiles?select=id,full_name,bio,avatar_url,role' .
    '&id=eq.' . urlencode($user_id);

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

$profiles = json_decode($response ?: '', true);

if (!isset($profiles[0])) {
    die('Unable to retrieve your profile.');
}

$profile_data = $profiles[0];

// Make the updated profile available to the shared header
$user_name = $profile_data['full_name'] ?? 'Employer';
$profile = [$profile_data];


// ==========================================
// PROFILE PICTURE URL
// ==========================================

$avatar_url = '';

if (
    !empty($profile_data['avatar_url']) &&
    $profile_data['avatar_url'] === $user_id . '/profile-avatar'
) {
    $avatar_url = SUPABASE_URL .
        '/storage/v1/object/public/avatars/' .
        $user_id . '/profile-avatar';
}

$user_email = $_SESSION['user']['email'] ?? '';
$user_initial = strtoupper(substr(trim($user_name), 0, 1));

require_once '../../includes/header.php';

?>

<div class="employer-profile-page">

    <div class="wp-dashboard-welcome">
        <div>
            <span class="wp-dashboard-eyebrow">ACCOUNT SETTINGS</span>
            <h1 class="wp-page-title">My Profile</h1>
            <p class="wp-page-description">
                Manage your personal information and employer profile.
            </p>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="wp-alert wp-alert-<?= htmlspecialchars($message_type) ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>


    <!-- PROFILE HEADER -->

    <section class="employer-profile-header">

        <div class="employer-profile-avatar">

            <?php if ($avatar_url !== ''): ?>

                <img
                    src="<?= htmlspecialchars($avatar_url, ENT_QUOTES, 'UTF-8') ?>"
                    alt="Profile picture">

            <?php else: ?>

                <?= htmlspecialchars($user_initial, ENT_QUOTES, 'UTF-8') ?>

            <?php endif; ?>

        </div>

        <div class="employer-profile-header-info">
            <span>EMPLOYER ACCOUNT</span>

            <h2>
                <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>
            </h2>

            <p>
                <?= htmlspecialchars($user_email, ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>

    </section>


    <!-- PROFILE INFORMATION -->

    <section class="employer-profile-card">

        <div class="employer-profile-card-heading">
            <div>
                <h3>Personal Information</h3>
                <p>Update the information associated with your account.</p>
            </div>

            <i class="bi bi-person-badge"></i>
        </div>

        <form method="POST" enctype="multipart/form-data">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrf_token) ?>">

            <div class="employer-profile-form-grid">

                <div class="employer-profile-field">
                    <label for="full_name">Full Name</label>

                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        maxlength="100"
                        value="<?= htmlspecialchars($profile_data['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        required>
                </div>

                <div class="employer-profile-field">
                    <label>Email Address</label>

                    <input
                        type="email"
                        value="<?= htmlspecialchars($user_email, ENT_QUOTES, 'UTF-8') ?>"
                        disabled>

                    <small>Email is managed by your account authentication.</small>
                </div>

                <div class="employer-profile-field full-width">
                    <label for="bio">About You / Your Business</label>

                    <textarea
                        id="bio"
                        name="bio"
                        rows="5"
                        placeholder="Tell workers about yourself, your business, or the kind of work you offer..."><?= htmlspecialchars($profile_data['bio'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

                <div class="employer-profile-field full-width">
                    <label for="avatar">Profile Picture</label>

                    <input
                        type="file"
                        id="avatar"
                        name="avatar"
                        accept="image/jpeg,image/png,image/webp">

                    <small>JPG, PNG, or WebP. Maximum file size: 2 MB.</small>
                </div>

            </div>

            <div class="employer-profile-actions">
                <button type="submit" name="update_profile" class="wp-btn wp-btn-primary">
                    <i class="bi bi-check-lg"></i>
                    Save Changes
                </button>
            </div>

        </form>

    </section>

</div>

<?php require_once '../../includes/footer.php'; ?>