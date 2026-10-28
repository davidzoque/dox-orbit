<?php
/**
 * Lo que llega desde fuera: suscribirse, confirmar, darse de baja, abrir,
 * hacer clic y "ver en el navegador".
 *
 * Todo va por la portada con ?dxo=<acción>&t=<token> en vez de por la API REST:
 * hay webs (Hide My WP) que cierran wp-json a los visitantes, y la portada
 * siempre está. Se atiende en `init`, antes de que el tema pinte nada, y se
 * marca como no cacheable para LiteSpeed y cualquier caché que mire las cabeceras.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DXO_Public {

	public static function init() {
		add_action( 'init', [ __CLASS__, 'route' ], 5 );
	}

	/** La URL pública de una acción. */
	public static function url( $action, $token = '', $link = 0 ) {
		$args = [ 'dxo' => $action ];
		if ( $token !== '' ) $args['t'] = $token;
		if ( $link ) $args['l'] = (int) $link;
		return add_query_arg( $args, home_url( '/' ) );
	}

	/** Los textos fijos del pie del correo, en el idioma del sitio. */
	public static function email_strings() {
		return [
			'unsubscribe' => __( 'Unsubscribe', 'dox-orbit' ),
			'view'        => __( 'View in browser', 'dox-orbit' ),
			'lang'        => substr( get_locale(), 0, 2 ),
		];
	}

	public static function route() {
		// Solo en la web: dentro de wp-admin (y admin-ajax) un campo "dxo" no es para nosotros.
		if ( empty( $_REQUEST['dxo'] ) || is_admin() ) return;
		$action = sanitize_key( wp_unslash( $_REQUEST['dxo'] ) );
		$token  = isset( $_REQUEST['t'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['t'] ) ) : '';

		self::nocache();

		switch ( $action ) {
			case 'subscribe':   self::subscribe(); break;
			case 'confirm':     self::confirm( $token ); break;
			case 'unsubscribe': self::unsubscribe( $token ); break;
			case 'open':        self::open( $token ); break;
			case 'click':       self::click( $token, isset( $_GET['l'] ) ? (int) $_GET['l'] : 0 ); break;
			case 'view':        self::view( $token ); break;
			default: return;
		}
		exit;
	}

	private static function nocache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
		do_action( 'litespeed_control_set_nocache', 'dox-orbit' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
	}

	private static function recipient( $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $token ) ) return null;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DXO_Install::table( 'recipients' ) . ' WHERE token = %s', $token ), ARRAY_A );
	}

	// ═══ Suscribirse ════════════════════════════════════════════════════════

	private static function subscribe() {
		$ajax = ! empty( $_POST['dxo_ajax'] );
		$form = DXO_Forms::get( isset( $_POST['dxo_form'] ) ? sanitize_key( wp_unslash( $_POST['dxo_form'] ) ) : '' );

		$respond = function ( $ok, $state, $message ) use ( $ajax, $form ) {
			if ( $ajax ) {
				wp_send_json( [ 'ok' => $ok, 'state' => $state, 'message' => $message ], $ok ? 200 : 400 );
			}
			// Sin JavaScript: de vuelta a la página del formulario con el resultado.
			$back = wp_get_referer() ?: home_url( '/' );
			$back = add_query_arg( [ 'dxo_s' => $state, 'dxo_f' => $form ? $form['slug'] : '' ], remove_query_arg( [ 'dxo_s', 'dxo_f' ], $back ) );
			wp_safe_redirect( $back . '#dxo-' . ( $form ? $form['slug'] : 'form' ) );
		};

		if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || ! $form ) {
			$respond( false, 'error', __( 'Something went wrong. Reload the page and try again.', 'dox-orbit' ) );
			return;
		}

		// Trampas para bots: un campo que una persona no ve (y no rellena) y un
		// mínimo de 2 segundos entre que se pinta el formulario y se envía.
		$hp = isset( $_POST['dxo_hp'] ) ? trim( (string) wp_unslash( $_POST['dxo_hp'] ) ) : '';
		$ts = isset( $_POST['dxo_ts'] ) ? (int) $_POST['dxo_ts'] : 0;
		if ( $hp !== '' || ( $ts && time() - $ts < 2 ) ) {
			// Al bot se le dice que todo fue bien, para que no insista.
			$respond( true, 'pending', $form['success_text'] );
			return;
		}

		// Como mucho 5 intentos cada 10 minutos por IP.
		$ip  = self::ip();
		$key = 'dxo_rl_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 5 ) {
			$respond( false, 'error', __( 'Too many attempts. Try again in a few minutes.', 'dox-orbit' ) );
			return;
		}
		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$name  = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		if ( ! is_email( $email ) ) {
			$respond( false, 'invalid', __( 'That email does not look right. Check it and try again.', 'dox-orbit' ) );
			return;
		}

		// El idioma de la página donde estaba el formulario, si es uno de los de Polylang; si no, el de la petición.
		$lang = function_exists( 'pll_current_language' ) ? (string) pll_current_language() : substr( determine_locale(), 0, 5 );
		if ( ! empty( $_POST['dxo_lang'] ) ) {
			$posted = sanitize_key( wp_unslash( $_POST['dxo_lang'] ) );
			if ( isset( DXO_Campaigns::languages()[ $posted ] ) ) $lang = $posted;
		}

		$res = DXO_Subscribers::subscribe( $email, [
			'first_name' => $name,
			'list_ids'   => [ (int) $form['list_id'] ],
			'source'     => $form['slug'],
			'lang'       => $lang,
			'ip'         => $ip,
		] );

		switch ( $res['result'] ) {
			case 'pending': $respond( true, 'pending', $form['success_text'] ); break;
			case 'active':  $respond( true, 'active', $form['done_text'] ); break;
			case 'already': $respond( true, 'already', __( 'You were already subscribed. Thanks!', 'dox-orbit' ) ); break;
			default:        $respond( false, 'invalid', __( 'That email does not look right. Check it and try again.', 'dox-orbit' ) );
		}
	}

	/** La IP de quien envía. REMOTE_ADDR: detrás de Cloudflare el servidor tiene que traer la real (mod_remoteip). */
	public static function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	// ═══ Confirmar y darse de baja ══════════════════════════════════════════

	private static function confirm( $token ) {
		$sub = DXO_Subscribers::confirm( $token );
		if ( ! $sub ) {
			self::page( __( 'This link is no longer valid', 'dox-orbit' ), __( 'It may have been used already. If you want to subscribe, use the form on the website again.', 'dox-orbit' ) );
			return;
		}
		if ( $sub['status'] !== 'active' ) {
			self::page( __( 'You are unsubscribed', 'dox-orbit' ), __( 'This address unsubscribed. To come back, use the form on the website.', 'dox-orbit' ) );
			return;
		}
		$s = DXO_Settings::all();
		self::page( $s['confirmed_title'], $s['confirmed_text'], 'ok' );
	}

	/**
	 * GET: una página con el botón de confirmar. No se da de baja con solo abrir
	 * el enlace porque los antivirus del correo abren todos los enlaces solos.
	 * POST: la baja. Gmail y Yahoo mandan un POST con List-Unsubscribe=One-Click
	 * (RFC 8058) sin cookies ni nonce, y hay que atenderlo igual.
	 */
	private static function unsubscribe( $token ) {
		$r   = self::recipient( $token );
		$sub = $r ? DXO_Subscribers::get( $r['subscriber_id'] ) : DXO_Subscribers::get_by_token( $token );
		if ( ! $sub ) {
			self::page( __( 'This link is no longer valid', 'dox-orbit' ), __( 'This address is no longer on our list.', 'dox-orbit' ) );
			return;
		}

		if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
			if ( $sub['status'] !== 'unsubscribed' ) DXO_Subscribers::unsubscribe( (int) $sub['id'], $r );
			if ( ! empty( $_POST['List-Unsubscribe'] ) ) {
				status_header( 200 );
				echo 'OK';
				return;
			}
			self::page( __( 'Done, you will not receive more emails', 'dox-orbit' ), sprintf( __( '%s has been removed from the list. If it was a mistake, you can subscribe again from the website.', 'dox-orbit' ), $sub['email'] ), 'ok' );
			return;
		}

		if ( $sub['status'] === 'unsubscribed' ) {
			self::page( __( 'You are already unsubscribed', 'dox-orbit' ), sprintf( __( '%s will not receive more emails from us.', 'dox-orbit' ), $sub['email'] ), 'ok' );
			return;
		}

		$form = '<form method="post" action="' . esc_url( self::url( 'unsubscribe', $token ) ) . '"><button type="submit" class="dxo-btn">' . esc_html__( 'Unsubscribe', 'dox-orbit' ) . '</button></form>';
		self::page( __( 'Do you want to unsubscribe?', 'dox-orbit' ), sprintf( __( '%s will stop receiving our newsletter.', 'dox-orbit' ), $sub['email'] ), '', $form );
	}

	// ═══ Seguimiento ════════════════════════════════════════════════════════

	/**
	 * El pixel de apertura. Ojo: Gmail y Mail del iPhone lo piden solos al
	 * llegar el correo, así que la apertura es una cifra al alza.
	 */
	private static function open( $token ) {
		$r = self::recipient( $token );
		if ( $r && $r['status'] === 'sent' ) self::mark_open( $r );
		header( 'Content-Type: image/gif' );
		header( 'Content-Length: 43' );
		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
	}

	/** Un clic prueba que hay una persona: los avisos pasajeros de rebote vuelven a cero. Las aperturas no (Apple MPP y los antivirus las disparan solos). */
	private static function reset_soft_bounces( array $r ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . DXO_Install::table( 'subscribers' ) . ' SET soft_bounces = 0 WHERE id = %d AND soft_bounces > 0', $r['subscriber_id'] ) );
	}

	private static function mark_open( array $r ) {
		global $wpdb;
		$t = DXO_Install::table( 'recipients' );
		if ( ! $r['opened_at'] ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET opened_at = %s, open_count = open_count + 1 WHERE id = %d", dxo_now(), $r['id'] ) );
			DXO_Campaigns::log_event( (int) $r['campaign_id'], (int) $r['id'], 'open' );
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET open_count = LEAST(open_count + 1, 65000) WHERE id = %d", $r['id'] ) );
		}
	}

	private static function click( $token, $link_id ) {
		global $wpdb;
		$link = $link_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DXO_Install::table( 'links' ) . ' WHERE id = %d', $link_id ), ARRAY_A ) : null;
		$r    = self::recipient( $token );

		if ( ! $link || ( $r && (int) $link['campaign_id'] !== (int) $r['campaign_id'] ) ) {
			wp_safe_redirect( home_url( '/' ) );
			return;
		}

		if ( $r && $r['status'] === 'sent' ) {
			$t = DXO_Install::table( 'recipients' );
			// Un clic sin apertura previa (imágenes bloqueadas) cuenta también como apertura.
			if ( ! $r['opened_at'] ) self::mark_open( $r );
			if ( ! $r['clicked_at'] ) {
				$wpdb->query( $wpdb->prepare( "UPDATE $t SET clicked_at = %s WHERE id = %d", dxo_now(), $r['id'] ) );
			}
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET click_count = LEAST(click_count + 1, 65000) WHERE id = %d", $r['id'] ) );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . DXO_Install::table( 'links' ) . ' SET clicks = clicks + 1 WHERE id = %d', $link['id'] ) );
			DXO_Campaigns::log_event( (int) $r['campaign_id'], (int) $r['id'], 'click', (int) $link['id'] );
			self::reset_soft_bounces( $r );
		}

		// La URL es la que se guardó al lanzar la campaña, nunca una que venga en
		// la petición: así el enlace no sirve para mandar a nadie a otra web.
		wp_redirect( $link['url'], 302, 'Dox Orbit' );
	}

	/** "Ver en el navegador": el mismo correo, con sus datos y sin seguimiento. */
	private static function view( $token ) {
		$r   = self::recipient( $token );
		$c   = $r ? DXO_Campaigns::get( $r['campaign_id'] ) : null;
		$sub = $r ? DXO_Subscribers::get( $r['subscriber_id'] ) : null;
		if ( ! $c || ! $sub ) {
			self::page( __( 'This email is no longer available', 'dox-orbit' ), '' );
			return;
		}
		$email = DXO_Sender::build( $c, $r, $sub, null );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $email['html']; // phpcs:ignore -- HTML generado por el renderizador, ya escapado.
	}

	// ═══ Página suelta ══════════════════════════════════════════════════════

	/**
	 * Una página sencilla con la marca, para confirmar o darse de baja. No usa
	 * el tema: así se ve bien en cualquier web y no depende de su cabecera.
	 */
	public static function page( $title, $text, $tone = '', $extra = '' ) {
		$brand  = DXO_Settings::brand();
		$accent = $brand['accent'];
		$logo   = $brand['logo_url']
			? '<img src="' . esc_url( $brand['logo_url'] ) . '" alt="' . esc_attr( $brand['company'] ) . '" style="height:32px;width:auto;display:block">'
			: '<b style="font-size:18px;letter-spacing:-.02em;color:#141313">' . esc_html( $brand['company'] ) . '</b>';
		$icon = $tone === 'ok'
			? '<div class="dxo-ic"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#141313" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5 9-10"/></svg></div>'
			: '';

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $title . ' · ' . $brand['company'] ); ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f2f0;font:16px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#52525b;padding:24px}
.dxo-card{width:100%;max-width:480px;background:#fff;border-radius:18px;padding:36px;box-shadow:0 .25rem .5rem rgba(0,0,0,.04),0 1.5rem 2.2rem rgba(0,0,0,.07);animation:in .5s cubic-bezier(.165,.84,.44,1)}
@keyframes in{from{opacity:0;transform:translateY(20px)}}
@media (prefers-reduced-motion:reduce){.dxo-card{animation:none}}
.dxo-ic{width:44px;height:44px;border-radius:50%;background:<?php echo esc_attr( $accent ); ?>;display:grid;place-items:center;margin:28px 0 16px}
h1{font-size:26px;line-height:1.25;letter-spacing:-.03em;color:#141313;margin:28px 0 8px}
.dxo-ic + h1{margin-top:0}
p{margin:0 0 22px}
.dxo-btn{all:unset;cursor:pointer;display:inline-block;background:#141313;color:#fff;font-weight:600;font-size:15px;padding:13px 24px;border-radius:999px;transition:transform .4s cubic-bezier(.165,.84,.44,1)}
.dxo-btn:hover{transform:translateY(-2px)}
.dxo-back{display:inline-block;margin-top:6px;color:#141313;font-weight:600;font-size:14px;text-decoration:none}
.dxo-back:hover{text-decoration:underline}
</style>
</head>
<body>
<main class="dxo-card">
	<?php echo $logo; // phpcs:ignore -- escapado arriba ?>
	<?php echo $icon; // phpcs:ignore ?>
	<h1><?php echo esc_html( $title ); ?></h1>
	<?php if ( $text !== '' ) : ?><p><?php echo esc_html( $text ); ?></p><?php endif; ?>
	<?php echo $extra; // phpcs:ignore -- formulario construido arriba con esc_* ?>
	<?php if ( $extra === '' ) : ?><a class="dxo-back" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Go to the website', 'dox-orbit' ); ?> &rarr;</a><?php endif; ?>
</main>
</body>
</html><?php
	}
}
