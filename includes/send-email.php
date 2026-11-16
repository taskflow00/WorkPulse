<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';


// Keeps the last email problem so a page can show a useful message.
$GLOBALS['workpulse_email_error'] = '';


function getEmailError()
{
    return $GLOBALS['workpulse_email_error'] ?? '';
}


// Check that the SMTP settings were filled in.
function isEmailConfigured()
{
    $config = require __DIR__ . '/../config/email.php';

    return $config['host'] !== '' &&
        $config['username'] !== '' &&
        $config['password'] !== '' &&
        filter_var($config['from_email'], FILTER_VALIDATE_EMAIL);
}


function sendEmail($recipient, $subject, $message)
{
    $GLOBALS['workpulse_email_error'] = '';

    $config = require __DIR__ . '/../config/email.php';

    // Stop early with a clear reason.
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $GLOBALS['workpulse_email_error'] = 'Invalid recipient email address.';
        error_log('WorkPulse email error: invalid recipient.');
        return false;
    }

    if (!isEmailConfigured()) {
        $GLOBALS['workpulse_email_error'] =
            'SMTP is not configured. Set MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD and MAIL_FROM_ADDRESS.';
        error_log('WorkPulse email error: ' . $GLOBALS['workpulse_email_error']);
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        // SMTP settings
        $mail->isSMTP();
        $mail->Host       = $config['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['username'];
        $mail->Password   = $config['password'];
        $mail->Port       = $config['port'];
        $mail->Timeout    = 15;
        $mail->CharSet    = 'UTF-8';

        // Port 465 uses SSL. Port 587 uses STARTTLS.
        $mail->SMTPSecure = ($config['encryption'] === 'ssl')
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;

        // Sender and recipient
        $mail->setFrom(
            $config['from_email'],
            $config['from_name']
        );

        $mail->addAddress($recipient);

        // Email content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $message;

        // Plain text version for email apps that block HTML.
        $plain_text = preg_replace(
            '/<(head|style)\b.*?<\/\1>/is',
            '',
            $message
        );
        $plain_text = preg_replace('/<br\s*\/?>/i', "\n", $plain_text);
        $plain_text = preg_replace('/<\/(p|div|h[1-6]|tr)>/i', "\n", $plain_text);
        $plain_text = html_entity_decode(strip_tags($plain_text), ENT_QUOTES, 'UTF-8');
        $plain_text = preg_replace("/[ \t]+/", ' ', $plain_text);
        $plain_text = trim(preg_replace("/\n\s*\n+/", "\n\n", $plain_text));

        $mail->AltBody = $plain_text;

        $mail->send();

        return true;
    } catch (Exception $e) {
        $GLOBALS['workpulse_email_error'] = $mail->ErrorInfo;
        error_log('WorkPulse email error: ' . $mail->ErrorInfo);
        return false;
    } catch (Throwable $e) {
        $GLOBALS['workpulse_email_error'] = $e->getMessage();
        error_log('WorkPulse email error: ' . $e->getMessage());
        return false;
    }
}
