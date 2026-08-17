<?php
/**
 * AJAX Endpoint — Sightings
 *
 * Handles two operations depending on HTTP method and cmd parameter:
 *
 *   GET  ?cmd=get&pet_id=X
 *        Returns all sightings for a specific pet (public, no auth required).
 *        Used by the map popup to display the sighting history list.
 *
 *   POST cmd=add (JSON body)
 *        Adds a new sighting for a pet.
 *        Requires: active login session + valid CSRF token in X-CSRF-Token header.
 *        Body JSON: { "pet_id": int, "comment": string, "lat": float, "lng": float }
 *
 * CSRF:  Required for POST (validated via X-CSRF-Token header).
 * Auth:  Required for POST (any logged-in user, both roles).
 *
 * GET response shape:
 * {
 *   "success": true,
 *   "data": {
 *     "sightings": [ { sighting_id, comment, latitude, longitude,
 *                      timestamp, reporter_username, ... } ],
 *     "total": 5
 *   }
 * }
 *
 * POST response shape (success):
 * {
 *   "success": true,
 *   "data": { "sighting_id": 42 },
 *   "message": "Sighting reported successfully."
 * }
 */

session_start();

// Dependencies
require_once(__DIR__ . '/../Models/JsonResponse.php');
require_once(__DIR__ . '/../Models/CsrfToken.php');
require_once(__DIR__ . '/../Models/UserAuthentication.php');
require_once(__DIR__ . '/../Models/SightingDataSet.php');
require_once(__DIR__ . '/../Models/PetDataSet.php');

$method = $_SERVER['REQUEST_METHOD'];

// GET — fetch sightings for a pet (no auth required, public read)
if ($method === 'GET') {

    // Validate cmd parameter
    $cmd = isset($_GET['cmd']) ? trim($_GET['cmd']) : '';
    if ($cmd !== 'get') {
        JsonResponse::error('Invalid cmd. Use cmd=get for GET requests.', 422);
    }

    // Validate pet_id
    $petId = isset($_GET['pet_id']) ? (int) $_GET['pet_id'] : 0;
    if ($petId <= 0) {
        JsonResponse::error('A valid pet_id is required.', 422);
    }

    try {
        $sightingDataSet = new SightingDataSet(true);
        $sightings = $sightingDataSet->fetchSightingsByPetId($petId);

        // Serialise each ExtSightingData object to a plain array via toArray()
        $payload = array_map(fn($s) => $s->toArray(), $sightings);

        // Short cache — sighting lists update when new sightings are added,
        // but a 30-second window is fine for the popup display
        header('Cache-Control: public, max-age=30');

        JsonResponse::success([
            'sightings' => $payload,
            'total' => count($payload),
        ]);

    } catch (Exception $e) {
        error_log('ajax/sightings.php GET error: ' . $e->getMessage());
        JsonResponse::error('Failed to retrieve sightings.', 500);
    }
}

// POST — add a new sighting (auth + CSRF required)
elseif ($method === 'POST') {

    // CSRF validation
    // The JavaScript AjaxHelper sends the token in the X-CSRF-Token header.
    // Reject immediately if missing or invalid — do not process any further.
    if (!CsrfToken::validate()) {
        JsonResponse::forbidden();
    }

    // Authentication check
    // Both 'admin' and 'user' roles may submit sightings.
    // Only the session check matters here — role is not restricted.
    $user = new User();
    if (!$user->isLoggedIn()) {
        JsonResponse::unauthorised();
    }

    // Parse JSON request body
    // The JS sends a JSON body (Content-Type: application/json),
    // so we read php://input and decode it rather than using $_POST.
    $rawBody = file_get_contents('php://input');
    $body = json_decode($rawBody, true);

    if (!is_array($body)) {
        JsonResponse::error('Request body must be valid JSON.', 400);
    }

    // Collect and validate inputs
    $petId = isset($body['pet_id']) ? (int) $body['pet_id'] : 0;
    $comment = isset($body['comment']) ? trim((string) $body['comment']) : '';
    $latitude = isset($body['lat']) ? (float) $body['lat'] : null;
    $longitude = isset($body['lng']) ? (float) $body['lng'] : null;

    $errors = [];

    if ($petId <= 0) {
        $errors[] = 'A valid pet_id is required.';
    }

    if (empty($comment)) {
        $errors[] = 'A sighting comment is required.';
    } elseif (strlen($comment) < 10) {
        $errors[] = 'Comment must be at least 10 characters.';
    } elseif (strlen($comment) > 500) {
        $errors[] = 'Comment must not exceed 500 characters.';
    }

    // Strip HTML tags from comment — belt-and-braces alongside prepared statements
    $comment = strip_tags($comment);

    if ($latitude === null || $latitude < -90 || $latitude > 90) {
        $errors[] = 'A valid latitude (-90 to 90) is required.';
    }
    if ($longitude === null || $longitude < -180 || $longitude > 180) {
        $errors[] = 'A valid longitude (-180 to 180) is required.';
    }

    if (!empty($errors)) {
        JsonResponse::error(implode(' ', $errors), 422);
    }

    // Anti-spam rate limiting
    // Mirrors the same logic in createsighting.php:
    // one sighting per user per pet per 2 minutes, tracked via session timestamp.
    $spamKey = 'last_sighting_' . $petId . '_uid_' . $user->userID();
    $cooldownSec = 120;
    $now = time();

    if (isset($_SESSION[$spamKey]) && ($now - $_SESSION[$spamKey]) < $cooldownSec) {
        $remaining = $cooldownSec - ($now - $_SESSION[$spamKey]);
        JsonResponse::error(
            "Please wait $remaining more second(s) before submitting another sighting for this pet.",
            429  // 429 Too Many Requests
        );
    }

    // Verify the pet exists
    try {
        $petDataSet = new PetDataSet(true);
        $pet = $petDataSet->fetchPetById($petId);

        if (!$pet) {
            JsonResponse::error('Pet not found.', 404);
        }

        // Insert the sighting
        $sightingDataSet = new SightingDataSet(true);
        $sightingId = $sightingDataSet->addSighting(
            $petId,
            $user->userID(),
            $comment,
            $latitude,
            $longitude
        );

        if (!$sightingId) {
            JsonResponse::error('Failed to save sighting. Please try again.', 500);
        }

        // Record submission time for anti-spam cooldown
        $_SESSION[$spamKey] = $now;

        // Fetch the newly created sighting to return it in the response,
        // so the JS can immediately add it to the popup without a second request
        $newSighting = $sightingDataSet->fetchSightingById($sightingId);

        JsonResponse::success(
            [
                'sighting_id' => $sightingId,
                'sighting' => $newSighting ? $newSighting->toArray() : null,
            ],
            'Sighting reported successfully.'
        );

    } catch (Exception $e) {
        error_log('ajax/sightings.php POST error: ' . $e->getMessage());
        JsonResponse::error('An error occurred while saving the sighting.', 500);
    }
}

// Reject any other HTTP methods
else {
    JsonResponse::methodNotAllowed();
}
