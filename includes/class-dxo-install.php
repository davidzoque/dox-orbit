<?php
/**
 * Tablas y puesta al día.
 *
 * Todo lo del plugin vive en tablas propias con el prefijo `dxo_`: los
 * suscriptores, sus listas, las campañas, cada correo enviado (recipients),
 * los enlaces de cada campaña y los eventos (aperturas, clics, bajas, rebotes).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DXO_Install {

	/**
	 * Sube con cualquier cambio de esquema: maybe_upgrade() vuelve a pasar dbDelta.
	 * 3 (0.5.0): campaigns.format ('blocks' o 'html') y campaigns.html (el código pegado).
	 * Las campañas que ya existían quedan en 'blocks' y con html vacío.
	 */
	const DB_VERSION = '3';

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'dxo_' . $name;
	}

	public static function activate() {
		self::create_tables();
		DXO_Lists::ensure_default();
		DXO_Sender::schedule();
		DXO_Bounces::schedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( DXO_Sender::HOOK );
		wp_clear_scheduled_hook( DXO_Bounces::HOOK );
		wp_clear_scheduled_hook( DXO_Bounces::HOOK_MORE );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'dxo_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
			DXO_Lists::ensure_default();
		}
	}

	/**
	 * dbDelta es quisquilloso con el formato: dos espacios tras PRIMARY KEY,
	 * cada columna en su línea y KEY en vez de INDEX. Si no, no compara bien y
	 * cree cada vez que falta una columna.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$c = $wpdb->get_charset_collate();

		$sql = [];

		$sql[] = 'CREATE TABLE ' . self::table( 'subscribers' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(190) NOT NULL,
  first_name varchar(100) NOT NULL DEFAULT '',
  last_name varchar(100) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'pending',
  source varchar(60) NOT NULL DEFAULT '',
  lang varchar(12) NOT NULL DEFAULT '',
  token char(32) NOT NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  confirmed_at datetime DEFAULT NULL,
  unsubscribed_at datetime DEFAULT NULL,
  confirm_sent_at datetime DEFAULT NULL,
  soft_bounces smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY email (email),
  UNIQUE KEY token (token),
  KEY status (status),
  KEY created_at (created_at)
) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'lists' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(120) NOT NULL,
  description varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id)
) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'list_subscriber' ) . " (
  list_id bigint(20) unsigned NOT NULL,
  subscriber_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (list_id,subscriber_id),
  KEY subscriber_id (subscriber_id)
) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'campaigns' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(20) NOT NULL DEFAULT 'regular',
  subject varchar(255) NOT NULL DEFAULT '',
  preheader varchar(255) NOT NULL DEFAULT '',
  blocks longtext NOT NULL,
  format varchar(10) NOT NULL DEFAULT 'blocks',
  html longtext NOT NULL,
  list_ids varchar(255) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'draft',
  scheduled_at datetime DEFAULT NULL,
  started_at datetime DEFAULT NULL,
  finished_at datetime DEFAULT NULL,
  total int(10) unsigned NOT NULL DEFAULT 0,
  sent int(10) unsigned NOT NULL DEFAULT 0,
  failed int(10) unsigned NOT NULL DEFAULT 0,
  bounced int(10) unsigned NOT NULL DEFAULT 0,
  test_sent_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY type (type)
) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'recipients' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL,
  subscriber_id bigint(20) unsigned NOT NULL,
  email varchar(190) NOT NULL,
  token char(32) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'queued',
  error varchar(255) NOT NULL DEFAULT '',
  queued_at datetime NOT NULL,
  sent_at datetime DEFAULT NULL,
  opened_at datetime DEFAULT NULL,
  open_count smallint(5) unsigned NOT NULL DEFAULT 0,
  clicked_at datetime DEFAULT NULL,
  click_count smallint(5) unsigned NOT NULL DEFAULT 0,
  unsubscribed_at datetime DEFAULT NULL,
  bounced_at datetime DEFAULT NULL,
  bounce_reason varchar(250) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  UNIQUE KEY campaign_subscriber (campaign_id,subscriber_id),
  KEY campaign_status (campaign_id,status),
  KEY subscriber_id (subscriber_id),
  KEY sent_at (sent_at)
) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'links' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL,
  url text NOT NULL,
  url_hash char(40) NOT NULL,
  clicks int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY campaign_url (campaign_id,url_hash)
) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'events' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL,
  recipient_id bigint(20) unsigned NOT NULL,
  type varchar(12) NOT NULL,
  link_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY campaign_type (campaign_id,type,created_at),
  KEY recipient_id (recipient_id)
) $c;";

		foreach ( $sql as $q ) {
			dbDelta( $q );
		}

		update_option( 'dxo_db_version', self::DB_VERSION, false );
	}
}
