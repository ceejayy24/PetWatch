/**
 * @file PetWatchMap.js — Core Mapping Deliverable
 * @module PetWatchMap
 *
 * Part of the JavaScript OO architecture.
 * Instantiated by: Views/map.phtml
 * Depends on: AjaxHelper.js, SightingForm.js, Leaflet 1.9.4, Leaflet.markercluster 1.5.3
 * AJAX endpoints used: ajax/pets.php (GET)
 */

/**
 * PetWatchMap
 *
 * Manages the interactive Leaflet map
 *
 * Responsibilities:
 *  - Initialises the Leaflet map and centres it on the user's geolocation
 *  - Loads pet + sighting data via AJAX from ajax/pets.php (paginated)
 *  - Places clustered markers on the map (Leaflet.markercluster)
 *  - Renders a scrollable sidebar list of pets
 *  - Handles list ↔ map interaction: clicking a sidebar item pans/zooms
 *    to that pet's marker and opens its popup
 *  - Delegates sighting form handling to SightingForm instances
 *
 * Design pattern: this class is the "controller" for the map page —
 * it orchestrates AjaxHelper (data fetching), Leaflet (rendering), and
 * SightingForm (user interaction) without knowing their internal details.
 *
 * Dependencies (loaded via CDN in map.phtml before this script):
 *  - Leaflet 1.9.4           (window.L)
 *  - Leaflet.markercluster   (L.markerClusterGroup)
 *  - AjaxHelper.js
 *  - SightingForm.js
 *
 * Usage (in map.phtml):
 *   const mapInstance = new PetWatchMap('map-container', {
 *       isLoggedIn: <?= $view->user->isLoggedIn() ? 'true' : 'false' ?>,
 *       userId:     <?= (int)$view->user->userID() ?>,
 *   });
 *   mapInstance.init();
 */
class PetWatchMap {
    // Private fields

    /** @type {L.Map} The Leaflet map instance */
    #map;

    /** @type {L.MarkerClusterGroup} Cluster group containing all pet markers */
    #clusterGroup;

    /** @type {Map<number, L.Marker>} pet_id → Leaflet Marker lookup for list clicks */
    #markerIndex = new Map();

    /** @type {string} The HTML element ID that Leaflet will render the map into */
    #containerId;

    /** @type {Object} Configuration passed in from the PHP view */
    #options;

    /** @type {boolean} Whether all pages of pets have been loaded */
    #allLoaded = false;

    /** @type {number|null} Pet ID to focus after all markers finish loading (from URL param) */
    #pendingFocusPetId = null;

    /** @type {number} Current page being fetched */
    #currentPage = 1;

    /** @type {number} Records per page for AJAX requests */
    #pageSize = 50;

    // Manchester city centre — fallback if geolocation is denied
    static #FALLBACK_LAT = 53.4808;
    static #FALLBACK_LNG = -2.2426;
    static #FALLBACK_ZOOM = 13;

    /**
     * @param {string} containerId  ID of the <div> Leaflet should render into
     * @param {Object} options      Configuration from the PHP view
     * @param {boolean} options.isLoggedIn  Whether the current user is logged in
     * @param {number}  options.userId      Current user's database ID (0 if guest)
     * @param {string}  [options.status]    Pet status filter: 'lost'|'found'|''
     */
    constructor(containerId, options = {}) {
        this.#containerId = containerId;
        this.#options = {
            isLoggedIn: false,
            userId: 0,
            status: "lost",
            ...options,
        };

