<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Rules;

/**
 * Storage contract for rules. SQL lives only in the repository.
 */
interface RuleGateway {
		/**
		 * @param string $event_type Event type the rules listen to.
		 * @return array<int,array<string,mixed>> Active rules, priority ASC then id ASC.
		 */
	public function active_for_event( string $event_type ): array;

		/**
		 * Customer-facing rules for the account "club" tab: every event type,
		 * active and within its date window, priority ASC then id ASC, at most 10.
		 *
		 * @param string $now_gmt Current UTC datetime (Y-m-d H:i:s).
		 * @return array<int,array<string,mixed>>
		 */
	public function active_for_display( string $now_gmt ): array;

		/**
		 * @param int $id Rule id.
		 * @return array<string,mixed>|null
		 */
	public function find( int $id ): ?array;

		/**
		 * @param array<string,mixed> $data Sanitized rule fields.
		 * @return int New rule id.
		 */
	public function insert( array $data ): int;

		/**
		 * @param int                 $id   Rule id.
		 * @param array<string,mixed> $data Sanitized rule fields.
		 * @return bool
		 */
	public function update( int $id, array $data ): bool;

	public function delete( int $id ): bool;

		/**
		 * @param string[] $statuses Allowed target statuses.
		 * @return bool
		 */
	public function set_status( int $id, array $statuses ): bool;

		/**
		 * @return array{items:array<int,array<string,mixed>>,total:int}
		 */
	public function list( int $page, int $per_page ): array;
}
