<?php
/**
 * Un cliente IMAP mínimo, sin la extensión imap de PHP (PHP 8.4 la quitó del
 * núcleo y muchos hostings no la traen). Solo lo que hace falta para leer los
 * rebotes de un buzón propio: entrar, abrir la bandeja, buscar lo no leído,
 * bajar el principio de cada mensaje, marcarlo y salir.
 *
 * Seguridad: la conexión va cifrada siempre (TLS directo en el 993, STARTTLS en
 * el resto) y el certificado se verifica; si el servidor no ofrece cifrado, no
 * se manda la contraseña. Todos los fallos son excepciones con el texto del servidor.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DXO_IMAP {

	/** Segundos de espera en cada lectura y al conectar. */
	const TIMEOUT = 20;

	/** Un trozo más grande que esto no se acepta (un literal {n} desmedido). */
	const MAX_LITERAL = 2097152;

	/** Cuánto de cada mensaje se baja: un rebote cabe de sobra. */
	const FETCH_BYTES = 65536;

	/** Una línea de respuesta más larga que esto se corta: un servidor hostil no nos llena la memoria. */
	const MAX_LINE = 1048576;

	private $fp     = null;
	private $tag    = 0;
	/** Ya entramos con LOGIN: solo entonces se enseñan los textos del servidor. */
	private $authed = false;
	/** Hubo un fallo de red o un timeout: no tiene sentido mandar LOGOUT, solo cerrar. */
	private $broken = false;
	/** Lo que el servidor dijo de la bandeja al abrirla: exists, unseen. */
	public $mailbox = [];

	public function __destruct() {
		if ( is_resource( $this->fp ) ) @fclose( $this->fp );
	}

	// ═══ Conexión ═══════════════════════════════════════════════════════════

	/**
	 * @throws Exception con el motivo si no entra
	 */
	public function connect( $host, $port ) {
		$host = trim( (string) $host );
		$port = (int) $port;
		if ( $host === '' || ! preg_match( '/^[A-Za-z0-9.\-]+$|^\[[0-9A-Fa-f:.]+\]$/', $host ) ) {
			throw new Exception( 'Invalid IMAP server name.' );
		}
		if ( $port < 1 || $port > 65535 ) throw new Exception( 'Invalid IMAP port.' );

		$implicit = ( $port === 993 );
		$ssl = [
			'verify_peer'       => true,
			'verify_peer_name'  => true,
			'allow_self_signed' => false,
			'peer_name'         => trim( $host, '[]' ),
			'SNI_enabled'       => true,
			'SNI_server_name'   => trim( $host, '[]' ),
			'disable_compression' => true,
		];
		// Hay servidores sin lista de certificados raíz en el sistema: se usa la que trae WordPress (la verificación sigue encendida).
		if ( (string) ini_get( 'openssl.cafile' ) === '' && (string) ini_get( 'openssl.capath' ) === '' && function_exists( 'openssl_get_cert_locations' ) && defined( 'WPINC' ) ) {
			$loc    = openssl_get_cert_locations();
			$bundle = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
			if ( ( empty( $loc['default_cert_file'] ) || ! is_file( $loc['default_cert_file'] ) ) && is_readable( $bundle ) ) $ssl['cafile'] = $bundle;
		}
		$ctx = stream_context_create( [ 'ssl' => $ssl ] );

		$errno = 0;
		$errstr = '';
		$fp = @stream_socket_client( ( $implicit ? 'ssl://' : 'tcp://' ) . $host . ':' . $port, $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $ctx );
		if ( ! $fp ) {
			throw new Exception( sprintf( 'Could not connect to %s:%d (%s).', $host, $port, $errstr !== '' ? $errstr : 'error ' . $errno ) );
		}
		stream_set_timeout( $fp, self::TIMEOUT );
		$this->fp = $fp;

		$greeting = $this->read_line();
		if ( ! preg_match( '/^\* (OK|PREAUTH)\b/i', $greeting ) ) {
			// No se repite lo que diga: es texto de un servidor cualquiera.
			throw new Exception( 'The server did not answer like an IMAP server.' );
		}

		if ( ! $implicit ) {
			// STARTTLS: sin cifrado no se sigue, para no mandar la contraseña en claro.
			$cap = $this->command( [ 'CAPABILITY' ] );
			if ( ! preg_match( '/\bSTARTTLS\b/i', implode( ' ', $cap['untagged'] ) ) ) {
				throw new Exception( 'This server does not offer an encrypted connection (STARTTLS). Use port 993.' );
			}
			$this->command( [ 'STARTTLS' ] );
			$method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
			if ( defined( 'STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT' ) ) $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
			if ( defined( 'STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT' ) ) $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
			$ok = @stream_socket_enable_crypto( $this->fp, true, $method );
			if ( $ok !== true ) throw new Exception( 'Could not secure the connection: the server certificate is not valid for ' . $host . '.' );
		}
	}

	/** @throws Exception */
	public function login( $user, $pass ) {
		$this->command( [ 'LOGIN', $this->arg( $user ), $this->arg( $pass ) ] );
		$this->authed = true;
	}

	/**
	 * Abre una bandeja. $readonly = EXAMINE: no cambia nada (la prueba de conexión).
	 *
	 * @return int mensajes en la bandeja
	 * @throws Exception
	 */
	public function select( $mailbox = 'INBOX', $readonly = false ) {
		$r = $this->command( [ $readonly ? 'EXAMINE' : 'SELECT', $this->arg( $mailbox ) ] );
		$this->mailbox = [ 'exists' => 0 ];
		foreach ( $r['untagged'] as $line ) {
			if ( preg_match( '/^\* (\d+) EXISTS/i', $line, $m ) ) $this->mailbox['exists'] = (int) $m[1];
		}
		return $this->mailbox['exists'];
	}

	/**
	 * UID de los mensajes sin leer, los más antiguos primero.
	 *
	 * @return int[]
	 * @throws Exception
	 */
	public function unseen() {
		return self::parse_search( $this->command( [ 'UID', 'SEARCH', 'UNSEEN' ] )['untagged'] );
	}

	/**
	 * UID de todos los mensajes de la bandeja abierta (las carpetas de detalle, que son solo de rebotes).
	 *
	 * @return int[]
	 * @throws Exception
	 */
	public function search_all() {
		return self::parse_search( $this->command( [ 'UID', 'SEARCH', 'ALL' ] )['untagged'] );
	}

	/**
	 * Todas las carpetas del buzón.
	 *
	 * @return array delimiter (el separador de jerarquía) y boxes: [ name, flags (en minúsculas) ]
	 * @throws Exception
	 */
	public function list_mailboxes() {
		$r = $this->command( [ 'LIST', '""', '"*"' ] );
		return self::parse_list( $r['untagged'], $r['literals'] );
	}

	/** Borra una carpeta (vacía). @throws Exception */
	public function delete_mailbox( $name ) {
		$this->command( [ 'DELETE', $this->arg( $name ) ] );
	}

	/**
	 * Las respuestas "* LIST (\flags) "." nombre". El nombre puede venir entre comillas
	 * (con \ y \" escapados), como átomo o como literal {n} (en la línea queda
	 * "{literal}" y el texto en $literals, en el mismo orden).
	 */
	public static function parse_list( array $untagged, array $literals = [] ) {
		$delim = '';
		$boxes = [];
		$li    = 0;
		foreach ( $untagged as $line ) {
			$n_lit = substr_count( $line, '{literal}' );
			if ( ! preg_match( '/^\* LIST \(([^)]*)\) (NIL|"(?:[^"\\\\]|\\\\.)*") (.*)$/i', $line, $m ) ) {
				$li += $n_lit;
				continue;
			}
			if ( $delim === '' && strtoupper( $m[2] ) !== 'NIL' ) $delim = stripslashes( substr( $m[2], 1, -1 ) );
			$rest = trim( $m[3] );
			if ( $rest === '{literal}' ) {
				$name = isset( $literals[ $li ] ) ? $literals[ $li ] : '';
			} elseif ( isset( $rest[0] ) && $rest[0] === '"' && substr( $rest, -1 ) === '"' ) {
				$name = preg_replace( '/\\\\(.)/', '$1', substr( $rest, 1, -1 ) );
			} else {
				$name = $rest;
			}
			$li += $n_lit;
			if ( $name === '' ) continue;
			$flags = array_map( 'strtolower', preg_split( '/\s+/', trim( $m[1] ), -1, PREG_SPLIT_NO_EMPTY ) );
			$boxes[] = [ 'name' => $name, 'flags' => $flags ];
		}
		return [ 'delimiter' => $delim !== '' ? $delim : '.', 'boxes' => $boxes ];
	}

	/** Los números de "* SEARCH 1 2 3", ordenados. */
	private static function parse_search( array $untagged ) {
		$ids = [];
		foreach ( $untagged as $line ) {
			if ( preg_match( '/^\* SEARCH\b(.*)$/i', $line, $m ) ) {
				foreach ( preg_split( '/\s+/', trim( $m[1] ), -1, PREG_SPLIT_NO_EMPTY ) as $n ) $ids[] = (int) $n;
			}
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * El principio del mensaje, sin marcarlo como leído (BODY.PEEK).
	 *
	 * @throws Exception
	 */
	public function fetch( $uid ) {
		$r = $this->command( [ 'UID', 'FETCH', (string) (int) $uid, '(BODY.PEEK[]<0.' . self::FETCH_BYTES . '>)' ] );
		return isset( $r['literals'][0] ) ? $r['literals'][0] : '';
	}

	/** Pone o quita una marca: \Seen o \Deleted. @throws Exception */
	public function flag( $uid, $flag ) {
		if ( ! in_array( $flag, [ '\\Seen', '\\Deleted' ], true ) ) throw new Exception( 'Invalid flag.' );
		$this->command( [ 'UID', 'STORE', (string) (int) $uid, '+FLAGS.SILENT', '(' . $flag . ')' ] );
	}

	/** Quita de verdad lo marcado \Deleted. @throws Exception */
	public function expunge() {
		$this->command( [ 'EXPUNGE' ] );
	}

	public function logout() {
		if ( ! is_resource( $this->fp ) ) return;
		// Tras un error de red o un timeout no se espera otra respuesta: solo se cierra.
		if ( $this->authed && ! $this->broken ) {
			try {
				$this->command( [ 'LOGOUT' ] );
			} catch ( Exception $e ) {
				// Da igual: se cierra de todos modos.
			}
		}
		@fclose( $this->fp );
		$this->fp = null;
	}

	// ═══ Protocolo ══════════════════════════════════════════════════════════

	/**
	 * Un argumento como cadena entre comillas (con \ y " escapados) o, si trae
	 * algo que no cabe entre comillas, como literal {n}.
	 *
	 * @return string|array el texto listo, o [ 'literal' => '...' ]
	 */
	private function arg( $s ) {
		$s = (string) $s;
		if ( strpbrk( $s, "\r\n\0" ) !== false ) throw new Exception( 'The user or password has characters IMAP cannot send.' );
		if ( preg_match( '/^[\x20-\x7E]*$/', $s ) ) {
			return '"' . str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], $s ) . '"';
		}
		return [ 'literal' => $s ];
	}

	/**
	 * Manda un comando con etiqueta y lee hasta su respuesta.
	 *
	 * @param array $parts palabras sueltas o [ 'literal' => texto ]
	 * @return array untagged (líneas), literals (los {n} que llegaron), text
	 * @throws Exception si la respuesta no es OK
	 */
	private function command( array $parts ) {
		if ( ! is_resource( $this->fp ) ) throw new Exception( 'Not connected.' );
		$tag    = 'A' . str_pad( (string) ++$this->tag, 4, '0', STR_PAD_LEFT );
		$line   = $tag;
		$logout = $parts === [ 'LOGOUT' ];
		$space  = false; // tras un literal, el siguiente argumento va precedido de un espacio

		foreach ( $parts as $p ) {
			if ( is_array( $p ) ) {
				// Literal sincronizado: se avisa del tamaño y se espera el "+" del servidor.
				$this->write( $line . ' {' . strlen( $p['literal'] ) . "}\r\n" );
				$cont = $this->read_line();
				if ( strncmp( $cont, '+', 1 ) !== 0 ) {
					throw new Exception( $this->authed ? $this->clip( $cont ) : 'The server did not accept the login.' );
				}
				$this->write( $p['literal'] );
				$line  = '';
				$space = true;
			} else {
				$line .= ( $line === '' && ! $space ? '' : ' ' ) . $p;
				$space = false;
			}
		}
		$this->write( $line . "\r\n" );

		$untagged = [];
		$literals = [];
		while ( true ) {
			$l = $this->read_line( $literals );
			if ( strncmp( $l, $tag . ' ', strlen( $tag ) + 1 ) === 0 ) {
				$rest = substr( $l, strlen( $tag ) + 1 );
				if ( ! preg_match( '/^OK\b/i', $rest ) ) {
					// NO / BAD: el texto del servidor ("Authentication failed", "Invalid credentials"...).
					// Solo se enseña el texto del servidor de LOGIN en adelante; antes (CAPABILITY, STARTTLS) es de un host cualquiera.
					if ( ! $this->authed && $parts[0] !== 'LOGIN' ) throw new Exception( 'The server refused the command.' );
					throw new Exception( $this->clip( trim( preg_replace( '/^(?:NO|BAD)\b\s*(?:\[[^\]]*\]\s*)?/i', '', $rest ) ) ?: $rest ) );
				}
				return [ 'untagged' => $untagged, 'literals' => $literals, 'text' => $rest ];
			}
			if ( ! $logout && strncmp( $l, '* BYE', 5 ) === 0 ) {
				$this->broken = true;
				throw new Exception( 'The server closed the connection.' );
			}
			$untagged[] = $l;
		}
	}

	private function write( $data ) {
		$len = strlen( $data );
		$off = 0;
		while ( $off < $len ) {
			$n = @fwrite( $this->fp, substr( $data, $off, 8192 ) );
			if ( $n === false || $n === 0 ) {
				$this->broken = true;
				throw new Exception( 'Lost the connection to the IMAP server.' );
			}
			$off += $n;
		}
	}

	/**
	 * Una línea de respuesta sin su salto. Si termina en {n}, lee esos n bytes
	 * (van a $literals) y sigue con el resto de la línea.
	 */
	private function read_line( &$literals = null ) {
		$out = '';
		$t0  = time();
		while ( true ) {
			$chunk = @fgets( $this->fp, 16384 );
			if ( $chunk === false ) {
				$this->broken = true;
				$meta = is_resource( $this->fp ) ? stream_get_meta_data( $this->fp ) : [];
				throw new Exception( ! empty( $meta['timed_out'] ) ? 'The IMAP server took too long to answer.' : 'Lost the connection to the IMAP server.' );
			}
			if ( time() - $t0 > 120 ) {
				$this->broken = true;
				throw new Exception( 'The IMAP server took too long to answer.' );
			}
			$out .= $chunk;
			if ( strlen( $out ) > self::MAX_LINE ) {
				$this->broken = true;
				throw new Exception( 'The IMAP server sent an answer that is too long.' );
			}
			if ( substr( $chunk, -1 ) !== "\n" ) continue; // la línea era más larga que el buffer

			$line = rtrim( $out, "\r\n" );
			if ( $literals !== null && preg_match( '/\{(\d+)\}$/', $line, $m ) ) {
				$n = (int) $m[1];
				if ( $n > self::MAX_LITERAL ) {
					$this->broken = true;
					throw new Exception( 'The server sent a message that is too large.' );
				}
				$literals[] = $this->read_bytes( $n );
				$out = substr( $line, 0, -strlen( $m[0] ) ) . '{literal}';
				continue; // el resto de la línea viene tras el literal
			}
			return $line;
		}
	}

	private function read_bytes( $n ) {
		$buf = '';
		$t0  = time();
		while ( strlen( $buf ) < $n ) {
			$chunk = @fread( $this->fp, min( 8192, $n - strlen( $buf ) ) );
			if ( $chunk === false || $chunk === '' ) {
				$meta = stream_get_meta_data( $this->fp );
				if ( ! empty( $meta['timed_out'] ) || feof( $this->fp ) || time() - $t0 > 120 ) {
					$this->broken = true;
					throw new Exception( 'Lost the connection to the IMAP server.' );
				}
				continue;
			}
			$buf .= $chunk;
		}
		return $buf;
	}

	/** Una línea del servidor recortada para ponerla en un mensaje de error. */
	private function clip( $s ) {
		$s = trim( preg_replace( '/\s+/', ' ', (string) $s ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 200 ) : substr( $s, 0, 200 );
	}
}
