<?php
/**
 * Ajustes: envío, remitente y marca, confirmación, seguimiento y pie.
 * Una barra flotante abajo avisa de los cambios sin guardar y guarda.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$s       = DXO_Settings::all();
$tab     = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'sending'; // phpcs:ignore
$tabs    = [
	'sending' => __( 'Sending', 'dox-orbit' ),
	'bounces' => __( 'Bounces', 'dox-orbit' ),
	'sender'  => __( 'Sender and brand', 'dox-orbit' ),
	'confirm' => __( 'Confirmation', 'dox-orbit' ),
	'footer'  => __( 'Tracking and footer', 'dox-orbit' ),
];
if ( ! isset( $tabs[ $tab ] ) ) $tab = 'sending';
$test_to = get_user_meta( get_current_user_id(), 'dxo_test_to', true ) ?: wp_get_current_user()->user_email;
$logo    = $s['logo_id'] ? wp_get_attachment_image_url( (int) $s['logo_id'], 'medium' ) : '';

// Qué plugin de correo usa la web, para decir por dónde sale de verdad.
$smtp = '';
foreach ( [ 'gosmtp/gosmtp.php' => 'GoSMTP', 'wp-mail-smtp/wp_mail_smtp.php' => 'WP Mail SMTP', 'fluent-smtp/fluent-smtp.php' => 'FluentSMTP', 'post-smtp/postman-smtp.php' => 'Post SMTP', 'easy-wp-smtp/easy-wp-smtp.php' => 'Easy WP SMTP' ] as $file => $name ) {
	if ( is_plugin_active( $file ) ) { $smtp = $name; break; }
}
$rate  = (int) $s['rate_per_hour'];
$limit = max( 1, (int) $s['server_limit'] );
$left  = max( 0, $limit - $rate );
?>
<div class="dxo-top">
	<div><div class="crumb">Orbit</div><h1><?php esc_html_e( 'Settings', 'dox-orbit' ); ?></h1></div>
</div>
<div class="dxo-page narrow">
	<div class="dxo-tabs" data-anim role="tablist">
		<?php foreach ( $tabs as $k => $t ) : ?>
			<button type="button" role="tab" data-tab="<?php echo esc_attr( $k ); ?>" class="<?php echo $tab === $k ? 'on' : ''; ?>"><?php echo esc_html( $t ); ?></button>
		<?php endforeach; ?>
	</div>

	<form id="dxo-settings" onsubmit="return false" autocomplete="off">

		<!-- ═══ Envío ═══ -->
		<section class="dxo-tab" data-tab-panel="sending" <?php echo $tab !== 'sending' ? 'hidden' : ''; ?>>
			<div class="dxo-card" data-anim>
				<div class="dxo-card-h"><h3><?php esc_html_e( 'How the emails go out', 'dox-orbit' ); ?></h3></div>
				<div class="dxo-card-b">
					<div class="dxo-choices">
						<label class="dxo-choice"><input type="radio" name="transport" value="wp" <?php checked( $s['transport'], 'wp' ); ?>>
							<div class="ic"><?php echo DXO_Admin::icon( 'mail' ); // phpcs:ignore ?></div>
							<b><?php esc_html_e( 'The WordPress email', 'dox-orbit' ); ?></b>
							<p><?php esc_html_e( 'The same one WooCommerce and the forms use. No cost and no new accounts. For lists of up to a few thousand.', 'dox-orbit' ); ?></p>
							<span class="dxo-pill p-ok"><span class="dot"></span><?php echo $smtp ? esc_html( sprintf( __( 'Now goes out through %s', 'dox-orbit' ), $smtp ) ) : esc_html__( 'Goes out through the server', 'dox-orbit' ); ?></span>
						</label>
						<label class="dxo-choice"><input type="radio" name="transport" value="ses" <?php checked( $s['transport'], 'ses' ); ?>>
							<div class="ic"><?php echo DXO_Admin::icon( 'cloud' ); // phpcs:ignore ?></div>
							<b>Amazon SES</b>
							<p><?php esc_html_e( 'For big lists. About 0.10 USD per 1,000 emails, and it does not use up the server quota.', 'dox-orbit' ); ?></p>
							<span class="dxo-pill <?php echo $s['ses_user'] ? 'p-ok' : 'p-gray'; ?>"><?php echo $s['ses_user'] ? esc_html__( 'Configured', 'dox-orbit' ) : esc_html__( 'Not configured', 'dox-orbit' ); ?></span>
						</label>
					</div>
					<div data-ses <?php echo $s['transport'] !== 'ses' ? 'hidden' : ''; ?> style="margin-top:14px">
						<div class="dxo-field"><label for="st-host"><?php esc_html_e( 'SMTP server', 'dox-orbit' ); ?></label><div><input class="dxo-input" id="st-host" name="ses_host" value="<?php echo esc_attr( $s['ses_host'] ); ?>"><div class="dxo-hint"><span><?php esc_html_e( 'In the SES console, «SMTP settings». It depends on the region: email-smtp.us-east-2.amazonaws.com is Ohio.', 'dox-orbit' ); ?></span></div></div></div>
						<div class="dxo-field"><label for="st-port"><?php esc_html_e( 'Port', 'dox-orbit' ); ?></label><input class="dxo-input" id="st-port" type="number" name="ses_port" value="<?php echo (int) $s['ses_port']; ?>" style="max-width:120px"></div>
						<div class="dxo-field"><label for="st-user"><?php esc_html_e( 'SMTP user', 'dox-orbit' ); ?></label><input class="dxo-input" id="st-user" name="ses_user" value="<?php echo esc_attr( $s['ses_user'] ); ?>" autocomplete="off"></div>
						<div class="dxo-field"><label for="st-pass"><?php esc_html_e( 'SMTP password', 'dox-orbit' ); ?></label><div><input class="dxo-input" id="st-pass" type="password" name="ses_pass" value="" autocomplete="new-password" placeholder="<?php echo $s['ses_pass'] ? esc_attr__( 'Saved. Leave it empty to keep it.', 'dox-orbit' ) : ''; ?>"><div class="dxo-hint"><span><?php esc_html_e( 'It is saved encrypted and never shown again. When saving, the connection with Amazon is checked.', 'dox-orbit' ); ?></span></div></div></div>
					</div>
				</div>
			</div>

			<div class="dxo-gap"></div>
			<div class="dxo-card" data-anim>
				<div class="dxo-card-h"><h3><?php esc_html_e( 'Sending speed', 'dox-orbit' ); ?></h3><div class="sp"></div><span class="dxo-pill p-acc" id="dxo-speed-pill"><?php echo esc_html( sprintf( __( '%s per hour', 'dox-orbit' ), dxo_num( $rate ) ) ); ?></span></div>
				<div class="dxo-card-b">
					<p style="margin:0 0 4px" id="dxo-speed-intro"><?php echo wp_kses( sprintf( __( 'Your server lets out <b>%s emails per hour</b> per website. Whatever the newsletter does not use stays free for orders, passwords and forms.', 'dox-orbit' ), '<span data-limit>' . dxo_num( $limit ) . '</span>' ), [ 'b' => [], 'span' => [ 'data-limit' => [] ] ] ); ?></p>
					<input type="range" min="10" max="<?php echo (int) max( $limit, $rate ); ?>" step="10" name="rate_per_hour" value="<?php echo (int) $rate; ?>" id="dxo-speed" aria-label="<?php esc_attr_e( 'Emails per hour', 'dox-orbit' ); ?>">
					<div class="dxo-quota">
						<div class="track"><div class="a" id="dxo-qa" style="width:<?php echo min( 100, $rate * 100 / $limit ); ?>%"></div><div class="b" id="dxo-qb"></div></div>
						<div class="legend"><span id="dxo-eta"></span><span><?php esc_html_e( 'Server limit:', 'dox-orbit' ); ?> <input type="number" name="server_limit" value="<?php echo (int) $limit; ?>" min="10" class="dxo-input" style="width:90px;height:26px!important;min-height:26px;display:inline-block;padding:0 6px!important;font-size:12px!important"> / <?php esc_html_e( 'hour', 'dox-orbit' ); ?></span></div>
					</div>
					<div class="dxo-note" id="dxo-speed-warn"><?php echo DXO_Admin::icon( 'info' ); // phpcs:ignore ?><span></span></div>
				</div>
			</div>

			<div class="dxo-gap"></div>
			<div class="dxo-card" data-anim>
				<div class="dxo-card-h"><h3><?php esc_html_e( 'Test', 'dox-orbit' ); ?></h3></div>
				<div class="dxo-card-b">
					<p style="margin:0 0 12px"><?php esc_html_e( 'Sends the confirmation email with these settings, even if they are not saved yet.', 'dox-orbit' ); ?></p>
					<div class="dxo-inline-input" style="flex-wrap:wrap">
						<input class="dxo-input" style="flex:1;min-width:220px" type="email" id="dxo-test-to" value="<?php echo esc_attr( $test_to ); ?>" aria-label="<?php esc_attr_e( 'Test email', 'dox-orbit' ); ?>">
						<button type="button" class="dxo-btn dxo-btn-gray" data-settings-test><?php esc_html_e( 'Send test email', 'dox-orbit' ); ?></button>
					</div>
				</div>
			</div>
		</section>

		<!-- ═══ Rebotes ═══ -->
		<section class="dxo-tab" data-tab-panel="bounces" <?php echo $tab !== 'bounces' ? 'hidden' : ''; ?>>
			<div class="dxo-card" data-anim>
				<div class="dxo-card-h"><h3><?php esc_html_e( 'Bounced emails', 'dox-orbit' ); ?></h3><div class="sp"></div>
					<span class="dxo-pill <?php echo DXO_Bounces::configured( $s ) ? 'p-ok' : 'p-gray'; ?>"><span class="dot"></span><?php echo DXO_Bounces::configured( $s ) ? esc_html__( 'On', 'dox-orbit' ) : esc_html__( 'Off', 'dox-orbit' ); ?></span>
				</div>
				<div class="dxo-card-b">
					<p style="margin:0 0 4px"><?php esc_html_e( 'When an address does not exist or the mailbox is full, the other server sends the email back. Without this, those returns get lost and Orbit keeps writing to dead addresses, which hurts your reputation with Gmail and Outlook.', 'dox-orbit' ); ?></p>
					<p style="margin:0"><?php esc_html_e( 'Create a mailbox only for this (in cPanel: Email Accounts), for example bounces@yourdomain.com, and put its address and password here. Orbit checks it every hour, marks the addresses that do not exist as Bounced so it stops writing to them, and tidies the mailbox.', 'dox-orbit' ); ?></p>
					<div class="dxo-opt" style="margin-top:6px"><div class="tx"><b><?php esc_html_e( 'Handle bounces automatically', 'dox-orbit' ); ?></b><span><?php esc_html_e( 'Every email from Orbit will use the mailbox below as its return address. The rest of the website emails are not touched.', 'dox-orbit' ); ?></span></div>
						<label class="dxo-sw"><input type="checkbox" name="bounce_enabled" value="1" <?php checked( $s['bounce_enabled'] ); ?>><span></span></label></div>
				</div>
			</div>

			<div class="dxo-gap"></div>
			<div class="dxo-card" data-anim>
				<div class="dxo-card-h"><h3><?php esc_html_e( 'The mailbox', 'dox-orbit' ); ?></h3></div>
				<div class="dxo-card-b tight">
					<div class="dxo-field"><label for="st-be"><?php esc_html_e( 'Email', 'dox-orbit' ); ?></label><div><input class="dxo-input" id="st-be" type="email" name="bounce_email" value="<?php echo esc_attr( $s['bounce_email'] ); ?>" placeholder="<?php echo esc_attr( 'bounces@' . preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ); ?>" autocomplete="off"><div class="dxo-hint"><span><?php esc_html_e( 'A mailbox created only for this. Do not use your personal one: Orbit deletes the bounce messages it reads.', 'dox-orbit' ); ?></span></div></div></div>
					<div class="dxo-opt"><div class="tx"><b><?php esc_html_e( 'Use plus addressing (VERP)', 'dox-orbit' ); ?></b><span><?php esc_html_e( 'Most mailboxes accept name+anything@ (cPanel, Gmail and Microsoft 365 do): it lets Orbit know which email bounced. Turn it off only if bounces stop arriving.', 'dox-orbit' ); ?></span></div>
						<label class="dxo-sw"><input type="checkbox" name="bounce_verp" value="1" <?php checked( $s['bounce_verp'] ); ?>><span></span></label></div>
					<div class="dxo-field"><label for="st-ih"><?php esc_html_e( 'IMAP server', 'dox-orbit' ); ?></label><div><input class="dxo-input" id="st-ih" name="imap_host" value="<?php echo esc_attr( $s['imap_host'] ); ?>" placeholder="mail.yourdomain.com" autocomplete="off"><div class="dxo-hint"><span><?php esc_html_e( 'In cPanel, in the mailbox «Connect Devices», it is the incoming server. The connection is always encrypted.', 'dox-orbit' ); ?></span></div></div></div>
					<div class="dxo-field"><label for="st-ip"><?php esc_html_e( 'Port', 'dox-orbit' ); ?></label><input class="dxo-input" id="st-ip" type="number" name="imap_port" value="<?php echo (int) $s['imap_port']; ?>" style="max-width:120px"></div>
					<div class="dxo-field"><label for="st-iu"><?php esc_html_e( 'User', 'dox-orbit' ); ?></label><div><input class="dxo-input" id="st-iu" name="imap_user" value="<?php echo esc_attr( $s['imap_user'] ); ?>" placeholder="<?php esc_attr_e( 'The same email', 'dox-orbit' ); ?>" autocomplete="off"><div class="dxo-hint"><span><?php esc_html_e( 'Leave it empty if the user is the full email address, which is the usual.', 'dox-orbit' ); ?></span></div></div></div>
					<div class="dxo-field"><label for="st-iw"><?php esc_html_e( 'Password', 'dox-orbit' ); ?></label><div><input class="dxo-input" id="st-iw" type="password" name="imap_pass" value="" autocomplete="new-password" placeholder="<?php echo $s['imap_pass'] ? esc_attr__( 'Saved. Leave it empty to keep it.', 'dox-orbit' ) : ''; ?>"><div class="dxo-hint"><span><?php esc_html_e( 'It is saved encrypted and never shown again.', 'dox-orbit' ); ?></span></div></div></div>
					<div class="dxo-field"><span class="lbl"></span><div><button type="button" class="dxo-btn dxo-btn-gray" data-bounce-test><?php esc_html_e( 'Test connection', 'dox-orbit' ); ?></button><div class="dxo-hint"><span id="dxo-bounce-result"><?php esc_html_e( 'It logs in and counts the messages in the inbox, with what is on the screen even if it is not saved yet.', 'dox-orbit' ); ?></span></div></div></div>
					<?php if ( $s['transport'] === 'ses' ) : ?>
						<div class="dxo-note"><?php echo DXO_Admin::icon( 'info' ); // phpcs:ignore ?><span><?php esc_html_e( 'You are sending with Amazon SES: Orbit does not change the return address and SES receives the bounces itself, so this mailbox will not get them.', 'dox-orbit' ); ?></span></div>
					<?php endif; ?>
					<?php
					$dom = function ( $e ) { return strtolower( (string) substr( strrchr( (string) $e, '@' ), 1 ) ); };
					if ( is_email( $s['bounce_email'] ) && is_email( $s['from_email'] ) && $dom( $s['bounce_email'] ) !== $dom( $s['from_email'] ) ) : ?>
						<div class="dxo-note"><?php echo DXO_Admin::icon( 'info' ); // phpcs:ignore ?><span><?php echo esc_html( sprintf( __( 'The mailbox is on %1$s but the emails come from %2$s. For SPF to line up, use a mailbox on the same domain as the sender.', 'dox-orbit' ), $dom( $s['bounce_email'] ), $dom( $s['from_email'] ) ) ); ?></span></div>
					<?php endif; ?>
				</div>
			</div>

			<div class="dxo-gap"></div>
			<div class="dxo-card" data-anim>
				<div class="dxo-card-h"><h3><?php esc_html_e( 'Last check', 'dox-orbit' ); ?></h3></div>
				<div class="dxo-card-b">
					<?php
					$bs = DXO_Bounces::last_status();
					$bounced_total = DXO_Subscribers::counts()['bounced'];
					if ( $bs ) : ?>
						<p style="margin:0 0 6px">
							<span class="dxo-pill <?php echo ! empty( $bs['ok'] ) ? 'p-ok' : 'p-bad'; ?>"><span class="dot"></span><?php echo ! empty( $bs['ok'] ) ? esc_html__( 'Worked', 'dox-orbit' ) : esc_html__( 'Failed', 'dox-orbit' ); ?></span>
							<span class="muted" style="margin-left:6px"><?php echo esc_html( dxo_date( $bs['time'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) . ' · ' . dxo_ago( $bs['time'] ) ); ?></span>
						</p>
						<?php if ( ! empty( $bs['ok'] ) ) : ?>
							<p style="margin:0"><?php echo esc_html( sprintf(
								/* translators: 1: messages read, 2: bounces found, 3: don't exist, 4: temporary */
								__( '%1$s messages read, %2$s bounces found (%3$s do not exist, %4$s temporary).', 'dox-orbit' ),
								dxo_num( $bs['checked'] ), dxo_num( $bs['found'] ), dxo_num( $bs['hard'] ), dxo_num( $bs['soft'] )
							) ); ?></p>
							<?php if ( ! empty( $bs['unverified'] ) ) : ?>
								<p class="muted" style="margin:4px 0 0"><?php echo esc_html( sprintf( __( '%s ignored because they could not be verified as coming from an email of this site.', 'dox-orbit' ), dxo_num( $bs['unverified'] ) ) ); ?></p>
							<?php endif; ?>
						<?php else : ?>
							<p style="margin:0;color:var(--bad)"><?php echo esc_html( $bs['message'] ); ?></p>
						<?php endif; ?>
					<?php else : ?>
						<p class="muted" style="margin:0"><?php esc_html_e( 'Not checked yet. Once this is on, Orbit checks the mailbox every hour.', 'dox-orbit' ); ?></p>
					<?php endif; ?>
					<div class="dxo-note"><?php echo DXO_Admin::icon( 'info' ); // phpcs:ignore ?><span><?php echo esc_html( sprintf( _n( '%s address is marked as Bounced and will not get more emails.', '%s addresses are marked as Bounced and will not get more emails.', $bounced_total, 'dox-orbit' ), dxo_num( $bounced_total ) ) ); ?> <a href="<?php echo esc_url( DXO_Admin::url( 'subscribers', [ 'status' => 'bounced' ] ) ); ?>"><?php esc_html_e( 'See them', 'dox-orbit' ); ?></a> · <?php esc_html_e( 'A temporary problem, like a full mailbox, is only a warning. After 3 warnings without the person opening anything, the address is marked as Bounced.', 'dox-orbit' ); ?></span></div>
				</div>
			</div>
		</section>

		<!-- ═══ Remitente y marca ═══ -->
		<section class="dxo-tab" data-tab-panel="sender" <?php echo $tab !== 'sender' ? 'hidden' : ''; ?>>
			<div class="dxo-card">
				<div class="dxo-card-h"><h3><?php esc_html_e( 'Who it comes from', 'dox-orbit' ); ?></h3></div>
				<div class="dxo-card-b tight">
					<div class="dxo-field"><label class="req" for="st-fn"><?php esc_html_e( 'Name', 'dox-orbit' ); ?></label><div><input class="dxo-input" id="st-fn" name="from_name" value="<?php echo esc_attr( $s['from_name'] ); ?>"><div class="dxo-hint"><span><?php esc_html_e( 'A person\'s name is opened more than a company\'s: «Ana from Acme» better than «Acme Newsletter».', 'dox-orbit' ); ?></span></div></div></div>
					<div class="dxo-field"><label class="req" for="st-fe"><?php esc_html_e( 'Email', 'dox-orbit' ); ?></label><div><input class="dxo-input" id="st-fe" type="email" name="from_email" value="<?php echo esc_attr( $s['from_email'] ); ?>"><div class="dxo-hint"><span><?php echo esc_html( sprintf( __( 'Better an address @%s: Gmail checks that the sender matches the domain.', 'dox-orbit' ), preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) ); ?></span></div></div></div>
					<div class="dxo-field"><label for="st-rt"><?php esc_html_e( 'Reply to', 'dox-orbit' ); ?></label><div><input class="dxo-input" id="st-rt" type="email" name="reply_to" value="<?php echo esc_attr( $s['reply_to'] ); ?>" placeholder="<?php esc_attr_e( 'The same email', 'dox-orbit' ); ?>"><div class="dxo-hint"><span><?php esc_html_e( 'Where replies arrive, if it is a different address.', 'dox-orbit' ); ?></span></div></div></div>
				</div>
			</div>
			<div class="dxo-gap"></div>
			<div class="dxo-card">
				<div class="dxo-card-h"><h3><?php esc_html_e( 'Brand in the email', 'dox-orbit' ); ?></h3></div>
				<div class="dxo-card-b tight">
					<div class="dxo-field"><span class="lbl"><?php esc_html_e( 'Logo', 'dox-orbit' ); ?></span><div class="dxo-imgpick" data-logo>
						<img src="<?php echo esc_url( $logo ?: '' ); ?>" alt="" <?php echo $logo ? '' : 'hidden'; ?> style="width:auto;max-width:160px;height:40px;object-fit:contain;padding:4px">
						<input type="hidden" name="logo_id" value="<?php echo (int) $s['logo_id']; ?>">
						<button type="button" class="dxo-btn dxo-btn-gray dxo-btn-sm" data-logo-pick><?php echo $logo ? esc_html__( 'Change', 'dox-orbit' ) : esc_html__( 'Choose', 'dox-orbit' ); ?></button>
						<button type="button" class="dxo-btn dxo-btn-link" data-logo-clear <?php echo $logo ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'dox-orbit' ); ?></button>
					</div></div>
					<div class="dxo-field"><label for="st-co"><?php esc_html_e( 'Company', 'dox-orbit' ); ?></label><div><input class="dxo-input" id="st-co" name="company" value="<?php echo esc_attr( $s['company'] ); ?>"><div class="dxo-hint"><span><?php esc_html_e( 'At the top if there is no logo, and in the footer.', 'dox-orbit' ); ?></span></div></div></div>
					<div class="dxo-field"><label class="req" for="st-ad"><?php esc_html_e( 'Postal address', 'dox-orbit' ); ?></label><div><input class="dxo-input" id="st-ad" name="address" value="<?php echo esc_attr( $s['address'] ); ?>" placeholder="<?php esc_attr_e( 'Street, city, state, ZIP', 'dox-orbit' ); ?>"><div class="dxo-hint"><span><?php esc_html_e( 'The CAN-SPAM law in the US requires a real postal address in every marketing email: a street address, a PO box or a private mailbox.', 'dox-orbit' ); ?></span></div></div></div>
					<div class="dxo-field"><label for="st-ac"><?php esc_html_e( 'Brand color', 'dox-orbit' ); ?></label><div class="dxo-inline-input"><input type="color" name="accent" value="<?php echo esc_attr( $s['accent'] ); ?>" style="width:44px;height:36px;border:1px solid var(--line);border-radius:10px;padding:2px;background:#fff" id="st-ac"><span class="unit"><?php esc_html_e( 'For the «brand color» buttons and the glow of the forms.', 'dox-orbit' ); ?></span></div></div>
				</div>
			</div>
		</section>

		<!-- ═══ Confirmación ═══ -->
		<section class="dxo-tab" data-tab-panel="confirm" <?php echo $tab !== 'confirm' ? 'hidden' : ''; ?>>
			<div class="dxo-card">
				<div class="dxo-card-b" style="padding-top:4px;padding-bottom:4px">
					<div class="dxo-opt"><div class="tx"><b><?php esc_html_e( 'Double confirmation', 'dox-orbit' ); ?></b><span><?php esc_html_e( 'Whoever subscribes receives an email to confirm. It keeps fake and mistyped addresses out, which is what protects your reputation with Gmail. Recommended.', 'dox-orbit' ); ?></span></div>
						<label class="dxo-sw"><input type="checkbox" name="double_optin" value="1" <?php checked( $s['double_optin'] ); ?>><span></span></label></div>
				</div>
			</div>
			<div class="dxo-gap"></div>
			<div class="dxo-card">
				<div class="dxo-card-h"><h3><?php esc_html_e( 'The confirmation email', 'dox-orbit' ); ?></h3></div>
				<div class="dxo-card-b tight">
					<div class="dxo-field"><label for="st-cs"><?php esc_html_e( 'Subject', 'dox-orbit' ); ?></label><input class="dxo-input" id="st-cs" name="confirm_subject" value="<?php echo esc_attr( $s['confirm_subject'] ); ?>"></div>
					<div class="dxo-field"><label for="st-ch"><?php esc_html_e( 'Title', 'dox-orbit' ); ?></label><input class="dxo-input" id="st-ch" name="confirm_heading" value="<?php echo esc_attr( $s['confirm_heading'] ); ?>"></div>
					<div class="dxo-field"><label for="st-ct"><?php esc_html_e( 'Text', 'dox-orbit' ); ?></label><textarea class="dxo-input" id="st-ct" name="confirm_text" rows="3"><?php echo esc_textarea( $s['confirm_text'] ); ?></textarea></div>
					<div class="dxo-field"><label for="st-cb"><?php esc_html_e( 'Button', 'dox-orbit' ); ?></label><input class="dxo-input" id="st-cb" name="confirm_button" value="<?php echo esc_attr( $s['confirm_button'] ); ?>"></div>
				</div>
			</div>
			<div class="dxo-gap"></div>
			<div class="dxo-card">
				<div class="dxo-card-h"><h3><?php esc_html_e( 'The page after confirming', 'dox-orbit' ); ?></h3></div>
				<div class="dxo-card-b tight">
					<div class="dxo-field"><label for="st-pt"><?php esc_html_e( 'Title', 'dox-orbit' ); ?></label><input class="dxo-input" id="st-pt" name="confirmed_title" value="<?php echo esc_attr( $s['confirmed_title'] ); ?>"></div>
					<div class="dxo-field"><label for="st-px"><?php esc_html_e( 'Text', 'dox-orbit' ); ?></label><textarea class="dxo-input" id="st-px" name="confirmed_text" rows="2"><?php echo esc_textarea( $s['confirmed_text'] ); ?></textarea></div>
				</div>
			</div>
		</section>

		<!-- ═══ Seguimiento y pie ═══ -->
		<section class="dxo-tab" data-tab-panel="footer" <?php echo $tab !== 'footer' ? 'hidden' : ''; ?>>
			<div class="dxo-card">
				<div class="dxo-card-b" style="padding-top:4px;padding-bottom:4px">
					<div class="dxo-opt"><div class="tx"><b><?php esc_html_e( 'Count opens', 'dox-orbit' ); ?></b><span><?php esc_html_e( 'With an invisible image. Gmail and the iPhone load it by themselves, so it is a figure on the high side.', 'dox-orbit' ); ?></span></div><label class="dxo-sw"><input type="checkbox" name="track_opens" value="1" <?php checked( $s['track_opens'] ); ?>><span></span></label></div>
					<div class="dxo-opt"><div class="tx"><b><?php esc_html_e( 'Count clicks', 'dox-orbit' ); ?></b><span><?php esc_html_e( 'The links go through your website for an instant before reaching their destination.', 'dox-orbit' ); ?></span></div><label class="dxo-sw"><input type="checkbox" name="track_clicks" value="1" <?php checked( $s['track_clicks'] ); ?>><span></span></label></div>
				</div>
			</div>
			<div class="dxo-gap"></div>
			<div class="dxo-card">
				<div class="dxo-card-h"><h3><?php esc_html_e( 'Footer of the emails', 'dox-orbit' ); ?></h3></div>
				<div class="dxo-card-b tight">
					<div class="dxo-field"><label for="st-fw"><?php esc_html_e( 'Why they receive it', 'dox-orbit' ); ?></label><div><input class="dxo-input" id="st-fw" name="footer_why" value="<?php echo esc_attr( $s['footer_why'] ); ?>"><div class="dxo-hint"><span><?php esc_html_e( 'Below it always go the unsubscribe link and the postal address.', 'dox-orbit' ); ?></span></div></div></div>
				</div>
			</div>
		</section>

		<div class="dxo-savebar">
			<span class="state" id="dxo-settings-state"><?php esc_html_e( 'Everything saved', 'dox-orbit' ); ?></span>
			<span class="sp"></span>
			<button type="button" class="dxo-btn dxo-btn-gray" data-settings-reset hidden><?php esc_html_e( 'Discard', 'dox-orbit' ); ?></button>
			<button type="button" class="dxo-btn dxo-btn-dark" data-settings-save><?php esc_html_e( 'Save', 'dox-orbit' ); ?></button>
		</div>
	</form>
