<?php

// Load .env for local development.
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

return [
    'host'       => $getConfigValue('MAIL_HOST', 'smtp.gmail.com'),
    'port'       => (int) $getConfigValue('MAIL_PORT', 587),
    'username'   => $getConfigValue('MAIL_USERNAME'),
    'password'   => $getConfigValue('MAIL_PASSWORD'),
    'from_email' => $getConfigValue('MAIL_FROM_ADDRESS'),
    'from_name'  => $getConfigValue('MAIL_FROM_NAME', 'WorkPulse')
];
