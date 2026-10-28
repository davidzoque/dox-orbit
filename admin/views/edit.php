<?php
/**
 * Editor de una campaña: a la izquierda el asunto, a quién y los bloques; a la
 * derecha la vista previa en vivo (el mismo HTML que se envía). Se guarda solo.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore
$c  = $id ? DXO_Campaigns::get( $id ) : null;

if ( ! $c ) : ?>
	<div class="dxo-top"><div><h1><?php esc_html_e( 'Campaign not found', 'dox-orbit' ); ?></h1></div></div>
	<div class="dxo-page"><a class="dxo-btn dxo-btn-gray" href="<?php echo esc_url( DXO_Admin::url( 'campaigns' ) ); ?>"><?php esc_html_e( 'Back to campaigns', 'dox-orbit' ); ?></a></div>
	<?php return;
endif;

$editable = in_array( $c['status'], [ 'draft', 'scheduled', 'active', 'inactive' ], true );
if ( ! $editable ) : ?>
	<div class="dxo-top"><div><div class="crumb"><a href="<?php echo esc_url( DXO_Admin::url( 'campaigns' ) ); ?>"><?php esc_html_e( 'Campaigns', 'dox-orbit' ); ?></a></div><h1><?php echo esc_html( $c['subject'] ); ?></h1></div></div>
	<div class="dxo-page"><div class="dxo-card"><div class="dxo-card-b"><?php esc_html_e( 'This campaign has already gone out and cannot be edited. You can duplicate it to send a new version.', 'dox-orbit' ); ?> <a href="<?php echo esc_url( DXO_Admin::url( 'report', [ 'id' => $c['id'] ] ) ); ?>"><?php esc_html_e( 'See the report', 'dox-orbit' ); ?></a></div></div></div>
	<?php return;
endif;

$s        = DXO_Settings::all();
$welcome  = $c['type'] === 'welcome';
$is_html  = $c['format'] === 'html';
$dom_ok   = DXO_Sanitizer::available(); // sin DOM no se puede limpiar HTML: solo bloques
$lists    = DXO_Lists::all();
$resend   = ! empty( $c['audience']['resend'] );
$test_to  = get_user_meta( get_current_user_id(), 'dxo_test_to', true ) ?: wp_get_current_user()->user_email;
$audience = $welcome ? 0 : count( DXO_Campaigns::audience_ids( $c ) );
$brand    = DXO_Settings::brand();
$initials = strtoupper( mb_substr( preg_replace( '/[^\p{L}\s]/u', '', $s['from_name'] ), 0, 1 ) . mb_substr( (string) strstr( trim( $s['from_name'] ), ' ' ), 1, 1 ) );

$data = [
	'id'        => (int) $c['id'],
	'type'      => $c['type'],
	'status'    => $c['status'],
	'subject'   => $c['subject'],
	'preheader' => $c['preheader'],
	'format'    => $c['format'],
	'blocks'    => $c['blocks'],
	'lists'     => array_map( 'intval', $c['audience']['lists'] ?? [] ),
	'resend'    => $resend,
	'testTo'    => $test_to,
	'tested'    => (bool) $c['test_sent_at'],
	'audience'  => $audience,
	'scheduled' => $c['scheduled_at'] ? dxo_date( $c['scheduled_at'], 'Y-m-d\TH:i' ) : '',
	'accent'    => $brand['accent'],
	'problems'  => DXO_Campaigns::problems( $c ),
	'strings'   => [
		'people'      => _n( '%s person', '%s people', 2, 'dox-orbit' ),
		'person'      => _n( '%s person', '%s people', 1, 'dox-orbit' ),
		'send_now'    => __( 'Send now', 'dox-orbit' ),
		'schedule'    => __( 'Schedule', 'dox-orbit' ),
		'send_title'  => __( 'Ready to send?', 'dox-orbit' ),
		'fix_title'   => __( 'Before sending', 'dox-orbit' ),
		'when'        => __( 'When', 'dox-orbit' ),
		'now'         => __( 'Now', 'dox-orbit' ),
		'later'       => __( 'Choose a date', 'dox-orbit' ),
		'goes_to'     => __( 'It will go to %1$s. At %2$s per hour, it takes about %3$s.', 'dox-orbit' ),
		'hours'       => __( '%s hours', 'dox-orbit' ),
		'minutes'     => __( '%s minutes', 'dox-orbit' ),
		'no_test'     => __( 'You have not sent yourself a test yet.', 'dox-orbit' ),
		'test_title'  => __( 'Send a test', 'dox-orbit' ),
		'test_text'   => __( 'With your name in the fields and «[Test]» in the subject. It does not count in the report.', 'dox-orbit' ),
		'send_test'   => __( 'Send test', 'dox-orbit' ),
		'all_lists'   => __( 'All subscribers', 'dox-orbit' ),
		'checks_ok'   => [
			'unsub'   => __( '<b>Unsubscribe link</b> and the one-click unsubscribe header that Gmail and Yahoo require', 'dox-orbit' ),
			'address' => __( '<b>Postal address</b> in the footer, as required by CAN-SPAM', 'dox-orbit' ),
			'alt'     => __( '<b>All images</b> have alternative text', 'dox-orbit' ),
			'test'    => __( '<b>Test sent.</b> Check it on your phone too.', 'dox-orbit' ),
		],
		'checks_bad'  => [
			'address' => __( '<b>No postal address.</b> Add it in Settings: CAN-SPAM requires it.', 'dox-orbit' ),
			'alt'     => __( '<b>Some image has no alternative text.</b> It is what is read when images are blocked.', 'dox-orbit' ),
			'test'    => __( '<b>No test sent yet.</b> Send yourself one before scheduling it.', 'dox-orbit' ),
		],
		'hasAddress'  => $s['address'] !== '',
		'merge_help'  => __( 'Fields: {first_name}, {last_name}, {email}. With a fallback: {first_name|friend}.', 'dox-orbit' ),
		'html'        => [
			'own_unsub'   => __( '<b>Your own unsubscribe link</b> ({unsubscribe_url}) is in the code, plus the one-click unsubscribe header that Gmail and Yahoo require', 'dox-orbit' ),
			'footer'      => __( '<b>Footer added by Orbit.</b> Your code has no link with {unsubscribe_url}, so the unsubscribe link and your address go at the end. The one-click header is sent too.', 'dox-orbit' ),
			'import_ask'  => __( 'The code in the editor will be replaced by the file. Continue?', 'dox-orbit' ),
			'import_btn'  => __( 'Replace', 'dox-orbit' ),
			'import_big'  => __( 'That file is too large. Over 1 MB cannot be a good email.', 'dox-orbit' ),
			'imported'    => __( 'File imported', 'dox-orbit' ),
			'to_html_t'   => __( 'Switch to HTML code?', 'dox-orbit' ),
			'to_html'     => __( 'The email will be sent from the HTML code. Your blocks are kept, and you can go back to them at any time.', 'dox-orbit' ),
			'to_blocks_t' => __( 'Switch to blocks?', 'dox-orbit' ),
			'to_blocks'   => __( 'The email will be sent from the blocks. Your HTML code is kept, and you can go back to it at any time.', 'dox-orbit' ),
			'switch'      => __( 'Switch', 'dox-orbit' ),
		],
		'labels'      => [
			'text'     => __( 'Text', 'dox-orbit' ),
			'size'     => __( 'Size', 'dox-orbit' ),
			'large'    => __( 'Large', 'dox-orbit' ),
			'medium'   => __( 'Medium', 'dox-orbit' ),
			'small'    => __( 'Small', 'dox-orbit' ),
			'align'    => __( 'Align', 'dox-orbit' ),
			'left'     => __( 'Left', 'dox-orbit' ),
			'center'   => __( 'Center', 'dox-orbit' ),
			'link'     => __( 'Link', 'dox-orbit' ),
			'url'      => __( 'Address', 'dox-orbit' ),
			'alt'      => __( 'Alt text', 'dox-orbit' ),
			'alt_hint' => __( 'What the image shows, in a few words.', 'dox-orbit' ),
			'image'    => __( 'Image', 'dox-orbit' ),
			'choose'   => __( 'Choose', 'dox-orbit' ),
			'change'   => __( 'Change', 'dox-orbit' ),
			'style'    => __( 'Color', 'dox-orbit' ),
			'dark'     => __( 'Black', 'dox-orbit' ),
			'accent'   => __( 'Brand color', 'dox-orbit' ),
			'height'   => __( 'Height', 'dox-orbit' ),
			'post'     => __( 'Post', 'dox-orbit' ),
			'search'   => __( 'Search posts…', 'dox-orbit' ),
			'cta'      => __( 'Link text', 'dox-orbit' ),
			'move_up'  => __( 'Move up', 'dox-orbit' ),
			'move_dn'  => __( 'Move down', 'dox-orbit' ),
			'remove'   => __( 'Remove', 'dox-orbit' ),
			'bold'     => __( 'Bold', 'dox-orbit' ),
			'italic'   => __( 'Italic', 'dox-orbit' ),
			'list'     => __( 'List', 'dox-orbit' ),
			'add_link' => __( 'Link', 'dox-orbit' ),
			'link_prompt' => __( 'Link address (https://…)', 'dox-orbit' ),
			'apply'    => __( 'Apply', 'dox-orbit' ),
			'html_code' => __( 'HTML code', 'dox-orbit' ),
			'html_ph'  => '<table>…</table>',
			'html_hint' => __( 'The HTML of the block. Tables and inline styles work in every email app.', 'dox-orbit' ),
		],
	],
];
?>
<div class="dxo-top">
	<div style="min-width:0">
		<div class="crumb"><a href="<?php echo esc_url( DXO_Admin::url( 'campaigns' ) ); ?>"><?php esc_html_e( 'Campaigns', 'dox-orbit' ); ?></a></div>
		<h1 id="dxo-title"><?php echo esc_html( $c['subject'] !== '' ? $c['subject'] : ( $welcome ? __( 'Welcome email', 'dox-orbit' ) : __( 'New campaign', 'dox-orbit' ) ) ); ?></h1>
	</div>
	<?php if ( DXO_Campaigns::lang_name( $c ) !== '' ) : ?>
		<span class="dxo-pill p-info"><?php echo esc_html( DXO_Campaigns::lang_name( $c ) ); ?></span>
	<?php endif; ?>
	<?php if ( $c['status'] === 'scheduled' ) : ?>
		<span class="dxo-pill p-info"><span class="dot"></span><?php echo esc_html( sprintf( __( 'Scheduled for %s', 'dox-orbit' ), dxo_date( $c['scheduled_at'], 'D j M, ' . get_option( 'time_format' ) ) ) ); ?></span>
	<?php endif; ?>
	<div class="sp"></div>
	<span class="small muted dxo-hide-sm" id="dxo-save-state" style="white-space:nowrap"><?php esc_html_e( 'Saved', 'dox-orbit' ); ?></span>
	<button type="button" class="dxo-btn dxo-btn-gray" data-ed="test"><?php echo DXO_Admin::icon( 'mail' ); // phpcs:ignore ?><?php esc_html_e( 'Send me a test', 'dox-orbit' ); ?></button>
	<?php if ( $welcome ) : ?>
		<a class="dxo-btn dxo-btn-dark" href="<?php echo esc_url( DXO_Admin::url( 'campaigns' ) ); ?>"><?php esc_html_e( 'Done', 'dox-orbit' ); ?></a>
	<?php else : ?>
		<button type="button" class="dxo-btn dxo-btn-dark" data-ed="launch"><?php echo $c['status'] === 'scheduled' ? esc_html__( 'Change date', 'dox-orbit' ) : esc_html__( 'Send or schedule', 'dox-orbit' ); ?> <?php echo DXO_Admin::icon( 'arrow' ); // phpcs:ignore ?></button>
	<?php endif; ?>
</div>

<div class="dxo-page wide">
	<div class="dxo-ed" id="dxo-editor">
		<div class="dxo-col">
			<div class="dxo-card" data-anim>
				<div class="dxo-card-b tight">
					<div class="dxo-field">
						<label class="req" for="dxo-subject"><?php esc_html_e( 'Subject', 'dox-orbit' ); ?></label>
						<div><input class="dxo-input" id="dxo-subject" maxlength="255" value="<?php echo esc_attr( $c['subject'] ); ?>" placeholder="<?php esc_attr_e( 'What is this email about?', 'dox-orbit' ); ?>">
							<div class="dxo-hint"><span id="dxo-subject-hint"><?php esc_html_e( 'Between 30 and 50 characters is read in full on a phone', 'dox-orbit' ); ?></span><span id="dxo-subject-count"></span></div></div>
					</div>
					<div class="dxo-field">
						<label for="dxo-preheader"><?php esc_html_e( 'Preview text', 'dox-orbit' ); ?></label>
						<div><input class="dxo-input" id="dxo-preheader" maxlength="255" value="<?php echo esc_attr( $c['preheader'] ); ?>" placeholder="<?php esc_attr_e( 'A line that invites to open it', 'dox-orbit' ); ?>">
							<div class="dxo-hint"><span><?php esc_html_e( 'What is seen next to the subject in the inbox', 'dox-orbit' ); ?></span></div></div>
					</div>
					<div class="dxo-field">
						<span class="lbl"><?php esc_html_e( 'From', 'dox-orbit' ); ?></span>
						<div><input class="dxo-input" readonly value="<?php echo esc_attr( $s['from_name'] . ' · ' . $s['from_email'] ); ?>">
							<div class="dxo-hint"><a href="<?php echo esc_url( DXO_Admin::url( 'settings', [ 'tab' => 'sender' ] ) ); ?>"><?php esc_html_e( 'Change it in Settings', 'dox-orbit' ); ?></a></div></div>
					</div>
				</div>
			</div>

			<?php if ( ! $welcome ) : ?>
				<div class="dxo-card" data-anim>
					<div class="dxo-card-h"><h3><?php esc_html_e( 'Who receives it', 'dox-orbit' ); ?></h3><div class="sp"></div><span class="dxo-pill p-acc" id="dxo-audience"><?php echo esc_html( sprintf( _n( '%s person', '%s people', $audience, 'dox-orbit' ), dxo_num( $audience ) ) ); ?></span></div>
					<div class="dxo-card-b">
						<?php if ( $resend ) : ?>
							<p style="margin:0"><?php echo esc_html( DXO_Campaigns::audience_label( $c ) ); ?>.</p>
							<p class="small muted" style="margin:6px 0 0"><?php esc_html_e( 'Counted again when it goes out: whoever opens the original in the meantime will not receive it.', 'dox-orbit' ); ?></p>
						<?php else : ?>
							<div class="dxo-checks" id="dxo-lists">
								<?php foreach ( $lists as $l ) : ?>
									<label class="dxo-chip"><input type="checkbox" value="<?php echo (int) $l['id']; ?>" <?php checked( in_array( (int) $l['id'], $data['lists'], true ) ); ?>><?php echo esc_html( $l['name'] ); ?> <em><?php echo esc_html( dxo_num( $l['active'] ) ); ?></em></label>
								<?php endforeach; ?>
							</div>
							<p class="small muted" style="margin:10px 0 0"><?php esc_html_e( 'Only active subscribers. Whoever is on two lists receives it once. With no list checked, it goes to everyone.', 'dox-orbit' ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $is_html ) : ?>
				<div class="dxo-card" data-anim>
					<div class="dxo-card-h">
						<h3><?php esc_html_e( 'HTML code', 'dox-orbit' ); ?></h3><div class="sp"></div>
						<button type="button" class="dxo-btn dxo-btn-gray dxo-btn-sm" data-ed="import"><?php esc_html_e( 'Import .html file', 'dox-orbit' ); ?></button>
						<button type="button" class="dxo-btn dxo-btn-gray dxo-btn-sm" data-ed="format" data-to="blocks"><?php esc_html_e( 'Use blocks instead', 'dox-orbit' ); ?></button>
						<input type="file" id="dxo-import-file" accept=".html,.htm,text/html" hidden>
					</div>
					<div class="dxo-card-b">
						<div class="dxo-code-wrap"><textarea id="dxo-html" class="dxo-code" spellcheck="false" autocomplete="off" aria-label="<?php esc_attr_e( 'HTML code', 'dox-orbit' ); ?>" placeholder="<?php esc_attr_e( 'Paste the full HTML of your email here', 'dox-orbit' ); ?>"><?php echo esc_textarea( $c['html'] ); ?></textarea></div>
						<p class="dxo-note"><?php echo DXO_Admin::icon( 'info' ); // phpcs:ignore ?><span><?php esc_html_e( 'Fields: {first_name}, {last_name}, {email}. With a fallback: {first_name|friend}. Also {unsubscribe_url} and {view_in_browser_url} for links. If your code has no {unsubscribe_url}, Orbit adds its footer with the unsubscribe link at the end.', 'dox-orbit' ); ?></span></p>
						<p class="dxo-note"><?php echo DXO_Admin::icon( 'info' ); // phpcs:ignore ?><span><?php esc_html_e( 'When it goes out, Orbit removes scripts, forms and anything an email app does not allow, moves the CSS of the <style> tags into each element (Gmail and Outlook need it there) and tracks the clicks. The preview on the right is exactly that.', 'dox-orbit' ); ?></span></p>
					</div>
				</div>
			<?php else : ?>
				<div class="dxo-card" data-anim>
					<div class="dxo-card-h"><h3><?php esc_html_e( 'Blocks', 'dox-orbit' ); ?></h3><div class="sp"></div><span class="small muted dxo-hide-sm"><?php esc_html_e( 'Drag to reorder', 'dox-orbit' ); ?></span>
						<?php if ( $dom_ok ) : ?><button type="button" class="dxo-btn dxo-btn-gray dxo-btn-sm" data-ed="format" data-to="html"><?php esc_html_e( 'Use HTML code instead', 'dox-orbit' ); ?></button><?php endif; ?></div>
					<div class="dxo-card-b">
						<div class="dxo-blocks" id="dxo-blocks"></div>
						<div class="dxo-add" id="dxo-add">
							<?php foreach ( array_diff( [ 'heading', 'text', 'image', 'button', 'post', 'html', 'divider', 'spacer' ], $dom_ok ? [] : [ 'html' ] ) as $t ) : ?>
								<button type="button" data-add="<?php echo esc_attr( $t ); ?>">+ <span data-label="<?php echo esc_attr( $t ); ?>"></span></button>
							<?php endforeach; ?>
						</div>
						<p class="dxo-note"><?php echo DXO_Admin::icon( 'info' ); // phpcs:ignore ?><span><?php echo esc_html( $data['strings']['merge_help'] ); ?></span></p>
					</div>
				</div>
			<?php endif; ?>

			<div class="dxo-card" data-anim>
				<div class="dxo-card-h"><h3><?php esc_html_e( 'Before sending', 'dox-orbit' ); ?></h3></div>
				<div class="dxo-card-b dxo-checklist" id="dxo-checklist"></div>
			</div>
		</div>

		<div class="dxo-stage" id="dxo-stage" data-anim>
			<div class="dxo-stage-top">
				<div class="dxo-seg" id="dxo-device"><button type="button" class="on" data-d="desk"><?php esc_html_e( 'Desktop', 'dox-orbit' ); ?></button><button type="button" data-d="mobile"><?php esc_html_e( 'Phone', 'dox-orbit' ); ?></button></div>
				<span class="small muted" style="margin-left:auto"><?php esc_html_e( 'Live preview, with your name in the fields', 'dox-orbit' ); ?></span>
			</div>
			<div class="dxo-inbox">
				<div class="dxo-av"><?php echo esc_html( $initials ?: 'N' ); ?></div>
				<div style="flex:1;min-width:0">
					<div class="l1"><b><?php echo esc_html( $s['from_name'] ); ?></b><span><?php echo esc_html( wp_date( get_option( 'time_format' ) ) ); ?></span></div>
					<div class="subj" id="dxo-inbox-subject"></div>
					<div class="pre" id="dxo-inbox-pre"></div>
				</div>
			</div>
			<div class="dxo-frame-wrap"><iframe class="dxo-frame" id="dxo-frame" title="<?php esc_attr_e( 'Email preview', 'dox-orbit' ); ?>" sandbox="allow-same-origin"></iframe></div>
		</div>
	</div>
</div>
<script type="application/json" id="dxo-data"><?php echo wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ); // phpcs:ignore -- JSON con < y & escapados: un "</script>" en un bloque no puede cerrar la etiqueta. ?></script>
