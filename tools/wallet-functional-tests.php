<?php
/**
 * AriunetClub Phase 3 functional tests — real PHP, no WordPress.
 *
 * Loads the REAL plugin classes (Money, Settings, Logger, LedgerService,
 * LedgerGateway, OrderMeta, SpendCalculator, HoldManager, CheckoutController)
 * on top of a runtime-stubbed WooCommerce/WP layer, plus an in-memory ledger
 * that replicates the semantics of the real LedgerRepository (row locking
 * replaced by single-threaded execution).
 *
 * Run: php tools/wallet-functional-tests.php  (exit code 0 = all green)
 */

error_reporting( E_ALL );

// ---------------------------------------------------------------------------
// 1) WordPress / WooCommerce runtime stubs
// ---------------------------------------------------------------------------

$GLOBALS['ariunetclub_test_settings']      = array();
$GLOBALS['ariunetclub_test_orders']        = array();
$GLOBALS['ariunetclub_test_hooks']         = array();
$GLOBALS['ariunetclub_test_transients']    = array();
$GLOBALS['ariunetclub_test_user_logged_in'] = true;

class JsonSentException extends Exception {
        public $payload;
        public $code_http;
        public function __construct( $payload, $http = 200 ) {
                $this->payload   = $payload;
                $this->code_http = $http;
                parent::__construct( 'json_sent' );
        }
}

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_html_e( $text, $domain = null ) { echo $text; }
function esc_html( $text ) { return $text; }
function esc_url( $url ) { return $url; }
function esc_attr( $text ) { return $text; }
function checked( $checked, $current = true, $display = true ) { return $checked === $current ? 'checked' : ''; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['ariunetclub_test_hooks'][ $hook ][] = array( $cb, $args ); }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['ariunetclub_test_hooks'][ $hook ][] = array( $cb, $args ); }
function do_action( $hook, ...$args ) {
        foreach ( $GLOBALS['ariunetclub_test_hooks'][ $hook ] ?? array() as $pair ) {
                call_user_func_array( $pair[0], array_slice( $args, 0, $pair[1] ) );
        }
}
function apply_filters( $hook, $value, ...$args ) { return $value; }
function get_option( $name, $default = false ) { return $GLOBALS['ariunetclub_test_settings'][ $name ] ?? $default; }
function add_option( $name, $value ) { $GLOBALS['ariunetclub_test_settings'][ $name ] = $value; return true; }
function update_option( $name, $value ) { $GLOBALS['ariunetclub_test_settings'][ $name ] = $value; return true; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( (array) $defaults, (array) $args ); }
function wp_unslash( $value ) { return $value; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function sanitize_text_field( $str ) { return trim( (string) $str ); }
function absint( $value ) { return abs( (int) $value ); }
function get_current_user_id() { return 2; }
function is_user_logged_in() { return $GLOBALS['ariunetclub_test_user_logged_in']; }
function current_time( $type, $gmt = false ) { return $gmt ? gmdate( 'Y-m-d H:i:s' ) : date( 'Y-m-d H:i:s' ); }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals, '.', ',' ); }
function wc_get_price_decimals() { return 0; }
function add_query_arg( $args, $url = '' ) { return $url . '?' . http_build_query( $args ); }
function admin_url( $path = '' ) { return 'https://test.local/wp-admin/' . $path; }
function wp_create_nonce( $action ) { return 'test-nonce'; }
function check_ajax_referer( $action, $arg = false, $die = true ) { return true; }
function is_checkout() { return true; }
function is_checkout_pay_page() { return false; }
function get_transient( $key ) {
        $stored = $GLOBALS['ariunetclub_test_transients'][ $key ] ?? null;
        if ( null === $stored ) { return false; }
        if ( $stored['expires'] < microtime( true ) ) { unset( $GLOBALS['ariunetclub_test_transients'][ $key ] ); return false; }
        return $stored['value'];
}
function set_transient( $key, $value, $seconds = 0 ) {
        $GLOBALS['ariunetclub_test_transients'][ $key ] = array( 'value' => $value, 'expires' => microtime( true ) + (float) $seconds );
        return true;
}
function delete_transient( $key ) { unset( $GLOBALS['ariunetclub_test_transients'][ $key ] ); return true; }
function wp_upload_dir() { return array( 'error' => 'uploads disabled in tests', 'basedir' => '/tmp' ); }
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function wp_mkdir_p( $dir ) { return false; }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function wp_send_json_error( $data = null, $http = null ) { throw new JsonSentException( array( 'success' => false, 'data' => $data ), $http ?: 400 ); }
function wp_send_json_success( $data = null, $http = null ) { throw new JsonSentException( array( 'success' => true, 'data' => $data ), 200 ); }
function wp_schedule_single_event( $ts, $hook, $args = array() ) { $GLOBALS['ariunetclub_test_cron'][] = array( $ts, $hook, $args ); return true; }
function wp_next_scheduled( $hook, $args = array() ) { return false; }
function wp_unschedule_event( $ts, $hook, $args = array() ) { return true; }
function wc_add_notice( $message, $type = 'notice', $args = array() ) { $GLOBALS['ariunetclub_test_notices'][] = array( $message, $type ); }
function wc_get_order( $id ) {
        if ( is_object( $id ) ) { return $id; }
        return $GLOBALS['ariunetclub_test_orders'][ (int) $id ] ?? null;
}

