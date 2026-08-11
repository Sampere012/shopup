<?php
/**
 * Logs de la aplicación.
 *
 * Registra en un archivo (.logs/app.log dentro del tema, o en uploads como
 * respaldo) TODO lo que ocurre en la app: info, advertencias y errores, con
 * archivo/línea, usuario, IP, negocio y contexto. El administrador del sitio
 * los ve desde wp-admin > ShopUp > Logs (solo manage_options), puede filtrar,
 * copiar, descargar o vaciar el archivo. Los errores graves además avisan al
 * admin por notificación (el chatbot los muestra).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

define( 'WS_LOG_LEVELS', array( 'DEBUG' => 0, 'INFO' => 1, 'NOTICE' => 2, 'WARNING' => 3, 'ERROR' => 4, 'FATAL' => 5 ) );

/* -------------------------------------------------------------------------
 * Escritura
 * ---------------------------------------------------------------------- */

/**
 * Directorio de logs: .logs dentro del tema; si no se puede escribir, usa
 * uploads (crea el directorio y protege el acceso web la primera vez).
 */
function ws_log_dir() {
    static $dir = null;
    if ( null !== $dir ) {
        return $dir;
    }
    $candidates = array(
        WS_PATH . '.logs',
        WP_CONTENT_DIR . '/uploads/workshop-logs',
    );
    foreach ( $candidates as $c ) {
        if ( wp_mkdir_p( $c ) && wp_is_writable( $c ) ) {
            // Protege el directorio del acceso web directo.
            $ht = $c . '/.htaccess';
            if ( ! file_exists( $ht ) ) {
                @file_put_contents( $ht, "Require all denied\nDeny from all\n" );
            }
            if ( ! file_exists( $c . '/index.php' ) ) {
                @file_put_contents( $c . '/index.php', "<?php // Silence is golden.\n" );
            }
            $dir = $c;
            return $dir;
        }
    }
    $dir = '';
    return $dir;
}

/** Ruta al archivo de log activo. */
function ws_log_file() {
    $dir = ws_log_dir();
    return $dir ? $dir . '/app.log' : '';
}

/**
 * Escribe una entrada de log. Niveles: DEBUG, INFO, NOTICE, WARNING, ERROR, FATAL.
 *
 * @param string $level   Nivel.
 * @param string $message Mensaje.
 * @param array  $context Contexto extra (se guarda como JSON).
 */
function ws_log( $level, $message, $context = array() ) {
    static $writing = false;
    if ( $writing ) { return; } // Evita recursión si falla la escritura.
    $level = strtoupper( (string) $level );
    if ( ! isset( WS_LOG_LEVELS[ $level ] ) ) { $level = 'INFO'; }

    $file = ws_log_file();
    if ( ! $file ) { return; }

    $entry = array(
        't' => current_time( 'mysql' ),
        'l' => $level,
        'm' => mb_substr( (string) $message, 0, 600 ),
        'f' => (string) ( $context['file'] ?? '' ),
        'n' => (int) ( $context['line'] ?? 0 ),
        'u' => get_current_user_id(),
        'ip'=> (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' ),
        'b' => '',
        'c' => array(),
    );
    if ( '' === $entry['ip'] && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        $entry['ip'] = trim( (string) explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
    }
    if ( '' === $entry['ip'] ) { $entry['ip'] = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); }
    try {
        if ( function_exists( 'ws_current_business_id' ) && ws_current_business_id() ) {
            $entry['b'] = (string) ws_current_business_id();
        }
    } catch ( \Throwable $e ) { /* sin negocio, no pasa nada */ }

    unset( $context['file'], $context['line'] );
    $entry['c'] = $context;

    $writing = true;
    $line    = wp_json_encode( $entry, JSON_UNESCAPED_UNICODE ) . "\n";
    @file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );

    // Rota cuando supera ~2 MB (mantiene app.1.log del anterior).
    if ( @filesize( $file ) > 2 * 1024 * 1024 ) {
        @rename( $file, dirname( $file ) . '/app.1.log' );
        @file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
    }
    $writing = false;

    // Errores graves: aviso al administrador del sitio (el bot lo muestra).
    // Guard propio: si la notificación falla y dispara un error, no recursar.
    if ( in_array( $level, array( 'ERROR', 'FATAL' ), true ) ) {
        static $notifying = false;
        if ( ! $notifying ) {
            $notifying = true;
            try {
                ws_log_notify_admin( $level, $message, $entry );
            } catch ( \Throwable $e ) {
                // El log del error ya quedó escrito; no avisar es aceptable.
            }
            $notifying = false;
        }
    }
}

