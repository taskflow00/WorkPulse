<?php

$required_role = 'worker';
$page_title = 'My Profile';
$active_page = 'profile';

require_once '../../includes/auth.php';

$user_id = $_SESSION['user']['id'];
$token = $_SESSION['access_token'];

$message = '';
$message_type = 'success';


// ==========================================
// REMOVE PROFILE PICTURE
// ==========================================

if (isset($_POST['remove_avatar'])) {

    // Only allow removal of this worker's own avatar
    $avatar_path = $_POST['current_avatar_path'] ?? '';
    $expected_path = $user_id . '/profile-avatar';

    if ($avatar_path === $expected_path) {

        // Delete the image from Supabase Storage
        $storage_url = SUPABASE_URL .
            '/storage/v1/object/avatars/' . $avatar_path;

        $options = [
            'http' => [
                'method' => 'DELETE',
                'header' =>
                "apikey: " . SUPABASE_KEY . "\r\n" .
                    "Authorization: Bearer " . $token . "\r\n",
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $delete_response = file_get_contents($storage_url, false, $context);

        $status_line = $http_response_header[0] ?? '';
        preg_match('/\s(\d{3})\s/', $status_line, $matches);
        $status_code = (int)($matches[1] ?? 0);

        if (
            $delete_response !== false &&
            $status_code >= 200 &&
            $status_code < 300
        ) {

            // Remove the avatar path from the worker profile
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
                    'content' => json_encode([
                        'avatar_url' => null
                    ]),
                    'ignore_errors' => true
                ]
            ];

            $context = stream_context_create($options);
            $response = file_get_contents($url, false, $context);
            $result = json_decode($response ?: '', true);

            if (isset($result[0]['id'])) {
                $message = 'Profile picture removed successfully!';
                $message_type = 'success';
            } else {
                $message = 'Image deleted, but the profile could not be updated.';
                $message_type = 'danger';
            }
        } else {
            $message = 'Unable to remove your profile picture. Please try again.';
            $message_type = 'danger';
        }
    } else {
        $message = 'Invalid profile picture path.';
        $message_type = 'danger';
    }
}


// ==========================================
// UPDATE PROFILE
// ==========================================

