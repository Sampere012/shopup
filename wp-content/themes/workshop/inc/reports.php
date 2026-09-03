<?php
/**
 * Reportes de negocio.
 *
 * Datos agregados del negocio (ventas, movimientos, productos más/menos
 * vendidos, promedio, transacciones y ventas POS) con filtro por ubicación
 * y período, y exportación a Excel (.xlsx).
 *
 * La exportación genera un XLSX real (Open XML) sin librerías externas:
 * el archivo es un ZIP (deflate) construido a mano con gzdeflate() y CRC32,
 * por lo que abre directamente en Excel, LibreOffice y Google Sheets.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Períodos disponibles para los reportes (clave = días; 0 = todo el historial).
 *
 * @return array{int:string}
 */
function ws_reports_periods() {
	return array(
		0  => __( 'Todo el historial', 'workshop' ),
		7  => __( 'Últimos 7 días', 'workshop' ),
		14 => __( 'Últimos 14 días', 'workshop' ),
		30 => __( 'Últimos 30 días', 'workshop' ),
		90 => __( 'Últimos 90 días', 'workshop' ),
	);
}

/**
 * Filtros de reporte (ubicación + período) a partir de la petición.
 *
 * La ubicación seleccionada solo se acepta si está entre las que tiene
 * asignadas el usuario. Si no hay ubicación válida se usan todas.
 *
 * El rango de fechas personalizado (ws_from / ws_to, formato YYYY-MM-DD)
 * tiene prioridad sobre el período preseleccionado (ws_period): si llega un
 * rango se usa tal cual; si no, se usa el período por defecto (últimos 14
 * días) o el seleccionado. Siempre se devuelve period_start y period_end.
 *
 * @param bool $from_post Leer de $_POST (exportación) o de $_GET (página).
 * @return array
 */
function ws_reports_filters( $from_post = false ) {
	$source    = $from_post ? $_POST : $_GET;
	$locations = ws_user_locations();
	$loc_ids   = array_values( array_filter( array_map(
		static fn( $l ) => (int) $l->id,
		$locations
	) ) );

	$selected = isset( $source['ws_loc'] ) ? (int) $source['ws_loc'] : 0;
	if ( $selected && in_array( $selected, $loc_ids, true ) ) {
		$use_ids = array( $selected );
	} else {
		$selected = 0;
		$use_ids  = $loc_ids;
	}

	// Rango de fechas personalizado (desde / hasta).
	$from = isset( $source['ws_from'] ) ? sanitize_text_field( $source['ws_from'] ) : '';
	$to   = isset( $source['ws_to'] ) ? sanitize_text_field( $source['ws_to'] ) : '';
	$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : '';
	$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? $to : '';
	if ( $from && $to && $from > $to ) {
		$tmp  = $from;
		$from = $to;
		$to   = $tmp;
	}
	if ( $from || $to ) {
		$period        = 0;
		$period_start  = $from ? $from : '1900-01-01';
		$period_end    = $to ? $to : date( 'Y-m-d', current_time( 'timestamp' ) );
		$label_from    = $from ? date( 'd/m/Y', strtotime( $from ) ) : __( 'Inicio del historial', 'workshop' );
		$label_to      = $to ? date( 'd/m/Y', strtotime( $to ) ) : __( 'Hoy', 'workshop' );
		$period_label  = $label_from . ' – ' . $label_to;
	} else {
		$period = isset( $source['ws_period'] ) ? (int) $source['ws_period'] : 14;
		if ( ! array_key_exists( $period, ws_reports_periods() ) ) {
			$period = 14;
		}
		$period_start = $period
			? date( 'Y-m-d', strtotime( '-' . $period . ' days', current_time( 'timestamp' ) ) )
			: '1900-01-01';
		$period_end   = date( 'Y-m-d', current_time( 'timestamp' ) );
		$period_label = ws_reports_periods()[ $period ];
	}

	return array(
		'location_id'  => $selected,
		'loc_ids'      => $use_ids,
		'locations'    => $locations,
		'period'       => $period,
		'period_label' => $period_label,
		'period_start' => $period_start,
		'period_end'   => $period_end,
	);
}

/**
 * Datos agregados de los reportes aplicando los filtros.
 *
 * @param array $filters Salida de ws_reports_filters().
 * @return array
 */
