/* Workshop MultiTienda Service Worker */
const CACHE_NAME = 'workshop-v28';
const STATIC_CACHE = 'workshop-static-v7';
const DYNAMIC_CACHE = 'workshop-dynamic-v7';
const DATA_CACHE = 'workshop-data-v7';

// URLs estáticas para cachear inmediatamente
// En producción el SW se sirve desde la raíz del dominio (/sw.js), así que
// BASE_PATH queda vacío.
// NOTA: la portada ('/') NO se lista aquí: es contenido dinámico
// (mercado de negocios) y se sirve con Network First para que los cambios del
// admin (qué negocios se muestran) se reflejen en la siguiente carga.
const BASE_PATH = '';
const STATIC_ASSETS = [
    '/manifest.json',
    '/wp-content/themes/workshop/assets/css/theme.css',
    '/wp-content/themes/workshop/assets/css/ws-assistant.css',
    '/wp-content/themes/workshop/assets/js/theme.js',
    '/wp-content/themes/workshop/assets/js/sw-register.js',
    '/wp-content/themes/workshop/assets/js/indexeddb.js',
    '/wp-content/themes/workshop/assets/js/offline-queue.js',
    '/wp-content/themes/workshop/assets/js/offline-ui.js',
    '/wp-content/themes/workshop/assets/js/data-sync.js',
    '/wp-content/themes/workshop/assets/js/pos-offline.js',
    '/wp-content/themes/workshop/assets/js/panel-offline.js',
    '/wp-content/themes/workshop/assets/js/ws-assistant.js',
    '/wp-content/themes/workshop/assets/js/vendor/alpine-collapse.min.js',
    '/wp-content/themes/workshop/assets/js/vendor/alpine.min.js',
    '/wp-content/themes/workshop/assets/images/icon-72.png',
    '/wp-content/themes/workshop/assets/images/icon-192.png',
    '/wp-content/themes/workshop/assets/images/icon-512.png',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
];

// Instalación del service worker
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => Promise.allSettled(
                STATIC_ASSETS.map((asset) => cache.add(asset).catch(() => {}))
            ))
            .then(() => self.skipWaiting())
    );
});

// Activación y limpieza de caches antiguos
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames.map((cacheName) => {
                        if (cacheName !== STATIC_CACHE && cacheName !== DYNAMIC_CACHE && cacheName !== DATA_CACHE) {
                            return caches.delete(cacheName);
                        }
                    })
                );
            })
            .then(() => self.clients.claim())
    );
});

