# Dox Orbit

Email marketing desde el propio WordPress, "tipo Mailchimp pero local" y
orientado a tiendas WooCommerce (como Klaviyo). Hasta la 0.1.1 se llamó Dox
Newsletter (prefijo `dxn`); desde la 0.2.0 todo es `dxo` y el shortcode es
`[dox_orbit]`. Lo pidió David el 04/10/2026 como plugin propio, igual que hizo Dox Mail
en Perfex, y con una interfaz al estilo de pixfort. Gran parte de la lógica viene
de Dox Mail (`Projects/Perfex CRM/Perfex Modules/dox_mail`): el renderizador del
correo, el envío por tandas, el seguimiento y el informe.

## Qué hay en cada archivo

| Archivo | Qué hace |
|---|---|
| `dox-orbit.php` | Arranque: constantes, dox-core, carga de clases, Plugin Update Checker |
| `includes/class-dxo-install.php` | Las 7 tablas `wp_dxo_*` con dbDelta. Se ponen al día solas al cambiar `DB_VERSION` (ahora `3`) |
| `includes/class-dxo-settings.php` | Ajustes (opción `dxo_settings`), marca del correo, cifrado de la contraseña de SES y utilidades de fecha y número |
| `includes/class-dxo-renderer.php` | De los bloques al HTML del correo. PHP puro: lo prueba `tests/run.php` sin WordPress |
| `includes/class-dxo-sanitizer.php` | Limpia el HTML de un correo con DOMDocument (lista de lo permitido). PHP puro: lo prueba `tests/run.php` |
| `includes/class-dxo-inliner.php` | Pasa el CSS de los `<style>` a cada elemento. PHP puro, igual |
| `includes/class-dxo-html-email.php` | Correos de código: preparar (limpiar + CSS en línea, una vez por campaña), montar el de cada persona, texto plano y avisos del editor |
| `includes/class-dxo-subscribers.php` | Suscriptores y listas: alta con doble confirmación, baja, interés, importar CSV |
| `includes/class-dxo-campaigns.php` | Campañas: guardar, lanzar o programar, audiencia, reenviar, bienvenida, números del informe |
| `includes/class-dxo-mailer.php` | Envía por `wp_mail` o por Amazon SES (PHPMailer propio) |
| `includes/class-dxo-sender.php` | La cola: tandas por WP-Cron con tope por hora y candado |
| `includes/class-dxo-bounces.php` | Rebotes: la pasada horaria que lee el buzón y aplica cada aviso (suscriptor, envío, campaña) |
| `includes/class-dxo-bounce-parser.php` | Lee un rebote (DSN estándar y avisos de texto). PHP puro, sin WordPress: lo prueba `tests/run.php` |
| `includes/class-dxo-imap.php` | Cliente IMAP mínimo sobre `stream_socket_client`, sin la extensión imap |
| `includes/class-dxo-public.php` | Lo que llega de fuera: suscribirse, confirmar, baja, pixel, clic, ver en el navegador |
| `includes/class-dxo-forms.php` | Formularios (opción `dxo_forms`): shortcode, ventana emergente y barra |
| `includes/class-dxo-stats.php` | Los números del Resumen |
| `includes/class-dxo-admin.php` | El panel: menú, recursos, iconos, gráfica y todas las acciones AJAX `dxo_*` |
| `includes/class-dxo-elementor.php` | Widget de Elementor (pinta el shortcode) |
| `admin/views/*.php` | Las pantallas: Resumen, Campañas, Editor, Suscriptores, Formularios, Informe, Ajustes |
| `assets/admin.*` | CSS y JS del panel |
| `assets/public.*` | CSS y JS de los formularios en la web |
| `tools/i18n.py`, `tools/es.py` | Saca los textos y genera el `.pot`, `.po` y `.mo` en español. No viaja en el zip |
| `tests/run.php` | Pruebas del renderizador y del lector de rebotes. No viaja en el zip; el workflow las corre antes de cada release |
| `maqueta/panel.html` | La maqueta que aprobó David el 04/10/2026. No viaja en el zip |

