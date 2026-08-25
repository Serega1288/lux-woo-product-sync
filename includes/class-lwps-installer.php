<?php

defined( 'ABSPATH' ) || exit;

final class LWPS_Installer {
	const DB_VERSION = '1.0.0';

	public static function activate() {
		self::create_tables();
		update_option( 'lwps_db_version', self::DB_VERSION, false );
	}

	public static function maybe_upgrade() {
		if ( self::DB_VERSION !== get_option( 'lwps_db_version' ) ) {
			self::activate();
		}
	}

	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$changes = $wpdb->prefix . 'lwps_changes';
		$jobs    = $wpdb->prefix . 'lwps_jobs';
		$items   = $wpdb->prefix . 'lwps_job_items';

		dbDelta(
			"CREATE TABLE {$changes} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				remote_uid varchar(36) NOT NULL,
				local_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				product_name text NOT NULL,
				product_type varchar(32) NOT NULL DEFAULT 'simple',
				change_status varchar(32) NOT NULL,
				donor_hash char(64) NOT NULL DEFAULT '',
				local_hash char(64) NOT NULL DEFAULT '',
				donor_variations int(10) unsigned NOT NULL DEFAULT 0,
				local_variations int(10) unsigned NOT NULL DEFAULT 0,
				variation_added int(10) unsigned NOT NULL DEFAULT 0,
				variation_updated int(10) unsigned NOT NULL DEFAULT 0,
				variation_removed int(10) unsigned NOT NULL DEFAULT 0,
				is_locked tinyint(1) unsigned NOT NULL DEFAULT 0,
				details_json longtext NULL,
				analyzed_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY remote_uid (remote_uid),
				KEY change_status (change_status),
				KEY local_product_id (local_product_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$jobs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				operation varchar(32) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				total_items int(10) unsigned NOT NULL DEFAULT 0,
				processed_items int(10) unsigned NOT NULL DEFAULT 0,
				success_items int(10) unsigned NOT NULL DEFAULT 0,
				failed_items int(10) unsigned NOT NULL DEFAULT 0,
				summary_json longtext NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				started_at datetime NULL,
				completed_at datetime NULL,
				PRIMARY KEY  (id),
				KEY status (status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$items} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_id bigint(20) unsigned NOT NULL,
				remote_uid varchar(36) NOT NULL,
				local_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				operation varchar(32) NOT NULL,
				options_json longtext NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				error_message text NULL,
				result_json longtext NULL,
				started_at datetime NULL,
				completed_at datetime NULL,
				PRIMARY KEY  (id),
				KEY job_status (job_id, status),
				KEY remote_uid (remote_uid)
			) {$charset};"
		);
	}
}
