<?php

require __DIR__ . '/../app/bootstrap.php';

use App\Auth;

if (Auth::userId() !== null) {
    header('Location: /index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (Auth::attempt($email, $password)) {
        header('Location: /index.php');
        exit;
    }
    $error = 'Wrong email or password.';
}

$pageTitle = 'Log in';
require __DIR__ . '/_header.php';
?>
<div class="card auth-card">
  <h1>Review Mangler</h1>
  <p class="muted">Quiet, private feedback tracking for your own work.</p>

  <?php if ($error): ?>
    <p class="alert"><?= htmlspecialchars($error) ?></p>
  <?php endif; ?>

  <form method="post">
    <label>Email
      <input type="email" name="email" required autofocus>
    </label>
    <label>Password
      <input type="password" name="password" required>
    </label>
    <button type="submit">Log in</button>
  </form>
  <p class="muted small">Accounts are created by invite for now — see README if you need one.</p>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
