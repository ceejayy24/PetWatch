<?php
/**
 * AJAX Endpoint — Pets for Map
 *
 * Returns a paginated JSON payload of pets enriched with their last known
 * location coordinates and most recent sighting data. Consumed by the
 * JavaScript PetWatchMap class to render Leaflet markers.
 *
 * Method:  GET only
 * Auth:    Not required (public read)
 * CSRF:    Not required (GET — read-only, no state change)
 *
 * Query parameters:
 *   status  string  'lost' | 'found' | '' (default: '' = all)
 *   page    int     Page number, 1-based (default: 1)
 *   limit   int     Records per page, max 100 (default: 50)
 *
 * Response shape:
 * {
 *   "success": true,
 *   "data": {
 *     "pets":  [ { pet_id, pet_name, ..., location_lat, location_lng,
 *                  last_sighting_lat, last_sighting_lng,
 *                  last_sighting_comment, last_sighting_time,
 *                  last_reporter_username } ],
 *     "total": 156,
 *     "page":  1,
 *     "pages": 4,
 *     "limit": 50
 *   }
 * }
 *
 * Caching: Responses are cached for 60 seconds via Cache-Control to reduce
 * server load when multiple users open the map simultaneously. This is safe
 * because map data does not need to be real-time to the second.
 */

session_start();

// Path resolution
// This file lives in ajax/ — Models are one level up
require_once(__DIR__ . '/../Models/JsonResponse.php');
require_once(__DIR__ . '/../Models/PetDataSet.php');
require_once(__DIR__ . '/../Models/PetData.php'); // for PetData::getDefaultPhotoPath()

// HTTP method guard
// This endpoint is GET only — reject anything else immediately
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    JsonResponse::methodNotAllowed();
}

// Input validation & sanitisation

// status — must be one of the allowed values or empty (means all)
// Default is '' (show all) — not 'lost'.
// The All filter button in PetWatchMap passes status='' which AjaxHelper
// strips from the query string entirely (it filters empty values).
// So when no status param arrives, we correctly default to showing all pets.
// Lost/Found buttons send non-empty values that are NOT stripped, so they work fine.
$allowedStatuses = ['lost', 'found', ''];
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
if (!in_array($status, $allowedStatuses, true)) {
    JsonResponse::error('Invalid status value. Use "lost", "found", or empty string.', 422);
}

// page — positive integer, default 1
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}

// limit — positive integer capped at 100 to prevent abuse
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
if ($limit < 1) {
    $limit = 1;
}
if ($limit > 100) {
    $limit = 100;
}

$offset = ($page - 1) * $limit;

// Database query
try {
    $petDataSet = new PetDataSet(true); // true = called from ajax subfolder

    // Get total count for pagination metadata
    $total = $petDataSet->countPetsForMap($status);
    $pages = $total > 0 ? (int) ceil($total / $limit) : 1;

    // Fetch the page of pets with location and latest sighting data
    $rows = $petDataSet->fetchPetsForMap($status, $limit, $offset);

    // Sanitise output
    // HTML-encode all string fields to prevent XSS when JavaScript
    // injects values into the DOM. Numbers are cast to their correct types.
    $pets = [];
    foreach ($rows as $row) {
        $pets[] = [
            // Pet core fields
            'pet_id' => (int) $row['pet_id'],
            'pet_name' => htmlspecialchars($row['pet_name'] ?? '', ENT_QUOTES, 'UTF-8'),
            'pet_species' => htmlspecialchars($row['pet_species'] ?? '', ENT_QUOTES, 'UTF-8'),
            'pet_breed' => htmlspecialchars($row['pet_breed'] ?? '', ENT_QUOTES, 'UTF-8'),
            'pet_color' => htmlspecialchars($row['pet_color'] ?? '', ENT_QUOTES, 'UTF-8'),
            'pet_photo' => htmlspecialchars($row['pet_photo'] ?? '', ENT_QUOTES, 'UTF-8'),
            // Use the uploaded photo if available, otherwise get a species default image.
            // Passes pet_id so different pets cycle through the image pool for variety.
            'pet_photo_path' => !empty($row['pet_photo'])
                ? 'images/pet-photos/' . htmlspecialchars($row['pet_photo'], ENT_QUOTES, 'UTF-8')
                : PetData::getDefaultPhotoPath($row['pet_species'] ?? '', (int) ($row['pet_id'] ?? 0)),
            'pet_status' => htmlspecialchars($row['pet_status'] ?? '', ENT_QUOTES, 'UTF-8'),
            'pet_description' => htmlspecialchars($row['pet_description'] ?? '', ENT_QUOTES, 'UTF-8'),
            'pet_date_reported' => htmlspecialchars($row['pet_date_reported'] ?? '', ENT_QUOTES, 'UTF-8'),
            'owner_username' => htmlspecialchars($row['owner_username'] ?? '', ENT_QUOTES, 'UTF-8'),

            // Last known stored location (from locations table)
            'location_lat' => $row['location_lat'] !== null ? (float) $row['location_lat'] : null,
            'location_lng' => $row['location_lng'] !== null ? (float) $row['location_lng'] : null,

            // Most recent sighting coordinates and details
            'last_sighting_lat' => $row['last_sighting_lat'] !== null ? (float) $row['last_sighting_lat'] : null,
            'last_sighting_lng' => $row['last_sighting_lng'] !== null ? (float) $row['last_sighting_lng'] : null,
            'last_sighting_comment' => htmlspecialchars($row['last_sighting_comment'] ?? '', ENT_QUOTES, 'UTF-8'),
            'last_sighting_time' => htmlspecialchars($row['last_sighting_time'] ?? '', ENT_QUOTES, 'UTF-8'),
            'last_reporter' => htmlspecialchars($row['last_reporter_username'] ?? 'Anonymous', ENT_QUOTES, 'UTF-8'),
        ];
    }

    // Cache headers
    // 60-second cache is acceptable for map data — pets don't move every second.
    // This reduces DB load when many users load the map simultaneously.
    header('Cache-Control: public, max-age=60');

    JsonResponse::success([
        'pets' => $pets,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'limit' => $limit,
    ]);

} catch (Exception $e) {
    // Log the real error server-side — never expose stack traces to the client
    error_log('ajax/pets.php error: ' . $e->getMessage());
    JsonResponse::error('Failed to retrieve pets. Please try again.', 500);
}
