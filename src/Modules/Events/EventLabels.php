<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Events;

/**
 * Persian display labels for event types, statuses and object families.
 */
class EventLabels {
	/** @var string[] */
	private const STATUSES = array(
		'processing' => 'در حال پردازش',
		'completed'  => 'تکمیل‌شده',
		'cancelled'  => 'لغوشده',
		'refunded'   => 'مستردشده',
		'failed'     => 'ناموفق',
	);

	/** @var string[] */
	private const QUEUE_STATUSES = array(
		'pending' => 'در انتظار',
		'done'    => 'پردازش‌شده',
		'failed'  => 'ناموفق',
		'skipped' => 'نادیده‌گرفته‌شده',
	);

	/** @var string[] */
	private const OBJECTS = array(
		'order'          => 'سفارش',
		'user'           => 'کاربر',
		'product_review' => 'دیدگاه محصول',
	);

	/**
	 * @return string[]
	 */
	public static function order_statuses(): array {
		return self::STATUSES;
	}

	/**
	 * @return string[]
	 */
	public static function queue_statuses(): array {
		return self::QUEUE_STATUSES;
	}

	/**
	 * @return string[]
	 */
	public static function objects(): array {
		return self::OBJECTS;
	}

	/**
	 * Human label for an event type such as order.status.completed.
	 */
	public static function type( string $type ): string {
		if ( 0 === strpos( $type, 'order.status.' ) ) {
			$status = substr( $type, strlen( 'order.status.' ) );
			$label  = self::STATUSES[ $status ] ?? $status;
			return 'سفارش → ' . $label;
		}
		if ( 'order.refunded' === $type ) {
			return 'مستردشدهٔ سفارش';
		}
		if ( 'user.registered' === $type ) {
			return 'ثبت‌نام کاربر';
		}
		if ( 'product.review.approved' === $type ) {
			return 'تأیید دیدگاه محصول';
		}
		return $type;
	}

	/**
	 * Persian label for a WooCommerce status slug (without wc- prefix).
	 */
	public static function order_status( string $status ): string {
		return self::STATUSES[ $status ] ?? $status;
	}
}
