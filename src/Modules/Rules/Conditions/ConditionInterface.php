<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Rules\Conditions;

use LClub\Modules\Rules\RuleContext;

/**
 * One evaluatable rule condition. Empty/inactive config always passes.
 */
interface ConditionInterface {
	/**
	 * Machine id used in the rule conditions JSON, e.g. min_amount.
	 */
	public function id(): string;

	/**
	 * @param RuleContext         $context Evaluation context.
	 * @param array<string,mixed> $config  Condition config decoded from the rule.
	 * @return bool True when the condition passes (or is inactive).
	 */
	public function check( RuleContext $context, array $config ): bool;
}
