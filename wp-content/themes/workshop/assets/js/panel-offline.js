/* Workshop MultiTienda - Panel Offline Completo
 * Intercepta window.fetch para que TODOS los módulos del panel (productos,
 * stock, pedidos, clientes, caja, reportes, etc.) funcionen sin conexión:
 *  - Lecturas: se cachean en IndexedDB y, si no hay red, se sirven de caché.
 *  - Escrituras: si no hay red, se encolan (acción genérica) y se sincronizan
 *    automáticamente cuando vuelve la conexión (offline-queue.js).
 *  - El POS (pos_sale_save) y el carrito (cart_*) tienen su propio manejo
 *    offline y se omiten aquí para no duplicar la cola.
 */
(function() {
    'use strict';

    // Solo se activa dentro del panel del negocio.
    if (!document.querySelector('.ws-panel')) {
        return;
    }

    // Acciones de LECTURA: traen datos y se sirven desde caché si no hay red.
    var READ_ACTIONS = [
        'cache_customers', 'cache_locations', 'cache_products',
        'cart_count', 'cart_get', 'cart_total',
        'customers_get',
        'expenses_list',
        'get_location_by_slug', 'location_links_get', 'locations_list',
        'loyalty_customers', 'loyalty_settings', 'loyalty_stats', 'loyalty_transactions',
        'movements_list', 'my_locations',
        'notifications_list',
        'order_detail', 'order_list',
        'pos_cash_counts_get', 'pos_cash_history', 'pos_cash_status', 'pos_cash_stock',
        'pos_sale_items_get', 'pos_sales_get', 'pos_stats',
        'price_history_list',
        'products_get', 'products_list', 'public_order_status',
        'reviews_get', 'reviews_stats',
        'shifts_list', 'stock_counts_list', 'stock_count_virtual',
        'stock_list', 'store_products', 'suppliers_list', 'workers_list',
        // Consultas del asistente (chatbot) en el panel: son lecturas.
        'ws_chatbot_search', 'ws_chatbot_summary', 'ws_chatbot_filters',
        'ws_chatbot_meta', 'ws_chatbot_top', 'ws_chatbot_llm', 'ws_notifications_list'
    ];

    // Acciones con manejo offline propio (POS y carrito) o que no tienen
    // sentido encolar sin conexión (exportar reportes descarga un archivo):
    // pasan tal cual y su error natural se muestra al usuario.
    var SKIP_ACTIONS = [
        'pos_sale_save',
        'cart_add', 'cart_update', 'cart_remove', 'cart_clear', 'cart_merge',
        'reports_export'
    ];

    // Acciones de LISTA con respaldo de instantánea: si offline no hay
    // coincidencia exacta (página/filtro nunca consultados con conexión), se
    // sirve la última copia guardada de esa acción para que paginar no falle.
    // Las lecturas de DETALLE (order_detail, get_location_by_slug…) quedan
    // fuera: devolver el último registro cacheado mostraría un dato equivocado.
    var SNAPSHOT_ACTIONS = [
        'cache_customers', 'customers_get', 'expenses_list', 'location_links_get', 'locations_list',
        'movements_list', 'my_locations',
        'notifications_list', 'order_list', 'pos_cash_history', 'pos_sales_get',
        'price_history_list', 'products_get', 'products_list', 'reviews_get',
        'shifts_list', 'stock_counts_list', 'stock_list', 'suppliers_list', 'workers_list',
        'loyalty_customers', 'loyalty_transactions', 'store_products',
        'ws_chatbot_search', 'ws_chatbot_summary', 'ws_chatbot_meta',
        'ws_chatbot_top', 'ws_notifications_list'
    ];
    var isRead = function(action) { return READ_ACTIONS.indexOf(action) !== -1; };
    var isSkip = function(action) { return SKIP_ACTIONS.indexOf(action) !== -1; };
    var isSnapshot = function(action) { return SNAPSHOT_ACTIONS.indexOf(action) !== -1; };

    // Captura el wrapper de theme.js (que cuenta peticiones y adjunta ws_biz).
    var _fetch = window.fetch.bind(window);

    var banner = null;

    // ---------- Utilidades ----------

    function isAjaxRequest(url) {
        if (!window.WS || !WS.ajaxUrl) return false;
        try {
            var target = new URL(WS.ajaxUrl, location.href).pathname;
            return url.pathname === target;
        } catch (e) {
            return false;
        }
    }

    // Clave estable de caché: acción + parámetros ordenados (sin nonce).
    function cacheKeyFor(action, params) {
        var parts = [];
        params.forEach(function(value, key) {
            if (key === 'ws_nonce' || key === 'action') return;
            parts.push(key + '=' + value);
        });
        parts.sort();
        return action + '|' + parts.join('&');
    }

    async function cachedRead(key) {
        if (!window.WSIndexedDB) return null;
        try {
            var entry = await WSIndexedDB.getAjaxCache(key);
            return entry && entry.data ? entry.data : null;
        } catch (e) {
            return null;
        }
    }

    async function saveReadCache(key, data) {
        if (!window.WSIndexedDB) return;
        try {
            var action = key.split('|')[0];
            // Instantánea por acción (sin parámetros): sirve de respaldo para
            // offline cuando se pide una página/filtro que nunca se consultó
            // con conexión (p.ej. página 2 de una lista), evitando el error
            // de conexión al paginar. Solo para acciones de lista.
            await Promise.all([
                WSIndexedDB.cacheAjax(key, { action: action, data: data }),
                (action && isSnapshot(action))
                    ? WSIndexedDB.cacheAjax(action + '|__latest__', { action: action, data: data })
                    : Promise.resolve()
            ]);
        } catch (e) {}
    }

    function jsonResponse(payload, status) {
        return new Response(JSON.stringify(payload), {
            status: status || 200,
            headers: { 'Content-Type': 'application/json; charset=utf-8' }
        });
    }

    // Reenvía con el mismo formato que el helper $ (nonce incluido) para que
    // el wrapper de theme.js adjunte ws_biz y cuente la petición (sin
    // recursión: usa _fetch capturado, no window.fetch).
    function resend(action, params) {
        var body = new URLSearchParams();
        params.forEach(function(value, key) {
            if (key === 'ws_nonce') return; // se renueva abajo con WS.nonce
            body.append(key, value);
        });
        if (action) body.set('action', action);
        if (window.WS && WS.nonce) body.set('ws_nonce', WS.nonce);
        return _fetch(WS.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        });
    }

    // ---------- Banner de modo offline ----------

    function ensureBanner() {
        if (banner) return;
        banner = document.createElement('div');
        banner.id = 'ws-panel-offline-banner';
        banner.className = 'ws-panel-offline-banner';
        banner.innerHTML =
            '<i class="fa-solid fa-wifi-slash"></i>' +
            '<span class="ws-pob-text">Sin conexión &middot; <strong>modo offline</strong></span>' +
            '<span class="ws-offline-queue-indicator" style="display:none">0</span>' +
            '<button type="button" class="ws-pob-sync">Sincronizar</button>';
        banner.style.display = 'none';
        document.body.appendChild(banner);
        banner.querySelector('.ws-pob-sync').addEventListener('click', function() {
            syncNow();
        });
    }

    function setOfflineUI(state) {
        ensureBanner();
        banner.style.display = state ? 'flex' : 'none';
        document.body.classList.toggle('ws-panel-offline', state);
        if (window.WSOfflineQueue && state) {
            setTimeout(function() { WSOfflineQueue.updateQueueIndicator(); }, 500);
        }
    }

    // ---------- Sincronización ----------

    async function syncNow() {
        if (!window.WSOfflineQueue || !window.WSIndexedDB) return;
        try {
            var btn = banner ? banner.querySelector('.ws-pob-sync') : null;
            if (btn) { btn.disabled = true; btn.textContent = 'Sincronizando…'; }
            await WSOfflineQueue.processQueue();
            // Al sincronizar (estamos online), se descarta la caché de
            // lecturas para que los próximos datos sean frescos.
            await WSIndexedDB.clearAjaxCache();
            if (window.WSOfflineUI) await WSOfflineUI.updateQueueUI();
            window.dispatchEvent(new CustomEvent('ws-online-synced'));
        } catch (e) {
            console.error('Error sincronizando cola offline:', e);
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = 'Sincronizar'; }
        }
    }

    // ---------- Interceptor de fetch ----------

    async function handleOffline(isReadAction, action, params, key) {
        setOfflineUI(true);

        if (isReadAction) {
            var cached = await cachedRead(key);
            if (!cached) {
                // Sin coincidencia exacta (página o filtro nunca consultados
                // online): servir la última instantánea guardada de esa acción
                // (solo listas, nunca detalle) para que paginar/recargar no
                // falle con error de conexión.
                var actionOnly = key.split('|')[0];
                cached = (actionOnly && isSnapshot(actionOnly)) ? await cachedRead(actionOnly + '|__latest__') : null;
                if (cached) {
                    return jsonResponse(Object.assign({}, cached, { offlineSnapshot: true }));
                }
            }
            if (cached) {
                return jsonResponse(cached);
            }
            return jsonResponse({
                success: false,
                offline: true,
                data: { msg: 'Sin conexión y sin datos en caché para esta consulta' }
            }, 503);
        }

        // Escritura: encolar para sincronizar cuando vuelva la conexión.
        // Los parámetros repetidos (p. ej. locations[]=1&locations[]=2 o
        // payment_methods[]=…) se agrupan en arrays para no perder valores:
        // al sincronizar se reenvían como campos repetidos, igual que el
        // formulario original.
        var payload = {};
        params.forEach(function(value, keyName) {
            if (keyName === 'action' || keyName === 'ws_nonce' || keyName === 'ws_biz') return;
            if (Object.prototype.hasOwnProperty.call(payload, keyName)) {
                if (Array.isArray(payload[keyName])) {
                    payload[keyName].push(value);
                } else {
                    payload[keyName] = [payload[keyName], value];
                }
            } else {
                payload[keyName] = value;
            }
        });

        try {
            if (window.WSOfflineQueue) {
                await WSOfflineQueue.addToQueue(
                    WSOfflineQueue.QUEUE_ACTIONS.GENERIC,
                    { action: action, payload: payload }
                );
            }
            return jsonResponse({
                success: true,
                queued: true,
                offline: true,
                data: { msg: 'Guardado sin conexión. Se sincronizará automáticamente al reconectar.' }
            });
        } catch (e) {
            return jsonResponse({
                success: false,
                offline: true,
                data: { msg: 'Sin conexión y no se pudo guardar localmente' }
            }, 503);
        }
    }

    async function wsFetch(input, init) {
        var request;
        try {
            request = new Request(input, init);
        } catch (e) {
            return _fetch(input, init);
        }

        var url = new URL(request.url);

        // Solo intercepta el AJAX del panel (mismo path de admin-ajax.php).
        if (!isAjaxRequest(url)) {
            return _fetch(input, init);
        }

        // Solo se interceptan los POST form-urlencoded (el helper $ de los
        // módulos del panel). Los GET a admin-ajax los maneja el Service
        // Worker (handleAJAXRequest) con su propio fallback a IndexedDB.
        if (request.method !== 'POST') {
            return _fetch(input, init);
        }
        var ct = request.headers.get('Content-Type') || '';
        if (ct.indexOf('application/x-www-form-urlencoded') === -1) {
            return _fetch(input, init);
        }
        var params = null;
        try {
            var text = await request.clone().text();
            params = new URLSearchParams(text);
        } catch (e) {
            return _fetch(input, init);
        }

        var action = params.get('action');
        if (!action) {
            return _fetch(input, init);
        }
        // Normaliza el nombre de la acción: el parámetro llega como
        // 'ws_suppliers_list' pero las listas de acciones (lectura, omisión,
        // instantánea) usan el nombre sin el prefijo 'ws_'. El action completo
        // se conserva para el reenvío y la cola offline.
        var normAction = action.indexOf('ws_') === 0 ? action.slice(3) : action;
        if (isSkip(normAction)) {
            return _fetch(input, init);
        }

        var isReadAction = isRead(normAction);
        var key = cacheKeyFor(normAction, params);

        try {
            var response = await resend(action, params);

            if (response.ok) {
                // Lectura exitosa: refrescar la copia local para el próximo offline.
                if (isReadAction) {
                    var bodyText = await response.clone().text();
                    var json = null;
                    try { json = JSON.parse(bodyText); } catch (e) {}
                    if (json) await saveReadCache(key, json);
                }
                // Solo oculta el banner si realmente hay conexión (el SW puede
                // servir respuestas cacheadas aunque estemos sin red).
                if (navigator.onLine) setOfflineUI(false);
                return response;
            }

            // Respuesta no-OK: solo se trata como offline si es el 503 del SW
            // o realmente no hay conexión. Errores reales del servidor pasan.
            var offlineNow = response.status === 503 || !navigator.onLine;
            if (offlineNow) {
                return handleOffline(isReadAction, action, params, key);
            }
            return response;
        } catch (e) {
            // fetch lanzó (red caída sin SW activo): modo offline.
            return handleOffline(isReadAction, action, params, key);
        }
    }

    // ---------- Inicialización ----------

    // Al abrir el panel con conexión y acciones pendientes, sincroniza y
    // refresca la caché de lecturas para que los datos estén al día (además
    // del evento 'online'). Si no hay pendientes no toca la caché.
    async function syncOnOpen() {
        try {
            if (!navigator.onLine || !window.WSOfflineQueue || !window.WSIndexedDB) return;
            var st = await WSOfflineQueue.getQueueStatus();
            if (st.pending > 0) {
                await syncNow();
            }
        } catch (e) {
            console.error('Error sincronizando al abrir el panel:', e);
        }
    }

    function init() {
        window.fetch = wsFetch;
        ensureBanner();
        if (!navigator.onLine) setOfflineUI(true);
        if (window.WSOfflineQueue) {
            setTimeout(function() { WSOfflineQueue.updateQueueIndicator(); }, 2000);
        }
        // Sincronización al abrir: espera a que IndexedDB esté listo.
        setTimeout(function() { syncOnOpen(); }, 2500);
    }

    window.addEventListener('online', function() {
        setOfflineUI(false);
        syncNow();
    });

    window.addEventListener('offline', function() {
        setOfflineUI(true);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
