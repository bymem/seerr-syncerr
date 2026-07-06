<?php

namespace SeerrSyncerr\Clients;

use SeerrSyncerr\Support\HttpClient;

class SonarrClient
{
    private HttpClient $http;

    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->http = new HttpClient($baseUrl, [
            'X-Api-Key: ' . $apiKey,
        ]);
    }

    public function findSeriesIdByTvdbId(int $tvdbId): ?int
    {
        $response = $this->http->get('/api/v3/series', ['tvdbId' => $tvdbId]);

        if ($response['status'] !== 200 || !is_array($response['body'])) {
            return null;
        }

        $series = $response['body'][0] ?? null;

        return isset($series['id']) ? (int) $series['id'] : null;
    }

    public function findEpisodeId(int $seriesId, int $season, int $episode): ?int
    {
        foreach ($this->allEpisodes($seriesId) as $ep) {
            if ((int) $ep['seasonNumber'] === $season && (int) $ep['episodeNumber'] === $episode) {
                return (int) $ep['id'];
            }
        }

        return null;
    }

    /**
     * Every episode in one season, for the "whole season reported" case.
     * Returns season/episode numbers alongside the id (deviates slightly
     * from SPEC.md §7's "return array of ids" wording) so the webhook
     * handler can build a readable per-episode summary line instead of
     * just citing Sonarr's internal episode id.
     *
     * @return array<array{id:int, season:int, episode:int}>
     */
    public function findEpisodeIdsForSeason(int $seriesId, int $season): array
    {
        $matches = [];
        foreach ($this->allEpisodes($seriesId) as $ep) {
            if ((int) $ep['seasonNumber'] === $season) {
                $matches[] = [
                    'id' => (int) $ep['id'],
                    'season' => (int) $ep['seasonNumber'],
                    'episode' => (int) $ep['episodeNumber'],
                ];
            }
        }

        return $matches;
    }

    /**
     * Every episode across every season, for the "whole series reported" case.
     *
     * @return array<array{id:int, season:int, episode:int}>
     */
    public function findAllEpisodeIds(int $seriesId): array
    {
        return array_map(
            static fn (array $ep): array => [
                'id' => (int) $ep['id'],
                'season' => (int) $ep['seasonNumber'],
                'episode' => (int) $ep['episodeNumber'],
            ],
            $this->allEpisodes($seriesId)
        );
    }

    /**
     * All three lookup methods above share this one call and just filter
     * client-side — no reason to hit Sonarr three separate times.
     */
    private function allEpisodes(int $seriesId): array
    {
        $response = $this->http->get('/api/v3/episode', ['seriesId' => $seriesId]);

        if ($response['status'] !== 200 || !is_array($response['body'])) {
            return [];
        }

        return $response['body'];
    }
}
