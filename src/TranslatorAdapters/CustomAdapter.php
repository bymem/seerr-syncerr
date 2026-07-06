<?php

namespace SeerrSyncerr\TranslatorAdapters;

use SeerrSyncerr\Support\HttpClient;

/**
 * User-supplied "callable" toggle + URL from the settings UI, for any
 * auto-translate tool not covered by a bundled adapter (SPEC.md §8).
 */
class CustomAdapter implements ExternalTranslatorAdapter
{
    private bool $callable;
    private string $url;

    public function __construct(bool $callable, string $url)
    {
        $this->callable = $callable;
        $this->url = $url;
    }

    public function isCallable(): bool
    {
        return $this->callable && $this->url !== '';
    }

    public function triggerRetranslate(string $sourceSubtitlePath): bool
    {
        if (!$this->isCallable()) {
            return false;
        }

        $client = new HttpClient($this->url);
        $response = $client->post('', ['path' => $sourceSubtitlePath]);

        return $response['status'] >= 200 && $response['status'] < 300;
    }
}
