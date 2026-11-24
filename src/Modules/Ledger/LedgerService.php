<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Ledger;

use AriunetClub\Core\Money;

class LedgerService {
        /** @var LedgerGateway */
        private $repository;

        public function __construct( LedgerGateway $repository ) {
                $this->repository = $repository; }
        /** @param array<string,mixed> $context @return array<string,mixed> */
        public function credit( int $user_id, float $amount, string $key, array $context = array() ): array {
                return $this->write( 'credit', $user_id, $amount, $key, $context ); }
        /** @param array<string,mixed> $context @return array<string,mixed> */
        public function grant( int $user_id, float $amount, string $key, array $context = array() ): array {
                return $this->write( 'grant', $user_id, $amount, $key, $context ); }
        /** @param array<string,mixed> $context @return array<string,mixed> */
        public function expire( int $user_id, float $amount, string $key, array $context = array() ): array {
                return $this->write( 'expire', $user_id, $amount, $key, $context ); }
        /** @param array<string,mixed> $context @return array<string,mixed> */
        public function debit( int $user_id, float $amount, string $key, array $context = array() ): array {
                return $this->write( 'debit', $user_id, $amount, $key, $context ); }
        /** @param array<string,mixed> $context @return array<string,mixed> */
        public function hold( int $user_id, float $amount, string $key, array $context = array() ): array {
                return $this->write( 'hold', $user_id, $amount, $key, $context ); }
        /** @param array<string,mixed> $context @return array<string,mixed> */
        public function capture( int $user_id, float $amount, string $key, array $context = array() ): array {
                return $this->write( 'capture', $user_id, $amount, $key, $context ); }
        /** @param array<string,mixed> $context @return array<string,mixed> */
        public function release( int $user_id, float $amount, string $key, array $context = array() ): array {
                return $this->write( 'release', $user_id, $amount, $key, $context ); }
        /** @param array<string,mixed> $context @return array<string,mixed> */
        public function adjust( int $user_id, float $amount, string $key, array $context = array() ): array {
                return $this->write( 'adjust', $user_id, $amount, $key, $context ); }
        /** @return array<string,mixed> */
        public function balance( int $user_id, string $asset = 'cashback' ): array {
                return $this->repository->balance( $user_id, $asset ); }

        /** @param array<string,mixed> $context @return array<string,mixed> */
        private function write( string $type, int $user_id, float $amount, string $key, array $context ): array {
                if ( $user_id <= 0 || '' === $key || ( 'adjust' !== $type && $amount <= 0 ) || ( 'adjust' === $type && 0.0 === $amount ) ) {
                        throw new \InvalidArgumentException( 'Invalid ledger entry.' ); }
                $entry    = array_merge(
                        array(
                                'user_id'         => $user_id,
                                'asset'           => 'cashback',
                                'entry_type'      => $type,
                                'amount'          => Money::round( $amount ),
                                'idempotency_key' => $key,
                                'ref_type'        => null,
                                'ref_id'          => null,
                                'rule_id'         => null,
                                'note'            => null,
                                'created_by'      => null,
                        ),
                        $context
                );
                $recorded = $this->repository->record( $entry );
                do_action( 'ariunetclub_ledger_entry_created', $recorded );
                return $recorded;
        }
}
