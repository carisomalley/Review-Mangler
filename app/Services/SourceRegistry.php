<?php

namespace App\Services;

use App\Database;

/**
 * Canonical list of Tier A sources (CLAUDE.md §7.2, §9.5) and the single
 * place that maps a source's domain to the client that knows how to fetch
 * it. Adding a Tier B (scraped) source in a later phase means adding one
 * entry here and one fetcher class — nothing else needs to change.
 */
class SourceRegistry
{
    private const SOURCES = [
        'newsapi.org' => ['type' => 'news', 'fetch_type' => 'api'],
        'reddit.com' => ['type' => 'reddit', 'fetch_type' => 'api'],
        'youtube.com' => ['type' => 'youtube', 'fetch_type' => 'api'],
    ];

    /**
     * Ensures every known Tier A source exists in `sources` and is linked to
     * this tracked_title (unmuted by default). Idempotent — safe to call on
     * every ingestion run, including for tracked_titles created in an
     * earlier phase before a source existed (CLAUDE.md §12's incremental
     * source rollout).
     */
    public static function ensureAllLinked(int $trackedTitleId): void
    {
        foreach (self::SOURCES as $domain => $meta) {
            $sourceId = self::ensureSource($meta['type'], $domain, $meta['fetch_type']);
            $stmt = Database::pdo()->prepare(
                'INSERT IGNORE INTO tracked_title_sources (tracked_title_id, source_id, muted) VALUES (?, ?, 0)'
            );
            $stmt->execute([$trackedTitleId, $sourceId]);
        }
    }

    public static function fetcherFor(string $domain): NewsClient|RedditClient|YoutubeClient
    {
        return match ($domain) {
            'newsapi.org' => new NewsClient(),
            'reddit.com' => new RedditClient(),
            'youtube.com' => new YoutubeClient(),
            default => throw new \InvalidArgumentException("No fetcher registered for domain: $domain"),
        };
    }

    private static function ensureSource(string $type, string $domain, string $fetchType): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id FROM sources WHERE domain = ? LIMIT 1');
        $stmt->execute([$domain]);
        $existing = $stmt->fetch();
        if ($existing) {
            return (int) $existing['id'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO sources (type, domain, fetch_type, health_status, created_at) VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$type, $domain, $fetchType, 'ok']);
        return (int) $pdo->lastInsertId();
    }
}
