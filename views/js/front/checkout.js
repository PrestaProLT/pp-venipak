/**
 * PPVenipak Checkout — Pickup terminal selector & courier extra fields.
 * Vanilla JS, no jQuery dependency.
 */
(function () {
    'use strict';

    /* ------------------------------------------------------------------ */
    /*  Constants                                                          */
    /* ------------------------------------------------------------------ */

    var DEBOUNCE_MS = 500;
    var MAP_DEFAULT_CENTER = [54.9, 23.9]; // Lithuania center
    var MAP_DEFAULT_ZOOM = 7;
    var MAP_NEAREST_ZOOM = 13; // post-geocode zoom — city block / neighbourhood
    var NEAREST_TOP_N = 5;     // when sorted by distance, only render the N closest
    var LEAFLET_CSS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    var LEAFLET_JS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';

    /* ------------------------------------------------------------------ */
    /*  State                                                              */
    /* ------------------------------------------------------------------ */

    var map = null;
    var markers = [];
    var allTerminals = [];
    var sortedByDistance = false; // true once Find-nearest has resorted the array
    // True once a terminal has actually been committed (auto OR manual). Lives at
    // module scope so it survives the picker re-init that `updatedDeliveryForm`
    // triggers, and so a slow in-flight auto-nearest geocode can't clobber a
    // selection the customer made while it was still resolving.
    var terminalCommitted = false;
    var leafletLoading = false;
    var leafletCallbacks = [];

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    function debounce(fn, ms) {
        var timer;
        return function () {
            var ctx = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(ctx, args);
            }, ms);
        };
    }

    function ajax(method, url, data, callback) {
        var xhr = new XMLHttpRequest();
        xhr.open(method, url, true);
        xhr.setRequestHeader('Accept', 'application/json');
        if (method === 'POST') {
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        }
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            var json = null;
            try {
                json = JSON.parse(xhr.responseText);
            } catch (e) {
                json = { error: 'Invalid server response' };
            }
            callback(xhr.status, json);
        };
        xhr.send(data || null);
    }

    function encodeParams(obj) {
        var parts = [];
        for (var key in obj) {
            if (obj.hasOwnProperty(key)) {
                parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(obj[key]));
            }
        }
        return parts.join('&');
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    /* ------------------------------------------------------------------ */
    /*  i18n — translations passed from the server via data-i18n           */
    /* ------------------------------------------------------------------ */

    var I18N = {};

    // Look up a translated string by its English key; fall back to the key so
    // the UI still reads correctly if a locale is missing an entry.
    function tr(key) {
        return (I18N && I18N[key]) || key;
    }

    /* ------------------------------------------------------------------ */
    /*  Leaflet lazy loader                                                */
    /* ------------------------------------------------------------------ */

    function loadLeaflet(callback) {
        if (window.L) {
            callback();
            return;
        }

        leafletCallbacks.push(callback);

        if (leafletLoading) return;
        leafletLoading = true;

        var css = document.createElement('link');
        css.rel = 'stylesheet';
        css.href = LEAFLET_CSS;
        document.head.appendChild(css);

        var js = document.createElement('script');
        js.src = LEAFLET_JS;
        js.onload = function () {
            leafletLoading = false;
            var cbs = leafletCallbacks.slice();
            leafletCallbacks = [];
            cbs.forEach(function (cb) { cb(); });
        };
        js.onerror = function () {
            leafletLoading = false;
            console.error('PPVenipak: Failed to load Leaflet.');
        };
        document.head.appendChild(js);
    }

    /* ------------------------------------------------------------------ */
    /*  Terminal list filtering                                             */
    /* ------------------------------------------------------------------ */

    function filterTerminals(query, terminals) {
        if (!query) return terminals;
        var q = query.toLowerCase().trim();
        return terminals.filter(function (t) {
            return (
                (t.city || '').toLowerCase().indexOf(q) !== -1 ||
                (t.zip || '').toLowerCase().indexOf(q) !== -1 ||
                (t.display_name || '').toLowerCase().indexOf(q) !== -1 ||
                (t.address || '').toLowerCase().indexOf(q) !== -1 ||
                (t.name || '').toLowerCase().indexOf(q) !== -1
            );
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Distance helpers (haversine + Nominatim geocoding)                  */
    /* ------------------------------------------------------------------ */

    function haversineKm(lat1, lng1, lat2, lng2) {
        var R = 6371; // earth radius in km
        var toRad = function (d) { return d * Math.PI / 180; };
        var dLat = toRad(lat2 - lat1);
        var dLng = toRad(lng2 - lng1);
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2)
              + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2))
              * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    /**
     * Geocode a country+postcode via our front AJAX endpoint, which proxies
     * to OpenStreetMap Nominatim server-side. Direct browser calls to
     * Nominatim are blocked (HTTP 403) because browsers can't set the
     * Nominatim-identifying User-Agent that the policy requires.
     *
     * The `ajaxUrl` argument is the same module AJAX URL used for terminal
     * loading — passed in so this helper stays decoupled from globals.
     */
    function geocodePostcode(ajaxUrl, country, postcode, callback) {
        if (!postcode || postcode.length < 3) {
            callback(new Error(tr('Enter a postcode.')), null);
            return;
        }

        var sep = ajaxUrl.indexOf('?') === -1 ? '?' : '&';
        var url = ajaxUrl + sep + 'action=geocode'
                + '&country=' + encodeURIComponent(country)
                + '&postcode=' + encodeURIComponent(postcode);

        fetch(url, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
            .then(function (r) {
                return r.json().then(function (data) { return { ok: r.ok, data: data }; });
            })
            .then(function (resp) {
                var data = resp.data || {};
                if (!resp.ok || !data.success) {
                    callback(new Error(data.error || tr('Could not locate postcode.')), null);
                    return;
                }
                callback(null, { lat: parseFloat(data.lat), lng: parseFloat(data.lng) });
            })
            .catch(function (err) {
                callback(err instanceof Error ? err : new Error(String(err)), null);
            });
    }

    function sortByDistance(terminals, originLat, originLng) {
        terminals.forEach(function (t) {
            var lat = parseFloat(t.lat);
            var lng = parseFloat(t.lng);
            t._distanceKm = (isFinite(lat) && isFinite(lng))
                ? haversineKm(originLat, originLng, lat, lng)
                : null;
        });
        terminals.sort(function (a, b) {
            var aD = (a._distanceKm === null) ? Infinity : a._distanceKm;
            var bD = (b._distanceKm === null) ? Infinity : b._distanceKm;
            return aD - bD;
        });
        return terminals;
    }

    function formatDistance(km) {
        if (typeof km !== 'number' || !isFinite(km)) return '';
        if (km < 1) return Math.round(km * 1000) + ' m';
        if (km < 10) return km.toFixed(1) + ' km';
        return Math.round(km) + ' km';
    }

    /* ------------------------------------------------------------------ */
    /*  Render terminal list                                               */
    /* ------------------------------------------------------------------ */

    function renderTerminalList(container, terminals) {
        var listEl = container.querySelector('.ppvenipak-pickup__list-items');
        if (!listEl) return;

        if (!terminals.length) {
            listEl.innerHTML = '<div class="ppvenipak-pickup__empty">' + escapeHtml(tr('No terminals found.')) + '</div>';
            return;
        }

        var html = '';
        terminals.forEach(function (t) {
            // Venipak's API exposes only two terminal_type values for the
            // current LT/LV/EE/PL dataset: 3 = unattended Locker
            // (paštomatas), anything else = manned Shop / parcel point
            // (Eurokos, IKI, RIMI, Maxima, etc.). This matches the admin
            // terminals page so the labels are consistent.
            var tType = parseInt(t.terminal_type, 10);
            var typeLabel, typeCls;
            if (tType === 3) {
                typeLabel = tr('Locker');
                typeCls = 'ppvenipak-pickup__item-type--locker';
            } else {
                typeLabel = tr('Shop');
                typeCls = 'ppvenipak-pickup__item-type--shop';
            }

            // COD-availability badge — surfaced before the customer commits
            // so they can pick a different terminal if they planned to pay
            // cash on delivery.
            var codBadge = (parseInt(t.cod_enabled, 10) === 1)
                ? '<span class="ppvenipak-pickup__item-cod ppvenipak-pickup__item-cod--yes" title="' + escapeHtml(tr('Cash on Delivery available')) + '">€</span>'
                : '<span class="ppvenipak-pickup__item-cod ppvenipak-pickup__item-cod--no" title="' + escapeHtml(tr('No Cash on Delivery')) + '">€</span>';

            var distText = formatDistance(t._distanceKm);
            var distHtml = distText
                ? '<span class="ppvenipak-pickup__item-distance">' + escapeHtml(distText) + '</span>'
                : '';

            html += '<div class="ppvenipak-pickup__item" data-terminal-id="' + escapeHtml(String(t.terminal_id)) + '">'
                + '<div class="ppvenipak-pickup__item-icon">'
                + '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
                + '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 1 1 18 0z"/>'
                + '<circle cx="12" cy="10" r="3"/></svg>'
                + '</div>'
                + '<div class="ppvenipak-pickup__item-info">'
                + '<span class="ppvenipak-pickup__item-name">' + escapeHtml(t.display_name || t.name) + '</span>'
                + '<span class="ppvenipak-pickup__item-address">' + escapeHtml(t.address) + ', ' + escapeHtml(t.city) + ' ' + escapeHtml(t.zip) + '</span>'
                + '</div>'
                + distHtml
                + codBadge
                + '<span class="ppvenipak-pickup__item-type ' + typeCls + '">' + escapeHtml(typeLabel) + '</span>'
                + '</div>';
        });

        listEl.innerHTML = html;
    }

    /* ------------------------------------------------------------------ */
    /*  Map initialization & markers                                       */
    /* ------------------------------------------------------------------ */

    function initMap(containerId, terminals) {
        var mapEl = document.getElementById(containerId);
        if (!mapEl) return;

        if (map) {
            map.remove();
            map = null;
        }
        markers = [];

        map = L.map(containerId).setView(MAP_DEFAULT_CENTER, MAP_DEFAULT_ZOOM);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 18,
        }).addTo(map);

        addMarkers(terminals);
    }

    function addMarkers(terminals) {
        if (!map) return;

        // Remove old markers
        markers.forEach(function (m) { map.removeLayer(m); });
        markers = [];

        var bounds = [];

        terminals.forEach(function (t) {
            var lat = parseFloat(t.lat);
            var lng = parseFloat(t.lng);
            if (!lat || !lng) return;

            var workingHours = '';
            if (t.working_hours) {
                try {
                    var wh = typeof t.working_hours === 'string' ? JSON.parse(t.working_hours) : t.working_hours;
                    if (typeof wh === 'object' && wh !== null) {
                        var days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                        for (var i = 0; i < days.length; i++) {
                            var dayKey = days[i].toLowerCase();
                            if (wh[dayKey]) {
                                workingHours += '<br>' + escapeHtml(tr(days[i])) + ': ' + escapeHtml(wh[dayKey]);
                            }
                        }
                    }
                } catch (e) { /* ignore parse errors */ }
            }

            var popupContent = '<strong>' + escapeHtml(t.display_name || t.name) + '</strong>'
                + '<br>' + escapeHtml(t.address)
                + '<br>' + escapeHtml(t.city) + ' ' + escapeHtml(t.zip)
                + (workingHours ? '<br><br><em>' + escapeHtml(tr('Working hours:')) + '</em>' + workingHours : '')
                + '<br><br><button type="button" class="ppvenipak-pickup__map-select-btn" data-terminal-id="'
                + escapeHtml(String(t.terminal_id)) + '">' + escapeHtml(tr('Select')) + '</button>';

            var marker = L.marker([lat, lng]).addTo(map);
            marker.bindPopup(popupContent);
            marker._ppTerminalId = t.terminal_id;
            markers.push(marker);
            bounds.push([lat, lng]);
        });

        if (bounds.length > 0) {
            map.fitBounds(bounds, { padding: [30, 30] });
        }
    }

    function highlightMarker(terminalId) {
        markers.forEach(function (m) {
            if (String(m._ppTerminalId) === String(terminalId)) {
                m.openPopup();
                map.setView(m.getLatLng(), 14);
            }
        });
    }

    /**
     * Re-measure the map and re-frame all current markers.
     *
     * Leaflet computes tile AND marker positions against the container size at
     * the moment setView()/fitBounds() runs. Because the map is built inside the
     * theme's initially-collapsed carrier-extra wrapper (0 height on Classic /
     * Hummingbird and any theme that collapses the delivery-option extra slot),
     * those first frames leave the markers projected thousands of pixels outside
     * the viewport — the tiles fill in on pan but the pins never appear. Simply
     * calling invalidateSize() once visible is not enough: it corrects the size
     * but keeps the stale centre/zoom. So re-apply the view here too, framing
     * every marker currently on the map. Call this after anything that reveals
     * the map (carrier selected, "Change" clicked, nearest search finished).
     */
    function refreshMapView() {
        if (!map) return;
        // Skip while the map is hidden (collapsed carrier row or selected-terminal
        // state): measuring a 0×0 container makes fitBounds jump to max zoom.
        var mapEl = document.getElementById('ppvenipak-map');
        if (!mapEl || mapEl.offsetWidth === 0 || mapEl.offsetHeight === 0) return;
        map.invalidateSize();
        if (!markers.length) return;
        var latlngs = markers.map(function (m) { return m.getLatLng(); });
        if (latlngs.length === 1) {
            map.setView(latlngs[0], MAP_NEAREST_ZOOM);
        } else {
            map.fitBounds(latlngs, { padding: [30, 30] });
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Load terminals via AJAX                                            */
    /* ------------------------------------------------------------------ */

    function loadTerminals(ajaxUrl, country, callback) {
        var url = ajaxUrl + (ajaxUrl.indexOf('?') === -1 ? '?' : '&')
            + 'action=getTerminals&country=' + encodeURIComponent(country);

        ajax('GET', url, null, function (status, data) {
            if (status >= 200 && status < 300 && data && data.terminals) {
                callback(null, data.terminals);
            } else {
                callback(data && data.error ? data.error : tr('Failed to load terminals.'));
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Save terminal selection                                            */
    /* ------------------------------------------------------------------ */

    function selectTerminal(terminal, ajaxUrl, container) {
        // Mark synchronously, before the save round-trips. A manual pick made
        // while the initial auto-nearest geocode is still resolving must win:
        // when that geocode finally calls back, its auto-commit is gated on
        // this flag and bows out instead of reverting to the nearest terminal.
        terminalCommitted = true;

        var params = encodeParams({ terminal_id: terminal.terminal_id });
        var url = ajaxUrl + (ajaxUrl.indexOf('?') === -1 ? '?' : '&') + 'action=saveTerminal';

        ajax('POST', url, params, function (status, data) {
            if (status >= 200 && status < 300 && data && data.success) {
                showSelectedState(container, terminal);
            } else {
                showError(container, data && data.error ? data.error : tr('Failed to save selection.'));
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  UI state management                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Everything that makes up the "picker" UI — toolbar (search + nearest),
     * map and list. Toggled as a group so picking a terminal hides them and
     * "Change" brings them all back. Includes the toolbar specifically so
     * the merchant doesn't see the postcode input + stale "Postcode not
     * found" error after they've already chosen a terminal.
     */
    function getSelectorElements(container) {
        return [
            container.querySelector('.ppvenipak-pickup__toolbar'),
            container.querySelector('.js-ppvenipak-map-wrap'),
            container.querySelector('.js-ppvenipak-list-wrap'),
        ];
    }

    function showSelectedState(container, terminal) {
        var selectedEl = container.querySelector('.js-ppvenipak-selected');

        // Keep the hidden submit input in sync with what's shown. The server
        // only fills this on a full re-render; without this, a same-DOM
        // re-init would read a stale/empty value and re-run the auto-nearest.
        var hiddenInput = container.querySelector('.js-ppvenipak-terminal-id');
        if (hiddenInput) hiddenInput.value = terminal.terminal_id;

        if (selectedEl) {
            var nameEl = selectedEl.querySelector('.js-ppvenipak-selected-name');
            var addrEl = selectedEl.querySelector('.js-ppvenipak-selected-address');
            if (nameEl) nameEl.textContent = terminal.display_name || terminal.name || '';
            if (addrEl) {
                var parts = [];
                if (terminal.address) parts.push(terminal.address);
                var cityZip = [terminal.city, terminal.zip].filter(Boolean).join(' ');
                if (cityZip) parts.push(cityZip);
                addrEl.textContent = parts.join(', ');
            }
            selectedEl.style.display = '';
        }

        getSelectorElements(container).forEach(function (el) {
            if (el) el.style.display = 'none';
        });

        // Clear any leftover nearest-search status (e.g. an earlier "not
        // found" error) so it doesn't reappear when the merchant clicks
        // Change later.
        var nearestStatus = container.querySelector('.js-ppvenipak-nearest-status');
        if (nearestStatus) {
            nearestStatus.textContent = '';
            nearestStatus.dataset.kind = '';
        }

        hideError(container);
    }

    function showSelectorState(container) {
        var selectedEl = container.querySelector('.js-ppvenipak-selected');
        if (selectedEl) selectedEl.style.display = 'none';

        getSelectorElements(container).forEach(function (el) {
            if (el) el.style.display = '';
        });

        // Leaflet measures the map at init; if it was hidden when first laid
        // out the tiles render at 0×0 and the markers land off-screen until we
        // remeasure AND re-frame them.
        if (map) {
            setTimeout(refreshMapView, 100);
        }
    }

    function showError(container, message) {
        var errEl = container.querySelector('.ppvenipak-pickup__error');
        if (errEl) {
            errEl.textContent = message;
            errEl.style.display = '';
        }
    }

    function hideError(container) {
        var errEl = container.querySelector('.ppvenipak-pickup__error');
        if (errEl) errEl.style.display = 'none';
    }

    function showLoading(container) {
        var listEl = container.querySelector('.ppvenipak-pickup__list-items');
        if (listEl) {
            listEl.innerHTML = '<div class="ppvenipak-pickup__loading">' + escapeHtml(tr('Loading terminals…')) + '</div>';
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Pickup terminal selector — init                                    */
    /* ------------------------------------------------------------------ */

    function initPickupSelector(container) {
        var ajaxUrl = container.getAttribute('data-ajax-url') || '';
        var country = container.getAttribute('data-country') || 'LT';
        var initialPostcode = (container.getAttribute('data-postcode') || '').trim();

        // Load server-provided translations for the JS-rendered strings.
        try {
            I18N = JSON.parse(container.getAttribute('data-i18n') || '{}');
        } catch (e) {
            I18N = {};
        }

        // The container itself doesn't carry the saved terminal id, but the
        // hidden submit input does — read from there so the auto-nearest
        // path can tell whether the cart already has a selection.
        var hiddenInput = container.querySelector('.js-ppvenipak-terminal-id');
        var preselectedId = (hiddenInput && hiddenInput.value)
            || container.getAttribute('data-selected-terminal')
            || '';

        if (!ajaxUrl) {
            console.error('PPVenipak: data-ajax-url missing on pickup container.');
            return;
        }

        showLoading(container);

        loadTerminals(ajaxUrl, country, function (err, terminals) {
            if (err) {
                showError(container, err);
                return;
            }

            allTerminals = terminals;
            renderTerminalList(container, terminals);

            // If a terminal was preselected, show the selected state
            if (preselectedId) {
                var pre = terminals.find(function (t) {
                    return String(t.terminal_id) === String(preselectedId);
                });
                if (pre) {
                    showSelectedState(container, pre);
                }
            }

            // Lazy-load Leaflet and init map
            loadLeaflet(function () {
                initMap('ppvenipak-map', terminals);

                // Frame the markers once the map exists and its container is
                // visible. refreshMapView no-ops while hidden, so this is safe.
                setTimeout(refreshMapView, 300);

                // Bind popup select button via event delegation on map container
                var mapEl = document.getElementById('ppvenipak-map');
                if (mapEl) {
                    mapEl.addEventListener('click', function (e) {
                        var btn = e.target.closest('.ppvenipak-pickup__map-select-btn');
                        if (!btn) return;
                        var tid = btn.getAttribute('data-terminal-id');
                        var t = findTerminal(tid);
                        if (t) selectTerminal(t, ajaxUrl, container);
                    });
                }
            });
        });

        // Single text-filter input lives directly above the terminal list
        // (the toolbar at the top is dedicated to the postcode "Find nearest"
        // flow). `.js-ppvenipak-search` is kept here only to remain
        // backwards-compatible with the old class name in case a custom
        // theme still references it.
        var searchInputs = [
            container.querySelector('.js-ppvenipak-list-search'),
            container.querySelector('.js-ppvenipak-search'),
        ].filter(Boolean);

        /**
         * Single source of truth for what's rendered to the list + map:
         *  - With a search query: every match across all terminals.
         *  - Sorted by distance + no query: only the top-N closest, so the
         *    merchant doesn't have to scroll past 600+ items to find the
         *    "nearest" one they asked for.
         *  - Otherwise: every terminal.
         */
        function applyVisibleTerminals(query) {
            var visible;
            if (query) {
                visible = filterTerminals(query, allTerminals);
            } else if (sortedByDistance) {
                visible = allTerminals.slice(0, NEAREST_TOP_N);
            } else {
                visible = allTerminals;
            }
            renderTerminalList(container, visible);
            if (map) addMarkers(visible);
        }

        var applyFilter = debounce(function (query) {
            searchInputs.forEach(function (inp) {
                if (inp.value !== query) inp.value = query;
            });
            applyVisibleTerminals(query);
        }, 250);

        searchInputs.forEach(function (inp) {
            inp.addEventListener('input', function () { applyFilter(inp.value); });
        });

        // "Find nearest" — geocode postcode → sort terminals by distance.
        var nearestInput = container.querySelector('.js-ppvenipak-nearest-input');
        var nearestBtn = container.querySelector('.js-ppvenipak-nearest-btn');
        var nearestStatus = container.querySelector('.js-ppvenipak-nearest-status');

        function setNearestStatus(text, kind) {
            if (!nearestStatus) return;
            nearestStatus.textContent = text || '';
            nearestStatus.dataset.kind = kind || '';
        }

        /**
         * Sort terminals by distance from the typed postcode.
         *
         * @param {boolean} autoSelectTop  If true, also commit the closest
         *   terminal as the cart's selection (saveTerminal AJAX). Used on
         *   the auto-init path where the cart already has the customer's
         *   address and we want them to land on the carrier step with a
         *   sensible default already chosen. Manual button-click leaves
         *   selection to the customer (autoSelectTop = false).
         */
        function runNearest(autoSelectTop) {
            var pc = nearestInput ? (nearestInput.value || '').trim() : '';
            if (!pc) {
                setNearestStatus(tr('Enter a postcode.'), 'error');
                return;
            }
            if (!allTerminals.length) {
                setNearestStatus(tr('Terminals are still loading…'), 'info');
                return;
            }

            setNearestStatus(tr('Locating…'), 'info');
            geocodePostcode(ajaxUrl, country, pc, function (err, origin) {
                if (err) {
                    setNearestStatus(err.message || tr('Could not locate postcode.'), 'error');
                    return;
                }

                sortByDistance(allTerminals, origin.lat, origin.lng);
                sortedByDistance = true;

                // Clear any active text filter so the closest results are
                // actually the ones rendered.
                searchInputs.forEach(function (inp) { inp.value = ''; });

                applyVisibleTerminals('');

                var top = allTerminals[0] || null;

                if (map) {
                    if (top && isFinite(parseFloat(top.lat)) && isFinite(parseFloat(top.lng))) {
                        // Surface the nearest terminal's popup so the customer
                        // can review + click "Select" without scanning.
                        highlightMarker(top.terminal_id);
                    } else {
                        map.setView([origin.lat, origin.lng], MAP_NEAREST_ZOOM);
                    }
                    // Remeasure + re-frame the visible (top-N nearest) markers.
                    // Doing this after the highlight guarantees the pins sit in
                    // the viewport even though the map may have been sized while
                    // its wrapper was still collapsed; the nearest popup stays
                    // open on top of the framed cluster.
                    setTimeout(refreshMapView, 50);
                }

                var topName = top && (top.display_name || top.name);
                var topDist = top && formatDistance(top._distanceKm);
                var nearestMsg = tr('Showing %count% closest to %postcode%')
                    .replace('%count%', NEAREST_TOP_N)
                    .replace('%postcode%', pc);
                if (topName) {
                    var topLabel = topName + (topDist ? ' (' + topDist + ')' : '');
                    nearestMsg += ' — ' + tr('top match: %name%').replace('%name%', topLabel);
                }
                setNearestStatus(nearestMsg + '.', 'success');

                // Auto-commit the top match on the auto-init path — only when
                // the cart had no prior terminal selection AND the customer
                // hasn't picked one in the meantime. Geocoding is async and
                // slow; without this guard a selection made during the wait
                // would be silently reverted to the nearest terminal.
                if (autoSelectTop && top && !terminalCommitted) {
                    selectTerminal(top, ajaxUrl, container);
                }
            });
        }

        if (nearestBtn) nearestBtn.addEventListener('click', runNearest);
        if (nearestInput) {
            nearestInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); runNearest(); }
            });
            // Clearing the postcode field returns the list to "all terminals".
            nearestInput.addEventListener('input', function () {
                if (!nearestInput.value.trim() && sortedByDistance) {
                    sortedByDistance = false;
                    applyVisibleTerminals(searchInputs[0] ? searchInputs[0].value : '');
                    setNearestStatus('', '');
                }
            });
        }

        // Auto-trigger after terminals load if the cart already has a
        // postcode AND no terminal has been picked yet — saves the customer
        // a click on the most common path. The `true` argument tells
        // runNearest to also commit the closest terminal as the cart's
        // selection so the customer arrives at the carrier step with a
        // sensible default already chosen. If they want a different one,
        // they click "Change" on the selected panel and pick another.
        if (initialPostcode && initialPostcode.length >= 3 && !preselectedId && !terminalCommitted) {
            // Wait for the terminals AJAX to finish before geocoding.
            var pollStart = Date.now();
            var poll = setInterval(function () {
                if (allTerminals.length) {
                    clearInterval(poll);
                    runNearest(true);
                } else if (Date.now() - pollStart > 8000) {
                    clearInterval(poll);
                }
            }, 200);
        }

        // Terminal list click — event delegation
        var listEl = container.querySelector('.ppvenipak-pickup__list-items');
        if (listEl) {
            listEl.addEventListener('click', function (e) {
                var item = e.target.closest('.ppvenipak-pickup__item');
                if (!item) return;
                var tid = item.getAttribute('data-terminal-id');
                var t = findTerminal(tid);
                if (!t) return;

                // Highlight on map
                if (map) highlightMarker(tid);

                // Select the terminal
                selectTerminal(t, ajaxUrl, container);
            });
        }

        // Change button — show selector again
        var changeBtn = container.querySelector('.ppvenipak-pickup__change-btn');
        if (changeBtn) {
            changeBtn.addEventListener('click', function (e) {
                e.preventDefault();
                showSelectorState(container);
            });
        }
    }

    function findTerminal(terminalId) {
        return allTerminals.find(function (t) {
            return String(t.terminal_id) === String(terminalId);
        }) || null;
    }

    /* ------------------------------------------------------------------ */
    /*  Courier extra fields — init                                        */
    /* ------------------------------------------------------------------ */

    function initCourierFields(container) {
        var ajaxUrl = container.getAttribute('data-ajax-url') || '';

        if (!ajaxUrl) {
            console.error('PPVenipak: data-ajax-url missing on courier container.');
            return;
        }

        var debouncedSave = debounce(function () {
            saveCourierFields(ajaxUrl, container);
        }, DEBOUNCE_MS);

        // Bind to all extra fields
        container.addEventListener('change', function (e) {
            if (e.target.closest('.js-ppvenipak-extra-field')) {
                debouncedSave();
            }
        });

        container.addEventListener('input', function (e) {
            var el = e.target.closest('.js-ppvenipak-extra-field');
            if (el && (el.tagName === 'INPUT' && el.type !== 'checkbox')) {
                debouncedSave();
            }
        });
    }

    function saveCourierFields(ajaxUrl, container) {
        var fields = container.querySelectorAll('.js-ppvenipak-extra-field');
        var data = {};

        fields.forEach(function (field) {
            var name = field.getAttribute('name') || field.getAttribute('data-field-name');
            if (!name) return;

            if (field.type === 'checkbox') {
                data[name] = field.checked ? 1 : 0;
            } else {
                data[name] = field.value;
            }
        });

        var params = encodeParams(data);
        var url = ajaxUrl + (ajaxUrl.indexOf('?') === -1 ? '?' : '&') + 'action=saveExtraFields';

        ajax('POST', url, params, function (status, resp) {
            if (status >= 200 && status < 300 && resp && resp.success) {
                // Saved silently
            } else {
                console.warn('PPVenipak: Failed to save extra fields.', resp);
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Bootstrap — init on DOMContentLoaded & carrier change              */
    /* ------------------------------------------------------------------ */

    function initAll() {
        var pickupContainer = document.querySelector('.ppvenipak-pickup');
        if (pickupContainer) {
            initPickupSelector(pickupContainer);
            ensureExtraContentVisible(pickupContainer);
        }

        var courierContainer = document.querySelector('.ppvenipak-courier');
        if (courierContainer) {
            initCourierFields(courierContainer);
            ensureExtraContentVisible(courierContainer);
        }
    }

    /**
     * The checkout wraps carrier-extra content in a collapsing container with
     * inline `display: none; max-height: 0` and is supposed to flip those
     * styles when the radio is selected, but on Symfony-routed checkout pages
     * the binding sometimes doesn't fire — the map and terminal list end up
     * rendered but invisible. Re-implement the toggle ourselves so the picker
     * always shows when its carrier is chosen.
     *
     * Selectors are resolved defensively so this works on any theme, not just
     * Classic/Hummingbird: the collapsing wrapper is `.js-carrier-extra-content`
     * on Classic-derived themes but some custom themes drop the `js-` prefix,
     * and the delivery option row is `.js-delivery-option` / `.delivery-option`.
     * When neither wrapper class is present we fall back to forcing the picker's
     * own element visible, and when neither option class is present we walk up
     * to the nearest ancestor that actually carries the carrier radio.
     */
    function ensureExtraContentVisible(innerContainer) {
        if (!innerContainer.closest) return;

        // Collapsing wrapper — fall back through class variants, then to the
        // picker element itself so themes using neither class still expand.
        var wrapper = innerContainer.closest('.js-carrier-extra-content')
            || innerContainer.closest('.carrier-extra-content')
            || innerContainer;

        // Delivery option row that carries the carrier radio.
        var option = innerContainer.closest('.js-delivery-option')
            || innerContainer.closest('.delivery-option');
        if (!option) {
            // No known option class — climb to the first ancestor that holds a
            // radio input, which is the delivery row on virtually any theme.
            var el = innerContainer.parentElement;
            while (el && !el.querySelector('input[type="radio"]')) {
                el = el.parentElement;
            }
            option = el;
        }
        if (!option) return;

        var radio = option.querySelector('input[type="radio"]');
        if (!radio) return;

        function sync() {
            if (radio.checked) {
                wrapper.style.display = '';
                wrapper.style.maxHeight = '';
                wrapper.style.overflow = 'visible';
                // Only remeasure the map here (invalidateSize keeps the current
                // pan/zoom). Re-framing the markers is done once when the picker
                // is first revealed — NOT on every row click — otherwise
                // dragging the map would keep snapping it back to the initial
                // view.
                if (map && typeof map.invalidateSize === 'function') {
                    setTimeout(function () { map.invalidateSize(); }, 50);
                }
            }
        }

        sync();
        if (!radio.dataset.ppvBound) {
            radio.dataset.ppvBound = '1';
            radio.addEventListener('change', sync);
        }
        // Listen on the label too — clicks on the row don't always fire a
        // `change` event in time when other handlers stop propagation. Ignore
        // clicks that originate inside the extra content (map drag, list, search
        // inputs) so interacting with the picker never re-triggers the sync.
        if (!option.dataset.ppvBound) {
            option.dataset.ppvBound = '1';
            option.addEventListener('click', function (e) {
                if (innerContainer.contains(e.target)) {
                    return;
                }
                setTimeout(sync, 0);
            });
        }
    }

    function onCarrierChange() {
        // Clean up existing map when carrier changes
        if (map) {
            map.remove();
            map = null;
        }
        markers = [];
        allTerminals = [];

        // Re-initialize after short delay to allow DOM update
        setTimeout(initAll, 100);
    }

    // Init on page load
    document.addEventListener('DOMContentLoaded', initAll);

    // PrestaShop carrier change events — use event delegation since DOM may be replaced
    if (typeof prestashop !== 'undefined') {
        prestashop.on('updatedDeliveryForm', onCarrierChange);
    } else {
        // Fallback: wait for prestashop object to be available
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof prestashop !== 'undefined') {
                prestashop.on('updatedDeliveryForm', onCarrierChange);
            }
        });
    }

})();
