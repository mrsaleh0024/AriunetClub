<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Rules;

use LClub\Core\Module;
use LClub\Core\Settings;
use LClub\Modules\Ledger\LedgerRepository;
use LClub\Modules\Ledger\LedgerService;
use LClub\Modules\Rules\Conditions\Category;
use LClub\Modules\Rules\Conditions\FirstOrder;
use LClub\Modules\Rules\Conditions\MinAmount;
use LClub\Modules\Rules\Conditions\PaymentMethod;
use LClub\Modules\Rules\Conditions\Product;
use LClub\Modules\Rules\Conditions\UserRole;
use LClub\Modules\Rules\Rewards\FixedReward;
use LClub\Modules\Rules\Rewards\PercentageReward;

/**
 * Phase 2 module: evaluates "event → conditions → reward" rules on the ledger.
 */
class RulesModule implements Module {
	public function id(): string {
			return 'rules';
	}

		/**
		 * @return string[]
		 */
	public function dependencies(): array {
			return array( 'core', 'events', 'ledger' );
	}

	public function boot(): void {
			global $wpdb;

			$repository = new RuleRepository( $wpdb );

			$ledger_service = isset( $GLOBALS['lclub_ledger_service'] ) && $GLOBALS['lclub_ledger_service'] instanceof LedgerService
					? $GLOBALS['lclub_ledger_service']
					: new LedgerService( new LedgerRepository( $wpdb ) );
			$ledger_gateway = new LedgerRepository( $wpdb );

			$settings = new Settings();
			$engine   = new RuleEngine(
				array(
					'min_amount'     => new MinAmount(),
					'category'       => new Category(),
					'product'        => new Product(),
					'first_order'    => new FirstOrder(),
					'user_role'      => new UserRole(),
					'payment_method' => new PaymentMethod(),
				),
				array(
					'percent' => new PercentageReward(),
					'fixed'   => new FixedReward(),
				)
			);

			$service = new RewardService(
				$repository,
				$engine,
				new LimitChecker( $ledger_gateway ),
				$ledger_service,
				$ledger_gateway,
				new WcContextFactory( $settings ),
				new WcOrderHistory(),
				new WcOrderLookup( $settings )
			);

			add_action( 'lclub_event_processed', array( $service, 'handle_event' ) );
			( new RulesAdminPage( $repository, $settings ) )->register();

			$GLOBALS['lclub_rule_service'] = $service;
	}
}
