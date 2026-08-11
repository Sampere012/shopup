/* Workshop MultiTienda - Sistema de Cola Offline */
(function() {
    'use strict';

    const QUEUE_ACTIONS = {
        CART_ADD: 'cart_add',
        CART_UPDATE: 'cart_update',
        CART_REMOVE: 'cart_remove',
        POS_SALE: 'pos_sale',
        CUSTOMER_CREATE: 'customer_create',
        CUSTOMER_UPDATE: 'customer_update',
        REVIEW_CREATE: 'review_create',
        // Acción genérica: reenvía CUALQUIER endpoint del panel con su payload
        // original (action + data). La usa panel-offline.js para el resto de
        // módulos (productos, stock, pedidos, caja, etc.).
        GENERIC: 'generic'
    };

    // Agregar acción a la cola offline
    async function addToQueue(action, data) {
        try {
            if (!window.WSIndexedDB) {
                console.warn('IndexedDB no disponible, acción no guardada en cola');
                return null;
            }

            const queueItem = {
                action: action,
                data: data,
                status: 'pending',
                retries: 0,
                created_at: new Date().toISOString()
            };
            
            const id = await WSIndexedDB.addToQueue(queueItem);
            console.log('Acción agregada a cola offline:', action, id);
            
            // Actualizar indicador de UI
            updateQueueIndicator();
            
            return id;
        } catch (error) {
            console.error('Error al agregar a cola offline:', error);
            throw error;
        }
    }

    // Procesar cola cuando vuelva la conexión
    async function processQueue() {
        try {
            if (!window.WSIndexedDB) {
                console.warn('IndexedDB no disponible, no se puede procesar cola');
                return;
            }

            const queue = await WSIndexedDB.getQueue();
            const pendingItems = queue.filter(item => item.status === 'pending');
            
            if (pendingItems.length === 0) {
                console.log('No hay acciones pendientes en cola');
                return;
            }

            console.log(`Procesando ${pendingItems.length} acciones pendientes...`);

            for (const item of pendingItems) {
                try {
                    await processQueueItem(item);
                    
                    // Marcar como completado
                    item.status = 'completed';
                    item.processed_at = new Date().toISOString();
                    await WSIndexedDB.updateQueueItem(item);
                    
                    console.log('Acción procesada exitosamente:', item.action);
                } catch (error) {
                    console.error('Error procesando acción:', item.action, error);
                    
                    // Incrementar reintentos
                    item.retries = (item.retries || 0) + 1;
                    
                    if (item.retries >= 3) {
                        item.status = 'failed';
                        item.error = error.message;
                    }
                    
                    await WSIndexedDB.updateQueueItem(item);
                }
            }

            // Limpiar items completados
            await cleanupCompletedItems();
            
            // Actualizar indicador
            updateQueueIndicator();
            
            // Notificar al usuario
            if (window.Swal) {
                const completedCount = queue.filter(i => i.status === 'completed').length;
                if (completedCount > 0) {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: `${completedCount} acciones sincronizadas`,
                        showConfirmButton: false,
                        timer: 3000
                    });
                }
            }
        } catch (error) {
            console.error('Error procesando cola:', error);
        }
    }

    // Procesar un item individual de la cola
    async function processQueueItem(item) {
        switch (item.action) {
            case QUEUE_ACTIONS.CART_ADD:
                return await processCartAdd(item.data);
            case QUEUE_ACTIONS.CART_UPDATE:
                return await processCartUpdate(item.data);
            case QUEUE_ACTIONS.CART_REMOVE:
                return await processCartRemove(item.data);
            case QUEUE_ACTIONS.POS_SALE:
                return await processPOSSale(item.data);
            case QUEUE_ACTIONS.CUSTOMER_CREATE:
                return await processCustomerCreate(item.data);
            case QUEUE_ACTIONS.CUSTOMER_UPDATE:
                return await processCustomerUpdate(item.data);
            case QUEUE_ACTIONS.REVIEW_CREATE:
                return await processReviewCreate(item.data);
            case QUEUE_ACTIONS.GENERIC:
                return await processGeneric(item.data);
            default:
                throw new Error(`Acción desconocida: ${item.action}`);
        }
    }

    // Procesador genérico: reenvía el endpoint AJAX original con sus datos.
    async function processGeneric(data) {
        const action = data && data.action;
        const payload = data && data.payload ? data.payload : data;
        if (!action) throw new Error('Acción genérica sin action');
        const response = await $(action, payload);
        if (!response.success) throw new Error(response.data?.msg || 'Error al sincronizar');
        return response;
    }

    // Procesadores de acciones específicas
    async function processCartAdd(data) {
        const response = await $('ws_cart_add', data);
        if (!response.success) throw new Error(response.data?.msg || 'Error al agregar al carrito');
        return response;
    }

    async function processCartUpdate(data) {
        const response = await $('ws_cart_update', data);
        if (!response.success) throw new Error(response.data?.msg || 'Error al actualizar carrito');
        return response;
    }

    async function processCartRemove(data) {
        const response = await $('ws_cart_remove', data);
        if (!response.success) throw new Error(response.data?.msg || 'Error al eliminar del carrito');
        return response;
    }

    async function processPOSSale(data) {
        const response = await $('ws_pos_sale_save', data);
        if (!response.success) throw new Error(response.data?.msg || 'Error al guardar venta POS');
        return response;
    }

    async function processCustomerCreate(data) {
        const response = await $('ws_customers_save', data);
        if (!response.success) throw new Error(response.data?.msg || 'Error al crear cliente');
        return response;
    }

    async function processCustomerUpdate(data) {
        const response = await $('ws_customers_save', data);
        if (!response.success) throw new Error(response.data?.msg || 'Error al actualizar cliente');
        return response;
    }

    async function processReviewCreate(data) {
        const response = await $('ws_reviews_save', data);
        if (!response.success) throw new Error(response.data?.msg || 'Error al guardar reseña');
        return response;
    }

    // Limpiar items completados (más de 7 días)
    async function cleanupCompletedItems() {
        if (!window.WSIndexedDB) return;
        
        try {
            const queue = await WSIndexedDB.getQueue();
            const sevenDaysAgo = new Date();
            sevenDaysAgo.setDate(sevenDaysAgo.getDate() - 7);

            for (const item of queue) {
                if (item.status === 'completed' && new Date(item.processed_at) < sevenDaysAgo) {
                    await WSIndexedDB.deleteQueueItem(item.id);
                }
            }
        } catch (error) {
            console.error('Error limpiando items completados:', error);
        }
    }

    // Actualizar indicador de cola en UI
    async function updateQueueIndicator() {
        if (!window.WSIndexedDB) return;
        
        try {
            const queue = await WSIndexedDB.getQueue();
            const pendingCount = queue.filter(item => item.status === 'pending').length;
            
            const indicator = document.querySelector('.ws-offline-queue-indicator');
            if (indicator) {
                indicator.textContent = pendingCount;
                indicator.style.display = pendingCount > 0 ? 'inline-block' : 'none';
            }
        } catch (error) {
            console.error('Error actualizando indicador de cola:', error);
        }
    }

    // Obtener estado de la cola
    async function getQueueStatus() {
        if (!window.WSIndexedDB) {
            return { total: 0, pending: 0, processing: 0, completed: 0, failed: 0 };
        }
        
        try {
            const queue = await WSIndexedDB.getQueue();
            return {
                total: queue.length,
                pending: queue.filter(i => i.status === 'pending').length,
                processing: queue.filter(i => i.status === 'processing').length,
                completed: queue.filter(i => i.status === 'completed').length,
                failed: queue.filter(i => i.status === 'failed').length
            };
        } catch (error) {
            console.error('Error obteniendo estado de cola:', error);
            return { total: 0, pending: 0, processing: 0, completed: 0, failed: 0 };
        }
    }

    // API pública
    window.WSOfflineQueue = {
        QUEUE_ACTIONS,
        addToQueue,
        processQueue,
        getQueueStatus,
        updateQueueIndicator
    };

    // Escuchar cambios de conexión
    window.addEventListener('online', async () => {
        console.log('Conexión restaurada, procesando cola...');
        await WSIndexedDB.setSyncStatus('last_online', new Date().toISOString());
        await processQueue();
    });

    window.addEventListener('offline', async () => {
        console.log('Conexión perdida, modo offline activado');
        await WSIndexedDB.setSyncStatus('last_offline', new Date().toISOString());
    });

    // Inicializar indicador
    document.addEventListener('DOMContentLoaded', () => {
        // Esperar a que IndexedDB se inicialice antes de actualizar el indicador
        setTimeout(() => {
            updateQueueIndicator();
        }, 2000);
    });
})();
