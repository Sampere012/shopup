<?php
/**
 * Sistema de colas para tareas pesadas.
 *
 * Usa WordPress Cron para procesar tareas en background.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

class WS_Queue {

    protected static function table( $t ) {
        return ws_table_name( $t );
    }

    /**
     * Inicializa el sistema de colas
     */
    public static function init() {
        // Programar cron para procesar cola cada 5 minutos
        if ( ! wp_next_scheduled( 'ws_process_queue' ) ) {
            wp_schedule_event( time(), 'ws_five_minutes', 'ws_process_queue' );
        }

        // Agregar intervalo personalizado de 5 minutos
        add_filter( 'cron_schedules', function( $schedules ) {
            $schedules['ws_five_minutes'] = array(
                'interval' => 300,
                'display'  => __( 'Cada 5 minutos', 'workshop' ),
            );
            return $schedules;
        });

        add_action( 'ws_process_queue', array( __CLASS__, 'process_queue' ) );
    }

    /**
     * Agrega una tarea a la cola
     */
    public static function add( $hook, $args = array(), $priority = 10 ) {
        global $wpdb;
        
        $wpdb->insert( self::table( 'queue' ), array(
            'hook' => $hook,
            'args' => maybe_serialize( $args ),
            'priority' => $priority,
            'status' => 'pending',
            'created_at' => current_time( 'mysql' ),
        ), array( '%s', '%s', '%d', '%s', '%s' ) );

        return $wpdb->insert_id;
    }

    /**
     * Procesa la cola de tareas
     */
    public static function process_queue() {
        global $wpdb;
        $table = self::table( 'queue' );

        // Obtener tareas pendientes ordenadas por prioridad
        $tasks = $wpdb->get_results(
            "SELECT * FROM {$table} 
            WHERE status = 'pending' 
            ORDER BY priority ASC, created_at ASC 
            LIMIT 10"
        );

        foreach ( $tasks as $task ) {
            // Marcar como procesando
            $wpdb->update( $table,
                array( 'status' => 'processing', 'started_at' => current_time( 'mysql' ) ),
                array( 'id' => $task->id ),
                array( '%s', '%s' ),
                array( '%d' )
            );

            $args = maybe_unserialize( $task->args );

            try {
                // Ejecutar el hook
                do_action_ref_array( $task->hook, $args );

                // Marcar como completado
                $wpdb->update( $table,
                    array( 'status' => 'completed', 'completed_at' => current_time( 'mysql' ) ),
                    array( 'id' => $task->id ),
                    array( '%s', '%s' ),
                    array( '%d' )
                );
            } catch ( Exception $e ) {
                // Marcar como fallido
                $wpdb->update( $table,
                    array( 
                        'status' => 'failed', 
                        'error_message' => $e->getMessage(),
                        'completed_at' => current_time( 'mysql' )
                    ),
                    array( 'id' => $task->id ),
                    array( '%s', '%s', '%s' ),
                    array( '%d' )
                );
            }
        }

        // Limpiar tareas completadas antiguas (más de 7 días)
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} 
            WHERE status = 'completed' 
            AND completed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        ) );

        // Reintentar tareas fallidas (máximo 3 reintentos)
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} 
            SET status = 'pending', retry_count = retry_count + 1 
            WHERE status = 'failed' 
            AND retry_count < 3 
            AND completed_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        ) );
    }

    /**
     * Obtiene estadísticas de la cola
     */
    public static function get_stats() {
        global $wpdb;
        $table = self::table( 'queue' );

        return array(
            'pending' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" ),
            'processing' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'processing'" ),
            'completed' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'completed'" ),
            'failed' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed'" ),
        );
    }
}

// Inicializar el sistema de colas
add_action( 'init', array( 'WS_Queue', 'init' ) );

/* ---------------- Tareas de ejemplo ---------------- */

/**
 * Tarea: Enviar notificación de stock bajo
 */
add_action( 'ws_queue_low_stock_alert', 'ws_task_low_stock_alert', 10, 4 );
function ws_task_low_stock_alert( $product_id, $location_id, $current_qty, $min_stock ) {
    if ( class_exists( 'WS_Stock' ) ) {
        WS_Stock::create_low_stock_alert( $product_id, $location_id, $current_qty, $min_stock );
    }
}

/**
 * Tarea: Generar reporte diario
 */
add_action( 'ws_queue_daily_report', 'ws_task_daily_report', 10, 2 );
function ws_task_daily_report( $location_id, $date ) {
    // Implementación de generación de reporte
    // Esto podría generar PDF, enviar email, etc.
}

/**
 * Tarea: Sincronizar inventario con proveedor
 */
add_action( 'ws_queue_sync_supplier', 'ws_task_sync_supplier', 10, 1 );
function ws_task_sync_supplier( $supplier_id ) {
    // Implementación de sincronización con proveedor
    // Esto podría llamar API externa, importar CSV, etc.
}

/**
 * Tarea: Procesar imágenes (redimensionar, optimizar)
 */
add_action( 'ws_queue_process_image', 'ws_task_process_image', 10, 2 );
function ws_task_process_image( $image_id, $sizes ) {
    // Implementación de procesamiento de imágenes
    // Redimensionar, comprimir, subir a CDN, etc.
}
