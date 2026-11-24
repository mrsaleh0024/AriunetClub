<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Core;

class Settings {
				const OPTION = 'ariunetclub_settings';

				/**
				 * @return array{enabled_modules:array<int,string>,phone_meta:string,delete_data:bool,earning_order_status:string,cashback_base_exclude_tax:bool,wallet_fee_title:string,wallet_max_percent:float,wallet_min_order:float,wallet_allowed_gateways:array<int,string>,wallet_hold_timeout:int,wallet_full_coverage:bool,expiry_enabled:bool,expiry_days:int,budget_percent:float,budget_fixed:float,guard_max_credit_per_order:float,guard_min_order_amount:float,guard_max_monthly_per_customer:float}
				 */
	public static function defaults(): array {
					return array(
						'enabled_modules'                => array( 'core', 'identity', 'ledger', 'events', 'credit', 'rules', 'wallet', 'account', 'license', 'dashboard' ),
						'phone_meta'                     => 'billing_phone',
						'delete_data'                    => false,
						'earning_order_status'           => 'completed',
						'cashback_base_exclude_tax'      => false,
						'wallet_fee_title'               => 'استفاده از اعتبار باشگاه',
						'wallet_max_percent'             => 100.0,
						'wallet_min_order'               => 0.0,
						'wallet_allowed_gateways'        => array(),
						'wallet_hold_timeout'            => 48,
						'wallet_full_coverage'           => true,
						'expiry_enabled'                 => false,
						'expiry_days'                    => 90,
						'budget_percent'                 => 0.0,
						'budget_fixed'                   => 0.0,
						'guard_max_credit_per_order'     => 0.0,
						'guard_min_order_amount'         => 0.0,
						'guard_max_monthly_per_customer' => 0.0,
					);
	}

