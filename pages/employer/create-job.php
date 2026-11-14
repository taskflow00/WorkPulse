<?php
session_start();

require_once '../../config/supabase.php';

// Only logged-in employers can access this page
if (!isset($_SESSION['user']) || $_SESSION['role'] != 'employer') {
    header('Location: ../../login.php');
    exit;
}

$message = '';
$message_type = '';

// Keep skills available even when the page first loads
$required_skills = [];

// Handle job submission
if (isset($_POST['create_job'])) {

    // Get the submitted form values
    $title = trim($_POST['title'] ?? '');
    $company_name = trim($_POST['company_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $requirements = trim($_POST['requirements'] ?? '');
    $hourly_rate = trim($_POST['hourly_rate'] ?? '');
    $employment_type = $_POST['employment_type'] ?? '';
    $work_schedule = trim($_POST['work_schedule'] ?? '');
    $interview_required = isset($_POST['interview_required']);

    // Get selected skills from the JavaScript skill selector
    if (isset($_POST['required_skills']) && is_array($_POST['required_skills'])) {

        foreach ($_POST['required_skills'] as $skill) {

            if (!is_string($skill)) {
                continue;
            }

            $skill = trim($skill);

            // Avoid empty and duplicate skills
            if ($skill != '' && !in_array($skill, $required_skills, true)) {
                $required_skills[] = $skill;
            }
        }
    }

    // Validate required fields
    if ($title == '' || $company_name == '' || $description == '') {

        $message = 'Please complete all required fields.';
    } elseif (strlen($title) > 150) {

        $message = 'Job title must not exceed 150 characters.';
    } elseif (strlen($company_name) > 150) {

        $message = 'Company name must not exceed 150 characters.';
    } elseif (!is_numeric($hourly_rate) || (float)$hourly_rate <= 0 || (float)$hourly_rate > 99999999.99) {

        $message = 'Please enter a valid hourly rate.';
    } elseif (!in_array($employment_type, ['full_time', 'part_time'], true)) {

        $message = 'Please select a valid employment type.';
    } else {

        // Prepare the job information
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

        // Convert the job information into JSON
        $json_data = json_encode($data);

        if ($json_data === false) {

            $message = 'Unable to prepare your job information.';
        } else {

            // Send the job information to Supabase
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

                // Connection or network error
                $message = 'Unable to connect to Supabase. Please try again.';
            } else {

                // Get the HTTP status code returned by Supabase
                $response_headers = http_get_last_response_headers();

                // Get the HTTP status code
                $status_code = 0;

                if (is_array($response_headers) && isset($response_headers[0])) {
                    preg_match('/\s(\d{3})\s/', $response_headers[0], $matches);

                    if (isset($matches[1])) {
                        $status_code = (int)$matches[1];
                    }
                }

                if (isset($http_response_header[0])) {
                    preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches);
                    $status_code = isset($matches[1]) ? (int)$matches[1] : 0;
                }

                // Convert the response into a PHP array
                $result = json_decode($response, true);

                // Check if Supabase successfully created the job
                if (
                    ($status_code == 200 || $status_code == 201) &&
                    is_array($result) &&
                    isset($result[0]['id'])
                ) {

                    $message = 'Job successfully published!';
                    $message_type = 'success';

                    // Clear the submitted form after successful creation
                    $_POST = [];
                    $required_skills = [];
                } else {

                    // Handle errors based on the HTTP status code
                    if ($status_code == 401 || $status_code == 403) {

                        $message = 'You are not authorized to publish this job. Please log in again.';
                    } elseif ($status_code == 400 || $status_code == 422) {

                        $message = 'Some job information is invalid. Please review your entries.';
                    } elseif ($status_code == 429) {

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

    // Set the message type for validation errors
    if ($message != '' && $message_type == '') {
        $message_type = 'error';
    }
}
?>

<h1>Create a Job Listing</h1>

<?php if ($message != ''): ?>
    <p class="<?php echo htmlspecialchars($message_type); ?>">
        <?php echo htmlspecialchars($message); ?>
    </p>
<?php endif; ?>

<form method="POST" action="">

    <label>Job Title *</label>
    <input
        type="text"
        name="title"
        maxlength="150"
        value="<?php echo htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
        required>

    <br><br>

    <label>Company Name *</label>
    <input
        type="text"
        name="company_name"
        maxlength="150"
        value="<?php echo htmlspecialchars($_POST['company_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
        required>

    <br><br>

    <label>Job Description *</label>
    <textarea
        name="description"
        required><?php echo htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>

    <br><br>

    <label>Requirements</label>
    <textarea
        name="requirements"><?php echo htmlspecialchars($_POST['requirements'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>

    <br><br>

    <label>Required Skills</label>

    <div
        class="skills-container"
        data-input-name="required_skills[]"
        data-selected-skills="<?php echo htmlspecialchars(
                                    json_encode($required_skills),
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

    <label>Hourly Rate (PHP) *</label>
    <input
        type="number"
        name="hourly_rate"
        min="0.01"
        max="99999999.99"
        step="0.01"
        value="<?php echo htmlspecialchars($_POST['hourly_rate'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
        required>

    <br><br>

    <label>Employment Type *</label>
    <select name="employment_type" required>

        <option value="">Select employment type</option>

        <option value="full_time"
            <?php echo ($_POST['employment_type'] ?? '') == 'full_time' ? 'selected' : ''; ?>>
            Full-time
        </option>

        <option value="part_time"
            <?php echo ($_POST['employment_type'] ?? '') == 'part_time' ? 'selected' : ''; ?>>
            Part-time
        </option>

    </select>

    <br><br>

    <label>Work Schedule</label>
    <input
        type="text"
        name="work_schedule"
        placeholder="Example: Monday-Friday, 8 AM-5 PM"
        value="<?php echo htmlspecialchars($_POST['work_schedule'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

    <br><br>

    <label>
        <input
            type="checkbox"
            name="interview_required"
            value="1"
            <?php echo (!isset($_POST['create_job']) || isset($_POST['interview_required'])) ? 'checked' : ''; ?>>
        Interview required
    </label>

    <br><br>

    <button type="submit" name="create_job">
        Publish Job
    </button>

</form>

<script src="../../assets/js/script.js"></script>