
<?php

// Load environment variables from .env
$env = parse_ini_file(__DIR__ . '/../.env');

return [
    'host'       => $env['MAIL_HOST'] ?? 'smtp.gmail.com',
    'port'       => (int) ($env['MAIL_PORT'] ?? 587),
    'username'   => $env['MAIL_USERNAME'] ?? '',
    'password'   => $env['MAIL_PASSWORD'] ?? '',
    'from_email' => $env['MAIL_FROM_ADDRESS'] ?? '',
    'from_name'  => $env['MAIL_FROM_NAME'] ?? 'WorkPulse'
];
