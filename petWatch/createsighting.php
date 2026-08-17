<?php
/**
 * Create Sighting Controller
 *
 * Allows authenticated users to report sightings of missing pets.
 * Validates all input, enforces anti-spam rate limiting, and stores
 * new sighting records in the database.
 *
 * Improvements:
 *  - header() redirect replaced with JS-safe $view->redirect pattern.
 *  - Anti-spam: users are limited to 1 sighting per pet
 *    per 2 minutes, tracked via session timestamps.
 *  - Role note: all logged-in users (both 'admin' and 'user') may submit
 *    sightings. Role enforcement is only applied to pet management.
 */

session_start();

require_once('Models/UserAuthentication.php');
require_once('Models/PetDataSet.php');
require_once('Models/SightingDataSet.php');

// View object
$view = new stdClass();
$view->pageTitle = 'Report Sighting';
$view->user = new User();
$view->errors = [];
$view->success = false;
$view->successMessage = '';
$view->redirect = '';

// Authentication check
// Sighting submission is available to all logged-in users (both roles)
if (!$view->user->isLoggedIn()) {
    $_SESSION['error_message'] = 'You must be logged in to report a sighting.';
    $view->redirect = 'authenticate.php';
    require_once('Views/createsighting.phtml');
    exit();
}

// Datasets
$petDataSet = new PetDataSet();
$sightingDataSet = new SightingDataSet();

// Fetch all lost pets for the dropdown selector
$view->missingPets = $petDataSet->fetchMissingPets();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['createSightingBtn'])) {

    // Collect and trim inputs
    $petId = isset($_POST['pet_id']) ? (int) $_POST['pet_id'] : 0;
    $comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';
    $latitude = isset($_POST['latitude']) ? trim($_POST['latitude']) : '';
    $longitude = isset($_POST['longitude']) ? trim($_POST['longitude']) : '';

    // Anti-spam rate limiting
    // Track the last sighting submission time per pet in the session.
    // A user may not submit more than one sighting per pet within 2 minutes.
    $spamKey = 'last_sighting_' . $petId . '_uid_' . $view->user->userID();
    $cooldownSec = 120; // 2-minute cooldown window
    $now = time();

    if (isset($_SESSION[$spamKey]) && ($now - $_SESSION[$spamKey]) < $cooldownSec) {
        $remaining = $cooldownSec - ($now - $_SESSION[$spamKey]);
        $view->errors[] = "Please wait $remaining more second(s) before submitting another sighting for this pet.";
    }

    // Field validation
    // pet_id must be a positive integer — zero or negative values indicate
    // a missing selection (the form default) and must be rejected server-side
    // even though the view also enforces selection client-side.
    if ($petId <= 0) {
        $view->errors[] = 'Please select a pet.';
    }

    // Comment length bounds: 10 chars minimum prevents empty/trivial reports;
    // 500 chars maximum prevents database column overflow and display issues.
    if (empty($comment)) {
        $view->errors[] = 'Please provide a description of the sighting.';
    } elseif (strlen($comment) < 10) {
        $view->errors[] = 'Sighting description must be at least 10 characters.';
    } elseif (strlen($comment) > 500) {
        $view->errors[] = 'Sighting description must not exceed 500 characters.';
    }

    // strip_tags() removes any HTML or script injection from the comment.
    // This is belt-and-braces alongside prepared statements — prepared statements
    // prevent SQL injection but do not prevent stored XSS if the comment is later
    // rendered via echo without htmlspecialchars(). Stripping tags here means
    // even if a view accidentally outputs unencoded content, no script runs.
    $comment = strip_tags($comment);

    // Coordinate range validation — enforce the physical bounds of GPS coordinates.
    // Latitude must be -90 to 90 (south pole to north pole).
    // Longitude must be -180 to 180 (west to east). Values outside these ranges
    // are impossible GPS readings and indicate either malformed or malicious input.
    if (empty($latitude)) {
        $view->errors[] = 'Please provide a latitude.';
    } elseif (!is_numeric($latitude) || $latitude < -90 || $latitude > 90) {
        $view->errors[] = 'Latitude must be a number between -90 and 90.';
    }

    if (empty($longitude)) {
        $view->errors[] = 'Please provide a longitude.';
    } elseif (!is_numeric($longitude) || $longitude < -180 || $longitude > 180) {
        $view->errors[] = 'Longitude must be a number between -180 and 180.';
    }

    // Save sighting if all validation passed
    if (empty($view->errors)) {
        $userId = $view->user->userID();
        $sightingId = $sightingDataSet->addSighting(
            $petId,
            $userId,
            $comment,
            (float) $latitude,
            (float) $longitude
        );

        if ($sightingId) {
            // Record the timestamp of this submission to enforce the cooldown
            $_SESSION[$spamKey] = $now;

            $view->success = true;
            $view->successMessage = 'Thank you! Your sighting has been reported successfully.';
            $_POST = []; // Clear POST data so the form resets
        } else {
            $view->errors[] = 'Failed to save sighting. Please try again.';
        }
    }
}

// Repopulate form on error
$view->formData = [
    'pet_id' => $_POST['pet_id'] ?? '',
    'comment' => $_POST['comment'] ?? '',
    'latitude' => $_POST['latitude'] ?? '',
    'longitude' => $_POST['longitude'] ?? '',
];

require_once('Views/createsighting.phtml');
