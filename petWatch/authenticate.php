<?php
/**
 * Authentication Controller
 *
 * Handles user login and logout for PetWatch.
 *
 * Security additions:
 *  - session_regenerate_id() is called on every successful login and logout.
 *    This prevents session fixation attacks, where an attacker pre-sets a
 *    known session ID and then waits for the victim to authenticate with it.
 *  - CsrfToken::regenerate() is called alongside session regeneration so the
 *    CSRF token also changes on auth state transitions — meaning a token
 *    captured before login cannot be reused after login.
 *  - Input length caps on username and password prevent oversized payloads
 *    from hitting the database at all.
 */

session_start();

require_once('Models/UserAuthentication.php');
require_once('Models/CsrfToken.php');

// View object
$view = new stdClass();
$view->pageTitle = 'Login & Logout';
$view->authMessage = '';
$view->errorMessage = '';
$view->redirect = '';
$view->user = new User();

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    // User::logout() calls session_destroy() internally, which completely
    // kills the active session. We must NOT call session_regenerate_id()
    // after this — there is no session left to regenerate, which would
    // produce a PHP warning. Instead, we start a brand new session after
    // the destroy, which automatically gets a fresh session ID (no fixation
    // risk) and gives us a clean context to generate a new CSRF token.
    $view->user->logout();

    // Start a fresh empty session — new session ID is assigned automatically
    session_start();

    // Generate a new CSRF token for the fresh session
    CsrfToken::regenerate();
}

// Handle login
if (isset($_POST['loginBtn'])) {

    // Collect inputs — cap length to avoid oversized DB queries
    $username = trim(substr($_POST['username'] ?? '', 0, 100));
    $password = trim(substr($_POST['password'] ?? '', 0, 255));

    // Strip any HTML tags from username (passwords are not echoed back)
    $username = strip_tags($username);

    if ($view->user->Authenticate($username, $password)) {

        // Session fixation prevention
        // Regenerate the session ID immediately after a successful login.
        // This ensures any session ID the user held before logging in
        // (which could have been set by an attacker) is now invalidated.
        session_regenerate_id(true);

        // Also regenerate CSRF token — ensures pre-login token cannot be reused
        CsrfToken::regenerate();

        $view->authMessage = 'Welcome, ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '!';

        // Reinitialise user object so it reflects the newly created session
        $view->user->initialise();

    } else {
        // Generic error message — do not reveal whether the username exists
        $view->errorMessage = 'Invalid username or password.';
    }
}

require_once('Views/authenticate.phtml');
