<?php
/**
 * PetDataSet Class
 *
 * Handles all database operations for pet records.
 * Provides methods to fetch, create, update, and delete pets.
 * Also exposes utility queries such as fetching distinct species
 * values (used to populate data-driven filter dropdowns in views).
 * All queries use prepared statements to prevent SQL injection.
 */

require_once('Database.php');
require_once('PetData.php');

class PetDataSet
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
     * Fetch all pets belonging to a specific user
     *
     * @param  int   $userID The owner's user ID
     * @return array         Array of PetData objects ordered by name
     */
    public function fetchUsersPets($userID)
    {
        $sqlQuery = 'SELECT * FROM pets WHERE user_id = :uid ORDER BY name ASC';
        $statement = $this->_dbHandle->prepare($sqlQuery);
        $statement->bindParam(':uid', $userID, PDO::PARAM_INT);
        $statement->execute();

        $dataSet = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $dataSet[] = new PetData($row);
        }
        return $dataSet;
    }

    /**
     * Fetch all pets (for admin dashboard or general viewing)
     *
     * @return array Array of PetData objects ordered by name
     */
    public function fetchAllPets()
    {
        $sqlQuery = 'SELECT * FROM pets ORDER BY name ASC';
        $statement = $this->_dbHandle->prepare($sqlQuery);
        $statement->execute();

        $dataSet = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $dataSet[] = new PetData($row);
        }
        return $dataSet;
    }

    /**
     * Fetch all missing (lost) pets
     *
     * @return array Array of PetData objects with status 'lost'
     */
    public function fetchMissingPets()
    {
        $sqlQuery = "SELECT * FROM pets WHERE status = 'lost' ORDER BY name ASC";
        $statement = $this->_dbHandle->prepare($sqlQuery);
        $statement->execute();

        $dataSet = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $dataSet[] = new PetData($row);
        }
        return $dataSet;
    }

    /**
     * Fetch a single pet by ID
     *
     * @param  int           $petID The pet's primary key
     * @return PetData|null         PetData object or null if not found
     */
    public function fetchPetById($petID)
    {
        $sqlQuery = 'SELECT * FROM pets WHERE id = :pid LIMIT 1';
        $statement = $this->_dbHandle->prepare($sqlQuery);
        $statement->bindParam(':pid', $petID, PDO::PARAM_INT);
        $statement->execute();

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ? new PetData($row) : null;
    }

    /**
     * Fetch all distinct species values from the pets table
     *
     * Used to build data-driven species filter dropdowns in the search UI,
     * ensuring the list always reflects what is actually in the database.
     *
     * @return array Plain array of species strings, sorted alphabetically
     */
    public function fetchDistinctSpecies()
    {
        $sqlQuery = 'SELECT DISTINCT species FROM pets WHERE species != "" ORDER BY species ASC';
        $statement = $this->_dbHandle->prepare($sqlQuery);
        $statement->execute();

        $species = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $species[] = $row['species'];
        }
        return $species;
    }

    /**
     * Add a new pet record to the database
     *
     * Only admin (owner) users should be permitted to call this; the
     * role check is enforced at the controller level before this method
     * is invoked.
     *
     * @param  int        $userID         Owner's user ID
     * @param  string     $petName        Pet's name
     * @param  string     $petStatus      'lost' or 'found'
     * @param  string     $petSpecies     Species (e.g. Dog, Cat)
     * @param  string     $petBreed       Breed description
     * @param  string     $petColor       Colour description
     * @param  string     $petDescription Longer free-text description
     * @param  string     $petPhoto       Stored filename of uploaded photo
     * @return int|false                  New pet ID on success, false on failure
     */
    public function addPet($userID, $petName, $petStatus, $petSpecies, $petBreed, $petColor, $petDescription, $petPhoto)
    {
        $sqlQuery = "INSERT INTO pets
                         (user_id, name, status, species, breed, color, description, photo_url, date_reported)
                     VALUES
                         (:uid, :pname, :pstatus, :pspecies, :pbreed, :pcolor, :pdesc, :pphoto, CURDATE())";

        $statement = $this->_dbHandle->prepare($sqlQuery);
        $statement->bindParam(':uid', $userID, PDO::PARAM_INT);
        $statement->bindParam(':pname', $petName);
        $statement->bindParam(':pstatus', $petStatus);
        $statement->bindParam(':pspecies', $petSpecies);
        $statement->bindParam(':pbreed', $petBreed);
        $statement->bindParam(':pcolor', $petColor);
        $statement->bindParam(':pdesc', $petDescription);
        $statement->bindParam(':pphoto', $petPhoto);

        return $statement->execute() ? (int) $this->_dbHandle->lastInsertId() : false;
    }

    /**
     * Update an existing pet record
     *
     * @param  int    $petID          Pet's primary key
     * @param  string $petName        Updated name
     * @param  string $petStatus      Updated status ('lost' or 'found')
     * @param  string $petSpecies     Updated species
     * @param  string $petBreed       Updated breed
     * @param  string $petColor       Updated colour
     * @param  string $petDescription Updated description
     * @param  string $petPhoto       Updated photo filename
     * @return bool                   True on success, false on failure
     */
    public function updatePet($petID, $petName, $petStatus, $petSpecies, $petBreed, $petColor, $petDescription, $petPhoto)
    {
        $sqlQuery = 'UPDATE pets
                     SET name        = :pname,
                         status      = :pstatus,
                         species     = :pspecies,
                         breed       = :pbreed,
                         color       = :pcolor,
                         description = :pdesc,
                         photo_url   = :pphoto
                     WHERE id = :pid';

        $statement = $this->_dbHandle->prepare($sqlQuery);
        $statement->bindParam(':pid', $petID, PDO::PARAM_INT);
        $statement->bindParam(':pname', $petName);
        $statement->bindParam(':pstatus', $petStatus);
        $statement->bindParam(':pspecies', $petSpecies);
        $statement->bindParam(':pbreed', $petBreed);
        $statement->bindParam(':pcolor', $petColor);
        $statement->bindParam(':pdesc', $petDescription);
        $statement->bindParam(':pphoto', $petPhoto);

        return $statement->execute();
    }

    /**
     * Delete a pet record by ID
     *
     * @param  int  $petID Pet's primary key
     * @return bool        True on success, false on failure
     */
    public function deletePet($petID)
    {
        $sqlQuery = 'DELETE FROM pets WHERE id = :pid';
        $statement = $this->_dbHandle->prepare($sqlQuery);
        $statement->bindParam(':pid', $petID, PDO::PARAM_INT);
        return $statement->execute();
    }

    /**
     * Update only the status of a specific pet
     *
     * @param  int    $petID  Pet's primary key
     * @param  string $status New status value ('lost' or 'found')
     * @return bool           True on success, false on failure
     */
    public function updatePetStatus($petID, $status)
    {
        $sqlQuery = 'UPDATE pets SET status = :status WHERE id = :pid';
        $statement = $this->_dbHandle->prepare($sqlQuery);
        $statement->bindParam(':pid', $petID, PDO::PARAM_INT);
        $statement->bindParam(':status', $status, PDO::PARAM_STR);
        return $statement->execute();
    }

    /**
     * Count all pets in the database
     *
     * @return int Total number of pet records
     */
    public function getPetsCount()
    {
        $sqlQuery = 'SELECT COUNT(*) as count FROM pets';
        $statement = $this->_dbHandle->prepare($sqlQuery);
        $statement->execute();

        $result = $statement->fetch(PDO::FETCH_ASSOC);
        return $result ? (int) $result['count'] : 0;
    }

    /**
     * Count pets by status
     *
     * @param  string $status 'lost' or 'found'
     * @return int            Number of pets with the given status
     */
    public function getPetsCountByStatus($status)
    {
        $sqlQuery = 'SELECT COUNT(*) as count FROM pets WHERE status = :status';
        $statement = $this->_dbHandle->prepare($sqlQuery);
        $statement->bindParam(':status', $status, PDO::PARAM_STR);
        $statement->execute();

        $result = $statement->fetch(PDO::FETCH_ASSOC);
        return $result ? (int) $result['count'] : 0;
    }

    /**
     * Fetch pets for the interactive map with location and latest sighting data.
     *
     * Returns pets enriched with:
     *  - Their last stored location (from the locations table)
     *  - Their most recent sighting's coordinates, comment, timestamp, and reporter
     *
     * This is done in a single JOIN query rather than N+1 per-pet queries,
     * making it efficient for 100s of records (performance criterion).
     *
     * The correlated subquery on sightings (fetching max timestamp) is
     * compatible with all MariaDB versions unlike window functions.
     *
     * @param  string $status Status filter: 'lost', 'found', or '' for both
     * @param  int    $limit  Max records to return (for pagination)
     * @param  int    $offset Row offset (for pagination)
     * @return array          Array of plain associative arrays (map-ready rows)
     */
    public function fetchPetsForMap($status = 'lost', $limit = 50, $offset = 0)
    {
        // Build optional status WHERE clause
        $statusWhere = !empty($status) ? 'AND p.status = :status' : '';

        // Single query joining pets, owners, locations, and most recent sighting.
        // The correlated subquery on sightings avoids fetching all sightings rows
        // then filtering in PHP — the DB does the heavy lifting.
        $sqlQuery = "SELECT
                p.id               AS pet_id,
                p.name             AS pet_name,
                p.species          AS pet_species,
                p.breed            AS pet_breed,
                p.color            AS pet_color,
                p.photo_url        AS pet_photo,
                p.status           AS pet_status,
                p.description      AS pet_description,
                p.date_reported    AS pet_date_reported,
                owner.username     AS owner_username,
                l.latitude         AS location_lat,
                l.longitude        AS location_lng,
                s.latitude         AS last_sighting_lat,
                s.longitude        AS last_sighting_lng,
                s.comment          AS last_sighting_comment,
                s.timestamp        AS last_sighting_time,
                reporter.username  AS last_reporter_username
            FROM pets p
            INNER JOIN users  owner    ON p.user_id  = owner.id
            LEFT  JOIN locations l     ON l.pet_id   = p.id
            LEFT  JOIN sightings s     ON s.id = (
                -- Correlated subquery: fetch the single most recent sighting for this pet.
                -- Uses the index on sightings.pet_id for efficiency.
                SELECT id FROM sightings
                WHERE pet_id = p.id
                ORDER BY timestamp DESC
                LIMIT 1
            )
            LEFT  JOIN users reporter  ON s.user_id  = reporter.id
            WHERE 1=1 $statusWhere
            ORDER BY p.name ASC
            LIMIT :lim OFFSET :off";

        $statement = $this->_dbHandle->prepare($sqlQuery);

        if (!empty($status)) {
            $statement->bindParam(':status', $status, PDO::PARAM_STR);
        }
        $statement->bindParam(':lim', $limit, PDO::PARAM_INT);
        $statement->bindParam(':off', $offset, PDO::PARAM_INT);
        $statement->execute();

        // Return plain rows — the AJAX endpoint will encode these directly,
        // so there is no need to instantiate PetData objects here.
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count pets for the map endpoint (matches fetchPetsForMap filters).
     *
     * Used to calculate total pages for the map's paginated AJAX loading.
     *
     * @param  string $status Status filter: 'lost', 'found', or '' for both
     * @return int            Total number of matching pet records
     */
    public function countPetsForMap($status = 'lost')
    {
        $statusWhere = !empty($status) ? 'WHERE status = :status' : '';
        $sqlQuery = "SELECT COUNT(*) AS total FROM pets $statusWhere";
        $statement = $this->_dbHandle->prepare($sqlQuery);

        if (!empty($status)) {
            $statement->bindParam(':status', $status, PDO::PARAM_STR);
        }
        $statement->execute();

        $result = $statement->fetch(PDO::FETCH_ASSOC);
        return $result ? (int) $result['total'] : 0;
    }
}
