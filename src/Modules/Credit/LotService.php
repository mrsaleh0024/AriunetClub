<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Credit;

use AriunetClub\Core\Money;
use AriunetClub\Core\Settings;
use AriunetClub\Modules\Ledger\InsufficientBalanceException;
use AriunetClub\Modules\Ledger\LedgerService;

/**
 * Phase 6 lot bookkeeping, wired into the ledger's transaction through the
 * `ariunetclub_ledger_entry_pending` hook (fired between the entry insert and
 * COMMIT, with the user's balance row locked). An exception thrown here rolls
 * the whole ledger entry back, so the lots and the ledger can never drift.
 *
 * Invariants maintained for every user (checked by ReconciliationJob):
 *   I1  live-lot remaining == available + held + debt, where debt >= 0 and
 *       debt == 0 whenever available >= 0;
 *   I2  no negative remaining/held on any lot;
 *   I3  a lot past its expiry is never allocated for new spending;
 *   I4  lot.held <= lot.remaining on every lot.
 *
 * Spend policy (FEFO): the soonest-expiring lots are consumed first, then the
 * older ones, and lots without expiry last. Held amounts never expire until
 * the hold is released; a released amount on an already-expired lot expires
 * immediately (separate `expire` ledger entry, idempotent per allocation).
 */
class LotService {

		const SOURCE_RULE          = 'rule';
		const SOURCE_MANUAL        = 'manual';
		const SOURCE_CAMPAIGN      = 'campaign';
		const SOURCE_REFERRAL      = 'referral';
		const SOURCE_WELCOME       = 'welcome';
		const SOURCE_LEGACY        = 'legacy';
		const SOURCE_REFUND_RETURN = 'refund_return';

		/** @var LotRepository */
		private $lots;

		/** @var Settings */
		private $settings;

		/** @var LedgerService|null */
		private $ledger;

		/**
		 * Freed allocations of the release currently being committed; consumed
		 * by the post-commit hook to expire amounts on already-expired lots.
		 *
		 * @var array<int,array{lot_id:int,allocation_id:int,amount:float}>
		 */
		private $release_freed = array();

	public function __construct( LotRepository $lots, Settings $settings, ?LedgerService $ledger = null ) {
			$this->lots     = $lots;
			$this->settings = $settings;
			$this->ledger   = $ledger;
	}

		/**
		 * Subscribes to both ledger hooks. Call once during module boot.
		 */
	public function register(): void {
			add_action( 'ariunetclub_ledger_entry_pending', array( $this, 'on_entry_pending' ), 10, 3 );
			add_action( 'ariunetclub_ledger_entry_created', array( $this, 'on_entry_created' ) );
	}

		/**
		 * In-transaction hook: dispatches one ledger entry to its lot rules.
		 *
		 * @param array<string,mixed> $entry  Entry row (delta already applied, includes `_lot_*` context).
		 * @param array{available:float,held:float} $before Balance before the entry.
		 * @param array{available:float,held:float} $after  Balance after the entry.
		 * @return void
		 */
	public function on_entry_pending( array $entry, array $before, array $after ): void {
			$type    = isset( $entry['entry_type'] ) && is_string( $entry['entry_type'] ) ? $entry['entry_type'] : '';
			$user_id = isset( $entry['user_id'] ) ? LotRepository::i( $entry['user_id'] ) : 0;
			$delta   = isset( $entry['amount'] ) && is_numeric( $entry['amount'] ) ? LotRepository::f( $entry['amount'] ) : 0.0;
		if ( $user_id <= 0 ) {
				return;
		}

		switch ( $type ) {
			case 'credit':
			case 'grant':
					$this->issue_lot( $entry, $user_id, $delta, $before );
				break;
			case 'adjust':
				if ( $delta > 0.0 ) {
						$this->issue_lot( $entry, $user_id, $delta, $before );
				} elseif ( $delta < 0.0 ) {
						$this->consume( $user_id, abs( $delta ), $entry );
				}
				break;
			case 'debit':
				if ( $delta < 0.0 ) {
						$this->consume( $user_id, abs( $delta ), $entry );
				}
				break;
			case 'hold':
					$this->allocate_hold( $entry, $user_id, $this->entry_amount( $entry, $delta, $before, $after ) );
				break;
			case 'capture':
					$this->capture_hold( $entry, $user_id, abs( $delta ) );
				break;
			case 'release':
					$this->release_hold( $entry, $user_id, $this->entry_amount( $entry, $delta, $before, $after ), $after );
				break;
			case 'expire':
					$this->expire_lot( $entry, $user_id, abs( $delta ) );
				break;
		}
	}