function ws_reports_data( $filters ) {
	global $wpdb;

	$loc_ids = $filters['loc_ids'];
	$ph      = $loc_ids ? implode( ',', array_fill( 0, count( $loc_ids ), '%d' ) ) : '0';
	$args    = $loc_ids ? $loc_ids : array( 0 );
	$since   = $filters['period_start'];
	$until   = $filters['period_end'];

	$orders_table      = ws_table_name( 'orders' );
	$movements_table   = ws_table_name( 'movements' );
	$order_items_table = ws_table_name( 'order_items' );
	$locations_table   = ws_table_name( 'locations' );
	$pos_sales_table   = ws_table_name( 'pos_sales' );
	$pos_items_table   = ws_table_name( 'pos_sale_items' );

	// Moneda del reporte: la de la ubicación filtrada o la base del negocio.
	$rep_currency = ws_currency_symbol( $filters['location_id'] );

	// Ventas por día. Se agrupan por moneda y cada monto se convierte a la
	// moneda del reporte antes de sumar el día (así no se mezclan CUP + USD).
	$sales = array();
	if ( $loc_ids ) {
		$raw_sales = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) AS d, currency, SUM(total) AS total, COUNT(*) AS n
			 FROM {$orders_table}
			 WHERE location_id IN ({$ph}) AND status IN ('accepted','completed')
			   AND created_at >= %s AND created_at <= %s
			 GROUP BY DATE(created_at), currency ORDER BY d ASC",
			...array_merge( $args, array( $since, $until ) )
		) );
		$sales_by_day = array();
		foreach ( $raw_sales as $row ) {
			$cur = $row->currency ? $row->currency : $rep_currency;
			$d   = $row->d;
			if ( ! isset( $sales_by_day[ $d ] ) ) {
				$sales_by_day[ $d ] = (object) array( 'd' => $d, 'total' => 0.0, 'n' => 0 );
			}
			$sales_by_day[ $d ]->total += ws_convert( (float) $row->total, $cur, $rep_currency );
			$sales_by_day[ $d ]->n     += (int) $row->n;
		}
		ksort( $sales_by_day );
		$sales = array_values( $sales_by_day );
	}

	// Movimientos por tipo.
	$by_type = array();
	if ( $loc_ids ) {
		$by_type = $wpdb->get_results( $wpdb->prepare(
			"SELECT type, COUNT(*) AS n, COALESCE(SUM(qty),0) AS qty
			 FROM {$movements_table}
			 WHERE location_id IN ({$ph}) AND created_at >= %s AND created_at <= %s
			 GROUP BY type ORDER BY n DESC",
			...array_merge( $args, array( $since, $until ) )
		) );
	}

	// Todos los productos vendidos en el período (ordenados por unidades).
	// Un mismo producto puede venderse en varias monedas (ubicaciones de
	// monedas distintas): se agrupa por moneda y se convierte a la moneda
	// del reporte antes de sumar.
	$top_all = array();
	if ( $loc_ids ) {
		$raw_top = $wpdb->get_results( $wpdb->prepare(
			"SELECT oi.product_id, oi.product_name, o.currency AS currency, SUM(oi.qty) AS qty,
			        SUM(oi.price * oi.qty) AS total, COUNT(DISTINCT o.id) AS orders
			 FROM {$order_items_table} oi
			 INNER JOIN {$orders_table} o ON o.id = oi.order_id
			 WHERE o.location_id IN ({$ph}) AND o.status IN ('accepted','completed')
			   AND o.created_at >= %s AND o.created_at <= %s
			 GROUP BY oi.product_id, oi.product_name, o.currency
			 ORDER BY qty DESC",
			...array_merge( $args, array( $since, $until ) )
		) );
		$top_by_product = array();
		foreach ( $raw_top as $row ) {
			$pid = (int) $row->product_id;
			$cur = $row->currency ? $row->currency : $rep_currency;
			if ( ! isset( $top_by_product[ $pid ] ) ) {
				$top_by_product[ $pid ] = (object) array(
					'product_id'   => $pid,
					'product_name' => $row->product_name,
					'qty'          => 0.0,
					'total'        => 0.0,
					'orders'       => 0,
				);
			}
			$top_by_product[ $pid ]->qty    += (float) $row->qty;
			$top_by_product[ $pid ]->total  += ws_convert( (float) $row->total, $cur, $rep_currency );
			$top_by_product[ $pid ]->orders += (int) $row->orders;
		}
		foreach ( $top_by_product as $p ) {
			$p->total = round( $p->total, 2 );
			$p->avg_per_trans = $p->orders ? round( $p->qty / $p->orders, 2 ) : 0.0;
		}
		usort( $top_by_product, fn( $a, $b ) => (float) $b->qty <=> (float) $a->qty );
		$top_all = array_values( $top_by_product );
	}

	// Detalle de transacciones (pedidos) del período.
	$transactions = array();
	if ( $loc_ids ) {
		$transactions = $wpdb->get_results( $wpdb->prepare(
			"SELECT o.id, o.number, o.created_at, o.customer_name, o.customer_phone, o.customer_address,
			        l.name AS location_name, o.subtotal, o.delivery_cost, o.total, o.status,
			        o.currency, o.delivery_currency
			 FROM {$orders_table} o
			 LEFT JOIN {$locations_table} l ON l.id = o.location_id
			 WHERE o.location_id IN ({$ph}) AND o.status IN ('accepted','completed')
			   AND o.created_at >= %s AND o.created_at <= %s
			 ORDER BY o.created_at DESC",
			...array_merge( $args, array( $since, $until ) )
		) );
	}

	$total_orders = array_sum( array_map( fn( $s ) => (int) $s->n, $sales ) );
	$total_sales  = array_sum( array_map( fn( $s ) => (float) $s->total, $sales ) );
	$total_units  = array_sum( array_map( fn( $p ) => (float) $p->qty, $top_all ) );
	$total_moves  = array_sum( array_map( fn( $t ) => (int) $t->n, $by_type ) );

	// Menos vendidos: mismos productos, orden invertido.
	$bottom = $top_all;
	usort( $bottom, fn( $a, $b ) => (float) $a->qty <=> (float) $b->qty );

	// Ventas POS (la tabla puede no existir en negocios antiguos).
	$pos_summary  = null;
	$pos_sales    = array();
	$pos_products = array();
	$has_pos      = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $pos_sales_table ) ) );
	if ( $has_pos && $loc_ids ) {
		// Resumen POS con la moneda de cada venta: se convierte y se suma
		// (el AVG en SQL mezclaría monedas si hay varias ubicaciones).
		$pos_sum = $wpdb->get_results( $wpdb->prepare(
			"SELECT COUNT(*) AS orders, currency, SUM(total) AS total
			 FROM {$pos_sales_table}
			 WHERE location_id IN ({$ph}) AND status <> 'cancelled' AND created_at >= %s AND created_at <= %s
			 GROUP BY currency",
			...array_merge( $args, array( $since, $until ) )
		) );
		$pos_orders = 0;
		$pos_total  = 0.0;
		foreach ( $pos_sum as $row ) {
			$cur = $row->currency ? $row->currency : $rep_currency;
			$pos_orders += (int) $row->orders;
			$pos_total  += ws_convert( (float) $row->total, $cur, $rep_currency );
		}
		$pos_summary = (object) array(
			'orders'  => $pos_orders,
			'total'   => round( $pos_total, 2 ),
			'average' => $pos_orders ? round( $pos_total / $pos_orders, 2 ) : 0.0,
		);

		$pos_sales = $wpdb->get_results( $wpdb->prepare(
			"SELECT ps.id, ps.number, ps.created_at, l.name AS location_name, ps.customer_name,
			        u.display_name AS seller_name, ps.payment_method, ps.cash_amount,
			        ps.transfer_amount, ps.total, ps.status, ps.currency
			 FROM {$pos_sales_table} ps
			 LEFT JOIN {$locations_table} l ON l.id = ps.location_id
			 LEFT JOIN {$wpdb->users} u ON u.ID = ps.seller_id
			 WHERE ps.location_id IN ({$ph}) AND ps.status <> 'cancelled' AND ps.created_at >= %s AND ps.created_at <= %s
			 ORDER BY ps.created_at DESC",
			...array_merge( $args, array( $since, $until ) )
		) );

		$pos_products = $wpdb->get_results( $wpdb->prepare(
			"SELECT psi.product_id, psi.product_name, SUM(psi.qty) AS qty,
			        COUNT(DISTINCT ps.id) AS transactions, SUM(psi.subtotal) AS total
			 FROM {$pos_items_table} psi
			 INNER JOIN {$pos_sales_table} ps ON ps.id = psi.sale_id
			 WHERE ps.location_id IN ({$ph}) AND ps.status <> 'cancelled' AND ps.created_at >= %s AND ps.created_at <= %s
			 GROUP BY psi.product_id, psi.product_name
			 ORDER BY qty DESC",
			...array_merge( $args, array( $since, $until ) )
		) );
	}

	// Ventas por MONEDA: cada moneda con sus pedidos, ventas POS y total.
	// Así el reporte no mezcla CUP + USD (sumar sin convertir sería incorrecto).
	$currency_totals = array();
	$base_currency   = ws_currency_symbol();
	if ( $loc_ids ) {
		$by_cur_orders = $wpdb->get_results( $wpdb->prepare(
			"SELECT currency, SUM(total) AS total, COUNT(*) AS n
			 FROM {$orders_table}
			 WHERE location_id IN ({$ph}) AND status IN ('accepted','completed')
			   AND created_at >= %s AND created_at <= %s
			 GROUP BY currency",
			...array_merge( $args, array( $since, $until ) )
		) );
		foreach ( $by_cur_orders as $row ) {
			$cur = $row->currency ? $row->currency : $base_currency;
			if ( ! isset( $currency_totals[ $cur ] ) ) {
				$currency_totals[ $cur ] = array( 'orders' => 0, 'pos' => 0, 'total' => 0.0 );
			}
			$currency_totals[ $cur ]['orders'] += (int) $row->n;
			$currency_totals[ $cur ]['total']  += (float) $row->total;
		}
		if ( $has_pos ) {
			$by_cur_pos = $wpdb->get_results( $wpdb->prepare(
				"SELECT currency, SUM(total) AS total, COUNT(*) AS n
				 FROM {$pos_sales_table}
				 WHERE location_id IN ({$ph}) AND status <> 'cancelled'
				   AND created_at >= %s AND created_at <= %s
				 GROUP BY currency",
				...array_merge( $args, array( $since, $until ) )
			) );
			foreach ( $by_cur_pos as $row ) {
				$cur = $row->currency ? $row->currency : $base_currency;
				if ( ! isset( $currency_totals[ $cur ] ) ) {
					$currency_totals[ $cur ] = array( 'orders' => 0, 'pos' => 0, 'total' => 0.0 );
				}
				$currency_totals[ $cur ]['pos']   += (int) $row->n;
				$currency_totals[ $cur ]['total'] += (float) $row->total;
			}
		}
	}
	// Ordena por total desc y añade el equivalente en la moneda base.
	$currency_rows = array();
	foreach ( $currency_totals as $cur => $t ) {
		$currency_rows[] = (object) array(
			'currency'   => $cur,
			'orders'     => (int) $t['orders'],
			'pos'        => (int) $t['pos'],
			'total'      => round( $t['total'], 2 ),
			'total_base' => round( ws_convert( $t['total'], $cur, $base_currency ), 2 ),
		);
	}
	usort( $currency_rows, fn( $a, $b ) => (float) $b->total <=> (float) $a->total );

	// Total CONVERTIDO a la moneda base (para no mezclar monedas al sumar).
	$total_sales_base = array_sum( array_map( fn( $c ) => (float) $c->total_base, $currency_rows ) );

	return array(
		'sales'            => $sales,
		'by_type'          => $by_type,
		'top'              => array_slice( $top_all, 0, 10 ),
		'top_all'          => $top_all,
		'bottom'           => array_slice( $bottom, 0, 10 ),
		'transactions'     => $transactions,
		'pos_summary'      => $pos_summary,
		'pos_sales'        => $pos_sales,
		'pos_products'     => $pos_products,
		'total_sales'      => $total_sales,
		'total_sales_base' => $total_sales_base,
		'currency_totals'  => $currency_rows,
		'total_orders'     => $total_orders,
		'total_units'      => $total_units,
		'total_moves'      => $total_moves,
		'avg_sale'         => $total_orders ? $total_sales / $total_orders : 0.0,
	);
}

