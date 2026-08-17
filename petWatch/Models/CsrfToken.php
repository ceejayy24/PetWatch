<?php
/**
 * CsrfToken Class
 *
 * Generates, stores, and validates CSRF (Cross-Site Request Forgery) tokens.
 *
 * Why CSRF protection matters:
 * Without it, a malicious website could trick a logged-in user's browser into
 * silently sending requests to PetWatch (e.g. adding fake sightings) because
 * the browser automatically includes the session cookie. A CSRF token is a
 * secret value tied to the user's session that only our own JavaScript knows,
 * so any request missing the correct token is rejected.
 *
 * How it works in PetWatch:
 *  1. getToken() is called once per session — it generates a cryptographically
 *     secure random token and stores it in $_SESSION['csrf_token'].
 *  2. The header template outputs it in a <meta name="csrf-token"> tag.
 *  3. Our JavaScript AjaxHelper class reads the meta tag and attaches the
 *     token as an X-CSRF-Token HTTP header on every POST request.
 *  4. Every PHP AJAX endpoint calls CsrfToken::validate() before processing
 *     the request — requests without the correct token get a 403 response.
 *
 * Token lifetime: one token per session (not per-request) for UX simplicity.
 * Tokens are regenerated on login/logout via UserAuthentication.
 */
class CsrfToken
{
    /** Session key used to store the token */
    const SESSION_KEY = 'csrf_token';

    /** HTTP header name our JS sends the token in */
    const HEADER_NAME = 'HTTP_X_CSRF_TOKEN';

    /**
     * Get the current session CSRF token, generating one if it does not exist.
     *
     * Uses random_bytes() which is cryptographically secure (PHP 7+).
     * bin2hex() converts the raw bytes to a 64-character hex string.
     *
     * @return string The 64-character hex CSRF token for this session
     */
    public static function getToken()
    {
        // Start session if not already active
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Generate and store a new token if one doesn't exist yet
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Validate an incoming CSRF token against the session token.
     *
     * Reads the token from the X-CSRF-Token request header (set by our
     * JavaScript AjaxHelper). Uses hash_equals() for timing-safe comparison
     * to prevent timing attacks that could allow token guessing.
     *
     * @return bool True if the token is present and matches the session token
     */
    public static function validate()
    {
        // Ensure a session token exists to compare against
        if (empty($_SESSION[self::SESSION_KEY])) {
            return false;
        }

        // Read the token from the request header sent by AjaxHelper
        $incoming = $_SERVER[self::HEADER_NAME] ?? '';

        if (empty($incoming)) {
            return false;
        }

        // Timing-safe string comparison — prevents timing-based token guessing
        return hash_equals($_SESSION[self::SESSION_KEY], $incoming);
    }

    /**
     * Regenerate the CSRF token.
     *
     * Called after login and logout to ensure the old token cannot be
     * reused across authentication state changes.
     *
     * @return string The newly generated token
     */
    public static function regenerate()
    {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        return $_SESSION[self::SESSION_KEY];
    }
}
