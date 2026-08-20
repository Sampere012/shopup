<?php
/**
 * Categorías de productos en ÁRBOL (subcategorías).
 *
 * Cada negocio organiza su catálogo con una jerarquía de categorías
 * padre/hijo. Los productos apuntan a su categoría (category_id) y la
 * columna de texto `category` guarda la RUTA («Padre / Hijo») para las
 * búsquedas, filtros y el chatbot existentes.
 *
 * Podar (eliminar): se borra la categoría y TODA su rama de descendientes;
 * los productos de esa rama pasan a la categoría padre (o quedan sin
 * categoría si era raíz) para no perder el catálogo.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

class WS_Categories {

    /** Tabla de categorías del negocio actual. */
    public static function table() {
        return ws_table_name( 'categories' );
    }

    /** Todas las categorías del negocio (sin árbol, orden automático por nombre). */
    public static function all() {
        global $wpdb;
        $t = self::table();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
            return array();
        }
        return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY name ASC" );
    }

    /** Mapa id => fila (acceso rápido al árbol). */
    public static function map() {
        $out = array();
        foreach ( self::all() as $c ) {
            $out[ (int) $c->id ] = $c;
        }
        return $out;
    }

    /** Hijos directos de una categoría (parent_id = 0 → raíces). */
    public static function children( $parent_id = 0 ) {
        $out = array();
        foreach ( self::all() as $c ) {
            if ( (int) $c->parent_id === (int) $parent_id ) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /** IDs de TODA la rama: descendientes recursivos de una categoría. */
    public static function descendants( $id ) {
        $map   = self::map();
        $ids   = array();
        $queue = array( (int) $id );
        while ( $queue ) {
            $cur = array_shift( $queue );
            foreach ( $map as $c ) {
                if ( (int) $c->parent_id === (int) $cur && ! in_array( (int) $c->id, $ids, true ) ) {
                    $ids[]   = (int) $c->id;
                    $queue[] = (int) $c->id;
                }
            }
        }
        return $ids;
    }

    /** Nombres desde la raíz hasta la categoría (ruta «Padre / Hijo»). */
    public static function path( $id ) {
        $map  = self::map();
        $names = array();
        $cur  = (int) $id;
        $guard = 0;
        while ( $cur && isset( $map[ $cur ] ) && $guard++ < 50 ) {
            array_unshift( $names, (string) $map[ $cur ]->name );
            $cur = (int) $map[ $cur ]->parent_id;
        }
        return $names;
    }

    /** Texto de la ruta («Padre / Hijo») o cadena vacía. */
    public static function path_text( $id ) {
        $p = self::path( $id );
        return $p ? implode( ' / ', $p ) : '';
    }

    /** Nombre legible para la UI: "Padre › Hijo" en vez de guiones para no confundir. */
    public static function display_name( $id ) {
        $path = self::path( (int) $id );
        return $path ? implode( ' › ', $path ) : '';
    }

    /** Lista plana con profundidad, para <select> con indentación. */
    public static function flat( $parent = 0, $depth = 0 ) {
        $out = array();
        foreach ( self::children( $parent ) as $c ) {
            $label = self::display_name( (int) $c->id );
            $out[] = array(
                'id'    => (int) $c->id,
                'name'  => $label ? $label : (string) $c->name,
                'depth' => (int) $depth,
            );
            $out = array_merge( $out, self::flat( (int) $c->id, $depth + 1 ) );
        }
        return $out;
    }

    /** Número de productos activos asignados a una categoría (toda su rama). */
    public static function products_count( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! $id ) {
            return 0;
        }
        $branch = array_merge( array( $id ), self::descendants( $id ) );
        $pt     = ws_table_name( 'products' );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pt ) ) !== $pt ) {
            return 0;
        }
        $ph = implode( ',', array_fill( 0, count( $branch ), '%d' ) );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$pt} WHERE active=1 AND category_id IN ({$ph})",
            ...$branch
        ) );
    }

    /** Guarda (crea o edita) una categoría. */
    public static function save( $data, $id = 0 ) {
        global $wpdb;
        $id     = (int) $id;
        $name   = sanitize_text_field( $data['name'] ?? '' );
        $parent = max( 0, (int) ( $data['parent_id'] ?? 0 ) );
        $active = isset( $data['active'] ) ? (int) filter_var( $data['active'], FILTER_VALIDATE_BOOLEAN ) : 1;
        $sort   = max( 0, (int) ( $data['sort_order'] ?? 0 ) );
        if ( '' === $name ) {
            return new WP_Error( 'name', __( 'El nombre de la categoría es obligatorio.', 'workshop' ) );
        }
        // Una categoría no puede ser hija de sí misma ni de sus descendientes.
        if ( $id && $parent ) {
            if ( $parent === $id || in_array( $parent, self::descendants( $id ), true ) ) {
                return new WP_Error( 'parent', __( 'La categoría no puede estar dentro de sí misma.', 'workshop' ) );
            }
        }
        // Jerarquía limitada a 3 niveles (raíz → subcategoría → sub-subcategoría):
        // si el padre ya está en el nivel 3 no se permiten más hijos.
        if ( $parent && count( self::path( $parent ) ) >= 3 ) {
            return new WP_Error( 'depth', __( 'Máximo 3 niveles de categorías.', 'workshop' ) );
        }
        $fields = array(
            'parent_id'  => $parent,
            'name'       => mb_substr( $name, 0, 150 ),
            'slug'       => mb_substr( sanitize_title( $name ), 0, 150 ),
            'sort_order' => $sort,
            'active'     => $active,
        );
        if ( $id ) {
            $wpdb->update( self::table(), $fields, array( 'id' => $id ) );
            return $id;
        }
        $wpdb->insert( self::table(), $fields );
        return (int) $wpdb->insert_id;
    }

    /**
     * PODAR: elimina la categoría y toda su rama de subcategorías. Los
     * productos de esa rama se reasignan a la categoría padre (o quedan sin
     * categoría si era raíz) y su texto de ruta se actualiza.
     */
    public static function delete( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! $id ) {
            return false;
        }
        $all = self::map();
        if ( ! isset( $all[ $id ] ) ) {
            return false;
        }
        $parent = (int) $all[ $id ]->parent_id;
        $branch = array_merge( array( $id ), self::descendants( $id ) );
        $ph     = implode( ',', array_fill( 0, count( $branch ), '%d' ) );
        $pt     = ws_table_name( 'products' );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pt ) ) === $pt ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$pt} SET category_id = %d, category = %s WHERE category_id IN ({$ph})",
                $parent,
                $parent ? self::path_text( $parent ) : '',
                ...$branch
            ) );
        }
        $wpdb->query( $wpdb->prepare(
            'DELETE FROM ' . self::table() . ' WHERE id IN (' . $ph . ')',
            ...$branch
        ) );
        return true;
    }
}

