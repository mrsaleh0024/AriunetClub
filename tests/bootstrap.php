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
if ( ! function_exists( 'apply_filters' ) ) {
        function apply_filters( $hook_name, $value, ...$args ) {
                return $value; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
        function wp_json_encode( $data, $flags = 0 ) {
                return json_encode( $data, $flags ); }
}
if ( ! function_exists( 'get_transient' ) ) {
        function get_transient( $transient ) {
                return false; }
}
if ( ! function_exists( 'set_transient' ) ) {
        function set_transient( $transient, $value, $expiration = 0 ) {
                return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
        function delete_transient( $transient ) {
                return true; }
}
if ( ! function_exists( 'wp_parse_args' ) ) {
        function wp_parse_args( $args, $defaults = array() ) {
                if ( is_object( $args ) ) {
                        $args = get_object_vars( $args );
                }
                if ( ! is_array( $args ) ) {
                        $args = array();
                }
                return array_merge( (array) $defaults, $args );
        }
}
if ( ! function_exists( 'get_option' ) ) {
        function get_option( $option, $default = false ) {
                return $default; }
}
require_once __DIR__ . '/unit/Fixtures.php';
