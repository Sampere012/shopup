/* Workshop MultiTienda - Service Worker Registration */
(function() {
    'use strict';

    // Nota: se eliminó el botón flotante "Instalar app" (PWA); el service
    // worker sigue registrándose para el modo offline y las actualizaciones.

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

    // ---------- Prompt de instalación PWA (persistente) ----------
    // El navegador muestra su prompt nativo y lo oculta solo en segundos.
    // Interceptamos el evento (preventDefault) para suprimir ese prompt fugaz
    // y mostramos un diálogo propio que permanece hasta que el usuario decide.
    var deferredInstallPrompt = null;
    var WS_PWA_COOLDOWN_KEY = 'ws_pwa_install_dismissed_at';
    var WS_PWA_COOLDOWN_DAYS = 7;
    var WS_PWA_COOLDOWN_MS = WS_PWA_COOLDOWN_DAYS * 86400000;

    function wsIsStandalone() {
        return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
            window.navigator.standalone === true;
    }

    function wsInstallCooldownActive() {
        try {
            var t = parseInt(localStorage.getItem(WS_PWA_COOLDOWN_KEY) || '0', 10);
            if (!t) return false;
            return (Date.now() - t) < WS_PWA_COOLDOWN_MS;
        } catch (e) {
            return false;
        }
    }

    function wsRememberDismiss() {
        try { localStorage.setItem(WS_PWA_COOLDOWN_KEY, String(Date.now())); } catch (e) {}
    }

    function wsMaybeShowInstallPrompt() {
        // Ya instalada, en espera (cooldown) o sin soporte: no molestar.
        if (!deferredInstallPrompt || wsIsStandalone() || wsInstallCooldownActive()) return;
        if (!window.Swal) return;

        // Pequeño retardo para que la página termine de renderizar y el
        // diálogo no compita con la carga inicial.
        setTimeout(function() {
            if (!deferredInstallPrompt) return;
            Swal.fire({
                title: 'Instala Workshop en tu dispositivo',
                html: 'Accede <strong>más rápido</strong> y trabaja <strong>sin conexión</strong> desde la tienda, tu panel y el POS.',
                icon: 'info',
                showConfirmButton: true,
                confirmButtonText: 'Instalar ahora',
                showCancelButton: true,
                cancelButtonText: 'Ahora no',
                // El diálogo permanece hasta que el usuario decide; cerrarlo
                // (X, ESC o click fuera) también cuenta como "Ahora no".
                focusConfirm: true
            }).then(function(result) {
                if (result.isConfirmed && deferredInstallPrompt) {
                    // prompt() puede lanzar si el evento ya no es válido
                    // (ya instalada, standalone…): en ese caso se registra el
                    // cooldown para no volver a mostrarlo de inmediato.
                    try {
                        deferredInstallPrompt.prompt();
                        deferredInstallPrompt.userChoice.then(function(choice) {
                            if (choice && choice.outcome === 'accepted') {
                                deferredInstallPrompt = null;
                                if (window.Swal) {
                                    Swal.fire({
                                        toast: true, position: 'top-end', icon: 'success',
                                        title: 'Instalación iniciada',
                                        showConfirmButton: false, timer: 2500
                                    });
                                }
                            } else {
                                wsRememberDismiss();
                            }
                        });
                    } catch (e) {
                        deferredInstallPrompt = null;
                        wsRememberDismiss();
                    }
                } else {
                    wsRememberDismiss();
                }
            });
        }, 1200);
    }

    window.addEventListener('beforeinstallprompt', function(e) {
        // Suprime el prompt nativo fugaz; el nuestro se encarga.
        e.preventDefault();
        deferredInstallPrompt = e;
        wsMaybeShowInstallPrompt();
    });

    window.addEventListener('appinstalled', function() {
        deferredInstallPrompt = null;
        try { localStorage.removeItem(WS_PWA_COOLDOWN_KEY); } catch (e) {}
    });
})();
