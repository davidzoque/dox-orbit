<?php
/**
 * Lee un rebote (el correo que devuelve el servidor del destinatario) y dice a
 * qué dirección se refiere y si es definitivo o pasajero.
 *
 * No depende de WordPress, para poder probarlo suelto (php tests/run.php).
 * El texto viene de internet: se trata como no fiable, con tope de tamaño, de
 * profundidad y de partes, y nada de lo que trae se ejecuta ni se incluye sin escapar.
 *
 * parse() devuelve null si no es un rebote (respuesta automática, aviso de
 * "retrasado", un correo cualquiera) o un array:
 *   email  la dirección que falló, en minúsculas
 *   type   hard (no existe) | soft (buzón lleno, fallo pasajero) | block (la rechaza
 *          por spam o por política nuestra: no dice nada de la dirección)
 *   status el código tipo 5.1.1 ('' si no vino)
 *   reason el motivo en una línea, máximo 250 caracteres
 *   verp   "campaña.destinatario.firma" del buzón+token@ al que llegó el aviso, o ''
 *   orbit  "campaña.destinatario.firma" de la cabecera X-Dox-Orbit del correo devuelto, o ''
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DXO_Bounce_Parser {

	/** Más de esto no se mira: un rebote real cabe de sobra. */
	const MAX_BYTES = 262144;
	const MAX_DEPTH = 6;
	const MAX_PARTS = 40;

	/** Si la dirección fallida no existe en el servidor de destino. */
	const RE_UNKNOWN = '/user unknown|unknown user|no such (?:user|recipient|mailbox|address)|does ?n.t exist|does not exist|not exist|doesn.t have an? .{1,40}account|address not found|(?:could ?n.t|could not|can.?t|cannot|wasn.t|was not) be found|recipient (?:rejected|not found|unknown|address rejected)|invalid (?:recipient|address|mailbox)|mailbox (?:unavailable|not found|disabled|is disabled)|account (?:is |has been )?(?:disabled|inactive|suspended|expired|closed)|unrouteable address|unroutable|bad destination mailbox|user not found|no mailbox here|undeliverable address|recipnotfound|nosuchuser|nxdomain|domain (?:not found|does not exist)|host or domain name not found|unknown (?:recipient|mailbox|address)|address unknown|recipient unknown|not a valid mailbox|mailbox not found/i';

	/** El rechazo es por el remitente (nosotros), no por la dirección de destino. */
	const RE_SENDER = '/sender[^\n]{0,60}(?:rejected|verif|domain|not found|does not exist|invalid|denied|not allowed|policy)|(?:rejected|verif)[^\n]{0,30}sender|\bmail from\b|\bhelo\b|\behlo\b|reverse dns|\brdns\b|\bptr record/i';

	/** Sin duda alguna habla del remitente (nosotros). */
	const RE_SENDER_STRONG = '/sender[^\n]{0,20}(?:address )?(?:rejected|verify|verification)|sender domain|domain of sender/i';

	/** Avisos de "aún lo estamos intentando": no son un fallo. */
	const RE_DELAY = '/(?:will|shall) (?:keep|continue) (?:trying|to try)|no action is required|(?:delivery|delivering) (?:has been |is |was )?delayed|has been delayed|delayed (?:for|by|\d)|warning: message|still (?:being tried|trying)|delay notification|not yet (?:been )?delivered|retry(?:ing)? (?:for|until|again)/i';

	/** El texto dice que el fallo es definitivo. */
	const RE_PERMANENT = '/permanent(?:ly)? (?:error|failure|fatal)|failed permanently|has failed|delivery has failed|permanent error/i';

	/** Buzón lleno o sin espacio: pasajero. */
	const RE_FULL = '/mailbox (?:is )?full|over ?quota|quota exceeded|exceed(?:ed|s) (?:its |the |their )?(?:storage|quota|mailbox)|insufficient (?:system )?storage|mailbox size limit|storage allocation|out of storage|disk quota/i';

	/** El destino rechaza por algo nuestro (spam, reputación, política), no por la dirección. */
	const RE_BLOCK = '/spam|blocked|blacklist|block list|blocklist|reputation|dmarc|\bspf\b|\brbl\b|spamhaus|denied by policy|policy reasons|unsolicited|not authorized|not authorised|authentication (?:required|failed)|relay(?:ing)? (?:not permitted|denied)|ip address.{0,30}(?:listed|blocked)|message (?:content )?rejected|poor reputation/i';

	/** Cuerpo de la respuesta automática (vacaciones, fuera de oficina). */
	const RE_AUTO_SUBJECT = '/out of (?:the )?office|automatic reply|auto[- ]?reply|autoreply|auto-response|autorespuesta|respuesta autom|fuera de(?:l la)? ?(?:la )?oficina|ausente|vacation|on leave|away from (?:my )?(?:desk|office)|absence/i';

	/** Asunto de un aviso de entrega fallida. */
	const RE_BOUNCE_SUBJECT = '/undeliver|delivery (?:status|failure|failed|has failed|problem)|returned mail|failure notice|mail delivery failed|could not be delivered|couldn.?t be delivered|non[- ]delivery|not delivered|delivery incomplete|mail system error|returned to sender|message not delivered|bounced|address not found/i';

	/**
	 * @param string $raw          el correo completo (o el principio: se admite cortado)
	 * @param string $bounce_email el buzón de rebotes; si se da, el token VERP solo vale en direcciones de ese buzón
	 * @param string $extra_verp   un token VERP sacado de otra parte (el nombre de la carpeta donde estaba el aviso), si las cabeceras no lo traen
	 * @return array|null
	 */
	public static function parse( $raw, $bounce_email = '', $extra_verp = '' ) {
		$raw = (string) $raw;
		if ( $raw === '' ) return null;
		$raw = substr( $raw, 0, self::MAX_BYTES );
		$raw = str_replace( [ "\r\n", "\r" ], "\n", $raw );
		$raw = str_replace( "\0", '', $raw );

		$parts_left = self::MAX_PARTS;
		$leaves     = [];
		$root       = self::entity( $raw, 0, $parts_left, $leaves );
		$h          = $root['headers'];

		$ctype   = self::ctype( $h );
		$from    = strtolower( self::first( $h, 'from' ) );
		$subject = self::decode_header( self::first( $h, 'subject' ) );
		$orbit   = self::find_orbit( $leaves, $raw );
		$verp    = self::find_verp( $h, $bounce_email );
		if ( $verp === '' && preg_match( '/^\d{1,10}\.\d{1,10}\.[a-f0-9]{10}$/', (string) $extra_verp ) ) $verp = $extra_verp;

		$dsn_leaf = null;
		foreach ( $leaves as $l ) {
			if ( $l['type'] === 'message/delivery-status' ) { $dsn_leaf = $l; break; }
		}
		$is_report = $ctype['type'] === 'multipart/report' && strtolower( $ctype['params']['report-type'] ?? '' ) === 'delivery-status';
		$from_mta  = (bool) preg_match( '/mailer-daemon|postmaster|mail delivery (?:sub)?system|mail administrator|mailerdaemon|mail-daemon/', $from );

		// ── Respuestas automáticas: no son rebotes ──
		// Ojo: Exim y Postfix marcan sus propios avisos de rebote con Auto-Submitted, así
		// que solo cuenta si no parece un aviso del servidor de correo.
		if ( ! $dsn_leaf && ! $is_report && ! $from_mta && ! preg_match( self::RE_BOUNCE_SUBJECT, $subject ) && self::is_auto_reply( $h, $subject ) ) return null;

		// ── Camino estándar: RFC 3464 ──
		if ( $dsn_leaf ) {
			$res = self::from_dsn( $dsn_leaf['body'], $leaves, $verp !== '' );
			if ( $res === false ) return null; // el aviso dice "retrasado" o "entregado"
			if ( $res !== null ) {
				$res['orbit'] = $orbit;
				$res['verp']  = $verp;
				return $res;
			}
			// Un delivery-status vacío o ilegible: se intenta con el texto.
		}

		// ── Camino por texto: Exim, Postfix, Gmail, Microsoft, Yahoo... ──
		if ( ! $from_mta && ! $is_report && ! preg_match( self::RE_BOUNCE_SUBJECT, $subject ) ) return null;

		$text = '';
		foreach ( $leaves as $l ) {
			if ( in_array( $l['type'], [ 'text/plain', 'text/html' ], true ) ) {
				$body = $l['body'];
				if ( $l['type'] === 'text/html' ) $body = self::html_to_text( $body );
				$text .= $body . "\n";
			}
		}
		if ( $text === '' ) $text = $root['body'];
		$text = self::trim_original( $text );

		$res = self::from_text( $text, $from, $h, $subject, $verp !== '' );
		if ( $res === null ) return null;
		$res['orbit'] = $orbit;
		$res['verp']  = $verp;
		return $res;
	}

	// ═══ Camino estándar ════════════════════════════════════════════════════

	/**
	 * El cuerpo de message/delivery-status: un bloque para el mensaje y uno por
	 * destinatario, separados por una línea en blanco.
	 *
	 * @return array|null|false array = rebote, null = no se pudo leer, false = no es un fallo
	 */
	private static function from_dsn( $body, array $leaves, $has_verp = false ) {
		$blocks = preg_split( '/\n[ \t]*\n/', trim( $body ) );
		$saw    = false;
		foreach ( $blocks as $block ) {
			$f = self::fields( $block );
			if ( ! isset( $f['action'] ) && ! isset( $f['final-recipient'] ) ) continue;
			$saw    = true;
			$action = strtolower( trim( explode( ' ', $f['action'] ?? '' )[0] ) );
			if ( $action !== 'failed' ) continue; // delayed, delivered, relayed, expanded

			$email = self::address( $f['original-recipient'] ?? '' ) ?: self::address( $f['final-recipient'] ?? '' );
			$diag  = trim( preg_replace( '/^[a-z0-9.\-]+;\s*/i', '', $f['diagnostic-code'] ?? '' ) );
			$status = '';
			if ( preg_match( '/\b([245])\.(\d{1,3})\.(\d{1,3})\b/', $f['status'] ?? '', $m ) ) {
				$status = $m[0];
			} elseif ( preg_match( '/\b([245]\.\d{1,3}\.\d{1,3})\b/', $diag, $m ) ) {
				$status = $m[1];
			}
			if ( $status === '' && preg_match( '/\b([45])\d\d\b/', $diag, $m ) ) $status = $m[1] . '.0.0';
			if ( $status === '' || $status[0] === '2' ) return null;

			if ( $email === '' ) {
				// El DSN no trae la dirección (raro): se busca en el texto legible.
				$email = self::email_from_text( self::text_of( $leaves ), '', [] );
			}
			// Con VERP el destinatario lo identifica el propio token: la dirección puede faltar.
			if ( $email === '' && ! $has_verp ) return null;

			$reason = $diag !== '' ? $diag : ( $f['status'] ?? $status );
			$type   = self::classify( $status, '', $diag, $diag );
			return [
				'email'  => $email,
				'type'   => $type,
				'status' => $status,
				'reason' => self::tidy( $reason ),
			];
		}
		return $saw ? false : null;
	}

	/**
	 * Qué clase de rebote es. El orden importa: primero lo pasajero, luego lo que
	 * es por el remitente (nosotros), y solo después lo que dice que la dirección
	 * de destino no existe.
	 *
	 * @param string $status 5.1.1 ('' si no vino)
	 * @param string $code   550 ('' si no vino)
	 * @param string $focus  la línea del error (para saber si habla del remitente)
	 * @param string $hay    todo el texto disponible
	 * @return string|null hard|soft|block, o null si no se sabe
	 */
	private static function classify( $status, $code, $focus, $hay ) {
		$cls = $status !== '' ? $status[0] : ( $code !== '' ? $code[0] : '' );
		if ( preg_match( self::RE_FULL, $hay ) || in_array( $status, [ '5.2.2', '4.2.2', '5.2.3', '5.3.4' ], true ) ) return 'soft';
		if ( $cls === '4' ) return 'soft';
		// "Sender address rejected: User unknown" lleva un 5.1.1 pero habla del remitente.
		if ( preg_match( self::RE_SENDER_STRONG, $focus ) || in_array( $status, [ '5.1.7', '5.1.8' ], true ) ) return 'block';
		// Los códigos de destinatario explícitos mandan sobre las palabras sueltas.
		if ( in_array( $status, [ '5.1.1', '5.1.2', '5.1.3', '5.1.6', '5.2.1' ], true ) ) return 'hard';
		// Lo demás del remitente, solo en la línea del error (no en todo el mensaje).
		if ( preg_match( self::RE_SENDER, $focus ) ) return 'block';
		if ( preg_match( self::RE_UNKNOWN, $hay ) ) return 'hard';
		if ( strpos( $status, '5.7.' ) === 0 || preg_match( self::RE_BLOCK, $hay ) ) return 'block';
		return $cls === '5' ? 'soft' : null; // un 5xx sin motivo claro solo cuenta como aviso
	}

	// ═══ Camino por texto ═══════════════════════════════════════════════════

	private static function from_text( $text, $from, array $headers, $subject, $has_verp = false ) {
		$status = '';
		$code   = '';
		$line   = '';
		$more   = '(?:\n[ \t]+[^\n]*){0,2}'; // el error puede seguir en las líneas con sangría

		// Un aviso de "retrasado" o de advertencia no es un fallo, salvo que diga que es definitivo.
		if ( ( preg_match( self::RE_DELAY, $text ) || preg_match( '/\b(?:delay(?:ed)?|warning)\b/i', $subject ) ) && ! preg_match( self::RE_PERMANENT, $text ) ) return null;

		// "550 5.1.1 ..." (código SMTP seguido del estado ampliado), lo más fiable.
		if ( preg_match( '/\b([45]\d\d)[ -]+([45]\.\d{1,3}\.\d{1,3})\b[^\n]*' . $more . '/', $text, $m ) ) {
			$code = $m[1]; $status = $m[2]; $line = $m[0];
		} elseif ( preg_match( '/(?:said|returned|response|reply|error|rejected|smtp|code)[^\n]{0,80}?[:;\'"\s]([45][0-5]\d)[ :\-]+[^\n]{0,200}' . $more . '/i', $text, $m ) ) {
			$code = $m[1]; $line = $m[0];
		} elseif ( preg_match( '/(?:^|\n)[ \t]*([45][0-5]\d)[ :\-]+[^\n]{0,200}' . $more . '/', $text, $m ) ) {
			$code = $m[1]; $line = trim( $m[0] );
		}
		if ( $status === '' && preg_match( '/\b([45]\.\d{1,3}\.\d{1,3})\b/', $text, $m ) ) $status = $m[1];
		if ( $code === '' && $status !== '' ) $code = $status[0] . '00';

		// El motivo puede estar en la línea del código o en las de alrededor: se mira todo el texto.
		$hay = $line . "\n" . $text;

		if ( $code === '' && ! preg_match( self::RE_UNKNOWN, $text ) && ! preg_match( self::RE_FULL, $text ) ) return null;

		$type = self::classify( $status, $code, $line, $hay );
		if ( $type === null ) return null;

		// A quién iba: quitamos nuestro buzón de rebotes y al servidor de correo.
		$skip = [];
		foreach ( [ 'to', 'delivered-to', 'x-original-to' ] as $k ) {
			$a = self::address( self::first( $headers, $k ) );
			if ( $a !== '' ) $skip[] = $a;
		}
		$email = self::email_from_text( $text, $from, $skip );
		if ( $email === '' && ! $has_verp ) return null;

		$reason = $line !== '' ? $line : self::first_line_matching( $text, [ self::RE_UNKNOWN, self::RE_FULL ] );
		if ( $reason === '' ) $reason = $type === 'hard' ? 'Address does not exist' : 'Delivery failed';
		return [
			'email'  => $email,
			'type'   => $type,
			'status' => $status,
			'reason' => self::tidy( $reason ),
		];
	}

	/**
	 * La dirección que falló, en el texto del aviso. Cada servidor lo escribe a su
	 * manera; se prueba de lo más específico a lo más general.
	 */
	private static function email_from_text( $text, $from, array $skip ) {
		$e = '([A-Za-z0-9._%+\'\-]{1,64}@[A-Za-z0-9.\-]{1,190}\.[A-Za-z]{2,24})';
		$patterns = [
			// Microsoft: "Delivery has failed to these recipients or groups:\n\nana@x.com"
			'/(?:delivery has failed to these recipients or groups|the following recipient\(s\) cannot be reached|following (?:addresses|recipients) (?:failed|were rejected)|the following address(?:es)? (?:\(es\) )?(?:failed|had permanent fatal errors)|could not be delivered to the following)[^\n]*\n+\s*[<(]?' . $e . '/i',
			// Exim: "following address(es) failed:\n\n  ana@x.com"
			'/address\(es\) failed:\s*\n+\s*(?:[^\n]*?[<(])?' . $e . '/i',
			// Postfix y Yahoo: "<ana@x.com>: host mx said: 550 ..."
			'/(?:^|\n)[ \t]*<' . $e . '>:/',
			// Gmail: "Your message wasn't delivered to ana@x.com because ..."
			'/(?:wasn.t|was not|could ?n.t be|could not be|cannot be|can.t be) delivered to\s+<?' . $e . '/i',
			'/(?:delivery to|unable to deliver (?:message )?to|message to)\s+(?:the following (?:recipient|address)[^\n]*:\s*)?<?' . $e . '/i',
			'/(?:recipient|recipient address|failed recipient|original-recipient|final-recipient)\s*:?\s*(?:rfc822;)?\s*<?' . $e . '/i',
		];
		foreach ( $patterns as $re ) {
			if ( preg_match_all( $re, $text, $m ) ) {
				foreach ( $m[1] as $cand ) {
					$cand = self::clean_candidate( $cand, $from, $skip );
					if ( $cand !== '' ) return $cand;
				}
			}
		}
		// Último recurso: el primer correo del texto que no sea del servidor ni nuestro.
		if ( preg_match_all( '/' . $e . '/', $text, $m ) ) {
			foreach ( array_slice( $m[1], 0, 15 ) as $cand ) {
				$cand = self::clean_candidate( $cand, $from, $skip );
				if ( $cand !== '' ) return $cand;
			}
		}
		return '';
	}

	private static function clean_candidate( $addr, $from, array $skip ) {
		$a = strtolower( rtrim( trim( $addr ), '.>)' ) );
		if ( ! filter_var( $a, FILTER_VALIDATE_EMAIL ) ) return '';
		if ( preg_match( '/^(?:mailer-daemon|postmaster|mail-daemon|mailerdaemon|abuse|noreply|no-reply|bounce[s]?|root)@/', $a ) ) return '';
		if ( in_array( $a, $skip, true ) || preg_match( '/\+\d{1,10}[.\-]\d{1,10}[.\-][a-f0-9]{10}@/', $a ) ) return '';
		if ( $from !== '' && strpos( $from, $a ) !== false ) return '';
		return $a;
	}

	/** Lo que viene después de "aquí va una copia del mensaje" es el original: su texto no cuenta. */
	private static function trim_original( $text ) {
		$cut = preg_split( '/\n-{2,}[^\n]{0,60}(?:copy of the message|original message|message headers|forwarded message|returned message|begin message)[^\n]*\n|\n(?:Original message headers|Message headers):|\nReporting-MTA:/i', $text, 2 );
		return $cut[0];
	}

	// ═══ Respuestas automáticas ═════════════════════════════════════════════

	private static function is_auto_reply( array $h, $subject ) {
		$auto = strtolower( trim( self::first( $h, 'auto-submitted' ) ) );
		if ( $auto !== '' && $auto !== 'no' ) return true;
		if ( self::first( $h, 'x-autoreply' ) !== '' || self::first( $h, 'x-autorespond' ) !== '' || self::first( $h, 'x-auto-response' ) !== '' ) return true;
		if ( preg_match( '/auto[_-]?reply/i', self::first( $h, 'precedence' ) ) ) return true;
		return (bool) preg_match( self::RE_AUTO_SUBJECT, $subject );
	}

	// ═══ Correo: partes y cabeceras ═════════════════════════════════════════

	/**
	 * Parte un correo (o una parte) en cabeceras y cuerpo, y baja por las partes
	 * anidadas. Cada hoja que sirve queda en $leaves con su cuerpo ya decodificado.
	 */
	private static function entity( $raw, $depth, &$left, array &$leaves ) {
		$left--;
		$pos = strpos( $raw, "\n\n" );
		if ( strncmp( $raw, "\n", 1 ) === 0 ) { $head = ''; $body = ltrim( $raw, "\n" ); }
		elseif ( $pos === false ) { $head = $raw; $body = ''; }
		else { $head = substr( $raw, 0, $pos ); $body = substr( $raw, $pos + 2 ); }

		$h     = self::parse_headers( $head );
		$ctype = self::ctype( $h );
		$enc   = strtolower( trim( self::first( $h, 'content-transfer-encoding' ) ) );
		$out   = [ 'headers' => $h, 'body' => $body ];

		if ( $depth >= self::MAX_DEPTH || $left <= 0 ) return $out;

		if ( strpos( $ctype['type'], 'multipart/' ) === 0 && ! empty( $ctype['params']['boundary'] ) ) {
			$b     = $ctype['params']['boundary'];
			$pieces = explode( '--' . $b, $body );
			array_shift( $pieces ); // lo anterior a la primera frontera
			foreach ( $pieces as $piece ) {
				if ( $left <= 0 ) break;
				if ( strncmp( $piece, '--', 2 ) === 0 ) break; // cierre
				$piece = ltrim( $piece, " \t" );
				if ( strncmp( $piece, "\n", 1 ) === 0 ) $piece = substr( $piece, 1 );
				self::entity( $piece, $depth + 1, $left, $leaves );
			}
			return $out;
		}

		$decoded = self::decode_body( $body, $enc );

		if ( in_array( $ctype['type'], [ 'message/rfc822', 'message/global', 'message/partial' ], true ) ) {
			// Una copia del mensaje original: se miran sus cabeceras y, si hace falta, su cuerpo.
			$hp = strpos( $decoded, "\n\n" );
			$leaves[] = [ 'type' => 'text/rfc822-headers', 'headers' => $h, 'body' => $hp === false ? $decoded : substr( $decoded, 0, $hp ) ];
			return $out;
		}

		$type = $ctype['type'] !== '' ? $ctype['type'] : 'text/plain';
		if ( in_array( $type, [ 'text/plain', 'text/html', 'text/rfc822-headers', 'message/delivery-status' ], true ) ) {
			$leaves[] = [ 'type' => $type, 'headers' => $h, 'body' => $decoded ];
		} elseif ( strpos( $type, 'multipart/' ) !== 0 && $type !== 'application/octet-stream' && strpos( $type, 'text/' ) === 0 ) {
			$leaves[] = [ 'type' => 'text/plain', 'headers' => $h, 'body' => $decoded ];
		}
		return $out;
	}

	/** Cabeceras desplegadas (las líneas que empiezan con espacio siguen a la anterior). */
	private static function parse_headers( $head ) {
		$head = preg_replace( '/\n[ \t]+/', ' ', $head );
		$out  = [];
		foreach ( explode( "\n", $head ) as $line ) {
			if ( ! preg_match( '/^([A-Za-z0-9\-_]{1,60}):[ \t]*(.*)$/', $line, $m ) ) continue;
			$out[ strtolower( $m[1] ) ][] = trim( $m[2] );
		}
		return $out;
	}

	/** Bloque "Campo: valor" del delivery-status, en minúsculas. */
	private static function fields( $block ) {
		$h = self::parse_headers( $block );
		$o = [];
		foreach ( $h as $k => $v ) $o[ $k ] = $v[0];
		return $o;
	}

	private static function first( array $h, $name ) {
		return isset( $h[ $name ][0] ) ? (string) $h[ $name ][0] : '';
	}

	private static function ctype( array $h ) {
		$raw  = self::first( $h, 'content-type' );
		$bits = preg_split( '/;\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		$type = strtolower( trim( (string) array_shift( $bits ) ) );
		$par  = [];
		foreach ( $bits as $b ) {
			if ( preg_match( '/^([a-z0-9\-]+)\s*=\s*"?([^"]*)"?\s*$/i', $b, $m ) ) $par[ strtolower( $m[1] ) ] = $m[2];
		}
		return [ 'type' => $type, 'params' => $par ];
	}

	private static function decode_body( $body, $enc ) {
		if ( $enc === 'base64' ) {
			$d = base64_decode( preg_replace( '/[^A-Za-z0-9+\/=]/', '', $body ), false );
			return $d === false ? '' : str_replace( "\r", '', $d );
		}
		if ( $enc === 'quoted-printable' ) return quoted_printable_decode( $body );
		return $body;
	}

	private static function decode_header( $v ) {
		if ( strpos( $v, '=?' ) === false ) return $v;
		if ( function_exists( 'iconv_mime_decode' ) ) {
			$d = @iconv_mime_decode( $v, 2, 'UTF-8' );
			if ( $d !== false ) return $d;
		}
		if ( function_exists( 'mb_decode_mimeheader' ) ) return mb_decode_mimeheader( $v );
		return $v;
	}

	/** La dirección de "rfc822; ana@x.com", "Ana <ana@x.com>" o "ana@x.com". */
	private static function address( $v ) {
		if ( $v === '' ) return '';
		if ( preg_match( '/<([^<>\s]+@[^<>\s]+)>/', $v, $m ) ) $v = $m[1];
		elseif ( preg_match( '/([A-Za-z0-9._%+\'\-]+@[A-Za-z0-9.\-]+)/', $v, $m ) ) $v = $m[1];
		$v = strtolower( trim( $v, " \t<>;" ) );
		return filter_var( $v, FILTER_VALIDATE_EMAIL ) ? $v : '';
	}

	private static function html_to_text( $html ) {
		$html = preg_replace( '/<(?:script|style)\b.*?<\/(?:script|style)>/is', ' ', $html );
		$html = preg_replace( '/<\s*(?:br|\/p|\/div|\/tr|\/li|\/h\d)\b[^>]*>/i', "\n", $html );
		return html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private static function text_of( array $leaves ) {
		$t = '';
		foreach ( $leaves as $l ) {
			if ( $l['type'] === 'text/plain' ) $t .= $l['body'] . "\n";
			elseif ( $l['type'] === 'text/html' ) $t .= self::html_to_text( $l['body'] ) . "\n";
		}
		return self::trim_original( $t );
	}

	/** X-Dox-Orbit del mensaje devuelto: en una parte con las cabeceras del original, o en cualquier sitio. */
	private static function find_orbit( array $leaves, $raw ) {
		// Solo vale la firmada (campaña.destinatario.firma): sin firma cualquiera podría inventarla.
		$re = '/^[> \t]*X-Dox-Orbit:[ \t]*(\d{1,10})\.(\d{1,10})\.([a-f0-9]{10})[ \t]*$/mi';
		foreach ( $leaves as $l ) {
			if ( preg_match( $re, $l['body'], $m ) ) return $m[1] . '.' . $m[2] . '.' . $m[3];
		}
		if ( preg_match( $re, $raw, $m ) ) return $m[1] . '.' . $m[2] . '.' . $m[3];
		return '';
	}

	/**
	 * El token VERP: el buzón de rebotes se usó como "buzón+campaña.destinatario.firma@dominio"
	 * y el rebote llega dirigido a esa dirección. Se busca en las cabeceras donde los
	 * servidores dejan a quién iba, en este orden. La firma no se comprueba aquí.
	 */
	private static function find_verp( array $h, $bounce_email ) {
		$local  = '[A-Za-z0-9._%\-]+';
		$domain = '[A-Za-z0-9.\-]+';
		if ( $bounce_email !== '' && strrpos( $bounce_email, '@' ) !== false ) {
			$pos    = strrpos( $bounce_email, '@' );
			$local  = preg_quote( substr( $bounce_email, 0, $pos ), '/' );
			$domain = preg_quote( substr( $bounce_email, $pos + 1 ), '/' );
		}
		// El token va con '-' (campaña-destinatario-firma) para no crear carpetas anidadas en Dovecot; el antiguo con '.' también vale.
		$re = '/(?<![A-Za-z0-9._%+\-])' . $local . '\+(\d{1,10})([.\-])(\d{1,10})\2([a-f0-9]{10})@' . $domain . '(?![A-Za-z0-9.\-])/i';
		foreach ( [ 'delivered-to', 'x-original-to', 'envelope-to', 'x-envelope-to', 'original-recipient', 'to' ] as $name ) {
			foreach ( isset( $h[ $name ] ) ? $h[ $name ] : [] as $v ) {
				if ( preg_match( $re, $v, $m ) ) return strtolower( $m[1] . '.' . $m[3] . '.' . $m[4] );
			}
		}
		return '';
	}

	/**
	 * El token VERP en el nombre de una carpeta del buzón. Dovecot (cPanel) guarda lo que
	 * va tras el "+" en una carpeta con ese nombre: "INBOX.1-4-abcdef0123", o con el token
	 * antiguo, "INBOX.1.3.7921af8add" (los puntos son jerarquía). Devuelve "c.r.firma" o ''.
	 */
	public static function token_from_mailbox( $name, $delimiter = '.' ) {
		$delimiter = (string) $delimiter !== '' ? (string) $delimiter : '.';
		$parts = explode( $delimiter, (string) $name );
		if ( isset( $parts[0] ) && strcasecmp( $parts[0], 'INBOX' ) === 0 ) array_shift( $parts );
		if ( ! $parts ) return '';
		if ( preg_match( '/^(\d{1,10})([.\-])(\d{1,10})\2([a-f0-9]{10})$/i', implode( '.', $parts ), $m ) ) return strtolower( $m[1] . '.' . $m[3] . '.' . $m[4] );
		return '';
	}

	private static function first_line_matching( $text, array $res ) {
		foreach ( explode( "\n", $text ) as $line ) {
			foreach ( $res as $re ) {
				if ( preg_match( $re, $line ) ) return trim( $line );
			}
		}
		return '';
	}

	/** El motivo en una línea, sin caracteres raros y con tope. */
	private static function tidy( $s ) {
		$s = preg_replace( '/\s+/', ' ', (string) $s );
		if ( function_exists( 'iconv' ) ) $s = (string) @iconv( 'UTF-8', 'UTF-8//IGNORE', $s );
		$s = preg_replace( '/[^\P{C}]+/u', '', (string) $s ) ?? $s;
		$s = trim( (string) $s );
		if ( function_exists( 'mb_substr' ) ) return mb_substr( $s, 0, 250, 'UTF-8' );
		return substr( $s, 0, 250 );
	}
}
