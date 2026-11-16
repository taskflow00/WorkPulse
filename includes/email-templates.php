<?php

// ============================================
// WORKPULSE EMAIL TEMPLATES
// Professional SaaS Email Design
// ============================================


// ============================================
// 1. JOB INVITATION EMAIL
// ============================================

function invitationEmail($employerName, $jobTitle, $message = '')
{
    $employerName = htmlspecialchars($employerName, ENT_QUOTES, 'UTF-8');
    $jobTitle = htmlspecialchars($jobTitle, ENT_QUOTES, 'UTF-8');

    $message = trim($message);
    $personalMessage = '';

    if ($message !== '') {

        $safeMessage = nl2br(
            htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        );

        $personalMessage = "
            <div style='
                background-color: #F8F6F7;
                border-left: 4px solid #B77F96;
                border-radius: 6px;
                padding: 18px 20px;
                margin: 24px 0;
            '>
                <p style='
                    margin: 0 0 10px;
                    color: #77717A;
                    font-size: 12px;
                    font-weight: bold;
                    text-transform: uppercase;
                    letter-spacing: 1px;
                '>
                    Personal Message
                </p>

                <p style='
                    margin: 0;
                    color: #45404A;
                    font-size: 14px;
                    line-height: 1.8;
                '>
                    {$safeMessage}
                </p>
            </div>
        ";
    }

    return "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <meta name='color-scheme' content='light'>
        <title>Job Invitation - WorkPulse</title>
    </head>

    <body style='
        margin: 0;
        padding: 0;
        background-color: #F4F2F4;
        font-family: Arial, Helvetica, sans-serif;
        color: #29252D;
    '>

        <div style='display:none;max-height:0;overflow:hidden;opacity:0;'>
            You have received a new job invitation on WorkPulse.
        </div>

        <table role='presentation' width='100%' cellspacing='0'
            cellpadding='0' border='0'
            style='background-color:#F4F2F4;padding:35px 15px;'>

            <tr>
                <td align='center'>

                    <table role='presentation' width='100%'
                        cellspacing='0' cellpadding='0' border='0'
                        style='max-width:600px;background-color:#FFFFFF;border-radius:12px;overflow:hidden;'>

                        <tr>
                            <td style='background-color:#B77F96;padding:28px 35px;'>
                                <h1 style='margin:0;color:#FFFFFF;font-size:25px;font-weight:bold;letter-spacing:-0.5px;'>
                                    WorkPulse
                                </h1>

                                <p style='margin:5px 0 0;color:#FCEEF3;font-size:12px;letter-spacing:1px;'>
                                    CONNECT. WORK. GROW.
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <td style='padding:38px 35px;'>

                                <span style='display:inline-block;background-color:#F8EDF2;color:#A36580;padding:7px 12px;border-radius:20px;font-size:11px;font-weight:bold;letter-spacing:0.5px;'>
                                    NEW OPPORTUNITY
                                </span>

                                <h2 style='margin:22px 0 12px;color:#29252D;font-size:25px;line-height:1.3;'>
                                    You've been invited!
                                </h2>

                                <p style='margin:0;color:#77717A;font-size:14px;line-height:1.8;'>
                                    Hello!
                                    <br><br>
                                    Great news! <strong style='color:#29252D;'>{$employerName}</strong>
                                    would like you to consider joining their team.
                                    You've received an invitation for the following position:
                                </p>

                                <div style='margin-top:25px;padding:22px;background-color:#FCFAFB;border:1px solid #EFE8EC;border-radius:9px;'>

                                    <p style='margin:0 0 9px;color:#A36580;font-size:11px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;'>
                                        INVITATION FOR
                                    </p>

                                    <h3 style='margin:0;color:#302A33;font-size:20px;line-height:1.4;'>
                                        {$jobTitle}
                                    </h3>

                                    <p style='margin:10px 0 0;color:#89818B;font-size:13px;'>
                                        Invited by {$employerName}
                                    </p>

                                </div>

                                {$personalMessage}

                                <div style='margin-top:28px;padding-top:23px;border-top:1px solid #F0ECF0;'>

                                    <h3 style='margin:0 0 10px;color:#302A33;font-size:16px;'>
                                        What's next?
                                    </h3>

                                    <p style='margin:0;color:#77717A;font-size:14px;line-height:1.8;'>
                                        Log in to your WorkPulse account and visit your
                                        invitations to review the opportunity and decide
                                        whether you'd like to accept or decline.
                                    </p>

                                </div>

                                <p style='margin:28px 0 0;color:#77717A;font-size:14px;line-height:1.7;'>
                                    We hope this opportunity helps you take the next
                                    step in your professional journey!
                                </p>

                            </td>
                        </tr>

                        <tr>
                            <td style='background-color:#FAF8F9;border-top:1px solid #F0ECF0;padding:24px 35px;'>

                                <p style='margin:0;color:#B77F96;font-size:15px;font-weight:bold;'>
                                    WorkPulse
                                </p>

                                <p style='margin:7px 0 0;color:#96909A;font-size:12px;line-height:1.7;'>
                                    Connecting employers with skilled professionals.
                                    <br>
                                    This is an automated notification. Please do not
                                    reply directly to this email.
                                </p>

                                <p style='margin:18px 0 0;color:#B1ABB3;font-size:11px;'>
                                    &copy; " . date('Y') . " WorkPulse. All rights reserved.
                                </p>

                            </td>
                        </tr>

                    </table>

                </td>
            </tr>
        </table>

    </body>
    </html>
    ";
}


