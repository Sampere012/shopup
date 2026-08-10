<?php
/**
 * Plugin Name: WS Producción - Migración de URL automática
 * Description: En producción (no localhost) reemplaza las URLs locales
 *              (http://localhost/workshop, 127.0.0.1/workshop) por el dominio
 *              real (http://shopup.site.je) en TODA la base de datos, de forma
 *              segura con datos serializados. Corre UNA sola vez.
 * Version: 1.0.0
 *
 * @package WorkshopProd
 */

defined( 'ABSPATH' ) || exit;

// Solo en producción: si el host actual es localhost/127.0.0.1, no hacer nada.
add_action( 'init', 'ws_prod_migrate_maybe_run' );
function ws_prod_migrate_maybe_run() {
	// Ya migrado: no volver a tocar la BD.
	if ( get_option( 'ws_prod_url_migrated' ) ) {
		return;
	}

	$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';
	if ( '' === $host || preg_match( '/(^|\.)(localhost|127\.0\.0\.1|:8080|:3000)/i', $host ) ) {
		return; // Entorno local: este plugin es inocuo.
	}

	ws_prod_migrate_run();
	update_option( 'ws_prod_url_migrated', time() );
}

/**
 * Reemplazo recursivo con soporte de datos serializados.
 *
 * @param mixed $data
 * @return mixed
 */
function ws_prod_replace_deep( $data ) {
	if ( is_string( $data ) ) {
		if ( preg_match( '/^(a|O|s|i|d|b|N):/', $data ) ) {
			$unserialized = @unserialize( $data );
			if ( false !== $unserialized ) {
				return serialize( ws_prod_replace_deep( $unserialized ) );
			}
		}

		// Variantes locales -> dominio de producción.
		$data = str_replace(
			array(
				'http://localhost/workshop',
				'https://localhost/workshop',
				'//localhost/workshop',
				'http://127.0.0.1/workshop',
				'https://127.0.0.1/workshop',
				'//127.0.0.1/workshop',
			),
			array(
				'http://shopup.site.je',
				'http://shopup.site.je',
				'//shopup.site.je',
				'http://shopup.site.je',
				'http://shopup.site.je',
				'//shopup.site.je',
			),
			$data
		);

		// Formas "peladas" (sin esquema), protegiendo emails.
		$data = preg_replace( '/(?<!@)localhost\/workshop/', 'shopup.site.je', $data );
		$data = preg_replace( '/(?<!@)127\.0\.0\.1\/workshop/', 'shopup.site.je', $data );

		return $data;
	}

	if ( is_array( $data ) ) {
		$out = array();
		foreach ( $data as $k => $v ) {
			$out[ is_string( $k ) ? ws_prod_replace_deep( $k ) : $k ] = ws_prod_replace_deep( $v );
		}
		return $out;
	}

	if ( is_object( $data ) ) {
		try {
			$class = get_class( $data );
			if ( class_exists( $class ) ) {
				$obj = new $class();
				foreach ( get_object_vars( $data ) as $k => $v ) {
					$obj->{$k} = ws_prod_replace_deep( $v );
				}
				return $obj;
			}
		} catch ( Exception $e ) {
			return $data;
		}
	}

	return $data;
}

/**
 * Recorre todas las tablas wp_* (incluye wp_ws_* del tema) reemplazando URLs.
 */
function ws_prod_migrate_run() {
	global $wpdb;

	$tables = $wpdb->get_col( 'SHOW TABLES' );
	if ( empty( $tables ) ) {
		return;
	}

	$table_prefix = preg_quote( $wpdb->prefix, '/' );
	$batch        = 200;

	foreach ( $tables as $table ) {
		if ( ! preg_match( '/^' . $table_prefix . '/', $table ) ) {
			continue;
		}

		$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" );
		$text_cols = array();
		$pk_cols   = array();

		foreach ( $columns as $col ) {
			$type = strtolower( $col->Type );
			if ( false !== strpos( $type, 'char' )
				|| false !== strpos( $type, 'text' )
				|| false !== strpos( $type, 'blob' )
				|| false !== strpos( $type, 'enum' )
				|| false !== strpos( $type, 'set' )
				|| false !== strpos( $type, 'json' ) ) {
				$text_cols[] = $col->Field;
			}
			if ( 'PRI' === $col->Key ) {
				$pk_cols[] = $col;
			}
		}

		if ( empty( $text_cols ) ) {
			continue;
		}

		$pk_ok = ( 1 === count( $pk_cols ) )
			&& preg_match( '/(int|decimal|float|double|numeric)/i', $pk_cols[0]->Type );

		if ( ! $pk_ok ) {
			// Sin PK única numérica: SQL REPLACE (riesgo bajo de serializados).
			foreach ( $text_cols as $col ) {
				$where = "`{$col}` LIKE '%localhost/workshop%' OR `{$col}` LIKE '%127.0.0.1/workshop%'";
				$wpdb->query(
					"UPDATE `{$table}` SET `{$col}` = "
					. "REPLACE(REPLACE(`{$col}`,
						'http://localhost/workshop', 'http://shopup.site.je'),
						'http://127.0.0.1/workshop', 'http://shopup.site.je')"
					. " WHERE {$where}"
				);
			}
			continue;
		}

		$pk      = $pk_cols[0]->Field;
		$last_id = 0;

		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}`
					 WHERE `{$pk}` > %d
					   AND (CONCAT_WS(' ', " . implode( ', ', array_map( static function ( $c ) { return "`{$c}`"; }, $text_cols ) ) . ") LIKE '%localhost/workshop%' OR CONCAT_WS(' ', " . implode( ', ', array_map( static function ( $c ) { return "`{$c}`"; }, $text_cols ) ) . ") LIKE '%127.0.0.1/workshop%')
					 ORDER BY `{$pk}` ASC
					 LIMIT %d",
					$last_id,
					$batch
				)
			);

			if ( empty( $rows ) ) {
				break;
			}

			$last_id = (int) end( $rows )->{$pk};

			foreach ( $rows as $row ) {
				$updates  = array();
				$row_meta = (array) $row;

				foreach ( $text_cols as $col ) {
					$old = $row_meta[ $col ];
					if ( ! is_string( $old ) || '' === $old ) {
						continue;
					}
					$new = ws_prod_replace_deep( $old );
					if ( $new !== $old ) {
						$updates[ $col ] = $new;
					}
				}

				if ( empty( $updates ) ) {
					continue;
				}

				$set = array();
				foreach ( $updates as $col => $val ) {
					$set[] = "`{$col}` = " . $wpdb->prepare( '%s', $val );
				}

				$wpdb->query(
					"UPDATE `{$table}` SET " . implode( ', ', $set )
					. ' WHERE `' . esc_sql( $pk ) . '` = ' . (int) $row_meta[ $pk ]
				);
			}
		}
	}

	// Forzar las URLs maestras.
	update_option( 'siteurl', 'http://shopup.site.je' );
	update_option( 'home', 'http://shopup.site.je' );

	// Regenerar permalinks para las rutas del tema.
	flush_rewrite_rules();
}
