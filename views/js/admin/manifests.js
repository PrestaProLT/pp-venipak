/**
 * Manifests admin page — Close Manifest + Call Courier handlers.
 * Mirrors the order panel's implementation but operates on a per-row table
 * with a shared courier-call modal so the page stays uncluttered when many
 * manifests are open.
 */
(function () {
    'use strict';

    var config = window.ppvManifestsConfig || { urls: {} };
    var modal = document.getElementById('ppv-courier-modal');
    var manifestLabel = document.getElementById('ppv-courier-manifest');
    var dateInput = document.getElementById('ppv-courier-date');
    var fromSelect = document.getElementById('ppv-courier-from');
    var toSelect = document.getElementById('ppv-courier-to');
    var commentInput = document.getElementById('ppv-courier-comment');
    var noticeContainer = document.getElementById('ppv-manifests-notice');
    var activeManifestId = '';

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-action]');
        if (!btn) return;

        var action = btn.dataset.action;
        switch (action) {
            case 'close-manifest':
                closeManifest(btn);
                break;
            case 'call-courier':
                openCourierModal(btn.dataset.manifestId || '');
                break;
            case 'close-courier-modal':
                hideCourierModal();
                break;
            case 'submit-courier':
                submitCourier(btn);
                break;
        }
    });

    function closeManifest(btn) {
        var manifestId = btn.dataset.manifestId || '';
        if (!manifestId) return;

        setLoading(btn, true);

        postForm(config.urls.close, { manifest_id: manifestId })
            .then(function (data) {
                setLoading(btn, false);
                if (data.success) {
                    notice('success', 'Manifest closed.');
                    setTimeout(function () { window.location.reload(); }, 800);
                } else {
                    notice('error', data.message || 'Failed to close manifest.');
                }
            })
            .catch(function (err) {
                setLoading(btn, false);
                notice('error', 'Request failed: ' + err.message);
            });
    }

    function openCourierModal(manifestId) {
        if (!manifestId || !modal) return;
        activeManifestId = manifestId;
        if (manifestLabel) manifestLabel.textContent = manifestId;
        if (commentInput) commentInput.value = '';
        // Default the date: today before 15:00, tomorrow after — saves a
        // click on the most common path when the cutoff has passed.
        if (dateInput) {
            var d = new Date();
            if (d.getHours() >= 15) {
                d.setDate(d.getDate() + 1);
            }
            dateInput.value = d.toISOString().slice(0, 10);
        }
        modal.style.display = 'flex';
        document.body.classList.add('ppv-modal-open');
    }

    function hideCourierModal() {
        if (!modal) return;
        modal.style.display = 'none';
        document.body.classList.remove('ppv-modal-open');
        activeManifestId = '';
    }

    function submitCourier(btn) {
        if (!activeManifestId) return;

        var pickupDate = dateInput ? dateInput.value : '';
        var hourFrom = fromSelect ? fromSelect.value : '';
        var hourTo = toSelect ? toSelect.value : '';
        var comment = commentInput ? commentInput.value : '';

        if (!pickupDate) {
            notice('error', 'Pickup date is required.');
            return;
        }

        if (!hourFrom || !hourTo) {
            notice('error', 'Pickup window is required.');
            return;
        }

        if ((parseInt(hourTo, 10) - parseInt(hourFrom, 10)) < 2) {
            notice('error', 'Pickup window must be at least 2 hours.');
            return;
        }

        setLoading(btn, true);

        postForm(config.urls.callCourier, {
            manifest_id: activeManifestId,
            date: pickupDate,
            hour_from: hourFrom,
            min_from: '0',
            hour_to: hourTo,
            min_to: '0',
            comment: comment,
        })
            .then(function (data) {
                setLoading(btn, false);
                if (data.success) {
                    notice('success', 'Courier pickup requested.');
                    hideCourierModal();
                    setTimeout(function () { window.location.reload(); }, 1000);
                } else {
                    notice('error', data.message || 'Courier call failed.');
                }
            })
            .catch(function (err) {
                setLoading(btn, false);
                notice('error', 'Request failed: ' + err.message);
            });
    }

    function postForm(url, params) {
        var body = Object.keys(params)
            .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
            .join('&');

        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: body,
        }).then(function (response) {
            var ct = response.headers.get('content-type') || '';
            if (!ct.includes('application/json')) {
                throw new Error('Unexpected response (HTTP ' + response.status + ').');
            }
            return response.json();
        });
    }

    function setLoading(btn, loading) {
        if (window.PPVenipakAdmin && window.PPVenipakAdmin.setLoading) {
            window.PPVenipakAdmin.setLoading(btn, loading);
        } else {
            btn.disabled = !!loading;
        }
    }

    function notice(type, message) {
        if (window.PPVenipakAdmin && window.PPVenipakAdmin.showNotice) {
            window.PPVenipakAdmin.showNotice(noticeContainer || document.body, type, message);
        }
        // No browser-native fallback — silently drop the message rather
        // than blocking the page with a modal alert dialog.
    }

    // Esc closes the modal.
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal && modal.style.display !== 'none') {
            hideCourierModal();
        }
    });
})();
