<?php
/**
 * Compresión de imágenes remotas.
 *
 * Descarga las imágenes cargadas desde URLs externas (p. ej. Pexels) y las
 * sirve como WebP redimensionadas y comprimidas desde una caché local en
 * uploads, para que el front-end no cargue la imagen original a máxima
 * calidad. Si la compresión falla, se devuelve la URL original.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Directorio de caché de imágenes comprimidas (uploads/ws-img-cache).
 */
function ws_img_cache_dir() {
    $up = wp_upload_dir();
    return trailingslashit( $up['basedir'] ) . 'ws-img-cache';
}

/**
 * Ancho máximo y calidad de las imágenes comprimidas (filtrables).
 */
function ws_img_max_width() {
    return (int) apply_filters( 'ws_img_max_width', 1600 );
}

function ws_img_quality() {
    return (int) apply_filters( 'ws_img_quality', 78 );
}

/**
 * URL optimizada para la descarga: a los hosts conocidos se les piden
 * versiones ya redimensionadas para no bajar el original a máxima calidad.
 */
function ws_img_download_url( $url, $max_w ) {
    $host = wp_parse_url( $url, PHP_URL_HOST );
    if ( 'images.pexels.com' === $host || 'images.unsplash.com' === $host ) {
        $sep  = ( false === strpos( $url, '?' ) ) ? '?' : '&';
        return $url . $sep . 'auto=compress&cs=tinysrgb&w=' . (int) $max_w;
    }
    return $url;
}

/**
 * Devuelve una versión comprimida (WebP) de una imagen remota.
 *
 * @param string $url URL de la imagen (http/https).
 * @return string URL comprimida en caché local, o la original si falla.
 */
function ws_image_url( $url = '' ) {
    if ( ! $url ) {
        return '';
    }
    $scheme = wp_parse_url( (string) $url, PHP_URL_SCHEME );
    if ( ! in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) ) {
        return $url;
    }
    if ( ! function_exists( 'imagewebp' ) || ! function_exists( 'imagecreatefromstring' ) ) {
        return $url;
    }

    $key  = sha1( $url . '|' . ws_img_max_width() . '|' . ws_img_quality() );
    $dir  = ws_img_cache_dir();
    $file = trailingslashit( $dir ) . $key . '.webp';

    // Caché: devuelve la copia local si ya existe.
    if ( file_exists( $file ) ) {
        return ws_img_cache_url( $file );
    }
    if ( ! wp_mkdir_p( $dir ) ) {
        return $url;
    }

    // Lock simple para evitar descargas duplicadas en concurrencia.
    $lock = trailingslashit( $dir ) . $key . '.lock';
    if ( file_exists( $lock ) && time() - filemtime( $lock ) < 60 ) {
        return $url;
    }
    @file_put_contents( $lock, (string) time() );
    register_shutdown_function( static function () use ( $lock ) {
        if ( file_exists( $lock ) ) {
            @unlink( $lock );
        }
    } );

    $response = wp_remote_get( ws_img_download_url( $url, ws_img_max_width() ), array(
        'timeout'    => 30,
        'redirection'=> 3,
        'sslverify'  => false,
        'user-agent' => 'Mozilla/5.0 (compatible; Workshop Image Proxy)',
    ) );
    if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        return $url;
    }
    $data = wp_remote_retrieve_body( $response );
    if ( '' === $data ) {
        return $url;
    }

    $src = @imagecreatefromstring( $data );
    if ( ! $src ) {
        return $url;
    }

    // Redimensiona si supera el ancho máximo, conservando la proporción.
    $max_w = max( 320, ws_img_max_width() );
    $sw    = imagesx( $src );
    $sh    = imagesy( $src );
    if ( $sw > $max_w ) {
        $nh = (int) round( $sh * ( $max_w / $sw ) );
        $dst = imagecreatetruecolor( $max_w, $nh );
        imagecopyresampled( $dst, $src, 0, 0, 0, 0, $max_w, $nh, $sw, $sh );
        imagedestroy( $src );
        $src = $dst;
    }

    $ok = imagewebp( $src, $file, ws_img_quality() );
    imagedestroy( $src );
    if ( ! $ok || ! file_exists( $file ) ) {
        return $url;
    }
    return ws_img_cache_url( $file );
}

/**
 * URL pública de un archivo de la caché de imágenes.
 */
function ws_img_cache_url( $file ) {
    $up = wp_upload_dir();
    $basedir = wp_normalize_path( trailingslashit( $up['basedir'] ) );
    $baseurl = trailingslashit( $up['baseurl'] );
    $path    = wp_normalize_path( $file );
    return 0 === strpos( $path, $basedir )
        ? $baseurl . substr( $path, strlen( $basedir ) )
        : $path;
}
