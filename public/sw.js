/* Booking PWA service worker.
 *
 * Caching policy is deliberately narrow:
 *   - static assets only (CSS, JS, icons, manifest),
 *   - navigation requests go to the network first and fall back to a static
 *     offline page,
 *   - everything else (API, form posts, HTML) is never cached.
 *
 * No page containing submitted data is ever stored, so a stale cache can never
 * present a booking as if it were current.
 */
const CACHE_NAME = 'booking-shell-v2';

const PRECACHE = [
    '/offline.html',
    '/assets/css/app.css',
    '/assets/js/app.js',
    '/assets/icons/icon-192.png',
    '/assets/icons/icon-512.png',
    '/manifest.webmanifest'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match('/offline.html'))
        );
        return;
    }

    // Network-first with a cache fallback: stylesheet and script changes must
    // take effect on a normal reload, and offline still works from cache.
    if (url.pathname.startsWith('/assets/')) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
                    }
                    return response;
                })
                .catch(() => caches.match(request))
        );
    }
});