## Cómo sale un correo

1. **Lanzar** congela la audiencia en `wp_dxo_recipients` (una fila por persona,
   cada una con su token) y registra los enlaces del correo en `wp_dxo_links`.
2. **Cada minuto** (WP-Cron, hook `dxo_send`) sale una tanda: como mucho 25
   correos y 20 segundos, y nunca más de lo que deje el **tope por hora** de
   Ajustes contando los enviados en los últimos 60 minutos (no por pasadas: aunque
   el cron se salte minutos, nunca salen más de los que tocan).
3. Antes de cada correo se mira que la persona siga activa: si se dio de baja
   entre medias, se salta.
4. **Con el panel abierto**, la franja de estado pregunta cada 20 segundos y de
   paso ejecuta una pasada (`ajax_status`). Así el envío no depende de que alguien
   visite la web para que corra WP-Cron.

**Por qué el tope:** en un hosting compartido el servidor deja salir un número de
correos por hora por dominio (en el de Dox Studio, 200). El boletín sale a 150 por
defecto para dejar sitio a pedidos, contraseñas y formularios. Y la IP del
servidor la comparten todas las webs: una lista sucia puede llevar el correo de
todas a spam, por eso la doble confirmación viene encendida.

**Si la web tiene un plugin de SMTP** (GoSMTP, WP Mail SMTP...), `wp_mail` sale
por él, y si ese plugin fuerza el remitente, manda el suyo sobre el de Ajustes.

## Lo que llega de fuera

Todo por la portada con `?dxo=<acción>&t=<token>`, no por la API REST: Hide My WP
puede cerrar `wp-json` a los visitantes. Se atiende en `init` y se marca como no
cacheable (`DONOTCACHEPAGE`, `litespeed_control_set_nocache`, `nocache_headers`).
Nunca dentro de wp-admin ni de admin-ajax (`is_admin()`).

- `subscribe` (POST): sin nonce a propósito, porque la página del formulario
  puede estar en la caché y el nonce caducaría. Lo frenan un campo trampa
  (`dxo_hp`), un mínimo de 2 segundos desde que se pinta el formulario y 5
  intentos cada 10 minutos por IP. Con `dxo_ajax=1` responde JSON; sin JS
  redirige a la página con `?dxo_s=<estado>&dxo_f=<formulario>`.
- `confirm`: pasa de `pending` a `active` y pone en cola la bienvenida si está encendida.
- `unsubscribe`: por GET enseña un botón (los antivirus del correo abren todos
  los enlaces solos y darían de baja a la gente); por POST da de baja. Gmail y
  Yahoo mandan un POST con `List-Unsubscribe=One-Click` (RFC 8058) sin cookies.
- `open`: el pixel. **Gmail y Mail del iPhone lo piden solos** al llegar el
  correo, así que la apertura es una cifra al alza; el informe lo dice.
- `click`: redirige a la URL guardada al lanzar la campaña, nunca a una que venga
  en la petición. Un clic sin apertura cuenta también como apertura.
- `view`: el correo de esa persona en el navegador, sin seguimiento.

## Trampas que ya conté

- **Un `<form>` dentro de otro** cierra el de fuera antes de tiempo. La vista
  previa del formulario en el panel va dentro del formulario de edición: por eso
  en modo `preview` se pinta con `<div>` y sin `name` en los campos. Con `name`,
  el campo oculto `dxo=subscribe` viajaba con cada guardado.
- **Los scripts del pie se imprimen en `wp_footer` con prioridad 20.** La ventana
  emergente y la barra se pintan en `wp_footer` con prioridad 5; a la 20, el JS
  se encolaba tarde y la ventana no salía nunca.
- **`.dxo-app svg { width: 16px }`** es para los iconos: la gráfica necesita su
  regla con más peso (`.dxo-app svg.dxo-chart`).
- **Un `display: grid` gana al atributo `hidden`.** El panel lleva
  `.dxo-app [hidden] { display: none !important }`.
