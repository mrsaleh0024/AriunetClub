<?php
/**
 * Plugin Name: AriunetClub
 * Description: باشگاه مشتریان ووکامرس با معماری رویدادمحور و دفتر کل.
 * Version: 0.3.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: AriunetClub
 * Text Domain: lclub
 * Domain Path: /languages
 *
 * @package LClub
 */
defined( 'ABSPATH' ) || exit;
define( 'LCLUB_VERSION', '0.3.0' );
define( 'LCLUB_FILE', __FILE__ );
define( 'LCLUB_PATH', plugin_dir_path( __FILE__ ) );
define( 'LCLUB_URL', plugin_dir_url( __FILE__ ) );
require_once LCLUB_PATH . 'vendor/autoload.php';
register_activation_hook( __FILE__, array( '\\LClub\\Core\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\LClub\\Core\\Plugin', 'deactivate' ) );
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', LCLUB_FILE, true );
		}
	}
);
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>'
						. esc_html__( 'افزونه AriunetClub برای اجرا به ووکامرس نیاز دارد.', 'lclub' )
						. '</p></div>';
				}
			);
			return;
		}
		( new \LClub\Core\Plugin() )->boot();
	}
);
