<?php

/**
 * Invite-only account creation for Phase 1 (CLAUDE.md §5, §12 — no public
 * signup form yet). Run once per creator you're onboarding:
 *
 *   php bin/create_user.php someone@example.com 'a strong password'
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Database;

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("This script is CLI-only.\n");
}

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php bin/create_user.php <email> <password>\n");
    exit(1);
}

[, $email, $password] = $argv;

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "That doesn't look like a valid email address.\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Use a password with at least 8 characters.\n");
    exit(1);
}

$pdo = Database::pdo();
$check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$check->execute([$email]);
if ($check->fetch()) {
    fwrite(STDERR, "A user with that email already exists.\n");
    exit(1);
}

$stmt = $pdo->prepare('INSERT INTO users (email, password_hash, created_at) VALUES (?, ?, NOW())');
$stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);

echo "Created user #{$pdo->lastInsertId()} ($email). They can log in at /login.php.\n";
