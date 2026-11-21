const VERSION = 'v3';
const STATIC_CACHE = `shorts-studio-static-${VERSION}`;
const PRECACHE = ['/offline.html', '/icons/icon-192.png', '/icons/icon-512.png', '/favicon.svg'];

// Never intercept dynamic or large streamed requests.
const BYPASS = ['/livewire', '/media', '/youtube', '/deploy', '/logout', '/login', '/two-factor', '/user', '/passkeys'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(STATIC_CACHE).then((cache) => cache.addAll(PRECACHE)));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== STATIC_CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (BYPASS.some((prefix) => url.pathname.startsWith(prefix)) && request.mode !== 'navigate') {
        return;
    }

    if (request.headers.has('range')) {
        return;
    }

    // Pages: always network, fall back to the offline screen.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match('/offline.html')));

        return;
    }

    // Versioned build assets, icons and fonts: cache first, refresh in the background.
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/') || url.pathname.startsWith('/flux/') || /\.(?:woff2?|png|svg|ico)$/.test(url.pathname)) {
        event.respondWith(
            caches.open(STATIC_CACHE).then(async (cache) => {
                const cached = await cache.match(request);
                const network = fetch(request)
                    .then((response) => {
                        if (response.ok) {
                            cache.put(request, response.clone());
                        }

                        return response;
                    })
                    .catch(() => cached);

                return cached || network;
            }),
        );
    }
});
