<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Events\Sources;

use LClub\Modules\Events\EventBus;

/**
 * Records approved product reviews as club events.
 * Covers both immediate approvals and later moderation transitions.
 */
class ReviewSource {
	/** @var EventBus */
	private $bus;

	public function __construct( EventBus $bus ) {
		$this->bus = $bus;
	}

	public function register(): void {
		add_action( 'comment_post', array( $this, 'on_comment_post' ), 10, 2 );
		add_action( 'transition_comment_status', array( $this, 'on_transition' ), 10, 3 );
	}

	/**
	 * @param int|string $comment_id Comment id.
	 * @param int|string $approved   Approval state (1 = approved).
	 */
	public function on_comment_post( $comment_id, $approved ): void {
		if ( 1 !== (int) $approved ) {
			return;
		}
		$this->record_comment( (int) $comment_id );
	}

	/**
	 * @param string      $new_status New comment status.
	 * @param string      $old_status Previous comment status.
	 * @param \WP_Comment $comment    Comment object.
	 */
	public function on_transition( string $new_status, string $old_status, $comment ): void {
		if ( $new_status === $old_status || 'approved' !== $new_status ) {
			return;
		}
		if ( $comment instanceof \WP_Comment ) {
			$this->record_comment( (int) $comment->comment_ID );
		}
	}

	/**
	 * @param int $comment_id Comment id.
	 */
	private function record_comment( int $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof \WP_Comment || 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return;
		}
		$user_id = (int) $comment->user_id;
		$this->bus->record(
			'product.review.approved',
			$user_id > 0 ? $user_id : null,
			'product_review',
			$comment_id,
			'product_review:' . $comment_id,
			array(
				'post_id' => (int) $comment->comment_post_ID,
			)
		);
	}
}
