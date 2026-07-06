<?php

namespace SeerrSyncerr\TranslatorAdapters;

use SeerrSyncerr\Support\HttpClient;

/**
 * ai-subtitle-translator (https://pypi.org/project/ai-subtitle-translator/)
 * runs its own FastAPI server, so it's the one bundled adapter that's
 * actually callable on demand (SPEC.md §8).
 */
class AiSubtitleTranslatorAdapter implements ExternalTranslatorAdapter
{
    private string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = $baseUrl;
    }

    public function isCallable(): bool
    {
        return $this->baseUrl !== '';
    }

    public function triggerRetranslate(string $sourceSubtitlePath): bool
    {
        if (!$this->isCallable()) {
            return false;
        }

        $client = new HttpClient($this->baseUrl);
        $response = $client->post('/process', ['path' => $sourceSubtitlePath]);

        return $response['status'] >= 200 && $response['status'] < 300;
    }
}
