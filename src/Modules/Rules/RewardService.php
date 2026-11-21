<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Rules;

use LClub\Core\Money;
use LClub\Modules\Events\SkipEventException;
use LClub\Modules\Ledger\LedgerGateway;
use LClub\Modules\Ledger\LedgerService;

/**
 * Handles processed club events: applies earning rules and compensating reversals.
 * All money goes through LedgerService with idempotent keys, so a repeated
 * event never earns twice (acceptance criterion of phase 2).
 */
class RewardService {
		const REVERSAL_NOTE_REFUND    = 'برگشت کش‌بک — مستردشدهٔ سفارش';
		const REVERSAL_NOTE_CANCELLED = 'برگشت کش‌بک — لغو سفارش';
		const REVERSAL_NOTE_FAILED    = 'برگشت کش‌بک — سفارش ناموفق';

		/** @var RuleGateway */
		private $rules;

		/** @var RuleEngine */
		private $engine;

		/** @var LimitChecker */
		private $limits;

		/** @var LedgerService */
		private $ledger;

		/** @var LedgerGateway */
		private $ledger_gateway;

		/** @var ContextFactoryInterface */
		private $factory;

		/** @var OrderHistoryInterface */
		private $history;

		/** @var OrderLookupInterface */
		private $lookup;

	public function __construct(
		RuleGateway $rules,
		RuleEngine $engine,
		LimitChecker $limits,
		LedgerService $ledger,
		LedgerGateway $ledger_gateway,
		ContextFactoryInterface $factory,
		OrderHistoryInterface $history,
		OrderLookupInterface $lookup
	) {
			$this->rules          = $rules;
			$this->engine         = $engine;
			$this->limits         = $limits;
			$this->ledger         = $ledger;
			$this->ledger_gateway = $ledger_gateway;
			$this->factory        = $factory;
			$this->history        = $history;
			$this->lookup         = $lookup;
	}

		/**
		 * Entry point wired to the internal action `lclub_event_processed`.
		 *
		 * @param array<string,mixed> $event Event row.
		 * @return array<string,mixed> Summary for logging.
		 * @throws SkipEventException When the event is intentionally not actionable.
		 * @throws \RuntimeException  When the event must be retried or failed.
		 */
	public function handle_event( array $event ): array {
			$type = (string) ( $event['type'] ?? '' );
		if ( '' === $type ) {
				throw SkipEventException::because( 'unknown_type' );
		}

		if ( 0 === strpos( $type, 'order.status.' ) ) {
				$status = substr( $type, strlen( 'order.status.' ) );
			if ( 'cancelled' === $status || 'failed' === $status ) {
					return $this->full_reversal( $event, $status );
			}
			if ( 'refunded' === $status ) {
					// Refund objects already produced proportional reversal events.
					return array( 'mode' => 'noop' );
			}
				return $this->apply_rules( $event );
		}

		if ( 'order.refunded' === $type ) {
				return $this->proportional_reversal( $event );
		}

		if ( 'user.registered' === $type || 'product.review.approved' === $type ) {
				return $this->apply_rules( $event );
		}

			throw SkipEventException::because( 'unknown_type' );
	}

		/**
		 * Evaluates active rules for the event and credits matching rewards.
		 *
		 * @param array<string,mixed> $event Event row.
		 * @return array<string,mixed>
		 * @throws SkipEventException|\RuntimeException
		 */
	public function apply_rules( array $event ): array {
			$type  = (string) $event['type'];
			$rules = $this->rules->active_for_event( $type );
			$rules = array_values(
				array_filter(
					$rules,
					static function ( array $rule ): bool {
									return isset( $rule['status'] ) && 'active' === (string) $rule['status'];
					}
				)
			);
		if ( empty( $rules ) ) {
				return array(
					'mode'    => 'noop',
					'matched' => 0,
				);
		}

			$context = $this->factory->from_event( $event );
			$context = null !== $context ? $context : new RuleContext( $type, (int) ( $event['user_id'] ?? 0 ) );
		if ( $context->user_id <= 0 ) {
				throw SkipEventException::because( 'guest_user' );
		}

		if ( $this->needs_first_order( $rules ) && null === $context->first_order ) {
				$context->first_order = false;
				$snapshot             = $this->order_snapshot( $event );
			if ( null !== $snapshot && '' !== $snapshot->created_gmt ) {
					$context->first_order = ! $this->history->has_paid_order_before( $context->user_id, $snapshot->created_gmt );
			}
		}

			$results  = $this->engine->evaluate( $rules, $context, time() );
			$key      = (string) ( $event['idempotency_key'] ?? '' );
			$credited = array();

		foreach ( $results as $result ) {
				$rule   = $result['rule'];
				$amount = $result['amount'];
			if ( $this->limits->exhausted( $rule, $context->user_id, time() ) ) {
					continue;
			}
				$amount = (float) apply_filters( 'lclub_rule_reward_amount', $amount, $rule, $context );
				$amount = Money::round( $amount );
			if ( $amount <= 0.0 ) {
					continue;
			}
				$credit_key = 'rule:' . (int) $rule['id'] . ':' . $key;
				$recorded   = $this->ledger->credit(
					$context->user_id,
					$amount,
					$credit_key,
					array(
						'ref_type' => (string) ( $event['object_type'] ?? 'order' ),
						'ref_id'   => (int) ( $event['object_id'] ?? 0 ),
						'rule_id'  => (int) $rule['id'],
						'note'     => (string) ( $rule['title'] ?? '' ),
					)
				);
				$credited[] = array(
					'rule_id' => (int) $rule['id'],
					'amount'  => $amount,
					'entry'   => $recorded,
				);
		}

			return array(
				'mode'     => 'earn',
				'matched'  => count( $results ),
				'credited' => $credited,
			);
	}

