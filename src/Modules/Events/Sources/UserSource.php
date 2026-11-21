<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Events\Sources;

use AriunetClub\Modules\Events\EventBus;

/**
 * Records account registration as a club event.
 */
class UserSource {
	/** @var EventBus */
	private $bus;

	public function __construct( EventBus $bus ) {
		$this->bus = $bus;
	}

	public function register(): void {
		add_action( 'user_register', array( $this, 'on_register' ), 10, 1 );
	}

	/**
	 * @param int $user_id New user id.
	 */
	public function on_register( $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}
		$this->bus->record(
			'user.registered',
			$user_id,
			'user',
			$user_id,
			'user:' . $user_id . ':registered',
			array()
		);
	}
}
