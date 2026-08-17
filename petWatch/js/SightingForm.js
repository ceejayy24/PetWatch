/**
 * @file SightingForm.js — Map Popup Sighting Submission
 * @module SightingForm
 *
 * Part of the JavaScript OO architecture.
 * Instantiated by: PetWatchMap.js (on popup open, for authenticated users)
 * Depends on: AjaxHelper.js
 * AJAX endpoint used: ajax/sightings.php (POST, requires auth + CSRF token)
 */

/**
 * SightingForm
 *
 * Manages the sighting submission mini-form that appears inside a map marker
 * popup for authenticated users. Works like a review form on Amazon or
 * TripAdvisor — the user types a comment and clicks Submit, and the sighting
 * is saved via AJAX without any page reload.
 *
 * Each popup gets its own SightingForm instance, created by PetWatchMap
 * when a popup opens. The form:
 *  1. Renders itself into the <div id="sighting-form-{pet_id}"> placeholder
 *     that PetWatchMap put in the popup HTML.
 *  2. Uses the current map centre as the sighting coordinates (the user has
 *     already navigated the map to the sighting location).
 *  3. Submits via AjaxHelper.post() with the CSRF token automatically attached.
 *  4. On success, updates the popup's "Last sighting" section in-place.
 *
 * Dependencies: AjaxHelper.js (must be loaded before this file)
 *
 * Usage (called by PetWatchMap on popupopen event):
 *   const form = new SightingForm(petId, marker, mapInstance);
 *   form.init();
 */
class SightingForm {
    // Private fields

    /** @type {number} The database ID of the pet being reported on */
    #petId;

    /** @type {L.Marker} The Leaflet marker this form is attached to */
    #marker;

    /** @type {L.Map} The Leaflet map instance (used to read current centre coords) */
    #map;

    /** @type {HTMLElement|null} The container div inside the popup */
    #container;

    /** @type {boolean} Prevents double-submission while a request is in flight */
    #isSubmitting = false;

    /**
     * @param {number}   petId   Database ID of the pet
     * @param {L.Marker} marker  The Leaflet marker this popup belongs to
     * @param {L.Map}    map     The Leaflet map (for reading coordinates)
     */
    constructor(petId, marker, map) {
        this.#petId = petId;
        this.#marker = marker;
        this.#map = map;
    }

    /**
     * Initialise the form — find the container div and render the form HTML.
     *
     * Called by PetWatchMap after a popup opens and the DOM is ready.
     *
     * @returns {void}
     */
    init() {
        this.#container = document.getElementById(`sighting-form-${this.#petId}`);

        if (!this.#container) {
            // Container not found — popup may not be open yet, or pet has no form slot
            return;
        }

        this.#render();
        this.#attachEvents();
    }

    // Private: Rendering

    /**
     * Render the sighting submission form HTML into the container div.
     *
     * The form is intentionally minimal — just a textarea and a submit button —
     * so it fits comfortably inside a Leaflet popup.
     *
     * @returns {void}
     */
    #render() {
        this.#container.innerHTML = `
            <div class="sighting-form-wrap" style="margin-top:8px;">
                <hr style="margin:6px 0 8px;">
                <strong style="font-size:0.85rem;">📍 Report a Sighting</strong>
                <p style="font-size:0.78rem;color:#666;margin:2px 0 6px;">
                    Your current GPS location will be saved as the sighting location.
                </p>
                <textarea
                    id="sighting-comment-${this.#petId}"
                    rows="3"
                    maxlength="500"
                    placeholder="Describe where you saw this pet… (min. 10 characters)"
                    style="width:100%;font-size:0.82rem;border:1px solid #ced4da;
                           border-radius:4px;padding:6px;resize:vertical;box-sizing:border-box;"></textarea>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;">
                    <span id="sighting-char-${this.#petId}"
                          style="font-size:0.72rem;color:#888;">0 / 500</span>
                    <button
                        id="sighting-submit-${this.#petId}"
                        style="background:#0d6efd;color:white;border:none;border-radius:4px;
                               padding:5px 14px;font-size:0.82rem;cursor:pointer;">
                        Submit
                    </button>
                </div>
                <div id="sighting-msg-${this.#petId}"
                     style="font-size:0.8rem;margin-top:6px;display:none;"></div>
            </div>`;
    }

    /**
     * Attach event listeners to the form's textarea and submit button.
     *
     * @returns {void}
     */
    #attachEvents() {
        const textarea = document.getElementById(`sighting-comment-${this.#petId}`);
        const submitBtn = document.getElementById(`sighting-submit-${this.#petId}`);
        const charCount = document.getElementById(`sighting-char-${this.#petId}`);

        if (!textarea || !submitBtn) return;

        // Live character counter
        textarea.addEventListener("input", () => {
            const len = textarea.value.length;
            charCount.textContent = `${len} / 500`;
            charCount.style.color = len > 450 ? "#dc3545" : "#888";
        });

        // Submit button click → validate and POST
        submitBtn.addEventListener("click", (e) => {
            e.preventDefault();
            this.#handleSubmit(textarea.value.trim());
        });
    }