// Estrategia de caché mejorada con soporte offline
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // Ignorar peticiones no-GET.
    if (event.request.method !== 'GET') {
        return;
    }

    // CDN estáticos (Font Awesome, Google Fonts, SweetAlert…): Cache First con
    // red de respaldo. Aunque sean cross-origin los incluimos en la lista de
    // assets estáticos para poder trabajar offline.
    const isStatic = STATIC_ASSETS.some((asset) => url.href.includes(asset) || url.pathname.includes(asset));
    if (isStatic) {
        event.respondWith(
            caches.match(event.request)
                .then((response) => response || fetch(event.request)
                    .then((networkResponse) => {
                        try {
                            if (!networkResponse.bodyUsed) {
                                const copy = networkResponse.clone();
                                caches.open(STATIC_CACHE)
                                    .then((cache) => cache.put(event.request, copy))
                                    .catch(() => {});
                            }
                        } catch (e) { /* cuerpo ya consumido: no cachear */ }
                        return networkResponse;
                    })
                    .catch(() => new Response('', { status: 503 }))
                )
        );
        return;
    }

    // Ignorar el resto de peticiones a otros dominios (análisis, etc.).
    if (url.origin !== self.location.origin) {
        return;
    }

    // Navegaciones (documentos HTML): Network First con fallback a caché/offline.
    // Así la portada del mercado y los paneles siempre reflejan los últimos
    // cambios del admin (qué negocios aparecen), manteniendo offline-first.
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .then((networkResponse) => {
                    try {
                        if (!networkResponse.bodyUsed) {
                            const copy = networkResponse.clone();
                            caches.open(DYNAMIC_CACHE)
                                .then((cache) => cache.put(event.request, copy))
                                .catch(() => {});
                        }
                    } catch (e) { /* cuerpo ya consumido: no cachear */ }
                    return networkResponse;
                })
                .catch(() =>
                    caches.match(event.request)
                        .then((cached) => cached || caches.match('/'))
                )
        );
        return;
    }

    // Para peticiones AJAX (admin-ajax.php), usar Network First con fallback a IndexedDB
    if (url.pathname.includes('admin-ajax.php')) {
        event.respondWith(handleAJAXRequest(event.request));
        return;
    }

    // Para contenido dinámico, usar Stale-While-Revalidate
    event.respondWith(
        caches.match(event.request)
            .then((cachedResponse) => {
                const fetchPromise = fetch(event.request)
                    .then((networkResponse) => {
                        // Cachear respuestas exitosas (única vez y solo si el
                        // cuerpo no se ha consumido): evita "Response body is
                        // already used" cuando dos eventos comparten Response.
                        if (networkResponse && networkResponse.status === 200 && !networkResponse.bodyUsed) {
                            try {
                                const copy = networkResponse.clone();
                                caches.open(DYNAMIC_CACHE)
                                    .then((cache) => cache.put(event.request, copy))
                                    .catch(() => {});
                            } catch (e) { /* cuerpo ya consumido: no cachear */ }
                        }
                        return networkResponse;
                    })
                    .catch(() => {
                        // Si falla la red y hay caché, usarla
                        if (cachedResponse) {
                            return cachedResponse;
                        }
                        // Fallback para páginas HTML cuando está offline
                        if (event.request.headers.get('accept').includes('text/html')) {
                            return caches.match('/');
                        }
                    });
                return cachedResponse || fetchPromise;
            })
    );
});

// Manejar peticiones AJAX con soporte offline
async function handleAJAXRequest(request) {
    try {
        // Intentar fetch primero
        const response = await fetch(request);
        
        // Si es exitoso, cachear la respuesta
        if (response.ok) {
            try {
                if (!response.bodyUsed) {
                    const copy = response.clone();
                    const cache = await caches.open(DATA_CACHE);
                    cache.put(request, copy).catch(() => {});
                }
            } catch (e) { /* cuerpo ya consumido: no cachear */ }
        }

        return response;
    } catch (error) {
        // Si falla la red, intentar obtener de caché
        const cachedResponse = await caches.match(request);
        
        if (cachedResponse) {
            return cachedResponse;
        }
        
        // Si no hay caché, devolver respuesta offline
        return new Response(JSON.stringify({
            success: false,
            offline: true,
            msg: 'Sin conexión. La acción se guardará y sincronizará cuando vuelva la conexión.'
        }), {
            headers: { 'Content-Type': 'application/json' }
        });
    }
}

// Sincronización en background
self.addEventListener('sync', (event) => {
    if (event.tag === 'sync-offline-queue') {
        event.waitUntil(syncOfflineQueue());
    }
});

// Notificaciones push
self.addEventListener('push', (event) => {
    const options = {
        body: event.data ? event.data.text() : 'Nueva notificación',
        icon: '/wp-content/themes/workshop/assets/images/icon-192.png',
        badge: '/wp-content/themes/workshop/assets/images/icon-72.png',
        vibrate: [100, 50, 100],
        data: {
            dateOfArrival: Date.now(),
            primaryKey: 1
        }
    };

    event.waitUntil(
        self.registration.showNotification('Workshop', options)
    );
});

// Función para sincronizar cola offline (placeholder)
function syncOfflineQueue() {
    // Esta función se implementará en el frontend con IndexedDB
    return Promise.resolve();
}
