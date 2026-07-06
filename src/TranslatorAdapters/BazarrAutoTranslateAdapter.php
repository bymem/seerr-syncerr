<?php

namespace SeerrSyncerr\TranslatorAdapters;

/**
 * Bazarr_AutoTranslate (https://github.com/anast20sm/Bazarr_AutoTranslate)
 * is the same script-agent pattern as Bazarr-AI-Translate — no on-demand
 * call available.
 */
class BazarrAutoTranslateAdapter implements ExternalTranslatorAdapter
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
