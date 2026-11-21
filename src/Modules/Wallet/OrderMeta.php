<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Wallet;

use LClub\Core\Money;

/**
 * Phase 3 order meta: wallet state, spend amount and the order-total
 * snapshot used by refund math. Every read and write goes through the
 * WC_Order CRUD interface, so both HPOS and legacy storage behave the same.
 */
class OrderMeta {

	const STATE        = '_lclub_wallet_state';
	const AMOUNT       = '_lclub_wallet_amount';
	const ORDER_TOTAL  = '_lclub_wallet_order_total';
	const HELD_AT      = '_lclub_wallet_held_at';

	const STATE_HELD     = 'held';
	const STATE_CAPTURED = 'captured';
	const STATE_RELEASED = 'released';

	/**
	 * @return string Current wallet state or an empty string when unused.
	 */
	public static function state( \WC_Order $order ): string {
		$value = $order->get_meta( self::STATE, true );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @return float Wallet amount spent on the order.
	 */
	public static function amount( \WC_Order $order ): float {
		$value = $order->get_meta( self::AMOUNT, true );
		return is_scalar( $value ) ? (float) $value : 0.0;
	}

	/**
	 * @return float Order total (after the wallet fee) snapshotted at hold time.
	 */
	public static function order_total( \WC_Order $order ): float {
		$value = $order->get_meta( self::ORDER_TOTAL, true );
		return is_scalar( $value ) ? (float) $value : 0.0;
	}

	/**
	 * @return int Unix GMT timestamp of the hold, zero when missing.
	 */
	public static function held_at( \WC_Order $order ): int {
		$value = $order->get_meta( self::HELD_AT, true );
		return is_scalar( $value ) ? (int) $value : 0;
	}

	/**
	 * Stores the hold snapshot (amount, order total, timestamp) and marks the order as held.
	 */
	public static function remember_hold( \WC_Order $order, float $amount, float $order_total ): void {
		$order->update_meta_data( self::AMOUNT, Money::round( $amount ) );
		$order->update_meta_data( self::ORDER_TOTAL, Money::round( $order_total ) );
		$order->update_meta_data( self::HELD_AT, time() );
		self::mark( $order, self::STATE_HELD );
	}

	/**
	 * Moves the order to a new wallet state and persists the meta.
	 */
	public static function mark( \WC_Order $order, string $state ): void {
		$order->update_meta_data( self::STATE, $state );
		$order->save();
	}
}
