<?php
session_start();

require_once '../config/supabase.php';

// Get the job ID
$job_id = $_GET['id'] ?? '';

$job = null;
$message = '';
$application_message = '';
$existing_resume = null;


// Retrieve the selected job
if (!preg_match(
    '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
    $job_id
)) {
    $message = 'Invalid job listing.';
} else {

    $url = SUPABASE_URL .
        '/rest/v1/jobs?select=*&id=eq.' . urlencode($job_id) .
        '&status=eq.open';

    $options = [
        'http' => [
            'method' => 'GET',
            'header' => "apikey: " . SUPABASE_KEY . "\r\n",
            'ignore_errors' => true
        ]
    ];

    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);

    if ($response === false) {
        $message = 'Unable to connect to the job listings.';
    } else {

        $result = json_decode($response, true);

        if (isset($result[0])) {
            $job = $result[0];
        } elseif (empty($result)) {
            $message = 'This job listing is no longer available.';
        } else {
            $message = 'Unable to retrieve the job details. ' .
                ($result['message'] ?? 'Please try again.');
        }
    }
}


// Get the worker's existing resume from their profile
if (
    isset($_SESSION['user']) &&
    $_SESSION['role'] === 'worker'
) {

    $worker_id = $_SESSION['user']['id'];

    $profile_url = SUPABASE_URL .
        '/rest/v1/profiles?select=resume_url&id=eq.' . urlencode($worker_id);

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
    $profile_response = file_get_contents($profile_url, false, $context);

    $profile_result = json_decode($profile_response ?: '', true);

    $existing_resume = $profile_result[0]['resume_url'] ?? null;
}


