/* Workshop MultiTienda - Service Worker Registration */
(function() {
    'use strict';

    // ---- Botón "Instalar app" (PWA) ----
    let deferredPrompt = null;
    const installKey = 'ws_pwa_install_dismissed';

    function showInstallButton() {
        var btn = document.getElementById('ws-pwa-install');
        if (!btn) return;
        btn.classList.add('ws-pwa-install-visible');
    }

    function hideInstallButton() {
        var btn = document.getElementById('ws-pwa-install');
        if (btn) btn.classList.remove('ws-pwa-install-visible');
    }

    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredPrompt = e;
        // No molestar si el usuario ya lo descartó (guarda durante 7 días).
        try {
            if (localStorage.getItem(installKey) &&
                Date.now() - Number(localStorage.getItem(installKey)) < 7 * 24 * 3600 * 1000) {
                return;
            }
        } catch (err) { /* localStorage no disponible */ }
        showInstallButton();
    });

    document.addEventListener('click', function(e) {
        var btn = e.target.closest ? e.target.closest('#ws-pwa-install') : null;
        if (!btn) return;
        if (!deferredPrompt) {
            // Ya instalada o no soportada: abrir el sitio en el navegador.
            try {
                localStorage.setItem(installKey, String(Date.now()));
            } catch (err) {}
            hideInstallButton();
            return;
        }
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function(choice) {
            deferredPrompt = null;
            hideInstallButton();
            try {
                localStorage.setItem(installKey, String(Date.now()));
            } catch (err) {}
        });
    });

    window.addEventListener('appinstalled', function() {
        deferredPrompt = null;
        hideInstallButton();
        try {
            localStorage.setItem(installKey, String(Date.now()));
        } catch (err) {}
    });

    // Verificar si el navegador soporta service workers
    if ('serviceWorker' in navigator) {
        // Esperar a que la página cargue completamente
        window.addEventListener('load', function() {
            navigator.serviceWorker.register(WSPWA.swUrl)
                .then(function(registration) {
                    console.log('Service Worker registrado con éxito:', registration.scope);
                    
                    // Verificar actualizaciones del service worker
                    registration.addEventListener('updatefound', function() {
                        const newWorker = registration.installing;
                        
                        newWorker.addEventListener('statechange', function() {
                            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                                // Nuevo service worker disponible, mostrar notificación
                                if (window.Swal) {
                                    Swal.fire({
                                        title: 'Actualización disponible',
                                        text: 'Hay una nueva versión de la aplicación. ¿Desea actualizar ahora?',
                                        icon: 'info',
                                        showCancelButton: true,
                                        confirmButtonText: 'Actualizar',
                                        cancelButtonText: 'Más tarde'
                                    }).then(function(result) {
                                        if (result.isConfirmed) {
                                            newWorker.postMessage({ type: 'SKIP_WAITING' });
                                            window.location.reload();
                                        }
                                    });
                                }
                            }
                        });
                    });

                    // Registrar sincronización en background
                    if ('sync' in registration) {
                        registration.sync.register('sync-offline-queue')
                            .then(function() {
                                console.log('Sincronización en background registrada');
                            })
                            .catch(function(error) {
                                console.log('Error registrando sincronización:', error);
                            });
                    }
                })
                .catch(function(error) {
                    console.error('Error al registrar Service Worker:', error);
                });
        });

        // Escuchar mensajes del service worker
        navigator.serviceWorker.addEventListener('message', function(event) {
            if (event.data && event.data.type === 'CACHE_UPDATED') {
                console.log('Caché actualizada');
            }
        });

        // Detectar cambios de conexión
        window.addEventListener('online', async function() {
            console.log('Conexión restaurada');
            
            // Mostrar notificación
            if (window.Swal) {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Conexión restaurada',
                    showConfirmButton: false,
                    timer: 3000
                });
            }

            // Procesar cola offline si está disponible
            if (window.WSOfflineQueue) {
                await WSOfflineQueue.processQueue();
            }
        });

        window.addEventListener('offline', function() {
            console.log('Sin conexión');
            
            if (window.Swal) {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'warning',
                    title: 'Modo offline activado',
                    text: 'Las acciones se guardarán y sincronizarán cuando vuelva la conexión',
                    showConfirmButton: false,
                    timer: 4000
                });
            }
        });
    }

    // Solicitar permiso para notificaciones push
    if ('Notification' in window && Notification.permission === 'default') {
        document.addEventListener('click', function requestNotificationPermission() {
            Notification.requestPermission().then(function(permission) {
                if (permission === 'granted') {
                    console.log('Permiso de notificaciones concedido');
                }
                document.removeEventListener('click', requestNotificationPermission);
            });
        }, { once: true });
    }

    // Interceptar fetch para agregar a cola offline cuando no hay conexión
    if (window.fetch && window.WSOfflineQueue) {
        const originalFetch = window.fetch;
        window.fetch = function(...args) {
            return originalFetch.apply(this, args).catch(async function(error) {
                // Si falla por falta de conexión y es una petición AJAX
                if (!navigator.onLine && args[0] instanceof Request && args[0].url.includes('admin-ajax.php')) {
                    const formData = await args[0].formData();
                    const action = formData.get('action');
                    
                    console.log('Petición fallida offline, agregando a cola:', action);
                    
                    // Mapear acciones de AJAX a acciones de cola
                    const actionMap = {
                        'ws_cart_add': WSOfflineQueue.QUEUE_ACTIONS.CART_ADD,
                        'ws_cart_update': WSOfflineQueue.QUEUE_ACTIONS.CART_UPDATE,
                        'ws_cart_remove': WSOfflineQueue.QUEUE_ACTIONS.CART_REMOVE,
                        'ws_pos_sale_save': WSOfflineQueue.QUEUE_ACTIONS.POS_SALE,
                        'ws_customers_save': WSOfflineQueue.QUEUE_ACTIONS.CUSTOMER_CREATE,
                        'ws_reviews_save': WSOfflineQueue.QUEUE_ACTIONS.REVIEW_CREATE
                    };

                    if (actionMap[action]) {
                        await WSOfflineQueue.addToQueue(actionMap[action], Object.fromEntries(formData));
                        
                        // Devolver respuesta offline
                        return {
                            ok: true,
                            json: async () => ({
                                success: true,
                                offline: true,
                                msg: 'Acción guardada offline. Se sincronizará cuando vuelva la conexión.'
                            })
                        };
                    }
                }
                
                throw error;
            });
        };
    }
})();