class WP_Error {
        public $errors = array();
        public function add( $code, $message, $data = null ) { $this->errors[ $code ][] = $message; }
        public function get_error_messages( $code = '' ) { return $this->errors[ $code ] ?? array(); }
        public function has_errors() { return ! empty( $this->errors ); }
}

class WC_Session_Handler {
        private $store = array();
        public function get( $key, $default = false ) { return $this->store[ $key ] ?? $default; }
        public function set( $key, $value ) { $this->store[ $key ] = $value; }
}

class WC_Cart {
        public $fees = array();
        public $subtotal = 0.0;
        public $subtotal_tax = 0.0;
        public $shipping = 0.0;
        public $shipping_tax = 0.0;
        public function get_subtotal() { return $this->subtotal; }
        public function get_subtotal_tax() { return $this->subtotal_tax ?? 0.0; }
        public function get_shipping_total() { return $this->shipping ?? 0.0; }
        public function get_shipping_tax() { return $this->shipping_tax ?? 0.0; }
        public function add_fee( $name, $amount, $taxable = false, $tax_class = '' ) {
                $row = new stdClass();
                $row->name = $name; $row->amount = (float) $amount; $row->taxable = (bool) $taxable; $row->tax_class = $tax_class;
                $this->fees[] = $row;
        }
        public function get_fees() { return $this->fees; }
        public function empty_cart() { $this->fees = array(); }
        public function is_empty() { return false; }
        public $contents_count = 1;
        public function get_cart_contents_count() { return $this->contents_count; }
        public function cart_total_after_fees() {
                $total = $this->get_subtotal() + $this->get_subtotal_tax() + $this->get_shipping_total() + $this->get_shipping_tax();
                foreach ( $this->fees as $fee ) { $total += $fee->amount; }
                return $total;
        }
}

class WC {
        public $cart;
        public $session;
}

function WC() {
        if ( null === ( $GLOBALS['ariunetclub_test_wc'] ?? null ) ) {
                $GLOBALS['ariunetclub_test_wc']           = new WC();
                $GLOBALS['ariunetclub_test_wc']->cart     = new WC_Cart();
                $GLOBALS['ariunetclub_test_wc']->session  = new WC_Session_Handler();
        }
        return $GLOBALS['ariunetclub_test_wc'];
}

class WC_Order {
        public $id;
        public $customer_id;
        public $status = 'pending';
        public $meta = array();
        public $notes = array();
        public $order_total = 0.0;
        public $payment_method = '';
        public $payment_method_title = '';
        public function __construct( $id, $customer_id = 0, $total = 0.0 ) {
                $this->id = (int) $id; $this->customer_id = (int) $customer_id; $this->order_total = (float) $total;
                $GLOBALS['ariunetclub_test_orders'][ $this->id ] = $this;
        }
        public function get_id(): int { return $this->id; }
        public function get_customer_id(): int { return $this->customer_id; }
        public function get_status(): string { return $this->status; }
        public function update_status( $new, $note = '', $manual = false ) {
                $old = $this->status;
                $this->status = $new;
                if ( '' !== $note ) { $this->notes[] = $note; }
                do_action( 'woocommerce_order_status_changed', $this->id, $old, $new, $this );
        }
        public function payment_complete() { $this->update_status( 'processing' ); return true; }
        public function get_total( $context = 'view' ) { return $this->order_total; }
        public function set_total( $total ) { $this->order_total = (float) $total; }
        public function get_meta( $key, $single = true, $context = 'view' ) { return $this->meta[ $key ] ?? ''; }
        public function update_meta_data( $key, $value, $unique = false ) { $this->meta[ $key ] = $value; }
        public function save() { return $this->id; }
        public function add_order_note( $note, $is_customer = 0, $by_user = false ) { $this->notes[] = $note; return count( $this->notes ); }
        public function get_payment_method() { return $this->payment_method; }
        public function set_payment_method( $method ) { $this->payment_method = $method; }
        public function set_payment_method_title( $title ) { $this->payment_method_title = $title; }
}

