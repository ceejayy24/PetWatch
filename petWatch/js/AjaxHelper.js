/**
 * @file AjaxHelper.js — Shared AJAX Utility
 * @module AjaxHelper
 *
 * Part of the JavaScript OO architecture.
 * Used by: PetWatchMap.js, SightingForm.js, LiveSearch.js
 */

/**
 * AjaxHelper
 *
 * A shared utility class that wraps the browser's native fetch() API.
 * All AJAX communication in PetWatch flows through this class, meaning:
 *  - CSRF tokens are attached automatically on every POST request
 *  - Errors are handled and normalised in one place
 *  - The calling classes (PetWatchMap, SightingForm, LiveSearch) never
 *    deal with raw fetch() calls — they just call AjaxHelper.get() or
 *    AjaxHelper.post() and receive a consistent response object.
 *
 * Usage:
 *   const data = await AjaxHelper.get('ajax/pets.php', { status: 'lost' });
 *   const data = await AjaxHelper.post('ajax/sightings.php', { pet_id: 1, ... });
 *
 * Both methods return the parsed JSON object on success, or throw an Error
 * whose message comes from the server's JSON error field when available.
 *
 */
class AjaxHelper {
    /**
     * Read the CSRF token from the <meta name="csrf-token"> tag injected
     * by the PHP header template. Returns an empty string if not found
     * (e.g. on pages not behind authentication).
     *
     * @returns {string} 64-character hex CSRF token, or ''
     */
    static #getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute("content") : "";
    }

    /**
     * Perform a GET request and return the parsed JSON response.
     *
     * Builds a query string from the params object and appends it to the URL.
     * GET requests do not require a CSRF token (read-only operations).
     *
     * The optional options.signal (AbortSignal) allows the caller to cancel
     * an in-flight request — used by LiveSearch to drop stale requests when
     * the user types a new character before the previous fetch completes.
     *
     * @param  {string} url      Relative URL of the AJAX endpoint (e.g. 'ajax/pets.php')
     * @param  {Object} params   Key-value pairs to encode as query string parameters
     * @param  {Object} options  Optional fetch options (e.g. { signal: abortController.signal })
     * @returns {Promise<Object>} Parsed JSON response object
     * @throws {Error} If the network request fails or the server returns an error
     */
    static async get(url, params = {}, options = {}) {
        // Build query string — filter out null/undefined values to keep URLs clean
        const query = Object.entries(params)
            .filter(([, v]) => v !== null && v !== undefined && v !== "")
            .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
            .join("&");

        const fullUrl = query ? `${url}?${query}` : url;

        try {
            const response = await fetch(fullUrl, {
                method: "GET",
                credentials: "same-origin", // Send session cookie with request
                headers: {
                    Accept: "application/json",
                },
                // Pass AbortSignal through if provided — lets LiveSearch cancel
                // stale requests when the user types before a response arrives
                ...(options.signal ? { signal: options.signal } : {}),
            });

            return await AjaxHelper.#parseResponse(response);
        } catch (err) {
            // Re-throw network-level errors with a user-friendly message
            throw new Error(`Network error on GET ${url}: ${err.message}`);
        }
    }

    /**
     * Perform a POST request with a JSON body and return the parsed response.
     *
     * Automatically attaches the CSRF token as an X-CSRF-Token header,
     * which the PHP CsrfToken::validate() method reads and verifies.
     *
     * @param  {string} url  Relative URL of the AJAX endpoint
     * @param  {Object} data Object to serialise as the JSON request body
     * @returns {Promise<Object>} Parsed JSON response object
     * @throws {Error} If the request fails, CSRF validation fails, or server errors
     */
    static async post(url, data = {}) {
        try {
            const response = await fetch(url, {
                method: "POST",
                credentials: "same-origin", // Send session cookie for auth check
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    // CSRF token read from the meta tag in the page <head>
                    // Validated by CsrfToken::validate() in the PHP endpoint
                    "X-CSRF-Token": AjaxHelper.#getCsrfToken(),
                },
                body: JSON.stringify(data),
            });

            return await AjaxHelper.#parseResponse(response);
        } catch (err) {
            throw new Error(`Network error on POST ${url}: ${err.message}`);
        }
    }

    /**
     * Parse a fetch() Response into a plain JS object.
     *
     * Reads the JSON body and throws an Error if the server returned
     * success: false, using the server's own error message where available.
     * This means calling code only needs to catch one type of failure —
     * it doesn't matter whether the error came from the network or the server.
     *
     * @param  {Response} response The raw fetch Response object
     * @returns {Promise<Object>}  The parsed JSON body
     * @throws {Error}             If HTTP status >= 400 or success === false
     */
    static async #parseResponse(response) {
        let json;

        try {
            json = await response.json();
        } catch {
            // Response body was not valid JSON (e.g. PHP fatal error page)
            throw new Error(
                `Server returned non-JSON response (HTTP ${response.status})`,
            );
        }

        // Use the server's own error message if available, otherwise use HTTP status
        if (!response.ok || json.success === false) {
            const message =
                json.error || `Request failed with status ${response.status}`;
            const err = new Error(message);
            err.code = json.code || response.status;
            throw err;
        }

        return json;
    }
}
