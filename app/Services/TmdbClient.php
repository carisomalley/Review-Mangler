<?php

namespace App\Services;

use App\Env;

/**
 * Tier A metadata source for films (CLAUDE.md §7.1 / §9.5). Free API, used to
 * verify a title before we start tracking it — not for review content.
 */
class TmdbClient
{
    private const BASE_URL = 'https://api.themoviedb.org/3';

    /**
     * @return array<int, array{external_id:string, name:string, year:?string, poster_url:?string, overview:string}>
     */
    public function search(string $query): array
    {
        $apiKey = Env::require('TMDB_API_KEY');
        $url = self::BASE_URL . '/search/movie?' . http_build_query([
            'query' => $query,
            'include_adult' => 'false',
        ]);

        $response = HttpClient::get($url, ["Authorization: Bearer $apiKey", 'Accept: application/json']);
        if ($response['status'] !== 200) {
            throw new \RuntimeException('TMDB search failed with HTTP ' . $response['status']);
        }

        $data = json_decode($response['body'], true);
        $results = [];
        foreach (($data['results'] ?? []) as $r) {
            $results[] = [
                'external_id' => (string) $r['id'],
                'name' => $r['title'] ?? '',
                'year' => !empty($r['release_date']) ? substr($r['release_date'], 0, 4) : null,
                'poster_url' => !empty($r['poster_path']) ? 'https://image.tmdb.org/t/p/w200' . $r['poster_path'] : null,
                'overview' => $r['overview'] ?? '',
            ];
        }
        return $results;
    }
}
