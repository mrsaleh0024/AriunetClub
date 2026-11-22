<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Dashboard;

use AriunetClub\Core\Module;

/**
 * Phase 5 module: the admin dashboard with real ledger statistics and a
 * server-rendered SVG chart. Read-only by design — it can never change
 * money, so it stays available (behind the license lock screen) even when
 * the license is invalid.
 */
final class DashboardModule implements Module {

	public function id(): string {
		return 'dashboard';
	}

	/**
	 * @return string[]
	 */
	public function dependencies(): array {
		return array( 'core' );
	}

	public function boot(): void {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return; // no database handle, nothing safe to wire.
		}
		( new DashboardPage( new DashboardStatsRepository( $wpdb ) ) )->register();
	}
}
