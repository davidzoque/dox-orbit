<?php
/**
 * Pruebas del renderizador, del HTML propio (limpieza, CSS en línea, correos de código) y del lector de rebotes, sin WordPress: php tests/run.php
 * No viajan en el zip (el workflow excluye tests/).
 */
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../includes/class-dxo-renderer.php';
require __DIR__ . '/../includes/class-dxo-sanitizer.php';
require __DIR__ . '/../includes/class-dxo-inliner.php';
require __DIR__ . '/../includes/class-dxo-html-email.php';
require __DIR__ . '/../includes/class-dxo-bounce-parser.php';
require __DIR__ . '/../includes/class-dxo-mailer.php';
require __DIR__ . '/../includes/class-dxo-imap.php';
require __DIR__ . '/../includes/class-dxo-bounces.php';

$fails = 0;
function check( $name, $ok ) {
	global $fails;
	echo ( $ok ? "  ok   " : "  FAIL " ) . $name . "\n";
	if ( ! $ok ) $fails++;
}

echo "Campos\n";
check( 'nombre', DXO_Renderer::merge( 'Hola, {first_name}', [ 'first_name' => 'Ana' ] ) === 'Hola, Ana' );
check( 'respaldo', DXO_Renderer::merge( 'Hola, {first_name|amigo}', [] ) === 'Hola, amigo' );
check( 'sin nombre se lleva la coma', DXO_Renderer::merge( 'Hola, {first_name}:', [] ) === 'Hola:' );
check( 'se escapa', DXO_Renderer::merge( '{first_name}', [ 'first_name' => '<b>' ] ) === '&lt;b&gt;' );
check( 'campo desconocido se queda', DXO_Renderer::merge( '{plan}', [] ) === '{plan}' );
check( 'unknown_tags', DXO_Renderer::unknown_tags( 'a {plan} {first_name} {x|y}' ) === [ '{plan}', '{x}' ] );

echo "Limpieza del texto\n";
$c = DXO_Renderer::clean_text_html( '<div style="color:red">Hola <b>tú</b><script>alert(1)</script></div><a href="javascript:x">mal</a><a href="https://a.com">bien</a>' );
check( 'div pasa a p', strpos( $c, '<p>Hola <strong>tú</strong>' ) === 0 );
check( 'sin script ni su texto', stripos( $c, 'script' ) === false && strpos( $c, 'alert' ) === false );
check( 'sin javascript:', strpos( $c, 'javascript' ) === false );
check( 'enlace bueno', strpos( $c, '<a href="https://a.com">bien</a>' ) !== false );

echo "Bloques\n";
$b = DXO_Renderer::normalize_blocks( [ [ 'type' => 'nada' ], [ 'type' => 'button', 'text' => 'Ir', 'url' => 'javascript:x' ], [ 'type' => 'spacer', 'height' => 500 ] ] );
check( 'tipo desconocido fuera', count( $b ) === 2 );
check( 'url peligrosa vacía', $b[0]['url'] === '' );
check( 'espacio con tope', $b[1]['height'] === 96 );

echo "Correo\n";
$r = DXO_Renderer::render_email( [
	'blocks'          => DXO_Renderer::normalize_blocks( [ [ 'type' => 'text', 'html' => '<p>Mira <a href="https://a.com/x">esto</a></p>' ], [ 'type' => 'button', 'text' => 'Ir', 'url' => 'https://a.com/y', 'style' => 'accent' ] ] ),
	'brand'           => [ 'company' => 'Dox & Co', 'address' => 'Riviera Beach, FL', 'accent' => '#ff8d27', 'why' => 'Te suscribiste.' ],
	'subject'         => 'Hola {first_name}',
	'preheader'       => 'Pre &copy;',
	'fields'          => [ 'first_name' => 'Ana' ],
	'unsubscribe_url' => 'https://web.com/?dxo=unsubscribe&t=1',
	'pixel_url'       => 'https://web.com/?dxo=open&t=1',
	'link'            => function ( $u ) { return 'https://web.com/?dxo=click&l=' . md5( $u ); },
] );
check( 'enlaces con seguimiento', substr_count( $r['html'], 'dxo=click' ) === 2 );
check( 'la baja no se sigue', strpos( $r['html'], 'href="https://web.com/?dxo=unsubscribe&amp;t=1"' ) !== false );
check( 'pixel', strpos( $r['html'], 'dxo=open' ) !== false );
check( 'empresa escapada', strpos( $r['html'], 'Dox &amp; Co' ) !== false );
check( 'preheader escapado', strpos( $r['html'], 'Pre &amp;copy;' ) !== false );
check( 'texto plano con enlace', strpos( $r['text'], 'esto (https://web.com/?dxo=click' ) !== false );
check( 'botón naranja con texto oscuro', strpos( $r['html'], 'color:#141313;text-decoration:none' ) !== false );
check( 'texto sobre negro es blanco', DXO_Renderer::text_on( '#141313' ) === '#ffffff' );
check( 'texto sobre amarillo es oscuro', DXO_Renderer::text_on( '#ffe066' ) === '#141313' );


echo "Rebotes\n";

// Exim: aviso de texto, con la copia del mensaje original (y su X-Dox-Orbit) debajo.
$exim = <<<'MAIL'
Return-path: <>
Envelope-to: rebotes@sitio.com
Auto-Submitted: auto-replied
From: Mail Delivery System <Mailer-Daemon@srv1.hosting.net>
To: rebotes@sitio.com
Subject: Mail delivery failed: returning message to sender
Message-Id: <E1xyz-0001Ab-Cd@srv1.hosting.net>
Date: Tue, 27 Oct 2026 14:02:11 -0400

This message was created automatically by mail delivery software.

A message that you sent could not be delivered to one or more of its
recipients. This is a permanent error. The following address(es) failed:

  ana.perez@ejemplo.com
    host mx.ejemplo.com [203.0.113.5]
    SMTP error from remote mail server after RCPT TO:<ana.perez@ejemplo.com>:
    550 5.1.1 <ana.perez@ejemplo.com>: Recipient address rejected: User unknown in virtual mailbox table

------ This is a copy of the message, including all the headers. ------

Return-path: <rebotes@sitio.com>
X-Dox-Orbit: 12.345.a1b2c3d4e5
List-Unsubscribe: <https://sitio.com/?dxo=unsubscribe&t=abc>
From: Sitio <hola@sitio.com>
To: ana.perez@ejemplo.com
Subject: Novedades de octubre
MAIL;
$r = DXO_Bounce_Parser::parse( $exim );
check( 'Exim: dirección', $r && $r['email'] === 'ana.perez@ejemplo.com' );
check( 'Exim: definitivo 5.1.1', $r && $r['type'] === 'hard' && $r['status'] === '5.1.1' );
check( 'Exim: motivo', $r && stripos( $r['reason'], 'User unknown' ) !== false );
check( 'Exim: X-Dox-Orbit de la copia', $r && $r['orbit'] === '12.345.a1b2c3d4e5' );
check( 'Exim: Auto-Submitted de un aviso del servidor no lo descarta', $r !== null );

// Gmail: multipart/report estándar, con el original adjunto.
$gmail = <<<'MAIL'
From: Mail Delivery Subsystem <mailer-daemon@googlemail.com>
To: rebotes@sitio.com
Subject: Delivery Status Notification (Failure)
Auto-Submitted: auto-replied
MIME-Version: 1.0
Content-Type: multipart/report; boundary="000000000000a1b2c3"; report-type=delivery-status

--000000000000a1b2c3
Content-Type: multipart/related; boundary="000000000000d4e5f6"

--000000000000d4e5f6
Content-Type: multipart/alternative; boundary="000000000000g7h8i9"

--000000000000g7h8i9
Content-Type: text/plain; charset="UTF-8"

** Address not found **

Your message wasn't delivered to nadie@gmail.com because the address couldn't be found, or is unable to receive mail.

The response from the remote server was:

550 5.1.1 The email account that you tried to reach does not exist.

--000000000000g7h8i9
Content-Type: text/html; charset="UTF-8"

<p><b>Address not found</b></p>

--000000000000g7h8i9--
--000000000000d4e5f6--

--000000000000a1b2c3
Content-Type: message/delivery-status

Reporting-MTA: dns; googlemail.com
Received-From-MTA: dns; srv1.hosting.net
Arrival-Date: Tue, 27 Oct 2026 11:02:09 -0700 (PDT)

Final-Recipient: rfc822; nadie@gmail.com
Action: failed
Status: 5.1.1
Remote-MTA: dns; gmail-smtp-in.l.google.com. (142.250.1.27, the server for the domain gmail.com.)
Diagnostic-Code: smtp; 550-5.1.1 The email account that you tried to reach does not
 exist. Please try double-checking the recipient's email address for typos or
 unnecessary spaces. For more information, go to
 https://support.google.com/mail/?p=NoSuchUser abc123 - gsmtp
Last-Attempt-Date: Tue, 27 Oct 2026 11:02:10 -0700 (PDT)

--000000000000a1b2c3
Content-Type: message/rfc822

Received: by 2002:a05:6000:1 with SMTP id x1csp1234
X-Dox-Orbit: 7.88.0123456789
From: Sitio <hola@sitio.com>
To: nadie@gmail.com
Subject: Novedades de octubre
Content-Type: text/html; charset="UTF-8"

<p>Hola</p>

--000000000000a1b2c3--
MAIL;
$r = DXO_Bounce_Parser::parse( $gmail );
check( 'Gmail: dirección', $r && $r['email'] === 'nadie@gmail.com' );
check( 'Gmail: definitivo 5.1.1', $r && $r['type'] === 'hard' && $r['status'] === '5.1.1' );
check( 'Gmail: motivo del Diagnostic-Code (plegado)', $r && strpos( $r['reason'], 'does not exist. Please try' ) !== false && strpos( $r['reason'], "\n" ) === false );
check( 'Gmail: X-Dox-Orbit en el original adjunto', $r && $r['orbit'] === '7.88.0123456789' );

// Microsoft: NDR de texto, citado imprimible.
$ms = <<<'MAIL'
From: postmaster@contoso-com.mail.protection.outlook.com
To: rebotes@sitio.com
Subject: Undeliverable: Novedades de octubre
Content-Language: en-US
Content-Type: text/plain; charset="us-ascii"
Content-Transfer-Encoding: quoted-printable
X-MS-Exchange-Message-Is-Ndr:
Auto-Submitted: auto-generated

Delivery has failed to these recipients or groups:

carlos@contoso.com<mailto:carlos@contoso.com>
The email address you entered couldn't be found. Please check the recipient's=
 email address and try to resend the message. If the problem continues, please contact your email administrator.

