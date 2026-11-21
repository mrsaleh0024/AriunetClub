<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Rules;

use AriunetClub\Modules\Events\SkipEventException;

/**
 * Builds a RuleContext from an event row. Implementations may read live
 * WooCommerce data; unit tests provide fakes.
 */
interface ContextFactoryInterface {
	/**
	 * @param array<string,mixed> $event Event row from the queue.
	 * @return RuleContext|null Null when the event has no order (non-order events still produce a user context).
	 * @throws SkipEventException When the event is intentionally not actionable.
	 */
	public function from_event( array $event ): ?RuleContext;
}
