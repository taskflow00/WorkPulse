<?php
$required_role = 'employer';
require_once '../../includes/auth.php';

$token = $_SESSION['access_token'];
$employer_id = $_SESSION['user']['id'];

$page_title = 'Edit Job';
$active_page = 'jobs';
$page_styles = ['/assets/css/edit-job.css'];

$message = '';
$message_type = '';

// Generate CSRF token.
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

// Get the selected job ID.
$job_id = $_GET['id'] ?? '';

if ($job_id === '') {
    header('Location: jobs.php');
    exit;
}

// Retrieve this employer's job listing.
$url = SUPABASE_URL .
    '/rest/v1/jobs?select=id,title,company_name,description,requirements,required_skills,hourly_rate,employment_type,work_schedule,interview_required,status' .
    '&id=eq.' . urlencode($job_id) .
    '&employer_id=eq.' . urlencode($employer_id) .
    '&limit=1';

$options = [
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $token . "\r\n",
        'ignore_errors' => true,
        'timeout' => 15
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

$result = json_decode($response ?: '', true);

if (!is_array($result) || !isset($result[0]['id'])) {
    $_SESSION['job_message'] = 'Job listing not found or unavailable.';
    header('Location: jobs.php');
    exit;
}

$job = $result[0];

// Prepare the form values.
$form = $job;
$required_skills = $job['required_skills'] ?? [];

// Handle form submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Check CSRF token.
    $submitted_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf_token, $submitted_token)) {

        $message = 'Invalid request. Please refresh the page and try again.';
    } else {

        // Get submitted values.
        $form['title'] = trim($_POST['title'] ?? '');
        $form['company_name'] = trim($_POST['company_name'] ?? '');
        $form['description'] = trim($_POST['description'] ?? '');
        $form['requirements'] = trim($_POST['requirements'] ?? '');
        $form['hourly_rate'] = trim($_POST['hourly_rate'] ?? '');
        $form['employment_type'] = $_POST['employment_type'] ?? '';
        $form['work_schedule'] = trim($_POST['work_schedule'] ?? '');
        $form['interview_required'] = isset($_POST['interview_required']);

        // Get selected skills.
        $required_skills = [];

        if (isset($_POST['required_skills']) && is_array($_POST['required_skills'])) {

            foreach ($_POST['required_skills'] as $skill) {

                if (!is_string($skill)) {
                    continue;
                }

                $skill = trim($skill);

                if ($skill !== '' && !in_array($skill, $required_skills, true)) {
                    $required_skills[] = $skill;
                }
            }
        }

        // Validate the form.
        if (
            $form['title'] === '' ||
            $form['company_name'] === '' ||
            $form['description'] === ''
        ) {

            $message = 'Please complete all required fields.';
        } elseif (
            strlen($form['title']) > 150 ||
            strlen($form['company_name']) > 150
        ) {

            $message = 'Title and company name must not exceed 150 characters.';
        } elseif (
            !is_numeric($form['hourly_rate']) ||
            (float)$form['hourly_rate'] <= 0 ||
            (float)$form['hourly_rate'] > 99999999.99
        ) {

            $message = 'Please enter a valid hourly rate.';
        } elseif (!in_array($form['employment_type'], ['full_time', 'part_time'], true)) {

            $message = 'Please select a valid employment type.';
        } else {

            // Prepare updated job information.
            $data = [
                'title' => $form['title'],
                'company_name' => $form['company_name'],
                'description' => $form['description'],
                'requirements' => $form['requirements'],
                'required_skills' => $required_skills,
                'hourly_rate' => (float)$form['hourly_rate'],
                'employment_type' => $form['employment_type'],
                'work_schedule' => $form['work_schedule'],
                'interview_required' => $form['interview_required']
            ];

            $json_data = json_encode($data);

            if ($json_data === false) {

                $message = 'Unable to prepare the job information.';
            } else {

                // Update only this employer's job listing.
                $url = SUPABASE_URL .
                    '/rest/v1/jobs?id=eq.' . urlencode($job_id) .
                    '&employer_id=eq.' . urlencode($employer_id);

                $options = [
                    'http' => [
                        'method' => 'PATCH',
                        'header' =>
                        "apikey: " . SUPABASE_KEY . "\r\n" .
                            "Authorization: Bearer " . $token . "\r\n" .
                            "Content-Type: application/json\r\n" .
                            "Prefer: return=representation\r\n",
                        'content' => $json_data,
                        'ignore_errors' => true,
                        'timeout' => 15
                    ]
                ];

                $context = stream_context_create($options);
                $response = file_get_contents($url, false, $context);

                if ($response === false) {

                    $message = 'Unable to connect to Supabase. Please try again.';
                } else {

                    $status_code = 0;

                    $response_headers = http_get_last_response_headers();

                    if (is_array($response_headers) && isset($response_headers[0])) {
                        preg_match('/\s(\d{3})\s/', $response_headers[0], $matches);
                        $status_code = isset($matches[1]) ? (int)$matches[1] : 0;
                    }

                    $result = json_decode($response, true);

                    if (
                        $status_code >= 200 &&
                        $status_code < 300 &&
                        is_array($result) &&
                        count($result) > 0
                    ) {

                        $_SESSION['job_message'] = 'Job listing successfully updated.';

                        header('Location: jobs.php');
                        exit;
                    } elseif ($status_code === 401 || $status_code === 403) {

                        $message = 'You are not authorized to update this job listing.';
                    } elseif ($status_code === 400 || $status_code === 422) {

                        $message = 'Some information is invalid. Please review your entries.';
                    } else {

                        $message = 'Unable to update the job listing. Please try again.';
                    }
                }
            }
        }
    }

    if ($message !== '') {
        $message_type = 'error';
    }
}

