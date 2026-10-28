<?php
/**
 * Correos hechos con código HTML: el bloque "HTML" y las campañas de formato
 * "html" (código pegado).
 *
 * PHP puro, sin WordPress: tests/run.php lo prueba solo. Lo caro (limpiar el
 * HTML y pasar su CSS a línea) se hace una vez por contenido y se recuerda: en
 * memoria durante la petición y, con WordPress, en un transient para que ni la
 * pasada de envío de cada minuto, ni la vista previa a cada tecla, ni "ver en
 * el navegador" lo repitan. Lo barato (campos, preheader, enlaces, pie, píxel)
 * se hace por cada persona.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DXO_Html_Email {

	/** Gmail corta los correos que pasan de unos 102 KB y esconde el resto. */
	const GMAIL_CLIP = 102400;

	/** Lo más grande que se guarda de una campaña: más no tiene sentido en un correo. */
	const MAX_BYTES = 1000000;

	/** Dónde se pone el preheader y el pie con el píxel: comentarios que pone Orbit, no la persona. */
	const SLOT_START = '<!--dxo-start-->';
	const SLOT_END   = '<!--dxo-end-->';

	// ─── Preparar (una vez por contenido) ───────────────────────────────────

	/** ¿Es un documento completo, o un trozo suelto? */
	public static function is_document( $html ) {
		return (bool) preg_match( '/<\s*(html|body)\b/i', (string) $html );
	}

	/** Un trozo sin <html> ni <body> se mete en un documento de correo mínimo. */
	public static function wrap( $fragment ) {
		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title></title></head>'
			. '<body style="margin:0;padding:0;">' . $fragment . '</body></html>';
	}

	/** El documento limpio y con el CSS en línea, todavía con los {campos} sin rellenar (y con los huecos del preheader y del pie). */
	public static function prepare( $html ) {
		return self::prepared( $html )['doc'];
	}

	/** ¿Trae el código su propio enlace de baja, visible y de verdad? (ver dom_has_unsubscribe) */
	public static function own_unsubscribe( $html ) {
		return self::prepared( $html, false )['own'];
	}

	protected static function cache_key( $html ) {
		return 'dxo_p_' . md5( $html . '|' . ( defined( 'DXO_VERSION' ) ? DXO_VERSION : 't' ) );
	}

	/** Se llama al guardar para que el contenido viejo no se quede en la base. */
	public static function forget( $html ) {
		$html = trim( (string) $html );
		if ( $html !== '' && function_exists( 'delete_transient' ) ) delete_transient( self::cache_key( $html ) );
	}

	/**
	 * Todo lo que sale de limpiar un código: [ 'doc', 'report', 'own', 'warn', 'skipped' ].
	 * 'warn' son los avisos de contenido (sin el del tamaño, que depende del pie).
	 */
	protected static function prepared( $html, $persist = true ) {
		static $mem = [], $saved = [];
		$html = trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $html ) );
		$key  = self::cache_key( $html );

		if ( ! isset( $mem[ $key ] ) ) {
			$hit = function_exists( 'get_transient' ) ? get_transient( $key ) : false;
			if ( is_array( $hit ) && isset( $hit['doc'] ) ) {
				$res = $hit;
				$saved[ $key ] = true;
			} else {
				$res = self::build( $html );
			}
			if ( count( $mem ) >= 4 ) array_shift( $mem );
			$mem[ $key ] = $res;
		}
		// Solo el envío, la prueba y "ver en el navegador" guardan en la base. La vista previa (una por tecla)
		// usa la memoria de la petición y nada más: de otro modo cada pulsación dejaría hasta 1 MB durante 7 días.
		if ( $persist && empty( $saved[ $key ] ) && function_exists( 'set_transient' ) ) {
			set_transient( $key, $mem[ $key ], 7 * 86400 );
			$saved[ $key ] = true;
		}
		return $mem[ $key ];
	}

	protected static function build( $html ) {
		if ( ! DXO_Sanitizer::available() ) {
			return [ 'doc' => self::wrap( DXO_Sanitizer::fragment( $html ) ) . '', 'report' => [], 'own' => false, 'warn' => [], 'skipped' => 0 ];
		}
		$src = self::is_document( $html ) ? $html : self::wrap( $html );
		$had = (bool) preg_match( '/^\s*<!doctype/i', $src );
		$doc = DXO_Sanitizer::load( $src );
		DXO_Sanitizer::clean_document( $doc );
		$report = DXO_Sanitizer::$report;
		$inl    = DXO_Inliner::inline_dom( $doc );

		$own  = self::dom_has_unsubscribe( $doc );
		$warn = self::content_warnings( $doc, $report, $inl['skipped'] );

		// Los huecos del preheader y del pie, puestos en el DOM: así nunca caen dentro de un comentario o de un atributo.
		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body && $doc->documentElement ) {
			$body = $doc->createElement( 'body' );
			$doc->documentElement->appendChild( $body );
		}
		if ( $body ) {
			$body->insertBefore( $doc->createComment( 'dxo-start' ), $body->firstChild );
			$body->appendChild( $doc->createComment( 'dxo-end' ) );
		}
		return [ 'doc' => DXO_Sanitizer::save( $doc, $had ), 'report' => $report, 'own' => $own, 'warn' => $warn, 'skipped' => $inl['skipped'] ];
	}

	/**
	 * Un trozo de un bloque HTML listo para el correo: limpio y con su CSS en línea.
	 * Lo que no se pudo pasar a línea (@media...) se queda en un <style> que el
	 * correo lleva luego al <head>.
	 */
	public static function fragment( $html ) {
		static $cache = [];
		$html = trim( (string) $html );
		if ( $html === '' ) return '';
		$key = md5( $html );
		if ( isset( $cache[ $key ] ) ) return $cache[ $key ];
		if ( ! DXO_Sanitizer::available() ) return DXO_Sanitizer::fragment( $html );

		$doc  = DXO_Sanitizer::load( $html, true );
		$root = DXO_Sanitizer::fragment_root( $doc );
		$out  = '';
		if ( $root ) {
			DXO_Sanitizer::clean_fragment( $doc );
			DXO_Inliner::inline_dom( $doc, $root );
			$out = DXO_Sanitizer::save_fragment( $doc );
		}
		if ( count( $cache ) >= 8 ) array_shift( $cache );
		return $cache[ $key ] = $out;
	}

	// ─── La baja propia ─────────────────────────────────────────────────────

	/**
	 * ¿Tiene el documento (ya limpio y con el CSS en línea) un enlace de baja que una
	 * persona vea y pueda tocar? Tiene que ser un <a> cuyo href EMPIECE por
	 * {unsubscribe_url}, con texto visible (o una imagen de tamaño razonable) y sin
	 * ningún ascendiente escondido (display:none, visibility:hidden, font-size:0, opacity:0,
	 * max-height:0, mso-hide:all). Un {unsubscribe_url} metido en otra URL, en un comentario,
	 * en un texto suelto o en un enlace invisible no cuenta: se pone el pie de Orbit.
	 */
	public static function dom_has_unsubscribe( DOMDocument $doc ) {
		foreach ( $doc->getElementsByTagName( 'a' ) as $a ) {
			$href = trim( DXO_Sanitizer::restore( $a->getAttribute( 'href' ) ) );
			// Exactamente {unsubscribe_url}. Se mira sobre el documento ya limpio: un href con respaldo
			// ({unsubscribe_url|...}) lo quita el limpiador y no cuenta.
			if ( ! preg_match( '/^\{unsubscribe_url\}$/i', $href ) ) continue;

			$visible = trim( preg_replace( '/[\s\x{00A0}\x{200B}\x{200C}\x{034F}]+/u', ' ', $a->textContent ) ) !== '';
			if ( ! $visible ) {
				foreach ( $a->getElementsByTagName( 'img' ) as $img ) {
					$w = $img->getAttribute( 'width' );
					$h = $img->getAttribute( 'height' );
					if ( ( $w === '' || (int) $w >= 16 ) && ( $h === '' || (int) $h >= 16 ) ) { $visible = true; break; }
				}
			}
			if ( ! $visible ) continue;

			$hidden = false;
			for ( $n = $a; $n && $n->nodeType === XML_ELEMENT_NODE; $n = $n->parentNode ) {
				if ( self::hidden_style( (string) $n->getAttribute( 'style' ) ) ) { $hidden = true; break; }
			}
			if ( ! $hidden && self::same_color_as_background( $a ) ) $hidden = true;
			if ( ! $hidden ) return true;
		}
		return false;
	}

	/** ¿Esconde este estilo lo que lleva dentro (a una persona, no a un lector de pantalla)? */
	protected static function hidden_style( $style ) {
		if ( $style === '' ) return false;
		$d = [];
		foreach ( DXO_Inliner::parse_decls( $style ) as $x ) $d[ $x[0] ] = strtolower( trim( $x[1] ) );

		if ( ( $d['display'] ?? '' ) === 'none' || ( $d['visibility'] ?? '' ) === 'hidden' || ( $d['mso-hide'] ?? '' ) === 'all' ) return true;
		if ( isset( $d['opacity'] ) && (float) $d['opacity'] == 0 ) return true;
		if ( isset( $d['max-height'] ) && (float) $d['max-height'] == 0 && preg_match( '/^0/', $d['max-height'] ) ) return true;
		if ( isset( $d['line-height'] ) && preg_match( '/^0(\.0+)?(px|pt|em|rem|%)?$/', $d['line-height'] ) ) return true;
		// height:0 solo esconde con overflow:hidden.
		if ( isset( $d['height'] ) && preg_match( '/^0(\.0+)?(px|pt|em|rem|%)?$/', $d['height'] ) && preg_match( '/hidden/', ( $d['overflow'] ?? '' ) . ( $d['overflow-y'] ?? '' ) ) ) return true;
		// El texto sacado de la pantalla.
		if ( isset( $d['text-indent'] ) && preg_match( '/^-\s*(\d+(?:\.\d+)?)/', $d['text-indent'], $m ) && (float) $m[1] >= 500 ) return true;
		// Letra diminuta: menos de 6 px (4,5 pt, 0,4 em).
		if ( isset( $d['font-size'] ) && preg_match( '/^(\d+(?:\.\d+)?)(px|pt|em|rem|%)?$/', $d['font-size'], $m ) ) {
			$n = (float) $m[1];
			$u = $m[2] ?? 'px';
			if ( ( $u === 'px' && $n < 6 ) || ( $u === 'pt' && $n < 4.5 ) || ( ( $u === 'em' || $u === 'rem' ) && $n < 0.4 ) || ( $u === '%' && $n < 40 ) ) return true;
		}
		return false;
	}

	/** Un color hexadecimal de un valor CSS, normalizado a 6 dígitos ('' si no hay uno explícito). */
	protected static function hex_of( $value ) {
		if ( ! preg_match( '/#([0-9a-f]{6}|[0-9a-f]{3})\b/i', (string) $value, $m ) ) return '';
		$h = strtolower( $m[1] );
		return strlen( $h ) === 3 ? $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2] : $h;
	}

	/** ¿Tiene el texto del enlace el mismo color que el fondo más cercano? Solo con los dos en hexadecimal explícito. */
	protected static function same_color_as_background( DOMElement $a ) {
		$color = $bg = '';
		for ( $n = $a; $n && $n->nodeType === XML_ELEMENT_NODE; $n = $n->parentNode ) {
			$d = [];
			foreach ( DXO_Inliner::parse_decls( (string) $n->getAttribute( 'style' ) ) as $x ) $d[ $x[0] ] = $x[1];
			if ( $color === '' && isset( $d['color'] ) ) $color = self::hex_of( $d['color'] );
			if ( $bg === '' ) {
				$bg = self::hex_of( ( $d['background-color'] ?? '' ) ?: ( $d['background'] ?? '' ) );
				if ( $bg === '' ) $bg = self::hex_of( $n->getAttribute( 'bgcolor' ) );
			}
			if ( $color !== '' && $bg !== '' ) break;
		}
		return $color !== '' && $bg !== '' && $color === $bg;
	}

	/** Lo mismo sobre un HTML en cadena (ya limpio). */
	public static function has_unsubscribe_link( $html ) {
		if ( ! DXO_Sanitizer::available() ) return false;
		return self::dom_has_unsubscribe( DXO_Sanitizer::load( $html ) );
	}

	// ─── El correo de una persona ───────────────────────────────────────────

	/**
	 * $o: html, brand, subject, preheader, fields, unsubscribe_url, view_url,
	 * pixel_url (o null), link (callable o null), strings.
	 *
	 * @return array [ 'html' => ..., 'text' => ... ]
	 */
	public static function render( array $o ) {
		$fields = $o['fields'] ?? [];
		$brand  = $o['brand'] ?? [];
		$str    = wp_parse_args_lite( $o['strings'] ?? [], [ 'unsubscribe' => 'Unsubscribe', 'view' => 'View in browser', 'lang' => 'en' ] );
		$unsub  = (string) ( $o['unsubscribe_url'] ?? '#' );
		$view   = (string) ( $o['view_url'] ?? '' );

		// 'preview' => true: la vista previa del editor no persiste lo preparado (ver prepared()).
		$p    = self::prepared( (string) ( $o['html'] ?? '' ), empty( $o['preview'] ) );
		$html = DXO_Renderer::fill_links( $p['doc'], $unsub, $view );
		// Los campos (el CSS ya no los lleva: el limpiador los quita de <style> y de style="").
		$html = DXO_Renderer::merge( $html, $fields, true );

		$pre    = DXO_Renderer::preheader_html( $o['preheader'] ?? '', $fields );
		$footer = '';
		if ( ! $p['own'] ) {
			$footer = "\n" . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding:0 16px 24px 16px;">'
				. DXO_Renderer::footer_html( $brand, $unsub, $view, $str )
				. '</td></tr></table>';
		}
		$html = self::fill_slot( $html, self::SLOT_START, $pre );

		// Seguimiento de clics: http(s) solamente (mailto, tel y #anclas no), y la baja y "ver en el navegador" no.
		// Se hace antes de poner el pie para que su enlace de baja quede intacto de todos modos.
		if ( ! empty( $o['link'] ) && is_callable( $o['link'] ) ) {
			$html = DXO_Renderer::rewrite_links( $html, $o['link'], [ $unsub, $view ] );
		}

		$pixel = empty( $o['pixel_url'] ) ? '' : '<img src="' . DXO_Renderer::esc( $o['pixel_url'] ) . '" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;">';
		$html  = self::fill_slot( $html, self::SLOT_END, $footer . $pixel );

		return [ 'html' => $html, 'text' => self::to_text( $html ) ];
	}

	/** Pone $piece donde está el hueco (un comentario que puso Orbit); si falta, antes de </body>. */
	protected static function fill_slot( $html, $slot, $piece ) {
		$pos = strpos( $html, $slot );
		if ( $pos !== false ) return substr_replace( $html, $piece, $pos, strlen( $slot ) );
		if ( $piece === '' ) return $html;
		$end = strripos( $html, '</body>' );
		return $end === false ? $html . $piece : substr( $html, 0, $end ) . $piece . substr( $html, $end );
	}

	// ─── Texto plano ────────────────────────────────────────────────────────

	/**
	 * La versión de solo texto: los enlaces como "texto (dirección)", los títulos
	 * en mayúsculas, las listas con guion y el resto sin etiquetas.
	 */
	public static function to_text( $html ) {
		$h = (string) $html;
		$h = preg_replace( '/<!--.*?-->/s', '', $h );
		$h = preg_replace( '#<(head|style|script|title)\b.*?</\1>#is', '', $h );
		// El preheader y cualquier cosa escondida a propósito.
		$h = preg_replace( '#<div\b[^>]*display\s*:\s*none[^>]*>.*?</div>#is', '', $h );
		// Cada bloque empieza en su línea (el pie no se pega a lo anterior).
		$h = preg_replace( '#<(table|p|div|h[1-6]|ul|ol|blockquote|hr)\b#i', "\n<$1", $h );

		// Títulos: mayúsculas solo en el texto, sin tocar las direcciones de los enlaces de dentro.
		$up = function ( $s ) { return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $s, 'UTF-8' ) : strtoupper( $s ); };
		$h = preg_replace_callback( '#<h[1-6]\b[^>]*>(.*?)</h[1-6]>#is', function ( $m ) use ( $up ) {
			$inner = preg_replace_callback( '/(?<=>|^)[^<]+/', function ( $t ) use ( $up ) {
				return htmlspecialchars( $up( html_entity_decode( $t[0], ENT_QUOTES, 'UTF-8' ) ), ENT_NOQUOTES, 'UTF-8' );
			}, $m[1] );
			return "\n\n" . $inner . "\n\n";
		}, $h );

		$h = preg_replace_callback( '#<a\b[^>]*\bhref="([^"]*)"[^>]*>(.*?)</a>#is', function ( $m ) {
			$text = trim( preg_replace( '/\s+/', ' ', html_entity_decode( strip_tags( $m[2] ), ENT_QUOTES, 'UTF-8' ) ) );
			$url  = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
			if ( $url === '' || $url[0] === '#' ) return $text;
			$url = preg_replace( '/^mailto:/i', '', $url );
			return $text === '' || $text === $url ? $url : $text . ' (' . $url . ')';
		}, $h );

		$h = preg_replace( '#<li\b[^>]*>#i', "\n- ", $h );
		$h = preg_replace( '#<br\s*/?>#i', "\n", $h );
		$h = preg_replace( '#<hr\b[^>]*>#i', "\n\n----\n\n", $h );
		$h = preg_replace( '#</(p|div|tr|table|ul|ol|blockquote|h[1-6]|section|article|header|footer)>#i', "\n", $h );
		$h = preg_replace( '#</(td|th)>#i', ' ', $h );
		$h = preg_replace( '#</(p|table|ul|ol|blockquote)>#i', "\n\n", $h );

		$text = html_entity_decode( strip_tags( $h ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t\x{00A0}\x{034F}\x{200C}\x{200B}]+/u", ' ', $text );
		$text = preg_replace( "/ *\n */", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	// ─── Avisos para el editor (no bloquean nada) ───────────────────────────

	/**
	 * Lo que conviene saber del código antes de enviarlo. Cada aviso es
	 * [ 'code' => ..., + datos ]; el panel los pone en palabras.
	 *
	 * codes: size (kb), img_relative (n), img_http (n), img_alt (n), css (items),
	 * fonts, removed (items), rules (n).
	 */
	public static function warnings( $html ) {
		$html = trim( (string) $html );
		if ( $html === '' ) return [];
		$p = self::prepared( $html, false );
		$w = [];
		// El pie de Orbit pesa lo suyo (unos 1,5 KB) si el código no trae baja propia.
		$bytes = strlen( $p['doc'] ) + ( $p['own'] ? 0 : 1500 );
		if ( $bytes > self::GMAIL_CLIP ) $w[] = [ 'code' => 'size', 'kb' => (int) round( $bytes / 1024 ) ];
		return array_merge( $w, $p['warn'] );
	}

	/** ¿Una dirección de imagen en WebP o AVIF? (Outlook para Windows no las muestra.) */
	public static function is_modern_image( $url ) {
		return (bool) preg_match( '~\.(?:webp|avif)(?:[?#]|$)~i', trim( (string) $url ) );
	}

	/** Cuántas imágenes WebP/AVIF hay en una lista de bloques (imagen, entrada del blog y las <img> de un bloque HTML). */
	public static function modern_images_in_blocks( array $blocks ) {
		$n = 0;
		foreach ( $blocks as $b ) {
			if ( ( $b['type'] ?? '' ) === 'image' && self::is_modern_image( $b['url'] ?? '' ) ) $n++;
			if ( ( $b['type'] ?? '' ) === 'post' && self::is_modern_image( $b['image'] ?? '' ) ) $n++;
			if ( ( $b['type'] ?? '' ) === 'html' && preg_match_all( '/<img\b[^>]*\bsrc\s*=\s*"([^"]*)"/i', (string) $b['html'], $m ) ) {
				foreach ( $m[1] as $u ) if ( self::is_modern_image( html_entity_decode( $u, ENT_QUOTES, 'UTF-8' ) ) ) $n++;
			}
		}
		return $n;
	}

	/** Los avisos que salen del contenido ya limpio (se calculan una vez y se guardan con él). */
	protected static function content_warnings( DOMDocument $doc, array $report, $skipped ) {
		$w   = [];
		$rel = $http = $alt = 0;
		foreach ( $doc->getElementsByTagName( 'img' ) as $img ) {
			$u = trim( DXO_Sanitizer::restore( $img->getAttribute( 'src' ) ) );
			if ( $u === '' ) continue;
			if ( preg_match( '#^http://#i', $u ) ) $http++;
			elseif ( ! preg_match( '#^(https://|//|\{)#i', $u ) ) $rel++;
			if ( ! $img->hasAttribute( 'alt' ) ) $alt++;
		}
		$modern = 0;
		foreach ( $doc->getElementsByTagName( 'img' ) as $img ) if ( self::is_modern_image( DXO_Sanitizer::restore( $img->getAttribute( 'src' ) ) ) ) $modern++;
		if ( $modern ) $w[] = [ 'code' => 'img_webp', 'n' => $modern ];
		if ( $rel ) $w[] = [ 'code' => 'img_relative', 'n' => $rel ];
		if ( $http ) $w[] = [ 'code' => 'img_http', 'n' => $http ];
		if ( $alt ) $w[] = [ 'code' => 'img_alt', 'n' => $alt ];

		// CSS que los programas de correo ignoran o rompen.
		$css = '';
		foreach ( $doc->getElementsByTagName( 'style' ) as $s ) $css .= $s->textContent . "\n";
		foreach ( ( new DOMXPath( $doc ) )->query( '//@style' ) as $a ) $css .= $a->nodeValue . ";\n";
		$bad = [];
		if ( preg_match( '/display\s*:\s*(inline-)?(flex|grid)|\b(flex|grid)(-[a-z]+)*\s*:/i', $css ) ) $bad[] = 'flex / grid';
		if ( preg_match( '/position\s*:\s*(absolute|fixed|sticky)/i', $css ) ) $bad[] = 'position';
		if ( preg_match( '/var\(\s*--|--[a-z0-9-]+\s*:/i', $css ) ) $bad[] = 'CSS variables';
		if ( $bad ) $w[] = [ 'code' => 'css', 'items' => $bad ];

		// Google Fonts (link o @import) lo cargan algunos programas de correo; cualquier otra fuente externa no se verá en ninguno.
		if ( preg_match( '/@font-face/i', $css ) || ! empty( $report['tags']['link'] ) || ! empty( $report['imports'] ) ) $w[] = [ 'code' => 'fonts' ];

		if ( $skipped ) $w[] = [ 'code' => 'rules', 'n' => (int) $skipped ];

		$removed = array_keys( $report['tags'] ?? [] );
		if ( ! empty( $report['attrs'] ) ) $removed[] = 'on*, javascript:, data:';
		if ( $removed ) $w[] = [ 'code' => 'removed', 'items' => $removed ];
		return $w;
	}
}
