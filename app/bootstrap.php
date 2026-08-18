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
ini_set('display_errors', Env::get('APP_ENV') === 'local' ? '1' : '0');
