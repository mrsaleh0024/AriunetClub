<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Credit;

use AriunetClub\Core\JalaliDate;
use AriunetClub\Core\Logger;
use AriunetClub\Core\Money;
use AriunetClub\Core\Settings;

/**
 * Phase 6 admin page: credit lots health, the club liability summary, the
 * monthly budget status, the balance-migration report and the two explicit
 * maintenance actions (re-run migration, apply expiry to legacy credit).
 */
class CreditAdminPage {

        const SLUG = 'ariunetclub-credit';

        /** @var LotRepository */
        private $lots;

        /** @var BudgetService */
        private $budget;

        /** @var Settings */
        private $settings;

        /** @var Logger */
        private $logger;

        public function __construct( LotRepository $lots, BudgetService $budget, Settings $settings, Logger $logger ) {
                $this->lots     = $lots;
                $this->budget   = $budget;
                $this->settings = $settings;
                $this->logger   = $logger;
        }

        public function register(): void {
                add_action( 'admin_menu', array( $this, 'menu' ), 20 );
                add_action( 'admin_post_ariunetclub_credit_migrate_restart', array( $this, 'migrate_restart' ) );
                add_action( 'admin_post_ariunetclub_credit_apply_expiry', array( $this, 'apply_expiry' ) );
                add_action( 'admin_post_ariunetclub_credit_reconcile_now', array( $this, 'reconcile_now' ) );
        }

        public function menu(): void {
                add_submenu_page(
                        'ariunetclub-settings',
                        __( 'ردیف‌های اعتبار', 'ariunetclub' ),
                        __( 'ردیف‌های اعتبار', 'ariunetclub' ),
                        'manage_woocommerce',
                        self::SLUG,
                        array( $this, 'render' )
                );
        }

        /**
         * Renders the page (read-only views plus three nonce-protected actions).
         */
        public function render(): void {
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_die( esc_html__( 'شما اجازهٔ دسترسی به این صفحه را ندارید.', 'ariunetclub' ) );
                }

                $now_gmt   = current_time( 'mysql', true );
                $in_30d    = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
                $liability = $this->lots->liability_summary( $now_gmt, $in_30d );
                $budget    = $this->budget->state();
                $migration = ( new BalanceMigration( $this->lots, $this->get_wpdb(), $this->logger ) )->status();
                $config    = $this->settings->get();
                $expiry_on = ! empty( $config['expiry_enabled'] );
                $expiry_days = isset( $config['expiry_days'] ) && is_numeric( $config['expiry_days'] ) ? (int) $config['expiry_days'] : 90;
                $legacy_applied = get_option( 'ariunetclub_expiry_legacy_applied', 0 );
                $reconcile = new ReconciliationJob( $this->lots, $this->logger );
                $reconcile_status = $reconcile->status();