- **Las reglas de párrafo del tema (o de wp-admin) ganan a `.dxo-title`.** En
  `public.css` los textos del formulario van con dos clases (`.dxo-box .dxo-title`).
- **El editor redibuja los bloques** al abrir uno: el campo al que dar el foco
  está en el elemento nuevo, no en el que recibió el clic.
- **Las fechas de la gráfica van en HTML** debajo del SVG: el SVG se estira a lo
  ancho (`preserveAspectRatio="none"`) y el texto dentro salía deformado en el móvil.
- `__( '%s person' )` no encuentra la traducción si ese texto existe también como
  plural (`_n`): el `.mo` lo guarda junto a su plural. Para esos, siempre `_n()`.

## Bienvenida por idioma (0.3.0)

Con Polylang hay una bienvenida por idioma del sitio; el idioma va en
`audience.lang` (JSON de `list_ids`, sin cambiar tablas). Sin Polylang todo
sigue igual: una sola, sin idioma. `DXO_Campaigns::welcomes()` crea las que
faltan (apagadas, con el asunto y los bloques en ese idioma vía
`switch_to_locale`) y la bienvenida que ya existía pasa a ser la del idioma por
defecto. `welcome_for( $sub['lang'] )` elige: slug exacto, luego las dos primeras
letras (`es_CO` → `es`), luego el idioma por defecto, luego la primera; y solo
se encola si esa está encendida. Al construir el correo (`DXO_Sender::build`, que
también usan la prueba, el navegador y la vista previa) se cambia el idioma de
WordPress al de la campaña: así "Darse de baja" y "Ver en el navegador" salen en
su idioma. El ajuste `footer_why` es un texto libre en un solo idioma, así que
fuera del idioma por defecto se usa el texto del plugin traducido
(`DXO_Settings::default_why()`). El correo de confirmación (doble opt-in) sigue
saliendo en el idioma de Ajustes, un solo texto.

## Rebotes (0.4.0)

Hasta la 0.3.0 Orbit solo sabía "enviado" (el servidor lo aceptó), "falló" (lo
rechazó al momento) y aperturas o clics. Los rebotes que vuelven después, cuando
la dirección no existe, iban al remitente del sobre por defecto y nadie los leía,
así que `bounced` existía como estado pero nada lo ponía. 
**Lo recomendado:** un buzón solo para rebotes en el propio hosting (`rebotes@sudominio.com`, en cPanel
Cuentas de correo). Nunca un buzón que lea alguien (info@, ventas@), porque Orbit lo ordena. Sirve
cualquier buzón con IMAP y contraseña: los del hosting funcionan directo, Gmail necesita contraseña de
aplicación y Microsoft 365 / Outlook.com no lo permiten. Decidido el 28/10/2026: leer el buzón del
remitente se descartó, y el receptor sin configuración (estilo Mailchimp) queda para la versión Pro.

Ahora:

1. **Ajustes, pestaña Rebotes.** Un buzón solo para esto (`rebotes@sudominio.com`;
   en cPanel, Cuentas de correo), con su servidor IMAP, puerto (993), usuario
   (vacío = el correo) y contraseña. La contraseña se guarda cifrada igual que la
   de SES (`DXO_Settings::encrypt`), no vuelve a la pantalla y vacía = se queda la
   que había. "Probar conexión" (`ajax_bounce_test`) entra, cuenta los mensajes y
   enseña el texto del servidor si falla; usa lo que hay en pantalla aunque no esté guardado.
