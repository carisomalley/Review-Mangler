<?php

namespace App\Services;

use App\Env;

/**
 * Tier A metadata source for books (CLAUDE.md §7.1 / §9.5). No API key
 * required for basic search volume, though a key raises the rate limit —
 * GOOGLE_BOOKS_API_KEY in .env is optional.
 */
class GoogleBooksClient
{
    private const BASE_URL = 'https://www.googleapis.com/books/v1/volumes';

    /**
     * @return array<int, array{external_id:string, name:string, year:?string, poster_url:?string, overview:string}>
     */
    public function search(string $query): array
    {
        $params = ['q' => $query];
        $apiKey = Env::get('GOOGLE_BOOKS_API_KEY');
        if ($apiKey) {
            $params['key'] = $apiKey;
        }

        $url = self::BASE_URL . '?' . http_build_query($params);
        $response = HttpClient::get($url, ['Accept: application/json']);
        if ($response['status'] !== 200) {
            throw new \RuntimeException('Google Books search failed with HTTP ' . $response['status'] . ': ' . $response['body']);
        }

        $data = json_decode($response['body'], true);
        $results = [];
        foreach (($data['items'] ?? []) as $item) {
            $info = $item['volumeInfo'] ?? [];
            $authors = implode(', ', $info['authors'] ?? []);
            $results[] = [
                'external_id' => $item['id'] ?? '',
                'name' => $info['title'] ?? '',
                'creator_name' => $authors,
                'year' => !empty($info['publishedDate']) ? substr($info['publishedDate'], 0, 4) : null,
                'poster_url' => $info['imageLinks']['thumbnail'] ?? null,
                'overview' => $info['description'] ?? '',
            ];
        }
        return $results;
    }
}
