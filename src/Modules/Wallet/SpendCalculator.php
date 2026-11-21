<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Wallet;

use AriunetClub\Core\Money;
use AriunetClub\Core\Settings;

/**
 * Phase 3 helper: computes how much wallet balance may be spent on one cart.
 * Pure math on top of the wallet settings; no I/O and no state, so the
 * checkout, the validation and the gateway all ask the same calculator.
 */
class SpendCalculator {

	/** @var Settings */
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Maximum wallet spend allowed for the current cart.
	 *
	 * The percentage cap uses items + tax as its base (shipping excluded, the
	 * same convention as the phase 2 cashback base). The spend never exceeds
	 * the amount due, and when full coverage is disabled one price step stays
	 * payable through a gateway.
	 *
	 * @param float $available Available wallet balance.
	 * @param float $items_base Cart items total including tax, excluding shipping.
	 * @param float $cart_total Amount due for the cart (items + shipping).
	 * @return float Rounded spend amount, zero when the wallet cannot be used.
	 */
	public function max_spend( float $available, float $items_base, float $cart_total ): float {
		$config     = $this->settings->get();
		$raw_percent = isset( $config['wallet_max_percent'] ) ? $config['wallet_max_percent'] : null;
		$raw_min     = isset( $config['wallet_min_order'] ) ? $config['wallet_min_order'] : null;
		$percent     = is_numeric( $raw_percent ) ? (float) $raw_percent : 100.0;
		$min_order   = is_numeric( $raw_min ) ? (float) $raw_min : 0.0;
		$coverage   = ! empty( $config['wallet_full_coverage'] );

		$available  = max( 0.0, Money::round( $available ) );
		$items_base = max( 0.0, Money::round( $items_base ) );
		$cart_total = max( 0.0, Money::round( $cart_total ) );

		if ( $available <= 0.0 || $cart_total <= 0.0 || $items_base <= 0.0 || $percent <= 0.0 ) {
			return 0.0;
		}

		if ( $cart_total < $min_order ) {
			return 0.0;
		}

		$percent = min( 100.0, max( 0.0, $percent ) );
		$cap     = Money::round( $items_base * $percent / 100.0 );
		$spend   = min( $available, $cap, $cart_total );

		if ( ! $coverage && $spend + 0.001 >= $cart_total ) {
			// Keep at least one price step payable through a gateway.
			$step  = Money::decimals() > 0 ? pow( 10, -1 * Money::decimals() ) : 1.0;
			$spend = Money::round( $cart_total - $step );
		}

		return max( 0.0, $spend );
	}
}
