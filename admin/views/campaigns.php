<?php
/**
 * Campañas: filtros con contador, buscador y la de bienvenida aparte.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$filter  = isset( $_GET['f'] ) ? sanitize_key( $_GET['f'] ) : 'all'; // phpcs:ignore
$search  = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore
$counts  = DXO_Campaigns::counts();
$rows    = DXO_Campaigns::query( $filter, $search );
$welcomes = DXO_Campaigns::welcomes(); // una por idioma con Polylang; una sola sin él

$filters = [
	'all'       => [ __( 'All', 'dox-orbit' ), '' ],
	'sending'   => [ __( 'Sending', 'dox-orbit' ), 'var(--accent)' ],
	'scheduled' => [ __( 'Scheduled', 'dox-orbit' ), '#2563EB' ],
	'draft'     => [ __( 'Drafts', 'dox-orbit' ), '#A1A1AA' ],
	'sent'      => [ __( 'Sent', 'dox-orbit' ), '#16A34A' ],
];
?>
<div class="dxo-top">
	<div><div class="crumb">Orbit</div><h1><?php esc_html_e( 'Campaigns', 'dox-orbit' ); ?></h1></div>
	<div class="sp"></div>
	<a class="dxo-btn dxo-btn-dark" <?php echo DXO_Admin::new_campaign_attrs(); // phpcs:ignore -- ya escapado. ?>><?php echo DXO_Admin::icon( 'plus' ); // phpcs:ignore ?><?php esc_html_e( 'New campaign', 'dox-orbit' ); ?></a>
</div>
<div class="dxo-page">
	<?php include DXO_PATH . 'admin/views/_status.php'; ?>

	<div class="dxo-toolbar" data-anim>
		<div class="dxo-filters">
			<?php foreach ( $filters as $k => $f ) : if ( $k !== 'all' && ! $counts[ $k ] ) continue; ?>
				<a class="<?php echo $filter === $k ? 'on' : ''; ?>" href="<?php echo esc_url( DXO_Admin::url( 'campaigns', [ 'f' => $k ] ) ); ?>">
					<?php if ( $f[1] ) : ?><span class="dot" style="background:<?php echo esc_attr( $f[1] ); ?>"></span><?php endif; ?>
					<?php echo esc_html( $f[0] ); ?> <em><?php echo esc_html( dxo_num( $counts[ $k ] ) ); ?></em>
				</a>
			<?php endforeach; ?>
		</div>
		<?php if ( $counts['all'] > 2 ) : ?>
			<form class="dxo-search" method="get">
				<input type="hidden" name="page" value="dox-orbit"><input type="hidden" name="view" value="campaigns"><input type="hidden" name="f" value="<?php echo esc_attr( $filter ); ?>">
				<?php echo DXO_Admin::icon( 'search' ); // phpcs:ignore ?>
				<input type="search" name="q" value="<?php echo esc_attr( $search ); ?>" data-ph="<?php esc_attr_e( 'Search by subject', 'dox-orbit' ); ?>" placeholder="<?php esc_attr_e( 'Search by subject', 'dox-orbit' ); ?>" aria-label="<?php esc_attr_e( 'Search', 'dox-orbit' ); ?>"><span class="dxo-kbd">/</span>
			</form>
		<?php endif; ?>
	</div>

	<div class="dxo-card" data-anim>
		<?php if ( $rows ) : ?>
			<div class="dxo-table-wrap"><table class="dxo-table">
				<thead><tr>
					<th><?php esc_html_e( 'Campaign', 'dox-orbit' ); ?></th>
					<th><?php esc_html_e( 'Status', 'dox-orbit' ); ?></th>
					<th class="dxo-hide-sm"><?php esc_html_e( 'Recipients', 'dox-orbit' ); ?></th>
					<th class="dxo-hide-sm"><?php esc_html_e( 'Opens', 'dox-orbit' ); ?></th>
					<th class="dxo-hide-sm"><?php esc_html_e( 'Clicks', 'dox-orbit' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $c ) :
					$st   = in_array( $c['status'], [ 'draft', 'scheduled' ], true ) ? null : DXO_Campaigns::stats( $c['id'] );
					$href = $st ? DXO_Admin::url( 'report', [ 'id' => $c['id'] ] ) : DXO_Admin::url( 'edit', [ 'id' => $c['id'] ] );
					if ( $c['status'] === 'scheduled' ) {
						$when = dxo_date( $c['scheduled_at'], 'D j M, ' . get_option( 'time_format' ) );
					} elseif ( $c['status'] === 'draft' ) {
						$when = sprintf( __( 'Edited %s', 'dox-orbit' ), dxo_ago( $c['updated_at'] ) );
					} else {
						$when = dxo_date( $c['started_at'], 'j M Y' );
					}
					?>
					<tr data-href="<?php echo esc_url( $href ); ?>">
						<td><div class="t"><?php echo esc_html( $c['subject'] !== '' ? $c['subject'] : __( '(no subject)', 'dox-orbit' ) ); ?><?php if ( $c['format'] === 'html' ) : ?> <span class="dxo-tag">HTML</span><?php endif; ?></div><div class="s"><?php echo esc_html( DXO_Campaigns::audience_label( $c ) . ' · ' . $when ); ?></div></td>
						<td><?php echo DXO_Admin::status_pill( $c, $st ); // phpcs:ignore ?></td>
						<td class="dxo-hide-sm num"><?php echo $st ? esc_html( $c['status'] === 'sending' ? dxo_num( $st['sent'] + $st['failed'] ) . ' / ' . dxo_num( $st['total'] ) : dxo_num( $st['sent'] ) ) : '<span class="muted">' . esc_html( dxo_num( count( DXO_Campaigns::audience_ids( $c ) ) ) ) . '</span>'; ?></td>
						<td class="dxo-hide-sm"><?php if ( $st && $st['sent'] ) : ?><div class="dxo-rate"><div class="dxo-meter"><i class="acc" style="width:<?php echo round( $st['opened'] * 100 / $st['sent'] ); ?>%"></i></div><b><?php echo esc_html( dxo_pct( $st['opened'], $st['sent'] ) ); ?></b></div><?php else : ?><span class="muted">–</span><?php endif; ?></td>
						<td class="dxo-hide-sm num"><?php echo $st && $st['sent'] ? esc_html( dxo_pct( $st['clicked'], $st['sent'] ) ) : '<span class="muted">–</span>'; ?></td>
						<td style="text-align:right">
							<div class="dxo-menu">
								<button type="button" class="dxo-iconbtn" data-menu aria-label="<?php esc_attr_e( 'Actions', 'dox-orbit' ); ?>"><?php echo DXO_Admin::icon( 'more' ); // phpcs:ignore ?></button>
								<div class="dxo-menu-list">
									<?php if ( in_array( $c['status'], [ 'draft', 'scheduled' ], true ) ) : ?>
										<a href="<?php echo esc_url( DXO_Admin::url( 'edit', [ 'id' => $c['id'] ] ) ); ?>"><?php echo DXO_Admin::icon( 'pen' ); // phpcs:ignore ?><?php esc_html_e( 'Edit', 'dox-orbit' ); ?></a>
									<?php else : ?>
										<a href="<?php echo esc_url( DXO_Admin::url( 'report', [ 'id' => $c['id'] ] ) ); ?>"><?php echo DXO_Admin::icon( 'chart' ); // phpcs:ignore ?><?php esc_html_e( 'Report', 'dox-orbit' ); ?></a>
									<?php endif; ?>
									<?php if ( $c['status'] === 'scheduled' ) : ?>
										<button type="button" data-act="unschedule" data-id="<?php echo (int) $c['id']; ?>"><?php echo DXO_Admin::icon( 'clock' ); // phpcs:ignore ?><?php esc_html_e( 'Back to draft', 'dox-orbit' ); ?></button>
									<?php endif; ?>
									<button type="button" data-act="duplicate" data-id="<?php echo (int) $c['id']; ?>"><?php echo DXO_Admin::icon( 'copy' ); // phpcs:ignore ?><?php esc_html_e( 'Duplicate', 'dox-orbit' ); ?></button>
									<?php if ( in_array( $c['status'], [ 'sending', 'paused' ], true ) ) : ?>
										<hr><button type="button" class="danger" data-act="cancel" data-id="<?php echo (int) $c['id']; ?>" data-confirm="<?php esc_attr_e( 'Whoever has not received it yet will not get it. This cannot be undone.', 'dox-orbit' ); ?>"><?php echo DXO_Admin::icon( 'x' ); // phpcs:ignore ?><?php esc_html_e( 'Cancel sending', 'dox-orbit' ); ?></button>
									<?php else : ?>
										<hr><button type="button" class="danger" data-act="delete_campaign" data-id="<?php echo (int) $c['id']; ?>" data-confirm="<?php echo esc_attr( $st ? __( 'The campaign and its report will be deleted. This cannot be undone.', 'dox-orbit' ) : __( 'The draft will be deleted. This cannot be undone.', 'dox-orbit' ) ); ?>"><?php echo DXO_Admin::icon( 'trash' ); // phpcs:ignore ?><?php esc_html_e( 'Delete', 'dox-orbit' ); ?></button>
									<?php endif; ?>
								</div>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		<?php elseif ( $search !== '' || $filter !== 'all' ) : ?>
			<div class="dxo-card-b muted"><?php esc_html_e( 'No campaign matches.', 'dox-orbit' ); ?> <a href="<?php echo esc_url( DXO_Admin::url( 'campaigns' ) ); ?>"><?php esc_html_e( 'See all', 'dox-orbit' ); ?></a></div>
		<?php else : ?>
			<div class="dxo-empty">
				<div class="ic"><?php echo DXO_Admin::icon( 'send' ); // phpcs:ignore ?></div>
				<h3><?php esc_html_e( 'No campaigns yet', 'dox-orbit' ); ?></h3>
				<p><?php esc_html_e( 'Write the first one. It saves by itself as you type, and you can send yourself a test before it goes out.', 'dox-orbit' ); ?></p>
				<a class="dxo-btn dxo-btn-dark" <?php echo DXO_Admin::new_campaign_attrs(); // phpcs:ignore -- ya escapado. ?>><?php echo DXO_Admin::icon( 'plus' ); // phpcs:ignore ?><?php esc_html_e( 'New campaign', 'dox-orbit' ); ?></a>
			</div>
		<?php endif; ?>
	</div>

	<?php foreach ( $welcomes as $welcome ) :
		$wst   = DXO_Campaigns::stats( $welcome['id'] );
		$wlang = DXO_Campaigns::lang_name( $welcome );
		?>
	<div class="dxo-gap"></div>
	<div class="dxo-card" data-anim>
		<div class="dxo-card-h">
			<h3><?php esc_html_e( 'Welcome email', 'dox-orbit' ); ?></h3>
			<?php if ( $wlang !== '' ) : ?><span class="dxo-pill p-info"><?php echo esc_html( $wlang ); ?></span><?php endif; ?>
			<?php echo DXO_Admin::status_pill( $welcome ); // phpcs:ignore ?>
			<div class="sp"></div>
			<a class="dxo-btn dxo-btn-gray dxo-btn-sm" href="<?php echo esc_url( DXO_Admin::url( 'edit', [ 'id' => $welcome['id'] ] ) ); ?>"><?php echo DXO_Admin::icon( 'pen' ); // phpcs:ignore ?><?php esc_html_e( 'Edit', 'dox-orbit' ); ?></a>
			<label class="dxo-sw" title="<?php esc_attr_e( 'On / off', 'dox-orbit' ); ?>"><input type="checkbox" data-welcome="<?php echo (int) $welcome['id']; ?>" <?php checked( $welcome['status'], 'active' ); ?> aria-label="<?php esc_attr_e( 'Send the welcome email', 'dox-orbit' ); ?>"><span></span></label>
		</div>
		<div class="dxo-card-b" style="display:flex;gap:24px;flex-wrap:wrap;align-items:center">
			<div style="flex:1 1 260px"><div class="t" style="color:var(--t1);font-weight:600"><?php echo esc_html( $welcome['subject'] ); ?></div><div class="small muted"><?php echo esc_html( $wlang !== '' ? __( 'Goes out by itself in this language when someone confirms their subscription.', 'dox-orbit' ) : __( 'Goes out by itself when someone confirms their subscription.', 'dox-orbit' ) ); ?></div></div>
			<div class="small"><b style="color:var(--t1)"><?php echo esc_html( dxo_num( $wst['sent'] ) ); ?></b> <?php esc_html_e( 'sent', 'dox-orbit' ); ?> · <b style="color:var(--t1)"><?php echo esc_html( dxo_pct( $wst['opened'], $wst['sent'] ) ); ?></b> <?php esc_html_e( 'opened', 'dox-orbit' ); ?> · <a href="<?php echo esc_url( DXO_Admin::url( 'report', [ 'id' => $welcome['id'] ] ) ); ?>"><?php esc_html_e( 'Report', 'dox-orbit' ); ?></a></div>
		</div>
	</div>
	<?php endforeach; ?>
</div>
