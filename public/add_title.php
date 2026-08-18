<?php

require __DIR__ . '/../app/bootstrap.php';

use App\Auth;
use App\Services\TitleService;

$userId = Auth::requireLogin();
$titleService = new TitleService();

$type = $_POST['type'] ?? $_GET['type'] ?? 'film';
$type = in_array($type, ['film', 'book'], true) ? $type : 'film';
$query = trim($_POST['query'] ?? $_GET['query'] ?? '');

$results = [];
$searchError = null;

// Step 2: user picked a specific result to track.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['track_external_id'])) {
    $result = [
        'external_id' => $_POST['track_external_id'],
        'name' => $_POST['track_name'],
        'creator_name' => $_POST['track_creator_name'] ?: null,
        'year' => $_POST['track_year'] ?: null,
        'poster_url' => $_POST['track_poster_url'] ?: null,
    ];
    $trackedTitleId = $titleService->trackForUser($userId, $type, $result);
    header('Location: /title.php?id=' . $trackedTitleId);
    exit;
}

// Step 1: search.
if ($query !== '') {
    try {
        $results = $titleService->search($type, $query);
    } catch (\Throwable $e) {
        error_log($e->getMessage());
        $searchError = "Search failed — the {$type} lookup service may be unavailable right now.";
    }
}

$pageTitle = 'Track a title';
require __DIR__ . '/_header.php';
?>

<h1>Track a title</h1>
<p class="muted">Search to verify the exact title before we start tracking it — this avoids mixing up two things with the same name.</p>

<form method="get" class="search-form">
  <label>Type
    <select name="type">
      <option value="film" <?= $type === 'film' ? 'selected' : '' ?>>Film</option>
      <option value="book" <?= $type === 'book' ? 'selected' : '' ?>>Book</option>
    </select>
  </label>
  <label class="grow">Title
    <input type="text" name="query" value="<?= htmlspecialchars($query) ?>" placeholder="e.g. the exact title" autofocus>
  </label>
  <button type="submit">Search</button>
</form>

<?php if ($searchError): ?>
  <p class="alert"><?= htmlspecialchars($searchError) ?></p>
<?php endif; ?>

<?php if ($query !== '' && empty($results) && !$searchError): ?>
  <p class="muted">No matches found. Try a different spelling, or drop the year.</p>
<?php endif; ?>

<div class="title-grid">
  <?php foreach ($results as $r): ?>
    <form method="post" class="card title-card">
      <input type="hidden" name="track_external_id" value="<?= htmlspecialchars($r['external_id']) ?>">
      <input type="hidden" name="track_name" value="<?= htmlspecialchars($r['name']) ?>">
      <input type="hidden" name="track_creator_name" value="<?= htmlspecialchars($r['creator_name'] ?? '') ?>">
      <input type="hidden" name="track_year" value="<?= htmlspecialchars($r['year'] ?? '') ?>">
      <input type="hidden" name="track_poster_url" value="<?= htmlspecialchars($r['poster_url'] ?? '') ?>">
      <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
      <?php if (!empty($r['poster_url'])): ?>
        <img class="poster" src="<?= htmlspecialchars($r['poster_url']) ?>" alt="">
      <?php endif; ?>
      <div>
        <h2><?= htmlspecialchars($r['name']) ?> <?= !empty($r['year']) ? '(' . htmlspecialchars($r['year']) . ')' : '' ?></h2>
        <?php if (!empty($r['creator_name'])): ?><p class="muted small"><?= htmlspecialchars($r['creator_name']) ?></p><?php endif; ?>
        <?php if (!empty($r['overview'])): ?><p class="small"><?= htmlspecialchars(mb_substr($r['overview'], 0, 200)) ?>…</p><?php endif; ?>
        <button type="submit">Track this one</button>
      </div>
    </form>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
