-- ============================================================
-- PetWatch — MariaDB Schema
-- Migration from SQLite to MariaDB for Poseidon hosting
-- ============================================================
-- Creates all tables, foreign keys, and performance indexes.
-- Compatible with MariaDB 10.x and MySQL 8.x.
-- ============================================================

-- Drop tables in reverse dependency order (safe re-run)
DROP TABLE IF EXISTS sightings;
DROP TABLE IF EXISTS locations;
DROP TABLE IF EXISTS pets;
DROP TABLE IF EXISTS users;

-- ── users ────────────────────────────────────────────────────────────────────
CREATE TABLE users (
    id            INT           NOT NULL AUTO_INCREMENT,
    username      VARCHAR(50)   NOT NULL,
    email         VARCHAR(255)  NOT NULL,
    password_hash VARCHAR(255)  NOT NULL,
    role          ENUM('user','admin') NOT NULL DEFAULT 'user',
    PRIMARY KEY (id),
    UNIQUE KEY uq_username (username),
    UNIQUE KEY uq_email    (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── pets ─────────────────────────────────────────────────────────────────────
CREATE TABLE pets (
    id            INT           NOT NULL AUTO_INCREMENT,
    name          VARCHAR(100)  NOT NULL,
    species       VARCHAR(50)   NOT NULL,
    breed         VARCHAR(100)  DEFAULT NULL,
    color         VARCHAR(100)  DEFAULT NULL,
    photo_url     VARCHAR(255)  DEFAULT NULL,
    status        ENUM('lost','found') NOT NULL,
    description   TEXT          DEFAULT NULL,
    date_reported DATE          NOT NULL,
    user_id       INT           DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_pets_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Performance indexes
CREATE INDEX idx_pets_status  ON pets(status);
CREATE INDEX idx_pets_species ON pets(species);
CREATE INDEX idx_pets_user_id ON pets(user_id);
-- Full-text index for efficient free-text search across name, description, breed
CREATE FULLTEXT INDEX idx_pets_fulltext ON pets(name, description, breed);

-- ── locations ─────────────────────────────────────────────────────────────────
CREATE TABLE locations (
    id        INT          NOT NULL AUTO_INCREMENT,
    pet_id    INT          NOT NULL,
    latitude  DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    timestamp DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_locations_pet FOREIGN KEY (pet_id) REFERENCES pets(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_locations_pet_id ON locations(pet_id);

-- ── sightings ─────────────────────────────────────────────────────────────────
CREATE TABLE sightings (
    id        INT           NOT NULL AUTO_INCREMENT,
    pet_id    INT           NOT NULL,
    user_id   INT           DEFAULT NULL,
    comment   TEXT          DEFAULT NULL,
    latitude  DECIMAL(10,7) DEFAULT NULL,
    longitude DECIMAL(10,7) DEFAULT NULL,
    timestamp DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_sightings_pet  FOREIGN KEY (pet_id)  REFERENCES pets(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sightings_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_sightings_pet_id   ON sightings(pet_id);
CREATE INDEX idx_sightings_user_id  ON sightings(user_id);
CREATE INDEX idx_sightings_timestamp ON sightings(timestamp);