Diagnostic information for administrators:

Generating server: BN8PR05MB1234.namprd05.prod.outlook.com

carlos@contoso.com
Remote Server returned '550 5.1.1 RESOLVER.ADR.RecipNotFound; not found'

Original message headers:

X-Dox-Orbit: 20.1001.abcdef0123
From: Sitio <hola@sitio.com>
To: carlos@contoso.com
MAIL;
$r = DXO_Bounce_Parser::parse( $ms );
check( 'Microsoft: dirección', $r && $r['email'] === 'carlos@contoso.com' );
check( 'Microsoft: definitivo', $r && $r['type'] === 'hard' && $r['status'] === '5.1.1' );
check( 'Microsoft: X-Dox-Orbit tras "Original message headers"', $r && $r['orbit'] === '20.1001.abcdef0123' );

// Postfix: buzón lleno (4.2.2), pasajero.
$postfix = <<<'MAIL'
Date: Tue, 27 Oct 2026 14:30:00 -0400 (EDT)
From: MAILER-DAEMON@srv1.hosting.net (Mail Delivery System)
Subject: Undelivered Mail Returned to Sender
To: rebotes@sitio.com
Auto-Submitted: auto-replied
MIME-Version: 1.0
Content-Type: multipart/report; report-type=delivery-status;
	boundary="7A1B2C3D4E.1793126400/srv1.hosting.net"

This is a MIME-encapsulated message.

--7A1B2C3D4E.1793126400/srv1.hosting.net
Content-Description: Notification
Content-Type: text/plain; charset=us-ascii

This is the mail system at host srv1.hosting.net.

I'm sorry to have to inform you that your message could not
be delivered to one or more recipients.

<lleno@ejemplo.org>: host mx.ejemplo.org[198.51.100.7] said: 452 4.2.2
    Mailbox full (in reply to RCPT TO command)

--7A1B2C3D4E.1793126400/srv1.hosting.net
Content-Description: Delivery report
Content-Type: message/delivery-status

Reporting-MTA: dns; srv1.hosting.net
X-Postfix-Queue-ID: 7A1B2C3D4E
Arrival-Date: Tue, 27 Oct 2026 14:29:58 -0400 (EDT)

Final-Recipient: rfc822; lleno@ejemplo.org
Original-Recipient: rfc822;lleno@ejemplo.org
Action: failed
Status: 4.2.2
Remote-MTA: dns; mx.ejemplo.org
Diagnostic-Code: smtp; 452 4.2.2 Mailbox full

--7A1B2C3D4E.1793126400/srv1.hosting.net
Content-Description: Undelivered Message Headers
Content-Type: text/rfc822-headers

Return-Path: <rebotes@sitio.com>
X-Dox-Orbit: 3.21.fedcba9876
From: Sitio <hola@sitio.com>
To: lleno@ejemplo.org

--7A1B2C3D4E.1793126400/srv1.hosting.net--
MAIL;
$r = DXO_Bounce_Parser::parse( $postfix );
check( 'Postfix: dirección', $r && $r['email'] === 'lleno@ejemplo.org' );
check( 'Postfix: 4.2.2 es pasajero', $r && $r['type'] === 'soft' && $r['status'] === '4.2.2' );
check( 'Postfix: X-Dox-Orbit en las cabeceras devueltas', $r && $r['orbit'] === '3.21.fedcba9876' );

// Un aviso de "retrasado" no es un rebote.
$delayed = <<<'MAIL'
From: Mail Delivery Subsystem <mailer-daemon@googlemail.com>
To: rebotes@sitio.com
Subject: Delivery Status Notification (Delay)
Content-Type: multipart/report; boundary="BND"; report-type=delivery-status

--BND
Content-Type: text/plain; charset="UTF-8"

** Message not delivered yet **
Gmail will keep trying for 47 hours more.

--BND
Content-Type: message/delivery-status

Reporting-MTA: dns; googlemail.com

Final-Recipient: rfc822; lento@ejemplo.com
Action: delayed
Status: 4.4.1
Diagnostic-Code: smtp; 421 4.4.1 connection timed out

--BND--
MAIL;
check( 'Aviso de retraso: se ignora', DXO_Bounce_Parser::parse( $delayed ) === null );

// Respuestas automáticas.
$ooo = <<<'MAIL'
From: Luis Gómez <luis@ejemplo.com>
To: rebotes@sitio.com
Subject: Out of office: Novedades de octubre
Auto-Submitted: auto-replied
X-Autoreply: yes
Content-Type: text/plain; charset=UTF-8

Estaré fuera de la oficina hasta el lunes. Si es urgente, escribe a soporte@ejemplo.com.
MAIL;
check( 'Fuera de oficina (cabeceras): se ignora', DXO_Bounce_Parser::parse( $ooo ) === null );
$ooo2 = "From: marta@ejemplo.com\nTo: rebotes@sitio.com\nSubject: Respuesta automática: Novedades\n\nGracias por tu mensaje. No estoy disponible. 550 gracias.\n";
check( 'Respuesta automática (solo asunto): se ignora', DXO_Bounce_Parser::parse( $ooo2 ) === null );
check( 'Un correo cualquiera no es un rebote', DXO_Bounce_Parser::parse( "From: ana@ejemplo.com\nTo: rebotes@sitio.com\nSubject: Hola\n\nMe equivoqué de dirección, perdona.\n" ) === null );
check( 'Vacío y basura', DXO_Bounce_Parser::parse( '' ) === null && DXO_Bounce_Parser::parse( str_repeat( "\xff\x00", 200 ) ) === null );

// Yahoo: texto plano con la copia del mensaje debajo.
$yahoo = <<<'MAIL'
From: MAILER-DAEMON@yahoo.com
To: rebotes@sitio.com
Subject: Failure Notice
Content-Type: text/plain; charset=UTF-8

Sorry, we were unable to deliver your message to the following address.

<mario@yahoo.com>:
554: delivery error: dd This user doesn't have a yahoo.com account (mario@yahoo.com) [0] - mta1234.mail.ne1.yahoo.com

--- Below this line is a copy of the message.

Received: from srv1.hosting.net
X-Dox-Orbit: 5.9.00ff00ff00
From: hola@sitio.com
To: mario@yahoo.com
MAIL;
$r = DXO_Bounce_Parser::parse( $yahoo );
check( 'Yahoo: dirección y definitivo', $r && $r['email'] === 'mario@yahoo.com' && $r['type'] === 'hard' );
check( 'Yahoo: X-Dox-Orbit', $r && $r['orbit'] === '5.9.00ff00ff00' );

// Rechazo por spam o por reputación: no dice nada de la dirección.
$spam = <<<'MAIL'
From: Mail Delivery Subsystem <mailer-daemon@googlemail.com>
To: rebotes@sitio.com
Subject: Delivery Status Notification (Failure)
Content-Type: multipart/report; boundary="BND"; report-type=delivery-status

--BND
Content-Type: text/plain

Your message could not be delivered.

--BND
Content-Type: message/delivery-status

Reporting-MTA: dns; googlemail.com

Final-Recipient: rfc822; persona@gmail.com
Action: failed
Status: 5.7.1
Diagnostic-Code: smtp; 550-5.7.1 This message was blocked because its content presents a potential spam risk

--BND--
MAIL;
$r = DXO_Bounce_Parser::parse( $spam );
check( 'Rechazo por spam: type block, no definitivo', $r && $r['email'] === 'persona@gmail.com' && $r['type'] === 'block' );

// Buzón lleno en un aviso de texto (Exim).
$full = "From: Mail Delivery System <Mailer-Daemon@srv1.hosting.net>\nTo: rebotes@sitio.com\nSubject: Mail delivery failed: returning message to sender\n\nThis message was created automatically by mail delivery software.\n\nA message that you sent could not be delivered to one or more of its\nrecipients. This is a permanent error. The following address(es) failed:\n\n  juan@ejemplo.net\n    mailbox is full: retry timeout exceeded\n    SMTP error from remote mail server after RCPT TO:<juan@ejemplo.net>:\n    552 5.2.2 Over quota\n";
$r = DXO_Bounce_Parser::parse( $full );
check( 'Exim: buzón lleno es pasajero', $r && $r['email'] === 'juan@ejemplo.net' && $r['type'] === 'soft' );

// Rechazos por el remitente (nosotros): no dicen nada de la dirección de destino.
$sender1 = <<<'MAIL'
From: Mail Delivery System <MAILER-DAEMON@srv1.hosting.net>
To: rebotes@sitio.com
Subject: Undelivered Mail Returned to Sender
Content-Type: multipart/report; report-type=delivery-status; boundary="BND"

--BND
Content-Type: text/plain

Could not deliver.

--BND
Content-Type: message/delivery-status

Reporting-MTA: dns; srv1.hosting.net

Final-Recipient: rfc822; cliente@ejemplo.com
Action: failed
Status: 5.7.1
Diagnostic-Code: smtp; 550 5.7.1 <rebotes@sitio.com>: Sender address rejected: Domain not found

--BND--
MAIL;
$r = DXO_Bounce_Parser::parse( $sender1 );
check( 'Remitente rechazado ("Domain not found"): block, no hard', $r && $r['type'] === 'block' );
$sender2 = str_replace( [ '5.7.1', '550 5.7.1 <rebotes@sitio.com>: Sender address rejected: Domain not found' ], [ '5.1.8', '553 5.1.8 Sender address domain does not exist' ], $sender1 );
$r = DXO_Bounce_Parser::parse( $sender2 );
check( '5.1.8 dominio del remitente: block', $r && $r['type'] === 'block' );
$sender3 = "From: Mail Delivery System <Mailer-Daemon@srv1.hosting.net>\nTo: rebotes@sitio.com\nSubject: Mail delivery failed: returning message to sender\n\nThis is a permanent error. The following address(es) failed:\n\n  cliente@ejemplo.com\n    SMTP error from remote mail server after MAIL FROM:<rebotes@sitio.com>:\n    553 5.1.8 Sender address domain does not exist\n";
$r = DXO_Bounce_Parser::parse( $sender3 );
check( 'Texto: remitente rechazado también es block', $r && $r['type'] === 'block' );
$r = DXO_Bounce_Parser::parse( str_replace( 'Status: 5.7.1', 'Status: 5.1.1', str_replace( '550 5.7.1 <rebotes@sitio.com>: Sender address rejected: Domain not found', '550 5.1.1 <cliente@ejemplo.com>: Recipient address rejected: User unknown', $sender1 ) ) );
check( 'Destinatario inexistente sigue siendo hard', $r && $r['type'] === 'hard' );
$r = DXO_Bounce_Parser::parse( str_replace( 'Status: 5.7.1', 'Status: 5.0.0', str_replace( '550 5.7.1 <rebotes@sitio.com>: Sender address rejected: Domain not found', '550 5.0.0 Transaction failed', $sender1 ) ) );
check( '5.x genérico sin motivo: solo soft', $r && $r['type'] === 'soft' );

