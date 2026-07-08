<?php

namespace SeerrSyncerr\TranslatorAdapters;

use SeerrSyncerr\Support\HttpClient;
use SeerrSyncerr\Support\Logger;

/**
 * User-supplied "callable" toggle + URL from the settings UI, for any
 * auto-translate tool not covered by a bundled adapter (SPEC.md §8).
 */
class CustomAdapter implements ExternalTranslatorAdapter
{
    private bool $callable;
    private string $url;
    private ?Logger $logger;

    public function __construct(bool $callable, string $url, ?Logger $logger = null)
    {
        $this->callable = $callable;
        $this->url = $url;
        $this->logger = $logger;
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

        $client = new HttpClient($this->url, [], $this->logger);
        $response = $client->post('', ['path' => $sourceSubtitlePath]);

        return $response['status'] >= 200 && $response['status'] < 300;
    }
}