/** Payload de categorías para el panel y el formulario de productos. */
function ws_categories_payload() {
    $by_parent = array();
    foreach ( WS_Categories::all() as $c ) {
        $by_parent[ (int) $c->parent_id ][] = $c;
    }
    $build = function ( $parent ) use ( &$build, $by_parent ) {
        $out = array();
        foreach ( $by_parent[ (int) $parent ] ?? array() as $c ) {
            $out[] = array(
                'id'       => (int) $c->id,
                'parent_id'=> (int) $c->parent_id,
                'name'     => (string) $c->name,
                'active'   => (int) $c->active,
                'children' => $build( (int) $c->id ),
            );
        }
        return $out;
    };
    return array(
        'tree' => $build( 0 ),
        'flat' => WS_Categories::flat(),
    );
}

/* -------------------------------------------------------------------------
 * AJAX del módulo de categorías (panel de negocio)
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_ws_categories_list', 'ws_ajax_categories_list' );
function ws_ajax_categories_list() {
    ws_guard( 'categories_manage' );
    $out = array();
    foreach ( WS_Categories::all() as $c ) {
        $out[] = array(
            'id'         => (int) $c->id,
            'parent_id'  => (int) $c->parent_id,
            'name'       => (string) $c->name,
            'slug'       => (string) $c->slug,
            'active'     => (int) $c->active,
            'sort_order' => (int) $c->sort_order,
            'path'       => WS_Categories::path_text( (int) $c->id ),
            'children'   => count( WS_Categories::children( (int) $c->id ) ),
            'products'   => (int) WS_Categories::products_count( (int) $c->id ),
        );
    }
    wp_send_json_success( array(
        'categories' => $out,
        'payload'    => ws_categories_payload(),
    ) );
}

add_action( 'wp_ajax_ws_category_save', 'ws_ajax_category_save' );
function ws_ajax_category_save() {
    ws_guard( 'categories_manage' );
    $id = (int) ( $_POST['id'] ?? 0 );
    $result = WS_Categories::save( $_POST, $id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    ws_log_audit( $id ? 'category_update' : 'category_create', 'category', $result, array( 'name' => $_POST['name'] ?? '' ) );
    wp_send_json_success( array(
        'id'       => (int) $result,
        'payload'  => ws_categories_payload(),
    ) );
}

add_action( 'wp_ajax_ws_category_delete', 'ws_ajax_category_delete' );
function ws_ajax_category_delete() {
    ws_guard( 'categories_manage' );
    $id = (int) ( $_POST['id'] ?? 0 );
    WS_Categories::delete( $id );
    ws_log_audit( 'category_delete', 'category', $id );
    wp_send_json_success( array( 'payload' => ws_categories_payload() ) );
}
