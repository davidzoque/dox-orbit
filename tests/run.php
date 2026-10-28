<?php
/**
 * Pruebas del renderizador y del lector de rebotes, sin WordPress: php tests/run.php
 * No viajan en el zip (el workflow excluye tests/).
 */
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../includes/class-dxo-renderer.php';
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
echo "\n";
echo $fails ? "\n$fails fallos\n" : "\nTodo bien\n";
exit( $fails ? 1 : 0 );
