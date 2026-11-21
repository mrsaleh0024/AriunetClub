<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Ledger;

use AriunetClub\Core\Module;
use AriunetClub\Modules\Identity\IdentityService;
use AriunetClub\Modules\Identity\PhoneNormalizer;

class LedgerModule implements Module {
	public function id(): string {
		return 'ledger'; }
	public function dependencies(): array {
		return array( 'core', 'identity' ); }
	public function boot(): void {
		global $wpdb;
		$repository = new LedgerRepository( $wpdb );
		$service    = new LedgerService( $repository );
		$identity   = isset( $GLOBALS['ariunetclub_identity_service'] ) && $GLOBALS['ariunetclub_identity_service'] instanceof IdentityService ? $GLOBALS['ariunetclub_identity_service'] : new IdentityService( new PhoneNormalizer() );
		( new WalletAdminPage( $repository, $service, $identity ) )->register();
		$GLOBALS['ariunetclub_ledger_service'] = $service;
	}
}