/**
 * Etiqueta legible de un mes "YYYY-MM" => "Agosto 2026".
 */
function ws_month_label( $ym ) {
	$parts = explode( '-', (string) $ym );
	if ( 2 !== count( $parts ) ) {
		return (string) $ym;
	}
	$names = array(
		1 => __( 'Enero', 'workshop' ), 2 => __( 'Febrero', 'workshop' ),
		3 => __( 'Marzo', 'workshop' ), 4 => __( 'Abril', 'workshop' ),
		5 => __( 'Mayo', 'workshop' ), 6 => __( 'Junio', 'workshop' ),
		7 => __( 'Julio', 'workshop' ), 8 => __( 'Agosto', 'workshop' ),
		9 => __( 'Septiembre', 'workshop' ), 10 => __( 'Octubre', 'workshop' ),
		11 => __( 'Noviembre', 'workshop' ), 12 => __( 'Diciembre', 'workshop' ),
	);
	$m = (int) $parts[1];
	return ( $names[ $m ] ?? $parts[1] ) . ' ' . $parts[0];
}

/**
 * Utilidades mensuales del reporte.
 *
 * La utilidad es INGRESOS (pedidos aceptados/completados + ventas POS
 * completadas) MENOS GASTOS. Los gastos pueden ser GENERALES (location_id = 0,
 * se reparten a TODAS las ubicaciones del negocio) o de UNA ubicación concreta
 * (solo cuentan para esa ubicación). Los ingresos y los gastos respetan el
 * filtro de ubicación del reporte; los gastos se restan completos en el mes
 * que les corresponde. Se devuelve el desglose mes a mes, la utilidad por
 * punto de venta (ingresos − gastos) y el ingreso por punto de venta.
 *
 * @param array $filters Salida de ws_reports_filters().
 * @return array
 */