		/**
		 * Post-commit hook: only the release path needs it — freed amounts on
		 * lots that are already past their expiry date expire immediately.
		 *
		 * @param array<string,mixed> $entry Committed entry.
		 * @return void
		 */
	public function on_entry_created( array $entry ): void {
			$type = isset( $entry['entry_type'] ) && is_string( $entry['entry_type'] ) ? $entry['entry_type'] : '';
		if ( 'release' !== $type || empty( $this->release_freed ) || null === $this->ledger ) {
				$this->release_freed = array();
				return;
		}
			$freed               = $this->release_freed;
			$this->release_freed = array();
			$user_id             = isset( $entry['user_id'] ) ? LotRepository::i( $entry['user_id'] ) : 0;
		if ( $user_id <= 0 ) {
				return;
		}
			$now_gmt = (string) current_time( 'mysql', true );
		foreach ( $freed as $item ) {
				$lot = $this->lots->lock_lot( LotRepository::i( $item['lot_id'] ) );
			if ( null === $lot || LotRepository::STATUS_ACTIVE !== LotRepository::s( $lot['status'] ) ) {
					continue;
			}
				$expires_at = isset( $lot['expires_at'] ) ? LotRepository::s( $lot['expires_at'] ) : '';
			if ( '' === $expires_at || $expires_at > $now_gmt ) {
					continue;
			}
			try {
					$this->ledger->expire(
						$user_id,
						Money::round( LotRepository::f( $item['amount'] ) ),
						'lot:' . LotRepository::i( $item['lot_id'] ) . ':expire:alloc:' . LotRepository::i( $item['allocation_id'] ),
						array(
							'ref_type' => 'lot',
							'ref_id'   => LotRepository::i( $item['lot_id'] ),
							'note'     => __( 'انقضای اعتبار باشگاه — پس از آزادسازی رزرو', 'ariunetclub' ),
							'_lot_id'  => LotRepository::i( $item['lot_id'] ),
						)
					);
			} catch ( \Throwable $error ) {
					// The daily expiry job sweeps this lot later; the ledger entry is idempotent.
					continue;
			}
		}
	}

		/**
		 * Creates the lot for a positive credit/grant/adjust entry after paying
		 * off any implicit debt (I1): debt := lot_remaining - (available + held)
		 * of the pre-state, clamped at zero. A grant smaller than the debt pays
		 * what it can; the residual debt is then repaid from the user's own
		 * unheld lots so the "no debt while available >= 0" rule always holds.
		 *
		 * @param array<string,mixed> $entry Entry row.
		 * @param int                 $user_id Customer.
		 * @param float               $amount Positive delta.
		 * @param array{available:float,held:float} $before Pre-state balance.
		 * @return void
		 */
	private function issue_lot( array $entry, int $user_id, float $amount, array $before ): void {
			$amount = Money::round( $amount );
		if ( $amount <= 0.0 ) {
				return;
		}
			$sums = $this->lots->sums_for_user( $user_id );
			$debt = max( 0.0, $sums['remaining'] - ( LotRepository::f( $before['available'] ) + LotRepository::f( $before['held'] ) ) );

		if ( $debt > LotRepository::EPSILON && $amount < $debt - LotRepository::EPSILON ) {
				// The grant cannot clear the debt: the leftover is repaid from
				// the customer's own unheld credit (never from held amounts).
				$this->consume( $user_id, Money::round( $debt - $amount ), $entry );
				return;
		}

			$lot_amount = Money::round( $amount - $debt );
		if ( $lot_amount <= 0.0 ) {
				return; // the whole grant repays debt; no live lot is created.
		}

			$source_type = $this->source_type_for( $entry );
			$expires_at  = $this->expiry_for( $entry, $source_type );
			$reason      = $this->reason_for( $entry, $source_type );

			$this->lots->insert_lot(
				array(
					'user_id'        => $user_id,
					'source_type'    => $source_type,
					'source_ref'     => isset( $entry['id'] ) ? LotRepository::i( $entry['id'] ) : null,
					'ref_type'       => isset( $entry['ref_type'] ) ? $entry['ref_type'] : null,
					'ref_id'         => isset( $entry['ref_id'] ) ? $entry['ref_id'] : null,
					'rule_id'        => isset( $entry['rule_id'] ) ? $entry['rule_id'] : null,
					'initial_amount' => $lot_amount,
					'remaining'      => $lot_amount,
					'held'           => 0.0,
					'expires_at'     => $expires_at,
					'status'         => LotRepository::STATUS_ACTIVE,
					'reason'         => $reason,
					'campaign_id'    => isset( $entry['_lot_campaign_id'] ) && is_numeric( $entry['_lot_campaign_id'] ) ? LotRepository::i( $entry['_lot_campaign_id'] ) : null,
				)
			);
	}

