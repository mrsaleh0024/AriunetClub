<?php
/**
 * @package LClub
 */

namespace LClub\Core;

class Plugin {
	const DB_VERSION = '1.1.0';

	/** @var self|null */
	private static $instance;

	/** @var Logger */
	private $logger;

	public static function activate(): void {
		if ( is_multisite() && is_network_admin() ) {
			wp_die( esc_html__( 'فعال‌سازی شبکه‌ای AriunetClub در MVP پشتیبانی نمی‌شود.', 'lclub' ) );
		}

		( new Migrator() )->migrate();
		Settings::ensure_defaults();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'lclub_process_events' );
	}

	public function boot(): void {
		if ( self::$instance ) {
			return;
		}

		self::$instance = $this;
		$this->logger   = new Logger();
		$settings       = new Settings();
		$settings->register();
		( new Migrator() )->maybe_migrate();
		$this->load_textdomain();
		$this->register_assets();

		$registry = new ModuleRegistry();
		$registry->register( new CoreModule( $this->logger ) );
		$registry->register( new \LClub\Modules\Identity\IdentityModule() );
		$registry->register( new \LClub\Modules\Ledger\LedgerModule() );
		$registry->boot( $settings->enabled_modules() );
	}

	public function logger(): Logger {
		return $this->logger;
	}

	private function load_textdomain(): void {
		load_plugin_textdomain( 'lclub', false, dirname( plugin_basename( LCLUB_FILE ) ) . '/languages' );
	}

	private function register_assets(): void {
		add_action(
			'admin_enqueue_scripts',
			static function ( $hook ) {
				if ( false === strpos( (string) $hook, 'lclub' ) ) {
					return;
				}
				wp_enqueue_style( 'lclub-admin', LCLUB_URL . 'assets/css/lclub-admin.css', array(), LCLUB_VERSION );
				wp_enqueue_script( 'lclub-admin', LCLUB_URL . 'assets/js/lclub-admin.js', array(), LCLUB_VERSION, true );
				wp_localize_script( 'lclub-admin', 'lclubAdmin', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'lclub_adjust_balance' ) ) );
			}
		);
	}
}
