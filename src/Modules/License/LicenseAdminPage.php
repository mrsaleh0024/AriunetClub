<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\License;

use AriunetClub\Core\JalaliDate;
use AriunetClub\Core\Money;

/**
 * Phase 5 license admin page: activation form, live status card and
 * deactivation. Capability + nonce guarded; no network request is ever
 * made — verification is fully offline via Ed25519.
 */
final class LicenseAdminPage {

	/** @var LicenseStore */
	private $store;

	public function __construct( LicenseStore $store ) {
		$this->store = $store;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 40 );
		add_action( 'admin_post_ariunetclub_activate_license', array( $this, 'activate' ) );
		add_action( 'admin_post_ariunetclub_deactivate_license', array( $this, 'deactivate' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'ariunetclub-settings',
			__( 'لایسنس', 'ariunetclub' ),
			__( 'لایسنس', 'ariunetclub' ),
			'manage_woocommerce',
			'ariunetclub-license',
			array( $this, 'render' )
		);
	}

	private function guard(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'شما اجازهٔ دسترسی به این صفحه را ندارید.', 'ariunetclub' ) );
		}
	}

	/**
	 * Activation POST handler: verifies offline and binds the license.
	 */
	public function activate(): void {
		$this->guard();
		check_admin_referer( 'ariunetclub_activate_license', 'ariunetclub_nonce' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalized below; a token is base64url + dot only.
		$raw   = isset( $_POST['ariunetclub_license_token'] ) && is_string( $_POST['ariunetclub_license_token'] ) ? wp_unslash( $_POST['ariunetclub_license_token'] ) : '';
		$token = self::normalize_token( is_string( $raw ) ? $raw : '' );

		$result = $this->store->activate( $token );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                      => 'ariunetclub-license',
					'ariunetclub_license_flash' => $result['ok'] ? 'activated' : 'invalid',
					'ariunetclub_license_code'  => rawurlencode( $result['code'] ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Deactivation POST handler: drops the binding (history is kept so the
	 * same token cannot restart its term later).
	 */
	public function deactivate(): void {
		$this->guard();
		check_admin_referer( 'ariunetclub_deactivate_license', 'ariunetclub_nonce' );
		$this->store->deactivate();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                      => 'ariunetclub-license',
					'ariunetclub_license_flash' => 'deactivated',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Renders the page. The license page never sits behind the lock screen —
	 * it is the page that unlocks the plugin.
	 */
	public function render(): void {
		$this->guard();
		$status = $this->store->status();

		// Same-page flash message from the redirect after activation.
		$flash = sanitize_key( LicenseGuard::query_string( 'ariunetclub_license_flash' ) );
		$code  = sanitize_key( LicenseGuard::query_string( 'ariunetclub_license_code' ) );

		$binding        = $this->store->binding();
		$payload        = is_array( $status['payload'] ) ? $status['payload'] : array();
		$payload_domain = is_string( $payload['domain'] ?? null ) ? $payload['domain'] : '';
		$payload_plan   = is_string( $payload['plan'] ?? null ) ? $payload['plan'] : '';
		$payload_jti    = is_string( $payload['jti'] ?? null ) ? $payload['jti'] : '';
		?>
		<div class="wrap ariunetclub-scope" dir="rtl">
			<div class="ariunetclub-admin-shell">
				<header class="ariunetclub-admin-header">
					<span class="ariunetclub-admin-kicker"><?php esc_html_e( 'ARIUNETCLUB', 'ariunetclub' ); ?></span>
					<h1><?php esc_html_e( 'لایسنس AriunetClub', 'ariunetclub' ); ?></h1>
					<p><?php esc_html_e( 'کد لایسنس دریافتی از راست‌چین را اینجا وارد کنید. بررسی کاملاً آفلاین انجام می‌شود و هیچ درخواستی به بیرون از سایت شما ارسال نمی‌شود.', 'ariunetclub' ); ?></p>
				</header>

				<?php if ( 'activated' === $flash ) : ?>
					<div class="ariunetclub-notice ariunetclub-notice--success"><?php esc_html_e( 'لایسنس با موفقیت فعال شد.', 'ariunetclub' ); ?></div>
				<?php elseif ( 'deactivated' === $flash ) : ?>
					<div class="ariunetclub-notice ariunetclub-notice--info"><?php esc_html_e( 'لایسنس غیرفعال شد؛ بخش‌های مدیریتی قفل شدند.', 'ariunetclub' ); ?></div>
				<?php elseif ( 'invalid' === $flash ) : ?>
					<div class="ariunetclub-notice ariunetclub-notice--error"><?php echo esc_html( LicenseGuard::notice_message_for( $code ) ); ?></div>
				<?php endif; ?>

				<section class="ariunetclub-panel ariunetclub-license-status" aria-labelledby="ariunetclub-license-status-title">
					<div class="ariunetclub-section-heading"><div><h2 id="ariunetclub-license-status-title"><?php esc_html_e( 'وضعیت لایسنس', 'ariunetclub' ); ?></h2><p><?php esc_html_e( 'خلاصهٔ وضعیت فعلی لایسنس روی این سایت.', 'ariunetclub' ); ?></p></div>
					<span class="ariunetclub-license-status__chip ariunetclub-license-status__chip--<?php echo esc_attr( self::status_family( $status['code'] ) ); ?>"><?php echo esc_html( self::status_label( $status['code'] ) ); ?></span></div>

					<?php if ( 'ok' === $status['code'] ) : ?>
						<dl class="ariunetclub-license-status__grid">
							<div><dt><?php esc_html_e( 'دامنهٔ ثبت‌شده', 'ariunetclub' ); ?></dt><dd><?php echo esc_html( $payload_domain ); ?></dd></div>
							<div><dt><?php esc_html_e( 'طرح', 'ariunetclub' ); ?></dt><dd><?php echo esc_html( $payload_plan ); ?></dd></div>
							<div><dt><?php esc_html_e( 'تاریخ فعال‌سازی', 'ariunetclub' ); ?></dt><dd><?php echo esc_html( Money::to_persian_digits( JalaliDate::format( gmdate( 'Y-m-d H:i:s', $status['activated_at'] ) ) ) ); ?></dd></div>
							<div><dt><?php esc_html_e( 'اعتبار تا', 'ariunetclub' ); ?></dt><dd><?php echo esc_html( Money::to_persian_digits( JalaliDate::format( gmdate( 'Y-m-d H:i:s', $status['expires_at'] ) ) ) ); ?></dd></div>
							<div><dt><?php esc_html_e( 'روزهای باقی‌مانده', 'ariunetclub' ); ?></dt><dd><?php echo esc_html( Money::to_persian_digits( (string) $status['days_left'] ) ); ?></dd></div>
						</dl>
						<p class="ariunetclub-license-status__hint"><?php esc_html_e( 'شناسهٔ لایسنس:', 'ariunetclub' ); ?> <code><?php echo esc_html( self::mask_token_id( $payload_jti ) ); ?></code></p>
					<?php elseif ( 'missing' === $status['code'] ) : ?>
						<p class="ariunetclub-license-status__hint"><?php esc_html_e( 'هنوز لایسنسی روی این سایت فعال نشده است. کد لایسنس را در فرم پایین وارد کنید.', 'ariunetclub' ); ?></p>
					<?php else : ?>
						<p class="ariunetclub-license-status__hint"><?php echo esc_html( $status['message'] ); ?></p>
						<?php if ( null !== $binding ) : ?>
							<p class="ariunetclub-license-status__hint"><?php esc_html_e( 'شناسهٔ آخرین لایسنس ثبت‌شده:', 'ariunetclub' ); ?> <code><?php echo esc_html( self::mask_token_id( is_string( $binding['jti'] ?? null ) ? $binding['jti'] : '' ) ); ?></code></p>
						<?php endif; ?>
					<?php endif; ?>
				</section>

				<section class="ariunetclub-panel" aria-labelledby="ariunetclub-license-form-title">
					<div class="ariunetclub-section-heading"><div><h2 id="ariunetclub-license-form-title"><?php esc_html_e( 'فعال‌سازی لایسنس', 'ariunetclub' ); ?></h2><p><?php esc_html_e( 'کد لایسنس را دقیقاً همان‌طور که دریافت کرده‌اید کپی کنید.', 'ariunetclub' ); ?></p></div></div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ariunetclub-license-form">
						<input type="hidden" name="action" value="ariunetclub_activate_license" />
						<?php wp_nonce_field( 'ariunetclub_activate_license', 'ariunetclub_nonce' ); ?>
						<p>
							<label for="ariunetclub-license-token"><?php esc_html_e( 'کد لایسنس', 'ariunetclub' ); ?></label>
							<textarea id="ariunetclub-license-token" name="ariunetclub_license_token" rows="4" class="large-text code" dir="ltr" spellcheck="false" required></textarea>
						</p>
						<p><button class="button button-primary" type="submit"><?php esc_html_e( 'فعال‌سازی', 'ariunetclub' ); ?></button></p>
					</form>

					<?php if ( 'ok' === $status['code'] ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ariunetclub-license-form" data-ariunetclub-confirm="<?php esc_attr_e( 'لایسنس غیرفعال شود؟ بخش‌های مدیریتی قفل خواهند شد.', 'ariunetclub' ); ?>">
							<input type="hidden" name="action" value="ariunetclub_deactivate_license" />
							<?php wp_nonce_field( 'ariunetclub_deactivate_license', 'ariunetclub_nonce' ); ?>
							<p><button class="button ariunetclub-button-secondary" type="submit"><?php esc_html_e( 'غیرفعال‌سازی لایسنس', 'ariunetclub' ); ?></button></p>
						</form>
					<?php endif; ?>
				</section>
			</div>
		</div>
		<?php
	}

	/**
	 * Normalizes pasted token input: keeps only base64url characters and the
	 * separating dot, collapses whitespace. Guards against payloads hidden
	 * in whitespace or mixed line endings.
	 */
	public static function normalize_token( string $raw ): string {
		$raw = str_replace( array( "\r", "\n", "\t", ' ' ), '', trim( $raw ) );
		$raw = preg_replace( '/[^A-Za-z0-9_.\-]/', '', $raw );
		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * @return string Masked jti safe for display.
	 */
	public static function mask_token_id( string $jti ): string {
		return strlen( $jti ) > 12 ? substr( $jti, 0, 8 ) . '…' . substr( $jti, -4 ) : $jti;
	}

	/**
	 * @return string Persian status label.
	 */
	public static function status_label( string $code ): string {
		$labels = array(
			'ok'                 => 'فعال',
			'missing'            => 'بدون لایسنس',
			'expired'            => 'منقضی‌شده',
			'tampered'           => 'دستکاری‌شده',
			'crypto_unavailable' => 'خطای کتابخانهٔ رمزنگاری',
			'unavailable'        => 'ماژول لایسنس در دسترس نیست',
		);
		return isset( $labels[ $code ] ) ? $labels[ $code ] : 'نامعتبر';
	}

	/**
	 * @return string success|danger|warning chip family.
	 */
	public static function status_family( string $code ): string {
		if ( 'ok' === $code ) {
			return 'success';
		}
		if ( in_array( $code, array( 'missing', 'crypto_unavailable', 'unavailable' ), true ) ) {
			return 'warning';
		}
		return 'danger';
	}
}