2. **Return-Path.** Con los rebotes encendidos y el correo válido, `DXO_Mailer::send`
   pone ese buzón de remitente del sobre (`$phpmailer->Sender`) solo con el transporte
   `wp`: con un `phpmailer_init` de prioridad 99 que solo vive mientras dura el envío
   de Orbit (`try/finally`; los correos de WooCommerce y el resto no se tocan). Con
   SES no se toca. `wp_mail()` reutiliza un solo PHPMailer y nunca vacía `Sender`: `DXO_Mailer`
   guarda `Sender`, `Mailer` y `Sendmail` en `phpmailer_init` (prioridad 0) y los devuelve en el
   `finally`. Con `mail()` o sendmail, PHPMailer solo pasa `-f` si la dirección es "segura para
   la shell" y el `+` no lo es: si `sendmail_path` es una ruta absoluta ejecutable (y no es
   Windows) se cambia a sendmail directo con `-f<VERP>` (`plan_return_path`); si no, se cae a la
   dirección sin token y queda la cabecera `X-Dox-Orbit`. Un plugin de SMTP que fuerce su propio `Sender` con más prioridad le gana.
   **Con Amazon SES** los rebotes los recibe Amazon: el buzón no los recibirá.
3. **La pasada** (`DXO_Bounces::run`, hook `dxo_bounces`, cada hora; se programa en
   `init` como la de envío y se quita al desactivar). Candado atómico con `INSERT IGNORE`
   (solo gana quien ve `rows_affected = 1`; `dxo_bounces_lock` caduca a los 10 minutos con un UPDATE
   condicional; `add_option` no vale porque mira antes con `get_option`). Entra por IMAP (`DXO_IMAP`), busca los no
   leídos (hasta 100 por pasada; si quedan más, otra a los 60 segundos con el hook
   `dxo_bounces_more`), baja los primeros 64 KB de cada uno con `BODY.PEEK`, los
   interpreta (`DXO_Bounce_Parser::parse`) y: lo reconocido se marca `\Deleted` y
   al final `EXPUNGE` (es un buzón dedicado); lo demás se marca `\Seen` y se deja.
   El resultado (hora, bien o error, leídos, encontrados) queda en la opción
   `dxo_bounce_status` y se enseña bajo la sección de Ajustes.
4. **IMAP sin extensión.** TLS directo en el 993 y STARTTLS en el resto (si el
   servidor no ofrece STARTTLS no se manda la contraseña), con el certificado
   verificado (`verify_peer` y `verify_peer_name`; si el sistema no tiene certificados raíz se usa el `ca-bundle.crt` de WordPress), 20 segundos de espera, comandos
   con etiqueta, literales `{n}` con tope de 2 MB y argumentos entre comillas
   escapadas (o como literal si traen algo que no cabe).
5. **El lector.** `parse()` devuelve `null` (no es un rebote) o `email`, `type`,
   `status`, `reason` y `orbit`. DSN estándar (RFC 3464): `Original-Recipient` antes
   que `Final-Recipient`, `Action: failed` es rebote (`delayed`, `relayed`,
   `delivered` se ignoran), `5.x.x` definitivo y `4.x.x` pasajero. Avisos de texto
   (Exim, Postfix, Gmail, Microsoft, Yahoo): se busca el código SMTP y la frase y se
   saca la dirección del formato de cada uno. `X-Dox-Orbit` se busca en cualquier
   parte del mensaje devuelto. Las respuestas automáticas (`Auto-Submitted`,
   `X-Autoreply`, "fuera de la oficina") no son rebotes, **salvo que las mande el
   servidor de correo**: Exim y Postfix marcan sus propios avisos con
   `Auto-Submitted: auto-replied`. Tipos: `hard` (no existe), `soft` (buzón lleno,
   4.x.x, 5.2.2 y 5.2.3/5.3.4) y `block` (5.7.x o frases de spam, reputación,
   DMARC...: dice algo de nosotros, no de la dirección; no se toca al suscriptor).
