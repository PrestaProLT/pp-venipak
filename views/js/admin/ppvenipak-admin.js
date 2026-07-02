/*
 * Lightweight enhancements shared across all PPVenipak admin pages.
 * - Loading state on async submit buttons (forms with [data-ppv-loading])
 * - Loading state on links that hit POST endpoints via JS
 * - Notice helpers (success/error messages) consumable by inline page scripts
 */

(function () {
    'use strict';

    // Apply ppv-loading state to a button/element. Idempotent.
    function setLoading(el, isLoading) {
        if (!el) return;
        if (isLoading) {
            if (!el.dataset.ppvOrigText) {
                el.dataset.ppvOrigText = el.textContent;
            }
            el.classList.add('ppv-loading');
            el.setAttribute('disabled', 'disabled');
        } else {
            el.classList.remove('ppv-loading');
            el.removeAttribute('disabled');
            if (el.dataset.ppvOrigText) {
                el.textContent = el.dataset.ppvOrigText;
                delete el.dataset.ppvOrigText;
            }
        }
    }

    // Mark forms that opt-in via data-ppv-loading; show spinner on submit.
    document.querySelectorAll('form[data-ppv-loading]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"], input[type="submit"]');
            setLoading(btn, true);
        });
    });

    // Expose helpers for page-specific scripts.
    window.PPVenipakAdmin = window.PPVenipakAdmin || {};
    window.PPVenipakAdmin.setLoading = setLoading;

    window.PPVenipakAdmin.showNotice = function (container, type, message) {
        if (!container) return;
        container.querySelectorAll('.ppv-notice').forEach(function (n) { n.remove(); });
        var el = document.createElement('div');
        el.className = 'ppv-notice ppv-notice--' + type;
        el.textContent = message || '';
        container.insertBefore(el, container.firstChild);
        var lineCount = (String(message || '').match(/\n/g) || []).length + 1;
        var ms = Math.max(8000, Math.min(30000, 4000 + lineCount * 2500 + (message || '').length * 25));
        setTimeout(function () { el.remove(); }, ms);
        return el;
    };

    /*
     * Locale-dropdown handler for PrestaShop's TranslatableType.
     *
     * On Symfony-routed admin pages with _legacy_link, PS's standard
     * translatable-input.js doesn't always bind. We re-implement the
     * dropdown behaviour ourselves: clicking a .js-locale-item toggles
     * d-none on the corresponding .js-locale-input siblings inside the
     * same .locale-input-group, and updates the dropdown button label.
     *
     * Idempotent — if PS's own handler already fires, our toggle still
     * yields the same end-state (one input visible).
     */
    document.addEventListener('click', function (event) {
        var item = event.target.closest('.js-locale-item');
        if (!item) return;

        var locale = item.dataset.locale;
        if (!locale) return;

        var group = item.closest('.locale-input-group, .js-locale-input-group');
        if (!group) return;

        group.querySelectorAll('.js-locale-input').forEach(function (input) {
            input.classList.add('d-none');
        });

        var target = group.querySelector('.js-locale-' + locale);
        if (target) {
            target.classList.remove('d-none');
        }

        var btn = group.querySelector('.js-locale-btn');
        if (btn) {
            btn.textContent = locale;
        }

        // Close the Bootstrap dropdown menu.
        var menu = item.closest('.dropdown-menu');
        if (menu) {
            menu.classList.remove('show');
        }
    });
})();