	public static function ensure_defaults(): void {
		if ( false === get_option( self::OPTION, false ) ) {
						add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	public function register(): void {
					add_action( 'admin_init', array( $this, 'register_settings' ) );
					add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

				/** @return array<int,string> */
	public function enabled_modules(): array {
					$settings = $this->get();
					$modules  = isset( $settings['enabled_modules'] ) && is_array( $settings['enabled_modules'] ) ? array_filter( $settings['enabled_modules'], 'is_string' ) : array();

					return array_values( array_unique( array_merge( self::defaults()['enabled_modules'], $modules ) ) );
	}

				/**
				 * @return array<string,mixed>
				 */
	public function get(): array {
					return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public function register_settings(): void {
					register_setting(
						'ariunetclub_settings_group',
						self::OPTION,
						array(
							'type'              => 'array',
							'sanitize_callback' => array( $this, 'sanitize' ),
							'default'           => self::defaults(),
						)
					);
	}

				/**
				 * @param mixed $input
				 * @return array{enabled_modules:array<int,string>,phone_meta:string,delete_data:bool,earning_order_status:string,cashback_base_exclude_tax:bool,wallet_fee_title:string,wallet_max_percent:float,wallet_min_order:float,wallet_allowed_gateways:array<int,string>,wallet_hold_timeout:int,wallet_full_coverage:bool,expiry_enabled:bool,expiry_days:int,budget_percent:float,budget_fixed:float,guard_max_credit_per_order:float,guard_min_order_amount:float,guard_max_monthly_per_customer:float}
				 */
	public function sanitize( $input ): array {
					$input           = is_array( $input ) ? $input : array();
					$statuses        = array( 'processing', 'completed', 'cancelled', 'refunded', 'failed' );
					$status          = isset( $input['earning_order_status'] ) ? sanitize_key( (string) $input['earning_order_status'] ) : 'completed';
					$raw_fee_title   = isset( $input['wallet_fee_title'] ) ? $input['wallet_fee_title'] : '';
					$fee_title       = is_string( $raw_fee_title ) ? sanitize_text_field( $raw_fee_title ) : '';
					$raw_percent     = isset( $input['wallet_max_percent'] ) ? $input['wallet_max_percent'] : null;
					$percent         = is_numeric( $raw_percent ) ? (float) $raw_percent : 100.0;
					$raw_min         = isset( $input['wallet_min_order'] ) ? $input['wallet_min_order'] : null;
					$min_order       = is_numeric( $raw_min ) ? (float) $raw_min : 0.0;
					$timeout         = isset( $input['wallet_hold_timeout'] ) ? absint( $input['wallet_hold_timeout'] ) : 48;
					$gateways        = isset( $input['wallet_allowed_gateways'] ) && is_array( $input['wallet_allowed_gateways'] ) ? $input['wallet_allowed_gateways'] : array();
					$gateways        = array_values(
						array_unique(
							array_filter(
								array_map(
									static function ( $id ) {
																									return is_string( $id ) ? sanitize_key( $id ) : '';
									},
									array_slice( $gateways, 0, 30 )
								)
							)
						)
					);
					$expiry_days     = isset( $input['expiry_days'] ) ? absint( $input['expiry_days'] ) : 90;
					$raw_budget_pct  = isset( $input['budget_percent'] ) ? $input['budget_percent'] : null;
					$budget_pct      = is_numeric( $raw_budget_pct ) ? (float) $raw_budget_pct : 0.0;
					$raw_budget_fix  = isset( $input['budget_fixed'] ) ? $input['budget_fixed'] : null;
					$budget_fix      = is_numeric( $raw_budget_fix ) ? (float) $raw_budget_fix : 0.0;
					$raw_guard_order = isset( $input['guard_max_credit_per_order'] ) ? $input['guard_max_credit_per_order'] : null;
					$guard_order     = is_numeric( $raw_guard_order ) ? (float) $raw_guard_order : 0.0;
					$raw_guard_min   = isset( $input['guard_min_order_amount'] ) ? $input['guard_min_order_amount'] : null;
					$guard_min       = is_numeric( $raw_guard_min ) ? (float) $raw_guard_min : 0.0;
					$raw_guard_month = isset( $input['guard_max_monthly_per_customer'] ) ? $input['guard_max_monthly_per_customer'] : null;
					$guard_month     = is_numeric( $raw_guard_month ) ? (float) $raw_guard_month : 0.0;
					return array(
						'enabled_modules'                => array( 'core', 'identity', 'ledger', 'events', 'credit', 'rules', 'wallet', 'account', 'license', 'dashboard' ),
						'phone_meta'                     => isset( $input['phone_meta'] ) ? sanitize_key( (string) $input['phone_meta'] ) : 'billing_phone',
						'delete_data'                    => ! empty( $input['delete_data'] ),
						'earning_order_status'           => in_array( $status, $statuses, true ) ? $status : 'completed',
						'cashback_base_exclude_tax'      => ! empty( $input['cashback_base_exclude_tax'] ),
						'wallet_fee_title'               => '' !== $fee_title ? $fee_title : 'استفاده از اعتبار باشگاه',
						'wallet_max_percent'             => min( 100.0, max( 0.0, $percent ) ),
						'wallet_min_order'               => max( 0.0, $min_order ),
						'wallet_allowed_gateways'        => $gateways,
						'wallet_hold_timeout'            => min( 720, max( 1, $timeout ) ),
						'wallet_full_coverage'           => ! empty( $input['wallet_full_coverage'] ),
						'expiry_enabled'                 => ! empty( $input['expiry_enabled'] ),
						'expiry_days'                    => min( 3650, max( 1, $expiry_days ) ),
						'budget_percent'                 => min( 100.0, max( 0.0, $budget_pct ) ),
						'budget_fixed'                   => max( 0.0, $budget_fix ),
						'guard_max_credit_per_order'     => max( 0.0, $guard_order ),
						'guard_min_order_amount'         => max( 0.0, $guard_min ),
						'guard_max_monthly_per_customer' => max( 0.0, $guard_month ),
					);
	}

				/**
				 * Payment gateways available for the wallet companion rule.
				 *
				 * @return array<string,string> Gateway id => title.
				 */
	private function wallet_gateway_options(): array {
					$options = array();
		if ( ! function_exists( 'WC' ) || null === WC() || null === WC()->payment_gateways() ) {
						return $options;
		}
					$gateways = WC()->payment_gateways()->payment_gateways();
		if ( is_array( $gateways ) ) {
			foreach ( $gateways as $id => $gateway ) {
				if ( 'ariunetclub_wallet' === (string) $id ) {
					continue;
				}
							$title                   = method_exists( $gateway, 'get_title' ) ? (string) $gateway->get_title() : (string) $id;
							$options[ (string) $id ] = '' !== $title ? $title : (string) $id;
			}
		}
					return $options;
	}

	public function register_menu(): void {
					add_menu_page(
						__( 'باشگاه مشتریان', 'ariunetclub' ),
						__( 'باشگاه مشتریان', 'ariunetclub' ),
						'manage_woocommerce',
						'ariunetclub-settings',
						array( $this, 'render_page' ),
						'dashicons-groups',
						56
					);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
						wp_die( esc_html__( 'شما اجازهٔ دسترسی به این صفحه را ندارید.', 'ariunetclub' ) );
		}
					$settings           = $this->get();
					$phone_meta         = isset( $settings['phone_meta'] ) && is_string( $settings['phone_meta'] ) ? $settings['phone_meta'] : 'billing_phone';
					$earning            = isset( $settings['earning_order_status'] ) && is_string( $settings['earning_order_status'] ) ? $settings['earning_order_status'] : 'completed';
					$exclude_tax        = ! empty( $settings['cashback_base_exclude_tax'] );
					$wallet_title       = isset( $settings['wallet_fee_title'] ) && is_string( $settings['wallet_fee_title'] ) && '' !== $settings['wallet_fee_title'] ? $settings['wallet_fee_title'] : 'استفاده از اعتبار باشگاه';
					$raw_wallet_percent = isset( $settings['wallet_max_percent'] ) ? $settings['wallet_max_percent'] : null;
					$wallet_percent     = is_numeric( $raw_wallet_percent ) ? (float) $raw_wallet_percent : 100.0;
					$raw_wallet_min     = isset( $settings['wallet_min_order'] ) ? $settings['wallet_min_order'] : null;
					$wallet_min         = is_numeric( $raw_wallet_min ) ? (float) $raw_wallet_min : 0.0;
					$wallet_allowed     = isset( $settings['wallet_allowed_gateways'] ) && is_array( $settings['wallet_allowed_gateways'] ) ? array_values( array_filter( $settings['wallet_allowed_gateways'], 'is_string' ) ) : array();
					$raw_wallet_timeout = isset( $settings['wallet_hold_timeout'] ) ? $settings['wallet_hold_timeout'] : null;
					$wallet_timeout     = is_numeric( $raw_wallet_timeout ) ? (int) $raw_wallet_timeout : 48;
					$wallet_full        = ! empty( $settings['wallet_full_coverage'] );
					$expiry_on          = ! empty( $settings['expiry_enabled'] );
					$raw_expiry_days    = isset( $settings['expiry_days'] ) ? $settings['expiry_days'] : null;
					$expiry_days        = is_numeric( $raw_expiry_days ) ? (int) $raw_expiry_days : 90;
					$raw_budget_percent = isset( $settings['budget_percent'] ) ? $settings['budget_percent'] : null;
					$budget_percent     = is_numeric( $raw_budget_percent ) ? (float) $raw_budget_percent : 0.0;
					$raw_budget_fixed   = isset( $settings['budget_fixed'] ) ? $settings['budget_fixed'] : null;
					$budget_fixed       = is_numeric( $raw_budget_fixed ) ? (float) $raw_budget_fixed : 0.0;
					$raw_guard_order    = isset( $settings['guard_max_credit_per_order'] ) ? $settings['guard_max_credit_per_order'] : null;
					$guard_order        = is_numeric( $raw_guard_order ) ? (float) $raw_guard_order : 0.0;
					$raw_guard_min      = isset( $settings['guard_min_order_amount'] ) ? $settings['guard_min_order_amount'] : null;
					$guard_min          = is_numeric( $raw_guard_min ) ? (float) $raw_guard_min : 0.0;
					$raw_guard_month    = isset( $settings['guard_max_monthly_per_customer'] ) ? $settings['guard_max_monthly_per_customer'] : null;
					$guard_month        = is_numeric( $raw_guard_month ) ? (float) $raw_guard_month : 0.0;
					$wallet_gateways    = $this->wallet_gateway_options();
					$phone_metas        = apply_filters( 'ariunetclub_phone_meta_keys', array( 'billing_phone' ) );
					$phone_metas        = is_array( $phone_metas ) ? array_values( array_unique( array_filter( $phone_metas, 'is_string' ) ) ) : array( 'billing_phone' );
		if ( ! in_array( $phone_meta, $phone_metas, true ) ) {
						$phone_metas[] = $phone_meta;
		}
		?>
								<div class="wrap ariunetclub-scope" dir="rtl">
												<div class="ariunetclub-admin-shell">
																<header class="ariunetclub-admin-header"><span class="ariunetclub-admin-kicker"><?php esc_html_e( 'ARIUNETCLUB', 'ariunetclub' ); ?></span><h1><?php esc_html_e( 'تنظیمات باشگاه مشتریان', 'ariunetclub' ); ?></h1><p><?php esc_html_e( 'موتور رویداد، قوانین کسب و خرج اعتبار باشگاه در چک‌اوت فعال است.', 'ariunetclub' ); ?></p></header>
																<form method="post" action="options.php" class="ariunetclub-panel">
																	<?php settings_fields( 'ariunetclub_settings_group' ); ?>
																				<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'بخش‌های تنظیمات', 'ariunetclub' ); ?>"><a class="nav-tab nav-tab-active" href="#general"><?php esc_html_e( 'عمومی', 'ariunetclub' ); ?></a><a class="nav-tab" href="#earning"><?php esc_html_e( 'کسب کش‌بک', 'ariunetclub' ); ?></a><a class="nav-tab" href="#wallet"><?php esc_html_e( 'خرج اعتبار باشگاه', 'ariunetclub' ); ?></a><a class="nav-tab" href="#expiry"><?php esc_html_e( 'انقضا و ردیف‌های اعتبار', 'ariunetclub' ); ?></a><a class="nav-tab" href="#budget"><?php esc_html_e( 'بودجه و سقف‌ها', 'ariunetclub' ); ?></a><a class="nav-tab" href="#data"><?php esc_html_e( 'داده‌ها', 'ariunetclub' ); ?></a></nav>
																				<section id="general" class="ariunetclub-settings-section"><h2><?php esc_html_e( 'تنظیمات عمومی', 'ariunetclub' ); ?></h2><table class="form-table" role="presentation"><tr><th scope="row"><label for="ariunetclub-phone-meta"><?php esc_html_e( 'کلید متای موبایل', 'ariunetclub' ); ?></label></th><td><select id="ariunetclub-phone-meta" name="ariunetclub_settings[phone_meta]" class="regular-text">
																	<?php
																	foreach ( $phone_metas as $meta_key ) :
																		?>
																								<option value="<?php echo esc_attr( $meta_key ); ?>" <?php selected( $phone_meta, $meta_key ); ?>><?php echo esc_html( $meta_key ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'کلیدهای حاوی phone یا mobile در متای کاربران برای انتخاب نمایش داده می‌شوند. در صورت خالی بودن، billing_phone استفاده می‌شود.', 'ariunetclub' ); ?></p></td></tr></table></section>
																				<section id="earning" class="ariunetclub-settings-section"><h2><?php esc_html_e( 'کسب کش‌بک', 'ariunetclub' ); ?></h2><table class="form-table" role="presentation">
																								<tr><th scope="row"><label for="ariunetclub-earning-status"><?php esc_html_e( 'وضعیت پیش‌فرض کسب', 'ariunetclub' ); ?></label></th><td><select id="ariunetclub-earning-status" name="ariunetclub_settings[earning_order_status]">
																												<option value="processing" <?php selected( $earning, 'processing' ); ?>><?php esc_html_e( 'در حال پردازش', 'ariunetclub' ); ?></option>
																												<option value="completed" <?php selected( $earning, 'completed' ); ?>><?php esc_html_e( 'تکمیل‌شده', 'ariunetclub' ); ?></option>
																												<option value="cancelled" <?php selected( $earning, 'cancelled' ); ?>><?php esc_html_e( 'لغوشده', 'ariunetclub' ); ?></option>
																												<option value="refunded" <?php selected( $earning, 'refunded' ); ?>><?php esc_html_e( 'مستردشده', 'ariunetclub' ); ?></option>
																												<option value="failed" <?php selected( $earning, 'failed' ); ?>><?php esc_html_e( 'ناموفق', 'ariunetclub' ); ?></option>
																								</select><p class="description"><?php esc_html_e( 'فقط پیش‌فرض فرم «قانون جدید» را تعیین می‌کند؛ هر قانون می‌تواند وضعیت خودش را داشته باشد.', 'ariunetclub' ); ?></p></td></tr>
																								<tr><th scope="row"><label for="ariunetclub-base-tax"><?php esc_html_e( 'مبنای مبلغ کش‌بک', 'ariunetclub' ); ?></label></th><td><span class="ariunetclub-check"><input id="ariunetclub-base-tax" name="ariunetclub_settings[cashback_base_exclude_tax]" type="checkbox" value="1" <?php checked( $exclude_tax ); ?> /> <?php esc_html_e( 'مالیات را هم از مبنای کش‌بک کم کن', 'ariunetclub' ); ?></span><p class="description"><?php esc_html_e( 'مبنای پیش‌فرض «مجموع سفارش منهای هزینهٔ حمل» است؛ با این گزینه مالیات نیز کسر می‌شود.', 'ariunetclub' ); ?></p></td></tr>
																				</table></section>
																				<section id="wallet" class="ariunetclub-settings-section"><h2><?php esc_html_e( 'خرج اعتبار باشگاه', 'ariunetclub' ); ?></h2><table class="form-table" role="presentation">
																								<tr><th scope="row"><label for="ariunetclub-wallet-title"><?php esc_html_e( 'عنوان ردیف هزینه', 'ariunetclub' ); ?></label></th><td><input id="ariunetclub-wallet-title" name="ariunetclub_settings[wallet_fee_title]" type="text" value="<?php echo esc_attr( $wallet_title ); ?>" /><p class="description"><?php esc_html_e( 'این عنوان در سبد خرید و سفارش برای ردیف تخفیف اعتبار باشگاه نمایش داده می‌شود.', 'ariunetclub' ); ?></p></td></tr>
																								<tr><th scope="row"><label for="ariunetclub-wallet-percent"><?php esc_html_e( 'سقف درصدی از سبد', 'ariunetclub' ); ?></label></th><td><input id="ariunetclub-wallet-percent" name="ariunetclub_settings[wallet_max_percent]" type="number" min="0" max="100" step="1" value="<?php echo esc_attr( (string) $wallet_percent ); ?>" /><p class="description"><?php esc_html_e( 'حداکثر درصد از مبلغ اقلام سبد (با مالیات، بدون هزینهٔ حمل) که در هر سفارش قابل خرج کردن است.', 'ariunetclub' ); ?></p></td></tr>
																								<tr><th scope="row"><label for="ariunetclub-wallet-min"><?php esc_html_e( 'حداقل مبلغ سفارش', 'ariunetclub' ); ?></label></th><td><input id="ariunetclub-wallet-min" name="ariunetclub_settings[wallet_min_order]" type="number" min="0" step="any" value="<?php echo esc_attr( (string) $wallet_min ); ?>" /><p class="description"><?php esc_html_e( 'برای سبد کم‌تر از این مبلغ، امکان استفاده از اعتبار باشگاه نمایش داده نمی‌شود.', 'ariunetclub' ); ?></p></td></tr>
																								<tr><th scope="row"><?php esc_html_e( 'درگاه‌های مجاز', 'ariunetclub' ); ?></th><td><fieldset id="ariunetclub-wallet-gateways" class="ariunetclub-check">
																									<?php
																									$gateway_index = 0;
																									foreach ( $wallet_gateways as $gateway_id => $gateway_title ) :
																													++$gateway_index;
																													$checked = in_array( (string) $gateway_id, $wallet_allowed, true );
																										?>
																																<label for="<?php echo esc_attr( 'ariunetclub-wallet-gateway-' . $gateway_index ); ?>"><input id="<?php echo esc_attr( 'ariunetclub-wallet-gateway-' . $gateway_index ); ?>" name="ariunetclub_settings[wallet_allowed_gateways][]" type="checkbox" value="<?php echo esc_attr( (string) $gateway_id ); ?>" <?php checked( $checked ); ?> /> <?php echo esc_html( $gateway_title ); ?></label>
																												<?php endforeach; ?>
																												<?php if ( empty( $wallet_gateways ) ) : ?>
																																<p class="description"><?php esc_html_e( 'درگاهی برای انتخاب نیست.', 'ariunetclub' ); ?></p>
																												<?php endif; ?>
																												<p class="description"><?php esc_html_e( 'اگر هیچ درگاهی انتخاب نشود، همهٔ درگاه‌ها به‌جز «پرداخت در محل» مجاز هستند. سفارش‌هایی که اعتبار باشگاه کل مبلغشان را می‌پوشاند نیازی به درگاه ندارند.', 'ariunetclub' ); ?></p>
																								</fieldset></td></tr>
																								<tr><th scope="row"><label for="ariunetclub-wallet-timeout"><?php esc_html_e( 'مهلت رزرو (ساعت)', 'ariunetclub' ); ?></label></th><td><input id="ariunetclub-wallet-timeout" name="ariunetclub_settings[wallet_hold_timeout]" type="number" min="1" max="720" step="1" value="<?php echo esc_attr( (string) $wallet_timeout ); ?>" /><p class="description"><?php esc_html_e( 'اگر سفارش در این مدت پرداخت نشود و در وضعیت «در انتظار پرداخت» بماند، رزرو اعتبار باشگاه خودکار آزاد می‌شود.', 'ariunetclub' ); ?></p></td></tr>
																								<tr><th scope="row"><label for="ariunetclub-wallet-full"><?php esc_html_e( 'پوشش کامل مبلغ', 'ariunetclub' ); ?></label></th><td><span class="ariunetclub-check"><input id="ariunetclub-wallet-full" name="ariunetclub_settings[wallet_full_coverage]" type="checkbox" value="1" <?php checked( $wallet_full ); ?> /> <?php esc_html_e( 'پرداخت کامل سفارش با اعتبار باشگاه مجاز باشد', 'ariunetclub' ); ?></span><p class="description"><?php esc_html_e( 'با فعال بودن این گزینه، سفارش بدون نیاز به درگاه بانکی و با روش «اعتبار باشگاه» تکمیل می‌شود.', 'ariunetclub' ); ?></p></td></tr>
																				</table></section>
																				<section id="expiry" class="ariunetclub-settings-section"><h2><?php esc_html_e( 'انقضا و ردیف‌های اعتبار', 'ariunetclub' ); ?></h2><table class="form-table" role="presentation">
																								<tr><th scope="row"><label for="ariunetclub-expiry-enabled"><?php esc_html_e( 'انقضای اعتبار', 'ariunetclub' ); ?></label></th><td><span class="ariunetclub-check"><input id="ariunetclub-expiry-enabled" name="ariunetclub_settings[expiry_enabled]" type="checkbox" value="1" <?php checked( $expiry_on ); ?> /> <?php esc_html_e( 'ردیف‌های کش‌بک جدید تاریخ انقضا داشته باشند', 'ariunetclub' ); ?></span><p class="description"><?php esc_html_e( 'پیش‌فرض خاموش است. با روشن بودن، هر ردیف کش‌بکِ جدید پس از تعداد روز تنظیم‌شده منقضی می‌شود؛ ردیف‌های قبلی فقط با تأیید صریح و با اعلان دست‌کم ۳۰ روزه از صفحهٔ «ردیف‌های اعتبار» مشمول انقضا می‌شوند.', 'ariunetclub' ); ?></p></td></tr>
																								<tr><th scope="row"><label for="ariunetclub-expiry-days"><?php esc_html_e( 'مدت اعتبار (روز)', 'ariunetclub' ); ?></label></th><td><input id="ariunetclub-expiry-days" name="ariunetclub_settings[expiry_days]" type="number" min="1" max="3650" step="1" value="<?php echo esc_attr( (string) $expiry_days ); ?>" /><p class="description"><?php esc_html_e( 'پیشنهاد: ۹۰ روز. اعتبار رزروشدهٔ سفارش‌های در جریان تا آزاد شدن رزرو منقضی نمی‌شود.', 'ariunetclub' ); ?></p></td></tr>
																				</table></section>
																				<section id="budget" class="ariunetclub-settings-section"><h2><?php esc_html_e( 'بودجه و سقف‌های محافظ', 'ariunetclub' ); ?></h2><table class="form-table" role="presentation">
																								<tr><th scope="row"><label for="ariunetclub-budget-percent"><?php esc_html_e( 'سقف بودجه: درصدی از فروش ماه', 'ariunetclub' ); ?></label></th><td><input id="ariunetclub-budget-percent" name="ariunetclub_settings[budget_percent]" type="number" min="0" max="100" step="any" value="<?php echo esc_attr( (string) $budget_percent ); ?>" /><p class="description"><?php esc_html_e( '۰ یعنی خاموش. درصدی از فروش تکمیل‌شدهٔ ماه جاری (به وقت سایت) به‌عنوان سقف هزینهٔ باشگاه اعمال می‌شود.', 'ariunetclub' ); ?></p></td></tr>
																								<tr><th scope="row"><label for="ariunetclub-budget-fixed"><?php esc_html_e( 'سقف بودجه: مبلغ ثابت ماهانه', 'ariunetclub' ); ?></label></th><td><input id="ariunetclub-budget-fixed" name="ariunetclub_settings[budget_fixed]" type="number" min="0" step="any" value="<?php echo esc_attr( (string) $budget_fixed ); ?>" /><p class="description"><?php esc_html_e( '۰ یعنی خاموش. اگر هر دو سقف تنظیم شوند، کوچک‌ترِ آن‌ها اعمال می‌شود. تنظیم دستی ادمین مشمول سقف نیست.', 'ariunetclub' ); ?></p></td></tr>
																								<tr><th scope="row"><label for="ariunetclub-guard-order"><?php esc_html_e( 'سقف اعتبار هر سفارش', 'ariunetclub' ); ?></label></th><td><input id="ariunetclub-guard-order" name="ariunetclub_settings[guard_max_credit_per_order]" type="number" min="0" step="any" value="<?php echo esc_attr( (string) $guard_order ); ?>" /><p class="description"><?php esc_html_e( '۰ یعنی نامحدود. مجموع اعتباری که قوانین برای یک سفارش صادر می‌کنند.', 'ariunetclub' ); ?></p></td></tr>
																								<tr><th scope="row"><label for="ariunetclub-guard-min"><?php esc_html_e( 'حداقل مبلغ سبد برای کسب', 'ariunetclub' ); ?></label></th><td><input id="ariunetclub-guard-min" name="ariunetclub_settings[guard_min_order_amount]" type="number" min="0" step="any" value="<?php echo esc_attr( (string) $guard_min ); ?>" /><p class="description"><?php esc_html_e( '۰ یعنی بی‌قید. سبد‌های کوچک‌تر از این مبلغ هرگز کش‌بک نمی‌گیرند (در کنار شرط حداقل مبلغ هر قانون).', 'ariunetclub' ); ?></p></td></tr>
																								<tr><th scope="row"><label for="ariunetclub-guard-month"><?php esc_html_e( 'سقف ماهانهٔ هر مشتری', 'ariunetclub' ); ?></label></th><td><input id="ariunetclub-guard-month" name="ariunetclub_settings[guard_max_monthly_per_customer]" type="number" min="0" step="any" value="<?php echo esc_attr( (string) $guard_month ); ?>" /><p class="description"><?php esc_html_e( '۰ یعنی نامحدود. مجموع اعتباری که یک مشتری در هر ماه از قوانین دریافت می‌کند.', 'ariunetclub' ); ?></p></td></tr>
																				</table></section>
																				<section id="data" class="ariunetclub-settings-section"><h2><?php esc_html_e( 'حذف داده‌ها', 'ariunetclub' ); ?></h2><p><?php esc_html_e( 'با فعال‌سازی این گزینه، حذف افزونه همهٔ داده‌های AriunetClub را پاک می‌کند. این عمل قابل بازگشت نیست.', 'ariunetclub' ); ?></p><label><input name="ariunetclub_settings[delete_data]" type="checkbox" value="1" <?php checked( $settings['delete_data'] ); ?> /> <?php esc_html_e( 'پاک‌سازی کامل داده‌ها هنگام حذف افزونه', 'ariunetclub' ); ?></label></section>
																				<?php submit_button( __( 'ذخیرهٔ تغییرات', 'ariunetclub' ) ); ?>
																</form>
												</div>
								</div>
								<?php
	}
}
