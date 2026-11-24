<?php
/**
 * Plugin Name: AriunetClub
 * Description: باشگاه مشتریان ووکامرس با معماری رویدادمحور و دفتر کل.
 * Version: 0.6.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: AriunetClub
 * Text Domain: ariunetclub
 * Domain Path: /languages
 *
 * @package AriunetClub
 */
defined( 'ABSPATH' ) || exit;
define( 'ARIUNETCLUB_VERSION', '0.6.0' );
define( 'ARIUNETCLUB_FILE', __FILE__ );
define( 'ARIUNETCLUB_PATH', plugin_dir_path( __FILE__ ) );
define( 'ARIUNETCLUB_URL', plugin_dir_url( __FILE__ ) );
require_once ARIUNETCLUB_PATH . 'vendor/autoload.php';
register_activation_hook( __FILE__, array( '\\AriunetClub\\Core\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\AriunetClub\\Core\\Plugin', 'deactivate' ) );
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
						\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ARIUNETCLUB_FILE, true );
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
																		. esc_html__( 'افزونه AriunetClub برای اجرا به ووکامرس نیاز دارد.', 'ariunetclub' )
																		. '</p></div>';
							}
						);
						return;
		}
								( new \AriunetClub\Core\Plugin() )->boot();
	}
);
