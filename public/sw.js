// public/sw.js
const CACHE_NAME = 'lfg-static-v2';
const STATIC_ASSETS = [
    '/style.css',
    '/app.js',
    '/sw-register.js',
    '/manifest.json',
    '/offline.html'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            // Individual failures must not abort the whole install.
            .then((cache) => Promise.allSettled(STATIC_ASSETS.map((asset) => cache.add(asset))))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

const isCacheableAsset = (url) =>
    /\.(css|js|json|png|jpg|jpeg|svg|webp|woff2?)$/i.test(url.pathname);

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // Never intercept API calls or cross-origin requests (Steam avatars, fonts).
    if (url.origin !== self.location.origin || url.pathname.startsWith('/api/')) return;

    // Authenticated HTML must never be served from cache, otherwise a logged
    // out user keeps seeing the previous session's app shell.
    if (request.mode === 'navigate' || request.destination === 'document') {
        event.respondWith(
            fetch(request).catch(() => caches.match('/offline.html'))
        );
        return;
    }

    if (!isCacheableAsset(url)) return;

    // Stale-while-revalidate for static assets.
    event.respondWith(
        caches.match(request).then((cached) => {
            const network = fetch(request)
                .then((response) => {
                    if (response && response.ok) {
                        const copy = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
                    }
                    return response;
                })
                .catch(() => cached);

            return cached || network;
        })
    );
});
