<?php

namespace SeerrSyncerr\Clients;

use SeerrSyncerr\Support\HttpClient;
use SeerrSyncerr\Support\Logger;

/**
 * Confirmed live 2026-07-06 (SPEC.md §7) against a real Seerr instance —
 * both routes match Overseerr's stable REST API exactly.
 */
class SeerrClient
{
    private HttpClient $http;

    public function __construct(string $baseUrl, string $apiKey, ?Logger $logger = null)
    {
        $this->http = new HttpClient($baseUrl, [
            'X-Api-Key: ' . $apiKey,
        ], $logger);
    }

    public function addComment(int $issueId, string $message): bool
    {
        $response = $this->http->post("/api/v1/issue/{$issueId}/comment", [
            'message' => $message,
        ]);

        return $response['status'] >= 200 && $response['status'] < 300;
    }

    public function resolveIssue(int $issueId): bool
    {
        $response = $this->http->post("/api/v1/issue/{$issueId}/resolved");

        return $response['status'] >= 200 && $response['status'] < 300;
    }
}
