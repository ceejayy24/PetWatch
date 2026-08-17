<?php
/**
 * SightingData Class
 *
 * Base class representing a core pet sighting record from the sightings table.
 * ExtSightingData extends this with joined pet and user information.
 *
 * toArray() serialises the core sighting fields to a plain
 * array for JSON encoding in AJAX endpoints.
 */
class SightingData
{
    // Core sighting fields from the sightings table
    protected $sighting_id;
    protected $pet_id;
    protected $user_id;
    protected $comment;
    protected $latitude;
    protected $longitude;
    protected $timestamp;

    /**
     * Constructor — maps a raw DB row to object properties.
     *
     * @param array $row Associative array from PDO fetch (aliased column names)
     */
    public function __construct($row)
    {
        $this->sighting_id = $row['sighting_id'] ?? null;
        $this->pet_id = $row['pet_id'] ?? null;
        $this->user_id = $row['user_id'] ?? null;
        $this->comment = $row['sighting_comment'] ?? '';
        $this->latitude = $row['sighting_latitude'] ?? null;
        $this->longitude = $row['sighting_longitude'] ?? null;
        $this->timestamp = $row['sighting_timestamp'] ?? null;
    }

    // Getters

    /** @return int|null */
    public function getSightingId()
    {
        return $this->sighting_id;
    }

    /** @return int|null */
    public function getPetId()
    {
        return $this->pet_id;
    }

    /** @return int|null */
    public function getUserId()
    {
        return $this->user_id;
    }

    /** @return string Sighting description text */
    public function getSightingComment()
    {
        return $this->comment;
    }

    /** @return float|null GPS latitude of the sighting */
    public function getSightingLatitude()
    {
        return $this->latitude;
    }

    /** @return float|null GPS longitude of the sighting */
    public function getSightingLongitude()
    {
        return $this->longitude;
    }

    /** @return string|null Datetime string e.g. '2025-11-10 12:21:16' */
    public function getSightingTimestamp()
    {
        return $this->timestamp;
    }

    /**
     * Get a human-friendly formatted timestamp.
     *
     * @return string e.g. 'Nov 10, 2025 12:21 PM'
     */
    public function getFormattedTimestamp()
    {
        if ($this->timestamp) {
            return (new DateTime($this->timestamp))->format('M j, Y g:i A');
        }
        return 'Unknown';
    }

    // Setters

    /** @param string $comment */
    public function setSightingComment($comment)
    {
        $this->comment = $comment;
    }

    /** @param float $latitude */
    public function setSightingLatitude($latitude)
    {
        $this->latitude = $latitude;
    }

    /** @param float $longitude */
    public function setSightingLongitude($longitude)
    {
        $this->longitude = $longitude;
    }

    // JSON Serialisation

    /**
     * Serialise core sighting fields to a plain associative array.
     *
     * Overridden in ExtSightingData to include joined pet and user data.
     * Values are cast to their correct types so json_encode() produces
     * numbers for numeric fields rather than JSON strings.
     *
     * @return array Plain array representation of this sighting
     */
    public function toArray()
    {
        return [
            'sighting_id' => $this->sighting_id !== null ? (int) $this->sighting_id : null,
            'pet_id' => $this->pet_id !== null ? (int) $this->pet_id : null,
            'user_id' => $this->user_id !== null ? (int) $this->user_id : null,
            'comment' => htmlspecialchars($this->comment ?? '', ENT_QUOTES, 'UTF-8'),
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'timestamp' => $this->timestamp,
            'timestamp_formatted' => $this->getFormattedTimestamp(),
        ];
    }
}
