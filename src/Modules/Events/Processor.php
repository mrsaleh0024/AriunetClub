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
	const LOCK_OPTION  = 'ariunetclub_process_lock';
	const LOCK_SECONDS = 45;
	const MAX_ATTEMPTS = 3;

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
		if ( ! $this->acquire_lock() ) {
			return 0;
		}
		try {
			return $this->drain( $batch );
		} finally {
			$this->release_lock();
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
				$this->gateway->mark_error( (int) $event['id'], (string) $error->getMessage(), $this->gateway->max_attempts() );
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

	/**
	 * Atomic cross-request lock: add_option() fails when the row already
	 * exists, so exactly one processor can win. The previous transient
	 * check-then-set let two concurrent requests both pass the gate and
	 * process the same batch. A holder that died mid-run is detected
	 * through a stale timestamp and cleared.
	 */
	private function acquire_lock(): bool {
		$now = time();
		if ( add_option( self::LOCK_OPTION, (string) $now, '', false ) ) {
			return true;
		}
		$locked_at = get_option( self::LOCK_OPTION, 0 );
		if ( is_numeric( $locked_at ) && $now - (int) $locked_at > self::LOCK_SECONDS + 30 ) {
			delete_option( self::LOCK_OPTION ); // stale holder: a process died mid-run.
			return add_option( self::LOCK_OPTION, (string) $now, '', false );
		}
		return false;
	}

	private function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}
}
