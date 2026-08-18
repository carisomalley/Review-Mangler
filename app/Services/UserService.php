<?php

namespace App\Services;

use App\Database;

/**
 * Shared account-creation logic used by both bin/create_user.php (CLI,
 * preferred) and public/setup_admin.php (the browser-based fallback for
 * plans without SSH — see that file's own warning about deleting it after
 * use). Invite-only for now, no public signup form (CLAUDE.md §5, §12).
 */
class UserService
{
    /**
     * @return array{ok:bool, error:?string, id:?int}
     */
    public static function create(string $email, string $password): array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => "That doesn't look like a valid email address.", 'id' => null];
        }
        if (strlen($password) < 8) {
            return ['ok' => false, 'error' => 'Use a password with at least 8 characters.', 'id' => null];
        }

        $pdo = Database::pdo();
        $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$email]);
        if ($check->fetch()) {
            return ['ok' => false, 'error' => 'A user with that email already exists.', 'id' => null];
        }

        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, created_at) VALUES (?, ?, NOW())');
        $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);

        return ['ok' => true, 'error' => null, 'id' => (int) $pdo->lastInsertId()];
    }
}