function ws_reports_utilities( $filters ) {
	global $wpdb;

	$loc_ids = $filters['loc_ids'];
	$since   = $filters['period_start'];
	$until   = $filters['period_end'];
	$ph      = $loc_ids ? implode( ',', array_fill( 0, count( $loc_ids ), '%d' ) ) : '0';
	$args    = $loc_ids ? $loc_ids : array( 0 );
	$loc_set = $loc_ids ? array_flip( array_map( 'intval', $loc_ids ) ) : array();

	// Moneda del reporte y moneda de cada ubicación: al sumar ingresos de
	// varias ubicaciones no se pueden mezclar monedas, así que cada fila se
	// convierte a la moneda del reporte.
	$rep_cur      = ws_currency_symbol( $filters['location_id'] );
	$base_cur     = ws_currency_symbol();
	$loc_currency = array();
	foreach ( $filters['locations'] as $l ) {
		$loc_currency[ (int) $l->id ] = $l->currency ? $l->currency : $base_cur;
	}

	$orders_t = ws_table_name( 'orders' );
	$pos_t    = ws_table_name( 'pos_sales' );
	$exp_t    = ws_table_name( 'expenses' );

	$income_by_month = array(); // ym => total.
	$by_loc_by_month = array(); // ym => [ loc_id => total ].
	$by_loc_total    = array(); // loc_id => total de ingresos del período.

	// GANANCIA real por costo: para cada item vendido, (precio de venta − costo)
	// × cantidad. El costo se guarda en el item en el momento de la venta
	// (cost_price), así el resultado no cambia aunque el producto se reprecie.
	$profit_by_month      = array(); // ym => ganancia total.
	$profit_loc_by_month  = array(); // ym => [ loc_id => ganancia ].
	$profit_by_loc_total  = array(); // loc_id => ganancia del período.

	if ( $loc_ids ) {
		$order_inc = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, location_id, SUM(total) AS total
			 FROM {$orders_t}
			 WHERE location_id IN ({$ph}) AND status IN ('accepted','completed') AND created_at >= %s AND created_at <= %s
			 GROUP BY ym, location_id",
			...array_merge( $args, array( $since, $until ) )
		) );
		$pos_inc = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, location_id, SUM(total) AS total
			 FROM {$pos_t}
			 WHERE location_id IN ({$ph}) AND status = 'completed' AND created_at >= %s AND created_at <= %s
			 GROUP BY ym, location_id",
			...array_merge( $args, array( $since, $until ) )
		) );
		foreach ( array_merge( $order_inc, $pos_inc ) as $row ) {
			$loc_id = (int) $row->location_id;
			$ym     = (string) $row->ym;
			$cur    = isset( $loc_currency[ $loc_id ] ) ? $loc_currency[ $loc_id ] : $rep_cur;
			$total  = ws_convert( (float) $row->total, $cur, $rep_cur );
			$income_by_month[ $ym ]                      = (float) ( $income_by_month[ $ym ] ?? 0 ) + $total;
			$by_loc_by_month[ $ym ][ $loc_id ]           = (float) ( $by_loc_by_month[ $ym ][ $loc_id ] ?? 0 ) + $total;
			$by_loc_total[ $loc_id ]                     = (float) ( $by_loc_total[ $loc_id ] ?? 0 ) + $total;
		}

		// Ganancia por costo de ventas POS: items unidos a la venta completada.
		$pos_items_t = ws_table_name( 'pos_sale_items' );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $pos_items_t ) ) ) === $pos_items_t ) {
			$sit_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$pos_items_t}", 0 );
			if ( in_array( 'cost_price', $sit_cols, true ) ) {
				$pos_profit = $wpdb->get_results( $wpdb->prepare(
					"SELECT DATE_FORMAT(s.created_at, '%Y-%m') AS ym, s.location_id,
					        SUM((i.price - i.cost_price) * i.qty) AS profit
					 FROM {$pos_items_t} i
					 INNER JOIN {$pos_t} s ON s.id = i.sale_id
					 WHERE s.location_id IN ({$ph}) AND s.status = 'completed' AND s.created_at >= %s AND s.created_at <= %s
					 GROUP BY ym, s.location_id",
					...array_merge( $args, array( $since, $until ) )
				) );
				foreach ( $pos_profit as $row ) {
					$loc_id = (int) $row->location_id;
					$ym     = (string) $row->ym;
					$cur    = isset( $loc_currency[ $loc_id ] ) ? $loc_currency[ $loc_id ] : $rep_cur;
					$p      = ws_convert( (float) $row->profit, $cur, $rep_cur );
					$profit_by_month[ $ym ]            = ( $profit_by_month[ $ym ] ?? 0 ) + $p;
					$profit_loc_by_month[ $ym ][ $loc_id ] = ( $profit_loc_by_month[ $ym ][ $loc_id ] ?? 0 ) + $p;
					$profit_by_loc_total[ $loc_id ]    = ( $profit_by_loc_total[ $loc_id ] ?? 0 ) + $p;
				}
			}
		}

		// Ganancia por costo de PEDIDOS: el item no guarda costo, así que se
		// toma el costo ACTUAL del producto (lo más fiel disponible) y si el
		// producto ya no existe, se asume 0 de costo (ganancia = precio).
		$order_items_t = ws_table_name( 'order_items' );
		$products_t    = ws_table_name( 'products' );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $order_items_t ) ) ) === $order_items_t ) {
			$order_profit = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE_FORMAT(o.created_at, '%Y-%m') AS ym, o.location_id,
				        SUM(oi.qty * (oi.price - COALESCE(p.cost_price, 0))) AS profit
				 FROM {$order_items_t} oi
				 INNER JOIN {$orders_t} o ON o.id = oi.order_id
				 LEFT JOIN {$products_t} p ON p.id = oi.product_id
				 WHERE o.location_id IN ({$ph}) AND o.status IN ('accepted','completed') AND o.created_at >= %s AND o.created_at <= %s
				 GROUP BY ym, o.location_id",
				...array_merge( $args, array( $since, $until ) )
			) );
			foreach ( $order_profit as $row ) {
				$loc_id = (int) $row->location_id;
				$ym     = (string) $row->ym;
				$cur    = isset( $loc_currency[ $loc_id ] ) ? $loc_currency[ $loc_id ] : $rep_cur;
				$p      = ws_convert( (float) $row->profit, $cur, $rep_cur );
				$profit_by_month[ $ym ]            = ( $profit_by_month[ $ym ] ?? 0 ) + $p;
				$profit_loc_by_month[ $ym ][ $loc_id ] = ( $profit_loc_by_month[ $ym ][ $loc_id ] ?? 0 ) + $p;
				$profit_by_loc_total[ $loc_id ]    = ( $profit_by_loc_total[ $loc_id ] ?? 0 ) + $p;
			}
		}
	}

	// Gastos por mes y por ubicación. location_id = 0 es GENERAL: cuenta una
	// vez en el total del negocio y se reparte a CADA ubicación del filtro
	// (su utilidad por punto de venta lo asume). Un gasto de una ubicación
	// concreta solo cuenta si esa ubicación está dentro del filtro.
	$exp_by_month   = array(); // ym => total (general + específicos del filtro).
	$exp_loc_by_month = array(); // ym => [ loc_id => total ].
	$exp_by_loc_total = array(); // loc_id => total de gastos del período.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $exp_t ) ) ) === $exp_t ) {
		$exp_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(expense_date, '%Y-%m') AS ym, location_id, SUM(amount) AS total
			 FROM {$exp_t} WHERE expense_date >= %s AND expense_date <= %s GROUP BY ym, location_id",
			$since, $until
		) );
		foreach ( $exp_rows as $row ) {
			$ym    = (string) $row->ym;
			// Los gastos no guardan moneda: se asumen en la moneda base del
			// negocio y se convierten a la moneda del reporte si hace falta.
			$total = ws_convert( (float) $row->total, $base_cur, $rep_cur );
			$lid   = (int) $row->location_id;
			if ( 0 === $lid ) {
				$exp_by_month[ $ym ] = ( $exp_by_month[ $ym ] ?? 0 ) + $total;
				foreach ( $loc_ids as $loc ) {
					$exp_loc_by_month[ $ym ][ $loc ] = ( $exp_loc_by_month[ $ym ][ $loc ] ?? 0 ) + $total;
					$exp_by_loc_total[ $loc ]        = ( $exp_by_loc_total[ $loc ] ?? 0 ) + $total;
				}
			} elseif ( ! isset( $loc_set[ $lid ] ) ) {
				continue;
			} else {
				$exp_by_month[ $ym ] = ( $exp_by_month[ $ym ] ?? 0 ) + $total;
				$exp_loc_by_month[ $ym ][ $lid ] = ( $exp_loc_by_month[ $ym ][ $lid ] ?? 0 ) + $total;
				$exp_by_loc_total[ $lid ]        = ( $exp_by_loc_total[ $lid ] ?? 0 ) + $total;
			}
		}
	}

	$util_by_loc_total = array();
	foreach ( $by_loc_total as $loc => $inc ) {
		$util_by_loc_total[ $loc ] = $inc - (float) ( $exp_by_loc_total[ $loc ] ?? 0 );
	}

	$yms = array_unique( array_merge( array_keys( $income_by_month ), array_keys( $exp_by_month ) ) );
	sort( $yms );

	$months = array();
	$totals = array( 'income' => 0.0, 'expenses' => 0.0, 'profit' => 0.0, 'utility' => 0.0 );
	foreach ( $yms as $ym ) {
		$income   = (float) ( $income_by_month[ $ym ] ?? 0 );
		$expenses = (float) ( $exp_by_month[ $ym ] ?? 0 );
		$profit   = (float) ( $profit_by_month[ $ym ] ?? 0 );
		$months[] = array(
			'ym'       => $ym,
			'label'    => ws_month_label( $ym ),
			'income'   => $income,
			'expenses' => $expenses,
			'profit'   => $profit,
			'utility'  => $income - $expenses,
			'by_loc'   => $by_loc_by_month[ $ym ] ?? array(),
			'profit_by_loc' => $profit_loc_by_month[ $ym ] ?? array(),
		);
		$totals['income']   += $income;
		$totals['expenses'] += $expenses;
		$totals['profit']   += $profit;
		$totals['utility']  += $income - $expenses;
	}

	return array(
		'months'    => $months,
		'totals'    => $totals,
		'by_loc'    => $by_loc_total,
		'exp_by_loc'=> $exp_by_loc_total,
		'util_by_loc' => $util_by_loc_total,
		'profit_by_loc' => $profit_by_loc_total,
		'locations' => $filters['locations'],
	);
}

