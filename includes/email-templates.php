<?php

function invitationEmail($employerName, $jobTitle, $message = '')
{
    $employerName = htmlspecialchars($employerName, ENT_QUOTES, 'UTF-8');
    $jobTitle = htmlspecialchars($jobTitle, ENT_QUOTES, 'UTF-8');
    $message = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

    return "
        <div style='font-family: Arial, sans-serif; color: #25232A; line-height: 1.6;'>
            <h2 style='color: #B77F96;'>You've received a job invitation!</h2>
            <p>Hello!</p>
            <p><strong>{$employerName}</strong> has invited you to apply for:</p>
            <h3>{$jobTitle}</h3>
            <p>{$message}</p>
            <p>Log in to your WorkPulse account to view the invitation.</p>
            <p>— WorkPulse Team</p>
        </div>
    ";
}

function applicationStatusEmail($workerName, $jobTitle, $status)
{
    $workerName = htmlspecialchars($workerName, ENT_QUOTES, 'UTF-8');
    $jobTitle = htmlspecialchars($jobTitle, ENT_QUOTES, 'UTF-8');
    $status = htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8');

    return "
        <div style='font-family: Arial, sans-serif; color: #25232A; line-height: 1.6;'>
            <h2 style='color: #B77F96;'>Application Update</h2>
            <p>Hello {$workerName},</p>
            <p>Your application for <strong>{$jobTitle}</strong> has been updated.</p>
            <p>Current status: <strong>{$status}</strong></p>
            <p>Log in to WorkPulse to view more details.</p>
            <p>— WorkPulse Team</p>
        </div>
    ";
}
