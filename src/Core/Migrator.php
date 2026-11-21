<?php
/**
 * @package LClub
 */

namespace LClub\Core;

class Migrator {
	public function maybe_migrate(): void {
		if ( self::db_version() !== get_option( 'lclub_db_version' ) ) {
				$this->migrate();
		}
	}

	public function migrate(): void {
			global $wpdb;

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$charset_collate = $wpdb->get_charset_collate();
			$events          = $wpdb->prefix . 'lclub_events';
			$ledger          = $wpdb->prefix . 'lclub_ledger';
			$balances        = $wpdb->prefix . 'lclub_balances';
			$rules           = $wpdb->prefix . 'lclub_rules';

			$sql = "CREATE TABLE {$events} (
                        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        type varchar(100) NOT NULL,
                        user_id bigint(20) unsigned NULL,
                        object_type varchar(50) NOT NULL,
                        object_id bigint(20) unsigned NULL,
                        payload longtext NULL,
                        idempotency_key varchar(191) NOT NULL,
                        status varchar(20) NOT NULL DEFAULT 'pending',
                        attempts int(11) NOT NULL DEFAULT 0,
                        error text NULL,
                        created_at datetime NOT NULL,
                        processed_at datetime NULL,
                        PRIMARY KEY  (id),
                        UNIQUE KEY idempotency_key (idempotency_key),
                        KEY status_created_at (status,created_at),
                        KEY object_lookup (object_type,object_id)
                ) {$charset_collate};
                CREATE TABLE {$ledger} (
                        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        user_id bigint(20) unsigned NOT NULL,
                        asset varchar(32) NOT NULL,
                        entry_type varchar(20) NOT NULL,
                        amount decimal(20,8) NOT NULL,
                        balance_after decimal(20,8) NULL,
                        ref_type varchar(50) NULL,
                        ref_id bigint(20) unsigned NULL,
                        rule_id bigint(20) unsigned NULL,
                        idempotency_key varchar(191) NOT NULL,
                        note text NULL,
                        created_by bigint(20) unsigned NULL,
                        created_at datetime NOT NULL,
                        PRIMARY KEY  (id),
                        UNIQUE KEY idempotency_key (idempotency_key),
                        KEY user_asset_created (user_id,asset,created_at),
                        KEY reference (ref_type,ref_id)
                ) {$charset_collate};
                CREATE TABLE {$balances} (
                        user_id bigint(20) unsigned NOT NULL,
                        asset varchar(32) NOT NULL,
                        available decimal(20,8) NOT NULL DEFAULT 0,
                        held decimal(20,8) NOT NULL DEFAULT 0,
                        updated_at datetime NOT NULL,
                        PRIMARY KEY  (user_id,asset)
                ) {$charset_collate};
                CREATE TABLE {$rules} (
                        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        title varchar(191) NOT NULL,
                        status varchar(20) NOT NULL DEFAULT 'draft',
                        event_type varchar(100) NOT NULL,
                        conditions longtext NULL,
                        reward longtext NULL,
                        limits longtext NULL,
                        priority int(11) NOT NULL DEFAULT 10,
                        exclusive tinyint(1) NOT NULL DEFAULT 0,
                        starts_at datetime NULL,
                        ends_at datetime NULL,
                        PRIMARY KEY  (id),
                        KEY active_rules (status,event_type,priority)
                ) {$charset_collate};";

			dbDelta( $sql );
			update_option( 'lclub_db_version', self::db_version(), false );
	}

	public static function db_version(): string {
			return Plugin::DB_VERSION;
	}
}
