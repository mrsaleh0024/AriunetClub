<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Rules;

use AriunetClub\Core\Money;
use AriunetClub\Core\Settings;

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
