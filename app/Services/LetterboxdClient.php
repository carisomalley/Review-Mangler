<?php

namespace App\Services;

use App\Env;

/**
 * Tier B (scraped) source — CLAUDE.md §7.2, §7.4, Phase 3 build log.
 * Letterboxd has no public review API, so this fetches and parses public
 * HTML pages directly, which is inherently more fragile than the Tier A API
 * clients — see the big warning below before touching the parsing methods.
 *
 * Phase 3 only builds Letterboxd. IMDb, Goodreads, and Amazon were each
 * checked against their live robots.txt before writing any scraper code, and
 * all three disallow what a scraper would actually need: IMDb and Amazon
 * disallow generic crawlers essentially site-wide, and Goodreads explicitly
 * disallows /book/reviews/, /review/show, and /search for User-agent: *.
 * Scraping any of those would violate the "respect robots.txt" requirement
 * this class exists to satisfy, so they were deliberately not built — see the
 * CLAUDE.md build log for the exact findings. Letterboxd's robots.txt has no
 * disallow on /film/, /tmdb/, or the default (unsorted) /reviews/ listing —
 * only on sorted/filtered variants like /*\/by/* and /*\/tag/* — so this
 * class sticks strictly to those paths and never touches a /by/... URL.
 * RobotsChecker re-verifies this against the live robots.txt on every run
 * anyway, so a future change on Letterboxd's end degrades this source
 * (throws, gets marked "degraded") instead of silently violating it.
 *
 * IMPORTANT — the HTML parsing below was built from live inspection of
 * Letterboxd's actual pages in August 2026 (this dev environment has no
 * direct outbound access to arbitrary third-party sites to test against, so
 * it couldn't be run end-to-end before delivery). It deliberately leans on
 * the most stable part of Letterboxd's markup — the review permalink URL
 * shape itself (/<username>/film/<slug>/) — rather than CSS class names,
 * since class names are far more likely to change in a redesign. Still,
 * treat this as the single most fragile piece of the app per CLAUDE.md §7.2.
 * If a tracked film's Letterboxd reviews stay at zero after a "check now",
 * that's the first place to look — Letterboxd's markup may have shifted and
 * the parsing heuristics here will need adjusting to match.
 */
class LetterboxdClient
{
    private const BASE_URL = 'https://letterboxd.com';
    private const DOMAIN = 'letterboxd.com';
    private const MAX_PAGES = 2; // politeness cap, not a technical limit
    private const MIN_REVIEW_LENGTH = 40; // filters out star-only "watched" logs with no written text

    /**
     * @param array{canonical_source?:string, canonical_id?:string} $titleMeta
     * @return array<int, array{external_url:string, headline:?string, author:?string, published_at:?string, text:string, native_rating:?string}>
     */
    public function search(string $query, array $titleMeta = []): array
    {
        if (($titleMeta['canonical_source'] ?? null) !== 'tmdb' || empty($titleMeta['canonical_id'])) {
            // Letterboxd is film-only and keyed off the TMDB id already
            // verified during title tracking (CLAUDE.md §7.1) — without it,
            // there's no reliable way to find the right film. (Letterboxd's
            // own text search is loaded client-side via JS, so a plain HTTP
            // fetch can't use it as a fallback anyway.)
            return [];
        }

        $slug = $this->resolveSlug($titleMeta['canonical_id']);
        if ($slug === null) {
            return [];
        }

        $results = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $path = $page === 1 ? "/film/$slug/reviews/" : "/film/$slug/reviews/page/$page/";
            [$status, $html] = $this->politeGet($path);

            if ($status === 404) {
                break; // no more pages
            }
            if ($status !== 200) {
                throw new \RuntimeException("Letterboxd reviews fetch failed with HTTP $status for $path");
            }

            $pageResults = $this->parseReviews($html);
            if (empty($pageResults)) {
                break; // nothing (more) written here — stop rather than keep paging blindly
            }
            $results = array_merge($results, $pageResults);
        }

