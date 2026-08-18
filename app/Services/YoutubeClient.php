<?php

namespace App\Services;

use App\Env;

/**
 * Tier A source: video reviews AND their top comments (CLAUDE.md §7.2,
 * §9.5 — explicitly called out because reaction/video-essay commentary is a
 * realistic source of both criticism and personal remarks for the seed
 * documentary use case). Each matched video becomes one review (its
 * description), plus up to COMMENTS_PER_VIDEO of its top comments as
 * separate reviews, so a cruel comment doesn't hide inside an otherwise
 * respectful video's aggregate score.
 */
class YoutubeClient
{
    private const SEARCH_URL = 'https://www.googleapis.com/youtube/v3/search';
    private const COMMENTS_URL = 'https://www.googleapis.com/youtube/v3/commentThreads';
    private const COMMENTS_PER_VIDEO = 5;

    /**
     * @return array<int, array{external_url:string, headline:string, author:?string, published_at:?string, text:string}>
     */
    public function search(string $query): array
    {
        $apiKey = Env::require('YOUTUBE_API_KEY');

        $searchUrl = self::SEARCH_URL . '?' . http_build_query([
            'key' => $apiKey,
            'part' => 'snippet',
            'q' => $query . ' review',
            'type' => 'video',
            'maxResults' => 15,
            'relevanceLanguage' => 'en',
        ]);

        $response = HttpClient::get($searchUrl);
        if ($response['status'] !== 200) {
            throw new \RuntimeException('YouTube search failed with HTTP ' . $response['status'] . ': ' . $response['body']);
        }

        $data = json_decode($response['body'], true);
        $results = [];

        foreach (($data['items'] ?? []) as $item) {
            $videoId = $item['id']['videoId'] ?? null;
            $snippet = $item['snippet'] ?? [];
            if (!$videoId) {
                continue;
            }

            $results[] = [
                'external_url' => "https://www.youtube.com/watch?v={$videoId}",
                'headline' => $snippet['title'] ?? '(untitled video)',
                'author' => $snippet['channelTitle'] ?? null,
                'published_at' => $snippet['publishedAt'] ?? null,
                'text' => $snippet['description'] ?? '',
            ];

            foreach ($this->topComments($videoId, $apiKey) as $comment) {
                $results[] = [
                    'external_url' => "https://www.youtube.com/watch?v={$videoId}&lc={$comment['id']}",
                    'headline' => 'Comment on: ' . ($snippet['title'] ?? '(untitled video)'),
                    'author' => $comment['author'],
                    'published_at' => $comment['published_at'],
                    'text' => $comment['text'],
                ];
            }
        }

        return $results;
    }

    /**
     * @return array<int, array{id:string, author:?string, published_at:?string, text:string}>
     */
    private function topComments(string $videoId, string $apiKey): array
    {
        $url = self::COMMENTS_URL . '?' . http_build_query([
            'key' => $apiKey,
            'part' => 'snippet',
            'videoId' => $videoId,
            'order' => 'relevance',
            'maxResults' => self::COMMENTS_PER_VIDEO,
            'textFormat' => 'plainText',
        ]);

        $response = HttpClient::get($url);
        if ($response['status'] !== 200) {
            // Comments can be disabled on a video — that's normal, not an error
            // worth failing the whole source over.
            return [];
        }

        $data = json_decode($response['body'], true);
        $comments = [];
        foreach (($data['items'] ?? []) as $item) {
            $top = $item['snippet']['topLevelComment']['snippet'] ?? null;
            if (!$top) {
                continue;
            }
            $comments[] = [
                'id' => $item['id'],
                'author' => $top['authorDisplayName'] ?? null,
                'published_at' => $top['publishedAt'] ?? null,
                'text' => $top['textOriginal'] ?? ($top['textDisplay'] ?? ''),
            ];
        }
        return $comments;
    }
}
