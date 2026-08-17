<?php
/**
 * UserAuthentication (User) Class
 *
 * Handles user authentication state and session lifecycle management.
 * Stores the logged-in user's username, ID, and role in the PHP session.
 * Role enforcement (admin vs user) is checked here and exposed via
 * isOwner() — this is the single source of truth for role checks across
 * all controllers, preventing bypassing via URL manipulation.
 *
 * Roles:
 *  - 'admin' → Pet owner / Manager (Lee). Can add, edit, delete pets.
 *  - 'user'  → Browsing user (Zara). Can only submit sighting reports.
 */

require_once('UserDataSet.php');

class User
{
    // Core user state properties
    protected $_username;
    protected $_loggedin;
    protected $_userID;
    protected $_role; // 'admin' or 'user'

    /**
     * Constructor
     *
     * Starts the session (if not already active) and restores user state
     * from the session if the user was previously authenticated.
     */
    public function __construct()
    {
        // Start session only if one isn't already active
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Set secure defaults before checking session
        $this->_username = 'No user';
        $this->_loggedin = false;
        $this->_userID = 0;
        $this->_role = 'user'; // Least-privileged default

        // Restore state from session if the user is logged in
        if (isset($_SESSION['login'])) {
            $this->_username = $_SESSION['login'];
            $this->_loggedin = true;
            $this->_userID = $_SESSION['uid'] ?? 0;
            $this->_role = $_SESSION['role'] ?? 'user';
        }
    }

    /**
     * Reinitialise user object
     *
     * Resets all properties and reloads from the current session.
     * Called after a successful login to refresh the object state.
     */
    public function initialise()
    {
        $this->_username = 'No user';
        $this->_loggedin = false;
        $this->_userID = 0;
        $this->_role = 'user';

        if (isset($_SESSION['login'])) {
            $this->_username = $_SESSION['login'];
            $this->_loggedin = true;
            $this->_userID = $_SESSION['uid'] ?? 0;
            $this->_role = $_SESSION['role'] ?? 'user';
        }
    }

    /**
     * Authenticate a user with username and password
     *
     * Verifies credentials against the database using bcrypt password_verify().
     * On success, stores the user's ID, username, and role in the session.
     *
     * @param  string $uname Plain-text username
     * @param  string $pword Plain-text password
     * @return bool          True if authentication succeeded, false otherwise
     */
    public function Authenticate($uname, $pword)
    {
        $users = new UserDataSet(false);
        $userDataSet = $users->checkUsersCredentials($uname, $pword);

        if (count($userDataSet) > 0) {
            $user = $userDataSet[0];

            // Store essential session variables — role included for enforcement
            $_SESSION['login'] = $uname;
            $_SESSION['uid'] = $user->getUserID();
            $_SESSION['role'] = $user->getRole(); // 'admin' or 'user'

            // Update in-memory object state
            $this->_loggedin = true;
            $this->_username = $uname;
            $this->_userID = $user->getUserID();
            $this->_role = $user->getRole();

            return true;
        }

        // Authentication failed — do not update state
        $this->_loggedin = false;
        return false;
    }

    /**
     * Log out the current user
     *
     * Clears all session variables, destroys the session, and resets
     * the object to its unauthenticated defaults.
     */
    public function logout()
    {
        // Remove all session keys set during login
        unset($_SESSION['login']);
        unset($_SESSION['uid']);
        unset($_SESSION['role']);

        // Destroy the session entirely
        session_destroy();

        // Reset object state to unauthenticated defaults
        $this->initialise();
    }

    // Getter Methods

    /**
     * Get the current username
     *
     * @return string Username string, or 'No user' if not logged in
     */
    public function userName()
    {
        return $this->_username;
    }

    /**
     * Check whether the user is logged in
     *
     * @return bool True if authenticated, false otherwise
     */
    public function isLoggedIn()
    {
        return $this->_loggedin;
    }

    /**
     * Get the current user's database ID
     *
     * @return int User ID, or 0 if not logged in
     */
    public function userID()
    {
        return $this->_userID;
    }

    /**
     * Get the current user's role
     *
     * @return string 'admin' for owners/managers, 'user' for browsing users
     */
    public function getUserRole()
    {
        return $this->_role;
    }

    /**
     * Check whether the current user is a pet owner / manager (admin role)
     *
     * This is the authoritative role check used by all controllers.
     * Always verified server-side — never rely on client-side checks alone.
     *
     * @return bool True if the user has the 'admin' role, false otherwise
     */
    public function isOwner()
    {
        return $this->_loggedin && $this->_role === 'admin';
    }
}
