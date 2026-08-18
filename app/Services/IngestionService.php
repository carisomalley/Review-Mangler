<?php

namespace App\Services;

use App\Database;

/**
 * Ingestion tick (CLAUDE.md §7.2, §9.3). Phase 1 has exactly one source type
 * (news, via NewsClient) — more Tier A/Tier B sources plug in here in later
 * phases behind the same tracked_title_sources join.
 */
class IngestionService
{
    /**
     * Runs ingestion for every tracked title that is due, per its own
     * refresh_cadence_hours. Meant to be called from cron/ingest.php.
     *
     * @return array{titles_checked:int, reviews_added:int, errors:array<int,string>}
     */
    public function runDue(): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->query(
            "SELECT tt.id AS tracked_title_id, t.id AS title_id, t.display_name, t.creator_name
             FROM tracked_titles tt
             JOIN titles t ON t.id = tt.title_id
             WHERE tt.next_fetch_at <= NOW()"
        );
        $due = $stmt->fetchAll();

        $titlesChecked = 0;
        $reviewsAdded = 0;
        $errors = [];

        foreach ($due as $row) {
            $titlesChecked++;
            try {
                $reviewsAdded += $this->fetchForTitle(
                    (int) $row['tracked_title_id'],
                    (int) $row['title_id'],
                    $row['display_name'],
                    $row['creator_name']
                );
            } catch (\Throwable $e) {
                $errors[] = "tracked_title_id={$row['tracked_title_id']}: " . $e->getMessage();
                error_log('Ingestion error: ' . $e->getMessage());
            }
        }

        return ['titles_checked' => $titlesChecked, 'reviews_added' => $reviewsAdded, 'errors' => $errors];
    }

    /**
     * Also used for the user-triggered "check now" button (rate-limited
     * separately — see title.php).
     */
    public function fetchForTitle(int $trackedTitleId, int $titleId, string $displayName, ?string $creatorName): int
    {
        $pdo = Database::pdo();
        $query = trim($displayName . ' ' . ($creatorName ?? ''));

        $newsSourceId = $this->sourceId('newsapi.org');
        if ($newsSourceId === null) {
            return 0;
        }

        try {
            $articles = (new NewsClient())->search($query);
            $this->markSourceHealth($newsSourceId, 'ok');
        } catch (\Throwable $e) {
            $this->markSourceHealth($newsSourceId, 'degraded');
            throw $e;
        }

        $added = 0;
        foreach ($articles as $article) {
            if ($this->insertReviewIfNew($titleId, $newsSourceId, $article)) {
                $added++;
            }
        }

        $cadenceStmt = $pdo->prepare('SELECT refresh_cadence_hours FROM tracked_titles WHERE id = ?');
        $cadenceStmt->execute([$trackedTitleId]);
        $cadenceHours = (int) ($cadenceStmt->fetchColumn() ?: 168);

        $update = $pdo->prepare(
            'UPDATE tracked_titles SET last_fetched_at = NOW(), next_fetch_at = DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id = ?'
        );
        $update->execute([$cadenceHours, $trackedTitleId]);

        return $added;
    }

    private function insertReviewIfNew(int $titleId, int $sourceId, array $article): bool
    {
        $pdo = Database::pdo();
        $dedupKey = sha1($article['external_url']);

        $check = $pdo->prepare('SELECT id FROM reviews WHERE dedup_key = ? LIMIT 1');
        $check->execute([$dedupKey]);
        if ($check->fetch()) {
            return false;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO reviews
                (title_id, source_id, external_url, author, headline, raw_text, native_rating,
                 dedup_key, classification_status, fetched_at, published_at)
             VALUES (?, ?, ?, ?, ?, ?, NULL, ?, "pending", NOW(), ?)'
        );
        $stmt->execute([
            $titleId,
            $sourceId,
            $article['external_url'],
            $article['source_name'] ?? null,
            $article['headline'] ?? null,
            $article['snippet'] ?? '',
            $dedupKey,
            $article['published_at'] ?? null,
        ]);
        return true;
    }

    private function sourceId(string $domain): ?int
    {
        $stmt = Database::pdo()->prepare('SELECT id FROM sources WHERE domain = ? LIMIT 1');
        $stmt->execute([$domain]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function markSourceHealth(int $sourceId, string $status): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE sources SET health_status = ?, last_checked_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$status, $sourceId]);
    }
}
