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

    /**
     * Bazarr's blacklist POST handler already triggers a re-download itself
     * once the delete succeeds (`movies_download_subtitles()` internally) —
     * confirmed reading Bazarr's real source (api/movies/blacklist.py). No
     * separate research() call needed/wanted after this succeeds; doing one
     * anyway would just be a second, redundant provider search.
     */
    public function blacklistAndResearchMovie(
        int $radarrId,
        string $provider,
        string $subsId,
        string $subtitlePath,
        string $language
    ): bool {
        $blacklisted = $this->http->postMultipart('/api/movies/blacklist', [
            'radarrid' => $radarrId,
            'provider' => $provider,
            'subs_id' => $subsId,
            'subtitles_path' => $subtitlePath,
            'language' => $language,
        ]);

        return $blacklisted['status'] >= 200 && $blacklisted['status'] < 300;
    }

    /**
     * Mirrors blacklistAndResearchMovie() — confirmed against Bazarr's real
     * source (api/episodes/blacklist.py), same "already triggers its own
     * re-download" behavior.
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
            'seriesid' => $seriesId,
            'episodeid' => $episodeId,
            'provider' => $provider,
            'subs_id' => $subsId,
            'subtitles_path' => $subtitlePath,
            'language' => $language,
        ]);

        return $blacklisted['status'] >= 200 && $blacklisted['status'] < 300;
    }

    /**
     * Triggers a search-missing pass with no preceding blacklist — needed
     * when a report says "missing subtitles" and there's nothing on record
     * to blacklist in the first place (the exact example payload in
     * SPEC.md §5).
     *
     * Confirmed against Bazarr's real source (api/movies/movies.py): this is
     * `PATCH /api/movies` — not POST, which a live 500 exposed on 2026-07-08
     * (POST on this resource hits an unrelated "update movie profile"
     * handler and crashes on a missing field it assumes is present).
     * `action` dispatches to `movies_download_subtitles(radarrid)`, which
     * searches every currently-missing language on the movie's profile, not
     * just the one we're trying to fix — accepted as-is since there's no
     * single-language equivalent for movies the way there is for episodes
     * (see researchEpisode() below).
     */
    public function researchMovie(int $radarrId): bool
    {
        $research = $this->http->patchMultipart('/api/movies', [
            'radarrid' => $radarrId,
            'action' => 'search-missing',
        ]);

        return $research['status'] >= 200 && $research['status'] < 300;
    }

    /**
     * Unlike movies, there's no bulk "search everything missing" endpoint
     * for a single episode (`/api/episodes` only defines GET) — confirmed
     * against Bazarr's real source. The closest equivalent is the *targeted*
     * single-language download endpoint episodes actually have
     * (`PATCH /api/episodes/subtitles`), which is arguably a better fit
     * anyway: it searches exactly the language being fixed instead of
     * everything missing on the episode's profile.
     */
    public function researchEpisode(int $seriesId, int $episodeId, string $language): bool
    {
        $research = $this->http->patchMultipart('/api/episodes/subtitles', [
            'seriesid' => $seriesId,
            'episodeid' => $episodeId,
            'language' => $language,
            'forced' => 'False',
            'hi' => 'False',
        ]);

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
     * Confirmed against Bazarr's real source (api/subtitles/subtitles.py):
     * `PATCH /api/subtitles`, not POST — that resource has no post() at all,
     * so every prior call here would have 404/405'd (never actually
     * exercised in testing yet, since it's only reached via a "sync"
     * keyword match). `hi`/`forced`/`gss` are compared with an exact-case
     * `== 'True'` in Bazarr's handler, no normalization — lowercase 'true'
     * silently evaluates to False, so those must be sent capitalized.
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
        $response = $this->http->patchMultipart('/api/subtitles', [
            'action' => 'sync',
            'type' => $type,
            'path' => $subtitlePath,
            'id' => $id,
            'language' => $language,
            'hi' => $hi ? 'True' : 'False',
            'forced' => $forced ? 'True' : 'False',
            'gss' => 'True',
        ]);

        return $response['status'] >= 200 && $response['status'] < 300;
    }
}
