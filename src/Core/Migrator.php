<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Core;

class Migrator {
	public function maybe_migrate(): void {
		if ( self::db_version() !== get_option( 'ariunetclub_db_version' ) ) {
										$this->migrate();
		}
	}

	public function migrate(): void {
									global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
										return; // no database handle, nothing safe to migrate.
		}

									require_once ABSPATH . 'wp-admin/includes/upgrade.php';
									$this->migrate_legacy();

									$charset_collate = $wpdb->get_charset_collate();
									$events          = $wpdb->prefix . 'ariunetclub_events';
									$ledger          = $wpdb->prefix . 'ariunetclub_ledger';
									$balances        = $wpdb->prefix . 'ariunetclub_balances';
									$rules           = $wpdb->prefix . 'ariunetclub_rules';
									$lots            = $wpdb->prefix . 'ariunetclub_credit_lots';
									$allocations     = $wpdb->prefix . 'ariunetclub_lot_allocations';

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
                KEY reference (ref_type,ref_id),
                KEY ledger_created_at (created_at)
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
) {$charset_collate};
CREATE TABLE {$lots} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL,
                source_type varchar(32) NOT NULL DEFAULT 'rule',
                source_ref bigint(20) unsigned NULL,
                ref_type varchar(50) NULL,
                ref_id bigint(20) unsigned NULL,
                rule_id bigint(20) unsigned NULL,
                initial_amount decimal(20,8) NOT NULL DEFAULT 0,
                remaining decimal(20,8) NOT NULL DEFAULT 0,
                held decimal(20,8) NOT NULL DEFAULT 0,
                expires_at datetime NULL,
                status varchar(20) NOT NULL DEFAULT 'active',
                reason varchar(191) NULL,
                campaign_id bigint(20) unsigned NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY lot_user_status (user_id,status),
                KEY lot_expiry (status,expires_at),
                KEY lot_reference (ref_type,ref_id),
                KEY lot_source (source_type,source_ref)
) {$charset_collate};
CREATE TABLE {$allocations} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                lot_id bigint(20) unsigned NOT NULL,
                user_id bigint(20) unsigned NOT NULL,
                order_id bigint(20) unsigned NOT NULL DEFAULT 0,
                amount decimal(20,8) NOT NULL,
                state varchar(20) NOT NULL DEFAULT 'held',
                created_at datetime NOT NULL,
                released_at datetime NULL,
                PRIMARY KEY  (id),
                KEY alloc_order (order_id,state),
                KEY alloc_lot (lot_id,state),
                KEY alloc_user (user_id)
) {$charset_collate};";

									dbDelta( $sql );
									$this->migrate_fee_title_vocabulary();
									update_option( 'ariunetclub_db_version', self::db_version(), false );
	}

		/**
		 * Phase 6 vocabulary migration (4.1): the default checkout fee title moves
		 * from the pre-phase-6 default to the new «اعتبار باشگاه» wording. Only an
		 * untouched default is swapped; a shop that customised the title keeps its
		 * own words.
		 */
	private function migrate_fee_title_vocabulary(): void {
			$settings = get_option( Settings::OPTION, array() );
		if ( ! is_array( $settings ) || ! isset( $settings['wallet_fee_title'] ) ) {
				return;
		}
		if ( is_string( $settings['wallet_fee_title'] ?? null ) && 'استفاده از کیف پول' === $settings['wallet_fee_title'] ) {
				$settings['wallet_fee_title'] = 'استفاده از اعتبار باشگاه';
				update_option( Settings::OPTION, $settings, false );
		}
	}

	public static function db_version(): string {
									return Plugin::DB_VERSION;
	}

								/**
								 * One-time migration from the pre-rebrand `lclub` prefix (phases 1-3).
								 * Every step is guarded by an existence check, so calling it repeatedly
								 * (activation hooks, version bumps) is safe and effectively a no-op
								 * once the data has moved.
								 */
	private function migrate_legacy(): void {
									$this->migrate_legacy_options();
									$this->migrate_legacy_scheduled_actions();
									$this->migrate_legacy_cron_events();
									$this->migrate_legacy_order_data();
									$this->migrate_legacy_tables();
	}

								/**
								 * Copies the phase 1-3 settings option forward; the stored value wins
								 * only while no new option exists yet.
								 */
	private function migrate_legacy_options(): void {
									$legacy = get_option( 'lclub_settings', false );
		if ( false !== $legacy && false === get_option( 'ariunetclub_settings', false ) ) {
										add_option( 'ariunetclub_settings', $legacy, '', false );
		}
	}

								/**
								 * Moves pending Action Scheduler entries to the new hook names so open
								 * wallet holds keep their expiry timers, and drops the legacy recurring
								 * processor (the events module schedules its own on boot).
								 */
	private function migrate_legacy_scheduled_actions(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
										return;
		}

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
										as_unschedule_all_actions( 'lclub_process_events', array(), 'lclub' );
		}

									$actions = as_get_scheduled_actions(
										array(
											'hook'     => 'lclub_release_expired_hold',
											'status'   => 'pending',
											'group'    => 'lclub',
											'per_page' => -1,
										),
										'object'
									);
		foreach ( $actions as $action ) {
										$args = is_object( $action ) && method_exists( $action, 'get_args' ) ? $action->get_args() : array();
										$args = is_array( $args ) ? array_values( array_map( 'absint', $args ) ) : array();
										$when = self::legacy_action_timestamp( $action );
										// Past-due expiry actions are rescheduled too; Action Scheduler
										// runs them immediately and the overdue hold is released.
			if ( null !== $when && function_exists( 'as_schedule_single_action' ) ) {
								as_schedule_single_action( $when, 'ariunetclub_release_expired_hold', $args, 'ariunetclub' );
			}
			if ( function_exists( 'as_unschedule_action' ) ) {
											as_unschedule_action( 'lclub_release_expired_hold', $args, 'lclub' );
			}
		}
	}

								/**
								 * WP-Cron fallback entries from installations without Action Scheduler.
								 */
	private function migrate_legacy_cron_events(): void {
									$cron = get_option( 'cron', array() );
		if ( ! is_array( $cron ) ) {
										return;
		}

		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_numeric( $timestamp ) || ! is_array( $hooks ) ) {
											continue;
			}
			foreach ( $hooks as $hook => $signatures ) {
				if ( ! is_array( $signatures ) ) {
												continue;
				}
				if ( 'lclub_process_events' === $hook ) {
												wp_clear_scheduled_hook( $hook );
												continue;
				}
				if ( 'lclub_release_expired_hold' !== $hook ) {
												continue;
				}
				foreach ( array_values( $signatures ) as $data ) {
												$args = is_array( $data ) && isset( $data['args'] ) && is_array( $data['args'] ) ? array_values( array_map( 'absint', $data['args'] ) ) : array();
												wp_schedule_single_event( (int) $timestamp, 'ariunetclub_release_expired_hold', $args );
												wp_unschedule_event( (int) $timestamp, $hook, $args );
				}
			}
		}
	}

								/**
								 * Rewrites the payment gateway id and the wallet order-meta keys that
								 * past orders stored under the legacy prefix, in both the legacy
								 * postmeta storage and the HPOS tables when they exist.
								 */
	private function migrate_legacy_order_data(): void {
									global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
										return;
		}

									// Every value below is a compile-time literal; no user input is involved.
									$wpdb->query( "UPDATE {$wpdb->postmeta} SET meta_value = 'ariunetclub_wallet' WHERE meta_key = '_payment_method' AND meta_value = 'lclub_wallet'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed literals plus the WordPress-controlled table name.
		foreach ( self::legacy_meta_pairs() as $legacy_key => $modern_key ) {
										$wpdb->query( "UPDATE {$wpdb->postmeta} SET meta_key = '{$modern_key}' WHERE meta_key = '{$legacy_key}'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed literals plus the WordPress-controlled table name.
		}

									$orders_table = $wpdb->prefix . 'wc_orders';
		if ( $this->table_exists( $orders_table ) ) {
										$wpdb->query( "UPDATE {$orders_table} SET payment_method = 'ariunetclub_wallet' WHERE payment_method = 'lclub_wallet'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed literals; table name is prefix plus a fixed suffix.
										$meta_table = $wpdb->prefix . 'wc_orders_meta';
			if ( $this->table_exists( $meta_table ) ) {
				foreach ( self::legacy_meta_pairs() as $legacy_key => $modern_key ) {
					$wpdb->query( "UPDATE {$meta_table} SET meta_key = '{$modern_key}' WHERE meta_key = '{$legacy_key}'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is prefix plus a fixed suffix; fixed literal values.
				}
			}
		}
	}

								/**
								 * Renames the four phase 1-3 tables in place, preserving every row.
								 * A table is only renamed while the legacy one exists and the modern
								 * one does not, so fresh installs and repeat runs are unaffected.
								 */
	private function migrate_legacy_tables(): void {
									global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
										return;
		}

		foreach ( array( 'events', 'ledger', 'balances', 'rules' ) as $suffix ) {
										$legacy = $wpdb->prefix . 'lclub_' . $suffix;
										$modern = $wpdb->prefix . 'ariunetclub_' . $suffix;
			if ( ! $this->table_exists( $legacy ) || $this->table_exists( $modern ) ) {
											continue;
			}
										$renamed = $wpdb->query( "RENAME TABLE `{$legacy}` TO `{$modern}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are prefix plus fixed suffixes.
			if ( false === $renamed ) {
											( new Logger() )->error( 'Unable to rename legacy table; the new empty table will be created instead.', array( 'table' => $suffix ) );
			}
		}
	}

								/**
								 * @return array<string,string> Legacy meta key => modern meta key.
								 */
	private static function legacy_meta_pairs(): array {
									return array(
										'_lclub_wallet_state'       => '_ariunetclub_wallet_state',
										'_lclub_wallet_amount'      => '_ariunetclub_wallet_amount',
										'_lclub_wallet_order_total' => '_ariunetclub_wallet_order_total',
										'_lclub_wallet_held_at'     => '_ariunetclub_wallet_held_at',
									);
	}

								/**
								 * @param mixed $action Action Scheduler action object.
								 */
	private static function legacy_action_timestamp( $action ): ?int {
		if ( ! is_object( $action ) || ! method_exists( $action, 'get_schedule' ) ) {
										return null;
		}
									$schedule = $action->get_schedule();
		if ( ! is_object( $schedule ) || ! method_exists( $schedule, 'get_date' ) ) {
										return null;
		}
		try {
										$date = $schedule->get_date();
		} catch ( \Throwable $error ) {
										return null;
		}
									return $date instanceof \DateTimeInterface ? $date->getTimestamp() : null;
	}

	private function table_exists( string $table ): bool {
									global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
										return false;
		}
									$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- existence probe; value prepared.
									return is_string( $found ) && $found === $table;
	}
}
