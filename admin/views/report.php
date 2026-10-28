<?php
/**
 * Informe de una campaña. Sin id: la lista de campañas enviadas para elegir.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore
$c  = $id ? DXO_Campaigns::get( $id ) : null;

if ( ! $c || in_array( $c['status'], [ 'draft', 'scheduled' ], true ) ) :
	$sent = DXO_Campaigns::query( 'sent' );
	$live = DXO_Campaigns::query( 'sending' );
	$all  = array_merge( $live, $sent );
	?>
	<div class="dxo-top"><div><div class="crumb">Orbit</div><h1><?php esc_html_e( 'Reports', 'dox-orbit' ); ?></h1></div></div>
	<div class="dxo-page">
		<div class="dxo-card" data-anim>
			<?php if ( $all ) : ?>
				<div class="dxo-table-wrap"><table class="dxo-table">
					<thead><tr><th><?php esc_html_e( 'Campaign', 'dox-orbit' ); ?></th><th class="dxo-hide-sm"><?php esc_html_e( 'Sent', 'dox-orbit' ); ?></th><th><?php esc_html_e( 'Opens', 'dox-orbit' ); ?></th><th class="dxo-hide-sm"><?php esc_html_e( 'Clicks', 'dox-orbit' ); ?></th><th class="dxo-hide-sm"><?php esc_html_e( 'Unsubscribes', 'dox-orbit' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $all as $x ) : $st = DXO_Campaigns::stats( $x['id'] ); ?>
						<tr data-href="<?php echo esc_url( DXO_Admin::url( 'report', [ 'id' => $x['id'] ] ) ); ?>">
							<td><div class="t"><?php echo esc_html( $x['subject'] ); ?></div><div class="s"><?php echo esc_html( dxo_date( $x['started_at'], 'j M Y' ) . ' · ' . DXO_Campaigns::audience_label( $x ) ); ?></div></td>
							<td class="dxo-hide-sm num"><?php echo esc_html( dxo_num( $st['sent'] ) ); ?></td>
							<td><div class="dxo-rate"><div class="dxo-meter"><i class="acc" style="width:<?php echo $st['sent'] ? round( $st['opened'] * 100 / $st['sent'] ) : 0; ?>%"></i></div><b><?php echo esc_html( dxo_pct( $st['opened'], $st['sent'] ) ); ?></b></div></td>
							<td class="dxo-hide-sm num"><?php echo esc_html( dxo_pct( $st['clicked'], $st['sent'] ) ); ?></td>
							<td class="dxo-hide-sm num"><?php echo esc_html( dxo_num( $st['unsubscribed'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			<?php else : ?>
				<div class="dxo-empty"><div class="ic"><?php echo DXO_Admin::icon( 'chart' ); // phpcs:ignore ?></div><h3><?php esc_html_e( 'No reports yet', 'dox-orbit' ); ?></h3><p><?php esc_html_e( 'When a campaign goes out, its opens, clicks and unsubscribes appear here.', 'dox-orbit' ); ?></p></div>
			<?php endif; ?>
		</div>
	</div>
	<?php return;
endif;

$st     = DXO_Campaigns::stats( $c['id'] );
$hours  = DXO_Campaigns::hourly_opens( $c );
$links  = DXO_Campaigns::link_clicks( $c['id'] );
$provs  = DXO_Campaigns::providers( $c['id'] );
$avg    = DXO_Stats::averages( 6 );
$welcome = $c['type'] === 'welcome';
$first3 = array_sum( array_slice( $hours, 0, 3 ) );
$opened24 = array_sum( $hours );

// Hora del día en que más se abrió (en la zona del sitio).
$peak = null;
if ( $opened24 ) {
	global $wpdb;
	$byhour = array_fill( 0, 24, 0 );
	foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT opened_at FROM ' . DXO_Install::table( 'recipients' ) . ' WHERE campaign_id = %d AND opened_at IS NOT NULL', $c['id'] ) ) as $o ) {
		$byhour[ (int) wp_date( 'G', strtotime( $o . ' UTC' ) ) ]++;
	}
	$peak = array_search( max( $byhour ), $byhour, true );
}

$labels = [];
if ( $c['started_at'] ) {
	$start = strtotime( $c['started_at'] . ' UTC' );
	foreach ( [ 0, 6, 12, 18, 23 ] as $h ) $labels[ $h ] = wp_date( get_option( 'time_format' ), $start + $h * HOUR_IN_SECONDS );
}
$top_links = $links ? max( 1, max( array_map( 'intval', array_column( $links, 'clicks' ) ) ) ) : 1;
// Entregados: los que salieron menos los que rebotaron. Aperturas, clics y bajas se miden sobre ellos.
$delivered = max( 0, $st['sent'] - $st['bounced'] );
$not_open  = max( 0, $delivered - $st['opened'] );
$not_click = max( 0, $delivered - $st['clicked'] );

// Diferencia con la media de las otras campañas.
$vs = '';
if ( ! $welcome && $avg && $avg['campaigns'] > 1 && $st['sent'] && $avg['sent'] > $st['sent'] ) {
	$others_rate = ( $avg['opened'] - $st['opened'] ) / max( 1, $avg['sent'] - $st['sent'] ) * 100;
	$diff        = $st['opened'] * 100 / $st['sent'] - $others_rate;
	if ( abs( $diff ) >= 1 ) {
		$vs = sprintf( $diff > 0 ? __( '%s points above your average', 'dox-orbit' ) : __( '%s points below your average', 'dox-orbit' ), number_format_i18n( abs( $diff ), 0 ) );
	}
}
?>
<div class="dxo-top">
	<div style="min-width:0"><div class="crumb"><a href="<?php echo esc_url( DXO_Admin::url( 'report' ) ); ?>"><?php esc_html_e( 'Reports', 'dox-orbit' ); ?></a></div><h1><?php echo esc_html( $c['subject'] ); ?></h1></div>
	<?php if ( DXO_Campaigns::lang_name( $c ) !== '' ) : ?><span class="dxo-pill p-info"><?php echo esc_html( DXO_Campaigns::lang_name( $c ) ); ?></span><?php endif; ?>
	<?php echo DXO_Admin::status_pill( $c, $st ); // phpcs:ignore ?>
	<div class="sp"></div>
	<?php if ( ! $welcome && in_array( $c['status'], [ 'sent', 'cancelled' ], true ) && $st['sent'] ) : ?>
		<div class="dxo-menu">
			<button type="button" class="dxo-btn dxo-btn-dark" data-menu><?php echo DXO_Admin::icon( 'refresh' ); // phpcs:ignore ?><?php esc_html_e( 'Resend', 'dox-orbit' ); ?></button>
			<div class="dxo-menu-list">
				<button type="button" data-act="resend" data-who="not_opened" data-id="<?php echo (int) $c['id']; ?>"><?php echo esc_html( sprintf( __( 'To who did not open it (%s)', 'dox-orbit' ), dxo_num( $not_open ) ) ); ?></button>
				<button type="button" data-act="resend" data-who="not_clicked" data-id="<?php echo (int) $c['id']; ?>"><?php echo esc_html( sprintf( __( 'To who did not click (%s)', 'dox-orbit' ), dxo_num( $not_click ) ) ); ?></button>
			</div>
		</div>
	<?php endif; ?>
	<button type="button" class="dxo-btn dxo-btn-gray" data-act="duplicate" data-id="<?php echo (int) $c['id']; ?>"><?php echo DXO_Admin::icon( 'copy' ); // phpcs:ignore ?><span class="dxo-hide-sm"><?php esc_html_e( 'Duplicate', 'dox-orbit' ); ?></span></button>
</div>
<div class="dxo-page">
	<?php if ( in_array( $c['status'], [ 'sending', 'paused' ], true ) ) include DXO_PATH . 'admin/views/_status.php'; ?>

	<div class="dxo-stats" data-anim>
		<div class="lead">
			<div class="n"><?php echo esc_html( dxo_pct( $st['opened'], $delivered ) ); ?></div>
			<div class="l"><?php esc_html_e( 'opened it', 'dox-orbit' ); ?></div>
			<div class="d"><?php echo esc_html( sprintf( __( '%1$s of %2$s', 'dox-orbit' ), dxo_num( $st['opened'] ), dxo_num( $delivered ) ) . ( $vs ? ' · ' . $vs : '' ) ); ?></div>
		</div>
		<div><div class="n"><?php echo esc_html( dxo_pct( $st['clicked'], $delivered ) ); ?></div><div class="l"><?php esc_html_e( 'clicked', 'dox-orbit' ); ?></div><div class="d"><?php echo esc_html( sprintf( _n( '%s person', '%s people', $st['clicked'], 'dox-orbit' ), dxo_num( $st['clicked'] ) ) ); ?></div></div>
		<div><div class="n"><?php echo esc_html( dxo_num( $delivered ) ); ?></div><div class="l"><?php esc_html_e( 'delivered', 'dox-orbit' ); ?></div><div class="d"><?php echo esc_html( $st['failed'] ? sprintf( _n( '%s failed', '%s failed', $st['failed'], 'dox-orbit' ), dxo_num( $st['failed'] ) ) : __( 'No failures', 'dox-orbit' ) ); ?></div></div>
		<div><div class="n"><?php echo esc_html( dxo_num( $st['unsubscribed'] ) ); ?></div><div class="l"><?php esc_html_e( 'unsubscribed', 'dox-orbit' ); ?></div><div class="d"><?php echo esc_html( dxo_pct( $st['unsubscribed'], $delivered, 2 ) ); ?></div></div>
		<div><div class="n"><?php echo esc_html( dxo_num( $st['bounced'] ) ); ?></div><div class="l"><?php esc_html_e( 'bounced', 'dox-orbit' ); ?></div><div class="d"><?php echo esc_html( $st['bounced'] ? dxo_pct( $st['bounced'], $st['sent'], 2 ) : ( DXO_Bounces::configured() ? __( 'None so far', 'dox-orbit' ) : __( 'Not tracked', 'dox-orbit' ) ) ); ?></div></div>
	</div>

	<div class="dxo-row" style="margin-top:20px">
		<div class="dxo-col" style="width:62%">
			<div class="dxo-card" data-anim>
				<div class="dxo-card-h"><h3><?php esc_html_e( 'First 24 hours', 'dox-orbit' ); ?></h3><div class="sp"></div><span class="small muted"><?php esc_html_e( 'Opens per hour', 'dox-orbit' ); ?></span></div>
				<div class="dxo-card-b">
					<?php if ( $opened24 ) : ?>
						<?php echo DXO_Admin::area_chart( $hours, $labels, '#141313', true ); // phpcs:ignore ?>
						<div class="dxo-note"><?php echo DXO_Admin::icon( 'info' ); // phpcs:ignore ?><span><?php
							echo esc_html( sprintf( __( '%s of the opens came in the first 3 hours.', 'dox-orbit' ), dxo_pct( $first3, $opened24, 0 ) ) );
							if ( $peak !== null ) echo ' ' . esc_html( sprintf( __( 'Your readers open most around %s.', 'dox-orbit' ), wp_date( get_option( 'time_format' ), gmmktime( $peak, 0, 0 ), new DateTimeZone( 'UTC' ) ) ) );
						?></span></div>
					<?php else : ?>
						<p class="muted" style="margin:0"><?php esc_html_e( 'No opens yet. They usually start arriving a few minutes after sending.', 'dox-orbit' ); ?></p>
					<?php endif; ?>
					<div class="dxo-note"><?php echo DXO_Admin::icon( 'info' ); // phpcs:ignore ?><span><?php esc_html_e( 'Gmail and the iPhone Mail app load the images by themselves when the email arrives, so some opens are not a person reading. Clicks are the reliable figure.', 'dox-orbit' ); ?></span></div>
				</div>
			</div>
		</div>
		<div class="dxo-col" style="width:38%">
			<div class="dxo-card" data-anim>
				<div class="dxo-card-h"><h3><?php esc_html_e( 'Where they clicked', 'dox-orbit' ); ?></h3></div>
				<div class="dxo-card-b dxo-links">
					<?php if ( $links && array_sum( array_map( 'intval', array_column( $links, 'clicks' ) ) ) ) : ?>
						<?php foreach ( array_slice( $links, 0, 8 ) as $l ) :
							$u     = wp_parse_url( $l['url'] );
							$label = ( $u['host'] ?? '' ) . ( isset( $u['path'] ) && $u['path'] !== '/' ? untrailingslashit( $u['path'] ) : '' );
							?>
							<div class="lk"><b title="<?php echo esc_attr( $l['url'] ); ?>"><?php echo esc_html( preg_replace( '/^www\./', '', $label ) ); ?></b><span class="small muted"><?php echo esc_html( dxo_num( $l['clicks'] ) ); ?></span><div class="dxo-meter"><i class="ink" style="width:<?php echo round( (int) $l['clicks'] * 100 / $top_links ); ?>%"></i></div></div>
						<?php endforeach; ?>
					<?php else : ?>
						<p class="muted" style="margin:0"><?php esc_html_e( 'No clicks yet.', 'dox-orbit' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<?php if ( $provs ) : ?>
		<div class="dxo-stats" data-anim style="margin-top:20px">
			<?php foreach ( $provs as $p ) : ?>
				<div><div class="n"><?php echo esc_html( dxo_pct( $p['opened'], $p['sent'], 0 ) ); ?></div><div class="l"><?php echo esc_html( $p['name'] ); ?></div><div class="d"><?php echo esc_html( sprintf( _n( '%s person', '%s people', $p['sent'], 'dox-orbit' ), dxo_num( $p['sent'] ) ) ); ?></div></div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( $st['bounced'] ) :
		$bounces = DXO_Campaigns::bounced_list( $c['id'], 25 );
		$kinds   = [
			'hard'  => [ 'p-bad', __( 'Does not exist', 'dox-orbit' ) ],
			'soft'  => [ 'p-warn', __( 'Temporary', 'dox-orbit' ) ],
			'block' => [ 'p-gray', __( 'Rejected', 'dox-orbit' ) ],
		];
		?>
		<div class="dxo-card" data-anim style="margin-top:20px">
			<div class="dxo-card-h"><h3><?php esc_html_e( 'Emails that bounced', 'dox-orbit' ); ?></h3><div class="sp"></div><span class="small muted"><?php echo esc_html( sprintf( __( 'Last %s', 'dox-orbit' ), dxo_num( count( $bounces ) ) ) ); ?></span></div>
			<div class="dxo-table-wrap"><table class="dxo-table">
				<thead><tr><th><?php esc_html_e( 'Address', 'dox-orbit' ); ?></th><th><?php esc_html_e( 'What happened', 'dox-orbit' ); ?></th><th class="dxo-hide-sm"><?php esc_html_e( 'Reason', 'dox-orbit' ); ?></th><th class="dxo-hide-sm"><?php esc_html_e( 'When', 'dox-orbit' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $bounces as $b ) :
					$kind = 'hard';
					$why  = (string) $b['bounce_reason'];
					if ( preg_match( '/^(hard|soft|block)(?: [0-9.]+)?: ?(.*)$/s', $why, $mm ) ) { $kind = $mm[1]; $why = $mm[2]; }
					$k = $kinds[ $kind ];
					?>
					<tr>
						<td><div class="t"><?php echo esc_html( $b['email'] ); ?></div></td>
						<td><span class="dxo-pill <?php echo esc_attr( $k[0] ); ?>"><span class="dot"></span><?php echo esc_html( $k[1] ); ?></span><?php if ( $b['sub_status'] === 'bounced' ) : ?> <span class="dxo-pill p-gray"><?php esc_html_e( 'Removed from the list', 'dox-orbit' ); ?></span><?php endif; ?></td>
						<td class="dxo-hide-sm"><div class="s" title="<?php echo esc_attr( $why ); ?>"><?php echo esc_html( wp_html_excerpt( $why, 90, '…' ) ); ?></div></td>
						<td class="dxo-hide-sm"><?php echo esc_html( dxo_ago( $b['bounced_at'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		</div>
	<?php endif; ?>

	<?php if ( $st['failed'] ) :
		global $wpdb;
		$errors = $wpdb->get_results( $wpdb->prepare( 'SELECT error, COUNT(*) n FROM ' . DXO_Install::table( 'recipients' ) . " WHERE campaign_id = %d AND status = 'failed' GROUP BY error ORDER BY n DESC LIMIT 5", $c['id'] ), ARRAY_A );
		?>
		<div class="dxo-card" data-anim style="margin-top:20px">
			<div class="dxo-card-h"><h3><?php esc_html_e( 'Why some failed', 'dox-orbit' ); ?></h3></div>
			<div class="dxo-card-b dxo-src">
				<?php foreach ( $errors as $e ) : ?>
					<div class="s"><b title="<?php echo esc_attr( $e['error'] ); ?>"><?php echo esc_html( $e['error'] ?: __( 'No reason given', 'dox-orbit' ) ); ?></b><span class="small muted"><?php echo esc_html( dxo_num( $e['n'] ) ); ?></span></div>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>
</div>
