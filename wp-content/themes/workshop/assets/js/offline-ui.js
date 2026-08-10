/* Workshop MultiTienda - UI Offline-First */
(function() {
    'use strict';

    // Crear indicadores de estado
    function createOfflineUI() {
        // Indicador de conexión dentro del navbar
        const connectionIndicator = document.createElement('div');
        connectionIndicator.id = 'ws-connection-indicator';
        connectionIndicator.className = 'ws-connection-indicator';
        connectionIndicator.innerHTML = `
            <i class="fa-solid fa-wifi"></i>
            <span class="ws-status-text">Conectado</span>
        `;
        connectionIndicator.style.cssText = `
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            background: #10b981;
            color: white;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            margin-left: 12px;
            white-space: nowrap;
        `;
        connectionIndicator.addEventListener('click', showConnectionModal);
        connectionIndicator.addEventListener('mouseenter', () => {
            connectionIndicator.style.opacity = '0.8';
        });
        connectionIndicator.addEventListener('mouseleave', () => {
            connectionIndicator.style.opacity = '1';
        });
        
        // Intentar agregar al navbar al lado del avatar
        const navbar = document.querySelector('.ws-navbar, nav, .navbar, header nav');
        if (navbar) {
            // Buscar el avatar o el lado derecho del navbar
            const avatar = navbar.querySelector('.ws-avatar, .user-avatar, [class*="avatar"], .nav-item:last-child');
            if (avatar) {
                avatar.parentNode.insertBefore(connectionIndicator, avatar.nextSibling);
            } else {
                // Si no hay avatar, agregar al final del navbar
                const navbarContent = navbar.querySelector('.navbar-content, .nav-content, .container, .flex');
                if (navbarContent) {
                    navbarContent.appendChild(connectionIndicator);
                } else {
                    navbar.appendChild(connectionIndicator);
                }
            }
        } else {
            // Si no hay navbar, agregar flotante en la esquina
            connectionIndicator.style.cssText += `
                position: fixed;
                top: 70px;
                right: 20px;
                z-index: 9999;
                margin-left: 0;
            `;
            document.body.appendChild(connectionIndicator);
        }

        // Indicador de cola (badge pequeño sobre el indicador de conexión)
        const queueBadge = document.createElement('span');
        queueBadge.id = 'ws-queue-badge';
        queueBadge.className = 'ws-queue-badge';
        queueBadge.style.cssText = `
            position: absolute;
            top: -6px;
            right: -6px;
            background: #ef4444;
            color: white;
            border-radius: 50%;
            min-width: 18px;
            height: 18px;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: bold;
            border: 2px solid white;
            padding: 0 4px;
        `;
        queueBadge.textContent = '0';
        connectionIndicator.appendChild(queueBadge);

        // Modal de estado de conexión
        const modal = document.createElement('div');
        modal.id = 'ws-connection-modal';
        modal.className = 'ws-connection-modal';
        modal.style.cssText = `
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 10001;
            justify-content: center;
            align-items: center;
        `;
        modal.innerHTML = `
            <div class="ws-connection-modal-content" style="
                background: white;
                border-radius: 12px;
                padding: 24px;
                max-width: 400px;
                width: 90%;
            ">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                    <h3 style="margin:0;font-size:18px;">Estado de Conexión</h3>
                    <button class="ws-close-modal" style="background:none;border:none;font-size:24px;cursor:pointer;">&times;</button>
                </div>
                <div id="ws-connection-details" style="margin-bottom:16px;"></div>
                <div id="ws-queue-list" style="min-height:60px;"></div>
                <div style="margin-top:16px;text-align:right;">
                    <button id="ws-sync-now" style="
                        background: #3b82f6;
                        color: white;
                        border: none;
                        padding: 8px 16px;
                        border-radius: 6px;
                        cursor: pointer;
                        font-weight: 500;
                    ">Sincronizar Ahora</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        // Event listeners del modal
        modal.querySelector('.ws-close-modal').addEventListener('click', () => {
            modal.style.display = 'none';
        });
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        });
        modal.querySelector('#ws-sync-now').addEventListener('click', async () => {
            if (window.WSOfflineQueue) {
                await WSOfflineQueue.processQueue();
                updateConnectionUI();
                updateQueueUI();
            }
        });
    }

    // Actualizar UI de estado de conexión
    function updateConnectionStatus() {
        const indicator = document.getElementById('ws-connection-indicator');
        if (!indicator) return;

        const icon = indicator.querySelector('i');
        const text = indicator.querySelector('.ws-status-text');

        if (navigator.onLine) {
            indicator.style.background = '#10b981';
            icon.className = 'fa-solid fa-wifi';
            text.textContent = 'Conectado';
        } else {
            indicator.style.background = '#ef4444';
            icon.className = 'fa-solid fa-wifi-slash';
            text.textContent = 'Sin conexión';
        }
    }

    // Actualizar UI de cola
    async function updateQueueUI() {
        if (!window.WSOfflineQueue) return;

        try {
            const status = await WSOfflineQueue.getQueueStatus();
            const queueBadge = document.getElementById('ws-queue-badge');

            if (queueBadge) {
                if (status.pending > 0) {
                    queueBadge.style.display = 'flex';
                    queueBadge.textContent = status.pending > 9 ? '9+' : status.pending;
                } else {
                    queueBadge.style.display = 'none';
                }
            }
        } catch (error) {
            console.log('IndexedDB aún no inicializado, se actualizará más tarde');
        }
    }

    // Mostrar modal de conexión
    async function showConnectionModal() {
        const modal = document.getElementById('ws-connection-modal');
        const connectionDetails = document.getElementById('ws-connection-details');
        const queueList = document.getElementById('ws-queue-list');
        
        if (!modal) return;

        // Mostrar estado de conexión
        connectionDetails.innerHTML = `
            <div style="display:flex;align-items:center;gap:8px;padding:12px;border-radius:8px;background:${navigator.onLine ? '#d1fae5' : '#fee2e2'};">
                <i class="fa-solid ${navigator.onLine ? 'fa-wifi' : 'fa-wifi-slash'}" style="color:${navigator.onLine ? '#059669' : '#dc2626'};"></i>
                <span style="font-weight:500;">${navigator.onLine ? 'Conectado a Internet' : 'Sin conexión - Modo offline activo'}</span>
            </div>
        `;

        // Mostrar cola pendiente
        if (window.WSIndexedDB) {
            const queue = await WSIndexedDB.getQueue();
            const pendingItems = queue.filter(item => item.status === 'pending');

            if (pendingItems.length === 0) {
                queueList.innerHTML = '<p style="text-align:center;color:#6b7280;padding:20px;">No hay acciones pendientes</p>';
            } else {
                queueList.innerHTML = `
                    <div style="margin-bottom:12px;font-weight:500;">${pendingItems.length} acción(es) pendiente(s):</div>
                    ${pendingItems.slice(0, 5).map(item => `
                        <div style="
                            padding: 10px;
                            border: 1px solid #e5e7eb;
                            border-radius: 6px;
                            margin-bottom: 6px;
                            display: flex;
                            justify-content: space-between;
                            align-items: center;
                            font-size: 13px;
                        ">
                            <span>${getActionLabel(item.action)}</span>
                            <span style="color:#6b7280;font-size:11px;">${new Date(item.created_at).toLocaleTimeString()}</span>
                        </div>
                    `).join('')}
                    ${pendingItems.length > 5 ? `<div style="text-align:center;color:#6b7280;font-size:12px;">... y ${pendingItems.length - 5} más</div>` : ''}
                `;
            }
        }

        modal.style.display = 'flex';
    }

    // Obtener etiqueta legible de acción
    function getActionLabel(action) {
        const labels = {
            'cart_add': 'Agregar al carrito',
            'cart_update': 'Actualizar carrito',
            'cart_remove': 'Eliminar del carrito',
            'pos_sale': 'Venta POS',
            'customer_create': 'Crear cliente',
            'customer_update': 'Actualizar cliente',
            'review_create': 'Crear reseña'
        };
        return labels[action] || action;
    }

    // Inicializar UI
    function init() {
        // createOfflineUI();  // Deshabilitado: botón flotante "Conectado"
        updateConnectionStatus();
        
        // Esperar a que IndexedDB esté listo antes de actualizar la cola
        window.addEventListener('ws-indexeddb-ready', () => {
            updateQueueUI();
        });
        
        // Fallback: intentar actualizar después de 3 segundos si el evento no se dispara
        setTimeout(() => {
            updateQueueUI();
        }, 3000);

        // Escuchar cambios de conexión
        window.addEventListener('online', () => {
            updateConnectionStatus();
            setTimeout(updateQueueUI, 1000);
        });

        window.addEventListener('offline', () => {
            updateConnectionStatus();
        });

        // Actualizar cola periódicamente
        setInterval(updateQueueUI, 30000);
    }

    // Inicializar cuando cargue el DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Exponer función para actualizar UI manualmente
    window.WSOfflineUI = {
        updateConnectionStatus,
        updateQueueUI,
        showConnectionModal
    };
})();