class WC_Order_Refund extends WC_Order {
        public $refund_amount = 0.0;
        public function get_amount() { return $this->refund_amount; }
}

class WC_Settings_API {
        public $plugin_id = 'woocommerce_';
        public $id = '';
        public $errors = array();
        public $settings = array();
        protected $form_fields = array();
        public function get_option( $key, $empty_value = null ) { return $empty_value; }
        public function init_settings() {}
        public function process_admin_options() { return true; }
}

class WC_Payment_Gateway extends WC_Settings_API {
        public $title = '';
        public $method_title = '';
        public $method_description = '';
        public $has_fields = false;
        public $enabled = 'yes';
        public function get_return_url( $order = null ) { return 'https://test.local/thanks'; }
        public function is_available() { return true; }
}

// ---------------------------------------------------------------------------
// 2) Load the real plugin classes (before the in-memory ledger)
// ---------------------------------------------------------------------------

use AriunetClub\Modules\Ledger\InsufficientBalanceException;
use AriunetClub\Modules\Ledger\LedgerGateway;
use AriunetClub\Modules\Ledger\LedgerService;
use AriunetClub\Core\Logger;
use AriunetClub\Core\Settings;
use AriunetClub\Modules\Wallet\CheckoutController;
use AriunetClub\Modules\Wallet\WalletGateway;
use AriunetClub\Modules\Wallet\HoldManager;
use AriunetClub\Modules\Wallet\OrderMeta;
use AriunetClub\Modules\Wallet\SpendCalculator;

$base = dirname( __DIR__ ) . '/src/';
require $base . 'Core/Money.php';
require $base . 'Core/Settings.php';
require $base . 'Core/Logger.php';
require $base . 'Modules/Ledger/InsufficientBalanceException.php';
require $base . 'Modules/Ledger/LedgerGateway.php';
require $base . 'Modules/Ledger/LedgerService.php';
require $base . 'Modules/Wallet/OrderMeta.php';
require $base . 'Modules/Wallet/SpendCalculator.php';
require $base . 'Modules/Wallet/HoldManager.php';
require $base . 'Modules/Wallet/CheckoutController.php';
require $base . 'Modules/Wallet/WalletGateway.php';

// ---------------------------------------------------------------------------
// 3) In-memory ledger (kept) — semantics copied from the real LedgerRepository
// ---------------------------------------------------------------------------

class InMemoryLedger implements LedgerGateway {
        public $rows = array();
        public $balances = array();
        private $next_id = 1;

