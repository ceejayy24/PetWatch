<?php
/**
 * ExtSightingData Class
 *
 * Extended sighting class that includes joined pet, user, and location data.
 * Extends SightingData and adds all fields returned by the JOIN queries in
 * SightingDataSet::fetchSightings().
 *
 * toArray() overrides the parent and produces a rich array
 * containing all fields needed to render a map marker, popup, and sidebar
 * list item in the JavaScript PetWatchMap class — all in one round trip.
 *
 * Inheritance: ExtSightingData → SightingData
 */

require_once('SightingData.php');
require_once('PetData.php'); // needed for PetData::getDefaultPhotoPath()

class ExtSightingData extends SightingData
{
    // Joined pet fields
    protected $pet_name;
    protected $pet_status;
    protected $pet_species;
    protected $pet_breed;
    protected $pet_color;
    protected $pet_description;
    protected $pet_photo;
    protected $pet_date_reported;

    // Joined user fields
    protected $reporter_username;
    protected $owner_username;

    // Joined location fields (from locations table)
    protected $location_latitude;
    protected $location_longitude;

    /**
     * Constructor — calls parent for core fields, then maps extended fields.
     *
     * @param array $row Associative array from a JOIN query in SightingDataSet
     */
    public function __construct($row)
    {
        // Initialise core sighting fields via parent
        parent::__construct($row);

        // Extended pet fields (from JOIN with pets table)
        $this->pet_name = $row['pet_name'] ?? '';
        $this->pet_status = $row['pet_status'] ?? '';
        $this->pet_species = $row['pet_species'] ?? '';
        $this->pet_breed = $row['pet_breed'] ?? '';
        $this->pet_color = $row['pet_color'] ?? '';
        $this->pet_description = $row['pet_description'] ?? '';
        $this->pet_photo = $row['pet_photo'] ?? '';
        $this->pet_date_reported = $row['pet_date_reported'] ?? '';

        // Reporter and owner usernames (from JOINs with users table)
        $this->reporter_username = $row['reporter_username'] ?? 'Anonymous';
        $this->owner_username = $row['owner_username'] ?? 'Unknown';

        // Last known location coordinates (from JOIN with locations table)
        $this->location_latitude = $row['location_latitude'] ?? null;
        $this->location_longitude = $row['location_longitude'] ?? null;
    }

    // Pet Getters

    /** @return string */
    public function getPetName()
    {
        return $this->pet_name;
    }

    /** @return string 'lost' or 'found' */
    public function getPetStatus()
    {
        return $this->pet_status;
    }

    /** @return string e.g. 'Dog' */
    public function getPetSpecies()
    {
        return $this->pet_species;
    }

    /** @return string e.g. 'Labrador' */
    public function getPetBreed()
    {
        return $this->pet_breed;
    }

    /** @return string e.g. 'Golden' */
    public function getPetColor()
    {
        return $this->pet_color;
    }

    /** @return string */
    public function getPetDescription()
    {
        return $this->pet_description;
    }

    /** @return string Stored photo filename */
    public function getPetPhoto()
    {
        return $this->pet_photo;
    }

    /** @return string Date string e.g. '2025-05-20' */
    public function getPetDateReported()
    {
        return $this->pet_date_reported;
    }

    /**
     * Get a human-friendly formatted date reported.
     *
     * @return string e.g. 'May 20, 2025'
     */
    public function getFormattedDateReported()
    {
        if ($this->pet_date_reported) {
            return (new DateTime($this->pet_date_reported))->format('M j, Y');
        }
        return 'Unknown';
    }

    // User Getters

    /** @return string Username of who submitted the sighting */
    public function getReporterUsername()
    {
        return $this->reporter_username;
    }

    /** @return string Username of the pet's owner */
    public function getOwnerUsername()
    {
        return $this->owner_username;
    }

    // Location Getters

    /** @return float|null Latitude from the locations table (last known position) */
    public function getLocationLatitude()
    {
        return $this->location_latitude;
    }

    /** @return float|null Longitude from the locations table */
    public function getLocationLongitude()
    {
        return $this->location_longitude;
    }

    // Utility Methods

