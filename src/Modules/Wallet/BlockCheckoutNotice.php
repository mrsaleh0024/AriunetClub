<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Wallet;

/**
 * Admin notice for block-based checkout (phase 6 gate, doc 4.8): the credit
 * switch only works in the CLASSIC checkout. When the shop's checkout page
 * contains the WooCommerce Checkout block, shop managers get a dismissible
 * notice on the plugin's admin pages plus a permanent line in the settings.
 */
class BlockCheckoutNotice {

        const META_KEY = 'ariunetclub_block_checkout_notice_dismissed';

        public function register(): void {
                add_action( 'admin_notices', array( $this, 'render' ) );
                add_action( 'admin_post_ariunetclub_dismiss_block_checkout_notice', array( $this, 'dismiss' ) );
        }

        /**
         * Renders the dismissible warning on plugin admin pages.
         *
         * @return void
         */
        public function render(): void {
                if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) {
                        return;
                }
                $page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
                if ( '' === $page || 0 !== strpos( $page, 'ariunetclub' ) ) {
                        return;
                }
                if ( ! $this->block_checkout_active() ) {
                        return;
                }
                $dismissed = get_user_meta( get_current_user_id(), self::META_KEY, true );
                if ( 'yes' === (string) $dismissed ) {
                        return;
                }

                $dismiss_url = wp_nonce_url(
                        add_query_arg(
                                array( 'action' => 'ariunetclub_dismiss_block_checkout_notice' ),
                                admin_url( 'admin-post.php' )
                        ),
                        'ariunetclub_dismiss_block_checkout_notice'
                );

                echo '<div class="notice notice-warning is-dismissible ariunetclub-block-checkout-notice"><p><strong>'
                        . esc_html__( 'باشگاه مشتریان AriunetClub', 'ariunetclub' ) . '</strong> — '
                        . esc_html__( 'صفحهٔ پرداخت فروشگاه از چک‌اوت بلوکی (Blocks) استفاده می‌کند؛ سوییچ «استفاده از اعتبار باشگاه» فقط در چک‌اوت کلاسیک نمایش داده می‌شود. برای فعال بودن آن، صفحهٔ پرداخت را به چک‌اوت کلاسیک برگردانید.', 'ariunetclub' )
                        . ' <a href="' . esc_url( $dismiss_url ) . '" class="ariunetclub-block-checkout-dismiss">' . esc_html__( 'انصراف', 'ariunetclub' ) . '</a></p></div>';
        }

        /**
         * Marks the notice as dismissed for the current shop manager.
		 *
		 * @return void
		 */
        public function dismiss(): void {
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_die( esc_html__( 'شما اجازهٔ دسترسی به این صفحه را ندارید.', 'ariunetclub' ) );
                }
                check_admin_referer( 'ariunetclub_dismiss_block_checkout_notice' );
                update_user_meta( get_current_user_id(), self::META_KEY, 'yes' );
                wp_safe_redirect( (string) wp_get_referer() );
                exit;
        }

        /**
		 * True when the configured WooCommerce checkout page contains the
		 * checkout block (the classic shortcode keeps returning false).
		 *
		 * @return bool
		 */
        public static function block_checkout_active(): bool {
                if ( ! function_exists( 'wc_get_page_id' ) || ! function_exists( 'get_post' ) ) {
                        return false;
                }
                $page_id = (int) wc_get_page_id( 'checkout' );
                if ( $page_id <= 0 ) {
                        return false;
                }
                $post = get_post( $page_id );
                if ( ! $post instanceof \WP_Post ) {
                        return false;
                }
                if ( ! function_exists( 'has_block' ) ) {
                        return false;
                }
                return has_block( 'woocommerce/checkout', $post );
        }
}
