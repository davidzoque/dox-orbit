<?php
/**
 * Limpieza del HTML de un correo.
 *
 * Lo usan el bloque "HTML" y las campañas hechas con código pegado. PHP puro,
 * sin WordPress (tests/run.php lo prueba solo). Se apoya en DOMDocument: el
 * HTML se lee como lo lee un navegador y se vuelve a escribir solo con lo que
 * un correo necesita. Lo que no está en la lista se quita; no hay lista de
 * "cosas peligrosas" que se pueda esquivar con una mayúscula o una entidad.
 *
 * Los {campos} del correo ({first_name}, {unsubscribe_url}...) se esconden
 * durante la lectura: libxml codifica con % las llaves dentro de un href y
 * rompería los enlaces. El escondite lleva un secreto distinto en cada lectura,
 * así que nadie puede escribir en su HTML algo que "se restaure" a otra cosa.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DXO_Sanitizer {

	/** Los {campos} que viajan intactos por el DOM. */
	const TAGS = 'first_name|last_name|email|unsubscribe_url|view_in_browser_url';

	/** Etiquetas que se quedan. */
	const ALLOWED = [
		'html', 'head', 'body', 'title', 'meta', 'style', 'link',
		'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col',
		'div', 'span', 'p', 'a', 'img', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'br', 'hr',
		'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del', 'ins', 'small', 'big', 'sup', 'sub', 'mark',
		'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'blockquote', 'center', 'font', 'pre', 'code', 'abbr', 'cite', 'q', 'address',
		'section', 'header', 'footer', 'article', 'main', 'nav', 'aside', 'figure', 'figcaption',
	];

	/** Etiquetas que se quitan con todo lo que llevan dentro. */
	const DROP = [
		'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript', 'noembed', 'noframes',
		'input', 'textarea', 'select', 'option', 'optgroup', 'datalist', 'button', 'keygen',
		'base', 'svg', 'math', 'canvas', 'audio', 'video', 'source', 'track', 'template', 'dialog',
		'map', 'area', 'param', 'bgsound', 'portal', 'plaintext', 'xmp', 'isindex',
	];

	/**
	 * De las anteriores, las que no tienen cierre. libxml no conoce <embed> ni <source> y
	 * a veces se traga lo que viene después como si fuera su contenido: se quita la
	 * etiqueta pero se salva lo que lleva dentro.
	 */
	const VOID = [ 'embed', 'input', 'source', 'track', 'param', 'area', 'base', 'keygen', 'bgsound', 'isindex' ];

	/** Atributos que se quedan en cualquier etiqueta. */
	const ATTRS = [
		'style', 'class', 'id', 'width', 'height', 'align', 'valign', 'bgcolor', 'background', 'border',
		'cellpadding', 'cellspacing', 'colspan', 'rowspan', 'role', 'dir', 'lang',
		'href', 'src', 'alt', 'title', 'target', 'rel', 'color', 'face', 'size', 'nowrap', 'start', 'scope',
	];

	/** Los de VML (los botones redondeados de Outlook), solo dentro de un comentario condicional. */
	const VML_ATTRS = [
		'fillcolor', 'strokecolor', 'strokeweight', 'arcsize', 'stroke', 'filled', 'stroked', 'type', 'coordsize',
		'path', 'inset', 'opacity', 'aspect', 'origin', 'position', 'v-text-anchor', 'o:allowincell', 'o:title',
	];

	/** Cuántos escondites de {campos} se recuerdan a la vez. */
	const KEEP_MAPS = 16;

	/** Lo que se ha quitado en la última limpieza: [ 'tags' => [ nombre => n ], 'attrs' => n, 'imports' => n ]. */
	public static $report = [ 'tags' => [], 'attrs' => 0, 'imports' => 0 ];

	/** secreto => [ n => '{campo}' ]. */
	protected static $maps = [];

	// ─── Por si el servidor no tiene lo necesario ───────────────────────────

	/** ¿Hay DOM? Sin él, nada de esto se puede hacer y el HTML propio queda apagado. */
	public static function available() {
		return class_exists( 'DOMDocument' ) && class_exists( 'DOMXPath' ) && function_exists( 'libxml_use_internal_errors' );
	}

	/** Recorta a $n caracteres, con o sin mbstring. */
	public static function cut( $s, $n ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $s, 0, $n ) : substr( (string) $s, 0, $n );
	}

	protected static function codepoint( $ch ) {
		$o = ord( $ch[0] );
		if ( $o < 0x80 ) return $o;
		if ( $o < 0xE0 && strlen( $ch ) >= 2 ) return ( ( $o & 0x1F ) << 6 ) | ( ord( $ch[1] ) & 0x3F );
		if ( $o < 0xF0 && strlen( $ch ) >= 3 ) return ( ( $o & 0x0F ) << 12 ) | ( ( ord( $ch[1] ) & 0x3F ) << 6 ) | ( ord( $ch[2] ) & 0x3F );
		return 0x10000;
	}

	// ─── Entrada y salida ───────────────────────────────────────────────────

	/**
	 * Esconde los {campos} en una cadena sin símbolos raros. Solo se esconde lo que
	 * es exactamente un campo bien formado ({nombre} o {nombre|respaldo} con letras,
	 * números y puntuación corriente); el escondite lleva un secreto aleatorio de
	 * esta lectura y solo se deshace lo que está en el mapa de esta lectura.
	 */
	public static function protect( $html ) {
		$html   = (string) $html;
		$secret = bin2hex( random_bytes( 8 ) );
		$map    = [];
		$out    = preg_replace_callback( '/\{(?:' . self::TAGS . ')(?:\|[\p{L}\p{N} _.,!?\-]{0,60})?\}/iu', function ( $m ) use ( &$map, $secret ) {
			$n         = count( $map );
			$map[ $n ] = $m[0];
			return 'dxo' . $secret . 'n' . $n . 'z';
		}, $html );
		if ( $out === null ) return $html; // UTF-8 roto: sin escondite, libxml hará lo que pueda
		self::$maps[ $secret ] = $map;
		if ( count( self::$maps ) > self::KEEP_MAPS ) array_shift( self::$maps );
		return $out;
	}

	public static function restore( $html ) {
		return preg_replace_callback( '/dxo([0-9a-f]{16})n(\d+)z/', function ( $m ) {
			return self::$maps[ $m[1] ][ (int) $m[2] ] ?? $m[0];
		}, (string) $html );
	}

	/** Quita los escondites de una cadena (el CSS no admite campos). */
	protected static function strip_tokens( $s ) {
		return preg_replace( '/dxo[0-9a-f]{16}n\d+z/', '', (string) $s );
	}

	/**
	 * Lee un HTML. $fragment = true lo mete dentro de <body> (el trozo que va
	 * dentro de un bloque): una etiqueta de cierre suelta no puede cortarlo.
	 */
	public static function load( $html, $fragment = false ) {
		$html = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $html );
		$html = str_replace( "\0", '', $html );
		// Un meta charset que diga otra cosa que UTF-8 estropearía los acentos: se quitan todos y
		// al salir se pone uno solo.
		$html = preg_replace( '~<meta\b[^>]*\bcharset\s*=[^>]*>~i', '', $html );
		$html = self::protect( $html );

		$prev = libxml_use_internal_errors( true );
		$doc  = new DOMDocument();
		// El <?xml encoding> es lo que hace que libxml lea el texto como UTF-8.
		if ( $fragment ) {
			$doc->loadHTML( '<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>', LIBXML_HTML_NODEFDTD | LIBXML_NONET );
		} else {
			$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NODEFDTD | LIBXML_NONET );
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		// Según la versión de libxml, esa línea queda como instrucción o como comentario.
		foreach ( iterator_to_array( $doc->childNodes ) as $n ) {
			if ( $n->nodeType === XML_PI_NODE || ( $n->nodeType === XML_COMMENT_NODE && strpos( ltrim( $n->nodeValue ), '?xml' ) === 0 ) ) {
				$doc->removeChild( $n );
			}
		}
		return $doc;
	}

	/** El <body> de un trozo leído con load( ..., true ). */
	public static function fragment_root( DOMDocument $doc ) {
		return $doc->getElementsByTagName( 'body' )->item( 0 );
	}

	/** El HTML de un documento leído con load(). $had_doctype: si el original traía <!doctype>. */
	public static function save( DOMDocument $doc, $had_doctype = false ) {
		$out = (string) $doc->saveHTML();
		$out = preg_replace( '/^\s*<!DOCTYPE[^>]*>\s*/i', $had_doctype ? '$0' : '', $out );
		if ( ! $had_doctype ) $out = '<!doctype html>' . "\n" . $out;
		return self::restore( self::unentity( trim( $out ) ) );
	}

	/**
	 * libxml escribe las letras con acento como &ntilde;. Se vuelven letras (el
	 * correo pesa menos y es legible) salvo las invisibles y el espacio duro, que
	 * se quedan como entidad para que se vean al leer el código.
	 */
	protected static function unentity( $html ) {
		return preg_replace_callback( '/&(?:#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]+);/i', function ( $m ) {
			$ch = html_entity_decode( $m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $ch === $m[0] || ! preg_match( '/^.$/su', $ch ) ) return $m[0];
			$cp = self::codepoint( $ch );
			if ( $cp < 0xA1 || ( $cp >= 0x2000 && $cp <= 0x200F ) || ( $cp >= 0x2028 && $cp <= 0x202F ) || $cp === 0x034F || $cp === 0xFEFF || $cp === 0xAD ) return $m[0];
			return $ch;
		}, $html );
	}

	// ─── Lo que usan los demás ──────────────────────────────────────────────

	/** Un documento completo, limpio. Sin DOM en el servidor, solo el texto. */
	public static function document( $html, &$report = null ) {
		$html = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $html );
		if ( ! self::available() ) return self::text_only( $html );
		$had = (bool) preg_match( '/^\s*<!doctype/i', $html );
		$doc = self::load( $html );
		self::clean_document( $doc );
		$report = self::$report;
		return self::save( $doc, $had );
	}

	/** Un trozo (lo de dentro de un bloque HTML), limpio. */
	public static function fragment( $html, &$report = null ) {
		$html = trim( (string) $html );
		if ( $html === '' ) return '';
		if ( ! self::available() ) return self::text_only( $html );
		$doc = self::load( $html, true );
		if ( ! self::fragment_root( $doc ) ) return '';
		self::clean_fragment( $doc );
		$report = self::$report;
		return self::save_fragment( $doc );
	}

	/** Sin DOM no se puede limpiar con garantías: solo queda el texto. */
	protected static function text_only( $html ) {
		return '<p>' . htmlspecialchars( trim( strip_tags( (string) $html ) ), ENT_QUOTES, 'UTF-8' ) . '</p>';
	}

	public static function clean_document( DOMDocument $doc ) {
		self::$report = [ 'tags' => [], 'attrs' => 0, 'imports' => 0 ];
		if ( $doc->documentElement ) self::clean( $doc->documentElement, false );
		// Un comentario suelto antes de <html> (los condicionales de Outlook a veces van ahí).
		foreach ( iterator_to_array( $doc->childNodes ) as $n ) {
			if ( $n->nodeType === XML_COMMENT_NODE && ! self::comment_ok( $n->nodeValue ) ) $doc->removeChild( $n );
		}
		// Un solo meta charset, el primero del <head>.
		$html = $doc->documentElement;
		if ( $html ) {
			$head = $doc->getElementsByTagName( 'head' )->item( 0 );
			if ( ! $head ) {
				$head = $doc->createElement( 'head' );
				$html->insertBefore( $head, $html->firstChild );
			}
			$meta = $doc->createElement( 'meta' );
			$meta->setAttribute( 'charset', 'utf-8' );
			$head->insertBefore( $meta, $head->firstChild );
		}
	}

	public static function clean_fragment( DOMDocument $doc ) {
		self::$report = [ 'tags' => [], 'attrs' => 0, 'imports' => 0 ];
		$root = self::fragment_root( $doc );
		if ( $root ) self::clean( $root, true );
	}

	public static function save_fragment( DOMDocument $doc ) {
		$root = self::fragment_root( $doc );
		if ( ! $root ) return '';
		$out = '';
		foreach ( $root->childNodes as $child ) {
			$out .= $doc->saveHTML( $child );
		}
		return self::restore( self::unentity( trim( $out ) ) );
	}

	// ─── El recorrido ───────────────────────────────────────────────────────

	/**
	 * Limpia los hijos de $parent. $frag: es un trozo dentro de un bloque, donde
	 * <html>, <head> y <body> sobran y title, meta y link no pintan nada.
	 */
	protected static function clean( DOMNode $parent, $frag ) {
		foreach ( iterator_to_array( $parent->childNodes ) as $node ) {
			if ( $node->nodeType === XML_COMMENT_NODE ) {
				if ( ! self::comment_ok( $node->nodeValue ) ) $parent->removeChild( $node );
				continue;
			}
			if ( $node->nodeType === XML_TEXT_NODE ) continue;
			if ( $node->nodeType !== XML_ELEMENT_NODE ) {
				$parent->removeChild( $node );
				continue;
			}

			$tag = strtolower( $node->nodeName );

			if ( in_array( $tag, self::DROP, true ) || ( $frag && in_array( $tag, [ 'title', 'meta', 'link' ], true ) ) ) {
				self::note( $tag );
				if ( in_array( $tag, self::VOID, true ) ) {
					self::clean( $node, $frag );
					while ( $node->firstChild ) $parent->insertBefore( $node->firstChild, $node );
				}
				$parent->removeChild( $node );
				continue;
			}

			// Fuera de la lista (form, label, v:roundrect, o:p...): se quita la etiqueta y se queda lo que lleva.
			$unwrap = ! in_array( $tag, self::ALLOWED, true ) || ( $frag && in_array( $tag, [ 'html', 'head', 'body' ], true ) );
			if ( $unwrap ) {
				if ( $tag === 'form' ) self::note( 'form' );
				self::clean( $node, $frag );
				while ( $node->firstChild ) $parent->insertBefore( $node->firstChild, $node );
				$parent->removeChild( $node );
				continue;
			}

			if ( ! self::clean_element( $node, $tag ) ) {
				$parent->removeChild( $node );
				continue;
			}
			self::clean( $node, $frag );
		}
	}

	protected static function note( $tag ) {
		self::$report['tags'][ $tag ] = ( self::$report['tags'][ $tag ] ?? 0 ) + 1;
	}

	/** Atributos de un elemento permitido. Devuelve false si el elemento entero sobra. */
	protected static function clean_element( DOMElement $el, $tag ) {
		if ( $tag === 'link' ) {
			$href = trim( (string) $el->getAttribute( 'href' ) );
			$rel  = strtolower( trim( (string) $el->getAttribute( 'rel' ) ) );
			// Solo las fuentes de Google: unos programas de correo las cargan y los demás las ignoran.
			if ( $rel !== 'stylesheet' || ! preg_match( '#^https://fonts\.googleapis\.com/#i', $href ) ) {
				self::note( 'link' );
				return false;
			}
		}
		if ( $tag === 'meta' ) {
			$name = strtolower( trim( (string) $el->getAttribute( 'name' ) ) );
			$he   = strtolower( trim( (string) $el->getAttribute( 'http-equiv' ) ) );
			// http-equiv solo vale para compatibilidad de Outlook; "refresh" y compañía no pasan aunque el meta
			// lleve también un nombre permitido.
			if ( $he !== '' && $he !== 'x-ua-compatible' ) {
				$el->removeAttribute( 'http-equiv' );
				self::note( 'meta' );
				$he = '';
			}
			$ok = $he !== '' || in_array( $name, [ 'viewport', 'x-apple-disable-message-reformatting', 'format-detection', 'color-scheme', 'supported-color-schemes', 'description' ], true );
			if ( ! $ok ) {
				self::note( 'meta' );
				return false;
			}
		}
		if ( $tag === 'style' ) {
			$css = self::clean_css( $el->textContent );
			while ( $el->firstChild ) $el->removeChild( $el->firstChild );
			if ( trim( $css ) === '' ) return false;
			$el->appendChild( $el->ownerDocument->createTextNode( $css ) );
		}

		foreach ( iterator_to_array( $el->attributes ) as $attr ) {
			$name  = strtolower( $attr->nodeName );
			$value = (string) $attr->nodeValue;

			if ( strpos( $name, 'on' ) === 0 ) {
				self::$report['attrs']++;
				$el->removeAttributeNode( $attr );
				continue;
			}

			$keep = in_array( $name, self::ATTRS, true )
				|| strpos( $name, 'aria-' ) === 0
				|| ( $tag === 'html' && strpos( $name, 'xmlns' ) === 0 )
				|| ( $tag === 'meta' && in_array( $name, [ 'charset', 'name', 'content', 'http-equiv' ], true ) )
				|| ( $tag === 'link' && $name === 'type' )
				|| ( $tag === 'style' && $name === 'media' )
				|| ( in_array( $tag, [ 'ol', 'ul', 'li' ], true ) && $name === 'type' );
			if ( ! $keep ) {
				$el->removeAttributeNode( $attr );
				continue;
			}

			if ( $name === 'style' ) {
				$css = trim( self::clean_css( $value, true ) );
				if ( $css === '' ) $el->removeAttributeNode( $attr ); else $el->setAttribute( 'style', $css );
			} elseif ( $name === 'href' ) {
				if ( ! self::url_ok( $value, [ 'http', 'https', 'mailto', 'tel' ] ) ) {
					self::$report['attrs']++;
					$el->removeAttributeNode( $attr );
				}
			} elseif ( $name === 'src' || $name === 'background' ) {
				if ( ! self::url_ok( $value, [ 'http', 'https' ] ) ) {
					self::$report['attrs']++;
					$el->removeAttributeNode( $attr );
				}
			} elseif ( $name === 'target' ) {
				if ( ! in_array( strtolower( $value ), [ '_blank', '_self', '_parent', '_top' ], true ) ) $el->removeAttributeNode( $attr );
			} elseif ( $name === 'id' ) {
				if ( ! preg_match( '/^[A-Za-z][\w:.-]*$/', $value ) ) $el->removeAttributeNode( $attr );
			}
		}

		// Una imagen sin dirección no es nada.
		if ( $tag === 'img' && ! $el->hasAttribute( 'src' ) ) return false;
		return true;
	}

	// ─── Direcciones ────────────────────────────────────────────────────────

	/**
	 * ¿Se puede dejar esta dirección? Con esquema (http:, javascript:...) solo los
	 * de $schemes; sin esquema (relativa, //, #) pasa. Los espacios y controles
	 * se ignoran al buscar el esquema, como hacen los navegadores ("java\nscript:").
	 * Un {campo} al principio solo vale si es el de baja o el de "ver en el
	 * navegador": el nombre de una persona no puede decidir adónde va un enlace.
	 */
	public static function url_ok( $url, array $schemes ) {
		$v = trim( self::restore( $url ) );
		if ( $v === '' ) return false;
		if ( $v[0] === '{' ) {
			// Exactamente el campo, sin respaldo ni nada detrás: "{unsubscribe_url|https://otro}" no es una baja.
			return (bool) preg_match( '/^\{(unsubscribe_url|view_in_browser_url)\}$/i', $v );
		}
		$clean = preg_replace( '/[\x00-\x20\x7f]+/', '', $v );
		if ( preg_match( '/^([a-z][a-z0-9+.\-]*):/i', $clean, $m ) ) {
			return in_array( strtolower( $m[1] ), $schemes, true );
		}
		return true;
	}

	// ─── CSS ────────────────────────────────────────────────────────────────

	/**
	 * Quita del CSS (de un <style> o de un atributo style) lo que ejecuta algo o
	 * va a otra parte: expression(), behavior, url() que no sea http(s), @import que
	 * no sea de Google Fonts y cualquier declaración con una barra invertida (con
	 * ella se escribe "javascript" sin que se lea). Los {campos} no valen en CSS:
	 * el nombre de una persona no puede meter reglas.
	 *
	 * Los comentarios se quitan primero y hasta que no quede ninguno, y fuera todo "<":
	 * así "</sty/**\/le>" no puede volver a formar "</style>" al quitar lo de en medio.
	 * En un atributo ($attr) también fuera el ">".
	 */
	public static function clean_css( $css, $attr = false ) {
		$css = str_replace( "\0", '', self::strip_tokens( $css ) );
		do {
			$prev = $css;
			$css  = preg_replace( '#/\*.*?\*/#s', '', $css );
			$css  = preg_replace( '#/\*.*$#s', '', $css ); // comentario sin cerrar: el resto es comentario
			$css  = str_replace( [ '<!--', '-->' ], '', $css );
			$css  = str_replace( '<', '', $css );
			if ( $attr ) $css = str_replace( '>', '', $css );
		} while ( $css !== $prev );

		// @import: solo una hoja de Google Fonts por https, con a lo sumo una lista de medios detrás.
		$css = preg_replace_callback( '/@import\b[^;]*;?/i', function ( $m ) {
			$s = trim( $m[0] );
			$u = 'https://fonts\.googleapis\.com/[^"\')\s;]*';
			if ( preg_match( '~^@import\s+(?:url\(\s*(["\']?)' . $u . '\1\s*\)|(["\'])' . $u . '\2)\s*[a-z0-9 ,]*;?$~i', $s ) ) return $m[0];
			self::$report['imports']++;
			return '';
		}, $css );

		$bad = 'expression\s*\(|(?:java|vb|live)script\s*:|behaviou?r\s*:|-moz-binding|\\\\|data\s*:'
			. '|url\s*\(\s*+[\'"]?+\s*+(?!https?:|//)[^)\'"\s]*+';
		return preg_replace( '~[^;{}]*(?:' . $bad . ')[^;{}]*;?~i', '', $css );
	}

	// ─── Comentarios ────────────────────────────────────────────────────────

	/**
	 * Solo se dejan los condicionales de Outlook (<!--[if mso]> ... <![endif]-->)
	 * y sus cierres. Los demás comentarios sobran. Lo de dentro de un condicional
	 * no lo lee un navegador pero sí Outlook (y un navegador, si el comentario se
	 * cerrara antes de tiempo): se comprueba etiqueta por etiqueta y atributo por
	 * atributo, y si algo no está en la lista el comentario entero se quita.
	 */
	protected static function comment_ok( $data ) {
		$d = (string) $data;
		// "--!>" cierra un comentario en HTML5 y "<!--" abre otro: con ellos el resto se escaparía del comentario.
		if ( preg_match( '/--!|<!--|\x00/', $d ) || preg_match( '/^\s*-?>/', $d ) ) return false;
		if ( preg_match( '/^\s*(<!\[endif\]|\[endif\])\s*$/i', $d ) ) return true;
		if ( ! preg_match( '/^\s*\[if\b[^\]<>]*\]>(.*)$/is', $d, $m ) ) return false;
		$inner = preg_replace( '/(<!\[endif\]|<!)\s*$/i', '', $m[1] );
		if ( self::inner_ok( $inner ) ) return true;
		self::$report['attrs']++;
		return false;
	}

	/** El contenido de un condicional: texto libre y etiquetas de la lista con atributos de la lista. */
	protected static function inner_ok( $s ) {
		if ( trim( $s ) === '' ) return true;
		// Entidades: solo las corrientes.
		if ( preg_match( '/&(?!(?:nbsp|amp|lt|gt|quot|copy|reg|middot|zwnj);)/i', $s ) ) return false;

		$tag_re  = '/\G<\s*(\/?)\s*([A-Za-z][A-Za-z0-9:\-]*)((?:\s+[^\s=\/>"\'<`]+(?:\s*=\s*(?:"[^"<]*"|\'[^\'<]*\'|[^\s"\'<>=`]+))?)*)\s*\/?>/';
		$attr_re = '/\s+([^\s=\/>"\'<`]+)(?:\s*=\s*(?:"([^"<]*)"|\'([^\'<]*)\'|([^\s"\'<>=`]+)))?/';
		$pos     = 0;
		while ( ( $lt = strpos( $s, '<', $pos ) ) !== false ) {
			if ( ! preg_match( $tag_re, $s, $m, 0, $lt ) ) return false;
			$pos  = $lt + strlen( $m[0] );
			$name = strtolower( $m[2] );
			$vml  = (bool) preg_match( '/^(v|o|w|m):[a-z0-9]+$/', $name );
			if ( ! $vml && $name !== 'xml' && ! in_array( $name, self::ALLOWED, true ) ) return false;
			if ( in_array( $name, [ 'link', 'meta', 'title', 'head', 'html', 'body' ], true ) ) return false;

			if ( $m[1] === '' && preg_match_all( $attr_re, $m[3], $attrs, PREG_SET_ORDER ) ) {
				foreach ( $attrs as $a ) {
					$an = strtolower( $a[1] );
					$av = (string) ( $a[2] ?? '' ) . (string) ( $a[3] ?? '' ) . (string) ( $a[4] ?? '' );
					if ( strpos( $an, 'on' ) === 0 ) return false;
					$ok = in_array( $an, self::ATTRS, true ) || strpos( $an, 'aria-' ) === 0 || strpos( $an, 'xmlns' ) === 0
						|| ( $vml && in_array( $an, self::VML_ATTRS, true ) ) || ( $name === 'style' && $an === 'media' );
					if ( ! $ok ) return false;
					if ( $an === 'href' && ! self::url_ok( $av, [ 'http', 'https', 'mailto', 'tel' ] ) ) return false;
					if ( ( $an === 'src' || $an === 'background' ) && ! self::url_ok( $av, [ 'http', 'https' ] ) ) return false;
					if ( $an === 'style' && trim( self::clean_css( $av, true ) ) !== trim( $av ) ) return false;
				}
			}

			// El contenido de un <style> no se lee como etiquetas: se comprueba como CSS.
			if ( $name === 'style' && $m[1] === '' ) {
				$end = stripos( $s, '</style', $pos );
				if ( $end === false ) return false;
				$css = substr( $s, $pos, $end - $pos );
				if ( trim( self::clean_css( $css ) ) !== trim( $css ) ) return false;
				$pos = $end;
			}
		}
		return true;
	}
}
