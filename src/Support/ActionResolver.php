<?php

namespace SeerrSyncerr\Support;

/**
 * Turns a reporter's free-text comment into which kind of fix to run.
 * See SPEC.md §4.1 / §7.1 — sync (realign existing file) vs. replace
 * (blacklist + research) are independent of which language is affected.
 */
class ActionResolver
{
    public const ACTION_SYNC = 'sync';
    public const ACTION_REPLACE = 'replace';

    /**
     * @param array $syncKeywords phrases like "out of sync", "timing"
     */
    public function resolve(string $comment, array $syncKeywords): string
    {
        $haystack = strtolower($comment);

        foreach ($syncKeywords as $phrase) {
            $phrase = strtolower((string) $phrase);
            if ($phrase !== '' && str_contains($haystack, $phrase)) {
                return self::ACTION_SYNC;
            }
        }

        return self::ACTION_REPLACE;
    }
}
