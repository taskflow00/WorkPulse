<?php

// Load .env for local development.
// On Vercel, environment variables are provided by the platform.
$envFile = __DIR__ . '/../.env';
$env = is_file($envFile) ? parse_ini_file($envFile) : [];

if (!is_array($env)) {
    $env = [];
}

// Read from the server environment first, then use .env as fallback.
$getConfigValue = function ($key, $default = '') use ($env) {
    $value = getenv($key);

    if ($value !== false && $value !== '') {
        return $value;
    }

    return $env[$key] ?? $default;
};

// Supabase credentials
define('SUPABASE_URL', $getConfigValue('SUPABASE_URL'));
define('SUPABASE_KEY', $getConfigValue('SUPABASE_KEY'));

// Server-side admin credential.
// Keep this key on the server only.
define(
    'SUPABASE_SERVICE_ROLE_KEY',
    $getConfigValue('SUPABASE_SERVICE_ROLE_KEY')
);

// Stop early if the required Supabase configuration is missing.
if (SUPABASE_URL === '' || SUPABASE_KEY === '') {
    die('Supabase environment variables are not configured.');
}