		/**
		 * FEFO consumption for debits, reversals and negative adjustments.
		 * Lots referencing the same order are consumed first (precise
		 * claw-back), then everything else in FEFO order; whatever is left
		 * becomes implicit debt in the ledger (I1 allows debt only when the
		 * live lots are exhausted).
		 *
		 * @param array<string,mixed> $entry Entry row.
		 * @return void
		 */
	private function consume( int $user_id, float $amount, array $entry ): void {
			$left = Money::round( $amount );
		if ( $left <= 0.0 ) {
				return;
		}
			$candidates = array();
			$ref_type   = isset( $entry['ref_type'] ) && is_string( $entry['ref_type'] ) ? $entry['ref_type'] : '';
			$ref_id     = isset( $entry['ref_id'] ) && is_numeric( $entry['ref_id'] ) ? LotRepository::i( $entry['ref_id'] ) : 0;
		if ( 'order' === $ref_type && $ref_id > 0 ) {
			foreach ( $this->lots->lock_order_lots( $user_id, $ref_id ) as $lot ) {
					$candidates[ LotRepository::i( $lot['id'] ) ] = $lot;
			}
		}
		foreach ( $this->lots->lock_consumable_lots( $user_id ) as $lot ) {
				$candidates[ LotRepository::i( $lot['id'] ) ] = $lot;
		}
		foreach ( $candidates as $lot ) {
			if ( $left <= LotRepository::EPSILON ) {
					break;
			}
				$remaining = LotRepository::f( $lot['remaining'] );
				$held      = LotRepository::f( $lot['held'] );
				$headroom  = Money::round( $remaining - $held );
			if ( $headroom <= LotRepository::EPSILON ) {
					continue;
			}
				$take = Money::round( min( $headroom, $left ) );
			if ( $take <= LotRepository::EPSILON ) {
					continue;
			}
				$this->lots->update_lot( LotRepository::i( $lot['id'] ), $remaining - $take, $held, LotRepository::s( $lot['status'] ) );
				$left = Money::round( $left - $take );
		}
			// Any leftover is intentional debt; nothing more to do here.
	}

		/**
		 * Reserves the hold amount on allocatable lots (FEFO, never on expired
		 * lots — I3) and records one allocation row per touched lot.
		 *
		 * @param array<string,mixed> $entry Entry row.
		 * @return void
		 * @throws InsufficientBalanceException When the lots cannot cover the hold (drift, fail closed).
		 */
	private function allocate_hold( array $entry, int $user_id, float $amount ): void {
			$left = Money::round( $amount );
		if ( $left <= 0.0 ) {
				return;
		}
			$order_id = isset( $entry['ref_id'] ) && is_numeric( $entry['ref_id'] ) ? LotRepository::i( $entry['ref_id'] ) : 0;
		if ( 'order' !== LotRepository::s( $entry['ref_type'] ?? '' ) || $order_id <= 0 ) {
				return; // non-order holds keep working on the balance level only.
		}
			$now_gmt = (string) current_time( 'mysql', true );
		foreach ( $this->lots->lock_allocatable_lots( $user_id, $now_gmt ) as $lot ) {
			if ( $left <= LotRepository::EPSILON ) {
					break;
			}
				$remaining = LotRepository::f( $lot['remaining'] );
				$held      = LotRepository::f( $lot['held'] );
				$headroom  = Money::round( $remaining - $held );
			if ( $headroom <= LotRepository::EPSILON ) {
					continue;
			}
				$take = Money::round( min( $headroom, $left ) );
			if ( $take <= LotRepository::EPSILON ) {
					continue;
			}
				$this->lots->update_lot( LotRepository::i( $lot['id'] ), $remaining, $held + $take, LotRepository::s( $lot['status'] ) );
				$this->lots->insert_allocation( LotRepository::i( $lot['id'] ), $user_id, $order_id, $take );
				$left = Money::round( $left - $take );
		}
		if ( $left > LotRepository::EPSILON ) {
				throw new InsufficientBalanceException( 'Insufficient allocatable credit lots for the hold.' );
		}
	}

