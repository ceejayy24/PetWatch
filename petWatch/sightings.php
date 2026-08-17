<?php
/**
 * Sightings Controller
 *
 * Displays all pet sightings with search, filter, sort, and pagination.
 * Accessible to all users (no login required to browse).
 *
 * Passes a $searchSpecies parameter to fetchSightings()
 * so the species dropdown filter actually narrows results
 * Species list for the dropdown is fetched dynamically from the DB via
 * PetDataSet::fetchDistinctSpecies() rather than being hardcoded.
 */

session_start();

require_once('Models/UserAuthentication.php');
require_once('Models/SightingDataSet.php');
require_once('Models/PetDataSet.php');

// View object
$view = new stdClass();
$view->pageTitle = 'Pet Sightings';
$view->authMessage = '';
$view->errorMessage = '';
$view->user = new User();
$view->authMessage = $view->user->userName();

// Datasets
$view->sightingDataSet = new SightingDataSet();
$petDataSet = new PetDataSet();

// Fetch distinct species for the data-driven dropdown
$view->speciesList = $petDataSet->fetchDistinctSpecies();

// Default search parameter values
$searchTerm = '';   // Empty string = match all (% wildcard in model)
$searchOrder = 'newest';
$searchLostOrFound = '';
$searchSpecies = '';   // New species filter

// Handle POST search submission
if (isset($_POST['searchBtn'])) {
    $searchTerm = !empty($_POST['searchTerm']) ? trim($_POST['searchTerm']) : '';
    $searchOrder = isset($_POST['searchOrder']) ? trim($_POST['searchOrder']) : 'newest';
    $searchLostOrFound = isset($_POST['searchLostOrFound']) ? trim($_POST['searchLostOrFound']) : '';
    $searchSpecies = isset($_POST['searchSpecies']) ? trim($_POST['searchSpecies']) : '';

    // Strip tags to prevent XSS in search term; prepared statements handle SQL injection
    $searchTerm = strip_tags($searchTerm);
}
// Handle GET parameters (pagination links carry search state)
// When the user clicks a pagination link, the current search state is carried
// in GET params so the correct page of the correct results is shown.
// strip_tags() applied here as on POST — defence-in-depth for XSS prevention.
elseif (
    isset($_GET['searchTerm']) || isset($_GET['searchOrder']) ||
    isset($_GET['searchLostOrFound']) || isset($_GET['searchSpecies'])
) {

    $searchTerm = isset($_GET['searchTerm']) && $_GET['searchTerm'] !== '' ? strip_tags(trim($_GET['searchTerm'])) : '';
    $searchOrder = isset($_GET['searchOrder']) ? trim($_GET['searchOrder']) : 'newest';
    $searchLostOrFound = isset($_GET['searchLostOrFound']) ? trim($_GET['searchLostOrFound']) : '';
    $searchSpecies = isset($_GET['searchSpecies']) ? trim($_GET['searchSpecies']) : '';
}

// Fetch sightings from DB (now with species filter)
// Passing '' as searchTerm when empty so the model uses '%' wildcard internally
$allSightings = $view->sightingDataSet->fetchSightings(
    $searchTerm,
    $searchOrder,
    $searchLostOrFound,
    $searchSpecies
);

// Store current parameters so the view can repopulate the form
$view->currentSearchTerm = $searchTerm;
$view->currentSearchOrder = $searchOrder;
$view->currentSearchLostOrFound = $searchLostOrFound;
$view->currentSearchSpecies = $searchSpecies;

// Pagination
$sightingsPerPage = 9; // 3 rows of 3 cards
$totalSightings = is_array($allSightings) ? count($allSightings) : 0;
$totalPages = max(1, ceil($totalSightings / $sightingsPerPage));
$currentPage = isset($_GET['page']) ? max(1, min((int) $_GET['page'], $totalPages)) : 1;
$offset = ($currentPage - 1) * $sightingsPerPage;

$view->sightingData = array_slice($allSightings, $offset, $sightingsPerPage);
$view->currentPage = $currentPage;
$view->totalPages = $totalPages;
$view->totalSightings = $totalSightings;

require_once('Views/sightings.phtml');
