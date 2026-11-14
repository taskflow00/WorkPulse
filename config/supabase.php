<?php

// Load environment variables from .env
$env = parse_ini_file(__DIR__ . '/../.env');

// Supabase credentials
define('SUPABASE_URL', $env['SUPABASE_URL']);
define('SUPABASE_KEY', $env['SUPABASE_KEY']);