		/**
		 * Finalizes a hold: the recorded allocations are consumed; any gap
		 * (pre-upgrade hold without allocations) is consumed FEFO.
		 *
		 * @param array<string,mixed> $entry Entry row.
		 * @return void
		 */
	private function capture_hold( array $entry, int $user_id, float $amount ): void {
			$left = Money::round( $amount );
		if ( $left <= 0.0 ) {
				return;
		}
			$order_id = isset( $entry['ref_id'] ) && is_numeric( $entry['ref_id'] ) ? LotRepository::i( $entry['ref_id'] ) : 0;
		if ( 'order' !== LotRepository::s( $entry['ref_type'] ?? '' ) || $order_id <= 0 ) {
				$this->consume( $user_id, $left, $entry );
				return;
		}
			$lot_status = array();
		foreach ( $this->lots->lock_held_allocations( $order_id, $user_id ) as $allocation ) {
			if ( $left <= LotRepository::EPSILON ) {
					break;
			}
				$lot = $this->lots->lock_lot( LotRepository::i( $allocation['lot_id'] ) );
			if ( null === $lot ) {
					continue;
			}
				$take = Money::round( min( LotRepository::f( $allocation['amount'] ), $left ) );
			if ( $take <= LotRepository::EPSILON ) {
					continue;
			}
				$this->lots->update_lot( LotRepository::i( $lot['id'] ), LotRepository::f( $lot['remaining'] ) - $take, LotRepository::f( $lot['held'] ) - $take, LotRepository::s( $lot['status'] ) );
				$this->lots->set_allocation_state( LotRepository::i( $allocation['id'] ), 'captured' );
				$left = Money::round( $left - $take );
		}
		if ( $left > LotRepository::EPSILON ) {
				$this->consume( $user_id, $left, $entry );
		}
	}

		/**
		 * Frees the recorded allocations of a hold. Pre-upgrade holds have no
		 * allocations, so nothing is freed on lots (the amount returns to the
		 * balance level only). Two debt rules apply after the freeing:
		 *  - a freed amount on an already-expired lot expires immediately (the
		 *    post-commit hook);
		 *  - when the user still carries implicit debt (reversals exceeded the
		 *    live lots), the freed credit first repays it, so the invariant
		 *    "available >= 0 ⇒ no debt" (I1) always holds.
		 *
		 * @param array<string,mixed> $entry Entry row.
		 * @param int                 $user_id Customer.
		 * @param float               $amount Released amount.
		 * @param array{available:float,held:float} $after Post-entry balance.
		 * @return void
		 */
	private function release_hold( array $entry, int $user_id, float $amount, array $after ): void {
			$left = Money::round( $amount );
		if ( $left <= 0.0 ) {
				return;
		}
			$order_id = isset( $entry['ref_id'] ) && is_numeric( $entry['ref_id'] ) ? LotRepository::i( $entry['ref_id'] ) : 0;
		if ( 'order' !== LotRepository::s( $entry['ref_type'] ?? '' ) || $order_id <= 0 ) {
				return;
		}
			$this->release_freed = array();
		foreach ( $this->lots->lock_held_allocations( $order_id, $user_id ) as $allocation ) {
			if ( $left <= LotRepository::EPSILON ) {
					break;
			}
				$lot = $this->lots->lock_lot( LotRepository::i( $allocation['lot_id'] ) );
			if ( null === $lot ) {
					continue;
			}
				$take = Money::round( min( LotRepository::f( $allocation['amount'] ), $left ) );
			if ( $take <= LotRepository::EPSILON ) {
					continue;
			}
				$this->lots->update_lot( LotRepository::i( $lot['id'] ), LotRepository::f( $lot['remaining'] ), LotRepository::f( $lot['held'] ) - $take, LotRepository::s( $lot['status'] ) );
				$this->lots->set_allocation_state( LotRepository::i( $allocation['id'] ), 'released' );
				$this->release_freed[] = array(
					'lot_id'        => LotRepository::i( $lot['id'] ),
					'allocation_id' => LotRepository::i( $allocation['id'] ),
					'amount'        => $take,
				);
				$left                  = Money::round( $left - $take );
		}

			/*
			 * Debt repayment: the freed lots are unheld now, so consume()
			 * draws exactly from them; the repaid part is dropped from the
			 * freed list so the post-commit hook cannot expire it twice.
			 */
			$sums_after = $this->lots->sums_for_user( $user_id );
			$debt_after = $sums_after['remaining'] - ( LotRepository::f( $after['available'] ) + LotRepository::f( $after['held'] ) );
		if ( $debt_after > LotRepository::EPSILON ) {
				$repay = Money::round( min( $amount, $debt_after ) );
			if ( $repay > LotRepository::EPSILON ) {
					$this->consume( $user_id, $repay, $entry );
					$drop = $repay;
				foreach ( $this->release_freed as $index => $freed ) {
					if ( $drop <= LotRepository::EPSILON ) {
								break;
					}
					$take            = min( LotRepository::f( $freed['amount'] ), $drop );
					$freed['amount'] = Money::round( LotRepository::f( $freed['amount'] ) - $take );
					$drop            = Money::round( $drop - $take );
					if ( $freed['amount'] <= LotRepository::EPSILON ) {
									unset( $this->release_freed[ $index ] );
					} else {
						$this->release_freed[ $index ] = $freed;
					}
				}
			}
		}
	}

