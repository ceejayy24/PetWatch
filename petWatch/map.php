<?php
/**
 * Map Controller — Core Mapping Deliverable
 *
 * Serves the interactive live map page for PetWatch (map.phtml).
 * This controller is intentionally minimal — it only bootstraps the view
 * with the correct user authentication state. All map logic lives in JS:
 *
 *   PetWatchMap.js   — Leaflet map, geolocation, AJAX marker loading,
 *                      marker clustering, list↔map interaction
 *   SightingForm.js  — sighting submission form inside map popups
 *   AjaxHelper.js    — shared AJAX utility used by the above
 *
 * AJAX endpoint consumed by this page: ajax/pets.php (GET)
 * AJAX endpoint for sighting submission:  ajax/sightings.php (POST, auth+CSRF)
 *
 * Accessibility: the map is publicly viewable (no login required).
 * Authenticated users additionally see a sighting submission form
 * inside each marker popup (role-checked server-side in ajax/sightings.php).
 */

session_start();

require_once('Models/UserAuthentication.php');

// View object
$view = new stdClass();
$view->pageTitle = 'Live Map';
$view->redirect = '';
$view->user = new User();

require_once('Views/map.phtml');
