<?php
/**
 * Database Class
 *
 * Manages the MariaDB database connection using PDO.
 * Implements the Singleton pattern to guarantee only one active connection
 * exists throughout the application's request lifecycle, avoiding the
 * overhead of opening multiple connections per page load.
 *
 * Migrated from SQLite (file-based) to MariaDB (server-based)
 * for Poseidon hosting. All credentials are sourced from db_config.php —
 * never hardcoded here — so the same class works on both local XAMPP
 * and the live Poseidon server by simply toggling DB_ENV in db_config.php.
 *
 * The $ajax constructor parameter is retained for backward compatibility
 * but is no longer used for path resolution (it was only needed for SQLite).
 *
 * Security: PDO error mode is set to ERRMODE_EXCEPTION so all database
 * errors throw catchable exceptions rather than silently failing.
 * PDO::ATTR_EMULATE_PREPARES is disabled to use true prepared statements,
 * which gives stronger protection against SQL injection.
 */

// Load credentials from the config file (kept separate for security)
require_once(__DIR__ . '/db_config.php');

class Database
{
    /**
     * @var Database|null Singleton instance — only one connection per request
     */
    protected static $_dbInstance = null;

    /**
     * @var PDO|null Active PDO connection handle
     */
    protected $_dbHandle;

    /**
     * Singleton accessor
     *
     * Returns the existing Database instance or creates a new one.
     * The $ajax parameter is kept for backward compatibility with
     * existing DataSet constructors that pass it through.
     *
     * @param  bool     $ajax No longer used (was for SQLite path resolution)
     * @return Database       The single shared Database instance
     */
    public static function getInstance($ajax = false)
    {
        if (self::$_dbInstance === null) {
            self::$_dbInstance = new self($ajax);
        }
        return self::$_dbInstance;
    }

    /**
     * Private constructor — called only by getInstance()
     *
     * Builds the PDO DSN from db_config.php constants and opens
     * the MariaDB connection with secure default attributes set.
     *
     * @param bool $ajax Unused — retained for signature compatibility
     */
    private function __construct($ajax)
    {
        try {
            // Build PDO DSN using constants defined in db_config.php
            $dsn = 'mysql:host=' . DB_HOST
                . ';dbname=' . DB_NAME
                . ';charset=' . DB_CHARSET;

            // PDO options applied at connection time
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,   // Throw on error
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // Always return assoc arrays
                PDO::ATTR_EMULATE_PREPARES => false,                    // True prepared statements
            ];

            $this->_dbHandle = new PDO($dsn, DB_USER, DB_PASS, $options);

        } catch (PDOException $e) {
            // Output a safe error message — never expose credentials or stack trace
            error_log('PetWatch DB connection failed: ' . $e->getMessage());
            echo 'Database connection failed. Please try again later.';
            exit;
        }
    }

    /**
     * Return the active PDO connection handle
     *
     * Used by all DataSet classes to execute prepared statements.
     *
     * @return PDO The active database connection
     */
    public function getdbConnection()
    {
        return $this->_dbHandle;
    }

    /**
     * Destructor — close the connection when the object is destroyed
     */
    public function __destruct()
    {
        $this->_dbHandle = null;
    }
}