        return $results;
    }

    /**
     * Letterboxd redirects /tmdb/{id}/ straight to the canonical film page —
     * confirmed live (Aug 2026): /tmdb/187017/ -> /film/22-jump-street/. Far
     * more reliable than trying to fuzzy-match a free-text search.
     */
    private function resolveSlug(string $tmdbId): ?string
    {
        [$status, , $effectiveUrl] = $this->politeGet("/tmdb/$tmdbId/");
        if ($status !== 200 || $effectiveUrl === null) {
            return null;
        }
        $path = parse_url($effectiveUrl, PHP_URL_PATH) ?? '';
        if (preg_match('#/film/([a-z0-9\-]+)/#i', $path, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * @return array{0:int, 1:string, 2:?string} [status, body, effectiveUrl]
     */
    private function politeGet(string $path): array
    {
        if (!RobotsChecker::isAllowed(self::DOMAIN, $path)) {
            throw new \RuntimeException("Letterboxd robots.txt disallows $path — refusing to fetch.");
        }

        // Randomized polite delay between requests (CLAUDE.md §7.2). Letterboxd's
        // robots.txt specifies no Crawl-delay for generic crawlers, so this is a
        // conservative default rather than hammering them at full speed.
        usleep(random_int(400_000, 1_200_000));

        $contact = Env::require('SCRAPER_CONTACT');
        $userAgent = "ReviewMangler/1.0 (+mailto:$contact)";
        $response = HttpClient::get(self::BASE_URL . $path, ["User-Agent: $userAgent", 'Accept: text/html'], 15);

        return [$response['status'], $response['body'], $response['effective_url'] ?? null];
    }

    /**
     * @return array<int, array{external_url:string, headline:?string, author:?string, published_at:?string, text:string, native_rating:?string}>
     */
    private function parseReviews(string $html): array
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);

        $results = [];
        $seen = [];

        foreach ($xpath->query('//a[@href]') as $anchor) {
            /** @var \DOMElement $anchor */
            $href = $anchor->getAttribute('href');

            // A review permalink is /<username>/film/<slug>/ — three segments,
            // first one not literally "film" (that's the canonical film page,
            // which every review listing also links back to repeatedly).
            if (!preg_match('#^/([^/]+)/film/([^/]+)/?$#', $href, $m) || $m[1] === 'film') {
                continue;
            }
            if (isset($seen[$href])) {
                continue; // the same permalink often appears twice (avatar + username)
            }
            $seen[$href] = true;

            $container = $this->findReviewContainer($anchor);
            if ($container === null) {
                continue;
            }
            $containerText = (string) preg_replace('/\s+/u', ' ', trim($container->textContent));

            $rating = null;
            if (preg_match('/(\x{2605}{1,5}\x{00BD}?)/u', $containerText, $rm)) {
                $rating = $this->starsToText($rm[1]);
            }

            $text = $this->extractReviewText($container, $containerText);
            if ($text === null || mb_strlen($text) < self::MIN_REVIEW_LENGTH) {
                continue; // star-only "watched" log, not an actual written review — nothing to classify
            }

            $results[] = [
                'external_url' => self::BASE_URL . $href,
                'headline' => null,
                'author' => $m[1],
                'published_at' => $this->extractDate($container),
                'text' => $text,
                'native_rating' => $rating,
            ];
        }

        return $results;
    }

    private function findReviewContainer(\DOMElement $anchor): ?\DOMNode
    {
        $node = $anchor;
        for ($i = 0; $i < 8 && $node->parentNode; $i++) {
            $node = $node->parentNode;
            if ($node instanceof \DOMElement && strtolower($node->tagName) === 'li') {
                return $node;
            }
        }
        // No <li> ancestor found (markup may differ from what was inspected) —
        // fall back to a fixed number of hops up from the anchor. Worse than a
        // confirmed container, still better than giving up on this review.
        $fallback = $anchor->parentNode?->parentNode?->parentNode;
        return $fallback ?? $anchor->parentNode;
    }

    private function extractReviewText(\DOMNode $container, string $containerText): ?string
    {
        if (!($container instanceof \DOMElement)) {
            return null;
        }
        $xpath = new \DOMXPath($container->ownerDocument);
        $best = null;
        $bestLen = 0;

        foreach ($xpath->query('.//p | .//div', $container) as $node) {
            $t = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
            // Skip empty nodes and nodes that are really just the whole
            // container's text again (a wrapper <div>, not a specific block).
            if ($t === '' || mb_strlen($t) >= mb_strlen($containerText) - 5) {
                continue;
            }
            $len = mb_strlen($t);
            if ($len > $bestLen) {
                $bestLen = $len;
                $best = $t;
            }
        }

        return $best;
    }

    private function extractDate(\DOMNode $container): ?string
    {
        if ($container instanceof \DOMElement) {
            $xpath = new \DOMXPath($container->ownerDocument);
            $timeNodes = $xpath->query('.//time[@datetime]', $container);
            if ($timeNodes->length > 0) {
                $datetime = $timeNodes->item(0)->getAttribute('datetime');
                if ($datetime !== '') {
                    return $datetime;
                }
            }
        }
        $text = (string) preg_replace('/\s+/u', ' ', trim($container->textContent));
        if (preg_match('/\b(\d{1,2} [A-Z][a-z]{2} \d{4})\b/u', $text, $m)) {
            return $m[1]; // e.g. "18 Aug 2026" — strtotime() (via IngestionService) parses this fine
        }
        return null;
    }

    private function starsToText(string $glyphs): string
    {
        $full = substr_count($glyphs, "\u{2605}"); // exact byte-sequence count — fine for a fixed UTF-8 glyph
        $half = str_contains($glyphs, "\u{00BD}");
        $value = $full + ($half ? 0.5 : 0);
        return $value . '/5';
    }
}
