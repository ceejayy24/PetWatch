<?php
/**
 * JsonResponse Class
 *
 * Provides a consistent JSON response format for all AJAX endpoints.
 *
 * Every AJAX endpoint in PetWatch returns one of two shapes:
 *
 *   Success:
 *   {
 *     "success": true,
 *     "data":    { ... },      // The actual payload
 *     "message": "Done."       // Optional human-readable message
 *   }
 *
 *   Failure:
 *   {
 *     "success": false,
 *     "error":   "Reason.",    // Human-readable error description
 *     "code":    403           // Mirrors the HTTP status code
 *   }
 *
 * Using a consistent envelope means our JavaScript classes can handle
 * all responses with one shared pattern — check response.success, then
 * either use response.data or display response.error.
 *
 * All methods set the correct Content-Type header and call exit() so
 * no other output can accidentally corrupt the JSON.
 */
class JsonResponse
{
    /**
     * Send a successful JSON response and terminate.
     *
     * Sets HTTP 200 and outputs a success envelope containing the payload.
     *
     * @param mixed  $data    The response payload (array, object, or scalar)
     * @param string $message Optional human-readable success message
     * @param int    $status  HTTP status code (default 200)
     * @return void           Never returns — calls exit()
     */
    public static function success($data = null, $message = '', $status = 200)
    {
        http_response_code($status);
        self::setHeaders();

        $response = ['success' => true];

        if ($data !== null) {
            $response['data'] = $data;
        }
        if (!empty($message)) {
            $response['message'] = $message;
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Send an error JSON response and terminate.
     *
     * Sets the given HTTP status code and outputs a failure envelope.
     * The status code is mirrored inside the JSON body as 'code' so the
     * JavaScript layer can inspect it without reading response headers.
     *
     * @param string $error  Human-readable description of what went wrong
     * @param int    $status HTTP status code (e.g. 400, 401, 403, 422, 500)
     * @return void          Never returns — calls exit()
     */
    public static function error($error, $status = 400)
    {
        http_response_code($status);
        self::setHeaders();

        echo json_encode([
            'success' => false,
            'error' => $error,
            'code' => $status,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Send a 403 Forbidden response — used when CSRF validation fails.
     *
     * Separated into its own method so endpoints can call it expressively:
     *   JsonResponse::forbidden();
     *
     * @return void Never returns — calls exit()
     */
    public static function forbidden()
    {
        self::error('Forbidden: invalid or missing security token.', 403);
    }

    /**
     * Send a 401 Unauthorised response — used when the user is not logged in.
     *
     * @return void Never returns — calls exit()
     */
    public static function unauthorised()
    {
        self::error('Unauthorised: you must be logged in to perform this action.', 401);
    }

    /**
     * Send a 405 Method Not Allowed response.
     *
     * Used by endpoints that only accept specific HTTP verbs.
     *
     * @return void Never returns — calls exit()
     */
    public static function methodNotAllowed()
    {
        self::error('Method not allowed.', 405);
    }

    /**
     * Set the required HTTP headers for a JSON AJAX response.
     *
     * Content-Type tells the browser to parse the body as JSON.
     * X-Content-Type-Options prevents MIME-type sniffing attacks.
     * Cache-Control is set to no-store so responses aren't cached by
     * default — individual endpoints can override this for GET requests
     * that return stable data (e.g. the species list).
     *
     * @return void
     */
    private static function setHeaders()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }
}