// ============================================
// 2. APPLICATION STATUS EMAIL
// ============================================

function applicationStatusEmail($workerName, $jobTitle, $status)
{
    $workerName = htmlspecialchars($workerName, ENT_QUOTES, 'UTF-8');
    $jobTitle = htmlspecialchars($jobTitle, ENT_QUOTES, 'UTF-8');

    $originalStatus = strtolower(trim($status));

    $safeStatus = htmlspecialchars(
        ucfirst($originalStatus),
        ENT_QUOTES,
        'UTF-8'
    );

    $statusColor = '#A36580';
    $statusBackground = '#F8EDF2';

    if (in_array($originalStatus, ['accepted', 'approved'], true)) {
        $statusColor = '#21865B';
        $statusBackground = '#EAF7F0';
    } elseif (in_array($originalStatus, ['rejected', 'declined'], true)) {
        $statusColor = '#C34F59';
        $statusBackground = '#FCEEEF';
    }

    return "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <meta name='color-scheme' content='light'>
        <title>Application Update - WorkPulse</title>
    </head>

    <body style='
        margin:0;
        padding:0;
        background-color:#F4F2F4;
        font-family:Arial,Helvetica,sans-serif;
        color:#29252D;
    '>

        <div style='display:none;max-height:0;overflow:hidden;opacity:0;'>
            Your WorkPulse job application has a new update.
        </div>

        <table role='presentation' width='100%' cellspacing='0'
            cellpadding='0' border='0'
            style='background-color:#F4F2F4;padding:35px 15px;'>

            <tr>
                <td align='center'>

                    <table role='presentation' width='100%'
                        cellspacing='0' cellpadding='0' border='0'
                        style='max-width:600px;background-color:#FFFFFF;border-radius:12px;overflow:hidden;'>

                        <tr>
                            <td style='background-color:#B77F96;padding:28px 35px;'>
                                <h1 style='margin:0;color:#FFFFFF;font-size:25px;font-weight:bold;'>
                                    WorkPulse
                                </h1>

                                <p style='margin:5px 0 0;color:#FCEEF3;font-size:12px;letter-spacing:1px;'>
                                    CONNECT. WORK. GROW.
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <td style='padding:38px 35px;'>

                                <span style='display:inline-block;background-color:#F8EDF2;color:#A36580;padding:7px 12px;border-radius:20px;font-size:11px;font-weight:bold;letter-spacing:0.5px;'>
                                    APPLICATION UPDATE
                                </span>

                                <h2 style='margin:22px 0 12px;color:#29252D;font-size:25px;'>
                                    Hello, {$workerName}!
                                </h2>

                                <p style='margin:0;color:#77717A;font-size:14px;line-height:1.8;'>
                                    There's an update regarding your job application.
                                    Here are the details:
                                </p>

                                <div style='margin-top:25px;padding:22px;background-color:#FCFAFB;border:1px solid #EFE8EC;border-radius:9px;'>

                                    <p style='margin:0 0 9px;color:#A36580;font-size:11px;font-weight:bold;letter-spacing:1px;'>
                                        POSITION
                                    </p>

                                    <h3 style='margin:0;color:#302A33;font-size:19px;line-height:1.4;'>
                                        {$jobTitle}
                                    </h3>

                                    <div style='margin-top:20px;padding-top:17px;border-top:1px solid #EFE8EC;'>

                                        <p style='margin:0 0 10px;color:#77717A;font-size:12px;font-weight:bold;text-transform:uppercase;letter-spacing:0.7px;'>
                                            Current Status
                                        </p>

                                        <span style='display:inline-block;padding:9px 15px;border-radius:6px;background-color:{$statusBackground};color:{$statusColor};font-size:13px;font-weight:bold;'>
                                            {$safeStatus}
                                        </span>

                                    </div>
                                </div>

                                <div style='margin-top:28px;padding-top:23px;border-top:1px solid #F0ECF0;'>

                                    <h3 style='margin:0 0 10px;color:#302A33;font-size:16px;'>
                                        Stay updated
                                    </h3>

                                    <p style='margin:0;color:#77717A;font-size:14px;line-height:1.8;'>
                                        Log in to your WorkPulse account to review
                                        your application and check for any further updates.
                                    </p>

                                </div>

                            </td>
                        </tr>

                        <tr>
                            <td style='background-color:#FAF8F9;border-top:1px solid #F0ECF0;padding:24px 35px;'>

                                <p style='margin:0;color:#B77F96;font-size:15px;font-weight:bold;'>
                                    WorkPulse
                                </p>

                                <p style='margin:7px 0 0;color:#96909A;font-size:12px;line-height:1.7;'>
                                    Connecting employers with skilled professionals.
                                    <br>
                                    This is an automated notification. Please do not
                                    reply directly to this email.
                                </p>

                                <p style='margin:18px 0 0;color:#B1ABB3;font-size:11px;'>
                                    &copy; " . date('Y') . " WorkPulse. All rights reserved.
                                </p>

                            </td>
                        </tr>

                    </table>

                </td>
            </tr>
        </table>

    </body>
    </html>
    ";
}