/* ============================================================
 * XLSX (Open XML) sin dependencias.
 * ============================================================ */

/**
 * Escapa texto para XML 1.0 (quita caracteres de control no válidos).
 */
function ws_xlsx_escape( $str ) {
	$str = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $str );
	return htmlspecialchars( $str, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

/**
 * Letra de columna a partir de un índice base 0 (0 => A, 25 => Z, 26 => AA).
 */
function ws_xlsx_col_letter( $n ) {
	$letter = '';
	while ( $n >= 0 ) {
		$letter = chr( 65 + ( $n % 26 ) ) . $letter;
		$n      = (int) ( $n / 26 ) - 1;
	}
	return $letter;
}

/**
 * Celda de una hoja.
 *
 * @param int    $col    Índice de columna (base 0).
 * @param int    $row    Número de fila (base 1).
 * @param string $value  Valor.
 * @param string $type   's' (texto inline) o 'n' (número).
 * @param int    $style  Índice de estilo (0 = general, 1 = moneda).
 */
function ws_xlsx_cell( $col, $row, $value, $type = 's', $style = 0 ) {
	$ref  = ws_xlsx_col_letter( $col ) . $row;
	$attr = ' r="' . $ref . '"' . ( $style ? ' s="' . (int) $style . '"' : '' );
	if ( 'n' === $type ) {
		$num = is_numeric( $value ) ? (float) $value : 0;
		return '<c' . $attr . ' t="n"><v>' . $num . '</v></c>';
	}
	$text = ws_xlsx_escape( $value );
	return '<c' . $attr . ' t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
}

/**
 * Fila XML a partir de un array de celdas.
 * Cada celda puede ser un valor plano (texto) o [ valor, tipo, estilo ].
 */
function ws_xlsx_row( $row_index, array $cells ) {
	$xml = '<row r="' . $row_index . '">';
	$col = 0;
	foreach ( $cells as $cell ) {
		$type  = 's';
		$style = 0;
		if ( is_array( $cell ) ) {
			$value = $cell[0];
			$type  = isset( $cell[1] ) ? $cell[1] : 's';
			$style = isset( $cell[2] ) ? (int) $cell[2] : 0;
		} else {
			$value = $cell;
		}
		$xml .= ws_xlsx_cell( $col, $row_index, $value, $type, $style );
		$col++;
	}
	return $xml . '</row>';
}

/**
 * Hoja de cálculo XML completa.
 */
function ws_xlsx_sheet( $name, array $rows ) {
	$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
	$xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
	$i    = 1;
	foreach ( $rows as $row ) {
		$xml .= ws_xlsx_row( $i, $row );
		$i++;
	}
	return $xml . '</sheetData></worksheet>';
}

/**
 * Construye el ZIP del XLSX a mano (deflate + CRC32), sin ZipArchive.
 *
 * @param array $files Mapa nombre de archivo => contenido.
 */
function ws_xlsx_zip( array $files ) {
	$data   = '';
	$cd     = '';
	$offset = 0;
	foreach ( $files as $name => $content ) {
		$comp  = gzdeflate( $content, 9 );
		$crc   = crc32( $content );
		$csize = strlen( $comp );
		$usize = strlen( $content );
		$nlen  = strlen( $name );

		// Cabecera local.
		$data .= pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0, 8, 0, 0, $crc, $csize, $usize, $nlen, 0 );
		$data .= $name;
		$data .= $comp;

		// Entrada del directorio central.
		$cd .= pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, 0, 0, $crc, $csize, $usize, $nlen, 0, 0, 0, 0, 0, $offset );
		$cd .= $name;

		$offset += 30 + $nlen + $csize;
	}

	$cd_size = strlen( $cd );
	$count   = count( $files );
	$data   .= $cd;
	$data   .= pack( 'VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $cd_size, $offset, 0 );

	return $data;
}

