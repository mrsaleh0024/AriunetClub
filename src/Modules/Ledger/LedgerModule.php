<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Ledger;

use LClub\Core\Module;
use LClub\Modules\Identity\IdentityService;
use LClub\Modules\Identity\PhoneNormalizer;

class LedgerModule implements Module {
	public function id(): string {
		return 'ledger'; }
	public function dependencies(): array {
		return array( 'core', 'identity' ); }
	public function boot(): void {
		global $wpdb;
		$repository = new LedgerRepository( $wpdb );
		$service    = new LedgerService( $repository );
		$identity   = isset( $GLOBALS['lclub_identity_service'] ) && $GLOBALS['lclub_identity_service'] instanceof IdentityService ? $GLOBALS['lclub_identity_service'] : new IdentityService( new PhoneNormalizer() );
		( new WalletAdminPage( $repository, $service, $identity ) )->register();
		$GLOBALS['lclub_ledger_service'] = $service;
	}
}