        private function &bal( $user_id ) {
                if ( ! isset( $this->balances[ $user_id ] ) ) {
                        $this->balances[ $user_id ] = array( 'available' => 0.0, 'held' => 0.0 );
                }
                return $this->balances[ $user_id ];
        }
        public function record( array $entry ): array {
                $key = (string) $entry['idempotency_key'];
                foreach ( $this->rows as $row ) {
                        if ( $row['idempotency_key'] === $key ) { return $row; } // idempotent replay
                }
                $b        = &$this->bal( (int) $entry['user_id'] );
                $amount   = round( (float) $entry['amount'], 0 );
                $delta    = 0.0;
                switch ( $entry['entry_type'] ) {
                        case 'credit': $b['available'] += $amount; $delta = $amount; break;
                        case 'debit':
                                if ( $b['available'] < $amount ) { throw new InsufficientBalanceException( 'Insufficient available balance.' ); }
                                $b['available'] -= $amount; $delta = -$amount; break;
                        case 'hold':
                                if ( $b['available'] < $amount ) { throw new InsufficientBalanceException( 'Insufficient available balance.' ); }
                                $b['available'] -= $amount; $b['held'] += $amount; break;
                        case 'capture':
                                if ( $b['held'] < $amount ) { throw new InsufficientBalanceException( 'Insufficient held balance.' ); }
                                $b['held'] -= $amount; $delta = -$amount; break;
                        case 'release':
                                if ( $b['held'] < $amount ) { throw new InsufficientBalanceException( 'Insufficient held balance.' ); }
                                $b['held'] -= $amount; $b['available'] += $amount; break;
                        case 'adjust': $b['available'] += $amount; $delta = $amount; break;
                        default: throw new InvalidArgumentException( 'Unsupported ledger entry type.' );
                }
                $entry['amount']        = $delta;
                $entry['balance_after'] = $b['available'] + $b['held'];
                $entry['id']            = $this->next_id++;
                $this->rows[]           = $entry;
                return $entry;
        }
        public function balance( int $user_id, string $asset = 'cashback' ): array {
                $b = $this->bal( $user_id );
                return array( 'user_id' => $user_id, 'asset' => $asset, 'available' => (string) $b['available'], 'held' => (string) $b['held'], 'updated_at' => null );
        }
        public function balances( array $user_ids = array(), int $page = 1, int $per_page = 20 ): array { return array( 'items' => array(), 'total' => 0 ); }
        public function history( int $user_id, string $asset = 'cashback', int $page = 1, int $per_page = 20 ): array { return array( 'items' => array(), 'total' => 0 ); }
        public function entries_for_reference( string $ref_type, int $ref_id, array $entry_types = array() ): array {
                $out = array();
                foreach ( $this->rows as $row ) {
                        if ( ( $row['ref_type'] ?? null ) !== $ref_type || (int) ( $row['ref_id'] ?? 0 ) !== $ref_id ) { continue; }
                        if ( $entry_types && ! in_array( $row['entry_type'], $entry_types, true ) ) { continue; }
                        $out[] = $row;
                }
                return $out;
        }
        public function count_for_rule( int $rule_id, ?int $user_id = null, ?string $since_gmt = null ): int { return 0; }
        public function sum_for_rule( int $rule_id ): float { return 0.0; }
}

// ---------------------------------------------------------------------------
// 4) Test runner
// ---------------------------------------------------------------------------

$PASS = 0; $FAIL = 0;
function check( $label, $condition ) {
        global $PASS, $FAIL;
        if ( $condition ) { $PASS++; echo "  PASS  $label\n"; }
        else { $FAIL++; echo "  FAIL  $label\n"; }
}
function fresh_world( $settings_overrides = array() ) {
        $GLOBALS['ariunetclub_test_orders']        = array();
        $GLOBALS['ariunetclub_test_hooks']         = array();
        $GLOBALS['ariunetclub_test_transients']    = array();
        $GLOBALS['ariunetclub_test_user_logged_in'] = true;
        unset( $GLOBALS['ariunetclub_test_wc'] );
        unset( $GLOBALS['ariunetclub_test_notices'] );

        $settings                     = new Settings();
        $calculator                   = new SpendCalculator( $settings );
        $ledger_gateway               = new InMemoryLedger();
        $ledger_service               = new LedgerService( $ledger_gateway );
        $logger                       = new Logger();
        $holds                        = new HoldManager( $ledger_service, $ledger_gateway, $settings, $logger );
        $holds->register();
        $checkout                     = new CheckoutController( $ledger_service, $calculator, $settings, $holds, $logger );
        $checkout->register();

        $GLOBALS['ariunetclub_test_settings']['ariunetclub_settings'] = array_merge( array(
                'wallet_fee_title'        => 'استفاده از کیف پول',
                'wallet_max_percent'      => 100.0,
                'wallet_min_order'        => 0.0,
                'wallet_allowed_gateways' => array(),
                'wallet_hold_timeout'     => 48,
                'wallet_full_coverage'    => true,
        ), $settings_overrides );

        return array( $ledger_gateway, $ledger_service, $holds, $checkout, $calculator, $settings, $logger );
}

