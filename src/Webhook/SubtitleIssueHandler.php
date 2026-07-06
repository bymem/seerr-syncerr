<?php

namespace SeerrSyncerr\Webhook;

use SeerrSyncerr\Clients\BazarrClient;
use SeerrSyncerr\Clients\RadarrClient;
use SeerrSyncerr\Clients\SeerrClient;
use SeerrSyncerr\Clients\SonarrClient;
use SeerrSyncerr\Config;
use SeerrSyncerr\Support\ActionResolver;
use SeerrSyncerr\Support\ExternalTranslationDetector;
use SeerrSyncerr\Support\LanguageResolver;
use SeerrSyncerr\Support\Logger;
use SeerrSyncerr\TranslatorAdapters\AiSubtitleTranslatorAdapter;
use SeerrSyncerr\TranslatorAdapters\BazarrAiTranslateAdapter;
use SeerrSyncerr\TranslatorAdapters\BazarrAutoTranslateAdapter;
use SeerrSyncerr\TranslatorAdapters\CustomAdapter;
use SeerrSyncerr\TranslatorAdapters\ExternalTranslatorAdapter;

/**
 * Orchestrates the whole issue -> resolve -> fix -> comment flow described
 * in SPEC.md §7.
 */
class SubtitleIssueHandler
{
    private Config $config;
    private Logger $logger;
    private SeerrClient $seerr;
    private RadarrClient $radarr;
    private SonarrClient $sonarr;
    private BazarrClient $bazarr;
    private LanguageResolver $languageResolver;
    private ActionResolver $actionResolver;
    private ExternalTranslationDetector $translationDetector;

    public function __construct(Config $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;

        $this->seerr = new SeerrClient((string) $config->get('seerr.url', ''), (string) $config->get('seerr.api_key', ''));
        $this->radarr = new RadarrClient((string) $config->get('radarr.url', ''), (string) $config->get('radarr.api_key', ''));
        $this->sonarr = new SonarrClient((string) $config->get('sonarr.url', ''), (string) $config->get('sonarr.api_key', ''));
        $this->bazarr = new BazarrClient((string) $config->get('bazarr.url', ''), (string) $config->get('bazarr.api_key', ''));

        $this->languageResolver = new LanguageResolver();
        $this->actionResolver = new ActionResolver();
        $this->translationDetector = new ExternalTranslationDetector();
    }

    public function handle(array $payload): void
    {
        if (($payload['notification_type'] ?? null) !== 'ISSUE_CREATED') {
            return;
        }

        $issue = $payload['issue'] ?? [];
        if (($issue['issue_type'] ?? null) !== 'SUBTITLES') {
            return;
        }

        $issueId = (int) ($issue['issue_id'] ?? 0);
        if ($issueId === 0) {
            $this->logger->error('Webhook payload is missing issue.issue_id, aborting.');
            return;
        }

        $media = $payload['media'] ?? [];
        $mediaType = $media['media_type'] ?? null;
        $message = (string) ($payload['message'] ?? '');
        $extra = $payload['extra'] ?? [];

        $targets = $this->resolveTargets($mediaType, $media, $extra);
        if ($targets === null || $targets === []) {
            $this->logger->error("Could not resolve media for issue #{$issueId} to a Radarr/Sonarr id.");
            $this->seerr->addComment(
                $issueId,
                'seerr-syncerr could not resolve this title to a Radarr/Sonarr id — check the Radarr/Sonarr connection in its settings.'
            );
            return;
        }

        $languages = $this->languageResolver->resolve(
            $message,
            (array) $this->config->get('subtitles.main_languages', []),
            (array) $this->config->get('subtitles.language_keywords', [])
        );

        $action = $this->actionResolver->resolve($message, (array) $this->config->get('subtitles.sync_keywords', []));

        $summaryLines = [];
        $allResolved = true;

        foreach ($languages as $language) {
            foreach ($targets as $target) {
                [$line, $resolved] = $this->processTarget($target, (string) $language, $action);
                $summaryLines[] = $line;
                if (!$resolved) {
                    $allResolved = false;
                }
            }
        }

        $this->seerr->addComment($issueId, implode("\n", $summaryLines));

        if ($allResolved) {
            $this->seerr->resolveIssue($issueId);
        }
    }