if (isset($_POST['update_profile'])) {

    $full_name = trim($_POST['full_name'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $experience = trim($_POST['experience'] ?? '');
    $education = trim($_POST['education'] ?? '');
    $portfolio_url = trim($_POST['portfolio_url'] ?? '');
    $hourly_rate = trim($_POST['preferred_hourly_rate'] ?? '');

    $skills = array_unique(
        array_filter(
            array_map('trim', $_POST['skills'] ?? [])
        )
    );

    // Validate submitted information
    if ($full_name === '') {
        $message = 'Full name is required.';
    } elseif (
        $hourly_rate !== '' &&
        (!is_numeric($hourly_rate) || $hourly_rate < 0)
    ) {
        $message = 'Please enter a valid hourly rate.';
    } elseif (
        $portfolio_url !== '' &&
        !filter_var($portfolio_url, FILTER_VALIDATE_URL)
    ) {
        $message = 'Please enter a valid portfolio URL.';
    }


    // ==========================================
    // PROFILE PICTURE UPLOAD
    // ==========================================

    $avatar_path = null;

    if (
        $message === '' &&
        isset($_FILES['avatar']) &&
        $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE
    ) {

        $file = $_FILES['avatar'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message = 'There was a problem uploading your profile picture.';
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $message = 'Profile picture must not exceed 2 MB.';
        } else {

            // Check the actual image file type
            $mime_type = finfo_file(
                finfo_open(FILEINFO_MIME_TYPE),
                $file['tmp_name']
            );

            $allowed_types = [
                'image/jpeg',
                'image/png',
                'image/webp'
            ];

            if (!in_array($mime_type, $allowed_types, true)) {
                $message = 'Only JPG, PNG, and WebP images are allowed.';
            } else {

                // Each worker has their own avatar path
                $avatar_path = $user_id . '/profile-avatar';

                $storage_url = SUPABASE_URL .
                    '/storage/v1/object/avatars/' . $avatar_path;

                $file_content = file_get_contents($file['tmp_name']);

                $options = [
                    'http' => [
                        'method' => 'POST',
                        'header' =>
                        "apikey: " . SUPABASE_KEY . "\r\n" .
                            "Authorization: Bearer " . $token . "\r\n" .
                            "Content-Type: " . $mime_type . "\r\n" .
                            "x-upsert: true\r\n",
                        'content' => $file_content,
                        'ignore_errors' => true
                    ]
                ];

                $context = stream_context_create($options);
                $upload_response = file_get_contents(
                    $storage_url,
                    false,
                    $context
                );

                $status_line = $http_response_header[0] ?? '';
                preg_match('/\s(\d{3})\s/', $status_line, $matches);
                $status_code = (int)($matches[1] ?? 0);

                if (
                    $upload_response === false ||
                    $status_code < 200 ||
                    $status_code >= 300
                ) {

                    $upload_result = json_decode($upload_response ?: '', true);

                    $message = 'Profile picture upload failed. ' .
                        ($upload_result['message'] ?? 'Please try again.');

                    $avatar_path = null;
                }
            }
        }
    }


    // ==========================================
    // RESUME UPLOAD
    // ==========================================

    $resume_path = null;

    if (
        $message === '' &&
        isset($_FILES['resume']) &&
        $_FILES['resume']['error'] !== UPLOAD_ERR_NO_FILE
    ) {

        $file = $_FILES['resume'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message = 'There was a problem uploading your resume.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $message = 'Resume must not exceed 5 MB.';
        } elseif ($extension !== 'pdf') {
            $message = 'Only PDF resumes are allowed.';
        } elseif (
            finfo_file(
                finfo_open(FILEINFO_MIME_TYPE),
                $file['tmp_name']
            ) !== 'application/pdf'
        ) {
            $message = 'The selected file is not a valid PDF.';
        } else {

            // Each worker has their own private resume path
            $resume_path = $user_id . '/resume.pdf';

            $storage_url = SUPABASE_URL .
                '/storage/v1/object/resumes/' . $resume_path;

            $file_content = file_get_contents($file['tmp_name']);

            $options = [
                'http' => [
                    'method' => 'POST',
                    'header' =>
                    "apikey: " . SUPABASE_KEY . "\r\n" .
                        "Authorization: Bearer " . $token . "\r\n" .
                        "Content-Type: application/pdf\r\n" .
                        "x-upsert: true\r\n",
                    'content' => $file_content,
                    'ignore_errors' => true
                ]
            ];

            $context = stream_context_create($options);
            $upload_response = file_get_contents($storage_url, false, $context);

            $status_line = $http_response_header[0] ?? '';
            preg_match('/\s(\d{3})\s/', $status_line, $matches);
            $status_code = (int)($matches[1] ?? 0);

            if (
                $upload_response === false ||
                $status_code < 200 ||
                $status_code >= 300
            ) {

                $upload_result = json_decode($upload_response ?: '', true);

                $message = 'Resume upload failed. ' .
                    ($upload_result['message'] ?? 'Please try again.');
            }
        }
    }

    // ==========================================
    // SAVE PROFILE TO SUPABASE
    // ==========================================

    if ($message === '') {

        $data = [
            'full_name' => $full_name,
            'bio' => $bio,
            'skills' => array_values($skills),
            'experience' => $experience,
            'education' => $education,
            'portfolio_url' => $portfolio_url,
            'preferred_hourly_rate' =>
            $hourly_rate === '' ? null : $hourly_rate,

        ];

        // Save the new avatar path only when a picture was uploaded
        if ($avatar_path !== null) {
            $data['avatar_url'] = $avatar_path;
        }

        if ($resume_path !== null) {
            $data['resume_url'] = $resume_path;
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
            $message = 'Profile update failed. Please try again.';
            $message_type = 'danger';
        }
    }
}

// ==========================================
// GET CURRENT WORKER PROFILE
// ==========================================

$url = SUPABASE_URL .
    '/rest/v1/profiles?id=eq.' . urlencode($user_id) .
    '&select=*';

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
    die('Unable to retrieve profile.');
}

$profile = $profiles[0];

// Make the updated name available to the shared header
$user_name = $profile['full_name'] ?? 'Worker';

// ==========================================
// CREATE TEMPORARY RESUME PREVIEW URL
// ==========================================

$resume_preview_url = '';

if (
    !empty($profile['resume_url']) &&
    $profile['resume_url'] === $user_id . '/resume.pdf'
) {

    $signed_url_endpoint = SUPABASE_URL .
        '/storage/v1/object/sign/resumes/' .
        $profile['resume_url'];

    $options = [
        'http' => [
            'method' => 'POST',
            'header' =>
            "apikey: " . SUPABASE_KEY . "\r\n" .
                "Authorization: Bearer " . $token . "\r\n" .
                "Content-Type: application/json\r\n",
            'content' => json_encode([
                'expiresIn' => 300
            ]),
            'ignore_errors' => true
        ]
    ];

    $context = stream_context_create($options);
    $signed_response = file_get_contents(
        $signed_url_endpoint,
        false,
        $context
    );

    $signed_result = json_decode($signed_response ?: '', true);

    if (!empty($signed_result['signedURL'])) {

        $signed_path = $signed_result['signedURL'];

        if (strpos($signed_path, 'http') === 0) {
            $resume_preview_url = $signed_path;
        } else {
            $resume_preview_url = SUPABASE_URL .
                '/storage/v1' . $signed_path;
        }
    }
}

// ==========================================
// PROFILE DISPLAY VALUES
// ==========================================

$initial = strtoupper(substr($user_name, 0, 1));

$skills = $profile['skills'] ?? [];

$hourly_rate = $profile['preferred_hourly_rate'] ?? null;

// ==========================================
// PROFILE PICTURE URL
// ==========================================

$avatar_url = '';

if (
    !empty($profile['avatar_url']) &&
    strpos($profile['avatar_url'], $user_id . '/') === 0
) {

    $avatar_parts = explode('/', $profile['avatar_url']);

    $avatar_url = SUPABASE_URL .
        '/storage/v1/object/public/avatars/' .
        implode('/', array_map('rawurlencode', $avatar_parts));
}

require_once '../../includes/header.php';

?>

<!-- ==========================================
     PROFILE PAGE
========================================== -->

<div class="wp-profile-page">

    <div class="wp-dashboard-welcome">
        <div>
            <span class="wp-dashboard-eyebrow">YOUR PROFESSIONAL IDENTITY</span>
            <h1 class="wp-page-title">My Profile</h1>
            <p class="wp-page-description">
                Present your skills, experience, and professional background.
            </p>
        </div>

        <button
            type="button"
            class="wp-btn wp-btn-primary"
            id="edit-profile-button"
            onclick="toggleProfileEdit(true)">

            <i class="bi bi-pencil-square"></i>
            Edit Profile

        </button>
    </div>

    <?php if ($message !== ''): ?>

        <div class="wp-alert wp-alert-<?= $message_type ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>

    <?php endif; ?>

    <!-- ==========================================
         PROFILE VIEW MODE
    ========================================== -->

    <div id="profile-view">

        <!-- Profile header -->
        <section class="wp-profile-hero">

            <div class="wp-profile-avatar">

                <?php if ($avatar_url !== ''): ?>

                    <img
                        src="<?= htmlspecialchars($avatar_url, ENT_QUOTES, 'UTF-8') ?>"
                        alt="Profile picture">

                <?php else: ?>

                    <?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?>

                <?php endif; ?>

            </div>

            <div class="wp-profile-hero-info">

                <span class="wp-profile-eyebrow">
                    WORKPULSE PROFESSIONAL PROFILE
                </span>

                <h2>
                    <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>
                </h2>

                <p>
                    <?= !empty($profile['bio'])
                        ? htmlspecialchars(substr($profile['bio'], 0, 110), ENT_QUOTES, 'UTF-8')
                        : 'Add a professional introduction to tell employers about yourself.' ?>
                </p>

                <div class="wp-profile-hero-meta">

                    <span>
                        <i class="bi bi-person-check"></i>
                        Worker
                    </span>

                    <?php if ($hourly_rate !== null && $hourly_rate !== ''): ?>
                        <span>
                            <i class="bi bi-cash"></i>
                            $<?= number_format((float)$hourly_rate, 2) ?> / hr
                        </span>
                    <?php endif; ?>

                </div>

            </div>

        </section>

        <div class="wp-profile-content-grid">

            <div class="wp-profile-main-column">

                <!-- About -->
                <section class="wp-profile-card">

                    <div class="wp-profile-card-heading">
                        <div>
                            <span class="wp-profile-section-label">INTRODUCTION</span>
                            <h3>About Me</h3>
                        </div>
                        <i class="bi bi-person-lines-fill"></i>
                    </div>

                    <?php if (!empty($profile['bio'])): ?>

                        <p class="wp-profile-description">
                            <?= nl2br(htmlspecialchars($profile['bio'], ENT_QUOTES, 'UTF-8')) ?>
                        </p>

                    <?php else: ?>

                        <p class="wp-profile-empty">
                            No introduction added yet.
                        </p>

                    <?php endif; ?>

                </section>

                <!-- Skills -->
                <section class="wp-profile-card">

                    <div class="wp-profile-card-heading">
                        <div>
                            <span class="wp-profile-section-label">WHAT I CAN DO</span>
                            <h3>Skills & Expertise</h3>
                        </div>
                        <i class="bi bi-stars"></i>
                    </div>

                    <?php if (!empty($skills)): ?>

                        <div class="wp-profile-skills">

                            <?php foreach ($skills as $skill): ?>

                                <span class="wp-profile-skill">
                                    <?= htmlspecialchars($skill, ENT_QUOTES, 'UTF-8') ?>
                                </span>

                            <?php endforeach; ?>

                        </div>

                    <?php else: ?>

                        <p class="wp-profile-empty">
                            Add your skills to help employers understand your expertise.
                        </p>

                    <?php endif; ?>

                </section>

                <!-- Experience -->
                <section class="wp-profile-card">

                    <div class="wp-profile-card-heading">
                        <div>
                            <span class="wp-profile-section-label">BACKGROUND</span>
                            <h3>Experience</h3>
                        </div>
                        <i class="bi bi-briefcase"></i>
                    </div>

                    <?php if (!empty($profile['experience'])): ?>

                        <p class="wp-profile-description">
                            <?= nl2br(htmlspecialchars($profile['experience'], ENT_QUOTES, 'UTF-8')) ?>
                        </p>

                    <?php else: ?>

                        <p class="wp-profile-empty">
                            No work experience added yet.
                        </p>

                    <?php endif; ?>

                    <div class="wp-profile-divider"></div>

                    <div class="wp-profile-card-heading">
                        <div>
                            <span class="wp-profile-section-label">LEARNING</span>
                            <h3>Education</h3>
                        </div>
                        <i class="bi bi-mortarboard"></i>
                    </div>

                    <?php if (!empty($profile['education'])): ?>

                        <p class="wp-profile-description">
                            <?= nl2br(htmlspecialchars($profile['education'], ENT_QUOTES, 'UTF-8')) ?>
                        </p>

                    <?php else: ?>

                        <p class="wp-profile-empty">
                            No education details added yet.
                        </p>

                    <?php endif; ?>

                </section>

            </div>

            <!-- Right column -->
            <aside class="wp-profile-side-column">

                <!-- Resume -->
                <section class="wp-profile-card">

                    <div class="wp-profile-card-heading">
                        <div>
                            <span class="wp-profile-section-label">DOCUMENTS</span>
                            <h3>My Resume</h3>
                        </div>
                        <i class="bi bi-file-earmark-pdf"></i>
                    </div>

                    <?php if (!empty($profile['resume_url'])): ?>

                        <div class="wp-resume-file">
                            <div class="wp-resume-icon">
                                <i class="bi bi-file-earmark-pdf"></i>
                            </div>

                            <div>
                                <strong>Resume.pdf</strong>
                                <small>PDF document</small>
                            </div>
                        </div>

                        <?php if ($resume_preview_url !== ''): ?>

                            <a
                                href="<?= htmlspecialchars($resume_preview_url, ENT_QUOTES, 'UTF-8') ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="wp-btn wp-btn-outline wp-profile-full-button">

                                <i class="bi bi-eye"></i>
                                Preview Resume

                            </a>

                            <p class="wp-profile-note">
                                Your private resume preview link expires after 5 minutes.
                            </p>

                        <?php else: ?>

                            <p class="wp-profile-empty">
                                Unable to generate a resume preview. Please try again later.
                            </p>

                        <?php endif; ?>

                    <?php else: ?>

                        <div class="wp-profile-empty-document">
                            <i class="bi bi-file-earmark-plus"></i>
                            <p>No resume uploaded yet.</p>
                        </div>

                    <?php endif; ?>

                </section>

                <!-- Portfolio -->
                <section class="wp-profile-card">

                    <div class="wp-profile-card-heading">
                        <div>
                            <span class="wp-profile-section-label">MY WORK</span>
                            <h3>Portfolio</h3>
                        </div>
                        <i class="bi bi-globe"></i>
                    </div>

                    <?php if (!empty($profile['portfolio_url'])): ?>

                        <a
                            href="<?= htmlspecialchars($profile['portfolio_url'], ENT_QUOTES, 'UTF-8') ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="wp-profile-portfolio-link">

                            <span>
                                <i class="bi bi-box-arrow-up-right"></i>
                                Visit My Portfolio
                            </span>

                            <i class="bi bi-arrow-up-right"></i>

                        </a>

                    <?php else: ?>

                        <p class="wp-profile-empty">
                            Add a portfolio link to showcase your work.
                        </p>

                    <?php endif; ?>

                </section>

                <!-- Rate -->
                <section class="wp-profile-rate-card">

                    <span>EXPECTED HOURLY RATE</span>

                    <?php if ($hourly_rate !== null && $hourly_rate !== ''): ?>

                        <h2>
                            $<?= number_format((float)$hourly_rate, 2) ?>
                            <small>/ hour</small>
                        </h2>

                    <?php else: ?>

                        <h2>Not set</h2>

                    <?php endif; ?>

                    <p>Your preferred hourly compensation.</p>

                </section>

            </aside>

        </div>

    </div>

    <!-- ==========================================
         PROFILE EDIT MODE
    ========================================== -->

    <div id="profile-edit" style="display: none;">

        <div class="wp-profile-edit-heading">

            <div>
                <h3>Edit Your Profile</h3>
                <p>Update the information employers can use to learn about you.</p>
            </div>

            <button
                type="button"
                class="wp-btn wp-btn-outline"
                onclick="toggleProfileEdit(false)">

                <i class="bi bi-x-lg"></i>
                Cancel

            </button>

        </div>

        <form method="POST" enctype="multipart/form-data">


            <!-- Profile picture -->
            <section class="wp-profile-card">

                <div class="wp-profile-card-heading">
                    <div>
                        <span class="wp-profile-section-label">PROFILE PHOTO</span>
                        <h3>Profile Picture</h3>
                    </div>
                    <i class="bi bi-camera"></i>
                </div>

                <div class="wp-avatar-editor">

                    <div class="wp-avatar-preview" id="avatar-preview">

                        <?php if ($avatar_url !== ''): ?>

                            <img
                                src="<?= htmlspecialchars($avatar_url, ENT_QUOTES, 'UTF-8') ?>"
                                alt="Current profile picture"
                                id="avatar-preview-image">

                        <?php else: ?>

                            <span id="avatar-preview-initial">
                                <?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?>
                            </span>

                        <?php endif; ?>

                    </div>

                    <div class="wp-avatar-editor-details">

                        <strong>Upload a professional photo</strong>

                        <p>
                            Choose a clear picture so employers can recognize you.
                        </p>

                        <input
                            type="file"
                            name="avatar"
                            id="avatar-input"
                            class="wp-input"
                            accept="image/jpeg,image/png,image/webp">

                        <small class="wp-form-hint">
                            JPG, PNG, or WebP. Maximum file size: 2 MB.
                        </small>

                        <?php if ($avatar_url !== ''): ?>

                            <input
                                type="hidden"
                                name="current_avatar_path"
                                value="<?= htmlspecialchars($profile['avatar_url'], ENT_QUOTES, 'UTF-8') ?>">

                            <button
                                type="submit"
                                name="remove_avatar"
                                value="1"
                                class="wp-btn wp-btn-outline wp-avatar-remove"
                                formnovalidate
                                onclick="return confirm('Are you sure you want to remove your profile picture?');">

                                <i class="bi bi-trash"></i>
                                Remove Picture

                            </button>

                        <?php endif; ?>

                    </div>

                </div>

            </section>


            <!-- Personal information -->
            <section class="wp-profile-card">

                <div class="wp-profile-card-heading">
                    <div>
                        <span class="wp-profile-section-label">BASIC INFORMATION</span>
                        <h3>Personal Information</h3>
                    </div>
                    <i class="bi bi-person"></i>
                </div>

                <div class="wp-form-group">
                    <label class="wp-label">Full Name</label>
                    <input
                        type="text"
                        name="full_name"
                        class="wp-input"
                        value="<?= htmlspecialchars($profile['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        required>
                </div>

                <div class="wp-form-group">
                    <label class="wp-label">About Me</label>
                    <textarea
                        name="bio"
                        class="wp-textarea"
                        placeholder="Introduce yourself, your strengths, and what you can offer..."><?= htmlspecialchars($profile['bio'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

            </section>

            <!-- Skills -->
            <section class="wp-profile-card">

                <div class="wp-profile-card-heading">
                    <div>
                        <span class="wp-profile-section-label">EXPERTISE</span>
                        <h3>Skills & Expertise</h3>
                    </div>
                    <i class="bi bi-stars"></i>
                </div>

                <div class="wp-form-group">

                    <label class="wp-label">Choose Your Skills</label>

                    <div class="wp-skills-container">

                        <input
                            type="text"
                            id="skill-search"
                            class="wp-input"
                            placeholder="Search skills..."
                            autocomplete="off">

                        <div id="skill-suggestions" class="wp-skill-suggestions"></div>

                        <div
                            id="selected-skills"
                            class="wp-selected-skills"
                            data-input-name="skills[]"
                            data-selected-skills="<?= htmlspecialchars(json_encode($skills), ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                    </div>

                    <small class="wp-form-hint">
                        Select the skills that best represent your abilities.
                    </small>

                </div>

            </section>

            <!-- Experience and education -->
            <section class="wp-profile-card">

                <div class="wp-profile-card-heading">
                    <div>
                        <span class="wp-profile-section-label">BACKGROUND</span>
                        <h3>Experience & Education</h3>
                    </div>
                    <i class="bi bi-mortarboard"></i>
                </div>

                <div class="wp-form-group">
                    <label class="wp-label">Work Experience</label>
                    <textarea
                        name="experience"
                        class="wp-textarea"
                        placeholder="Describe your previous work experience..."><?= htmlspecialchars($profile['experience'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

                <div class="wp-form-group">
                    <label class="wp-label">Education</label>
                    <textarea
                        name="education"
                        class="wp-textarea"
                        placeholder="Add your educational background..."><?= htmlspecialchars($profile['education'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

            </section>

            <!-- Resume -->
            <section class="wp-profile-card">

                <div class="wp-profile-card-heading">
                    <div>
                        <span class="wp-profile-section-label">DOCUMENTS</span>
                        <h3>Resume</h3>
                    </div>
                    <i class="bi bi-file-earmark-pdf"></i>
                </div>

                <?php if (!empty($profile['resume_url'])): ?>

                    <div class="wp-resume-file">
                        <div class="wp-resume-icon">
                            <i class="bi bi-file-earmark-pdf"></i>
                        </div>

                        <div>
                            <strong>Current Resume</strong>
                            <small>Resume.pdf</small>
                        </div>
                    </div>

                <?php endif; ?>

                <div class="wp-form-group">

                    <label class="wp-label">
                        <?= !empty($profile['resume_url']) ? 'Replace Resume' : 'Upload Resume' ?>
                    </label>

                    <input
                        type="file"
                        name="resume"
                        class="wp-input"
                        accept=".pdf,application/pdf">

                    <small class="wp-form-hint">
                        PDF only. Maximum file size: 5 MB.
                        Your existing resume stays saved unless you upload a replacement.
                    </small>

                </div>

            </section>

            <!-- Professional preferences -->
            <section class="wp-profile-card">

                <div class="wp-profile-card-heading">
                    <div>
                        <span class="wp-profile-section-label">PROFESSIONAL DETAILS</span>
                        <h3>Portfolio & Rate</h3>
                    </div>
                    <i class="bi bi-briefcase"></i>
                </div>

                <div class="wp-form-group">
                    <label class="wp-label">Portfolio URL</label>
                    <input
                        type="url"
                        name="portfolio_url"
                        class="wp-input"
                        placeholder="https://yourportfolio.com"
                        value="<?= htmlspecialchars($profile['portfolio_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="wp-form-group">
                    <label class="wp-label">Preferred Hourly Rate (USD)</label>

                    <div class="wp-rate-input">
                        <span>$</span>
                        <input
                            type="number"
                            name="preferred_hourly_rate"
                            class="wp-input"
                            min="0"
                            step="0.01"
                            placeholder="0.00"
                            value="<?= htmlspecialchars((string)($hourly_rate ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <small class="wp-form-hint">
                        Enter your preferred rate in US dollars per hour.
                    </small>
                </div>

            </section>

            <div class="wp-profile-save-area">

                <button
                    type="button"
                    class="wp-btn wp-btn-outline"
                    onclick="toggleProfileEdit(false)">

                    Cancel

                </button>

                <button
                    type="submit"
                    name="update_profile"
                    class="wp-btn wp-btn-success">

                    <i class="bi bi-check-lg"></i>
                    Save Changes

                </button>

            </div>

        </form>

    </div>

</div>

<script src="../../assets/js/profile.js"></script>

<?php require_once '../../includes/footer.php'; ?>