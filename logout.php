<?php
// Get the page the user should return to
$redirect = $_GET['redirect'] ?? '';

// Destroy the current session
session_start();
session_unset();
session_destroy();

// Redirect to login and preserve the destination
header('Location: login.php?redirect=' . urlencode($redirect));
exit;
