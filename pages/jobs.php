<?php
session_start();

require_once '../config/supabase.php';

// Retrieve all open job listings
$url = SUPABASE_URL .
    '/rest/v1/jobs?select=id,title,company_name,description,hourly_rate,employment_type,work_schedule,required_skills,created_at&status=eq.open&order=created_at.desc';

// Prepare the Supabase request
$options = [
    'http' => [
        'method' => 'GET',
        'header' => "apikey: " . SUPABASE_KEY . "\r\n",
        'ignore_errors' => true,
        'timeout' => 15
    ]
];

$context = stream_context_create($options);

// Send the request
$response = file_get_contents($url, false, $context);

$jobs = [];
$message = '';

if ($response === false) {

    $message = 'Unable to connect to the job listings. Please try again.';
} else {

    // Get the HTTP status code
    $response_headers = http_get_last_response_headers();
    $status_code = 0;

    if (is_array($response_headers) && isset($response_headers[0])) {
        preg_match('/\s(\d{3})\s/', $response_headers[0], $matches);

        if (isset($matches[1])) {
            $status_code = (int)$matches[1];
        }
    }

    $result = json_decode($response, true);

    // Check whether Supabase successfully returned the jobs
    if ($status_code == 200 && is_array($result)) {

        $jobs = $result;
    } elseif ($status_code == 401 || $status_code == 403) {

        $message = 'Unable to access the job listings. Please try again later.';
    } else {

        $message = 'Unable to retrieve jobs. Please try again later.';
    }
}

?>

<h1>Find Remote Jobs</h1>

<?php if (
    isset($_SESSION['user']) &&
    $_SESSION['role'] === 'worker'
): ?>

    <a href="worker/applications.php">
        Back to My Applications
    </a>

<?php endif; ?>


<p>Discover opportunities and find work that matches your skills.</p>

<?php if ($message != ''): ?>

    <p><?php echo htmlspecialchars($message); ?></p>

<?php elseif (empty($jobs)): ?>

    <p>No job listings are available right now.</p>

<?php else: ?>

    <!-- Search and Filters -->
    <div class="job-filters">

        <h2>Find Your Opportunity</h2>

        <!-- Search by job title or company -->
        <label for="job-search">Search Jobs</label>

        <input
            type="text"
            id="job-search"
            placeholder="Search job title or company...">

        <br><br>

        <!-- Filter by employment type -->
        <label for="employment-filter">Employment Type</label>

        <select id="employment-filter">
            <option value="">All Employment Types</option>
            <option value="full_time">Full-time</option>
            <option value="part_time">Part-time</option>
        </select>

        <br><br>

        <!-- Filter by hourly rate -->
        <label for="minimum-rate">Minimum Hourly Rate (PHP)</label>

        <input
            type="number"
            id="minimum-rate"
            min="0"
            step="0.01"
            placeholder="Minimum rate">

        <br><br>

        <label for="maximum-rate">Maximum Hourly Rate (PHP)</label>

        <input
            type="number"
            id="maximum-rate"
            min="0"
            step="0.01"
            placeholder="Maximum rate">

        <br><br>

        <!-- Filter by required skills -->
        <label for="skill-filter">Required Skills</label>

        <input
            type="text"
            id="skill-filter"
            placeholder="Example: PHP, Graphic Design...">

        <br><br>

        <button type="button" id="clear-filters">
            Clear Filters
        </button>

    </div>

    <hr>

    <!-- Job Count -->
    <p id="job-count">
        <?php echo count($jobs); ?> job(s) available
    </p>

    <!-- No matching jobs message -->
    <p id="no-matching-jobs" style="display: none;">
        No jobs match your search. Try changing your filters.
    </p>

    <!-- Job Listings -->
    <div id="job-listings">

        <?php foreach ($jobs as $job): ?>

            <?php
            // Prepare job information for JavaScript filtering
            $skills = $job['required_skills'] ?? [];

            if (!is_array($skills)) {
                $skills = [];
            }

            $searchable_text = strtolower(
                $job['title'] . ' ' .
                    $job['company_name'] . ' ' .
                    $job['description']
            );

            $searchable_skills = strtolower(implode(' ', $skills));
            ?>

            <div
                class="job-card"
                data-search="<?php echo htmlspecialchars($searchable_text, ENT_QUOTES, 'UTF-8'); ?>"
                data-type="<?php echo htmlspecialchars($job['employment_type'], ENT_QUOTES, 'UTF-8'); ?>"
                data-rate="<?php echo htmlspecialchars((string)$job['hourly_rate'], ENT_QUOTES, 'UTF-8'); ?>"
                data-skills="<?php echo htmlspecialchars($searchable_skills, ENT_QUOTES, 'UTF-8'); ?>">

                <h2>
                    <?php echo htmlspecialchars($job['title']); ?>
                </h2>

                <p>
                    Company:
                    <?php echo htmlspecialchars($job['company_name']); ?>
                </p>

                <p>
                    Hourly Rate:
                    $<?php echo number_format((float)$job['hourly_rate'], 2); ?>/hour
                </p>

                <p>
                    Employment Type:
                    <?php echo htmlspecialchars(
                        ucwords(str_replace('_', ' ', $job['employment_type']))
                    ); ?>
                </p>

                <p>
                    Work Schedule:
                    <?php echo htmlspecialchars($job['work_schedule'] ?? 'Not specified'); ?>
                </p>

                <p>
                    Description:
                    <?php echo nl2br(htmlspecialchars($job['description'])); ?>
                </p>

                <p>Required Skills:</p>

                <?php if (!empty($skills)): ?>

                    <?php foreach ($skills as $skill): ?>

                        <span class="skill-tag">
                            <?php echo htmlspecialchars($skill); ?>
                        </span>

                    <?php endforeach; ?>

                <?php else: ?>

                    <span>No specific skills required.</span>

                <?php endif; ?>

                <a href="job-details.php?id=<?php echo urlencode($job['id']); ?>">
                    View Details
                </a>

                <hr>

            </div>

        <?php endforeach; ?>

    </div>

<?php endif; ?>

<script src="../assets/js/script.js">
</script>