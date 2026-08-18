<?php

/**
 * ============================================================================
 *  ONE-TIME BROWSER FALLBACK — DELETE THIS FILE AS SOON AS YOU'RE DONE.
 * ============================================================================
 * Only exists for Hostinger plans without SSH access, where
 * bin/create_user.php can't be run from a terminal. It creates exactly one
 * account, guarded by SETUP_TOKEN in .env, then you delete it (File Manager
 * → find this file in public/ → delete). Leaving it on a live site means
 * anyone who guesses/finds the URL and your token could try to hit it —
 * the token is the only thing standing in the way, so remove the file
 * once you don't need it. If SSH ever becomes available on your plan, use
 * bin/create_user.php instead and skip this entirely.
 * ============================================================================
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Env;
use App\Services\UserService;

$configuredToken = Env::get('SETUP_TOKEN', '');
$suppliedToken = $_POST['token'] ?? $_GET['token'] ?? '';

$tokenOk = $configuredToken !== '' && hash_equals($configuredToken, (string) $suppliedToken);

$message = null;
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$tokenOk) {
        http_response_code(403);
        $message = 'Wrong or missing token.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $result = UserService::create($email, $password);
        if ($result['ok']) {
            $success = true;
            $message = "Created user #{$result['id']} ($email). They can log in at /login.php now.";
        } else {
            $message = $result['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>One-time setup — delete after use</title>
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<main class="container">
  <div class="card auth-card">
    <h1>Create account</h1>
    <p class="alert">Delete this file (public/setup_admin.php) right after you use it.</p>

    <?php if ($message): ?>
      <p class="<?= $success ? 'notice' : 'alert' ?>"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <?php if (!$success): ?>
      <form method="post">
        <label>Setup token (from .env's SETUP_TOKEN)
          <input type="text" name="token" required value="<?= htmlspecialchars((string) $suppliedToken) ?>">
        </label>
        <label>Email
          <input type="email" name="email" required>
        </label>
        <label>Password
          <input type="password" name="password" required minlength="8">
        </label>
        <button type="submit">Create account</button>
      </form>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
