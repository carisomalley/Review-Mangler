<?php

namespace App;

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

Env::load(__DIR__ . '/../.env');

// Hostinger serves over HTTPS by default once SSL is enabled — force secure,
// http-only session cookies. See README for enabling SSL if you haven't.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (Env::get('APP_ENV', 'production') === 'production'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

date_default_timezone_set(Env::get('APP_TIMEZONE', 'UTC'));

error_reporting(E_ALL);
// Show errors for local dev AND any command-line run (bin/create_user.php,
// cron/*.php) — only a real website visitor should ever have errors hidden.
// Discovered the hard way: PHP -d display_errors=1 on the command line
// can't override an admin-locked setting on some hosts, so cron/CLI output
// was silently swallowed until this was forced here instead.
$showErrors = Env::get('APP_ENV') === 'local' || PHP_SAPI === 'cli';
ini_set('display_errors', $showErrors ? '1' : '0');
