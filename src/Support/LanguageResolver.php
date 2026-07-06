<?php

namespace SeerrSyncerr\Support;

/**
 * Turns a reporter's free-text comment into the list of language codes to
 * fix. See SPEC.md §4.1 / §7 for the reasoning behind the two-path design.
 */
class LanguageResolver
{
    /**
     * @param array $mainLanguages ordered list of language codes, e.g. ['da', 'en']
     * @param array $keywordMap keyword => language code, e.g. ['english' => 'en']
     * @return array language codes to fix, in priority order
     */
    public function resolve(string $comment, array $mainLanguages, array $keywordMap): array
    {
        $words = preg_split('/[^a-z0-9]+/i', strtolower($comment)) ?: [];

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            foreach ($keywordMap as $keyword => $languageCode) {
                if (strtolower((string) $keyword) === $word) {
                    return [$languageCode];
                }
            }
        }

        return $mainLanguages;
    }
}