/**
 * Nombre de hoja seguro para Excel (<=31 caracteres y sin caracteres inválidos).
 */
function ws_xlsx_sheet_name( $name ) {
	$name = preg_replace( '/[\[\]\*:\/\?\\\\]/', '', (string) $name );
	$name = trim( $name );
	if ( '' === $name ) {
		$name = 'Hoja';
	}
	return mb_substr( $name, 0, 31 );
}

/**
 * Construye el libro XLSX completo a partir de las hojas.
 *
 * @param array $sheets Mapa nombre de hoja => filas (array de arrays).
 */
function ws_xlsx_build( array $sheets ) {
	$content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
		. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
		. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
		. '<Default Extension="xml" ContentType="application/xml"/>'
		. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
		. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

	$rels      = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
		. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
		. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
		. '</Relationships>';

	$workbook      = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
		. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
	$workbook_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
		. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

	$files = array();
	$n     = 0;
	foreach ( $sheets as $name => $rows ) {
		$n++;
		$name = ws_xlsx_sheet_name( $name );
		$files[ 'xl/worksheets/sheet' . $n . '.xml' ] = ws_xlsx_sheet( $name, $rows );
		$workbook .= '<sheet name="' . ws_xlsx_escape( $name ) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
		$workbook_rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
		$content_types .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
	}

	$workbook .= '</sheets></workbook>';
	$workbook_rels .= '<Relationship Id="rId' . ( $n + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
	$workbook_rels .= '</Relationships>';

	$content_types .= '</Types>';

	$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
		. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
		. '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
		. '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
		. '<borders count="1"><border/></borders>'
		. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
		. '<cellXfs count="2">'
		. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
		. '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
		. '</cellXfs>'
		. '</styleSheet>';

	$files = array_merge( array(
		'[Content_Types].xml'       => $content_types,
		'_rels/.rels'               => $rels,
		'xl/workbook.xml'           => $workbook,
		'xl/_rels/workbook.xml.rels' => $workbook_rels,
		'xl/styles.xml'             => $styles,
	), $files );

	return ws_xlsx_zip( $files );
}

/* ============================================================
 * Exportación AJAX.
 * ============================================================ */

add_action( 'wp_ajax_ws_reports_summary', 'ws_ajax_reports_summary' );
add_action( 'wp_ajax_nopriv_ws_reports_summary', 'ws_ajax_reports_summary' );
function ws_ajax_reports_summary() {
	ws_guard( 'reports_view' );

	$filters = ws_reports_filters( true );
	$data    = ws_reports_data( $filters );

	// Serializa los objetos de filas a arrays simples para JSON móvil.
	$jsonify = static function ( $rows ) {
		return array_map( static function ( $r ) {
			return (array) $r;
		}, (array) $rows );
	};

	// Utilidades mensuales y por punto de venta (ingresos − gastos).
	$utilities = ws_reports_utilities( $filters );

	$out = array(
		'filters'    => array(
			'location_id'  => (int) $filters['location_id'],
			'period'       => (int) $filters['period'],
			'period_label' => $filters['period_label'],
			'period_start' => $filters['period_start'],
			'period_end'   => $filters['period_end'],
			'locations'    => array_map( static function ( $l ) {
				return array( 'id' => (int) $l->id, 'name' => (string) $l->name );
			}, $filters['locations'] ),
		),
		'currency'       => ws_currency_symbol( $filters['location_id'] ),
		'sales'          => $jsonify( $data['sales'] ),
		'by_type'        => $jsonify( $data['by_type'] ),
		'top_all'        => $jsonify( $data['top_all'] ),
		'bottom'         => $jsonify( $data['bottom'] ),
		'transactions'   => $jsonify( $data['transactions'] ),
		'pos_summary'    => $data['pos_summary'] ? (array) $data['pos_summary'] : null,
		'pos_sales'      => $jsonify( $data['pos_sales'] ),
		'pos_products'   => $jsonify( $data['pos_products'] ),
		'total_sales'    => (float) $data['total_sales'],
		'total_orders'   => (int) $data['total_orders'],
		'total_units'    => (int) $data['total_units'],
		'total_moves'    => (int) $data['total_moves'],
		'avg_sale'       => (float) $data['avg_sale'],
		'currency_totals'=> array_map( static function ( $ct ) {
			$ct = (array) $ct;
			return array( 'currency' => (string) ( $ct['currency'] ?? '' ), 'total' => (float) ( $ct['total'] ?? 0 ), 'n' => (int) ( $ct['n'] ?? 0 ) );
		}, $data['currency_totals'] ),
		'utils'          => $utilities,
	);
	wp_send_json_success( array( 'data' => $out ) );
}

add_action( 'wp_ajax_ws_reports_export', 'ws_ajax_reports_export' );
function ws_ajax_reports_export() {
	ws_guard( 'reports_view' );

	$filters = ws_reports_filters( true );
	$data    = ws_reports_data( $filters );
	$sheets  = ws_reports_build_sheets( $filters, $data );
	$file    = ws_xlsx_build( $sheets );

	$file_loc = $filters['location_id'] ? 'ubicacion-' . $filters['location_id'] : 'todas';
	$filename = 'reportes-' . date( 'Y-m-d', current_time( 'timestamp' ) ) . '-' . $file_loc . '.xlsx';

	status_header( 200 );
	nocache_headers();
	header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'X-WS-Filename: ' . $filename );
	header( 'X-WS-Export: xlsx' );
	header( 'Content-Length: ' . strlen( $file ) );
	header( 'Content-Transfer-Encoding: binary' );
	header( 'Expires: 0' );
	echo $file;
	wp_die();
}

