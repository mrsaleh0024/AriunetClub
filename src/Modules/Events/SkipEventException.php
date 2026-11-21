<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Events;

/**
 * Thrown by event handlers when an event is intentionally not actionable.
 * The processor records the event as skipped instead of failed.
 */
class SkipEventException extends \RuntimeException {
	/** @var string */
	private $reason;

	/** @return string */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Persian-readable skip reason for the admin log (S11: no internals).
	 *
	 * @param string $reason Machine reason code.
	 */
	public static function because( string $reason ): self {
		$messages          = array(
			'guest_user'        => 'کاربر مهمان کیف پول باشگاه ندارد.',
			'guest_order'       => 'سفارش مهمان کیف پول باشگاه ندارد.',
			'sub_order_ignored' => 'زیرسفارش نادیده گرفته شد (کش‌بک روی سفارش اصلی محاسبه می‌شود).',
		);
		$exception         = new self( $messages[ $reason ] ?? $reason );
		$exception->reason = $reason;
		return $exception;
	}
}