</div>
<script type="application/json" id="dxo-set-strings"><?php echo wp_json_encode( [
	'perHour'  => __( '%s per hour', 'dox-orbit' ),
	'news'     => __( 'Newsletter %s', 'dox-orbit' ),
	'rest'     => __( 'Rest of the website %s', 'dox-orbit' ),
	'eta'      => __( '%1$s people take about %2$s hours', 'dox-orbit' ),
	'eta_min'  => __( '%1$s people take about %2$s minutes', 'dox-orbit' ),
	'active'   => DXO_Stats::active_count(),
	'okWarn'   => __( 'Recommended: leave at least 50 free per hour.', 'dox-orbit' ),
	'badWarn'  => __( 'With less than 50 free, order emails may have to wait.', 'dox-orbit' ),
	'sesWarn'  => __( 'With Amazon SES the server quota is not used: the speed only spreads the send.', 'dox-orbit' ),
	'unsaved'  => __( 'Unsaved changes', 'dox-orbit' ),
	'saved'    => __( 'Everything saved', 'dox-orbit' ),
	'checking' => __( 'Checking with Amazon…', 'dox-orbit' ),
	'choose'   => __( 'Choose', 'dox-orbit' ),
	'change'   => __( 'Change', 'dox-orbit' ),
	'logo'     => __( 'Logo for the emails', 'dox-orbit' ),
	'test_ok'  => __( 'Test sent to %s. Check the inbox (and spam).', 'dox-orbit' ),
	'testing'  => __( 'Connecting…', 'dox-orbit' ),
] ); ?></script>
