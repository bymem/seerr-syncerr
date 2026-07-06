<?php

namespace SeerrSyncerr\Support;

/**
 * Session-based login for the settings UI (SPEC.md §4.2). The settings
 * pages expose every configured service's API key plus the webhook secret,
 * so access has to be gated before the app is reachable at all —
 * credentials are the WEBUI_USERNAME/WEBUI_PASSWORD env vars, checked via
 * hash_equals(), never stored anywhere else.
 */
class SessionAuth
{
    private const SESSION_KEY = 'ss_authenticated';
    private const CSRF_KEY = 'ss_csrf_token';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    public static function isLoggedIn(): bool
    {
        self::start();
        return !empty($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Checks the submitted credentials against WEBUI_USERNAME/WEBUI_PASSWORD
     * and, on success, starts the authenticated session.
     */
    public static function attempt(string $username, string $password): bool
    {
        $expectedPassword = (string) getenv('WEBUI_PASSWORD');
        if ($expectedPassword === '') {
            // Should never happen — entrypoint.sh refuses to start the
            // container without WEBUI_PASSWORD set — but fail closed.
            return false;
        }

        $expectedUsername = (string) (getenv('WEBUI_USERNAME') ?: 'admin');
        $ok = hash_equals($expectedUsername, $username) && hash_equals($expectedPassword, $password);

        if ($ok) {
            self::start();
            session_regenerate_id(true);
            $_SESSION[self::SESSION_KEY] = true;
        }

        return $ok;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }

    /**
     * Returns the current CSRF token, generating one on first use. Embed
     * this in the settings form and check it in verifyCsrf() on save —
     * needed now that auth is a session cookie rather than a header sent
     * with every request (Basic Auth was immune to CSRF; cookies aren't).
     */
    public static function csrfToken(): string
    {
        self::start();
        if (empty($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::CSRF_KEY];
    }

    public static function verifyCsrf(?string $token): bool
    {
        self::start();
        return $token !== null && $token !== '' && hash_equals($_SESSION[self::CSRF_KEY] ?? '', $token);
    }
}
