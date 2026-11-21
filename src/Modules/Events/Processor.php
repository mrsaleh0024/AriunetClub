<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Events;

/**
 * Processes the pending event queue. Handlers are attached to the internal
 * action `ariunetclub_event_processed`; the processor itself knows nothing about rules.
 */
class Processor {
	const LOCK_TRANSIENT = 'ariunetclub_process_lock';
	const LOCK_SECONDS   = 45;
	const MAX_ATTEMPTS   = 3;

	/** @var EventGateway */
	private $gateway;

	/** @var callable|null */
	private $dispatcher;

	/**
	 * @param EventGateway   $gateway    Queue storage.
	 * @param callable|null  $dispatcher Receives the event row; defaults to the internal action.
	 */
	public function __construct( EventGateway $gateway, ?callable $dispatcher = null ) {
		$this->gateway    = $gateway;
		$this->dispatcher = $dispatcher;
	}

	/**
	 * Claims and processes a batch of pending events.
	 *
	 * @param int $batch Maximum events per run.
	 * @return int Number of events processed.
	 */
	public function process_pending( int $batch = 20 ): int {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return 0;
		}
		set_transient( self::LOCK_TRANSIENT, 1, self::LOCK_SECONDS );
		try {
			return $this->drain( $batch );
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * @param int $batch Maximum events per run.
	 * @return int Number of events processed.
	 */
	private function drain( int $batch ): int {
		$events = $this->gateway->pending_batch( $batch );
		$done   = 0;
		foreach ( $events as $event ) {
			if ( ! isset( $event['id'], $event['type'], $event['status'] ) || 'pending' !== (string) $event['status'] ) {
				continue;
			}
			$this->gateway->claim( (int) $event['id'] );
			try {
				$this->dispatch( $event );
				$this->gateway->mark_done( (int) $event['id'] );
			} catch ( SkipEventException $skip ) {
				$this->gateway->mark_skipped( (int) $event['id'], (string) $skip->getMessage() );
			} catch ( \Throwable $error ) {
				$this->gateway->mark_error( (int) $event['id'], (string) $error->getMessage(), self::MAX_ATTEMPTS );
			}
			++$done;
		}
		return $done;
	}

	/**
	 * @param array<string,mixed> $event Event row.
	 */
	private function dispatch( array $event ): void {
		if ( null !== $this->dispatcher ) {
			( $this->dispatcher )( $event );
			return;
		}
		do_action( 'ariunetclub_event_processed', $event ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ariunetclub_ prefixed internal hook.
	}
}
