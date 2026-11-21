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

// Defensive cleanup of scheduled work in case a deactivate hook never ran.
wp_clear_scheduled_hook( 'ariunetclub_process_events' );
wp_clear_scheduled_hook( 'ariunetclub_release_expired_hold' );
wp_clear_scheduled_hook( 'lclub_process_events' );
wp_clear_scheduled_hook( 'lclub_release_expired_hold' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'ariunetclub_process_events', array(), 'ariunetclub' );
	as_unschedule_all_actions( 'ariunetclub_release_expired_hold', array(), 'ariunetclub' );
	as_unschedule_all_actions( 'lclub_process_events', array(), 'lclub' );
	as_unschedule_all_actions( 'lclub_release_expired_hold', array(), 'lclub' );
}

delete_option( 'ariunetclub_settings' );
delete_option( 'ariunetclub_db_version' );
delete_option( 'ariunetclub_rewrite_version' );
delete_option( 'lclub_settings' );
delete_option( 'lclub_db_version' );
