<?php
/**
 * @package LClub
 */

namespace LClub\Core;

class Settings {
	const OPTION = 'lclub_settings';

	/**
	 * @return array{enabled_modules:array<int,string>,phone_meta:string,delete_data:bool,earning_order_status:string,cashback_base_exclude_tax:bool,wallet_fee_title:string,wallet_max_percent:float,wallet_min_order:float,wallet_allowed_gateways:array<int,string>,wallet_hold_timeout:int,wallet_full_coverage:bool}
	 */
	public static function defaults(): array {
		return array(
			'enabled_modules'           => array( 'core', 'identity', 'ledger', 'events', 'rules', 'wallet' ),
			'phone_meta'                => 'billing_phone',
			'delete_data'               => false,
			'earning_order_status'      => 'completed',
			'cashback_base_exclude_tax' => false,
			'wallet_fee_title'          => 'استفاده از کیف پول',
			'wallet_max_percent'        => 100.0,
			'wallet_min_order'           => 0.0,
			'wallet_allowed_gateways'   => array(),
			'wallet_hold_timeout'       => 48,
			'wallet_full_coverage'       => true,
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
			'lclub_settings_group',
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
	 * @return array{enabled_modules:array<int,string>,phone_meta:string,delete_data:bool,earning_order_status:string,cashback_base_exclude_tax:bool,wallet_fee_title:string,wallet_max_percent:float,wallet_min_order:float,wallet_allowed_gateways:array<int,string>,wallet_hold_timeout:int,wallet_full_coverage:bool}
	 */
	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$statuses = array( 'processing', 'completed', 'cancelled', 'refunded', 'failed' );
		$status   = isset( $input['earning_order_status'] ) ? sanitize_key( (string) $input['earning_order_status'] ) : 'completed';
		$fee_title = isset( $input['wallet_fee_title'] ) ? sanitize_text_field( (string) $input['wallet_fee_title'] ) : '';
		$percent  = isset( $input['wallet_max_percent'] ) ? (float) $input['wallet_max_percent'] : 100.0;
		$min_order = isset( $input['wallet_min_order'] ) ? (float) $input['wallet_min_order'] : 0.0;
		$timeout  = isset( $input['wallet_hold_timeout'] ) ? absint( $input['wallet_hold_timeout'] ) : 48;
		$gateways = isset( $input['wallet_allowed_gateways'] ) && is_array( $input['wallet_allowed_gateways'] ) ? $input['wallet_allowed_gateways'] : array();
		$gateways = array_values( array_unique( array_filter( array_map( static function ( $id ) {
			return sanitize_key( (string) $id );
		}, array_slice( $gateways, 0, 30 ) ) ) ) );
		return array(
			'enabled_modules'           => array( 'core', 'identity', 'ledger', 'events', 'rules', 'wallet' ),
			'phone_meta'                => isset( $input['phone_meta'] ) ? sanitize_key( (string) $input['phone_meta'] ) : 'billing_phone',
			'delete_data'               => ! empty( $input['delete_data'] ),
			'earning_order_status'      => in_array( $status, $statuses, true ) ? $status : 'completed',
			'cashback_base_exclude_tax' => ! empty( $input['cashback_base_exclude_tax'] ),
			'wallet_fee_title'          => '' !== $fee_title ? $fee_title : 'استفاده از کیف پول',
			'wallet_max_percent'        => min( 100.0, max( 0.0, $percent ) ),
			'wallet_min_order'           => max( 0.0, $min_order ),
			'wallet_allowed_gateways'   => $gateways,
			'wallet_hold_timeout'       => min( 720, max( 1, $timeout ) ),
			'wallet_full_coverage'      => ! empty( $input['wallet_full_coverage'] ),
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
				if ( 'lclub_wallet' === (string) $id ) {
					continue;
				}
				$title                   = $gateway instanceof \WC_Payment_Gateway && method_exists( $gateway, 'get_title' ) ? (string) $gateway->get_title() : (string) $id;
				$options[ (string) $id ] = '' !== $title ? $title : (string) $id;
			}
		}
		return $options;
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'باشگاه مشتریان', 'lclub' ),
			__( 'باشگاه مشتریان', 'lclub' ),
			'manage_woocommerce',
			'lclub-settings',
			array( $this, 'render_page' ),
			'dashicons-groups',
			56
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'شما اجازهٔ دسترسی به این صفحه را ندارید.', 'lclub' ) );
		}
		$settings    = $this->get();
		$phone_meta  = isset( $settings['phone_meta'] ) && is_string( $settings['phone_meta'] ) ? $settings['phone_meta'] : 'billing_phone';
		$earning     = isset( $settings['earning_order_status'] ) && is_string( $settings['earning_order_status'] ) ? $settings['earning_order_status'] : 'completed';
		$exclude_tax = ! empty( $settings['cashback_base_exclude_tax'] );
		$wallet_title   = isset( $settings['wallet_fee_title'] ) && is_string( $settings['wallet_fee_title'] ) && '' !== $settings['wallet_fee_title'] ? $settings['wallet_fee_title'] : 'استفاده از کیف پول';
		$wallet_percent = isset( $settings['wallet_max_percent'] ) ? (float) $settings['wallet_max_percent'] : 100.0;
		$wallet_min     = isset( $settings['wallet_min_order'] ) ? (float) $settings['wallet_min_order'] : 0.0;
		$wallet_allowed = isset( $settings['wallet_allowed_gateways'] ) && is_array( $settings['wallet_allowed_gateways'] ) ? array_map( 'strval', $settings['wallet_allowed_gateways'] ) : array();
		$wallet_timeout = isset( $settings['wallet_hold_timeout'] ) ? (int) $settings['wallet_hold_timeout'] : 48;
		$wallet_full    = ! empty( $settings['wallet_full_coverage'] );
		$wallet_gateways = $this->wallet_gateway_options();
		$phone_metas = apply_filters( 'lclub_phone_meta_keys', array( 'billing_phone' ) );
		$phone_metas = is_array( $phone_metas ) ? array_values( array_unique( array_filter( $phone_metas, 'is_string' ) ) ) : array( 'billing_phone' );
		if ( ! in_array( $phone_meta, $phone_metas, true ) ) {
			$phone_metas[] = $phone_meta;
		}
		?>
		<div class="wrap lclub-scope" dir="rtl">
			<div class="lclub-admin-shell">
				<header class="lclub-admin-header"><span class="lclub-admin-kicker"><?php esc_html_e( 'ARIUNETCLUB', 'lclub' ); ?></span><h1><?php esc_html_e( 'تنظیمات باشگاه مشتریان', 'lclub' ); ?></h1><p><?php esc_html_e( 'موتور رویداد، قوانین کسب و خرج کیف پول در چک‌اوت فعال است.', 'lclub' ); ?></p></header>
				<form method="post" action="options.php" class="lclub-panel">
					<?php settings_fields( 'lclub_settings_group' ); ?>
					<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'بخش‌های تنظیمات', 'lclub' ); ?>"><a class="nav-tab nav-tab-active" href="#general"><?php esc_html_e( 'عمومی', 'lclub' ); ?></a><a class="nav-tab" href="#earning"><?php esc_html_e( 'کسب کش‌بک', 'lclub' ); ?></a><a class="nav-tab" href="#wallet"><?php esc_html_e( 'خرج کیف پول', 'lclub' ); ?></a><a class="nav-tab" href="#data"><?php esc_html_e( 'داده‌ها', 'lclub' ); ?></a></nav>
					<section id="general" class="lclub-settings-section"><h2><?php esc_html_e( 'تنظیمات عمومی', 'lclub' ); ?></h2><table class="form-table" role="presentation"><tr><th scope="row"><label for="lclub-phone-meta"><?php esc_html_e( 'کلید متای موبایل', 'lclub' ); ?></label></th><td><select id="lclub-phone-meta" name="lclub_settings[phone_meta]" class="regular-text">
					<?php
					foreach ( $phone_metas as $meta_key ) :
						?>
						<option value="<?php echo esc_attr( $meta_key ); ?>" <?php selected( $phone_meta, $meta_key ); ?>><?php echo esc_html( $meta_key ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'کلیدهای حاوی phone یا mobile در متای کاربران برای انتخاب نمایش داده می‌شوند. در صورت خالی بودن، billing_phone استفاده می‌شود.', 'lclub' ); ?></p></td></tr></table></section>
					<section id="earning" class="lclub-settings-section"><h2><?php esc_html_e( 'کسب کش‌بک', 'lclub' ); ?></h2><table class="form-table" role="presentation">
						<tr><th scope="row"><label for="lclub-earning-status"><?php esc_html_e( 'وضعیت پیش‌فرض کسب', 'lclub' ); ?></label></th><td><select id="lclub-earning-status" name="lclub_settings[earning_order_status]">
							<option value="processing" <?php selected( $earning, 'processing' ); ?>><?php esc_html_e( 'در حال پردازش', 'lclub' ); ?></option>
							<option value="completed" <?php selected( $earning, 'completed' ); ?>><?php esc_html_e( 'تکمیل‌شده', 'lclub' ); ?></option>
							<option value="cancelled" <?php selected( $earning, 'cancelled' ); ?>><?php esc_html_e( 'لغوشده', 'lclub' ); ?></option>
							<option value="refunded" <?php selected( $earning, 'refunded' ); ?>><?php esc_html_e( 'مستردشده', 'lclub' ); ?></option>
							<option value="failed" <?php selected( $earning, 'failed' ); ?>><?php esc_html_e( 'ناموفق', 'lclub' ); ?></option>
						</select><p class="description"><?php esc_html_e( 'فقط پیش‌فرض فرم «قانون جدید» را تعیین می‌کند؛ هر قانون می‌تواند وضعیت خودش را داشته باشد.', 'lclub' ); ?></p></td></tr>
						<tr><th scope="row"><label for="lclub-base-tax"><?php esc_html_e( 'مبنای مبلغ کش‌بک', 'lclub' ); ?></label></th><td><span class="lclub-check"><input id="lclub-base-tax" name="lclub_settings[cashback_base_exclude_tax]" type="checkbox" value="1" <?php checked( $exclude_tax ); ?> /> <?php esc_html_e( 'مالیات را هم از مبنای کش‌بک کم کن', 'lclub' ); ?></span><p class="description"><?php esc_html_e( 'مبنای پیش‌فرض «مجموع سفارش منهای هزینهٔ حمل» است؛ با این گزینه مالیات نیز کسر می‌شود.', 'lclub' ); ?></p></td></tr>
					</table></section>
					<section id="wallet" class="lclub-settings-section"><h2><?php esc_html_e( 'خرج کیف پول', 'lclub' ); ?></h2><table class="form-table" role="presentation">
						<tr><th scope="row"><label for="lclub-wallet-title"><?php esc_html_e( 'عنوان ردیف هزینه', 'lclub' ); ?></label></th><td><input id="lclub-wallet-title" name="lclub_settings[wallet_fee_title]" type="text" value="<?php echo esc_attr( $wallet_title ); ?>" /><p class="description"><?php esc_html_e( 'این عنوان در سبد خرید و سفارش برای ردیف تخفیف کیف پول نمایش داده می‌شود.', 'lclub' ); ?></p></td></tr>
						<tr><th scope="row"><label for="lclub-wallet-percent"><?php esc_html_e( 'سقف درصدی از سبد', 'lclub' ); ?></label></th><td><input id="lclub-wallet-percent" name="lclub_settings[wallet_max_percent]" type="number" min="0" max="100" step="1" value="<?php echo esc_attr( (string) $wallet_percent ); ?>" /><p class="description"><?php esc_html_e( 'حداکثر درصد از مبلغ اقلام سبد (با مالیات، بدون هزینهٔ حمل) که در هر سفارش قابل خرج کردن است.', 'lclub' ); ?></p></td></tr>
						<tr><th scope="row"><label for="lclub-wallet-min"><?php esc_html_e( 'حداقل مبلغ سفارش', 'lclub' ); ?></label></th><td><input id="lclub-wallet-min" name="lclub_settings[wallet_min_order]" type="number" min="0" step="any" value="<?php echo esc_attr( (string) $wallet_min ); ?>" /><p class="description"><?php esc_html_e( 'برای سبد کم‌تر از این مبلغ، امکان استفاده از کیف پول نمایش داده نمی‌شود.', 'lclub' ); ?></p></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'درگاه‌های مجاز', 'lclub' ); ?></th><td><fieldset id="lclub-wallet-gateways" class="lclub-check">
							<?php
							$gateway_index = 0;
							foreach ( $wallet_gateways as $gateway_id => $gateway_title ) :
								++$gateway_index;
								$checked = in_array( (string) $gateway_id, $wallet_allowed, true );
								?>
								<label for="<?php echo esc_attr( 'lclub-wallet-gateway-' . $gateway_index ); ?>"><input id="<?php echo esc_attr( 'lclub-wallet-gateway-' . $gateway_index ); ?>" name="lclub_settings[wallet_allowed_gateways][]" type="checkbox" value="<?php echo esc_attr( (string) $gateway_id ); ?>" <?php checked( $checked ); ?> /> <?php echo esc_html( $gateway_title ); ?></label>
							<?php endforeach; ?>
							<?php if ( empty( $wallet_gateways ) ) : ?>
								<p class="description"><?php esc_html_e( 'درگاهی برای انتخاب نیست.', 'lclub' ); ?></p>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'اگر هیچ درگاهی انتخاب نشود، همهٔ درگاه‌ها به‌جز «پرداخت در محل» مجاز هستند. سفارش‌هایی که کیف پول کل مبلغشان را می‌پوشاند نیازی به درگاه ندارند.', 'lclub' ); ?></p>
						</fieldset></td></tr>
						<tr><th scope="row"><label for="lclub-wallet-timeout"><?php esc_html_e( 'مهلت رزرو (ساعت)', 'lclub' ); ?></label></th><td><input id="lclub-wallet-timeout" name="lclub_settings[wallet_hold_timeout]" type="number" min="1" max="720" step="1" value="<?php echo esc_attr( (string) $wallet_timeout ); ?>" /><p class="description"><?php esc_html_e( 'اگر سفارش در این مدت پرداخت نشود و در وضعیت «در انتظار پرداخت» بماند، رزرو کیف پول خودکار آزاد می‌شود.', 'lclub' ); ?></p></td></tr>
						<tr><th scope="row"><label for="lclub-wallet-full"><?php esc_html_e( 'پوشش کامل مبلغ', 'lclub' ); ?></label></th><td><span class="lclub-check"><input id="lclub-wallet-full" name="lclub_settings[wallet_full_coverage]" type="checkbox" value="1" <?php checked( $wallet_full ); ?> /> <?php esc_html_e( 'پرداخت کامل سفارش با کیف پول مجاز باشد', 'lclub' ); ?></span><p class="description"><?php esc_html_e( 'با فعال بودن این گزینه، سفارش بدون نیاز به درگاه بانکی و با روش «کیف پول» تکمیل می‌شود.', 'lclub' ); ?></p></td></tr>
					</table></section>
					<section id="data" class="lclub-settings-section"><h2><?php esc_html_e( 'حذف داده‌ها', 'lclub' ); ?></h2><p><?php esc_html_e( 'با فعال‌سازی این گزینه، حذف افزونه همهٔ داده‌های AriunetClub را پاک می‌کند. این عمل قابل بازگشت نیست.', 'lclub' ); ?></p><label><input name="lclub_settings[delete_data]" type="checkbox" value="1" <?php checked( $settings['delete_data'] ); ?> /> <?php esc_html_e( 'پاک‌سازی کامل داده‌ها هنگام حذف افزونه', 'lclub' ); ?></label></section>
					<?php submit_button( __( 'ذخیرهٔ تغییرات', 'lclub' ) ); ?>
				</form>
			</div>
		</div>
		<?php
	}
}
