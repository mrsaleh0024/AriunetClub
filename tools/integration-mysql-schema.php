<?php
/**
 * Shared MySQL schema for the phase 6 integration/load test drivers: the exact
 * production DDL the Migrator sends to dbDelta (phases 1-6).
 *
 * @param PDO $pdo Connected MySQL handle.
 * @return void
 */
function integration_create_mysql_schema( PDO $pdo ): void {
        $sql = <<<'SQL'
CREATE TABLE wp_ariunetclub_events (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE wp_ariunetclub_ledger (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE wp_ariunetclub_balances (
        user_id bigint(20) unsigned NOT NULL,
        asset varchar(32) NOT NULL,
        available decimal(20,8) NOT NULL DEFAULT 0,
        held decimal(20,8) NOT NULL DEFAULT 0,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (user_id,asset)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE wp_ariunetclub_rules (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE wp_ariunetclub_credit_lots (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE wp_ariunetclub_lot_allocations (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        foreach ( array_filter( array_map( 'trim', explode( ';', $sql ) ) ) as $statement ) {
                if ( '' !== $statement ) {
                        $pdo->exec( $statement );
                }
        }
}