// Handle job application
if (isset($_POST['apply'])) {

    if (
        !isset($_SESSION['user']) ||
        $_SESSION['role'] !== 'worker'
    ) {
        $application_message = 'Please log in using a worker account to apply.';
    } elseif ($job === null) {
        $application_message = 'This job is no longer available.';
    } else {

        $worker_id = $_SESSION['user']['id'];
        $application_statement = trim($_POST['application_statement'] ?? '');

        // Use the existing profile resume by default
        $resume_path = $existing_resume;


        // Check if the worker uploaded a replacement resume
        if (
            isset($_FILES['resume']) &&
            $_FILES['resume']['error'] !== UPLOAD_ERR_NO_FILE
        ) {

            $file = $_FILES['resume'];

            $extension = strtolower(
                pathinfo($file['name'], PATHINFO_EXTENSION)
            );

            $allowed_types = [
                'pdf' => 'application/pdf',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $application_message = 'Resume upload failed.';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $application_message = 'Resume must not exceed 5 MB.';
            } elseif ($file['size'] <= 0) {
                $application_message = 'Please select a valid resume file.';
            } else {

                // Check the actual file type
                $file_info = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($file_info, $file['tmp_name']);

                if (
                    !isset($allowed_types[$extension]) ||
                    $mime_type !== $allowed_types[$extension]
                ) {
                    $application_message = 'Only PDF, DOC, and DOCX files are allowed.';
                } else {

                    // Create a unique Storage path
                    $resume_path = $worker_id .
                        '/resume-' . time() . '.' . $extension;

                    $storage_url = SUPABASE_URL .
                        '/storage/v1/object/resumes/' . $resume_path;

                    $options = [
                        'http' => [
                            'method' => 'POST',
                            'header' =>
                            "apikey: " . SUPABASE_KEY . "\r\n" .
                                "Authorization: Bearer " . $_SESSION['access_token'] . "\r\n" .
                                "Content-Type: " . $mime_type . "\r\n" .
                                "x-upsert: true\r\n",
                            'content' => file_get_contents($file['tmp_name']),
                            'ignore_errors' => true
                        ]
                    ];

                    $context = stream_context_create($options);
                    $upload_response = file_get_contents($storage_url, false, $context);

                    preg_match(
                        '/\s(\d{3})\s/',
                        $http_response_header[0] ?? '',
                        $matches
                    );

                    $status_code = (int)($matches[1] ?? 0);

                    if (
                        $upload_response === false ||
                        $status_code < 200 ||
                        $status_code >= 300
                    ) {
                        $application_message = 'Unable to upload your resume.';
                    } else {

                        // Update the worker's profile with the replacement resume
                        $profile_url = SUPABASE_URL .
                            '/rest/v1/profiles?id=eq.' . urlencode($worker_id);

                        $options = [
                            'http' => [
                                'method' => 'PATCH',
                                'header' =>
                                "Content-Type: application/json\r\n" .
                                    "apikey: " . SUPABASE_KEY . "\r\n" .
                                    "Authorization: Bearer " . $_SESSION['access_token'] . "\r\n" .
                                    "Prefer: return=representation\r\n",
                                'content' => json_encode([
                                    'resume_url' => $resume_path
                                ]),
                                'ignore_errors' => true
                            ]
                        ];

                        $context = stream_context_create($options);
                        $profile_update = file_get_contents($profile_url, false, $context);

                        preg_match(
                            '/\s(\d{3})\s/',
                            $http_response_header[0] ?? '',
                            $matches
                        );

                        $status_code = (int)($matches[1] ?? 0);

                        if (
                            $profile_update === false ||
                            $status_code < 200 ||
                            $status_code >= 300
                        ) {
                            $application_message = 'Resume uploaded, but profile update failed. Please try again.';
                        } else {
                            $existing_resume = $resume_path;
                        }
                    }
                }
            }
        }


        // A resume is required
        if (
            $application_message === '' &&
            empty($resume_path)
        ) {
            $application_message = 'Please upload your Resume / CV before applying.';
        }


        // Submit the application only if everything is valid
        if ($application_message === '') {

            $data = [
                'job_id' => $job['id'],
                'worker_id' => $worker_id,
                'application_statement' => $application_statement,
                'status' => 'pending',
                'resume_path' => $resume_path
            ];

            $url = SUPABASE_URL . '/rest/v1/applications';

            $options = [
                'http' => [
                    'method' => 'POST',
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

            preg_match(
                '/\s(\d{3})\s/',
                $http_response_header[0] ?? '',
                $matches
            );

            $status_code = (int)($matches[1] ?? 0);
            $result = json_decode($response ?: '', true);

            if ($status_code === 201) {
                $application_message = 'Application submitted successfully!';
            } elseif (
                isset($result['code']) &&
                $result['code'] === '23505'
            ) {
                $application_message = 'You have already applied for this job.';
            } else {
                $application_message = 'Application failed. ' .
                    ($result['message'] ?? 'Please try again.');
            }
        }
    }
}
?>


<?php if ($message !== ''): ?>

    <h1>Job Not Available</h1>

    <p><?php echo htmlspecialchars($message); ?></p>

    <a href="jobs.php">Back to Job Listings</a>

<?php elseif ($job !== null): ?>

    <h1><?php echo htmlspecialchars($job['title']); ?></h1>

    <p>
        Company:
        <?php echo htmlspecialchars($job['company_name']); ?>
    </p>

    <p>
        Hourly Rate:
        ₱<?php echo number_format((float)$job['hourly_rate'], 2); ?>/hour
    </p>

    <p>
        Employment Type:
        <?php echo htmlspecialchars(
            ucwords(str_replace('_', ' ', $job['employment_type']))
        ); ?>
    </p>

    <p>
        Work Schedule:
        <?php echo htmlspecialchars(
            $job['work_schedule'] ?? 'Not specified'
        ); ?>
    </p>

    <hr>

    <h2>Job Description</h2>

    <p>
        <?php echo nl2br(htmlspecialchars($job['description'])); ?>
    </p>

    <h2>Requirements</h2>

    <p>
        <?php echo nl2br(htmlspecialchars(
            $job['requirements'] ?? 'No specific requirements.'
        )); ?>
    </p>

    <h2>Required Skills</h2>

    <?php if (!empty($job['required_skills'])): ?>

        <?php foreach ($job['required_skills'] as $skill): ?>

            <span class="skill-tag">
                <?php echo htmlspecialchars($skill); ?>
            </span>

        <?php endforeach; ?>

    <?php else: ?>

        <p>No specific skills required.</p>

    <?php endif; ?>

    <h2>Interview</h2>

    <p>
        <?php echo !empty($job['interview_required'])
            ? 'An interview is required.'
            : 'No interview required.'; ?>
    </p>

    <hr>

    <h2>Apply for This Job</h2>

    <?php if ($application_message !== ''): ?>

        <p><?php echo htmlspecialchars($application_message); ?></p>

    <?php endif; ?>

    <?php if (
        isset($_SESSION['user']) &&
        $_SESSION['role'] === 'worker'
    ): ?>

        <form method="POST" enctype="multipart/form-data">

            <label for="application_statement">Application Statement (Optional)</label>

            <textarea
                name="application_statement"
                id="application_statement"
                rows="5"
                placeholder="Introduce yourself, explain why you're interested in this position, and describe how your skills and experience make you a good fit..."></textarea>
            <br><br>

            <label for="resume">Resume / CV (Required)</label>

            <?php if (!empty($existing_resume)): ?>

                <p>
                    <strong>Existing Resume:</strong>
                    <?php echo htmlspecialchars(basename($existing_resume)); ?>
                </p>

                <p>You can keep your existing resume or upload a replacement.</p>

            <?php else: ?>

                <p>You don't have a resume on your profile yet. Please upload one.</p>

            <?php endif; ?>

            <input
                type="file"
                name="resume"
                id="resume"
                accept=".pdf,.doc,.docx"
                <?php echo empty($existing_resume) ? 'required' : ''; ?>>

            <p>Accepted formats: PDF, DOC, DOCX. Maximum size: 5 MB.</p>

            <button type="submit" name="apply">
                Submit Application
            </button>

        </form>

    <?php elseif (!isset($_SESSION['user'])): ?>

        <p>Please log in using a worker account to apply.</p>

        <a href="../login.php?redirect=<?php echo urlencode('pages/job-details.php?id=' . $job['id']); ?>" class="btn">
            Log In to Apply
        </a>

    <?php else: ?>

        <p>You need a worker account to apply for this job.</p>

        <a href="../logout.php?redirect=<?php echo urlencode('pages/job-details.php?id=' . $job['id']); ?>" class="btn">
            Switch Account / Log In as Worker
        </a>

    <?php endif; ?>

    <hr>

    <a href="jobs.php">Back to Job Listings</a>

    <?php if (
        isset($_SESSION['user']) &&
        $_SESSION['role'] === 'worker'
    ): ?>

        <a href="worker/applications.php">
            Back to My Applications
        </a>

    <?php endif; ?>

<?php endif; ?>