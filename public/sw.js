const CACHE_NAME = 'tracker-asset-v7';
const APP_SHELL = [
    '/',
    '/login',
    '/manifest.webmanifest',
    '/offline.html',
    '/favicon.ico',
    '/images/mito-icon.png',
    '/images/mito-icon-192.png',
    '/images/mito-icon-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys
                .filter((key) => key !== CACHE_NAME)
                .map((key) => caches.delete(key))
        )).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') {
        return;
    }

    const requestUrl = new URL(event.request.url);
    if (requestUrl.origin !== self.location.origin) {
        return;
    }

    // Never serve/cache dynamic HTML or JSON through cache-first. Authenticated
    // page fragments (services, fleet polling, command state) are user/session
    // specific and must always reach the server.
    const accept = event.request.headers.get('Accept') || '';
    if (event.request.mode !== 'navigate' && (
        event.request.headers.has('X-Requested-With')
        || accept.includes('text/html')
        || accept.includes('application/json')
        || requestUrl.pathname.startsWith('/api/')
    )) {
        event.respondWith(fetch(event.request));
        return;
    }

    if (event.request.mode === 'navigate') {
        // Network-first, and never cached. Authenticated pages share a cache key
        // by URL only, so storing them here would replay one user's fleet page or
        // access-control screen to the next session on a shared machine. Only the
        // offline fallback is cached; every real page comes from the network.
        event.respondWith(
            fetch(event.request).catch(() => caches.match('/offline.html'))
        );

        return;
    }

    event.respondWith(
        caches.match(event.request).then((cachedResponse) => {
            if (cachedResponse) {
                return cachedResponse;
            }

            return fetch(event.request)
                .then((networkResponse) => {
                    if (networkResponse && networkResponse.status === 200) {
                        const responseClone = networkResponse.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, responseClone));
                    }

                    return networkResponse;
                })
                .catch(() => caches.match('/offline.html') || Response.error());
        })
    );
});