		/**
		 * Reduces one specific lot by the expire entry amount (its unheld
		 * part); the entry context carries `_lot_id`.
		 *
		 * @param array<string,mixed> $entry Entry row.
		 * @return void
		 * @throws \RuntimeException When the entry does not match the lot (fail closed).
		 */
	private function expire_lot( array $entry, int $user_id, float $amount ): void {
			$lot_id = isset( $entry['_lot_id'] ) && is_numeric( $entry['_lot_id'] ) ? LotRepository::i( $entry['_lot_id'] ) : 0;
		if ( $lot_id <= 0 ) {
				return;
		}
			$lot = $this->lots->lock_lot( $lot_id );
		if ( null === $lot ) {
				return;
		}
		if ( LotRepository::i( $lot['user_id'] ) !== $user_id ) {
				throw new \RuntimeException( 'Expire entry user does not match the credit lot owner.' );
		}
			$remaining = LotRepository::f( $lot['remaining'] );
			$held      = LotRepository::f( $lot['held'] );
			$headroom  = Money::round( $remaining - $held );
		if ( $amount > $headroom + LotRepository::EPSILON ) {
				// The lot changed between the job's scan and this entry; fail closed so
				// the idempotent retry uses fresh numbers instead of over-expiring.
				throw new \RuntimeException( 'Expire amount exceeds the lot unheld balance.' );
		}
			$take = Money::round( $amount );
		if ( $take <= LotRepository::EPSILON ) {
				return;
		}
			$new_remaining = Money::round( $remaining - $take );
			$status        = LotRepository::s( $lot['status'] );
		if ( $new_remaining <= LotRepository::EPSILON && $held <= LotRepository::EPSILON ) {
				$status = LotRepository::STATUS_EXPIRED;
		}
			$this->lots->update_lot( $lot_id, $new_remaining, $held, $status );
	}

		/**
		 * Hold and release entries carry a zero delta in the ledger; the
		 * original amount travels as `_original_amount` and falls back to the
		 * balance movement (hold: available fell; release: available rose).
		 *
		 * @param array<string,mixed> $entry Entry row.
		 * @param float               $delta Recorded delta.
		 * @param array{available:float,held:float} $before Balance before.
		 * @param array{available:float,held:float} $after  Balance after.
		 * @return float
		 */
	private function entry_amount( array $entry, float $delta, array $before, array $after ): float {
		if ( abs( $delta ) > LotRepository::EPSILON ) {
				return abs( $delta );
		}
		if ( isset( $entry['_original_amount'] ) && is_numeric( $entry['_original_amount'] ) ) {
				return abs( LotRepository::f( $entry['_original_amount'] ) );
		}
			$moved = abs( ( LotRepository::f( $after['available'] ) ) - ( LotRepository::f( $before['available'] ) ) );
			return $moved;
	}

