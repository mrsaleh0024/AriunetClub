<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Account;

use AriunetClub\Core\JalaliDate;
use AriunetClub\Core\Money;
use AriunetClub\Modules\Identity\IdentityService;
use AriunetClub\Modules\Ledger\LedgerGateway;
use AriunetClub\Modules\Rules\RuleGateway;
use AriunetClub\Modules\Rules\RuleEngine;
use AriunetClub\Modules\Rules\RulePublicSummary;

/**
 * Phase 4 endpoint: the "club" tab inside WooCommerce My Account.
 *
 * Registers the rewrite endpoint (with a one-time flush guard), puts the tab
 * first in the account menu and renders a theme-overridable template. Every
 * query is keyed to get_current_user_id(), so a customer can only ever see
 * their own balance and history.
 */
class ClubEndpoint {

				const ENDPOINT        = 'club';
				const PER_PAGE        = 20;
				const REWRITE_VERSION = '1';

				/** @var LedgerGateway */
				private $ledger;

				/** @var RuleGateway */
				private $rules;

				/** @var IdentityService */
				private $identity;

				/**
				 * @param LedgerGateway    $ledger   Ledger storage.
				 * @param RuleGateway      $rules    Rule storage.
				 * @param IdentityService  $identity Phone identity service.
				 */
	public function __construct( LedgerGateway $ledger, RuleGateway $rules, IdentityService $identity ) {
					$this->ledger   = $ledger;
					$this->rules    = $rules;
					$this->identity = $identity;
	}

	public function register(): void {
					add_action( 'init', array( self::class, 'register_rewrite' ) );
					add_action( 'init', array( $this, 'maybe_flush_rewrite' ), 99 );
					add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
					add_action( 'woocommerce_account_club_endpoint', array( $this, 'render' ) );
					add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

				/**
				 * Registers the /my-account/club/ rewrite endpoint. Also called from the
				 * activation hook so a flush right after activation already includes it.
				 */
	public static function register_rewrite(): void {
					add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

				/**
				 * Flushes the rewrite rules once per endpoint version, so installs that
				 * update the plugin without a deactivate/reactivate cycle still get the
				 * club rules without asking the shop owner to save permalinks manually.
				 */
	public function maybe_flush_rewrite(): void {
		if ( get_option( 'ariunetclub_rewrite_version' ) !== self::REWRITE_VERSION ) {
						flush_rewrite_rules();
						update_option( 'ariunetclub_rewrite_version', self::REWRITE_VERSION, false );
		}
	}

				/**
				 * Puts «باشگاه» first in the My Account navigation.
				 *
				 * @param array<string,string> $items Endpoint slug => label.
				 * @return array<string,string>
				 */
	public function add_menu_item( array $items ): array {
					return array_merge( array( self::ENDPOINT => __( 'باشگاه', 'ariunetclub' ) ), $items );
	}

				/**
				 * Loads the account stylesheet on the club tab only.
				 */
	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_account_page' ) || ! function_exists( 'is_wc_endpoint_url' ) ) {
						return;
		}
		if ( ! is_account_page() || ! is_wc_endpoint_url( self::ENDPOINT ) ) {
						return;
		}
					wp_enqueue_style( 'ariunetclub-account', ARIUNETCLUB_URL . 'assets/css/ariunetclub-account.css', array(), ARIUNETCLUB_VERSION );
	}

				/**
				 * Renders the tab content through the overridable template.
				 */
	public function render(): void {
					$data = $this->build_view( (int) get_current_user_id(), $this->current_page() );

					$template = locate_template( array( 'ariunetclub/account/club.php', 'lclub/account/club.php' ) );
		if ( '' === $template ) {
						$template = ARIUNETCLUB_PATH . 'templates/account/club.php';
		}
					include $template; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template path is fixed or theme-owned; the template escapes its output.
	}