require_once '../../includes/header.php';
?>

<div class="edit-job-page">

    <div class="edit-job-heading">
        <div>
            <a href="jobs.php" class="edit-job-back">
                <i class="bi bi-arrow-left"></i>
                Back to Job Listings
            </a>

            <h1>Edit Job Listing</h1>
            <p>Update the details and requirements of your job posting.</p>
        </div>

        <span class="edit-job-status">
            <i class="bi bi-circle-fill"></i>
            <?php echo htmlspecialchars(ucfirst($job['status'] ?? 'Unknown')); ?>
        </span>
    </div>

    <?php if ($message !== ''): ?>
        <div class="edit-job-alert">
            <i class="bi bi-exclamation-circle"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="" class="edit-job-layout">

        <input
            type="hidden"
            name="csrf_token"
            value="<?php echo htmlspecialchars($csrf_token); ?>">

        <div class="edit-job-main">

            <section class="edit-job-card">

                <div class="edit-job-card-heading">
                    <div class="edit-job-icon">
                        <i class="bi bi-briefcase"></i>
                    </div>
                    <div>
                        <h2>Basic Information</h2>
                        <p>Provide the main details of this position.</p>
                    </div>
                </div>

                <div class="edit-job-field">
                    <label for="title">Job Title <span>*</span></label>
                    <input
                        type="text"
                        id="title"
                        name="title"
                        maxlength="150"
                        value="<?php echo htmlspecialchars($form['title'], ENT_QUOTES, 'UTF-8'); ?>"
                        placeholder="e.g. Junior Web Developer"
                        required>
                </div>

                <div class="edit-job-field">
                    <label for="company_name">Company Name <span>*</span></label>
                    <input
                        type="text"
                        id="company_name"
                        name="company_name"
                        maxlength="150"
                        value="<?php echo htmlspecialchars($form['company_name'], ENT_QUOTES, 'UTF-8'); ?>"
                        placeholder="Enter your company name"
                        required>
                </div>

                <div class="edit-job-field">
                    <label for="description">Job Description <span>*</span></label>
                    <textarea
                        id="description"
                        name="description"
                        rows="6"
                        placeholder="Describe the role and its responsibilities..."
                        required><?php echo htmlspecialchars($form['description'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="edit-job-field">
                    <label for="requirements">Requirements</label>
                    <textarea
                        id="requirements"
                        name="requirements"
                        rows="5"
                        placeholder="List the qualifications and experience needed..."><?php echo htmlspecialchars($form['requirements'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

            </section>

            <section class="edit-job-card">

                <div class="edit-job-card-heading">
                    <div class="edit-job-icon">
                        <i class="bi bi-tools"></i>
                    </div>
                    <div>
                        <h2>Required Skills</h2>
                        <p>Choose the skills applicants should have.</p>
                    </div>
                </div>

                <div
                    class="skills-container"
                    data-input-name="required_skills[]"
                    data-selected-skills="<?php echo htmlspecialchars(json_encode($required_skills), ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="create-job-skill-search">
                        <i class="bi bi-search"></i>
                        <input
                            type="text"
                            id="skill-search"
                            placeholder="Search skills (e.g. PHP, Graphic Design)"
                            autocomplete="off">
                    </div>

                    <div id="skill-suggestions"></div>
                    <div id="selected-skills"></div>

                </div>

                <p class="edit-job-hint">
                    <i class="bi bi-info-circle"></i>
                    Search and select the skills required for this position.
                </p>

            </section>

            <section class="edit-job-card">

                <div class="edit-job-card-heading">
                    <div class="edit-job-icon">
                        <i class="bi bi-clock"></i>
                    </div>
                    <div>
                        <h2>Employment Details</h2>
                        <p>Set the compensation and working arrangement.</p>
                    </div>
                </div>

                <div class="edit-job-field">
                    <label for="hourly_rate">Hourly Rate (USD) <span>*</span></label>
                    <div class="edit-job-input-icon">
                        <span>$</span>
                        <input
                            type="number"
                            id="hourly_rate"
                            name="hourly_rate"
                            min="0.01"
                            max="99999999.99"
                            step="0.01"
                            value="<?php echo htmlspecialchars((string)$form['hourly_rate'], ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="0.00"
                            required>
                    </div>
                </div>

                <div class="edit-job-field">
                    <label for="employment_type">Employment Type <span>*</span></label>
                    <select id="employment_type" name="employment_type" required>
                        <option value="">Select employment type</option>
                        <option value="full_time" <?php echo $form['employment_type'] === 'full_time' ? 'selected' : ''; ?>>
                            Full-time
                        </option>
                        <option value="part_time" <?php echo $form['employment_type'] === 'part_time' ? 'selected' : ''; ?>>
                            Part-time
                        </option>
                    </select>
                </div>

                <div class="edit-job-field">
                    <label for="work_schedule">Work Schedule</label>
                    <input
                        type="text"
                        id="work_schedule"
                        name="work_schedule"
                        value="<?php echo htmlspecialchars($form['work_schedule'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                        placeholder="e.g. Monday-Friday, 8 AM-5 PM">
                </div>

                <label class="edit-job-checkbox">
                    <input
                        type="checkbox"
                        name="interview_required"
                        value="1"
                        <?php echo !empty($form['interview_required']) ? 'checked' : ''; ?>>
                    <span>
                        <strong>Interview required</strong>
                        <small>Applicants must complete an interview before being hired.</small>
                    </span>
                </label>

            </section>

        </div>

        <aside class="edit-job-sidebar">

            <div class="edit-job-card edit-job-publish-card">
                <div class="edit-job-publish-icon">
                    <i class="bi bi-pencil-square"></i>
                </div>

                <h2>Save your changes</h2>
                <p>Review your updates before saving this job listing.</p>

                <button type="submit" class="edit-job-save-button">
                    <i class="bi bi-check-lg"></i>
                    Save Changes
                </button>

                <a href="jobs.php" class="edit-job-cancel-button">
                    Cancel
                </a>
            </div>

            <div class="edit-job-card edit-job-tip-card">
                <h3><i class="bi bi-lightbulb"></i> Helpful Tips</h3>
                <ul>
                    <li>Use a clear and specific job title.</li>
                    <li>Keep the job description easy to understand.</li>
                    <li>List only skills relevant to the position.</li>
                    <li>Make the work schedule and pay clear.</li>
                </ul>
            </div>

        </aside>

    </form>

</div>

<?php require_once '../../includes/footer.php'; ?>