// Cabecera sin firma: no se da por buena.
$r = DXO_Bounce_Parser::parse( str_replace( 'X-Dox-Orbit: 12.345.a1b2c3d4e5', 'X-Dox-Orbit: 12.345', $exim ) );
check( 'X-Dox-Orbit sin firma: orbit vacío', $r && $r['orbit'] === '' );

// VERP: el aviso llega dirigido a buzón+token@dominio y no trae el X-Dox-Orbit del original.
$ms_verp = str_replace( [ "To: rebotes@sitio.com", "X-Dox-Orbit: 20.1001.abcdef0123\n" ], [ "To: rebotes+20.1001.abcdef0123@sitio.com", "" ], $ms );
$r = DXO_Bounce_Parser::parse( $ms_verp, 'rebotes@sitio.com' );
check( 'VERP: token en el To (Microsoft sin X-Dox-Orbit)', $r && $r['verp'] === '20.1001.abcdef0123' && $r['orbit'] === '' && $r['email'] === 'carlos@contoso.com' );
$yahoo_verp = "Delivered-To: Rebotes+9.77.0011223344@Sitio.com\n" . $yahoo;
$yahoo_verp = str_replace( "X-Dox-Orbit: 5.9.00ff00ff00\n", '', $yahoo_verp );
$r = DXO_Bounce_Parser::parse( $yahoo_verp, 'rebotes@sitio.com' );
check( 'VERP: Delivered-To, sin distinguir mayúsculas', $r && $r['verp'] === '9.77.0011223344' );
$r = DXO_Bounce_Parser::parse( $yahoo_verp, 'otro@sitio.com' );
check( 'VERP: de otro buzón no vale', $r && $r['verp'] === '' );
$r = DXO_Bounce_Parser::parse( "X-Original-To: rebotes+1.2.abcdef0123@sitio.com\nFrom: Mail Delivery System <Mailer-Daemon@srv1.hosting.net>\nTo: rebotes@sitio.com\nSubject: Mail delivery failed\n\nSMTP error: 550 5.1.1 User unknown\n", 'rebotes@sitio.com' );
check( 'VERP: sin dirección fallida en el texto sigue siendo rebote', $r && $r['verp'] === '1.2.abcdef0123' && $r['email'] === '' && $r['type'] === 'hard' );
check( 'Sin VERP ni dirección: no es utilizable', DXO_Bounce_Parser::parse( "From: Mail Delivery System <Mailer-Daemon@srv1.hosting.net>\nTo: rebotes@sitio.com\nSubject: Mail delivery failed\n\nSMTP error: 550 5.1.1 User unknown\n", 'rebotes@sitio.com' ) === null );

// Un 5.1.1 de verdad cuyo cuerpo menciona MAIL FROM o helo sigue siendo definitivo.
$real511 = "From: Mail Delivery System <Mailer-Daemon@srv1.hosting.net>\nTo: rebotes@sitio.com\nSubject: Mail delivery failed: returning message to sender\n\nThis is a permanent error. The following address(es) failed:\n\n  ana@ejemplo.com\n    host mx.ejemplo.com [203.0.113.5]\n    SMTP error from remote mail server after RCPT TO:<ana@ejemplo.com>:\n    550 5.1.1 <ana@ejemplo.com>: User unknown\n\nTranscript: HELO srv1.hosting.net / MAIL FROM:<rebotes@sitio.com> / helo accepted.\n";
$r = DXO_Bounce_Parser::parse( $real511 );
check( '5.1.1 con "MAIL FROM" y "helo" en el cuerpo: hard', $r && $r['type'] === 'hard' && $r['email'] === 'ana@ejemplo.com' );
$r = DXO_Bounce_Parser::parse( str_replace( 'Status: 5.7.1', 'Status: 5.1.1', str_replace( '550 5.7.1 <rebotes@sitio.com>: Sender address rejected: Domain not found', '550 5.1.1 <rebotes@sitio.com>: Sender address rejected: User unknown in virtual mailbox table', $sender1 ) ) );
check( '5.1.1 "Sender address rejected": block, aunque lleve 5.1.1', $r && $r['type'] === 'block' );

// Aviso de retraso de Exim (no es un DSN): se ignora.
$exim_delay = "Auto-Submitted: auto-replied\nFrom: Mail Delivery System <Mailer-Daemon@srv1.hosting.net>\nTo: rebotes@sitio.com\nSubject: Warning: message 1xyz-0001Ab-Cd delayed 24 hours\n\nThis message was created automatically by mail delivery software.\n\nA message that you sent has not yet been delivered to one or more of its\nrecipients after more than 24 hours on the queue on srv1.hosting.net.\n\nThe message identifier is:     1xyz-0001Ab-Cd\n\nThe message will be retried for another 24 hours. No action is required on your part.\n\n  lento@ejemplo.com\n    host mx.ejemplo.com [203.0.113.5]\n    SMTP error from remote mail server after RCPT TO:<lento@ejemplo.com>:\n    451 4.7.1 Greylisted, please try again later\n";
check( 'Exim "delayed 24 hours" (451 greylisted): se ignora', DXO_Bounce_Parser::parse( $exim_delay ) === null );

// Un correo enorme o con partes de más no cuelga ni revienta.
$big = "From: MAILER-DAEMON@x.com\nSubject: Undelivered Mail Returned to Sender\nContent-Type: multipart/report; boundary=\"B\"; report-type=delivery-status\n\n" . str_repeat( "--B\nContent-Type: text/plain\n\nhola\n", 500 );
$t0 = microtime( true );
DXO_Bounce_Parser::parse( $big );
check( 'Muchas partes: termina rápido', microtime( true ) - $t0 < 1 );

echo "Return-Path (VERP) por transporte\n";
$verp  = 'rebotes+12-345-a1b2c3d4e5@sitio.com';
$plain = 'rebotes@sitio.com';
$p = DXO_Mailer::plan_return_path( 'smtp', $verp, $plain, '', false );
check( 'SMTP: el VERP tal cual, sin tocar el transporte', $p['sender'] === $verp && $p['mailer'] === null );
$p = DXO_Mailer::plan_return_path( 'mail', $verp, $plain, '/usr/sbin/sendmail', false );
check( 'mail() con sendmail válido: sendmail directo con -f', $p['mailer'] === 'sendmail' && $p['sendmail'] === '/usr/sbin/sendmail -f' . $verp && $p['sender'] === $verp );
$p = DXO_Mailer::plan_return_path( 'sendmail', $verp, $plain, '/usr/sbin/sendmail', false );
check( 'sendmail con binario válido: lo mismo', $p['mailer'] === 'sendmail' && $p['sender'] === $verp );
$p = DXO_Mailer::plan_return_path( 'mail', $verp, $plain, '', false );
check( 'mail() sin sendmail_path: cae a la dirección sin token', $p['mailer'] === null && $p['sender'] === $plain );
$p = DXO_Mailer::plan_return_path( 'mail', $verp, $plain, '/usr/sbin/sendmail', true );
check( 'Windows: sin token', $p['mailer'] === null && $p['sender'] === $plain );
$p = DXO_Mailer::plan_return_path( 'mail', 'a b+1.2.abcdef0123@x.com', $plain, '/usr/sbin/sendmail', false );
check( 'Dirección rara: sin token', $p['sender'] === $plain && $p['mailer'] === null );
$p = DXO_Mailer::plan_return_path( 'mail', $plain, $plain, '', false );
check( 'Sin VERP (dirección segura): PHPMailer pondrá el -f', $p['sender'] === $plain && $p['mailer'] === null );
check( 'sendmail_binary solo devuelve rutas absolutas ejecutables', DXO_Mailer::sendmail_binary() === '' || DXO_Mailer::sendmail_binary()[0] === '/' );

// De punta a punta con el PHPMailer de WordPress y un sendmail falso que apunta sus argumentos.
$pm_dir = null;
foreach ( [ getenv( 'DXO_PHPMAILER_DIR' ), '/Users/davidzoque/dev/wp-newsletter/wordpress/wp-includes/PHPMailer' ] as $d ) {
	if ( $d && is_file( $d . '/PHPMailer.php' ) ) { $pm_dir = $d; break; }
}
if ( $pm_dir ) {
	require_once $pm_dir . '/Exception.php';
	require_once $pm_dir . '/PHPMailer.php';
	require_once $pm_dir . '/SMTP.php';
	$tmp  = sys_get_temp_dir() . '/dxo-sendmail-' . getmypid();
	@mkdir( $tmp );
	file_put_contents( $tmp . '/sendmail', "#!/bin/sh\necho \"\$@\" > '$tmp/args'\ncat > /dev/null\n" );
	chmod( $tmp . '/sendmail', 0755 );

	$phpmailer = new PHPMailer\PHPMailer\PHPMailer( true );
	$phpmailer->isMail();
	$GLOBALS['phpmailer'] = $phpmailer;
	$set = Closure::bind( function ( $sender, $plain ) { self::$sender = $sender; self::$plain = $plain; }, null, 'DXO_Mailer' );
	$set( $verp, $plain );
	DXO_Mailer::capture_state( $phpmailer );
	// Lo que haría set_return_path con un sendmail válido (el plan se prueba arriba; aquí con el falso).
	$plan = DXO_Mailer::plan_return_path( $phpmailer->Mailer, $verp, $plain, $tmp . '/sendmail', false );
	$phpmailer->isSendmail();
	$phpmailer->Sendmail = $plan['sendmail'];
	$phpmailer->Sender   = $plan['sender'];
	$phpmailer->setFrom( 'hola@sitio.com', 'Sitio' );
	$phpmailer->addAddress( 'ana@ejemplo.com' );
	$phpmailer->Subject = 'Hola';
	$phpmailer->Body    = 'Hola';
	$phpmailer->send();
	$args = trim( (string) @file_get_contents( $tmp . '/args' ) );
	check( 'sendmail recibe -f con el VERP (PHPMailer no lo quita)', strpos( $args, '-f' . $verp ) !== false && substr_count( $args, '-f' ) === 1 );
	$restore = Closure::bind( function () { self::restore_phpmailer(); }, null, 'DXO_Mailer' );
	$restore();
	check( 'Se restauran Sender, Mailer y Sendmail del PHPMailer global', $phpmailer->Sender === '' && $phpmailer->Mailer === 'mail' );
	@unlink( $tmp . '/sendmail' ); @unlink( $tmp . '/args' ); @rmdir( $tmp );
} else {
	echo "  (se omite la prueba con PHPMailer: no está a mano; define DXO_PHPMAILER_DIR)\n";
}

