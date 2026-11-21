<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Wallet;

use LClub\Core\Logger;
use LClub\Core\Money;
use LClub\Core\Settings;
use LClub\Modules\Ledger\InsufficientBalanceException;
use LClub\Modules\Ledger\LedgerGateway;
use LClub\Modules\Ledger\LedgerService;

/**
 * Phase 3 wallet lifecycle: hold on order creation, capture when the order is
 * paid (processing/completed), release on cancel/failure or pending timeout,
 * and proportional wallet refund when an order refund happens.
 *
 * Every write goes through the idempotent LedgerService, so a repeated
 * status flip can never spend twice (acceptance criterion of phase 3).
 * This listener is synchronous on purpose: money movement must never sit
 * in the async event queue, only earning rules do.
 */
class HoldManager {

	const EXPIRY_ACTION = 'lclub_release_expired_hold';
	const REFUND_PREFIX = 'wallet:refund:order:';

	/** @var LedgerService */
	private $ledger;

	/** @var LedgerGateway */
	private $gateway;

	/** @var Settings */
	private $settings;

	/** @var Logger */
	private $logger;

	public function __construct( LedgerService $ledger, LedgerGateway $gateway, Settings $settings, Logger $logger ) {
		$this->ledger   = $ledger;
		$this->gateway  = $gateway;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 3 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refunded' ), 10, 2 );
		add_action( self::EXPIRY_ACTION, array( $this, 'on_expired' ), 10, 1 );
	}

	public static function hold_key( int $order_id ): string {
		return 'wallet:hold:order:' . $order_id;
	}

	public static function retry_hold_key( int $order_id ): string {
		return 'wallet:hold:order:' . $order_id . ':retry';
	}

	public static function capture_key( int $order_id ): string {
		return 'wallet:capture:order:' . $order_id;
	}

	public static function release_key( int $order_id ): string {
		return 'wallet:release:order:' . $order_id;
	}

	public static function refund_key( int $order_id, int $refund_id ): string {
		return self::REFUND_PREFIX . $order_id . ':refund:' . $refund_id;
	}

	/**
	 * Order status transitions drive the hold state machine.
	 *
	 * @param int|string $order_id Order id.
	 * @param string    $from     Previous status.
	 * @param string    $to       New status.
	 */
	public function on_status_changed( $order_id, string $from, string $to ): void {
		$order = $this->order( (int) $order_id );
		if ( null === $order ) {
			return;
		}
		$state   = OrderMeta::state( $order );
		$user_id = (int) $order->get_customer_id();
		$amount  = OrderMeta::amount( $order );

		if ( ( 'processing' === $to || 'completed' === $to ) && OrderMeta::STATE_HELD === $state ) {
			$this->capture( $order, $user_id, $amount );
			return;
		}

		if ( ( 'processing' === $to || 'completed' === $to ) && $amount > 0.0 && ( OrderMeta::STATE_RELEASED === $state || '' === $state ) ) {
			$this->recapture_released( $order, $user_id, $amount );
			return;
		}

		if ( ( 'cancelled' === $to || 'failed' === $to || 'refunded' === $to ) && OrderMeta::STATE_HELD === $state ) {
			$this->release( $order, $user_id, $amount );
		}
	}

