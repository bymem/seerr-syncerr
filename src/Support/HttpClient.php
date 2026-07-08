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
    private ?Logger $logger;

    public function __construct(string $baseUrl, array $headers = [], ?Logger $logger = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->headers = $headers;
        $this->logger = $logger;
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

    /**
     * Bazarr's actual "run an action" endpoints (/api/movies, /api/subtitles,
     * /api/episodes/subtitles) are PATCH, not POST — confirmed against
     * Bazarr's real source (flask_restx resources only define patch(), no
     * post(), for these routes) after a live 500 showed the POST-based
     * version was hitting the wrong handler entirely.
     */
    public function patchMultipart(string $path, array $formFields, array $query = []): array
    {
        $url = $this->buildUrl($path, $query);
        return $this->execute('PATCH', $url, $formFields, false);
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
        $curlError = $response === false ? curl_error($ch) : null;
        curl_close($ch);

        $decoded = null;
        if ($response !== false && $response !== '') {
            $decoded = json_decode($response, true);
        }

        // Every non-2xx/cURL-level failure gets logged right here — this is
        // the one chokepoint every API call (Seerr/Radarr/Sonarr/Bazarr)
        // passes through, so callers don't each need their own diagnostic
        // logging to explain *why* a request failed, not just that it did.
        if ($this->logger !== null && ($curlError !== null || $status < 200 || $status >= 300)) {
            $reason = $curlError !== null
                ? "connection error: {$curlError}"
                : 'status=' . $status . ($response !== false && $response !== '' ? ' body=' . substr((string) $response, 0, 300) : '');
            $this->logger->warning("HTTP {$method} {$url} failed — {$reason}");
        }

        return ['status' => $status, 'body' => $decoded];
    }
}
