<?php
/**
 * Database Configuration
 *
 * Stores MariaDB connection credentials for the application.
 * This file is the single place to update credentials when moving between
 * environments (local XAMPP ↔ Poseidon live server).
 *
 * SECURITY: This file must never be committed to a public repository.
 *           Add it to .gitignore (if using version control.)
 *
 * ── Local XAMPP (development) ────────────────────────────────────────────────
 * Host:     localhost
 * Database: petwatch
 * User:     root
 * Password: (empty on default XAMPP install)
 *
 * ── Poseidon (live/production) ───────────────────────────────────────────────
 * Host:     localhost  (MariaDB is local to Poseidon)
 * Database: your_db_name   ← replace with your actual Poseidon DB name
 * User:     your_db_user   ← replace with your Poseidon DB username
 * Password: your_password  ← replace with your Poseidon DB password
 */

// Active environment: switch between 'local' and 'production'
define('DB_ENV', 'production');

if (DB_ENV === 'production') {
    // Poseidon / live server credentials
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'sge242');   // ← dbname
    define('DB_USER', 'sge242');   // ← username
    define('DB_PASS', 'gyqOvmK3hhRrzfA');   // ← password
    define('DB_CHARSET', 'utf8mb4');
} else {
    // Local XAMPP development credentials
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'petwatch');
    define('DB_USER', 'root');
    define('DB_PASS', '');           // Default XAMPP has no root password
    define('DB_CHARSET', 'utf8mb4');
}
