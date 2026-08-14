<?php
/**
 * CRUD de productos, proveedores y ubicaciones.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

class WS_CRUD {

    /**
     * Resultado de la última conversión de fraccionamiento (para la UI).
     *
     * @var array|null
     */
    protected static $last_fraction = null;

    /**
     * Devuelve el resultado de la última conversión de fraccionamiento
     * (o null si el último guardado no enlazó un fraccionamiento).
     */
    public static function last_fraction_conversion() {
        return self::$last_fraction;
    }

    protected static function table( $t ) {
        return ws_table_name( $t );
    }

    /* ---------------- Productos ---------------- */

    public static function get_products( $args = array() ) {
        global $wpdb;
        $table = self::table( 'products' );
        $where = self::products_where( $args );
        $sql = "SELECT p.*, s.name AS supplier_name
                FROM {$table} p
                LEFT JOIN " . self::table( 'suppliers' ) . " s ON s.id = p.supplier_id
                WHERE " . implode( ' AND ', $where ) . " ORDER BY " . self::products_orderby( $args['orderby'] ?? '', $args['order'] ?? 'ASC' );
        if ( isset( $args['limit'] ) ) {
            $sql .= $wpdb->prepare( ' LIMIT %d', max( 1, (int) $args['limit'] ) );
        }
        if ( isset( $args['offset'] ) ) {
            $sql .= $wpdb->prepare( ' OFFSET %d', max( 0, (int) $args['offset'] ) );
        }
        return $wpdb->get_results( $sql );
    }

    public static function count_products( $args = array() ) {
        global $wpdb;
        $table = self::table( 'products' );
        $where = self::products_where( $args );
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} p WHERE " . implode( ' AND ', $where ) );
    }

    /**
     * WHERE compartido de productos (listado y conteo).
     */
    protected static function products_where( $args = array() ) {
        global $wpdb;
        $where = array( '1=1' );
        if ( ! empty( $args['search'] ) ) {
            $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = $wpdb->prepare( "(p.name LIKE %s OR p.barcode LIKE %s)", $like, $like );
        }
        if ( ! empty( $args['category'] ) ) {
            $where[] = $wpdb->prepare( 'p.category = %s', $args['category'] );
        }
        if ( ! empty( $args['supplier_id'] ) ) {
            $where[] = $wpdb->prepare( 'p.supplier_id = %d', $args['supplier_id'] );
        }
        if ( isset( $args['active'] ) ) {
            $where[] = $wpdb->prepare( 'p.active = %d', $args['active'] );
        }
        return $where;
    }

    /**
     * ORDER BY seguro por columna (whitelist) para productos.
     */
    protected static function products_orderby( $key = '', $dir = 'ASC' ) {
        $map = array(
            'name' => 'p.name', 'barcode' => 'p.barcode', 'supplier_name' => 's.name',
            'cost_price' => 'p.cost_price', 'sale_price' => 'p.sale_price',
            'transfer_pct' => 'p.transfer_pct', 'min_stock' => 'p.min_stock',
            'production_date' => 'p.production_date', 'expiry_date' => 'p.expiry_date',
        );
        $col = isset( $map[ $key ] ) ? $map[ $key ] : 'p.name';
        return $col . ' ' . ( 'DESC' === strtoupper( $dir ) ? 'DESC' : 'ASC' );
    }

    public static function get_product( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table( 'products' ) . " WHERE id = %d", $id
        ) );
        return $row;
    }

    /**
     * True si ya existe otro producto con ese barcode (excluyendo $exclude_id).
     */
    protected static function barcode_taken( $barcode, $exclude_id = 0 ) {
        global $wpdb;
        $sql = $wpdb->prepare( "SELECT id FROM " . self::table( 'products' ) . " WHERE barcode = %s", $barcode );
        if ( $exclude_id ) {
            $sql .= $wpdb->prepare( ' AND id <> %d', $exclude_id );
        }
        return (bool) $wpdb->get_var( $sql );
    }

    public static function save_product( $data, $id = 0 ) {
        $barcode = sanitize_text_field( $data['barcode'] ?? '' );
        // La tabla tiene UNIQUE KEY barcode: garantiza unicidad (clonado, copias,
        // productos sin código) añadiendo sufijo al código cuando ya existe.
        $unique = $barcode;
        $suffix = 2;
        while ( self::barcode_taken( $unique, $id ) ) {
            $base = ( '' === $barcode ) ? sanitize_title( $data['name'] ?? '' ) : $barcode;
            if ( '' === $base ) {
                $base = 'producto';
            }
            $unique = $base . '-' . $suffix;
            $suffix++;
        }
        $category_id = (int) ( $data['category_id'] ?? 0 );
        // Fechas de producción/vencimiento (YYYY-MM-DD o vacío). Se guardan
        // como DATE (NULL si no se indican) para la tabla de productos y los
        // avisos de caducidad.
        $production_date = isset( $data['production_date'] ) ? sanitize_text_field( (string) $data['production_date'] ) : '';
        $expiry_date     = isset( $data['expiry_date'] ) ? sanitize_text_field( (string) $data['expiry_date'] ) : '';
        $production_date = self::clean_product_date( $production_date );
        $expiry_date     = self::clean_product_date( $expiry_date );
        if ( $production_date && $expiry_date && $expiry_date < $production_date ) {
            return new WP_Error( 'dates', __( 'La fecha de vencimiento no puede ser anterior a la de producción.', 'workshop' ) );
        }
        $fields = array(
            'name'          => sanitize_text_field( $data['name'] ?? '' ),
            'barcode'       => $unique,
            'category'      => sanitize_text_field( $data['category'] ?? '' ),
            'description'   => sanitize_textarea_field( $data['description'] ?? '' ),
            'image'         => esc_url_raw( $data['image'] ?? '' ),
            'cost_price'    => (float) ( $data['cost_price'] ?? 0 ),
            'sale_price'    => (float) ( $data['sale_price'] ?? 0 ),
            'transfer_pct'  => (float) ( $data['transfer_pct'] ?? 0 ),
            'currency'      => sanitize_text_field( $data['currency'] ?? ws_currency_symbol() ),
            'show_equiv'    => isset( $data['show_equiv'] ) ? (int) filter_var( $data['show_equiv'], FILTER_VALIDATE_BOOLEAN ) : 1,
            'supplier_id'   => (int) ( $data['supplier_id'] ?? 0 ),
            'min_stock'     => (float) ( $data['min_stock'] ?? 0 ),
            'production_date' => ( '' === $production_date ) ? null : $production_date,
            'expiry_date'     => ( '' === $expiry_date ) ? null : $expiry_date,
            'fraction_parent' => (int) ( $data['fraction_parent'] ?? 0 ),
            'fraction_qty'    => (float) ( $data['fraction_qty'] ?? 0 ),
            'active'        => isset( $data['active'] ) ? (int) filter_var( $data['active'], FILTER_VALIDATE_BOOLEAN ) : 1,
        );
        if ( empty( $fields['name'] ) ) {
            return new WP_Error( 'name', __( 'El nombre es obligatorio.', 'workshop' ) );
        }
        // Categoría en ÁRBOL: si llega category_id se valida contra la tabla
        // de categorías y se sincroniza el texto `category` con la RUTA de la
        // categoría («Padre / Hijo») para las búsquedas/filtros existentes y
        // la tienda. Si solo llega texto (importación CSV/legacy) se conserva.
        if ( $category_id ) {
            $cat_map = class_exists( 'WS_Categories' ) ? WS_Categories::map() : array();
            if ( ! isset( $cat_map[ $category_id ] ) ) {
                return new WP_Error( 'category', __( 'La categoría seleccionada no existe.', 'workshop' ) );
            }
            $fields['category_id'] = $category_id;
            $fields['category']    = WS_Categories::path_text( $category_id );
        } else {
            $fields['category_id'] = 0;
        }
        // Fraccionamiento: el padre debe existir y no puede ser hijo de nadie.
        if ( $fields['fraction_parent'] ) {
            if ( (int) $fields['fraction_parent'] === (int) $id ) {
                return new WP_Error( 'fraction', __( 'Un producto no puede ser fracción de sí mismo.', 'workshop' ) );
            }
            $parent = self::get_product( $fields['fraction_parent'] );
            if ( ! $parent ) {
                return new WP_Error( 'fraction', __( 'El producto madre no existe.', 'workshop' ) );
            }
            if ( (int) $parent->fraction_parent ) {
                return new WP_Error( 'fraction', __( 'El producto madre no puede ser a su vez una fracción.', 'workshop' ) );
            }
            if ( (float) $fields['fraction_qty'] <= 0 ) {
                return new WP_Error( 'fraction', __( 'Indica cuántos hijos equivalen a 1 unidad del producto madre.', 'workshop' ) );
            }
        }
        global $wpdb;
        $old = $id ? self::get_product( $id ) : null;
        if ( $id ) {
            // Guarda el estado anterior para la trazabilidad de precios.
            $wpdb->update( self::table( 'products' ), $fields, array( 'id' => $id ) );
            self::record_price_change( $old, $fields );
        } else {
            $wpdb->insert( self::table( 'products' ), $fields );
            $id = $wpdb->insert_id;
        }

        // Fraccionamiento recién enlazado: convierte 1 unidad del padre en
        // unidades del hijo (1 padre = N hijos) para que el stock quede
        // completo y el hijo pueda venderse o comprarse sin romper el
        // inventario. Si el padre tiene menos de 1 unidad en total, queda en
        // 0 y el hijo recibe solo el remanente (lo que quede * factor).
        self::$last_fraction = null;
        if ( $fields['fraction_parent'] && (float) $fields['fraction_qty'] > 0 ) {
            $old_parent = $old ? (int) ( $old->fraction_parent ?? 0 ) : 0;
            if ( $old_parent !== (int) $fields['fraction_parent'] ) {
                self::$last_fraction = WS_Stock::convert_fraction(
                    (int) $fields['fraction_parent'],
                    (int) $id,
                    (float) $fields['fraction_qty']
                );
            }
        }

        return (int) $id;
    }

    /**
     * Normaliza una fecha de producto (YYYY-MM-DD). Devuelve '' si está vacía
     * o no es una fecha real (evita guardar valores basura en columnas DATE).
     */
    protected static function clean_product_date( $raw ) {
        $raw = trim( (string) $raw );
        if ( '' === $raw ) {
            return '';
        }
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) ) {
            return '';
        }
        if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
            return '';
        }
        return $raw;
    }

    /**
     * Trazabilidad: guarda un registro cuando cambia costo o venta.
     * Solo persiste si alguno de los dos precios varió.
     */
    public static function record_price_change( $old, $new_fields ) {
        if ( ! $old || empty( $old->id ) ) {
            return;
        }
        $old_cost = (float) ( $old->cost_price ?? 0 );
        $new_cost = (float) ( $new_fields['cost_price'] ?? 0 );
        $old_sale = (float) ( $old->sale_price ?? 0 );
        $new_sale = (float) ( $new_fields['sale_price'] ?? 0 );
        if ( abs( $old_cost - $new_cost ) < 0.001 && abs( $old_sale - $new_sale ) < 0.001 ) {
            return;
        }
        global $wpdb;
        $wpdb->insert( self::table( 'price_history' ), array(
            'product_id'   => (int) $old->id,
            'product_name' => sanitize_text_field( $old->name ?? '' ),
            'old_cost'     => $old_cost,
            'new_cost'     => $new_cost,
            'old_sale'     => $old_sale,
            'new_sale'     => $new_sale,
            'currency'     => sanitize_text_field( $new_fields['currency'] ?? $old->currency ?? '' ),
            'user_id'      => get_current_user_id(),
        ) );
    }

    /* ---------------- Historial de precios ---------------- */

    public static function get_price_history( $args = array() ) {
        global $wpdb;
        $where = self::price_history_where( $args );
        $sql = "SELECT h.*, u.display_name AS user_name
                FROM " . self::table( 'price_history' ) . " h
                LEFT JOIN {$wpdb->users} u ON u.ID = h.user_id
                WHERE " . implode( ' AND ', $where ) . " ORDER BY " . self::price_history_orderby( $args['orderby'] ?? '', $args['order'] ?? 'ASC' );
        if ( isset( $args['limit'] ) ) {
            $sql .= $wpdb->prepare( ' LIMIT %d', max( 1, (int) $args['limit'] ) );
        }
        if ( isset( $args['offset'] ) ) {
            $sql .= $wpdb->prepare( ' OFFSET %d', max( 0, (int) $args['offset'] ) );
        }
        return $wpdb->get_results( $sql );
    }

    public static function count_price_history( $args = array() ) {
        global $wpdb;
        $where = self::price_history_where( $args );
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table( 'price_history' ) . " h WHERE " . implode( ' AND ', $where ) );
    }

    /**
     * WHERE compartido del historial (listado y conteo).
     */
    protected static function price_history_where( $args = array() ) {
        global $wpdb;
        $where = array( '1=1' );
        if ( ! empty( $args['product_id'] ) ) {
            $where[] = $wpdb->prepare( 'h.product_id = %d', $args['product_id'] );
        }
        if ( ! empty( $args['search'] ) ) {
            $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = $wpdb->prepare( 'h.product_name LIKE %s', $like );
        }
        return $where;
    }

    /**
     * ORDER BY seguro por columna (whitelist) para el historial.
     * Sin columna definida se ordena por fecha descendente (más reciente primero).
     */
    protected static function price_history_orderby( $key = '', $dir = 'ASC' ) {
        $map = array(
            'product_name' => 'h.product_name',
            'old_cost'     => 'h.old_cost',
            'new_cost'     => 'h.new_cost',
            'old_sale'     => 'h.old_sale',
            'new_sale'     => 'h.new_sale',
            'user_name'    => 'u.display_name',
            'created_at'   => 'h.created_at',
        );
        $col = isset( $map[ $key ] ) ? $map[ $key ] : 'h.created_at';
        $d = ( 'DESC' === strtoupper( $dir ) ) ? 'DESC' : 'ASC';
        if ( '' === $key ) {
            $d = 'DESC';
        }
        return $col . ' ' . $d;
    }

    public static function delete_product( $id ) {
        global $wpdb;
        // Al eliminar un padre, sus hijos dejan de ser fraccionados.
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table( 'products' ) . " SET fraction_parent = 0, fraction_qty = 0 WHERE fraction_parent = %d",
            $id
        ) );
        $wpdb->delete( self::table( 'products' ), array( 'id' => $id ) );
        $wpdb->delete( self::table( 'stock' ), array( 'product_id' => $id ) );
    }

    /* ---------------- Proveedores ---------------- */

    public static function get_suppliers( $args = array() ) {
        global $wpdb;
        $where = self::suppliers_where( $args );
        $sql = "SELECT * FROM " . self::table( 'suppliers' ) . " WHERE " . implode( ' AND ', $where ) . " ORDER BY " . self::suppliers_orderby( $args['orderby'] ?? '', $args['order'] ?? 'ASC' );
        if ( isset( $args['limit'] ) ) {
            $sql .= $wpdb->prepare( ' LIMIT %d', max( 1, (int) $args['limit'] ) );
        }
        if ( isset( $args['offset'] ) ) {
            $sql .= $wpdb->prepare( ' OFFSET %d', max( 0, (int) $args['offset'] ) );
        }
        return $wpdb->get_results( $sql );
    }

    public static function count_suppliers( $args = array() ) {
        global $wpdb;
        $where = self::suppliers_where( $args );
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table( 'suppliers' ) . " WHERE " . implode( ' AND ', $where ) );
    }

    /**
     * WHERE compartido de proveedores (listado y conteo).
     */
    protected static function suppliers_where( $args = array() ) {
        global $wpdb;
        $where = array( '1=1' );
        if ( ! empty( $args['search'] ) ) {
            $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = $wpdb->prepare( '(name LIKE %s OR country LIKE %s OR province LIKE %s OR address LIKE %s OR phone LIKE %s)', $like, $like, $like, $like, $like );
        }
        return $where;
    }

    /**
     * ORDER BY seguro por columna (whitelist) para proveedores.
     */
    protected static function suppliers_orderby( $key = '', $dir = 'ASC' ) {
        $map = array( 'name' => 'name', 'phone' => 'phone', 'address' => 'address', 'country' => 'country', 'province' => 'province' );
        $col = isset( $map[ $key ] ) ? $map[ $key ] : 'name';
        return $col . ' ' . ( 'DESC' === strtoupper( $dir ) ? 'DESC' : 'ASC' );
    }

    public static function get_supplier( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table( 'suppliers' ) . " WHERE id = %d", $id
        ) );
    }

    public static function save_supplier( $data, $id = 0 ) {
        $fields = array(
            'name'     => sanitize_text_field( $data['name'] ?? '' ),
            'phone'    => sanitize_text_field( $data['phone'] ?? '' ),
            'address'  => sanitize_text_field( $data['address'] ?? '' ),
            'country'  => sanitize_text_field( $data['country'] ?? '' ),
            'province' => sanitize_text_field( $data['province'] ?? '' ),
        );
        if ( empty( $fields['name'] ) ) {
            return new WP_Error( 'name', __( 'El nombre es obligatorio.', 'workshop' ) );
        }
        global $wpdb;
        if ( $id ) {
            $wpdb->update( self::table( 'suppliers' ), $fields, array( 'id' => $id ) );
        } else {
            $wpdb->insert( self::table( 'suppliers' ), $fields );
            $id = $wpdb->insert_id;
        }
        return (int) $id;
    }

    public static function delete_supplier( $id ) {
        global $wpdb;
        $wpdb->update( self::table( 'products' ), array( 'supplier_id' => 0 ), array( 'supplier_id' => $id ) );
        $wpdb->delete( self::table( 'suppliers' ), array( 'id' => $id ) );
    }

    /* ---------------- Ubicaciones ---------------- */

    public static function get_locations( $type = '', $args = array() ) {
        global $wpdb;
        $where = self::locations_where( $type, $args );
        $sql = "SELECT * FROM " . self::table( 'locations' ) . " WHERE " . implode( ' AND ', $where ) . " ORDER BY " . self::locations_orderby( $args['orderby'] ?? '', $args['order'] ?? 'ASC' );
        if ( isset( $args['limit'] ) ) {
            $sql .= $wpdb->prepare( ' LIMIT %d', max( 1, (int) $args['limit'] ) );
        }
        if ( isset( $args['offset'] ) ) {
            $sql .= $wpdb->prepare( ' OFFSET %d', max( 0, (int) $args['offset'] ) );
        }
        return $wpdb->get_results( $sql );
    }

    public static function count_locations( $type = '', $args = array() ) {
        global $wpdb;
        $where = self::locations_where( $type, $args );
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table( 'locations' ) . " WHERE " . implode( ' AND ', $where ) );
    }

    /**
     * WHERE compartido de ubicaciones (listado y conteo).
     */
    protected static function locations_where( $type = '', $args = array() ) {
        global $wpdb;
        $where = array( '1=1' );
        if ( $type ) {
            $where[] = $wpdb->prepare( 'type = %s', $type );
        }
        if ( ! empty( $args['search'] ) ) {
            $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = $wpdb->prepare( '(name LIKE %s OR address LIKE %s OR whatsapp LIKE %s OR slug LIKE %s)', $like, $like, $like, $like );
        }
        return $where;
    }

    /**
     * ORDER BY seguro por columna (whitelist) para ubicaciones.
     */
    protected static function locations_orderby( $key = '', $dir = 'ASC' ) {
        $map = array( 'name' => 'name', 'type' => 'type', 'address' => 'address', 'whatsapp' => 'whatsapp', 'currency' => 'currency', 'delivery_cost' => 'delivery_cost', 'slug' => 'slug' );
        $col = isset( $map[ $key ] ) ? $map[ $key ] : 'name';
        return $col . ' ' . ( 'DESC' === strtoupper( $dir ) ? 'DESC' : 'ASC' );
    }

    public static function get_location( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table( 'locations' ) . " WHERE id = %d", $id
        ) );
    }

    public static function get_location_by_slug( $slug ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table( 'locations' ) . " WHERE slug = %s", $slug
        ) );
    }

    public static function get_user_locations( $user_id ) {
        global $wpdb;
        $sql = "SELECT l.* FROM " . self::table( 'locations' ) . " l
                INNER JOIN " . self::table( 'user_locations' ) . " ul ON ul.location_id = l.id
                WHERE ul.user_id = %d ORDER BY l.name ASC";
        return $wpdb->get_results( $wpdb->prepare( $sql, $user_id ) );
    }

    public static function save_location( $data, $id = 0 ) {
        $name  = sanitize_text_field( $data['name'] ?? '' );
        $type  = in_array( $data['type'] ?? '', array( 'pv', 'almacen' ), true ) ? $data['type'] : 'pv';
        if ( empty( $name ) ) {
            return new WP_Error( 'name', __( 'El nombre es obligatorio.', 'workshop' ) );
        }
        $slug = sanitize_title( $data['slug'] ?? '' );
        if ( empty( $slug ) ) {
            $slug = sanitize_title( $name );
        }
        // Slug único.
        $suffix = '';
        while ( self::slug_exists( $slug . $suffix, $id ) ) {
            $suffix = $suffix ? ( (int) $suffix + 1 ) : 2;
        }
        $slug = $slug . ( is_int( $suffix ) && $suffix > 1 ? '-' . $suffix : '' );

        $payment_methods = isset( $data['payment_methods'] ) && is_array( $data['payment_methods'] )
            ? json_encode( array_values( array_map( 'sanitize_text_field', $data['payment_methods'] ) ) )
            : '';

        $fields = array(
            'type'            => $type,
            'name'            => $name,
            'slug'            => $slug,
            'address'         => sanitize_text_field( $data['address'] ?? '' ),
            'photo'           => esc_url_raw( $data['photo'] ?? '' ),
            'currency'        => sanitize_text_field( $data['currency'] ?? ws_currency_symbol() ),
            'payment_methods' => $payment_methods,
            'whatsapp'        => sanitize_text_field( $data['whatsapp'] ?? '' ),
            'delivery_cost'   => (float) ( $data['delivery_cost'] ?? 0 ),
            'active'          => isset( $data['active'] ) ? (int) filter_var( $data['active'], FILTER_VALIDATE_BOOLEAN ) : 1,
        );
        global $wpdb;
        if ( $id ) {
            $wpdb->update( self::table( 'locations' ), $fields, array( 'id' => $id ) );
        } else {
            $wpdb->insert( self::table( 'locations' ), $fields );
            $id = $wpdb->insert_id;
        }
        return (int) $id;
    }

    protected static function slug_exists( $slug, $exclude_id = 0 ) {
        global $wpdb;
        $sql = $wpdb->prepare( "SELECT id FROM " . self::table( 'locations' ) . " WHERE slug = %s", $slug );
        if ( $exclude_id ) {
            $sql .= $wpdb->prepare( ' AND id <> %d', $exclude_id );
        }
        return (bool) $wpdb->get_var( $sql );
    }

    public static function delete_location( $id ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM " . self::table( 'movements' ) . " WHERE location_id = %d OR dest_location_id = %d",
            $id, $id
        ) );
        $wpdb->delete( self::table( 'locations' ), array( 'id' => $id ) );
        $wpdb->delete( self::table( 'stock' ), array( 'location_id' => $id ) );
        $wpdb->delete( self::table( 'user_locations' ), array( 'location_id' => $id ) );
    }

    /* ---------------- Trabajadores ---------------- */

    public static function get_workers() {
        // Los dueños del negocio NO son trabajadores: se gestionan en el
        // registro/negocio y no deben aparecer en la tabla de trabajadores
        // ni poder editarse desde aquí. Solo cuentan almaceneros y vendedores.
        $roles = array( 'ws_storekeeper', 'ws_seller' );
        $biz   = ws_current_business_id();
        $out   = array();

        if ( WS_Business::is_default_id( $biz ) ) {
            // El negocio por defecto incluye a los trabajadores legacy (sin
            // ws_business_id) además de los asignados explícitamente.
            $legacy = new WP_User_Query( array(
                'role__in'    => $roles,
                'orderby'     => 'display_name',
                'order'       => 'ASC',
                'meta_key'    => 'ws_business_id',
                'meta_compare' => 'NOT EXISTS',
            ) );
            foreach ( $legacy->get_results() as $u ) {
                $out[] = $u;
            }
        }

        $q = new WP_User_Query( array(
            'role__in'    => $roles,
            'orderby'     => 'display_name',
            'order'       => 'ASC',
            'meta_key'    => 'ws_business_id',
            'meta_value'  => $biz,
        ) );
        foreach ( $q->get_results() as $u ) {
            $out[] = $u;
        }

        // Sin duplicados, ordenados por nombre.
        $seen = array();
        $out  = array_values( array_filter( $out, function ( $u ) use ( &$seen ) {
            if ( isset( $seen[ $u->ID ] ) ) {
                return false;
            }
            $seen[ $u->ID ] = true;
            return true;
        } ) );
        usort( $out, function ( $a, $b ) {
            return strcasecmp( (string) $a->display_name, (string) $b->display_name );
        } );
        return $out;
    }

    public static function set_worker_locations( $user_id, $location_ids ) {
        global $wpdb;
        $table = self::table( 'user_locations' );
        $wpdb->delete( $table, array( 'user_id' => $user_id ) );
        foreach ( array_unique( array_map( 'intval', (array) $location_ids ) ) as $lid ) {
            if ( $lid ) {
                $wpdb->insert( $table, array( 'user_id' => $user_id, 'location_id' => $lid ) );
            }
        }
    }

    /**
     * Trabajadores del negocio actual con filtro de búsqueda (misma lógica de
     * get_workers, pero aplicando search en PHP para poder paginar server-side
     * con ws_send_list). Devuelve el array completo; la paginación/orden se
     * hace en el endpoint AJAX.
     *
     * @param string $search Texto a buscar en nombre, email o login.
     * @return WP_User[]
     */
    public static function get_workers_matching( $search = '' ) {
        $roles = array( 'ws_storekeeper', 'ws_seller' );
        $biz   = ws_current_business_id();
        $out   = array();

        if ( WS_Business::is_default_id( $biz ) ) {
            $legacy = new WP_User_Query( array(
                'role__in'     => $roles,
                'orderby'      => 'display_name',
                'order'        => 'ASC',
                'meta_key'     => 'ws_business_id',
                'meta_compare' => 'NOT EXISTS',
            ) );
            foreach ( $legacy->get_results() as $u ) {
                $out[] = $u;
            }
        }

        $q = new WP_User_Query( array(
            'role__in'   => $roles,
            'orderby'    => 'display_name',
            'order'      => 'ASC',
            'meta_key'   => 'ws_business_id',
            'meta_value' => $biz,
        ) );
        foreach ( $q->get_results() as $u ) {
            $out[] = $u;
        }

        $seen = array();
        $out  = array_values( array_filter( $out, function ( $u ) use ( &$seen ) {
            if ( isset( $seen[ $u->ID ] ) ) {
                return false;
            }
            $seen[ $u->ID ] = true;
            return true;
        } ) );

        if ( '' !== trim( (string) $search ) ) {
            $needle = mb_strtolower( trim( (string) $search ) );
            $out    = array_values( array_filter( $out, function ( $u ) use ( $needle ) {
                return false !== mb_strpos( mb_strtolower( (string) $u->display_name ), $needle )
                    || false !== mb_strpos( mb_strtolower( (string) $u->user_email ), $needle )
                    || false !== mb_strpos( mb_strtolower( (string) $u->user_login ), $needle );
            } ) );
        }

        return $out;
    }
}
