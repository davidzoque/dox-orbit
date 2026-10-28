<?php
/**
 * El panel en wp-admin.
 *
 * Una sola página (Dox Plugins > Orbit) con su propia navegación:
 * ?page=dox-orbit&view=dashboard|campaigns|edit|subscribers|forms|report|settings.
 * Las acciones van por admin-ajax (dxo_*), todas con nonce y manage_options.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DXO_Admin {

	const SLUG = 'dox-orbit';
	const CAP  = 'manage_options';

	const VIEWS = [ 'dashboard', 'campaigns', 'edit', 'subscribers', 'forms', 'report', 'settings' ];

	public static function init() {
		add_action( 'dox_core_register', [ __CLASS__, 'register_in_core' ] );
		add_action( 'admin_menu', [ __CLASS__, 'fallback_menu' ], 20 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_filter( 'admin_body_class', [ __CLASS__, 'body_class' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( DXO_FILE ), [ __CLASS__, 'action_links' ] );

		$ajax = [
			'save_campaign', 'preview', 'send_test', 'launch', 'unschedule', 'pause', 'resume', 'cancel',
			'delete_campaign', 'duplicate', 'resend', 'welcome_toggle', 'status',
			'save_subscriber', 'delete_subscriber', 'subscriber_status', 'import', 'resend_confirm',
			'save_list', 'delete_list', 'save_form', 'delete_form', 'form_preview',
			'save_settings', 'settings_test', 'bounce_test', 'posts',
		];
		foreach ( $ajax as $a ) {
			add_action( 'wp_ajax_dxo_' . $a, [ __CLASS__, 'ajax_' . $a ] );
		}
		add_action( 'admin_post_dxo_export', [ __CLASS__, 'export' ] );
		add_action( 'admin_post_dxo_new_campaign', [ __CLASS__, 'new_campaign' ] );
	}

	// ═══ Menú ═══════════════════════════════════════════════════════════════

	public static function register_in_core( $core ) {
		$core->register_plugin( [
			'slug'    => self::SLUG,
			'name'    => 'Orbit',
			'version' => DXO_VERSION,
			'summary' => __( 'Newsletters and email campaigns from your own WordPress.', 'dox-orbit' ),
			'page'    => [
				'menu_title' => 'Orbit',
				'page_title' => 'Dox Orbit',
				'callback'   => [ __CLASS__, 'render' ],
			],
		] );
	}

	/** Sin dox-core (carpeta a medias), el plugin cuelga su propio menú. */
	public static function fallback_menu() {
		if ( function_exists( 'dox_core' ) ) return;
		add_menu_page( 'Dox Orbit', 'Orbit', self::CAP, self::SLUG, [ __CLASS__, 'render' ], 'dashicons-email-alt', 58.95 );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open', 'dox-orbit' ) . '</a>' );
		return $links;
	}

	/**
	 * Los atributos de cada botón "New campaign": sin JavaScript crea una de bloques;
	 * con él, el panel pregunta primero si es de bloques o de código (admin.js).
	 * Ya vienen escapados.
	 */
	public static function new_campaign_attrs() {
		$blocks = wp_nonce_url( admin_url( 'admin-post.php?action=dxo_new_campaign' ), 'dxo_new' );
		// Sin la extensión DOM de PHP no se puede limpiar HTML: solo bloques.
		if ( ! DXO_Sanitizer::available() ) return 'href="' . esc_url( $blocks ) . '"';
		$html   = wp_nonce_url( admin_url( 'admin-post.php?action=dxo_new_campaign&format=html' ), 'dxo_new' );
		return 'href="' . esc_url( $blocks ) . '" data-new-campaign data-blocks-url="' . esc_url( $blocks ) . '" data-html-url="' . esc_url( $html ) . '"';
	}

	public static function url( $view = 'dashboard', array $args = [] ) {
		$base = function_exists( 'dox_core' ) ? 'admin.php' : 'admin.php';
		return add_query_arg( array_merge( [ 'page' => self::SLUG, 'view' => $view ], $args ), admin_url( $base ) );
	}

	private static function is_our_page() {
		return isset( $_GET['page'] ) && $_GET['page'] === self::SLUG; // phpcs:ignore
	}

	public static function body_class( $classes ) {
		return self::is_our_page() ? $classes . ' dxo-screen' : $classes;
	}

	public static function current_view() {
		$v = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'dashboard'; // phpcs:ignore
		return in_array( $v, self::VIEWS, true ) ? $v : 'dashboard';
	}

	// ═══ Recursos ═══════════════════════════════════════════════════════════

	public static function assets() {
		if ( ! self::is_our_page() ) return;
		wp_enqueue_style( 'dxo-inter', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap', [], null );
		wp_enqueue_style( 'dxo-admin', DXO_URL . 'assets/admin.css', [], dxo_asset_ver( 'admin.css' ) );

		$view = self::current_view();
		if ( in_array( $view, [ 'edit', 'settings' ], true ) ) wp_enqueue_media();

		// El editor de código que trae WordPress (CodeMirror) para el bloque HTML y las
		// campañas de código. Devuelve false si la persona lo apagó en su perfil: entonces
		// el campo queda como una caja de texto monoespaciada.
		$code = false;
		$deps = [];
		if ( $view === 'edit' ) {
			$code = wp_enqueue_code_editor( [
				'type'       => 'text/html',
				'codemirror' => [ 'lint' => false, 'gutters' => [], 'lineNumbers' => true, 'lineWrapping' => true, 'indentUnit' => 2, 'tabSize' => 2, 'indentWithTabs' => false ],
			] );
			if ( $code ) $deps[] = 'code-editor';
		}
		wp_enqueue_script( 'dxo-admin', DXO_URL . 'assets/admin.js', $deps, dxo_asset_ver( 'admin.js' ), true );
		if ( $view === 'forms' ) {
			wp_enqueue_style( 'dxo-public', DXO_URL . 'assets/public.css', [], dxo_asset_ver( 'public.css' ) );
		}

		wp_localize_script( 'dxo-admin', 'DXO', [
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'dxo' ),
			'view'  => $view,
			'rate'  => (int) DXO_Settings::get( 'rate_per_hour' ),
			'codeEditor' => $code,
			'urls'  => [
				'campaigns' => self::url( 'campaigns' ),
				'edit'      => self::url( 'edit' ),
				'report'    => self::url( 'report' ),
				'dashboard' => self::url( 'dashboard' ),
			],
			'i18n'  => [
				'saved'        => __( 'Saved', 'dox-orbit' ),
				'saving'       => __( 'Saving…', 'dox-orbit' ),
				'unsaved'      => __( 'Unsaved changes', 'dox-orbit' ),
				'error'        => __( 'Something went wrong. Try again.', 'dox-orbit' ),
				'sent_test'    => __( 'Test sent to %s', 'dox-orbit' ),
				'copied'       => __( 'Copied', 'dox-orbit' ),
				'confirm'      => __( 'Confirm', 'dox-orbit' ),
				'cancel'       => __( 'Cancel', 'dox-orbit' ),
				'delete'       => __( 'Delete', 'dox-orbit' ),
				'choose_image' => __( 'Choose an image', 'dox-orbit' ),
				'use_image'    => __( 'Use this image', 'dox-orbit' ),
				'leave'        => __( 'There are unsaved changes.', 'dox-orbit' ),
				'chars'        => __( '%d characters', 'dox-orbit' ),
				'block'        => [
					'heading' => __( 'Title', 'dox-orbit' ),
					'text'    => __( 'Text', 'dox-orbit' ),
					'image'   => __( 'Image', 'dox-orbit' ),
					'button'  => __( 'Button', 'dox-orbit' ),
					'post'    => __( 'Blog post', 'dox-orbit' ),
					'divider' => __( 'Divider', 'dox-orbit' ),
					'spacer'  => __( 'Space', 'dox-orbit' ),
					'html'    => __( 'HTML', 'dox-orbit' ),
				],
				'new_title'    => __( 'New campaign', 'dox-orbit' ),
				'new_blocks'   => __( 'Design with blocks', 'dox-orbit' ),
				'new_blocks_d' => __( 'Add titles, text, images and buttons and see the email as you go. The easy way.', 'dox-orbit' ),
				'new_html'     => __( 'Paste HTML code', 'dox-orbit' ),
				'new_html_d'   => __( 'Bring an email you already have (from a designer, an HTML template or another tool) and send it as it is.', 'dox-orbit' ),
				'read_more'    => __( 'Read more', 'dox-orbit' ),
				'no_posts'     => __( 'No posts found', 'dox-orbit' ),
				'empty_block'  => __( '(empty)', 'dox-orbit' ),
			],
		] );
	}

	// ═══ Pintar ═════════════════════════════════════════════════════════════

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) return;
		$view = self::current_view();
		include DXO_PATH . 'admin/views/layout.php';
	}

	/**
	 * El logo "dox orbit": el isotipo y el "dox" del logo de Dox Studio tal cual
	 * (del lockup "dox plugins" que dibujó David) y "orbit" en Poppins Medium
	 * naranja (el peso exacto de su "plugins"), con la misma escala, línea de base y espaciado, ya en curvas.
	 */
	public static function logo() {
		$file = DXO_PATH . 'assets/logo-orbit.svg';
		if ( ! file_exists( $file ) ) return '<b>Dox Orbit</b>';
		return str_replace( '<svg ', '<svg class="dxo-logo" ', (string) file_get_contents( $file ) );
	}

	/** Un icono de línea (los de la maqueta), por nombre. */
	public static function icon( $name ) {
		$p = [
			'pulse'    => '<path d="M3 12h4l3 8 4-16 3 8h4"/>',
			'send'     => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
			'pen'      => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
			'users'    => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M17 4a4 4 0 0 1 0 8M22 21a7 7 0 0 0-4-6.3"/>',
			'form'     => '<rect x="3" y="4" width="18" height="16" rx="3"/><path d="M7 9h10M7 13h6"/>',
			'chart'    => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
			'gear'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
			'plus'     => '<path d="M12 5v14M5 12h14"/>',
			'download' => '<path d="M12 3v12M7 10l5 5 5-5"/><path d="M5 21h14"/>',
			'upload'   => '<path d="M12 15V3M7 8l5-5 5 5"/><path d="M5 21h14"/>',
			'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
			'pause'    => '<path d="M8 5v14M16 5v14"/>',
			'play'     => '<path d="M7 4v16l13-8z"/>',
			'more'     => '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
			'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
			'info'     => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
			'check'    => '<path d="m5 12 5 5 9-10"/>',
			'warn'     => '<path d="M12 8v5M12 17h.01"/><circle cx="12" cy="12" r="9"/>',
			'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
			'cloud'    => '<path d="M17.5 19a4.5 4.5 0 1 0-1.4-8.8A6 6 0 1 0 6 16.5"/><path d="M6 19h11.5"/>',
			'refresh'  => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
			'trash'    => '<path d="M4 7h16M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13M9 7V4h6v3"/>',
			'copy'     => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
			'x'        => '<path d="M6 6l12 12M18 6 6 18"/>',
			'eye'      => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
			'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
			'list'     => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
			'grip'     => '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>',
			'up'       => '<path d="m6 15 6-6 6 6"/>',
			'down'     => '<path d="m6 9 6 6 6-6"/>',
			'heading'  => '<path d="M4 7V5h16v2M9 19h6M12 5v14"/>',
			'text'     => '<path d="M4 6h16M4 11h16M4 16h10"/>',
			'image'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-8 8"/>',
			'button'   => '<rect x="4" y="8" width="16" height="8" rx="4"/>',
			'post'     => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
			'divider'  => '<path d="M4 12h16"/>',
			'spacer'   => '<path d="M12 4v16M8 8l4-4 4 4M8 16l4 4 4-4"/>',
		];
		return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ( $p[ $name ] ?? '' ) . '</svg>';
	}

	/**
	 * Gráfica de área en SVG, pintada en el servidor: sale aunque el JS no cargue.
	 *
	 * @param array  $values números
	 * @param array  $labels [ índice => texto ] para el eje de abajo
	 * @param string $color  color de la línea
	 * @param bool   $zero   el eje empieza en 0 (si no, se ajusta a los datos)
	 */
	public static function area_chart( array $values, array $labels, $color, $zero = false ) {
		$values = array_values( $values );
		$n      = count( $values );
		if ( $n < 2 ) return '';
		$W = 700; $H = 190; $p = 8;
		$hi  = max( $values );
		$lo  = min( $values );
		$pad = max( 1, ( $hi - $lo ) * .25 );
		$max = $hi + $pad;
		$min = $zero ? 0 : max( 0, $lo - $pad );
		if ( $max <= $min ) $max = $min + 1;
		$x = function ( $i ) use ( $W, $p, $n ) { return $p + $i * ( $W - 2 * $p ) / ( $n - 1 ); };
		$y = function ( $v ) use ( $H, $min, $max ) { return $H - 8 - ( $v - $min ) / ( $max - $min ) * ( $H - 24 ); };

		$d = '';
		foreach ( $values as $i => $v ) $d .= ( $i ? 'L' : 'M' ) . round( $x( $i ), 1 ) . ' ' . round( $y( $v ), 1 ) . ' ';
		$id   = 'g' . wp_rand( 1000, 99999 );
		$grid = '';
		for ( $k = 0; $k < 4; $k++ ) {
			$yy    = 16 + $k * ( $H - 40 ) / 3;
			$grid .= '<line x1="0" x2="' . $W . '" y1="' . $yy . '" y2="' . $yy . '" stroke="#F4F4F5"/>';
		}
		// Las fechas van en HTML debajo y no dentro del SVG: el SVG se estira a lo ancho
		// (preserveAspectRatio none) y el texto saldría deformado en el móvil.
		$axis = '';
		foreach ( $labels as $i => $l ) {
			$pos   = round( $x( $i ) / $W * 100, 2 );
			$shift = $i === 0 ? '0' : ( $i === $n - 1 ? '-100%' : '-50%' );
			$axis .= '<span style="left:' . $pos . '%;transform:translateX(' . $shift . ')">' . esc_html( $l ) . '</span>';
		}
		$last = $n - 1;
		return '<svg class="dxo-chart" viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none" role="img">'
			. '<defs><linearGradient id="' . $id . '" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="' . esc_attr( $color ) . '" stop-opacity=".22"/><stop offset="1" stop-color="' . esc_attr( $color ) . '" stop-opacity="0"/></linearGradient></defs>'
			. $grid
			. '<path d="' . $d . 'L' . round( $x( $last ), 1 ) . ' ' . ( $H - 8 ) . ' L' . round( $x( 0 ), 1 ) . ' ' . ( $H - 8 ) . 'Z" fill="url(#' . $id . ')"/>'
			. '<path class="dxo-line" d="' . trim( $d ) . '" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" pathLength="1"/>'
			. '<circle cx="' . round( $x( $last ), 1 ) . '" cy="' . round( $y( $values[ $last ] ), 1 ) . '" r="4.5" fill="#fff" stroke="' . esc_attr( $color ) . '" stroke-width="2.4" vector-effect="non-scaling-stroke"/>'
			. '</svg><div class="dxo-axis">' . $axis . '</div>';
	}

	/** Lo que necesita la franja de estado para ponerse al día. */
	public static function status_payload() {
		$st = DXO_Sender::status();
		if ( ! $st ) return [ 'active' => false ];
		return [
			'active' => true,
			'id'     => $st['id'],
			'paused' => $st['status'] === 'paused',
			'title'  => sprintf( $st['status'] === 'paused' ? __( 'Paused «%s»', 'dox-orbit' ) : __( 'Sending «%s»', 'dox-orbit' ), $st['subject'] ),
			'count'  => sprintf( __( '%1$s of %2$s', 'dox-orbit' ), dxo_num( $st['done'] ), dxo_num( $st['total'] ) ),
			'pct'    => $st['total'] ? round( $st['done'] * 100 / $st['total'] ) : 0,
			'meta'   => self::status_meta( $st ),
			'label'  => $st['status'] === 'paused' ? __( 'Resume', 'dox-orbit' ) : __( 'Pause', 'dox-orbit' ),
		];
	}

	/** "Sale a 150 por hora para dejar sitio a los correos de la web · termina hoy hacia las 18:40" */
	public static function status_meta( array $st ) {
		if ( $st['status'] === 'paused' ) {
			return sprintf( __( '%s left. Nothing goes out until you resume it.', 'dox-orbit' ), dxo_num( $st['left'] ) );
		}
		$rate = sprintf( __( 'Going out at %s per hour to leave room for the website emails', 'dox-orbit' ), dxo_num( $st['rate'] ) );
		if ( ! $st['left'] ) return $rate;
		$when = $st['eta_day'] === 'today'
			? sprintf( __( 'finishes today around %s', 'dox-orbit' ), $st['eta'] )
			: sprintf( __( 'finishes %1$s around %2$s', 'dox-orbit' ), $st['eta_day'], $st['eta'] );
		return $rate . ' · ' . $when;
	}

	/** La pastilla de estado de una campaña. */
	public static function status_pill( array $c, $st = null ) {
		$map = [
			'draft'     => [ 'p-gray', __( 'Draft', 'dox-orbit' ) ],
			'scheduled' => [ 'p-info', __( 'Scheduled', 'dox-orbit' ) ],
			'sending'   => [ 'p-acc', __( 'Sending', 'dox-orbit' ) ],
			'paused'    => [ 'p-warn', __( 'Paused', 'dox-orbit' ) ],
			'sent'      => [ 'p-ok', __( 'Sent', 'dox-orbit' ) ],
			'cancelled' => [ 'p-gray', __( 'Cancelled', 'dox-orbit' ) ],
			'active'    => [ 'p-ok', __( 'On', 'dox-orbit' ) ],
			'inactive'  => [ 'p-gray', __( 'Off', 'dox-orbit' ) ],
		];
		$m     = $map[ $c['status'] ] ?? [ 'p-gray', $c['status'] ];
		$label = $m[1];
		if ( $c['status'] === 'sending' && $st && $st['total'] ) {
			$label .= ' ' . round( ( $st['sent'] + $st['failed'] ) * 100 / $st['total'] ) . ' %';
		}
		return '<span class="dxo-pill ' . $m[0] . '"><span class="dot"></span>' . esc_html( $label ) . '</span>';
	}

	// ═══ AJAX: utilidades ═══════════════════════════════════════════════════

	private static function guard() {
		if ( ! current_user_can( self::CAP ) || ! check_ajax_referer( 'dxo', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Your session expired. Reload the page.', 'dox-orbit' ) ], 403 );
		}
	}

	private static function in( $key, $default = '' ) {
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default; // phpcs:ignore
	}

	private static function fail( $message, $extra = [] ) {
		wp_send_json_error( array_merge( [ 'message' => $message ], $extra ), 400 );
	}

	// ═══ AJAX: campañas ═════════════════════════════════════════════════════

	public static function new_campaign() {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'dxo_new' ) ) wp_die( 'Forbidden' );
		$id = DXO_Campaigns::create( [ 'format' => isset( $_GET['format'] ) && $_GET['format'] === 'html' && DXO_Sanitizer::available() ? 'html' : 'blocks' ] ); // phpcs:ignore -- el nonce se comprueba arriba.
		wp_safe_redirect( self::url( 'edit', [ 'id' => $id ] ) );
		exit;
	}

	/** El código HTML que manda el editor: en base64 (html_b64, para que ningún cortafuegos lo confunda con un ataque) o en claro. */
	private static function posted_html() {
		if ( isset( $_POST['html_b64'] ) ) { // phpcs:ignore
			$dec = base64_decode( (string) self::in( 'html_b64' ), true );
			if ( $dec === false ) self::fail( __( 'Something went wrong. Try again.', 'dox-orbit' ) );
			return $dec;
		}
		return (string) self::in( 'html' );
	}

	public static function ajax_save_campaign() {
		self::guard();
		// Solo se guarda lo que llega: el editor de un formato no manda el contenido del otro.
		$data = [];
		foreach ( [ 'subject', 'preheader', 'format' ] as $k ) {
			if ( isset( $_POST[ $k ] ) ) $data[ $k ] = self::in( $k );
		}
		if ( isset( $_POST['blocks'] ) ) $data['blocks'] = json_decode( (string) self::in( 'blocks', '[]' ), true ) ?: [];
		if ( isset( $_POST['html'] ) || isset( $_POST['html_b64'] ) ) $data['html'] = self::posted_html();
		if ( isset( $data['format'] ) && $data['format'] === 'html' && ! DXO_Sanitizer::available() ) self::fail( __( 'This server cannot clean HTML code (the PHP DOM extension is missing), so HTML campaigns are turned off.', 'dox-orbit' ) );
		if ( isset( $_POST['lists'] ) ) $data['lists'] = (array) self::in( 'lists', [] );
		$c = DXO_Campaigns::save( (int) self::in( 'id' ), $data );
		if ( is_wp_error( $c ) ) self::fail( $c->get_error_message() );
		wp_send_json_success( [ 'audience' => count( DXO_Campaigns::audience_ids( $c ) ), 'problems' => DXO_Campaigns::problems( $c ) ] );
	}

	/**
	 * Los avisos del código HTML en frases. No bloquean nada: solo cosas que
	 * conviene saber antes de enviar.
	 */
	public static function html_warning_texts( array $warnings ) {
		$out = [];
		foreach ( $warnings as $w ) {
			switch ( $w['code'] ) {
				case 'size':
					/* translators: %s: size in KB */
					$out[] = sprintf( __( 'The email weighs about %s KB. Gmail cuts off emails over 102 KB and hides the rest behind «View entire message».', 'dox-orbit' ), (int) $w['kb'] );
					break;
				case 'img_relative':
					/* translators: %s: number of images */
					$out[] = sprintf( _n( '%s image has a relative address (like /images/logo.png). In an email it needs the full address, starting with https://.', '%s images have a relative address (like /images/logo.png). In an email they need the full address, starting with https://.', (int) $w['n'], 'dox-orbit' ), (int) $w['n'] );
					break;
				case 'img_webp':
					/* translators: %s: number of images */
					$out[] = sprintf( _n( '%s image is WebP or AVIF. Outlook for Windows does not show it; use JPG or PNG.', '%s images are WebP or AVIF. Outlook for Windows does not show them; use JPG or PNG.', (int) $w['n'], 'dox-orbit' ), (int) $w['n'] );
					break;
				case 'img_http':
					/* translators: %s: number of images */
					$out[] = sprintf( _n( '%s image uses http:// and some email apps block it. Use https://.', '%s images use http:// and some email apps block them. Use https://.', (int) $w['n'], 'dox-orbit' ), (int) $w['n'] );
					break;
				case 'img_alt':
					/* translators: %s: number of images */
					$out[] = sprintf( _n( '%s image has no alt attribute. It is what is read when images are blocked.', '%s images have no alt attribute. It is what is read when images are blocked.', (int) $w['n'], 'dox-orbit' ), (int) $w['n'] );
					break;
				case 'css':
					/* translators: %s: list of CSS features */
					$out[] = sprintf( __( 'Many email apps ignore or break this CSS: %s. Tables work everywhere.', 'dox-orbit' ), implode( ', ', $w['items'] ) );
					break;
				case 'fonts':
					$out[] = __( 'Custom fonts only load in some email apps (Apple Mail, iPhone). Anywhere else the fallback font is used, so always add one.', 'dox-orbit' );
					break;
				case 'rules':
					/* translators: %s: number of CSS rules */
					$out[] = sprintf( __( 'The code has too many CSS rules: %s were left out of the inlining and stay in the style tag.', 'dox-orbit' ), (int) $w['n'] );
					break;
				case 'removed':
					/* translators: %s: list of removed HTML tags */
					$out[] = sprintf( __( 'Removed from the email because email apps do not allow it: %s.', 'dox-orbit' ), implode( ', ', $w['items'] ) );
					break;
			}
		}
		return $out;
	}

	/** La vista previa: el mismo HTML que se envía, con los datos de quien la mira. */
	public static function ajax_preview() {
		self::guard();
		$user   = wp_get_current_user();
		$c      = DXO_Campaigns::get( (int) self::in( 'id' ) ) ?: [];
		$format = self::in( 'format', $c['format'] ?? 'blocks' ) === 'html' ? 'html' : 'blocks';
		$blocks = DXO_Renderer::normalize_blocks( json_decode( (string) self::in( 'blocks', '[]' ), true ) ?: [] );
		$html   = self::posted_html();
		if ( strlen( $html ) > DXO_Html_Email::MAX_BYTES ) self::fail( __( 'The HTML code is too large. Over 1 MB cannot be a good email.', 'dox-orbit' ) );
		// Si la campaña tiene idioma, la vista previa saca los textos fijos en ese idioma.
		$entered = DXO_Campaigns::enter_lang( DXO_Campaigns::render_lang( $c ) );
		try {
			$brand = DXO_Settings::brand();
			if ( $c && DXO_Campaigns::is_secondary_lang( $c ) ) $brand['why'] = DXO_Settings::default_why();
			$strings = DXO_Public::email_strings();
		} finally {
			DXO_Campaigns::leave_lang( $entered );
		}
		$render = DXO_Renderer::render_email( [
			'preview'         => true, // lo preparado no se guarda en la base: una vista previa por tecla
			'format'          => $format,
			'blocks'          => $blocks,
			'html'            => $html,
			'brand'           => $brand,
			'subject'         => (string) self::in( 'subject' ),
			'preheader'       => (string) self::in( 'preheader' ),
			'fields'          => [ 'first_name' => $user->first_name ?: 'Ana', 'last_name' => $user->last_name, 'email' => $user->user_email ],
			'unsubscribe_url' => '#',
			'view_url'        => '#',
			'strings'         => $strings,
		] );
		$out = [ 'html' => $render['html'] ];
		if ( $format !== 'html' ) {
			$n = DXO_Html_Email::modern_images_in_blocks( $blocks );
			$out['warnings'] = $n ? self::html_warning_texts( [ [ 'code' => 'img_webp', 'n' => $n ] ] ) : [];
		}
		if ( $format === 'html' ) {
			$out['warnings']  = self::html_warning_texts( DXO_Html_Email::warnings( $html ) );
			$out['own_unsub'] = DXO_Html_Email::own_unsubscribe( $html );
		}
		wp_send_json_success( $out );
	}

	public static function ajax_send_test() {
		self::guard();
		$c  = DXO_Campaigns::get( (int) self::in( 'id' ) );
		$to = sanitize_email( self::in( 'to' ) );
		if ( ! $c ) self::fail( __( 'That campaign no longer exists.', 'dox-orbit' ) );
		if ( ! is_email( $to ) ) self::fail( __( 'Write a valid email for the test.', 'dox-orbit' ) );
		update_user_meta( get_current_user_id(), 'dxo_test_to', $to );
		$err = DXO_Sender::send_test( $c, $to );
		if ( $err !== null ) self::fail( $err );
		global $wpdb;
		$wpdb->update( DXO_Install::table( 'campaigns' ), [ 'test_sent_at' => dxo_now() ], [ 'id' => $c['id'] ] );
		wp_send_json_success();
	}

	public static function ajax_launch() {
		self::guard();
		$when = (string) self::in( 'when' );
		$utc  = null;
		if ( $when !== '' ) {
			// Llega en la hora del sitio (input datetime-local): se pasa a UTC.
			try {
				$dt  = new DateTime( $when, wp_timezone() );
				$utc = $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			} catch ( Exception $e ) {
				self::fail( __( 'That date is not valid.', 'dox-orbit' ) );
			}
			if ( strtotime( $utc . ' UTC' ) < time() ) self::fail( __( 'That time has already passed.', 'dox-orbit' ) );
		}
		$c = DXO_Campaigns::launch( (int) self::in( 'id' ), $utc );
		if ( is_wp_error( $c ) ) self::fail( $c->get_error_message(), [ 'problems' => $c->get_error_data() ] );
		wp_send_json_success( [ 'status' => $c['status'], 'redirect' => $c['status'] === 'scheduled' ? self::url( 'campaigns' ) : self::url( 'dashboard' ) ] );
	}

	public static function ajax_unschedule() {
		self::guard();
		DXO_Campaigns::unschedule( (int) self::in( 'id' ) );
		wp_send_json_success();
	}

	public static function ajax_pause() {
		self::guard();
		global $wpdb;
		$wpdb->update( DXO_Install::table( 'campaigns' ), [ 'status' => 'paused' ], [ 'id' => (int) self::in( 'id' ), 'status' => 'sending' ] );
		wp_send_json_success( self::status_payload() );
	}

	public static function ajax_resume() {
		self::guard();
		global $wpdb;
		$wpdb->update( DXO_Install::table( 'campaigns' ), [ 'status' => 'sending' ], [ 'id' => (int) self::in( 'id' ), 'status' => 'paused' ] );
		DXO_Sender::kick();
		wp_send_json_success( self::status_payload() );
	}

	public static function ajax_cancel() {
		self::guard();
		DXO_Campaigns::cancel( (int) self::in( 'id' ) );
		wp_send_json_success();
	}

	public static function ajax_delete_campaign() {
		self::guard();
		if ( ! DXO_Campaigns::delete( (int) self::in( 'id' ) ) ) self::fail( __( 'This campaign cannot be deleted while it is sending.', 'dox-orbit' ) );
		wp_send_json_success();
	}

	public static function ajax_duplicate() {
		self::guard();
		$id = DXO_Campaigns::duplicate( (int) self::in( 'id' ) );
		wp_send_json_success( [ 'redirect' => self::url( 'edit', [ 'id' => $id ] ) ] );
	}

	public static function ajax_resend() {
		self::guard();
		$id = DXO_Campaigns::resend( (int) self::in( 'id' ), (string) self::in( 'who', 'not_opened' ) );
		if ( ! $id ) self::fail( __( 'Only sent campaigns can be resent.', 'dox-orbit' ) );
		wp_send_json_success( [ 'redirect' => self::url( 'edit', [ 'id' => $id ] ) ] );
	}

	public static function ajax_welcome_toggle() {
		self::guard();
		$w  = DXO_Campaigns::get( (int) self::in( 'id' ) );
		if ( ! $w || $w['type'] !== 'welcome' ) self::fail( __( 'That welcome email no longer exists.', 'dox-orbit' ) );
		$on = (int) self::in( 'on' ) === 1;
		if ( $on ) {
			$p = DXO_Campaigns::problems( $w );
			if ( $p ) self::fail( implode( "\n", $p ) );
		}
		DXO_Campaigns::set_status( $w['id'], $on ? 'active' : 'inactive' );
		global $wpdb;
		$wpdb->update( DXO_Install::table( 'campaigns' ), [ 'finished_at' => null ], [ 'id' => $w['id'] ] );
		wp_send_json_success();
	}

	/**
	 * El panel pregunta cada 20 segundos cómo va el envío. De paso, si toca,
	 * manda una tanda: con el panel abierto el envío no depende de que alguien
	 * visite la web para que corra el cron.
	 */
	public static function ajax_status() {
		self::guard();
		DXO_Sender::run();
		wp_send_json_success( self::status_payload() );
	}

	// ═══ AJAX: suscriptores y listas ════════════════════════════════════════

	public static function ajax_save_subscriber() {
		self::guard();
		global $wpdb;
		$id    = (int) self::in( 'id' );
		$email = sanitize_email( self::in( 'email' ) );
		$first = sanitize_text_field( self::in( 'first_name' ) );
		$last  = sanitize_text_field( self::in( 'last_name' ) );
		$lists = array_map( 'intval', (array) self::in( 'lists', [] ) );
		if ( ! is_email( $email ) ) self::fail( __( 'That email does not look right.', 'dox-orbit' ) );

		$other = DXO_Subscribers::get_by_email( $email );
		if ( $other && (int) $other['id'] !== $id ) self::fail( __( 'There is already a subscriber with that email.', 'dox-orbit' ) );

		if ( $id ) {
			$wpdb->update( DXO_Install::table( 'subscribers' ), [ 'email' => strtolower( $email ), 'first_name' => $first, 'last_name' => $last ], [ 'id' => $id ] );
		} else {
			if ( ! self::in( 'consent' ) ) self::fail( __( 'Confirm that this person agreed to receive your emails.', 'dox-orbit' ) );
			$id = DXO_Subscribers::add_imported( strtolower( $email ), $first, $last, $lists, 'manual' );
		}
		DXO_Subscribers::set_lists( $id, $lists ?: [ DXO_Lists::default_id() ] );
		wp_send_json_success( [ 'id' => $id ] );
	}

	public static function ajax_delete_subscriber() {
		self::guard();
		DXO_Subscribers::delete( (int) self::in( 'id' ) );
		wp_send_json_success();
	}

	public static function ajax_subscriber_status() {
		self::guard();
		$status = sanitize_key( self::in( 'status' ) );
		// A mano solo se puede dar de baja o reactivar a quien rebotó: volver a
		// activar a alguien que se dio de baja él mismo no es nuestro.
		$sub = DXO_Subscribers::get( (int) self::in( 'id' ) );
		if ( ! $sub ) self::fail( __( 'That subscriber no longer exists.', 'dox-orbit' ) );
		if ( $status === 'active' && $sub['status'] === 'unsubscribed' ) {
			self::fail( __( 'This person unsubscribed. Only they can subscribe again, from a form.', 'dox-orbit' ) );
		}
		DXO_Subscribers::set_status( (int) $sub['id'], $status );
		wp_send_json_success();
	}

	public static function ajax_resend_confirm() {
		self::guard();
		$sub = DXO_Subscribers::get( (int) self::in( 'id' ) );
		if ( ! $sub || $sub['status'] !== 'pending' ) self::fail( __( 'This person is not waiting for confirmation.', 'dox-orbit' ) );
		DXO_Subscribers::send_confirmation( $sub, true ) ? wp_send_json_success() : self::fail( __( 'The email could not be sent.', 'dox-orbit' ) );
	}

	public static function ajax_import() {
		self::guard();
		if ( empty( $_FILES['file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) self::fail( __( 'Choose a CSV file.', 'dox-orbit' ) );
		if ( ! self::in( 'consent' ) ) self::fail( __( 'Confirm that these people agreed to receive your emails.', 'dox-orbit' ) );
		$lists = array_map( 'intval', (array) self::in( 'lists', [] ) ) ?: [ DXO_Lists::default_id() ];
		$res   = DXO_Subscribers::import_csv( $_FILES['file']['tmp_name'], $lists );
		if ( $res['invalid'] === -1 ) self::fail( __( 'No email column was found in the file.', 'dox-orbit' ) );
		wp_send_json_success( $res );
	}

	public static function ajax_save_list() {
		self::guard();
		$name = sanitize_text_field( self::in( 'name' ) );
		if ( $name === '' ) self::fail( __( 'Give the list a name.', 'dox-orbit' ) );
		$id = (int) self::in( 'id' );
		$id ? DXO_Lists::rename( $id, $name ) : ( $id = DXO_Lists::create( $name ) );
		wp_send_json_success( [ 'id' => $id ] );
	}

	public static function ajax_delete_list() {
		self::guard();
		if ( count( DXO_Lists::all() ) < 2 ) self::fail( __( 'There must be at least one list.', 'dox-orbit' ) );
		DXO_Lists::delete( (int) self::in( 'id' ) );
		wp_send_json_success();
	}

	/** CSV con los suscriptores del filtro actual. BOM para que Excel lea las tildes. */
	public static function export() {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'dxo_export' ) ) wp_die( 'Forbidden' );
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'active';
		$list   = isset( $_GET['list'] ) ? (int) $_GET['list'] : 0;
		$res    = DXO_Subscribers::query( [ 'status' => $status, 'list_id' => $list, 'per_page' => 200, 'page' => 1 ] );
		$pages  = (int) ceil( $res['total'] / 200 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="subscribers-' . $status . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, [ 'email', 'first_name', 'last_name', 'status', 'lists', 'source', 'created_at' ] );
		for ( $p = 1; $p <= max( 1, $pages ); $p++ ) {
			if ( $p > 1 ) $res = DXO_Subscribers::query( [ 'status' => $status, 'list_id' => $list, 'per_page' => 200, 'page' => $p ] );
			foreach ( $res['rows'] as $r ) {
				fputcsv( $out, [ $r['email'], $r['first_name'], $r['last_name'], $r['status'], implode( ' | ', $r['lists'] ), $r['source'], dxo_date( $r['created_at'], 'Y-m-d H:i' ) ] );
			}
		}
		fclose( $out );
		exit;
	}

	// ═══ AJAX: formularios ══════════════════════════════════════════════════

	private static function form_from_post() {
		$keys = array_keys( DXO_Forms::defaults() );
		$f    = [];
		foreach ( $keys as $k ) {
			if ( isset( $_POST[ $k ] ) ) $f[ $k ] = self::in( $k ); // phpcs:ignore
		}
		foreach ( [ 'ask_name', 'show_count', 'enabled' ] as $b ) {
			$f[ $b ] = ! empty( $_POST[ $b ] ) ? 1 : 0; // phpcs:ignore
		}
		return $f;
	}

	public static function ajax_save_form() {
		self::guard();
		$f = DXO_Forms::save( self::form_from_post() );
		wp_send_json_success( [ 'slug' => $f['slug'], 'redirect' => self::url( 'forms', [ 'form' => $f['slug'] ] ) ] );
	}

	public static function ajax_delete_form() {
		self::guard();
		if ( ! DXO_Forms::delete( sanitize_key( self::in( 'slug' ) ) ) ) self::fail( __( 'There must be at least one form.', 'dox-orbit' ) );
		wp_send_json_success( [ 'redirect' => self::url( 'forms' ) ] );
	}

	/** El formulario tal y como saldrá en la web, sin guardar. */
	public static function ajax_form_preview() {
		self::guard();
		$f = wp_parse_args( self::form_from_post(), DXO_Forms::defaults() );
		$f = array_map( function ( $v ) { return is_string( $v ) ? sanitize_text_field( $v ) : $v; }, $f );
		$f['slug'] = sanitize_key( $f['slug'] ) ?: 'preview';
		wp_send_json_success( [ 'html' => DXO_Forms::render( $f, $f['placement'] === 'bar' ? 'preview-bar' : 'preview' ) ] );
	}

	// ═══ AJAX: ajustes ══════════════════════════════════════════════════════

	public static function ajax_save_settings() {
		self::guard();
		$s   = DXO_Settings::all();
		$new = [];
		foreach ( [ 'from_name', 'company', 'address', 'confirm_subject', 'confirm_heading', 'confirm_button', 'confirmed_title', 'footer_why', 'ses_host', 'ses_user', 'imap_user' ] as $k ) {
			if ( isset( $_POST[ $k ] ) ) $new[ $k ] = sanitize_text_field( self::in( $k ) );
		}
		foreach ( [ 'confirm_text', 'confirmed_text' ] as $k ) {
			if ( isset( $_POST[ $k ] ) ) $new[ $k ] = sanitize_textarea_field( self::in( $k ) );
		}
		foreach ( [ 'from_email', 'reply_to', 'bounce_email' ] as $k ) {
			if ( ! isset( $_POST[ $k ] ) ) continue;
			$v = sanitize_email( self::in( $k ) );
			if ( $k === 'from_email' && ! is_email( $v ) ) self::fail( __( 'The sender email is not valid.', 'dox-orbit' ), [ 'field' => $k ] );
			if ( $k === 'reply_to' && self::in( $k ) !== '' && ! is_email( $v ) ) self::fail( __( 'The reply-to email is not valid.', 'dox-orbit' ), [ 'field' => $k ] );
			if ( $k === 'bounce_email' && trim( (string) self::in( $k ) ) !== '' && ! is_email( $v ) ) self::fail( __( 'The bounce mailbox email is not valid.', 'dox-orbit' ), [ 'field' => $k ] );
			$new[ $k ] = $v;
		}
		if ( isset( $_POST['imap_host'] ) ) {
			$host = self::imap_host( self::in( 'imap_host' ) );
			if ( $host === null ) self::fail( __( 'Write only the name of the IMAP server, like mail.yourdomain.com.', 'dox-orbit' ), [ 'field' => 'imap_host' ] );
			$new['imap_host'] = $host;
		}
		if ( isset( $_POST['accent'] ) ) $new['accent'] = DXO_Renderer::color( self::in( 'accent' ) );
		if ( isset( $_POST['logo_id'] ) ) $new['logo_id'] = (int) self::in( 'logo_id' );
		foreach ( [ 'rate_per_hour' => [ 10, 2000 ], 'server_limit' => [ 10, 100000 ], 'ses_port' => [ 1, 65535 ], 'imap_port' => [ 1, 65535 ] ] as $k => $r ) {
			if ( isset( $_POST[ $k ] ) ) $new[ $k ] = max( $r[0], min( $r[1], (int) self::in( $k ) ) );
		}
		foreach ( [ 'track_opens', 'track_clicks', 'double_optin', 'bounce_enabled', 'bounce_verp' ] as $k ) {
			if ( isset( $_POST[ $k ] ) ) $new[ $k ] = (int) self::in( $k ) ? 1 : 0;
		}
		if ( isset( $_POST['transport'] ) ) $new['transport'] = self::in( 'transport' ) === 'ses' ? 'ses' : 'wp';

		// La contraseña de SES: vacía quiere decir "no la cambies". Nunca vuelve a la pantalla.
		$pass = (string) self::in( 'ses_pass' );
		if ( $pass !== '' ) {
			$enc = DXO_Settings::encrypt( $pass );
			if ( $enc === '' ) self::fail( __( 'The password could not be stored safely because this server has no OpenSSL. Nothing was changed.', 'dox-orbit' ), [ 'field' => 'ses_pass' ] );
			$new['ses_pass'] = $enc;
		}

		// La contraseña del buzón de rebotes, igual: vacía = se queda la que había, y nunca vuelve a la pantalla.
		$ipass = (string) self::in( 'imap_pass' );
		if ( $ipass !== '' ) {
			$enc = DXO_Settings::encrypt( $ipass );
			// Sin OpenSSL encrypt() devuelve '': guardarlo borraría la contraseña que ya había.
			if ( $enc === '' ) self::fail( __( 'The password could not be stored safely because this server has no OpenSSL. Nothing was changed.', 'dox-orbit' ), [ 'field' => 'imap_pass' ] );
			$new['imap_pass'] = $enc;
		}

		// Con los rebotes encendidos tiene que estar todo para leer el buzón.
		$m = array_merge( $s, $new );
		if ( ! empty( $m['bounce_enabled'] ) ) {
			if ( ! is_email( $m['bounce_email'] ) ) self::fail( __( 'Write the bounce mailbox email to turn this on.', 'dox-orbit' ), [ 'field' => 'bounce_email' ] );
			if ( trim( (string) $m['imap_host'] ) === '' ) self::fail( __( 'Write the IMAP server of the mailbox to turn this on.', 'dox-orbit' ), [ 'field' => 'imap_host' ] );
			if ( (string) $m['imap_pass'] === '' ) self::fail( __( 'Write the password of the mailbox to turn this on.', 'dox-orbit' ), [ 'field' => 'imap_pass' ] );
		}

		// Con SES elegido, se comprueba contra Amazon antes de guardar: un usuario
		// o una contraseña mal puestos harían fallar a todos los de una campaña.
		$merged = array_merge( $s, $new );
		if ( $merged['transport'] === 'ses' ) {
			$err = DXO_Mailer::check_ses( DXO_Mailer::ses_config( $merged ) );
			if ( $err !== null ) self::fail( sprintf( __( 'Amazon SES did not accept the connection: %s', 'dox-orbit' ), $err ), [ 'field' => 'ses_user' ] );
		}

		DXO_Settings::update( $new );
		wp_send_json_success();
	}

	/** El nombre del servidor IMAP, o null si trae algo más (esquema, ruta, espacios). */
	private static function imap_host( $v ) {
		$v = trim( sanitize_text_field( (string) $v ) );
		if ( $v === '' ) return '';
		return preg_match( '/^[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?$/', $v ) ? $v : null;
	}

	/**
	 * Entra al buzón de rebotes con lo que hay en pantalla (aunque no esté guardado)
	 * y cuenta lo que hay en la bandeja. Contraseña vacía = la guardada.
	 */
	public static function ajax_bounce_test() {
		self::guard();
		$s    = DXO_Settings::all();
		$host = self::imap_host( self::in( 'imap_host' ) );
		if ( $host === null ) self::fail( __( 'Write only the name of the IMAP server, like mail.yourdomain.com.', 'dox-orbit' ), [ 'field' => 'imap_host' ] );
		$email = sanitize_email( self::in( 'bounce_email' ) );
		$user  = sanitize_text_field( self::in( 'imap_user' ) );
		$pass  = (string) self::in( 'imap_pass' );
		$c = [
			'host' => $host,
			'port' => max( 1, min( 65535, (int) self::in( 'imap_port', 993 ) ) ),
			'user' => $user !== '' ? $user : $email,
			'pass' => $pass,
		];
		if ( $c['user'] === '' ) self::fail( __( 'Write the bounce mailbox email first.', 'dox-orbit' ), [ 'field' => 'bounce_email' ] );
		// La contraseña guardada solo se usa con el mismo servidor, puerto y usuario que están guardados:
		// si no, alguien con acceso al panel podría mandarla a un servidor suyo.
		if ( $c['pass'] === '' ) {
			$saved = DXO_Bounces::credentials( $s );
			if ( $c['host'] === $saved['host'] && (int) $c['port'] === (int) $saved['port'] && $c['user'] === $saved['user'] ) {
				$c['pass'] = $saved['pass'];
			} else {
				self::fail( __( 'You changed the server, port or user: write the password to test it.', 'dox-orbit' ), [ 'field' => 'imap_pass' ] );
			}
		}
		try {
			$r = DXO_Bounces::test( $c );
		} catch ( Exception $e ) {
			self::fail( sprintf( __( 'The mailbox did not accept the connection: %s', 'dox-orbit' ), $e->getMessage() ) );
		}
		wp_send_json_success( [ 'message' => sprintf( _n( 'Connected. There is %s message in the inbox.', 'Connected. There are %s messages in the inbox.', $r['exists'], 'dox-orbit' ), dxo_num( $r['exists'] ) ) ] );
	}

	/** Correo de prueba con los ajustes del formulario, aunque no estén guardados. */
	public static function ajax_settings_test() {
		self::guard();
		$to = sanitize_email( self::in( 'to' ) );
		if ( ! is_email( $to ) ) self::fail( __( 'Write a valid email for the test.', 'dox-orbit' ) );
		update_user_meta( get_current_user_id(), 'dxo_test_to', $to );
		$err = DXO_Subscribers::send_confirmation( [
			'id' => 0, 'email' => $to, 'first_name' => wp_get_current_user()->first_name, 'token' => str_repeat( '0', 32 ), 'confirm_sent_at' => null,
		], true ) ? null : __( 'The email could not be sent. Check the sending settings.', 'dox-orbit' );
		if ( $err ) self::fail( $err );
		wp_send_json_success();
	}

	/** Buscador de entradas para el bloque "Entrada del blog". */
	public static function ajax_posts() {
		self::guard();
		$q = new WP_Query( [
			's'              => sanitize_text_field( self::in( 'q' ) ),
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 8,
			'no_found_rows'  => true,
			'lang'           => '', // Polylang: todas las lenguas
		] );
		$out = [];
		foreach ( $q->posts as $p ) {
			$excerpt = has_excerpt( $p ) ? get_the_excerpt( $p ) : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $p->post_content ) ), 28, '…' );
			$out[]   = [
				'post_id' => $p->ID,
				'title'   => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'excerpt' => html_entity_decode( $excerpt, ENT_QUOTES, 'UTF-8' ),
				'image'   => (string) get_the_post_thumbnail_url( $p, 'large' ),
				'url'     => get_permalink( $p ),
				'date'    => get_the_date( '', $p ),
			];
		}
		wp_send_json_success( $out );
	}
}