6. **VERP.** Con "Usar direcciones con más" (`bounce_verp`, encendido por defecto) y el
   transporte `wp`, el Return-Path de cada correo de campaña es
   `buzón+campaña-destinatario-firma@dominio` (con guiones, no puntos; `DXO_Settings::bounce_address( $token )`,
   con el token de su `X-Dox-Orbit`). Muchos NDR (Microsoft, Yahoo, Exim sin el mensaje
   devuelto) no traen la cabecera original, pero sí llegan dirigidos a esa dirección:
   el lector la busca en `Delivered-To`, `X-Original-To`, `Envelope-To`,
   `X-Envelope-To`, `Original-Recipient` y `To` (`parse( $raw, $bounce_email )` solo
   acepta direcciones de ese buzón). VERP manda sobre `X-Dox-Orbit`, que queda como segundo
   camino. El lector acepta también el token antiguo con puntos.
   **Carpetas de Dovecot (cPanel):** con `lmtp_save_to_detail_mailbox` el aviso a `buzón+token@` no
   va a INBOX sino a una carpeta nueva con el nombre del token (con puntos serían subcarpetas
   anidadas, de ahí los guiones). `DXO_Bounces::sweep` lista las carpetas (`LIST "" "*"`, con el
   separador que diga el servidor), procesa INBOX (no leídos) y cada carpeta cuyo nombre sea un
   token (todos los mensajes; el nombre de la carpeta vale como token si las cabeceras no lo
   traen), borra los verificados, hace `EXPUNGE` y elimina la carpeta si queda vacía, y después
   las intermedias numéricas vacías del formato antiguo (`INBOX.1`, `INBOX.1.3`). Lo no
   verificable deja la carpeta y se marca leído. Gmail y Microsoft 365 lo dejan en INBOX. Si el lector no saca la dirección que falló pero el token VERP es válido,
   se usa el correo del envío. Lo que se reconoce como rebote pero no se puede verificar
   se marca leído y **no se borra**; solo lo verificado y aplicado se borra.
7. **Aplicar.** El buzón es público (es el Return-Path), así que un aviso falso no
   puede tocar a nadie: `X-Dox-Orbit` lleva `campaña.destinatario.firma` (los 10
   primeros hex de un HMAC-SHA256 con `wp_salt('auth')`, `DXO_Bounces::sign`). Solo
   si la firma es válida **y** el correo de ese envío es el de la dirección que
   rebotó se actúa; si no, no se toca nada y se cuenta como "no verificado" en el
   estado de la última pasada. No se adivina el envío por el correo. Los correos que
   no son de campaña (confirmación, prueba) no tienen envío y por tanto no pueden
   cambiar a ningún suscriptor.
   `recipients.bounced_at` y `bounce_reason` ("hard 5.1.1: motivo"), `campaigns.bounced`
   +1 y un evento `bounce`; un aviso ya anotado en ese envío no se cuenta dos veces.
   Definitivo: el suscriptor pasa a `bounced` (si no se dio de baja él). Pasajero:
   `subscribers.soft_bounces` +1 (una vez por envío: la marca `bounced_at` de ese
   envío evita repetir) y al tercero pasa a `bounced`; solo un clic (`DXO_Public`)
   lo pone a cero, no las aperturas (Apple MPP y los antivirus las disparan solos). Reactivar a mano o volver a apuntarse también.
8. **Nunca se vuelve a escribir** a un `bounced`: `send_one` salta a quien no está
   activo, la audiencia solo toma activos, la bienvenida solo se encola a activos y
   la confirmación solo se reenvía a pendientes. Si la misma dirección se apunta otra
   vez desde un formulario, `DXO_Subscribers::subscribe` sigue como siempre (pasa a
   `pending` y recibe el correo de confirmación, o a `active` si la doble
   confirmación está apagada): no se reactiva a escondidas.
9. **Esquema (`DB_VERSION` 2):** `recipients.bounced_at`, `recipients.bounce_reason`,
   `subscribers.soft_bounces`, `campaigns.bounced`. `maybe_upgrade` corre en
   `plugins_loaded` y vuelve a pasar dbDelta solo con que cambie el número.
10. **Informe y Resumen.** El informe suma "rebotados" (cifra y %) y una tarjeta con
   las direcciones que rebotaron (tipo, motivo, si ya salió de la lista). El Resumen
   suma "Rebotados" a la salud de la lista y cuenta en su nota.

