<?php
/**
 * Homepage Controller
 *
 * Displays the main landing page for the petWatch application.
 * Provides an overview of the platform's features including;
 * pet sighting browsing, sighting reporting, and pet management.
 * Adapts content based on user authentication.
 */

require_once('Models/UserAuthentication.php');

$view = new stdClass();
$view->pageTitle = 'Homepage';
$view->authMessage = '';
$view->user = new User();
require_once('Views/index.phtml');