echo "=== 1) SpendCalculator math ===\n";
list( $gw, $svc, $holds, $checkout, $calc, $settings, $logger ) = fresh_world();
check( 'available below cap wins', 30000.0 === $calc->max_spend( 30000.0, 100000.0, 115000.0 ) );
list( , , , , $calc ) = fresh_world( array( 'wallet_max_percent' => 20.0 ) );
check( 'percent cap 20% of items base', 20000.0 === $calc->max_spend( 50000.0, 100000.0, 115000.0 ) );
check( 'zero total -> zero spend', 0.0 === $calc->max_spend( 50000.0, 100000.0, 0.0 ) );
check( 'zero available -> zero spend', 0.0 === $calc->max_spend( 0.0, 100000.0, 115000.0 ) );
list( , , , , $calc ) = fresh_world( array( 'wallet_min_order' => 120000.0 ) );
check( 'below min order -> zero spend', 0.0 === $calc->max_spend( 50000.0, 100000.0, 115000.0 ) );
list( , , , , $calc ) = fresh_world( array( 'wallet_full_coverage' => false ) );
check( 'coverage off leaves one step payable', 114999.0 === $calc->max_spend( 999999.0, 200000.0, 115000.0 ) );

echo "=== 2) Full lifecycle: hold -> capture -> idempotent re-fire ===\n";
list( $gw, $svc, $holds, $checkout, $calc, $settings, $logger ) = fresh_world();
$svc->credit( 2, 50000.0, 'seed:1' );
check( 'seed balance available=50000', 50000.0 === (float) $svc->balance( 2 )['available'] );

$order = new WC_Order( 77, 2, 0.0 );
WC()->cart->subtotal = 100000.0;
WC()->cart->shipping = 15000.0;
WC()->session->set( CheckoutController::SESSION_FLAG, true );
WC()->session->set( CheckoutController::SESSION_SPEND, 50000.0 );
$order->set_total( 65000.0 ); // 115000 - 50000 fee
$checkout->stamp_order( $order ); // real flow: stamps AMOUNT + ORDER_TOTAL

check( 'stamp recorded the spend', 50000.0 === OrderMeta::amount( $order ) );
check( 'stamp recorded the order total snapshot', 65000.0 === OrderMeta::order_total( $order ) );
do_action( 'woocommerce_checkout_order_processed', 77, array(), $order ); // place_hold
check( 'order meta state=held after hold', 'held' === OrderMeta::state( $order ) );
check( 'hold moved balance: available=0', 0.0 === (float) $svc->balance( 2 )['available'] );
check( 'hold moved balance: held=50000', 50000.0 === (float) $svc->balance( 2 )['held'] );

$order->payment_complete(); // pending -> processing triggers capture
check( 'state=captured after payment', 'captured' === OrderMeta::state( $order ) );
check( 'capture: held=0', 0.0 === (float) $svc->balance( 2 )['held'] );
check( 'capture: available still 0 (spent)', 0.0 === (float) $svc->balance( 2 )['available'] );

// re-fire the same transition — must be a no-op thanks to the idempotency key
$order->update_status( 'processing' );
check( 're-fired capture does not double-spend', 0.0 === (float) $svc->balance( 2 )['held'] && 0.0 === (float) $svc->balance( 2 )['available'] );
$wallet_rows = 0;
foreach ( $gw->rows as $row ) { if ( 'wallet:capture:order:77' === $row['idempotency_key'] ) { $wallet_rows++; } }
check( 'exactly one capture row in ledger', 1 === $wallet_rows );

echo "=== 3) Proportional refund math + caps + idempotency ===\n";
$refund1 = new WC_Order_Refund( 9001, 0, 0.0 );
$refund1->refund_amount = 32500.0;
do_action( 'woocommerce_order_refunded', 77, 9001 );
check( 'first refund returns 25000 (50%)', 25000.0 === (float) $svc->balance( 2 )['available'] );
$refund2 = new WC_Order_Refund( 9002, 0, 0.0 );
$refund2->refund_amount = 32500.0;
do_action( 'woocommerce_order_refunded', 77, 9002 );
check( 'second refund returns the remaining 25000', 50000.0 === (float) $svc->balance( 2 )['available'] );
do_action( 'woocommerce_order_refunded', 77, 9002 ); // same refund id twice — idempotent key
check( 'same refund id does not double-credit', 50000.0 === (float) $svc->balance( 2 )['available'] );
$refund3 = new WC_Order_Refund( 9003, 0, 0.0 );
$refund3->refund_amount = 1000.0;
do_action( 'woocommerce_order_refunded', 77, 9003 );
check( 'refund beyond returned cap returns nothing', 50000.0 === (float) $svc->balance( 2 )['available'] );

