<?php

namespace SeerrSyncerr\Support;

/**
 * Thin cURL wrapper shared by every API client (Seerr/Radarr/Sonarr/Bazarr).
 * Each client is responsible for its own base URL and auth headers.
 */
class HttpClient
{
    private string $baseUrl;
    private array $headers;

    public function __construct(string $baseUrl, array $headers = [])
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->headers = $headers;
    }

    public function get(string $path, array $query = []): array
    {
        $url = $this->buildUrl($path, $query);
        return $this->execute('GET', $url);
    }

    public function post(string $path, array $body = [], array $query = []): array
    {
        $url = $this->buildUrl($path, $query);
        return $this->execute('POST', $url, json_encode($body), true);
    }

    public function put(string $path, array $body = [], array $query = []): array
    {
        $url = $this->buildUrl($path, $query);
        return $this->execute('PUT', $url, json_encode($body), true);
    }

    /**
     * Bazarr's subtitle-action endpoints expect multipart/form-data, not
     * JSON, unlike every other API this project talks to (confirmed live,
     * SPEC.md §7 HttpClient note). Passing a plain array (not json_encode'd)
     * as CURLOPT_POSTFIELDS makes cURL send it as multipart automatically —
     * this is why the method exists separately from post() rather than
     * being redundant with it.
     */
    public function postMultipart(string $path, array $formFields, array $query = []): array
    {
        $url = $this->buildUrl($path, $query);
        return $this->execute('POST', $url, $formFields, false);
    }

    private function buildUrl(string $path, array $query): string
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $url;
    }

    /**
     * @param string|array|null $body
     */
    private function execute(string $method, string $url, $body = null, bool $isJson = false): array
    {
        $ch = curl_init($url);

        $headers = $this->headers;
        if ($isJson) {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = null;
        if ($response !== false && $response !== '') {
            $decoded = json_decode($response, true);
        }

        return ['status' => $status, 'body' => $decoded];
    }
}
