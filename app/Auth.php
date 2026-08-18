<?php

namespace App;

/**
 * Deliberately minimal, single/invite-only-user auth for the Phase 1 MVP.
 * Accounts are created with bin/create_user.php, not a signup form — see
 * CLAUDE.md §12 (self-serve multi-tenant signup is Phase 4).
 */
class Auth
{
    public static function attempt(string $email, string $password): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, password_hash FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            // Constant-ish time regardless of whether the email existed.
            password_verify($password, '$2y$10$invalidinvalidinvalidinvalidinvalidinva');
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function userId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    public static function requireLogin(): int
    {
        $id = self::userId();
        if ($id === null) {
            header('Location: /login.php');
            exit;
        }
        return $id;
    }
}
