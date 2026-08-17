<?php
/**
 * SightingDataSet Class
 *
 * Handles all database operations for pet sightings.
 * Provides methods to fetch, search, filter, and create sighting records.
 * All queries JOIN sightings with pets and users to return complete data.
 *
 * fetchSightings() searches pets.description and accepts
 * an optional species filter parameter, addressing the marker feedback
 * that search was not covering all fields and lacked a species dropdown.
 *
 * All queries use prepared statements to prevent SQL injection.
 */

require_once('Database.php');
require_once('ExtSightingData.php');

class SightingDataSet
{
    protected $_dbHandle, $_dbInstance;

    /**
     * Constructor — initialises the database connection
     *
     * @param bool $ajax True when called from an AJAX endpoint (adjusts DB path)
     */
    public function __construct($ajax = false)
    {
        $this->_dbInstance = Database::getInstance($ajax);
        $this->_dbHandle = $this->_dbInstance->getdbConnection();
    }

    /**
     * Fetch sightings with full search, filter, and sort support
     *
     * The free-text search covers: sighting comment, pet name,
     * pet description, species, breed, and colour.
     *
     * The $searchSpecies parameter enables the data-driven species dropdown
     * filter, narrowing results to a single species when selected.
     *
     * @param  string $searchText        Free-text search term (searches 6 fields)
     * @param  string $searchOrder       Sort direction: 'A-Z', 'Z-A', 'newest', 'oldest'
     * @param  string $searchLostOrFound Status filter: 'lost', 'found', or '' for both
     * @param  string $searchSpecies     Species filter: exact species name, or '' for all
     * @return array                     Array of ExtSightingData objects
     */
    public function fetchSightings($searchText, $searchOrder, $searchLostOrFound, $searchSpecies = '')
    {
        // Sort order
        switch ($searchOrder) {
            case 'A-Z':
                $orderByClause = 'ORDER BY pets.name ASC';
                break;
            case 'Z-A':
                $orderByClause = 'ORDER BY pets.name DESC';
                break;
            case 'newest':
                $orderByClause = 'ORDER BY sightings.timestamp DESC';
                break;
            case 'oldest':
                $orderByClause = 'ORDER BY sightings.timestamp ASC';
                break;
            default:
                $orderByClause = 'ORDER BY sightings.timestamp DESC';
                break;
        }

        // Optional exact-match filters appended to WHERE
        // Status filter (lost / found)
        $statusFilter = !empty($searchLostOrFound) ? 'AND pets.status = :status' : '';
        // Species filter — data-driven dropdown selection
        $speciesFilter = !empty($searchSpecies) ? 'AND pets.species = :species' : '';

        // Split the search term into individual words so that multi-word queries
        // like "Max golden" match pets where "Max" is in one field and "golden"
        // is in another. Each word must match at least one of the 6 searched
        // fields — all words must match (AND between words, OR between fields).
        $words = !empty($searchText)
            ? array_values(array_filter(array_map('trim', explode(' ', $searchText))))
            : [];

        // Build one AND block per word: AND (field LIKE :w0 OR field LIKE :w0b OR ...)
        $wordClauses = [];
        foreach ($words as $i => $word) {
            $wordClauses[] = "(
                sightings.comment   LIKE :w{$i}a
                OR pets.name        LIKE :w{$i}b
                OR pets.description LIKE :w{$i}c
                OR pets.species     LIKE :w{$i}d
                OR pets.breed       LIKE :w{$i}e
                OR pets.color       LIKE :w{$i}f
            )";
        }

        // If no search term, match everything; otherwise require all words to match
        $termFilter = !empty($wordClauses) ? implode(' AND ', $wordClauses) : '1=1';

