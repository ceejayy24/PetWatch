<?php
/**
 * UserDataSet Class
 *
 * Handles all database operations for user records.
 * Provides methods to fetch users, authenticate credentials using
 * password_verify(), and check for existing usernames.
 * All queries use prepared statements to prevent SQL injection.
 */

require_once('Database.php');
require_once('UserData.php');

class UserDataSet
{
    protected $_dbHandle, $_dbInstance;

    /**
     * Constructor — initialises database connection
     *
     * @param bool $ajax True when called from an AJAX endpoint (adjusts DB path)
     */
    public function __construct($ajax = false)
    {
        $this->_dbInstance = Database::getInstance($ajax);
        $this->_dbHandle = $this->_dbInstance->getdbConnection();
    }

    /**
     * Map a raw database row to a UserData-compatible array.
     *
     * Normalises the password_hash column name and ensures the role field
     * is always present before constructing a UserData object.
     *
     * @param  array $row Raw associative row from PDO
     * @return array      Normalised row ready for UserData constructor
     */
    private function normaliseRow($row)
    {
        // password_hash column → password key expected by UserData
        if (isset($row['password_hash'])) {
            $row['password'] = $row['password_hash'];
        }
        // Guarantee role exists; default to least-privileged role
        if (!isset($row['role'])) {
            $row['role'] = 'user';
        }
        return $row;
    }

    /**
     * Fetch a single user by username
     *
     * @param  string        $username
     * @return UserData|null Returns UserData object if found, null otherwise
     */
    public function fetchUserByUsername($username)
    {
        $sqlQuery = 'SELECT * FROM users WHERE username = :username LIMIT 1';
        $statement = $this->_dbHandle->prepare($sqlQuery);
        $statement->bindParam(':username', $username);
        $statement->execute();

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return new UserData($this->normaliseRow($row));
        }

        return null;
    }

    /**
     * Fetch all users from the database
     *
     * @return array Array of UserData objects
     */
    public function fetchAllUsers()
    {
        $sqlQuery = 'SELECT * FROM users';
        $statement = $this->_dbHandle->prepare($sqlQuery);
        $statement->execute();

        $dataSet = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $dataSet[] = new UserData($this->normaliseRow($row));
        }
        return $dataSet;
    }

    /**
     * Verify user credentials with bcrypt password_verify()
     *
     * Fetches the user by username then verifies the plain-text password
     * against the stored bcrypt hash. Returns a populated array on success
     * or an empty array on failure.
     *
     * @param  string $uname Plain-text username
     * @param  string $pword Plain-text password
     * @return array         Array containing one UserData object on success, empty on failure
     */
    public function checkUsersCredentials($uname, $pword)
    {
        $sqlQuery = 'SELECT * FROM users WHERE username = :uname';
        $statement = $this->_dbHandle->prepare($sqlQuery);
        $statement->bindParam(':uname', $uname);
        $statement->execute();

        $dataSet = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            // Verify password against the bcrypt hash stored in the DB
            if (isset($row['password_hash']) && password_verify($pword, $row['password_hash'])) {
                $dataSet[] = new UserData($this->normaliseRow($row));
            }
        }
        return $dataSet;
    }
}
