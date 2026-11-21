<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Rules;

/**
 * Immutable snapshot of order facts needed by earning and reversal logic.
 * Built from live WooCommerce data at processing time.
 */
final class OrderSnapshot {
		/** @var int */
		public $id = 0;

		/** @var int */
		public $user_id = 0;

		/** @var int */
		public $parent_id = 0;

		/** @var float Cashback base amount (order total minus shipping, minus optional tax). */
		public $base_amount = 0.0;

		/** @var string UTC datetime string (Y-m-d H:i:s) or empty when unknown. */
		public $created_gmt = '';
}