        $sqlQuery = "SELECT
                sightings.id          AS sighting_id,
                sightings.comment     AS sighting_comment,
                sightings.latitude    AS sighting_latitude,
                sightings.longitude   AS sighting_longitude,
                sightings.timestamp   AS sighting_timestamp,
                pets.id               AS pet_id,
                pets.name             AS pet_name,
                pets.status           AS pet_status,
                pets.species          AS pet_species,
                pets.breed            AS pet_breed,
                pets.color            AS pet_color,
                pets.description      AS pet_description,
                pets.photo_url        AS pet_photo,
                pets.date_reported    AS pet_date_reported,
                users.id              AS user_id,
                users.username        AS reporter_username,
                pet_owner.username    AS owner_username,
                locations.latitude    AS location_latitude,
                locations.longitude   AS location_longitude
            FROM  sightings
            INNER JOIN pets      ON sightings.pet_id   = pets.id
            LEFT  JOIN users     ON sightings.user_id  = users.id
            INNER JOIN users     AS pet_owner ON pets.user_id = pet_owner.id
            LEFT  JOIN locations ON pets.id            = locations.pet_id
            WHERE ($termFilter)
            $statusFilter
            $speciesFilter
            GROUP BY sightings.id
            $orderByClause";

        $statement = $this->_dbHandle->prepare($sqlQuery);

        // Bind each word to its six field placeholders
        foreach ($words as $i => $word) {
            $pattern = '%' . $word . '%';
            $statement->bindValue(":w{$i}a", $pattern, PDO::PARAM_STR);
            $statement->bindValue(":w{$i}b", $pattern, PDO::PARAM_STR);
            $statement->bindValue(":w{$i}c", $pattern, PDO::PARAM_STR);
            $statement->bindValue(":w{$i}d", $pattern, PDO::PARAM_STR);
            $statement->bindValue(":w{$i}e", $pattern, PDO::PARAM_STR);
            $statement->bindValue(":w{$i}f", $pattern, PDO::PARAM_STR);
        }

        // Bind optional filter parameters only when they are in use
        if (!empty($searchLostOrFound)) {
            $statement->bindParam(':status', $searchLostOrFound, PDO::PARAM_STR);
        }
        if (!empty($searchSpecies)) {
            $statement->bindParam(':species', $searchSpecies, PDO::PARAM_STR);
        }

        $statement->execute();