    /**
     * @return array<int, array{type:string, radarrId?:int, seriesId?:int, episodeId?:int, season?:int, episode?:int}>|null
     */
    private function resolveTargets(?string $mediaType, array $media, array $extra): ?array
    {
        if ($mediaType === 'movie') {
            $tmdbId = (int) ($media['tmdbId'] ?? 0);
            $radarrId = $tmdbId > 0 ? $this->radarr->findRadarrIdByTmdbId($tmdbId) : null;

            return $radarrId === null ? null : [['type' => 'movie', 'radarrId' => $radarrId]];
        }

        if ($mediaType === 'tv') {
            $tvdbId = (int) ($media['tvdbId'] ?? 0);
            $seriesId = $tvdbId > 0 ? $this->sonarr->findSeriesIdByTvdbId($tvdbId) : null;
            if ($seriesId === null) {
                return null;
            }

            $season = null;
            $episode = null;
            foreach ($extra as $entry) {
                if (($entry['name'] ?? null) === 'Affected Season') {
                    $season = (int) $entry['value'];
                }
                if (($entry['name'] ?? null) === 'Affected Episode') {
                    $episode = (int) $entry['value'];
                }
            }

            // Single episode reported.
            if ($season !== null && $episode !== null) {
                $episodeId = $this->sonarr->findEpisodeId($seriesId, $season, $episode);
                if ($episodeId === null) {
                    return null;
                }
                return [[
                    'type' => 'episode',
                    'seriesId' => $seriesId,
                    'episodeId' => $episodeId,
                    'season' => $season,
                    'episode' => $episode,
                ]];
            }

            // Whole season reported.
            if ($season !== null) {
                return array_map(
                    static fn (array $ep): array => [
                        'type' => 'episode',
                        'seriesId' => $seriesId,
                        'episodeId' => $ep['id'],
                        'season' => $ep['season'],
                        'episode' => $ep['episode'],
                    ],
                    $this->sonarr->findEpisodeIdsForSeason($seriesId, $season)
                );
            }

            // Neither present — whole series reported.
            return array_map(
                static fn (array $ep): array => [
                    'type' => 'episode',
                    'seriesId' => $seriesId,
                    'episodeId' => $ep['id'],
                    'season' => $ep['season'],
                    'episode' => $ep['episode'],
                ],
                $this->sonarr->findAllEpisodeIds($seriesId)
            );
        }

        return null;
    }

    /**
     * @return array{0:string, 1:bool} [summary line, whether this reached a definite outcome]
     */
    private function processTarget(array $target, string $language, string $action): array
    {
        $isEpisode = $target['type'] === 'episode';
        $mediaId = $isEpisode ? $target['episodeId'] : $target['radarrId'];
        $label = $isEpisode ? sprintf('S%02dE%02d', $target['season'], $target['episode']) : 'movie';

        if ($action === ActionResolver::ACTION_SYNC) {
            return $this->syncSubtitle($target, $isEpisode, $mediaId, $language, $label);
        }

        return $this->replaceSubtitle($target, $isEpisode, $mediaId, $language, $label);
    }

    private function syncSubtitle(array $target, bool $isEpisode, int $mediaId, string $language, string $label): array
    {
        $release = $this->bazarr->findCurrentSubtitleRelease($mediaId, $language, $isEpisode);
        if ($release === null || $release['path'] === null) {
            $this->logger->warning("No current {$language} subtitle found to sync for {$label}.");
            return ["{$language} ({$label}): no existing subtitle found to sync.", false];
        }

        $ok = $isEpisode
            ? $this->bazarr->syncEpisodeSubtitle($target['seriesId'], $target['episodeId'], $release['path'], $language)
            : $this->bazarr->syncMovieSubtitle($target['radarrId'], $release['path'], $language);

        return $ok
            ? ["{$language} ({$label}): resynced the existing subtitle to audio.", true]
            : ["{$language} ({$label}): resync request to Bazarr failed.", false];
    }