		/**
		 * Entry source → lot source_type (vocabulary of the phase 6 document).
		 *
		 * @param array<string,mixed> $entry Entry row.
		 * @return string
		 */
	private function source_type_for( array $entry ): string {
			$type = isset( $entry['entry_type'] ) && is_string( $entry['entry_type'] ) ? $entry['entry_type'] : '';
			$key  = isset( $entry['idempotency_key'] ) && is_string( $entry['idempotency_key'] ) ? $entry['idempotency_key'] : '';
		if ( 'credit' === $type ) {
				return self::SOURCE_RULE;
		}
		if ( 'grant' === $type ) {
				$source = isset( $entry['_lot_source'] ) && is_string( $entry['_lot_source'] ) ? $entry['_lot_source'] : self::SOURCE_CAMPAIGN;
				return in_array( $source, array( self::SOURCE_CAMPAIGN, self::SOURCE_REFERRAL, self::SOURCE_WELCOME, self::SOURCE_MANUAL ), true ) ? $source : self::SOURCE_CAMPAIGN;
		}
		if ( 0 === strpos( $key, 'wallet:refund:order:' ) ) {
				return self::SOURCE_REFUND_RETURN;
		}
			return self::SOURCE_MANUAL;
	}

		/**
		 * Expiry date for a new lot. Rule cashback honours the expiry settings
		 * (default off, 90 days when on); grant/manual entries only honour an
		 * explicit `_lot_expiry_days` context.
		 *
		 * @param array<string,mixed> $entry Entry row.
		 * @return string|null UTC datetime or null for "never expires".
		 */
	private function expiry_for( array $entry, string $source_type ) {
			$days = 0;
		if ( isset( $entry['_lot_expiry_days'] ) && is_numeric( $entry['_lot_expiry_days'] ) ) {
				$days = max( 0, min( 3650, LotRepository::i( $entry['_lot_expiry_days'] ) ) );
		}
		if ( self::SOURCE_RULE === $source_type && $days <= 0 ) {
				$config = $this->settings->get();
			if ( empty( $config['expiry_enabled'] ) ) {
					return null;
			}
				$raw  = isset( $config['expiry_days'] ) ? $config['expiry_days'] : null;
				$days = is_numeric( $raw ) ? (int) $raw : 90;
				$days = max( 1, min( 3650, $days ) );
		}
		if ( $days <= 0 ) {
				return null;
		}
			return gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
	}

		/**
		 * Customer-facing reason line, e.g. «از سفارش ۱۲۳، قانون «کش‌بک ۵٪»».
		 *
		 * @param array<string,mixed> $entry Entry row.
		 * @return string
		 */
	private function reason_for( array $entry, string $source_type ): string {
			$note   = isset( $entry['note'] ) && is_string( $entry['note'] ) ? $entry['note'] : '';
			$ref_id = isset( $entry['ref_id'] ) && is_numeric( $entry['ref_id'] ) ? LotRepository::i( $entry['ref_id'] ) : 0;
			$ref    = isset( $entry['ref_type'] ) && is_string( $entry['ref_type'] ) ? $entry['ref_type'] : '';
			$reason = $note;

		switch ( $source_type ) {
			case self::SOURCE_RULE:
				if ( 'order' === $ref && $ref_id > 0 ) {
						/* translators: 1: order number, 2: rule title. */
						$reason = sprintf( __( 'از سفارش %1$s، قانون «%2$s»', 'ariunetclub' ), (string) $ref_id, $note );
				} elseif ( '' !== $note ) {
						/* translators: %s: rule title. */
						$reason = sprintf( __( 'قانون «%s»', 'ariunetclub' ), $note );
				}
				break;
			case self::SOURCE_REFUND_RETURN:
				if ( $ref_id > 0 ) {
					/* translators: %s: order number. */
					$reason = sprintf( __( 'برگشت اعتبار خرج‌شده — سفارش %s', 'ariunetclub' ), (string) $ref_id );
				} else {
							$reason = __( 'برگشت اعتبار خرج‌شده', 'ariunetclub' );
				}
				break;
			case self::SOURCE_LEGACY:
					$reason = __( 'ماندهٔ اعتبار پیش از ارتقا', 'ariunetclub' );
				break;
			case self::SOURCE_MANUAL:
			case self::SOURCE_CAMPAIGN:
			case self::SOURCE_REFERRAL:
			case self::SOURCE_WELCOME:
			default:
					$reason = '' !== $note ? $note : __( 'اعتبار باشگاه', 'ariunetclub' );
				break;
		}
			$reason = sanitize_text_field( $reason );
		if ( function_exists( 'mb_substr' ) ) {
				return '' === trim( $reason ) ? '' : mb_substr( $reason, 0, 190, 'UTF-8' );
		}
			return '' === trim( $reason ) ? '' : substr( $reason, 0, 190 );
	}
}