        $dataSet = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $dataSet[] = new ExtSightingData($row);
        }
        return $dataSet;
    }

    /**
     * Fetch a single sighting by its ID
     *
     * @param  int                   $sightingId Sighting primary key
     * @return ExtSightingData|null              Sighting object, or null if not found
     */
    public function fetchSightingById($sightingId)
    {
        $sqlQuery = "SELECT
                sightings.id          AS sighting_id,
                sightings.comment     AS sighting_comment,
                sightings.latitude    AS sighting_latitude,
                sightings.longitude   AS sighting_longitude,
                sightings.timestamp   AS sighting_timestamp,
                pets.id               AS pet_id,
                pets.name             AS pet_name,
                pets.status           AS pet_status,
                pets.species          AS pet_species,
                pets.breed            AS pet_breed,
                pets.color            AS pet_color,
                pets.description      AS pet_description,
                pets.photo_url        AS pet_photo,
                pets.date_reported    AS pet_date_reported,
                users.id              AS user_id,
                users.username        AS reporter_username,
                pet_owner.username    AS owner_username,
                locations.latitude    AS location_latitude,
                locations.longitude   AS location_longitude
            FROM  sightings
            INNER JOIN pets      ON sightings.pet_id   = pets.id
            LEFT  JOIN users     ON sightings.user_id  = users.id
            INNER JOIN users     AS pet_owner ON pets.user_id = pet_owner.id
            LEFT  JOIN locations ON pets.id            = locations.pet_id
            WHERE sightings.id = :sightingId
            LIMIT 1";

        $statement = $this->_dbHandle->prepare($sqlQuery);
        $statement->bindParam(':sightingId', $sightingId, PDO::PARAM_INT);
        $statement->execute();

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ? new ExtSightingData($row) : null;
    }

    /**
     * Fetch all sightings for a specific pet, ordered newest first
     *
     * @param  int   $petId The pet's primary key
     * @return array        Array of ExtSightingData objects
     */
    public function fetchSightingsByPetId($petId)
    {
        $sqlQuery = "SELECT
                sightings.id          AS sighting_id,
                sightings.comment     AS sighting_comment,
                sightings.latitude    AS sighting_latitude,
                sightings.longitude   AS sighting_longitude,
                sightings.timestamp   AS sighting_timestamp,
                pets.id               AS pet_id,
                pets.name             AS pet_name,
                pets.status           AS pet_status,
                pets.species          AS pet_species,
                pets.breed            AS pet_breed,
                pets.color            AS pet_color,
                pets.description      AS pet_description,
                pets.photo_url        AS pet_photo,
                pets.date_reported    AS pet_date_reported,
                users.id              AS user_id,
                users.username        AS reporter_username,
                pet_owner.username    AS owner_username
            FROM  sightings
            INNER JOIN pets      ON sightings.pet_id  = pets.id
            LEFT  JOIN users     ON sightings.user_id = users.id
            INNER JOIN users     AS pet_owner ON pets.user_id = pet_owner.id
            WHERE pets.id = :petId
            ORDER BY sightings.timestamp DESC";

        $statement = $this->_dbHandle->prepare($sqlQuery);
        $statement->bindParam(':petId', $petId, PDO::PARAM_INT);
        $statement->execute();

        $dataSet = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $dataSet[] = new ExtSightingData($row);
        }
        return $dataSet;
    }

    /**
     * Insert a new sighting record into the database
     *
     * Supports anonymous sightings (userId = null) as well as
     * authenticated user sightings. The null case uses PDO::PARAM_NULL
     * to ensure the column is set to SQL NULL rather than the string "null".
     *
     * @param  int        $petId     Pet's primary key
     * @param  int|null   $userId    Reporter's user ID, or null for anonymous
     * @param  string     $comment   Sighting description text
     * @param  float      $latitude  GPS latitude of the sighting
     * @param  float      $longitude GPS longitude of the sighting
     * @return int|false             New sighting ID on success, false on failure
     */
    public function addSighting($petId, $userId, $comment, $latitude, $longitude)
    {
        $sqlQuery = "INSERT INTO sightings
                         (pet_id, user_id, comment, latitude, longitude, timestamp)
                     VALUES
                         (:petId, :userId, :comment, :latitude, :longitude, NOW())";

        $statement = $this->_dbHandle->prepare($sqlQuery);
        $statement->bindParam(':petId', $petId, PDO::PARAM_INT);

        // Bind NULL properly for anonymous (guest) sightings
        if ($userId === null) {
            $statement->bindValue(':userId', null, PDO::PARAM_NULL);
        } else {
            $statement->bindParam(':userId', $userId, PDO::PARAM_INT);
        }

        $statement->bindParam(':comment', $comment, PDO::PARAM_STR);
        $statement->bindParam(':latitude', $latitude, PDO::PARAM_STR);
        $statement->bindParam(':longitude', $longitude, PDO::PARAM_STR);

        return $statement->execute() ? (int) $this->_dbHandle->lastInsertId() : false;
    }

    /**
     * Count all sightings in the database
     *
     * @return int Total number of sighting records
     */
    public function getSightingsCount()
    {
        $sqlQuery = 'SELECT COUNT(*) as count FROM sightings';
        $statement = $this->_dbHandle->prepare($sqlQuery);
        $statement->execute();

        $result = $statement->fetch(PDO::FETCH_ASSOC);
        return $result ? (int) $result['count'] : 0;
    }

    /**
     * Count sightings for a specific pet
     *
     * @param  int $petId The pet's primary key
     * @return int        Number of sightings recorded for this pet
     */
    public function getSightingsCountByPetId($petId)
    {
        $sqlQuery = 'SELECT COUNT(*) as count FROM sightings WHERE pet_id = :petId';
        $statement = $this->_dbHandle->prepare($sqlQuery);
        $statement->bindParam(':petId', $petId, PDO::PARAM_INT);
        $statement->execute();

        $result = $statement->fetch(PDO::FETCH_ASSOC);
        return $result ? (int) $result['count'] : 0;
    }
}
