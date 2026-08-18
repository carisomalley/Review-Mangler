<?php
/** Included by every page in public/. Expects $pageTitle to be set. */
use App\Auth;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle ?? 'Review Mangler') ?> · Review Mangler</title>
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php if (Auth::userId() !== null): ?>
<nav class="topnav">
  <a href="/index.php" class="brand">Review Mangler</a>
  <a href="/add_title.php">+ Track a title</a>
  <a href="/logout.php" class="right">Log out</a>
</nav>
<?php endif; ?>
<main class="container">
