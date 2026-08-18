<?php

namespace App\Services;

use App\Env;

/**
 * Tier A source: Reddit mentions/discussion threads (CLAUDE.md §7.2, §9.5).
 *
 * IMPORTANT — verify before relying on this in production: Reddit's Data API
 * terms and pricing changed materially in 2023 and are worth re-checking at
 * https://www.reddit.com/wiki/api/ before a real deployment — this client is
 * written for the low-volume, read-only, "app-only" OAuth flow (client
 * credentials grant), which historically covered exactly this kind of
 * periodic public search. If Reddit's terms no longer fit, this is the only
 * file that talks to Reddit, so swapping providers is a one-file change.
 *
 * Register an app at https://www.reddit.com/prefs/apps (choose "script" or
 * "web app") to get REDDIT_CLIENT_ID / REDDIT_CLIENT_SECRET. Reddit strictly
 * enforces a descriptive User-Agent identifying your app — set
 * REDDIT_USER_AGENT to something like "ReviewMangler/1.0 (by /u/yourname)".
 */
class RedditClient
{
    private const TOKEN_URL = 'https://www.reddit.com/api/v1/access_token';
    private const SEARCH_URL = 'https://oauth.reddit.com/search';

    /**
     * @return array<int, array{external_url:string, headline:string, author:?string, published_at:?string, text:string}>
     */
    public function search(string $query): array
    {
        $token = $this->fetchAppOnlyToken();
        $userAgent = Env::require('REDDIT_USER_AGENT');

        $url = self::SEARCH_URL . '?' . http_build_query([
            'q' => $query,
            'sort' => 'relevance',
            'limit' => 25,
            'type' => 'link',
        ]);

        $response = HttpClient::get($url, [
            "Authorization: Bearer $token",
            "User-Agent: $userAgent",
        ]);

        if ($response['status'] !== 200) {
            throw new \RuntimeException('Reddit search failed with HTTP ' . $response['status'] . ': ' . $response['body']);
        }

        $data = json_decode($response['body'], true);
        $results = [];
        foreach (($data['data']['children'] ?? []) as $child) {
            $post = $child['data'] ?? [];
            if (empty($post['permalink'])) {
                continue;
            }
            // Post title + selftext is the "discussion thread mention" per
            // §7.2 — pulling each thread's top comments too is a reasonable
            // future enhancement, kept out for now to bound API calls.
            $text = trim(($post['title'] ?? '') . "\n\n" . ($post['selftext'] ?? ''));
            $results[] = [
                'external_url' => 'https://www.reddit.com' . $post['permalink'],
                'headline' => $post['title'] ?? '(untitled post)',
                'author' => isset($post['author']) ? 'u/' . $post['author'] : null,
                'published_at' => isset($post['created_utc']) ? date('c', (int) $post['created_utc']) : null,
                'text' => $text,
            ];
        }
        return $results;
    }

    private function fetchAppOnlyToken(): string
    {
        $clientId = Env::require('REDDIT_CLIENT_ID');
        $clientSecret = Env::require('REDDIT_CLIENT_SECRET');
        $userAgent = Env::require('REDDIT_USER_AGENT');

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_USERPWD => "$clientId:$clientSecret",
            CURLOPT_HTTPHEADER => ["User-Agent: $userAgent"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            throw new \RuntimeException('Reddit OAuth token request failed with HTTP ' . $status);
        }

        $data = json_decode($body, true);
        if (empty($data['access_token'])) {
            throw new \RuntimeException('Reddit OAuth token response missing access_token');
        }
        return $data['access_token'];
    }
}
