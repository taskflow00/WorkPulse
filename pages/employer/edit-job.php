<?php
session_start();

require_once '../../config/supabase.php';

// Check login and employer role.
if (!isset($_SESSION['access_token']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

if ($_SESSION['role'] !== 'employer') {
    if ($_SESSION['role'] === 'worker') {
        header('Location: ../worker/dashboard.php');
    } elseif ($_SESSION['role'] === 'admin') {
        header('Location: ../admin/dashboard.php');
    } else {
        header('Location: ../../login.php');
    }

    exit;
}

$token = $_SESSION['access_token'];
$employer_id = $_SESSION['user']['id'];


// Generate CSRF token.
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

$message = '';


// Get the selected job ID.
$job_id = $_GET['id'] ?? '';

if ($job_id === '') {
    header('Location: jobs.php');
    exit;
}


// Retrieve the selected job.
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
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);

$response = file_get_contents($url, false, $context);

$job = null;

if ($response !== false) {

    $result = json_decode($response, true);

    if (is_array($result) && count($result) > 0 && !isset($result['code'])) {
        $job = $result[0];
    }
}

if ($job === null) {
    exit('Job listing not found or you do not have permission to edit it.');
}


// Prepare the form values.
$form = $job;

$skills_text = implode(', ', $job['required_skills'] ?? []);


// Handle form submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf_token, $submitted_token)) {

        $message = 'Invalid request. Please try again.';
    } else {

        // Get submitted form values.
        $form['title'] = trim($_POST['title'] ?? '');
        $form['company_name'] = trim($_POST['company_name'] ?? '');
        $form['description'] = trim($_POST['description'] ?? '');
        $form['requirements'] = trim($_POST['requirements'] ?? '');
        $skills_text = trim($_POST['required_skills'] ?? '');
        $form['hourly_rate'] = trim($_POST['hourly_rate'] ?? '');
        $form['employment_type'] = $_POST['employment_type'] ?? '';
        $form['work_schedule'] = trim($_POST['work_schedule'] ?? '');
        $form['interview_required'] = isset($_POST['interview_required']);

        // Validate the submitted information.
        if (
            $form['title'] === '' ||
            $form['company_name'] === '' ||
            $form['description'] === ''
        ) {

            $message = 'Please complete the required fields.';
        } elseif (
            !is_numeric($form['hourly_rate']) ||
            (float) $form['hourly_rate'] <= 0
        ) {

            $message = 'Please enter a valid hourly rate.';
        } elseif (
            !in_array($form['employment_type'], ['full_time', 'part_time'])
        ) {

            $message = 'Please select a valid employment type.';
        } else {

            // Convert comma-separated skills into an array.
            $required_skills = [];

            foreach (explode(',', $skills_text) as $skill) {

                $skill = trim($skill);

                if ($skill !== '' && !in_array($skill, $required_skills)) {
                    $required_skills[] = $skill;
                }
            }

            // Prepare the updated job information.
            $data = [
                'title' => $form['title'],
                'company_name' => $form['company_name'],
                'description' => $form['description'],
                'requirements' => $form['requirements'],
                'required_skills' => $required_skills,
                'hourly_rate' => (float) $form['hourly_rate'],
                'employment_type' => $form['employment_type'],
                'work_schedule' => $form['work_schedule'],
                'interview_required' => $form['interview_required']
            ];

            // Update only this employer's job.
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
                    'content' => json_encode($data),
                    'ignore_errors' => true
                ]
            ];

            $context = stream_context_create($options);

            $response = file_get_contents($url, false, $context);

            if ($response === false) {

                $message = 'Unable to connect to Supabase.';
            } else {

                $result = json_decode($response, true);

                if (is_array($result) && count($result) > 0) {

                    $_SESSION['job_message'] = 'Job listing successfully updated.';

                    header('Location: jobs.php');
                    exit;
                } else {

                    $message = 'Unable to update the job listing.';
                }
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>WorkPulse | Edit Job</title>
</head>

<body>

    <h1>Edit Job Listing</h1>

    <p>
        <a href="jobs.php">Back to My Job Listings</a> |
        <a href="../../logout.php">Logout</a>
    </p>

    <hr>

    <?php if ($message !== ''): ?>

        <p><?php echo htmlspecialchars($message); ?></p>

    <?php endif; ?>


    <form method="POST" action="">

        <input
            type="hidden"
            name="csrf_token"
            value="<?php echo htmlspecialchars($csrf_token); ?>">

        <div>

            <label>Job Title</label><br>

            <input
                type="text"
                name="title"
                value="<?php echo htmlspecialchars($form['title'], ENT_QUOTES, 'UTF-8'); ?>"
                required>

        </div>

        <br>

        <div>

            <label>Company Name</label><br>

            <input
                type="text"
                name="company_name"
                value="<?php echo htmlspecialchars($form['company_name'], ENT_QUOTES, 'UTF-8'); ?>"
                required>

        </div>

        <br>

        <div>

            <label>Job Description</label><br>

            <textarea
                name="description"
                rows="5"
                required><?php echo htmlspecialchars($form['description'], ENT_QUOTES, 'UTF-8'); ?></textarea>

        </div>

        <br>

        <div>

            <label>Requirements</label><br>

            <textarea
                name="requirements"
                rows="4"><?php echo htmlspecialchars($form['requirements'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>

        </div>

        <br>

        <div>

            <label>Required Skills</label><br>

            <input
                type="text"
                name="required_skills"
                value="<?php echo htmlspecialchars($skills_text, ENT_QUOTES, 'UTF-8'); ?>"
                placeholder="PHP, HTML, CSS, JavaScript">

            <p>Separate each skill with a comma.</p>

        </div>

        <br>

        <div>

            <label>Hourly Rate (USD)</label><br>

            <input
                type="number"
                name="hourly_rate"
                min="0.01"
                step="0.01"
                value="<?php echo htmlspecialchars((string) $form['hourly_rate'], ENT_QUOTES, 'UTF-8'); ?>"
                required>

        </div>

        <br>

        <div>

            <label>Employment Type</label><br>

            <select name="employment_type" required>

                <option
                    value="full_time"
                    <?php echo $form['employment_type'] === 'full_time' ? 'selected' : ''; ?>>
                    Full Time
                </option>

                <option
                    value="part_time"
                    <?php echo $form['employment_type'] === 'part_time' ? 'selected' : ''; ?>>
                    Part Time
                </option>

            </select>

        </div>

        <br>

        <div>

            <label>Work Schedule</label><br>

            <textarea
                name="work_schedule"
                rows="3"><?php echo htmlspecialchars($form['work_schedule'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>

        </div>

        <br>

        <div>

            <label>

                <input
                    type="checkbox"
                    name="interview_required"
                    value="1"
                    <?php echo !empty($form['interview_required']) ? 'checked' : ''; ?>>

                Interview Required

            </label>

        </div>

        <br>

        <button type="submit">Save Changes</button>

        <a href="jobs.php">Cancel</a>

    </form>

</body>

</html>