/** Atajos. */
function ws_log_info( $m, $c = array() ) { ws_log( 'INFO', $m, $c ); }
function ws_log_warning( $m, $c = array() ) { ws_log( 'WARNING', $m, $c ); }
function ws_log_error( $m, $c = array() ) { ws_log( 'ERROR', $m, $c ); }

/**
 * Avisa al administrador del sitio por notificación (máx. 1 por hora y por
 * tipo de error) para que el chatbot se lo muestre sin inundar.
 */
function ws_log_notify_admin( $level, $message, $entry ) {
    $key = 'ws_log_alert_' . md5( substr( (string) $message, 0, 60 ) . $entry['f'] . $entry['n'] );
    if ( get_transient( $key ) ) { return; }
    set_transient( $key, 1, HOUR_IN_SECONDS );
    $admins = get_users( array( 'capability' => 'manage_options', 'fields' => 'ID', 'number' => 5 ) );
    if ( empty( $admins ) || ! function_exists( 'ws_chatbot_notify_user' ) ) { return; }
    $where = $entry['f'] ? ' en ' . basename( (string) $entry['f'] ) . ( $entry['n'] ? ':' . $entry['n'] : '' ) : '';
    $msg   = mb_substr( (string) $message, 0, 220 ) . $where . ( $entry['ip'] ? ' · IP ' . $entry['ip'] : '' );
    foreach ( $admins as $uid ) {
        ws_chatbot_notify_user( (int) $uid, '⚠️ ' . $level . ' en la web', $msg, 'ws_log_' . $key );
    }
}

/* -------------------------------------------------------------------------
 * Captura de errores PHP (errores + fatales), registrada al cargar el tema
 * ---------------------------------------------------------------------- */

/** Traduce un código de error PHP a nivel de log. */
function ws_log_level_for_errno( $errno ) {
    if ( E_ERROR === $errno || E_PARSE === $errno || E_CORE_ERROR === $errno || E_COMPILE_ERROR === $errno || E_USER_ERROR === $errno ) { return 'ERROR'; }
    if ( E_WARNING === $errno || E_USER_WARNING === $errno || E_CORE_WARNING === $errno || E_COMPILE_WARNING === $errno ) { return 'WARNING'; }
    if ( E_NOTICE === $errno || E_USER_NOTICE === $errno ) { return 'NOTICE'; }
    return 'DEBUG';
}

/** Handler de errores PHP no fatales. */
function ws_log_error_handler( $errno, $errstr, $errfile, $errline ) {
    if ( ! ( error_reporting() & $errno ) ) { return false; }
    // Solo se registran los errores de la app (tema). Los fatales —causa
    // típica del 500— sí se capturan de cualquier origen en el shutdown.
    if ( defined( 'WS_PATH' ) && 0 !== strpos( (string) $errfile, WS_PATH ) ) {
        return false;
    }
    // Los avisos (notices) solo se registran con WP_DEBUG, para no llenar el log.
    $level = ws_log_level_for_errno( $errno );
    if ( 'NOTICE' === $level && ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) ) {
        return false;
    }
    ws_log( $level, (string) $errstr, array( 'file' => (string) $errfile, 'line' => (int) $errline ) );
    return false; // Deja que PHP/WordPress continúe con su manejo normal.
}

