<?php

namespace SeerrSyncerr\Clients;

use SeerrSyncerr\Support\HttpClient;
use SeerrSyncerr\Support\Logger;

/**
 * Confirmed against Bazarr's own source (morpheus65535/bazarr, bazarr/api/)
 * and live-captured requests from Bazarr's UI (2026-07-06) — see SPEC.md §7
 * for the full trail of what's confirmed vs. assumed-by-symmetry.
 */
class BazarrClient
{
    /** Confirmed live traceback: action==4 means "manually uploaded". */
    private const HISTORY_ACTION_MANUAL_UPLOAD = 4;

    private HttpClient $http;

    public function __construct(string $baseUrl, string $apiKey, ?Logger $logger = null)
    {
        $this->http = new HttpClient($baseUrl, [
            'X-API-KEY: ' . $apiKey,
        ], $logger);
    }

    public function findMovieByRadarrId(int $radarrId): ?array
    {
        $response = $this->http->get('/api/movies', ['radarrid[]' => $radarrId]);

        if ($response['status'] !== 200 || !is_array($response['body']['data'] ?? null)) {
            return null;
        }

        return $response['body']['data'][0] ?? null;
    }

    public function findEpisodeBySonarrIds(int $seriesId, int $episodeId): ?array
    {
        $response = $this->http->get('/api/episodes', [
            'seriesid[]' => $seriesId,
            'episodeid[]' => $episodeId,
        ]);

        if ($response['status'] !== 200 || !is_array($response['body']['data'] ?? null)) {
            return null;
        }

        return $response['body']['data'][0] ?? null;
    }

    /**
     * Bazarr blacklists a specific release (provider + subs_id), not "the
     * Danish subtitle for movie X" — this looks up the most recent matching
     * real download so the caller has something concrete to blacklist.
     */
    public function findCurrentSubtitleRelease(int $mediaId, string $language, bool $isEpisode): ?array
    {
        $path = $isEpisode ? '/api/episodes/history' : '/api/movies/history';
        $idParam = $isEpisode ? 'episodeid' : 'radarrid';

        $response = $this->http->get($path, [$idParam => $mediaId]);

        if ($response['status'] !== 200 || !is_array($response['body']['data'] ?? null)) {
            return null;
        }

        foreach ($response['body']['data'] as $entry) {
            $entryLanguage = $entry['language']['code2'] ?? $entry['language'] ?? null;
            $action = (int) ($entry['action'] ?? -1);

            if ($entryLanguage === $language && $action !== self::HISTORY_ACTION_MANUAL_UPLOAD) {
                return [
                    'provider' => $entry['provider'] ?? null,
                    'subs_id' => $entry['subs_id'] ?? null,
                    'path' => $entry['subtitles_path'] ?? $entry['path'] ?? null,
                ];
            }
        }

        return null;
    }

    public function blacklistAndResearchMovie(
        int $radarrId,
        string $provider,
        string $subsId,
        string $subtitlePath,
        string $language
    ): bool {
        $blacklisted = $this->http->postMultipart('/api/movies/blacklist', [
            'provider' => $provider,
            'subs_id' => $subsId,
            'subtitles_path' => $subtitlePath,
            'language' => $language,
        ], ['radarrid' => $radarrId]);

        if ($blacklisted['status'] < 200 || $blacklisted['status'] >= 300) {
            return false;
        }

        return $this->researchMovie($radarrId);
    }

    /**
     * Mirrors blacklistAndResearchMovie(), unconfirmed live but low-risk
     * given how consistently movies/episodes mirror each other elsewhere
     * (SPEC.md §7/§11 open item).
     */
    public function blacklistAndResearchEpisode(
        int $seriesId,
        int $episodeId,
        string $provider,
        string $subsId,
        string $subtitlePath,
        string $language
    ): bool {
        $blacklisted = $this->http->postMultipart('/api/episodes/blacklist', [
            'provider' => $provider,
            'subs_id' => $subsId,
            'subtitles_path' => $subtitlePath,
            'language' => $language,
        ], ['seriesid' => $seriesId, 'episodeid' => $episodeId]);

        if ($blacklisted['status'] < 200 || $blacklisted['status'] >= 300) {
            return false;
        }

        return $this->researchEpisode($seriesId, $episodeId);
    }

    /**
     * Triggers a search-missing pass with no preceding blacklist — needed
     * when a report says "missing subtitles" and there's nothing on record
     * to blacklist in the first place (the exact example payload in
     * SPEC.md §5).
     *
     * `action` goes in the query string, not the multipart body — same
     * placement as sync()'s `?action=sync` below. A live 500 from Bazarr
     * (2026-07-08) showed the previous version, which put `action` in the
     * body alongside `radarrid`, was wrong despite SPEC.md's "(multipart,
     * same as sync)" note — this wasn't actually matching sync's shape.
     */
    public function researchMovie(int $radarrId): bool
    {
        $research = $this->http->postMultipart('/api/movies', [
            'radarrid' => $radarrId,
        ], ['action' => 'search-missing']);

        return $research['status'] >= 200 && $research['status'] < 300;
    }

    public function researchEpisode(int $seriesId, int $episodeId): bool
    {
        $research = $this->http->postMultipart('/api/episodes', [
            'seriesid' => $seriesId,
            'episodeid' => $episodeId,
        ], ['action' => 'search-missing']);

        return $research['status'] >= 200 && $research['status'] < 300;
    }

    public function syncMovieSubtitle(
        int $radarrId,
        string $subtitlePath,
        string $language,
        bool $hi = false,
        bool $forced = false
    ): bool {
        return $this->sync('movie', $radarrId, $subtitlePath, $language, $hi, $forced);
    }

    public function syncEpisodeSubtitle(
        int $seriesId,
        int $episodeId,
        string $subtitlePath,
        string $language,
        bool $hi = false,
        bool $forced = false
    ): bool {
        return $this->sync('episode', $episodeId, $subtitlePath, $language, $hi, $forced);
    }

    /**
     * Confirmed live: POST /api/subtitles?action=sync, multipart/form-data.
     * Realigns the existing file to audio via ffsubsync (gss = Golden
     * Section Search) — no blacklist or re-download involved.
     */
    private function sync(
        string $type,
        int $id,
        string $subtitlePath,
        string $language,
        bool $hi,
        bool $forced
    ): bool {
        $response = $this->http->postMultipart('/api/subtitles', [
            'type' => $type,
            'path' => $subtitlePath,
            'id' => $id,
            'language' => $language,
            'hi' => $hi ? 'true' : 'false',
            'forced' => $forced ? 'true' : 'false',
            'gss' => 'true',
        ], ['action' => 'sync']);

        return $response['status'] >= 200 && $response['status'] < 300;
    }
}
