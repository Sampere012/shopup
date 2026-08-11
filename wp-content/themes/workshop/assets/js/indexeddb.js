/* Workshop MultiTienda - IndexedDB para offline */
(function() {
    'use strict';

    const DB_NAME = 'WorkshopOfflineDB';
    const DB_VERSION = 2;
    const STORES = {
        products: 'products',
        customers: 'customers',
        locations: 'locations',
        cart: 'cart',
        pos_sales: 'pos_sales',
        offline_queue: 'offline_queue',
        sync_status: 'sync_status',
        ajax_cache: 'ajax_cache'
    };

    let db = null;

    // Inicializar IndexedDB
    function initDB() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onerror = () => {
                console.error('Error abriendo IndexedDB:', request.error);
                reject(request.error);
            };
            request.onsuccess = () => {
                db = request.result;
                console.log('IndexedDB inicializado correctamente');
                // Emitir evento personalizado
                const event = new CustomEvent('ws-indexeddb-ready');
                window.dispatchEvent(event);
                resolve(db);
            };

            request.onupgradeneeded = (event) => {
                const database = event.target.result;

                // Crear stores
                if (!database.objectStoreNames.contains(STORES.products)) {
                    const store = database.createObjectStore(STORES.products, { keyPath: 'id' });
                    store.createIndex('location_id', 'location_id', { unique: false });
                    store.createIndex('barcode', 'barcode', { unique: false });
                }

                if (!database.objectStoreNames.contains(STORES.customers)) {
                    const store = database.createObjectStore(STORES.customers, { keyPath: 'id' });
                    store.createIndex('email', 'email', { unique: true });
                    store.createIndex('phone', 'phone', { unique: false });
                }

                if (!database.objectStoreNames.contains(STORES.locations)) {
                    const store = database.createObjectStore(STORES.locations, { keyPath: 'id' });
                    store.createIndex('slug', 'slug', { unique: true });
                }

                if (!database.objectStoreNames.contains(STORES.cart)) {
                    const store = database.createObjectStore(STORES.cart, { keyPath: 'id', autoIncrement: true });
                    store.createIndex('session_id', 'session_id', { unique: false });
                }

                if (!database.objectStoreNames.contains(STORES.pos_sales)) {
                    const store = database.createObjectStore(STORES.pos_sales, { keyPath: 'id', autoIncrement: true });
                    store.createIndex('location_id', 'location_id', { unique: false });
                    store.createIndex('synced', 'synced', { unique: false });
                }

                if (!database.objectStoreNames.contains(STORES.offline_queue)) {
                    const store = database.createObjectStore(STORES.offline_queue, { keyPath: 'id', autoIncrement: true });
                    store.createIndex('status', 'status', { unique: false });
                }

                if (!database.objectStoreNames.contains(STORES.sync_status)) {
                    database.createObjectStore(STORES.sync_status, { keyPath: 'key' });
                }

                if (!database.objectStoreNames.contains(STORES.ajax_cache)) {
                    const store = database.createObjectStore(STORES.ajax_cache, { keyPath: 'key' });
                    store.createIndex('action', 'action', { unique: false });
                    store.createIndex('created_at', 'created_at', { unique: false });
                }
            };
        });
    }

    // Operaciones genéricas
    function getAll(storeName) {
        return new Promise((resolve, reject) => {
            if (!db) {
                console.warn('IndexedDB no inicializado');
                resolve([]);
                return;
            }
            const transaction = db.transaction(storeName, 'readonly');
            const store = transaction.objectStore(storeName);
            const request = store.getAll();
            request.onerror = () => reject(request.error);
            request.onsuccess = () => resolve(request.result);
        });
    }

    function get(storeName, key) {
        return new Promise((resolve, reject) => {
            if (!db) {
                console.warn('IndexedDB no inicializado');
                resolve(null);
                return;
            }
            const transaction = db.transaction(storeName, 'readonly');
            const store = transaction.objectStore(storeName);
            const request = store.get(key);
            request.onerror = () => reject(request.error);
            request.onsuccess = () => resolve(request.result);
        });
    }

    function put(storeName, data) {
        return new Promise((resolve, reject) => {
            if (!db) {
                console.warn('IndexedDB no inicializado');
                resolve(null);
                return;
            }
            const transaction = db.transaction(storeName, 'readwrite');
            const store = transaction.objectStore(storeName);
            const request = store.put(data);
            request.onerror = () => reject(request.error);
            request.onsuccess = () => resolve(request.result);
        });
    }

    function add(storeName, data) {
        return new Promise((resolve, reject) => {
            if (!db) {
                console.warn('IndexedDB no inicializado');
                resolve(null);
                return;
            }
            const transaction = db.transaction(storeName, 'readwrite');
            const store = transaction.objectStore(storeName);
            const request = store.add(data);
            request.onerror = () => reject(request.error);
            request.onsuccess = () => resolve(request.result);
        });
    }

    function deleteItem(storeName, key) {
        return new Promise((resolve, reject) => {
            if (!db) {
                console.warn('IndexedDB no inicializado');
                resolve();
                return;
            }
            const transaction = db.transaction(storeName, 'readwrite');
            const store = transaction.objectStore(storeName);
            const request = store.delete(key);
            request.onerror = () => reject(request.error);
            request.onsuccess = () => resolve();
        });
    }

    function clearStore(storeName) {
        return new Promise((resolve, reject) => {
            if (!db) {
                console.warn('IndexedDB no inicializado');
                resolve();
                return;
            }
            const transaction = db.transaction(storeName, 'readwrite');
            const store = transaction.objectStore(storeName);
            const request = store.clear();
            request.onerror = () => reject(request.error);
            request.onsuccess = () => resolve();
        });
    }

    // API pública
    window.WSIndexedDB = {
        init: initDB,
        getAll,
        get,
        put,
        add,
        delete: deleteItem,
        clear: clearStore,
        STORES,

        // Productos
        saveProducts: (products) => {
            return Promise.all(products.map(p => put(STORES.products, p)));
        },
        getProducts: () => getAll(STORES.products),
        getProduct: (id) => get(STORES.products, id),

        // Clientes
        saveCustomers: (customers) => {
            return Promise.all(customers.map(c => put(STORES.customers, c)));
        },
        getCustomers: () => getAll(STORES.customers),
        getCustomer: (id) => get(STORES.customers, id),

        // Ubicaciones
        saveLocations: (locations) => {
            return Promise.all(locations.map(l => put(STORES.locations, l)));
        },
        getLocations: () => getAll(STORES.locations),
        getLocation: (id) => get(STORES.locations, id),

        // Carrito
        saveCartItem: (item) => put(STORES.cart, item),
        getCartItems: () => getAll(STORES.cart),
        getCartItem: (id) => get(STORES.cart, id),
        deleteCartItem: (id) => deleteItem(STORES.cart, id),
        clearCart: () => clearStore(STORES.cart),

        // Ventas POS
        savePOSSale: (sale) => add(STORES.pos_sales, sale),
        getPOSSales: () => getAll(STORES.pos_sales),
        getPOSSale: (id) => get(STORES.pos_sales, id),
        updatePOSSale: (sale) => put(STORES.pos_sales, sale),

        // Cola offline
        addToQueue: (action) => {
            action.status = 'pending';
            action.created_at = new Date().toISOString();
            return add(STORES.offline_queue, action);
        },
        getQueue: () => getAll(STORES.offline_queue),
        updateQueueItem: (item) => put(STORES.offline_queue, item),
        deleteQueueItem: (id) => deleteItem(STORES.offline_queue, id),

        // Estado de sincronización
        setSyncStatus: (key, status) => put(STORES.sync_status, { key, status, updated_at: new Date().toISOString() }),
        getSyncStatus: (key) => get(STORES.sync_status, key),

        // Caché de respuestas AJAX (para el panel offline)
        cacheAjax: (key, payload) => put(STORES.ajax_cache, Object.assign({ key, created_at: new Date().toISOString() }, payload)),
        getAjaxCache: (key) => get(STORES.ajax_cache, key),
        deleteAjaxCache: (key) => deleteItem(STORES.ajax_cache, key),
        clearAjaxCache: () => clearStore(STORES.ajax_cache),

        // Utilidades
        isOnline: () => navigator.onLine,
        getDB: () => db
    };

    // Inicializar al cargar
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDB);
    } else {
        initDB();
    }
})();