Los avisos de rebote **pueden traer datos de terceros** (es correo de internet):
nada se ejecuta, el texto se limita y todo lo que se pinta va con `esc_html`.

## Las tarjetas de bienvenida (0.4.0)

La tarjeta de cada bienvenida en Campañas enseña siempre "N enviados · X %
abiertos · Informe", también con 0 enviados, para que el informe se encuentre desde
el primer día. El informe de una campaña sin destinatarios sale con ceros y guiones.

## Correos con código HTML (0.5.0)

Dos cosas nuevas, las dos con el mismo limpiador (`DXO_Sanitizer`) y el mismo
pasador de CSS a línea (`DXO_Inliner`):

1. **Bloque "HTML"** (`type = html`) junto a título, texto, imagen... Va dentro del
   diseño normal de Orbit (logo, pie, baja). Se limpia al guardar
   (`normalize_blocks`) y otra vez al pintar. Su CSS se pasa a línea y lo que no
   se puede (`@media`) sube al `<head>` del correo (`render_email` saca los
   `<style>` del contenido). Los campos y los enlaces con seguimiento funcionan igual.
2. **Campañas de formato `html`** ("Pegar código HTML" al crear). Dos columnas
   nuevas en `campaigns` (`DB_VERSION` 3): `format` (`blocks` o `html`, por defecto
   `blocks`) y `html` (longtext). **El contenido del otro formato no se borra**:
   cambiar de formato solo cambia `format`, y se puede volver (el editor pide
   confirmación). Valen para campañas y para la bienvenida. Se eligió columna y no
   meterlo en el JSON de `blocks` o `list_ids` porque es lo que se lee al enviar
   y así no se mezcla con la audiencia ni con los bloques.
   El código se guarda **tal cual lo escribió la persona** (así el editor lo
   enseña igual al volver y el guardado automático no le recoloca el código a medio
   escribir) y se limpia **siempre que sale**: vista previa, prueba, envío y "ver en
   el navegador" pasan por `DXO_Renderer::render_email` → `DXO_Html_Email::render`.
   Tope de 1 MB al guardar.