    // Private: Submission

    /**
     * Validate the comment and submit the sighting via AJAX.
     *
     * Uses the map's current centre point as the sighting coordinates.
     * This means the user first navigates the map to where they saw the pet,
     * then types their comment — the centre of the map IS the location.
     *
     * @param {string} comment  The trimmed comment text from the textarea
     * @returns {Promise<void>}
     */
    async #handleSubmit(comment) {
        // Prevent double-submission
        if (this.#isSubmitting) return;

        // Client-side validation
        if (comment.length < 10) {
            this.#showMessage("Please enter at least 10 characters.", "error");
            return;
        }
        if (comment.length > 500) {
            this.#showMessage("Comment must not exceed 500 characters.", "error");
            return;
        }

        // Confirm dialog before submitting
        if (
            !confirm(
                "Are you sure you want to submit this sighting report? Once submitted, it will be publicly visible.",
            )
        ) {
            return;
        }

        // Get the user's real GPS location to save as the sighting coordinates.
        // If geolocation is unavailable or denied, redirect to the manual form.
        let coords;
        try {
            coords = await this.#getUserLocation();
        } catch {
            window.location.href = "createsighting.php?geoloc_error=1";
            return;
        }

        this.#setLoading(true);

        try {
            const response = await AjaxHelper.post("ajax/sightings.php", {
                pet_id: this.#petId,
                comment: comment,
                lat: coords.lat,
                lng: coords.lng,
            });

            this.#showMessage("✅ " + response.message, "success");
            this.#clearForm();

            if (response.data && response.data.sighting) {
                this.#updateLastSighting(response.data.sighting);
            }
        } catch (err) {
            this.#showMessage("⚠️ " + err.message, "error");
        } finally {
            this.#setLoading(false);
        }
    }

    /**
     * Wrap navigator.geolocation.getCurrentPosition in a Promise.
     * Rejects if geolocation is unsupported or the user denies permission.
     *
     * @returns {Promise<{lat: number, lng: number}>}
     */
    #getUserLocation() {
        return new Promise((resolve, reject) => {
            if (!navigator.geolocation) {
                reject(new Error("Geolocation not supported"));
                return;
            }
            navigator.geolocation.getCurrentPosition(
                (pos) =>
                    resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
                () => reject(new Error("Geolocation unavailable")),
                { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 },
            );
        });
    }

    // Private: UI Helpers

    /**
     * Show a success or error message below the form.
     *
     * @param {string} text   Message text
     * @param {'success'|'error'} type  Controls the text colour
     * @returns {void}
     */
    #showMessage(text, type) {
        const msg = document.getElementById(`sighting-msg-${this.#petId}`);
        if (!msg) return;
        msg.textContent = text;
        msg.style.color = type === "success" ? "#198754" : "#dc3545";
        msg.style.display = "block";
    }

    /**
     * Toggle the submit button's disabled state and label during a request.
     *
     * @param {boolean} loading  True while request is in flight
     * @returns {void}
     */
    #setLoading(loading) {
        this.#isSubmitting = loading;
        const btn = document.getElementById(`sighting-submit-${this.#petId}`);
        if (!btn) return;
        btn.disabled = loading;
        btn.textContent = loading ? "Submitting…" : "Submit";
        btn.style.opacity = loading ? "0.6" : "1";
    }

    /**
     * Clear the textarea and character counter after a successful submission.
     *
     * @returns {void}
     */
    #clearForm() {
        const textarea = document.getElementById(`sighting-comment-${this.#petId}`);
        const charCount = document.getElementById(`sighting-char-${this.#petId}`);
        if (textarea) {
            textarea.value = "";
        }
        if (charCount) {
            charCount.textContent = "0 / 500";
        }
    }

    /**
     * Update the "Last sighting" paragraph in the popup with the new sighting.
     *
     * Finds the paragraph by its data attribute and replaces its content so
     * the freshly submitted sighting is visible immediately without reopening
     * the popup.
     *
     * @param {Object} sighting  The new sighting's toArray() fields from the server
     * @returns {void}
     */
    #updateLastSighting(sighting) {
        const popupEl = this.#marker.getPopup().getElement();
        if (!popupEl) return;

        // Look for the last-sighting paragraph — identify it by a data attribute
        // we set in PetWatchMap#buildPopupContent
        const lastSightingEl = popupEl.querySelector(".last-sighting-text");
        if (lastSightingEl) {
            lastSightingEl.innerHTML = `
                <strong>Last sighting:</strong> ${sighting.comment}
                <br><small class="text-muted">${sighting.timestamp ?? ""} — ${sighting.reporter_username ?? "You"}</small>`;
        }
    }
}
