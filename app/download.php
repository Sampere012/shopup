<?php
/**
 * Descarga del APK de ShopUp Panel.
 *
 * InfinityFree sirve un challenge anti-bot (403) para archivos estáticos como
 * .apk cuando la petición no viene de un navegador con JS (p. ej. el descargador
 * interno de la app móvil, que usa HttpURLConnection). Este script PHP hace
 * streaming del binario con las cabeceras correctas, así la app y el footer
 * pueden descargar/instalar la actualización desde cualquier cliente.
 */

$file = __DIR__ . '/shopup-panel.apk';

if ( ! is_file( $file ) || ! is_readable( $file ) ) {
	http_response_code( 404 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	exit( 'APK no disponible' );
}

$size = filesize( $file );

header( 'Content-Type: application/vnd.android.package-archive' );
header( 'Content-Disposition: attachment; filename="shopup-panel.apk"' );
header( 'Content-Length: ' . $size );
header( 'Accept-Ranges: none' );
header( 'Cache-Control: public, max-age=3600' );
header( 'X-Content-Type-Options: nosniff' );

while ( ob_get_level() > 0 ) {
	ob_end_clean();
}

readfile( $file );
exit;