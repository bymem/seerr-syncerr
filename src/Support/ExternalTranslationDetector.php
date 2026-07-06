<?php

namespace SeerrSyncerr\Support;

/**
 * Detects whether the subtitle currently on disk was written by an external
 * auto-translate tool rather than Bazarr itself (SPEC.md §8). Two
 * independent signals, either is sufficient:
 *
 *   1. No matching real-download entry in Bazarr's history for that language.
 *   2. The filename matches the user-configured pattern.
 */
class ExternalTranslationDetector
{
    /** Confirmed from a live traceback: action==4 means "manually uploaded". */
    private const HISTORY_ACTION_MANUAL_UPLOAD = 4;

    public function wasExternallyTranslated(
        string $subtitlePath,
        string $filenamePattern,
        ?array $historyEntries = null
    ): bool {
        if ($filenamePattern !== '' && @preg_match($filenamePattern, $subtitlePath) === 1) {
            return true;
        }

        if ($historyEntries !== null) {
            $matchesCurrentFile = false;
            foreach ($historyEntries as $entry) {
                $entryPath = $entry['subtitles_path'] ?? $entry['path'] ?? null;
                if ($entryPath === $subtitlePath
                    && (int) ($entry['action'] ?? -1) !== self::HISTORY_ACTION_MANUAL_UPLOAD
                ) {
                    $matchesCurrentFile = true;
                    break;
                }
            }

            if (!$matchesCurrentFile) {
                return true;
            }
        }

        return false;
    }
}
