<?php

namespace App\Services;

use App\Database;
use PDO;

/**
 * Title verification + tracking (CLAUDE.md §7.1, §6). Titles are keyed by
 * (canonical_source, canonical_id) so re-adding the "same" title never
 * fragments into duplicates just because someone typed it differently.
 */
class TitleService
{
    public function search(string $type, string $query): array
    {
        if ($type === 'film') {
            return (new TmdbClient())->search($query);
        }
        if ($type === 'book') {
            return (new GoogleBooksClient())->search($query);
        }
        throw new \InvalidArgumentException("Unknown title type: $type");
    }

    /**
     * Finds or creates the Title row, then creates a TrackedTitle for the
     * given user pointing at it. Returns the tracked_title id.
     */
    public function trackForUser(int $userId, string $type, array $searchResult, int $refreshCadenceHours = 168): int
    {
        $pdo = Database::pdo();
        $canonicalSource = $type === 'film' ? 'tmdb' : 'google_books';

        $stmt = $pdo->prepare(
            'SELECT id FROM titles WHERE canonical_source = ? AND canonical_id = ? LIMIT 1'
        );
        $stmt->execute([$canonicalSource, $searchResult['external_id']]);
        $existing = $stmt->fetch();

        if ($existing) {
            $titleId = (int) $existing['id'];
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO titles (type, canonical_source, canonical_id, display_name, creator_name, year, poster_url, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $type,
                $canonicalSource,
                $searchResult['external_id'],
                $searchResult['name'],
                $searchResult['creator_name'] ?? null,
                $searchResult['year'] ?? null,
                $searchResult['poster_url'] ?? null,
            ]);
            $titleId = (int) $pdo->lastInsertId();
        }

        // Don't create a duplicate tracked_titles row if this user already tracks it.
        $stmt = $pdo->prepare('SELECT id FROM tracked_titles WHERE user_id = ? AND title_id = ? LIMIT 1');
        $stmt->execute([$userId, $titleId]);
        $existingTracked = $stmt->fetch();
        if ($existingTracked) {
            return (int) $existingTracked['id'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO tracked_titles (user_id, title_id, refresh_cadence_hours, next_fetch_at, created_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([$userId, $titleId, $refreshCadenceHours]);
        $trackedTitleId = (int) $pdo->lastInsertId();

        // Link every currently-known Tier A source (CLAUDE.md §7.2, §9.5) —
        // see SourceRegistry for the single place that list lives.
        SourceRegistry::ensureAllLinked($trackedTitleId);

        return $trackedTitleId;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function listForUser(int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT tt.id AS tracked_title_id, tt.next_fetch_at, tt.last_fetched_at,
                    t.id AS title_id, t.display_name, t.creator_name, t.year, t.poster_url, t.type
             FROM tracked_titles tt
             JOIN titles t ON t.id = tt.title_id
             WHERE tt.user_id = ?
             ORDER BY tt.created_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Ownership check used by every page that operates on a specific
     * tracked_title_id, per the strict per-account isolation rule in
     * CLAUDE.md §5.
     */
    public function getOwnedTrackedTitle(int $userId, int $trackedTitleId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT tt.*, t.display_name, t.creator_name, t.year, t.type, t.id AS title_id
             FROM tracked_titles tt
             JOIN titles t ON t.id = tt.title_id
             WHERE tt.id = ? AND tt.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$trackedTitleId, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
