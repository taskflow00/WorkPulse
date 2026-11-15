
<?php

// Load environment variables from .env
$env = parse_ini_file(__DIR__ . '/../.env');

// Check if the .env file was loaded
if ($env === false) {
    die('Unable to load environment configuration.');
}

// Supabase credentials
define('SUPABASE_URL', $env['SUPABASE_URL']);
define('SUPABASE_KEY', $env['SUPABASE_KEY']);

// Server-side admin credential
define(
    'SUPABASE_SERVICE_ROLE_KEY',
    $env['SUPABASE_SERVICE_ROLE_KEY'] ?? ''
);
