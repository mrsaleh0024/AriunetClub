<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\License;

use AriunetClub\Core\Module;

/**
 * Phase 5 module: offline Ed25519 license verification, the license admin
 * page, and the central LicenseGuard that locks rule management, manual
 * adjustments and settings saving while unlicensed (earning and spending
 * are never blocked).
 */
final class LicenseModule implements Module {

	public function id(): string {
		return 'license';
	}

	/**
	 * @return string[]
	 */
	public function dependencies(): array {
		return array( 'core' );
	}

	public function boot(): void {
		$store = new LicenseStore( new LicenseVerifier() );
		$guard = new LicenseGuard( $store );
		$guard->register();
		( new LicenseAdminPage( $store ) )->register();
		LicenseGuard::set_instance( $guard );
	}
}
