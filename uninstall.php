<?php
/**
 * @package AriunetClub
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
}

$settings = (array) get_option( 'ariunetclub_settings', get_option( 'lclub_settings', array() ) );
if ( empty( $settings['delete_data'] ) ) {
		return;
}

global $wpdb;

// Modern tables plus the pre-rebrand (phase 1-3) names, so nothing survives
// an explicit data deletion on upgraded installations.
$tables = array(
	'ariunetclub_events',
	'ariunetclub_ledger',
	'ariunetclub_balances',
	'ariunetclub_rules',
	'lclub_events',
	'lclub_ledger',
	'lclub_balances',
	'lclub_rules',
);
foreach ( $tables as $table ) {
		$table_name = $wpdb->prefix . $table;
		$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefix and names are fixed constants.
}

delete_option( 'ariunetclub_settings' );
delete_option( 'ariunetclub_db_version' );
delete_option( 'ariunetclub_rewrite_version' );
delete_option( 'lclub_settings' );
delete_option( 'lclub_db_version' );
