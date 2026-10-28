<?php
/**
 * Suscriptores y listas.
 *
 * Estados de un suscriptor:
 * - pending:      se apuntó y falta que confirme (doble opt-in).
 * - active:       recibe las campañas.
 * - unsubscribed: se dio de baja. Nunca se le vuelve a escribir salvo que se
 *                 apunte otra vez él mismo (y vuelva a confirmar).
 * - bounced:      su dirección no existe o rechaza el correo.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DXO_Lists {

	public static function all() {
		global $wpdb;
		$t  = DXO_Install::table( 'lists' );
		$ls = DXO_Install::table( 'list_subscriber' );
		$s  = DXO_Install::table( 'subscribers' );
		return $wpdb->get_results(
			"SELECT l.*, (SELECT COUNT(*) FROM $ls x JOIN $s s ON s.id = x.subscriber_id AND s.status = 'active' WHERE x.list_id = l.id) AS active
			 FROM $t l ORDER BY l.id ASC",
			ARRAY_A
		) ?: [];
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DXO_Install::table( 'lists' ) . ' WHERE id = %d', $id ), ARRAY_A );
	}

	public static function names() {
		$out = [];
		foreach ( self::all() as $l ) $out[ (int) $l['id'] ] = $l['name'];
		return $out;
	}

	public static function create( $name, $description = '' ) {
		global $wpdb;
		$wpdb->insert( DXO_Install::table( 'lists' ), [
			'name'        => mb_substr( $name, 0, 120 ),
			'description' => mb_substr( $description, 0, 255 ),
			'created_at'  => dxo_now(),
		] );
		return (int) $wpdb->insert_id;
	}

	public static function rename( $id, $name ) {
		global $wpdb;
		return false !== $wpdb->update( DXO_Install::table( 'lists' ), [ 'name' => mb_substr( $name, 0, 120 ) ], [ 'id' => (int) $id ] );
	}

	/** Borra la lista, no a la gente: siguen en las demás listas y en la base. */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( DXO_Install::table( 'list_subscriber' ), [ 'list_id' => (int) $id ] );
		return (bool) $wpdb->delete( DXO_Install::table( 'lists' ), [ 'id' => (int) $id ] );
	}

	/** Toda instalación tiene al menos una lista, la "General". */
	public static function ensure_default() {
		global $wpdb;
		$n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DXO_Install::table( 'lists' ) );
		if ( ! $n ) {
			self::create( __( 'General', 'dox-orbit' ) );
		}
	}

	public static function default_id() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT MIN(id) FROM ' . DXO_Install::table( 'lists' ) );
	}
}

class DXO_Subscribers {

	const STATUSES = [ 'active', 'pending', 'unsubscribed', 'bounced' ];

	private static function t() {
		return DXO_Install::table( 'subscribers' );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', $id ), ARRAY_A );
	}

	public static function get_by_email( $email ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE email = %s', strtolower( trim( $email ) ) ), ARRAY_A );
	}

