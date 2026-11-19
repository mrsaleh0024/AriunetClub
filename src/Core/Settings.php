<?php
/**
 * @package LClub
 */

namespace LClub\Core;

class Settings {
	const OPTION = 'lclub_settings';

	/**
	 * @return array{enabled_modules:array<int,string>,phone_meta:string,delete_data:bool}
	 */
	public static function defaults(): array {
		return array(
			'enabled_modules' => array( 'core', 'identity', 'ledger' ),
			'phone_meta'      => 'billing_phone',
			'delete_data'     => false,
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
	 * @return array{enabled_modules:array<int,string>,phone_meta:string,delete_data:bool}
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		return array(
			'enabled_modules' => array( 'core', 'identity', 'ledger' ),
			'phone_meta'      => isset( $input['phone_meta'] ) ? sanitize_key( $input['phone_meta'] ) : 'billing_phone',
			'delete_data'     => ! empty( $input['delete_data'] ),
		);
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
		$phone_metas = apply_filters( 'lclub_phone_meta_keys', array( 'billing_phone' ) );
		$phone_metas = is_array( $phone_metas ) ? array_values( array_unique( array_filter( $phone_metas, 'is_string' ) ) ) : array( 'billing_phone' );
		if ( ! in_array( $phone_meta, $phone_metas, true ) ) {
			$phone_metas[] = $phone_meta;
		}
		?>
		<div class="wrap lclub-scope" dir="rtl">
			<div class="lclub-admin-shell">
				<header class="lclub-admin-header"><span class="lclub-admin-kicker"><?php esc_html_e( 'ARIUNETCLUB', 'lclub' ); ?></span><h1><?php esc_html_e( 'تنظیمات باشگاه مشتریان', 'lclub' ); ?></h1><p><?php esc_html_e( 'هستهٔ باشگاه آماده است؛ ماژول‌های کسب و خرج در فازهای بعدی فعال می‌شوند.', 'lclub' ); ?></p></header>
				<form method="post" action="options.php" class="lclub-panel">
					<?php settings_fields( 'lclub_settings_group' ); ?>
					<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'بخش‌های تنظیمات', 'lclub' ); ?>"><a class="nav-tab nav-tab-active" href="#general"><?php esc_html_e( 'عمومی', 'lclub' ); ?></a><a class="nav-tab" href="#data"><?php esc_html_e( 'داده‌ها', 'lclub' ); ?></a></nav>
					<section id="general" class="lclub-settings-section"><h2><?php esc_html_e( 'تنظیمات عمومی', 'lclub' ); ?></h2><table class="form-table" role="presentation"><tr><th scope="row"><label for="lclub-phone-meta"><?php esc_html_e( 'کلید متای موبایل', 'lclub' ); ?></label></th><td><select id="lclub-phone-meta" name="lclub_settings[phone_meta]" class="regular-text">
					<?php
					foreach ( $phone_metas as $meta_key ) :
						?>
						<option value="<?php echo esc_attr( $meta_key ); ?>" <?php selected( $phone_meta, $meta_key ); ?>><?php echo esc_html( $meta_key ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'کلیدهای حاوی phone یا mobile در متای کاربران برای انتخاب نمایش داده می‌شوند. در صورت خالی بودن، billing_phone استفاده می‌شود.', 'lclub' ); ?></p></td></tr></table></section>
					<section id="data" class="lclub-settings-section"><h2><?php esc_html_e( 'حذف داده‌ها', 'lclub' ); ?></h2><p><?php esc_html_e( 'با فعال‌سازی این گزینه، حذف افزونه همهٔ داده‌های AriunetClub را پاک می‌کند. این عمل قابل بازگشت نیست.', 'lclub' ); ?></p><label><input name="lclub_settings[delete_data]" type="checkbox" value="1" <?php checked( $settings['delete_data'] ); ?> /> <?php esc_html_e( 'پاک‌سازی کامل داده‌ها هنگام حذف افزونه', 'lclub' ); ?></label></section>
					<?php submit_button( __( 'ذخیرهٔ تغییرات', 'lclub' ) ); ?>
				</form>
			</div>
		</div>
		<?php
	}
}