echo "=== 4) Cancel while held -> release ===\n";
list( $gw, $svc, $holds, $checkout, $calc ) = fresh_world();
$svc->credit( 2, 30000.0, 'seed:c4' );
$order = new WC_Order( 88, 2, 70000.0 );
$order->update_meta_data( OrderMeta::AMOUNT, 30000.0 );
do_action( 'woocommerce_checkout_order_processed', 88, array(), $order );
check( 'held after hold (c4)', 'held' === OrderMeta::state( $order ) );
$order->update_status( 'cancelled' );
check( 'release on cancel: available restored', 30000.0 === (float) $svc->balance( 2 )['available'] );
check( 'release on cancel: held zero', 0.0 === (float) $svc->balance( 2 )['held'] );
check( 'state=released', 'released' === OrderMeta::state( $order ) );

echo "=== 5) Recovery paths (regression for the reviewed bugs) ===\n";
// 5a: order whose hold FAILED (state ''), customer retries and pays -> wallet must still be charged
list( $gw, $svc, $holds, $checkout, $calc ) = fresh_world();
$svc->credit( 2, 40000.0, 'seed:c5' );
$order = new WC_Order( 99, 2, 60000.0 );
$order->update_meta_data( OrderMeta::AMOUNT, 40000.0 ); // stamped, hold never placed
$order->update_status( 'processing' );
check( 'never-held order gets charged on retry (bug fix)', 'captured' === OrderMeta::state( $order ) );
check( 'wallet charged 40000 (held 0)', 0.0 === (float) $svc->balance( 2 )['held'] );
check( 'available 0 after retry charge', 0.0 === (float) $svc->balance( 2 )['available'] );

// 5b: retry payment but balance is gone -> debt entry, ledger stays whole
list( $gw, $svc, $holds, $checkout, $calc ) = fresh_world();
$order = new WC_Order( 111, 2, 20000.0 );
$order->update_meta_data( OrderMeta::AMOUNT, 20000.0 );
$order->update_status( 'processing' );
check( 'debt path marks order captured', 'captured' === OrderMeta::state( $order ) );
check( 'debt recorded: available=-20000', -20000.0 === (float) $svc->balance( 2 )['available'] );
check( 'debt recorded: no held left', 0.0 === (float) $svc->balance( 2 )['held'] );

// 5c: released hold + later retry -> re-hold then capture
list( $gw, $svc, $holds, $checkout, $calc ) = fresh_world();
$svc->credit( 2, 25000.0, 'seed:c5c' );
$order = new WC_Order( 112, 2, 75000.0 );
$order->update_meta_data( OrderMeta::AMOUNT, 25000.0 );
do_action( 'woocommerce_checkout_order_processed', 112, array(), $order );
$order->update_status( 'failed' );
check( 'released after failure', 'released' === OrderMeta::state( $order ) );
check( 'available restored after release', 25000.0 === (float) $svc->balance( 2 )['available'] );
$order->update_status( 'processing' ); // customer pays later
check( 're-hold+capture after retry', 'captured' === OrderMeta::state( $order ) );
check( 'wallet spent after retry capture', 0.0 === (float) $svc->balance( 2 )['available'] );

echo "=== 6) Pending timeout release ===\n";
list( $gw, $svc, $holds, $checkout, $calc ) = fresh_world( array( 'wallet_hold_timeout' => 48 ) );
$svc->credit( 2, 15000.0, 'seed:c6' );
$order = new WC_Order( 120, 2, 90000.0 );
$order->update_meta_data( OrderMeta::AMOUNT, 15000.0 );
do_action( 'woocommerce_checkout_order_processed', 120, array(), $order );
check( 'held before expiry', 'held' === OrderMeta::state( $order ) );
$order->update_meta_data( OrderMeta::HELD_AT, time() - 49 * 3600 );
do_action( HoldManager::EXPIRY_ACTION, 120 );
check( 'expired pending hold released', 'released' === OrderMeta::state( $order ) );
check( 'available restored after expiry', 15000.0 === (float) $svc->balance( 2 )['available'] );