/** Handler de shutdown: captura errores fatales (los típicos 500) y de BD. */
function ws_log_shutdown() {
    $last = error_get_last();
    if ( $last && in_array( $last['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
        ws_log( 'FATAL', (string) $last['message'], array( 'file' => (string) $last['file'], 'line' => (int) $last['line'] ) );
    }
    // Error de base de datos del último query (causa frecuente de 500).
    global $wpdb;
    if ( isset( $wpdb ) && ! empty( $wpdb->last_error ) ) {
        ws_log( 'ERROR', 'Error de base de datos: ' . (string) $wpdb->last_error );
    }
}

// Se registran al cargar el tema para capturar errores tempranos.
set_error_handler( 'ws_log_error_handler' );
register_shutdown_function( 'ws_log_shutdown' );

/** Registra los inicios de sesión exitosos como INFO. */
add_action( 'wp_login', 'ws_log_login_ok', 10, 2 );
function ws_log_login_ok( $user_login, $user ) {
    ws_log( 'INFO', 'Inicio de sesión exitoso: ' . sanitize_user( $user_login ), array( 'user' => $user->ID ) );
}

/* -------------------------------------------------------------------------
 * Visor en wp-admin (solo administradores)
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', 'ws_logs_admin_menu', 40 );
function ws_logs_admin_menu() {
    add_submenu_page(
        'ws-permissions',
        __( 'Logs', 'workshop' ),
        __( 'Logs', 'workshop' ),
        'manage_options',
        'ws-logs',
        'ws_admin_page_logs'
    );
}

/**
 * Estadísticas del log para un período: conteo por nivel y los últimos
 * ERROR/FATAL del período con mensaje y archivo:línea. Se usa en el resumen
 * diario del chatbot y en el reporte de logs bajo demanda.
 */
function ws_log_daily_stats( $days = 1 ) {
    $days   = max( 1, (int) $days );
    $start  = gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp' ) - ( $days - 1 ) * DAY_IN_SECONDS );
    $counts = array( 'DEBUG' => 0, 'INFO' => 0, 'NOTICE' => 0, 'WARNING' => 0, 'ERROR' => 0, 'FATAL' => 0 );
    $severe = array(); // ERROR/FATAL del período (nuevo -> viejo).

    $file = ws_log_file();
    if ( ! $file ) {
        return array( 'counts' => $counts, 'severe' => $severe );
    }

    // El log activo + el rotado (app.1.log) del día anterior.
    $files = array( $file );
    $rot   = dirname( $file ) . '/app.1.log';
    if ( file_exists( $rot ) ) {
        $files[] = $rot;
    }

    foreach ( $files as $f ) {
        $raw = @file( $f, FILE_IGNORE_NEW_LINES );
        if ( ! is_array( $raw ) ) {
            continue;
        }
        foreach ( array_reverse( $raw ) as $line ) {
            $line = trim( (string) $line );
            if ( '' === $line ) {
                continue;
            }
            $e = json_decode( $line, true );
            if ( ! is_array( $e ) ) {
                continue;
            }
            $t = (string) ( $e['t'] ?? '' );
            if ( $t < $start ) {
                continue;
            }
            $l = (string) ( $e['l'] ?? 'INFO' );
            if ( isset( $counts[ $l ] ) ) {
                $counts[ $l ]++;
            }
            if ( in_array( $l, array( 'ERROR', 'FATAL' ), true ) && count( $severe ) < 10 ) {
                $severe[] = array(
                    't' => $t,
                    'l' => $l,
                    'm' => (string) ( $e['m'] ?? '' ),
                    'f' => (string) ( $e['f'] ?? '' ),
                    'n' => (int) ( $e['n'] ?? 0 ),
                );
            }
        }
    }
    return array( 'counts' => $counts, 'severe' => $severe );
}

/** Últimas N líneas del log (nuevo -> viejo), parseadas a arrays. */
function ws_log_tail( $file, $max_lines = 300 ) {
    if ( ! $file || ! file_exists( $file ) ) { return array(); }
    $size   = filesize( $file );
    $chunk  = min( $size, 200 * 1024 );
    $fh     = fopen( $file, 'r' );
    if ( ! $fh ) { return array(); }
    fseek( $fh, max( 0, $size - $chunk ) );
    if ( $size > $chunk ) { fgets( $fh ); } // descarta la línea parcial del inicio
    $raw = stream_get_contents( $fh );
    fclose( $fh );
    $lines = preg_split( '/\r?\n/', $raw );
    $out   = array();
    foreach ( array_reverse( $lines ) as $line ) {
        $line = trim( (string) $line );
        if ( '' === $line ) { continue; }
        $json = json_decode( $line, true );
        $out[] = is_array( $json ) ? $json : array( 't' => '', 'l' => 'INFO', 'm' => $line, 'f' => '', 'n' => 0, 'u' => 0, 'ip' => '', 'b' => '', 'c' => array() );
        if ( count( $out ) >= $max_lines ) { break; }
    }
    return $out;
}

/** Página de logs. */
function ws_admin_page_logs() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    $file    = ws_log_file();
    $level_f = isset( $_GET['level'] ) ? sanitize_key( $_GET['level'] ) : '';
    $search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
    $all     = ws_log_tail( $file, 400 );
    $counts  = array( 'INFO' => 0, 'NOTICE' => 0, 'WARNING' => 0, 'ERROR' => 0, 'FATAL' => 0, 'DEBUG' => 0 );
    foreach ( $all as $e ) {
        $l = (string) ( $e['l'] ?? 'INFO' );
        if ( isset( $counts[ $l ] ) ) { $counts[ $l ]++; }
    }
    $entries = $all;
    if ( $level_f && isset( WS_LOG_LEVELS[ $level_f ] ) ) {
        $entries = array_values( array_filter( $entries, function ( $e ) use ( $level_f ) { return ( $e['l'] ?? '' ) === $level_f; } ) );
    }
    if ( '' !== $search ) {
        $entries = array_values( array_filter( $entries, function ( $e ) use ( $search ) {
            return false !== stripos( (string) ( $e['m'] ?? '' ), $search ) || false !== stripos( (string) ( $e['f'] ?? '' ), $search );
        } ) );
    }
    $nonce_dl   = wp_create_nonce( 'ws_logs_dl' );
    $nonce_clr  = wp_create_nonce( 'ws_logs_clr' );
    $badge = array( 'INFO' => 'blue', 'NOTICE' => 'gray', 'WARNING' => 'orange', 'ERROR' => 'red', 'FATAL' => 'red', 'DEBUG' => 'gray' );
    ?>
    <div class="wrap">
        <h1>📋 Logs de la aplicación
            <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=ws_logs_download&_wpnonce=' . $nonce_dl ) ); ?>" class="page-title-action">Descargar app.log</a>
            <button type="button" class="page-title-action" id="ws-logs-copy">Copiar visibles</button>
        </h1>
        <?php if ( $file ) : ?>
            <p style="color:#646970">Archivo: <code><?php echo esc_html( str_replace( ABSPATH, '', $file ) ); ?></code> · Tamaño: <?php echo esc_html( size_format( @filesize( $file ) ?: 0 ) ); ?>
                · Últimos 400 eventos · <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ws_logs_clear' ), 'ws_logs_clr' ) ); ?>" style="color:#d63638" onclick="return confirm('¿Vaciar el archivo de logs? Esta acción no se puede deshacer.')">Vaciar logs</a></p>
        <?php else : ?>
            <p style="color:#d63638">No se pudo crear el directorio de logs (permisos de escritura). Revisa los permisos del tema o de wp-content/uploads.</p>
        <?php endif; ?>

        <form method="get" style="margin:12px 0;display:flex;gap:8px;align-items:center">
            <input type="hidden" name="page" value="ws-logs">
            <select name="level">
                <option value="">Todos los niveles</option>
                <?php foreach ( array_keys( $counts ) as $lv ) : ?>
                    <option value="<?php echo esc_attr( $lv ); ?>" <?php selected( $level_f, $lv ); ?>><?php echo esc_html( $lv ); ?> (<?php echo (int) $counts[ $lv ]; ?>)</option>
                <?php endforeach; ?>
            </select>
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Buscar en mensajes…">
            <button type="submit" class="button">Filtrar</button>
        </form>

        <table class="widefat striped" id="ws-logs-table">
            <thead>
                <tr><th style="width:150px">Fecha</th><th style="width:80px">Nivel</th><th>Mensaje</th><th style="width:180px">Origen</th><th style="width:120px">Usuario/IP</th></tr>
            </thead>
            <tbody>
            <?php if ( empty( $entries ) ) : ?>
                <tr><td colspan="5">Sin eventos. Cuando ocurra algo (avisos, errores, inicios de sesión…) aparecerá aquí.</td></tr>
            <?php endif; ?>
            <?php foreach ( $entries as $e ) : ?>
                <?php
                $lvl = (string) ( $e['l'] ?? 'INFO' );
                $src = ( (string) ( $e['f'] ?? '' ) ? basename( (string) $e['f'] ) : '' ) . ( $e['n'] ? ':' . (int) $e['n'] : '' );
                $ctx = (array) ( $e['c'] ?? array() );
                $ctx['user_id'] = (int) ( $e['u'] ?? 0 );
                $ctx['ip'] = (string) ( $e['ip'] ?? '' );
                if ( $e['b'] ) { $ctx['business_id'] = (string) $e['b']; }
                ?>
                <tr class="ws-log-row" data-json="<?php echo esc_attr( wp_json_encode( array( 't' => $e['t'], 'l' => $lvl, 'm' => $e['m'], 'src' => $src, 'ctx' => $ctx ), JSON_UNESCAPED_UNICODE ) ); ?>">
                    <td><?php echo esc_html( (string) $e['t'] ); ?></td>
                    <td><span class="ws-log-badge ws-log-<?php echo esc_attr( $badge[ $lvl ] ?? 'gray' ); ?>"><?php echo esc_html( $lvl ); ?></span></td>
                    <td><strong><?php echo esc_html( (string) $e['m'] ); ?></strong>
                        <?php if ( $ctx ) : ?>
                            <details style="margin-top:4px"><summary style="cursor:pointer;color:#2271b1;font-size:12px">contexto</summary>
                                <pre style="background:#f6f7f7;padding:8px;font-size:11px;overflow:auto;white-space:pre-wrap"><?php echo esc_html( wp_json_encode( $ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
                            </details>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $src ); ?></td>
                    <td><?php echo (int) ( $e['u'] ?? 0 ) ? 'user#' . (int) $e['u'] : '—'; ?> <?php echo $e['ip'] ? '<br><code>' . esc_html( $e['ip'] ) . '</code>' : ''; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <style>
        .ws-log-badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:700; color:#fff; }
        .ws-log-red { background:#d63638; } .ws-log-orange { background:#dba617; }
        .ws-log-blue { background:#2271b1; } .ws-log-gray { background:#787c82; }
        #ws-logs-table td { vertical-align: top; }
        #ws-logs-table td:nth-child(3) { max-width: 620px; word-break: break-word; }
    </style>
    <script>
    (function () {
        var btn = document.getElementById('ws-logs-copy');
        if (!btn) { return; }
        btn.addEventListener('click', function () {
            var rows = Array.prototype.map.call(document.querySelectorAll('#ws-logs-table .ws-log-row'), function (r) {
                var d = r.getAttribute('data-json');
                if (!d) { return ''; }
                try { var o = JSON.parse(d); return '[' + o.t + '] [' + o.l + '] ' + o.m + (o.src ? ' (' + o.src + ')' : '') + (o.ctx ? ' ' + JSON.stringify(o.ctx) : ''); } catch (e) { return d; }
            }).filter(Boolean).join('\n');
            if (!rows) { return; }
            (navigator.clipboard ? navigator.clipboard.writeText(rows) : Promise.reject()).then(function () { alert('Logs copiados (' + rows.split('\n').length + ' líneas). Pégalos aquí en el chat.'); }).catch(function () {
                var ta = document.createElement('textarea'); ta.value = rows; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); alert('Logs copiados.');
            });
        });
    })();
    </script>
    <?php
}

/** Descarga del archivo de logs (solo admin). */
add_action( 'admin_post_ws_logs_download', 'ws_logs_download_handler' );
function ws_logs_download_handler() {
    if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ws_logs_dl' ) ) {
        wp_die( 'No autorizado.' );
    }
    $file = ws_log_file();
    if ( ! $file || ! file_exists( $file ) ) { wp_die( 'Sin logs todavía.' ); }
    header( 'Content-Type: text/plain; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="app-' . gmdate( 'Ymd-His' ) . '.log"' );
    header( 'Content-Length: ' . filesize( $file ) );
    readfile( $file );
    exit;
}

/** Vacía el archivo de logs (solo admin). */
add_action( 'admin_post_ws_logs_clear', 'ws_logs_clear_handler' );
function ws_logs_clear_handler() {
    if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ws_logs_clr' ) ) {
        wp_die( 'No autorizado.' );
    }
    $file = ws_log_file();
    if ( $file ) { @file_put_contents( $file, '' ); }
    wp_safe_redirect( admin_url( 'admin.php?page=ws-logs&cleared=1' ) );
    exit;
}
