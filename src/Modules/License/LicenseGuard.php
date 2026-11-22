<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\License;

/**
 * Central license gate (phase 5). Every module that owns a locked action
 * asks this guard instead of re-implementing checks.
 *
 * Locked while the license is anything but active (stricter than the MVP
 * doc, per the phase 5 decisions): creating, editing, deleting or toggling
 * earning rules; manual wallet adjustments; saving plugin settings. Earning
 * and spending keep running so customer money is never hostage to the
 * license. Read-only admin pages render behind a blurred lock screen.
 *
 * Fails closed: if the license module never booted, everything stays locked
 * with an explicit message instead of silently opening up.
 */
final class LicenseGuard {

				const LOCKED_ACTIONS_MESSAGE  = 'تا زمانی که لایسنس فعال نباشد، ساخت و ویرایش قوانین کش‌بک ممکن نیست. کسب و خرج کیف پول مشتریان بدون وقفه ادامه می‌یابد.';
				const LOCKED_ADJUST_MESSAGE   = 'تا زمانی که لایسنس فعال نباشد، تنظیم دستی موجودی ممکن نیست. کسب و خرج کیف پول مشتریان بدون وقفه ادامه می‌یابد.';
				const LOCKED_SETTINGS_MESSAGE = 'تا زمانی که لایسنس فعال نباشد، ذخیرهٔ تنظیمات قفل است؛ مقادیر فعلی بدون تغییر ماندند.';
				const UNAVAILABLE_MESSAGE     = 'ماژول لایسنس AriunetClub بارگیری نشد؛ برای امنیت، بخش‌های مدیریتی قفل شدند. یک‌بار افزونه را غیرفعال و دوباره فعال کنید.';

				/** @var LicenseStore|null */
				private $store;

				/** @var array{code:string,payload:array<string,mixed>|null,activated_at:int,expires_at:int,days_left:int,message:string}|null */
	private $status_cache;

				/** @var self|null */
	private static $instance;

	public function __construct( LicenseStore $store ) {
					$this->store = $store;
	}

				/**
				 * Sets the process-wide guard instance (called by LicenseModule::boot).
				 */
	public static function set_instance( ?LicenseGuard $guard ): void {
					self::$instance = $guard;
	}

				/**
				 * The guard other modules must consult. When the license module is not
				 * booted this returns a fail-closed guard.
				 *
				 * @return self
				 */
	public static function instance(): self {
		if ( null === self::$instance ) {
						self::$instance = new self( new LicenseStore( new LicenseVerifier() ) );
						self::$instance->mark_unavailable();
		}
					return self::$instance;
	}

				/** @var bool */
				private $unavailable = false;

	private function mark_unavailable(): void {
					$this->unavailable = true;
	}

				/**
				 * Current license status (cached per request).
				 *
				 * @return array{code:string,payload:array<string,mixed>|null,activated_at:int,expires_at:int,days_left:int,message:string}
				 */
	public function status(): array {
		if ( null === $this->status_cache ) {
			if ( $this->unavailable || null === $this->store ) {
				$this->status_cache = array(
					'code'         => 'unavailable',
					'payload'      => null,
					'activated_at' => 0,
					'expires_at'   => 0,
					'days_left'    => 0,
					'message'      => self::UNAVAILABLE_MESSAGE,
				);
			} else {
							$this->status_cache = $this->store->status();
			}
		}
					return $this->status_cache;
	}

				/**
				 * @return bool True only when a valid, unexpired license is active.
				 */
	public function is_active(): bool {
					return 'ok' === $this->status()['code'];
	}

				/** @return bool */
	public function can_manage_rules(): bool {
					return $this->is_active();
	}

				/** @return bool */
	public function can_adjust_balances(): bool {
					return $this->is_active();
	}

				/** @return bool */
	public function can_save_settings(): bool {
					return $this->is_active();
	}

				/**
				 * Guards the rule admin POST actions. Never returns when locked.
				 */
	public function assert_rule_management(): void {
		if ( $this->can_manage_rules() ) {
				return;
		}
			wp_die( esc_html__( 'تا زمانی که لایسنس فعال نباشد، ساخت و ویرایش قوانین کش‌بک ممکن نیست. کسب و خرج کیف پول مشتریان بدون وقفه ادامه می‌یابد.', 'ariunetclub' ) );
	}

