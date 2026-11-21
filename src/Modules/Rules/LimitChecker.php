<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Rules;

use AriunetClub\Modules\Ledger\LedgerGateway;

/**
 * Usage limits for a rule: per-user rolling window, total count and total amount.
 * All counting happens on the append-only ledger (credit entries carry rule_id).
 */
class LimitChecker {
		/** @var LedgerGateway */
		private $ledger;

	public function __construct( LedgerGateway $ledger ) {
			$this->ledger = $ledger;
	}

		/**
		 * @param array<string,mixed> $rule    Rule row.
		 * @param int                 $user_id Customer id.
		 * @param int                 $now_gmt Current UTC timestamp.
		 * @return bool True when a limit is exhausted.
		 */
	public function exhausted( array $rule, int $user_id, int $now_gmt ): bool {
			$limits = RuleEngine::json( $rule['limits'] ?? null );

			$per_count = isset( $limits['per_user_count'] ) ? (int) $limits['per_user_count'] : 0;
			$per_days  = isset( $limits['per_user_days'] ) ? (int) $limits['per_user_days'] : 0;
		if ( $per_count > 0 && $per_days > 0 ) {
				$since = gmdate( 'Y-m-d H:i:s', $now_gmt - $per_days * 86400 );
				$used  = $this->ledger->count_for_rule( (int) $rule['id'], $user_id, $since );
			if ( $used >= $per_count ) {
				return true;
			}
		}

			$total_times = isset( $limits['total_times'] ) ? (int) $limits['total_times'] : 0;
		if ( $total_times > 0 && $this->ledger->count_for_rule( (int) $rule['id'], null, null ) >= $total_times ) {
				return true;
		}

			$total_amount = isset( $limits['total_amount'] ) ? (float) $limits['total_amount'] : 0.0;
		if ( $total_amount > 0.0 && $this->ledger->sum_for_rule( (int) $rule['id'] ) >= $total_amount ) {
				return true;
		}

			return false;
	}
}
