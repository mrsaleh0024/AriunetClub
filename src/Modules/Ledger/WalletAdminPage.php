<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Ledger;

use AriunetClub\Core\JalaliDate;
use AriunetClub\Core\Money;
use AriunetClub\Modules\Identity\IdentityService;

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
		add_action( 'admin_post_ariunetclub_adjust_balance', array( $this, 'adjust_balance' ) );
		add_action( 'wp_ajax_ariunetclub_adjust_balance', array( $this, 'adjust_balance_ajax' ) ); }
	public function menu(): void {
		add_submenu_page( 'ariunetclub-settings', __( 'کیف پول‌ها', 'ariunetclub' ), __( 'کیف پول‌ها', 'ariunetclub' ), 'manage_woocommerce', 'ariunetclub-wallets', array( $this, 'render_wallets' ) );
		add_submenu_page( null, __( 'تاریخچه کیف پول', 'ariunetclub' ), __( 'تاریخچه کیف پول', 'ariunetclub' ), 'manage_woocommerce', 'ariunetclub-wallet-history', array( $this, 'render_history' ) );
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
		$result = null === $user_id && '' !== $search
			? array(
				'items' => array(),
				'total' => 0,
			)
			: $this->repository->balances( null === $user_id ? array() : array( $user_id ), $page );
		$this->shell_open( __( 'کیف پول مشتریان', 'ariunetclub' ), __( 'جست‌وجو و بررسی موجودی‌های باشگاه مشتریان.', 'ariunetclub' ) );
		?>
		<form method="get" class="ariunetclub-panel"><input type="hidden" name="page" value="ariunetclub-wallets" /><label for="ariunetclub-wallet-phone"><?php esc_html_e( 'جست‌وجوی شماره موبایل', 'ariunetclub' ); ?></label><p><input id="ariunetclub-wallet-phone" name="phone" type="text" value="<?php echo esc_attr( Money::to_persian_digits( $search ) ); ?>" placeholder="۰۹۱۲۱۲۳۴۵۶۷" /> <button class="button button-primary" type="submit"><?php esc_html_e( 'جست‌وجو', 'ariunetclub' ); ?></button></p>
		<?php
		if ( '' !== $search && null === $user_id ) :
			?>
			<div class="ariunetclub-notice ariunetclub-notice--warning"><?php esc_html_e( 'کاربری با این شماره موبایل پیدا نشد.', 'ariunetclub' ); ?></div><?php endif; ?>
		<table class="ariunetclub-table"><thead><tr><th><?php esc_html_e( 'مشتری', 'ariunetclub' ); ?></th><th><?php esc_html_e( 'موبایل', 'ariunetclub' ); ?></th><th><?php esc_html_e( 'قابل استفاده', 'ariunetclub' ); ?></th><th><?php esc_html_e( 'رزروشده', 'ariunetclub' ); ?></th><th><?php esc_html_e( 'عملیات', 'ariunetclub' ); ?></th></tr></thead><tbody>
		<?php
		foreach ( $result['items'] as $balance ) :
			$user = get_userdata( (int) $balance['user_id'] );
			if ( ! $user ) {
				continue;
			} $url = add_query_arg(
				array(
					'page'    => 'ariunetclub-wallet-history',
					'user_id' => (int) $balance['user_id'],
				),
				admin_url( 'admin.php' )
			);
			?>
			<?php $phone = $this->identity->phone_for_user( (int) $balance['user_id'] ); ?>
			<tr><td><?php echo esc_html( $user->display_name ); ?></td><td><?php echo esc_html( null === $phone ? '—' : Money::to_persian_digits( '0' . $phone ) ); ?></td><td class="ariunetclub-balance"><?php echo esc_html( Money::format( (float) $balance['available'] ) ); ?></td><td class="ariunetclub-balance"><?php echo esc_html( Money::format( (float) $balance['held'] ) ); ?></td><td><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'تاریخچه و تنظیم', 'ariunetclub' ); ?></a></td></tr><?php endforeach; ?></tbody></table>
		<?php $this->pagination( $page, $result['total'], 'ariunetclub-wallets', array( 'phone' => $search ) ); ?></form>
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
			wp_die( esc_html__( 'مشتری مورد نظر پیدا نشد.', 'ariunetclub' ) ); }
		// This is a read-only URL filter. A nonce would break direct links to a wallet history page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitized GET input does not change state.
		$page        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$balance     = $this->ledger->balance( $user_id );
		$history     = $this->repository->history( $user_id, 'cashback', $page );
		$wallets_url = add_query_arg( array( 'page' => 'ariunetclub-wallets' ), admin_url( 'admin.php' ) );
		/* translators: %s: customer display name. */
		$this->shell_open( sprintf( __( 'کیف پول %s', 'ariunetclub' ), $user->display_name ), __( 'تاریخچهٔ تراکنش‌ها و تنظیم دستی موجودی.', 'ariunetclub' ), $wallets_url );
		?>
		<?php $phone = $this->identity->phone_for_user( $user_id ); ?>
		<?php
		// This is a read-only status message from the same-page redirect.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitized GET input does not change state.
		if ( isset( $_GET['ariunetclub_notice'] ) && 'adjusted' === sanitize_key( wp_unslash( $_GET['ariunetclub_notice'] ) ) ) :
			?>
			<div class="ariunetclub-notice ariunetclub-notice--info"><?php esc_html_e( 'موجودی با موفقیت تنظیم شد.', 'ariunetclub' ); ?></div><?php endif; ?>
		<section class="ariunetclub-panel ariunetclub-history-summary" aria-labelledby="ariunetclub-balance-title"><div class="ariunetclub-history-summary__primary"><span><?php esc_html_e( 'موجودی قابل استفاده', 'ariunetclub' ); ?></span><strong id="ariunetclub-balance-title"><?php echo esc_html( Money::format( (float) $balance['available'] ) ); ?></strong></div><dl class="ariunetclub-history-summary__details"><div><dt><?php esc_html_e( 'شماره موبایل', 'ariunetclub' ); ?></dt><dd><?php echo esc_html( null === $phone ? '—' : Money::to_persian_digits( '0' . $phone ) ); ?></dd></div><div><dt><?php esc_html_e( 'موجودی رزروشده', 'ariunetclub' ); ?></dt><dd><?php echo esc_html( Money::format( (float) $balance['held'] ) ); ?></dd></div></dl></section>
		<section class="ariunetclub-panel ariunetclub-history-adjust" aria-labelledby="ariunetclub-adjust-title"><div><h2 id="ariunetclub-adjust-title"><?php esc_html_e( 'تنظیم دستی', 'ariunetclub' ); ?></h2><p><?php esc_html_e( 'در صورت نیاز، اعتبار این مشتری را با ثبت دلیل تغییر دهید.', 'ariunetclub' ); ?></p></div><button class="button button-primary" type="button" data-ariunetclub-open-adjust><?php esc_html_e( 'تنظیم دستی موجودی', 'ariunetclub' ); ?></button></section><dialog class="ariunetclub-dialog" data-ariunetclub-adjust-dialog aria-labelledby="ariunetclub-adjust-dialog-title"><form method="dialog" class="ariunetclub-dialog__card" data-ariunetclub-adjust-form><header class="ariunetclub-dialog__header"><div><span class="ariunetclub-admin-kicker">ARIUNETCLUB</span><h2 id="ariunetclub-adjust-dialog-title"><?php esc_html_e( 'تنظیم دستی موجودی', 'ariunetclub' ); ?></h2><p><?php esc_html_e( 'مبلغ مثبت اعتبار و مبلغ منفی کسر می‌کند.', 'ariunetclub' ); ?></p></div><button class="ariunetclub-dialog__close" type="button" data-ariunetclub-close-adjust aria-label="<?php esc_attr_e( 'بستن', 'ariunetclub' ); ?>">×</button></header><input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>" /><?php wp_nonce_field( 'ariunetclub_adjust_balance' ); ?><p><label for="ariunetclub-adjust-amount"><?php esc_html_e( 'مبلغ تغییر', 'ariunetclub' ); ?></label><input id="ariunetclub-adjust-amount" name="amount" type="number" step="any" required /></p><p><label for="ariunetclub-adjust-reason"><?php esc_html_e( 'دلیل', 'ariunetclub' ); ?></label><textarea id="ariunetclub-adjust-reason" name="reason" required></textarea></p><footer class="ariunetclub-dialog__actions"><button class="button ariunetclub-button-secondary" type="button" data-ariunetclub-close-adjust><?php esc_html_e( 'انصراف', 'ariunetclub' ); ?></button><button class="button button-primary" type="submit" data-ariunetclub-save-adjust><?php esc_html_e( 'ثبت تغییر', 'ariunetclub' ); ?></button></footer></form></dialog><div class="ariunetclub-toast" data-ariunetclub-toast role="status" aria-live="polite" hidden></div>
		<section class="ariunetclub-panel ariunetclub-history-transactions" aria-labelledby="ariunetclub-history-title"><div class="ariunetclub-section-heading"><div><h2 id="ariunetclub-history-title"><?php esc_html_e( 'تاریخچه تراکنش‌ها', 'ariunetclub' ); ?></h2><p><?php esc_html_e( 'تمام تغییرهای ثبت‌شده برای این کیف پول.', 'ariunetclub' ); ?></p></div><span class="ariunetclub-history-count"><?php echo esc_html( Money::to_persian_digits( (string) $history['total'] ) ); ?></span></div><div class="ariunetclub-table-scroll"><table class="ariunetclub-table"><thead><tr><th><?php esc_html_e( 'تاریخ', 'ariunetclub' ); ?></th><th><?php esc_html_e( 'نوع', 'ariunetclub' ); ?></th><th><?php esc_html_e( 'تغییر', 'ariunetclub' ); ?></th><th><?php esc_html_e( 'مانده کل', 'ariunetclub' ); ?></th><th><?php esc_html_e( 'دلیل', 'ariunetclub' ); ?></th></tr></thead><tbody>
		<?php
		foreach ( $history['items'] as $entry ) :
			?>
			<tr><td><?php echo esc_html( JalaliDate::format( (string) $entry['created_at'] ) ); ?></td><td><?php echo esc_html( $this->entry_label( (string) $entry['entry_type'] ) ); ?></td><td class="ariunetclub-balance"><?php echo esc_html( Money::format( (float) $entry['amount'] ) ); ?></td><td class="ariunetclub-balance"><?php echo esc_html( Money::format( (float) $entry['balance_after'] ) ); ?></td><td><?php echo esc_html( (string) $entry['note'] ); ?></td></tr><?php endforeach; ?></tbody></table></div><?php $this->pagination( $page, $history['total'], 'ariunetclub-wallet-history', array( 'user_id' => $user_id ) ); ?></section>
		<?php
		$this->shell_close();
	}
	public function adjust_balance(): void {
		$this->guard();
		check_admin_referer( 'ariunetclub_adjust_balance' );
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$reason  = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		$amount  = isset( $_POST['amount'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : 0.0;
		if ( $user_id <= 0 || '' === $reason || 0.0 === $amount ) {
			wp_die( esc_html__( 'مبلغ و دلیل معتبر الزامی هستند.', 'ariunetclub' ) ); }
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
					'page'               => 'ariunetclub-wallet-history',
					'user_id'            => $user_id,
					'ariunetclub_notice' => 'adjusted',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
	public function adjust_balance_ajax(): void {
		$this->guard();
		check_ajax_referer( 'ariunetclub_adjust_balance', 'nonce' );
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$reason  = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		$amount  = isset( $_POST['amount'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : 0.0;
		if ( $user_id <= 0 || '' === $reason || 0.0 === $amount ) {
			wp_send_json_error( array( 'message' => __( 'مبلغ و دلیل معتبر الزامی هستند.', 'ariunetclub' ) ), 400 );
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
			wp_send_json_success( array( 'message' => __( 'موجودی با موفقیت تغییر کرد.', 'ariunetclub' ) ) );
		} catch ( \Throwable $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 400 );
		}
	}
	private function guard(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'شما اجازهٔ دسترسی به این صفحه را ندارید.', 'ariunetclub' ) ); } }
	private function shell_open( string $title, string $description, string $back_url = '' ): void {

		?>
	<div class="wrap ariunetclub-scope" dir="rtl"><div class="ariunetclub-admin-shell"><header class="ariunetclub-admin-header">
		<?php
		if ( '' !== $back_url ) :
			?>
		<nav class="ariunetclub-history-breadcrumb" aria-label="<?php esc_attr_e( 'ناوبری کیف پول', 'ariunetclub' ); ?>"><a href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'بازگشت به کیف پول‌ها', 'ariunetclub' ); ?></a></nav><?php endif; ?><span class="ariunetclub-admin-kicker">ARIUNETCLUB</span><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $description ); ?></p></header>
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
	<nav class="ariunetclub-pagination" aria-label="<?php esc_attr_e( 'صفحه‌بندی', 'ariunetclub' ); ?>">
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
			'credit'  => __( 'اعتبار', 'ariunetclub' ),
			'debit'   => __( 'کسر', 'ariunetclub' ),
			'hold'    => __( 'رزرو', 'ariunetclub' ),
			'capture' => __( 'ثبت مصرف', 'ariunetclub' ),
			'release' => __( 'آزادسازی', 'ariunetclub' ),
			'adjust'  => __( 'تنظیم دستی', 'ariunetclub' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type; }
}
