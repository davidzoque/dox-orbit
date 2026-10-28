<?php
/**
 * Plugin Name:       Dox Orbit
 * Plugin URI:        https://doxstudio.com
 * Description:       Email marketing for WordPress and WooCommerce, from your own site: subscription forms with double opt-in, a block editor or your own HTML code, sending in batches through the site's email, and opens, clicks and unsubscribes in every report.
 * Version:           0.5.2
 * Author:            Dox Studio
 * Author URI:        https://doxstudio.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dox-orbit
 * Domain Path:       /languages
 * Requires at least: 6.5
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Update URI:        https://github.com/davidzoque/dox-orbit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DXO_VERSION', '0.5.2' );
define( 'DXO_FILE', __FILE__ );
define( 'DXO_PATH', plugin_dir_path( __FILE__ ) );
define( 'DXO_URL', plugin_dir_url( __FILE__ ) );

/** Versión de un archivo de assets/ para la URL: cambia en cuanto cambia el archivo. */
function dxo_asset_ver( $file ) {
	$path = DXO_PATH . 'assets/' . $file;
	return DXO_VERSION . ( file_exists( $path ) ? '.' . filemtime( $path ) : '' );
}

// ─── Traducciones ─────────────────────────────────────────────────────────────
// El plugin está escrito en inglés y trae el español (es_ES). WordPress no pasa de
// es_CO, es_MX o es_AR a es_ES por su cuenta: sin esto, una web en español de
// Colombia lo vería en inglés. Una traducción propia (Loco Translate) sigue mandando.
add_action( 'init', function () {
	load_plugin_textdomain( 'dox-orbit', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}, 1 );

// Desde WP 6.5, switch_to_locale() y restore_previous_locale() descargan los textos y
// los recargan solos "a demanda", y esa carga no pasa por la ruta del plugin ni por el
// filtro de abajo (es_CO saldría en inglés). Al cambiar de idioma se vuelve a cargar a mano.
add_action( 'change_locale', function () {
	unload_textdomain( 'dox-orbit', true );
	load_plugin_textdomain( 'dox-orbit', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

add_filter( 'load_textdomain_mofile', function ( $mofile, $domain ) {
	if ( 'dox-orbit' !== $domain ) return $mofile;
	if ( ! preg_match( '/dox-orbit-es(_[A-Za-z]+)?\.mo$/', (string) $mofile ) ) return $mofile;
	if ( is_readable( $mofile ) || is_readable( substr( $mofile, 0, -3 ) . '.l10n.php' ) ) return $mofile;

	$es = DXO_PATH . 'languages/dox-orbit-es_ES.mo';
	return is_readable( $es ) ? $es : $mofile;
}, 10, 2 );

// ─── Menú común de los plugins de Dox Studio ──────────────────────────────────
// Con file_exists: si la carpeta llegara a medias (una subida cortada), el plugin
// sigue en pie y usa su propio menú (ver DXO_Admin::fallback_menu).
if ( file_exists( DXO_PATH . 'dox-core/loader.php' ) && file_exists( DXO_PATH . 'dox-core/version.php' ) ) {
	require_once DXO_PATH . 'dox-core/loader.php';
	if ( class_exists( 'Dox_Core_Loader' ) ) {
		Dox_Core_Loader::register( require DXO_PATH . 'dox-core/version.php', DXO_PATH . 'dox-core/dox-core.php' );
	}
}

// ─── Cargar archivos ───────────────────────────────────────────────────────────
require_once DXO_PATH . 'includes/class-dxo-install.php';
require_once DXO_PATH . 'includes/class-dxo-settings.php';
require_once DXO_PATH . 'includes/class-dxo-renderer.php';
require_once DXO_PATH . 'includes/class-dxo-sanitizer.php';
require_once DXO_PATH . 'includes/class-dxo-inliner.php';
require_once DXO_PATH . 'includes/class-dxo-html-email.php';
require_once DXO_PATH . 'includes/class-dxo-subscribers.php';
require_once DXO_PATH . 'includes/class-dxo-campaigns.php';
require_once DXO_PATH . 'includes/class-dxo-mailer.php';
require_once DXO_PATH . 'includes/class-dxo-sender.php';
require_once DXO_PATH . 'includes/class-dxo-bounce-parser.php';
require_once DXO_PATH . 'includes/class-dxo-imap.php';
require_once DXO_PATH . 'includes/class-dxo-bounces.php';
require_once DXO_PATH . 'includes/class-dxo-forms.php';
require_once DXO_PATH . 'includes/class-dxo-public.php';
require_once DXO_PATH . 'includes/class-dxo-stats.php';
if ( is_admin() ) {
	require_once DXO_PATH . 'includes/class-dxo-admin.php';
}

register_activation_hook( __FILE__, [ 'DXO_Install', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'DXO_Install', 'deactivate' ] );

// Las tablas se ponen al día solas cuando cambia la versión: una actualización
// desde GitHub no pasa por la activación.
add_action( 'plugins_loaded', [ 'DXO_Install', 'maybe_upgrade' ] );

DXO_Sender::init();
DXO_Bounces::init();
DXO_Public::init();
DXO_Forms::init();
if ( is_admin() ) {
	DXO_Admin::init();
}

// ─── Widget de Elementor ───────────────────────────────────────────────────────
add_action( 'elementor/widgets/register', function ( $manager ) {
	require_once DXO_PATH . 'includes/class-dxo-elementor.php';
	$manager->register( new DXO_Elementor_Widget() );
} );

// ─── Auto-actualizaciones desde GitHub (Plugin Update Checker) ────────────────
// El plugin se actualiza desde las releases del repo, no desde WordPress.org.
$dxo_puc = DXO_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $dxo_puc ) ) {
	require_once $dxo_puc;
	$dxo_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/davidzoque/dox-orbit/',
		__FILE__,
		'dox-orbit'
	);
	$dxo_update_checker->setBranch( 'main' );
	// Usa el ZIP limpio que el workflow de GitHub Actions adjunta a cada release.
	$dxo_update_checker->getVcsApi()->enableReleaseAssets();
	if ( defined( 'DXO_GITHUB_TOKEN' ) && DXO_GITHUB_TOKEN ) {
		$dxo_update_checker->setAuthentication( DXO_GITHUB_TOKEN );
	}
}
