<?php
session_start();

require_once '../../config/supabase.php';

// Only logged-in workers can access this page
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'worker') {
    header('Location: ../../login.php');
    exit;
}

$user_id = $_SESSION['user']['id'];
$message = '';


// Handle profile update
if (isset($_POST['update_profile'])) {

    // Get submitted information
    $full_name = trim($_POST['full_name'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $experience = trim($_POST['experience'] ?? '');
    $education = trim($_POST['education'] ?? '');
    $portfolio_url = trim($_POST['portfolio_url'] ?? '');
    $hourly_rate = trim($_POST['preferred_hourly_rate'] ?? '');

    // Get selected skills
    $skills = array_unique(
        array_filter(
            array_map('trim', $_POST['skills'] ?? [])
        )
    );

    // Basic validation
    if ($full_name === '') {
        $message = 'Full name is required.';
    } elseif ($hourly_rate !== '' && (!is_numeric($hourly_rate) || $hourly_rate < 0)) {
        $message = 'Please enter a valid hourly rate.';
    } elseif ($portfolio_url !== '' && !filter_var($portfolio_url, FILTER_VALIDATE_URL)) {
        $message = 'Please enter a valid portfolio URL.';
    }

    // Handle resume upload if a new file was selected
    $resume_path = null;

    if ($message === '' && isset($_FILES['resume']) && $_FILES['resume']['error'] !== UPLOAD_ERR_NO_FILE) {

        $file = $_FILES['resume'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        // Validate the uploaded file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message = 'There was a problem uploading your resume.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $message = 'Resume must not exceed 5 MB.';
        } elseif ($extension !== 'pdf') {
            $message = 'Only PDF resumes are allowed.';
        } elseif (finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']) !== 'application/pdf') {
            $message = 'The selected file is not a valid PDF.';
        } else {

            // Create the worker's private Storage path
            $resume_path = $user_id . '/resume.pdf';

            $storage_url = SUPABASE_URL .
                '/storage/v1/object/resumes/' . $resume_path;

            $file_content = file_get_contents($file['tmp_name']);

            // Upload PDF to Supabase Storage
            $options = [
                'http' => [
                    'method' => 'POST',
                    'header' =>
                    "apikey: " . SUPABASE_KEY . "\r\n" .
                        "Authorization: Bearer " . $_SESSION['access_token'] . "\r\n" .
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

            if ($upload_response === false || $status_code < 200 || $status_code >= 300) {

                $upload_result = json_decode($upload_response ?: '', true);

                $message = 'Resume upload failed. ' .
                    ($upload_result['message'] ?? $upload_result['error'] ?? 'Please try again.');
            }
        }
    }

    // Update profile if everything is valid
    if ($message === '') {

        $data = [
            'full_name' => $full_name,
            'bio' => $bio,
            'skills' => array_values($skills),
            'experience' => $experience,
            'education' => $education,
            'portfolio_url' => $portfolio_url,
            'preferred_hourly_rate' => $hourly_rate === '' ? null : $hourly_rate
        ];

        // Update resume path only when a new PDF was uploaded
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
                    "Authorization: Bearer " . $_SESSION['access_token'] . "\r\n" .
                    "Prefer: return=representation\r\n",
                'content' => json_encode($data),
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        $result = json_decode($response ?: '', true);

        $message = isset($result[0]['id'])
            ? 'Profile updated successfully!'
            : 'Profile update failed. ' . ($result['message'] ?? 'Please try again.');
    }
}


// Retrieve the current worker profile
$url = SUPABASE_URL .
    '/rest/v1/profiles?id=eq.' . urlencode($user_id) .
    '&select=*';

$options = [
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $_SESSION['access_token'] . "\r\n",
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
?>

<h1>My Worker Profile</h1>

<?php if ($message !== ''): ?>
    <p><?php echo htmlspecialchars($message); ?></p>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">

    <label>Full Name</label>
    <input
        type="text"
        name="full_name"
        value="<?php echo htmlspecialchars($profile['full_name'] ?? ''); ?>"
        required>

    <br><br>

    <label>About Me</label>
    <textarea name="bio"><?php echo htmlspecialchars($profile['bio'] ?? ''); ?></textarea>

    <br><br>

    <label>Skills</label>

    <div
        class="skills-container"
        data-selected-skills="<?php echo htmlspecialchars(
                                    json_encode($profile['skills'] ?? []),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>">

        <input
            type="text"
            id="skill-search"
            placeholder="Search skills (e.g. PHP, Graphic Design)"
            autocomplete="off">

        <div id="skill-suggestions"></div>
        <div id="selected-skills"></div>

    </div>

    <br><br>

    <label>Experience</label>
    <textarea name="experience"><?php echo htmlspecialchars($profile['experience'] ?? ''); ?></textarea>

    <br><br>

    <label>Education</label>
    <textarea name="education"><?php echo htmlspecialchars($profile['education'] ?? ''); ?></textarea>

    <br><br>

    <label>Upload Resume (PDF only, maximum 5 MB)</label>

    <input
        type="file"
        name="resume"
        accept=".pdf,application/pdf">

    <?php if (!empty($profile['resume_url'])): ?>

        <p>
            <strong>Current Resume:</strong>
            <?php echo htmlspecialchars(basename($profile['resume_url'])); ?>
        </p>

        <p>Your existing resume will remain saved unless you upload a replacement.</p>

    <?php else: ?>

        <p>You haven't uploaded a resume yet.</p>

    <?php endif; ?>

    <br><br>

    <label>Portfolio URL</label>
    <input
        type="url"
        name="portfolio_url"
        value="<?php echo htmlspecialchars($profile['portfolio_url'] ?? ''); ?>">

    <br><br>

    <label>Preferred Hourly Rate (PHP)</label>
    <input
        type="number"
        name="preferred_hourly_rate"
        min="0"
        step="0.01"
        value="<?php echo htmlspecialchars((string)($profile['preferred_hourly_rate'] ?? '')); ?>">

    <br><br>

    <button type="submit" name="update_profile">
        Save Profile
    </button>

</form>

<script src="../../assets/js/script.js"></script>