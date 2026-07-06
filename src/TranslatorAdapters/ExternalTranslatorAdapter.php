<?php

namespace SeerrSyncerr\TranslatorAdapters;

/**
 * The entire adapter contract for an external auto-translate tool
 * (SPEC.md §8): can we call it on demand, and how? Everything else
 * (folder naming, filename conventions) is per-install config, not
 * something baked into the adapter class.
 */
interface ExternalTranslatorAdapter
{
    public function isCallable(): bool;

    /**
     * Only meaningful when isCallable() is true; no-op otherwise.
     */
    public function triggerRetranslate(string $sourceSubtitlePath): bool;
}
