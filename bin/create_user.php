<?php

/**
 * Invite-only account creation for Phase 1 (CLAUDE.md §5, §12 — no public
 * signup form yet). Run once per creator you're onboarding:
 *
 *   php bin/create_user.php someone@example.com 'a strong password'
 *
 * No SSH access on your plan? Use public/setup_admin.php instead — see the
 * big warning at the top of that file before you do.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Services\UserService;

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("This script is CLI-only. See public/setup_admin.php for a browser-based fallback.\n");
}

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php bin/create_user.php <email> <password>\n");
    exit(1);
}

[, $email, $password] = $argv;

// Wrapped explicitly rather than relying on php.ini's display_errors — some
// hosts lock that setting server-side in a way even `php -d display_errors=1`
// can't override, which otherwise means a crash here prints nothing at all.
try {
    $result = UserService::create($email, $password);
    if (!$result['ok']) {
        fwrite(STDERR, $result['error'] . "\n");
        exit(1);
    }
    echo "Created user #{$result['id']} ($email). They can log in at /login.php.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