				/**
				 * Page number parsed from the endpoint value (/club/page/2/ or /club/2/).
				 * Anything unexpected falls back to page 1, the patterns have no nested
				 * quantifiers (no regex backtracking) and the result is capped so a
				 * hostile page number can never overflow the SQL OFFSET math.
				 */
	public function current_page(): int {
					$raw   = get_query_var( self::ENDPOINT );
					$value = is_string( $raw ) ? trim( $raw, '/' ) : '';
					$page  = 1;
		if ( 1 === preg_match( '#^page/([0-9]+)$#', $value, $matches ) ) {
						$page = (int) $matches[1];
		} elseif ( 1 === preg_match( '#^[0-9]+$#', $value ) ) {
						$page = (int) $value;
		}
					return max( 1, min( 1000000, $page ) );
	}

				/**
				 * Builds the whole view model for the template. All formatting (Persian
				 * digits, Jalali dates, signed amounts, masked phone) happens here so the
				 * template only escapes and prints.
				 *
				 * @param int $user_id Current user id (0 when logged out).
				 * @param int $page    Requested history page.
				 * @return array<string,mixed>
				 */
	public function build_view( int $user_id, int $page ): array {
					$view = array(
						'user_id'           => $user_id,
						'logged_in'         => $user_id > 0,
						'has_phone'         => false,
						'phone_masked'      => '',
						'available'         => 0.0,
						'available_display' => Money::format( 0.0 ),
						'held'              => 0.0,
						'held_display'      => Money::format( 0.0 ),
						'entries'           => array(),
						'total'             => 0,
						'page'              => 1,
						'pages'             => 1,
						'rules'             => array(),
						'currency'          => self::currency_symbol(),
						'edit_address_url'  => self::edit_address_url(),
					);

					if ( $user_id <= 0 ) {
									return $view;
					}

					$balance = $this->ledger->balance( $user_id );
					$phone   = $this->identity->phone_for_user( $user_id );

					$view['available']         = isset( $balance['available'] ) && is_numeric( $balance['available'] ) ? (float) $balance['available'] : 0.0;
					$view['held']              = isset( $balance['held'] ) && is_numeric( $balance['held'] ) ? (float) $balance['held'] : 0.0;
					$view['available_display'] = Money::format( $view['available'] );
					$view['held_display']      = Money::format( $view['held'] );
					if ( is_string( $phone ) && '' !== $phone ) {
									$view['has_phone']    = true;
									$view['phone_masked'] = self::mask_phone( $phone );
					}

					$page    = max( 1, $page );
					$history = $this->ledger->history( $user_id, 'cashback', $page, self::PER_PAGE );
					$total   = $history['total'];
					$pages   = max( 1, (int) ceil( $total / self::PER_PAGE ) );
					if ( $page > $pages ) {
									$page    = $pages;
									$history = $this->ledger->history( $user_id, 'cashback', $page, self::PER_PAGE );
					}
					$view['page']    = $page;
					$view['pages']   = $pages;
					$view['total']   = $total;
					$view['entries'] = array_map( array( $this, 'format_entry' ), $history['items'] );

					$rules         = $this->rules->active_for_display( (string) current_time( 'mysql', true ) );
					$view['rules'] = array_map(
						static function ( array $row ): array {
											$reward = RuleEngine::json( $row['reward'] ?? null );
											$type   = ( isset( $reward['type'] ) && is_string( $reward['type'] ) ) ? $reward['type'] : 'percent';
											$value  = ( isset( $reward['value'] ) && is_numeric( $reward['value'] ) ) ? (float) $reward['value'] : 0.0;
											return array(
												'id'     => ( isset( $row['id'] ) && is_numeric( $row['id'] ) ) ? (int) $row['id'] : 0,
												'title'  => ( isset( $row['title'] ) && is_string( $row['title'] ) ) ? $row['title'] : '',
												'reward' => RulePublicSummary::reward_text( $row ),
												'reward_kind' => ( $value > 0.0 && in_array( $type, array( 'percent', 'fixed' ), true ) ) ? $type : '',
											);
						},
						$rules
					);

					return $view;
	}

