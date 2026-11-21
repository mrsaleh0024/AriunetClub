<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Ledger;

interface LedgerGateway {
	/** @param array<string,mixed> $entry @return array<string,mixed> */
	public function record( array $entry ): array;
	/** @return array<string,mixed> */
	public function balance( int $user_id, string $asset = 'cashback' ): array;
	/** @return array{items:array<int,array<string,mixed>>,total:int} */
	public function balances( array $user_ids = array(), int $page = 1, int $per_page = 20 ): array;
	/** @return array{items:array<int,array<string,mixed>>,total:int} */
	public function history( int $user_id, string $asset = 'cashback', int $page = 1, int $per_page = 20 ): array;
	/**
	 * Ledger rows attached to one reference, newest last. Used by phase 2
	 * reversals and limit counting.
	 *
	 * @param string   $ref_type    Reference family (order, user, product_review).
	 * @param int      $ref_id      Reference id.
	 * @param string[] $entry_types Optional entry-type filter.
	 * @return array<int,array<string,mixed>>
	 */
	public function entries_for_reference( string $ref_type, int $ref_id, array $entry_types = array() ): array;
	/**
	 * Count entries attached to a rule; used by earning limits.
	 *
	 * @param int         $rule_id    Rule id.
	 * @param int|null    $user_id    Optional customer filter.
	 * @param string|null $since_gmt  Optional UTC datetime lower bound.
	 * @return int
	 */
	public function count_for_rule( int $rule_id, ?int $user_id = null, ?string $since_gmt = null ): int;
	/**
	 * Sum of positive amounts attached to a rule; used by total amount caps.
	 *
	 * @param int $rule_id Rule id.
	 * @return float
	 */
	public function sum_for_rule( int $rule_id ): float;
}