**Qué hace el envío** (`DXO_Html_Email::render`), en este orden: `prepare()` (una vez
por campaña, se recuerda en memoria: un trozo sin `<html>`/`<body>` se mete en un
documento mínimo, se limpia y se pasa el CSS a línea) → rellena `{unsubscribe_url}` y
`{view_in_browser_url}` → campos (`{first_name|amigo}`, escapados) → preheader
oculto justo después de `<body>` → **si no hay un enlace con `{unsubscribe_url}`**,
el pie de siempre (`DXO_Renderer::footer_html`, con su idioma) antes de `</body>` →
enlaces `http(s)` con seguimiento (mailto, tel y `#` no; la baja y "ver en el
navegador" tampoco) → píxel de apertura antes de `</body>` → texto plano
(`DXO_Html_Email::to_text`: enlaces "texto (url)", títulos en mayúsculas).
Decisión: solo cuenta como baja propia un `{unsubscribe_url}` dentro de un `href`
y fuera de un comentario; en un texto suelto o dentro de un `<!--[if mso]>` no
cuenta y se añade el pie (nunca sale un correo sin baja). Los registros de enlaces
al lanzar (`register_links`) salen del mismo documento ya preparado, así que
coinciden con los que luego se reescriben.

**El limpiador** quita script, iframe, object, embed, form, input, textarea, select,
button, base, svg, meta refresh, `on*`, y `javascript:`, `vbscript:` y `data:` en
`href`/`src`/`background`; solo deja `link` de Google Fonts; los comentarios
condicionales de Outlook se quedan si dentro no hay nada ejecutable; en el CSS quita
`expression()`, `behavior`, `url()` que no sea http(s) y cualquier declaración con barra
invertida. Lo que no está en la lista de etiquetas se desenvuelve (se queda su texto).
Un `{campo}` solo puede ser todo el `href` si es el de baja o el de "ver en el navegador":
el nombre de una persona no decide adónde va un enlace.
**Trampa:** libxml codifica con `%` las llaves de un `href` (`{email}` → `%7Bemail%7D`),
así que los campos se esconden (`dxotag` + hex + `x`) mientras dura la lectura
(`protect` / `restore`) y libxml también escribe `&ntilde;` en vez de ñ (`unentity`).

**El pasador de CSS** entiende etiqueta, clase, id, compuestos, listas con comas,
descendientes y `>`. Prioridad: `!important`, luego lo que ya traía en línea, luego
especificidad y orden. Todo lo que no entiende (`:hover`, `[x]`, `+`, `~`) y todos
los `@media`/`@font-face`/`@import` se quedan en un `<style>` del `<head>`; si no
sobra nada, el `<style>` desaparece.

**Revisión de la 0.5.0.** Los `{campos}` se esconden con un secreto aleatorio por lectura y solo se
deshace lo que está en el mapa de esa lectura (antes un `dxotag<hex>x` escrito a mano se convertía en HTML: XSS).
Lo preparado (limpio + CSS en línea + si hay baja propia + avisos) se guarda en un transient por
`md5(código + versión)` y se borra al guardar (`DXO_Html_Email::forget`); el pasador indexa los elementos una vez y
tiene tope de 2000 reglas. El preheader, el pie y el píxel van en dos huecos (`<!--dxo-start-->` y `<!--dxo-end-->`)
puestos en el DOM, nunca por búsqueda de texto. Un @media que toca una propiedad la deja fuera de la línea. El código
viaja en base64 (`html_b64`) por los cortafuegos. "Ver en el navegador" lleva Content-Security-Policy. Sin la extensión
DOM de PHP el HTML propio se apaga con un aviso. El `>` se quita del CSS solo en atributos `style`; en un `<style>` es
el selector hijo y solo se quita el `<`.

**Editor** (`edit.php` + `admin.js`): CodeMirror de WordPress con
`wp_enqueue_code_editor( text/html )` (sin lint, que protesta de todo lo que es
normal en un correo); si la persona lo apagó en su perfil, queda un textarea
monoespaciado. Vista previa en un `iframe` con `sandbox="allow-same-origin"` y sin
`allow-scripts`. "Importar archivo .html" lee el archivo en el navegador
(FileReader, sin subirlo). Los avisos (tamaño sobre 102 KB que Gmail corta,
imágenes con dirección relativa o `http://`, sin `alt`, flex/grid/position/variables
CSS, fuentes externas, lo que se quitó) salen de `DXO_Html_Email::warnings` y no bloquean.
El JSON de datos del editor va con `JSON_HEX_TAG`: un `</script>` o un `<!--` en el
código no puede romper la página.

## Traducción

El código está en inglés y trae el español (`languages/dox-orbit-es_ES.*`).
WordPress no pasa de es_CO o es_MX a es_ES solo: el filtro de
`load_textdomain_mofile` del archivo principal lo hace. Para un texto nuevo,
añadir su traducción en `tools/es.py` y correr `python3 tools/i18n.py`.

## Probar en local

Funciona en un WordPress con SQLite (el plugin oficial *SQLite Database
Integration*) y `php -S`, sin MySQL. Con un mu-plugin que enganche `pre_wp_mail`
y guarde cada correo en un archivo se ve exactamente lo que recibiría la gente sin
enviar nada. El servidor de PHP atiende de una en una, así que el editor va algo
lento ahí.

## Desinstalar

Borrar el plugin **no borra** la lista ni las campañas (como Dox POS): solo quita
la tarea programada. Las tablas `wp_dxo_*` y las opciones `dxo_*` se borran a mano.

## Pendiente

- Casilla "Quiero recibir novedades" en el checkout de WooCommerce.
- Quejas (marcar como spam) y rebotes de Amazon SES por SNS (como `acg_mail`): el buzón IMAP no los ve con SES.
- Filtro de bots en los clics (los antivirus de Outlook abren todos los enlaces).
