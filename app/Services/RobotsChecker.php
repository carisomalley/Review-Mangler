<?php

namespace App\Services;

/**
 * Generic robots.txt fetcher/parser (CLAUDE.md §7.2 Tier B requirement:
 * "Respect robots.txt per domain"). Used by every Tier B scraper before it
 * requests anything — this is what makes that requirement real rather than
 * "we eyeballed it once." Caches each domain's robots.txt to storage/cache/
 * for 24h so a scraper run doesn't re-fetch it on every single request.
 *
 * Deliberately fails CLOSED: if robots.txt can't be fetched or parsed for
 * some reason, isAllowed() returns false rather than guessing "probably
 * fine" — a scraper that can't verify it's welcome shouldn't proceed.
 */
class RobotsChecker
{
    private const CACHE_TTL_SECONDS = 86400; // robots.txt rarely changes; re-check daily

    public static function isAllowed(string $domain, string $path, string $userAgent = 'reviewmangler'): bool
    {
        try {
            $rules = self::rulesFor($domain);
        } catch (\Throwable $e) {
            error_log("RobotsChecker: couldn't fetch robots.txt for $domain: " . $e->getMessage());
            return false;
        }
        if ($rules === null) {
            return false;
        }

        $applicable = $rules[strtolower($userAgent)] ?? $rules['*'] ?? [];

        // Standard robots.txt semantics: the longest matching pattern wins,
        // regardless of whether it's Allow or Disallow. No match = allowed.
        $matchedLength = -1;
        $allowed = true;

        foreach ($applicable as [$type, $pattern]) {
            $regex = self::patternToRegex($pattern);
            if ($regex === null || !preg_match($regex, $path)) {
                continue;
            }
            $len = strlen($pattern);
            if ($len > $matchedLength) {
                $matchedLength = $len;
                $allowed = ($type === 'allow');
            }
        }

        return $allowed;
    }

    /**
     * @return array<string, array<int, array{0:string,1:string}>>|null
     */
    private static function rulesFor(string $domain): ?array
    {
        $cachePath = self::cachePath($domain);
        $body = self::readCache($cachePath);

        if ($body === null) {
            $response = HttpClient::get("https://$domain/robots.txt", ['Accept: text/plain'], 10);
            if ($response['status'] === 404) {
                // No robots.txt at all conventionally means everything's allowed.
                return ['*' => []];
            }
            if ($response['status'] !== 200) {
                return null; // couldn't verify one way or the other — caller fails closed
            }
            $body = $response['body'];
            self::writeCache($cachePath, $body);
        }

        return self::parse($body);
    }

    /**
     * @return array<string, array<int, array{0:string,1:string}>>
     */
    private static function parse(string $body): array
    {
        $groups = [];
        $currentAgents = [];
        $sawRuleSinceAgent = true; // true so the first User-agent line starts a fresh group

        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if ($sawRuleSinceAgent) {
                    // A rule line was seen since the last User-agent line, so
                    // this starts a brand-new group rather than joining the
                    // previous one (consecutive User-agent lines with no rules
                    // between them share a single group instead — standard
                    // robots.txt grouping behavior).
                    $currentAgents = [];
                    $sawRuleSinceAgent = false;
                }
                $agent = strtolower($value);
                $currentAgents[] = $agent;
                $groups[$agent] ??= [];
            } elseif (($field === 'disallow' || $field === 'allow') && $currentAgents) {
                $sawRuleSinceAgent = true;
                foreach ($currentAgents as $agent) {
                    $groups[$agent][] = [$field, $value];
                }
            }
        }

        return $groups;
    }

    private static function patternToRegex(string $pattern): ?string
    {
        if ($pattern === '') {
            return null; // an empty Disallow value conventionally means "allow everything"
        }
        $anchoredEnd = str_ends_with($pattern, '$');
        $core = $anchoredEnd ? substr($pattern, 0, -1) : $pattern;
        $escaped = preg_quote($core, '#');
        $escaped = str_replace('\*', '.*', $escaped); // robots.txt "*" is a wildcard, not literal
        return '#^' . $escaped . ($anchoredEnd ? '$' : '') . '#';
    }

    private static function cachePath(string $domain): string
    {
        $safeName = preg_replace('/[^a-z0-9.\-]/i', '_', $domain);
        return __DIR__ . '/../../storage/cache/robots/' . $safeName . '.txt';
    }

    private static function readCache(string $path): ?string
    {
        if (!is_file($path) || (time() - filemtime($path)) > self::CACHE_TTL_SECONDS) {
            return null;
        }
        $body = @file_get_contents($path);
        return $body === false ? null : $body;
    }

    private static function writeCache(string $path, string $body): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // Best-effort — a shared host with an unwritable storage/ shouldn't
        // block scraping, it just means robots.txt gets re-fetched every run.
        @file_put_contents($path, $body);
    }
}
