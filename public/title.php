<?php

require __DIR__ . '/../app/bootstrap.php';

use App\Auth;
use App\Env;
use App\Services\TitleService;
use App\Services\DashboardService;
use App\Services\IngestionService;
use App\Services\ReviewService;

$userId = Auth::requireLogin();
$trackedTitleId = (int) ($_GET['id'] ?? 0);

$titleService = new TitleService();
$tracked = $titleService->getOwnedTrackedTitle($userId, $trackedTitleId);

if (!$tracked) {
    http_response_code(404);
    $pageTitle = 'Not found';
    require __DIR__ . '/_header.php';
    echo '<p>Title not found.</p>';
    require __DIR__ . '/_footer.php';
    exit;
}

// Manual "check now" — rate-limited so it can't be used to hammer sources or
// turn into a doomscroll trigger (CLAUDE.md §4, §7.2).
$cooldownHours = (int) Env::get('MANUAL_REFRESH_COOLDOWN_HOURS', '24');
$lastFetched = $tracked['last_fetched_at'] ? new DateTime($tracked['last_fetched_at']) : null;
$cooldownUntil = $lastFetched ? (clone $lastFetched)->modify("+{$cooldownHours} hours") : null;
$canCheckNow = $cooldownUntil === null || $cooldownUntil <= new DateTime();

$checkNowMessage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_now'])) {
    if (!$canCheckNow) {
        $checkNowMessage = "You already checked recently — try again after " . $cooldownUntil->format('M j, g:ia') . ".";
    } else {
        try {
            $added = (new IngestionService())->fetchForTitle(
                (int) $tracked['id'],
                (int) $tracked['title_id'],
                $tracked['display_name'],
                $tracked['creator_name']
            );
            $checkNowMessage = $added > 0 ? "Found {$added} new item(s). They'll be scored shortly." : "Checked — nothing new right now.";
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            $checkNowMessage = "Couldn't check right now — the news source may be unavailable.";
        }
        // Refresh $tracked so the cooldown reflects the check we just did.
        $tracked = $titleService->getOwnedTrackedTitle($userId, $trackedTitleId);
    }
}

$dashboard = new DashboardService();
$agg = $dashboard->aggregates((int) $tracked['title_id'], (int) $tracked['id']);
$reviews = $dashboard->reviewList((int) $tracked['title_id'], (int) $tracked['id']);
$revealed = $_SESSION['revealed'] ?? [];

// Fetch text only for reviews this session has already deliberately revealed
// (CLAUDE.md §7.5 — raw text never leaves the metadata-only query above by default).
$revealedTexts = [];
if (!empty($revealed)) {
    $reviewService = new ReviewService();
    foreach ($reviews as $r) {
        if (in_array((int) $r['id'], $revealed, true)) {
            $full = $reviewService->getRevealableText($userId, (int) $r['id']);
            if ($full) {
                $revealedTexts[(int) $r['id']] = $full['raw_text'];
            }
        }
    }
}

$pageTitle = $tracked['display_name'];
require __DIR__ . '/_header.php';
?>

<a href="/index.php" class="back-link">&larr; All titles</a>
<h1><?= htmlspecialchars($tracked['display_name']) ?> <?= $tracked['year'] ? '(' . htmlspecialchars($tracked['year']) . ')' : '' ?></h1>
<?php if ($tracked['creator_name']): ?><p class="muted"><?= htmlspecialchars($tracked['creator_name']) ?></p><?php endif; ?>

<?php if ($checkNowMessage): ?>
  <p class="notice"><?= htmlspecialchars($checkNowMessage) ?></p>
<?php endif; ?>

