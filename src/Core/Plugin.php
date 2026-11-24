<?php
/**
 * @package AriunetClub
 */
namespace AriunetClub\Core;

class Plugin {
                const DB_VERSION = '1.5.0';
                /** @var self|null */
        private static $instance;

        public static function activate(): void {
                if ( is_multisite() && is_network_admin() ) {
                                wp_die( esc_html__( 'فعال‌سازی شبکه‌ای AriunetClub در MVP پشتیبانی نمی‌شود.', 'ariunetclub' ) );
                }
                        ( new Migrator() )->migrate();
                        Settings::ensure_defaults();
                        \AriunetClub\Modules\Account\ClubEndpoint::register_rewrite();
                        flush_rewrite_rules();
        }

        public static function deactivate(): void {
                        wp_clear_scheduled_hook( 'ariunetclub_process_events' );
                        wp_clear_scheduled_hook( 'ariunetclub_release_expired_hold' );
                        wp_clear_scheduled_hook( 'ariunetclub_credit_expiry' );
                        wp_clear_scheduled_hook( 'ariunetclub_credit_reconcile' );
                        wp_clear_scheduled_hook( 'ariunetclub_credit_migrate_balances' );
                        // Pre-rebrand hook names (phases 1-3); cleared defensively in
                        // case the migration never ran.
                        wp_clear_scheduled_hook( 'lclub_process_events' );
                        wp_clear_scheduled_hook( 'lclub_release_expired_hold' );
                if ( function_exists( 'as_unschedule_all_actions' ) ) {
                                as_unschedule_all_actions( 'ariunetclub_process_events', array(), 'ariunetclub' );
                                // Per-order expiry actions carry arguments; while the plugin is
                                // inactive their callbacks complete as harmless no-ops, and the
                                // next hold reschedules a fresh action.
                                as_unschedule_all_actions( 'ariunetclub_release_expired_hold', array(), 'ariunetclub' );
                                as_unschedule_all_actions( 'ariunetclub_credit_expiry', array(), 'ariunetclub' );
                                as_unschedule_all_actions( 'ariunetclub_credit_reconcile', array(), 'ariunetclub' );
                                as_unschedule_all_actions( 'ariunetclub_credit_migrate_balances', array(), 'ariunetclub' );
                                as_unschedule_all_actions( 'lclub_process_events', array(), 'lclub' );
                                as_unschedule_all_actions( 'lclub_release_expired_hold', array(), 'lclub' );
                }
                        flush_rewrite_rules();
        }

        public function boot(): void {
                if ( self::$instance ) {
                                return;
                }
                        self::$instance = $this;
                        $settings       = new Settings();
                        $settings->register();
                        ( new Migrator() )->maybe_migrate();
                        $this->load_textdomain();
                        $this->register_assets();
                        $registry = new ModuleRegistry();
                        $registry->register( new CoreModule() );
                        $registry->register( new \AriunetClub\Modules\Identity\IdentityModule() );
                        $registry->register( new \AriunetClub\Modules\Ledger\LedgerModule() );
                        $registry->register( new \AriunetClub\Modules\Events\EventsModule() );
                        $registry->register( new \AriunetClub\Modules\Credit\CreditModule() );
                        $registry->register( new \AriunetClub\Modules\Rules\RulesModule() );
                        $registry->register( new \AriunetClub\Modules\Wallet\WalletModule() );
                        $registry->register( new \AriunetClub\Modules\Account\AccountModule() );
                        $registry->register( new \AriunetClub\Modules\License\LicenseModule() );
                        $registry->register( new \AriunetClub\Modules\Dashboard\DashboardModule() );
                        $registry->boot( $settings->enabled_modules() );
        }

        private function load_textdomain(): void {
                        load_plugin_textdomain( 'ariunetclub', false, dirname( plugin_basename( ARIUNETCLUB_FILE ) ) . '/languages' );
        }

        private function register_assets(): void {
                        add_action(
                                'admin_enqueue_scripts',
                                static function ( $hook ) {
                                        if ( false === strpos( (string) $hook, 'ariunetclub' ) ) {
                                                        return;
                                        }
                                                        wp_enqueue_style( 'ariunetclub-admin', ARIUNETCLUB_URL . 'assets/css/ariunetclub-admin.css', array(), ARIUNETCLUB_VERSION );
                                                        wp_enqueue_script( 'ariunetclub-admin', ARIUNETCLUB_URL . 'assets/js/ariunetclub-admin.js', array(), ARIUNETCLUB_VERSION, true );
                                                        wp_localize_script(
                                                                'ariunetclub-admin',
                                                                'ariunetclubAdmin',
                                                                array(
                                                                        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                                                                        'nonce'   => wp_create_nonce( 'ariunetclub_adjust_balance' ),
                                                                )
                                                        );
                                }
                        );
        }
}
