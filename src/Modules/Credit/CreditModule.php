<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Credit;

use AriunetClub\Core\Logger;
use AriunetClub\Core\Module;
use AriunetClub\Core\Settings;
use AriunetClub\Modules\Events\EventRepository;
use AriunetClub\Modules\Ledger\LedgerRepository;
use AriunetClub\Modules\Ledger\LedgerService;

/**
 * Phase 6 module: credit lots with expiry, the monthly budget, protective
 * grant caps, the legacy balance migration and the daily reconciliation.
 *
 * Boots before the rules module so RewardService can pick up the shared
 * CreditPolicy from $GLOBALS['ariunetclub_credit_policy'].
 */
class CreditModule implements Module {

	public function id(): string {
			return 'credit';
	}

		/**
		 * @return string[]
		 */
	public function dependencies(): array {
			return array( 'core', 'ledger', 'events' );
	}

	public function boot(): void {
			global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
				return; // no database handle, nothing safe to wire.
		}

			$settings = new Settings();
			$logger   = new Logger();
			$lots     = new LotRepository( $wpdb );

			$ledger_service = isset( $GLOBALS['ariunetclub_ledger_service'] ) && $GLOBALS['ariunetclub_ledger_service'] instanceof LedgerService
					? $GLOBALS['ariunetclub_ledger_service']
					: new LedgerService( new LedgerRepository( $wpdb ) );

			$bus = isset( $GLOBALS['ariunetclub_event_bus'] ) && $GLOBALS['ariunetclub_event_bus'] instanceof \AriunetClub\Modules\Events\EventBus
					? $GLOBALS['ariunetclub_event_bus']
					: new \AriunetClub\Modules\Events\EventBus( new EventRepository( $wpdb ) );

			$lot_service = new LotService( $lots, $settings, $ledger_service );
			$lot_service->register();

			$budget = new BudgetService( $wpdb, $settings, $bus, $logger );
			( new ExpiryJob( $lots, $ledger_service, $bus, $logger ) )->register();
			( new ReconciliationJob( $lots, $logger ) )->register();
			( new BalanceMigration( $lots, $wpdb, $logger ) )->register();

			$policy = new CreditPolicy( $settings, $budget, $logger );
			( new CreditAdminPage( $lots, $budget, $settings, $logger ) )->register();

			$GLOBALS['ariunetclub_credit_policy']  = $policy;
			$GLOBALS['ariunetclub_lot_repository'] = $lots;
	}
}