                $base = add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) );
                ?>
                <div class="wrap ariunetclub-scope" dir="rtl">
                        <div class="ariunetclub-admin-shell">
                                <header class="ariunetclub-admin-header">
                                        <span class="ariunetclub-admin-kicker"><?php esc_html_e( 'ARIUNETCLUB', 'ariunetclub' ); ?></span>
                                        <h1><?php esc_html_e( 'ردیف‌های اعتبار باشگاه', 'ariunetclub' ); ?></h1>
                                        <p><?php esc_html_e( 'اعتبار هر مشتری از ردیف‌های جدا تشکیل شده است؛ این‌جا بدهی باشگاه، بودجهٔ ماهانه و سلامت ردیف‌ها را می‌بینید.', 'ariunetclub' ); ?></p>
                                </header>

                                <?php $this->render_notices(); ?>

                                <section class="ariunetclub-panel" aria-labelledby="ariunetclub-credit-liability">
                                        <div class="ariunetclub-section-heading"><div><h2 id="ariunetclub-credit-liability"><?php esc_html_e( 'بدهی باشگاه', 'ariunetclub' ); ?></h2><p><?php esc_html_e( 'اعتبار مصرف‌نشدهٔ مشتریان؛ همان چیزی که فروشگاه بدهکار است.', 'ariunetclub' ); ?></p></div></div>
                                        <div class="ariunetclub-credit-cards">
                                                <div class="ariunetclub-credit-card"><span><?php esc_html_e( 'کل اعتبار باز', 'ariunetclub' ); ?></span><strong><?php echo esc_html( Money::format( $liability['outstanding'] ) ); ?></strong></div>
                                                <div class="ariunetclub-credit-card"><span><?php esc_html_e( 'منقضی‌شونده تا ۳۰ روز', 'ariunetclub' ); ?></span><strong><?php echo esc_html( Money::format( $liability['expiring_30d'] ) ); ?></strong></div>
                                                <div class="ariunetclub-credit-card"><span><?php esc_html_e( 'ردیف‌های فعال', 'ariunetclub' ); ?></span><strong><?php echo esc_html( Money::to_persian_digits( (string) $liability['lots'] ) ); ?></strong></div>
                                                <div class="ariunetclub-credit-card"><span><?php esc_html_e( 'مشتریان دارای اعتبار', 'ariunetclub' ); ?></span><strong><?php echo esc_html( Money::to_persian_digits( (string) $liability['users'] ) ); ?></strong></div>
                                        </div>
                                </section>

                                <section class="ariunetclub-panel" aria-labelledby="ariunetclub-credit-budget">
                                        <div class="ariunetclub-section-heading"><div><h2 id="ariunetclub-credit-budget"><?php esc_html_e( 'بودجهٔ ماهانه', 'ariunetclub' ); ?></h2><p><?php esc_html_e( 'سقف هزینهٔ باشگاه در ماه جاری؛ قوانین پس از پر شدن بودجه اعتبار جدید صادر نمی‌کنند.', 'ariunetclub' ); ?></p></div></div>
                                        <?php if ( empty( $budget['enabled'] ) ) : ?>
                                                <p class="description"><?php esc_html_e( 'بودجه‌ای تنظیم نشده است؛ از تنظیمات افزونه درصدی از فروش یا مبلغ ثابت تعیین کنید.', 'ariunetclub' ); ?></p>
                                        <?php else : ?>
                                                <div class="ariunetclub-credit-cards">
                                                        <div class="ariunetclub-credit-card"><span><?php esc_html_e( 'سقف ماه', 'ariunetclub' ); ?></span><strong><?php echo esc_html( Money::format( $budget['cap'] ) ); ?></strong></div>
                                                        <div class="ariunetclub-credit-card"><span><?php esc_html_e( 'صادرشده تاکنون', 'ariunetclub' ); ?></span><strong><?php echo esc_html( Money::format( $budget['used'] ) ); ?></strong></div>
                                                        <div class="ariunetclub-credit-card"><span><?php esc_html_e( 'باقی‌مانده', 'ariunetclub' ); ?></span><strong><?php echo esc_html( Money::format( $budget['remaining'] ) ); ?></strong></div>
                                                        <div class="ariunetclub-credit-card"><span><?php esc_html_e( 'درصد مصرف', 'ariunetclub' ); ?></span><strong class="<?php echo esc_attr( $budget['ratio'] >= 0.8 ? 'ariunetclub-credit-danger' : '' ); ?>"><?php echo esc_html( Money::to_persian_digits( number_format( round( $budget['ratio'] * 100, 1 ), 1 ) ) ); ?>٪</strong></div>
                                                </div>
                                                <?php if ( $budget['ratio'] >= BudgetService::WARNING_RATIO ) : ?>
                                                        <div class="ariunetclub-notice ariunetclub-notice--warning"><?php esc_html_e( 'بودجهٔ ماه به بیش از ۸۰٪ رسیده است؛ با پر شدن آن، قوانین کش‌بک تا پایان ماه اعتبار جدید صادر نمی‌کنند.', 'ariunetclub' ); ?></div>
                                                <?php endif; ?>
                                                <p class="description"><?php echo esc_html( sprintf( /* translators: 1: month key, 2: sales total, 3: percent-based cap, 4: fixed cap. */ __( 'ماه %1$s — فروش تکمیل‌شده: %2$s (سقف درصدی: %3$s، سقف ثابت: %4$s؛ کوچک‌ترینِ آن‌ها اعمال می‌شود).', 'ariunetclub' ), Money::to_persian_digits( (string) $budget['month'] ), Money::format( $budget['sales'] ), Money::format( $budget['percent_cap'] ), Money::format( $budget['fixed_cap'] ) ) ); ?></p>
                                        <?php endif; ?>
                                </section>

                                <section class="ariunetclub-panel" aria-labelledby="ariunetclub-credit-expiry">
                                        <div class="ariunetclub-section-heading"><div><h2 id="ariunetclub-credit-expiry"><?php esc_html_e( 'انقضای اعتبار', 'ariunetclub' ); ?></h2></div></div>
                                        <p><?php echo esc_html( sprintf( /* translators: %s: number of days in Persian digits. */ __( 'وضعیت: %s', 'ariunetclub' ), $expiry_on ? sprintf( /* translators: %s: days. */ __( 'فعال — ردیف‌های کش‌بک جدید %s روز پس از صدور منقضی می‌شوند', 'ariunetclub' ), Money::to_persian_digits( (string) $expiry_days ) ) : __( 'خاموش — اعتبارها تاریخ انقضا ندارند', 'ariunetclub' ) ) ); ?></p>
                                        <?php if ( is_numeric( $legacy_applied ) && (int) $legacy_applied > 0 ) : ?>
                                                <p class="description"><?php echo esc_html( sprintf( /* translators: %s: Jalali date. */ __( 'انقضا آخرین‌بار روی اعتبارهای قدیمی در %s اعمال شده است.', 'ariunetclub' ), Money::to_persian_digits( JalaliDate::format( gmdate( 'Y-m-d H:i:s', (int) $legacy_applied ) ) ) ) ); ?></p>
                                        <?php endif; ?>
                                        <?php if ( $expiry_on ) : ?>
                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                                        <input type="hidden" name="action" value="ariunetclub_credit_apply_expiry" />
                                                        <?php wp_nonce_field( 'ariunetclub_credit_apply_expiry' ); ?>
                                                        <p><label class="ariunetclub-check"><input type="checkbox" name="confirm" value="1" required /> <?php echo esc_html( sprintf( /* translators: %s: days in Persian digits. */ __( 'می‌دانم تاریخ انقضای اعتبارهای قبلی دست‌کم %s روز آینده تعیین می‌شود و پیش از آن به مشتریان اطلاع می‌دهم.', 'ariunetclub' ), Money::to_persian_digits( '30' ) ) ); ?></label></p>
                                                        <button class="button" type="submit"><?php esc_html_e( 'اعمال انقضا روی اعتبارهای قدیمی', 'ariunetclub' ); ?></button>
                                                        <p class="description"><?php esc_html_e( 'فقط ردیف‌های «ماندهٔ پیش از ارتقا» که هنوز تاریخ انقضا ندارند تغییر می‌کنند؛ ردیف‌های جدید از همین حالا مشمول تنظیمات هستند.', 'ariunetclub' ); ?></p>
                                                </form>
                                        <?php endif; ?>
                                </section>

                                <section class="ariunetclub-panel" aria-labelledby="ariunetclub-credit-migration">
                                        <div class="ariunetclub-section-heading"><div><h2 id="ariunetclub-credit-migration"><?php esc_html_e( 'گزارش مهاجرت مانده‌ها', 'ariunetclub' ); ?></h2><p><?php esc_html_e( 'انتقال مانده‌های پیش از ارتقا به ردیف‌های اعتبار؛ دسته‌ای و قابل ادامه.', 'ariunetclub' ); ?></p></div></div>
                                        <?php $this->render_migration_report( $migration ); ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                                <input type="hidden" name="action" value="ariunetclub_credit_migrate_restart" />
                                                <?php wp_nonce_field( 'ariunetclub_credit_migrate_restart' ); ?>
                                                <button class="button" type="submit"><?php esc_html_e( 'اجرای دوبارهٔ مهاجرت', 'ariunetclub' ); ?></button>
                                                <p class="description"><?php esc_html_e( 'بی‌خطر و تکرارپذیر است؛ فقط شکاف‌های باقی‌مانده پر می‌شوند.', 'ariunetclub' ); ?></p>
                                        </form>
                                </section>

                                <section class="ariunetclub-panel" aria-labelledby="ariunetclub-credit-reconcile">
                                        <div class="ariunetclub-section-heading"><div><h2 id="ariunetclub-credit-reconcile"><?php esc_html_e( 'مغایرت‌گیری', 'ariunetclub' ); ?></h2><p><?php esc_html_e( 'بررسی روزانهٔ برابری ردیف‌ها، موجودی و دفتر کل.', 'ariunetclub' ); ?></p></div></div>
                                        <?php $this->render_reconcile_report( $reconcile_status ); ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                                <input type="hidden" name="action" value="ariunetclub_credit_reconcile_now" />
                                                <?php wp_nonce_field( 'ariunetclub_credit_reconcile_now' ); ?>
                                                <button class="button" type="submit"><?php esc_html_e( 'بررسی هم‌اکنون', 'ariunetclub' ); ?></button>
                                        </form>
                                </section>
                        </div>
                </div>
                <?php
        }

        /**
         * @return void
         */
        private function render_notices(): void {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status message from a redirect.
                $notice = isset( $_GET['ariunetclub_notice'] ) ? sanitize_key( wp_unslash( $_GET['ariunetclub_notice'] ) ) : '';
                if ( '' === $notice ) {
                        return;
                }
                $messages = array(
                        'expiry_applied' => __( 'انقضا روی اعتبارهای قدیمی اعمال شد.', 'ariunetclub' ),
                        'migration_restarted' => __( 'اجرای مهاجرت مانده‌ها آغاز شد.', 'ariunetclub' ),
                        'reconcile_started'   => __( 'بررسی مغایرت‌گیری آغاز شد.', 'ariunetclub' ),
                );
                if ( isset( $messages[ $notice ] ) ) {
                        echo '<div class="ariunetclub-notice ariunetclub-notice--info">' . esc_html( $messages[ $notice ] ) . '</div>';
                }
        }

        /**
         * @param array<string,mixed> $migration Migration status row.
         * @return void
         */
        private function render_migration_report( array $migration ): void {
                if ( empty( $migration ) ) {
                        echo '<p class="description">' . esc_html__( 'هنوز گزارشی ثبت نشده است.', 'ariunetclub' ) . '</p>';
                        return;
                }
                $status = 'done' === (string) ( $migration['status'] ?? '' ) ? __( 'تمام‌شده', 'ariunetclub' ) : __( 'در حال اجرا', 'ariunetclub' );
                ?>
                <table class="ariunetclub-table"><tbody>
                        <tr><th scope="row"><?php esc_html_e( 'وضعیت', 'ariunetclub' ); ?></th><td><?php echo esc_html( $status ); ?></td></tr>
                        <tr><th scope="row"><?php esc_html_e( 'کاربران بررسی‌شده', 'ariunetclub' ); ?></th><td><?php echo esc_html( Money::to_persian_digits( (string) (int) ( $migration['processed'] ?? 0 ) ) ); ?></td></tr>
                        <tr><th scope="row"><?php esc_html_e( 'ردیف قدیمی ساخته‌شده', 'ariunetclub' ); ?></th><td><?php echo esc_html( Money::to_persian_digits( (string) (int) ( $migration['created'] ?? 0 ) ) ); ?></td></tr>
                        <tr><th scope="row"><?php esc_html_e( 'تکمیل‌شدهٔ شکاف', 'ariunetclub' ); ?></th><td><?php echo esc_html( Money::to_persian_digits( (string) (int) ( $migration['topped_up'] ?? 0 ) ) ); ?></td></tr>
                        <tr><th scope="row"><?php esc_html_e( 'بدون نیاز به تغییر', 'ariunetclub' ); ?></th><td><?php echo esc_html( Money::to_persian_digits( (string) (int) ( $migration['skipped'] ?? 0 ) ) ); ?></td></tr>
                </tbody></table>
                <?php
                $errors = isset( $migration['errors'] ) && is_array( $migration['errors'] ) ? $migration['errors'] : array();
                if ( ! empty( $errors ) ) {
                        echo '<div class="ariunetclub-notice ariunetclub-notice--warning"><ul>';
                        foreach ( array_slice( $errors, 0, 10 ) as $error ) {
                                echo '<li>' . esc_html( (string) $error ) . '</li>';
                        }
                        echo '</ul></div>';
                }
        }

        /**
         * @param array<string,mixed> $status Reconciliation status row.
         * @return void
         */
        private function render_reconcile_report( array $status ): void {
                if ( empty( $status ) ) {
                        echo '<p class="description">' . esc_html__( 'هنوز بررسی‌ای ثبت نشده است.', 'ariunetclub' ) . '</p>';
                        return;
                }
                $drift = (int) ( $status['drift_count'] ?? 0 );
                ?>
                <table class="ariunetclub-table"><tbody>
                        <tr><th scope="row"><?php esc_html_e( 'آخرین اجرا', 'ariunetclub' ); ?></th><td><?php echo esc_html( isset( $status['updated_at'] ) && (int) $status['updated_at'] > 0 ? Money::to_persian_digits( JalaliDate::format( gmdate( 'Y-m-d H:i:s', (int) $status['updated_at'] ) ) ) : '—' ); ?></td></tr>
                        <tr><th scope="row"><?php esc_html_e( 'کاربران بررسی‌شده', 'ariunetclub' ); ?></th><td><?php echo esc_html( Money::to_persian_digits( (string) (int) ( $status['checked'] ?? 0 ) ) ); ?></td></tr>
                        <tr><th scope="row"><?php esc_html_e( 'مغایرت', 'ariunetclub' ); ?></th><td><?php echo esc_html( 0 === $drift ? __( 'بدون مغایرت', 'ariunetclub' ) : sprintf( /* translators: %s: count. */ __( '%s کاربر', 'ariunetclub' ), Money::to_persian_digits( (string) $drift ) ) ); ?></td></tr>
                </tbody></table>
                <?php
                $samples = isset( $status['samples'] ) && is_array( $status['samples'] ) ? $status['samples'] : array();
                if ( $drift > 0 && ! empty( $samples ) ) {
                        echo '<div class="ariunetclub-notice ariunetclub-notice--warning"><p>' . esc_html__( 'نمونهٔ مغایرت‌ها (اصلاح خودکار انجام نمی‌شود):', 'ariunetclub' ) . '</p><ul>';
                        foreach ( array_slice( $samples, 0, 10 ) as $sample ) {
                                echo '<li>' . esc_html( sprintf( 'user %d — %s', (int) ( $sample['user_id'] ?? 0 ), (string) ( $sample['problem'] ?? '' ) ) ) . '</li>';
                        }
                        echo '</ul></div>';
                }
        }

        /**
         * admin-post: restart the balance migration.
         *
         * @return void
         */
        public function migrate_restart(): void {
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_die( esc_html__( 'شما اجازهٔ دسترسی به این صفحه را ندارید.', 'ariunetclub' ) );
                }
                check_admin_referer( 'ariunetclub_credit_migrate_restart' );
                ( new BalanceMigration( $this->lots, $this->get_wpdb(), $this->logger ) )->restart();
                $this->redirect( 'migration_restarted' );
        }

        /**
         * admin-post: apply expiry to legacy lots (explicit confirmation, at
         * least 30 days of notice — the phase 6 rule).
         *
         * @return void
         */
        public function apply_expiry(): void {
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_die( esc_html__( 'شما اجازهٔ دسترسی به این صفحه را ندارید.', 'ariunetclub' ) );
                }
                $guard = \AriunetClub\Modules\License\LicenseGuard::instance();
                if ( ! $guard->can_save_settings() ) {
                        wp_die( esc_html__( 'تا زمانی که لایسنس فعال نباشد، تغییر سیاست انقضا ممکن نیست. کسب و خرج اعتبار مشتریان بدون وقفه ادامه می‌یابد.', 'ariunetclub' ) );
                }
                check_admin_referer( 'ariunetclub_credit_apply_expiry' );
                $confirm = isset( $_POST['confirm'] ) && '1' === sanitize_key( (string) wp_unslash( $_POST['confirm'] ?? '' ) );
                if ( ! $confirm ) {
                        wp_die( esc_html__( 'برای اعمال انقضا روی اعتبارهای قدیمی، تأیید صریح الزامی است.', 'ariunetclub' ) );
                }
                $config = $this->settings->get();
                if ( empty( $config['expiry_enabled'] ) ) {
                        wp_die( esc_html__( 'ابتدا انقضا را از تنظیمات فعال کنید.', 'ariunetclub' ) );
                }
                $days = isset( $config['expiry_days'] ) && is_numeric( $config['expiry_days'] ) ? (int) $config['expiry_days'] : 90;
                $days = max( 30, $days ); // at least 30 days of customer notice, per the phase 6 rule.
                $expires_at = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
                $this->lots->apply_legacy_expiry( $expires_at );
                update_option( 'ariunetclub_expiry_legacy_applied', time(), false );
                $this->logger->info( 'Legacy credit expiry applied by admin.', array( 'expires_at' => $expires_at, 'days' => $days ) );
                $this->redirect( 'expiry_applied' );
        }

        /**
         * admin-post: run the reconciliation scan now.
         *
         * @return void
         */
        public function reconcile_now(): void {
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_die( esc_html__( 'شما اجازهٔ دسترسی به این صفحه را ندارید.', 'ariunetclub' ) );
                }
                check_admin_referer( 'ariunetclub_credit_reconcile_now' );
                ( new ReconciliationJob( $this->lots, $this->logger ) )->restart();
                $this->redirect( 'reconcile_started' );
        }

        /**
         * @param string $notice Notice key.
         * @return void
         */
        private function redirect( string $notice ): void {
                wp_safe_redirect(
                        add_query_arg(
                                array(
                                        'page'               => self::SLUG,
                                        'ariunetclub_notice' => $notice,
                                ),
                                admin_url( 'admin.php' )
                        )
                );
                exit;
        }

        /**
         * @return \wpdb
         */
        private function get_wpdb(): \wpdb {
                global $wpdb;
                return $wpdb;
        }
}
