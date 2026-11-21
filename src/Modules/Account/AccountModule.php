<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Account;

use AriunetClub\Core\Module;
use AriunetClub\Modules\Identity\IdentityService;
use AriunetClub\Modules\Identity\PhoneNormalizer;
use AriunetClub\Modules\Ledger\LedgerRepository;
use AriunetClub\Modules\Rules\RuleRepository;

/**
 * Phase 4 module: the customer-facing "club" tab in My Account (balance,
 * held amount, paginated history with Jalali dates, active earning rules
 * and the no-phone guide message).
 */
class AccountModule implements Module {

	public function id(): string {
		return 'account';
	}

	/**
	 * @return string[]
	 */
	public function dependencies(): array {
		return array( 'core', 'ledger', 'rules' );
	}

	public function boot(): void {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return; // no database handle, nothing safe to wire.
		}

		$identity = isset( $GLOBALS['ariunetclub_identity_service'] ) && $GLOBALS['ariunetclub_identity_service'] instanceof IdentityService
			? $GLOBALS['ariunetclub_identity_service']
			: new IdentityService( new PhoneNormalizer() );

		( new ClubEndpoint( new LedgerRepository( $wpdb ), new RuleRepository( $wpdb ), $identity ) )->register();
	}
}
