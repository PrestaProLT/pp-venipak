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
    var LEAFLET_CSS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    var LEAFLET_JS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';

    /* ------------------------------------------------------------------ */
    /*  State                                                              */
    /* ------------------------------------------------------------------ */

    var map = null;
    var markers = [];
    var allTerminals = [];
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
    /*  Render terminal list                                               */
    /* ------------------------------------------------------------------ */

    function renderTerminalList(container, terminals) {
        var listEl = container.querySelector('.ppvenipak-pickup__list-items');
        if (!listEl) return;

        if (!terminals.length) {
            listEl.innerHTML = '<div class="ppvenipak-pickup__empty">No terminals found.</div>';
            return;
        }

        var html = '';
        terminals.forEach(function (t) {
            var typeLabel = parseInt(t.terminal_type, 10) === 2 ? 'Locker' : 'Pickup';
            var typeCls = parseInt(t.terminal_type, 10) === 2
                ? 'ppvenipak-pickup__item-type--locker'
                : 'ppvenipak-pickup__item-type--pickup';

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
                                workingHours += '<br>' + days[i] + ': ' + escapeHtml(wh[dayKey]);
                            }
                        }
                    }
                } catch (e) { /* ignore parse errors */ }
            }

            var popupContent = '<strong>' + escapeHtml(t.display_name || t.name) + '</strong>'
                + '<br>' + escapeHtml(t.address)
                + '<br>' + escapeHtml(t.city) + ' ' + escapeHtml(t.zip)
                + (workingHours ? '<br><br><em>Working hours:</em>' + workingHours : '')
                + '<br><br><button type="button" class="ppvenipak-pickup__map-select-btn" data-terminal-id="'
                + escapeHtml(String(t.terminal_id)) + '">Select</button>';

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
                callback(data && data.error ? data.error : 'Failed to load terminals.');
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Save terminal selection                                            */
    /* ------------------------------------------------------------------ */

    function selectTerminal(terminal, ajaxUrl, container) {
        var params = encodeParams({ terminal_id: terminal.terminal_id });
        var url = ajaxUrl + (ajaxUrl.indexOf('?') === -1 ? '?' : '&') + 'action=saveTerminal';

        ajax('POST', url, params, function (status, data) {
            if (status >= 200 && status < 300 && data && data.success) {
                showSelectedState(container, terminal);
            } else {
                showError(container, data && data.error ? data.error : 'Failed to save selection.');
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  UI state management                                                */
    /* ------------------------------------------------------------------ */

    function showSelectedState(container, terminal) {
        var selectedEl = container.querySelector('.ppvenipak-pickup__selected');
        var selectorEl = container.querySelector('.ppvenipak-pickup__selector');

        if (selectedEl) {
            var nameEl = selectedEl.querySelector('.ppvenipak-pickup__selected-name');
            var addrEl = selectedEl.querySelector('.ppvenipak-pickup__selected-address');
            if (nameEl) nameEl.textContent = terminal.display_name || terminal.name || '';
            if (addrEl) addrEl.textContent = (terminal.address || '') + ', ' + (terminal.city || '') + ' ' + (terminal.zip || '');
            selectedEl.style.display = '';
        }

        if (selectorEl) {
            selectorEl.style.display = 'none';
        }

        hideError(container);
    }

    function showSelectorState(container) {
        var selectedEl = container.querySelector('.ppvenipak-pickup__selected');
        var selectorEl = container.querySelector('.ppvenipak-pickup__selector');

        if (selectedEl) selectedEl.style.display = 'none';
        if (selectorEl) selectorEl.style.display = '';

        // Invalidate map size after showing (Leaflet needs this)
        if (map) {
            setTimeout(function () { map.invalidateSize(); }, 100);
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
            listEl.innerHTML = '<div class="ppvenipak-pickup__loading">Loading terminals...</div>';
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Pickup terminal selector — init                                    */
    /* ------------------------------------------------------------------ */

    function initPickupSelector(container) {
        var ajaxUrl = container.getAttribute('data-ajax-url') || '';
        var country = container.getAttribute('data-country') || 'LT';
        var preselectedId = container.getAttribute('data-selected-terminal') || '';

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

        // Search input — client-side filtering
        var searchInput = container.querySelector('.ppvenipak-pickup__search-input');
        if (searchInput) {
            searchInput.addEventListener('input', debounce(function () {
                var filtered = filterTerminals(searchInput.value, allTerminals);
                renderTerminalList(container, filtered);
                if (map) addMarkers(filtered);
            }, 300));
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
        }

        var courierContainer = document.querySelector('.ppvenipak-courier');
        if (courierContainer) {
            initCourierFields(courierContainer);
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