echo "VERP con guion y carpetas de Dovecot\n";
// Token con guiones (el actual) y con puntos (el antiguo) en el To.
$ms_dash = str_replace( 'rebotes+20.1001.abcdef0123@', 'rebotes+20-1001-abcdef0123@', $ms_verp );
$r = DXO_Bounce_Parser::parse( $ms_dash, 'rebotes@sitio.com' );
check( 'VERP con guiones: se normaliza a c.r.firma', $r && $r['verp'] === '20.1001.abcdef0123' && $r['email'] === 'carlos@contoso.com' );
check( 'VERP antiguo con puntos: sigue valiendo', DXO_Bounce_Parser::parse( $ms_verp, 'rebotes@sitio.com' )['verp'] === '20.1001.abcdef0123' );
check( 'VERP mezclando separadores: no vale', DXO_Bounce_Parser::parse( str_replace( 'rebotes+20.1001.abcdef0123@', 'rebotes+20-1001.abcdef0123@', $ms_verp ), 'rebotes@sitio.com' )['verp'] === '' );
$r = DXO_Bounce_Parser::parse( "From: Mail Delivery System <Mailer-Daemon@srv1.hosting.net>\nTo: rebotes@sitio.com\nSubject: Mail delivery failed\n\nSMTP error: 550 5.1.1 User unknown\n", 'rebotes@sitio.com', '1.3.7921af8add' );
check( 'Token del nombre de la carpeta cuando las cabeceras no lo traen', $r && $r['verp'] === '1.3.7921af8add' && $r['type'] === 'hard' );
check( 'Un token de carpeta mal formado no se usa', ! DXO_Bounce_Parser::parse( "From: Mail Delivery System <Mailer-Daemon@srv1.hosting.net>\nSubject: Mail delivery failed\n\nSMTP error: 550 5.1.1 User unknown\n", 'rebotes@sitio.com', '../etc' ) );

check( 'Carpeta con guiones', DXO_Bounce_Parser::token_from_mailbox( 'INBOX.1-4-abcdef0123', '.' ) === '1.4.abcdef0123' );
check( 'Carpeta antigua anidada por puntos', DXO_Bounce_Parser::token_from_mailbox( 'INBOX.1.3.7921af8add', '.' ) === '1.3.7921af8add' );
check( 'Carpeta con separador "/"', DXO_Bounce_Parser::token_from_mailbox( 'INBOX/1/3/7921af8add', '/' ) === '1.3.7921af8add' && DXO_Bounce_Parser::token_from_mailbox( 'INBOX/1-4-abcdef0123', '/' ) === '1.4.abcdef0123' );
check( 'Carpeta en la raíz, sin INBOX', DXO_Bounce_Parser::token_from_mailbox( '1-4-abcdef0123', '.' ) === '1.4.abcdef0123' );
check( 'Carpetas normales no son token', DXO_Bounce_Parser::token_from_mailbox( 'INBOX.Sent', '.' ) === '' && DXO_Bounce_Parser::token_from_mailbox( 'INBOX', '.' ) === '' && DXO_Bounce_Parser::token_from_mailbox( 'INBOX.1-4-zzzzzzzzzz', '.' ) === '' );

// LIST: separador ".", "/", NIL, nombres entre comillas con escapes, literales y \Noselect.
$l = DXO_IMAP::parse_list( [
	'* LIST (\HasChildren) "." "INBOX"',
	'* LIST (\HasNoChildren) "." "INBOX.1-4-abcdef0123"',
	'* LIST (\HasChildren \Noselect) "." "INBOX.1.3"',
	'* LIST (\HasNoChildren) "." "INBOX.Co\"mo \\\\ va"',
	'* LIST (\HasNoChildren) "." {literal}',
	'* LIST (\HasNoChildren) "." INBOX.Atom',
	'* OK ignorado',
], [ 'INBOX.Sent' ] );
$names = array_column( $l['boxes'], 'name' );
check( 'LIST: separador "."', $l['delimiter'] === '.' );
check( 'LIST: nombres (comillas, escapes, literal, átomo)', $names === [ 'INBOX', 'INBOX.1-4-abcdef0123', 'INBOX.1.3', 'INBOX.Co"mo \\ va', 'INBOX.Sent', 'INBOX.Atom' ] );
check( 'LIST: banderas en minúsculas', $l['boxes'][2]['flags'] === [ '\haschildren', '\noselect' ] );
$l2 = DXO_IMAP::parse_list( [ '* LIST (\HasNoChildren) "/" "INBOX/1/3/7921af8add"', '* LIST (\Noselect) "/" "INBOX/1/3"', '* LIST () NIL "Raiz"' ] );
check( 'LIST: separador "/" y NIL', $l2['delimiter'] === '/' && count( $l2['boxes'] ) === 3 );
$tf = DXO_Bounces::token_folders( $l2['boxes'], $l2['delimiter'] );
check( 'Carpetas con token: salta las \Noselect y las normales', count( $tf ) === 1 && $tf[0]['name'] === 'INBOX/1/3/7921af8add' && $tf[0]['token'] === '1.3.7921af8add' );
$np = DXO_Bounces::numeric_parents( [ [ 'name' => 'INBOX.1', 'flags' => [] ], [ 'name' => 'INBOX.1.3', 'flags' => [ '\noselect' ] ], [ 'name' => 'INBOX.1.3.5', 'flags' => [] ], [ 'name' => 'INBOX.Sent', 'flags' => [] ], [ 'name' => 'INBOX', 'flags' => [] ] ], '.' );
check( 'Carpetas numéricas de paso: la más profunda primero, sin \Noselect', $np === [ 'INBOX.1.3.5', 'INBOX.1' ] );

