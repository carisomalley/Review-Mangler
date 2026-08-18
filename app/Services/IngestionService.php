<?php

namespace App\Services;

use App\Database;

/**
 * Ingestion tick (CLAUDE.md §7.2, §9.3). Loops every Tier A source linked to
 * a tracked title (via SourceRegistry) — Phase 1 had just news; Phase 2 adds
 * Reddit and YouTube here without touching the scheduling/dedup logic below.
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
     * separately — see title.php). Fetches from every unmuted source linked
     * to this tracked title; one source failing doesn't stop the others
     * (CLAUDE.md §7.2 — sources are meant to degrade independently).
     */
    public function fetchForTitle(int $trackedTitleId, int $titleId, string $displayName, ?string $creatorName): int
    {
        // Backfills any Tier A source that didn't exist yet when this title
        // was first tracked (e.g. a Phase 1 tracked_title gaining Reddit/
        // YouTube in Phase 2) — cheap and idempotent, safe to call every run.
        SourceRegistry::ensureAllLinked($trackedTitleId);

        $pdo = Database::pdo();
        $query = trim($displayName . ' ' . ($creatorName ?? ''));

        $linkedSourcesStmt = $pdo->prepare(
            'SELECT s.id, s.domain
             FROM tracked_title_sources tts
             JOIN sources s ON s.id = tts.source_id
             WHERE tts.tracked_title_id = ? AND tts.muted = 0'
        );
        $linkedSourcesStmt->execute([$trackedTitleId]);
        $linkedSources = $linkedSourcesStmt->fetchAll();

        $added = 0;
        $failures = 0;
        $lastError = null;

        foreach ($linkedSources as $source) {
            $sourceId = (int) $source['id'];
            $domain = $source['domain'];

            try {
                $items = SourceRegistry::fetcherFor($domain)->search($query);
                $this->markSourceHealth($sourceId, 'ok');
            } catch (\Throwable $e) {
                $this->markSourceHealth($sourceId, 'degraded');
                error_log("Ingestion error for source {$domain}: " . $e->getMessage());
                $lastError = $e;
                $failures++;
                continue; // one source failing shouldn't stop the others
            }

            foreach ($items as $item) {
                if ($this->insertReviewIfNew($titleId, $sourceId, $item)) {
                    $added++;
                }
            }
        }

        $cadenceStmt = $pdo->prepare('SELECT refresh_cadence_hours FROM tracked_titles WHERE id = ?');
        $cadenceStmt->execute([$trackedTitleId]);
        $cadenceHours = (int) ($cadenceStmt->fetchColumn() ?: 168);

        $update = $pdo->prepare(
            'UPDATE tracked_titles SET last_fetched_at = NOW(), next_fetch_at = DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id = ?'
        );
        $update->execute([$cadenceHours, $trackedTitleId]);

        // If every single linked source failed, surface that to the caller
        // (e.g. the "check now" button) instead of silently reporting success
        // with 0 added — but a partial failure (some sources ok) is not an error.
        if (!empty($linkedSources) && $failures === count($linkedSources)) {
            throw $lastError;
        }

        return $added;
    }

    private function insertReviewIfNew(int $titleId, int $sourceId, array $item): bool
    {
        $pdo = Database::pdo();
        $dedupKey = sha1($item['external_url']);

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
            $item['external_url'],
            $item['author'] ?? null,
            $item['headline'] ?? null,
            $item['text'] ?? '',
            $dedupKey,
            $this->toMysqlDatetime($item['published_at'] ?? null),
        ]);
        return true;
    }

    /**
     * Every source client returns published_at as ISO 8601 (NewsAPI,
     * YouTube) or date('c') (Reddit) — e.g. "2026-08-18T12:00:00Z". MySQL's
     * DATETIME column rejects that format outright under strict mode (the
     * default since MySQL 5.7/MariaDB 10.2), which would otherwise fail
     * every single insert that has a publish date. Normalize here, once,
     * for every source.
     */
    private function toMysqlDatetime(?string $iso8601): ?string
    {
        if ($iso8601 === null || $iso8601 === '') {
            return null;
        }
        $timestamp = strtotime($iso8601);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private function markSourceHealth(int $sourceId, string $status): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE sources SET health_status = ?, last_checked_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$status, $sourceId]);
    }
}