	/**
	 * A refund on a captured wallet order returns the wallet share proportionally.
	 *
	 * @param int|string $order_id  Order id.
	 * @param int|string $refund_id Refund (WC_Order_Refund) id.
	 */
	public function on_refunded( $order_id, $refund_id ): void {
		$order = $this->order( (int) $order_id );
		if ( null === $order || OrderMeta::STATE_CAPTURED !== OrderMeta::state( $order ) ) {
			return;
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$refund        = wc_get_order( (int) $refund_id );
		$refund_amount = $refund instanceof \WC_Order_Refund ? (float) $refund->get_amount() : 0.0;
		if ( $refund_amount <= 0.0 ) {
			return;
		}

		$wallet_amount = OrderMeta::amount( $order );
		$total         = OrderMeta::order_total( $order );
		$returned      = $this->returned_for( $order->get_id() );
		$remaining     = max( 0.0, Money::round( $wallet_amount - $returned ) );
		if ( $wallet_amount <= 0.0 || $remaining <= 0.0 ) {
			return;
		}

		$share = $total > 0.0 ? $wallet_amount * $refund_amount / $total : min( $refund_amount, $remaining );
		$share = Money::round( min( $share, $remaining ) );
		if ( $share <= 0.0 ) {
			return;
		}

		try {
			$this->ledger->adjust(
				$user_id,
				$share,
				self::refund_key( $order->get_id(), (int) $refund_id ),
				array(
					'ref_type' => 'order',
					'ref_id'   => $order->get_id(),
					'note'     => __( 'برگشت خرج کیف پول — مستردشدهٔ سفارش', 'lclub' ),
				)
			);
			$order->add_order_note( __( 'برگشت خرج کیف پول به کیف پول اضافه شد: ', 'lclub' ) . Money::format( $share ) );
			$this->logger->info( 'Wallet refund share returned.', array( 'order_id' => $order->get_id(), 'share' => $share ) );
		} catch ( \Throwable $error ) {
			$this->logger->error( 'Wallet refund share failed.', array( 'order_id' => $order->get_id(), 'error' => $error->getMessage() ) );
			$order->add_order_note( __( 'خطا در برگشت خرج کیف پول؛ از تاریخچهٔ کیف پول بررسی کنید.', 'lclub' ) );
		}
	}

	/**
	 * Scheduled pending-order timeout: release the hold when the payment window has passed.
	 *
	 * @param int|string $order_id Order id.
	 */
	public function on_expired( $order_id ): void {
		$order_id = (int) $order_id;
		$order    = $this->order( $order_id );
		if ( null === $order || OrderMeta::STATE_HELD !== OrderMeta::state( $order ) || 'pending' !== $order->get_status() ) {
			$this->unschedule( $order_id );
			return;
		}

		$held_at = OrderMeta::held_at( $order );
		$due     = $held_at + $this->timeout_seconds();
		if ( 0 === $held_at ) {
			$due = time();
		}
		if ( time() < $due ) {
			$this->schedule_at( $order_id, $due );
			return;
		}

		$user_id = (int) $order->get_customer_id();
		$amount  = OrderMeta::amount( $order );
		$this->release( $order, $user_id, $amount );
		$order->add_order_note( __( 'مهلت پرداخت سفارش منقضی و رزرو کیف پول آزاد شد.', 'lclub' ) );
		$this->logger->info( 'Expired wallet hold released.', array( 'order_id' => $order_id ) );
	}

	/**
	 * Schedules the pending-order expiry for one order. Action Scheduler is
	 * primary, WP-Cron is the fallback (the same convention as the events module).
	 *
	 * @param int $order_id Order id.
	 */
	public function schedule_expiry( int $order_id ): void {
		$this->schedule_at( $order_id, time() + $this->timeout_seconds() );
	}

	/**
	 * @return float Sum of wallet refund entries already returned for the order.
	 */
	public function returned_for( int $order_id ): float {
		$entries = $this->gateway->entries_for_reference( 'order', $order_id, array( 'adjust' ) );
		$prefix  = self::REFUND_PREFIX . $order_id . ':refund:';
		$sum     = 0.0;
		foreach ( $entries as $entry ) {
			$key = isset( $entry['idempotency_key'] ) && is_string( $entry['idempotency_key'] ) ? $entry['idempotency_key'] : '';
			if ( 0 === strpos( $key, $prefix ) ) {
				$entry_amount = isset( $entry['amount'] ) ? $entry['amount'] : null;
				$sum         += is_numeric( $entry_amount ) ? max( 0.0, (float) $entry_amount ) : 0.0;
			}
		}
		return $sum;
	}

	/**
	 * @param \WC_Order $order Order object.
	 * @param int       $user_id Customer id.
	 * @param float     $amount Wallet amount held on the order.
	 */
	private function capture( \WC_Order $order, int $user_id, float $amount ): void {
		if ( $user_id <= 0 || $amount <= 0.0 ) {
			$this->logger->error( 'Wallet capture skipped for invalid input.', array( 'order_id' => $order->get_id() ) );
			return;
		}
		try {
			$this->ledger->capture(
				$user_id,
				$amount,
				self::capture_key( $order->get_id() ),
				array(
					'ref_type' => 'order',
					'ref_id'   => $order->get_id(),
					'note'     => __( 'مصرف کیف پول برای سفارش', 'lclub' ),
				)
			);
			OrderMeta::mark( $order, OrderMeta::STATE_CAPTURED );
			$order->add_order_note( __( 'مصرف کیف پول قطعی شد: ', 'lclub' ) . Money::format( $amount ) );
			$this->unschedule( $order->get_id() );
			$this->logger->info( 'Wallet spend captured.', array( 'order_id' => $order->get_id(), 'amount' => $amount ) );
		} catch ( \Throwable $error ) {
			$this->logger->error( 'Wallet capture failed.', array( 'order_id' => $order->get_id(), 'error' => $error->getMessage() ) );
			$order->add_order_note( __( 'خطا در قطعی‌سازی رزرو کیف پول؛ لطفاً از تاریخچهٔ کیف پول بررسی کنید.', 'lclub' ) );
		}
	}

	/**
	 * Recovery paths: an order reaches processing/completed without an intact
	 * hold — either the hold was released before a retry payment, or hold
	 * placement failed at checkout while the fee discount stayed on the
	 * order. Re-hold then capture; when the balance is no longer enough the
	 * spend is recorded as debt so the ledger stays whole.
	 *
	 * @param \WC_Order $order Order object.
	 * @param int       $user_id Customer id.
	 * @param float     $amount Wallet amount originally requested.
	 */
	private function recapture_released( \WC_Order $order, int $user_id, float $amount ): void {
		try {
			$this->ledger->hold(
				$user_id,
				$amount,
				self::retry_hold_key( $order->get_id() ),
				array(
					'ref_type' => 'order',
					'ref_id'   => $order->get_id(),
					'note'     => __( 'رزرو مجدد کیف پول برای پرداخت مجدد سفارش', 'lclub' ),
				)
			);
			$this->ledger->capture(
				$user_id,
				$amount,
				self::capture_key( $order->get_id() ),
				array(
					'ref_type' => 'order',
					'ref_id'   => $order->get_id(),
					'note'     => __( 'مصرف کیف پول برای سفارش', 'lclub' ),
				)
			);
			OrderMeta::mark( $order, OrderMeta::STATE_CAPTURED );
			$order->add_order_note( __( 'مصرف کیف پول پس از پرداخت مجدد سفارش قطعی شد: ', 'lclub' ) . Money::format( $amount ) );
			$this->logger->info( 'Wallet re-held and captured after retry payment.', array( 'order_id' => $order->get_id(), 'amount' => $amount ) );
		} catch ( InsufficientBalanceException $exception ) {
			try {
				$this->ledger->adjust(
					$user_id,
					-1 * $amount,
					self::capture_key( $order->get_id() ) . ':debt',
					array(
						'ref_type' => 'order',
						'ref_id'   => $order->get_id(),
						'note'     => __( 'بدهی کیف پول — پرداخت مجدد سفارش', 'lclub' ),
					)
				);
				OrderMeta::mark( $order, OrderMeta::STATE_CAPTURED );
				$order->add_order_note( __( 'موجودی کیف پول کافی نبود؛ مبلغ مصرف به‌صورت بدهی ثبت شد: ', 'lclub' ) . Money::format( $amount ) );
				$this->logger->error( 'Wallet retry capture created debt.', array( 'order_id' => $order->get_id(), 'amount' => $amount ) );
			} catch ( \Throwable $error ) {
				$this->logger->error( 'Wallet retry capture failed entirely.', array( 'order_id' => $order->get_id(), 'error' => $error->getMessage() ) );
				$order->add_order_note( __( 'خطا در ثبت مصرف کیف پول پس از پرداخت مجدد؛ از تاریخچهٔ کیف پول بررسی کنید.', 'lclub' ) );
			}
		} catch ( \Throwable $error ) {
			$this->logger->error( 'Wallet retry capture failed.', array( 'order_id' => $order->get_id(), 'error' => $error->getMessage() ) );
			$order->add_order_note( __( 'خطا در ثبت مصرف کیف پول پس از پرداخت مجدد؛ از تاریخچهٔ کیف پول بررسی کنید.', 'lclub' ) );
		}
	}

	/**
	 * @param \WC_Order $order Order object.
	 * @param int       $user_id Customer id.
	 * @param float     $amount Wallet amount held on the order.
	 */
	private function release( \WC_Order $order, int $user_id, float $amount ): void {
		if ( $user_id <= 0 || $amount <= 0.0 ) {
			$this->unschedule( $order->get_id() );
			return;
		}
		try {
			$this->ledger->release(
				$user_id,
				$amount,
				self::release_key( $order->get_id() ),
				array(
					'ref_type' => 'order',
					'ref_id'   => $order->get_id(),
					'note'     => __( 'آزادسازی رزرو کیف پول', 'lclub' ),
				)
			);
			OrderMeta::mark( $order, OrderMeta::STATE_RELEASED );
			$order->add_order_note( __( 'رزرو کیف پول آزاد شد: ', 'lclub' ) . Money::format( $amount ) );
			$this->unschedule( $order->get_id() );
			$this->logger->info( 'Wallet hold released.', array( 'order_id' => $order->get_id(), 'amount' => $amount ) );
		} catch ( \Throwable $error ) {
			$this->logger->error( 'Wallet release failed.', array( 'order_id' => $order->get_id(), 'error' => $error->getMessage() ) );
			$order->add_order_note( __( 'خطا در آزادسازی رزرو کیف پول؛ از تاریخچهٔ کیف پول بررسی کنید.', 'lclub' ) );
		}
	}

	/**
	 * @return int Expiry window in seconds from the wallet settings.
	 */
	private function timeout_seconds(): int {
		$config = $this->settings->get();
		$raw_hours = isset( $config['wallet_hold_timeout'] ) ? $config['wallet_hold_timeout'] : null;
		$hours     = is_numeric( $raw_hours ) ? (int) $raw_hours : 48;
		$hours  = min( 720, max( 1, $hours ) );
		return $hours * 3600;
	}

	/**
	 * @param int $when Unix timestamp when the expiry should fire.
	 */
	private function schedule_at( int $order_id, int $when ): void {
		$when = max( time() + 60, $when );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $when, self::EXPIRY_ACTION, array( $order_id ), 'lclub' );
			return;
		}
		wp_schedule_single_event( $when, self::EXPIRY_ACTION, array( $order_id ) );
	}

	private function unschedule( int $order_id ): void {
		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( self::EXPIRY_ACTION, array( $order_id ), 'lclub' );
			return;
		}
		$when = wp_next_scheduled( self::EXPIRY_ACTION, array( $order_id ) );
		if ( false !== $when ) {
			wp_unschedule_event( $when, self::EXPIRY_ACTION, array( $order_id ) );
		}
	}

	/**
	 * @return \WC_Order|null
	 */
	private function order( int $order_id ) {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		return $order instanceof \WC_Order ? $order : null;
	}
}
