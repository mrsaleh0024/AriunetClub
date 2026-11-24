<?php
/**
 * Refuse to run against the Local site database. Integration tests may truncate tables.
 */
$ariunetclub_test_db_name = defined( 'DB_NAME' ) ? DB_NAME : getenv( 'ARIUNETCLUB_TEST_DB_NAME' );
if ( ! is_string( $ariunetclub_test_db_name ) || 0 !== strpos( $ariunetclub_test_db_name, 'ariunetclub_test_' ) ) {
        throw new RuntimeException( 'AriunetClub tests require a database whose name starts with ariunetclub_test_.' );
}

define( 'ARIUNETCLUB_VERSION', '0.1.0' );
define( 'ARIUNETCLUB_FILE', __DIR__ . '/../ariunetclub.php' );
define( 'ARIUNETCLUB_URL', 'https://example.test/wp-content/plugins/ariunetclub/' );
define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ARRAY_A' ) ) {
        define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! class_exists( 'wpdb' ) ) {
        class wpdb {}
}

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
if ( ! function_exists( 'as_enqueue_async_action' ) ) {
        $GLOBALS['ariunetclub_test_async_actions'] = array();
        function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false ) {
                if ( ! isset( $GLOBALS['ariunetclub_test_async_actions'] ) || ! is_array( $GLOBALS['ariunetclub_test_async_actions'] ) ) {
                        $GLOBALS['ariunetclub_test_async_actions'] = array();
                }
                foreach ( $GLOBALS['ariunetclub_test_async_actions'] as $call ) {
                        $same = isset( $call['hook'], $call['args'], $call['group'], $call['unique'] ) && $call['hook'] === $hook && $call['args'] === $args && $call['group'] === $group;
                        if ( $same && $unique && $call['unique'] ) {
                                return 0; // Action Scheduler refuses a duplicate unique action.
                        }
                }
                $GLOBALS['ariunetclub_test_async_actions'][] = array(
                        'hook'   => $hook,
                        'args'   => $args,
                        'group'  => $group,
                        'unique' => $unique,
                );
                return count( $GLOBALS['ariunetclub_test_async_actions'] );
        }
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
if ( ! function_exists( '__' ) ) {
        function __( $text, $domain = 'default' ) {
                return $text; }
}
if ( ! function_exists( 'get_option' ) ) {
        function get_option( $option, $default = false ) {
                return isset( $GLOBALS['ariunetclub_test_settings'][ $option ] ) ? $GLOBALS['ariunetclub_test_settings'][ $option ] : $default; }
}
if ( ! function_exists( 'add_option' ) ) {
        function add_option( $option, $value = '', $deprecated = '', $autoload = null ) {
                if ( ! isset( $GLOBALS['ariunetclub_test_settings'] ) || ! is_array( $GLOBALS['ariunetclub_test_settings'] ) ) {
                        $GLOBALS['ariunetclub_test_settings'] = array();
                }
                if ( array_key_exists( $option, $GLOBALS['ariunetclub_test_settings'] ) ) {
                        return false; // mirrors the duplicate-key INSERT failure.
                }
                $GLOBALS['ariunetclub_test_settings'][ $option ] = $value;
                return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
        function delete_option( $option ) {
                if ( ! isset( $GLOBALS['ariunetclub_test_settings'] ) || ! is_array( $GLOBALS['ariunetclub_test_settings'] ) || ! array_key_exists( $option, $GLOBALS['ariunetclub_test_settings'] ) ) {
                        return false; }
                unset( $GLOBALS['ariunetclub_test_settings'][ $option ] );
                return true; }
}
if ( ! function_exists( 'wc_get_orders' ) ) {
        $GLOBALS['ariunetclub_test_wc_get_orders_args'] = array();
        function wc_get_orders( $args = array() ) {
                if ( ! isset( $GLOBALS['ariunetclub_test_wc_get_orders_args'] ) || ! is_array( $GLOBALS['ariunetclub_test_wc_get_orders_args'] ) ) {
                        $GLOBALS['ariunetclub_test_wc_get_orders_args'] = array();
                }
                $GLOBALS['ariunetclub_test_wc_get_orders_args'][] = $args;
                return array();
        }
}
if ( ! function_exists( 'get_user_meta' ) ) {
        $GLOBALS['ariunetclub_test_user_meta'] = array();
        function get_user_meta( $user_id, $key, $single = false ) {
                return isset( $GLOBALS['ariunetclub_test_user_meta'][ (int) $user_id ][ $key ] ) ? $GLOBALS['ariunetclub_test_user_meta'][ (int) $user_id ][ $key ] : '';
        }
}
if ( ! function_exists( 'current_time' ) ) {
        function current_time( $type, $gmt = false ) {
                return $gmt ? gmdate( 'Y-m-d H:i:s' ) : date( 'Y-m-d H:i:s' ); }
}
if ( ! function_exists( 'get_query_var' ) ) {
        $GLOBALS['ariunetclub_test_query_vars'] = array();
        function get_query_var( $var, $default = '' ) {
                return isset( $GLOBALS['ariunetclub_test_query_vars'][ $var ] ) ? $GLOBALS['ariunetclub_test_query_vars'][ $var ] : $default;
        }
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
        define( 'DAY_IN_SECONDS', 86400 );
        define( 'MINUTE_IN_SECONDS', 60 );
        define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! function_exists( 'update_option' ) ) {
        function update_option( $option, $value, $autoload = null ) {
                $GLOBALS['ariunetclub_test_settings'][ $option ] = $value;
                return true; }
}
if ( ! function_exists( 'wp_salt' ) ) {
        function wp_salt( $scheme = 'auth' ) {
                return 'ariunetclub-test-salt-' . $scheme; }
}
if ( ! defined( 'ARIUNETCLUB_TEST_SITE_URL' ) ) {
        define( 'ARIUNETCLUB_TEST_SITE_URL', 'https://www.example.test' );
}
if ( ! function_exists( 'get_current_user_id' ) ) {
        function get_current_user_id() {
                return isset( $GLOBALS['ariunetclub_test_user_id'] ) ? (int) $GLOBALS['ariunetclub_test_user_id'] : 0;
        }
}
if ( ! function_exists( 'current_user_can' ) ) {
        function current_user_can( $capability, ...$args ) {
                return ! empty( $GLOBALS['ariunetclub_test_can_manage'] );
        }
}
if ( ! function_exists( 'esc_html' ) ) {
        function esc_html( $text ) {
                return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
        }
}
if ( ! function_exists( 'esc_attr' ) ) {
        function esc_attr( $text ) {
                return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
        }
}
if ( ! function_exists( 'esc_html__' ) ) {
        function esc_html__( $text, $domain = 'default' ) {
                return $text;
        }

        function sanitize_text_field( $str ) {
                if ( is_object( $str ) || is_array( $str ) ) {
                        return '';
                }
                $str = (string) $str;
                $str = preg_replace( '/<[a-zA-Z0-9\/][^>]*>/', '', $str );
                $str = preg_replace( '~[\r\n\t ]+~u', ' ', $str );
                return trim( $str );
        }
}
require_once __DIR__ . '/unit/Fixtures.php';
