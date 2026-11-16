<?php

require_once __DIR__ . '/includes/session.php';

// Get the page the user should return to.
$redirect = $_GET['redirect'] ?? '';

// Destroy the database session and browser cookie.
workpulse_end_session();

// Redirect to login.
header('Location: /login.php?redirect=' . urlencode($redirect));
exit;

?>
