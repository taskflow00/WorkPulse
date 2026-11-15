<?php
$required_role = 'employer';
require_once '../../includes/auth.php';

$application_id = $_GET['id'] ?? '';

// Validate application ID
if (!preg_match(
    '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
    $application_id
)) {
    exit('Invalid application.');
}

// Retrieve the application
$url = SUPABASE_URL .
    '/rest/v1/applications?select=id,resume_path' .
    '&id=eq.' . urlencode($application_id);

$options = [
    'http' => [
        'method' => 'GET',
        'header' => 
        "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $_SESSION['access_token'] . "\r\n",
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

if ($response === false) {
    exit('Unable to retrieve the application.');
}

$result = json_decode($response, true);

preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $matches);
$status_code = (int)($matches[1] ?? 0);

// RLS should only return applications belonging to this employer
if (
    $status_code !== 200 ||
    !is_array($result) ||
    empty($result[0]['resume_path'])
) {
    exit('Resume not found or you are not authorized to access it.');
}

$resume_path = $result[0]['resume_path'];

// Encode each path segment while preserving folder separators
$encoded_path = implode(
    '/',
    array_map('rawurlencode', explode('/', $resume_path))
);

// Request a temporary signed URL
$url = SUPABASE_URL .
    '/storage/v1/object/sign/resumes/' . $encoded_path;

$data = [
    'expiresIn' => 300
];

$options = [
    'http' => [
        'method' => 'POST',
        'header' =>
        "Content-Type: application/json\r\n" .
            "apikey: " . SUPABASE_KEY . "\r\n" .
            "Authorization: Bearer " . $_SESSION['access_token'] . "\r\n",
        'content' => json_encode($data),
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

if ($response === false) {
    exit('Unable to generate the resume link.');
}

$result = json_decode($response, true);

preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $matches);
$status_code = (int)($matches[1] ?? 0);

if (
    $status_code < 200 ||
    $status_code >= 300 ||
    empty($result['signedURL'])
) {
    exit('Unable to access this resume.');
}

// Build the complete signed URL
$signed_url = $result['signedURL'];

if (!preg_match('/^https:\/\//i', $signed_url)) {
    $signed_url = rtrim(SUPABASE_URL, '/') .
        '/storage/v1' . $signed_url;
}

// Redirect to the temporary resume link
header('Location: ' . $signed_url);
exit;
