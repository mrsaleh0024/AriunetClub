<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Ledger;

use LClub\Core\JalaliDate;
use LClub\Core\Money;
use LClub\Modules\Identity\IdentityService;

class WalletAdminPage {
	/** @var LedgerGateway */
	private $repository;
	/** @var LedgerService */
	private $ledger;
	/** @var IdentityService */
	private $identity;

	public function __construct( LedgerGateway $repository, LedgerService $ledger, IdentityService $identity ) {
		$this->repository = $repository;
		$this->ledger     = $ledger;
		$this->identity   = $identity; }
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_lclub_adjust_balance', array( $this, 'adjust_balance' ) );
		add_action( 'wp_ajax_lclub_adjust_balance', array( $this, 'adjust_balance_ajax' ) ); }
	public function menu(): void {
		add_submenu_page( 'lclub-settings', __( 'کیف پول‌ها', 'lclub' ), __( 'کیف پول‌ها', 'lclub' ), 'manage_woocommerce', 'lclub-wallets', array( $this, 'render_wallets' ) );
		add_submenu_page( null, __( 'تاریخچه کیف پول', 'lclub' ), __( 'تاریخچه کیف پول', 'lclub' ), 'manage_woocommerce', 'lclub-wallet-history', array( $this, 'render_history' ) );
	}
	public function render_wallets(): void {
		$this->guard();
		// This is a read-only URL filter. A nonce would break shareable searches and pagination.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitized GET input does not change state.
		$search = isset( $_GET['phone'] ) ? sanitize_text_field( wp_unslash( $_GET['phone'] ) ) : '';
		// This is a read-only URL filter. A nonce would break shareable searches and pagination.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitized GET input does not change state.
		$user_id = '' !== $search ? $this->identity->find_user_by_phone( $search ) : null;
		// This is a read-only URL filter. A nonce would break shareable searches and pagination.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitized GET input does not change state.
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$result = $this->repository->balances( null === $user_id ? array() : array( $user_id ), $page );
		$this->shell_open( __( 'کیف پول مشتریان', 'lclub' ), __( 'جست‌وجو و بررسی موجودی‌های باشگاه مشتریان.', 'lclub' ) );
		?>
		<form method="get" class="lclub-panel"><input type="hidden" name="page" value="lclub-wallets" /><label for="lclub-wallet-phone"><?php esc_html_e( 'جست‌وجوی شماره موبایل', 'lclub' ); ?></label><p><input id="lclub-wallet-phone" name="phone" type="text" value="<?php echo esc_attr( Money::to_persian_digits( $search ) ); ?>" placeholder="۰۹۱۲۱۲۳۴۵۶۷" /> <button class="button button-primary" type="submit"><?php esc_html_e( 'جست‌وجو', 'lclub' ); ?></button></p>
		<?php
		if ( '' !== $search && null === $user_id ) :
			?>
			<div class="lclub-notice lclub-notice--warning"><?php esc_html_e( 'کاربری با این شماره موبایل پیدا نشد.', 'lclub' ); ?></div><?php endif; ?>
		<table class="lclub-table"><thead><tr><th><?php esc_html_e( 'مشتری', 'lclub' ); ?></th><th><?php esc_html_e( 'موبایل', 'lclub' ); ?></th><th><?php esc_html_e( 'قابل استفاده', 'lclub' ); ?></th><th><?php esc_html_e( 'رزروشده', 'lclub' ); ?></th><th><?php esc_html_e( 'عملیات', 'lclub' ); ?></th></tr></thead><tbody>
		<?php
		foreach ( $result['items'] as $balance ) :
			$user = get_userdata( (int) $balance['user_id'] );
			if ( ! $user ) {
				continue;
			} $url = add_query_arg(
				array(
					'page'    => 'lclub-wallet-history',
					'user_id' => (int) $balance['user_id'],
				),
				admin_url( 'admin.php' )
			);
			?>
			<?php $phone = $this->identity->phone_for_user( (int) $balance['user_id'] ); ?>
			<tr><td><?php echo esc_html( $user->display_name ); ?></td><td><?php echo esc_html( null === $phone ? '—' : Money::to_persian_digits( '0' . $phone ) ); ?></td><td class="lclub-balance"><?php echo esc_html( Money::format( (float) $balance['available'] ) ); ?></td><td class="lclub-balance"><?php echo esc_html( Money::format( (float) $balance['held'] ) ); ?></td><td><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'تاریخچه و تنظیم', 'lclub' ); ?></a></td></tr><?php endforeach; ?></tbody></table>
		<?php $this->pagination( $page, $result['total'], 'lclub-wallets', array( 'phone' => $search ) ); ?></form>
		<?php
		$this->shell_close();
	}
	public function render_history(): void {
		$this->guard();
		// This is a read-only URL filter. A nonce would break direct links to a wallet history page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitized GET input does not change state.
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			wp_die( esc_html__( 'مشتری مورد نظر پیدا نشد.', 'lclub' ) ); }
		// This is a read-only URL filter. A nonce would break direct links to a wallet history page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitized GET input does not change state.
		$page        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$balance     = $this->ledger->balance( $user_id );
		$history     = $this->repository->history( $user_id, 'cashback', $page );
		$wallets_url = add_query_arg( array( 'page' => 'lclub-wallets' ), admin_url( 'admin.php' ) );
		/* translators: %s: customer display name. */
		$this->shell_open( sprintf( __( 'کیف پول %s', 'lclub' ), $user->display_name ), __( 'تاریخچهٔ تراکنش‌ها و تنظیم دستی موجودی.', 'lclub' ), $wallets_url );
		?>
		<?php $phone = $this->identity->phone_for_user( $user_id ); ?>
		<?php
		// This is a read-only status message from the same-page redirect.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitized GET input does not change state.
		if ( isset( $_GET['lclub_notice'] ) && 'adjusted' === sanitize_key( wp_unslash( $_GET['lclub_notice'] ) ) ) :
			?>
			<div class="lclub-notice lclub-notice--info"><?php esc_html_e( 'موجودی با موفقیت تنظیم شد.', 'lclub' ); ?></div><?php endif; ?>
		<section class="lclub-panel lclub-history-summary" aria-labelledby="lclub-balance-title"><div class="lclub-history-summary__primary"><span><?php esc_html_e( 'موجودی قابل استفاده', 'lclub' ); ?></span><strong id="lclub-balance-title"><?php echo esc_html( Money::format( (float) $balance['available'] ) ); ?></strong></div><dl class="lclub-history-summary__details"><div><dt><?php esc_html_e( 'شماره موبایل', 'lclub' ); ?></dt><dd><?php echo esc_html( null === $phone ? '—' : Money::to_persian_digits( '0' . $phone ) ); ?></dd></div><div><dt><?php esc_html_e( 'موجودی رزروشده', 'lclub' ); ?></dt><dd><?php echo esc_html( Money::format( (float) $balance['held'] ) ); ?></dd></div></dl></section>
		<section class="lclub-panel lclub-history-adjust" aria-labelledby="lclub-adjust-title"><div><h2 id="lclub-adjust-title"><?php esc_html_e( 'تنظیم دستی', 'lclub' ); ?></h2><p><?php esc_html_e( 'در صورت نیاز، اعتبار این مشتری را با ثبت دلیل تغییر دهید.', 'lclub' ); ?></p></div><button class="button button-primary" type="button" data-lclub-open-adjust><?php esc_html_e( 'تنظیم دستی موجودی', 'lclub' ); ?></button></section><dialog class="lclub-dialog" data-lclub-adjust-dialog aria-labelledby="lclub-adjust-dialog-title"><form method="dialog" class="lclub-dialog__card" data-lclub-adjust-form><header class="lclub-dialog__header"><div><span class="lclub-admin-kicker">ARIUNETCLUB</span><h2 id="lclub-adjust-dialog-title"><?php esc_html_e( 'تنظیم دستی موجودی', 'lclub' ); ?></h2><p><?php esc_html_e( 'مبلغ مثبت اعتبار و مبلغ منفی کسر می‌کند.', 'lclub' ); ?></p></div><button class="lclub-dialog__close" type="button" data-lclub-close-adjust aria-label="<?php esc_attr_e( 'بستن', 'lclub' ); ?>">×</button></header><input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>" /><?php wp_nonce_field( 'lclub_adjust_balance' ); ?><p><label for="lclub-adjust-amount"><?php esc_html_e( 'مبلغ تغییر', 'lclub' ); ?></label><input id="lclub-adjust-amount" name="amount" type="number" step="any" required /></p><p><label for="lclub-adjust-reason"><?php esc_html_e( 'دلیل', 'lclub' ); ?></label><textarea id="lclub-adjust-reason" name="reason" required></textarea></p><footer class="lclub-dialog__actions"><button class="button lclub-button-secondary" type="button" data-lclub-close-adjust><?php esc_html_e( 'انصراف', 'lclub' ); ?></button><button class="button button-primary" type="submit" data-lclub-save-adjust><?php esc_html_e( 'ثبت تغییر', 'lclub' ); ?></button></footer></form></dialog><div class="lclub-toast" data-lclub-toast role="status" aria-live="polite" hidden></div>
		<section class="lclub-panel lclub-history-transactions" aria-labelledby="lclub-history-title"><div class="lclub-section-heading"><div><h2 id="lclub-history-title"><?php esc_html_e( 'تاریخچه تراکنش‌ها', 'lclub' ); ?></h2><p><?php esc_html_e( 'تمام تغییرهای ثبت‌شده برای این کیف پول.', 'lclub' ); ?></p></div><span class="lclub-history-count"><?php echo esc_html( Money::to_persian_digits( (string) $history['total'] ) ); ?></span></div><div class="lclub-table-scroll"><table class="lclub-table"><thead><tr><th><?php esc_html_e( 'تاریخ', 'lclub' ); ?></th><th><?php esc_html_e( 'نوع', 'lclub' ); ?></th><th><?php esc_html_e( 'تغییر', 'lclub' ); ?></th><th><?php esc_html_e( 'مانده کل', 'lclub' ); ?></th><th><?php esc_html_e( 'دلیل', 'lclub' ); ?></th></tr></thead><tbody>
		<?php
		foreach ( $history['items'] as $entry ) :
			?>
			<tr><td><?php echo esc_html( JalaliDate::format( (string) $entry['created_at'] ) ); ?></td><td><?php echo esc_html( $this->entry_label( (string) $entry['entry_type'] ) ); ?></td><td class="lclub-balance"><?php echo esc_html( Money::format( (float) $entry['amount'] ) ); ?></td><td class="lclub-balance"><?php echo esc_html( Money::format( (float) $entry['balance_after'] ) ); ?></td><td><?php echo esc_html( (string) $entry['note'] ); ?></td></tr><?php endforeach; ?></tbody></table></div><?php $this->pagination( $page, $history['total'], 'lclub-wallet-history', array( 'user_id' => $user_id ) ); ?></section>
		<?php
		$this->shell_close();
	}
	public function adjust_balance(): void {
		$this->guard();
		check_admin_referer( 'lclub_adjust_balance' );
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$reason  = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		$amount  = isset( $_POST['amount'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : 0.0;
		if ( $user_id <= 0 || '' === $reason || 0.0 === $amount ) {
			wp_die( esc_html__( 'مبلغ و دلیل معتبر الزامی هستند.', 'lclub' ) ); }
		$this->ledger->adjust(
			$user_id,
			$amount,
			'manual-adjust:' . wp_generate_uuid4(),
			array(
				'note'       => $reason,
				'ref_type'   => 'manual_adjustment',
				'created_by' => get_current_user_id(),
			)
		);
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'lclub-wallet-history',
					'user_id'      => $user_id,
					'lclub_notice' => 'adjusted',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
	public function adjust_balance_ajax(): void {
		$this->guard();
		check_ajax_referer( 'lclub_adjust_balance', 'nonce' );
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$reason  = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		$amount  = isset( $_POST['amount'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : 0.0;
		if ( $user_id <= 0 || '' === $reason || 0.0 === $amount ) {
			wp_send_json_error( array( 'message' => __( 'مبلغ و دلیل معتبر الزامی هستند.', 'lclub' ) ), 400 );
		}
		try {
			$this->ledger->adjust(
				$user_id,
				$amount,
				'manual-adjust:' . wp_generate_uuid4(),
				array(
					'note'       => $reason,
					'ref_type'   => 'manual_adjustment',
					'created_by' => get_current_user_id(),
				)
			);
			wp_send_json_success( array( 'message' => __( 'موجودی با موفقیت تغییر کرد.', 'lclub' ) ) );
		} catch ( \Throwable $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 400 );
		}
	}
	private function guard(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'شما اجازهٔ دسترسی به این صفحه را ندارید.', 'lclub' ) ); } }
	private function shell_open( string $title, string $description, string $back_url = '' ): void {

		?>
	<div class="wrap lclub-scope" dir="rtl"><div class="lclub-admin-shell"><header class="lclub-admin-header">
		<?php
		if ( '' !== $back_url ) :
			?>
		<nav class="lclub-history-breadcrumb" aria-label="<?php esc_attr_e( 'ناوبری کیف پول', 'lclub' ); ?>"><a href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'بازگشت به کیف پول‌ها', 'lclub' ); ?></a></nav><?php endif; ?><span class="lclub-admin-kicker">ARIUNETCLUB</span><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $description ); ?></p></header>
		<?php
	}
	private function shell_close(): void {

		?>
	</div></div>
		<?php
	}
	/** @param array<string,mixed> $args */
	private function pagination( int $page, int $total, string $slug, array $args ): void {
		$pages = (int) ceil( $total / 20 );
		if ( $pages <= 1 ) {
			return; }
		?>
	<nav class="lclub-pagination" aria-label="<?php esc_attr_e( 'صفحه‌بندی', 'lclub' ); ?>">
		<?php
		for ( $number = 1; $number <= $pages; $number++ ) :
			$url = add_query_arg(
				array_merge(
					array(
						'page'  => $slug,
						'paged' => $number,
					),
					$args
				),
				admin_url( 'admin.php' )
			);
			?>
		<a href="<?php echo esc_url( $url ); ?>" <?php echo $number === $page ? 'aria-current="page"' : ''; ?>><?php echo esc_html( Money::to_persian_digits( (string) $number ) ); ?></a><?php endfor; ?></nav>
		<?php
	}
	private function entry_label( string $type ): string {
		$labels = array(
			'credit'  => __( 'اعتبار', 'lclub' ),
			'debit'   => __( 'کسر', 'lclub' ),
			'hold'    => __( 'رزرو', 'lclub' ),
			'capture' => __( 'ثبت مصرف', 'lclub' ),
			'release' => __( 'آزادسازی', 'lclub' ),
			'adjust'  => __( 'تنظیم دستی', 'lclub' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type; }
}
