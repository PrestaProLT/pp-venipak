/**
 * PrestaPro Carrier Module — Shared Admin JS
 */
document.addEventListener('DOMContentLoaded', function () {
    initTabs();
    initTestConnection();
});

/**
 * Tab navigation for config page.
 */
function initTabs() {
    var tabs = document.querySelectorAll('.prestapro-tabs__tab');
    var contents = document.querySelectorAll('.prestapro-tab-content');

    if (!tabs.length) {
        return;
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var targetId = this.getAttribute('data-tab');

            tabs.forEach(function (t) {
                t.classList.remove('prestapro-tabs__tab--active');
            });
            contents.forEach(function (c) {
                c.classList.remove('prestapro-tab-content--active');
            });

            this.classList.add('prestapro-tabs__tab--active');
            var target = document.getElementById(targetId);
            if (target) {
                target.classList.add('prestapro-tab-content--active');
            }

            // Persist active tab in URL hash
            if (history.replaceState) {
                history.replaceState(null, '', '#' + targetId);
            }
        });
    });

    // Restore tab from URL hash
    var hash = window.location.hash.replace('#', '');
    if (hash) {
        var activeTab = document.querySelector('[data-tab="' + hash + '"]');
        if (activeTab) {
            activeTab.click();
            return;
        }
    }

    // Default: first tab active
    if (tabs[0]) {
        tabs[0].click();
    }
}

/**
 * Test Connection button handler.
 */
function initTestConnection() {
    var btn = document.querySelector('.prestapro-status__btn[data-test-url]');

    if (!btn) {
        return;
    }

    btn.addEventListener('click', function (e) {
        e.preventDefault();

        var url = this.getAttribute('data-test-url');
        var statusBar = this.closest('.prestapro-status');
        var statusText = statusBar.querySelector('.prestapro-status__text');
        var originalText = btn.textContent;

        btn.textContent = 'Testing...';
        btn.disabled = true;

        fetch(url, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                statusBar.classList.remove(
                    'prestapro-status--connected',
                    'prestapro-status--disconnected',
                    'prestapro-status--unknown'
                );

                if (data.success) {
                    statusBar.classList.add('prestapro-status--connected');
                    if (statusText) {
                        statusText.textContent = data.message || 'Connected';
                    }
                } else {
                    statusBar.classList.add('prestapro-status--disconnected');
                    if (statusText) {
                        statusText.textContent = data.message || 'Connection failed';
                    }
                }
            })
            .catch(function () {
                statusBar.classList.remove(
                    'prestapro-status--connected',
                    'prestapro-status--disconnected',
                    'prestapro-status--unknown'
                );
                statusBar.classList.add('prestapro-status--disconnected');
                if (statusText) {
                    statusText.textContent = 'Connection error';
                }
            })
            .finally(function () {
                btn.textContent = originalText;
                btn.disabled = false;
            });
    });
}
