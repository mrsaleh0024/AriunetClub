<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Rules;

use AriunetClub\Core\JalaliDate;
use AriunetClub\Core\Money;
use AriunetClub\Core\Settings;
use AriunetClub\Modules\Events\EventLabels;

/**
 * Admin CRUD for earning rules: readable sentence-style PHP form (works
 * without JS) and a list styled like the phase 1 wallet pages.
 */
class RulesAdminPage {
                                /** @var RuleRepository */
                                private $repository;

                                /** @var Settings */
                                private $settings;

        public function __construct( RuleRepository $repository, Settings $settings ) {
                                        $this->repository = $repository;
                                        $this->settings   = $settings;
        }

        public function register(): void {
                                        add_action( 'admin_menu', array( $this, 'menu' ), 20 );
                                        add_action( 'admin_post_ariunetclub_save_rule', array( $this, 'save' ) );
                                        add_action( 'admin_post_ariunetclub_rule_action', array( $this, 'rule_action' ) );
        }

        public function menu(): void {
                                        add_submenu_page(
                                                'ariunetclub-settings',
                                                __( 'قوانین کش‌بک', 'ariunetclub' ),
                                                __( 'قوانین کش‌بک', 'ariunetclub' ),
                                                'manage_woocommerce',
                                                'ariunetclub-rules',
                                                array( $this, 'render_list' )
                                        );
                                        add_submenu_page(
                                                null,
                                                __( 'ویرایش قانون', 'ariunetclub' ),
                                                __( 'ویرایش قانون', 'ariunetclub' ),
                                                'manage_woocommerce',
                                                'ariunetclub-rule-edit',
                                                array( $this, 'render_form' )
                                        );
        }

