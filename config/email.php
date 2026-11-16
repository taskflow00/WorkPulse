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

$port = (int) $getConfigValue('MAIL_PORT', 587);

// Gmail shows app passwords with spaces (xxxx xxxx xxxx xxxx).
// Remove them so the password works either way.
$password = str_replace(' ', '', (string) $getConfigValue('MAIL_PASSWORD'));

$username = trim((string) $getConfigValue('MAIL_USERNAME'));

// Encryption: "tls" (STARTTLS, port 587) or "ssl" (SMTPS, port 465).
// If MAIL_ENCRYPTION is not set, choose it from the port.
$encryption = strtolower(
    trim((string) $getConfigValue('MAIL_ENCRYPTION', ''))
);

if (!in_array($encryption, ['tls', 'ssl'], true)) {
    $encryption = ($port === 465) ? 'ssl' : 'tls';
}

return [
    'host'       => trim((string) $getConfigValue('MAIL_HOST', 'smtp.gmail.com')),
    'port'       => $port,
    'encryption' => $encryption,
    'username'   => $username,
    'password'   => $password,

    // Gmail only sends from the logged-in account,
    // so use the SMTP username when no sender is set.
    'from_email' => trim((string) $getConfigValue('MAIL_FROM_ADDRESS', $username)),
    'from_name'  => $getConfigValue('MAIL_FROM_NAME', 'WorkPulse')
];
