<?php
/**
 * Campañas.
 *
 * Estados de una campaña normal: draft → scheduled → sending → sent, con
 * paused y cancelled por el camino. La de bienvenida (type = welcome) está
 * active o inactive: cada alta confirmada le añade un destinatario. Con
 * Polylang hay una por idioma del sitio (el idioma va en `audience.lang`);
 * sin Polylang, una sola y sin idioma.
 *
 * Al lanzar se congela la audiencia en `recipients`, una fila por persona con
 * su token: desde ahí salen los enlaces de seguimiento, la baja y el informe.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DXO_Campaigns {

	private static function t( $name = 'campaigns' ) {
		return DXO_Install::table( $name );
	}

	public static function get( $id ) {
		global $wpdb;
		$c = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', $id ), ARRAY_A );
		return $c ? self::hydrate( $c ) : null;
	}

	private static function hydrate( array $c ) {
		$c['blocks']   = json_decode( (string) ( $c['blocks'] ?? '' ), true ) ?: [];
		// El formato dice cuál de los dos contenidos sale; el otro se guarda por si se vuelve.
		$c['format']   = ( $c['format'] ?? '' ) === 'html' ? 'html' : 'blocks';
		$c['html']     = (string) ( $c['html'] ?? '' );
		$c['audience'] = json_decode( (string) $c['list_ids'], true );
		if ( ! is_array( $c['audience'] ) ) $c['audience'] = [ 'lists' => [] ];
		return $c;
	}

	/**
	 * @param string $filter all|sending|scheduled|draft|sent
	 */
	public static function query( $filter = 'all', $search = '' ) {
		global $wpdb;
		$where = "type = 'regular'";
		$args  = [];
		$map   = [
			'sending'   => "status IN ('sending','paused')",
			'scheduled' => "status = 'scheduled'",
			'draft'     => "status = 'draft'",
			'sent'      => "status IN ('sent','cancelled')",
		];
		if ( isset( $map[ $filter ] ) ) $where .= ' AND ' . $map[ $filter ];
		if ( $search !== '' ) {
			$where .= ' AND subject LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		// Sin blocks ni html: son los campos pesados y la lista no los usa.
		$cols = 'id, type, subject, preheader, format, list_ids, status, scheduled_at, started_at, finished_at, total, sent, failed, bounced, test_sent_at, created_at, updated_at';
		$sql = "SELECT $cols FROM " . self::t() . " WHERE $where ORDER BY FIELD(status,'sending','paused','scheduled','draft','sent','cancelled'), COALESCE(started_at, scheduled_at, updated_at) DESC LIMIT 200";
		$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ) ?: [];
		return array_map( [ __CLASS__, 'hydrate' ], $rows );
	}

	public static function counts() {
		global $wpdb;
		$out = [ 'all' => 0, 'sending' => 0, 'scheduled' => 0, 'draft' => 0, 'sent' => 0 ];
		foreach ( $wpdb->get_results( 'SELECT status, COUNT(*) n FROM ' . self::t() . " WHERE type = 'regular' GROUP BY status", ARRAY_A ) as $r ) {
			$n = (int) $r['n'];
			$out['all'] += $n;
			if ( in_array( $r['status'], [ 'sending', 'paused' ], true ) ) $out['sending'] += $n;
			elseif ( $r['status'] === 'scheduled' ) $out['scheduled'] += $n;
			elseif ( $r['status'] === 'draft' ) $out['draft'] += $n;
			else $out['sent'] += $n;
		}
		return $out;
	}

	// ═══ Guardar ════════════════════════════════════════════════════════════

	public static function create( array $data = [] ) {
		global $wpdb;
		$now = dxo_now();
		$wpdb->insert( self::t(), [
			'type'       => $data['type'] ?? 'regular',
			'subject'    => mb_substr( (string) ( $data['subject'] ?? '' ), 0, 255 ),
			'preheader'  => mb_substr( (string) ( $data['preheader'] ?? '' ), 0, 255 ),
			'blocks'     => wp_json_encode( $data['blocks'] ?? self::starter_blocks() ),
			'format'     => ( $data['format'] ?? '' ) === 'html' ? 'html' : 'blocks',
			'html'       => (string) ( $data['html'] ?? '' ),
			'list_ids'   => wp_json_encode( $data['audience'] ?? [ 'lists' => [ DXO_Lists::default_id() ] ] ),
			'status'     => $data['status'] ?? 'draft',
			'created_at' => $now,
			'updated_at' => $now,
		] );
		return (int) $wpdb->insert_id;
	}

	/** Lo que trae una campaña nueva, para no empezar con la hoja en blanco. */
	public static function starter_blocks() {
		return [
			[ 'type' => 'heading', 'text' => '', 'size' => 'large', 'align' => 'left' ],
			[ 'type' => 'text', 'html' => '<p>' . esc_html__( 'Hi {first_name|there},', 'dox-orbit' ) . '</p><p></p>', 'align' => 'left' ],
			[ 'type' => 'button', 'text' => __( 'Read more', 'dox-orbit' ), 'url' => home_url( '/' ), 'style' => 'dark', 'align' => 'left' ],
		];
	}

	/** Guarda lo que manda el editor. Solo se pueden editar borradores, programadas y la bienvenida. */
	public static function save( $id, array $data ) {
		global $wpdb;
		$c = self::get( $id );
		if ( ! $c ) return new WP_Error( 'missing', __( 'That campaign no longer exists.', 'dox-orbit' ) );
		if ( ! in_array( $c['status'], [ 'draft', 'scheduled', 'active', 'inactive' ], true ) ) {
			return new WP_Error( 'locked', __( 'This campaign has already been sent and cannot be edited.', 'dox-orbit' ) );
		}

		$row = [ 'updated_at' => dxo_now() ];
		if ( isset( $data['subject'] ) ) $row['subject'] = mb_substr( sanitize_text_field( $data['subject'] ), 0, 255 );
		if ( isset( $data['preheader'] ) ) $row['preheader'] = mb_substr( sanitize_text_field( $data['preheader'] ), 0, 255 );
		if ( isset( $data['blocks'] ) ) $row['blocks'] = wp_json_encode( DXO_Renderer::normalize_blocks( $data['blocks'] ) );
		if ( isset( $data['format'] ) && in_array( $data['format'], [ 'blocks', 'html' ], true ) ) $row['format'] = $data['format'];
		if ( isset( $data['html'] ) ) {
			// El código se guarda tal cual lo escribió la persona (así el editor lo enseña igual al volver).
			// Se limpia siempre que sale: vista previa, prueba, envío y "ver en el navegador".
			$html = trim( (string) $data['html'] );
			if ( strlen( $html ) > DXO_Html_Email::MAX_BYTES ) {
				return new WP_Error( 'too_big', __( 'The HTML code is too large. Over 1 MB cannot be a good email.', 'dox-orbit' ) );
			}
			$row['html'] = $html;
			if ( $html !== $c['html'] ) DXO_Html_Email::forget( $c['html'] );
		}
		if ( isset( $data['lists'] ) && empty( $c['audience']['resend'] ) ) {
			$aud = [ 'lists' => array_values( array_filter( array_map( 'intval', (array) $data['lists'] ) ) ) ];
			// El idioma de la campaña no se pierde al guardar las listas.
			if ( self::lang_of( $c ) !== '' ) $aud['lang'] = self::lang_of( $c );
			$row['list_ids'] = wp_json_encode( $aud );
		}
		$wpdb->update( self::t(), $row, [ 'id' => (int) $id ] );
		return self::get( $id );
	}

	public static function delete( $id ) {
		global $wpdb;
		$c = self::get( $id );
		if ( ! $c || $c['type'] !== 'regular' || in_array( $c['status'], [ 'sending' ], true ) ) return false;
		$rids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'recipients' ) . ' WHERE campaign_id = %d', $id ) );
		$wpdb->delete( self::t( 'events' ), [ 'campaign_id' => (int) $id ] );
		$wpdb->delete( self::t( 'links' ), [ 'campaign_id' => (int) $id ] );
		$wpdb->delete( self::t( 'recipients' ), [ 'campaign_id' => (int) $id ] );
		return (bool) $wpdb->delete( self::t(), [ 'id' => (int) $id ] );
	}

	public static function duplicate( $id ) {
		$c = self::get( $id );
		if ( ! $c ) return 0;
		return self::create( [
			'subject'   => $c['subject'],
			'preheader' => $c['preheader'],
			'blocks'    => $c['blocks'],
			'format'    => $c['format'],
			'html'      => $c['html'],
			'audience'  => [ 'lists' => $c['audience']['lists'] ?? [] ],
		] );
	}

	/**
	 * Un borrador con el mismo correo para quien no abrió (o no hizo clic) la
	 * campaña $id. La audiencia se calcula al lanzarlo: quien abra entretanto
	 * ya no lo recibe.
	 */
	public static function resend( $id, $who = 'not_opened' ) {
		$c = self::get( $id );
		if ( ! $c || ! in_array( $c['status'], [ 'sent', 'cancelled' ], true ) ) return 0;
		return self::create( [
			'subject'   => $c['subject'],
			'preheader' => $c['preheader'],
			'blocks'    => $c['blocks'],
			'format'    => $c['format'],
			'html'      => $c['html'],
			'audience'  => [ 'resend' => [ 'campaign_id' => (int) $id, 'who' => $who === 'not_clicked' ? 'not_clicked' : 'not_opened' ] ],
		] );
	}

	// ═══ Audiencia ══════════════════════════════════════════════════════════

	/**
	 * Los ids de suscriptor que recibirían la campaña ahora mismo: activos, de
	 * las listas elegidas (todas si no hay ninguna) o, en un reenvío, los que no
	 * abrieron o no hicieron clic la original y siguen activos.
	 */
	public static function audience_ids( array $c ) {
		global $wpdb;
		$s = self::t( 'subscribers' );

		if ( ! empty( $c['audience']['resend'] ) ) {
			$r   = $c['audience']['resend'];
			$col = $r['who'] === 'not_clicked' ? 'clicked_at' : 'opened_at';
			return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
				'SELECT r.subscriber_id FROM ' . self::t( 'recipients' ) . " r JOIN $s s ON s.id = r.subscriber_id AND s.status = 'active'
				 WHERE r.campaign_id = %d AND r.status = 'sent' AND r.$col IS NULL AND r.unsubscribed_at IS NULL",
				(int) $r['campaign_id']
			) ) );
		}

		$lists = array_filter( array_map( 'intval', $c['audience']['lists'] ?? [] ) );
		if ( ! $lists ) {
			return array_map( 'intval', $wpdb->get_col( "SELECT id FROM $s WHERE status = 'active'" ) );
		}
		$in = implode( ',', $lists );
		return array_map( 'intval', $wpdb->get_col(
			'SELECT DISTINCT s.id FROM ' . self::t( 'list_subscriber' ) . " ls JOIN $s s ON s.id = ls.subscriber_id AND s.status = 'active' WHERE ls.list_id IN ($in)"
		) );
	}

	/** "Lista General · 1.184 personas", o "Quien no abrió «...»". */
	public static function audience_label( array $c ) {
		if ( ! empty( $c['audience']['resend'] ) ) {
			$orig = self::get( (int) $c['audience']['resend']['campaign_id'] );
			$what = $c['audience']['resend']['who'] === 'not_clicked' ? __( 'Who did not click «%s»', 'dox-orbit' ) : __( 'Who did not open «%s»', 'dox-orbit' );
			return sprintf( $what, $orig ? $orig['subject'] : '#' . $c['audience']['resend']['campaign_id'] );
		}
		$names = DXO_Lists::names();
		$lists = array_filter( array_map( function ( $id ) use ( $names ) { return $names[ $id ] ?? null; }, $c['audience']['lists'] ?? [] ) );
		return $lists ? implode( ', ', $lists ) : __( 'All subscribers', 'dox-orbit' );
	}

	// ═══ Lanzar ═════════════════════════════════════════════════════════════

	/** Lo que impide lanzar la campaña, en frases para la pantalla. Vacío = se puede. */
	public static function problems( array $c ) {
		$p = [];
		if ( trim( $c['subject'] ) === '' ) $p[] = __( 'Write a subject.', 'dox-orbit' );
		$texts = [ $c['subject'], $c['preheader'] ];

		if ( $c['format'] === 'html' ) {
			// Sin los <style> y el <head>: lo que queda tiene que decir algo (texto o una imagen).
			$body = preg_replace( '#<(style|script|head|title)\b.*?</\1>#is', '', $c['html'] );
			if ( trim( html_entity_decode( strip_tags( $body ), ENT_QUOTES, 'UTF-8' ) ) === '' && stripos( $body, '<img' ) === false ) {
				$p[] = __( 'The email is empty: paste the HTML code.', 'dox-orbit' );
			}
			// Las llaves del CSS no son campos.
			$texts[] = preg_replace( '#<style\b.*?</style>#is', '', $c['html'] );
		} else {
			$has = false;
			foreach ( $c['blocks'] as $b ) {
				if ( ( $b['type'] === 'text' && trim( strip_tags( $b['html'] ) ) !== '' ) || ( $b['type'] === 'heading' && $b['text'] !== '' ) || ( $b['type'] === 'image' && $b['url'] !== '' ) || $b['type'] === 'post'
					|| ( $b['type'] === 'html' && ( trim( strip_tags( $b['html'] ) ) !== '' || stripos( $b['html'], '<img' ) !== false ) ) ) {
					$has = true;
					break;
				}
			}
			if ( ! $has ) $p[] = __( 'The email is empty: add a title, a text or an image.', 'dox-orbit' );

			foreach ( $c['blocks'] as $b ) {
				$texts[] = $b['text'] ?? '';
				$texts[] = isset( $b['type'] ) && $b['type'] === 'html' ? preg_replace( '#<style\b.*?</style>#is', '', $b['html'] ) : ( $b['html'] ?? '' );
				$texts[] = $b['url'] ?? '';
			}
		}
		$unknown = array_unique( array_merge(
			DXO_Renderer::unknown_tags( ...$texts ),
			// {unsubscribe_url} solo se rellena en enlaces del cuerpo, no en el asunto ni en el preheader.
			DXO_Renderer::link_tags_in( $c['subject'], $c['preheader'] )
		) );
		if ( $unknown ) {
			/* translators: %s: list of unknown merge tags */
			$p[] = sprintf( __( 'These fields cannot be filled: %s. The ones that work are {first_name}, {last_name} and {email}.', 'dox-orbit' ), implode( ', ', $unknown ) );
		}
		if ( ! is_email( DXO_Settings::get( 'from_email' ) ) ) $p[] = __( 'The sender email in Settings is not valid.', 'dox-orbit' );
		if ( $c['type'] === 'regular' && ! self::audience_ids( $c ) ) $p[] = __( 'Nobody would receive it: the chosen lists have no active subscribers.', 'dox-orbit' );
		return $p;
	}

	/**
	 * Lanza ya o programa para $when (UTC). Devuelve la campaña o un WP_Error
	 * con los problemas.
	 */
	public static function launch( $id, $when = null ) {
		global $wpdb;
		$c = self::get( $id );
		if ( ! $c || ! in_array( $c['status'], [ 'draft', 'scheduled' ], true ) ) {
			return new WP_Error( 'state', __( 'This campaign cannot be sent from its current state.', 'dox-orbit' ) );
		}
		$p = self::problems( $c );
		if ( $p ) return new WP_Error( 'problems', implode( "\n", $p ), $p );

		if ( $when && strtotime( $when . ' UTC' ) > time() + 60 ) {
			$wpdb->update( self::t(), [ 'status' => 'scheduled', 'scheduled_at' => $when, 'updated_at' => dxo_now() ], [ 'id' => $c['id'] ] );
			return self::get( $id );
		}
		self::start( $c );
		DXO_Sender::kick();
		return self::get( $id );
	}

	/** Vuelve a borrador una programada (quitarle la fecha). */
	public static function unschedule( $id ) {
		global $wpdb;
		return (bool) $wpdb->update( self::t(), [ 'status' => 'draft', 'scheduled_at' => null ], [ 'id' => (int) $id, 'status' => 'scheduled' ] );
	}

	/** Congela los destinatarios y la pasa a "enviando". */
	public static function start( array $c ) {
		global $wpdb;
		$ids = self::audience_ids( $c );
		$s   = self::t( 'subscribers' );
		$r   = self::t( 'recipients' );
		$now = dxo_now();

		foreach ( array_chunk( $ids, 300 ) as $chunk ) {
			$in     = implode( ',', $chunk );
			$people = $wpdb->get_results( "SELECT id, email FROM $s WHERE id IN ($in)", ARRAY_A );
			$values = [];
			foreach ( $people as $p ) {
				$values[] = $wpdb->prepare( '(%d,%d,%s,%s,%s,%s)', $c['id'], $p['id'], $p['email'], dxo_token(), 'queued', $now );
			}
			if ( $values ) {
				// IGNORE: si se relanza tras un fallo, los que ya están no se duplican.
				$wpdb->query( "INSERT IGNORE INTO $r (campaign_id, subscriber_id, email, token, status, queued_at) VALUES " . implode( ',', $values ) );
			}
		}

		self::register_links( $c );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $r WHERE campaign_id = %d", $c['id'] ) );
		$wpdb->update( self::t(), [ 'status' => 'sending', 'started_at' => $now, 'total' => $total, 'updated_at' => $now ], [ 'id' => $c['id'] ] );
	}

	/** Los enlaces del correo, una fila por URL. Los que llevan un {campo} no se siguen. */
	public static function register_links( array $c ) {
		global $wpdb;
		$html = $c['format'] === 'html' ? DXO_Html_Email::prepare( $c['html'] ) : DXO_Renderer::render_blocks( $c['blocks'], DXO_Settings::brand() );
		foreach ( DXO_Renderer::extract_links( $html ) as $url ) {
			if ( strpos( $url, '{' ) !== false ) continue;
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::t( 'links' ) . ' (campaign_id, url, url_hash) VALUES (%d, %s, %s)', $c['id'], $url, sha1( $url ) ) );
		}
	}

	public static function link_map( $campaign_id ) {
		global $wpdb;
		$map = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, url FROM ' . self::t( 'links' ) . ' WHERE campaign_id = %d', $campaign_id ), ARRAY_A ) as $l ) {
			$map[ $l['url'] ] = (int) $l['id'];
		}
		return $map;
	}

	public static function set_status( $id, $status ) {
		global $wpdb;
		$data = [ 'status' => $status, 'updated_at' => dxo_now() ];
		if ( $status === 'sent' || $status === 'cancelled' ) $data['finished_at'] = dxo_now();
		return (bool) $wpdb->update( self::t(), $data, [ 'id' => (int) $id ] );
	}

	/** Cancelar: lo que quedaba en cola ya no sale. */
	public static function cancel( $id ) {
		global $wpdb;
		$wpdb->update( self::t( 'recipients' ), [ 'status' => 'skipped', 'error' => 'cancelled' ], [ 'campaign_id' => (int) $id, 'status' => 'queued' ] );
		return self::set_status( $id, 'cancelled' );
	}

	// ═══ Bienvenida ═════════════════════════════════════════════════════════

	// ─── Idiomas (Polylang) ───

	public static function polylang() {
		return function_exists( 'pll_languages_list' );
	}

	/**
	 * Los idiomas del sitio en el orden de Polylang: slug => [ name, locale ].
	 * Vacío sin Polylang.
	 */
	public static function languages() {
		if ( ! self::polylang() ) return [];
		$slugs   = (array) pll_languages_list( [ 'fields' => 'slug' ] );
		$names   = (array) pll_languages_list( [ 'fields' => 'name' ] );
		$locales = (array) pll_languages_list( [ 'fields' => 'locale' ] );
		$out     = [];
		foreach ( $slugs as $i => $slug ) {
			$out[ (string) $slug ] = [ 'name' => (string) ( $names[ $i ] ?? $slug ), 'locale' => (string) ( $locales[ $i ] ?? '' ) ];
		}
		// En WP-Cron Polylang a veces aún no tiene sus idiomas cargados: se usa la última lista que se vio.
		if ( ! $out ) {
			$seen = get_option( 'dxo_pll_langs' );
			return is_array( $seen ) && ! empty( $seen['list'] ) ? $seen['list'] : [];
		}
		$def  = function_exists( 'pll_default_language' ) ? (string) pll_default_language() : '';
		$seen = get_option( 'dxo_pll_langs' );
		if ( ! is_array( $seen ) || ( $seen['list'] ?? null ) !== $out || ( $seen['default'] ?? '' ) !== $def ) {
			update_option( 'dxo_pll_langs', [ 'list' => $out, 'default' => $def ], false );
		}
		return $out;
	}

	/** El idioma por defecto de Polylang (su slug); '' sin Polylang o si no se sabe. */
	public static function default_lang() {
		if ( ! self::polylang() ) return '';
		$d = function_exists( 'pll_default_language' ) ? (string) pll_default_language() : '';
		$langs = self::languages(); // también refresca la copia guardada
		if ( $d === '' ) {
			$seen = get_option( 'dxo_pll_langs' );
			$d    = is_array( $seen ) ? (string) ( $seen['default'] ?? '' ) : '';
		}
		if ( $d === '' && $langs ) $d = (string) array_key_first( $langs );
		return $d;
	}

	/** El idioma guardado en una campaña ('' = ninguno). */
	public static function lang_of( array $c ) {
		return (string) ( $c['audience']['lang'] ?? '' );
	}

	/**
	 * El idioma en que se pintan los textos fijos del correo (baja, "ver en el navegador"...).
	 * El de la campaña si lo tiene; si no, con Polylang el idioma por defecto del sitio público
	 * (el del admin puede ser otro: un panel en inglés sobre una web en español); sin Polylang
	 * '' = el idioma de WordPress de siempre.
	 */
	public static function render_lang( array $c ) {
		$l = self::lang_of( $c );
		if ( $l !== '' ) return $l;
		return self::polylang() ? self::default_lang() : '';
	}

	/** El nombre del idioma de una campaña, para pintarlo en el panel ('' = no tiene). */
	public static function lang_name( array $c ) {
		$l = self::lang_of( $c );
		if ( $l === '' ) return '';
		$langs = self::languages();
		return $langs[ $l ]['name'] ?? $l;
	}

	/**
	 * Cambia el idioma de WordPress al de la campaña mientras se construyen sus
	 * textos. Devuelve true si cambió de verdad: solo entonces hay que llamar a
	 * restore_previous_locale() (switch_to_locale devuelve false si ya estaba en
	 * ese idioma o si ese idioma no está instalado).
	 */
	public static function enter_lang( $lang ) {
		$lang = (string) $lang;
		if ( $lang === '' || ! function_exists( 'switch_to_locale' ) ) return false;
		$langs = self::languages();
		if ( empty( $langs[ $lang ]['locale'] ) ) return false;
		return (bool) switch_to_locale( $langs[ $lang ]['locale'] );
	}

	public static function leave_lang( $entered ) {
		if ( $entered ) restore_previous_locale();
	}

	/** ¿Es una campaña en un idioma que no es el principal? Su pie no usa el texto guardado en Ajustes. */
	public static function is_secondary_lang( array $c ) {
		$l = self::lang_of( $c );
		$d = self::default_lang();
		return self::polylang() && $d !== '' && $l !== '' && $l !== $d;
	}

	// ─── Bienvenidas ───

	/** El asunto y los bloques de una bienvenida nueva, en el idioma $lang ('' = el del sitio). */
	private static function welcome_defaults( $lang ) {
		$entered = self::enter_lang( $lang );
		try {
			$home = $lang !== '' && function_exists( 'pll_home_url' ) ? (string) pll_home_url( $lang ) : '';
			if ( $home === '' ) $home = home_url( '/' );
			return [
				'subject'   => sprintf( __( 'Welcome to %s', 'dox-orbit' ), get_bloginfo( 'name' ) ),
				'preheader' => __( 'What you will receive and how often.', 'dox-orbit' ),
				'blocks'    => [
					[ 'type' => 'heading', 'text' => __( 'Welcome, {first_name|friend}', 'dox-orbit' ), 'size' => 'large', 'align' => 'left' ],
					[ 'type' => 'text', 'html' => '<p>' . esc_html__( 'Thanks for subscribing. Once a month you will receive what we learn and what we publish, without filler.', 'dox-orbit' ) . '</p>', 'align' => 'left' ],
					[ 'type' => 'button', 'text' => __( 'Visit the blog', 'dox-orbit' ), 'url' => $home, 'style' => 'dark', 'align' => 'left' ],
				],
			];
		} finally {
			self::leave_lang( $entered );
		}
	}

	/** Crea una bienvenida apagada ($lang = '' sin Polylang) y devuelve la campaña. */
	private static function create_welcome( $lang ) {
		global $wpdb;
		$d  = self::welcome_defaults( $lang );
		$aud = [ 'lists' => [] ];
		if ( $lang !== '' ) $aud['lang'] = $lang;
		$id = self::create( [
			'type'      => 'welcome',
			'status'    => 'inactive',
			'subject'   => $d['subject'],
			'preheader' => $d['preheader'],
			'audience'  => $aud,
			'blocks'    => $d['blocks'],
		] );
		$wpdb->update( self::t(), [ 'started_at' => dxo_now() ], [ 'id' => $id ] );

		// Dos peticiones a la vez pueden crear la misma: se queda la de id más bajo y la otra se borra.
		if ( $lang !== '' ) {
			$rows = $wpdb->get_results( 'SELECT * FROM ' . self::t() . " WHERE type = 'welcome' AND id < " . (int) $id . ' ORDER BY id ASC', ARRAY_A ) ?: [];
			foreach ( $rows as $r ) {
				$c = self::hydrate( $r );
				if ( self::lang_of( $c ) === $lang ) {
					$wpdb->delete( self::t(), [ 'id' => $id ] );
					return $c;
				}
			}
		}
		return self::get( $id );
	}

	/**
	 * Las bienvenidas, slug de idioma => campaña, en el orden de Polylang.
	 * Se crean las que falten (apagadas). Sin Polylang es una sola, con la
	 * clave '' (se crea la primera vez que se pide, apagada).
	 *
	 * Instalaciones que vienen de antes de la 0.3.0: la bienvenida que ya
	 * existía, sin idioma, pasa a ser la del idioma por defecto.
	 */
	public static function welcomes() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::t() . " WHERE type = 'welcome' ORDER BY id ASC", ARRAY_A ) ?: [];
		$all  = array_map( [ __CLASS__, 'hydrate' ], $rows );
		$langs = self::languages();

		if ( ! $langs ) {
			return [ '' => $all ? $all[0] : self::create_welcome( '' ) ];
		}

		$by     = [];
		$orphan = null;
		foreach ( $all as $c ) {
			$l = self::lang_of( $c );
			if ( $l === '' ) { if ( ! $orphan ) $orphan = $c; continue; }
			if ( isset( $langs[ $l ] ) && ! isset( $by[ $l ] ) ) $by[ $l ] = $c;
		}

		$default = self::default_lang();
		if ( $orphan && ! isset( $by[ $default ] ) ) {
			$aud         = $orphan['audience'];
			$aud['lang'] = $default;
			$wpdb->update( self::t(), [ 'list_ids' => wp_json_encode( $aud ) ], [ 'id' => (int) $orphan['id'] ] );
			$by[ $default ] = self::get( $orphan['id'] );
		}

		$out = [];
		foreach ( $langs as $slug => $_ ) {
			$out[ $slug ] = $by[ $slug ] ?? self::create_welcome( $slug );
		}
		return $out;
	}

	/** La bienvenida principal: la del idioma por defecto (o la única, sin Polylang). */
	public static function welcome() {
		$all = self::welcomes();
		$d   = self::default_lang();
		return $all[ $d ] ?? reset( $all );
	}

	/**
	 * La bienvenida que le toca a alguien según su idioma ($lang: 'es', 'en',
	 * 'es_CO'...). Primero el slug exacto; luego las dos primeras letras; si
	 * nada encaja, la del idioma por defecto; y si tampoco, la primera.
	 */
	public static function welcome_for( $lang ) {
		$all  = self::welcomes();
		$norm = function ( $l ) { return strtolower( str_replace( '_', '-', trim( (string) $l ) ) ); };
		$want = $norm( $lang );
		if ( $want !== '' ) {
			foreach ( $all as $slug => $c ) {
				if ( $slug !== '' && $norm( $slug ) === $want ) return $c;
			}
			foreach ( $all as $slug => $c ) {
				if ( $slug !== '' && substr( $norm( $slug ), 0, 2 ) === substr( $want, 0, 2 ) ) return $c;
			}
		}
		$d = self::default_lang();
		return $all[ $d ] ?? reset( $all );
	}

	/** Si la bienvenida de su idioma está encendida, pone en cola el correo para quien acaba de entrar. */
	public static function enqueue_welcome( $sub ) {
		global $wpdb;
		if ( ! $sub || $sub['status'] !== 'active' ) return;
		$w = self::welcome_for( (string) ( $sub['lang'] ?? '' ) );
		if ( ! $w || $w['status'] !== 'active' ) return;

		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . self::t( 'recipients' ) . ' (campaign_id, subscriber_id, email, token, status, queued_at) VALUES (%d,%d,%s,%s,%s,%s)',
			$w['id'], $sub['id'], $sub['email'], dxo_token(), 'queued', dxo_now()
		) );
		if ( $wpdb->rows_affected ) {
			self::register_links( $w );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . ' SET total = total + 1 WHERE id = %d', $w['id'] ) );
			DXO_Sender::kick();
		}
	}

	// ═══ Eventos e informe ══════════════════════════════════════════════════

	public static function log_event( $campaign_id, $recipient_id, $type, $link_id = 0 ) {
		global $wpdb;
		$wpdb->insert( self::t( 'events' ), [
			'campaign_id'  => (int) $campaign_id,
			'recipient_id' => (int) $recipient_id,
			'type'         => $type,
			'link_id'      => (int) $link_id,
			'created_at'   => dxo_now(),
		] );
	}

	/** Los números de una campaña: enviados, abiertos, clics, bajas, fallos, rebotes. */
	public static function stats( $id ) {
		global $wpdb;
		$r = self::t( 'recipients' );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) total,
				SUM(status = 'sent') sent,
				SUM(status = 'failed') failed,
				SUM(status = 'queued') queued,
				SUM(status = 'sent' AND opened_at IS NOT NULL) opened,
				SUM(status = 'sent' AND clicked_at IS NOT NULL) clicked,
				SUM(unsubscribed_at IS NOT NULL) unsubscribed,
				SUM(bounced_at IS NOT NULL) bounced
			 FROM $r WHERE campaign_id = %d",
			$id
		), ARRAY_A );
		return array_map( 'intval', $row ?: [] );
	}

	/** Los envíos que rebotaron, los últimos primero, para el informe. */
	public static function bounced_list( $id, $limit = 25 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT r.email, r.bounced_at, r.bounce_reason, s.status AS sub_status FROM ' . self::t( 'recipients' ) . ' r LEFT JOIN ' . self::t( 'subscribers' ) . ' s ON s.id = r.subscriber_id WHERE r.campaign_id = %d AND r.bounced_at IS NOT NULL ORDER BY r.bounced_at DESC, r.id DESC LIMIT %d',
			$id, $limit
		), ARRAY_A ) ?: [];
	}

	/** Aperturas por hora en las primeras 24 horas (primera apertura de cada uno). */
	public static function hourly_opens( array $c ) {
		global $wpdb;
		$out = array_fill( 0, 24, 0 );
		if ( ! $c['started_at'] ) return $out;
		$start = strtotime( $c['started_at'] . ' UTC' );
		$rows  = $wpdb->get_col( $wpdb->prepare( 'SELECT opened_at FROM ' . self::t( 'recipients' ) . ' WHERE campaign_id = %d AND opened_at IS NOT NULL', $c['id'] ) );
		foreach ( $rows as $o ) {
			$h = (int) floor( ( strtotime( $o . ' UTC' ) - $start ) / HOUR_IN_SECONDS );
			if ( $h >= 0 && $h < 24 ) $out[ $h ]++;
		}
		return $out;
	}

	public static function link_clicks( $id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT url, clicks FROM ' . self::t( 'links' ) . ' WHERE campaign_id = %d ORDER BY clicks DESC, id ASC', $id ), ARRAY_A ) ?: [];
	}

	/** Apertura por proveedor de correo (Gmail, Outlook, iCloud, Yahoo y el resto). */
	public static function providers( $id ) {
		global $wpdb;
		$groups = [
			'gmail'   => [ 'Gmail', [ 'gmail.com', 'googlemail.com' ] ],
			'outlook' => [ 'Outlook / Hotmail', [ 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'outlook.es', 'hotmail.es' ] ],
			'icloud'  => [ 'iCloud', [ 'icloud.com', 'me.com', 'mac.com' ] ],
			'yahoo'   => [ 'Yahoo', [ 'yahoo.com', 'yahoo.es', 'ymail.com', 'aol.com' ] ],
		];
		$out = [];
		foreach ( $groups as $k => $g ) $out[ $k ] = [ 'name' => $g[0], 'sent' => 0, 'opened' => 0 ];
		$out['other'] = [ 'name' => __( 'Others', 'dox-orbit' ), 'sent' => 0, 'opened' => 0 ];

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT SUBSTRING_INDEX(email,'@',-1) d, COUNT(*) n, SUM(opened_at IS NOT NULL) o FROM " . self::t( 'recipients' ) . " WHERE campaign_id = %d AND status = 'sent' AND email <> '' GROUP BY d",
			$id
		), ARRAY_A ) ?: [];
		foreach ( $rows as $r ) {
			$key = 'other';
			foreach ( $groups as $k => $g ) {
				if ( in_array( strtolower( $r['d'] ), $g[1], true ) ) { $key = $k; break; }
			}
			$out[ $key ]['sent']   += (int) $r['n'];
			$out[ $key ]['opened'] += (int) $r['o'];
		}
		return array_filter( $out, function ( $g ) { return $g['sent'] > 0; } );
	}
}
