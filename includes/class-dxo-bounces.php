<?php
/**
 * Los rebotes: lo que el servidor del destinatario devuelve cuando la dirección
 * no existe o no puede recibir.
 *
 * Con "Rebotes" activado en Ajustes, todo correo de Orbit sale con un buzón
 * propio como remitente del sobre (Return-Path). Cada hora (WP-Cron) se lee ese
 * buzón por IMAP, se interpreta cada aviso (DXO_Bounce_Parser) y:
 *
 * - definitivo (la dirección no existe): el suscriptor pasa a "bounced" y no se le
 *   vuelve a escribir (el envío salta a quien no está activo).
 * - pasajero (buzón lleno...): suma un aviso; al tercero seguido, a "bounced". Si abre o
 *   hace clic, los avisos vuelven a cero (ver DXO_Public).
 * - rechazo por spam o reputación (block): no dice nada de la dirección; se anota
 *   en el informe pero no se toca al suscriptor.
 *
 * El aviso se anota en el envío concreto (recipients.bounced_at y bounce_reason)
 * si trae la cabecera X-Dox-Orbit o si se encuentra el último envío a esa dirección.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DXO_Bounces {

	const HOOK      = 'dxo_bounces';
	/** Una pasada extra, un minuto después, cuando quedaban más de los que caben en una. */
	const HOOK_MORE = 'dxo_bounces_more';
	const LOCK      = 'dxo_bounces_lock';
	const STATUS    = 'dxo_bounce_status';

	/** Mensajes por pasada. */
	const BATCH = 100;

	/** Avisos pasajeros seguidos antes de dar la dirección por perdida. */
	const SOFT_LIMIT = 3;

	/** Un candado más viejo que esto se da por colgado. */
	const LOCK_SECONDS = 600;

	/** Segundos como máximo por pasada. */
	const BUDGET = 40;

	// ═══ Firma de la cabecera X-Dox-Orbit ═══════════════════════════════════
	// El buzón de rebotes es público (es el Return-Path de los correos) y los ids son
	// consecutivos: sin firma, cualquiera podría mandar un aviso falso y dar de baja
	// a un suscriptor. La firma solo la sabe este sitio.

	public static function sign( $campaign_id, $recipient_id ) {
		return substr( hash_hmac( 'sha256', (int) $campaign_id . '.' . (int) $recipient_id, wp_salt( 'auth' ) ), 0, 10 );
	}

	/** El valor completo de la cabecera: campaña.destinatario.firma */
	public static function header_value( $campaign_id, $recipient_id ) {
		return (int) $campaign_id . '.' . (int) $recipient_id . '.' . self::sign( $campaign_id, $recipient_id );
	}

	public static function init() {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		add_action( self::HOOK_MORE, [ __CLASS__, 'run' ] );
		add_action( 'init', [ __CLASS__, 'schedule' ] );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 120, 'hourly', self::HOOK );
		}
	}

	/** ¿Están puestos los datos para leer el buzón? */
	public static function configured( ?array $s = null ) {
		$s = $s ?: DXO_Settings::all();
		return ! empty( $s['bounce_enabled'] ) && is_email( $s['bounce_email'] ) && trim( (string) $s['imap_host'] ) !== '' && $s['imap_pass'] !== '';
	}

	/** Los datos de entrada al buzón. El usuario vacío es el propio correo de rebotes. */
	public static function credentials( array $s ) {
		return [
			'host' => trim( (string) $s['imap_host'] ),
			'port' => (int) $s['imap_port'] ?: 993,
			'user' => trim( (string) $s['imap_user'] ) !== '' ? trim( (string) $s['imap_user'] ) : (string) $s['bounce_email'],
			'pass' => DXO_Settings::decrypt( $s['imap_pass'] ),
		];
	}

	/** Lo que se muestra bajo la sección de Ajustes: cuándo, cómo salió y cuántos. */
	public static function last_status() {
		$st = get_option( self::STATUS, [] );
		return is_array( $st ) ? $st : [];
	}

	// ═══ Prueba de conexión ═════════════════════════════════════════════════

	/**
	 * Entra al buzón y cuenta lo que hay, sin cambiar nada.
	 *
	 * @return array exists
	 * @throws Exception con el texto del servidor si no entra
	 */
	public static function test( array $c ) {
		if ( $c['host'] === '' ) throw new Exception( __( 'Write the IMAP server of the mailbox.', 'dox-orbit' ) );
		if ( $c['pass'] === '' ) throw new Exception( __( 'Write the password of the mailbox.', 'dox-orbit' ) );
		$imap = new DXO_IMAP();
		try {
			$imap->connect( $c['host'], $c['port'] );
			$imap->login( $c['user'], $c['pass'] );
			$n = $imap->select( 'INBOX', true );
			$imap->logout();
			return [ 'exists' => $n ];
		} catch ( Exception $e ) {
			$imap->logout();
			throw new Exception( self::redact( $e->getMessage(), $c ) );
		}
	}

	/** El texto de un error sin la contraseña, por si el servidor la repitiera. */
	private static function redact( $msg, array $c ) {
		if ( $c['pass'] !== '' ) $msg = str_replace( $c['pass'], '***', $msg );
		return $msg;
	}

	// ═══ Una pasada ═════════════════════════════════════════════════════════

	/** @return int cuántos rebotes se reconocieron */
	public static function run() {
		$s = DXO_Settings::all();
		if ( ! self::configured( $s ) ) return 0;

		// Que dos pasadas no se pisen. Si una se cuelga, el candado caduca solo.
		if ( ! self::lock() ) return 0;

		$c     = self::credentials( $s );
		$res   = [ 'time' => dxo_now(), 'ok' => true, 'message' => '', 'checked' => 0, 'found' => 0, 'hard' => 0, 'soft' => 0, 'block' => 0, 'unverified' => 0 ];
		$more  = false;
		$imap  = new DXO_IMAP();
		$bounce_email = (string) $s['bounce_email'];

		// Qué hacer con cada mensaje: 'delete' si es un rebote verificado y aplicado; 'seen' si no.
		$handle = function ( $raw, $folder_token ) use ( &$res, $bounce_email ) {
			$res['checked']++;
			$b = $raw !== '' ? DXO_Bounce_Parser::parse( $raw, $bounce_email, $folder_token ) : null;
			if ( $b === null ) return 'seen'; // no es un rebote
			$done = self::apply( $b );
			$res['found']++;
			$res[ $done ]++;
			return $done === 'unverified' ? 'seen' : 'delete'; // lo no verificable se deja, leído, para revisarlo
		};

		try {
			$imap->connect( $c['host'], $c['port'] );
			$imap->login( $c['user'], $c['pass'] );
			$sw   = self::sweep( $imap, $handle, self::BATCH, time() + self::BUDGET );
			$more = $sw['more'];
			$imap->logout();
		} catch ( Exception $e ) {
			$res['ok']      = false;
			$res['message'] = self::redact( $e->getMessage(), $c );
			$imap->logout();
		} catch ( Throwable $e ) {
			$res['ok']      = false;
			$res['message'] = 'Error: ' . self::redact( $e->getMessage(), $c );
			$imap->logout();
		}

		update_option( self::STATUS, $res, false );
		self::unlock();

		if ( $more && $res['ok'] && ! wp_next_scheduled( self::HOOK_MORE ) ) {
			wp_schedule_single_event( time() + 60, self::HOOK_MORE );
		}
		return $res['found'];
	}

	// ═══ Recorrer el buzón ══════════════════════════════════════════════════
	// Gmail y Microsoft 365 dejan el correo "buzón+token@" en la bandeja de entrada. El
	// Dovecot de cPanel (lmtp_save_to_detail_mailbox) lo guarda en una carpeta nueva con
	// el nombre del token, así que además de INBOX hay que mirar esas carpetas.

	/**
	 * Las carpetas cuyo nombre es un token VERP, con el token normalizado ("c.r.firma").
	 *
	 * @param array  $boxes lo que devuelve DXO_IMAP::list_mailboxes()['boxes']
	 * @return array lista de [ name, token ]
	 */
	public static function token_folders( array $boxes, $delimiter ) {
		$out = [];
		foreach ( $boxes as $b ) {
			if ( in_array( '\\noselect', $b['flags'], true ) || in_array( '\\nonexistent', $b['flags'], true ) ) continue;
			if ( strcasecmp( $b['name'], 'INBOX' ) === 0 ) continue;
			$t = DXO_Bounce_Parser::token_from_mailbox( $b['name'], $delimiter );
			if ( $t !== '' ) $out[] = [ 'name' => $b['name'], 'token' => $t ];
		}
		return $out;
	}

	/**
	 * Carpetas "de paso" que deja el formato antiguo con puntos (INBOX.1, INBOX.1.3): las que
	 * se llaman solo con números bajo INBOX y se pueden abrir, de la más profunda a la más alta.
	 */
	public static function numeric_parents( array $boxes, $delimiter ) {
		$out = [];
		foreach ( $boxes as $b ) {
			if ( in_array( '\\noselect', $b['flags'], true ) || in_array( '\\nonexistent', $b['flags'], true ) ) continue;
			$parts = explode( $delimiter, $b['name'] );
			if ( count( $parts ) < 2 || strcasecmp( array_shift( $parts ), 'INBOX' ) !== 0 ) continue;
			$ok = true;
			foreach ( $parts as $p ) { if ( ! ctype_digit( $p ) ) $ok = false; }
			if ( $ok ) $out[] = $b['name'];
		}
		usort( $out, function ( $a, $b ) { return substr_count( $b, '.' ) + substr_count( $b, '/' ) <=> substr_count( $a, '.' ) + substr_count( $a, '/' ); } );
		return $out;
	}

	/**
	 * Pasa por INBOX (lo no leído) y por cada carpeta con token (todo lo que haya), y
	 * borra las carpetas que se quedan vacías. No depende de WordPress: lo prueba tests/run.php.
	 *
	 * @param callable $handle ( $raw, $folder_token ) => 'delete' | 'seen'
	 * @return array processed (mensajes vistos), more (quedó trabajo), deleted_folders
	 */
	public static function sweep( DXO_IMAP $imap, callable $handle, $limit, $until ) {
		$list    = $imap->list_mailboxes();
		$delim   = $list['delimiter'];
		$st      = [ 'processed' => 0, 'more' => false, 'deleted_folders' => [] ];

		$imap->select( 'INBOX' );
		self::drain( $imap, $imap->unseen(), '', $handle, $limit, $until, $st );

		foreach ( self::token_folders( $list['boxes'], $delim ) as $f ) {
			if ( $st['processed'] >= $limit || time() >= $until ) { $st['more'] = true; break; }
			$imap->select( $f['name'] );
			$d = self::drain( $imap, $imap->search_all(), $f['token'], $handle, $limit, $until, $st );
			// Vacía del todo (se borró todo lo que había y no quedó nada por mirar): se quita la carpeta.
			if ( $d['kept'] === 0 && $d['left'] === 0 ) {
				$imap->select( 'INBOX' ); // no se borra una carpeta abierta
				$imap->delete_mailbox( $f['name'] );
				$st['deleted_folders'][] = $f['name'];
			}
		}

		if ( $st['deleted_folders'] ) {
			// El formato antiguo deja INBOX.1 e INBOX.1.3 detrás: si están vacías y sin hijas, también se van.
			$after = $imap->list_mailboxes();
			$names = array_column( $after['boxes'], 'name' );
			foreach ( self::numeric_parents( $after['boxes'], $delim ) as $name ) {
				$has_children = false;
				foreach ( $names as $n ) { if ( strpos( $n, $name . $delim ) === 0 ) { $has_children = true; break; } }
				if ( $has_children ) continue;
				if ( $imap->select( $name ) !== 0 ) continue;
				$imap->select( 'INBOX' );
				$imap->delete_mailbox( $name );
				$st['deleted_folders'][] = $name;
				$names = array_values( array_diff( $names, [ $name ] ) );
			}
		}
		return $st;
	}

	/** Procesa los mensajes de la bandeja abierta. @return array kept (se quedan) y left (sin mirar por el tope) */
	private static function drain( DXO_IMAP $imap, array $uids, $folder_token, callable $handle, $limit, $until, array &$st ) {
		$kept = 0;
		$left = 0;
		$gone = false;
		foreach ( $uids as $uid ) {
			if ( $st['processed'] >= $limit || time() >= $until ) { $left++; $st['more'] = true; continue; }
			$st['processed']++;
			if ( $handle( $imap->fetch( $uid ), $folder_token ) === 'delete' ) {
				$imap->flag( $uid, '\\Deleted' );
				$gone = true;
			} else {
				$imap->flag( $uid, '\\Seen' );
				$kept++;
			}
		}
		if ( $gone ) $imap->expunge();
		return [ 'kept' => $kept, 'left' => $left ];
	}

	// ═══ Candado ════════════════════════════════════════════════════════════
	// add_option es atómico (la tabla no admite dos filas con el mismo nombre). Si ya
	// existe y es viejo, se toma con un UPDATE condicional, también atómico.

	private static function lock() {
		global $wpdb;
		$now = time();
		// INSERT IGNORE: la tabla no admite dos filas con el mismo nombre, así que solo una pasada ve rows_affected = 1.
		// (add_option comprueba antes con get_option y dos peticiones podrían pasar a la vez.)
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, (string) $now ) );
		$got = ( (int) $wpdb->rows_affected === 1 );
		if ( ! $got ) {
			// Ya existía: se toma solo si es viejo (una pasada colgada), con un UPDATE condicional también atómico.
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
				(string) $now, self::LOCK, $now - self::LOCK_SECONDS
			) );
			$got = ( (int) $wpdb->rows_affected === 1 );
		}
		self::forget_lock_cache();
		return $got;
	}

	/** Se escribe directo en la base: que la caché de opciones no diga otra cosa. */
	private static function forget_lock_cache() {
		wp_cache_delete( self::LOCK, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	private static function unlock() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );
		self::forget_lock_cache();
	}

	// ═══ Aplicar un rebote ══════════════════════════════════════════════════

	/**
	 * Solo se actúa sobre un suscriptor si el aviso trae la cabecera X-Dox-Orbit con
	 * firma válida Y el correo del envío es el de la dirección que rebotó. Sin eso,
	 * no se toca nada ('unverified'): cualquiera puede escribir al buzón de rebotes.
	 *
	 * @param array $b lo que devuelve DXO_Bounce_Parser::parse()
	 * @return string hard|soft|block|unverified lo que se hizo
	 */
	public static function apply( array $b ) {
		global $wpdb;
		$rt = DXO_Install::table( 'recipients' );

		// Primero el token VERP (la dirección a la que llegó el aviso), luego la cabecera X-Dox-Orbit.
		$rec = null;
		foreach ( [ 'verp' => (string) ( $b['verp'] ?? '' ), 'orbit' => (string) ( $b['orbit'] ?? '' ) ] as $kind => $token ) {
			if ( ! preg_match( '/^(\d+)\.(\d+)\.([a-f0-9]{10})$/', $token, $m ) ) continue;
			if ( ! hash_equals( self::sign( $m[1], $m[2] ), $m[3] ) ) continue;
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $rt WHERE id = %d AND campaign_id = %d", $m[2], $m[1] ), ARRAY_A );
			if ( ! $row ) continue;
			// Si el lector sacó la dirección que falló, tiene que ser la de ese envío. Sin dirección, solo vale con VERP,
			// que identifica al destinatario por sí mismo.
			if ( $b['email'] !== '' ? strcasecmp( $row['email'], $b['email'] ) !== 0 : $kind !== 'verp' ) continue;
			$rec = $row;
			break;
		}
		if ( ! $rec ) return 'unverified';

		// La marca de este envío es lo que evita contar dos veces el mismo aviso.
		$fresh = empty( $rec['bounced_at'] );
		if ( $fresh ) {
			$wpdb->update( $rt, [
				'bounced_at'    => dxo_now(),
				'bounce_reason' => mb_substr( $b['type'] . ( $b['status'] !== '' ? ' ' . $b['status'] : '' ) . ': ' . $b['reason'], 0, 250 ),
			], [ 'id' => $rec['id'] ] );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . DXO_Install::table( 'campaigns' ) . ' SET bounced = bounced + 1 WHERE id = %d', $rec['campaign_id'] ) );
			DXO_Campaigns::log_event( (int) $rec['campaign_id'], (int) $rec['id'], 'bounce' );
		}

		$sub = DXO_Subscribers::get( (int) $rec['subscriber_id'] );
		// Los que se dieron de baja ya no reciben nada: no se les cambia el estado.
		if ( ! $sub || $sub['status'] === 'unsubscribed' ) return $b['type'];

		$st = DXO_Install::table( 'subscribers' );
		if ( $b['type'] === 'hard' ) {
			DXO_Subscribers::set_status( (int) $sub['id'], 'bounced' );
		} elseif ( $b['type'] === 'soft' && $fresh ) {
			// Un aviso pasajero cuenta una sola vez por envío.
			$wpdb->query( $wpdb->prepare( "UPDATE $st SET soft_bounces = LEAST(soft_bounces + 1, 60000) WHERE id = %d", $sub['id'] ) );
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT soft_bounces FROM $st WHERE id = %d", $sub['id'] ) );
			if ( $n >= self::SOFT_LIMIT ) DXO_Subscribers::set_status( (int) $sub['id'], 'bounced' );
		}
		return $b['type'];
	}
}
