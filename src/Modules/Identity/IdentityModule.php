<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Identity;

use LClub\Core\Module;

class IdentityModule implements Module {
	public function id(): string {
		return 'identity'; }
	public function dependencies(): array {
		return array( 'core' ); }
	public function boot(): void {
		$service = new IdentityService( new PhoneNormalizer() );
		add_filter( 'lclub_phone_meta_keys', array( $service, 'phone_meta_keys' ) );
		$GLOBALS['lclub_identity_service'] = $service;
	}
}
