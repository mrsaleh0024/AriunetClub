<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Rules;

use LClub\Core\Money;
use LClub\Core\Settings;

/**
 * Single source of truth for the cashback base amount:
 * order total minus shipping, minus tax when the setting demands.
 */
final class CashbackBase {
	public static function from_order( \WC_Order $order, Settings $settings ): float {
		$base   = (float) $order->get_total() - (float) $order->get_shipping_total();
		$config = $settings->get();
		if ( ! empty( $config['cashback_base_exclude_tax'] ) ) {
			$base -= (float) $order->get_total_tax();
		}
		return max( 0.0, Money::round( $base ) );
	}
}
