/* Workshop MultiTienda - POS Offline */
(function() {
    'use strict';

    // Estado del POS offline
    let currentPOSSale = null;
    let currentLocationId = null;

    // Inicializar POS offline
    async function initPOS(locationId) {
        currentLocationId = locationId;
        
        // Cargar productos offline
        if (window.WSDataSync) {
            if (navigator.onLine) {
                await WSDataSync.syncProducts(locationId);
            }
        }
        
        // Crear nueva venta temporal
        createNewSale();
    }

    // Crear nueva venta POS
    function createNewSale() {
        currentPOSSale = {
            location_id: currentLocationId,
            seller_id: WS.userId,
            customer_id: 0,
            customer_name: '',
            currency: WS.currency || '€',
            subtotal: 0,
            discount: 0,
            total: 0,
            payment_method: 'cash',
            status: 'pending',
            synced: false,
            items: [],
            created_at: new Date().toISOString()
        };
    }

    // Agregar producto a la venta POS actual
    function addProductToSale(product, qty = 1) {
        if (!currentPOSSale) {
            createNewSale();
        }

        const existingItem = currentPOSSale.items.find(item => item.product_id === product.id);
        
        if (existingItem) {
            existingItem.qty += qty;
            existingItem.subtotal = existingItem.qty * existingItem.price;
        } else {
            currentPOSSale.items.push({
                product_id: product.id,
                product_name: product.name,
                qty: qty,
                price: product.sale_price,
                discount: 0,
                subtotal: qty * product.sale_price
            });
        }

        recalculateTotals();
        return currentPOSSale;
    }

    // Actualizar cantidad de item en venta POS
    function updateSaleItem(productId, qty) {
        if (!currentPOSSale) return null;

        const item = currentPOSSale.items.find(item => item.product_id === productId);
        
        if (item) {
            if (qty <= 0) {
                currentPOSSale.items = currentPOSSale.items.filter(i => i.product_id !== productId);
            } else {
                item.qty = qty;
                item.subtotal = qty * item.price;
            }
        }

        recalculateTotals();
        return currentPOSSale;
    }

    // Eliminar item de venta POS
    function removeSaleItem(productId) {
        if (!currentPOSSale) return null;

        currentPOSSale.items = currentPOSSale.items.filter(item => item.product_id !== productId);
        recalculateTotals();
        return currentPOSSale;
    }

    // Recalcular totales de la venta
    function recalculateTotals() {
        if (!currentPOSSale) return;

        currentPOSSale.subtotal = currentPOSSale.items.reduce((sum, item) => sum + item.subtotal, 0);
        currentPOSSale.total = currentPOSSale.subtotal - currentPOSSale.discount;
    }

    // Establecer cliente de la venta
    function setCustomer(customer) {
        if (!currentPOSSale) {
            createNewSale();
        }

        currentPOSSale.customer_id = customer.id || 0;
        currentPOSSale.customer_name = customer.name || '';
        return currentPOSSale;
    }

    // Establecer método de pago
    function setPaymentMethod(method) {
        if (!currentPOSSale) {
            createNewSale();
        }

        currentPOSSale.payment_method = method;
        return currentPOSSale;
    }

    // Establecer descuento
    function setDiscount(discount) {
        if (!currentPOSSale) {
            createNewSale();
        }

        currentPOSSale.discount = discount;
        recalculateTotals();
        return currentPOSSale;
    }

    // Completar venta POS
    async function completeSale() {
        if (!currentPOSSale || currentPOSSale.items.length === 0) {
            throw new Error('No hay items en la venta');
        }

        currentPOSSale.status = 'completed';
        currentPOSSale.completed_at = new Date().toISOString();

        // Si está online, enviar al servidor
        if (navigator.onLine) {
            try {
                const response = await $('ws_pos_sale_save', currentPOSSale);
                if (response.success) {
                    const saleId = response.data.sale_id;
                    createNewSale();
                    return { success: true, sale_id: saleId, synced: true };
                }
            } catch (error) {
                console.error('Error guardando venta online:', error);
            }
        }

        // Si está offline o falló, guardar en IndexedDB
        if (window.WSIndexedDB) {
            try {
                const saleId = await WSIndexedDB.savePOSSale(currentPOSSale);
                
                // Agregar a cola de sincronización
                if (window.WSOfflineQueue) {
                    await WSOfflineQueue.addToQueue(WSOfflineQueue.QUEUE_ACTIONS.POS_SALE, currentPOSSale);
                }

                createNewSale();
                return { success: true, sale_id: saleId, synced: false };
            } catch (error) {
                console.error('Error guardando venta offline:', error);
                throw error;
            }
        }

        throw new Error('No se pudo guardar la venta');
    }

    // Obtener productos offline
    async function getProductsOffline() {
        if (window.WSDataSync) {
            return await WSDataSync.getProductsOffline(currentLocationId);
        }
        return [];
    }

    // Obtener clientes offline
    async function getCustomersOffline() {
        if (window.WSDataSync) {
            return await WSDataSync.getCustomersOffline();
        }
        return [];
    }

    // Obtener ventas POS offline pendientes de sincronización
    async function getPendingSales() {
        if (window.WSIndexedDB) {
            const sales = await WSIndexedDB.getPOSSales();
            return sales.filter(sale => !sale.synced);
        }
        return [];
    }

    // Obtener venta actual
    function getCurrentSale() {
        return currentPOSSale;
    }

    // Cancelar venta actual
    function cancelSale() {
        createNewSale();
    }

    // API pública
    window.WSPOSOffline = {
        initPOS,
        createNewSale,
        addProductToSale,
        updateSaleItem,
        removeSaleItem,
        setCustomer,
        setPaymentMethod,
        setDiscount,
        completeSale,
        getProductsOffline,
        getCustomersOffline,
        getPendingSales,
        getCurrentSale,
        cancelSale
    };

    // Componente Alpine para POS offline
    if (window.Alpine) {
        Alpine.data('posOffline', () => ({
            sale: currentPOSSale,
            products: [],
            customers: [],
            searchQuery: '',
            showCustomerModal: false,
            
            async init() {
                const locationId = this.$el.dataset.locationId || currentLocationId;
                await initPOS(locationId);
                this.products = await getProductsOffline();
                this.customers = await getCustomersOffline();
                this.sale = getCurrentSale();
            },

            get filteredProducts() {
                if (!this.searchQuery) return this.products;
                return this.products.filter(p => 
                    p.name.toLowerCase().includes(this.searchQuery.toLowerCase()) ||
                    p.barcode.includes(this.searchQuery)
                );
            },

            async addToCart(product) {
                this.sale = addProductToSale(product, 1);
            },

            async updateQty(productId, qty) {
                this.sale = updateSaleItem(productId, qty);
            },

            async removeFromCart(productId) {
                this.sale = removeSaleItem(productId);
            },

            async selectCustomer(customer) {
                this.sale = setCustomer(customer);
                this.showCustomerModal = false;
            },

            async completeSale() {
                try {
                    const result = await completeSale();
                    if (result.synced) {
                        if (window.Swal) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Venta completada',
                                text: 'Venta #' + result.sale_id + ' guardada exitosamente',
                                timer: 2000,
                                showConfirmButton: false
                            });
                        }
                    } else {
                        if (window.Swal) {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Venta guardada offline',
                                text: 'Se sincronizará cuando vuelva la conexión',
                                timer: 3000,
                                showConfirmButton: false
                            });
                        }
                    }
                    this.sale = getCurrentSale();
                } catch (error) {
                    if (window.Swal) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: error.message
                        });
                    }
                }
            },

            cancelSale() {
                cancelSale();
                this.sale = getCurrentSale();
            }
        }));
    }
})();
