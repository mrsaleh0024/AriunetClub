<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Identity;

use AriunetClub\Core\Module;

class IdentityModule implements Module {
	public function id(): string {
		return 'identity'; }
	public function dependencies(): array {
		return array( 'core' ); }
	public function boot(): void {
		$service = new IdentityService( new PhoneNormalizer() );
		add_filter( 'ariunetclub_phone_meta_keys', array( $service, 'phone_meta_keys' ) );
		$GLOBALS['ariunetclub_identity_service'] = $service;
	}
}
