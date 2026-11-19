<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Ledger;

interface LedgerGateway {
	/** @param array<string,mixed> $entry @return array<string,mixed> */
	public function record( array $entry ): array;
	/** @return array<string,mixed> */
	public function balance( int $user_id, string $asset = 'cashback' ): array;
	/** @return array{items:array<int,array<string,mixed>>,total:int} */
	public function balances( array $user_ids = array(), int $page = 1, int $per_page = 20 ): array;
	/** @return array{items:array<int,array<string,mixed>>,total:int} */
	public function history( int $user_id, string $asset = 'cashback', int $page = 1, int $per_page = 20 ): array;
}
