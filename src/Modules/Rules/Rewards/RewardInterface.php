<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Rules\Rewards;

use AriunetClub\Modules\Rules\RuleContext;

/**
 * Computes one reward from the rule config. Null means "not computable"
 * (e.g. a percentage reward on an event without an order).
 */
interface RewardInterface {
	/**
	 * Machine id used in the rule reward JSON, e.g. percent.
	 */
	public function type(): string;

	/**
	 * @param RuleContext         $context Evaluation context.
	 * @param array<string,mixed> $config  Reward config decoded from the rule.
	 * @return float|null Reward amount before the public amount filter.
	 */
	public function compute( RuleContext $context, array $config ): ?float;
}