    private function replaceSubtitle(array $target, bool $isEpisode, int $mediaId, string $language, string $label): array
    {
        $release = $this->bazarr->findCurrentSubtitleRelease($mediaId, $language, $isEpisode);

        // Nothing on record to blacklist (e.g. the subtitle was simply
        // missing, like the confirmed "missing subtitles" example payload
        // in SPEC.md §5) — just trigger a fresh search.
        if ($release === null || $release['provider'] === null || $release['subs_id'] === null) {
            $ok = $isEpisode
                ? $this->bazarr->researchEpisode($target['seriesId'], $target['episodeId'])
                : $this->bazarr->researchMovie($target['radarrId']);

            return $ok
                ? ["{$language} ({$label}): no subtitle on record, triggered a fresh search.", true]
                : ["{$language} ({$label}): search request to Bazarr failed.", false];
        }

        $adapterName = (string) $this->config->get('translator.adapter', 'none');
        $externallyTranslated = $adapterName !== 'none'
            && $release['path'] !== null
            && $this->translationDetector->wasExternallyTranslated(
                $release['path'],
                (string) $this->config->get('translator.filename_pattern', '')
            );

        if ($externallyTranslated) {
            return $this->remediateExternalTranslation($target, $isEpisode, $language, $release, $label);
        }

        $ok = $isEpisode
            ? $this->bazarr->blacklistAndResearchEpisode($target['seriesId'], $target['episodeId'], $release['provider'], $release['subs_id'], (string) $release['path'], $language)
            : $this->bazarr->blacklistAndResearchMovie($target['radarrId'], $release['provider'], $release['subs_id'], (string) $release['path'], $language);

        return $ok
            ? ["{$language} ({$label}): blacklisted the current subtitle and searching for a replacement.", true]
            : ["{$language} ({$label}): blacklist/research request to Bazarr failed.", false];
    }

    /**
     * SPEC.md §8 remediation: an external auto-translate tool wrote this
     * file directly, so Bazarr never had it on record. Delete it, fetch a
     * fresh source-language subtitle, then either trigger the external
     * tool directly (if callable) or leave the issue open for its next
     * scheduled pass.
     */
    private function remediateExternalTranslation(array $target, bool $isEpisode, string $language, array $release, string $label): array
    {
        if ($release['path'] !== null && is_string($release['path']) && file_exists($release['path'])) {
            @unlink($release['path']);
        }

        $sourceLanguage = (string) $this->config->get('translator.source_language', 'en');
        $sourceMediaId = $isEpisode ? $target['episodeId'] : $target['radarrId'];
        $sourceRelease = $this->bazarr->findCurrentSubtitleRelease($sourceMediaId, $sourceLanguage, $isEpisode);

        if ($sourceRelease !== null && $sourceRelease['provider'] !== null && $sourceRelease['subs_id'] !== null) {
            $isEpisode
                ? $this->bazarr->blacklistAndResearchEpisode($target['seriesId'], $target['episodeId'], $sourceRelease['provider'], $sourceRelease['subs_id'], (string) $sourceRelease['path'], $sourceLanguage)
                : $this->bazarr->blacklistAndResearchMovie($target['radarrId'], $sourceRelease['provider'], $sourceRelease['subs_id'], (string) $sourceRelease['path'], $sourceLanguage);
        } else {
            $isEpisode
                ? $this->bazarr->researchEpisode($target['seriesId'], $target['episodeId'])
                : $this->bazarr->researchMovie($target['radarrId']);
        }

        $adapter = $this->buildTranslatorAdapter();

        if ($adapter->isCallable()) {
            $adapter->triggerRetranslate((string) ($release['path'] ?? ''));
            return [
                "{$language} ({$label}): detected an externally-translated subtitle, fetched a fresh {$sourceLanguage} source and re-triggered translation.",
                true,
            ];
        }

        return [
            "{$language} ({$label}): detected an externally-translated subtitle, fetched a fresh {$sourceLanguage} source — translation will run on the tool's next scheduled pass, leaving this issue open.",
            false,
        ];
    }

    private function buildTranslatorAdapter(): ExternalTranslatorAdapter
    {
        $toolUrl = (string) $this->config->get('translator.tool_url', '');

        return match ($this->config->get('translator.adapter', 'none')) {
            'bazarr_ai_translate' => new BazarrAiTranslateAdapter(),
            'bazarr_auto_translate' => new BazarrAutoTranslateAdapter(),
            'ai_subtitle_translator' => new AiSubtitleTranslatorAdapter($toolUrl),
            'custom' => new CustomAdapter((bool) $this->config->get('translator.custom_callable', false), $toolUrl),
            default => new class implements ExternalTranslatorAdapter {
                public function isCallable(): bool
                {
                    return false;
                }

                public function triggerRetranslate(string $sourceSubtitlePath): bool
                {
                    return false;
                }
            },
        };
    }
}
