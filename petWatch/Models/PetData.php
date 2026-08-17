<?php
/**
 * PetData Class
 *
 * Represents a single pet record from the database.
 * Encapsulates pet information including name, species, breed, colour,
 * description, photo, status (lost/found), and date reported.
 *
 * toArray() method serialises the object to a plain
 * associative array for use with json_encode() in AJAX endpoints.
 * This meets the assessment criterion "JSON/XML via extended DB classes".
 */
class PetData
{
    protected $_petID;
    protected $_userID;
    protected $_petName;
    protected $_status;
    protected $_species;
    protected $_breed;
    protected $_color;
    protected $_description;
    protected $_photo;
    protected $_dateReported;

    /**
     * Constructor — maps a raw DB row to object properties.
     *
     * @param array $dbRow Associative array from PDO fetch
     */
    public function __construct($dbRow)
    {
        $this->_petID = $dbRow['id'] ?? null;
        $this->_userID = $dbRow['user_id'] ?? null;
        $this->_petName = $dbRow['name'] ?? '';
        $this->_status = $dbRow['status'] ?? '';
        $this->_species = $dbRow['species'] ?? '';
        $this->_breed = $dbRow['breed'] ?? '';
        $this->_color = $dbRow['color'] ?? '';
        $this->_description = $dbRow['description'] ?? '';
        $this->_photo = $dbRow['photo_url'] ?? '';
        $this->_dateReported = $dbRow['date_reported'] ?? '';
    }

    // Getters

    /** @return int|null */
    public function getPetID()
    {
        return $this->_petID;
    }

    /** @return int|null */
    public function getUserID()
    {
        return $this->_userID;
    }

    /** @return string */
    public function getPetName()
    {
        return $this->_petName;
    }

    /** @return string 'lost' or 'found' */
    public function getPetStatus()
    {
        return $this->_status;
    }

    /** @return string e.g. 'Dog', 'Cat' */
    public function getPetSpecies()
    {
        return $this->_species;
    }

    /** @return string e.g. 'Labrador' */
    public function getPetBreed()
    {
        return $this->_breed;
    }

    /** @return string e.g. 'Golden' */
    public function getPetColor()
    {
        return $this->_color;
    }

    /** @return string Free-text description */
    public function getPetDescription()
    {
        return $this->_description;
    }

    /** @return string Stored filename of the pet's uploaded photo */
    public function getPetPhoto()
    {
        return $this->_photo;
    }

    /** @return string Date string e.g. '2025-11-10' */
    public function getDateReported()
    {
        return $this->_dateReported;
    }

    // Backward-compatible aliases (used by existing views)
    public function getStatus()
    {
        return $this->_status;
    }
    public function getSpecies()
    {
        return $this->_species;
    }
    public function getBreed()
    {
        return $this->_breed;
    }
    public function getColor()
    {
        return $this->_color;
    }
    public function getDescription()
    {
        return $this->_description;
    }
    public function getPhoto()
    {
        return $this->_photo;
    }

    // Helper Methods

    /**
     * Returns the uploaded photo path if one exists, otherwise returns a
     * species-specific default image via getDefaultPhotoPath().
     * Passes the pet's own ID so different pets get different default images.
     *
     * @return string Relative path — never empty
     */
    public function getPhotoPath()
    {
        if (!empty($this->_photo)) {
            return 'images/pet-photos/' . $this->_photo;
        }
        return self::getDefaultPhotoPath($this->_species, $this->_petID);
    }

    /**
     * Check whether a real uploaded photo filename has been stored.
     *
     * @return bool True if an uploaded photo exists
     */
    public function hasPhoto()
    {
        return !empty($this->_photo);
    }

    /**
     * Returns the path to a default image for the given species.
     * Used when a pet has no uploaded photo.
     *
     * Images are stored in pools per species (e.g. images/defaults/dog/).
     * The pet ID is used to cycle through the pool so different pets
     * get different images rather than all sharing the same one.
     *
     * Falls back to the generic default.svg for unknown species.
     *
     * @param  string $species  Pet species (e.g. 'Dog', 'Cat')
     * @param  int    $petId    Pet ID used to pick from the image pool
     * @return string           Relative image path
     */
    public static function getDefaultPhotoPath($species, $petId = 0)
    {
        // Number of images available per species in images/defaults/{species}/
        $pools = [
            'dog' => 6,
            'cat' => 6,
            'bird' => 6,
            'rabbit' => 6,
            'guinea pig' => 6,
            'hamster' => 6,
        ];

        $key = strtolower(trim($species));
        $count = $pools[$key] ?? 0;

        // Unknown species — use the generic SVG fallback
        if ($count === 0) {
            return 'images/defaults/default.svg';
        }

        // Cycle through the pool using pet ID for variety across cards
        $index = ($petId > 0) ? (($petId - 1) % $count) + 1 : 1;
        $folder = str_replace(' ', '_', $key); // e.g. "guinea pig" → "guinea_pig"

        return "images/defaults/{$folder}/{$folder}{$index}.jpg";
    }

    // JSON Serialisation

    /**
     * Serialise this pet to a plain associative array.
     *
     * Used by AJAX endpoints to build JSON responses via json_encode().
     * All string values are passed through htmlspecialchars() to ensure
     * safe output encoding even when consumed by JavaScript.
     *
     * The photo_url field contains only the stored filename; the full
     * relative path is also included as photo_path for convenience.
     *
     * @return array Plain array representation of this pet record
     */
    public function toArray()
    {
        return [
            'id' => (int) $this->_petID,
            'user_id' => (int) $this->_userID,
            'name' => htmlspecialchars($this->_petName, ENT_QUOTES, 'UTF-8'),
            'status' => htmlspecialchars($this->_status, ENT_QUOTES, 'UTF-8'),
            'species' => htmlspecialchars($this->_species, ENT_QUOTES, 'UTF-8'),
            'breed' => htmlspecialchars($this->_breed, ENT_QUOTES, 'UTF-8'),
            'color' => htmlspecialchars($this->_color, ENT_QUOTES, 'UTF-8'),
            'description' => htmlspecialchars($this->_description, ENT_QUOTES, 'UTF-8'),
            'photo_url' => htmlspecialchars($this->_photo, ENT_QUOTES, 'UTF-8'),
            'photo_path' => htmlspecialchars($this->getPhotoPath(), ENT_QUOTES, 'UTF-8'),  // Never empty — uses species placeholder if no upload
            'date_reported' => htmlspecialchars($this->_dateReported, ENT_QUOTES, 'UTF-8'),
        ];
    }
}
