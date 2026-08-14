/* Workshop MultiTienda Service Worker */
const CACHE_NAME = 'workshop-v49';
const STATIC_CACHE = 'workshop-static-v15';
const DYNAMIC_CACHE = 'workshop-dynamic-v15';
const DATA_CACHE = 'workshop-data-v15';

// URLs estáticas para cachear inmediatamente
// En producción el SW se sirve desde la raíz del dominio (/sw.js), así que
// BASE_PATH queda vacío.
// NOTA: la portada ('/') NO va en esta lista: la comprobación isStatic usa
// includes() y 'http://…/' contiene '/', con lo que TODAS las peticiones se
// tratarían como estáticas (Cache-First) y rompería el Network First de la
// navegación. La portada se precachea por separado en install.
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
    // Respaldo offline (navegación útil sin red).
    '/offline.html',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
];

// Instalación del service worker
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => Promise.allSettled(
                STATIC_ASSETS.map((asset) => cache.add(asset).catch(() => {}))
            ))
            // Portada como último recurso offline (precachada aparte para no
            // ensuciar la lista estática usada por isStatic).
            .then((cache) => {
                try {
                    return caches.open(STATIC_CACHE).then((c) => c.add(BASE_PATH + '/').catch(() => {}));
                } catch (e) { return Promise.resolve(); }
            })
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

    // NO interceptar el panel de WordPress (wp-admin / wp-login): el SW no
    // debe añadir tráfico ni retrasos al acceso del administrador. Antes se
    // cacheaba/reejecutaba todo (Network First + guardado), lo que en móviles
    // con conexiones lentas producía ráfagas de peticiones que algunos hosts
    // respondían con «Too Many Requests» y bloqueaban la entrada al panel.
    if (url.pathname.indexOf('/wp-admin') !== -1 || url.pathname.indexOf('/wp-login.php') !== -1) {
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
                        .then((cached) => cached || caches.match('/offline.html') || caches.match('/'))
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
                            return caches.match('/offline.html') || caches.match('/');
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

// Precarga del panel del usuario (pedida por sw-register.js): cachea todos los
// módulos del panel (dashboard, productos, stock, POS…) para poder trabajar
// offline. El fetch desde el SW conserva las cookies de sesión del contexto,
// así que las páginas autenticadas se guardan igual que las visitaría el usuario.
self.addEventListener('message', (event) => {
    if (!event.data || event.data.type !== 'PRECACHE_PANEL') return;
    const urls = Array.isArray(event.data.urls) ? event.data.urls : [];
    if (!urls.length) return;
    event.waitUntil(
        caches.open(DYNAMIC_CACHE).then((cache) => precachePanelSequential(urls, cache))
    );
});

// Precarga los módulos del panel UNO A UNO con un pequeño espacio entre
// peticiones (en vez de disparar 20 a la vez). Las ráfagas de peticiones en
// paralelo desde móvil disparaban el límite de peticiones del host («Too Many
// Requests») y bloqueaban la entrada al panel. Solo descarga lo que falta en
// caché; las siguientes visitas no vuelven a pedir nada.
function precachePanelSequential(urls, cache) {
    const delay = () => new Promise((r) => setTimeout(r, 300));
    let i = 0;
    function next() {
        if (i >= urls.length) return Promise.resolve();
        const u = urls[i++];
        return cache.match(u)
            .then((hit) => {
                if (hit) return next();
                return fetch(u, { credentials: 'same-origin', redirect: 'manual' })
                    .then((res) => {
                        if (res.ok) cache.put(u, res.clone()).catch(() => {});
                    })
                    .catch(() => {})
                    .then(delay)
                    .then(next);
            })
            .catch(() => next());
    }
    return next();
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
