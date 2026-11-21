<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Rules;

use LClub\Modules\Rules\Conditions\ConditionInterface;
use LClub\Modules\Rules\Rewards\RewardInterface;

/**
 * Pure rule evaluation: window, conditions and reward computation.
 * No database, no WooCommerce — fully unit-testable.
 */
class RuleEngine {
	/** @var array<string,ConditionInterface> */
	private $conditions;

	/** @var array<string,RewardInterface> */
	private $rewards;

	/**
	 * @param array<string,ConditionInterface> $conditions Condition map keyed by id.
	 * @param array<string,RewardInterface>    $rewards    Reward map keyed by type.
	 */
	public function __construct( array $conditions, array $rewards ) {
		$this->conditions = $conditions;
		$this->rewards    = $rewards;
	}

	/**
	 * Evaluates rules in the given order (priority ASC expected).
	 * A matched rule marked exclusive stops the evaluation after its reward.
	 *
	 * @param array<int,array<string,mixed>> $rules   Active rules for the event type.
	 * @param RuleContext                    $context Evaluation context.
	 * @param int                            $now_gmt Current UTC timestamp.
	 * @return array<int,array{rule:array<string,mixed>,amount:float}>
	 */
	public function evaluate( array $rules, RuleContext $context, int $now_gmt ): array {
		$results = array();
		foreach ( $rules as $rule ) {
			if ( ! $this->in_window( $rule, $now_gmt ) ) {
				continue;
			}
			$reward = $this->reward( $rule, $context );
			if ( null === $reward ) {
				continue;
			}
			$results[] = array(
				'rule'   => $rule,
				'amount' => $reward,
			);
			if ( ! empty( $rule['exclusive'] ) ) {
				break;
			}
		}
		return $results;
	}

	/**
	 * @param array<string,mixed> $rule    Rule row.
	 * @param int                 $now_gmt Current UTC timestamp.
	 * @return bool
	 */
	public function in_window( array $rule, int $now_gmt ): bool {
		$starts = isset( $rule['starts_at'] ) && is_string( $rule['starts_at'] ) && '' !== $rule['starts_at'] ? self::utc( $rule['starts_at'] ) : null;
		$ends   = isset( $rule['ends_at'] ) && is_string( $rule['ends_at'] ) && '' !== $rule['ends_at'] ? self::utc( $rule['ends_at'] ) : null;
		if ( null !== $starts && $now_gmt < $starts ) {
			return false;
		}
		if ( null !== $ends && $now_gmt > $ends + 59 ) {
			return false;
		}
		return true;
	}

	/**
	 * Runs every condition; reward is computed only when all pass.
	 *
	 * @param array<string,mixed> $rule Rule row.
	 * @return float|null
	 */
	public function reward( array $rule, RuleContext $context ): ?float {
		$conditions = self::json( $rule['conditions'] ?? null );
		$reward     = self::json( $rule['reward'] ?? null );
		if ( ! is_array( $reward ) || ! isset( $reward['type'] ) || ! is_string( $reward['type'] ) ) {
			return null;
		}
		foreach ( $this->conditions as $condition ) {
			if ( ! $condition->check( $context, $conditions ) ) {
				return null;
			}
		}
		if ( ! isset( $this->rewards[ $reward['type'] ] ) ) {
			return null;
		}
		return $this->rewards[ $reward['type'] ]->compute( $context, $reward );
	}

	/**
	 * @param mixed $raw JSON string or already-decoded value.
	 * @return array<string,mixed>
	 */
	public static function json( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true, 16 );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Parses a UTC datetime column with an explicit UTC timezone.
	 *
	 * @return int|null
	 */
	public static function utc( string $datetime ): ?int {
		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $datetime, new \DateTimeZone( 'UTC' ) );
		if ( false === $parsed ) {
			return null;
		}
		return $parsed->getTimestamp();
	}
}
