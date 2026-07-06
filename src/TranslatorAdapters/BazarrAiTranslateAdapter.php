<?php

namespace SeerrSyncerr\TranslatorAdapters;

/**
 * Bazarr-AI-Translate (https://github.com/nirkons/Bazarr-AI-Translate) runs
 * as a Tautulli/cron-triggered script, not an HTTP server — nothing to call
 * on demand.
 */
class BazarrAiTranslateAdapter implements ExternalTranslatorAdapter
{
    public function isCallable(): bool
    {
        return false;
    }

    public function triggerRetranslate(string $sourceSubtitlePath): bool
    {
        return false;
    }
}