				/**
				 * Guards manual wallet adjustments (admin-post + AJAX). Never returns
				 * when locked; the AJAX flavour answers with JSON instead of HTML.
				 *
				 * @param bool $ajax True for the admin-ajax entry point.
				 */
	public function assert_balance_adjustment( bool $ajax = false ): void {
		if ( $this->can_adjust_balances() ) {
				return;
		}
		if ( $ajax ) {
				wp_send_json_error( array( 'message' => __( 'تا زمانی که لایسنس فعال نباشد، تنظیم دستی موجودی ممکن نیست. کسب و خرج کیف پول مشتریان بدون وقفه ادامه می‌یابد.', 'ariunetclub' ) ), 403 );
		}
			wp_die( esc_html__( 'تا زمانی که لایسنس فعال نباشد، تنظیم دستی موجودی ممکن نیست. کسب و خرج کیف پول مشتریان بدون وقفه ادامه می‌یابد.', 'ariunetclub' ) );
	}

				/**
				 * Wires every admin-side behaviour of the guard.
				 */
	public function register(): void {
					add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
					add_action( 'admin_post_ariunetclub_dismiss_license_notice', array( $this, 'dismiss_notice' ) );
					add_filter( 'pre_update_option_' . \AriunetClub\Core\Settings::OPTION, array( $this, 'maybe_block_settings_save' ), 10, 2 );
					add_action( 'admin_footer', array( $this, 'render_lock_screen' ) );
	}

				/**
				 * pre_update_option filter: while locked, the stored settings win and
				 * the submitted values are discarded (server-side enforcement for the
				 * blurred settings screen).
				 *
				 * @param mixed               $new_value Sanitized candidate value.
				 * @param mixed               $old_value Currently stored value.
				 * @return mixed
				 */
	public function maybe_block_settings_save( $new_value, $old_value ) {
		if ( $this->can_save_settings() ) {
						return $new_value;
		}
		if ( function_exists( 'add_settings_error' ) ) {
						add_settings_error( 'ariunetclub_settings_group', 'ariunetclub_license', self::LOCKED_SETTINGS_MESSAGE, 'error' );
		}
					return $old_value;
	}

				/**
				 * Smart, dismissible admin notice for shop managers. Re-appears when the
				 * status code changes. Customers and other roles never see it.
				 */
	public function render_admin_notice(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) {
						return;
		}
					$status = $this->status();
					$code   = (string) $status['code'];
		if ( 'ok' === $code ) {
						$days_left = (int) $status['days_left'];
			if ( $days_left > 14 ) {
							return;
			}
						$code = 'expiring:' . $days_left;
						/* translators: %s: number of days left in Persian digits. */
						$message = sprintf( __( 'توجه: اعتبار لایسنس AriunetClub تا %s روز دیگر به پایان می‌رسد.', 'ariunetclub' ), \AriunetClub\Core\Money::to_persian_digits( (string) $days_left ) );
						$class   = 'notice-warning';
		} else {
						$message = self::notice_message_for( $code, $status['message'] );
						$class   = 'notice-error';
		}

					$dismissed = get_user_meta( get_current_user_id(), 'ariunetclub_license_notice_dismissed', true );
		if ( is_string( $dismissed ) && $dismissed === $code ) {
						return;
		}

					$dismiss_url = wp_nonce_url(
						add_query_arg(
							array(
								'action' => 'ariunetclub_dismiss_license_notice',
								'code'   => rawurlencode( $code ),
							),
							admin_url( 'admin-post.php' )
						),
						'ariunetclub_dismiss_license_notice'
					);

					echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible ariunetclub-license-notice"><p><strong>'
									. esc_html__( 'باشگاه مشتریان AriunetClub', 'ariunetclub' ) . '</strong> — '
									. esc_html( $message ) . ' <a href="' . esc_url( self::license_page_url() ) . '">' . esc_html__( 'مدیریت لایسنس', 'ariunetclub' ) . '</a>'
									. ' <a href="' . esc_url( $dismiss_url ) . '" class="ariunetclub-license-notice-dismiss">' . esc_html__( 'انصراف', 'ariunetclub' ) . '</a></p></div>';
	}

				/**
				 * Marks the current status code as dismissed for this user.
				 */
	public function dismiss_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
						wp_die( esc_html__( 'شما اجازهٔ دسترسی به این صفحه را ندارید.', 'ariunetclub' ) );
		}
					check_admin_referer( 'ariunetclub_dismiss_license_notice' );
		$code = sanitize_text_field( self::query_string( 'code' ) );
					update_user_meta( get_current_user_id(), 'ariunetclub_license_notice_dismissed', $code );
					wp_safe_redirect( remove_query_arg( array( 'action', 'code', '_wpnonce' ), (string) wp_get_referer() ) );
					exit;
	}

				/**
				 * Renders the blur-and-lock overlay over plugin admin pages (except the
				 * license page itself, which must stay usable to activate).
				 */
	public function render_lock_screen(): void {
		if ( $this->is_active() ) {
						return;
		}
		$page = sanitize_key( self::query_string( 'page' ) );
		if ( '' === $page || 0 !== strpos( $page, 'ariunetclub' ) || 'ariunetclub-license' === $page ) {
						return;
		}
		?>
								<div class="ariunetclub-lock-screen" aria-labelledby="ariunetclub-lock-title">
												<div class="ariunetclub-lock-screen__card">
																<svg class="ariunetclub-lock-screen__icon" viewBox="0 0 24 24" width="44" height="44" aria-hidden="true" focusable="false"><path d="M12 2a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-1V7a5 5 0 0 0-5-5Zm-3 5a3 3 0 1 1 6 0v3H9V7Zm4 7.73V17a1 1 0 1 1-2 0v-2.27a2 1.99 0 1 1 2 0Z" fill="currentColor"/></svg>
																<h2 id="ariunetclub-lock-title"><?php esc_html_e( 'این بخش با لایسنس فعال باز می‌شود', 'ariunetclub' ); ?></h2>
																<p><?php esc_html_e( 'برای مدیریت قوانین، تنظیم دستی موجودی و تنظیمات افزونه، ابتدا لایسنس AriunetClub را فعال کنید. کسب کش‌بک و خرج کیف پول مشتریان بدون وقفه ادامه می‌یابد.', 'ariunetclub' ); ?></p>
																<a class="button button-primary" href="<?php echo esc_url( self::license_page_url() ); ?>"><?php esc_html_e( 'فعال‌سازی لایسنس', 'ariunetclub' ); ?></a>
												</div>
								</div>
							<?php
	}

				/**
				 * @return string URL of the license admin page.
				 */
	public static function license_page_url(): string {
					return add_query_arg( array( 'page' => 'ariunetclub-license' ), admin_url( 'admin.php' ) );
	}

	/**
	 * Safe string extraction from the query string: unslashed and
	 * type-narrowed in one place so callers never touch a raw superglobal
	 * (S2) while satisfying both the WPCS sanitizer flow and static types.
	 *
	 * @param string $key Query variable name.
	 * @return string Plain string value ('' when absent or not a string).
	 */
	public static function query_string( string $key ): string {
		$raw = isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- variable-key extraction; validated with is_string + wp_unslash here and sanitized by callers before use.
		return is_string( $raw ) ? $raw : '';
	}

				/**
				 * Persian notice text per status code.
				 *
				 * @param string $code        Status code.
				 * @param string $fallback    Store-provided message when available.
				 * @return string
				 */
	public static function notice_message_for( string $code, string $fallback = '' ): string {
		switch ( $code ) {
			case 'missing':
				return 'لایسنس AriunetClub فعال نیست؛ ساخت و ویرایش قوانین، تنظیم دستی موجودی و ذخیرهٔ تنظیمات قفل است.';
			case 'expired':
				return 'اعتبار لایسنس AriunetClub به پایان رسیده است؛ بخش‌های مدیریتی قفل شدند.';
			case 'crypto_unavailable':
				return 'کتابخانهٔ رمزنگاری libsodium روی این هاست در دسترس نیست و لایسنس قابل بررسی نیست.';
			case 'unavailable':
				return self::UNAVAILABLE_MESSAGE;
			default:
				return '' !== $fallback ? $fallback : 'لایسنس AriunetClub معتبر نیست؛ بخش‌های مدیریتی قفل شدند.';
		}
	}
}
