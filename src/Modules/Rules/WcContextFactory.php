<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Rules;

use AriunetClub\Core\Settings;
use AriunetClub\Modules\Events\SkipEventException;

/**
 * Builds RuleContext objects from live WooCommerce data.
 * Conditions are evaluated against the order as it exists at processing time.
 */
class WcContextFactory implements ContextFactoryInterface {
		/** @var Settings */
		private $settings;

	public function __construct( Settings $settings ) {
			$this->settings = $settings;
	}

		/** @param array<string,mixed> $event @return RuleContext|null @throws SkipEventException */
	public function from_event( array $event ): ?RuleContext {
			$type    = (string) $event['type'];
			$user_id = (int) ( $event['user_id'] ?? 0 );

		if ( 0 === strpos( $type, 'order.' ) ) {
				return $this->from_order_event( $event, $type, $user_id );
		}

		if ( 0 === $user_id ) {
				throw SkipEventException::because( 'guest_user' );
		}
			$context             = new RuleContext( $type, $user_id );
			$context->user_roles = $this->roles( $user_id );
			return $context;
	}

		/** @param array<string,mixed> $event @return RuleContext @throws SkipEventException */
	private function from_order_event( array $event, string $type, int $user_id ): RuleContext {
			$order_id = (int) ( $event['object_id'] ?? 0 );
			$order    = 0 !== $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
				throw new \RuntimeException( 'Order for event not found.' );
		}
		if ( $order->get_parent_id() > 0 ) {
				throw SkipEventException::because( 'sub_order_ignored' );
		}
		if ( 0 === $user_id ) {
				$user_id = (int) $order->get_customer_id();
		}
		if ( 0 === $user_id ) {
				throw SkipEventException::because( 'guest_order' );
		}

			$context                 = new RuleContext( $type, $user_id );
			$context->user_roles     = $this->roles( $user_id );
			$context->base_amount    = CashbackBase::from_order( $order, $this->settings );
			$context->payment_method = (string) $order->get_payment_method();

			$product_ids  = array();
			$category_ids = array();
		foreach ( $order->get_items() as $item ) {
				$product_id = $item instanceof \WC_Order_Item_Product ? (int) $item->get_product_id() : 0;
			if ( $product_id > 0 ) {
					$product_ids[] = $product_id;
				foreach ( $this->product_categories( $product_id ) as $category_id ) {
					$category_ids[] = $category_id;
				}
			}
		}
			$context->product_ids  = array_values( array_unique( $product_ids ) );
			$context->category_ids = array_values( array_unique( $category_ids ) );
			return $context;
	}

		/**
		 * @return string[]
		 */
	private function roles( int $user_id ): array {
			$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->roles ) || ! is_array( $user->roles ) ) {
				return array();
		}
			return array_values( array_map( 'strval', $user->roles ) );
	}

		/**
		 * @return int[]
		 */
	private function product_categories( int $product_id ): array {
			$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				return array();
		}
			return array_values( array_filter( array_map( 'intval', $terms ) ) );
	}
}
