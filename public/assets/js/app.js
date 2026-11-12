(function () {
    'use strict';

    // Narrow header: below 40rem the links and the account actions sit behind a
    // single button. The class is added here rather than in the markup so that a
    // page whose script fails keeps the visible link bar instead of a closed
    // panel with no control that opens it.
    var navToggle = document.querySelector('.nav-toggle');
    var navPanel = document.getElementById('site-nav-panel');

    if (navToggle && navPanel) {
        document.documentElement.classList.add('nav-collapsible');

        var setNavOpen = function (open) {
            navPanel.classList.toggle('is-open', open);
            navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        };

        navToggle.addEventListener('click', function () {
            setNavOpen(navToggle.getAttribute('aria-expanded') !== 'true');
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && navPanel.classList.contains('is-open')) {
                setNavOpen(false);
                navToggle.focus();
            }
        });

        // Widening past the breakpoint must not leave an open state behind for
        // the next time the window narrows.
        var narrow = window.matchMedia('(max-width: 40rem)');

        if (typeof narrow.addEventListener === 'function') {
            narrow.addEventListener('change', function (event) {
                if (!event.matches) {
                    setNavOpen(false);
                }
            });
        }
    }

    var status = document.getElementById('net-status');
    var statusText = document.getElementById('net-status-text');

    function renderConnectivity() {
        if (!status || !statusText) {
            return;
        }
        var offline = navigator.onLine === false;
        status.hidden = !offline;
        statusText.textContent = offline ? 'Offline — submissions are disabled' : 'Online';
    }

    window.addEventListener('online', renderConnectivity);
    window.addEventListener('offline', renderConnectivity);
    renderConnectivity();

    // Disable submit while offline: the browser cannot reach the server, and the
    // UI must never imply a request was accepted.
    document.addEventListener('submit', function (event) {
        if (navigator.onLine === false && event.target instanceof HTMLFormElement) {
            event.preventDefault();
            window.alert('You are offline. Reconnect before sending this form.');
        }
    });

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {
                // Registration failure must not break the page; the app works without it.
            });
        });
    }
})();