	public static function get_by_token( $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $token ) ) return null;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE token = %s', $token ), ARRAY_A );
	}

	// ═══ Alta ═══════════════════════════════════════════════════════════════

	/**
	 * Apunta a alguien desde un formulario (o el checkout, o la API).
	 *
	 * @param array $args first_name, last_name, list_ids, source, lang, ip
	 * @return array [ 'result' => pending|active|already|invalid, 'subscriber' => fila ]
	 */
	public static function subscribe( $email, array $args = [] ) {
		global $wpdb;

		$email = strtolower( trim( (string) $email ) );
		if ( ! is_email( $email ) ) {
			return [ 'result' => 'invalid', 'subscriber' => null ];
		}

		$args = wp_parse_args( $args, [
			'first_name' => '',
			'last_name'  => '',
			'list_ids'   => [],
			'source'     => '',
			'lang'       => '',
			'ip'         => '',
			'confirmed'  => false, // true = no hace falta confirmar (importación, alta a mano)
		] );
		$lists  = array_filter( array_map( 'intval', (array) $args['list_ids'] ) ) ?: [ DXO_Lists::default_id() ];
		$double = (int) DXO_Settings::get( 'double_optin' ) && ! $args['confirmed'];
		$sub    = self::get_by_email( $email );

		if ( $sub && $sub['status'] === 'active' ) {
			// Ya estaba: se le añaden las listas nuevas y no se le manda nada.
			self::add_to_lists( (int) $sub['id'], $lists );
			if ( $args['first_name'] !== '' && $sub['first_name'] === '' ) {
				$wpdb->update( self::t(), [ 'first_name' => mb_substr( $args['first_name'], 0, 100 ) ], [ 'id' => $sub['id'] ] );
			}
			return [ 'result' => 'already', 'subscriber' => self::get( $sub['id'] ) ];
		}

		$status = $double ? 'pending' : 'active';
		$now    = dxo_now();

		if ( $sub ) {
			// Estaba sin confirmar, de baja o rebotado y se vuelve a apuntar él mismo.
			$wpdb->update( self::t(), [
				'status'          => $status,
				'first_name'      => $args['first_name'] !== '' ? mb_substr( $args['first_name'], 0, 100 ) : $sub['first_name'],
				'last_name'       => $args['last_name'] !== '' ? mb_substr( $args['last_name'], 0, 100 ) : $sub['last_name'],
				'confirmed_at'    => $status === 'active' ? $now : null,
				'unsubscribed_at' => null,
				'soft_bounces'    => 0,
			], [ 'id' => $sub['id'] ] );
			$id = (int) $sub['id'];
		} else {
			$wpdb->insert( self::t(), [
				'email'        => $email,
				'first_name'   => mb_substr( (string) $args['first_name'], 0, 100 ),
				'last_name'    => mb_substr( (string) $args['last_name'], 0, 100 ),
				'status'       => $status,
				'source'       => mb_substr( (string) $args['source'], 0, 60 ),
				'lang'         => mb_substr( (string) $args['lang'], 0, 12 ),
				'token'        => dxo_token(),
				'ip'           => mb_substr( (string) $args['ip'], 0, 45 ),
				'created_at'   => $now,
				'confirmed_at' => $status === 'active' ? $now : null,
			] );
			$id = (int) $wpdb->insert_id;
			if ( ! $id ) {
				return [ 'result' => 'invalid', 'subscriber' => null ];
			}
		}

		self::add_to_lists( $id, $lists );
		$row = self::get( $id );

		if ( $status === 'pending' ) {
			self::send_confirmation( $row );
		} else {
			DXO_Campaigns::enqueue_welcome( $row );
		}

		return [ 'result' => $status, 'subscriber' => $row ];
	}

	/**
	 * El correo de "confirma tu suscripción". Sale enseguida, sin pasar por la
	 * cola de campañas: quien se acaba de apuntar lo está esperando.
	 * No se repite si ya se mandó hace menos de 10 minutos.
	 */
	public static function send_confirmation( array $sub, $force = false ) {
		global $wpdb;
		if ( ! $force && $sub['confirm_sent_at'] && strtotime( $sub['confirm_sent_at'] . ' UTC' ) > time() - 10 * MINUTE_IN_SECONDS ) {
			return true;
		}

		$s   = DXO_Settings::all();
		$url = DXO_Public::url( 'confirm', $sub['token'] );

		$render = DXO_Renderer::render_email( [
			'blocks' => [
				[ 'type' => 'heading', 'text' => $s['confirm_heading'], 'size' => 'large', 'align' => 'left' ],
				[ 'type' => 'text', 'html' => wpautop( esc_html( $s['confirm_text'] ) ), 'align' => 'left' ],
				[ 'type' => 'button', 'text' => $s['confirm_button'], 'url' => $url, 'style' => 'dark', 'align' => 'left' ],
			],
			'brand'           => DXO_Settings::brand(),
			'subject'         => $s['confirm_subject'],
			'fields'          => [ 'first_name' => $sub['first_name'], 'email' => $sub['email'] ],
			// En el de confirmación la baja no tiene sentido: aún no está dado de alta.
			'unsubscribe_url' => DXO_Public::url( 'unsubscribe', $sub['token'] ),
			'strings'         => DXO_Public::email_strings(),
		] );

		$error = DXO_Mailer::send( $sub['email'], $s['confirm_subject'], $render['html'], $render['text'], [] );
		if ( $error === null ) {
			$wpdb->update( self::t(), [ 'confirm_sent_at' => dxo_now() ], [ 'id' => $sub['id'] ] );
		}
		return $error === null;
	}

	/** El clic en "Sí, suscribirme". */
	public static function confirm( $token ) {
		global $wpdb;
		$sub = self::get_by_token( $token );
		if ( ! $sub ) return null;
		if ( $sub['status'] === 'pending' ) {
			$wpdb->update( self::t(), [ 'status' => 'active', 'confirmed_at' => dxo_now() ], [ 'id' => $sub['id'] ] );
			$sub = self::get( $sub['id'] );
			DXO_Campaigns::enqueue_welcome( $sub );
		}
		return $sub;
	}

	/**
	 * La baja. Si llega desde un correo concreto, se anota en ese envío para que
	 * el informe de la campaña la cuente.
	 */
	public static function unsubscribe( $subscriber_id, $recipient = null ) {
		global $wpdb;
		$wpdb->update( self::t(), [ 'status' => 'unsubscribed', 'unsubscribed_at' => dxo_now() ], [ 'id' => (int) $subscriber_id ] );
		if ( $recipient && empty( $recipient['unsubscribed_at'] ) ) {
			$wpdb->update( DXO_Install::table( 'recipients' ), [ 'unsubscribed_at' => dxo_now() ], [ 'id' => $recipient['id'] ] );
			DXO_Campaigns::log_event( (int) $recipient['campaign_id'], (int) $recipient['id'], 'unsub' );
		}
	}

	public static function set_status( $id, $status ) {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) return false;
		$data = [ 'status' => $status ];
		if ( $status === 'active' ) { $data['confirmed_at'] = dxo_now(); $data['soft_bounces'] = 0; }
		if ( $status === 'unsubscribed' ) $data['unsubscribed_at'] = dxo_now();
		return false !== $wpdb->update( self::t(), $data, [ 'id' => (int) $id ] );
	}

	// ═══ Listas de cada uno ═════════════════════════════════════════════════

	public static function add_to_lists( $id, array $list_ids ) {
		global $wpdb;
		$t = DXO_Install::table( 'list_subscriber' );
		foreach ( array_unique( array_map( 'intval', $list_ids ) ) as $lid ) {
			if ( ! $lid ) continue;
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $t (list_id, subscriber_id, created_at) VALUES (%d, %d, %s)", $lid, $id, dxo_now() ) );
		}
	}

	public static function set_lists( $id, array $list_ids ) {
		global $wpdb;
		$wpdb->delete( DXO_Install::table( 'list_subscriber' ), [ 'subscriber_id' => (int) $id ] );
		self::add_to_lists( $id, $list_ids );
	}

	public static function list_ids( $id ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT list_id FROM ' . DXO_Install::table( 'list_subscriber' ) . ' WHERE subscriber_id = %d', $id ) ) );
	}

	// ═══ Consultas ══════════════════════════════════════════════════════════

	/** Cuántos hay en cada estado, para los filtros con contador. */
	public static function counts() {
		global $wpdb;
		$out = array_fill_keys( self::STATUSES, 0 );
		foreach ( $wpdb->get_results( 'SELECT status, COUNT(*) n FROM ' . self::t() . ' GROUP BY status', ARRAY_A ) as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Una página de suscriptores.
	 *
	 * @param array $q status, list_id, search, page, per_page
	 * @return array [ 'rows' => [...], 'total' => n ]
	 */
	public static function query( array $q ) {
		global $wpdb;
		$q = wp_parse_args( $q, [ 'status' => 'active', 'list_id' => 0, 'search' => '', 'page' => 1, 'per_page' => 25 ] );

		$where = [ '1=1' ];
		$args  = [];
		if ( $q['status'] && in_array( $q['status'], self::STATUSES, true ) ) {
			$where[] = 's.status = %s';
			$args[]  = $q['status'];
		}
		$join = '';
		if ( $q['list_id'] ) {
			$join    = 'JOIN ' . DXO_Install::table( 'list_subscriber' ) . ' ls ON ls.subscriber_id = s.id AND ls.list_id = %d';
			array_unshift( $args, (int) $q['list_id'] );
		}
		if ( $q['search'] !== '' ) {
			$like    = '%' . $wpdb->esc_like( $q['search'] ) . '%';
			$where[] = '(s.email LIKE %s OR s.first_name LIKE %s OR s.last_name LIKE %s)';
			array_push( $args, $like, $like, $like );
		}

		$sqlWhere = implode( ' AND ', $where );
		$base     = 'FROM ' . self::t() . " s $join WHERE $sqlWhere";
		$total    = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) $base", $args ) : "SELECT COUNT(*) $base" );

		$per    = max( 1, min( 200, (int) $q['per_page'] ) );
		$offset = ( max( 1, (int) $q['page'] ) - 1 ) * $per;
		$sql    = "SELECT s.* $base ORDER BY s.created_at DESC, s.id DESC LIMIT $per OFFSET $offset";
		$rows   = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ) ?: [];

		return [ 'rows' => self::decorate( $rows ), 'total' => $total ];
	}

	/** Añade a cada fila sus listas y su nivel de interés. */
	public static function decorate( array $rows ) {
		global $wpdb;
		if ( ! $rows ) return $rows;
		$ids   = implode( ',', array_map( 'intval', array_column( $rows, 'id' ) ) );
		$names = DXO_Lists::names();

		$lists = [];
		foreach ( $wpdb->get_results( 'SELECT subscriber_id, list_id FROM ' . DXO_Install::table( 'list_subscriber' ) . " WHERE subscriber_id IN ($ids)", ARRAY_A ) as $r ) {
			if ( isset( $names[ (int) $r['list_id'] ] ) ) $lists[ (int) $r['subscriber_id'] ][] = $names[ (int) $r['list_id'] ];
		}

		$scores = self::interest( array_map( 'intval', array_column( $rows, 'id' ) ) );
		foreach ( $rows as &$r ) {
			$r['lists']    = $lists[ (int) $r['id'] ] ?? [];
			$r['interest'] = $scores[ (int) $r['id'] ] ?? null;
		}
		return $rows;
	}

	/**
	 * Interés de 0 a 5 según los últimos 5 correos que recibió: cada uno abierto
	 * suma, y con clic suma el doble. null = todavía no ha recibido ninguno.
	 */
	public static function interest( array $ids ) {
		global $wpdb;
		if ( ! $ids ) return [];
		$t   = DXO_Install::table( 'recipients' );
		$in  = implode( ',', array_map( 'intval', $ids ) );
		$out = [];
		$per = [];
		foreach ( $wpdb->get_results( "SELECT subscriber_id, opened_at, clicked_at FROM $t WHERE subscriber_id IN ($in) AND status = 'sent' ORDER BY sent_at DESC", ARRAY_A ) as $r ) {
			$sid = (int) $r['subscriber_id'];
			if ( ( $per[ $sid ] ?? 0 ) >= 5 ) continue;
			$per[ $sid ] = ( $per[ $sid ] ?? 0 ) + 1;
			$out[ $sid ] = ( $out[ $sid ] ?? 0 ) + ( $r['clicked_at'] ? 2 : ( $r['opened_at'] ? 1 : 0 ) );
		}
		foreach ( $out as $sid => $points ) {
			$out[ $sid ] = (int) round( $points / ( $per[ $sid ] * 2 ) * 5 );
		}
		return $out;
	}

	// ═══ Alta a mano, borrar, importar ══════════════════════════════════════

	/**
	 * Borra a la persona del todo. Sus envíos se quedan para que los informes no
	 * cambien, pero sin su correo.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( DXO_Install::table( 'list_subscriber' ), [ 'subscriber_id' => (int) $id ] );
		$wpdb->update( DXO_Install::table( 'recipients' ), [ 'email' => '' ], [ 'subscriber_id' => (int) $id ] );
		return (bool) $wpdb->delete( self::t(), [ 'id' => (int) $id ] );
	}

	/**
	 * Importa un CSV. Busca la columna del correo por su cabecera (email, e-mail,
	 * correo...) o, si no hay cabecera, la primera columna que tenga una @. El
	 * nombre, igual. Entran como activos: quien importa confirma que tiene permiso.
	 *
	 * @return array added, updated, skipped (de baja o rebotados: no se tocan), invalid
	 */
	public static function import_csv( $path, array $list_ids ) {
		$res = [ 'added' => 0, 'updated' => 0, 'skipped' => 0, 'invalid' => 0 ];
		$fh  = fopen( $path, 'r' );
		if ( ! $fh ) return $res;

		$first = fgets( $fh );
		if ( $first === false ) return $res;
		$first = preg_replace( '/^\xEF\xBB\xBF/', '', $first ); // BOM de Excel
		$delim = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ( substr_count( $first, "\t" ) > substr_count( $first, ',' ) ? "\t" : ',' );
		$head  = str_getcsv( trim( $first ), $delim );

		$col = [ 'email' => -1, 'first' => -1, 'last' => -1, 'name' => -1 ];
		foreach ( $head as $i => $h ) {
			$h = strtolower( trim( remove_accents( $h ) ) );
			if ( $col['email'] < 0 && preg_match( '/^(e-?mail|correo|email address|direccion de correo)/', $h ) ) $col['email'] = $i;
			elseif ( $col['first'] < 0 && preg_match( '/^(first|first name|nombre|nombres)$/', $h ) ) $col['first'] = $i;
			elseif ( $col['last'] < 0 && preg_match( '/^(last|last name|apellido|apellidos|surname)$/', $h ) ) $col['last'] = $i;
			elseif ( $col['name'] < 0 && preg_match( '/^(name|full name|nombre completo)$/', $h ) ) $col['name'] = $i;
		}
		$rows = [];
		if ( $col['email'] < 0 ) {
			// Sin cabecera reconocible: la primera fila ya es un dato.
			foreach ( $head as $i => $v ) {
				if ( strpos( $v, '@' ) !== false ) { $col['email'] = $i; break; }
			}
			$rows[] = $head;
		}
		if ( $col['email'] < 0 ) {
			fclose( $fh );
			$res['invalid'] = -1;
			return $res;
		}

		while ( ( $line = fgetcsv( $fh, 0, $delim ) ) !== false ) $rows[] = $line;
		fclose( $fh );

		foreach ( $rows as $r ) {
			$email = strtolower( trim( (string) ( $r[ $col['email'] ] ?? '' ) ) );
			if ( ! is_email( $email ) ) { $res['invalid']++; continue; }
			$first = $col['first'] >= 0 ? trim( (string) ( $r[ $col['first'] ] ?? '' ) ) : '';
			$last  = $col['last'] >= 0 ? trim( (string) ( $r[ $col['last'] ] ?? '' ) ) : '';
			if ( $first === '' && $col['name'] >= 0 ) {
				$first = trim( (string) ( $r[ $col['name'] ] ?? '' ) );
			}
			// "Nombre" sin columna de apellido suele traer el nombre completo: se parte
			// en el primer espacio para que {first_name} diga "Laura" y no "Laura Gómez".
			if ( $col['last'] < 0 && $last === '' && strpos( $first, ' ' ) !== false ) {
				list( $first, $last ) = preg_split( '/\s+/', $first, 2 );
			}
			$sub = self::get_by_email( $email );
			if ( $sub && in_array( $sub['status'], [ 'unsubscribed', 'bounced' ], true ) ) {
				$res['skipped']++;
				continue;
			}
			if ( $sub ) {
				self::add_to_lists( (int) $sub['id'], $list_ids );
				if ( $sub['status'] === 'pending' ) self::set_status( (int) $sub['id'], 'active' );
				$res['updated']++;
				continue;
			}
			self::add_imported( $email, $first, $last, $list_ids );
			$res['added']++;
		}
		return $res;
	}

	/** Alta directa como activo, sin correo de confirmación ni bienvenida. */
	public static function add_imported( $email, $first, $last, array $list_ids, $source = 'import' ) {
		global $wpdb;
		$wpdb->insert( self::t(), [
			'email'        => $email,
			'first_name'   => mb_substr( $first, 0, 100 ),
			'last_name'    => mb_substr( $last, 0, 100 ),
			'status'       => 'active',
			'source'       => $source,
			'token'        => dxo_token(),
			'created_at'   => dxo_now(),
			'confirmed_at' => dxo_now(),
		] );
		$id = (int) $wpdb->insert_id;
		if ( $id ) self::add_to_lists( $id, $list_ids ?: [ DXO_Lists::default_id() ] );
		return $id;
	}
}
