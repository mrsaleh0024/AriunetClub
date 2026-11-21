<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Rules;

/**
 * Normalized evaluation context for one event.
 * Built from live WooCommerce data at processing time (no snapshots).
 */
final class RuleContext {
		/** @var string */
		public $event_type;

		/** @var int */
		public $user_id;

		/** @var string[] */
		public $user_roles = array();

		/** @var float|null Cashback base amount; null when the event has no order. */
		public $base_amount = null;

		/** @var int[]|null */
		public $product_ids = null;

		/** @var int[]|null */
		public $category_ids = null;

		/** @var string|null */
		public $payment_method = null;

		/** @var bool|null */
		public $first_order = null;

		/** @param string $event_type Event type this context belongs to. */
	public function __construct( string $event_type, int $user_id ) {
			$this->event_type = $event_type;
			$this->user_id    = $user_id;
	}
}
