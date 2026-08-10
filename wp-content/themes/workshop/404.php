<?php
/**
 * 404 de WordPress (URLs no capturadas por el router).
 *
 * Reutiliza el template de error del tema para que el 404 sea coherente
 * con el resto de la web.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

include WS_PATH . 'templates/404.php';
