<?php
/**
 * Ajustes y utilidades comunes.
 *
 * Todos los ajustes van en una sola opción (`dxo_settings`). Las fechas se
 * guardan siempre en UTC y se pintan en la zona horaria del sitio (wp_date).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DXO_Settings {

	const OPTION = 'dxo_settings';

	/** El texto de "por qué lo recibes" que trae el plugin, en el idioma de WordPress en este momento. */
	public static function default_why() {
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		$site = preg_replace( '/^www\./', '', (string) $site );
		return sprintf( __( 'You are receiving this email because you subscribed at %s.', 'dox-orbit' ), $site );
	}

	public static function defaults() {
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		$site = preg_replace( '/^www\./', '', (string) $site );

		return [
			// Remitente
			'from_name'     => get_bloginfo( 'name' ),
			'from_email'    => get_option( 'admin_email' ),
			'reply_to'      => '',
			// Marca del correo
			'company'       => get_bloginfo( 'name' ),
			'address'       => '',
			'logo_id'       => 0,
			'logo_url'      => '',
			'accent'        => '#ff8d27',
			// Envío
			'transport'     => 'wp',   // wp | ses
			'rate_per_hour' => 150,
			'server_limit'  => 200,
			'ses_host'      => 'email-smtp.us-east-1.amazonaws.com',
			'ses_port'      => 587,
			'ses_user'      => '',
			'ses_pass'      => '',     // cifrada; ver encrypt()
			// Rebotes: buzón propio (Return-Path) que Orbit lee por IMAP cada hora
			'bounce_enabled' => 0,
			'bounce_email'   => '',
			'bounce_verp'    => 1,      // buzón+token@dominio: cada correo identifica a su destinatario
			'imap_host'      => '',
			'imap_port'      => 993,
			'imap_user'      => '',     // vacío = el correo de rebotes
			'imap_pass'      => '',     // cifrada; ver encrypt()
			// Seguimiento
			'track_opens'   => 1,
			'track_clicks'  => 1,
			// Confirmación (doble opt-in)
			'double_optin'  => 1,
			'confirm_subject' => __( 'Confirm your subscription', 'dox-orbit' ),
			'confirm_heading' => __( 'One click and you are in', 'dox-orbit' ),
			'confirm_text'    => __( 'Confirm that you want to receive our newsletter. If it was not you, ignore this email and nothing will happen.', 'dox-orbit' ),
			'confirm_button'  => __( 'Yes, subscribe me', 'dox-orbit' ),
			'confirmed_title' => __( 'You are subscribed', 'dox-orbit' ),
			'confirmed_text'  => __( 'Thanks for confirming. The next email will reach your inbox.', 'dox-orbit' ),
			'footer_why'      => self::default_why(),
			// Bienvenida
			'welcome_id'    => 0,
		];
	}

	public static function all() {
		$saved = get_option( self::OPTION, [] );
		return wp_parse_args( is_array( $saved ) ? $saved : [], self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function update( array $values ) {
		$all = array_merge( self::all(), $values );
		update_option( self::OPTION, $all, false );
		return $all;
	}

	/** Los datos de marca que necesita el renderizador. */
	public static function brand() {
		$s    = self::all();
		$logo = '';
		if ( ! empty( $s['logo_id'] ) ) {
			$logo = (string) wp_get_attachment_image_url( (int) $s['logo_id'], 'medium' );
		}
		if ( ! $logo ) $logo = (string) $s['logo_url'];

		return [
			'company'  => (string) $s['company'],
			'address'  => (string) $s['address'],
			'logo_url' => $logo,
			'accent'   => DXO_Renderer::color( $s['accent'] ),
			'why'      => (string) $s['footer_why'],
		];
	}

	/**
	 * El correo de rebotes si está activado y es válido; si no, '' (no se toca el Return-Path).
	 * Con VERP y un token "campaña.destinatario.firma" sale como buzón+token@dominio.
	 */
	public static function bounce_address( $token = '' ) {
		$s = self::all();
		if ( empty( $s['bounce_enabled'] ) ) return '';
		$e = trim( (string) $s['bounce_email'] );
		if ( ! is_email( $e ) ) return '';
		$pos = strrpos( $e, '@' );
		if ( ! empty( $s['bounce_verp'] ) && $pos !== false && preg_match( '/^\d{1,10}\.\d{1,10}\.[a-f0-9]{10}$/', (string) $token ) ) {
			// Con '-' y no '.': Dovecot guarda el aviso en una carpeta con el nombre del token y los puntos serían subcarpetas.
			$plus = substr( $e, 0, $pos ) . '+' . str_replace( '.', '-', $token ) . '@' . substr( $e, $pos + 1 );
			if ( is_email( $plus ) ) return $plus;
		}
		return $e;
	}

	// ─── Contraseñas de SES y del buzón de rebotes ──────────────────────────
	// Se guardan cifradas con las llaves de wp-config: una copia de la base de
	// datos sola no la revela.

	private static function key() {
		$k = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
		return hash( 'sha256', $k ?: 'dox-orbit', true );
	}

	public static function encrypt( $plain ) {
		if ( $plain === '' || ! function_exists( 'openssl_encrypt' ) ) return '';
		$iv  = random_bytes( 16 );
		$enc = openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		return base64_encode( $iv . $enc );
	}

	public static function decrypt( $stored ) {
		$raw = base64_decode( (string) $stored, true );
		if ( ! $raw || strlen( $raw ) < 17 || ! function_exists( 'openssl_decrypt' ) ) return '';
		$out = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		return $out === false ? '' : $out;
	}
}

/** Fecha y hora actuales en UTC, para guardar. */
function dxo_now() {
	return gmdate( 'Y-m-d H:i:s' );
}

/** Token aleatorio de 32 caracteres para enlaces de confirmación, baja y seguimiento. */
function dxo_token() {
	return bin2hex( random_bytes( 16 ) );
}

/** Una fecha UTC de la base, en la zona del sitio y con el formato que se pida. */
function dxo_date( $utc, $format = null ) {
	if ( ! $utc ) return '';
	$ts = strtotime( $utc . ' UTC' );
	return wp_date( $format ?: get_option( 'date_format' ), $ts );
}

/** "hace 5 minutos", "ayer", o la fecha si es de hace más de una semana. */
function dxo_ago( $utc ) {
	if ( ! $utc ) return '';
	$ts   = strtotime( $utc . ' UTC' );
	$diff = time() - $ts;
	if ( $diff < 60 ) return __( 'just now', 'dox-orbit' );
	if ( $diff < 7 * DAY_IN_SECONDS ) {
		/* translators: %s: human time difference, e.g. "5 mins" */
		return sprintf( __( '%s ago', 'dox-orbit' ), human_time_diff( $ts ) );
	}
	return wp_date( 'j M', $ts );
}

/** Números con el separador de miles del idioma del sitio. */
function dxo_num( $n, $decimals = 0 ) {
	return number_format_i18n( (float) $n, $decimals );
}

/** Un porcentaje con una cifra decimal, o "–" si no hay base. */
function dxo_pct( $part, $total, $decimals = 1 ) {
	if ( ! $total ) return '–';
	return number_format_i18n( $part * 100 / $total, $decimals ) . ' %';
}
