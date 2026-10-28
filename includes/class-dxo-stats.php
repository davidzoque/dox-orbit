<?php
/**
 * Los números del Resumen.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DXO_Stats {

	public static function active_count() {
		global $wpdb;
		static $n = null;
		if ( $n === null ) {
			$n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DXO_Install::table( 'subscribers' ) . " WHERE status = 'active'" );
		}
		return $n;
	}

	/**
	 * Suscriptores activos al final de cada uno de los últimos $days días.
	 * Se reconstruye con las fechas de alta y de baja de cada uno.
	 *
	 * @return array [ 'Y-m-d' => n, ... ]
	 */
	public static function growth( $days = 30 ) {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT confirmed_at, unsubscribed_at, status FROM ' . DXO_Install::table( 'subscribers' ) . ' WHERE confirmed_at IS NOT NULL', ARRAY_A ) ?: [];
		$tz   = wp_timezone();
		$out  = [];
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day = new DateTime( 'today -' . $i . ' days', $tz );
			$end = ( clone $day )->modify( '+1 day' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			$n   = 0;
			foreach ( $rows as $r ) {
				if ( $r['confirmed_at'] >= $end ) continue;
				if ( $r['unsubscribed_at'] && $r['unsubscribed_at'] < $end ) continue;
				// Los rebotados no tienen fecha de rebote: se quitan de toda la curva
				// para que el último día cuadre con el total de activos.
				if ( $r['status'] === 'bounced' ) continue;
				$n++;
			}
			$out[ $day->format( 'Y-m-d' ) ] = $n;
		}
		return $out;
	}

	/** Altas y bajas de los últimos $days días, y cuántos faltan por confirmar. */
	public static function movement( $days = 30 ) {
		global $wpdb;
		$t    = DXO_Install::table( 'subscribers' );
		$from = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		return [
			'new'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE confirmed_at >= %s", $from ) ),
			'gone'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE unsubscribed_at >= %s", $from ) ),
			'pending' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status = 'pending'" ),
		];
	}

	/** Apertura, clics y bajas medias de las últimas $n campañas enviadas. */
	public static function averages( $n = 5 ) {
		global $wpdb;
		$c   = DXO_Install::table( 'campaigns' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $c WHERE type = 'regular' AND status IN ('sent','sending','cancelled') AND sent > 0 ORDER BY started_at DESC LIMIT %d", $n ) );
		if ( ! $ids ) return null;
		$in  = implode( ',', array_map( 'intval', $ids ) );
		$row = $wpdb->get_row(
			'SELECT SUM(status = \'sent\') sent, SUM(status = \'sent\' AND opened_at IS NOT NULL) opened, SUM(status = \'sent\' AND clicked_at IS NOT NULL) clicked, SUM(unsubscribed_at IS NOT NULL) unsub
			 FROM ' . DXO_Install::table( 'recipients' ) . " WHERE campaign_id IN ($in)",
			ARRAY_A
		);
		$last = DXO_Campaigns::stats( (int) $ids[0] );
		return [
			'campaigns'    => count( $ids ),
			'sent'         => (int) $row['sent'],
			'opened'       => (int) $row['opened'],
			'clicked'      => (int) $row['clicked'],
			'unsub'        => (int) $row['unsub'],
			'last_clicked' => $last['clicked'],
		];
	}

	/**
	 * La salud de la lista en los últimos 90 días: rebotes (fallos al enviar),
	 * bajas y gente que lleva meses sin abrir nada.
	 */
	public static function health() {
		global $wpdb;
		$r    = DXO_Install::table( 'recipients' );
		$s    = DXO_Install::table( 'subscribers' );
		$from = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(status IN ('sent','failed')) total, SUM(status = 'failed') failed, SUM(unsubscribed_at IS NOT NULL) unsub, SUM(bounced_at IS NOT NULL) bounced FROM $r WHERE sent_at >= %s", $from ), ARRAY_A );

		// Activos que recibieron 3 o más correos en 6 meses y no abrieron ninguno.
		$six      = gmdate( 'Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS );
		$inactive = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM (SELECT r.subscriber_id FROM $r r JOIN $s s ON s.id = r.subscriber_id AND s.status = 'active'
			 WHERE r.status = 'sent' AND r.sent_at >= %s GROUP BY r.subscriber_id HAVING COUNT(*) >= 3 AND SUM(r.opened_at IS NOT NULL) = 0) x",
			$six
		) );
		$bounced = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $s WHERE status = 'bounced'" );

		$total = (int) $row['total'];
		$fail  = $total ? (int) $row['failed'] / $total : 0;
		$unsub = $total ? (int) $row['unsub'] / $total : 0;
		$bounce = $total ? (int) $row['bounced'] / $total : 0;
		$grade = 'good';
		if ( $fail > .02 || $unsub > .01 || $bounce > .02 ) $grade = 'watch';
		if ( $fail > .05 || $unsub > .02 || $bounce > .05 ) $grade = 'bad';

		return [
			'total'    => $total,
			'failed'   => (int) $row['failed'],
			'unsub'    => (int) $row['unsub'],
			'fail_pct' => $fail * 100,
			'unsub_pct'=> $unsub * 100,
			'bounces'  => (int) $row['bounced'],
			'bounce_pct'=> $bounce * 100,
			'inactive' => $inactive,
			'bounced'  => $bounced,
			'grade'    => $total ? $grade : 'none',
		];
	}

	/** La próxima campaña programada. */
	public static function next_scheduled() {
		global $wpdb;
		$id = $wpdb->get_var( 'SELECT id FROM ' . DXO_Install::table( 'campaigns' ) . " WHERE status = 'scheduled' ORDER BY scheduled_at ASC LIMIT 1" );
		return $id ? DXO_Campaigns::get( $id ) : null;
	}

	/** Las últimas $n campañas lanzadas, con sus números. */
	public static function recent( $n = 3 ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . DXO_Install::table( 'campaigns' ) . " WHERE type = 'regular' AND status IN ('sending','paused','sent','cancelled') ORDER BY started_at DESC LIMIT %d", $n ) );
		$out = [];
		foreach ( $ids as $id ) {
			$c          = DXO_Campaigns::get( $id );
			$c['stats'] = DXO_Campaigns::stats( $id );
			$out[]      = $c;
		}
		return $out;
	}
}