<div class="card summary-card">
  <h2>Summary</h2>
  <?php $total = (int) ($agg['total'] ?? 0); ?>
  <?php if ($total === 0): ?>
    <p class="muted">No scored reviews yet.</p>
  <?php else: ?>
    <div class="stat-row">
      <div class="stat"><span class="stat-num"><?= (int) $agg['positive'] ?></span><span class="stat-label">positive</span></div>
      <div class="stat"><span class="stat-num"><?= (int) $agg['mixed'] ?></span><span class="stat-label">mixed</span></div>
      <div class="stat"><span class="stat-num"><?= (int) $agg['negative'] ?></span><span class="stat-label">negative</span></div>
      <div class="stat"><span class="stat-num"><?= number_format((float) $agg['avg_meanness'], 1) ?>/5</span><span class="stat-label">avg meanness</span></div>
      <div class="stat"><span class="stat-num"><?= (int) $agg['constructive_count'] ?>/<?= $total ?></span><span class="stat-label">constructive</span></div>
      <div class="stat <?= (int) $agg['personal_attack_count'] > 0 ? 'stat-warn' : '' ?>">
        <span class="stat-num"><?= (int) $agg['personal_attack_count'] ?></span>
        <span class="stat-label">personal attacks flagged</span>
      </div>
    </div>
    <?php if ((int) $agg['muted_count'] > 0): ?>
      <p class="muted small"><?= (int) $agg['muted_count'] ?> review(s) from muted sources are excluded above.</p>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ((int) $agg['pending_classification'] > 0): ?>
    <p class="muted small"><?= (int) $agg['pending_classification'] ?> review(s) fetched but not scored yet — they'll appear here after the next classification run.</p>
  <?php endif; ?>

  <?php foreach (($agg['sources'] ?? []) as $s): ?>
    <p class="muted small">
      Source <strong><?= htmlspecialchars($s['domain']) ?></strong>:
      <?= (int) $s['review_count'] ?> review(s),
      status <span class="health-<?= htmlspecialchars($s['health_status']) ?>"><?= htmlspecialchars($s['health_status']) ?></span>
      <?= $s['last_checked_at'] ? '· last checked ' . htmlspecialchars($s['last_checked_at']) : '' ?>
    </p>
  <?php endforeach; ?>

  <form method="post">
    <button type="submit" name="check_now" value="1" <?= $canCheckNow ? '' : 'disabled' ?>>
      <?= $canCheckNow ? 'Check for new reviews now' : 'Checked recently — try later' ?>
    </button>
  </form>
</div>

<h2>Reviews</h2>
<p class="muted small">Metadata only, by default (CLAUDE.md §7.5). Open one deliberately — nothing here is shown to you unless you choose it.</p>

<div class="review-list">
  <?php foreach ($reviews as $r): ?>
    <div class="card review-row">
      <div class="review-meta">
        <span class="source-tag"><?= htmlspecialchars($r['source_domain']) ?></span>
        <?php if ($r['headline']): ?><strong><?= htmlspecialchars($r['headline']) ?></strong><?php endif; ?>
        <?php if ($r['author']): ?><span class="muted small"><?= htmlspecialchars($r['author']) ?></span><?php endif; ?>
        <?php if ($r['published_at']): ?><span class="muted small"><?= htmlspecialchars($r['published_at']) ?></span><?php endif; ?>
      </div>

      <?php if ($r['classification_status'] !== 'done'): ?>
        <p class="muted small">Status: <?= htmlspecialchars($r['classification_status']) ?></p>
      <?php else: ?>
        <div class="badges">
          <span class="badge sentiment-<?= htmlspecialchars($r['sentiment']) ?>"><?= htmlspecialchars($r['sentiment']) ?></span>
          <span class="badge">meanness <?= (int) $r['meanness_score'] ?>/5</span>
          <span class="badge"><?= $r['constructive'] ? 'constructive' : 'not constructive' ?></span>
          <?php if ($r['personal_attack']): ?><span class="badge badge-warn">personal attack</span><?php endif; ?>
          <?php
            $tags = json_decode($r['content_tags'] ?? '[]', true) ?: [];
            foreach ($tags as $tag): ?>
              <span class="badge badge-tag"><?= htmlspecialchars(str_replace('_', ' ', $tag)) ?></span>
          <?php endforeach; ?>
        </div>

        <?php if (isset($revealedTexts[(int) $r['id']])): ?>
          <div class="revealed-text">
            <p><?= nl2br(htmlspecialchars($revealedTexts[(int) $r['id']])) ?></p>
          </div>
        <?php else: ?>
          <form method="post" action="/reveal.php" class="inline-form">
            <input type="hidden" name="review_id" value="<?= (int) $r['id'] ?>">
            <input type="hidden" name="return_to" value="<?= (int) $trackedTitleId ?>">
            <?php if (!empty($tags)): ?>
              <span class="muted small">Contains: <?= htmlspecialchars(implode(', ', array_map(fn($t) => str_replace('_', ' ', $t), $tags))) ?> —</span>
            <?php endif; ?>
            <button type="submit">Reveal this review</button>
          </form>
        <?php endif; ?>

        <form method="post" action="/correct.php" class="inline-form correction-form">
          <input type="hidden" name="classification_id" value="<?= (int) $r['classification_id'] ?>">
          <input type="hidden" name="return_to" value="<?= (int) $trackedTitleId ?>">
          <input type="text" name="note" placeholder="This classification looks off because… (optional)">
          <button type="submit" class="link-button">Flag classification</button>
        </form>
      <?php endif; ?>

      <a class="muted small" href="<?= htmlspecialchars($r['external_url']) ?>" target="_blank" rel="noopener">Original source ↗</a>
    </div>
  <?php endforeach; ?>

  <?php if (empty($reviews)): ?>
    <p class="muted">Nothing found yet.</p>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
