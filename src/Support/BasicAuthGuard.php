<?php

namespace SeerrSyncerr\Support;

/**
 * Guards the settings UI with HTTP Basic Auth. The webhook endpoint has its
 * own secret-based auth (WebhookController) and is untouched by this —
 * this only protects the pages that expose every configured service's API
 * key and the webhook secret itself.
 */
class BasicAuthGuard
{
    public static function verify(): bool
    {
        $expectedPassword = (string) getenv('WEBUI_PASSWORD');

        if ($expectedPassword === '') {
            // Should never happen in the container — entrypoint.sh refuses
            // to start without WEBUI_PASSWORD set — but fail closed rather
            // than open if it's somehow missing (e.g. running php -S
            // directly without the env var during development).
            return false;
        }

        $expectedUsername = (string) (getenv('WEBUI_USERNAME') ?: 'admin');
        $providedUsername = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
        $providedPassword = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');

        return hash_equals($expectedUsername, $providedUsername)
            && hash_equals($expectedPassword, $providedPassword);
    }
}
