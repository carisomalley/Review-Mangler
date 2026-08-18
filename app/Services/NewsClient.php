<?php

namespace App\Services;

use App\Env;

/**
 * Tier A review/write-up source for the Phase 1 MVP (CLAUDE.md §7.2, §9.5,
 * §12 — the single ingestion source Phase 1 ships with).
 *
 * IMPORTANT — verify before relying on this in production: NewsAPI.org's
 * free "Developer" plan is documented (as of when this was written) as
 * intended for local development/testing, not a deployed production app.
 * Confirm your plan's current terms at https://newsapi.org/pricing before
 * pointing this at a live Hostinger deployment — you may need a paid plan,
 * or to swap this client for GNews or another provider. The rest of the app
 * only talks to this class through search(), so swapping providers later is
 * a one-file change.
 */
class NewsClient
{
    private const BASE_URL = 'https://newsapi.org/v2/everything';

    /**
     * @return array<int, array{external_url:string, headline:string, source_name:?string, published_at:?string, snippet:string}>
     */
    public function search(string $query, int $lookbackDays = 30): array
    {
        $apiKey = Env::require('NEWS_API_KEY');
        $params = [
            'q' => $query,
            'language' => 'en',
            'sortBy' => 'relevancy',
            'pageSize' => 25,
            'from' => date('Y-m-d', strtotime("-{$lookbackDays} days")),
        ];

        $url = self::BASE_URL . '?' . http_build_query($params);
        $response = HttpClient::get($url, ["X-Api-Key: $apiKey", 'Accept: application/json']);

        if ($response['status'] !== 200) {
            throw new \RuntimeException('NewsAPI search failed with HTTP ' . $response['status'] . ': ' . $response['body']);
        }

        $data = json_decode($response['body'], true);
        $results = [];
        foreach (($data['articles'] ?? []) as $a) {
            if (empty($a['url'])) {
                continue;
            }
            $results[] = [
                'external_url' => $a['url'],
                'headline' => $a['title'] ?? '(untitled)',
                'source_name' => $a['source']['name'] ?? null,
                'published_at' => $a['publishedAt'] ?? null,
                // NewsAPI's free tier truncates content; description + content is the
                // best full-text approximation available without fetching each page.
                'snippet' => trim(($a['description'] ?? '') . "\n\n" . ($a['content'] ?? '')),
            ];
        }
        return $results;
    }
}
