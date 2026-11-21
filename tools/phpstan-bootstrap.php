<?php
/**
 * Static-analysis bootstrap for PHPStan.
 *
 * Declares analysis-only stubs for the WooCommerce and Action Scheduler APIs
 * the plugin calls. This file is never loaded at runtime; WordPress core
 * symbols come from php-stubs/wordpress-stubs, which phpstan.neon loads first.
 * It replaces tests/bootstrap.php as the phpstan bootstrap because the test
 * bootstrap refuses to run without a database whose name starts with
 * ariunetclub_test_ (a guard that is only meaningful under PHPUnit).
 *
 * @package AriunetClub
 */

if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', __DIR__ . '/wp-stubs/wordpress/' );
}
if ( ! defined( 'ARIUNETCLUB_VERSION' ) ) {
        define( 'ARIUNETCLUB_VERSION', '0.3.0' );
}
if ( ! defined( 'ARIUNETCLUB_FILE' ) ) {
        define( 'ARIUNETCLUB_FILE', dirname( __DIR__ ) . '/ariunetclub.php' );
}
if ( ! defined( 'ARIUNETCLUB_PATH' ) ) {
        define( 'ARIUNETCLUB_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'ARIUNETCLUB_URL' ) ) {
        define( 'ARIUNETCLUB_URL', 'https://example.test/wp-content/plugins/ariunetclub/' );
}

class WC {
        /** @var \WC_Cart|null */
        public $cart;
        /** @var \WC_Session_Handler|null */
        public $session;
        /** @return \WC_Payment_Gateways|null */
        public function payment_gateways() {
                return null;
        }
}

class WC_Cart {
        public function get_subtotal(): float {
                return 0.0;
        }
        public function get_subtotal_tax(): float {
                return 0.0;
        }
        public function get_shipping_total(): float {
                return 0.0;
        }
        public function get_shipping_tax(): float {
                return 0.0;
        }
        /** @return float|string */
        public function get_total( string $context = 'view' ) {
                return 0.0;
        }
        public function add_fee( string $name, float $amount, bool $taxable = false, string $tax_class = '' ): void {
        }
        public function empty_cart(): void {
        }
        /**
         * Fee rows as WooCommerce returns them (objects). Typed mixed on purpose:
         * the module guards every access at runtime.
         *
         * @return array<int,mixed>
         */
        public function get_fees(): array {
                return array();
        }
        public function is_empty(): bool {
                return true;
        }
}

class WC_Session_Handler {
        /**
         * @param string $key Session key.
         * @param mixed  $default Default value.
         * @return mixed
         */
        public function get( string $key, $default = false ) {
                return $default;
        }
        /**
         * @param string $key Session key.
         * @param mixed  $value Value to store.
         */
        public function set( string $key, $value ): void {
        }
}

class WC_Payment_Gateways {
        /**
         * @return array<string,\WC_Payment_Gateway>|false
         */
        public function payment_gateways() {
                return array();
        }
        /**
         * @return array<string,\WC_Payment_Gateway>|false
         */
        public function get_available_payment_gateways() {
                return array();
        }
}

class WC_Settings_API {
        /** @var string */
        public $plugin_id = 'woocommerce_';
        /** @var string */
        public $id = '';
        /** @var array<int,string> */
        public $errors = array();
        /**
         * Settings (as saved). Public in WooCommerce: child classes must not
         * re-declare it with a tighter visibility or PHP fatals at load time.
         *
         * @var array<string,mixed>
         */
        public $settings = array();
        /** @var array<string,mixed> */
        public $gateway_data = array();
        /**
         * @var array<string,array<string,mixed>>
         */
        protected $form_fields = array();

        /**
         * @param string $key Option key.
         * @param mixed  $empty_value Value when the option is empty.
         * @return mixed
         */
        public function get_option( $key, $empty_value = null ) {
                return $empty_value;
        }
        public function init_settings(): void {
        }
        public function process_admin_options(): bool {
                return true;
        }
}

class WC_Payment_Gateway extends WC_Settings_API {
        /** @var string */
        public $title = '';
        /** @var string */
        public $method_title = '';
        /** @var string */
        public $method_description = '';
        /** @var bool */
        public $has_fields = false;
        /** @var string */
        public $enabled = 'yes';
        /** @var array<int,string> */
        public $supports = array();

        /**
         * @param \WC_Order|null $order Order object.
         * @return string
         */
        public function get_return_url( $order = null ) {
                return '';
        }
        public function is_available(): bool {
                return true;
        }
        /**
         * @param int $order_id Order id.
         * @return array<string,mixed>
         */
        public function process_payment( $order_id ) {
                return array();
        }
}

class WC_Order {
        public function get_id(): int {
                return 0;
        }
        public function get_customer_id(): int {
                return 0;
        }
        public function get_parent_id(): int {
                return 0;
        }
        public function get_total( string $context = 'view' ): float {
                return 0.0;
        }
        public function get_total_tax(): float {
                return 0.0;
        }
        public function get_shipping_total(): float {
                return 0.0;
        }
        public function get_date_created(): ?\WC_DateTime {
                return null;
        }
        /**
         * @return array<string,\WC_Order_Item>
         */
        public function get_items( string $type = 'line_item' ): array {
                return array();
        }
        public function get_payment_method(): string {
                return '';
        }
        public function set_payment_method( string $method ): void {
        }
        public function set_payment_method_title( string $title ): void {
        }
        /**
         * @param string $key Meta key.
         * @param bool   $single Return a single value.
         * @param string $context Context.
         * @return mixed
         */
        public function get_meta( string $key, bool $single = true, string $context = 'view' ) {
                return '';
        }
        /**
         * @param string $key Meta key.
         * @param mixed  $value Meta value.
         * @param bool   $unique Unique flag.
         */
        public function update_meta_data( string $key, $value, bool $unique = false ): void {
        }
        public function save(): int {
                return 0;
        }
        public function add_order_note( string $note, int $is_customer_note = 0, bool $added_by_user = false ): int {
                return 0;
        }
        public function update_status( string $new_status, string $note = '', bool $manual = false ): void {
        }
        public function payment_complete(): bool {
                return true;
        }
        public function get_status(): string {
                return '';
        }
}

class WC_Order_Refund extends WC_Order {
        public function get_amount(): float {
                return 0.0;
        }
}

class WC_Order_Item {
}

class WC_Order_Item_Product extends WC_Order_Item {
        public function get_product_id(): int {
                return 0;
        }
}

class WC_DateTime extends DateTime {
}

class WC_Product {
        public function get_id(): int {
                return 0;
        }
        public function get_name(): string {
                return '';
        }
        public function get_formatted_name(): string {
                return '';
        }
}

function WC(): ?WC {
        return null;
}

/**
 * @param mixed $the_order Order id or object.
 * @return \WC_Order|null
 */
function wc_get_order( $the_order = 0 ) {
        return null;
}

/**
 * @param array<string,mixed> $args Query args.
 * @return array<int,mixed>|false
 */
function wc_get_orders( array $args ) {
        return array();
}

/**
 * @param array<string,mixed> $args Query args.
 * @return array<int,\WC_Product>|false
 */
function wc_get_products( array $args ) {
        return array();
}

function wc_get_price_decimals(): int {
        return 0;
}

function is_checkout(): bool {
        return false;
}

function is_checkout_pay_page(): bool {
        return false;
}

function wc_add_notice( string $message, string $notice_type = 'notice', array $args = array() ): void {
}

function as_enqueue_async_action( string $hook, array $args = array(), string $group = '' ): int {
        return 0;
}

function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '' ): int {
        return 0;
}

function as_schedule_recurring_action( int $timestamp, int $interval_in_seconds, string $hook, array $args = array(), string $group = '' ): int {
        return 0;
}

/**
 * @param string $hook Action hook.
 * @param array<int,mixed> $args Action args.
 * @param string $group Action group.
 * @return int|false
 */
function as_next_scheduled_action( string $hook, array $args = array(), string $group = '' ) {
        return false;
}

function as_unschedule_action( string $hook, array $args = array(), string $group = '' ): void {
}

function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void {
}
