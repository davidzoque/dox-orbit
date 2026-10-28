<?php
/**
 * Manda un correo por donde diga Ajustes.
 *
 * - wp:  wp_mail(), lo mismo que usan WooCommerce y los formularios. Si la web
 *        tiene un plugin de SMTP (GoSMTP, WP Mail SMTP...), sale por él.
 * - ses: Amazon SES por SMTP, con un PHPMailer propio (el que trae WordPress):
 *        así las campañas no cambian el camino del resto de correos de la web.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DXO_Mailer {

	/** El PHPMailer de SES de esta petición: se crea una vez y sirve para toda la tanda. */
	private static $ses = null;

	/** Texto plano del correo en curso, para el phpmailer_init de wp_mail. */
	private static $alt = '';

	/** Correo de rebotes (Return-Path) del envío en curso; '' = no se toca. Con VERP lleva el token. */
	private static $sender = '';

	/** El mismo correo de rebotes sin token, por si el transporte no puede llevar el "+". */
	private static $plain = '';

	/** Remitente, transporte y sendmail del PHPMailer global antes de Orbit, para devolverlos. */
	private static $prev = null;

	/** Último error de wp_mail en esta petición. */
	private static $wp_error = '';

	/**
	 * @param array $headers cabeceras extra (nombre => valor)
	 * @return string|null null si salió; el motivo si no
	 */
	public static function send( $to, $subject, $html, $text, array $headers = [] ) {
		$s = DXO_Settings::all();
		try {
			if ( $s['transport'] === 'ses' ) {
				return self::send_ses( self::ses_config( $s ), $to, $subject, $html, $text, $headers, $s );
			}
			return self::send_wp( $to, $subject, $html, $text, $headers, $s );
		} catch ( Throwable $e ) {
			return $e->getMessage();
		}
	}

	// ─── wp_mail ────────────────────────────────────────────────────────────

	private static function send_wp( $to, $subject, $html, $text, array $headers, array $s ) {
		$lines = [ 'Content-Type: text/html; charset=UTF-8' ];
		$from  = self::encode_name( $s['from_name'] );
		$lines[] = 'From: ' . ( $from !== '' ? $from . ' <' . $s['from_email'] . '>' : $s['from_email'] );
		if ( is_email( $s['reply_to'] ) ) $lines[] = 'Reply-To: ' . $s['reply_to'];
		foreach ( $headers as $name => $value ) {
			$lines[] = $name . ': ' . str_replace( [ "\r", "\n" ], '', (string) $value );
		}

		self::$alt      = $text;
		self::$wp_error = '';
		self::$sender   = DXO_Settings::bounce_address( isset( $headers['X-Dox-Orbit'] ) ? (string) $headers['X-Dox-Orbit'] : '' );
		self::$plain    = DXO_Settings::bounce_address( '' );
		self::$prev     = null;
		add_action( 'phpmailer_init', [ __CLASS__, 'add_alt_body' ] );
		// Antes que cualquier plugin de SMTP (prioridad 10): así se guarda el estado de verdad, no el que ellos ya cambiaron.
		add_action( 'phpmailer_init', [ __CLASS__, 'capture_state' ], 0 );
		// Prioridad alta: los plugins de SMTP fijan su propio Sender en el suyo, y el de rebotes tiene que ir el último.
		add_action( 'phpmailer_init', [ __CLASS__, 'set_return_path' ], 99 );
		add_action( 'wp_mail_failed', [ __CLASS__, 'catch_wp_error' ] );

		// try/finally: pase lo que pase, los hooks se quitan y el Return-Path no se escapa a otros correos de la web.
		try {
			$ok = wp_mail( $to, $subject, $html, $lines );
		} finally {
			remove_action( 'phpmailer_init', [ __CLASS__, 'add_alt_body' ] );
			remove_action( 'phpmailer_init', [ __CLASS__, 'capture_state' ], 0 );
			remove_action( 'phpmailer_init', [ __CLASS__, 'set_return_path' ], 99 );
			remove_action( 'wp_mail_failed', [ __CLASS__, 'catch_wp_error' ] );
			self::restore_phpmailer();
			self::$alt    = '';
			self::$sender = '';
			self::$plain  = '';
		}

		if ( $ok ) return null;
		return self::$wp_error !== '' ? self::$wp_error : __( 'WordPress could not send the email.', 'dox-orbit' );
	}

	/** La versión de texto junto al HTML: los filtros de spam miran que exista. */
	public static function add_alt_body( $phpmailer ) {
		if ( self::$alt !== '' ) $phpmailer->AltBody = self::$alt;
	}

	/** Guarda cómo estaba el PHPMailer global justo antes de que nadie lo toque. */
	public static function capture_state( $phpmailer ) {
		if ( self::$prev === null && is_object( $phpmailer ) ) {
			self::$prev = [ 'sender' => $phpmailer->Sender, 'mailer' => $phpmailer->Mailer, 'sendmail' => $phpmailer->Sendmail ];
		}
	}

	/**
	 * wp_mail() reutiliza un único PHPMailer en toda la petición y nunca vacía
	 * Sender: sin esto, el siguiente correo de la web (WooCommerce...) saldría con
	 * el Return-Path y el token de Orbit.
	 */
	private static function restore_phpmailer() {
		global $phpmailer;
		if ( self::$prev !== null && is_object( $phpmailer ) && $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer ) {
			$phpmailer->Sender   = self::$prev['sender'];
			$phpmailer->Mailer   = self::$prev['mailer'];
			$phpmailer->Sendmail = self::$prev['sendmail'];
		}
		self::$prev = null;
	}

	/**
	 * El remitente del sobre (Return-Path) es el buzón de rebotes. Solo mientras
	 * dura el wp_mail de Orbit: el hook se quita después y el PHPMailer se deja como estaba.
	 */
	public static function set_return_path( $phpmailer ) {
		if ( self::$sender === '' ) return;
		$plan = self::plan_return_path( (string) $phpmailer->Mailer, self::$sender, self::$plain, self::sendmail_binary(), stripos( PHP_OS, 'WIN' ) === 0 );
		if ( $plan['mailer'] === 'sendmail' ) {
			$phpmailer->isSendmail();
			$phpmailer->Sendmail = $plan['sendmail'];
		}
		$phpmailer->Sender = $plan['sender'];
	}

	/**
	 * Qué Return-Path puede llevar cada transporte. Con SMTP va tal cual. Con mail() o
	 * sendmail, PHPMailer solo pasa -f<Sender> si la dirección es "segura para la shell",
	 * y el "+" del VERP no lo es: en ese caso se manda con sendmail directo, pasando el -f
	 * nosotros (PHPMailer le hace escapeshellcmd, que deja en paz "+" y "@"). Si no se puede
	 * con garantías, se cae a la dirección sin token (queda la cabecera X-Dox-Orbit).
	 * Pura, sin WordPress: la prueba tests/run.php.
	 *
	 * @param string $binary ruta absoluta del sendmail ('' si no hay uno usable)
	 * @return array mailer ('sendmail' o null = no tocar), sendmail (comando) y sender
	 */
	public static function plan_return_path( $mailer, $verp, $plain, $binary, $windows ) {
		$out = [ 'mailer' => null, 'sendmail' => '', 'sender' => $verp ];
		if ( $mailer === 'smtp' ) return $out;
		if ( preg_match( '/^[A-Za-z0-9@_.\-]+$/', $verp ) ) return $out; // segura: PHPMailer pondrá el -f
		if ( in_array( $mailer, [ 'mail', 'sendmail' ], true ) && $binary !== '' && ! $windows && preg_match( '/^[A-Za-z0-9._\-]+\+[0-9a-f\-]+@[A-Za-z0-9.\-]+$/', $verp ) ) {
			return [ 'mailer' => 'sendmail', 'sendmail' => $binary . ' -f' . $verp, 'sender' => $verp ];
		}
		$out['sender'] = $plain;
		return $out;
	}

	/** El sendmail de php.ini (primer elemento de sendmail_path), si es una ruta absoluta ejecutable. */
	public static function sendmail_binary() {
		$path = trim( (string) ini_get( 'sendmail_path' ) );
		if ( $path === '' ) return '';
		$bin = preg_split( '/\s+/', $path )[0];
		if ( $bin === '' || $bin[0] !== '/' || ! preg_match( '/^[A-Za-z0-9._\/\-]+$/', $bin ) ) return '';
		return @is_executable( $bin ) ? $bin : ''; // @: open_basedir avisa si la ruta queda fuera
	}

	public static function catch_wp_error( $error ) {
		if ( is_wp_error( $error ) ) self::$wp_error = $error->get_error_message();
	}

	/** Un nombre con comas o comillas tiene que ir entre comillas en la cabecera From. */
	private static function encode_name( $name ) {
		$name = trim( str_replace( [ "\r", "\n", '"' ], '', (string) $name ) );
		return $name === '' ? '' : ( preg_match( '/[,;<>@()]/', $name ) ? '"' . $name . '"' : $name );
	}

	// ─── Amazon SES ─────────────────────────────────────────────────────────

	public static function ses_config( array $s, array $override = [] ) {
		$c = [
			'host' => $s['ses_host'],
			'port' => (int) $s['ses_port'],
			'user' => $s['ses_user'],
			'pass' => DXO_Settings::decrypt( $s['ses_pass'] ),
		];
		foreach ( $override as $k => $v ) {
			if ( $v !== '' && $v !== null ) $c[ $k ] = $v;
		}
		return $c;
	}

	private static function phpmailer( array $c ) {
		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

		$m = new PHPMailer\PHPMailer\PHPMailer( true );
		$m->isSMTP();
		$m->Host       = $c['host'];
		$m->Port       = (int) $c['port'];
		$m->SMTPAuth   = true;
		$m->Username   = $c['user'];
		$m->Password   = $c['pass'];
		// 465 y 2465 van con TLS desde el principio; 587, 25 y 2587 con STARTTLS.
		$m->SMTPSecure = in_array( (int) $c['port'], [ 465, 2465 ], true ) ? 'ssl' : 'tls';
		$m->SMTPKeepAlive = true;
		$m->Timeout    = 20;
		$m->CharSet    = 'UTF-8';
		return $m;
	}

	private static function send_ses( array $c, $to, $subject, $html, $text, array $headers, array $s ) {
		if ( ! $c['user'] || ! $c['pass'] ) return __( 'Amazon SES is missing the SMTP user or password.', 'dox-orbit' );
		if ( self::$ses === null ) self::$ses = self::phpmailer( $c );
		$m = self::$ses;
		$m->clearAllRecipients();
		$m->clearReplyTos();
		$m->clearCustomHeaders();
		$m->setFrom( $s['from_email'], $s['from_name'], false );
		// Con SES no se toca el remitente del sobre: SES puede rechazar un MAIL FROM sin verificar y gestiona los rebotes él mismo.
		$m->Sender = '';
		if ( is_email( $s['reply_to'] ) ) $m->addReplyTo( $s['reply_to'] );
		$m->addAddress( $to );
		$m->Subject = $subject;
		$m->isHTML( true );
		$m->Body    = $html;
		$m->AltBody = $text;
		foreach ( $headers as $name => $value ) $m->addCustomHeader( $name, $value );
		try {
			$m->send();
			return null;
		} catch ( Throwable $e ) {
			// Tras un error la conexión puede quedar a medias: la siguiente abre otra.
			$m->smtpClose();
			self::$ses = null;
			return $m->ErrorInfo ?: $e->getMessage();
		}
	}

	/**
	 * Comprueba que SES acepta el servidor, el usuario y la contraseña: conecta y
	 * se autentica sin mandar nada. Se usa al guardar Ajustes.
	 *
	 * @return string|null null si entra; el motivo si no
	 */
	public static function check_ses( array $c ) {
		$log = [];
		try {
			$m = self::phpmailer( $c );
			$m->SMTPDebug   = 2;
			$m->Debugoutput = function ( $line ) use ( &$log ) { $log[] = trim( preg_replace( '/\s+/', ' ', (string) $line ) ); };
			$ok = $m->smtpConnect();
			$m->smtpClose();
			return $ok ? null : ( self::server_reason( $log ) ?: __( 'Could not connect to Amazon SES.', 'dox-orbit' ) );
		} catch ( Throwable $e ) {
			return self::server_reason( $log ) ?: $e->getMessage();
		}
	}

	/**
	 * Con el error solo, PHPMailer resume un fallo de red o de contraseña en
	 * "SMTP connect() failed"; Amazon sí dice el motivo ("535 Authentication
	 * Credentials Invalid"). Se busca en la conversación.
	 */
	private static function server_reason( array $log ) {
		foreach ( array_reverse( $log ) as $line ) {
			if ( preg_match( '/SERVER -> CLIENT: ([45]\d\d\b.*)$/', $line, $m ) ) return trim( $m[1] );
		}
		return '';
	}
}
