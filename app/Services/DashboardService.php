<?php

namespace App\Services;

use App\Database;

/**
 * Aggregate stats for the dashboard (CLAUDE.md §7.4). Deliberately returns
 * only aggregates + metadata-only review rows — raw review text is fetched
 * separately, only for reviews the session has explicitly revealed
 * (see reveal.php / title.php).
 *
 * Note on multi-tenancy: a Title (and its review pool) is shared across every
 * user who tracks it (CLAUDE.md §6 — that's the point, it avoids re-fetching
 * the same reviews per user), but source-muting lives on tracked_title_sources
 * and is per-user. So every query here takes BOTH $titleId (which review pool
 * to read) and $trackedTitleId (whose mute settings apply) — never assume a
 * mute setting from one user's tracked_titles row applies to another user
 * tracking the same title.
 */
class DashboardService
{
    public function aggregates(int $titleId, int $trackedTitleId): array
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(c.sentiment = 'positive') AS positive,
                SUM(c.sentiment = 'negative') AS negative,
                SUM(c.sentiment = 'mixed') AS mixed,
                AVG(c.meanness_score) AS avg_meanness,
                SUM(c.constructive = 1) AS constructive_count,
                SUM(c.personal_attack = 1) AS personal_attack_count
             FROM reviews r
             JOIN classifications c ON c.review_id = r.id
             LEFT JOIN tracked_title_sources tts
                ON tts.tracked_title_id = ? AND tts.source_id = r.source_id
             WHERE r.title_id = ? AND COALESCE(tts.muted, 0) = 0"
        );
        $stmt->execute([$trackedTitleId, $titleId]);
        $agg = $stmt->fetch() ?: [];

        $pendingStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM reviews WHERE title_id = ? AND classification_status = 'pending'"
        );
        $pendingStmt->execute([$titleId]);
        $agg['pending_classification'] = (int) $pendingStmt->fetchColumn();

        $mutedStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM reviews r
             JOIN tracked_title_sources tts ON tts.source_id = r.source_id AND tts.tracked_title_id = ?
             WHERE r.title_id = ? AND tts.muted = 1"
        );
        $mutedStmt->execute([$trackedTitleId, $titleId]);
        $agg['muted_count'] = (int) $mutedStmt->fetchColumn();

        $sourceStmt = $pdo->prepare(
            "SELECT s.domain, s.health_status, s.last_checked_at, COUNT(r.id) AS review_count
             FROM sources s
             JOIN reviews r ON r.source_id = s.id
             WHERE r.title_id = ?
             GROUP BY s.id"
        );
        $sourceStmt->execute([$titleId]);
        $agg['sources'] = $sourceStmt->fetchAll();

        return $agg;
    }

    /**
     * Metadata-only review list (no raw_text) for the default dashboard view.
     * Excludes reviews from sources THIS user has muted (CLAUDE.md §4 — the
     * count still shows in aggregates()'s muted_count, just not the list).
     */
    public function reviewList(int $titleId, int $trackedTitleId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT r.id, r.external_url, r.author, r.headline, r.published_at, r.classification_status,
                    s.domain AS source_domain, s.type AS source_type,
                    c.id AS classification_id, c.sentiment, c.meanness_score, c.constructive,
                    c.personal_attack, c.content_tags
             FROM reviews r
             JOIN sources s ON s.id = r.source_id
             LEFT JOIN classifications c ON c.review_id = r.id
             LEFT JOIN tracked_title_sources tts
                ON tts.tracked_title_id = ? AND tts.source_id = r.source_id
             WHERE r.title_id = ? AND COALESCE(tts.muted, 0) = 0
             ORDER BY COALESCE(r.published_at, r.fetched_at) DESC"
        );
        $stmt->execute([$trackedTitleId, $titleId]);
        return $stmt->fetchAll();
    }
}