// ============================================
// 3. INTERVIEW INVITATION EMAIL
// ============================================

function interviewInvitationEmail(
    $workerName,
    $jobTitle,
    $interviewAt,
    $interviewType,
    $meetingDetails,
    $message = '',
    $meetingLink = ''
) {

    $workerName = htmlspecialchars(
        $workerName,
        ENT_QUOTES,
        'UTF-8'
    );

    $jobTitle = htmlspecialchars(
        $jobTitle,
        ENT_QUOTES,
        'UTF-8'
    );

    $interviewLabels = [
        'online' => 'Online Meeting',
        'phone' => 'Phone Call',
        'in_person' => 'In Person',
        'other' => 'Other'
    ];

    $interviewType = $interviewLabels[$interviewType] ?? 'Other';

    $interviewType = htmlspecialchars(
        $interviewType,
        ENT_QUOTES,
        'UTF-8'
    );


    // Format interview date.

    try {

        $date = new DateTimeImmutable($interviewAt);

        $formattedDate = $date->setTimezone(
            new DateTimeZone('Asia/Manila')
        )->format('F d, Y - h:i A');
    } catch (Exception $e) {

        $formattedDate = $interviewAt;
    }

    $formattedDate = htmlspecialchars(
        $formattedDate,
        ENT_QUOTES,
        'UTF-8'
    );


    // Safely display optional meeting notes.

    $meetingDetails = trim((string)$meetingDetails);

    $meetingDetailsSection = '';

    if ($meetingDetails !== '') {

        $safeMeetingDetails = nl2br(
            htmlspecialchars(
                $meetingDetails,
                ENT_QUOTES,
                'UTF-8'
            )
        );

        $meetingDetailsSection = "
            <div style='margin-top:18px;'>

                <p style='margin:0 0 6px;color:#89818B;font-size:12px;'>
                    ADDITIONAL MEETING NOTES
                </p>

                <p style='margin:0;color:#45404A;font-size:14px;line-height:1.7;'>
                    {$safeMeetingDetails}
                </p>

            </div>
        ";
    }


    // Validate and prepare the dummy meeting link.

    $meetingLinkSection = '';

    if (
        is_string($meetingLink) &&
        preg_match(
            '/^https:\/\/meet\.google\.com\/[A-Za-z0-9-]+$/',
            $meetingLink
        )
    ) {

        $safeMeetingLink = htmlspecialchars(
            $meetingLink,
            ENT_QUOTES,
            'UTF-8'
        );

        $meetingLinkSection = "
            <div style='
                margin-top:22px;
                padding:20px;
                background-color:#F8EDF2;
                border:1px solid #F0DFE7;
                border-radius:9px;
                text-align:center;
            '>

                <p style='
                    margin:0 0 12px;
                    color:#A36580;
                    font-size:11px;
                    font-weight:bold;
                    letter-spacing:1px;
                '>
                    ONLINE INTERVIEW
                </p>

                <a
                    href='{$safeMeetingLink}'
                    target='_blank'
                    style='
                        display:inline-block;
                        padding:12px 24px;
                        background-color:#B77F96;
                        color:#FFFFFF;
                        text-decoration:none;
                        border-radius:7px;
                        font-size:14px;
                        font-weight:bold;
                    '
                >
                    Join Interview
                </a>

                <p style='
                    margin:13px 0 0;
                    color:#89818B;
                    font-size:11px;
                    line-height:1.6;
                '>
                    Demonstration link only. This does not open a real meeting room.
                </p>

            </div>
        ";
    }


    // Optional personal message.

    $messageSection = '';

    if (trim((string)$message) !== '') {

        $safeMessage = nl2br(
            htmlspecialchars(
                $message,
                ENT_QUOTES,
                'UTF-8'
            )
        );

        $messageSection = "
            <div style='
                background-color:#F8F6F7;
                border-left:4px solid #B77F96;
                border-radius:6px;
                padding:18px;
                margin-top:22px;
            '>

                <p style='
                    margin:0 0 8px;
                    color:#77717A;
                    font-size:12px;
                    font-weight:bold;
                    text-transform:uppercase;
                '>
                    Message from Employer
                </p>

                <p style='
                    margin:0;
                    color:#45404A;
                    font-size:14px;
                    line-height:1.8;
                '>
                    {$safeMessage}
                </p>

            </div>
        ";
    }


    return "
    <!DOCTYPE html>
    <html lang='en'>

    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <meta name='color-scheme' content='light'>
        <title>Interview Invitation - WorkPulse</title>
    </head>

    <body style='
        margin:0;
        padding:0;
        background-color:#F4F2F4;
        font-family:Arial,Helvetica,sans-serif;
        color:#29252D;
    '>

        <table role='presentation' width='100%' cellspacing='0'
            cellpadding='0' border='0'
            style='background-color:#F4F2F4;padding:35px 15px;'>

            <tr>
                <td align='center'>

                    <table role='presentation' width='100%'
                        cellspacing='0' cellpadding='0' border='0'
                        style='
                            max-width:600px;
                            background-color:#FFFFFF;
                            border-radius:12px;
                            overflow:hidden;
                        '>

                        <tr>
                            <td style='background-color:#B77F96;padding:28px 35px;'>

                                <h1 style='margin:0;color:#FFFFFF;font-size:25px;'>
                                    WorkPulse
                                </h1>

                                <p style='margin:5px 0 0;color:#FCEEF3;font-size:12px;letter-spacing:1px;'>
                                    CONNECT. WORK. GROW.
                                </p>

                            </td>
                        </tr>

                        <tr>
                            <td style='padding:38px 35px;'>

                                <span style='
                                    display:inline-block;
                                    background-color:#F8EDF2;
                                    color:#A36580;
                                    padding:7px 12px;
                                    border-radius:20px;
                                    font-size:11px;
                                    font-weight:bold;
                                '>
                                    INTERVIEW INVITATION
                                </span>

                                <h2 style='margin:22px 0 12px;color:#29252D;font-size:25px;'>
                                    You're invited to an interview!
                                </h2>

                                <p style='color:#77717A;font-size:14px;line-height:1.8;'>
                                    Hello <strong>{$workerName}</strong>,
                                    <br><br>
                                    An employer would like to meet with you
                                    regarding your application.
                                </p>

                                <div style='
                                    margin-top:25px;
                                    padding:22px;
                                    background-color:#FCFAFB;
                                    border:1px solid #EFE8EC;
                                    border-radius:9px;
                                '>

                                    <p style='margin:0 0 8px;color:#A36580;font-size:11px;font-weight:bold;'>
                                        POSITION
                                    </p>

                                    <h3 style='margin:0;color:#302A33;font-size:20px;'>
                                        {$jobTitle}
                                    </h3>

                                    <div style='margin-top:20px;padding-top:16px;border-top:1px solid #EFE8EC;'>

                                        <p style='margin:0 0 6px;color:#89818B;font-size:12px;'>
                                            INTERVIEW DATE AND TIME
                                        </p>

                                        <p style='margin:0;color:#302A33;font-size:15px;font-weight:bold;'>
                                            {$formattedDate}
                                        </p>

                                        <p style='margin:18px 0 6px;color:#89818B;font-size:12px;'>
                                            INTERVIEW TYPE
                                        </p>

                                        <p style='margin:0;color:#302A33;font-size:14px;font-weight:bold;'>
                                            {$interviewType}
                                        </p>

                                        {$meetingDetailsSection}

                                    </div>
                                </div>

                                {$meetingLinkSection}

                                {$messageSection}

                                <p style='margin-top:26px;color:#77717A;font-size:14px;line-height:1.8;'>
                                    Please log in to your WorkPulse account
                                    to review the invitation and respond.
                                    Make sure to take note of your interview schedule.
                                </p>

                            </td>
                        </tr>

                        <tr>
                            <td style='
                                background-color:#FAF8F9;
                                border-top:1px solid #F0ECF0;
                                padding:24px 35px;
                            '>

                                <p style='margin:0;color:#B77F96;font-size:15px;font-weight:bold;'>
                                    WorkPulse
                                </p>

                                <p style='margin:7px 0 0;color:#96909A;font-size:12px;line-height:1.7;'>
                                    Connecting employers with skilled professionals.
                                    <br>
                                    This is an automated notification. Please do not reply directly.
                                </p>

                                <p style='margin:18px 0 0;color:#B1ABB3;font-size:11px;'>
                                    &copy; " . date('Y') . " WorkPulse. All rights reserved.
                                </p>

                            </td>
                        </tr>

                    </table>

                </td>
            </tr>
        </table>

    </body>
    </html>
    ";
}
