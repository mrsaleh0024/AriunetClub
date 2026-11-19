<?php
/**
 * @package LClub
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! current_user_can( 'activate_plugins' ) ) {
	return;
}

$settings = (array) get_option( 'lclub_settings', array() );
if ( empty( $settings['delete_data'] ) ) {
	return;
}

global $wpdb;

$tables = array( 'lclub_events', 'lclub_ledger', 'lclub_balances', 'lclub_rules' );
foreach ( $tables as $table ) {
	$table_name = $wpdb->prefix . $table;
	$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefix and names are fixed constants.
}

delete_option( 'lclub_settings' );
delete_option( 'lclub_db_version' );
