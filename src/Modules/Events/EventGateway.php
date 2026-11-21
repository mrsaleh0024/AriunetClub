<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Events;

/**
 * Storage contract for the lclub_events queue. SQL lives only in the repository.
 */
interface EventGateway {
	/**
	 * @param string $key Idempotency key.
	 * @return array<string,mixed>|null
	 */
	public function find_by_key( string $key ): ?array;

	/**
	 * Inserts an event; duplicates (same idempotency key) return the stored row.
	 *
	 * @param array<string,mixed> $event Event fields.
	 * @return array<string,mixed> Stored row including id.
	 */
	public function insert( array $event ): array;

	/**
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function pending_batch( int $limit ): array;

	/**
	 * Increments the attempt counter for a pending event.
	 */
	public function claim( int $id ): void;

	public function mark_done( int $id ): void;

	public function mark_skipped( int $id, string $reason ): void;

	/**
	 * Stores the error message; flips the event to failed once max attempts is reached.
	 */
	public function mark_error( int $id, string $error, int $max_attempts ): void;

	public function requeue( int $id ): void;

	/**
	 * @param string $status Empty string for every status.
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function list( string $status, int $page, int $per_page ): array;

	/** @return array<string,int> */
	public function status_counts(): array;
}
