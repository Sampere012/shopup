<?php
/**
 * Combos: agrupar productos en paquetes (1 combo contiene x, y, z con sus
 * cantidades). El precio puede ser MANUAL (lo pone el vendedor) o AUTO
 * (la app suma el precio de venta de cada producto por su cantidad, con
 * conversión de moneda). El stock del combo es SIEMPRE derivado de sus
 * componentes: disponible = min( floor(stock(producto) / cantidad_necesaria) ).
 *
 * Cuando un combo se vende o se transfiere se descuentan los COMPONENTES
 * (cada uno su cantidad × nº de combos), así el control es real y el stock
 * nunca se desincroniza.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

class WS_Combos {

    protected static function table( $t ) {
        return ws_table_name( $t );
    }

    /* ---------------- Lectura ---------------- */

    public static function get( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! $id ) {
            return null;
        }
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table( 'combos' ) . " WHERE id = %d", $id
        ) );
    }

    public static function items( $combo_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT ci.*, p.name AS product_name, p.sale_price, p.currency AS product_currency, p.active AS product_active
             FROM " . self::table( 'combo_items' ) . " ci
             INNER JOIN " . self::table( 'products' ) . " p ON p.id = ci.product_id
             WHERE ci.combo_id = %d ORDER BY ci.id ASC", (int) $combo_id
        ) );
    }

    public static function all( $args = array() ) {
        global $wpdb;
        $where = self::where( $args );
        $sql = "SELECT c.*, (SELECT COUNT(*) FROM " . self::table( 'combo_items' ) . " ci WHERE ci.combo_id = c.id) AS item_count
                FROM " . self::table( 'combos' ) . " c WHERE " . implode( ' AND ', $where )
            . " ORDER BY c.name ASC";
        if ( isset( $args['limit'] ) ) {
            $sql .= $wpdb->prepare( ' LIMIT %d', max( 1, (int) $args['limit'] ) );
        }
        if ( isset( $args['offset'] ) ) {
            $sql .= $wpdb->prepare( ' OFFSET %d', max( 0, (int) $args['offset'] ) );
        }
        return $wpdb->get_results( $sql );
    }

    public static function count( $args = array() ) {
        global $wpdb;
        $where = self::where( $args );
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::table( 'combos' ) . " c WHERE " . implode( ' AND ', $where )
        );
    }

    protected static function where( $args = array() ) {
        global $wpdb;
        $where = array( '1=1' );
        if ( isset( $args['active'] ) && '' !== (string) $args['active'] ) {
            $where[] = $wpdb->prepare( 'c.active = %d', (int) $args['active'] );
        }
        if ( ! empty( $args['search'] ) ) {
            $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = $wpdb->prepare( '(c.name LIKE %s)', $like );
        }
        return $where;
    }

    /* ---------------- Precio ---------------- */

    /**
     * Precio del combo: manual si price_mode='manual', si no la suma de los
     * precios de venta de sus productos (convertidos a la moneda del combo).
     */
    public static function price( $combo ) {
        if ( ! $combo ) {
            return 0.0;
        }
        if ( 'manual' === (string) $combo->price_mode ) {
            return (float) $combo->price;
        }
        $total = 0.0;
        $cur   = $combo->currency ? $combo->currency : ws_currency_symbol();
        foreach ( self::items( (int) $combo->id ) as $it ) {
            $price = ws_convert( (float) $it->sale_price, $it->product_currency, $cur );
            $total += $price * (float) $it->qty;
        }
        return round( $total, 2 );
    }

    /* ---------------- Stock derivado ---------------- */

    /**
     * Combo disponible en una ubicación: min sobre los componentes de
     * floor( stock(producto) / cantidad_necesaria ). 0 si falta alguno.
     */
    public static function stock( $combo_id, $location_id ) {
        global $wpdb;
        $combo_id   = (int) $combo_id;
        $location_id = (int) $location_id;
        if ( ! $combo_id || ! $location_id ) {
            return 0;
        }
        $items = self::items( $combo_id );
        if ( empty( $items ) ) {
            return 0;
        }
        $min = null;
        foreach ( $items as $it ) {
            $avail = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT qty FROM " . self::table( 'stock' ) . " WHERE product_id=%d AND location_id=%d",
                (int) $it->product_id, $location_id
            ) );
            $need = (float) $it->qty;
            if ( $need <= 0 ) {
                continue;
            }
            $count = (int) floor( $avail / $need );
            $min = ( null === $min ) ? $count : min( $min, $count );
        }
        return ( null === $min ) ? 0 : max( 0, $min );
    }

    /**
     * Expande N combos en sus componentes: array de array('product_id', 'qty').
     */
    public static function expand( $combo_id, $count ) {
        $combo_id = (int) $combo_id;
        $count    = (float) $count;
        if ( ! $combo_id || $count <= 0 ) {
            return array();
        }
        $out = array();
        foreach ( self::items( $combo_id ) as $it ) {
            $out[] = array(
                'product_id' => (int) $it->product_id,
                'qty'        => round( (float) $it->qty * $count, 2 ),
            );
        }
        return $out;
    }

    /* ---------------- Escritura ---------------- */

    public static function save( $data, $id = 0 ) {
        global $wpdb;
        $id   = (int) $id;
        $name = sanitize_text_field( $data['name'] ?? '' );
        if ( '' === $name ) {
            return new WP_Error( 'name', __( 'El nombre del combo es obligatorio.', 'workshop' ) );
        }
        $price_mode = ( 'manual' === ( $data['price_mode'] ?? '' ) ) ? 'manual' : 'auto';
        $price      = (float) ( $data['price'] ?? 0 );
        if ( 'manual' === $price_mode && $price < 0 ) {
            return new WP_Error( 'price', __( 'El precio del combo no puede ser negativo.', 'workshop' ) );
        }
        $currency = sanitize_text_field( $data['currency'] ?? ws_currency_symbol() );
        $active   = isset( $data['active'] ) ? (int) filter_var( $data['active'], FILTER_VALIDATE_BOOLEAN ) : 1;

        // Componentes: [{product_id, qty}] con al menos uno válido.
        $items = array();
        if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
            foreach ( $data['items'] as $raw ) {
                if ( ! is_array( $raw ) ) {
                    continue;
                }
                $pid = (int) ( $raw['product_id'] ?? 0 );
                $qty = (float) ( $raw['qty'] ?? 0 );
                if ( $pid && $qty > 0 ) {
                    $items[] = array( 'product_id' => $pid, 'qty' => round( $qty, 2 ) );
                }
            }
        }
        if ( empty( $items ) ) {
            return new WP_Error( 'items', __( 'El combo necesita al menos un producto con cantidad.', 'workshop' ) );
        }

        $fields = array(
            'name'       => $name,
            'photo'      => esc_url_raw( $data['photo'] ?? '' ),
            'price_mode' => $price_mode,
            'price'      => round( $price, 2 ),
            'currency'   => $currency,
            'active'     => $active,
        );

        $wpdb->query( 'START TRANSACTION' );
        if ( $id ) {
            $wpdb->update( self::table( 'combos' ), $fields, array( 'id' => $id ) );
        } else {
            $wpdb->insert( self::table( 'combos' ), $fields );
            $id = (int) $wpdb->insert_id;
        }
        $wpdb->delete( self::table( 'combo_items' ), array( 'combo_id' => $id ) );
        foreach ( $items as $it ) {
            $wpdb->insert( self::table( 'combo_items' ), array(
                'combo_id'   => $id,
                'product_id' => $it['product_id'],
                'qty'        => $it['qty'],
            ) );
        }
        $wpdb->query( 'COMMIT' );
        return $id;
    }

    public static function delete( $id ) {
        global $wpdb;
        $id = (int) $id;
        $wpdb->delete( self::table( 'combo_items' ), array( 'combo_id' => $id ) );
        $wpdb->delete( self::table( 'combos' ), array( 'id' => $id ) );
    }

    public static function set_active( $id, $active ) {
        global $wpdb;
        return $wpdb->update( self::table( 'combos' ), array( 'active' => $active ? 1 : 0 ), array( 'id' => (int) $id ) );
    }

    /* ---------------- Stock: venta / entrada / transferencia ---------------- */

    /**
     * Descuenta los componentes de N combos en una ubicación (transacción
     * abierta). $type: 'salida' (venta) o el tipo de movimiento deseado.
     */
    public static function decrease_in_tx( $combo_id, $location_id, $count, $type = 'salida', $ref = '', $note = '', $user_id = 0 ) {
        foreach ( self::expand( $combo_id, $count ) as $it ) {
            $res = WS_Stock::decrease_in_tx( $it['product_id'], $location_id, $it['qty'], $type, $ref, $note, $user_id );
            if ( is_wp_error( $res ) ) {
                return $res;
            }
        }
        return true;
    }

    /**
     * Aumenta los componentes de N combos en una ubicación (transacción
     * abierta). Para entradas de combos: "si es una entrada no hay problema".
     */
    public static function increase_in_tx( $combo_id, $location_id, $count, $type = 'entrada', $ref = '', $note = '', $user_id = 0 ) {
        foreach ( self::expand( $combo_id, $count ) as $it ) {
            $res = WS_Stock::increase_in_tx( $it['product_id'], $location_id, $it['qty'], $type, $ref, $note, $user_id );
            if ( is_wp_error( $res ) ) {
                return $res;
            }
        }
        return true;
    }

    /**
     * Transfiere N combos de una ubicación a otra: mueve CADA componente
     * (qty × N) de forma atómica. Si algún componente no tiene stock en el
     * origen, se revierte TODO y se avisa cuál falta.
     */
    public static function transfer( $combo_id, $from_location, $to_location, $count, $ref = '', $note = '', $user_id = 0 ) {
        global $wpdb;
        $combo_id = (int) $combo_id;
        $from     = (int) $from_location;
        $to       = (int) $to_location;
        $count    = (float) $count;
        if ( ! $combo_id || ! $from || ! $to || $count <= 0 ) {
            return new WP_Error( 'invalid', __( 'Datos de transferencia de combo inválidos.', 'workshop' ) );
        }
        if ( $from === $to ) {
            return new WP_Error( 'same', __( 'Origen y destino deben ser distintos.', 'workshop' ) );
        }
        $combo = self::get( $combo_id );
        if ( ! $combo ) {
            return new WP_Error( 'not_found', __( 'Combo no encontrado.', 'workshop' ) );
        }
        $items = self::expand( $combo_id, $count );
        if ( empty( $items ) ) {
            return new WP_Error( 'items', __( 'El combo no tiene productos.', 'workshop' ) );
        }
        $base_note = $note ? $note . ' — ' : '';
        $wpdb->query( 'START TRANSACTION' );
        foreach ( $items as $it ) {
            $pname = $wpdb->get_var( $wpdb->prepare(
                "SELECT name FROM " . self::table( 'products' ) . " WHERE id=%d", $it['product_id']
            ) );
            $res = WS_Stock::transfer_in_tx(
                $it['product_id'], $from, $to, $it['qty'], $ref,
                $base_note . sprintf( __( 'Combo: %s', 'workshop' ), $combo->name ), $user_id
            );
            if ( is_wp_error( $res ) ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'insufficient', sprintf(
                    /* translators: 1: nombre del componente, 2: cantidad */
                    __( 'Stock insuficiente para el combo: falta %1$s (%2$s uds.) en el origen.', 'workshop' ),
                    $pname ? $pname : '#' . $it['product_id'],
                    number_format_i18n( $it['qty'], 2 )
                ) );
            }
        }
        $wpdb->query( 'COMMIT' );
        return true;
    }

    /* ---------------- Catálogo para la tienda / POS ---------------- */

    /**
     * Combos activos como filas de catálogo (para la tienda y el POS), con
     * precio y stock DERIVADO en la ubicación. $location_id obligatorio.
     * Cada fila: id (NEGATIVO = -combo_id, espacio distinto a productos),
     * name, photo, price, currency, qty (stock derivado), is_combo=1, items.
     */
    public static function catalog_rows( $location_id ) {
        $location_id = (int) $location_id;
        if ( ! $location_id ) {
            return array();
        }
        $rows = array();
        foreach ( self::all( array( 'active' => 1 ) ) as $c ) {
            $rows[] = array(
                'id'       => -1 * (int) $c->id,
                'combo_id' => (int) $c->id,
                'name'     => $c->name,
                'photo'    => $c->photo,
                'price'    => self::price( $c ),
                'currency' => $c->currency,
                'qty'      => self::stock( (int) $c->id, $location_id ),
                'is_combo' => 1,
                'items'    => array_map( function ( $it ) {
                    return array(
                        'product_id' => (int) $it->product_id,
                        'name'       => $it->product_name,
                        'qty'        => (float) $it->qty,
                    );
                }, self::items( (int) $c->id ) ),
            );
        }
        return $rows;
    }
}
