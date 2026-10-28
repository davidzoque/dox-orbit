<?php
/**
 * Un servidor IMAP falso de una sola conexión, para tests/run.php (no viaja en el zip).
 * Imita el Dovecot de cPanel: separador ".", el aviso "buzón+token@" guardado en una carpeta
 * nueva con el nombre del token. Uso: php fake_imap.php <archivo-de-registro>
 */
$log = $argv[1];
$srv = stream_socket_server( 'tcp://127.0.0.1:0', $en, $es );
echo substr( strrchr( stream_socket_get_name( $srv, false ), ':' ), 1 ) . "\n";
fflush( STDOUT );
$conn = stream_socket_accept( $srv, 20 );
if ( ! $conn ) exit( 1 );

$bounce = function ( $addr ) {
	return "From: Mail Delivery System <Mailer-Daemon@srv1.hosting.net>\nTo: rebotes@sitio.com\nSubject: Mail delivery failed: returning message to sender\n\nThis is a permanent error. The following address(es) failed:\n\n  $addr\n    SMTP error from remote mail server after RCPT TO:<$addr>:\n    550 5.1.1 <$addr>: User unknown\n";
};
$boxes = [
	'INBOX'                 => [ 'flags' => '\HasChildren', 'msgs' => [ 5 => [ 'raw' => "From: ana@ejemplo.com\nTo: rebotes@sitio.com\nSubject: Hola\n\nHola\n", 'seen' => false ] ] ],
	'INBOX.1-4-abcdef0123'  => [ 'flags' => '\HasNoChildren', 'msgs' => [ 1 => [ 'raw' => $bounce( 'ana@ejemplo.com' ), 'seen' => false ] ] ],
	'INBOX.1'               => [ 'flags' => '\HasChildren', 'msgs' => [] ],
	'INBOX.1.3'             => [ 'flags' => '\HasChildren \Noselect', 'msgs' => [] ],
	'INBOX.1.3.7921af8add'  => [ 'flags' => '\HasNoChildren', 'msgs' => [ 1 => [ 'raw' => $bounce( 'luis@ejemplo.com' ), 'seen' => true ] ] ],
	'INBOX.Sent'            => [ 'flags' => '\HasNoChildren', 'msgs' => [ 1 => [ 'raw' => "Subject: x\n\nx\n", 'seen' => true ] ] ],
];
$sel = '';
fwrite( $conn, "* OK ready\r\n" );
while ( ( $line = fgets( $conn ) ) !== false ) {
	$line = rtrim( $line, "\r\n" );
	file_put_contents( $log, $line . "\n", FILE_APPEND );
	if ( ! preg_match( '/^(\S+) (\S+)(?: (.*))?$/', $line, $m ) ) continue;
	list( , $tag, $cmd ) = $m;
	$rest = isset( $m[3] ) ? $m[3] : '';
	$name = trim( $rest, '"' );
	$cmd  = strtoupper( $cmd );
	if ( $cmd === 'LOGIN' ) {
		fwrite( $conn, "$tag OK logged in\r\n" );
	} elseif ( $cmd === 'LIST' ) {
		foreach ( $boxes as $n => $b ) {
			// Un nombre va como literal para probar esa ruta.
			if ( $n === 'INBOX.Sent' ) fwrite( $conn, '* LIST (' . $b['flags'] . ') "." {' . strlen( $n ) . "}\r\n" . $n . "\r\n" );
			else fwrite( $conn, '* LIST (' . $b['flags'] . ') "." "' . $n . "\"\r\n" );
		}
		fwrite( $conn, "$tag OK done\r\n" );
	} elseif ( $cmd === 'SELECT' || $cmd === 'EXAMINE' ) {
		if ( ! isset( $boxes[ $name ] ) ) { fwrite( $conn, "$tag NO no such mailbox\r\n" ); continue; }
		$sel = $name;
		fwrite( $conn, '* ' . count( $boxes[ $sel ]['msgs'] ) . " EXISTS\r\n$tag OK [READ-WRITE] done\r\n" );
	} elseif ( $cmd === 'UID' && preg_match( '/^SEARCH (ALL|UNSEEN)/', $rest, $mm ) ) {
		$ids = [];
		foreach ( $boxes[ $sel ]['msgs'] as $uid => $msg ) { if ( $mm[1] === 'ALL' || ! $msg['seen'] ) $ids[] = $uid; }
		fwrite( $conn, '* SEARCH ' . implode( ' ', $ids ) . "\r\n$tag OK done\r\n" );
	} elseif ( $cmd === 'UID' && preg_match( '/^FETCH (\d+)/', $rest, $mm ) ) {
		$raw = $boxes[ $sel ]['msgs'][ (int) $mm[1] ]['raw'];
		fwrite( $conn, '* 1 FETCH (UID ' . $mm[1] . ' BODY[]<0> {' . strlen( $raw ) . "}\r\n" . $raw . ")\r\n$tag OK done\r\n" );
	} elseif ( $cmd === 'UID' && preg_match( '/^STORE (\d+) \+FLAGS\.SILENT \((\\\\\w+)\)/', $rest, $mm ) ) {
		if ( $mm[2] === '\Seen' ) $boxes[ $sel ]['msgs'][ (int) $mm[1] ]['seen'] = true;
		if ( $mm[2] === '\Deleted' ) $boxes[ $sel ]['msgs'][ (int) $mm[1] ]['deleted'] = true;
		fwrite( $conn, "$tag OK stored\r\n" );
	} elseif ( $cmd === 'EXPUNGE' ) {
		foreach ( $boxes[ $sel ]['msgs'] as $uid => $msg ) { if ( ! empty( $msg['deleted'] ) ) unset( $boxes[ $sel ]['msgs'][ $uid ] ); }
		fwrite( $conn, "$tag OK done\r\n" );
	} elseif ( $cmd === 'DELETE' ) {
		if ( ! isset( $boxes[ $name ] ) || $boxes[ $name ]['msgs'] ) { fwrite( $conn, "$tag NO cannot delete\r\n" ); continue; }
		unset( $boxes[ $name ] );
		// Al irse la última hija, la intermedia "sin abrir" desaparece sola, como en Dovecot.
		unset( $boxes['INBOX.1.3'] );
		fwrite( $conn, "$tag OK deleted\r\n" );
	} elseif ( $cmd === 'LOGOUT' ) {
		fwrite( $conn, "* BYE bye\r\n$tag OK done\r\n" );
		break;
	} else {
		fwrite( $conn, "$tag BAD unknown\r\n" );
	}
}
