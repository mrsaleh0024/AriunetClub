<?php
/**
 * Refuse to run against the Local site database. Integration tests may truncate tables.
 */
$lclub_test_db_name = defined( 'DB_NAME' ) ? DB_NAME : getenv( 'LCLUB_TEST_DB_NAME' );
if ( ! is_string( $lclub_test_db_name ) || 0 !== strpos( $lclub_test_db_name, 'lclub_test_' ) ) {
	throw new RuntimeException( 'LClub tests require a database whose name starts with lclub_test_.' );
}

define( 'LCLUB_VERSION', '0.1.0' );
define( 'LCLUB_FILE', __DIR__ . '/../lclub.php' );
define( 'LCLUB_URL', 'https://example.test/wp-content/plugins/lclub/' );
define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( 'wc_get_price_decimals' ) ) {
	function wc_get_price_decimals() {
		return 0; }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( $number, $decimals, '.', ',' ); }
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook_name, ...$args ) {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
}