		/**
		 * Full compensating reversal for cancelled/failed orders.
		 *
		 * @param array<string,mixed> $event  Event row.
		 * @param string              $status cancelled|failed.
		 * @return array<string,mixed>
		 * @throws \RuntimeException When the order cannot be loaded.
		 */
	public function full_reversal( array $event, string $status ): array {
			$order_id = (int) ( $event['object_id'] ?? 0 );
			$snapshot = $this->lookup->find( $order_id );
		if ( null === $snapshot ) {
				throw new \RuntimeException( 'Order for event not found.' );
		}
			$user_id = $snapshot->user_id;
		if ( $user_id <= 0 ) {
				return array( 'mode' => 'noop' );
		}

			$earned   = $this->credits_for_order( $order_id );
			$reversed = $this->reversed_for_order( $order_id );
			$amount   = ReversalMath::full( $earned, $reversed );
		if ( $amount <= 0.0 ) {
				return array(
					'mode'   => 'reversal',
					'amount' => 0.0,
				);
		}

			$note = self::REVERSAL_NOTE_CANCELLED;
		if ( 'failed' === $status ) {
				$note = self::REVERSAL_NOTE_FAILED;
		}

			$this->ledger->adjust(
				$user_id,
				-1 * $amount,
				'order:' . $order_id . ':reversal',
				array(
					'ref_type' => 'order',
					'ref_id'   => $order_id,
					'rule_id'  => null,
					'note'     => $note,
				)
			);
			return array(
				'mode'   => 'reversal',
				'amount' => $amount,
				'note'   => $note,
			);
	}

		/**
		 * Proportional compensating reversal for one refund event.
		 *
		 * @param array<string,mixed> $event Event row.
		 * @return array<string,mixed>
		 * @throws \RuntimeException When the order cannot be loaded.
		 */
	public function proportional_reversal( array $event ): array {
			$order_id = (int) ( $event['object_id'] ?? 0 );
			$snapshot = $this->lookup->find( $order_id );
		if ( null === $snapshot ) {
				throw new \RuntimeException( 'Order for event not found.' );
		}
			$user_id = $snapshot->user_id;
		if ( $user_id <= 0 ) {
				return array( 'mode' => 'noop' );
		}

			$payload       = RuleEngine::json( $event['payload'] ?? null );
			$refund_id     = isset( $payload['refund_id'] ) ? (int) $payload['refund_id'] : 0;
			$refund_amount = isset( $payload['refund_amount'] ) ? (float) $payload['refund_amount'] : 0.0;
		if ( $refund_amount <= 0.0 ) {
				return array(
					'mode'   => 'reversal',
					'amount' => 0.0,
				);
		}
			$refund_amount = Money::round( $refund_amount );

			$earned   = $this->credits_for_order( $order_id );
			$reversed = $this->reversed_for_order( $order_id );
			$amount   = ReversalMath::proportional( $earned, $refund_amount, $snapshot->base_amount, $reversed );
		if ( $amount <= 0.0 ) {
				return array(
					'mode'   => 'reversal',
					'amount' => 0.0,
				);
		}

			$this->ledger->adjust(
				$user_id,
				-1 * $amount,
				'order:' . $order_id . ':refund:' . $refund_id . ':reversal',
				array(
					'ref_type' => 'order',
					'ref_id'   => $order_id,
					'rule_id'  => null,
					'note'     => self::REVERSAL_NOTE_REFUND,
				)
			);
			return array(
				'mode'   => 'reversal',
				'amount' => $amount,
				'note'   => self::REVERSAL_NOTE_REFUND,
			);
	}

		/**
		 * @return float Sum of earning credits granted for the order.
		 */
	private function credits_for_order( int $order_id ): float {
			$entries = $this->ledger_gateway->entries_for_reference( 'order', $order_id, array( 'credit' ) );
			$sum     = 0.0;
		foreach ( $entries as $entry ) {
				$sum += max( 0.0, (float) ( $entry['amount'] ?? 0.0 ) );
		}
			return $sum;
	}

		/**
		 * @return float Sum of prior reversal entries for the order.
		 * Reversals are adjust/debit entries whose idempotency key belongs to
		 * this order's reversal family, so spend debits (phase 3) never mix in.
		 */
	private function reversed_for_order( int $order_id ): float {
			$entries         = $this->ledger_gateway->entries_for_reference( 'order', $order_id, array( 'debit', 'adjust' ) );
			$reversal_prefix = 'order:' . $order_id . ':refund:';
			$full_key        = 'order:' . $order_id . ':reversal';
			$sum             = 0.0;
		foreach ( $entries as $entry ) {
				$key = (string) ( $entry['idempotency_key'] ?? '' );
			if ( $key === $full_key || 0 === strpos( $key, $reversal_prefix ) ) {
				$sum += abs( (float) ( $entry['amount'] ?? 0.0 ) );
			}
		}
			return $sum;
	}

		/**
		 * @param array<string,mixed> $event Event row.
		 * @return OrderSnapshot|null
		 */
	private function order_snapshot( array $event ): ?OrderSnapshot {
		if ( 'order' !== (string) ( $event['object_type'] ?? '' ) ) {
				return null;
		}
			return $this->lookup->find( (int) ( $event['object_id'] ?? 0 ) );
	}

		/**
		 * @param array<int,array<string,mixed>> $rules Rules about to be evaluated.
		 * @return bool
		 */
	private function needs_first_order( array $rules ): bool {
		foreach ( $rules as $rule ) {
				$conditions = RuleEngine::json( $rule['conditions'] ?? null );
			if ( ! empty( $conditions['first_order'] ) ) {
				return true;
			}
		}
			return false;
	}
}
