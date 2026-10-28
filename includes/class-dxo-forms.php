<?php
/**
 * Formularios de suscripción.
 *
 * Se guardan en una opción (`dxo_forms`), uno por slug. Cada formulario sale
 * en un sitio:
 * - inline: donde se ponga el shortcode [dox_orbit form="slug"] o el widget de Elementor.
 * - popup:  ventana emergente al bajar un porcentaje de la página o tras unos segundos.
 * - bar:    barra fija abajo, que se puede cerrar.
 * Las ventanas y barras se pintan solas en el pie de la web si están encendidas.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DXO_Forms {

	const OPTION = 'dxo_forms';

	private static $assets = false;

	public static function init() {
		add_shortcode( 'dox_orbit', [ __CLASS__, 'shortcode' ] );
		// Antes de la prioridad 20, que es cuando WordPress imprime los scripts del pie:
		// si no, el JS de la ventana se encolaría tarde y no saldría nunca.
		add_action( 'wp_footer', [ __CLASS__, 'footer' ], 5 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_assets' ] );
	}

	public static function defaults() {
		return [
			'slug'          => 'main',
			'name'          => __( 'Main form', 'dox-orbit' ),
			'title'         => __( 'One email a month, no filler', 'dox-orbit' ),
			'text'          => __( 'What we learn and what we publish. You can unsubscribe with one click.', 'dox-orbit' ),
			'button'        => __( 'Subscribe', 'dox-orbit' ),
			'placeholder'   => __( 'you@email.com', 'dox-orbit' ),
			'name_label'    => __( 'Your name', 'dox-orbit' ),
			'list_id'       => 0,
			'ask_name'      => 0,
			'show_count'    => 1,
			'style'         => 'dark',   // dark | light
			'placement'     => 'inline', // inline | popup | bar
			'enabled'       => 1,        // para ventana y barra: si se pintan solas o no
			'trigger'       => 'scroll', // scroll | delay
			'trigger_value' => 60,       // % de la página o segundos
			'where'         => 'posts',  // posts | all
			'success_text'  => __( 'Almost done! Check your inbox and confirm with one click.', 'dox-orbit' ),
			'done_text'     => __( 'Done! You are subscribed.', 'dox-orbit' ),
		];
	}

	public static function all() {
		$forms = get_option( self::OPTION, [] );
		if ( ! is_array( $forms ) || ! $forms ) {
			$forms = [ 'main' => self::defaults() ];
		}
		foreach ( $forms as $slug => $f ) {
			$forms[ $slug ] = wp_parse_args( $f, self::defaults() );
			$forms[ $slug ]['slug'] = $slug;
			if ( ! (int) $forms[ $slug ]['list_id'] ) $forms[ $slug ]['list_id'] = DXO_Lists::default_id();
		}
		return $forms;
	}

	public static function get( $slug ) {
		$all = self::all();
		if ( $slug === '' ) return reset( $all );
		return $all[ $slug ] ?? null;
	}

	public static function save( array $form ) {
		$all  = self::all();
		$slug = sanitize_key( $form['slug'] ?? '' );
		if ( $slug === '' ) {
			$slug = sanitize_key( sanitize_title( $form['name'] ?? 'form' ) ) ?: 'form';
			$base = $slug;
			$i    = 2;
			while ( isset( $all[ $slug ] ) ) $slug = $base . '-' . $i++;
		}
		$d   = self::defaults();
		$out = [ 'slug' => $slug ];
		foreach ( $d as $k => $v ) {
			if ( $k === 'slug' ) continue;
			$val = $form[ $k ] ?? ( $all[ $slug ][ $k ] ?? $v );
			if ( is_int( $v ) ) $val = (int) $val;
			elseif ( in_array( $k, [ 'text', 'success_text', 'done_text' ], true ) ) $val = sanitize_textarea_field( $val );
			else $val = sanitize_text_field( $val );
			$out[ $k ] = $val;
		}
		if ( ! in_array( $out['style'], [ 'dark', 'light' ], true ) ) $out['style'] = 'dark';
		if ( ! in_array( $out['placement'], [ 'inline', 'popup', 'bar' ], true ) ) $out['placement'] = 'inline';
		if ( ! in_array( $out['trigger'], [ 'scroll', 'delay' ], true ) ) $out['trigger'] = 'scroll';
		if ( ! in_array( $out['where'], [ 'posts', 'all' ], true ) ) $out['where'] = 'posts';
		$out['trigger_value'] = max( 1, min( $out['trigger'] === 'scroll' ? 100 : 300, $out['trigger_value'] ) );

		$all[ $slug ] = $out;
		update_option( self::OPTION, $all, false );
		return $out;
	}

	public static function delete( $slug ) {
		$all = self::all();
		if ( count( $all ) < 2 ) return false; // siempre queda uno
		unset( $all[ $slug ] );
		update_option( self::OPTION, $all, false );
		return true;
	}

	/** Cuántos suscriptores ha traído cada formulario en los últimos $days días. */
	public static function signups( $days = 30 ) {
		global $wpdb;
		$out = [];
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT source, COUNT(*) n FROM ' . DXO_Install::table( 'subscribers' ) . ' WHERE created_at >= %s GROUP BY source ORDER BY n DESC',
			gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
		), ARRAY_A ) ?: [];
		foreach ( $rows as $r ) $out[ $r['source'] ] = (int) $r['n'];
		return $out;
	}

	// ═══ Pintar ═════════════════════════════════════════════════════════════

	public static function register_assets() {
		wp_register_style( 'dxo-public', DXO_URL . 'assets/public.css', [], dxo_asset_ver( 'public.css' ) );
		wp_register_script( 'dxo-public', DXO_URL . 'assets/public.js', [], dxo_asset_ver( 'public.js' ), true );
	}

	private static function enqueue() {
		if ( self::$assets ) return;
		self::$assets = true;
		if ( ! wp_style_is( 'dxo-public', 'registered' ) ) self::register_assets();
		wp_enqueue_style( 'dxo-public' );
		wp_enqueue_script( 'dxo-public' );
		wp_localize_script( 'dxo-public', 'DXO', [
			'url'   => DXO_Public::url( 'subscribe' ),
			'error' => __( 'Something went wrong. Try again.', 'dox-orbit' ),
		] );
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( [ 'form' => '' ], $atts, 'dox_orbit' );
		$form = self::get( sanitize_key( $atts['form'] ) );
		if ( ! $form ) return '';
		self::enqueue();
		return self::render( $form, 'inline' );
	}

	/** Ventanas y barras encendidas, en el pie de la web. */
	public static function footer() {
		if ( is_admin() || is_feed() ) return;
		foreach ( self::all() as $form ) {
			if ( $form['placement'] === 'inline' || ! $form['enabled'] ) continue;
			if ( $form['where'] === 'posts' && ! is_singular( 'post' ) ) continue;
			self::enqueue();
			// El CSS ya no llega al <head> a estas alturas: se imprime aquí.
			wp_print_styles( 'dxo-public' );
			echo self::render( $form, $form['placement'] ); // phpcs:ignore -- render() escapa
		}
	}

	/**
	 * El HTML de un formulario. $mode: inline | popup | bar, o preview y
	 * preview-bar (la vista previa del panel: sin <form> ni nombres de campo).
	 */
	public static function render( array $f, $mode = 'inline' ) {
		$slug   = esc_attr( $f['slug'] );
		$count  = '';
		if ( $f['show_count'] ) {
			$n = DXO_Stats::active_count();
			if ( $n >= 100 ) {
				/* translators: %s: number of subscribers */
				$count = '<p class="dxo-proof"><span class="dxo-stack" aria-hidden="true"><i></i><i></i><i></i></span>' . esc_html( sprintf( __( 'Join %s subscribers', 'dox-orbit' ), dxo_num( $n ) ) ) . '</p>';
			}
		}

		// Resultado sin JavaScript (vuelta de la redirección).
		$state = '';
		if ( isset( $_GET['dxo_f'], $_GET['dxo_s'] ) && sanitize_key( $_GET['dxo_f'] ) === $f['slug'] ) {
			$s     = sanitize_key( $_GET['dxo_s'] );
			$msg   = $s === 'pending' ? $f['success_text'] : ( $s === 'active' ? $f['done_text'] : ( $s === 'already' ? __( 'You were already subscribed. Thanks!', 'dox-orbit' ) : __( 'That email does not look right. Check it and try again.', 'dox-orbit' ) ) );
			$state = '<p class="dxo-msg' . ( in_array( $s, [ 'pending', 'active', 'already' ], true ) ? ' is-ok' : ' is-error' ) . '" role="status">' . esc_html( $msg ) . '</p>';
		}

		$pv   = in_array( $mode, [ 'preview', 'preview-bar' ], true );
		$name = $f['ask_name']
			? '<label class="dxo-sr" for="dxo-name-' . $slug . '-' . $mode . '">' . esc_html( $f['name_label'] ) . '</label><input id="dxo-name-' . $slug . '-' . $mode . '" type="text"' . ( $pv ? '' : ' name="first_name"' ) . ' autocomplete="given-name" placeholder="' . esc_attr( $f['name_label'] ) . '">'
			: '';

		// En la vista previa del panel no puede ser un <form>: va dentro del formulario
		// de edición, y un <form> dentro de otro cierra el de fuera antes de tiempo.
		$tag  = in_array( $mode, [ 'preview', 'preview-bar' ], true ) ? 'div' : 'form';
		$form = '<' . $tag . ' class="dxo-form"' . ( $tag === 'form' ? ' method="post" action="' . esc_url( DXO_Public::url( 'subscribe' ) ) . '" novalidate' : '' ) . '>'
			. ( $pv ? '' : '<input type="hidden" name="dxo" value="subscribe"><input type="hidden" name="dxo_form" value="' . $slug . '">'
				. '<input type="hidden" name="dxo_ts" value="' . time() . '">'
				// Polylang: el formulario manda a la portada sin /en/, así que el idioma de la página viaja aquí.
				. ( function_exists( 'pll_current_language' ) && (string) pll_current_language() !== '' ? '<input type="hidden" name="dxo_lang" value="' . esc_attr( (string) pll_current_language() ) . '">' : '' )
				. '<div class="dxo-hp" aria-hidden="true"><input type="text" name="dxo_hp" tabindex="-1" autocomplete="off"></div>' )
			. '<div class="dxo-fields' . ( $f['ask_name'] ? ' has-name' : '' ) . '">' . $name
			. '<label class="dxo-sr" for="dxo-email-' . $slug . '-' . $mode . '">' . esc_html__( 'Email', 'dox-orbit' ) . '</label>'
			. '<input id="dxo-email-' . $slug . '-' . $mode . '" type="email"' . ( $pv ? '' : ' name="email"' ) . ' required autocomplete="email" inputmode="email" placeholder="' . esc_attr( $f['placeholder'] ) . '">'
			. '<button type="' . ( $pv ? 'button' : 'submit' ) . '">' . esc_html( $f['button'] ) . '</button></div>'
			. '<p class="dxo-msg" role="status" hidden></p>'
			. '</' . $tag . '>';

		$accent = DXO_Settings::brand()['accent'];
		$vars   = ' style="--dxo-accent:' . esc_attr( $accent ) . ';--dxo-on:' . esc_attr( DXO_Renderer::text_on( $accent ) ) . '"';

		$body = '<div class="dxo-box dxo-' . esc_attr( $f['style'] ) . '"' . $vars . '>'
			. ( $f['title'] !== '' ? '<p class="dxo-title">' . esc_html( $f['title'] ) . '</p>' : '' )
			. ( $f['text'] !== '' ? '<p class="dxo-text">' . esc_html( $f['text'] ) . '</p>' : '' )
			. ( $state ?: $form ) . $count . '</div>';

		if ( $mode === 'popup' ) {
			return '<div class="dxo-popup" id="dxo-' . $slug . '" data-dxo-slug="' . $slug . '" data-trigger="' . esc_attr( $f['trigger'] ) . '" data-value="' . (int) $f['trigger_value'] . '" hidden>'
				. '<div class="dxo-veil" data-dxo-close></div><div class="dxo-dialog" role="dialog" aria-modal="true" aria-label="' . esc_attr( $f['title'] ) . '">'
				. '<button type="button" class="dxo-close" data-dxo-close aria-label="' . esc_attr__( 'Close', 'dox-orbit' ) . '">&times;</button>' . $body . '</div></div>';
		}
		if ( $mode === 'bar' || $mode === 'preview-bar' ) {
			return '<div class="dxo-bar dxo-' . esc_attr( $f['style'] ) . '" id="dxo-' . $slug . '" data-dxo-slug="' . $slug . '"' . $vars . ' hidden>'
				. '<div class="dxo-bar-in"><p class="dxo-bar-title">' . esc_html( $f['title'] ) . '</p>' . $form
				. '<button type="button" class="dxo-close" data-dxo-close aria-label="' . esc_attr__( 'Close', 'dox-orbit' ) . '">&times;</button></div></div>';
		}
		return '<div class="dxo-inline" id="dxo-' . $slug . '">' . $body . '</div>';
	}
}
