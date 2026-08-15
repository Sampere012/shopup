<?php
/**
 * Módulo de stock: movimientos atómicos e historial.
 *
 * Tipos de movimiento:
 * - entrada   (aumenta stock)
 * - salida    (disminuye stock)
 * - baja      (disminuye stock, p.ej. merma)
 * - transferencia (origen->destino)
 * - pedido    (disminuye stock cuando se acepta un pedido)
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

class WS_Stock {

    protected static function table( $t ) {
        return ws_table_name( $t );
    }

    /**
     * Aumenta stock de forma atómica.
     */
    public static function increase( $product_id, $location_id, $qty, $type, $ref = '', $note = '', $user_id = 0, $skip_linked = false ) {
        global $wpdb;
        $product_id  = (int) $product_id;
        $location_id = (int) $location_id;
        $qty         = (float) $qty;
        if ( ! $product_id || ! $location_id || $qty <= 0 ) {
            return new WP_Error( 'invalid', __( 'Datos de movimiento inválidos.', 'workshop' ) );
        }

        $wpdb->query( 'START TRANSACTION' );
        self::_upsert_stock( $product_id, $location_id, $qty, '+', null );
        self::_apply_fraction_links( $product_id, $location_id, $qty, '+' );
        self::_log( $type, $product_id, $location_id, 0, $qty, $ref, $note, $user_id );
        if ( ! $skip_linked ) {
            self::_propagate_linked( $product_id, $location_id, $qty, '+', $type, $ref, $note, $user_id );
        }
        $wpdb->query( 'COMMIT' );
        return true;
    }

    /**
     * Disminuye stock de forma atómica (no permite negativo).
     */
    public static function decrease( $product_id, $location_id, $qty, $type, $ref = '', $note = '', $user_id = 0, $skip_linked = false ) {
        global $wpdb;
        $product_id  = (int) $product_id;
        $location_id = (int) $location_id;
        $qty         = (float) $qty;
        if ( ! $product_id || ! $location_id || $qty <= 0 ) {
            return new WP_Error( 'invalid', __( 'Datos de movimiento inválidos.', 'workshop' ) );
        }

        $wpdb->query( 'START TRANSACTION' );
        $updated = self::_decrease_locked( $product_id, $location_id, $qty );
        if ( ! $updated ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'insufficient', __( 'Stock insuficiente para el movimiento.', 'workshop' ) );
        }
        if ( ! self::_apply_fraction_links( $product_id, $location_id, $qty, '-' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'insufficient', __( 'Stock insuficiente para el movimiento (unidades relacionadas).', 'workshop' ) );
        }
        self::_log( $type, $product_id, $location_id, 0, $qty, $ref, $note, $user_id );
        if ( ! $skip_linked ) {
            self::_propagate_linked( $product_id, $location_id, $qty, '-', $type, $ref, $note, $user_id );
        }
        $wpdb->query( 'COMMIT' );
        return true;
    }

    /**
     * Disminuye stock asumiendo que la transacción ya está abierta.
     * Devuelve true si pudo decrementar. El caller gestiona commit/rollback.
     */
    public static function decrease_in_tx( $product_id, $location_id, $qty, $type, $ref = '', $note = '', $user_id = 0, $combo_id = 0 ) {
        $product_id  = (int) $product_id;
        $location_id = (int) $location_id;
        $qty         = (float) $qty;
        if ( ! $product_id || ! $location_id || $qty <= 0 ) {
            return new WP_Error( 'invalid', __( 'Datos de movimiento inválidos.', 'workshop' ) );
        }
        $updated = self::_decrease_locked( $product_id, $location_id, $qty );
        if ( ! $updated ) {
            return new WP_Error( 'insufficient', __( 'Stock insuficiente para el movimiento.', 'workshop' ) );
        }
        if ( ! self::_apply_fraction_links( $product_id, $location_id, $qty, '-' ) ) {
            return new WP_Error( 'insufficient', __( 'Stock insuficiente para el movimiento (unidades relacionadas).', 'workshop' ) );
        }
        self::_log( $type, $product_id, $location_id, 0, $qty, $ref, $note, $user_id, $combo_id );
        self::_propagate_linked( $product_id, $location_id, $qty, '-', $type, $ref, $note, $user_id, $combo_id );
        return true;
    }

    /**
     * Descuenta stock SIN fallar si no alcanza: descuenta lo disponible (0 si
     * no hay nada) y devuelve la cantidad realmente descontada. Lo usa la
     * sincronización de ventas offline, donde una discrepancia de stock no
     * debe tumbar la venta: se anota la diferencia y se avisa al negocio.
     * Asume que la transacción ya está abierta.
     */
    public static function decrease_partial_in_tx( $product_id, $location_id, $qty, $type = 'salida', $ref = '', $note = '', $user_id = 0, $combo_id = 0 ) {
        global $wpdb;
        $product_id  = (int) $product_id;
        $location_id = (int) $location_id;
        $qty         = (float) $qty;
        if ( ! $product_id || ! $location_id || $qty <= 0 ) {
            return 0;
        }
        $current = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT qty FROM " . self::table( 'stock' ) . " WHERE product_id=%d AND location_id=%d",
            $product_id, $location_id
        ) );
        if ( $current <= 0 ) {
            return 0;
        }
        $deducted = min( $qty, $current );
        if ( ! self::_decrease_locked( $product_id, $location_id, $deducted ) ) {
            return 0;
        }
        // Enlaces de fraccionamiento: aplica sobre lo realmente descontado.
        // Si la unidad relacionada (padre o hijo) está agotada, se devuelve la
        // cantidad en NEGATIVO para que el llamador lo reporte como
        // discrepancia (el inventario quedó desbalanceado entre padre/hijo).
        $frac_ok = self::_apply_fraction_links( $product_id, $location_id, $deducted, '-' );
        self::_log( $type, $product_id, $location_id, 0, $deducted, $ref, $note, $user_id, $combo_id );
        self::_propagate_linked( $product_id, $location_id, $deducted, '-', $type, $ref, $note, $user_id, $combo_id );
        return $frac_ok ? $deducted : ( -1 * $deducted );
    }

    /**
     * Aumenta stock asumiendo que la transacción ya está abierta.
     */
    public static function increase_in_tx( $product_id, $location_id, $qty, $type, $ref = '', $note = '', $user_id = 0, $combo_id = 0 ) {
        $product_id  = (int) $product_id;
        $location_id = (int) $location_id;
        $qty         = (float) $qty;
        if ( ! $product_id || ! $location_id || $qty <= 0 ) {
            return new WP_Error( 'invalid', __( 'Datos de movimiento inválidos.', 'workshop' ) );
        }
        self::_upsert_stock( $product_id, $location_id, $qty, '+', null );
        self::_apply_fraction_links( $product_id, $location_id, $qty, '+' );
        self::_log( $type, $product_id, $location_id, 0, $qty, $ref, $note, $user_id, $combo_id );
        self::_propagate_linked( $product_id, $location_id, $qty, '+', $type, $ref, $note, $user_id, $combo_id );
        return true;
    }

    /**
     * Transferencia entre ubicaciones (atómica).
     */
    public static function transfer( $product_id, $from_location, $to_location, $qty, $ref = '', $note = '', $user_id = 0 ) {
        global $wpdb;
        $product_id    = (int) $product_id;
        $from_location = (int) $from_location;
        $to_location   = (int) $to_location;
        $qty           = (float) $qty;
        if ( ! $product_id || ! $from_location || ! $to_location || $qty <= 0 ) {
            return new WP_Error( 'invalid', __( 'Datos de transferencia inválidos.', 'workshop' ) );
        }
        if ( $from_location === $to_location ) {
            return new WP_Error( 'same', __( 'Origen y destino deben ser distintos.', 'workshop' ) );
        }

        $wpdb->query( 'START TRANSACTION' );
        $updated = self::_decrease_locked( $product_id, $from_location, $qty );
        if ( ! $updated ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'insufficient', __( 'Stock insuficiente para la transferencia.', 'workshop' ) );
        }
        if ( ! self::_apply_fraction_links( $product_id, $from_location, $qty, '-' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'insufficient', __( 'Stock insuficiente para la transferencia (unidades relacionadas).', 'workshop' ) );
        }
        self::_upsert_stock( $product_id, $to_location, $qty, '+', null );
        self::_apply_fraction_links( $product_id, $to_location, $qty, '+' );
        self::_log( 'transferencia', $product_id, $from_location, $to_location, $qty, $ref, $note, $user_id );
        $wpdb->query( 'COMMIT' );
        return true;
    }

    /**
     * Transferencia de UN producto asumiendo que la transacción ya está
     * abierta (la usa la transferencia de COMBOS para mover los componentes
     * de forma atómica). Registra el movimiento tipo 'transferencia'.
     */
    public static function transfer_in_tx( $product_id, $from_location, $to_location, $qty, $ref = '', $note = '', $user_id = 0, $combo_id = 0 ) {
        global $wpdb;
        $product_id    = (int) $product_id;
        $from_location = (int) $from_location;
        $to_location   = (int) $to_location;
        $qty           = (float) $qty;
        if ( ! $product_id || ! $from_location || ! $to_location || $qty <= 0 ) {
            return new WP_Error( 'invalid', __( 'Datos de transferencia inválidos.', 'workshop' ) );
        }
        if ( $from_location === $to_location ) {
            return new WP_Error( 'same', __( 'Origen y destino deben ser distintos.', 'workshop' ) );
        }
        $updated = self::_decrease_locked( $product_id, $from_location, $qty );
        if ( ! $updated ) {
            return new WP_Error( 'insufficient', __( 'Stock insuficiente para la transferencia.', 'workshop' ) );
        }
        if ( ! self::_apply_fraction_links( $product_id, $from_location, $qty, '-' ) ) {
            return new WP_Error( 'insufficient', __( 'Stock insuficiente para la transferencia (unidades relacionadas).', 'workshop' ) );
        }
        self::_upsert_stock( $product_id, $to_location, $qty, '+', null );
        self::_apply_fraction_links( $product_id, $to_location, $qty, '+' );
        self::_log( 'transferencia', $product_id, $from_location, $to_location, $qty, $ref, $note, $user_id, $combo_id );
        return true;
    }

    /**
     * Movimiento múltiple (entrada/salida/baja o tipo personalizado) atómico.
     * $items = array de array( 'product_id' => int, 'qty' => float ).
     * $direction ('entrada'|'salida') solo se usa cuando $type es un tipo
     * personalizado (p.ej. "merma", "ajuste"): decide si aumenta o disminuye.
     * Para entrada/salida/baja la dirección se deduce del propio tipo.
     */
    public static function batch_move( $type, $location_id, $items, $ref = '', $note = '', $user_id = 0, $direction = '' ) {
        global $wpdb;
        $location_id = (int) $location_id;
        $type        = mb_substr( trim( (string) $type ), 0, 30 );
        if ( in_array( $type, array( 'entrada', 'salida', 'baja' ), true ) ) {
            $direction = ( 'entrada' === $type ) ? 'entrada' : 'salida';
        } elseif ( '' === $type ) {
            $type = ( 'entrada' === $direction ) ? 'entrada' : 'salida';
        }
        if ( ! in_array( $direction, array( 'entrada', 'salida' ), true ) || ! $location_id || empty( $items ) ) {
            return new WP_Error( 'invalid', __( 'Datos de movimiento inválidos.', 'workshop' ) );
        }
        $wpdb->query( 'START TRANSACTION' );
        $count = 0;
        foreach ( $items as $it ) {
            $pid = (int) ( $it['product_id'] ?? 0 );
            $cid = (int) ( $it['combo_id'] ?? 0 );
            $qty = (float) ( $it['qty'] ?? 0 );
            if ( ( ! $pid && ! $cid ) || $qty <= 0 ) {
                continue;
            }
            // Combo: se mueven sus COMPONENTES (cada producto × cantidad).
            if ( $cid > 0 ) {
                $result = ( 'entrada' === $direction )
                    ? WS_Combos::increase_in_tx( $cid, $location_id, $qty, $type, $ref, $note, $user_id )
                    : WS_Combos::decrease_in_tx( $cid, $location_id, $qty, $type, $ref, $note, $user_id );
            } else {
                $result = ( 'entrada' === $direction )
                    ? self::increase_in_tx( $pid, $location_id, $qty, $type, $ref, $note, $user_id )
                    : self::decrease_in_tx( $pid, $location_id, $qty, $type, $ref, $note, $user_id );
            }
            if ( is_wp_error( $result ) ) {
                $wpdb->query( 'ROLLBACK' );
                return $result;
            }
            $count++;
        }
        if ( ! $count ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'invalid', __( 'Datos de movimiento inválidos.', 'workshop' ) );
        }
        $wpdb->query( 'COMMIT' );
        return $count;
    }

    /**
     * Transferencia múltiple entre ubicaciones (atómica).
     * $items = array de array( 'product_id' => int, 'qty' => float ).
     */
    public static function batch_transfer( $from_location, $to_location, $items, $ref = '', $note = '', $user_id = 0 ) {
        global $wpdb;
        $from_location = (int) $from_location;
        $to_location   = (int) $to_location;
        if ( ! $from_location || ! $to_location || $from_location === $to_location || empty( $items ) ) {
            return new WP_Error( 'invalid', __( 'Datos de transferencia inválidos.', 'workshop' ) );
        }
        $wpdb->query( 'START TRANSACTION' );
        $count = 0;
        foreach ( $items as $it ) {
            $pid = (int) ( $it['product_id'] ?? 0 );
            $cid = (int) ( $it['combo_id'] ?? 0 );
            $qty = (float) ( $it['qty'] ?? 0 );
            if ( ( ! $pid && ! $cid ) || $qty <= 0 ) {
                continue;
            }
            // Combo: transferencia de sus componentes (cada uno × cantidad).
            if ( $cid > 0 ) {
                $updated = WS_Combos::transfer( $cid, $from_location, $to_location, $qty, $ref, $note, $user_id );
                if ( is_wp_error( $updated ) ) {
                    $wpdb->query( 'ROLLBACK' );
                    return $updated;
                }
                $count++;
                continue;
            }
            $updated = self::_decrease_locked( $pid, $from_location, $qty );
            if ( ! $updated ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'insufficient', sprintf( __( 'Stock insuficiente para el producto #%d.', 'workshop' ), $pid ) );
            }
            if ( ! self::_apply_fraction_links( $pid, $from_location, $qty, '-' ) ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'insufficient', sprintf( __( 'Stock insuficiente para el producto #%d (unidades relacionadas).', 'workshop' ), $pid ) );
            }
            self::_upsert_stock( $pid, $to_location, $qty, '+', null );
            self::_apply_fraction_links( $pid, $to_location, $qty, '+' );
            self::_log( 'transferencia', $pid, $from_location, $to_location, $qty, $ref, $note, $user_id );
            $count++;
        }
        $wpdb->query( 'COMMIT' );
        return $count;
    }

    /**
     * UPDATE atómico que evita stock negativo.
     * El UPDATE en una sola sentencia con la condición qty >= X es atómico
     * dentro de la transacción.
     */
    protected static function _decrease_locked( $product_id, $location_id, $qty ) {
        global $wpdb;
        $qty_sql = number_format( (float) $qty, 2, '.', '' );
        return $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table( 'stock' ) . " SET qty = qty - %s WHERE product_id=%d AND location_id=%d AND qty >= %s",
            $qty_sql, $product_id, $location_id, $qty_sql
        ) );
    }

    protected static function _upsert_stock( $product_id, $location_id, $qty, $op, $clause ) {
        global $wpdb;
        $table  = self::table( 'stock' );
        $qty_sql = number_format( (float) $qty, 2, '.', '' );
        if ( '+' === $op ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$table} (product_id, location_id, qty) VALUES (%d, %d, %s)
                 ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)",
                $product_id, $location_id, $qty_sql
            ) );
        }
    }

    protected static function _log( $type, $product_id, $location_id, $dest_location_id, $qty, $ref, $note, $user_id, $combo_id = 0 ) {
        global $wpdb;
        $wpdb->insert( self::table( 'movements' ), array(
            'type'             => $type,
            'product_id'       => $product_id,
            'combo_id'         => (int) $combo_id,
            'location_id'      => $location_id,
            'dest_location_id' => (int) $dest_location_id,
            'qty'              => $qty,
            'reference'        => sanitize_text_field( $ref ),
            'note'             => sanitize_text_field( $note ),
            'user_id'          => $user_id ? $user_id : get_current_user_id(),
        ) );
    }

    /* ---------------- Stock compartido entre ubicaciones ---------------- */

    /**
     * IDs de las ubicaciones que COMPARTEN STOCK con $location_id, de forma
     * JERÁRQUICA (línea familiar), sin incluir la propia.
     *
     * Un movimiento en una ubicación se aplica a toda su línea: TODOS sus
     * padres (hasta la raíz) y TODOS sus hijos/sub-ubicaciones (hasta las
     * hojas). Los HERMANOS (otras ramas bajo el mismo padre) NO se comparten:
     *   - n1->n3 y n2->n3 (estrella): lo que entra en n1 llega a n3 pero no a
     *     n2; lo que entra en n3 (raíz) llega a n1 y n2.
     *   - n1->n2->n3 (cadena): lo que entra en cualquiera llega a toda la
     *     línea (cada nodo está en la línea de los demás).
     */
    public static function linked_location_ids( $location_id ) {
        static $cache = array();
        $location_id = (int) $location_id;
        if ( ! $location_id ) {
            return array();
        }
        if ( array_key_exists( $location_id, $cache ) ) {
            return $cache[ $location_id ];
        }
        $tree = self::_link_tree();
        $out  = array();
        $seen = array( $location_id => true );
        // Padres: cadena hacia la raíz.
        $cur = $location_id;
        while ( isset( $tree['parent'][ $cur ] ) && ! isset( $seen[ $tree['parent'][ $cur ] ] ) ) {
            $cur = $tree['parent'][ $cur ];
            $seen[ $cur ] = true;
            $out[] = $cur;
        }
        // Hijos y descendientes (BFS por las ramas propias).
        $queue = array( $location_id );
        while ( $queue ) {
            $cur = array_shift( $queue );
            foreach ( (array) ( $tree['children'][ $cur ] ?? array() ) as $n ) {
                if ( isset( $seen[ $n ] ) ) {
                    continue;
                }
                $seen[ $n ] = true;
                $out[]     = $n;
                $queue[]   = $n;
            }
        }
        $cache[ $location_id ] = $out;
        return $out;
    }

    /**
     * Árbol DIRIGIDO de conexiones (stock compartido), cacheado por petición.
     *
     * Cada fila (location_a, location_b) significa "b es la SUPERIOR de a"
     * (b abastece a a). Se lee UNA vez y lo reutilizan la propagación por
     * línea y el cálculo del stock del grupo en los listados.
     *
     * Devuelve array( 'parent' => child => parent, 'children' => parent => [hijos] ).
     */
    protected static function _link_tree() {
        global $wpdb;
        static $tree = null;
        if ( null === $tree ) {
            $tree = array( 'parent' => array(), 'children' => array() );
            foreach ( $wpdb->get_results( "SELECT location_a, location_b FROM " . self::table( 'location_links' ) ) as $r ) {
                $a = (int) $r->location_a;
                $b = (int) $r->location_b;
                $tree['parent'][ $a ] = $b;
                $tree['children'][ $b ][] = $a;
            }
        }
        return $tree;
    }

    /**
     * Stock del GRUPO por producto para filas de stock.
     *
     * Para cada fila (producto + ubicación) calcula el total sumando el stock
     * del producto en TODAS las ubicaciones de SU LÍNEA (padres + hijos,
     * incluida la propia — los hermanos no cuentan), más el desglose por
     * ubicación para el tooltip.
     * Devuelve array keyed por "{product_id}:{location_id}" con
     * array( 'total' => float, 'parts' => array de {id, name, qty} ).
     */
    public static function stock_group_info( $rows ) {
        if ( empty( $rows ) ) {
            return array();
        }
        global $wpdb;

        // Nombres de ubicación (una sola lectura, son pocas).
        $loc_names = array();
        foreach ( $wpdb->get_results( "SELECT id, name FROM " . self::table( 'locations' ) ) as $l ) {
            $loc_names[ (int) $l->id ] = $l->name;
        }

        // Línea familiar (padres + hijos) por ubicación, cacheada.
        $components = array();
        $component  = function ( $lid ) use ( &$components ) {
            if ( isset( $components[ $lid ] ) ) {
                return $components[ $lid ];
            }
            $components[ $lid ] = array_merge( array( $lid ), self::linked_location_ids( $lid ) );
            return $components[ $lid ];
        };

        // Cantidad por ubicación de los productos implicados: UNA query con
        // todos los ids (índice unique product_location).
        $pids = array();
        foreach ( $rows as $r ) {
            $pid = (int) $r->product_id;
            if ( $pid ) {
                $pids[ $pid ] = true;
            }
        }
        $pids = array_keys( $pids );
        $qty_by_pid = array();
        if ( $pids ) {
            $ph = implode( ',', array_fill( 0, count( $pids ), '%d' ) );
            foreach ( $wpdb->get_results( $wpdb->prepare(
                "SELECT product_id, location_id, qty FROM " . self::table( 'stock' ) . " WHERE product_id IN ({$ph})",
                ...$pids
            ) ) as $q ) {
                $qty_by_pid[ (int) $q->product_id ][ (int) $q->location_id ] = (float) $q->qty;
            }
        }

        $info = array();
        foreach ( $rows as $r ) {
            $pid = (int) $r->product_id;
            $lid = (int) $r->location_id;
            if ( ! $pid || ! $lid ) {
                continue;
            }
            $component_ids = $component( $lid );
            $total         = 0.0;
            $parts         = array();
            foreach ( $component_ids as $gid ) {
                $gqty = (float) ( $qty_by_pid[ $pid ][ $gid ] ?? 0 );
                $total += $gqty;
                $parts[] = array(
                    'id'   => (int) $gid,
                    'name' => (string) ( $loc_names[ $gid ] ?? '' ),
                    'qty'  => $gqty,
                );
            }
            $info[ $pid . ':' . $lid ] = array( 'total' => round( $total, 2 ), 'parts' => $parts );
        }
        return $info;
    }

    /**
     * Conteo de filas de stock BAJO usando el STOCK DEL GRUPO (línea de
     * stock compartido): una fila (producto + ubicación) cuenta como baja
     * cuando el total de TODAS las ubicaciones de su línea (padres + hijos,
     * no hermanos) es menor o igual al mínimo del producto — no el stock de
     * cada ubicación.
     *
     * Pre-filtra en SQL por stock bajo por ubicación (el grupo bajo implica
     * que todas sus ubicaciones están bajas, así que es un superconjunto) y
     * aplica el criterio definitivo por grupo en PHP.
     *
     * @param int[] $location_ids Filtro de ubicaciones (vacío = todas).
     * @param bool  $exclude_empty No contar grupos agotados (total <= 0): con
     *                             true, un producto con mínimo 0 tampoco cuenta
     *                             (no puede cumplir total > 0 y total <= 0).
     * @param bool  $min_positive  Solo contar productos con mínimo > 0.
     * @return int
     */
    public static function count_low_stock_group_rows( $location_ids = array(), $exclude_empty = false, $min_positive = false ) {
        $args = array( 'low_stock' => 1 );
        if ( $location_ids ) {
            $args['location_ids'] = array_values( array_filter( array_map( 'intval', $location_ids ) ) );
        }
        $rows = self::stock_rows( $args );
        if ( empty( $rows ) ) {
            return 0;
        }
        $group = self::stock_group_info( $rows );
        $count = 0;
        foreach ( $rows as $r ) {
            if ( $min_positive && (float) $r->min_stock <= 0 ) {
                continue;
            }
            $g = $group[ $r->product_id . ':' . $r->location_id ] ?? null;
            $total = $g ? (float) $g['total'] : (float) $r->qty;
            if ( $exclude_empty && $total <= 0 ) {
                continue;
            }
            if ( $total <= (float) $r->min_stock ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Disminuye stock sin fallar si no alcanza: descuenta solo lo disponible
     * (nunca negativo) y devuelve la cantidad descontada. Lo usan las
     * ubicaciones CONECTADAS en el stock compartido: la venta no debe fallar
     * porque una ubicación vinculada esté agotada.
     */
    protected static function _decrease_capped( $product_id, $location_id, $qty ) {
        global $wpdb;
        $product_id  = (int) $product_id;
        $location_id = (int) $location_id;
        $qty         = (float) $qty;
        if ( ! $product_id || ! $location_id || $qty <= 0 ) {
            return 0;
        }
        $current = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT qty FROM " . self::table( 'stock' ) . " WHERE product_id=%d AND location_id=%d",
            $product_id, $location_id
        ) );
        if ( $current <= 0 ) {
            return 0;
        }
        $deduct     = min( $qty, $current );
        $deduct_sql = number_format( $deduct, 2, '.', '' );
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table( 'stock' ) . " SET qty = qty - %s WHERE product_id=%d AND location_id=%d AND qty >= %s",
            $deduct_sql, $product_id, $location_id, $deduct_sql
        ) );
        return $deduct;
    }

    /**
     * Propaga un movimiento (entrada '+') o salida '-') a las ubicaciones de
     * LA LÍNEA por stock compartido: lo que entra/sale en una ubicación se
     * aplica a todos sus padres (hasta la raíz) y a todos sus hijos (hasta
     * las hojas). Los hermanos NO se ven afectados.
     *
     * - Entrada: aumenta el stock completo en cada ubicación de la línea.
     * - Salida:  descuenta lo disponible en cada una (nunca negativo) y anota
     *            en el movimiento cuánto faltó si no alcanzó.
     *
     * Asume que la transacción ya está abierta. Nunca falla: el guard real
     * de stock lo aplica la ubicación de origen; las vinculadas son un espejo.
     */
    protected static function _propagate_linked( $product_id, $location_id, $qty, $op, $type, $ref = '', $note = '', $user_id = 0, $combo_id = 0 ) {
        $linked = self::linked_location_ids( $location_id );
        if ( ! $linked || $qty <= 0 ) {
            return;
        }
        global $wpdb;
        // El stock compartido propaga los movimientos a TODA LA LÍNEA de la
        // ubicación (padres + hijos; nunca hermanos):
        // - ENTRADAS ('+'): aumentan el stock de la línea entera y crean la
        //   fila de stock en la vinculada si no existe.
        // - SALIDAS/ventas/bajas ('-'): descuentan en cada vinculada lo que
        //   tenga disponible (0 si no hay fila), y el movimiento queda
        //   registrado en todas con la nota del faltante cuando no alcanza.
        foreach ( $linked as $lid ) {
            if ( '+' === $op ) {
                self::_upsert_stock( $product_id, $lid, $qty, '+', null );
                self::_apply_fraction_links( $product_id, $lid, $qty, '+' );
                self::_log( $type, $product_id, $lid, 0, $qty, $ref, self::_linked_note( $location_id, $note ), $user_id, $combo_id );
            } else {
                $deducted = self::_decrease_capped( $product_id, $lid, $qty );
                if ( $deducted > 0 ) {
                    self::_apply_fraction_links( $product_id, $lid, $deducted, '-' );
                }
                $l_note = self::_linked_note( $location_id, $note );
                $missing = round( $qty - $deducted, 2 );
                if ( $missing > 0 ) {
                    $l_note .= ' — ' . sprintf( __( 'faltaron %s en la vinculada', 'workshop' ), number_format( $missing, 2 ) );
                }
                self::_log( $type, $product_id, $lid, 0, $deducted, $ref, $l_note, $user_id, $combo_id );
            }
        }
    }

    /**
     * Nota de un movimiento propagado por stock compartido: indica que se
     * registró por estar conectada a la ubicación de origen.
     */
    protected static function _linked_note( $origin_location_id, $note = '' ) {
        $loc  = WS_CRUD::get_location( $origin_location_id );
        $base = __( 'Stock compartido (vinculada)', 'workshop' );
        if ( $loc && ! empty( $loc->name ) ) {
            $base .= ' — ' . $loc->name;
        }
        if ( '' !== trim( (string) $note ) ) {
            $base .= ' | ' . $note;
        }
        return mb_substr( $base, 0, 255 );
    }

    /* ---------------- Fraccionamiento de productos ---------------- */

    /**
     * Datos de fraccionamiento de un producto.
     * Si es hijo: array( 'parent_id' => int, 'qty' => float ).
     * Si es padre: array( 'children' => array de ids ) (sin el mismo).
     * null si el producto no participa en fraccionamiento.
     */
    public static function fraction_info( $product_id ) {
        global $wpdb;
        static $cache = array();
        $product_id = (int) $product_id;
        if ( ! $product_id ) {
            return null;
        }
        if ( array_key_exists( $product_id, $cache ) ) {
            return $cache[ $product_id ];
        }
        $p = $wpdb->get_row( $wpdb->prepare(
            "SELECT fraction_parent, fraction_qty FROM " . self::table( 'products' ) . " WHERE id=%d", $product_id
        ) );
        if ( ! $p ) {
            $cache[ $product_id ] = null;
            return null;
        }
        if ( $p->fraction_parent && (float) $p->fraction_qty > 0 ) {
            $cache[ $product_id ] = array( 'parent_id' => (int) $p->fraction_parent, 'qty' => (float) $p->fraction_qty );
            return $cache[ $product_id ];
        }
        $children = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM " . self::table( 'products' ) . " WHERE fraction_parent=%d AND id<>%d AND fraction_qty>0",
            $product_id, $product_id
        ) ) );
        $cache[ $product_id ] = $children ? array( 'children' => $children ) : null;
        return $cache[ $product_id ];
    }

    /**
     * Propaga un movimiento (entrada '+') o salida '-') a los productos
     * enlazados por fraccionamiento, en la misma ubicación.
     * 1 padre = N hijos -> mover el hijo X unidades mueve el padre X/N;
     * mover el padre X unidades mueve cada hijo X*N.
     * Devuelve false si el decremento enlazado no es posible (stock).
     */
    protected static function _apply_fraction_links( $product_id, $location_id, $qty, $op ) {
        $info = self::fraction_info( $product_id );
        if ( ! $info ) {
            return true;
        }
        $dir = ( '-' === $op ) ? '-' : '+';
        if ( isset( $info['parent_id'] ) ) {
            $linked = (float) $info['parent_id'];
            $factor = $info['qty'];
            $linked_qty = $qty / $factor;
            return ( '-' === $dir )
                ? (bool) self::_decrease_locked( $linked, $location_id, $linked_qty )
                : self::_upsert_stock( $linked, $location_id, $linked_qty, '+', null );
        }
        if ( isset( $info['children'] ) ) {
            $factor = self::_child_factor( $product_id, $info['children'] );
            foreach ( $info['children'] as $cid ) {
                $linked_qty = $qty * ( isset( $factor[ $cid ] ) ? $factor[ $cid ] : 1 );
                if ( '-' === $dir ) {
                    if ( ! self::_decrease_locked( $cid, $location_id, $linked_qty ) ) {
                        return false;
                    }
                } else {
                    self::_upsert_stock( $cid, $location_id, $linked_qty, '+', null );
                }
            }
            return true;
        }
        return true;
    }

    /**
     * Factor de conversión (hijos por unidad de padre) de cada hijo.
     */
    protected static function _child_factor( $parent_id, $children ) {
        global $wpdb;
        static $cache = array();
        if ( isset( $cache[ $parent_id ] ) ) {
            return $cache[ $parent_id ];
        }
        $cache[ $parent_id ] = array();
        if ( ! $children ) {
            return $cache[ $parent_id ];
        }
        $ph = implode( ',', array_fill( 0, count( $children ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, fraction_qty FROM " . self::table( 'products' ) . " WHERE id IN ({$ph})",
            ...$children
        ) );
        foreach ( $rows as $r ) {
            $cache[ $parent_id ][ (int) $r->id ] = (float) $r->fraction_qty;
        }
        return $cache[ $parent_id ];
    }

    /**
     * Convierte 1 unidad del producto padre en unidades del hijo fraccionado
     * (1 padre = $factor hijos) en la misma ubicación, de forma atómica.
     *
     * Se usa al enlazar un fraccionamiento (crear/editar un hijo): el stock
     * debe quedar completo (padre -1, hijo +factor) para que el hijo pueda
     * venderse o comprarse sin romper el inventario. Si el padre tiene menos
     * de 1 unidad en total, se convierte todo lo disponible: el padre queda
     * en 0 y el hijo recibe solo el remanente (stock restante * factor).
     *
     * Devuelve array con el resumen de conversión por ubicación, o WP_Error.
     */
    public static function convert_fraction( $parent_id, $child_id, $factor ) {
        global $wpdb;
        $parent_id = (int) $parent_id;
        $child_id  = (int) $child_id;
        $factor    = (float) $factor;
        if ( ! $parent_id || ! $child_id || $factor <= 0 ) {
            return new WP_Error( 'invalid', __( 'Datos de fraccionamiento inválidos.', 'workshop' ) );
        }

        $stock_table = self::table( 'stock' );
        $loc_table   = self::table( 'locations' );

        // Ubicaciones con stock del padre (mayor stock primero).
        $locs = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.location_id, s.qty, l.name AS location_name
             FROM {$stock_table} s
             LEFT JOIN {$loc_table} l ON l.id = s.location_id
             WHERE s.product_id = %d AND s.qty > 0
             ORDER BY s.qty DESC",
            $parent_id
        ) );
        if ( ! $locs ) {
            return array( 'attempted' => true, 'converted' => 0, 'locations' => array() );
        }

        $wpdb->query( 'START TRANSACTION' );
        $converted = array();
        $remaining = 1.0; // 1 unidad del padre (o el remanente si hay menos).
        $ok        = true;
        foreach ( $locs as $loc ) {
            if ( $remaining <= 0.0001 ) {
                break;
            }
            $loc_id = (int) $loc->location_id;
            $take   = min( $remaining, (float) $loc->qty );
            if ( $take <= 0.0001 ) {
                continue;
            }
            // UPDATE atómico con condición anti-negativo (misma técnica que
            // _decrease_locked): evita doble descuento ante concurrencia.
            $take_sql = number_format( $take, 4, '.', '' );
            $updated  = $wpdb->query( $wpdb->prepare(
                "UPDATE {$stock_table} SET qty = qty - %s WHERE product_id = %d AND location_id = %d AND qty >= %s",
                $take_sql, $parent_id, $loc_id, $take_sql
            ) );
            if ( ! $updated ) {
                $ok = false;
                break;
            }
            $child_qty = round( $take * $factor, 4 );
            if ( $child_qty > 0 ) {
                self::_upsert_stock( $child_id, $loc_id, $child_qty, '+', null );
            }
            self::_log( 'salida', $parent_id, $loc_id, 0, $take, 'Fraccionamiento', 'Conversión a producto fraccionado', get_current_user_id() );
            if ( $child_qty > 0 ) {
                self::_log( 'entrada', $child_id, $loc_id, 0, $child_qty, 'Fraccionamiento', 'Unidades generadas del producto madre', get_current_user_id() );
            }
            $converted[] = array(
                'location_id'   => $loc_id,
                'location_name' => $loc->location_name ?? '',
                'parent_qty'    => $take,
                'child_qty'     => $child_qty,
            );
            $remaining -= $take;
        }
        if ( ! $ok ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'concurrent', __( 'No se pudo convertir el stock. Inténtalo de nuevo.', 'workshop' ) );
        }
        $wpdb->query( 'COMMIT' );
        return array( 'attempted' => true, 'converted' => count( $converted ), 'locations' => $converted );
    }

    /**
     * Stock actual de un producto en una ubicación.
     */
    public static function qty( $product_id, $location_id ) {
        global $wpdb;
        return (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT qty FROM " . self::table( 'stock' ) . " WHERE product_id=%d AND location_id=%d", $product_id, $location_id
        ) );
    }

    /**
     * Movimientos con filtros y paginación (server-side).
     */
    public static function movements( $args = array() ) {
        global $wpdb;
        $where = self::movements_where( $args );
        $limit = isset( $args['limit'] ) ? (int) $args['limit'] : 50;
        $offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
        $sql = "SELECT m.*, p.name AS product_name, u.display_name AS user_name,
                c.name AS combo_name,
                l1.name AS location_name, l2.name AS dest_name
                FROM " . self::table( 'movements' ) . " m
                LEFT JOIN " . self::table( 'products' ) . " p ON p.id = m.product_id
                LEFT JOIN " . self::table( 'combos' ) . " c ON c.id = m.combo_id
                LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id
                LEFT JOIN " . self::table( 'locations' ) . " l1 ON l1.id = m.location_id
                LEFT JOIN " . self::table( 'locations' ) . " l2 ON l2.id = m.dest_location_id
                WHERE " . implode( ' AND ', $where ) . " ORDER BY " . self::movements_orderby( $args['orderby'] ?? '', $args['order'] ?? 'DESC' ) . " LIMIT {$limit} OFFSET {$offset}";
        return $wpdb->get_results( $sql );
    }

    /**
     * Conteo de movimientos con los mismos filtros (para paginación).
     */
    public static function count_movements( $args = array() ) {
        global $wpdb;
        $where = self::movements_where( $args );
        $sql = "SELECT COUNT(*) FROM " . self::table( 'movements' ) . " m
                LEFT JOIN " . self::table( 'products' ) . " p ON p.id = m.product_id
                WHERE " . implode( ' AND ', $where );
        return (int) $wpdb->get_var( $sql );
    }

    /**
     * WHERE compartido de movimientos (listado y conteo).
     */
    protected static function movements_where( $args = array() ) {
        global $wpdb;
        $where = array( '1=1' );
        // Pestañas del historial: solo productos (combo_id = 0), solo combos
        // (combo_id > 0) o todo.
        if ( isset( $args['scope'] ) && 'combos' === $args['scope'] ) {
            $where[] = 'm.combo_id > 0';
        } elseif ( isset( $args['scope'] ) && 'products' === $args['scope'] ) {
            $where[] = 'm.combo_id = 0';
        }
        if ( ! empty( $args['type'] ) ) {
            $where[] = $wpdb->prepare( 'm.type = %s', $args['type'] );
        }
        if ( ! empty( $args['location_id'] ) ) {
            $where[] = $wpdb->prepare( '(m.location_id = %d OR m.dest_location_id = %d)', $args['location_id'], $args['location_id'] );
        }
        if ( isset( $args['location_ids'] ) && is_array( $args['location_ids'] ) ) {
            $ids = array_values( array_filter( array_map( 'intval', $args['location_ids'] ) ) );
            if ( $ids ) {
                $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                $where[] = $wpdb->prepare( '(m.location_id IN (' . $ph . ') OR m.dest_location_id IN (' . $ph . '))', ...array_merge( $ids, $ids ) );
            } else {
                // Usuario sin ubicaciones asignadas: no ver nada.
                $where[] = '1=0';
            }
        }
        if ( ! empty( $args['product_id'] ) ) {
            $where[] = $wpdb->prepare( 'm.product_id = %d', $args['product_id'] );
        }
        if ( ! empty( $args['from'] ) ) {
            $where[] = $wpdb->prepare( 'm.created_at >= %s', $args['from'] . ' 00:00:00' );
        }
        if ( ! empty( $args['to'] ) ) {
            $where[] = $wpdb->prepare( 'm.created_at <= %s', $args['to'] . ' 23:59:59' );
        }
        if ( ! empty( $args['search'] ) ) {
            $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = $wpdb->prepare( '(p.name LIKE %s OR m.reference LIKE %s)', $like, $like );
        }
        return $where;
    }

    /**
     * ORDER BY seguro por columna (whitelist) para movimientos.
     */
    protected static function movements_orderby( $key = '', $dir = 'DESC' ) {
        $map = array(
            'id' => 'm.id', 'created_at' => 'm.created_at', 'type' => 'm.type',
            'product_name' => 'p.name', 'qty' => 'm.qty', 'reference' => 'm.reference',
            'user_name' => 'u.display_name', 'date' => 'm.created_at',
            'location_name' => 'l1.name', 'dest_name' => 'l2.name',
        );
        $col = isset( $map[ $key ] ) ? $map[ $key ] : 'm.id';
        return $col . ' ' . ( 'DESC' === strtoupper( $dir ) ? 'DESC' : 'ASC' );
    }

    /**
     * Stock por ubicación, con filtros y paginación (server-side).
     */
    public static function stock_rows( $args = array() ) {
        global $wpdb;
        $where = self::stock_rows_where( $args );
        $sql = "SELECT s.*, p.name, p.barcode, p.category, p.category_id, p.min_stock, p.sale_price, p.transfer_pct, p.currency, p.show_equiv, p.image, p.description, p.store_visible,
                l.name AS location_name, l.type AS location_type, l.description AS location_description
                FROM " . self::table( 'stock' ) . " s
                INNER JOIN " . self::table( 'products' ) . " p ON p.id = s.product_id
                LEFT JOIN " . self::table( 'locations' ) . " l ON l.id = s.location_id
                WHERE " . implode( ' AND ', $where ) . " ORDER BY " . self::stock_rows_orderby( $args['orderby'] ?? '', $args['order'] ?? 'ASC' );
        if ( isset( $args['limit'] ) ) {
            $sql .= $wpdb->prepare( ' LIMIT %d', max( 1, (int) $args['limit'] ) );
        }
        if ( isset( $args['offset'] ) ) {
            $sql .= $wpdb->prepare( ' OFFSET %d', max( 0, (int) $args['offset'] ) );
        }
        return $wpdb->get_results( $sql );
    }

    /**
     * Conteo de stock con los mismos filtros (para paginación).
     */
    public static function count_stock_rows( $args = array() ) {
        global $wpdb;
        $where = self::stock_rows_where( $args );
        $sql = "SELECT COUNT(*) FROM " . self::table( 'stock' ) . " s
                INNER JOIN " . self::table( 'products' ) . " p ON p.id = s.product_id
                WHERE " . implode( ' AND ', $where );
        return (int) $wpdb->get_var( $sql );
    }

    /**
     * WHERE compartido de stock (listado y conteo).
     */
    protected static function stock_rows_where( $args = array() ) {
        global $wpdb;
        $where = array( '1=1' );
        if ( ! empty( $args['location_id'] ) ) {
            $where[] = $wpdb->prepare( 's.location_id = %d', $args['location_id'] );
        }
        if ( isset( $args['location_ids'] ) && is_array( $args['location_ids'] ) ) {
            $ids = array_values( array_filter( array_map( 'intval', $args['location_ids'] ) ) );
            if ( $ids ) {
                $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                $where[] = $wpdb->prepare( 's.location_id IN (' . $ph . ')', ...$ids );
            } else {
                // Usuario sin ubicaciones asignadas: no ver nada.
                $where[] = '1=0';
            }
        }
        if ( ! empty( $args['search'] ) ) {
            $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = $wpdb->prepare( '(p.name LIKE %s OR p.barcode LIKE %s)', $like, $like );
        }
        if ( ! empty( $args['category'] ) ) {
            $where[] = $wpdb->prepare( 'p.category = %s', $args['category'] );
        }
        if ( isset( $args['min_price'] ) && is_numeric( $args['min_price'] ) ) {
            $where[] = $wpdb->prepare( 'p.sale_price >= %f', (float) $args['min_price'] );
        }
        if ( isset( $args['max_price'] ) && is_numeric( $args['max_price'] ) ) {
            $where[] = $wpdb->prepare( 'p.sale_price <= %f', (float) $args['max_price'] );
        }
        if ( ! empty( $args['in_stock'] ) ) {
            $where[] = 's.qty > 0';
        }
        if ( ! empty( $args['low_stock'] ) ) {
            $where[] = 's.qty <= p.min_stock';
        }
        if ( isset( $args['store_visible'] ) ) {
            // La visibilidad en la tienda es POR UBICACIÓN: manda el override
            // de ws_store_visibility (entidad + ubicación) sobre el global.
            if ( (int) $args['store_visible'] ) {
                $where[] = 'p.store_visible = 1';
                $where[] = 'NOT EXISTS (SELECT 1 FROM ' . self::table( 'store_visibility' ) . " sv WHERE sv.entity_type='product' AND sv.entity_id=p.id AND sv.location_id=s.location_id AND sv.visible=0)";
            } else {
                $where[] = '(p.store_visible = 0 OR EXISTS (SELECT 1 FROM ' . self::table( 'store_visibility' ) . " sv WHERE sv.entity_type='product' AND sv.entity_id=p.id AND sv.location_id=s.location_id AND sv.visible=0))";
            }
        }
        return $where;
    }

    /**
     * ORDER BY seguro por columna (whitelist) para stock.
     */
    protected static function stock_rows_orderby( $key = '', $dir = 'ASC' ) {
        $map = array(
            'name' => 'p.name', 'barcode' => 'p.barcode', 'qty' => 's.qty',
            'min_stock' => 'p.min_stock', 'sale_price' => 'p.sale_price',
            'location_name' => 'l.name',
        );
        $col = isset( $map[ $key ] ) ? $map[ $key ] : 'p.name';
        return $col . ' ' . ( 'DESC' === strtoupper( $dir ) ? 'DESC' : 'ASC' );
    }

    /* ---------------- Control de inventario predictivo ---------------- */

    /**
     * Obtiene productos con stock bajo o crítico
     */
    public static function get_low_stock_products( $location_id = 0, $threshold_multiplier = 1.0 ) {
        global $wpdb;
        $table = self::table( 'stock' );
        $products_table = self::table( 'products' );
        
        $where = array( '1=1' );
        if ( $location_id ) {
            $where[] = $wpdb->prepare( 's.location_id = %d', $location_id );
        }
        
        $sql = "SELECT s.*, p.name, p.barcode, p.min_stock, p.image, p.currency,
                CASE 
                    WHEN s.qty = 0 THEN 'critical'
                    WHEN s.qty <= (p.min_stock * 0.5) THEN 'critical'
                    WHEN s.qty <= p.min_stock THEN 'low'
                    ELSE 'ok'
                END as stock_status
                FROM {$table} s
                INNER JOIN {$products_table} p ON p.id = s.product_id
                WHERE " . implode( ' AND ', $where ) . "
                AND s.qty <= (p.min_stock * {$threshold_multiplier})
                AND p.min_stock > 0
                ORDER BY s.qty ASC";
        
        return $wpdb->get_results( $sql );
    }

    /**
     * Genera sugerencias de reabastecimiento basadas en histórico de ventas
     */
    public static function get_restock_suggestions( $location_id = 0, $days = 30 ) {
        global $wpdb;
        $stock_table = self::table( 'stock' );
        $products_table = self::table( 'products' );
        $movements_table = self::table( 'movements' );
        
        $where = array( 'm.type = %s' );
        $params = array( 'salida' );
        
        if ( $location_id ) {
            $where[] = 'm.location_id = %d';
            $params[] = $location_id;
        }
        
        $where[] = 'm.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
        $params[] = $days;
        
        $sql = "SELECT p.id, p.name, p.barcode, p.min_stock, p.cost_price, p.sale_price,
                s.qty as current_stock,
                COALESCE(SUM(m.qty), 0) as sold_qty,
                COALESCE(SUM(m.qty) / {$days}, 0) as daily_avg,
                CASE 
                    WHEN s.qty = 0 THEN 0
                    WHEN COALESCE(SUM(m.qty) / {$days}, 0) = 0 THEN 0
                    ELSE GREATEST(1, FLOOR(s.qty / COALESCE(SUM(m.qty) / {$days}, 0)))
                END as days_remaining
                FROM {$products_table} p
                LEFT JOIN {$stock_table} s ON s.product_id = p.id " . 
                ( $location_id ? $wpdb->prepare( 'AND s.location_id = %d', $location_id ) : '' ) . "
                LEFT JOIN {$movements_table} m ON m.product_id = p.id " . 
                ( $location_id ? $wpdb->prepare( 'AND m.location_id = %d', $location_id ) : '' ) . "
                WHERE " . implode( ' AND ', $where ) . "
                OR m.id IS NULL
                GROUP BY p.id, p.name, p.barcode, p.min_stock, p.cost_price, p.sale_price, s.qty
                HAVING days_remaining <= 7 OR current_stock <= min_stock
                ORDER BY days_remaining ASC";
        
        return $wpdb->get_results( $sql );
    }

    /**
     * Calcula stock óptimo basado en histórico de ventas
     */
    public static function calculate_optimal_stock( $product_id, $location_id = 0, $days = 30 ) {
        global $wpdb;
        $movements_table = self::table( 'movements' );
        
        $where = array( $wpdb->prepare( 'product_id = %d', $product_id ) );
        $where[] = $wpdb->prepare( 'type = %s', 'salida' );
        $where[] = $wpdb->prepare( 'created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', $days );
        
        if ( $location_id ) {
            $where[] = $wpdb->prepare( 'location_id = %d', $location_id );
        }
        
        $avg_daily = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(qty) / {$days}, 0) 
            FROM {$movements_table} 
            WHERE " . implode( ' AND ', $where )
        );
        
        if ( $avg_daily <= 0 ) {
            return 0;
        }
        
        // Stock óptimo = promedio diario * días de seguridad (30 días)
        $optimal = $avg_daily * 30;
        
        return array(
            'avg_daily' => $avg_daily,
            'optimal_stock' => ceil( $optimal ),
            'recommended_reorder' => ceil( $optimal * 0.7 ), // Reorder al 70%
        );
    }

    /**
     * Crea alerta de stock bajo en notificaciones
     */
    public static function create_low_stock_alert( $product_id, $location_id, $current_qty, $min_stock ) {
        if ( ! function_exists( 'WS_Notifications' ) ) {
            return false;
        }
        
        $product = WS_CRUD::get_product( $product_id );
        $location = WS_CRUD::get_location( $location_id );
        
        if ( ! $product || ! $location ) {
            return false;
        }
        
        $title = __( 'Stock bajo', 'workshop' );
        $message = sprintf(
            __( '%s en %s: %s %s (mínimo: %s)', 'workshop' ),
            $product->name,
            $location->name,
            number_format( $current_qty, 2 ),
            $product->currency,
            number_format( $min_stock, 2 )
        );
        
        // Notificar a usuarios con permiso de stock_view en esa ubicación
        $users = get_users( array( 
            'role__in' => array( 'ws_owner', 'ws_storekeeper' ),
        ) );
        
        foreach ( $users as $user ) {
            // Verificar si el usuario tiene acceso a esta ubicación
            if ( ws_can( 'stock_view' ) ) {
                WS_Notifications::add( $user->ID, 'warning', $title, $message, 
                    home_url( '/panel/owner/stock/?search=' . urlencode( $product->name ) ),
                    'low_stock_' . $product_id . '_' . $location_id
                );
            }
        }
        
        return true;
    }
}
