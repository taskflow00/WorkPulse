<?php
$required_role = 'employer';
require_once '../../includes/auth.php';

$employer_id = $_SESSION['user']['id'];
$access_token = $_SESSION['access_token'];

$message = '';
$message_type = 'info';


// SEND INTERVIEW INVITATION
if (isset($_POST['send_interview_invitation'])) {

    $application_id = trim($_POST['application_id'] ?? '');
    $interview_message = trim($_POST['interview_message'] ?? '');
    $interview_at_input = trim($_POST['interview_at'] ?? '');
    $interview_type = trim($_POST['interview_type'] ?? '');
    $meeting_details = trim($_POST['meeting_details'] ?? '');

    $allowed_interview_types = [
        'online',
        'phone',
        'in_person',
        'other'
    ];

    $valid_application_id = preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        $application_id
    );

    $interview_at = null;

    if ($interview_at_input !== '') {

        try {

            $interview_date = new DateTimeImmutable(
                $interview_at_input,
                new DateTimeZone('Asia/Manila')
            );

            $current_time = new DateTimeImmutable(
                'now',
                new DateTimeZone('Asia/Manila')
            );

            if ($interview_date <= $current_time) {
                $message = 'The interview date and time must be in the future.';
            } else {
                $interview_at = $interview_date->format('Y-m-d\TH:i:sP');
            }
        } catch (Exception $error) {

            $message = 'Invalid interview date and time.';
        }
    }

    if (!$valid_application_id) {

        $message = 'Invalid application.';
    } elseif ($message === '' && $interview_at === null) {

        $message = 'Please select an interview date and time.';
    } elseif ($message === '' && !in_array($interview_type, $allowed_interview_types, true)) {

        $message = 'Please select a valid interview type.';
    } elseif (
        $message === '' &&
        in_array($interview_type, ['online', 'in_person'], true) &&
        $meeting_details === ''
    ) {

        $message = 'Please provide the meeting link or interview location.';
    }

    if ($message === '') {

        $url = SUPABASE_URL . '/rest/v1/rpc/send_application_interview_invitation';

        $data = [
            'p_application_id' => $application_id,
            'p_message' => $interview_message,
            'p_interview_at' => $interview_at,
            'p_interview_type' => $interview_type,
            'p_meeting_details' => $meeting_details
        ];

        $options = [
            'http' => [
                'method' => 'POST',
                'header' =>
                "Content-Type: application/json\r\n" .
                    "apikey: " . SUPABASE_KEY . "\r\n" .
                    "Authorization: Bearer " . $access_token . "\r\n",
                'content' => json_encode($data),
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        $response_headers = http_get_last_response_headers();

        preg_match(
            '/\s(\d{3})\s/',
            $response_headers[0] ?? '',
            $matches
        );

        $status_code = (int)($matches[1] ?? 0);

        if ($response !== false) {

            $result = json_decode($response, true);

            if (
                $status_code >= 200 &&
                $status_code < 300 &&
                !empty($result['success'])
            ) {

                if (!empty($result['already_exists'])) {

                    $message = 'An active interview invitation already exists for this applicant.';
                } else {

                    $message = 'Interview invitation sent successfully!';
                }

                $message_type = 'success';
            } else {

                $message = $result['message'] ?? 'Unable to send the interview invitation.';
                $message_type = 'error';
            }
        } else {

            $message = 'Unable to connect to Supabase.';
            $message_type = 'error';
        }
    }
}


// REJECT APPLICATION
if (isset($_POST['reject_application'])) {

    $application_id = trim($_POST['application_id'] ?? '');

    $valid_application_id = preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        $application_id
    );

    if (!$valid_application_id) {

        $message = 'Invalid application.';
        $message_type = 'error';
    } else {

        // Only applications that have not been rejected or hired can be rejected.
        // RLS must also verify that the application belongs to this employer.
        $url = SUPABASE_URL .
            '/rest/v1/applications?id=eq.' . urlencode($application_id) .
            '&status=in.(pending,shortlisted,interview,accepted)';

        $options = [
            'http' => [
                'method' => 'PATCH',
                'header' =>
                "Content-Type: application/json\r\n" .
                    "apikey: " . SUPABASE_KEY . "\r\n" .
                    "Authorization: Bearer " . $access_token . "\r\n" .
                    "Prefer: return=representation\r\n",
                'content' => json_encode([
                    'status' => 'rejected'
                ]),
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        $response_headers = http_get_last_response_headers();

        preg_match(
            '/\s(\d{3})\s/',
            $response_headers[0] ?? '',
            $matches
        );

        $status_code = (int)($matches[1] ?? 0);

        if ($response !== false) {

            $result = json_decode($response, true);

            if (
                $status_code >= 200 &&
                $status_code < 300 &&
                !empty($result)
            ) {

                $message = 'Application rejected successfully.';
                $message_type = 'success';
            } else {

                $message = 'Unable to reject the application.';
                $message_type = 'error';
            }
        } else {

            $message = 'Unable to connect to Supabase.';
            $message_type = 'error';
        }
    }
}


// RETRIEVE APPLICATIONS BELONGING TO THIS EMPLOYER
$url = SUPABASE_URL .
    '/rest/v1/applications?select=id,worker_id,job_id,application_statement,status,applied_at,resume_path,jobs!inner(title,company_name,hourly_rate,employment_type,work_schedule)' .
    '&jobs.employer_id=eq.' . urlencode($employer_id) .
    '&order=applied_at.desc';

$options = [
    'http' => [
        'method' => 'GET',
        'header' =>
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $access_token . "\r\n",
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

$applications = [];
$profiles = [];
$invitations = [];
$employment_details = [];

if ($response === false) {

    $message = 'Unable to retrieve applications.';
    $message_type = 'error';
} else {

    $response_headers = http_get_last_response_headers();

    preg_match(
        '/\s(\d{3})\s/',
        $response_headers[0] ?? '',
        $matches
    );

    $status_code = (int)($matches[1] ?? 0);

    $result = json_decode($response, true);

    if (
        $status_code >= 200 &&
        $status_code < 300 &&
        is_array($result) &&
        array_is_list($result)
    ) {

        $applications = $result;

        // GET UNIQUE WORKER IDS AND APPLICATION IDS
        $worker_ids = array_unique(
            array_column($applications, 'worker_id')
        );

        $application_ids = array_column($applications, 'id');


        // RETRIEVE WORKER PROFILES
        if (!empty($worker_ids)) {

            $profile_url = SUPABASE_URL .
                '/rest/v1/profiles?select=id,full_name,skills,experience,education,portfolio_url,preferred_hourly_rate' .
                '&id=in.(' . implode(',', $worker_ids) . ')';

            $profile_options = [
                'http' => [
                    'method' => 'GET',
                    'header' =>
                    "apikey: " . SUPABASE_KEY . "\r\n" .
                        "Authorization: Bearer " . $access_token . "\r\n",
                    'ignore_errors' => true
                ]
            ];

            $profile_context = stream_context_create($profile_options);

            $profile_response = file_get_contents(
                $profile_url,
                false,
                $profile_context
            );

            if ($profile_response !== false) {

                $profiles = json_decode($profile_response, true) ?? [];
            }
        }


        // RETRIEVE INTERVIEW INVITATIONS
        if (!empty($application_ids)) {

            $invitation_url = SUPABASE_URL .
                '/rest/v1/invitations?select=id,application_id,status,created_at,message,interview_at,interview_type,meeting_details' .
                '&employer_id=eq.' . urlencode($employer_id) .
                '&application_id=in.(' . implode(',', $application_ids) . ')' .
                '&order=created_at.desc';

            $invitation_options = [
                'http' => [
                    'method' => 'GET',
                    'header' =>
                    "apikey: " . SUPABASE_KEY . "\r\n" .
                        "Authorization: Bearer " . $access_token . "\r\n",
                    'ignore_errors' => true
                ]
            ];

            $invitation_context = stream_context_create($invitation_options);

            $invitation_response = file_get_contents(
                $invitation_url,
                false,
                $invitation_context
            );

            if ($invitation_response !== false) {

                $invitations = json_decode($invitation_response, true) ?? [];
            }


            // RETRIEVE EMPLOYMENT OFFERS BELONGING TO THIS EMPLOYER
            // We retrieve the employer's offers first, then match them below.
            $details_url = SUPABASE_URL .
                '/rest/v1/employment_offers?select=id,application_id,invitation_id,status,hourly_rate,start_date,expires_at,worker_response_message,created_at' .
                '&employer_id=eq.' . urlencode($employer_id) .
                '&order=created_at.desc&limit=500';

            $details_options = [
                'http' => [
                    'method' => 'GET',
                    'header' =>
                    "apikey: " . SUPABASE_KEY . "\r\n" .
                        "Authorization: Bearer " . $access_token . "\r\n",
                    'ignore_errors' => true
                ]
            ];

            $details_context = stream_context_create($details_options);

            $details_response = file_get_contents(
                $details_url,
                false,
                $details_context
            );

            if ($details_response !== false) {

                $employment_details = json_decode($details_response, true) ?? [];
            }
        }
    } else {

        $message = 'Something went wrong while retrieving applications.';
        $message_type = 'error';
    }
}


// MATCH APPLICATIONS WITH PROFILES
$profile_map = [];

foreach ($profiles as $profile) {

    $profile_map[$profile['id']] = $profile;
}


// MATCH APPLICATIONS WITH INTERVIEW INVITATIONS
$invitation_map = [];

foreach ($invitations as $invitation) {

    if (!empty($invitation['application_id'])) {

        $invitation_map[$invitation['application_id']][] = $invitation;
    }
}


// MATCH INVITATION IDS WITH APPLICATION IDS
$invitation_application_map = [];

foreach ($invitations as $invitation) {

    if (!empty($invitation['id']) && !empty($invitation['application_id'])) {

        $invitation_application_map[$invitation['id']] = $invitation['application_id'];
    }
}


// MATCH APPLICATIONS WITH EMPLOYMENT OFFERS
$details_map = [];

foreach ($employment_details as $details) {

    $matched_application_id = $details['application_id'] ?? null;

    // Some offers may be linked through invitation_id instead.
    if (
        empty($matched_application_id) &&
        !empty($details['invitation_id'])
    ) {

        $matched_application_id =
            $invitation_application_map[$details['invitation_id']] ?? null;
    }

    // Offers are ordered newest first, so keep the first one found.
    if (
        !empty($matched_application_id) &&
        !isset($details_map[$matched_application_id])
    ) {

        $details_map[$matched_application_id] = $details;
    }
}
?>

<h1>Applications Received</h1>

<p>Review applicants and manage your recruitment process.</p>


<?php if ($message !== ''): ?>

    <p class="<?php echo htmlspecialchars($message_type); ?>">
        <?php echo htmlspecialchars($message); ?>
    </p>

<?php endif; ?>


<?php if (empty($applications)): ?>

    <p>You haven't received any applications yet.</p>

<?php else: ?>

    <?php foreach ($applications as $application): ?>

        <?php

        $job = $application['jobs'] ?? null;

        $worker = $profile_map[$application['worker_id']] ?? null;

        $application_invitations =
            $invitation_map[$application['id']] ?? [];

        // Invitations are ordered newest first.
        $latest_invitation =
            $application_invitations[0] ?? null;

        $details =
            $details_map[$application['id']] ?? null;

        $application_status = $application['status'];

        // The employer can schedule an interview when:
        // 1. The application is pending or shortlisted.
        // 2. The previous interview invitation was declined.
        // 3. The application is still in the interview stage but has no invitation.
        $can_invite = (
            in_array(
                $application_status,
                ['pending', 'shortlisted'],
                true
            ) ||
            (
                in_array(
                    $application_status,
                    ['interview', 'accepted'],
                    true
                ) &&
                (
                    $latest_invitation === null ||
                    $latest_invitation['status'] === 'declined'
                )
            )
        );

        $can_reject = in_array(
            $application_status,
            ['pending', 'shortlisted', 'interview', 'accepted'],
            true
        );

        $can_prepare_offer = (
            $latest_invitation !== null &&
            $latest_invitation['status'] === 'accepted' &&
            $details === null &&
            !in_array(
                $application_status,
                ['rejected', 'hired'],
                true
            )
        );

        ?>

        <div class="application-card">

            <h2>
                <?php echo htmlspecialchars(
                    $worker['full_name'] ?? 'Unknown Applicant'
                ); ?>
            </h2>


            <?php if ($job): ?>

                <p>
                    Applied for:
                    <strong>
                        <?php echo htmlspecialchars($job['title'] ?? 'Untitled Job'); ?>
                    </strong>
                </p>

                <p>
                    Company:
                    <?php echo htmlspecialchars($job['company_name'] ?? 'Not specified'); ?>
                </p>

                <p>
                    Advertised Rate:
                    $<?php echo number_format((float)($job['hourly_rate'] ?? 0), 2); ?>/hour
                </p>

            <?php endif; ?>


            <?php if ($worker): ?>

                <p><strong>Skills:</strong></p>

                <p>
                    <?php
                    if (is_array($worker['skills'] ?? null)) {

                        echo htmlspecialchars(
                            implode(', ', $worker['skills'])
                        );
                    } else {

                        echo htmlspecialchars(
                            $worker['skills'] ?? 'Not specified'
                        );
                    }
                    ?>
                </p>

                <p><strong>Experience:</strong></p>

                <p>
                    <?php echo nl2br(htmlspecialchars(
                        $worker['experience'] ?? 'Not provided'
                    )); ?>
                </p>

                <p><strong>Education:</strong></p>

                <p>
                    <?php echo nl2br(htmlspecialchars(
                        $worker['education'] ?? 'Not provided'
                    )); ?>
                </p>

                <?php if (!empty($worker['preferred_hourly_rate'])): ?>

                    <p>
                        <strong>Preferred Hourly Rate:</strong>
                        $<?php echo number_format(
                                (float)$worker['preferred_hourly_rate'],
                                2
                            ); ?>/hour
                    </p>

                <?php endif; ?>

                <?php if (!empty($worker['portfolio_url'])): ?>

                    <p>
                        <a
                            href="<?php echo htmlspecialchars($worker['portfolio_url']); ?>"
                            target="_blank"
                            rel="noopener noreferrer">
                            View Portfolio
                        </a>
                    </p>

                <?php endif; ?>

            <?php endif; ?>


            <?php if (!empty($application['resume_path'])): ?>

                <p>
                    <a
                        href="view-resume.php?id=<?php echo urlencode($application['id']); ?>"
                        target="_blank"
                        rel="noopener noreferrer">
                        View Submitted Resume
                    </a>
                </p>

            <?php else: ?>

                <p>No resume submitted.</p>

            <?php endif; ?>


            <hr>

            <h3>Application Statement</h3>

            <p>
                <?php echo nl2br(htmlspecialchars(
                    $application['application_statement'] ?? 'No statement provided.'
                )); ?>
            </p>

            <p>
                <strong>Applied:</strong>
                <?php echo htmlspecialchars(
                    date('M d, Y', strtotime($application['applied_at']))
                ); ?>
            </p>


            <!-- APPLICATION STATUS -->
            <p>
                <strong>Application Status:</strong>

                <?php

                $status_labels = [
                    'pending' => 'Pending Review',
                    'shortlisted' => 'Shortlisted',
                    'interview' => 'Interview Process',
                    'accepted' => 'Proceeding to Employment',
                    'rejected' => 'Rejected',
                    'hired' => 'Hired'
                ];

                echo htmlspecialchars(
                    $status_labels[$application_status] ?? ucfirst($application_status)
                );

                ?>
            </p>


            <!-- EMPLOYER ACTIONS -->
            <?php if ($can_reject): ?>

                <form method="POST">

                    <input
                        type="hidden"
                        name="application_id"
                        value="<?php echo htmlspecialchars($application['id']); ?>">

                    <button
                        type="submit"
                        name="reject_application"
                        onclick="return confirm('Are you sure you want to reject this applicant?');">
                        Reject Applicant
                    </button>

                </form>

            <?php endif; ?>


            <hr>

            <!-- INTERVIEW HISTORY -->
            <h3>Interview Invitations</h3>

            <?php if (empty($application_invitations)): ?>

                <p>No interview invitation sent yet.</p>

            <?php else: ?>

                <?php foreach ($application_invitations as $invitation): ?>

                    <div class="invitation-history">

                        <p>
                            <strong>
                                <?php

                                $invitation_labels = [
                                    'pending' => 'Awaiting Worker Response',
                                    'accepted' => 'Interview Invitation Accepted',
                                    'declined' => 'Interview Invitation Declined'
                                ];

                                echo htmlspecialchars(
                                    $invitation_labels[$invitation['status']] ??
                                        ucfirst($invitation['status'])
                                );

                                ?>
                            </strong>
                        </p>


                        <?php if (!empty($invitation['interview_at'])): ?>

                            <p>
                                <strong>Interview Schedule:</strong>

                                <?php echo htmlspecialchars(
                                    date(
                                        'M d, Y - h:i A',
                                        strtotime($invitation['interview_at'])
                                    )
                                ); ?>
                            </p>

                        <?php endif; ?>


                        <?php if (!empty($invitation['interview_type'])): ?>

                            <p>
                                <strong>Interview Type:</strong>

                                <?php

                                $interview_labels = [
                                    'online' => 'Online',
                                    'phone' => 'Phone Call',
                                    'in_person' => 'In Person',
                                    'other' => 'Other'
                                ];

                                echo htmlspecialchars(
                                    $interview_labels[$invitation['interview_type']] ??
                                        $invitation['interview_type']
                                );

                                ?>
                            </p>

                        <?php endif; ?>


                        <?php if (!empty($invitation['meeting_details'])): ?>

                            <p>
                                <strong>Meeting Details:</strong>

                                <?php echo nl2br(htmlspecialchars(
                                    $invitation['meeting_details']
                                )); ?>
                            </p>

                        <?php endif; ?>


                        <?php if (!empty($invitation['message'])): ?>

                            <p>
                                <strong>Invitation Message:</strong>

                                <?php echo nl2br(htmlspecialchars(
                                    $invitation['message']
                                )); ?>
                            </p>

                        <?php endif; ?>


                        <p>
                            <small>
                                Sent:
                                <?php echo htmlspecialchars(
                                    date(
                                        'M d, Y',
                                        strtotime($invitation['created_at'])
                                    )
                                ); ?>
                            </small>
                        </p>

                    </div>

                    <hr>

                <?php endforeach; ?>

            <?php endif; ?>


            <!-- PREPARE EMPLOYMENT DETAILS -->
            <?php if ($can_prepare_offer): ?>

                <h3>Next Step</h3>

                <p>
                    The worker accepted your interview invitation.
                    You can now prepare the final employment details.
                </p>

                <a href="send-offer.php?id=<?php echo urlencode($application['id']); ?>">
                    Prepare Employment Details
                </a>

                <hr>

            <?php endif; ?>


            <!-- EXISTING EMPLOYMENT OFFER -->
            <?php if ($details !== null): ?>

                <h3>Employment Details Confirmation</h3>

                <p>
                    <strong>Agreed Hourly Rate:</strong>
                    $<?php echo number_format(
                            (float)$details['hourly_rate'],
                            2
                        ); ?>/hour
                </p>

                <p>
                    <strong>Start Date:</strong>

                    <?php echo !empty($details['start_date'])
                        ? htmlspecialchars(date('M d, Y', strtotime($details['start_date'])))
                        : 'Not specified'; ?>
                </p>


                <?php if ($details['status'] === 'pending'): ?>

                    <p>
                        Waiting for the worker to confirm the agreed employment details.
                    </p>

                <?php elseif ($details['status'] === 'revision_requested'): ?>

                    <p><strong>Worker requested changes:</strong></p>

                    <p>
                        <?php echo nl2br(htmlspecialchars(
                            $details['worker_response_message'] ?? 'No explanation provided.'
                        )); ?>
                    </p>

                    <a href="send-offer.php?id=<?php echo urlencode($application['id']); ?>">
                        Revise Employment Details
                    </a>

                <?php elseif ($details['status'] === 'accepted'): ?>

                    <p>Worker confirmed the employment details.</p>

                <?php elseif ($details['status'] === 'declined'): ?>

                    <p>Worker declined the proposed employment details.</p>

                <?php endif; ?>

                <hr>

            <?php endif; ?>


            <!-- SCHEDULE INTERVIEW -->
            <?php if ($can_invite): ?>

                <h3>
                    <?php
                    if (
                        $latest_invitation !== null &&
                        $latest_invitation['status'] === 'declined'
                    ) {
                        echo 'Schedule Another Interview';
                    } else {
                        echo 'Schedule Interview';
                    }
                    ?>
                </h3>

                <p>
                    Choose an interview schedule and provide the necessary details
                    for the applicant.
                </p>

                <form method="POST">

                    <input
                        type="hidden"
                        name="application_id"
                        value="<?php echo htmlspecialchars($application['id']); ?>">

                    <label>Interview Date and Time</label>

                    <input
                        type="datetime-local"
                        name="interview_at"
                        required>


                    <label>Interview Type</label>

                    <select name="interview_type" required>

                        <option value="">Choose interview type</option>

                        <option value="online">Online Meeting</option>

                        <option value="phone">Phone Call</option>

                        <option value="in_person">In Person</option>

                        <option value="other">Other</option>

                    </select>


                    <label>Meeting Link or Location</label>

                    <textarea
                        name="meeting_details"
                        rows="3"
                        placeholder="Enter the meeting link, address, or call details."></textarea>


                    <label>Personal Message</label>

                    <textarea
                        name="interview_message"
                        rows="4"
                        placeholder="Tell the applicant about the interview and what they should prepare."></textarea>


                    <button type="submit" name="send_interview_invitation">
                        Send Interview Invitation
                    </button>

                </form>

            <?php elseif (
                $latest_invitation !== null &&
                $latest_invitation['status'] === 'pending'
            ): ?>

                <p>
                    <strong>Waiting for the worker:</strong>
                    Your interview invitation is waiting for the worker's response.
                </p>

            <?php endif; ?>


            <hr>

        </div>

    <?php endforeach; ?>

<?php endif; ?>