// El recorrido completo contra un servidor IMAP falso (INBOX + carpeta con guiones + carpeta antigua con puntos).
$logf = sys_get_temp_dir() . '/dxo-imap-log-' . getmypid();
@unlink( $logf );
$proc = proc_open( [ PHP_BINARY, __DIR__ . '/fake_imap.php', $logf ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
$port = $proc ? (int) trim( (string) fgets( $pipes[1] ) ) : 0;
if ( $port ) {
	$imap = new DXO_IMAP();
	$fp   = stream_socket_client( 'tcp://127.0.0.1:' . $port, $en, $es, 5 );
	stream_set_timeout( $fp, 5 );
	Closure::bind( function ( $fp ) { $this->fp = $fp; $this->authed = true; $this->read_line(); }, $imap, 'DXO_IMAP' )( $fp );
	$seen_tokens = [];
	$sw = DXO_Bounces::sweep( $imap, function ( $raw, $tok ) use ( &$seen_tokens ) {
		$b = DXO_Bounce_Parser::parse( $raw, 'rebotes@sitio.com', $tok );
		if ( ! $b ) return 'seen';
		$seen_tokens[] = $tok . '|' . $b['email'];
		return $b['verp'] === $tok && $tok !== '' ? 'delete' : 'seen';
	}, 100, time() + 20 );
	$imap->logout();
	proc_close( $proc );
	$log = (string) @file_get_contents( $logf );
	check( 'Recorrido: miró INBOX y las dos carpetas con token', $sw['processed'] === 3 && $seen_tokens === [ '1.4.abcdef0123|ana@ejemplo.com', '1.3.7921af8add|luis@ejemplo.com' ] );
	check( 'Recorrido: leyó también el mensaje ya leído de la carpeta antigua (todo, no solo no leído)', in_array( '1.3.7921af8add|luis@ejemplo.com', $seen_tokens, true ) );
	check( 'Recorrido: borró las dos carpetas vacías y la intermedia INBOX.1', in_array( 'INBOX.1-4-abcdef0123', $sw['deleted_folders'], true ) && in_array( 'INBOX.1.3.7921af8add', $sw['deleted_folders'], true ) && in_array( 'INBOX.1', $sw['deleted_folders'], true ) );
	check( 'Recorrido: no tocó INBOX.Sent ni borró INBOX', strpos( $log, 'DELETE "INBOX.Sent"' ) === false && strpos( $log, 'DELETE "INBOX"' ) === false );
	check( 'Recorrido: lo que no es rebote en INBOX se marca leído, no se borra', strpos( $log, 'STORE 5 +FLAGS.SILENT (\Seen)' ) !== false && strpos( $log, 'STORE 5 +FLAGS.SILENT (\Deleted)' ) === false );
	check( 'Recorrido: el EXPUNGE va antes de borrar la carpeta', strpos( $log, 'EXPUNGE' ) < strpos( $log, 'DELETE "INBOX.1-4-abcdef0123"' ) );
} else {
	check( 'Servidor IMAP falso arrancó', false );
}
@unlink( $logf );
// Una carpeta con un aviso no verificable se queda, con su mensaje.

// ═══ HTML propio: limpieza, CSS en línea y correos de código ═══════════════

echo "Limpieza del HTML\n";
$dirty = '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=https://mal.com"><title>Hola</title>'
	. '<link rel="stylesheet" href="https://evil.com/x.css"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter"><base href="https://evil.com/">'
	. '<script>alert(1)</script></head>'
	. '<body onload="x()"><!-- nota --><!--[if mso]><table><tr><td><![endif]-->'
	. '<table width="600" cellpadding="0" cellspacing="0"><tr><td onclick="y()" style="color:red;width:expression(alert(1))" bgcolor="#fff">Hola ñ <a href="javascript:alert(1)">uno</a> <a href="JaVa&#09;ScRipt:alert(2)">dos</a> <a href="https://a.com/?x=1&y=2">tres</a> <a href="vbscript:x">cuatro</a>'
	. '<img src="data:image/png;base64,AAAA" alt="d"><img src="https://a.com/i.png" alt="ok"></td></tr></table>'
	. '<!--[if mso]></td></tr></table><![endif]-->'
	. '<iframe src="https://evil.com"></iframe><object data="x"></object><embed src="x"><form action="x"><input name="a"><textarea>t</textarea><select><option>1</option></select><button>Ir</button><p>queda</p></form>'
	. '<svg><script>alert(2)</script><circle r="1"/></svg><div style="background:url(javascript:alert(3));color:blue">z</div></body></html>';
$rep = null;
$clean = DXO_Sanitizer::document( $dirty, $rep );
check( 'quita script y su contenido', stripos( $clean, '<script' ) === false && strpos( $clean, 'alert(1)' ) === false );
check( 'quita on*', stripos( $clean, 'onclick' ) === false && stripos( $clean, 'onload' ) === false );
check( 'quita javascript: (también con tab y mayúsculas) y vbscript:', stripos( $clean, 'javascript' ) === false && stripos( $clean, 'vbscript' ) === false );
check( 'deja el enlace bueno con su &', strpos( $clean, 'href="https://a.com/?x=1&amp;y=2"' ) !== false );
check( 'quita iframe, object, embed', ! preg_match( '/<(iframe|object|embed)/i', $clean ) );
check( 'quita form, input, textarea, select, button', ! preg_match( '/<(form|input|textarea|select|button|option)/i', $clean ) );
check( 'pero deja el texto que había dentro del form', strpos( $clean, '<p>queda</p>' ) !== false );
check( 'quita base y meta refresh', stripos( $clean, '<base' ) === false && stripos( $clean, 'refresh' ) === false );
check( 'quita svg entero (y su script)', stripos( $clean, '<svg' ) === false && strpos( $clean, 'alert(2)' ) === false );
check( 'quita data: en imágenes', strpos( $clean, 'data:image' ) === false && strpos( $clean, 'alt="d"' ) === false );
check( 'deja la imagen https', strpos( $clean, 'src="https://a.com/i.png"' ) !== false );
check( 'quita expression() y url(javascript:) del style', stripos( $clean, 'expression' ) === false && stripos( $clean, 'url(javascript' ) === false );
check( 'deja el resto del style', strpos( $clean, 'color:red' ) !== false && strpos( $clean, 'color:blue' ) !== false );
check( 'link: solo Google Fonts', strpos( $clean, 'fonts.googleapis.com' ) !== false && strpos( $clean, 'evil.com/x.css' ) === false );
check( 'comentarios condicionales de Outlook se quedan', strpos( $clean, '<!--[if mso]><table><tr><td><![endif]-->' ) !== false && strpos( $clean, '<!--[if mso]></td></tr></table><![endif]-->' ) !== false );
check( 'otros comentarios fuera', strpos( $clean, 'nota' ) === false );
check( 'las tablas y sus atributos se quedan', strpos( $clean, '<table width="600" cellpadding="0" cellspacing="0">' ) !== false && strpos( $clean, 'bgcolor="#fff"' ) !== false );
check( 'respeta el UTF-8', strpos( $clean, 'ñ' ) !== false );
check( 'informa de lo quitado', isset( $rep['tags']['script'], $rep['tags']['iframe'] ) && $rep['attrs'] > 0 );
$bad_cond = DXO_Sanitizer::document( '<html><body><!--[if mso]><script>alert(1)</script><![endif]--><p>x</p></body></html>' );
check( 'un comentario condicional con script se quita', stripos( $bad_cond, 'script' ) === false && strpos( $bad_cond, '<p>x</p>' ) !== false );
$tags = DXO_Sanitizer::document( '<html><body><a href="{unsubscribe_url}">baja</a> <a href="https://a.com/?n={first_name|amigo}&e={email}">ok</a> <a href="{first_name}">no</a></body></html>' );
check( 'los {campos} sobreviven en un href', strpos( $tags, 'href="{unsubscribe_url}"' ) !== false && strpos( $tags, 'n={first_name|amigo}&amp;e={email}' ) !== false );
check( 'un {campo} de la persona no puede ser todo el href', strpos( $tags, 'href="{first_name}"' ) === false );
$frag = DXO_Sanitizer::fragment( '<html><head><title>x</title></head><body><p onclick="a()">Hola</p><script>x</script><style>p{color:red}</style></body></html>' );
check( 'trozo: sin html/head/body/title/script', ! preg_match( '/<(html|head|body|title|script)/i', $frag ) && strpos( $frag, '<p>Hola</p>' ) !== false && strpos( $frag, '<style>' ) !== false );

echo "CSS en línea\n";
$in = function ( $html ) { return DXO_Inliner::inline( '<html><head>' . $html ); };
$h = $in( '<style>.a{color:red}</style></head><body><p class="a">x</p><p>y</p></body></html>' );
check( 'clase', strpos( $h, '<p class="a" style="color:red">x</p>' ) !== false && strpos( $h, '<p>y</p>' ) !== false );
$h = $in( '<style>#u{color:red}</style></head><body><p id="u">x</p></body></html>' );
check( 'id', strpos( $h, 'id="u" style="color:red"' ) !== false );
$h = $in( '<style>p{margin:0}</style></head><body><p>x</p><div>y</div></body></html>' );
check( 'etiqueta', strpos( $h, '<p style="margin:0">x</p>' ) !== false && strpos( $h, '<div>y</div>' ) !== false );
$h = $in( '<style>td.x{color:red}</style></head><body><table><tr><td class="x">a</td><td class="y">b</td><td>c</td></tr></table></body></html>' );
check( 'compuesto td.x', strpos( $h, '<td class="x" style="color:red">a</td>' ) !== false && strpos( $h, '<td class="y">b</td>' ) !== false );
$h = $in( '<style>table td{padding:4px}.box p{color:red}</style></head><body><table><tr><td>a</td></tr></table><p>fuera</p><div class="box"><span><p>dentro</p></span></div></body></html>' );
check( 'descendiente (con espacio)', strpos( $h, '<td style="padding:4px">a</td>' ) !== false && strpos( $h, '<p>fuera</p>' ) !== false && strpos( $h, '<p style="color:red">dentro</p>' ) !== false );
$h = $in( '<style>div>p{color:red}</style></head><body><div><p>hijo</p><span><p>nieto</p></span></div></body></html>' );
check( 'hijo directo (>)', strpos( $h, '<p style="color:red">hijo</p>' ) !== false && strpos( $h, '<p>nieto</p>' ) !== false );
$h = $in( '<style>h1,h2,.k{color:red}</style></head><body><h1>a</h1><h2>b</h2><h3>c</h3><i class="k">d</i></body></html>' );
check( 'lista con comas', strpos( $h, '<h1 style="color:red">a</h1>' ) !== false && strpos( $h, '<h2 style="color:red">b</h2>' ) !== false && strpos( $h, '<h3>c</h3>' ) !== false && strpos( $h, '<i class="k" style="color:red">d</i>' ) !== false );
$h = $in( '<style>p{color:blue}.a{color:green}#i{color:red}</style></head><body><p class="a" id="i">x</p><p class="a">y</p><p>z</p></body></html>' );
check( 'especificidad: id > clase > etiqueta', strpos( $h, 'id="i" style="color:red"' ) !== false && strpos( $h, '<p class="a" style="color:green">y</p>' ) !== false && strpos( $h, '<p style="color:blue">z</p>' ) !== false );
$h = $in( '<style>.a{color:green}.a{color:red}</style></head><body><p class="a">x</p></body></html>' );
check( 'a igual especificidad gana la última', strpos( $h, 'style="color:red"' ) !== false && strpos( $h, 'green' ) === false );
$h = $in( '<style>p{color:red}</style></head><body><p style="color:blue;margin:1px">x</p></body></html>' );
check( 'el estilo en línea gana a la hoja', strpos( $h, 'color:blue' ) !== false && strpos( $h, 'red' ) === false && strpos( $h, 'margin:1px' ) !== false );
$h = $in( '<style>p{color:red !important}</style></head><body><p style="color:blue">x</p></body></html>' );
check( '!important de la hoja gana al estilo en línea', strpos( $h, 'color:red !important' ) !== false && strpos( $h, 'blue' ) === false );
$h = $in( '<style>p{color:red !important}</style></head><body><p style="color:blue !important">x</p></body></html>' );
check( '!important en línea gana al de la hoja', strpos( $h, 'color:blue !important' ) !== false && strpos( $h, 'red' ) === false );
$h = $in( '<style>.a{color:red}@media (max-width:600px){.a{color:blue !important}}a:hover{color:green}</style></head><body><p class="a">x</p></body></html>' );
check( '@media y :hover se quedan en un <style> del head', preg_match( '#<head>.*<style>.*@media \(max-width:600px\)\{\.a\{color:blue !important\}\}.*a:hover\{color:green\}.*</style>.*</head>#s', $h ) === 1 );
check( '...y lo que un @media pisa NO se pasa a línea (el estilo en línea le ganaría): se queda en el <style>', strpos( $h, '<p class="a" style="color:red">' ) === false && strpos( $h, '.a{color:red}' ) !== false );
$h = $in( '<style>.a{color:red;font-size:12px}@media (max-width:600px){.a{color:blue !important}}</style></head><body><p class="a">x</p></body></html>' );
check( '...pero las demás propiedades de esa regla sí', strpos( $h, '<p class="a" style="font-size:12px">' ) !== false && strpos( $h, '.a{color:red}' ) !== false );
$h = $in( '<style>.a{color:red}p{margin:0}</style></head><body><p class="a">x</p></body></html>' );
check( 'si todo se pasó a línea el <style> desaparece', stripos( $h, '<style' ) === false );
$h = $in( '<style>a.x, a:hover{color:red}</style></head><body><a class="x" href="#">x</a></body></html>' );
check( 'lista a medias: lo entendido a línea, lo demás se queda', strpos( $h, '<a class="x" href="#" style="color:red">' ) !== false && strpos( $h, 'a:hover{color:red}' ) !== false );
$h = $in( '<style>p[x=y]{color:red}:::raro{color:red}p+p{color:red}.ok{color:blue}</style></head><body><p class="ok">x</p></body></html>' );
check( 'un selector que no entiende se salta sin romper nada', strpos( $h, '<p class="ok" style="color:blue">x</p>' ) !== false );
$h = $in( '<style>body{margin:0}</style></head><body><p>x</p></body></html>' );
check( 'body recibe su estilo', strpos( $h, '<body style="margin:0">' ) !== false );
$h = $in( '<style>.a { color : red ; padding:0 } /* c */ .b{margin:2px;}</style></head><body><p class="a b">x</p></body></html>' );
check( 'espacios y comentarios en el CSS', strpos( $h, 'color:red' ) !== false && strpos( $h, 'padding:0' ) !== false && strpos( $h, 'margin:2px' ) !== false );
$h = DXO_Inliner::inline( '<table><tr><td class="x">a</td></tr></table><style>.x{color:red}</style>' );
check( 'un <style> suelto en el cuerpo (sin head)', strpos( $h, '<td class="x" style="color:red">' ) !== false );

echo "Correos de código\n";
$brand = [ 'company' => 'Dox & Co', 'address' => 'Riviera Beach, FL', 'accent' => '#ff8d27', 'why' => 'Te suscribiste.' ];
$mk = function ( $html, array $o = [] ) use ( $brand ) {
	return DXO_Renderer::render_email( array_merge( [
		'format'          => 'html',
		'html'            => $html,
		'brand'           => $brand,
		'subject'         => 'Hola {first_name}',
		'preheader'       => 'Mira esto {first_name|amigo}',
		'fields'          => [ 'first_name' => 'Ana', 'email' => 'ana@a.com' ],
		'unsubscribe_url' => 'https://web.com/?dxo=unsubscribe&t=1',
		'view_url'        => 'https://web.com/?dxo=view&t=1',
		'pixel_url'       => 'https://web.com/?dxo=open&t=1',
		'link'            => function ( $u ) { return 'https://web.com/?dxo=click&l=' . substr( md5( $u ), 0, 6 ); },
	], $o ) );
};
$doc = '<!doctype html><html><head><style>.t{color:#111}</style></head><body style="margin:0"><table><tr><td class="t">Hola {first_name}, <a href="https://a.com/x">mira</a> <a href="mailto:a@a.com">escribe</a> <a href="tel:+1555">llama</a> <a href="#arriba">arriba</a></td></tr></table></body></html>';
$r = $mk( $doc );
check( 'preheader justo después de <body>', preg_match( '#<body style="margin:0"><div style="display:none[^>]*>Mira esto Ana#', $r['html'] ) === 1 );
check( 'pixel justo antes de </body>', preg_match( '#<img src="https://web\.com/\?dxo=open&amp;t=1"[^>]*></body>#', $r['html'] ) === 1 );
check( 'campos rellenos', strpos( $r['html'], 'Hola Ana, ' ) !== false );
check( 'sin {unsubscribe_url}: el pie de Orbit al final con la baja', strpos( $r['html'], 'href="https://web.com/?dxo=unsubscribe&amp;t=1"' ) !== false && strpos( $r['html'], 'Riviera Beach, FL' ) !== false && strpos( $r['html'], 'Te suscribiste.' ) !== false );
check( '...y el pie va antes del píxel', strpos( $r['html'], 'Riviera Beach' ) < strpos( $r['html'], 'dxo=open' ) );
check( 'solo un </body>', substr_count( $r['html'], '</body>' ) === 1 );
check( 'enlace http con seguimiento', strpos( $r['html'], 'href="https://web.com/?dxo=click&amp;l=' ) !== false && substr_count( $r['html'], 'dxo=click' ) === 1 );
check( 'mailto, tel y # sin seguimiento', strpos( $r['html'], 'href="mailto:a@a.com"' ) !== false && strpos( $r['html'], 'href="tel:+1555"' ) !== false && strpos( $r['html'], 'href="#arriba"' ) !== false );
check( 'la baja y "ver en el navegador" no se siguen', substr_count( $r['html'], 'dxo=unsubscribe' ) === 1 && strpos( $r['html'], 'href="https://web.com/?dxo=view&amp;t=1"' ) !== false );
check( 'el CSS de <style> pasó a línea', strpos( $r['html'], 'class="t" style="color:#111"' ) !== false );
check( 'texto: enlace como "texto (url)", sin preheader ni etiquetas', strpos( $r['text'], 'mira (https://web.com/?dxo=click' ) !== false && strpos( $r['text'], 'Mira esto' ) === false && strpos( $r['text'], '<' ) === false && strpos( $r['text'], 'Hola Ana,' ) === 0 );

$own = '<html><body><p>Hola</p><p><a href="{unsubscribe_url}">Darme de baja</a> &middot; <a href="{view_in_browser_url}">Ver en el navegador</a></p></body></html>';
$r = $mk( $own );
check( 'con {unsubscribe_url}: se rellena y NO se añade el pie', strpos( $r['html'], 'href="https://web.com/?dxo=unsubscribe&amp;t=1">Darme de baja' ) !== false && substr_count( $r['html'], 'dxo=unsubscribe' ) === 1 && strpos( $r['html'], 'Riviera Beach' ) === false );
check( '{view_in_browser_url} se rellena', strpos( $r['html'], 'href="https://web.com/?dxo=view&amp;t=1">Ver en el navegador' ) !== false );
check( 'el pixel sigue estando', strpos( $r['html'], 'dxo=open' ) !== false );
$r = $mk( $own, [ 'view_url' => '', 'pixel_url' => null, 'link' => null ] );
check( 'prueba (sin ver-en-navegador): queda #', strpos( $r['html'], 'href="#">Ver en el navegador' ) !== false && strpos( $r['html'], 'dxo=open' ) === false );
$only_comment = $mk( '<html><body><p>Hola</p><!--[if mso]><a href="{unsubscribe_url}">baja</a><![endif]--></body></html>' );
check( '{unsubscribe_url} solo dentro de un comentario no cuenta: pie añadido', strpos( $only_comment['html'], 'Riviera Beach' ) !== false );
$only_text = $mk( '<html><body><p>Tu baja: {unsubscribe_url}</p></body></html>' );
check( '{unsubscribe_url} solo como texto (sin enlace) no cuenta: pie añadido', strpos( $only_text['html'], 'Riviera Beach' ) !== false && strpos( $only_text['html'], 'Tu baja: https://web.com/?dxo=unsubscribe&amp;t=1' ) !== false );

$r = $mk( '<table><tr><td>Solo un trozo <b>sin</b> html</td></tr></table>' );
check( 'un trozo se envuelve en un documento mínimo', preg_match( '#^<!doctype html>\s*<html>.*<head>.*<meta charset="utf-8">.*<meta name="viewport".*</head>\s*<body[^>]*>.*Solo un trozo.*</body>\s*</html>$#is', $r['html'] ) === 1 );
check( '...y con el pie, el preheader y el píxel como cualquier otro', strpos( $r['html'], 'Mira esto Ana' ) !== false && strpos( $r['html'], 'Riviera Beach' ) !== false && strpos( $r['html'], 'dxo=open' ) !== false );
$r = $mk( '<p>Hola {first_name|amigo}</p>', [ 'fields' => [] ] );
check( 'respaldo del campo', strpos( $r['html'], 'Hola amigo' ) !== false );
$r = $mk( '<p>Hola {first_name}</p><script>alert(1)</script><img src="x" onerror="y()">', [ 'fields' => [ 'first_name' => '<b>Ana</b>' ] ] );
check( 'el valor de un campo se escapa y el script no sale', strpos( $r['html'], '&lt;b&gt;Ana&lt;/b&gt;' ) !== false && stripos( $r['html'], '<script' ) === false && stripos( $r['html'], 'onerror' ) === false );
$r = $mk( '<p>Hola</p>', [ 'preheader' => '' ] );
check( 'sin preheader no se pone el div', strpos( $r['html'], 'display:none' ) === false );

echo "Texto plano del HTML\n";
$t = DXO_Html_Email::to_text( '<html><head><title>x</title><style>p{}</style></head><body><h1>Gran <a href="https://a.com/h">título</a> &amp; más</h1><p>Un <a href="https://a.com/x">enlace</a> y <a href="https://b.com">https://b.com</a></p><ul><li>uno</li><li>dos</li></ul><p><a href="#a">ancla</a><br>fin</p></body></html>' );
check( 'títulos en mayúsculas', strpos( $t, 'GRAN TÍTULO (https://a.com/h) & MÁS' ) !== false );
check( 'enlaces "texto (url)" y url sola si coincide', strpos( $t, 'Un enlace (https://a.com/x) y https://b.com' ) !== false );
check( 'listas con guion', strpos( $t, "- uno\n- dos" ) !== false );
check( 'ancla sin url, salto de línea', strpos( $t, "ancla\nfin" ) !== false && strpos( $t, 'ancla (#a)' ) === false );
check( 'sin etiquetas ni título ni estilos', strpos( $t, '<' ) === false && strpos( $t, 'p{}' ) === false && strpos( $t, 'x' ) !== 0 );

echo "Bloque HTML\n";
$nb = DXO_Renderer::normalize_blocks( [ [ 'type' => 'html', 'html' => '<p onclick="x()">Hola</p><script>alert(1)</script>' ] ] );
check( 'el bloque se limpia al guardar', $nb[0]['type'] === 'html' && $nb[0]['html'] === '<p>Hola</p>' );
$r = DXO_Renderer::render_email( [
	'blocks'          => DXO_Renderer::normalize_blocks( [
		[ 'type' => 'heading', 'text' => 'Título', 'size' => 'large' ],
		[ 'type' => 'html', 'html' => '<style>.b{color:red;margin:0}@media (max-width:600px){.b{color:blue!important}}</style><p class="b">Hola {first_name} <a href="https://a.com/z">z</a> <a href="{unsubscribe_url}">baja</a></p>' ],
	] ),
	'brand'           => $brand,
	'subject'         => 's',
	'fields'          => [ 'first_name' => 'Ana' ],
	'unsubscribe_url' => 'https://web.com/?dxo=unsubscribe&t=1',
	'link'            => function ( $u ) { return 'https://web.com/?dxo=click&l=1'; },
] );
check( 'dentro del diseño de Orbit (logo/pie/baja)', strpos( $r['html'], 'Dox &amp; Co' ) !== false && strpos( $r['html'], 'Riviera Beach' ) !== false );
check( 'campos y CSS en línea', strpos( $r['html'], '<p class="b" style="margin:0">Hola Ana' ) !== false );
check( 'el @media del bloque sube al <head>', preg_match( '#<head>.*@media \(max-width:600px\)\{\.b\{color:blue!important\}\}.*</head>#s', $r['html'] ) === 1 );
check( 'enlaces con seguimiento; {unsubscribe_url} del bloque relleno y sin seguir', strpos( $r['html'], 'dxo=click&amp;l=1' ) !== false && substr_count( $r['html'], 'href="https://web.com/?dxo=unsubscribe&amp;t=1"' ) === 2 );
check( 'campos desconocidos: {unsubscribe_url} no lo es', DXO_Renderer::unknown_tags( '{unsubscribe_url} {view_in_browser_url} {nope}' ) === [ '{nope}' ] );

echo "Avisos del editor\n";
$w = DXO_Html_Email::warnings( '<html><body style="x"><div style="display:flex;position:absolute"><img src="/a.png"><img src="http://a.com/b.png" alt="b"><img src="https://a.com/c.png" alt=""></div><script>x</script><link rel="stylesheet" href="https://x.com/f.css"></body></html>' );
$codes = array_column( $w, 'code' );
check( 'imagen relativa, http, sin alt', in_array( 'img_relative', $codes, true ) && in_array( 'img_http', $codes, true ) && in_array( 'img_alt', $codes, true ) );
check( 'flex y position', in_array( 'css', $codes, true ) && array_values( array_filter( $w, function ( $x ) { return $x['code'] === 'css'; } ) )[0]['items'] === [ 'flex / grid', 'position' ] );
check( 'fuente externa', in_array( 'fonts', $codes, true ) );
check( 'lo quitado (script, link)', in_array( 'removed', $codes, true ) );
$big = DXO_Html_Email::warnings( '<html><body>' . str_repeat( '<p>relleno relleno relleno relleno</p>', 4000 ) . '</body></html>' );
check( 'tamaño de más de 102 KB', in_array( 'size', array_column( $big, 'code' ), true ) );
check( 'un correo limpio no avisa de nada', DXO_Html_Email::warnings( '<html><body><table><tr><td><img src="https://a.com/a.png" alt="a" width="10"></td></tr></table></body></html>' ) === [] );


// ═══ Revisión 0.5.0: seguridad, rendimiento y robustez ═════════════════════

echo "Escondite de {campos} (nadie puede forzar una restauración)\n";
$evil = '<img src=x onerror=alert(1)>';
$payload = '<p>Hola dxotag' . bin2hex( $evil ) . 'x dxo0123456789abcdefn0z</p><a href="dxotag' . bin2hex( 'javascript:alert(1)' ) . 'x">c</a><div style="dxotag' . bin2hex( '"><script>alert(2)</script>' ) . 'x">z</div>';
$outs = [ DXO_Sanitizer::fragment( $payload ), DXO_Sanitizer::document( '<html><body>' . $payload . '</body></html>' ), DXO_Html_Email::prepare( $payload ), $mk( $payload )['html'] ];
$ok = true;
foreach ( $outs as $o ) { if ( stripos( $o, '<img src=x' ) !== false || stripos( $o, '<script' ) !== false || strpos( $o, 'javascript:' ) !== false || stripos( $o, 'onerror=' ) !== false ) $ok = false; }
check( 'un "dxotag<hex>x" escrito a mano sale como texto, no como HTML', $ok );
$tok = DXO_Sanitizer::fragment( '<a href="https://a.com/?e={email}&n={first_name|amigo}">x</a><p>{last_name}</p>' );
check( 'los {campos} de verdad siguen sobreviviendo', strpos( $tok, 'e={email}&amp;n={first_name|amigo}' ) !== false && strpos( $tok, '<p>{last_name}</p>' ) !== false );
check( 'un respaldo con comillas o < no se esconde: no puede colar etiquetas por el escondite', strpos( DXO_Sanitizer::fragment( '<a title="{first_name|a&quot;><b>}">x</a>' ), '<b>' ) === false );

echo "meta http-equiv\n";
foreach ( [
	'<meta charset="utf-8" http-equiv="refresh" content="0;url=https://evil.test">',
	'<meta name="viewport" http-equiv="refresh" content="0;url=https://evil.test">',
	'<meta name="description" http-equiv="set-cookie" content="a=b">',
	'<meta HTTP-EQUIV="Refresh" content="0;url=https://evil.test">',
] as $m ) {
	$o = DXO_Sanitizer::document( '<html><head>' . $m . '</head><body><p>x</p></body></html>' );
	check( 'sin refresh/set-cookie: ' . substr( $m, 0, 50 ), stripos( $o, 'refresh' ) === false && stripos( $o, 'set-cookie' ) === false );
}
check( 'x-ua-compatible sí se queda', strpos( DXO_Sanitizer::document( '<html><head><meta http-equiv="X-UA-Compatible" content="IE=edge"></head><body></body></html>' ), 'http-equiv="X-UA-Compatible"' ) !== false );

echo "CSS: comentarios y mXSS\n";
foreach ( [ '</sty/**/le>', '</sty/**//**/le>', '<</**/!--/**/style>', '</style >', "</sty\0le>" ] as $v ) {
	$css = DXO_Sanitizer::clean_css( 'p{color:red}' . $v . '<img src=x onerror=alert(1)>' );
	check( 'clean_css no deja "<": ' . json_encode( $v ), strpos( $css, '<' ) === false );
}
$o = DXO_Sanitizer::document( '<html><head><style>p{color:red}</sty/**/le><img src=x onerror=alert(1)></style></head><body><p style="color:red</sty/**/le>">x</p></body></html>' );
check( 'en el documento no sale ninguna etiqueta nueva del CSS', ! preg_match( '/<img[^>]*onerror/i', $o ) );
check( 'el ">" de un selector hijo en <style> sigue funcionando', strpos( DXO_Sanitizer::clean_css( 'div > p{color:red}' ), 'div > p' ) !== false );
$cc = DXO_Sanitizer::clean_css( 'a{width:expression(1)}b{behavior:url(x.htc)}c{-moz-binding:url(x)}d{background:url(javascript:x)}e{color:red}' );
check( 'expression, behavior, -moz-binding, javascript: fuera', ! preg_match( '/expression|behavior|binding|javascript/i', $cc ) && strpos( $cc, 'e{color:red}' ) !== false );

echo "@import y link solo de Google Fonts\n";
check( '@import de Google Fonts se queda', strpos( DXO_Sanitizer::clean_css( '@import url("https://fonts.googleapis.com/css2?family=Inter");' ), 'fonts.googleapis.com' ) !== false );
check( '@import ajeno, con dominio parecido o con basura detrás, fuera', DXO_Sanitizer::clean_css( '@import url(https://evil.test/x.css);@import url(https://fonts.googleapis.com.evil.test/x);@import url(https://fonts.googleapis.com/a) ; url(https://evil.test);p{color:red}' ) === 'p{color:red}' || strpos( DXO_Sanitizer::clean_css( '@import url(https://evil.test/x.css);@import url(https://fonts.googleapis.com.evil.test/x);' ), 'evil' ) === false );
check( '<link> solo de fonts.googleapis.com', strpos( DXO_Sanitizer::document( '<html><head><link rel="stylesheet" href="https://fonts.googleapis.com.evil.test/x"></head><body></body></html>' ), 'evil' ) === false );

echo "Comentarios condicionales\n";
$o = DXO_Sanitizer::document( '<html><body><p>x</p><!--[if mso]> --!> <img src=x onerror=alert(1) <![endif]--></body></html>' );
check( 'un "--!>" dentro de un condicional no deja pasar nada vivo', stripos( $o, 'onerror' ) === false );
foreach ( [
	'<!--[if mso]><script>alert(1)</script><![endif]-->',
	'<!--[if mso]><p onclick="x()">a</p><![endif]-->',
	'<!--[if mso]><a href="javascript:alert(1)">a</a><![endif]-->',
	'<!--[if mso]><img src="data:text/html;base64,AA"><![endif]-->',
	'<!--[if mso]>&#60;script&#62;<![endif]-->',
] as $c ) {
	$o = DXO_Sanitizer::document( '<html><body><p>x</p>' . $c . '</body></html>' );
	check( 'se quita: ' . substr( $c, 0, 48 ), strpos( $o, '[if mso]' ) === false && strpos( $o, '<p>x</p>' ) !== false );
}
$vml = '<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" href="https://a.com" style="height:40px;width:200px" arcsize="10%" fillcolor="#000000" stroke="f"><w:anchorlock/><center style="color:#fff">Ir&nbsp;ya</center></v:roundrect><![endif]-->';
check( 'el botón VML de Outlook se queda tal cual', strpos( DXO_Sanitizer::document( '<html><body>' . $vml . '</body></html>' ), $vml ) !== false );
$mso_style = '<!--[if mso]><style>td{font-family:Arial,sans-serif}</style><table role="presentation"><tr><td width="600"><![endif]-->';
check( 'estilos y tablas dentro de un condicional se quedan', strpos( DXO_Sanitizer::document( '<html><body>' . $mso_style . '<p>x</p></body></html>' ), $mso_style ) !== false );

echo "Rendimiento y límites\n";
$css = ''; $rows = '';
for ( $i = 0; $i < 400; $i++ ) { $css .= ".c$i td p span.x$i{color:#abc;margin:0 auto}\n.r$i{padding:4px}\n"; }
for ( $i = 0; $i < 1000; $i++ ) { $rows .= '<tr class="r' . ( $i % 400 ) . '"><td><p class="c' . ( $i % 400 ) . '">Hola <span class="x' . ( $i % 400 ) . '">texto ' . $i . '</span> <a href="https://x.test/p/' . $i . '">link</a></p></td></tr>'; }
$t0 = microtime( true );
$big = DXO_Html_Email::prepare( '<!doctype html><html><head><style>' . $css . '</style></head><body><table>' . $rows . '</table></body></html>' );
check( '800 reglas y 1000 filas se preparan en menos de 2 s', microtime( true ) - $t0 < 2 && strpos( $big, 'padding:4px' ) !== false );
$t0 = microtime( true ); DXO_Html_Email::prepare( '<!doctype html><html><head><style>' . $css . '</style></head><body><table>' . $rows . '</table></body></html>' );
check( 'la segunda vez sale de la memoria', microtime( true ) - $t0 < 0.1 );
$many = ''; for ( $i = 0; $i < 2100; $i++ ) $many .= ".m$i{color:red}";
$w = DXO_Html_Email::warnings( '<html><head><style>' . $many . '</style></head><body><p class="m1">x</p></body></html>' );
check( 'más de 2000 reglas: se avisa y las que sobran se quedan en el <style>', in_array( 'rules', array_column( $w, 'code' ), true ) );
// El transient guarda lo preparado (con WordPress): se simula.
$GLOBALS['fake_t'] = [];
function get_transient( $k ) { return $GLOBALS['fake_t'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['fake_t'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['fake_t'][ $k ] ); return true; }
$uniq = '<p>transient ' . uniqid() . '</p>';
DXO_Html_Email::prepare( $uniq );
check( 'lo preparado se guarda en un transient', count( $GLOBALS['fake_t'] ) === 1 );
DXO_Html_Email::forget( $uniq );
check( 'forget() lo borra', count( $GLOBALS['fake_t'] ) === 0 );

echo "Baja propia de verdad\n";
$own_of = function ( $h ) { return DXO_Html_Email::own_unsubscribe( $h ); };
check( 'enlace normal con texto', $own_of( '<p><a href="{unsubscribe_url}">Baja</a></p>' ) === true );
check( 'dentro de un div display:none no cuenta', $own_of( '<div style="display:none"><a href="{unsubscribe_url}">u</a></div>' ) === false );
check( 'escondido con una clase (CSS en línea) no cuenta', $own_of( '<style>.h{display:none}</style><div class="h"><a href="{unsubscribe_url}">u</a></div>' ) === false );
foreach ( [ 'visibility:hidden', 'font-size:0', 'opacity:0', 'max-height:0', 'mso-hide:all' ] as $hid ) {
	check( "oculto con $hid no cuenta", $own_of( '<span style="' . $hid . '"><a href="{unsubscribe_url}">u</a></span>' ) === false );
}
check( 'como parámetro de otra URL no cuenta', $own_of( '<a href="https://evil.test/?r={unsubscribe_url}">fwd</a>' ) === false );
check( 'en un mailto no cuenta', $own_of( '<a href="mailto:a@b.c?body={unsubscribe_url}">fwd</a>' ) === false );
check( 'enlace vacío no cuenta', $own_of( '<a href="{unsubscribe_url}"></a>' ) === false );
check( 'una imagen de 1x1 no cuenta', $own_of( '<a href="{unsubscribe_url}"><img src="https://x/a.png" width="1" height="1" alt=""></a>' ) === false );
check( 'una imagen normal sí', $own_of( '<a href="{unsubscribe_url}"><img src="https://x/a.png" width="120" height="30" alt="baja"></a>' ) === true );
check( 'sin baja propia el pie se añade', strpos( $mk( '<div style="display:none"><a href="{unsubscribe_url}">u</a></div>' )['html'], 'Riviera Beach' ) !== false );


echo "Baja: forma exacta, ocultos y persistencia\n";
$r = $mk( '<p><a href="{unsubscribe_url|https://evil.test}">Baja</a></p>' );
check( 'M1: {unsubscribe_url|respaldo} no cuenta como baja propia: se añade el pie y no queda un enlace muerto', $own_of( '<a href="{unsubscribe_url|https://evil.test}">Baja</a>' ) === false && strpos( $r['html'], 'Riviera Beach' ) !== false && strpos( $r['html'], 'evil.test' ) === false );
check( 'M1: el href exacto (con espacios alrededor) sí', $own_of( '<a href=" {unsubscribe_url} ">Baja</a>' ) === true );
check( 'M1: {unsubscribe_url}/algo tampoco', $own_of( '<a href="{unsubscribe_url}/x">Baja</a>' ) === false );
check( 'M1: ningún href con respaldo sobrevive en el limpiador', strpos( DXO_Sanitizer::fragment( '<a href="{view_in_browser_url|https://evil.test}">v</a>' ), 'href' ) === false );
foreach ( [
	'line-height:0', 'height:0;overflow:hidden', 'text-indent:-9999px', 'font-size:2px', 'font-size:5px',
	'color:#ffffff;background:#fff', 'color:#FFF;background-color:#ffffff',
] as $hid ) {
	check( "B1: oculto con $hid", $own_of( '<div style="' . $hid . '"><a href="{unsubscribe_url}">u</a></div>' ) === false );
}
check( 'B1: color igual al fondo del ancestro (bgcolor)', $own_of( '<table><tr><td bgcolor="#112233"><a href="{unsubscribe_url}" style="color:#124"><font>u</font></a></td></tr></table>' ) === true && $own_of( '<table><tr><td bgcolor="#112233"><a href="{unsubscribe_url}" style="color:#112233">u</a></td></tr></table>' ) === false );
check( 'B1: un enlace normal (14px, colores distintos) sí cuenta', $own_of( '<div style="font-size:14px;line-height:20px;color:#333;background:#fff"><a href="{unsubscribe_url}" style="color:#888">u</a></div>' ) === true );
check( 'B1: height:0 sin overflow:hidden no esconde', $own_of( '<div style="height:0"><a href="{unsubscribe_url}">u</a></div>' ) === true );

$GLOBALS['fake_t'] = [];
$pv = '<p>vista previa ' . uniqid() . '</p>';
$mk( $pv, [ 'preview' => true ] );
DXO_Html_Email::own_unsubscribe( $pv );
DXO_Html_Email::warnings( $pv );
check( 'M2: la vista previa (render preview, avisos, comprobación de baja) no guarda nada en la base', count( $GLOBALS['fake_t'] ) === 0 );
$mk( $pv );
check( 'M2: el envío, la prueba y "ver en el navegador" (render normal) sí lo guardan, aunque la vista previa ya lo tuviera en memoria', count( $GLOBALS['fake_t'] ) === 1 );

echo "Huecos de preheader, pie y píxel\n";
$tricky = '<html><body><p>x</p><!--[if mso]></td></tr></table><![endif]--><p>fin</p><!--[if mso]></td></tr></table><![endif]--></body></html>';
$r = $mk( $tricky );
$cpos = strrpos( $r['html'], '<![endif]-->' );
check( 'el pie y el píxel no caen dentro de un comentario', $cpos !== false && strpos( $r['html'], 'Riviera Beach' ) > $cpos && strpos( $r['html'], 'dxo=open' ) > $cpos );
check( 'el preheader es lo primero del body', preg_match( '#<body[^>]*><div style="display:none#', $r['html'] ) === 1 );
check( 'los huecos no quedan en el correo', strpos( $r['html'], 'dxo-start' ) === false && strpos( $r['html'], 'dxo-end' ) === false );
$r = $mk( '<html><body><p>x</p></body></html>', [ 'preheader' => '', 'pixel_url' => null ] );
check( 'sin preheader ni píxel tampoco', strpos( $r['html'], 'dxo-' ) === false || strpos( $r['html'], 'dxo-start' ) === false );

echo "<style media>\n";
$h = DXO_Inliner::inline( '<html><head><style media="print">p{color:red}</style><style media="(max-width:600px)">p{margin:9px}</style><style>p{font-size:11px}</style></head><body><p>x</p></body></html>' );
check( 'las hojas de print o de otro medio no se pasan a línea', strpos( $h, 'color:red' ) !== false && strpos( $h, '<p style="font-size:11px">' ) !== false && strpos( $h, 'margin:9px' ) !== false && strpos( $h, '<p style="margin' ) === false );

echo "Acentos y charset\n";
foreach ( [
	'<html><head><meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1"></head><body><p>Año ñandú é</p></body></html>',
	'<html><head><meta charset="windows-1252"></head><body><p>Año ñandú é</p></body></html>',
	"\xEF\xBB\xBF<!DOCTYPE html><html><head><title>t</title></head><body><p>Año ñandú é</p></body></html>",
] as $h ) {
	$o = DXO_Html_Email::prepare( $h );
	check( 'acentos intactos y un solo meta charset utf-8', strpos( $o, 'Año ñandú é' ) !== false && substr_count( strtolower( $o ), 'charset' ) === 1 && strpos( $o, '<meta charset="utf-8">' ) !== false );
}

echo "Bloque HTML: trozos con cierres sueltos\n";
check( 'un </div> suelto no corta lo que sigue', DXO_Sanitizer::fragment( '<p>a</p></div><p>b</p><div>c' ) === '<p>a</p><p>b</p><div>c</div>' );
check( 'tras un </div> suelto lo peligroso sigue limpio', strpos( DXO_Sanitizer::fragment( '<p>a</p></div><img src=x onerror=alert(1)><p>b</p>' ), 'onerror' ) === false );
$b64 = DXO_Renderer::normalize_blocks( [ [ 'type' => 'html', 'html_b64' => base64_encode( '<p onclick="x()">Año</p><script>1</script>' ) ] ] );
check( 'html_b64 se decodifica y se limpia', $b64[0]['html'] === '<p>Año</p>' );
check( 'html_b64 roto da un bloque vacío', DXO_Renderer::normalize_blocks( [ [ 'type' => 'html', 'html_b64' => '%%%' ] ] )[0]['html'] === '' );

echo "Campos en CSS, asunto y texto\n";
$r = $mk( '<style>p{font-family:{first_name};background:url({email})}</style><p style="color:{first_name};margin:0">x {first_name}</p>', [ 'fields' => [ 'first_name' => 'x;}</style><script>alert(1)</script>', 'email' => 'a@b.c' ] ] );
check( 'el nombre de una persona no abre un <script>', stripos( $r['html'], '<script' ) === false );
check( '...ni en <style> ni en style=""', strpos( $r['html'], 'font-family:x' ) === false && strpos( $r['html'], 'color:x' ) === false && strpos( $r['html'], 'a@b.c' ) === false && strpos( $r['html'], 'margin:0' ) !== false );
check( '{unsubscribe_url} en asunto o preheader se avisa', DXO_Renderer::link_tags_in( 'Hola {unsubscribe_url}', 'x {view_in_browser_url|y}' ) === [ '{unsubscribe_url}', '{view_in_browser_url}' ] );
$r = $mk( '<html><body><p>Hola</p><center>despedida sin salto</center></body></html>' );
check( 'texto plano: el pie empieza en su línea', preg_match( "/despedida sin salto\n+Te suscribiste/", $r['text'] ) === 1 );
check( 'sin DOM no hay fatal (available() existe y es true aquí)', DXO_Sanitizer::available() === true );


echo "WebP / AVIF\n";
$w = DXO_Html_Email::warnings( '<html><body><img src="https://a.com/a.webp?v=2" alt="a"><img src="https://a.com/b.AVIF" alt="b"><img src="https://a.com/c.png" alt="c"></body></html>' );
$wp = array_values( array_filter( $w, function ( $x ) { return $x['code'] === 'img_webp'; } ) );
check( 'código HTML: avisa de .webp y .avif (2) y no de .png', count( $wp ) === 1 && $wp[0]['n'] === 2 );
check( 'bloques: imagen, entrada del blog y <img> de un bloque HTML', DXO_Html_Email::modern_images_in_blocks( [
	[ 'type' => 'image', 'url' => 'https://a.com/a.webp' ], [ 'type' => 'image', 'url' => 'https://a.com/b.jpg' ],
	[ 'type' => 'post', 'image' => 'https://a.com/p.avif?x=1' ], [ 'type' => 'html', 'html' => '<p><img src="https://a.com/h.webp" alt=""></p>' ],
] ) === 3 );

echo "Idioma de los textos fijos\n";
require_once __DIR__ . '/../includes/class-dxo-campaigns.php';
function pll_languages_list( $a = [] ) { $f = $a['fields'] ?? 'slug'; return [ 'slug' => [ 'es', 'en' ], 'name' => [ 'Español', 'English' ], 'locale' => [ 'es_CO', 'en_US' ] ][ $f ]; }
function pll_default_language() { return 'es'; }
function get_option( $k, $d = false ) { return $GLOBALS['fake_opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['fake_opt'][ $k ] = $v; return true; }
check( 'sin idioma y con Polylang: el idioma por defecto del sitio público', DXO_Campaigns::render_lang( [ 'audience' => [ 'lists' => [] ] ] ) === 'es' );
check( 'con idioma propio: ese', DXO_Campaigns::render_lang( [ 'audience' => [ 'lang' => 'en' ] ] ) === 'en' );
check( 'y su locale sale de la lista de Polylang', DXO_Campaigns::languages()['es']['locale'] === 'es_CO' );

echo "\n";
echo $fails ? "\n$fails fallos\n" : "\nTodo bien\n";
exit( $fails ? 1 : 0 );