echo "=== 7) due_total includes other (negative) fee rows ===\n";
list( $gw, $svc, $holds, $checkout, $calc ) = fresh_world();
$svc->credit( 2, 100000.0, 'seed:c7' );
WC()->cart->subtotal = 100000.0;
WC()->cart->shipping  = 15000.0;
WC()->cart->add_fee( 'کش‌بک فاز ۲', -20000.0 );
WC()->session->set( CheckoutController::SESSION_FLAG, true );
$_POST['use'] = '1';
try {
        $checkout->toggle_wallet();
} catch ( JsonSentException $sent ) { /* success response expected */ }
check( 'toggle succeeded', isset( $sent ) && true === $sent->payload['success'] );
check( 'spend capped at 95000 (cashback-aware)', 95000.0 === (float) $sent->payload['data']['spend'] );
WC()->session->set( CheckoutController::SESSION_FLAG, true );
WC()->session->set( CheckoutController::SESSION_SPEND, 95000.0 );
$checkout->apply_fee( WC()->cart );
$fee_rows = array();
foreach ( WC()->cart->get_fees() as $fee_row ) { $fee_rows[] = $fee_row->name; }
check( 'wallet fee row added', in_array( 'استفاده از کیف پول', $fee_rows, true ) );
check( 'cart total never negative', 0.0 === WC()->cart->cart_total_after_fees() );
$checkout->apply_fee( WC()->cart );
$wallet_fee_rows = 0;
foreach ( WC()->cart->get_fees() as $fee_row ) { if ( 'استفاده از کیف پول' === $fee_row->name ) { $wallet_fee_rows++; } }
check( 'apply_fee twice adds only one wallet fee row (per-request guard)', 1 === $wallet_fee_rows );

echo "=== 8) Checkout validation (allowed gateways) ===\n";
list( $gw, $svc, $holds, $checkout, $calc ) = fresh_world( array( 'wallet_allowed_gateways' => array( 'zarinpal' ) ) );
$svc->credit( 2, 50000.0, 'seed:c8' );
WC()->cart->subtotal = 200000.0;
WC()->cart->shipping  = 0.0;
WC()->session->set( CheckoutController::SESSION_FLAG, true );
WC()->session->set( CheckoutController::SESSION_SPEND, 50000.0 );
$errors = new WP_Error();
$checkout->validate_checkout( array( 'payment_method' => 'cod' ), $errors );
check( 'cod rejected when wallet is partial', $errors->has_errors() );
$errors2 = new WP_Error();
$checkout->validate_checkout( array( 'payment_method' => 'zarinpal' ), $errors2 );
check( 'allowed gateway accepted', ! $errors2->has_errors() );

echo "=== 9) Toggle endpoint behaviour ===\n";
list( $gw, $svc, $holds, $checkout, $calc ) = fresh_world();
delete_transient( CheckoutController::THROTTLE . 2 );
$_POST['use'] = '1';
$sent = null;
try { $checkout->toggle_wallet(); } catch ( JsonSentException $e ) { $sent = $e; }
check( 'toggle returns spend=0 error on empty cart', isset( $sent ) && false === $sent->payload['success'] );
delete_transient( CheckoutController::THROTTLE . 2 );
WC()->cart->subtotal = 50000.0;
$svc->credit( 2, 20000.0, 'seed:c9' );
$sent = null;
try { $checkout->toggle_wallet(); } catch ( JsonSentException $e ) { $sent = $e; }
check( 'toggle success stores spend', isset( $sent ) && true === $sent->payload['success'] && 20000.0 === (float) $sent->payload['data']['spend'] );
check( 'session flag persisted', true === WC()->session->get( CheckoutController::SESSION_FLAG ) );
$sent = null;
try { $checkout->toggle_wallet(); } catch ( JsonSentException $e ) { $sent = $e; }
check( 'throttled second call rejected (429)', isset( $sent ) && 429 === $sent->code_http );
delete_transient( CheckoutController::THROTTLE . 2 );
$_POST['use'] = '0';
$sent = null;
try { $checkout->toggle_wallet(); } catch ( JsonSentException $e ) { $sent = $e; }
check( 'toggle off clears flag', isset( $sent ) && true === $sent->payload['success'] && false === WC()->session->get( CheckoutController::SESSION_FLAG ) );

