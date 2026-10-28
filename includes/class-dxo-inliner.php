<?php
/**
 * Pasa el CSS de los <style> a los atributos style de cada elemento.
 *
 * Gmail, Outlook y compañía respetan mucho mejor el estilo en línea que una
 * hoja. PHP puro, sin WordPress (tests/run.php lo prueba solo).
 *
 * Entiende selectores simples: etiqueta, .clase, #id, compuestos (td.x),
 * listas con comas, descendientes (con espacio) e hijos directos (>). Todo lo
 * demás (:hover, [atributo], +, ~, pseudoelementos) no se pasa a línea: se
 * queda en un <style> del <head>, igual que cualquier @media o @font-face.
 * Un selector que no entiende simplemente se salta.
 *
 * Quién gana: !important, luego el estilo que el elemento ya traía en línea,
 * luego la especificidad (#id > .clase > etiqueta) y, a igualdad, la regla
 * que viene después.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DXO_Inliner {

	/** Las reglas que se pasan a línea como mucho; el resto se queda en el <style> tal cual. */
	const MAX_RULES = 2000;

	/** Un documento HTML (cadena) con su CSS pasado a línea. */
	public static function inline( $html ) {
		$had = (bool) preg_match( '/^\s*<!doctype/i', (string) $html );
		$doc = DXO_Sanitizer::load( $html );
		self::inline_dom( $doc );
		return DXO_Sanitizer::save( $doc, $had );
	}

	/**
	 * Pasa a línea el CSS de todos los <style> de $doc. Con $scope (un trozo, no un
	 * documento) solo se miran los elementos de dentro y lo que sobra se queda donde
	 * estaba; si no, lo que sobra se lleva al <head>.
	 *
	 * Los elementos se indexan una vez (por etiqueta, clase e id) y cada selector se
	 * compara solo con los candidatos de su último compuesto: no hay una consulta por regla.
	 * Un @media que toca una propiedad de un elemento la deja fuera de la línea (el estilo en
	 * línea ganaría al @media y el correo dejaría de adaptarse al móvil): esa declaración se
	 * queda en el <style> con su selector.
	 *
	 * @return array [ 'skipped' => reglas que no se pasaron por pasar del tope ]
	 */
	public static function inline_dom( DOMDocument $doc, ?DOMElement $scope = null ) {
		$fragment = $scope !== null;
		if ( ! $fragment ) $scope = $doc->documentElement;
		$result = [ 'skipped' => 0 ];
		if ( ! $scope ) return $result;

		$xp     = new DOMXPath( $doc );
		$styles = [];
		foreach ( $xp->query( './/style', $scope ) as $s ) $styles[] = $s;
		if ( ! $styles ) return $result;

		$idx = self::index( $scope, $fragment );

		// 1. Las hojas, en reglas y "tal cual". Las de otro medio (print...) no se tocan.
		$sheets = [];
		$moved  = [];
		foreach ( $styles as $style ) {
			$media = strtolower( trim( (string) $style->getAttribute( 'media' ) ) );
			if ( $media !== '' && $media !== 'all' && $media !== 'screen' ) { $moved[] = $style; continue; }
			$sheets[] = [ $style, self::parse_sheet( $style->textContent ) ];
		}

		// 2. Lo que tocan los @media: [ elemento ][ propiedad ] (esas no se pasan a línea).
		$protect = [];
		foreach ( $sheets as $sh ) {
			foreach ( $sh[1] as $item ) {
				if ( $item['kind'] !== 'raw' || ! preg_match( '/^@(media|supports)\b/i', $item['text'] ) ) continue;
				$open = strpos( $item['text'], '{' );
				$last = strrpos( $item['text'], '}' );
				if ( $open === false || $last === false || $last <= $open ) continue;
				foreach ( self::parse_sheet( substr( $item['text'], $open + 1, $last - $open - 1 ) ) as $inner ) {
					if ( $inner['kind'] !== 'rule' ) continue;
					$props = array_column( self::parse_decls( $inner['body'] ), 0 );
					foreach ( $inner['selectors'] as $sel ) {
						$parsed = self::parse_selector( $sel );
						if ( ! $parsed ) continue;
						foreach ( self::match( $parsed['parts'], $idx ) as $k ) {
							foreach ( $props as $pr ) $protect[ $k ][ $pr ] = true;
						}
					}
				}
			}
		}

		// 3. Las reglas, a cada elemento que les toca.
		$cand  = []; // clave del elemento => [ 'el' => nodo, 'decls' => [ propiedad => [prioridad, valor, !important] ] ]
		$order = 0;
		$seen  = 0;
		$keep  = []; // [ nodo style, lo que sobra ]
		foreach ( $sheets as $sh ) {
			$left = '';
			foreach ( $sh[1] as $item ) {
				if ( $item['kind'] === 'raw' ) {
					$left .= $item['text'] . "\n";
					continue;
				}
				if ( ++$seen > self::MAX_RULES ) {
					$result['skipped']++;
					$left .= implode( ',', $item['selectors'] ) . '{' . trim( $item['body'] ) . "}\n";
					continue;
				}
				$decls    = self::parse_decls( $item['body'] );
				$rest     = [];
				$okSels   = [];
				$deferred = [];
				foreach ( $item['selectors'] as $sel ) {
					$parsed = self::parse_selector( $sel );
					if ( ! $parsed ) { $rest[] = $sel; continue; }
					$okSels[] = $sel;
					foreach ( self::match( $parsed['parts'], $idx ) as $k ) {
						if ( ! isset( $cand[ $k ] ) ) $cand[ $k ] = [ 'el' => $idx['info'][ $k ]['el'], 'decls' => [] ];
						foreach ( $decls as $i => $d ) {
							$p = $d[0];
							if ( isset( $protect[ $k ][ $p ] ) ) { $deferred[ $i ] = $d; continue; }
							$prio = [ $d[2] ? 1 : 0, 0, $parsed['spec'], $order + $i ];
							if ( ! isset( $cand[ $k ]['decls'][ $p ] ) || self::cmp( $prio, $cand[ $k ]['decls'][ $p ][0] ) >= 0 ) {
								$cand[ $k ]['decls'][ $p ] = [ $prio, $d[1], $d[2] ];
							}
						}
					}
				}
				$order += count( $decls ) + 1;
				// Los selectores que no se entendieron se quedan, con sus declaraciones, para los programas que sí los usan.
				if ( $rest ) $left .= implode( ',', $rest ) . '{' . trim( $item['body'] ) . "}\n";
				// Lo que un @media pisa se queda en la hoja con su selector.
				if ( $deferred && $okSels ) {
					$txt = [];
					foreach ( $deferred as $d ) $txt[] = $d[0] . ':' . $d[1] . ( $d[2] ? ' !important' : '' );
					$left .= implode( ',', $okSels ) . '{' . implode( ';', $txt ) . "}\n";
				}
			}
			$keep[] = [ $sh[0], trim( $left ) ];
		}

		// 4. Cada elemento: lo que ya traía en línea más lo que le toca de las hojas.
		foreach ( $cand as $c ) {
			$decls = $c['decls'];
			foreach ( self::parse_decls( (string) $c['el']->getAttribute( 'style' ) ) as $d ) {
				$prio = [ $d[2] ? 1 : 0, 1, 0, 0 ];
				if ( ! isset( $decls[ $d[0] ] ) || self::cmp( $prio, $decls[ $d[0] ][0] ) >= 0 ) {
					$decls[ $d[0] ] = [ $prio, $d[1], $d[2] ];
				}
			}
			// De menos a más fuerte: si una propiedad corta y otra larga chocan (padding / padding-left), gana la fuerte.
			uasort( $decls, function ( $a, $b ) { return self::cmp( $a[0], $b[0] ); } );
			$out = [];
			foreach ( $decls as $p => $d ) $out[] = $p . ':' . $d[1] . ( $d[2] ? ' !important' : '' );
			$c['el']->setAttribute( 'style', implode( ';', $out ) );
		}

		// 5. Lo que sobra de cada <style>: se reescribe, o se quita si no queda nada.
		$head = null;
		if ( ! $fragment ) {
			$head = $doc->getElementsByTagName( 'head' )->item( 0 );
			if ( ! $head ) {
				$head = $doc->createElement( 'head' );
				$scope->insertBefore( $head, $scope->firstChild );
			}
		}
		foreach ( $keep as $k ) {
			list( $style, $left ) = $k;
			if ( $left === '' ) {
				$style->parentNode->removeChild( $style );
				continue;
			}
			while ( $style->firstChild ) $style->removeChild( $style->firstChild );
			$style->appendChild( $doc->createTextNode( $left ) );
			if ( $head && $style->parentNode !== $head ) $head->appendChild( $style );
		}
		if ( $head ) {
			foreach ( $moved as $style ) if ( $style->parentNode !== $head ) $head->appendChild( $style );
		}
		return $result;
	}

	// ─── Índice de elementos ────────────────────────────────────────────────

	/**
	 * Todos los elementos de $scope (menos los del <head>) en un solo recorrido:
	 * por etiqueta, clase e id, con su padre.
	 *
	 * @return array [ 'info' => [ clave => [ el, tag, id, cls, p ] ], 'tag' => ..., 'id' => ..., 'cls' => ... ]
	 */
	protected static function index( DOMElement $scope, $fragment ) {
		$idx   = [ 'info' => [], 'tag' => [], 'id' => [], 'cls' => [] ];
		$stack = $fragment ? array_reverse( self::children( $scope ) ) : [ $scope ];
		while ( $stack ) {
			$el  = array_pop( $stack );
			$tag = strtolower( $el->nodeName );
			if ( $tag === 'head' ) continue;
			$k   = spl_object_id( $el );
			$cls = [];
			foreach ( preg_split( '/\s+/', trim( (string) $el->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY ) as $c ) $cls[ $c ] = true;
			$id  = (string) $el->getAttribute( 'id' );
			$par = $el->parentNode;
			$idx['info'][ $k ] = [ 'el' => $el, 'tag' => $tag, 'id' => $id, 'cls' => $cls, 'p' => $par ? spl_object_id( $par ) : null ];
			$idx['tag'][ $tag ][] = $k;
			if ( $id !== '' ) $idx['id'][ $id ][] = $k;
			foreach ( $cls as $c => $_ ) $idx['cls'][ $c ][] = $k;
			foreach ( array_reverse( self::children( $el ) ) as $ch ) $stack[] = $ch;
		}
		return $idx;
	}

	protected static function children( DOMElement $el ) {
		$out = [];
		foreach ( $el->childNodes as $c ) if ( $c->nodeType === XML_ELEMENT_NODE ) $out[] = $c;
		return $out;
	}

	/** Las claves de los elementos que encajan con un selector. */
	protected static function match( array $parts, array $idx ) {
		$last = $parts[ count( $parts ) - 1 ];
		if ( $last['id'] !== '' ) {
			$cands = $idx['id'][ $last['id'] ] ?? [];
		} elseif ( $last['classes'] ) {
			$cands = null;
			foreach ( $last['classes'] as $c ) {
				$l = $idx['cls'][ $c ] ?? [];
				if ( $cands === null || count( $l ) < count( $cands ) ) $cands = $l;
			}
		} elseif ( $last['tag'] !== '*' ) {
			$cands = $idx['tag'][ $last['tag'] ] ?? [];
		} else {
			$cands = array_keys( $idx['info'] );
		}
		$out = [];
		foreach ( $cands as $k ) {
			if ( self::chain( $parts, count( $parts ) - 1, $k, $idx ) ) $out[] = $k;
		}
		return $out;
	}

	protected static function chain( array $parts, $i, $k, array $idx ) {
		$info = $idx['info'][ $k ] ?? null;
		if ( ! $info ) return false;
		$p = $parts[ $i ];
		if ( $p['tag'] !== '*' && $p['tag'] !== $info['tag'] ) return false;
		if ( $p['id'] !== '' && $p['id'] !== $info['id'] ) return false;
		foreach ( $p['classes'] as $c ) if ( ! isset( $info['cls'][ $c ] ) ) return false;
		if ( $i === 0 ) return true;
		if ( $p['comb'] === '>' ) {
			return $info['p'] !== null && isset( $idx['info'][ $info['p'] ] ) && self::chain( $parts, $i - 1, $info['p'], $idx );
		}
		for ( $a = $info['p']; $a !== null && isset( $idx['info'][ $a ] ); $a = $idx['info'][ $a ]['p'] ) {
			if ( self::chain( $parts, $i - 1, $a, $idx ) ) return true;
		}
		return false;
	}

	// ─── Hoja de estilos ────────────────────────────────────────────────────

	/**
	 * Parte el CSS en reglas y en "tal cual" (los @-reglas: @media, @font-face,
	 * @import...). Respeta cadenas, paréntesis y llaves anidadas.
	 *
	 * @return array [ [ 'kind' => 'rule', 'selectors' => [...], 'body' => '...' ] | [ 'kind' => 'raw', 'text' => '...' ] ]
	 */
	public static function parse_sheet( $css ) {
		$css = preg_replace( '#/\*.*?\*/#s', '', (string) $css );
		$css = str_replace( [ '<!--', '-->', '<![CDATA[', ']]>' ], '', $css );
		$n   = strlen( $css );
		$i   = 0;
		$out = [];

		while ( $i < $n ) {
			// El preludio: hasta { o ; fuera de cadenas y paréntesis.
			$start = $i;
			$paren = 0;
			$quote = '';
			while ( $i < $n ) {
				$ch = $css[ $i ];
				if ( $quote !== '' ) {
					if ( $ch === '\\' ) $i++; elseif ( $ch === $quote ) $quote = '';
				} elseif ( $ch === '"' || $ch === "'" ) {
					$quote = $ch;
				} elseif ( $ch === '(' ) {
					$paren++;
				} elseif ( $ch === ')' ) {
					$paren = max( 0, $paren - 1 );
				} elseif ( ( $ch === '{' || $ch === ';' ) && $paren === 0 ) {
					break;
				}
				$i++;
			}
			$prelude = trim( substr( $css, $start, $i - $start ) );
			if ( $i >= $n ) break; // basura al final, sin { ni ;

			if ( $css[ $i ] === ';' ) {
				$i++;
				if ( $prelude !== '' && $prelude[0] === '@' ) $out[] = [ 'kind' => 'raw', 'text' => $prelude . ';' ];
				continue;
			}

			// Un bloque { ... } con llaves anidadas.
			$i++;
			$depth = 1;
			$bs    = $i;
			$quote = '';
			while ( $i < $n && $depth > 0 ) {
				$ch = $css[ $i ];
				if ( $quote !== '' ) {
					if ( $ch === '\\' ) $i++; elseif ( $ch === $quote ) $quote = '';
				} elseif ( $ch === '"' || $ch === "'" ) {
					$quote = $ch;
				} elseif ( $ch === '{' ) {
					$depth++;
				} elseif ( $ch === '}' ) {
					$depth--;
				}
				$i++;
			}
			$body = substr( $css, $bs, max( 0, $i - 1 - $bs ) );

			if ( $prelude === '' ) continue;
			if ( $prelude[0] === '@' ) {
				$out[] = [ 'kind' => 'raw', 'text' => $prelude . '{' . trim( $body ) . '}' ];
			} else {
				$out[] = [ 'kind' => 'rule', 'selectors' => self::split_top( $prelude, ',' ), 'body' => $body ];
			}
		}
		return $out;
	}

	/** Parte por $sep fuera de paréntesis, corchetes y cadenas. */
	protected static function split_top( $text, $sep ) {
		$parts = [];
		$cur   = '';
		$depth = 0;
		$quote = '';
		$n     = strlen( $text );
		for ( $i = 0; $i < $n; $i++ ) {
			$ch = $text[ $i ];
			if ( $quote !== '' ) {
				if ( $ch === '\\' && $i + 1 < $n ) { $cur .= $ch . $text[ ++$i ]; continue; }
				if ( $ch === $quote ) $quote = '';
			} elseif ( $ch === '"' || $ch === "'" ) {
				$quote = $ch;
			} elseif ( $ch === '(' || $ch === '[' ) {
				$depth++;
			} elseif ( $ch === ')' || $ch === ']' ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( $ch === $sep && $depth === 0 ) {
				$parts[] = trim( $cur );
				$cur     = '';
				continue;
			}
			$cur .= $ch;
		}
		$parts[] = trim( $cur );
		return array_values( array_filter( $parts, function ( $p ) { return $p !== ''; } ) );
	}

	/** "a:b; c:d !important" → [ [ propiedad, valor, es_important ], ... ] */
	public static function parse_decls( $css ) {
		$out = [];
		foreach ( self::split_top( (string) $css, ';' ) as $d ) {
			$pos = strpos( $d, ':' );
			if ( $pos === false ) continue;
			$prop  = strtolower( trim( substr( $d, 0, $pos ) ) );
			$value = trim( substr( $d, $pos + 1 ) );
			if ( $prop === '' || $value === '' || ! preg_match( '/^[a-z_\-][a-z0-9_\-]*$/', $prop ) ) continue;
			$imp = false;
			if ( preg_match( '/\s*!\s*important\s*$/i', $value ) ) {
				$imp   = true;
				$value = trim( preg_replace( '/\s*!\s*important\s*$/i', '', $value ) );
			}
			if ( $value === '' ) continue;
			$out[] = [ $prop, $value, $imp ];
		}
		return $out;
	}

	// ─── Selectores ─────────────────────────────────────────────────────────

	/**
	 * Un selector simple: compuestos separados por espacio o ">". Devuelve null si
	 * lleva algo que no se sabe pasar a línea (:hover, [x], +, ~, ::before...).
	 *
	 * @return array|null [ 'parts' => [ [ 'comb' => ' '|'>', 'tag' => ..., 'id' => ..., 'classes' => [...] ], ... ], 'spec' => int ]
	 */
	public static function parse_selector( $sel ) {
		$sel = trim( (string) $sel );
		if ( $sel === '' || preg_match( '/[:\[\]()+~|\\\\"\']/', $sel ) ) return null;
		$sel   = trim( preg_replace( '/\s*>\s*/', ' > ', $sel ) );
		$parts = [];
		$comb  = ' ';
		$spec  = 0;
		foreach ( preg_split( '/\s+/', $sel ) as $tok ) {
			if ( $tok === '>' ) {
				if ( ! $parts || $comb === '>' ) return null;
				$comb = '>';
				continue;
			}
			if ( ! preg_match( '/^([a-z][a-z0-9]*|\*)?((?:[.#][A-Za-z_][A-Za-z0-9_\-]*)*)$/i', $tok, $m ) || $tok === '' ) return null;
			$tag = isset( $m[1] ) && $m[1] !== '' ? strtolower( $m[1] ) : '*';
			$id  = '';
			$cls = [];
			if ( ! empty( $m[2] ) ) {
				preg_match_all( '/([.#])([A-Za-z_][A-Za-z0-9_\-]*)/', $m[2], $mm, PREG_SET_ORDER );
				foreach ( $mm as $x ) {
					if ( $x[1] === '#' ) {
						if ( $id !== '' ) return null; // dos ids: no puede coincidir
						$id = $x[2];
					} else {
						$cls[] = $x[2];
					}
				}
			}
			$spec += ( $id !== '' ? 10000 : 0 ) + count( $cls ) * 100 + ( $tag !== '*' ? 1 : 0 );
			$parts[] = [ 'comb' => $comb, 'tag' => $tag, 'id' => $id, 'classes' => $cls ];
			$comb    = ' ';
		}
		if ( ! $parts || $comb === '>' ) return null;
		return [ 'parts' => $parts, 'spec' => $spec ];
	}

	/** Compara dos prioridades [important, en_línea, especificidad, orden]. */
	protected static function cmp( array $a, array $b ) {
		for ( $i = 0; $i < 4; $i++ ) {
			if ( $a[ $i ] !== $b[ $i ] ) return $a[ $i ] < $b[ $i ] ? -1 : 1;
		}
		return 0;
	}
}
