<?php
/**
 * El marco del panel: navegación a la izquierda y la vista a la derecha.
 *
 * @var string $view
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$counts_c = DXO_Campaigns::counts();
$active   = DXO_Stats::active_count();
$settings = DXO_Settings::all();

$nav = [
	'dashboard'   => [ __( 'Overview', 'dox-orbit' ), 'pulse', '' ],
	'campaigns'   => [ __( 'Campaigns', 'dox-orbit' ), 'send', $counts_c['all'] ? dxo_num( $counts_c['all'] ) : '' ],
	'subscribers' => [ __( 'Subscribers', 'dox-orbit' ), 'users', $active ? dxo_num( $active ) : '' ],
	'forms'       => [ __( 'Forms', 'dox-orbit' ), 'form', '' ],
	'report'      => [ __( 'Reports', 'dox-orbit' ), 'chart', '' ],
];
$current = $view === 'edit' ? 'campaigns' : $view;

// Primeros pasos: se tachan solos y la caja desaparece cuando están todos.
$steps = [
	[ __( 'Sender and address', 'dox-orbit' ), $settings['address'] !== '', DXO_Admin::url( 'settings' ) ],
	[ __( 'A form on the website', 'dox-orbit' ), (bool) array_diff_key( DXO_Forms::signups( 3650 ), [ 'import' => 1, 'manual' => 1 ] ) || (bool) array_filter( DXO_Forms::all(), function ( $f ) { return $f['placement'] !== 'inline' && $f['enabled']; } ), DXO_Admin::url( 'forms' ) ],
	[ __( 'First subscribers', 'dox-orbit' ), $active > 0, DXO_Admin::url( 'subscribers' ) ],
	[ __( 'First campaign', 'dox-orbit' ), (bool) DXO_Stats::recent( 1 ), DXO_Admin::url( 'campaigns' ) ],
];
$steps_left = count( array_filter( $steps, function ( $s ) { return ! $s[1]; } ) );
?>
<div class="dxo-app" id="dxo-app" data-view="<?php echo esc_attr( $view ); ?>">
	<script>document.getElementById('dxo-app').classList.add('dxo-js');</script>
	<nav class="dxo-nav" aria-label="Dox Orbit">
		<div class="dxo-brand">
			<?php echo DXO_Admin::logo(); // phpcs:ignore -- SVG propio del plugin ?>
			<small>Dox Plugins · <?php echo esc_html( DXO_VERSION ); ?></small>
		</div>
		<?php foreach ( $nav as $key => $n ) : ?>
			<a href="<?php echo esc_url( DXO_Admin::url( $key ) ); ?>" class="<?php echo $current === $key ? 'on' : ''; ?>">
				<?php echo DXO_Admin::icon( $n[1] ); // phpcs:ignore ?><?php echo esc_html( $n[0] ); ?>
				<?php if ( $n[2] !== '' ) : ?><span class="count"><?php echo esc_html( $n[2] ); ?></span><?php endif; ?>
			</a>
			<?php if ( $key === 'campaigns' ) : ?>
				<a <?php echo DXO_Admin::new_campaign_attrs(); // phpcs:ignore -- ya escapado. ?> class="<?php echo $view === 'edit' && empty( $_GET['id'] ) ? 'on' : ''; // phpcs:ignore ?>"><?php echo DXO_Admin::icon( 'pen' ); // phpcs:ignore ?><?php esc_html_e( 'New campaign', 'dox-orbit' ); ?></a>
			<?php endif; ?>
		<?php endforeach; ?>
		<div class="sep"></div>
		<a href="<?php echo esc_url( DXO_Admin::url( 'settings' ) ); ?>" class="<?php echo $current === 'settings' ? 'on' : ''; ?>"><?php echo DXO_Admin::icon( 'gear' ); // phpcs:ignore ?><?php esc_html_e( 'Settings', 'dox-orbit' ); ?></a>

		<?php if ( $steps_left ) : ?>
			<div class="help">
				<b><?php esc_html_e( 'First steps', 'dox-orbit' ); ?></b>
				<ol>
					<?php foreach ( $steps as $s ) : ?>
						<li class="<?php echo $s[1] ? 'done' : ''; ?>"><?php if ( ! $s[1] ) : ?><a href="<?php echo esc_url( $s[2] ); ?>"><?php endif; ?><?php echo esc_html( $s[0] ); ?><?php if ( ! $s[1] ) : ?></a><?php endif; ?></li>
					<?php endforeach; ?>
				</ol>
			</div>
		<?php else : ?>
			<div class="help">
				<b><?php echo $settings['transport'] === 'ses' ? 'Amazon SES' : esc_html__( 'WordPress email', 'dox-orbit' ); ?></b>
				<p><?php echo esc_html( sprintf( __( 'Sending at up to %s per hour.', 'dox-orbit' ), dxo_num( $settings['rate_per_hour'] ) ) ); ?></p>
				<a class="dxo-btn dxo-btn-link small" href="<?php echo esc_url( DXO_Admin::url( 'settings' ) ); ?>"><?php esc_html_e( 'Change', 'dox-orbit' ); ?> <?php echo DXO_Admin::icon( 'arrow' ); // phpcs:ignore ?></a>
			</div>
		<?php endif; ?>
	</nav>

	<main class="dxo-main">
		<div class="dxo-mnav">
			<?php foreach ( $nav + [ 'settings' => [ __( 'Settings', 'dox-orbit' ) ] ] as $key => $n ) : ?>
				<a href="<?php echo esc_url( DXO_Admin::url( $key ) ); ?>" class="<?php echo $current === $key ? 'on' : ''; ?>"><?php echo esc_html( $n[0] ); ?></a>
			<?php endforeach; ?>
		</div>
		<?php if ( ! DXO_Sanitizer::available() ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'This server has no PHP DOM extension, so Orbit cannot clean HTML code safely. The HTML block and HTML campaigns are turned off; blocks work as always. Ask your host to enable the DOM (php-xml) extension.', 'dox-orbit' ); ?></p></div>
		<?php endif; ?>
		<?php include DXO_PATH . 'admin/views/' . $view . '.php'; ?>
	</main>
</div>

<dialog class="dxo-modal" id="dxo-modal"><form method="dialog"><div class="hd"><h3></h3></div><div class="bd"></div><div class="ft"></div></form></dialog>
<div class="dxo-toast" id="dxo-toast" role="status" aria-live="polite"></div>
