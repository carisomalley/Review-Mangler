<?php

namespace App\Services;

/**
 * Thin cURL wrapper shared by every external API client. Kept dependency-free
 * (no Guzzle/composer) so the app runs on plain shared hosting.
 */
class HttpClient
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, effective_url:string}
     */
    public static function get(string $url, array $headers = [], int $timeoutSeconds = 15): array
    {
        return self::request('GET', $url, null, $headers, $timeoutSeconds);
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, effective_url:string}
     */
    public static function postJson(string $url, array $payload, array $headers = [], int $timeoutSeconds = 30): array
    {
        $headers[] = 'Content-Type: application/json';
        return self::request('POST', $url, json_encode($payload), $headers, $timeoutSeconds);
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, effective_url:string}
     */
    private static function request(string $method, string $url, ?string $body, array $headers, int $timeoutSeconds): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("HTTP request to $url failed: $error");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // Used by scrapers that get redirected to a canonical URL (e.g.
        // Letterboxd's /tmdb/{id}/ -> /film/{slug}/) and need to know where
        // they actually landed, not just the URL they requested.
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        return ['status' => $status, 'body' => $responseBody, 'effective_url' => $effectiveUrl];
    }
}
