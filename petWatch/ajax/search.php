<?php
/**
 * AJAX Endpoint — Live Search
 *
 * Powers the live search feature.
 * Returns a paginated, filtered JSON payload of pet sightings matching
 * the given search criteria. Called by the JavaScript LiveSearch class
 * after a debounce delay as the user types.
 *
 * Method:  GET only
 * Auth:    Not required (public read)
 * CSRF:    Not required (GET — read-only)
 *
 * Query parameters:
 *   term    string  Free-text search (name, description, species, breed, colour, comment)
 *   species string  Exact species filter (e.g. 'Dog') — data-driven from DB
 *   status  string  'lost' | 'found' | '' for both
 *   sort    string  'newest' | 'oldest' | 'A-Z' | 'Z-A' (default: 'newest')
 *   page    int     Page number, 1-based (default: 1)
 *   limit   int     Results per page, max 50 (default: 12)
 *
 * Response shape:
 * {
 *   "success": true,
 *   "data": {
 *     "results":  [ { ...toArray() fields... } ],
 *     "total":    591,
 *     "page":     1,
 *     "pages":    50,
 *     "limit":    12,
 *     "term":     "tabby",
 *     "species":  "Cat",
 *     "status":   "lost",
 *     "sort":     "newest"
 *   }
 * }
 *
 * Performance considerations:
 *  - Debouncing is handled client-side (LiveSearch class, 300ms delay) —
 *    this endpoint just processes whatever request arrives.
 *  - Pagination (default 12 per page) keeps payloads small and browser
 *    memory usage low even with 500+ total records.
 *  - The species list is returned only on the first call (page=1, empty term)
 *    and cached by the JS layer to avoid repeated DB calls.
 */

session_start();

// Dependencies
require_once(__DIR__ . '/../Models/JsonResponse.php');
require_once(__DIR__ . '/../Models/SightingDataSet.php');
require_once(__DIR__ . '/../Models/PetDataSet.php');

// HTTP method guard
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    JsonResponse::methodNotAllowed();
}

// Input validation & sanitisation

// Free-text search term — strip tags, cap length to prevent abuse
$term = isset($_GET['term']) ? strip_tags(trim($_GET['term'])) : '';
if (strlen($term) > 200) {
    $term = substr($term, 0, 200);
}

// Species filter — must be a plain string (no special chars needed)
$species = isset($_GET['species']) ? strip_tags(trim($_GET['species'])) : '';

// Status filter — whitelist only
$allowedStatuses = ['lost', 'found', ''];
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
if (!in_array($status, $allowedStatuses, true)) {
    JsonResponse::error('Invalid status. Use "lost", "found", or leave empty.', 422);
}

// Sort order — whitelist only
$allowedSorts = ['newest', 'oldest', 'A-Z', 'Z-A'];
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'newest';
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'newest';
}

// Pagination
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 12;
if ($page < 1) {
    $page = 1;
}
if ($limit < 1) {
    $limit = 1;
}
if ($limit > 50) {
    $limit = 50;
} // Hard cap — keeps payloads small

try {
    $sightingDataSet = new SightingDataSet(true);

    // Fetch ALL matching results for this query (for accurate total count)
    // SightingDataSet::fetchSightings() already handles all filtering and sorting.
    // We then paginate in PHP — acceptable for hundreds of records; for thousands
    // a SQL LIMIT/OFFSET approach would be preferable (noted as future improvement).
    $allResults = $sightingDataSet->fetchSightings($term, $sort, $status, $species);

    $total = count($allResults);
    $pages = $total > 0 ? (int) ceil($total / $limit) : 1;

    // Clamp page to valid range
    if ($page > $pages) {
        $page = $pages;
    }

    $offset = ($page - 1) * $limit;
    $pageResults = array_slice($allResults, $offset, $limit);

    // Serialise each ExtSightingData object using toArray()
    $results = array_map(fn($s) => $s->toArray(), $pageResults);

    // Include the species list on the first page of a fresh (empty) search
    // The JS LiveSearch class caches this on first load to populate the
    // species dropdown without an additional HTTP request.
    $speciesList = null;
    if ($page === 1 && empty($term) && empty($species) && empty($status)) {
        $petDataSet = new PetDataSet(true);
        $speciesList = $petDataSet->fetchDistinctSpecies();
    }

    // Build the response payload
    $payload = [
        'results' => $results,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'limit' => $limit,
        // Echo active filters back so the JS can confirm what was applied
        'term' => $term,
        'species' => $species,
        'status' => $status,
        'sort' => $sort,
    ];

    if ($speciesList !== null) {
        $payload['species_list'] = $speciesList;
    }

    // No caching on search — results must reflect the latest data
    header('Cache-Control: no-store, no-cache, must-revalidate');

    JsonResponse::success($payload);

} catch (Exception $e) {
    error_log('ajax/search.php error: ' . $e->getMessage());
    JsonResponse::error('Search failed. Please try again.', 500);
}
