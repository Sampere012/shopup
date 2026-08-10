/* Workshop MultiTienda - Sincronización de Datos Offline */
(function() {
    'use strict';

    // Helper AJAX propio (el `$` de los templates del panel solo existe ahí).
    const $ = (path, data) => fetch(WS.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(Object.assign({ action: path, ws_nonce: WS.nonce }, data || {}))
    }).then(r => r.json());

    // Sincronizar datos del servidor a IndexedDB
    async function syncData() {
        if (!navigator.onLine) {
            console.log('Sin conexión, no se pueden sincronizar datos');
            return;
        }

        try {
            console.log('Iniciando sincronización de datos...');
            
            // Sincronizar ubicaciones
            await syncLocations();
            
            // Sincronizar productos (si hay location_id)
            const locationId = getCurrentLocationId();
            if (locationId) {
                await syncProducts(locationId);
            }
            
            // Sincronizar clientes (si el usuario tiene permisos)
            if (WS.role === 'owner' || WS.role === 'storekeeper') {
                await syncCustomers();
            }
            
            // Actualizar estado de sincronización
            await WSIndexedDB.setSyncStatus('last_sync', new Date().toISOString());
            
            console.log('Sincronización de datos completada');
            
            if (window.Swal) {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Datos sincronizados',
                    showConfirmButton: false,
                    timer: 2000
                });
            }
        } catch (error) {
            console.error('Error sincronizando datos:', error);
        }
    }

    // Sincronizar ubicaciones
    async function syncLocations() {
        try {
            const response = await $('ws_cache_locations');
            if (response.success && response.data && Array.isArray(response.data.data)) {
                await WSIndexedDB.saveLocations(response.data.data);
                console.log('Ubicaciones sincronizadas:', response.data.data.length);
            }
        } catch (error) {
            console.error('Error sincronizando ubicaciones:', error);
        }
    }

    // Sincronizar productos
    async function syncProducts(locationId) {
        try {
            const response = await $('ws_cache_products', { location_id: locationId });
            if (response.success && response.data && Array.isArray(response.data.data)) {
                await WSIndexedDB.saveProducts(response.data.data);
                console.log('Productos sincronizados:', response.data.data.length);
            }
        } catch (error) {
            console.error('Error sincronizando productos:', error);
        }
    }

    // Sincronizar clientes
    async function syncCustomers() {
        try {
            const response = await $('ws_cache_customers');
            if (response.success && response.data && Array.isArray(response.data.data)) {
                await WSIndexedDB.saveCustomers(response.data.data);
                console.log('Clientes sincronizados:', response.data.data.length);
            }
        } catch (error) {
            console.error('Error sincronizando clientes:', error);
        }
    }

    // Obtener location_id actual de la URL
    function getCurrentLocationId() {
        const locationSlug = window.location.pathname.match(/\/tienda\/([^\/]+)/);
        if (locationSlug && locationSlug[1]) {
            // Buscar en IndexedDB
            return WSIndexedDB.getLocations().then(locations => {
                const location = locations.find(l => l.slug === locationSlug[1]);
                return location ? location.id : null;
            });
        }
        
        // Si estamos en el panel, obtener de localStorage o parámetros
        const panelMatch = window.location.pathname.match(/\/panel\/([^\/]+)/);
        if (panelMatch) {
            // Para vendedores, obtener su ubicación asignada
            return localStorage.getItem('ws_current_location_id');
        }
        
        return Promise.resolve(null);
    }

    // Obtener productos offline
    async function getProductsOffline(locationId) {
        const products = await WSIndexedDB.getProducts();
        if (locationId) {
            return products.filter(p => p.location_id === locationId);
        }
        return products;
    }

    // Obtener clientes offline
    async function getCustomersOffline() {
        return await WSIndexedDB.getCustomers();
    }

    // Obtener ubicaciones offline
    async function getLocationsOffline() {
        return await WSIndexedDB.getLocations();
    }

    // API pública
    window.WSDataSync = {
        syncData,
        syncLocations,
        syncProducts,
        syncCustomers,
        getProductsOffline,
        getCustomersOffline,
        getLocationsOffline
    };

    // Sincronizar datos al cargar la página si hay conexión. Solo se hace en
    // la tienda pública (el panel carga sus propios datos por AJAX); así no se
    // disparan 3 peticiones extra por página dentro del panel.
    document.addEventListener('DOMContentLoaded', async () => {
        const path = window.location.pathname || '';
        const isStore = /\/tienda\//.test(path) || /^\/?$/.test(path);
        if (!isStore || !navigator.onLine || !window.WSIndexedDB) return;
        // Esperar un poco para que IndexedDB se inicialice
        setTimeout(async () => {
            const lastSync = await WSIndexedDB.getSyncStatus('last_sync');
            const now = new Date();

            // Sincronizar si hace más de 1 hora desde la última sincronización
            if (!lastSync || (now - new Date(lastSync.updated_at) > 3600000)) {
                await syncData();
            }
        }, 2000);
    });

    // Sincronizar cuando vuelva la conexión
    window.addEventListener('online', () => {
        setTimeout(syncData, 1000);
    });

    // Botón para sincronizar manualmente (se puede agregar al panel)
    document.addEventListener('click', (e) => {
        if (e.target.closest('.ws-sync-button')) {
            e.preventDefault();
            syncData();
        }
    });
})();