echo "=== 10) stamp_order caps and gateway stamping ===\n";
list( $gw, $svc, $holds, $checkout, $calc ) = fresh_world();
WC()->session->set( CheckoutController::SESSION_FLAG, true );
WC()->session->set( CheckoutController::SESSION_SPEND, 7000.0 );
$order = new WC_Order( 130, 2, 0.0 );
$checkout->stamp_order( $order );
check( 'amount meta stamped', 7000.0 === OrderMeta::amount( $order ) );
check( 'zero-total order stamped as wallet payment', 'ariunetclub_wallet' === $order->get_payment_method() );
check( 'order total snapshot stored', 0.0 === OrderMeta::order_total( $order ) );

echo "=== 11) Hold failure marks order failed and surfaces an error ===\n";
list( $gw, $svc, $holds, $checkout, $calc ) = fresh_world();
WC()->session->set( CheckoutController::SESSION_FLAG, true );
WC()->session->set( CheckoutController::SESSION_SPEND, 9000.0 );
$order = new WC_Order( 140, 2, 1000.0 ); // zero balance for user 2!
$checkout->stamp_order( $order ); // stamps AMOUNT=9000 first, like checkout does
$threw = false;
try {
        do_action( 'woocommerce_checkout_order_processed', 140, array(), $order );
} catch ( Exception $e ) {
        $threw = true;
}
check( 'failed hold throws customer-facing error', $threw );
check( 'order marked failed', 'failed' === $order->get_status() );
check( 'no wallet state recorded on failed hold', '' === OrderMeta::state( $order ) );


echo "=== 12) WalletGateway availability and payment guard ===\n";
list( $gw, $svc, $holds, $checkout, $calc, $settings, $logger ) = fresh_world();
$gateway = new WalletGateway( $settings );

// Full coverage: the wallet fee already covers the whole cart.
WC()->cart->fees     = array();
WC()->cart->subtotal = 100000.0;
WC()->cart->add_fee( 'استفاده از کیف پول', -100000.0 );
WC()->session->set( CheckoutController::SESSION_FLAG, true );
check( 'gateway available when the wallet covers everything', true === $gateway->is_available() );

// Partial coverage (cap 50%): the remainder must go through a bank gateway.
list( $gw, $svc, $holds, $checkout, $calc, $settings, $logger ) = fresh_world( array( 'wallet_max_percent' => 50.0 ) );
$gateway = new WalletGateway( $settings );
WC()->cart->fees     = array();
WC()->cart->subtotal = 100000.0;
WC()->cart->add_fee( 'استفاده از کیف پول', -50000.0 );
WC()->session->set( CheckoutController::SESSION_FLAG, true );
check( 'gateway hidden while half the cart is still owed', false === $gateway->is_available() );

// Empty cart (e.g. a pay-page context): never available.
WC()->cart->contents_count = 0;
check( 'gateway hidden for an empty cart', false === $gateway->is_available() );
WC()->cart->contents_count = 1;

// process_payment refuses an order the wallet slice does not fully cover.
list( $gw, $svc, $holds, $checkout, $calc, $settings, $logger ) = fresh_world();
$gateway = new WalletGateway( $settings );
$partial = new WC_Order( 150, 2, 50000.0 );
$partial->update_meta_data( '_ariunetclub_wallet_amount', 30000.0 );
$result = $gateway->process_payment( 150 );
check( 'partial-coverage payment refused', 'failure' === $result['result'] );
check( 'refusal adds an error notice', count( $GLOBALS['ariunetclub_test_notices'] ) > 0 );
check( 'refused order stays pending', 'pending' === $partial->get_status() );

// A fully covered zero-total order completes through the wallet gateway.
$svc->credit( 2, 40000.0, 'gateway-seed' );
$covered = new WC_Order( 151, 2, 0.0 );
$covered->update_meta_data( '_ariunetclub_wallet_amount', 40000.0 );
$result = $gateway->process_payment( 151 );
check( 'full-coverage payment succeeds', 'success' === $result['result'] );
check( 'full-coverage order completed', 'processing' === $covered->get_status() );

echo "\n================ RESULT: $PASS passed, $FAIL failed ================\n";
exit( $FAIL > 0 ? 1 : 0 );