                                /**
                                 * Gate for every mutating action (S3, S4).
                                 */
        private function guard(): void {
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                                                wp_die( esc_html__( 'شما اجازهٔ دسترسی به این صفحه را ندارید.', 'ariunetclub' ) );
                }
        }

                                /**
                                 * Handles toggle/delete for a rule.
                                 */
        public function rule_action(): void {
                                        $this->guard();
                                        \AriunetClub\Modules\License\LicenseGuard::instance()->assert_rule_management();
                                        check_admin_referer( 'ariunetclub_rule_action' );

                                        $rule_id = isset( $_POST['rule_id'] ) ? absint( wp_unslash( $_POST['rule_id'] ) ) : 0;
                                        $kind    = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
                                        $rule    = $rule_id > 0 ? $this->repository->find( $rule_id ) : null;
                if ( $rule_id <= 0 || null === $rule ) {
                                                wp_die( esc_html__( 'قانون مورد نظر پیدا نشد.', 'ariunetclub' ) );
                }

                if ( 'delete' === $kind ) {
                                                $this->repository->delete( $rule_id );
                                                $notice = 'deleted';
                } elseif ( 'toggle' === $kind ) {
                                                $next = 'active' === (string) $rule['status'] ? 'draft' : 'active';
                                                $this->repository->set_status( $rule_id, array( $next ) );
                                                $notice = 'toggled';
                } else {
                                                wp_die( esc_html__( 'عملیات نامعتبر است.', 'ariunetclub' ) );
                }

                                        wp_safe_redirect(
                                                add_query_arg(
                                                        array(
                                                                'page'               => 'ariunetclub-rules',
                                                                'ariunetclub_notice' => $notice,
                                                        ),
                                                        admin_url( 'admin.php' )
                                                )
                                        );
                                        exit;
        }

                                /**
                                 * Validates and stores the rule form.
                                 */
        public function save(): void {
                                        $this->guard();
                                        \AriunetClub\Modules\License\LicenseGuard::instance()->assert_rule_management();
                                        check_admin_referer( 'ariunetclub_save_rule', 'ariunetclub_nonce' );

                                        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is sanitized below.
                                        $raw     = isset( $_POST['ariunetclub_rule'] ) && is_array( $_POST['ariunetclub_rule'] ) ? wp_unslash( $_POST['ariunetclub_rule'] ) : array();
                                        $rule    = $this->sanitize_rule( $raw );
                                        $rule_id = isset( $_POST['rule_id'] ) ? absint( wp_unslash( $_POST['rule_id'] ) ) : 0;

                if ( '' === $rule['title'] ) {
                                                wp_die( esc_html__( 'عنوان قانون الزامی است.', 'ariunetclub' ) );
                }

                if ( $rule_id > 0 && $this->repository->find( $rule_id ) ) {
                                                $this->repository->update( $rule_id, $rule );
                } else {
                                                $rule_id = $this->repository->insert( $rule );
                }

                                        wp_safe_redirect(
                                                add_query_arg(
                                                        array(
                                                                'page'               => 'ariunetclub-rule-edit',
                                                                'rule_id'            => $rule_id,
                                                                'ariunetclub_notice' => 'saved',
                                                        ),
                                                        admin_url( 'admin.php' )
                                                )
                                        );
                                        exit;
        }

                                /**
                                 * Normalizes every submitted field into its stored shape (S2, S13).
                                 *
                                 * @param array<string,mixed> $raw Raw user input.
                                 * @return array<string,mixed>
                                 */
        private function sanitize_rule( array $raw ): array {
                                        $title  = isset( $raw['title'] ) ? sanitize_text_field( (string) $raw['title'] ) : '';
                                        $status = isset( $raw['status'] ) && 'active' === $raw['status'] ? 'active' : 'draft';
                                        $event  = isset( $raw['event'] ) ? (string) $raw['event'] : '';
                if ( ! $this->is_known_event( $event ) ) {
                                                $config = $this->settings->get();
                                                $event  = 'order.status.' . ( isset( $config['earning_order_status'] ) && is_string( $config['earning_order_status'] ) ? $config['earning_order_status'] : 'completed' );
                }

                                        $conditions = array(
                                                'min_amount'      => isset( $raw['min_amount'] ) ? max( 0.0, (float) $raw['min_amount'] ) : 0.0,
                                                'categories'      => array(
                                                        'ids'  => isset( $raw['categories'] ) && is_array( $raw['categories'] ) ? array_values( array_filter( array_map( 'absint', $raw['categories'] ) ) ) : array(),
                                                        'mode' => isset( $raw['categories_mode'] ) && 'exclude' === $raw['categories_mode'] ? 'exclude' : 'include',
                                                ),
                                                'products'        => array(
                                                        'ids'  => isset( $raw['products'] ) && is_array( $raw['products'] ) ? array_values( array_filter( array_map( 'absint', $raw['products'] ) ) ) : array(),
                                                        'mode' => isset( $raw['products_mode'] ) && 'exclude' === $raw['products_mode'] ? 'exclude' : 'include',
                                                ),
                                                'first_order'     => isset( $raw['first_order'] ) ? true : false,
                                                'user_roles'      => isset( $raw['user_roles'] ) && is_array( $raw['user_roles'] ) ? array_values( array_filter( array_map( 'sanitize_key', $raw['user_roles'] ) ) ) : array(),
                                                'payment_methods' => isset( $raw['payment_methods'] ) && is_array( $raw['payment_methods'] ) ? array_values( array_filter( array_map( 'sanitize_key', $raw['payment_methods'] ) ) ) : array(),
                                        );

                                        $reward_type = isset( $raw['reward_type'] ) && 'fixed' === $raw['reward_type'] ? 'fixed' : 'percent';
                                        $reward      = array(
                                                'type'    => $reward_type,
                                                'value'   => isset( $raw['reward_value'] ) ? max( 0.0, (float) $raw['reward_value'] ) : 0.0,
                                                'max_cap' => 'percent' === $reward_type && isset( $raw['reward_cap'] ) ? max( 0.0, (float) $raw['reward_cap'] ) : 0.0,
                                        );

                                        $limits = array(
                                                'per_user_count' => isset( $raw['per_user_count'] ) ? absint( $raw['per_user_count'] ) : 0,
                                                'per_user_days'  => isset( $raw['per_user_days'] ) ? absint( $raw['per_user_days'] ) : 0,
                                                'total_times'    => isset( $raw['total_times'] ) ? absint( $raw['total_times'] ) : 0,
                                                'total_amount'   => isset( $raw['total_amount'] ) ? max( 0.0, (float) $raw['total_amount'] ) : 0.0,
                                        );

                                        return array(
                                                'title'      => $title,
                                                'status'     => $status,
                                                'event_type' => $event,
                                                'conditions' => (string) wp_json_encode( $conditions ),
                                                'reward'     => (string) wp_json_encode( $reward ),
                                                'limits'     => (string) wp_json_encode( $limits ),
                                                'priority'   => isset( $raw['priority'] ) ? (int) $raw['priority'] : 10,
                                                'exclusive'  => isset( $raw['exclusive'] ) ? 1 : 0,
                                                'starts_at'  => $this->sanitize_datetime( $raw['starts_at'] ?? '' ),
                                                'ends_at'    => $this->sanitize_datetime( $raw['ends_at'] ?? '' ),
                                        );
        }

                                /**
                                 * datetime-local input is site time; stored value is UTC (contract of section 5).
                                 *
                                 * @param string $value Raw input.
                                 * @return string|null UTC mysql string or null.
                                 */
        private function sanitize_datetime( string $value ): ?string {
                                        $value = trim( $value );
                if ( '' === $value ) {
                                                return null;
                }
                                        $date = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $value, wp_timezone() );
                if ( false === $date ) {
                                                $date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $value, wp_timezone() );
                }
                if ( false === $date ) {
                                                return null;
                }
                                        return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        }

                                /**
                                 * @param string $event Candidate event type.
                                 * @return bool
                                 */
        private function is_known_event( string $event ): bool {
                if ( 'user.registered' === $event || 'product.review.approved' === $event || 'order.refunded' === $event ) {
                                                return true;
                }
                if ( 0 === strpos( $event, 'order.status.' ) ) {
                                                return array_key_exists( substr( $event, strlen( 'order.status.' ) ), EventLabels::order_statuses() );
                }
                                        return false;
        }

                                /**
                                 * Renders the rules list.
                                 */
        public function render_list(): void {
                                        $this->guard();
                                        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sanitized read-only input.
                                        $page   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
                                        $result = $this->repository->list( $page, 20 );
                                        $base   = add_query_arg( array( 'page' => 'ariunetclub-rules' ), admin_url( 'admin.php' ) );
                                        $edit   = add_query_arg( array( 'page' => 'ariunetclub-rule-edit' ), admin_url( 'admin.php' ) );
                ?>
                                                                <div class="wrap ariunetclub-scope" dir="rtl">
                                                                                                <div class="ariunetclub-admin-shell">
                                                                                                                                <header class="ariunetclub-admin-header">
                                                                                                                                                                <span class="ariunetclub-admin-kicker"><?php esc_html_e( 'ARIUNETCLUB', 'ariunetclub' ); ?></span>
                                                                                                                                                                <h1><?php esc_html_e( 'قوانین کش‌بک', 'ariunetclub' ); ?></h1>
                                                                                                                                                                <p><?php esc_html_e( 'قوانین «رویداد ← شرط ← پاداش» را این‌جا تعریف کنید؛ هر قانون برای رویدادِ خودش اعتبار صادر می‌کند.', 'ariunetclub' ); ?></p>
                                                                                                                                </header>

                                                                                                                        <?php
                                                                                                                        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status message.
                                                                                                                        if ( isset( $_GET['ariunetclub_notice'] ) ) :
                                                                                                                                        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status message.
                                                                                                                                        $notice = sanitize_key( wp_unslash( $_GET['ariunetclub_notice'] ) );

                                                                                                                                if ( 'deleted' === $notice ) :
                                                                                                                                        ?>
                                                                                                                                                                <div class="ariunetclub-notice ariunetclub-notice-info"><?php esc_html_e( 'قانون با موفقیت حذف شد.', 'ariunetclub' ); ?></div>
                                                                                                                                                <?php elseif ( 'toggled' === $notice ) : ?>
                                                                                                                                                                <div class="ariunetclub-notice ariunetclub-notice-info"><?php esc_html_e( 'وضعیت قانون تغییر کرد.', 'ariunetclub' ); ?></div>
                                                                                                                                                <?php endif; ?>
                                                                                                                                <?php endif; ?>

                                                                                                                                <section class="ariunetclub-panel">
                                                                                                                                                                <div class="ariunetclub-section-heading">
                                                                                                                                                                                                <div>
                                                                                                                                                                                                                                <h2><?php esc_html_e( 'قوانین تعریف‌شده', 'ariunetclub' ); ?></h2>
                                                                                                                                                                                                                                <p><?php esc_html_e( 'اولویت کمتر، اجرای زودتر. قانون «انحصاری» بقیهٔ قوانین هم‌رویداد را متوقف می‌کند.', 'ariunetclub' ); ?></p>
                                                                                                                                                                                                </div>
                                                                                                                                                                                                <a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'rule_id', 0, $edit ) ); ?>"><?php esc_html_e( 'قانون جدید', 'ariunetclub' ); ?></a>
                                                                                                                                                                </div>

                                                                                                                                                <?php if ( empty( $result['items'] ) ) : ?>
                                                                                                                                                                                                <div class="ariunetclub-empty">
                                                                                                                                                                                                                                <h2><?php esc_html_e( 'هنوز قانونی ندارید', 'ariunetclub' ); ?></h2>
                                                                                                                                                                                                                                <p><?php esc_html_e( 'با اولین قانون، کش‌بک پس از رسیدن سفارش‌ها به وضعیت مبنای آن به‌صورت خودکار در اعتبار باشگاه مشتری نشسته می‌شود.', 'ariunetclub' ); ?></p>
                                                                                                                                                                                                </div>
                                                                                                                                                                <?php else : ?>
                                                                                                                                                                                                <div class="ariunetclub-table-scroll">
                                                                                                                                                                                                                                <table class="ariunetclub-table ariunetclub-rules-table">
                                                                                                                                                                                                                                                                <thead>
                                                                                                                                                                                                                                                                                                <tr>
                                                                                                                                                                                                                                                                                                                                <th><?php esc_html_e( 'قانون', 'ariunetclub' ); ?></th>
                                                                                                                                                                                                                                                                                                                                <th><?php esc_html_e( 'رویداد', 'ariunetclub' ); ?></th>
                                                                                                                                                                                                                                                                                                                                <th><?php esc_html_e( 'وضعیت', 'ariunetclub' ); ?></th>
                                                                                                                                                                                                                                                                                                                                <th><?php esc_html_e( 'اولویت', 'ariunetclub' ); ?></th>
                                                                                                                                                                                                                                                                                                                                <th><?php esc_html_e( 'بازه', 'ariunetclub' ); ?></th>
                                                                                                                                                                                                                                                                                                                                <th><?php esc_html_e( 'عملیات', 'ariunetclub' ); ?></th>
                                                                                                                                                                                                                                                                                                </tr>
                                                                                                                                                                                                                                                                </thead>
                                                                                                                                                                                                                                                                <tbody>
                                                                                                                                                                                                                                                                                                <?php foreach ( $result['items'] as $rule ) : ?>
                                                                                                                                                                                                                                                                                                                                <?php
                                                                                                                                                                                                                                                                                                                                $rule_id     = (int) $rule['id'];
                                                                                                                                                                                                                                                                                                                                $is_active   = 'active' === (string) $rule['status'];
                                                                                                                                                                                                                                                                                                                                $toggle_url  = admin_url( 'admin-post.php' );
                                                                                                                                                                                                                                                                                                                                $window      = array();
                                                                                                                                                                                                                                                                                                                                $window[]    = '' === (string) ( $rule['starts_at'] ?? '' ) ? null : JalaliDate::format( (string) $rule['starts_at'] );
                                                                                                                                                                                                                                                                                                                                $window[]    = '' === (string) ( $rule['ends_at'] ?? '' ) ? null : JalaliDate::format( (string) $rule['ends_at'] );
                                                                                                                                                                                                                                                                                                                                $window_text = implode( ' ← ', array_filter( $window ) );
                                                                                                                                                                                                                                                                                                                                ?>
                                                                                                                                                                                                                                                                                                                                <tr>
                                                                                                                                                                                                                                                                                                                                                                <td>
                                                                                                                                                                                                                                                                                                                                                                                                <strong><?php echo esc_html( (string) $rule['title'] ); ?></strong><br />
                                                                                                                                                                                                                                                                                                                                                                                                <span class="ariunetclub-muted"><?php echo esc_html( $this->summary( (array) $rule ) ); ?></span>
                                                                                                                                                                                                                                                                                                                                                                </td>
                                                                                                                                                                                                                                                                                                                                                                <td><?php echo esc_html( EventLabels::type( (string) $rule['event_type'] ) ); ?></td>
                                                                                                                                                                                                                                                                                                                                                                <td><span class="ariunetclub-badge ariunetclub-badge--<?php echo esc_attr( $is_active ? 'active' : 'draft' ); ?>"><?php echo esc_html( $is_active ? 'فعال' : 'پیش‌نویس' ); ?></span></td>
                                                                                                                                                                                                                                                                                                                                                                <td><?php echo esc_html( Money::to_persian_digits( (string) ( $rule['priority'] ?? 10 ) ) ); ?></td>
                                                                                                                                                                                                                                                                                                                                                                <td><?php echo esc_html( '' !== $window_text ? $window_text : '—' ); ?></td>
                                                                                                                                                                                                                                                                                                                                                                <td>
                                                                                                                                                                                                                                                                                                                                                                                                <a href="<?php echo esc_url( add_query_arg( 'rule_id', $rule_id, $edit ) ); ?>"><?php esc_html_e( 'ویرایش', 'ariunetclub' ); ?></a>
                                                                                                                                                                                                                                                                                                                                                                                                <form method="post" action="<?php echo esc_url( $toggle_url ); ?>" class="ariunetclub-inline-form">
                                                                                                                                                                                                                                                                                                                                                                                                                                <input type="hidden" name="action" value="ariunetclub_rule_action" />
                                                                                                                                                                                                                                                                                                                                                                                                                                <input type="hidden" name="rule_id" value="<?php echo esc_attr( (string) $rule_id ); ?>" />
                                                                                                                                                                                                                                                                                                                                                                                                                                <input type="hidden" name="kind" value="toggle" />
                                                                                                                                                                                                                                                                                                                                                                                                                                <?php wp_nonce_field( 'ariunetclub_rule_action' ); ?>
                                                                                                                                                                                                                                                                                                                                                                                                                                <button class="button ariunetclub-button-secondary" type="submit"><?php echo esc_html( $is_active ? 'غیرفعال‌سازی' : 'فعال‌سازی' ); ?></button>
                                                                                                                                                                                                                                                                                                                                                                                                </form>
                                                                                                                                                                                                                                                                                                                                                                                                <form method="post" action="<?php echo esc_url( $toggle_url ); ?>" class="ariunetclub-inline-form" data-ariunetclub-confirm="<?php esc_attr_e( 'این قانون برای همیشه حذف شود؟ اعتبارهای صادرشدهٔ قبلی باقی می‌مانند.', 'ariunetclub' ); ?>">
                                                                                                                                                                                                                                                                                                                                                                                                                                <input type="hidden" name="action" value="ariunetclub_rule_action" />
                                                                                                                                                                                                                                                                                                                                                                                                                                <input type="hidden" name="rule_id" value="<?php echo esc_attr( (string) $rule_id ); ?>" />
                                                                                                                                                                                                                                                                                                                                                                                                                                <input type="hidden" name="kind" value="delete" />
                                                                                                                                                                                                                                                                                                                                                                                                                                <?php wp_nonce_field( 'ariunetclub_rule_action' ); ?>
                                                                                                                                                                                                                                                                                                                                                                                                                                <button class="button ariunetclub-button-secondary" type="submit"><?php esc_html_e( 'حذف', 'ariunetclub' ); ?></button>
                                                                                                                                                                                                                                                                                                                                                                                                </form>
                                                                                                                                                                                                                                                                                                                                                                </td>
                                                                                                                                                                                                                                                                                                                                </tr>
                                                                                                                                                                                                                                                                                                <?php endforeach; ?>
                                                                                                                                                                                                                                                                </tbody>
                                                                                                                                                                                                                                </table>
                                                                                                                                                                                                </div>
                                                                                                                                                                                                <?php $this->pagination( $page, $result['total'], $base ); ?>
                                                                                                                                                                <?php endif; ?>
                                                                                                                                </section>
                                                                                                </div>
                                                                </div>
                                                                <?php
        }

                                /**
                                 * Renders the sentence-style rule form.
                                 */
        public function render_form(): void {
                                        $this->guard();
                                        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sanitized read-only input.
                                        $rule_id = isset( $_GET['rule_id'] ) ? absint( wp_unslash( $_GET['rule_id'] ) ) : 0;
                                        $rule    = $rule_id > 0 ? $this->repository->find( $rule_id ) : null;
                                        $rule    = is_array( $rule ) ? $rule : $this->defaults_for_form();

                                        $config     = $this->settings->get();
                                        $default    = isset( $config['earning_order_status'] ) && is_string( $config['earning_order_status'] ) ? $config['earning_order_status'] : 'completed';
                                        $events     = $this->event_options( $default );
                                        $conditions = RuleEngine::json( $rule['conditions'] ?? null );
                                        $reward     = RuleEngine::json( $rule['reward'] ?? null );
                                        $limits     = RuleEngine::json( $rule['limits'] ?? null );

                                        $categories = $this->category_options();
                                        $products   = $this->product_options();
                                        $roles      = $this->role_options();
                                        $methods    = $this->payment_options();

                                        $form_action = admin_url( 'admin-post.php' );
                                        $list_url    = add_query_arg( array( 'page' => 'ariunetclub-rules' ), admin_url( 'admin.php' ) );
                                        $starts      = '' === (string) ( $rule['starts_at'] ?? '' ) ? '' : $this->local_datetime( (string) $rule['starts_at'] );
                                        $ends        = '' === (string) ( $rule['ends_at'] ?? '' ) ? '' : $this->local_datetime( (string) $rule['ends_at'] );
                ?>
                                                                <div class="wrap ariunetclub-scope" dir="rtl">
                                                                                                <div class="ariunetclub-admin-shell">
                                                                                                                                <header class="ariunetclub-admin-header">
                                                                                                                                                                <span class="ariunetclub-admin-kicker"><?php esc_html_e( 'ARIUNETCLUB', 'ariunetclub' ); ?></span>
                                                                                                                                                                <h1><?php echo esc_html( $rule_id > 0 ? 'ویرایش قانون' : 'قانون جدید' ); ?></h1>
                                                                                                                                                                <p><?php esc_html_e( 'فرم را مثل یک جمله‌ی ساده پر کنید؛ خلاصه‌ی قانون زیر فرم به‌صورت زنده ساخته می‌شود.', 'ariunetclub' ); ?></p>
                                                                                                                                </header>

                                                                                                        <?php if ( isset( $_GET['ariunetclub_notice'] ) && 'saved' === sanitize_key( wp_unslash( $_GET['ariunetclub_notice'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status message. ?>
                                                                                                                                                                <div class="ariunetclub-notice ariunetclub-notice--info"><?php esc_html_e( 'قانون با موفقیت ذخیره شد.', 'ariunetclub' ); ?></div>
                                                                                                                                <?php endif; ?>

                                                                                                                                <form method="post" action="<?php echo esc_url( $form_action ); ?>" class="ariunetclub-panel ariunetclub-rule-form">
                                                                                                                                                                <input type="hidden" name="action" value="ariunetclub_save_rule" />
                                                                                                                                                                <input type="hidden" name="rule_id" value="<?php echo esc_attr( (string) $rule_id ); ?>" />
                                                                                                                                        <?php wp_nonce_field( 'ariunetclub_save_rule', 'ariunetclub_nonce' ); ?>

                                                                                                                                                                <section class="ariunetclub-rule-section">
                                                                                                                                                                                                <h2><?php esc_html_e( 'شناسنامهٔ قانون', 'ariunetclub' ); ?></h2>
                                                                                                                                                                                                <div class="ariunetclub-rule-grid">
                                                                                                                                                                                                                                <p class="ariunetclub-field">
                                                                                                                                                                                                                                                                <label for="ariunetclub-rule-title"><?php esc_html_e( 'عنوان قانون', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                <input id="ariunetclub-rule-title" name="ariunetclub_rule[title]" type="text" required value="<?php echo esc_attr( (string) ( $rule['title'] ?? '' ) ); ?>" />
                                                                                                                                                                                                                                </p>
                                                                                                                                                                                                                                <p class="ariunetclub-field">
                                                                                                                                                                                                                                                                <label for="ariunetclub-rule-status"><?php esc_html_e( 'وضعیت', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                <select id="ariunetclub-rule-status" name="ariunetclub_rule[status]">
                                                                                                                                                                                                                                                                                                <option value="draft" <?php selected( (string) ( $rule['status'] ?? 'draft' ), 'draft' ); ?>><?php esc_html_e( 'پیش‌نویس', 'ariunetclub' ); ?></option>
                                                                                                                                                                                                                                                                                                <option value="active" <?php selected( (string) ( $rule['status'] ?? '' ), 'active' ); ?>><?php esc_html_e( 'فعال', 'ariunetclub' ); ?></option>
                                                                                                                                                                                                                                                                </select>
                                                                                                                                                                                                                                </p>
                                                                                                                                                                                                </div>
                                                                                                                                                                </section>

                                                                                                                                                                <section class="ariunetclub-rule-section">
                                                                                                                                                                                                <h2><?php esc_html_e( '۱. وقتی که...', 'ariunetclub' ); ?></h2>
                                                                                                                                                                                                <p class="ariunetclub-field">
                                                                                                                                                                                                                                <label for="ariunetclub-rule-event"><?php esc_html_e( 'رویداد', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                <select id="ariunetclub-rule-event" name="ariunetclub_rule[event]" data-ariunetclub-rule-event>
                                                                                                                                                                                                                                        <?php foreach ( $events as $value => $label ) : ?>
                                                                                                                                                                                                                                                                                                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) ( $rule['event_type'] ?? '' ), $value ); ?>><?php echo esc_html( $label ); ?></option>
                                                                                                                                                                                                                                                                <?php endforeach; ?>
                                                                                                                                                                                                                                </select>
                                                                                                                                                                                                                                <span class="description"><?php esc_html_e( 'پیش‌فرض از تنظیمات باشگاه می‌آید؛ هر قانون می‌تواند وضعیت سفارش خودش را داشته باشد.', 'ariunetclub' ); ?></span>
                                                                                                                                                                                                </p>
                                                                                                                                                                </section>

                                                                                                                                                                <section class="ariunetclub-rule-section">
                                                                                                                                                                                                <h2><?php esc_html_e( '۲. و شرط‌ها این بود که...', 'ariunetclub' ); ?></h2>
                                                                                                                                                                                                <div class="ariunetclub-rule-grid">
                                                                                                                                                                                                                                <p class="ariunetclub-field">
                                                                                                                                                                                                                                                                <label for="ariunetclub-rule-min"><?php esc_html_e( 'حداقل مبلغ سفارش (تومان)', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                <input id="ariunetclub-rule-min" name="ariunetclub_rule[min_amount]" type="number" step="any" min="0" value="<?php echo esc_attr( (string) ( $conditions['min_amount'] ?? '' ) ); ?>" />
                                                                                                                                                                                                                                                                <span class="description"><?php esc_html_e( 'خالی یا صفر یعنی بدون شرط مبلغ.', 'ariunetclub' ); ?></span>
                                                                                                                                                                                                                                </p>
                                                                                                                                                                                                                                <p class="ariunetclub-field">
                                                                                                                                                                                                                                                                <label for="ariunetclub-rule-first"><?php esc_html_e( 'فقط اولین سفارش مشتری', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                <span class="ariunetclub-check"><input id="ariunetclub-rule-first" name="ariunetclub_rule[first_order]" type="checkbox" value="1" <?php checked( ! empty( $conditions['first_order'] ) ); ?> /> <?php esc_html_e( 'این پاداش فقط به اولین سفارش پرداخت‌شدهٔ مشتری تعلق می‌گیرد.', 'ariunetclub' ); ?></span>
                                                                                                                                                                                                                                </p>
                                                                                                                                                                                                </div>
                                                                                                                                                                                                <div class="ariunetclub-rule-grid">
                                                                                                                                                                                                                                <p class="ariunetclub-field">
                                                                                                                                                                                                                                                                <label for="ariunetclub-rule-cats"><?php esc_html_e( 'دسته‌بندی‌ها', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                <select id="ariunetclub-rule-cats" name="ariunetclub_rule[categories][]" multiple size="6">
                                                                                                                                                                                                                                                                        <?php foreach ( $categories as $id => $label ) : ?>
                                                                                                                                                                                                                                                                                                                                <option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( true, in_array( (int) $id, isset( $conditions['categories']['ids'] ) && is_array( $conditions['categories']['ids'] ) ? array_map( 'intval', $conditions['categories']['ids'] ) : array(), true ) ); ?>><?php echo esc_html( $label ); ?></option>
                                                                                                                                                                                                                                                                                                <?php endforeach; ?>
                                                                                                                                                                                                                                                                </select>
                                                                                                                                                                                                                                                                <span class="ariunetclub-mode">
                                                                                                                                                                                                                                                                                                <label><input type="radio" name="ariunetclub_rule[categories_mode]" value="include" <?php checked( 'include', $conditions['categories']['mode'] ?? 'include' ); ?> /> <?php esc_html_e( 'شامل', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                                                <label><input type="radio" name="ariunetclub_rule[categories_mode]" value="exclude" <?php checked( 'exclude', $conditions['categories']['mode'] ?? '' ); ?> /> <?php esc_html_e( 'مستثنا', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                </span>
                                                                                                                                                                                                                                                                <span class="description"><?php esc_html_e( 'با کلید Ctrl چند مورد را انتخاب کنید؛ خالی یعنی همهٔ دسته‌ها.', 'ariunetclub' ); ?></span>
                                                                                                                                                                                                                                </p>
                                                                                                                                                                                                                                <p class="ariunetclub-field">
                                                                                                                                                                                                                                                                <label for="ariunetclub-rule-products"><?php esc_html_e( 'محصولات', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                <select id="ariunetclub-rule-products" name="ariunetclub_rule[products][]" multiple size="6">
                                                                                                                                                                                                                                                                        <?php foreach ( $products as $id => $label ) : ?>
                                                                                                                                                                                                                                                                                                                                <option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( true, in_array( (int) $id, isset( $conditions['products']['ids'] ) && is_array( $conditions['products']['ids'] ) ? array_map( 'intval', $conditions['products']['ids'] ) : array(), true ) ); ?>><?php echo esc_html( $label ); ?></option>
                                                                                                                                                                                                                                                                                                <?php endforeach; ?>
                                                                                                                                                                                                                                                                </select>
                                                                                                                                                                                                                                                                <span class="ariunetclub-mode">
                                                                                                                                                                                                                                                                                                <label><input type="radio" name="ariunetclub_rule[products_mode]" value="include" <?php checked( 'include', $conditions['products']['mode'] ?? 'include' ); ?> /> <?php esc_html_e( 'شامل', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                                                <label><input type="radio" name="ariunetclub_rule[products_mode]" value="exclude" <?php checked( 'exclude', $conditions['products']['mode'] ?? '' ); ?> /> <?php esc_html_e( 'مستثنا', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                </span>
                                                                                                                                                                                                                                </p>
                                                                                                                                                                                                </div>
                                                                                                                                                                                                <div class="ariunetclub-rule-grid">
                                                                                                                                                                                                                                <p class="ariunetclub-field">
                                                                                                                                                                                                                                                                <label for="ariunetclub-rule-roles"><?php esc_html_e( 'نقش کاربر', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                <select id="ariunetclub-rule-roles" name="ariunetclub_rule[user_roles][]" multiple size="5">
                                                                                                                                                                                                                                                                        <?php foreach ( $roles as $slug => $label ) : ?>
                                                                                                                                                                                                                                                                                                                                <option value="<?php echo esc_attr( (string) $slug ); ?>" <?php selected( true, in_array( (string) $slug, isset( $conditions['user_roles'] ) && is_array( $conditions['user_roles'] ) ? array_map( 'strval', $conditions['user_roles'] ) : array(), true ) ); ?>><?php echo esc_html( $label ); ?></option>
                                                                                                                                                                                                                                                                                                <?php endforeach; ?>
                                                                                                                                                                                                                                                                </select>
                                                                                                                                                                                                                                                                <span class="description"><?php esc_html_e( 'خالی یعنی همهٔ نقش‌ها.', 'ariunetclub' ); ?></span>
                                                                                                                                                                                                                                </p>
                                                                                                                                                                                                                                <p class="ariunetclub-field">
                                                                                                                                                                                                                                                                <label for="ariunetclub-rule-methods"><?php esc_html_e( 'روش پرداخت', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                <select id="ariunetclub-rule-methods" name="ariunetclub_rule[payment_methods][]" multiple size="5">
                                                                                                                                                                                                                                                                        <?php foreach ( $methods as $slug => $label ) : ?>
                                                                                                                                                                                                                                                                                                                                <option value="<?php echo esc_attr( (string) $slug ); ?>" <?php selected( true, in_array( (string) $slug, isset( $conditions['payment_methods'] ) && is_array( $conditions['payment_methods'] ) ? array_map( 'strval', $conditions['payment_methods'] ) : array(), true ) ); ?>><?php echo esc_html( $label ); ?></option>
                                                                                                                                                                                                                                                                                                <?php endforeach; ?>
                                                                                                                                                                                                                                                                </select>
                                                                                                                                                                                                                                                                <span class="description"><?php esc_html_e( 'خالی یعنی همهٔ درگاه‌ها.', 'ariunetclub' ); ?></span>
                                                                                                                                                                                                                                </p>
                                                                                                                                                                                                </div>
                                                                                                                                                                </section>

                                                                                                                                                                <section class="ariunetclub-rule-section">
                                                                                                                                                                                                <h2><?php esc_html_e( '۳. این پاداش را بده...', 'ariunetclub' ); ?></h2>
                                                                                                                                                                                                <div class="ariunetclub-rule-grid">
                                                                                                                                                                                                                                <p class="ariunetclub-field">
                                                                                                                                                                                                                                                                <label><?php esc_html_e( 'نوع پاداش', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                <span class="ariunetclub-mode">
                                                                                                                                                                                                                                                                                                <label><input type="radio" name="ariunetclub_rule[reward_type]" value="percent" data-ariunetclub-reward="percent" <?php checked( 'percent', $reward['type'] ?? 'percent' ); ?> /> <?php esc_html_e( 'درصد کش‌بک', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                                                <label><input type="radio" name="ariunetclub_rule[reward_type]" value="fixed" data-ariunetclub-reward="fixed" <?php checked( 'fixed', $reward['type'] ?? '' ); ?> /> <?php esc_html_e( 'مبلغ ثابت', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                </span>
                                                                                                                                                                                                                                </p>
                                                                                                                                                                                                                                <p class="ariunetclub-field">
                                                                                                                                                                                                                                                                <label for="ariunetclub-rule-reward-value"><?php esc_html_e( 'مقدار پاداش', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                <input id="ariunetclub-rule-reward-value" name="ariunetclub_rule[reward_value]" type="number" step="any" min="0" value="<?php echo esc_attr( isset( $reward['value'] ) ? (string) $reward['value'] : '' ); ?>" />
                                                                                                                                                                                                                                                                <span class="description"><?php esc_html_e( 'در حالت درصد: درصد از مبنای کش‌بک. در حالت ثابت: مبلغ به تومان.', 'ariunetclub' ); ?></span>
                                                                                                                                                                                                                                </p>
                                                                                                                                                                                                                                <p class="ariunetclub-field">
                                                                                                                                                                                                                                                                <label for="ariunetclub-rule-reward-cap"><?php esc_html_e( 'سقف پاداش (تومان، فقط درصد)', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                <input id="ariunetclub-rule-reward-cap" name="ariunetclub_rule[reward_cap]" type="number" step="any" min="0" value="<?php echo esc_attr( isset( $reward['max_cap'] ) && (float) $reward['max_cap'] > 0 ? (string) $reward['max_cap'] : '' ); ?>" />
                                                                                                                                                                                                                                                                <span class="description"><?php esc_html_e( 'حداکثر کش‌بک برای یک سفارش؛ خالی یعنی بدون سقف.', 'ariunetclub' ); ?></span>
                                                                                                                                                                                                                                </p>
                                                                                                                                                                                                </div>
                                                                                                                                                                </section>

                                                                                                                                                                <section class="ariunetclub-rule-section">
                                                                                                                                                                                                <h2><?php esc_html_e( '۴. محدودیت‌ها و برنامه', 'ariunetclub' ); ?></h2>
                                                                                                                                                                                                <div class="ariunetclub-rule-grid">
                                                                                                                                                                                                                                <p class="ariunetclub-field">
                                                                                                                                                                                                                                                                <label for="ariunetclub-rule-per-count"><?php esc_html_e( 'حداکثر بهره‌مندی هر مشتری (بار)', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                <input id="ariunetclub-rule-per-count" name="ariunetclub_rule[per_user_count]" type="number" step="1" min="0" value="<?php echo esc_attr( isset( $limits['per_user_count'] ) && (int) $limits['per_user_count'] > 0 ? (string) $limits['per_user_count'] : '' ); ?>" />
                                                                                                                                                                                                                                </p>
                                                                                                                                                                                                                                <p class="ariunetclub-field">
                                                                                                                                                                                                                                                                <label for="ariunetclub-rule-per-days"><?php esc_html_e( 'در بازه (روز)', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                <input id="ariunetclub-rule-per-days" name="ariunetclub_rule[per_user_days]" type="number" step="1" min="0" value="<?php echo esc_attr( isset( $limits['per_user_days'] ) && (int) $limits['per_user_days'] > 0 ? (string) $limits['per_user_days'] : '' ); ?>" />
                                                                                                                                                                                                                                                                <span class="description"><?php esc_html_e( 'پنجره‌ی لغزان؛ مثلاً «۳ بار در ۳۰ روز». برای بی‌نهایت خالی بگذارید.', 'ariunetclub' ); ?></span>
                                                                                                                                                                                                                                </p>
                                                                                                                                                                                                </div>
                                                                                                                                                                                                <div class="ariunetclub-rule-grid">
                                                                                                                                                                                                                                <p class="ariunetclub-field">
                                                                                                                                                                                                                                                                <label for="ariunetclub-rule-total-times"><?php esc_html_e( 'سقف کل دفعات (همهٔ مشتری‌ها)', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                <input id="ariunetclub-rule-total-times" name="ariunetclub_rule[total_times]" type="number" step="1" min="0" value="<?php echo esc_attr( isset( $limits['total_times'] ) && (int) $limits['total_times'] > 0 ? (string) $limits['total_times'] : '' ); ?>" />
                                                                                                                                                                                                                                </p>
                                                                                                                                                                                                                                <p class="ariunetclub-field">
                                                                                                                                                                                                                                                                <label for="ariunetclub-rule-total-amount"><?php esc_html_e( 'سقف کل مبلغ صادرشده (تومان)', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                <input id="ariunetclub-rule-total-amount" name="ariunetclub_rule[total_amount]" type="number" step="any" min="0" value="<?php echo esc_attr( isset( $limits['total_amount'] ) && (float) $limits['total_amount'] > 0 ? (string) $limits['total_amount'] : '' ); ?>" />
                                                                                                                                                                                                                                </p>
                                                                                                                                                                                                </div>
                                                                                                                                                                                                <div class="ariunetclub-rule-grid">
                                                                                                                                                                                                                                <p class="ariunetclub-field">
                                                                                                                                                                                                                                                                <label for="ariunetclub-rule-priority"><?php esc_html_e( 'اولویت', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                <input id="ariunetclub-rule-priority" name="ariunetclub_rule[priority]" type="number" step="1" value="<?php echo esc_attr( (string) ( $rule['priority'] ?? 10 ) ); ?>" />
                                                                                                                                                                                                                                                                <span class="description"><?php esc_html_e( 'عدد کوچک‌تر زودتر اجرا می‌شود.', 'ariunetclub' ); ?></span>
                                                                                                                                                                                                                                </p>
                                                                                                                                                                                                                                <p class="ariunetclub-field">
                                                                                                                                                                                                                                                                <label for="ariunetclub-rule-exclusive"><?php esc_html_e( 'قانون انحصاری', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                <span class="ariunetclub-check"><input id="ariunetclub-rule-exclusive" name="ariunetclub_rule[exclusive]" type="checkbox" value="1" <?php checked( ! empty( $rule['exclusive'] ) ); ?> /> <?php esc_html_e( 'اگر این قانون اعمال شود، قوانین بعدی همان رویداد اجرا نمی‌شوند.', 'ariunetclub' ); ?></span>
                                                                                                                                                                                                                                </p>
                                                                                                                                                                                                </div>
                                                                                                                                                                                                <div class="ariunetclub-rule-grid">
                                                                                                                                                                                                                                <p class="ariunetclub-field">
                                                                                                                                                                                                                                                                <label for="ariunetclub-rule-starts"><?php esc_html_e( 'شروع (ساعت سایت)', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                <input id="ariunetclub-rule-starts" name="ariunetclub_rule[starts_at]" type="datetime-local" value="<?php echo esc_attr( $starts ); ?>" />
                                                                                                                                                                                                                                </p>
                                                                                                                                                                                                                                <p class="ariunetclub-field">
                                                                                                                                                                                                                                                                <label for="ariunetclub-rule-ends"><?php esc_html_e( 'پایان (ساعت سایت)', 'ariunetclub' ); ?></label>
                                                                                                                                                                                                                                                                <input id="ariunetclub-rule-ends" name="ariunetclub_rule[ends_at]" type="datetime-local" value="<?php echo esc_attr( $ends ); ?>" />
                                                                                                                                                                                                                                </p>
                                                                                                                                                                                                </div>
                                                                                                                                                                </section>

                                                                                                                                                                <div class="ariunetclub-rule-preview" data-ariunetclub-rule-preview aria-live="polite"></div>
                                                                                                                                                                <div class="ariunetclub-rule-actions">
                                                                                                                                                                                                <button class="button button-primary" type="submit"><?php esc_html_e( 'ذخیرهٔ قانون', 'ariunetclub' ); ?></button>
                                                                                                                                                                                                <a class="button ariunetclub-button-secondary" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'بازگشت', 'ariunetclub' ); ?></a>
                                                                                                                                                                </div>
                                                                                                                                </form>
                                                                                                </div>
                                                                </div>
                                                        <?php
        }

                                /**
                                 * One-line Persian summary of a rule for lists and previews.
                                 *
                                 * @param array<string,mixed> $rule Rule row.
                                 * @return string
                                 */
        public function summary( array $rule ): string {
                                        $conditions = RuleEngine::json( $rule['conditions'] ?? null );
                                        $reward     = RuleEngine::json( $rule['reward'] ?? null );
                                        $limits     = RuleEngine::json( $rule['limits'] ?? null );
                                        $parts      = array();

                                        $min = isset( $conditions['min_amount'] ) ? (float) $conditions['min_amount'] : 0.0;
                if ( $min > 0.0 ) {
                                                $parts[] = 'سفارش حداقل ' . Money::format( $min );
                }
                                        $cat_ids = isset( $conditions['categories']['ids'] ) && is_array( $conditions['categories']['ids'] ) ? array_map( 'intval', $conditions['categories']['ids'] ) : array();
                if ( ! empty( $cat_ids ) ) {
                                                $names   = $this->names_for( $this->category_options(), $cat_ids );
                                                $parts[] = 'دستهٔ ' . ( isset( $conditions['categories']['mode'] ) && 'exclude' === $conditions['categories']['mode'] ? 'غیر' : '' ) . implode( '، ', $names );
                }
                if ( ! empty( $conditions['first_order'] ) ) {
                                                $parts[] = 'اولین سفارش مشتری';
                }

                                        $type  = isset( $reward['type'] ) ? (string) $reward['type'] : 'percent';
                                        $value = isset( $reward['value'] ) ? (float) $reward['value'] : 0.0;
                if ( 'percent' === $type && $value > 0.0 ) {
                                                $text    = 'پاداش ' . Money::to_persian_digits( self::trim_number( $value ) ) . '٪ کش‌بک';
                                                $cap     = isset( $reward['max_cap'] ) ? (float) $reward['max_cap'] : 0.0;
                                                $parts[] = $cap > 0.0 ? $text . ' با سقف ' . Money::format( $cap ) : $text;
                } elseif ( 'fixed' === $type && $value > 0.0 ) {
                                                $parts[] = 'پاداش ثابت ' . Money::format( $value );
                }

                                        $per_count = isset( $limits['per_user_count'] ) ? (int) $limits['per_user_count'] : 0;
                                        $per_days  = isset( $limits['per_user_days'] ) ? (int) $limits['per_user_days'] : 0;
                if ( $per_count > 0 && $per_days > 0 ) {
                                                $parts[] = 'حداکثر ' . Money::to_persian_digits( (string) $per_count ) . ' بار در ' . Money::to_persian_digits( (string) $per_days ) . ' روز';
                }
                                        $total_times = isset( $limits['total_times'] ) ? (int) $limits['total_times'] : 0;
                if ( $total_times > 0 ) {
                                                $parts[] = 'سقف کل ' . Money::to_persian_digits( (string) $total_times ) . ' بار';
                }

                                        return ! empty( $parts ) ? implode( '؛ ', $parts ) : 'بدون شرط و پاداش';
        }

                                /**
                                 * @param array<int,string> $options id → label map.
                                 * @param int[]             $ids     Selected ids.
                                 * @return string[]
                                 */
        private function names_for( array $options, array $ids ): array {
                                        $names = array();
                foreach ( $ids as $id ) {
                                                $names[] = isset( $options[ $id ] ) ? $options[ $id ] : '#' . $id;
                }
                                        return $names;
        }

                                /**
                                 * @param float $value Raw amount.
                                 * @return string
                                 */
        private static function trim_number( float $value ): string {
                                        $formatted = number_format( $value, 2, '.', '' );
                                        return rtrim( rtrim( $formatted, '0' ), '.' );
        }

                                /**
                                 * @return array<string,string> Event type → Persian label.
                                 */
        private function event_options( string $default_status ): array {
                                        $options = array();
                foreach ( array_keys( EventLabels::order_statuses() ) as $status ) {
                                                $options[ 'order.status.' . $status ] = 'سفارش به وضعیت «' . EventLabels::order_status( $status ) . '» رسید';
                }
                                        $options['user.registered']         = 'مشتری در سایت ثبت‌نام کرد';
                                        $options['product.review.approved'] = 'دیدگاه محصول مشتری تأیید شد';
                if ( ! array_key_exists( 'order.status.' . $default_status, $options ) ) {
                                                $options[ 'order.status.' . $default_status ] = 'سفارش به وضعیت «' . $default_status . '» رسید';
                }
                                        return $options;
        }

                                /**
                                 * @return array<int,string>
                                 */
        private function category_options(): array {
                                        $terms   = get_terms(
                                                array(
                                                        'taxonomy'   => 'product_cat',
                                                        'hide_empty' => false,
                                                        'number'     => 200,
                                                        'orderby'    => 'name',
                                                        'order'      => 'ASC',
                                                )
                                        );
                                        $options = array();
                if ( is_array( $terms ) ) {
                        foreach ( $terms as $term ) {
                                if ( $term instanceof \WP_Term ) {
                                                                $options[ (int) $term->term_id ] = $term->name;
                                }
                        }
                }
                                        return $options;
        }

                                /**
                                 * @return array<int,string>
                                 */
        private function product_options(): array {
                                        $options = array();
                if ( ! function_exists( 'wc_get_products' ) ) {
                                                return $options;
                }
                                        $products = wc_get_products(
                                                array(
                                                        'limit'   => 200,
                                                        'orderby' => 'title',
                                                        'order'   => 'ASC',
                                                        'status'  => array( 'publish', 'draft', 'pending', 'private' ),
                                                )
                                        );
                if ( is_array( $products ) ) {
                        foreach ( $products as $product ) {
                                if ( $product instanceof \WC_Product ) {
                                                                $options[ (int) $product->get_id() ] = $product->get_formatted_name();
                                }
                        }
                }
                                        return $options;
        }

                                /**
                                 * @return array<string,string>
                                 */
        private function role_options(): array {
                                        global $wp_roles;
                if ( ! isset( $wp_roles ) || ! $wp_roles instanceof \WP_Roles ) {
                                                return array();
                }
                                        $names = $wp_roles->get_names();
                                        $out   = array();
                if ( is_array( $names ) ) {
                        foreach ( $names as $slug => $label ) {
                                                        $out[ (string) $slug ] = is_string( $label ) ? translate_user_role( $label ) : (string) $slug;
                        }
                }
                                        return $out;
        }

                                /**
                                 * @return array<string,string>
                                 */
        private function payment_options(): array {
                                        $options = array();
                if ( ! function_exists( 'WC' ) || null === WC() || null === WC()->payment_gateways() ) {
                                                return $options;
                }
                                        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
                if ( is_array( $gateways ) ) {
                        foreach ( $gateways as $id => $gateway ) {
                                                        $title                   = method_exists( $gateway, 'get_title' ) ? (string) $gateway->get_title() : (string) $id;
                                                        $options[ (string) $id ] = $title;
                        }
                }
                                        return $options;
        }

                                /**
                                 * @return array<string,mixed>
                                 */
        private function defaults_for_form(): array {
                                        $config  = $this->settings->get();
                                        $default = isset( $config['earning_order_status'] ) && is_string( $config['earning_order_status'] ) ? $config['earning_order_status'] : 'completed';
                                        return array(
                                                'title'      => '',
                                                'status'     => 'draft',
                                                'event_type' => 'order.status.' . $default,
                                                'conditions' => '',
                                                'reward'     => '',
                                                'limits'     => '',
                                                'priority'   => 10,
                                                'exclusive'  => 0,
                                                'starts_at'  => '',
                                                'ends_at'    => '',
                                        );
        }

                                /**
                                 * Converts a stored UTC datetime to the datetime-local value in site time.
                                 */
        private function local_datetime( string $utc ): string {
                                        $date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $utc, new \DateTimeZone( 'UTC' ) );
                if ( false === $date ) {
                                                return '';
                }
                                        return $date->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i' );
        }

                                /**
                                 * @param int    $page  Current page.
                                 * @param int    $total Total rows.
                                 * @param string $base  Base URL with page param.
                                 */
        private function pagination( int $page, int $total, string $base ): void {
                                        $per_page = 20;
                                        $pages    = (int) ceil( $total / $per_page );
                if ( $pages <= 1 ) {
                                                return;
                }
                                        $page = max( 1, min( $pages, $page ) );
                                        echo '<nav class="ariunetclub-pagination" aria-label="' . esc_attr__( 'صفحه‌بندی قوانین', 'ariunetclub' ) . '">';
                        for ( $i = 1; $i <= $pages; $i++ ) {
                                                $url   = add_query_arg( 'paged', $i, $base );
                                                $class = $i === $page ? ' ariunetclub-pagination-current' : '';
                                                echo '<a class="' . esc_attr( ltrim( $class ) ) . '" href="' . esc_url( $url ) . '"' . ( $i === $page ? ' aria-current="page"' : '' ) . '>' . esc_html( Money::to_persian_digits( (string) $i ) ) . '</a>';
                }
                                        echo '</nav>';
        }
}