    /**
     * Check whether a photo filename has been stored for this pet.
     *
     * @return bool
     */
    public function hasPhoto()
    {
        return !empty($this->pet_photo);
    }

    /**
     * Returns the uploaded photo path if one exists, otherwise delegates to
     * PetData::getDefaultPhotoPath() to get a species default image.
     * Passes the pet ID so the correct image is picked from the pool.
     *
     * @return string Relative image path — never empty
     */
    public function getPhotoPath()
    {
        if ($this->hasPhoto()) {
            return 'images/pet-photos/' . $this->pet_photo;
        }
        return PetData::getDefaultPhotoPath($this->pet_species, $this->pet_id);
    }

    /**
     * Check whether this sighting has GPS coordinates attached.
     *
     * @return bool
     */
    public function hasCoordinates()
    {
        return ($this->latitude !== null && $this->longitude !== null);
    }

    /**
     * Get the Bootstrap badge CSS class for the pet's status.
     *
     * @return string e.g. 'bg-danger' for lost pets
     */
    public function getStatusBadgeClass()
    {
        return strtolower($this->pet_status) === 'lost' ? 'bg-danger' : 'bg-success';
    }

    // Setters

    /** @param string $status */
    public function setPetStatus($status)
    {
        $this->pet_status = $status;
    }

    /** @param string $username */
    public function setReporterUsername($username)
    {
        $this->reporter_username = $username;
    }

    // JSON Serialisation

    /**
     * Serialise a full extended sighting to a plain associative array.
     *
     * Overrides SightingData::toArray() to include all joined fields.
     * This is the payload consumed by the JavaScript PetWatchMap class
     * to render map markers, popup info boxes, and the sidebar list.
     *
     * Coordinate priority for map placement:
     *   - sighting_lat/lng   = where THIS sighting was reported (used for marker)
     *   - location_lat/lng   = the pet's last known stored location (fallback)
     *
     * All string fields are HTML-encoded to prevent XSS when injected into
     * the DOM by JavaScript (belt-and-braces alongside JS textContent usage).
     *
     * @return array Complete plain array representation of this extended sighting
     */
    public function toArray()
    {
        // Start with the core sighting fields from the parent
        $base = parent::toArray();

        // Merge in the extended pet, user, and location data
        return array_merge($base, [
            // Pet fields
            'pet_name' => htmlspecialchars($this->pet_name, ENT_QUOTES, 'UTF-8'),
            'pet_status' => htmlspecialchars($this->pet_status, ENT_QUOTES, 'UTF-8'),
            'pet_species' => htmlspecialchars($this->pet_species, ENT_QUOTES, 'UTF-8'),
            'pet_breed' => htmlspecialchars($this->pet_breed, ENT_QUOTES, 'UTF-8'),
            'pet_color' => htmlspecialchars($this->pet_color, ENT_QUOTES, 'UTF-8'),
            'pet_description' => htmlspecialchars($this->pet_description, ENT_QUOTES, 'UTF-8'),
            'pet_photo' => htmlspecialchars($this->pet_photo, ENT_QUOTES, 'UTF-8'),
            'pet_photo_path' => htmlspecialchars($this->getPhotoPath(), ENT_QUOTES, 'UTF-8'),
            'pet_date_reported' => htmlspecialchars($this->pet_date_reported, ENT_QUOTES, 'UTF-8'),
            'pet_date_formatted' => $this->getFormattedDateReported(),
            'status_badge_class' => $this->getStatusBadgeClass(),

            // User fields
            'reporter_username' => htmlspecialchars($this->reporter_username, ENT_QUOTES, 'UTF-8'),
            'owner_username' => htmlspecialchars($this->owner_username, ENT_QUOTES, 'UTF-8'),

            // Location fields (last known position from locations table)
            'location_latitude' => $this->location_latitude !== null ? (float) $this->location_latitude : null,
            'location_longitude' => $this->location_longitude !== null ? (float) $this->location_longitude : null,

            // Convenience flags
            'has_photo' => $this->hasPhoto(),
            'has_coordinates' => $this->hasCoordinates(),
        ]);
    }
}
