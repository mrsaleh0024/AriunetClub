<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Rules;

use LClub\Core\Settings;

/**
 * WooCommerce-backed order lookup (works with HPOS and legacy storage).
 */
class WcOrderLookup implements OrderLookupInterface {
		/** @var Settings */
		private $settings;

	public function __construct( Settings $settings ) {
			$this->settings = $settings;
	}

		/** @return OrderSnapshot|null */
	public function find( int $order_id ): ?OrderSnapshot {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
				return null;
		}
			$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
				return null;
		}
			$snapshot              = new OrderSnapshot();
			$snapshot->id          = (int) $order->get_id();
			$snapshot->user_id     = (int) $order->get_customer_id();
			$snapshot->parent_id   = (int) $order->get_parent_id();
			$snapshot->base_amount = $this->base_amount( $order );
			$created               = $order->get_date_created();
			$snapshot->created_gmt = $created instanceof \WC_DateTime ? gmdate( 'Y-m-d H:i:s', $created->getTimestamp() ) : '';
			return $snapshot;
	}

		/**
		 * Cashback base: order total minus shipping, minus tax when the setting demands.
		 */
	private function base_amount( \WC_Order $order ): float {
			return CashbackBase::from_order( $order, $this->settings );
	}
}
