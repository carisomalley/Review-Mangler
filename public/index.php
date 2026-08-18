<?php

require __DIR__ . '/../app/bootstrap.php';

use App\Auth;
use App\Services\TitleService;
use App\Services\DashboardService;

$userId = Auth::requireLogin();
$titles = (new TitleService())->listForUser($userId);
$dashboard = new DashboardService();

$pageTitle = 'Your titles';
require __DIR__ . '/_header.php';
?>

<h1>Your titles</h1>

<?php if (empty($titles)): ?>
  <div class="card">
    <p>You're not tracking anything yet.</p>
    <a class="button" href="/add_title.php">Track your first title</a>
  </div>
<?php else: ?>
  <div class="title-grid">
    <?php foreach ($titles as $t):
        $agg = $dashboard->aggregates((int) $t['title_id'], (int) $t['tracked_title_id']);
        $total = (int) ($agg['total'] ?? 0);
    ?>
      <a class="card title-card" href="/title.php?id=<?= (int) $t['tracked_title_id'] ?>">
        <?php if ($t['poster_url']): ?>
          <img class="poster" src="<?= htmlspecialchars($t['poster_url']) ?>" alt="">
        <?php endif; ?>
        <div>
          <h2><?= htmlspecialchars($t['display_name']) ?> <?= $t['year'] ? '(' . htmlspecialchars($t['year']) . ')' : '' ?></h2>
          <?php if ($total === 0): ?>
            <p class="muted">No reviews found yet. First check runs automatically, or check back after the next scheduled update.</p>
          <?php else: ?>
            <p>
              <span class="badge sentiment-positive"><?= (int) $agg['positive'] ?> positive</span>
              <span class="badge sentiment-negative"><?= (int) $agg['negative'] ?> negative</span>
              <span class="badge sentiment-mixed"><?= (int) $agg['mixed'] ?> mixed</span>
            </p>
            <p class="muted small">
              <?= $total ?> review<?= $total === 1 ? '' : 's' ?> ·
              avg meanness <?= $agg['avg_meanness'] !== null ? number_format((float) $agg['avg_meanness'], 1) : '—' ?>/5
              <?php if ((int) $agg['personal_attack_count'] > 0): ?>
                · <strong class="warn"><?= (int) $agg['personal_attack_count'] ?> personal attack<?= $agg['personal_attack_count'] == 1 ? '' : 's' ?> flagged</strong>
              <?php endif; ?>
            </p>
          <?php endif; ?>
          <?php if ((int) $agg['pending_classification'] > 0): ?>
            <p class="muted small"><?= (int) $agg['pending_classification'] ?> review(s) still being scored…</p>
          <?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
