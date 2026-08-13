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

	$period = isset( $source['ws_period'] ) ? (int) $source['ws_period'] : 14;
	if ( ! array_key_exists( $period, ws_reports_periods() ) ) {
		$period = 14;
	}

	return array(
		'location_id'  => $selected,
		'loc_ids'      => $use_ids,
		'locations'    => $locations,
		'period'       => $period,
		'period_label' => ws_reports_periods()[ $period ],
		'period_start' => $period
			? date( 'Y-m-d', strtotime( '-' . $period . ' days', current_time( 'timestamp' ) ) )
			: '1900-01-01',
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

	$orders_table      = ws_table_name( 'orders' );
	$movements_table   = ws_table_name( 'movements' );
	$order_items_table = ws_table_name( 'order_items' );
	$locations_table   = ws_table_name( 'locations' );
	$pos_sales_table   = ws_table_name( 'pos_sales' );
	$pos_items_table   = ws_table_name( 'pos_sale_items' );

	// Ventas por día.
	$sales = array();
	if ( $loc_ids ) {
		$sales = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) AS d, SUM(total) AS total, COUNT(*) AS n
			 FROM {$orders_table}
			 WHERE location_id IN ({$ph}) AND status IN ('accepted','completed')
			   AND created_at >= %s
			 GROUP BY DATE(created_at) ORDER BY d ASC",
			...array_merge( $args, array( $since ) )
		) );
	}

	// Movimientos por tipo.
	$by_type = array();
	if ( $loc_ids ) {
		$by_type = $wpdb->get_results( $wpdb->prepare(
			"SELECT type, COUNT(*) AS n, COALESCE(SUM(qty),0) AS qty
			 FROM {$movements_table}
			 WHERE location_id IN ({$ph}) AND created_at >= %s
			 GROUP BY type ORDER BY n DESC",
			...array_merge( $args, array( $since ) )
		) );
	}

	// Todos los productos vendidos en el período (ordenados por unidades).
	$top_all = array();
	if ( $loc_ids ) {
		$top_all = $wpdb->get_results( $wpdb->prepare(
			"SELECT oi.product_id, oi.product_name, SUM(oi.qty) AS qty,
			        SUM(oi.price * oi.qty) AS total, COUNT(DISTINCT o.id) AS orders,
			        ROUND(SUM(oi.qty) / COUNT(DISTINCT o.id), 2) AS avg_per_trans
			 FROM {$order_items_table} oi
			 INNER JOIN {$orders_table} o ON o.id = oi.order_id
			 WHERE o.location_id IN ({$ph}) AND o.status IN ('accepted','completed')
			   AND o.created_at >= %s
			 GROUP BY oi.product_id, oi.product_name
			 ORDER BY qty DESC",
			...array_merge( $args, array( $since ) )
		) );
	}

	// Detalle de transacciones (pedidos) del período.
	$transactions = array();
	if ( $loc_ids ) {
		$transactions = $wpdb->get_results( $wpdb->prepare(
			"SELECT o.id, o.number, o.created_at, o.customer_name, o.customer_phone, o.customer_address,
			        l.name AS location_name, o.subtotal, o.delivery_cost, o.total, o.status
			 FROM {$orders_table} o
			 LEFT JOIN {$locations_table} l ON l.id = o.location_id
			 WHERE o.location_id IN ({$ph}) AND o.status IN ('accepted','completed')
			   AND o.created_at >= %s
			 ORDER BY o.created_at DESC",
			...array_merge( $args, array( $since ) )
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
		$pos_summary = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS orders, COALESCE(SUM(total),0) AS total, COALESCE(AVG(total),0) AS average
			 FROM {$pos_sales_table}
			 WHERE location_id IN ({$ph}) AND status <> 'cancelled' AND created_at >= %s",
			...array_merge( $args, array( $since ) )
		) );

		$pos_sales = $wpdb->get_results( $wpdb->prepare(
			"SELECT ps.id, ps.number, ps.created_at, l.name AS location_name, ps.customer_name,
			        u.display_name AS seller_name, ps.payment_method, ps.cash_amount,
			        ps.transfer_amount, ps.total, ps.status
			 FROM {$pos_sales_table} ps
			 LEFT JOIN {$locations_table} l ON l.id = ps.location_id
			 LEFT JOIN {$wpdb->users} u ON u.ID = ps.seller_id
			 WHERE ps.location_id IN ({$ph}) AND ps.status <> 'cancelled' AND ps.created_at >= %s
			 ORDER BY ps.created_at DESC",
			...array_merge( $args, array( $since ) )
		) );

		$pos_products = $wpdb->get_results( $wpdb->prepare(
			"SELECT psi.product_id, psi.product_name, SUM(psi.qty) AS qty,
			        COUNT(DISTINCT ps.id) AS transactions, SUM(psi.subtotal) AS total
			 FROM {$pos_items_table} psi
			 INNER JOIN {$pos_sales_table} ps ON ps.id = psi.sale_id
			 WHERE ps.location_id IN ({$ph}) AND ps.status <> 'cancelled' AND ps.created_at >= %s
			 GROUP BY psi.product_id, psi.product_name
			 ORDER BY qty DESC",
			...array_merge( $args, array( $since ) )
		) );
	}

	return array(
		'sales'        => $sales,
		'by_type'      => $by_type,
		'top'          => array_slice( $top_all, 0, 10 ),
		'top_all'      => $top_all,
		'bottom'       => array_slice( $bottom, 0, 10 ),
		'transactions' => $transactions,
		'pos_summary'  => $pos_summary,
		'pos_sales'    => $pos_sales,
		'pos_products' => $pos_products,
		'total_sales'  => $total_sales,
		'total_orders' => $total_orders,
		'total_units'  => $total_units,
		'total_moves'  => $total_moves,
		'avg_sale'     => $total_orders ? $total_sales / $total_orders : 0.0,
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

	$summary_rows = array(
		array( __( 'REPORTE DE VENTAS', 'workshop' ), '' ),
		array( __( 'Negocio', 'workshop' ), ws_site_name() ),
		array( __( 'Ubicación', 'workshop' ), $loc_label ),
		array( __( 'Período', 'workshop' ), $filters['period_label'] ),
		array( __( 'Generado', 'workshop' ), date( 'd/m/Y H:i', current_time( 'timestamp' ) ) ),
		array(),
		array( __( 'Ventas totales', 'workshop' ), $money( $data['total_sales'] ) ),
		array( __( 'Transacciones', 'workshop' ), array( (int) $data['total_orders'], 'n' ) ),
		array( __( 'Ticket promedio', 'workshop' ), $money( $data['avg_sale'] ) ),
		array( __( 'Unidades vendidas', 'workshop' ), array( (float) $data['total_units'], 'n' ) ),
		array( __( 'Movimientos registrados', 'workshop' ), array( (int) $data['total_moves'], 'n' ) ),
	);

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
			__( 'Cliente', 'workshop' ), __( 'Teléfono', 'workshop' ), __( 'Subtotal', 'workshop' ),
			__( 'Domicilio', 'workshop' ), __( 'Total', 'workshop' ), __( 'Estado', 'workshop' ),
		),
	);
	foreach ( $data['transactions'] as $o ) {
		$trans_rows[] = array(
			$o->number,
			mysql2date( 'd/m/Y H:i', $o->created_at ),
			(string) ( $o->location_name ?? '' ),
			$o->customer_name,
			$o->customer_phone,
			$money( $o->subtotal ),
			$money( $o->delivery_cost ),
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

	// Hojas de POS (solo si la tabla existe y hay datos).
	if ( $data['pos_summary'] ) {
		$pos_rows = array(
			array(
				__( 'Nº', 'workshop' ), __( 'Fecha', 'workshop' ), __( 'Ubicación', 'workshop' ),
				__( 'Cliente', 'workshop' ), __( 'Vendedor', 'workshop' ), __( 'Método', 'workshop' ),
				__( 'Efectivo', 'workshop' ), __( 'Transferencia', 'workshop' ), __( 'Total', 'workshop' ), __( 'Estado', 'workshop' ),
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