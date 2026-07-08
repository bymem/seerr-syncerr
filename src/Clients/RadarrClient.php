<?php

namespace SeerrSyncerr\Clients;

use SeerrSyncerr\Support\HttpClient;
use SeerrSyncerr\Support\Logger;

class RadarrClient
{
    private HttpClient $http;

    public function __construct(string $baseUrl, string $apiKey, ?Logger $logger = null)
    {
        $this->http = new HttpClient($baseUrl, [
            'X-Api-Key: ' . $apiKey,
        ], $logger);
    }

    public function findRadarrIdByTmdbId(int $tmdbId): ?int
    {
        $response = $this->http->get('/api/v3/movie', ['tmdbId' => $tmdbId]);

        if ($response['status'] !== 200 || !is_array($response['body'])) {
            return null;
        }

        $movie = $response['body'][0] ?? null;

        return isset($movie['id']) ? (int) $movie['id'] : null;
    }
}
