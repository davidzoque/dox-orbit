<?php
/**
 * Resumen: el estado primero (envío en curso), la lista y cómo responde.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$days   = isset( $_GET['days'] ) && in_array( (int) $_GET['days'], [ 7, 30, 90 ], true ) ? (int) $_GET['days'] : 30; // phpcs:ignore
$status = DXO_Sender::status();
$active = DXO_Stats::active_count();
$move   = DXO_Stats::movement( $days );
$growth = DXO_Stats::growth( $days );
$avg    = DXO_Stats::averages( 5 );
$health = DXO_Stats::health();
$next   = DXO_Stats::next_scheduled();
$recent = DXO_Stats::recent( 3 );
$forms  = DXO_Forms::all();
$src    = DXO_Forms::signups( 30 );
$first  = reset( $growth );
$delta  = $active - $first;

$labels = [];
$keys   = array_keys( $growth );
$marks  = $days === 7 ? [ 0, 2, 4, 6 ] : [ 0, (int) round( ( $days - 1 ) / 3 ), (int) round( 2 * ( $days - 1 ) / 3 ), $days - 1 ];
foreach ( $marks as $i ) $labels[ $i ] = wp_date( 'j M', strtotime( $keys[ $i ] . ' 12:00' ) );

$src_name = function ( $key ) use ( $forms ) {
	if ( isset( $forms[ $key ] ) ) return $forms[ $key ]['name'];
	$map = [ 'import' => __( 'Imported', 'dox-orbit' ), 'manual' => __( 'Added by hand', 'dox-orbit' ), '' => __( 'Other', 'dox-orbit' ) ];
	return $map[ $key ] ?? $key;
};
?>
<div class="dxo-top">
	<div><div class="crumb">Orbit</div><h1><?php esc_html_e( 'Overview', 'dox-orbit' ); ?></h1></div>
	<div class="sp"></div>
	<a class="dxo-btn dxo-btn-gray dxo-hide-sm" href="<?php echo esc_url( DXO_Admin::url( 'subscribers', [ 'import' => 1 ] ) ); ?>"><?php echo DXO_Admin::icon( 'download' ); // phpcs:ignore ?><?php esc_html_e( 'Import CSV', 'dox-orbit' ); ?></a>
	<a class="dxo-btn dxo-btn-dark" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dxo_new_campaign' ), 'dxo_new' ) ); ?>"><?php echo DXO_Admin::icon( 'plus' ); // phpcs:ignore ?><?php esc_html_e( 'New campaign', 'dox-orbit' ); ?></a>
</div>
<div class="dxo-page">

	<?php include DXO_PATH . 'admin/views/_status.php'; ?>

	<?php if ( ! $active && ! $recent ) : ?>
		<div class="dxo-card" data-anim>
			<div class="dxo-empty">
				<div class="ic"><?php echo DXO_Admin::icon( 'mail' ); // phpcs:ignore ?></div>
				<h3><?php esc_html_e( 'Your list starts here', 'dox-orbit' ); ?></h3>
				<p><?php esc_html_e( 'Three steps and you can send your first newsletter. Everything stays in your WordPress.', 'dox-orbit' ); ?></p>
			</div>
		</div>
		<div class="dxo-gap"></div>
		<div class="dxo-steps3" data-anim>
			<a href="<?php echo esc_url( DXO_Admin::url( 'forms' ) ); ?>"><span class="n">1</span><b><?php esc_html_e( 'Put a form on the website', 'dox-orbit' ); ?></b><?php esc_html_e( 'In a page, as a pop-up or as a bottom bar.', 'dox-orbit' ); ?></a>
			<a href="<?php echo esc_url( DXO_Admin::url( 'subscribers', [ 'import' => 1 ] ) ); ?>"><span class="n">2</span><b><?php esc_html_e( 'Bring your list', 'dox-orbit' ); ?></b><?php esc_html_e( 'A CSV from Mailchimp, Excel or wherever you have it.', 'dox-orbit' ); ?></a>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dxo_new_campaign' ), 'dxo_new' ) ); ?>"><span class="n">3</span><b><?php esc_html_e( 'Write the first campaign', 'dox-orbit' ); ?></b><?php esc_html_e( 'Send yourself a test before it goes out.', 'dox-orbit' ); ?></a>
		</div>
	<?php else : ?>

	<div class="dxo-row">
		<div class="dxo-col" style="width:66%">
			<div class="dxo-card" data-anim>
				<div class="dxo-card-b">
					<div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap">
						<div>
							<div class="muted small" style="font-weight:500"><?php esc_html_e( 'Active subscribers', 'dox-orbit' ); ?></div>
							<div class="dxo-hero-num">
								<span class="big"><?php echo esc_html( dxo_num( $active ) ); ?></span>
								<?php if ( $delta ) : ?>
									<span class="delta <?php echo $delta > 0 ? 'up' : 'down'; ?>"><?php echo esc_html( ( $delta > 0 ? '↑ ' : '↓ ' ) . sprintf( __( '%1$s in %2$d days', 'dox-orbit' ), dxo_num( abs( $delta ) ), $days ) ); ?></span>
								<?php endif; ?>
							</div>
							<div class="small muted"><?php echo esc_html( sprintf( __( '%1$s new, %2$s unsubscribed', 'dox-orbit' ), dxo_num( $move['new'] ), dxo_num( $move['gone'] ) ) ); ?><?php if ( $move['pending'] ) : ?> · <a href="<?php echo esc_url( DXO_Admin::url( 'subscribers', [ 'status' => 'pending' ] ) ); ?>"><?php echo esc_html( sprintf( _n( '%s waiting to confirm', '%s waiting to confirm', $move['pending'], 'dox-orbit' ), dxo_num( $move['pending'] ) ) ); ?></a><?php endif; ?></div>
						</div>
						<div class="dxo-seg" style="margin-left:auto">
							<?php foreach ( [ 7, 30, 90 ] as $d ) : ?>
								<a class="<?php echo $d === $days ? 'on' : ''; ?>" href="<?php echo esc_url( DXO_Admin::url( 'dashboard', [ 'days' => $d ] ) ); ?>"><?php echo esc_html( sprintf( __( '%d days', 'dox-orbit' ), $d ) ); ?></a>
							<?php endforeach; ?>
						</div>
					</div>
					<?php echo DXO_Admin::area_chart( $growth, $labels, '#FF8D27' ); // phpcs:ignore ?>
				</div>
			</div>

			<?php if ( $avg ) : ?>
				<div class="dxo-stats" data-anim>
					<div>
						<div class="n"><?php echo esc_html( dxo_pct( $avg['opened'], $avg['sent'] ) ); ?></div>
						<div class="l"><?php esc_html_e( 'Average open rate', 'dox-orbit' ); ?></div>
						<div class="d"><?php echo esc_html( sprintf( _n( 'Last campaign · Gmail and iPhone open some by themselves', 'Last %d campaigns · Gmail and iPhone open some by themselves', $avg['campaigns'], 'dox-orbit' ), $avg['campaigns'] ) ); ?></div>
					</div>
					<div>
						<div class="n"><?php echo esc_html( dxo_pct( $avg['clicked'], $avg['sent'] ) ); ?></div>
						<div class="l"><?php esc_html_e( 'Clicks', 'dox-orbit' ); ?></div>
						<div class="d"><?php echo esc_html( sprintf( _n( '%s person in the last one', '%s people in the last one', $avg['last_clicked'], 'dox-orbit' ), dxo_num( $avg['last_clicked'] ) ) ); ?></div>
					</div>
					<div>
						<div class="n"><?php echo esc_html( dxo_pct( $avg['unsub'], $avg['sent'], 2 ) ); ?></div>
						<div class="l"><?php esc_html_e( 'Unsubscribes per send', 'dox-orbit' ); ?></div>
						<div class="d"><?php esc_html_e( 'Below 0.5 % is considered healthy', 'dox-orbit' ); ?></div>
					</div>
				</div>
			<?php endif; ?>

			<div class="dxo-card" data-anim>
				<div class="dxo-card-h"><h3><?php esc_html_e( 'Latest campaigns', 'dox-orbit' ); ?></h3><div class="sp"></div><a class="dxo-btn dxo-btn-link" href="<?php echo esc_url( DXO_Admin::url( 'campaigns' ) ); ?>"><?php esc_html_e( 'See all', 'dox-orbit' ); ?> <?php echo DXO_Admin::icon( 'arrow' ); // phpcs:ignore ?></a></div>
				<?php if ( $recent ) : ?>
					<div class="dxo-table-wrap"><table class="dxo-table"><tbody>
						<?php foreach ( $recent as $c ) : $st = $c['stats']; ?>
							<tr data-href="<?php echo esc_url( DXO_Admin::url( 'report', [ 'id' => $c['id'] ] ) ); ?>">
								<td><div class="t"><?php echo esc_html( $c['subject'] ); ?></div><div class="s"><?php echo esc_html( dxo_date( $c['started_at'], 'j M' ) . ' · ' . sprintf( _n( '%s person', '%s people', $c['total'], 'dox-orbit' ), dxo_num( $c['total'] ) ) ); ?></div></td>
								<td><?php echo DXO_Admin::status_pill( $c, $st ); // phpcs:ignore ?></td>
								<td class="dxo-hide-sm"><div class="dxo-rate"><div class="dxo-meter"><i class="acc" style="width:<?php echo $st['sent'] ? round( $st['opened'] * 100 / $st['sent'] ) : 0; ?>%"></i></div><b><?php echo esc_html( dxo_pct( $st['opened'], $st['sent'] ) ); ?></b></div></td>
							</tr>
						<?php endforeach; ?>
					</tbody></table></div>
				<?php else : ?>
					<div class="dxo-card-b muted"><?php esc_html_e( 'No campaign has been sent yet.', 'dox-orbit' ); ?> <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dxo_new_campaign' ), 'dxo_new' ) ); ?>"><?php esc_html_e( 'Write the first one', 'dox-orbit' ); ?></a></div>
				<?php endif; ?>
			</div>
		</div>

		<div class="dxo-col" style="width:34%">
			<div class="dxo-card" data-anim>
				<div class="dxo-card-h"><h3><?php esc_html_e( 'Next send', 'dox-orbit' ); ?></h3></div>
				<div class="dxo-card-b">
					<?php if ( $next ) : $ts = strtotime( $next['scheduled_at'] . ' UTC' ); ?>
						<div class="dxo-next">
							<div class="date"><small><?php echo esc_html( wp_date( 'M', $ts ) ); ?></small><b><?php echo esc_html( wp_date( 'j', $ts ) ); ?></b></div>
							<div><div style="color:var(--t1);font-weight:600"><?php echo esc_html( $next['subject'] ); ?></div>
								<div class="small muted"><?php echo esc_html( wp_date( 'l ' . get_option( 'time_format' ), $ts ) . ' · ' . DXO_Campaigns::audience_label( $next ) ); ?></div>
								<a class="dxo-btn dxo-btn-link" style="margin-top:6px" href="<?php echo esc_url( DXO_Admin::url( 'edit', [ 'id' => $next['id'] ] ) ); ?>"><?php esc_html_e( 'Review it', 'dox-orbit' ); ?> <?php echo DXO_Admin::icon( 'arrow' ); // phpcs:ignore ?></a></div>
						</div>
					<?php else : ?>
						<p class="muted" style="margin:0 0 10px"><?php esc_html_e( 'Nothing scheduled.', 'dox-orbit' ); ?></p>
						<a class="dxo-btn dxo-btn-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dxo_new_campaign' ), 'dxo_new' ) ); ?>"><?php esc_html_e( 'Prepare the next one', 'dox-orbit' ); ?> <?php echo DXO_Admin::icon( 'arrow' ); // phpcs:ignore ?></a>
					<?php endif; ?>
				</div>
			</div>

			<div class="dxo-card" data-anim>
				<div class="dxo-card-h"><h3><?php esc_html_e( 'List health', 'dox-orbit' ); ?></h3><div class="sp"></div>
					<?php
					$grades = [ 'good' => [ 'p-ok', __( 'Good', 'dox-orbit' ) ], 'watch' => [ 'p-warn', __( 'Keep an eye on it', 'dox-orbit' ) ], 'bad' => [ 'p-bad', __( 'Needs attention', 'dox-orbit' ) ], 'none' => [ 'p-gray', __( 'No sends yet', 'dox-orbit' ) ] ];
					$g = $grades[ $health['grade'] ];
					?>
					<span class="dxo-pill <?php echo esc_attr( $g[0] ); ?>"><span class="dot"></span><?php echo esc_html( $g[1] ); ?></span>
				</div>
				<div class="dxo-card-b dxo-health">
					<div>
						<div class="h"><span><?php esc_html_e( 'Failed sends', 'dox-orbit' ); ?></span><b><?php echo esc_html( $health['total'] ? number_format_i18n( $health['fail_pct'], 1 ) . ' %' : '–' ); ?></b></div>
						<div class="dxo-meter"><i class="<?php echo $health['fail_pct'] > 2 ? 'amber' : ''; ?>" style="width:<?php echo min( 100, $health['fail_pct'] * 10 ); ?>%"></i></div>
						<div class="small muted" style="margin-top:4px"><?php esc_html_e( 'Last 90 days. Above 2 % hurts delivery.', 'dox-orbit' ); ?></div>
					</div>
					<div>
						<div class="h"><span><?php esc_html_e( 'Bounced', 'dox-orbit' ); ?></span><b><?php echo esc_html( $health['total'] ? number_format_i18n( $health['bounce_pct'], 1 ) . ' %' : '–' ); ?></b></div>
						<div class="dxo-meter"><i class="<?php echo $health['bounce_pct'] > 2 ? 'amber' : ''; ?>" style="width:<?php echo min( 100, $health['bounce_pct'] * 10 ); ?>%"></i></div>
						<div class="small muted" style="margin-top:4px"><?php
							if ( DXO_Bounces::configured() ) {
								echo esc_html( sprintf( _n( '%s address marked as Bounced in total.', '%s addresses marked as Bounced in total.', $health['bounced'], 'dox-orbit' ), dxo_num( $health['bounced'] ) ) );
							} else {
								esc_html_e( 'Not read yet. Turn on bounce handling in Settings to clean the list by itself.', 'dox-orbit' );
							}
						?></div>
					</div>
					<div>
						<div class="h"><span><?php esc_html_e( 'Unsubscribes', 'dox-orbit' ); ?></span><b><?php echo esc_html( $health['total'] ? number_format_i18n( $health['unsub_pct'], 2 ) . ' %' : '–' ); ?></b></div>
						<div class="dxo-meter"><i class="<?php echo $health['unsub_pct'] > 1 ? 'amber' : ''; ?>" style="width:<?php echo min( 100, $health['unsub_pct'] * 20 ); ?>%"></i></div>
						<div class="small muted" style="margin-top:4px"><?php esc_html_e( 'Gmail asks to keep spam complaints below 0.3 %.', 'dox-orbit' ); ?></div>
					</div>
					<div>
						<div class="h"><span><?php esc_html_e( 'No opens in 6 months', 'dox-orbit' ); ?></span><b><?php echo esc_html( dxo_num( $health['inactive'] ) ); ?></b></div>
						<div class="dxo-meter"><i class="amber" style="width:<?php echo $active ? min( 100, $health['inactive'] * 100 / $active ) : 0; ?>%"></i></div>
						<div class="small muted" style="margin-top:4px"><?php esc_html_e( 'Received 3 or more and opened none. Worth cleaning from time to time.', 'dox-orbit' ); ?></div>
					</div>
				</div>
			</div>

			<div class="dxo-card" data-anim>
				<div class="dxo-card-h"><h3><?php esc_html_e( 'Where they come from', 'dox-orbit' ); ?></h3><div class="sp"></div><span class="small muted"><?php esc_html_e( '30 days', 'dox-orbit' ); ?></span></div>
				<div class="dxo-card-b dxo-src">
					<?php if ( $src ) : $top = max( $src ); ?>
						<?php foreach ( array_slice( $src, 0, 5, true ) as $k => $n ) : ?>
							<div class="s"><b><?php echo esc_html( $src_name( $k ) ); ?></b><span class="small muted"><?php echo esc_html( dxo_num( $n ) ); ?></span><div class="dxo-meter"><i class="acc" style="width:<?php echo round( $n * 100 / $top ); ?>%"></i></div></div>
						<?php endforeach; ?>
					<?php else : ?>
						<p class="muted" style="margin:0"><?php esc_html_e( 'Nobody new in the last 30 days.', 'dox-orbit' ); ?> <a href="<?php echo esc_url( DXO_Admin::url( 'forms' ) ); ?>"><?php esc_html_e( 'Check the forms', 'dox-orbit' ); ?></a></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
	<?php endif; ?>
</div>
