<?php
/**
 * Al borrar el plugin desde WordPress no se borra la lista ni las campañas:
 * los suscriptores son lo más valioso y volver a instalarlo los recupera tal
 * cual. Solo se quita la tarea programada. Para borrarlo todo de verdad, las
 * tablas wp_dxo_* y las opciones dxo_* se eliminan a mano.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

wp_clear_scheduled_hook( 'dxo_send' );
wp_clear_scheduled_hook( 'dxo_send_now' );
wp_clear_scheduled_hook( 'dxo_bounces' );
wp_clear_scheduled_hook( 'dxo_bounces_more' );
delete_option( 'dxo_bounces_lock' );
delete_option( 'dxo_lock' );
