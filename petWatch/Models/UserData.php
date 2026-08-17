<?php
/**
 * UserData Class
 *
 * Represents a single user record from the database.
 * Encapsulates user information including ID, username, password hash,
 * and role (admin/user). Provides getter methods to access user data
 * securely. Used primarily for authentication and credential verification.
 */

class UserData
{
    // User properties
    protected $_id;
    protected $_username;
    protected $_passwordHash;
    protected $_role; // 'admin' (owner/manager) or 'user' (browsing user)

    /**
     * Constructor
     *
     * Initializes user object from a database row.
     *
     * @param array $dbRow Associative array of user data from the database
     */
    public function __construct($dbRow)
    {
        $this->_id = $dbRow['id'];
        $this->_username = $dbRow['username'];
        $this->_passwordHash = $dbRow['password'];
        // Default to 'user' if role column is absent (safe fallback)
        $this->_role = $dbRow['role'] ?? 'user';
    }

    /**
     * Get user ID
     *
     * @return int User's unique identifier
     */
    public function getUserID()
    {
        return $this->_id;
    }

    /**
     * Get username
     *
     * @return string User's username
     */
    public function getUserName()
    {
        return $this->_username;
    }

    /**
     * Get hashed password
     *
     * @return string User's bcrypt password hash for verification
     */
    public function getPasswordHash()
    {
        return $this->_passwordHash;
    }

    /**
     * Get user role
     *
     * @return string 'admin' for pet owners/managers, 'user' for browsing users
     */
    public function getRole()
    {
        return $this->_role;
    }

    /**
     * Check whether this user is an owner/manager (admin role)
     *
     * @return bool True if admin, false otherwise
     */
    public function isOwner()
    {
        return $this->_role === 'admin';
    }
}