        // Check if URL contains ?focus=PET_ID (set when clicking a pet on the sightings page)
        const urlParams = new URLSearchParams(window.location.search);
        const focusId = urlParams.get("focus");
        if (focusId) this.#pendingFocusPetId = parseInt(focusId, 10);
    }

    /**
     * Initialise the map — call this once after the DOM has loaded.
     *
     * Sets up geolocation, the Leaflet map, the marker cluster group,
     * and kicks off the first page of AJAX pet loading.
     *
     * @returns {void}
     */
    init() {
        this.#initMap();
        this.#setupGeolocation();
        this.#setupSidebarSearch();
        this.#loadPets();
    }

    // Private: Map Setup

    /**
     * Set up the sidebar search input to filter pet list items client-side.
     * Filters by pet name and species as the user types.
     */
    #setupSidebarSearch() {
        const input = document.getElementById("sidebar-search");
        if (!input) return;

        input.addEventListener("input", () => {
            const term = input.value.toLowerCase().trim();
            document.querySelectorAll(".pet-list-item").forEach((item) => {
                const name = (item.dataset.petName || "").toLowerCase();
                const species = (item.dataset.petSpecies || "").toLowerCase();
                item.style.display =
                    !term || name.includes(term) || species.includes(term) ? "" : "none";
            });
        });
    }

    /**
     * Create the Leaflet map, add the OpenStreetMap tile layer, and
     * initialise the marker cluster group.
     *
     * @returns {void}
     */
    #initMap() {
        // Create Leaflet map centred on Manchester as default (geolocation may move it)
        this.#map = L.map(this.#containerId, {
            center: [PetWatchMap.#FALLBACK_LAT, PetWatchMap.#FALLBACK_LNG],
            zoom: PetWatchMap.#FALLBACK_ZOOM,
        });

        // OpenStreetMap tile layer — free, no API key required
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            attribution:
                '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            maxZoom: 19,
        }).addTo(this.#map);

        // Marker cluster group — prevents the map being overwhelmed with 100s of pins.
        // Nearby markers are grouped into numbered cluster bubbles that expand on zoom.
        this.#clusterGroup = L.markerClusterGroup({
            chunkedLoading: true, // Adds markers in batches to avoid UI blocking
            maxClusterRadius: 60, // Pixels — clusters form within 60px of each other
            spiderfyOnMaxZoom: true, // At max zoom, clusters explode into individual pins
            showCoverageOnHover: false,
        });

        this.#map.addLayer(this.#clusterGroup);
    }

    /**
     * Request the user's geolocation and centre the map on their position.
     *
     * Falls back silently to the Manchester default if permission is denied
     * or the device has no GPS capability.
     *
     * @returns {void}
     */
    #setupGeolocation() {
        if (!navigator.geolocation) {
            // Browser doesn't support geolocation — stay at fallback position
            return;
        }

        navigator.geolocation.getCurrentPosition(
            // Success: pan and zoom to the user's actual location
            (position) => {
                const { latitude, longitude } = position.coords;
                this.#map.setView([latitude, longitude], PetWatchMap.#FALLBACK_ZOOM);

                // Add a subtle "you are here" marker so the user knows where they are
                L.circleMarker([latitude, longitude], {
                    radius: 10,
                    fillColor: "#4285F4", // Google-blue — universally understood as "me"
                    color: "#ffffff",
                    weight: 2,
                    fillOpacity: 0.85,
                })
                    .bindPopup("<strong>📍 Your location</strong>")
                    .addTo(this.#map);
            },
            // Failure: log quietly, map stays at Manchester fallback
            (error) => {
                console.info("PetWatchMap: geolocation unavailable —", error.message);
            },
            {
                enableHighAccuracy: false, // Battery-friendly
                timeout: 8000, // Give up after 8 seconds
                maximumAge: 60000, // Accept a cached position up to 1 minute old
            },
        );
    }

    // Private: Data Loading

    /**
     * Load one page of pets from ajax/pets.php via AjaxHelper.
     *
     * Called recursively (via #onPetsLoaded) until all pages are fetched.
     * Each batch is added to the cluster group as it arrives, so the map
     * is usable immediately and markers appear progressively.
     *
     * @returns {Promise<void>}
     */
    async #loadPets() {
        // Show loading indicator in the sidebar
        this.#setSidebarStatus("⏳ Loading pets…");

        try {
            const response = await AjaxHelper.get("ajax/pets.php", {
                status: this.#options.status,
                page: this.#currentPage,
                limit: this.#pageSize,
            });

            this.#onPetsLoaded(response.data);
        } catch (err) {
            console.error("PetWatchMap: failed to load pets —", err.message);
            this.#setSidebarStatus(
                "⚠️ Failed to load pets. Please refresh the page.",
            );
        }
    }

    /**
     * Process a page of pet data returned by the AJAX endpoint.
     *
     * Adds markers and sidebar items for each pet, then fetches the
     * next page if one exists.
     *
     * @param {Object} data  The response.data object from ajax/pets.php
     * @returns {void}
     */
    #onPetsLoaded(data) {
        const { pets, total, page, pages } = data;

        if (!pets || pets.length === 0) {
            this.#setSidebarStatus(
                total === 0 ? "🐾 No pets found for the selected filter." : "",
            );
            return;
        }

        // Add each pet to the map and sidebar
        pets.forEach((pet) => this.#addPetToMap(pet));

        // Update sidebar status line
        const loadedSoFar = Math.min(page * this.#pageSize, total);
        this.#setSidebarStatus(`Showing ${loadedSoFar} of ${total} pets`);

        // If there are more pages, fetch the next one automatically
        if (page < pages) {
            this.#currentPage++;
            this.#loadPets();
        } else {
            this.#allLoaded = true;
            this.#setSidebarStatus(`✅ All ${total} pets loaded`);

            // Focus a specific pet if one was requested via URL param
            if (this.#pendingFocusPetId) {
                setTimeout(() => {
                    this.focusPet(this.#pendingFocusPetId);
                    this.#pendingFocusPetId = null;
                }, 500); // wait for cluster group to render
            }
        }
    }

    // Private: Marker & Sidebar Rendering

    /**
     * Add a single pet to the map as a Leaflet marker and to the sidebar list.
     *
     * Coordinate priority:
     *   1. last_sighting_lat/lng — most recent sighting location (most accurate)
     *   2. location_lat/lng      — stored last known position (fallback)
     *
     * Pets with no coordinates at all are added to the sidebar but not the map.
     *
     * @param {Object} pet  Plain object from ajax/pets.php response
     * @returns {void}
     */
    #addPetToMap(pet) {
        // Determine the best available coordinates
        const lat = pet.last_sighting_lat ?? pet.location_lat;
        const lng = pet.last_sighting_lng ?? pet.location_lng;

        let marker = null;

        if (lat !== null && lng !== null) {
            // Choose marker colour by status: red for lost, green for found
            const markerColor = pet.pet_status === "lost" ? "#dc3545" : "#198754";
            const icon = L.divIcon({
                className: "", // Override Leaflet's default white box
                html: `<div style="
                    background:${markerColor};
                    width:28px; height:28px;
                    border-radius:50% 50% 50% 0;
                    transform:rotate(-45deg);
                    border:2px solid white;
                    box-shadow:0 2px 4px rgba(0,0,0,0.4);
                "></div>`,
                iconSize: [28, 28],
                iconAnchor: [14, 28], // Point of the pin is at the bottom
                popupAnchor: [0, -30], // Popup appears above the pin
            });

            marker = L.marker([lat, lng], { icon });

            // Build and bind the popup — includes pet details and (for logged-in
            // users) a sighting submission form via SightingForm
            marker.bindPopup(() => this.#buildPopupContent(pet), {
                maxWidth: 320,
                minWidth: 260,
            });

            // When popup opens, initialise the SightingForm for logged-in users on lost pets only
            marker.on("popupopen", () => {
                if (this.#options.isLoggedIn && pet.pet_status === "lost") {
                    const form = new SightingForm(pet.pet_id, marker, this.#map);
                    form.init();
                }
            });

            this.#clusterGroup.addLayer(marker);

            // Store marker reference so the sidebar list can focus it
            this.#markerIndex.set(pet.pet_id, marker);
        }

        // Add to sidebar list regardless of whether a marker was placed
        this.#addSidebarItem(pet, marker);
    }

    /**
     * Build the HTML string for a marker popup.
     *
     * Values are already HTML-encoded by the PHP endpoint's toArray() /
     * output sanitisation, so textContent would also be safe —
     * but we use the pre-encoded strings here for simplicity in innerHTML.
     *
     * @param {Object} pet  Pet data object from ajax/pets.php
     * @returns {string}    HTML string for the Leaflet popup
     */
    #buildPopupContent(pet) {
        const statusBadge =
            pet.pet_status === "lost"
                ? '<span class="badge bg-danger">Lost</span>'
                : '<span class="badge bg-success">Found</span>';

        const photoHtml = pet.pet_photo_path
            ? `<img src="${pet.pet_photo_path}"
                    alt="${pet.pet_name}"
                    style="width:100%;height:140px;object-fit:cover;border-radius:6px;margin-bottom:8px;">`
            : `<div style="width:100%;height:80px;background:#e9ecef;border-radius:6px;
                           display:flex;align-items:center;justify-content:center;
                           font-size:2rem;margin-bottom:8px;">🐾</div>`;

        const lastSighting = pet.last_sighting_comment
            ? `<p style="font-size:0.82rem;color:#555;margin:6px 0 0;">
                 <strong>Last sighting:</strong> ${pet.last_sighting_comment}
                 <br><small class="text-muted">${pet.last_sighting_time ?? ""} — ${pet.last_reporter ?? ""}</small>
               </p>`
            : '<p style="font-size:0.82rem;color:#888;margin:6px 0 0;"><em>No sightings recorded yet.</em></p>';

        // Sighting form placeholder — only for logged-in users reporting lost pets
        const sightingFormHtml =
            this.#options.isLoggedIn && pet.pet_status === "lost"
                ? `<div id="sighting-form-${pet.pet_id}" class="mt-2"></div>`
                : this.#options.isLoggedIn
                    ? "" // found pet — no sighting form
                    : `<p class="mt-2" style="font-size:0.82rem;">
                     <a href="authenticate.php">Log in</a> to report a sighting.
                   </p>`;

        return `
            <div style="font-family:inherit;">
                ${photoHtml}
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                    <strong style="font-size:1rem;">${pet.pet_name}</strong>
                    ${statusBadge}
                </div>
                <p style="font-size:0.85rem;margin:0;color:#444;">
                    ${pet.pet_species}${pet.pet_breed ? " — " + pet.pet_breed : ""}
                    ${pet.pet_color ? " · " + pet.pet_color : ""}
                </p>
                ${
            pet.pet_description
                ? `<p style="font-size:0.82rem;color:#555;margin:4px 0 0;">${pet.pet_description}</p>`
                : ""
        }
                <hr style="margin:8px 0;">
                ${lastSighting}
                <p style="font-size:0.78rem;color:#888;margin:4px 0 0;">
                    Owner: ${pet.owner_username}
                </p>
                ${sightingFormHtml}
            </div>`;
    }

    /**
     * Add a pet entry to the scrollable sidebar list.
     *
     * Clicking a sidebar item pans the map to that pet's marker and opens
     * its popup — the "list to map focus" feature required by the brief.
     *
     * @param {Object}       pet     Pet data object
     * @param {L.Marker|null} marker The Leaflet marker (null if no coords)
     * @returns {void}
     */
    #addSidebarItem(pet, marker) {
        const list = document.getElementById("pet-list");
        if (!list) return;

        const item = document.createElement("div");
        item.className = "pet-list-item";
        item.dataset.petId = pet.pet_id;

        // Status badge colour
        const badgeColor = pet.pet_status === "lost" ? "#dc3545" : "#198754";

        // Store name/species as data attrs for the sidebar search filter
        item.dataset.petName = pet.pet_name;
        item.dataset.petSpecies = pet.pet_species;

        item.innerHTML = `
            <div class="pet-list-inner">
                ${
            pet.pet_photo_path
                ? `<img src="${pet.pet_photo_path}" alt="${pet.pet_name}" class="pet-list-thumb">`
                : `<div class="pet-list-thumb-placeholder">🐾</div>`
        }
                <div class="pet-list-info">
                    <div class="pet-item-name">${pet.pet_name}</div>
                    <div class="pet-item-meta">${pet.pet_species}${pet.pet_breed ? " · " + pet.pet_breed : ""}</div>
                    <span class="pet-item-badge" style="background:${badgeColor};">
                        ${pet.pet_status.charAt(0).toUpperCase() + pet.pet_status.slice(1)}
                    </span>
                </div>
            </div>`;

        // List → Map focus
        // Clicking the sidebar item pans to the marker and opens its popup.
        // "moving the map to location when a record is selected from the list".
        item.addEventListener("click", () => this.focusPet(pet.pet_id));

        list.appendChild(item);
    }

    // Public API

    /**
     * Reload the map with a different status filter.
     *
     * Called by the sidebar filter buttons (Lost / Found / All).
     * Clears all existing markers and sidebar items, resets pagination,
     * then re-fetches from the AJAX endpoint with the new status.
     *
     * @param {string}      status     'lost' | 'found' | '' for all
     * @param {HTMLElement} activeBtn  The button that was clicked (for highlight)
     * @returns {void}
     */
    setStatusFilter(status, activeBtn) {
        this.#options.status = status;

        // Update button highlight styles
        if (activeBtn) {
            document
                .querySelectorAll("#map-sidebar .btn-group .btn")
                .forEach((btn) => {
                    btn.classList.remove(
                        "active",
                        "btn-danger",
                        "btn-success",
                        "btn-secondary",
                    );
                    btn.classList.add("btn-outline-light");
                });
            activeBtn.classList.remove("btn-outline-light");
            activeBtn.classList.add("active");
            if (status === "lost") {
                activeBtn.classList.add("btn-danger");
            } else if (status === "found") {
                activeBtn.classList.add("btn-success");
            } else {
                activeBtn.classList.add("btn-secondary");
            }
        }

        // Clear existing markers/sidebar and re-load with new filter
        this.#clearMap();
        this.#currentPage = 1;
        this.#allLoaded = false;
        this.#loadPets();
    }

    /**
     * Remove all markers from the cluster group and clear the sidebar list.
     *
     * Called before reloading with a new status filter.
     *
     * @returns {void}
     */
    #clearMap() {
        this.#clusterGroup.clearLayers();
        this.#markerIndex.clear();
        const list = document.getElementById("pet-list");
        if (list) list.innerHTML = "";
    }

    /**
     * Pan the map to a specific pet's marker and open its popup.
     *
     * Called by sidebar list item click handlers and can also be called
     * externally (e.g. from a search result link).
     *
     * @param {number} petId  The pet's database ID
     * @returns {void}
     */
    focusPet(petId) {
        const marker = this.#markerIndex.get(petId);
        if (!marker) {
            console.info(`PetWatchMap.focusPet: no marker found for pet_id=${petId}`);
            return;
        }

        // If the marker is inside a cluster, zoom in until it becomes visible
        this.#clusterGroup.zoomToShowLayer(marker, () => {
            // flyTo gives a smooth animated pan + zoom
            this.#map.flyTo(marker.getLatLng(), 16, { duration: 0.8 });
            marker.openPopup();
        });

        // Highlight the active sidebar item
        document.querySelectorAll(".pet-list-item").forEach((el) => {
            el.style.background = "";
            el.classList.remove("active-item");
        });
        const activeItem = document.querySelector(`[data-pet-id="${petId}"]`);
        if (activeItem) {
            activeItem.classList.add("active-item"); // triggers CSS colour rules
            activeItem.scrollIntoView({ behavior: "smooth", block: "nearest" });
        }
    }

    // Private: Sidebar Status

    /**
     * Update the status message shown above the sidebar pet list.
     *
     * @param {string} message  Plain text status (e.g. "Loading..." or "47 of 156")
     * @returns {void}
     */
    #setSidebarStatus(message) {
        const el = document.getElementById("pet-list-status");
        if (el) el.textContent = message;
    }
}