				/**
				 * Maps one ledger row onto the customer-facing history shape.
				 *
				 * @param array<string,mixed> $row Ledger row.
				 * @return array<string,mixed>
				 */
	private function format_entry( array $row ): array {
					$type   = ( isset( $row['entry_type'] ) && is_string( $row['entry_type'] ) ) ? $row['entry_type'] : '';
					$amount = isset( $row['amount'] ) && is_numeric( $row['amount'] ) ? (float) $row['amount'] : 0.0;
					$ref_id = ( isset( $row['ref_id'] ) && is_numeric( $row['ref_id'] ) ) ? (int) $row['ref_id'] : 0;
					$ref    = ( isset( $row['ref_type'] ) && is_string( $row['ref_type'] ) ) ? $row['ref_type'] : '';

					$entry = array(
						'date'           => ( isset( $row['created_at'] ) && is_string( $row['created_at'] ) ) ? Money::to_persian_digits( JalaliDate::format( $row['created_at'] ) ) : '',
						'type'           => $type,
						'label'          => self::entry_label( $type ),
						'family'         => self::entry_family( $type ),
						'amount_display' => '—',
						'note'           => isset( $row['note'] ) && is_scalar( $row['note'] ) ? (string) $row['note'] : '',
						'order_id'       => ( 'order' === $ref && $ref_id > 0 ) ? $ref_id : 0,
						'order_url'      => '',
					);

					if ( $amount > 0.0 ) {
									$entry['amount_display'] = '+' . Money::format( $amount );
					} elseif ( $amount < 0.0 ) {
									$entry['amount_display'] = '−' . Money::format( abs( $amount ) );
					}

					if ( $entry['order_id'] > 0 && function_exists( 'wc_get_endpoint_url' ) ) {
									$entry['order_url'] = (string) wc_get_endpoint_url( 'view-order', (string) $entry['order_id'] );
					}

					return $entry;
	}

				/** Persian label for a ledger entry type. */
	public static function entry_label( string $type ): string {
					$labels = array(
						'credit'  => __( 'کسب', 'ariunetclub' ),
						'debit'   => __( 'خرج', 'ariunetclub' ),
						'hold'    => __( 'رزرو', 'ariunetclub' ),
						'capture' => __( 'ثبت خرج', 'ariunetclub' ),
						'release' => __( 'آزادسازی رزرو', 'ariunetclub' ),
						'adjust'  => __( 'اصلاح', 'ariunetclub' ),
					);
					return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}

				/** Colour family (success/danger/warning/neutral) for a ledger entry type. */
	public static function entry_family( string $type ): string {
					$families = array(
						'credit'  => 'success',
						'release' => 'success',
						'debit'   => 'danger',
						'capture' => 'danger',
						'hold'    => 'warning',
						'adjust'  => 'neutral',
					);
					return isset( $families[ $type ] ) ? $families[ $type ] : 'neutral';
	}

				/**
				 * Masks a normalized mobile number: ۰۹۱۲***۴۵۶۷. The full number never
				 * reaches the browser.
				 */
	private static function mask_phone( string $phone ): string {
					$digits = preg_replace( '/[^0-9]/', '', $phone );
		if ( ! is_string( $digits ) || strlen( $digits ) < 7 ) {
						return '';
		}
					return Money::to_persian_digits( '0' . substr( $digits, 0, 3 ) . '***' . substr( $digits, -3 ) );
	}

	private static function currency_symbol(): string {
					return function_exists( 'get_woocommerce_currency_symbol' ) ? (string) get_woocommerce_currency_symbol() : '';
	}

	private static function edit_address_url(): string {
		if ( ! function_exists( 'wc_get_endpoint_url' ) || ! function_exists( 'wc_get_page_permalink' ) ) {
						return '';
		}
					return (string) wc_get_endpoint_url( 'edit-address', '', (string) wc_get_page_permalink( 'myaccount' ) );
	}
}
