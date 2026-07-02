/**
 * PPVenipak — Admin Order Panel JS
 * Handles label generation, printing, tracking, manifest and courier call actions.
 */
document.addEventListener('DOMContentLoaded', function () {
    const panel = document.getElementById('ppvenipak-order-panel');
    if (!panel) return;

    const orderId = parseInt(panel.dataset.idOrder, 10);
    const countryCode = (panel.dataset.countryCode || 'LT').toUpperCase();
    let urls;
    try {
        urls = JSON.parse(panel.dataset.urls);
    } catch (e) {
        console.error('PPVenipak: Failed to parse URLs', e);
        return;
    }

    // Bind all action buttons
    panel.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;

        const action = btn.dataset.action;
        switch (action) {
            case 'generate-label':
                generateLabel(btn);
                break;
            case 'print-label':
                printLabel(btn);
                break;
            case 'print-manifest':
                printManifest(btn);
                break;
            case 'close-manifest':
                closeManifest(btn);
                break;
            case 'refresh-tracking':
                refreshTracking(btn);
                break;
            case 'save-shipment':
                saveShipment(btn);
                break;
            case 'regenerate-label':
                regenerateLabel(btn);
                break;
            case 'toggle-terminal-picker':
                toggleTerminalPicker();
                break;
        }
    });

    // --- Pickup-point picker (only present on editable pickup orders) ---
    var terminalSearchTimer = null;
    var lastTerminalResults = {};

    var terminalSearchInput = document.getElementById('ppvenipak-terminal-search');
    if (terminalSearchInput) {
        // Fire deliberately, not on every keystroke: a long debounce + a
        // 2-char minimum keeps this from looking like an automated request
        // burst (which Cloudflare's Bot Fight Mode challenges, breaking the
        // session). Empty input re-shows the initial list (one request).
        terminalSearchInput.addEventListener('input', function () {
            if (terminalSearchTimer) clearTimeout(terminalSearchTimer);
            var q = terminalSearchInput.value.trim();
            if (q.length === 1) return; // wait for a more specific query
            terminalSearchTimer = setTimeout(function () { searchTerminals(q); }, 500);
        });
    }

    var terminalResults = document.getElementById('ppvenipak-terminal-results');
    if (terminalResults) {
        terminalResults.addEventListener('click', function (e) {
            var item = e.target.closest('[data-terminal-id]');
            if (!item) return;
            var t = lastTerminalResults[item.dataset.terminalId];
            if (!t) return;
            selectTerminal(t);
        });
    }

    function toggleTerminalPicker() {
        var picker = document.getElementById('ppvenipak-terminal-picker');
        if (!picker) return;
        var visible = picker.style.display !== 'none';
        picker.style.display = visible ? 'none' : 'block';
        if (!visible && terminalSearchInput) {
            terminalSearchInput.focus();
            searchTerminals(terminalSearchInput.value.trim());
        }
    }

    function searchTerminals(q) {
        if (!terminalResults) return;

        // POST the search term in the body (the URL keeps only the _token) so
        // the city name never appears in the query string — a GET ?q=<city>
        // was tripping Cloudflare's WAF on live and returning 403.
        var body = new URLSearchParams();
        body.set('country', countryCode);
        body.set('q', q || '');

        fetch(urls.order_terminals, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: body.toString(),
        })
            .then(handleResponse)
            .then(function (data) {
                if (!data.success || !data.terminals) {
                    terminalResults.style.display = 'none';
                    return;
                }
                renderTerminalResults(data.terminals);
            })
            .catch(function () { terminalResults.style.display = 'none'; });
    }

    function renderTerminalResults(terminals) {
        lastTerminalResults = {};

        if (!terminals.length) {
            terminalResults.innerHTML = '<div style="padding:8px 10px;color:#888;font-size:12px;">No pickup points found.</div>';
            terminalResults.style.display = 'block';
            return;
        }

        var html = '';
        terminals.forEach(function (t) {
            lastTerminalResults[t.terminal_id] = t;
            var sub = (t.address || '') + (t.city ? ', ' + t.city : '');
            html += '<button type="button" data-terminal-id="' + t.terminal_id + '"'
                + ' style="display:block;width:100%;text-align:left;border:0;border-bottom:1px solid #f0f0f0;background:#fff;padding:6px 10px;cursor:pointer;">'
                + '<strong>' + escapeHtml(t.display_name || t.name) + '</strong>'
                + '<br><small style="color:#888;">' + escapeHtml(sub) + '</small>'
                + '</button>';
        });
        terminalResults.innerHTML = html;
        terminalResults.style.display = 'block';
    }

    function selectTerminal(t) {
        var hidden = document.getElementById('ppvenipak-terminal-id');
        if (hidden) hidden.value = t.terminal_id;

        var current = document.getElementById('ppvenipak-terminal-current');
        if (current) {
            var sub = (t.address || '') + (t.city ? ', ' + t.city : '');
            current.innerHTML = '<strong>' + escapeHtml(t.display_name || t.name) + '</strong>'
                + ' <span class="badge badge-info">new — unsaved</span>'
                + '<br><small class="text-muted">' + escapeHtml(sub) + '</small>';
        }

        if (terminalResults) terminalResults.style.display = 'none';
        if (terminalSearchInput) terminalSearchInput.value = t.display_name || t.name;
    }

    function regenerateLabel(btn) {
        setLoading(btn, true);

        fetch(urls.regenerate_label, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: 'id_order=' + orderId,
        })
            .then(handleResponse)
            .then(function (data) {
                setLoading(btn, false);
                if (data.success) {
                    showNotice('success', data.message || 'Label reset.');
                    setTimeout(function () { window.location.reload(); }, 1200);
                } else {
                    showNotice('error', data.message || 'Failed to reset label.');
                }
            })
            .catch(function (err) {
                setLoading(btn, false);
                showNotice('error', 'Request failed: ' + err.message);
            });
    }

    function saveShipment(btn) {
        var weightInput = document.getElementById('ppvenipak-weight-input');
        var packagesInput = document.getElementById('ppvenipak-packages-input');
        var codInput = document.getElementById('ppvenipak-cod-input');

        var weight = weightInput ? parseFloat(weightInput.value.replace(',', '.')) : NaN;
        var packages = packagesInput ? parseInt(packagesInput.value, 10) : NaN;

        if (weightInput && (!(weight > 0) || weight > 999)) {
            showNotice('error', 'Weight must be between 0.001 and 999 kg.');
            weightInput.focus();
            return;
        }

        if (packagesInput && (!packages || packages < 1 || packages > 99)) {
            showNotice('error', 'Package count must be between 1 and 99.');
            packagesInput.focus();
            return;
        }

        var terminalIdInput = document.getElementById('ppvenipak-terminal-id');

        var params = new URLSearchParams();
        params.set('id_order', orderId);
        if (weightInput) params.set('weight', weight);
        if (packagesInput) params.set('packages', packages);
        if (codInput) params.set('is_cod', codInput.checked ? 1 : 0);
        if (terminalIdInput && terminalIdInput.value) params.set('terminal_id', terminalIdInput.value);

        setLoading(btn, true);

        fetch(urls.update_shipment, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: params.toString(),
        })
            .then(handleResponse)
            .then(function (data) {
                setLoading(btn, false);
                if (data.success) {
                    // A pickup-point change rewrites terminal_info server-side;
                    // reload so the panel re-renders the saved point cleanly.
                    if (data.terminal_changed) {
                        showNotice('success', data.message || 'Pickup point updated.');
                        setTimeout(function () { window.location.reload(); }, 1000);
                        return;
                    }
                    showNotice('success', data.message || 'Shipment details updated.');
                    // Re-render the COD amount display so the merchant sees
                    // the recomputed value without a full page reload.
                    var codDisplay = document.getElementById('ppvenipak-cod-amount');
                    if (codDisplay && data.updated) {
                        if ('cod_amount' in data.updated && (data.updated.is_cod === 1 || data.updated.is_cod === '1')) {
                            codDisplay.innerHTML = '&euro; ' + Number(data.updated.cod_amount).toFixed(2);
                        } else if (data.updated.is_cod === 0 || data.updated.is_cod === '0') {
                            codDisplay.innerHTML = '<small style="color: #888;">disabled</small>';
                        }
                    }
                } else {
                    showNotice('error', data.message || 'Failed to update shipment details.');
                }
            })
            .catch(function (err) {
                setLoading(btn, false);
                showNotice('error', 'Request failed: ' + err.message);
            });
    }

    function generateLabel(btn) {
        setLoading(btn, true);

        fetch(urls.generate_label, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: 'id_order=' + orderId,
        })
            .then(handleResponse)
            .then(function (data) {
                setLoading(btn, false);
                if (data.success) {
                    showNotice('success', data.message || 'Labels generated successfully.');
                    setTimeout(function () { window.location.reload(); }, 1500);
                } else {
                    showNotice('error', data.message || 'Label generation failed.');
                }
            })
            .catch(function (err) {
                setLoading(btn, false);
                showNotice('error', 'Request failed: ' + err.message);
            });
    }

    function printLabel(btn) {
        fetchAndPrint(
            btn,
            buildUrl(urls.print_label, { id_order: orderId }),
            'venipak-label-' + orderId + '.pdf'
        );
    }

    function printManifest(btn) {
        var manifestId = getManifestId();
        if (!manifestId) {
            showNotice('error', 'No manifest ID found.');
            return;
        }

        fetchAndPrint(
            btn,
            buildUrl(urls.print_manifest, { manifest_id: manifestId }),
            'venipak-manifest-' + manifestId + '.pdf'
        );
    }

    /**
     * Build a URL that merges an existing query string (Symfony's
     * router.generate() in admin context already includes _token) with extra
     * parameters, and re-stamps the admin CSRF token from the current page URL
     * if the generated one is missing or stale. PS9 redirects token-less GETs
     * to an "Invalid access key" interstitial — fine in a tab, broken when the
     * response gets piped to a print iframe.
     */
    function buildUrl(base, params) {
        var qIndex = base.indexOf('?');
        var path = qIndex > -1 ? base.substring(0, qIndex) : base;
        var search = qIndex > -1 ? base.substring(qIndex + 1) : '';
        var qs = new URLSearchParams(search);

        Object.keys(params || {}).forEach(function (key) {
            qs.set(key, params[key]);
        });

        if (!qs.get('_token')) {
            var pageToken = new URLSearchParams(window.location.search).get('_token');
            if (pageToken) {
                qs.set('_token', pageToken);
            }
        }

        return path + '?' + qs.toString();
    }

    /**
     * Fetch a PDF and open the browser print dialog without leaving the page.
     * Loads the blob into a hidden iframe, then calls print() on its window.
     * If the response is JSON (error), surface the message instead.
     */
    function fetchAndPrint(btn, url, filename) {
        setLoading(btn, true);

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then(function (response) {
                var contentType = response.headers.get('content-type') || '';
                if (contentType.includes('application/json')) {
                    return response.json().then(function (data) {
                        throw new Error(data.message || 'Failed to load PDF.');
                    });
                }
                if (!response.ok) {
                    throw new Error('Failed to load PDF (HTTP ' + response.status + ').');
                }
                // Anything that isn't a PDF is almost certainly PS's
                // "Invalid access key" interstitial or a redirect to the login
                // page. Don't pipe HTML into the print iframe.
                if (!contentType.includes('application/pdf')) {
                    throw new Error('Session expired or access denied. Reload the page and try again.');
                }
                return response.blob();
            })
            .then(function (blob) {
                var blobUrl = URL.createObjectURL(blob);
                var iframe = document.createElement('iframe');
                iframe.style.position = 'fixed';
                iframe.style.right = '0';
                iframe.style.bottom = '0';
                iframe.style.width = '0';
                iframe.style.height = '0';
                iframe.style.border = '0';
                iframe.src = blobUrl;
                iframe.dataset.filename = filename;
                document.body.appendChild(iframe);

                iframe.addEventListener('load', function () {
                    setLoading(btn, false);
                    try {
                        iframe.contentWindow.focus();
                        iframe.contentWindow.print();
                    } catch (e) {
                        showNotice('error', 'Could not open print dialog: ' + e.message);
                    }
                    // Revoke after a generous delay so the print dialog has read the blob.
                    setTimeout(function () {
                        URL.revokeObjectURL(blobUrl);
                        iframe.remove();
                    }, 60000);
                });
            })
            .catch(function (err) {
                setLoading(btn, false);
                showNotice('error', err.message || 'Print failed.');
            });
    }

    function closeManifest(btn) {
        var manifestId = getManifestId();
        if (!manifestId) {
            showNotice('error', 'No manifest ID found.');
            return;
        }

        setLoading(btn, true);

        fetch(urls.close_manifest, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: 'manifest_id=' + encodeURIComponent(manifestId),
        })
            .then(handleResponse)
            .then(function (data) {
                setLoading(btn, false);
                if (data.success) {
                    showNotice('success', 'Manifest closed.');
                    setTimeout(function () { window.location.reload(); }, 1000);
                } else {
                    showNotice('error', data.message || 'Failed to close manifest.');
                }
            })
            .catch(function (err) {
                setLoading(btn, false);
                showNotice('error', 'Request failed: ' + err.message);
            });
    }

    function refreshTracking(btn) {
        setLoading(btn, true);

        fetch(urls.tracking + '?id_order=' + orderId, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(handleResponse)
            .then(function (data) {
                setLoading(btn, false);
                if (data.success && data.tracking) {
                    renderTrackingDetails(data.tracking);
                    showNotice('success', 'Tracking updated.');
                } else {
                    showNotice('error', data.message || 'No tracking data.');
                }
            })
            .catch(function (err) {
                setLoading(btn, false);
                showNotice('error', 'Request failed: ' + err.message);
            });
    }

    function renderTrackingDetails(tracking) {
        var container = document.getElementById('ppvenipak-tracking-details');
        container.style.display = 'block';

        var html = '<div class="ppvenipak-panel__section"><strong>Tracking History</strong>';

        for (var packNo in tracking) {
            if (!tracking.hasOwnProperty(packNo)) continue;
            var entry = tracking[packNo] || {};
            var events = entry.events || [];
            var state = entry.state || {};

            html += '<div class="ppvenipak-tracking-pack"><small><strong>' + escapeHtml(packNo) + '</strong>';
            if (state.pack_status_text) {
                html += ' — ' + escapeHtml(state.pack_status_text);
                if (state.is_final) {
                    html += ' ✓';
                }
            }
            html += '</small>';

            if (events.length === 0) {
                html += '<div class="ppvenipak-tracking-row">No tracking data yet</div>';
            } else {
                for (var i = 0; i < events.length; i++) {
                    var ev = events[i];
                    var location = (ev.location && (ev.location.place || ev.location.city)) || '';
                    html += '<div class="ppvenipak-tracking-row">'
                        + '<span class="ppvenipak-tracking-date">' + escapeHtml(ev.date || '') + '</span> '
                        + '<span class="ppvenipak-tracking-status">' + escapeHtml(ev.pack_status_text || ev.event || '') + '</span>'
                        + (location ? ' <span class="ppvenipak-tracking-terminal">(' + escapeHtml(location) + ')</span>' : '')
                        + '</div>';
                }
            }
            html += '</div>';
        }

        html += '</div>';
        container.innerHTML = html;
    }

    // Helpers

    function getManifestId() {
        // The new template renders the manifest as an anchor whose href
        // contains `#manifest-<ID>`; pull the ID out of that fragment.
        var link = panel.querySelector('a[href*="#manifest-"]');
        if (!link) return '';
        var match = link.getAttribute('href').match(/#manifest-(.+)$/);
        return match ? match[1] : '';
    }

    function handleResponse(response) {
        if (response.headers.get('content-type')?.includes('application/json')) {
            return response.json();
        }
        throw new Error('Unexpected response format (HTTP ' + response.status + ')');
    }

    function setLoading(btn, loading) {
        if (loading) {
            btn.disabled = true;
            btn.dataset.origText = btn.innerHTML;
            btn.innerHTML = '<i class="material-icons ppvenipak-spin">sync</i> Loading...';
        } else {
            btn.disabled = false;
            btn.innerHTML = btn.dataset.origText || btn.innerHTML;
        }
    }

    function showNotice(type, message) {
        // Remove existing notices
        var existing = panel.querySelectorAll('.ppvenipak-notice');
        existing.forEach(function (el) { el.remove(); });

        var text = String(message || '');
        var notice = document.createElement('div');
        notice.className = 'ppvenipak-notice ppvenipak-notice--' + type;
        notice.style.whiteSpace = 'pre-line';
        notice.textContent = text;

        var body = panel.querySelector('.card-body');
        body.insertBefore(notice, body.firstChild);

        // Multi-line errors need more reading time. ~80 chars or one newline per second, 8s minimum.
        var lineCount = (text.match(/\n/g) || []).length + 1;
        var readingMs = Math.max(8000, Math.min(30000, 4000 + lineCount * 2500 + text.length * 25));
        setTimeout(function () { notice.remove(); }, readingMs);
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
});
