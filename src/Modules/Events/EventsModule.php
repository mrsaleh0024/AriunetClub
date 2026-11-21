<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Events;

use AriunetClub\Core\Module;

/**
 * Phase 2 module: turns hooks into queue events and processes them async.
 */
class EventsModule implements Module {
	public function id(): string {
		return 'events';
	}

	/**
	 * @return string[]
	 */
	public function dependencies(): array {
		return array( 'core' );
	}

	public function boot(): void {
		global $wpdb;

		$repository = new EventRepository( $wpdb );
		$bus        = new EventBus( $repository );
		$processor  = new Processor( $repository );

		( new Sources\OrderSource( $bus ) )->register();
		( new Sources\UserSource( $bus ) )->register();
		( new Sources\ReviewSource( $bus ) )->register();
		( new EventsAdminPage( $repository, $processor ) )->register();

		// Cron entry point for both Action Scheduler and WP-Cron callbacks.
		add_action( 'ariunetclub_process_events', array( $processor, 'process_pending' ) );
		$this->schedule();

		$GLOBALS['ariunetclub_event_bus'] = $bus;
	}

	/**
	 * Recurring queue drain: Action Scheduler when present, WP-Cron otherwise.
	 */
	private function schedule(): void {
		if ( function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' ) ) {
			if ( false === as_next_scheduled_action( 'ariunetclub_process_events', array(), 'ariunetclub' ) ) {
				as_schedule_recurring_action( time() + 60, 60, 'ariunetclub_process_events', array(), 'ariunetclub' );
			}
			return;
		}

		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- minute cadence is intentional and AS is preferred.
		if ( ! wp_next_scheduled( 'ariunetclub_process_events' ) ) {
			wp_schedule_event( time() + 60, 'ariunetclub_minutely', 'ariunetclub_process_events' );
		}
	}

	/**
	 * @param array<string,array<string,mixed>> $schedules Registered cron schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public function add_cron_schedule( array $schedules ): array {
		if ( ! isset( $schedules['ariunetclub_minutely'] ) ) {
			$schedules['ariunetclub_minutely'] = array(
				'interval' => 60,
				'display'  => esc_html__( 'هر یک دقیقه (باشگاه مشتریان)', 'ariunetclub' ),
			);
		}
		return $schedules;
	}
}
