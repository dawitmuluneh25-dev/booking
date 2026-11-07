(function () {
    'use strict';

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
