<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Credit;

use AriunetClub\Core\Logger;
use AriunetClub\Core\Money;
use AriunetClub\Core\Settings;

/**
 * Protective grant policy (phase 6) consulted by RewardService before every
 * rule cashback:
 *
 *  - minimum order amount: orders below it never earn (global floor, on top
 *    of any per-rule MinAmount condition);
 *  - per-order credit cap: the total rule credit of one order is capped;
 *  - per-customer monthly cap: what one customer may earn per calendar month;
 *  - monthly budget: the club-wide cost ceiling (80% warning, 100% stop).
 *
 * Manual admin adjustments bypass every rule of this class by design (they
 * are not rule grants).
 */
class CreditPolicy {

		/** @var Settings */
		private $settings;

		/** @var BudgetService */
		private $budget;

		/** @var Logger */
		private $logger;

	public function __construct( Settings $settings, BudgetService $budget, Logger $logger ) {
			$this->settings = $settings;
			$this->budget   = $budget;
			$this->logger   = $logger;
	}

		/**
		 * Reviews one candidate grant.
		 *
		 * @param float     $amount  Candidate amount (already rounded).
		 * @param int       $user_id Customer.
		 * @param float|null $order_base Order base amount (null for non-order events).
		 * @param int       $order_id Order id (0 for non-order events).
		 * @param float     $already_credited Rule credit already granted for this order.
		 * @return array{allowed:bool,amount:float,reason:string}
		 */
	public function review( float $amount, int $user_id, ?float $order_base, int $order_id, float $already_credited ): array {
			$config = $this->settings->get();

			// 1) Global minimum order amount floor.
			$min_order = isset( $config['guard_min_order_amount'] ) && is_numeric( $config['guard_min_order_amount'] ) ? (float) $config['guard_min_order_amount'] : 0.0;
		if ( $min_order > 0.0 && null !== $order_base && $order_base < $min_order - LotRepository::EPSILON ) {
				$this->log_skip( 'min_order', $user_id, $order_id, $amount );
				return array(
					'allowed' => false,
					'amount'  => 0.0,
					'reason'  => 'min_order',
				);
		}

			// 2) Per-order credit cap.
			$per_order = isset( $config['guard_max_credit_per_order'] ) && is_numeric( $config['guard_max_credit_per_order'] ) ? (float) $config['guard_max_credit_per_order'] : 0.0;
		if ( $per_order > 0.0 && $order_id > 0 ) {
				$room = Money::round( $per_order - $already_credited );
			if ( $room <= LotRepository::EPSILON ) {
					$this->log_skip( 'per_order_cap', $user_id, $order_id, $amount );
					return array(
						'allowed' => false,
						'amount'  => 0.0,
						'reason'  => 'per_order_cap',
					);
			}
				$amount = Money::round( min( $amount, $room ) );
		}

			// 3) Per-customer monthly cap.
			$monthly = isset( $config['guard_max_monthly_per_customer'] ) && is_numeric( $config['guard_max_monthly_per_customer'] ) ? (float) $config['guard_max_monthly_per_customer'] : 0.0;
		if ( $monthly > 0.0 ) {
				$window = $this->budget->month_window();
				$issued = $this->budget->issued_in_window( $window['start_gmt'], $window['end_gmt'], $user_id );
				$room   = Money::round( $monthly - $issued );
			if ( $room <= LotRepository::EPSILON ) {
					$this->log_skip( 'monthly_customer_cap', $user_id, $order_id, $amount );
					return array(
						'allowed' => false,
						'amount'  => 0.0,
						'reason'  => 'monthly_customer_cap',
					);
			}
				$amount = Money::round( min( $amount, $room ) );
		}

			// 4) Club-wide monthly budget (clamps to the remaining ceiling).
			$amount = $this->budget->clamp_grant( $amount );
		if ( $amount <= LotRepository::EPSILON ) {
				return array(
					'allowed' => false,
					'amount'  => 0.0,
					'reason'  => 'budget_blocked',
				);
		}

			return array(
				'allowed' => true,
				'amount'  => $amount,
				'reason'  => '',
			);
	}

		/**
		 * Post-grant hook: raises the 80% budget warning once per month.
		 *
		 * @return void
		 */
	public function after_grant(): void {
			$this->budget->maybe_warn();
	}

		/**
		 * @return void
		 */
	private function log_skip( string $reason, int $user_id, int $order_id, float $amount ): void {
			$this->logger->info(
				'Rule grant skipped by the protective credit policy.',
				array(
					'reason'   => $reason,
					'user_id'  => $user_id,
					'order_id' => $order_id,
					'amount'   => $amount,
				)
			);
	}
}
