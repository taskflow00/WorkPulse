<?php
// ==========================================
// CREATE JOB LISTING
// ==========================================

$required_role = 'employer';
$page_title = 'Create Job';
$active_page = 'jobs';
$page_styles = ['/assets/css/create-job.css'];

require_once '../../includes/auth.php';

$message = '';
$message_type = '';
$required_skills = [];


// ==========================================
// CSRF TOKEN
// ==========================================

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];


// ==========================================
// HANDLE JOB SUBMISSION
// ==========================================

if (isset($_POST['create_job'])) {

    // Check CSRF token.
    $submitted_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf_token, $submitted_token)) {

        $message = 'Invalid request. Please refresh the page and try again.';
    } else {

        // Get submitted form values.
        $title = trim($_POST['title'] ?? '');
        $company_name = trim($_POST['company_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $requirements = trim($_POST['requirements'] ?? '');
        $hourly_rate = trim($_POST['hourly_rate'] ?? '');
        $employment_type = $_POST['employment_type'] ?? '';
        $work_schedule = trim($_POST['work_schedule'] ?? '');
        $interview_required = isset($_POST['interview_required']);


        // Get selected skills.
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


        // ==========================================
        // VALIDATION
        // ==========================================

        if ($title === '' || $company_name === '' || $description === '') {

            $message = 'Please complete all required fields.';
        } elseif (strlen($title) > 150) {

            $message = 'Job title must not exceed 150 characters.';
        } elseif (strlen($company_name) > 150) {

            $message = 'Company name must not exceed 150 characters.';
        } elseif (
            !is_numeric($hourly_rate) ||
            (float) $hourly_rate <= 0 ||
            (float) $hourly_rate > 99999999.99
        ) {

            $message = 'Please enter a valid hourly rate.';
        } elseif (!in_array($employment_type, ['full_time', 'part_time'], true)) {

            $message = 'Please select a valid employment type.';
        } else {

            // ==========================================
            // PREPARE JOB DATA
            // ==========================================

            $data = [
                'employer_id' => $_SESSION['user']['id'],
                'title' => $title,
                'company_name' => $company_name,
                'description' => $description,
                'requirements' => $requirements,
                'required_skills' => $required_skills,
                'hourly_rate' => $hourly_rate,
                'employment_type' => $employment_type,
                'work_schedule' => $work_schedule,
                'interview_required' => $interview_required
            ];

            $json_data = json_encode($data);

            if ($json_data === false) {

                $message = 'Unable to prepare your job information.';
            } else {

                // ==========================================
                // SEND TO SUPABASE
                // ==========================================

                $url = SUPABASE_URL . '/rest/v1/jobs';

                $options = [
                    'http' => [
                        'method' => 'POST',
                        'header' =>
                        "Content-Type: application/json\r\n" .
                            "apikey: " . SUPABASE_KEY . "\r\n" .
                            "Authorization: Bearer " . $_SESSION['access_token'] . "\r\n" .
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

                    // Get the HTTP status code.
                    $response_headers = http_get_last_response_headers();
                    $status_code = 0;

                    if (is_array($response_headers) && isset($response_headers[0])) {

                        preg_match('/\s(\d{3})\s/', $response_headers[0], $matches);

                        if (isset($matches[1])) {
                            $status_code = (int) $matches[1];
                        }
                    }

                    $result = json_decode($response, true);

                    // Check whether the job was created.
                    if (
                        ($status_code === 200 || $status_code === 201) &&
                        is_array($result) &&
                        isset($result[0]['id'])
                    ) {

                        $message = 'Your job has been successfully published!';
                        $message_type = 'success';

                        // Clear the form after successful creation.
                        $_POST = [];
                        $required_skills = [];
                    } else {

                        if ($status_code === 401 || $status_code === 403) {

                            $message = 'You are not authorized to publish this job. Please log in again.';
                        } elseif ($status_code === 400 || $status_code === 422) {

                            $message = 'Some job information is invalid. Please review your entries.';
                        } elseif ($status_code === 429) {

                            $message = 'Too many requests. Please wait and try again.';
                        } elseif ($status_code >= 500) {

                            $message = 'Supabase is currently experiencing a problem. Please try again later.';
                        } else {

                            $message = 'Job creation failed. Please check your information and try again.';
                        }
                    }
                }
            }
        }
    }

    if ($message !== '' && $message_type === '') {
        $message_type = 'error';
    }
}


// ==========================================
// FORM VALUES
// ==========================================

$form_title = $_POST['title'] ?? '';
$form_company = $_POST['company_name'] ?? '';
$form_description = $_POST['description'] ?? '';
$form_requirements = $_POST['requirements'] ?? '';
$form_hourly_rate = $_POST['hourly_rate'] ?? '';
$form_employment_type = $_POST['employment_type'] ?? '';
$form_work_schedule = $_POST['work_schedule'] ?? '';

$interview_checked = !isset($_POST['create_job']) ||
    isset($_POST['interview_required']);


// ==========================================
// SHARED DASHBOARD HEADER
// ==========================================

require_once '../../includes/header.php';

?>

<div class="wp-page create-job-page">

    <!-- Page heading -->
    <div class="create-job-heading">

        <div>
            <div class="create-job-eyebrow">
                <i class="bi bi-plus-circle"></i>
                JOB MANAGEMENT
            </div>

            <h1 class="wp-page-title">Create a Job Listing</h1>

            <p class="wp-page-description">
                Provide the details of your opportunity to find the right worker for your team.
            </p>
        </div>

        <a href="jobs.php" class="wp-btn wp-btn-outline create-job-back">
            <i class="bi bi-arrow-left"></i>
            Back to Listings
        </a>

    </div>


    <!-- Feedback message -->
    <?php if ($message !== ''): ?>

        <div class="create-job-alert create-job-alert-<?= htmlspecialchars($message_type, ENT_QUOTES, 'UTF-8') ?>">
            <i class="bi bi-<?= $message_type === 'success' ? 'check-circle-fill' : 'exclamation-circle-fill' ?>"></i>

            <span><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></span>
        </div>

    <?php endif; ?>


    <form method="POST" action="" class="create-job-form">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">


        <div class="create-job-layout">

            <!-- Main form -->
            <div class="create-job-main">


                <!-- Basic information -->
                <section class="create-job-section">

                    <div class="create-job-section-heading">

                        <div class="create-job-section-icon">
                            <i class="bi bi-briefcase"></i>
                        </div>

                        <div>
                            <h2>Basic Information</h2>
                            <p>Start with the main details of your job opportunity.</p>
                        </div>

                    </div>


                    <div class="create-job-fields">

                        <div class="create-job-field">
                            <label for="title">
                                Job Title <span>*</span>
                            </label>

                            <input
                                type="text"
                                id="title"
                                name="title"
                                maxlength="150"
                                placeholder="e.g. Junior Web Developer"
                                value="<?= htmlspecialchars($form_title, ENT_QUOTES, 'UTF-8') ?>"
                                required>

                            <small>Use a clear title that describes the position.</small>
                        </div>


                        <div class="create-job-field">
                            <label for="company_name">
                                Company Name <span>*</span>
                            </label>

                            <input
                                type="text"
                                id="company_name"
                                name="company_name"
                                maxlength="150"
                                placeholder="e.g. WorkPulse Technologies"
                                value="<?= htmlspecialchars($form_company, ENT_QUOTES, 'UTF-8') ?>"
                                required>
                        </div>


                        <div class="create-job-field">
                            <label for="description">
                                Job Description <span>*</span>
                            </label>

                            <textarea
                                id="description"
                                name="description"
                                rows="7"
                                placeholder="Describe the role, responsibilities, and what the worker will be doing..."
                                required><?= htmlspecialchars($form_description, ENT_QUOTES, 'UTF-8') ?></textarea>

                            <small>Explain what the worker will be expected to accomplish.</small>
                        </div>


                        <div class="create-job-field">
                            <label for="requirements">
                                Job Requirements
                            </label>

                            <textarea
                                id="requirements"
                                name="requirements"
                                rows="5"
                                placeholder="List the qualifications, experience, and other requirements..."><?= htmlspecialchars($form_requirements, ENT_QUOTES, 'UTF-8') ?></textarea>

                            <small>Specify the qualifications a suitable applicant should have.</small>
                        </div>

                    </div>

                </section>


                <!-- Skills -->
                <section class="create-job-section">

                    <div class="create-job-section-heading">

                        <div class="create-job-section-icon">
                            <i class="bi bi-stars"></i>
                        </div>

                        <div>
                            <h2>Required Skills</h2>
                            <p>Choose the skills applicants should have for this position.</p>
                        </div>

                    </div>


                    <div class="create-job-field">

                        <label>Skills for this Job</label>

                        <div
                            class="skills-container"
                            data-input-name="required_skills[]"
                            data-selected-skills="<?= htmlspecialchars(
                                                        json_encode($required_skills),
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>">

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

                        <small>Select relevant skills to help applicants understand what you're looking for.</small>

                    </div>

                </section>


                <!-- Employment details -->
                <section class="create-job-section">

                    <div class="create-job-section-heading">

                        <div class="create-job-section-icon">
                            <i class="bi bi-calendar-week"></i>
                        </div>

                        <div>
                            <h2>Employment Details</h2>
                            <p>Set the compensation and working arrangement.</p>
                        </div>

                    </div>


                    <div class="create-job-fields create-job-fields-two">

                        <div class="create-job-field">
                            <label for="hourly_rate">
                                Hourly Rate (USD) <span>*</span>
                            </label>

                            <div class="create-job-input-prefix">
                                <span>$</span>

                                <input
                                    type="number"
                                    id="hourly_rate"
                                    name="hourly_rate"
                                    min="0.01"
                                    max="99999999.99"
                                    step="0.01"
                                    placeholder="0.00"
                                    value="<?= htmlspecialchars($form_hourly_rate, ENT_QUOTES, 'UTF-8') ?>"
                                    required>
                            </div>

                            <small>Enter the hourly compensation for this position.</small>
                        </div>


                        <div class="create-job-field">
                            <label for="employment_type">
                                Employment Type <span>*</span>
                            </label>

                            <select id="employment_type" name="employment_type" required>

                                <option value="">Select employment type</option>

                                <option
                                    value="full_time"
                                    <?= $form_employment_type === 'full_time' ? 'selected' : '' ?>>
                                    Full-time
                                </option>

                                <option
                                    value="part_time"
                                    <?= $form_employment_type === 'part_time' ? 'selected' : '' ?>>
                                    Part-time
                                </option>

                            </select>
                        </div>


                        <div class="create-job-field create-job-field-full">
                            <label for="work_schedule">
                                Work Schedule
                            </label>

                            <input
                                type="text"
                                id="work_schedule"
                                name="work_schedule"
                                placeholder="e.g. Monday-Friday, 8 AM-5 PM"
                                value="<?= htmlspecialchars($form_work_schedule, ENT_QUOTES, 'UTF-8') ?>">

                            <small>Describe the expected working days and hours.</small>
                        </div>

                    </div>


                    <div class="create-job-interview-option">

                        <div class="create-job-interview-icon">
                            <i class="bi bi-camera-video"></i>
                        </div>

                        <div class="create-job-interview-text">
                            <strong>Require an interview</strong>
                            <span>Applicants will need to complete an interview before proceeding with the hiring process.</span>
                        </div>

                        <label class="create-job-switch">
                            <input
                                type="checkbox"
                                name="interview_required"
                                value="1"
                                <?= $interview_checked ? 'checked' : '' ?>>

                            <span></span>
                        </label>

                    </div>

                </section>

            </div>


            <!-- Side panel -->
            <aside class="create-job-sidebar">

                <div class="create-job-publish-card">

                    <div class="create-job-publish-icon">
                        <i class="bi bi-send"></i>
                    </div>

                    <h3>Ready to publish?</h3>

                    <p>
                        Review your job information before publishing it to potential workers.
                    </p>

                    <button
                        type="submit"
                        name="create_job"
                        value="1"
                        class="wp-btn wp-btn-primary create-job-submit">

                        <i class="bi bi-send"></i>
                        Publish Job

                    </button>

                    <a href="jobs.php" class="create-job-cancel">
                        Cancel and go back
                    </a>

                </div>


                <div class="create-job-tips-card">

                    <h3>
                        <i class="bi bi-lightbulb"></i>
                        Tips for a good listing
                    </h3>

                    <ul>
                        <li>
                            <i class="bi bi-check-circle"></i>
                            Use a specific and understandable job title.
                        </li>

                        <li>
                            <i class="bi bi-check-circle"></i>
                            Clearly describe the responsibilities.
                        </li>

                        <li>
                            <i class="bi bi-check-circle"></i>
                            Include realistic skills and requirements.
                        </li>

                        <li>
                            <i class="bi bi-check-circle"></i>
                            Set a clear hourly rate and work schedule.
                        </li>
                    </ul>

                </div>

            </aside>

        </div>

    </form>

</div>

<?php require_once '../../includes/footer.php'; ?>