/**
 * Construye las hojas del libro a partir de los datos y filtros del reporte.
 *
 * @param array $filters Salida de ws_reports_filters().
 * @param array $data    Salida de ws_reports_data().
 * @return array Mapa nombre de hoja => filas.
 */
function ws_reports_build_sheets( $filters, $data ) {
	$currency = ws_currency_symbol( $filters['location_id'] );
	$money    = static function ( $v ) {
		return array( round( (float) $v, 2 ), 'n', 1 );
	};

	$loc_label = __( 'Todas las ubicaciones', 'workshop' );
	if ( $filters['location_id'] ) {
		foreach ( $filters['locations'] as $l ) {
			if ( (int) $l->id === $filters['location_id'] ) {
				$loc_label = $l->name;
				break;
			}
		}
	}

	// Total convertido a la MONEDA DEL REPORTE (no a la base del negocio),
	// usando el desglose por moneda que ya no mezcla montos.
	$total_sales_rep = array_sum( array_map(
		static fn( $ct ) => ws_convert( (float) $ct->total, $ct->currency, $currency ),
		(array) $data['currency_totals']
	) );

	$summary_rows = array(
		array( __( 'REPORTE DE VENTAS', 'workshop' ), '' ),
		array( __( 'Negocio', 'workshop' ), ws_site_name() ),
		array( __( 'Ubicación', 'workshop' ), $loc_label ),
		array( __( 'Período', 'workshop' ), $filters['period_label'] ),
		array( __( 'Moneda del reporte', 'workshop' ), $currency ),
		array( __( 'Generado', 'workshop' ), date( 'd/m/Y H:i', current_time( 'timestamp' ) ) ),
		array(),
		array( __( 'Ventas totales (convertidas)', 'workshop' ), $money( $total_sales_rep ) ),
		array( __( 'Transacciones', 'workshop' ), array( (int) $data['total_orders'], 'n' ) ),
		array( __( 'Ticket promedio', 'workshop' ), $money( $data['avg_sale'] ) ),
		array( __( 'Unidades vendidas', 'workshop' ), array( (float) $data['total_units'], 'n' ) ),
		array( __( 'Movimientos registrados', 'workshop' ), array( (int) $data['total_moves'], 'n' ) ),
	);

	// Ventas por MONEDA (no se mezclan montos de monedas distintas).
	if ( ! empty( $data['currency_totals'] ) ) {
		$summary_rows[] = array();
		$summary_rows[] = array( __( 'VENTAS POR MONEDA', 'workshop' ), '' );
		foreach ( $data['currency_totals'] as $ct ) {
			$summary_rows[] = array(
				$ct->currency,
				array(
					/* translators: 1: pedidos tienda, 2: ventas POS */
					sprintf( __( 'Pedidos: %1$d · POS: %2$d', 'workshop' ), (int) $ct->orders, (int) $ct->pos ),
					's',
				),
				$money( $ct->total ),
				array( round( (float) $ct->total_base, 2 ), 'n', 1 ),
			);
		}
	}

	if ( $data['pos_summary'] ) {
		$summary_rows[] = array( __( 'Ventas POS', 'workshop' ), $money( $data['pos_summary']->total ) );
		$summary_rows[] = array( __( 'Nº ventas POS', 'workshop' ), array( (int) $data['pos_summary']->orders, 'n' ) );
		$summary_rows[] = array( __( 'Ticket promedio POS', 'workshop' ), $money( $data['pos_summary']->average ) );
	}

	// Ventas por día.
	$by_day_rows = array(
		array( __( 'Día', 'workshop' ), __( 'Pedidos', 'workshop' ), __( 'Total', 'workshop' ) ),
	);
	foreach ( $data['sales'] as $s ) {
		$by_day_rows[] = array( mysql2date( 'd/m/Y', $s->d ), array( (int) $s->n, 'n' ), $money( $s->total ) );
	}

	// Transacciones (detalle de pedidos).
	$trans_rows = array(
		array(
			__( 'Nº', 'workshop' ), __( 'Fecha', 'workshop' ), __( 'Ubicación', 'workshop' ),
			__( 'Cliente', 'workshop' ), __( 'Teléfono', 'workshop' ), __( 'Moneda', 'workshop' ),
			__( 'Subtotal', 'workshop' ), __( 'Domicilio', 'workshop' ), __( 'Moneda dom.', 'workshop' ),
			__( 'Total', 'workshop' ), __( 'Estado', 'workshop' ),
		),
	);
	foreach ( $data['transactions'] as $o ) {
		$trans_rows[] = array(
			$o->number,
			mysql2date( 'd/m/Y H:i', $o->created_at ),
			(string) ( $o->location_name ?? '' ),
			$o->customer_name,
			$o->customer_phone,
			(string) ( $o->currency ?? '' ),
			$money( $o->subtotal ),
			$money( $o->delivery_cost ),
			(string) ( $o->delivery_currency ? $o->delivery_currency : $o->currency ),
			$money( $o->total ),
			ucfirst( $o->status ),
		);
	}

	// Fila común de productos.
	$prod_rows = static function ( array $list ) use ( $money ) {
		$rows = array(
			array( '#', __( 'Producto', 'workshop' ), __( 'Unidades', 'workshop' ), __( 'Pedidos', 'workshop' ), __( 'Promedio / pedido', 'workshop' ), __( 'Total', 'workshop' ) ),
		);
		$i = 1;
		foreach ( $list as $p ) {
			$rows[] = array(
				array( $i++, 'n' ),
				$p->product_name,
				array( (float) $p->qty, 'n' ),
				array( (int) $p->orders, 'n' ),
				array( (float) $p->avg_per_trans, 'n' ),
				$money( $p->total ),
			);
		}
		return $rows;
	};

	$top    = array_slice( $data['top_all'], 0, 100 );
	$bottom = array_slice( $data['bottom'], 0, 100 );

	// Promedio de unidades por pedido (descendente).
	$avg = $data['top_all'];
	usort( $avg, static function ( $a, $b ) {
		return (float) $b->avg_per_trans <=> (float) $a->avg_per_trans;
	} );
	$avg = array_slice( $avg, 0, 100 );

	// Movimientos por tipo.
	$mov_rows = array(
		array( __( 'Tipo', 'workshop' ), __( 'Registros', 'workshop' ), __( 'Unidades', 'workshop' ) ),
	);
	foreach ( $data['by_type'] as $t ) {
		$mov_rows[] = array( ucfirst( $t->type ), array( (int) $t->n, 'n' ), array( (float) $t->qty, 'n' ) );
	}

	$sheets = array(
		__( 'Resumen', 'workshop' )            => $summary_rows,
		__( 'Ventas por día', 'workshop' )     => $by_day_rows,
		__( 'Transacciones', 'workshop' )      => $trans_rows,
		__( 'Más vendidos', 'workshop' )       => $prod_rows( $top ),
		__( 'Menos vendidos', 'workshop' )     => $prod_rows( $bottom ),
		__( 'Promedio productos', 'workshop' ) => $prod_rows( $avg ),
		__( 'Movimientos', 'workshop' )        => $mov_rows,
	);

	// Utilidades mensuales: ingresos menos gastos, mes a mes.
	$utilities = ws_reports_utilities( $filters );
	if ( ! empty( $utilities['months'] ) ) {
		$util_rows = array(
			array( __( 'Mes', 'workshop' ), __( 'Ingresos', 'workshop' ), __( 'Ganancia (venta − costo)', 'workshop' ), __( 'Gastos', 'workshop' ), __( 'Utilidad', 'workshop' ) ),
		);
		foreach ( array_reverse( $utilities['months'] ) as $u ) {
			$util_rows[] = array( $u['label'], $money( $u['income'] ), $money( $u['profit'] ), $money( $u['expenses'] ), $money( $u['utility'] ) );
		}
		$util_rows[] = array( __( 'Total del período', 'workshop' ), $money( $utilities['totals']['income'] ), $money( $utilities['totals']['profit'] ), $money( $utilities['totals']['expenses'] ), $money( $utilities['totals']['utility'] ) );
		$sheets[ __( 'Utilidades', 'workshop' ) ] = $util_rows;
	}
	$util_locs = array_unique( array_merge(
		array_keys( (array) $utilities['by_loc'] ),
		array_keys( (array) $utilities['exp_by_loc'] )
	) );
	sort( $util_locs );
	if ( $util_locs ) {
		$utilloc_rows = array(
			array( __( 'Punto de venta', 'workshop' ), __( 'Ingresos', 'workshop' ), __( 'Ganancia (venta − costo)', 'workshop' ), __( 'Gastos', 'workshop' ), __( 'Utilidad', 'workshop' ) ),
		);
		foreach ( $util_locs as $lid ) {
			$lname = '#' . $lid;
			foreach ( $utilities['locations'] as $l ) {
				if ( (int) $l->id === (int) $lid ) {
					$lname = $l->name;
					break;
				}
			}
			$inc = (float) ( $utilities['by_loc'][ $lid ] ?? 0 );
			$exp = (float) ( $utilities['exp_by_loc'][ $lid ] ?? 0 );
			$prf = (float) ( $utilities['profit_by_loc'][ $lid ] ?? 0 );
			$utilloc_rows[] = array( $lname, $money( $inc ), $money( $prf ), $money( $exp ), $money( $inc - $exp ) );
		}
		$sheets[ __( 'Utilidad por punto de venta', 'workshop' ) ] = $utilloc_rows;
	}

	// Hojas de POS (solo si la tabla existe y hay datos).
	if ( $data['pos_summary'] ) {
		$pos_rows = array(
			array(
				__( 'Nº', 'workshop' ), __( 'Fecha', 'workshop' ), __( 'Ubicación', 'workshop' ),
				__( 'Cliente', 'workshop' ), __( 'Vendedor', 'workshop' ), __( 'Método', 'workshop' ),
				__( 'Moneda', 'workshop' ), __( 'Efectivo', 'workshop' ), __( 'Transferencia', 'workshop' ),
				__( 'Total', 'workshop' ), __( 'Estado', 'workshop' ),
			),
		);
		foreach ( $data['pos_sales'] as $p ) {
			$pos_rows[] = array(
				$p->number,
				mysql2date( 'd/m/Y H:i', $p->created_at ),
				(string) ( $p->location_name ?? '' ),
				$p->customer_name,
				(string) ( $p->seller_name ?? '' ),
				ucfirst( $p->payment_method ),
				(string) ( $p->currency ?? '' ),
				$money( $p->cash_amount ),
				$money( $p->transfer_amount ),
				$money( $p->total ),
				ucfirst( $p->status ),
			);
		}
		$sheets[ __( 'Ventas POS', 'workshop' ) ] = $pos_rows;

		$posp_rows = array(
			array( '#', __( 'Producto', 'workshop' ), __( 'Unidades', 'workshop' ), __( 'Transacciones', 'workshop' ), __( 'Total', 'workshop' ) ),
		);
		$i = 1;
		foreach ( array_slice( $data['pos_products'], 0, 100 ) as $p ) {
			$posp_rows[] = array(
				array( $i++, 'n' ),
				$p->product_name,
				array( (float) $p->qty, 'n' ),
				array( (int) $p->transactions, 'n' ),
				$money( $p->total ),
			);
		}
		$sheets[ __( 'Productos POS', 'workshop' ) ] = $posp_rows;
	}

	return $sheets;
}