<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Events;

use LClub\Core\JalaliDate;
use LClub\Core\Money;

/**
 * Admin log of the event queue: status, attempts, errors and manual requeue.
 */
class EventsAdminPage {
		/** @var EventRepository */
		private $repository;

		/** @var Processor */
		private $processor;

	public function __construct( EventRepository $repository, Processor $processor ) {
			$this->repository = $repository;
			$this->processor  = $processor;
	}

	public function register(): void {
			add_action( 'admin_menu', array( $this, 'menu' ), 30 );
			add_action( 'admin_post_lclub_requeue_event', array( $this, 'requeue' ) );
	}

	public function menu(): void {
			add_submenu_page(
				'lclub-settings',
				__( 'رویدادها', 'lclub' ),
				__( 'رویدادها', 'lclub' ),
				'manage_woocommerce',
				'lclub-events',
				array( $this, 'render' )
			);
	}

		/**
		 * Handles the admin requeue action (S3, S4).
		 */
	public function requeue(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'شما اجازهٔ دسترسی به این صفحه را ندارید.', 'lclub' ) );
		}
			check_admin_referer( 'lclub_requeue_event' );

			$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		if ( $event_id <= 0 ) {
				wp_die( esc_html__( 'رویداد نامعتبر است.', 'lclub' ) );
		}

			$this->repository->requeue( $event_id );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( 'lclub_process_events', array(), 'lclub' );
		} elseif ( ! wp_next_scheduled( 'lclub_process_events' ) ) {
				wp_schedule_single_event( time() + 5, 'lclub_process_events' );
		}
			// Drain the queue right away so the admin sees the result on this visit.
			$this->processor->process_pending();

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'         => 'lclub-events',
						'lclub_notice' => 'requeued',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
	}

		/**
		 * Renders the events log.
		 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'شما اجازهٔ دسترسی به این صفحه را ندارید.', 'lclub' ) );
		}

			// Read-only GET filters keep shareable URLs; state changes happen through POST nonces.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sanitized read-only input.
			$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sanitized read-only input.
			$page     = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
			$result   = $this->repository->list( $status, $page, 20 );
			$counts   = $this->repository->status_counts();
			$statuses = EventLabels::queue_statuses();
		if ( ! isset( $statuses[ $status ] ) ) {
				$status = '';
		}
			$base_url = add_query_arg( array( 'page' => 'lclub-events' ), admin_url( 'admin.php' ) );
		?>
				<div class="wrap lclub-scope" dir="rtl">
						<div class="lclub-admin-shell">
								<header class="lclub-admin-header">
										<span class="lclub-admin-kicker"><?php esc_html_e( 'ARIUNETCLUB', 'lclub' ); ?></span>
										<h1><?php esc_html_e( 'لاگ رویدادها', 'lclub' ); ?></h1>
										<p><?php esc_html_e( 'صف رویدادهای باشگاه مشتریان؛ پردازش ناهمزمان با صف برنامه‌ریزی‌شده انجام می‌شود.', 'lclub' ); ?></p>
								</header>

							<?php if ( isset( $_GET['lclub_notice'] ) && 'requeued' === sanitize_key( wp_unslash( $_GET['lclub_notice'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status message. ?>
										<div class="lclub-notice lclub-notice--info"><?php esc_html_e( 'رویداد برای پردازش مجدد در صف قرار گرفت.', 'lclub' ); ?></div>
								<?php endif; ?>

								<section class="lclub-panel lclub-events-summary">
									<?php foreach ( $statuses as $key => $label ) : ?>
												<span class="lclub-events-summary__item lclub-badge lclub-badge--<?php echo esc_attr( $key ); ?>">
														<?php echo esc_html( $label ); ?>
														<strong><?php echo esc_html( Money::to_persian_digits( (string) ( $counts[ $key ] ?? 0 ) ) ); ?></strong>
												</span>
										<?php endforeach; ?>
								</section>

								<section class="lclub-panel">
										<form method="get" class="lclub-filter">
												<input type="hidden" name="page" value="lclub-events" />
												<label for="lclub-event-status"><?php esc_html_e( 'وضعیت', 'lclub' ); ?></label>
												<select id="lclub-event-status" name="status">
														<option value=""><?php esc_html_e( 'همه', 'lclub' ); ?></option>
													<?php foreach ( $statuses as $key => $label ) : ?>
																<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
														<?php endforeach; ?>
												</select>
												<button class="button button-primary" type="submit"><?php esc_html_e( 'اعمال', 'lclub' ); ?></button>
										</form>

									<?php if ( empty( $result['items'] ) ) : ?>
												<div class="lclub-empty">
														<h2><?php esc_html_e( 'هنوز رویدادی ثبت نشده است', 'lclub' ); ?></h2>
														<p><?php esc_html_e( 'با اولین سفارش، ثبت‌نام یا دیدگاه تأییدشده، رویدادها این‌جا دیده می‌شوند.', 'lclub' ); ?></p>
												</div>
										<?php else : ?>
												<div class="lclub-table-scroll">
														<table class="lclub-table lclub-events-table">
																<thead>
																		<tr>
																				<th><?php esc_html_e( '#', 'lclub' ); ?></th>
																				<th><?php esc_html_e( 'رویداد', 'lclub' ); ?></th>
																				<th><?php esc_html_e( 'وضعیت', 'lclub' ); ?></th>
																				<th><?php esc_html_e( 'تلاش', 'lclub' ); ?></th>
																				<th><?php esc_html_e( 'ثبت', 'lclub' ); ?></th>
																				<th><?php esc_html_e( 'پردازش', 'lclub' ); ?></th>
																				<th><?php esc_html_e( 'جزئیات', 'lclub' ); ?></th>
																		</tr>
																</thead>
																<tbody>
																		<?php foreach ( $result['items'] as $event ) : ?>
																				<?php
																				$event_id   = (int) $event['id'];
																				$type       = (string) $event['type'];
																				$state      = (string) $event['status'];
																				$payload    = json_decode( (string) $event['payload'], true );
																				$payload    = is_array( $payload ) ? $payload : array();
																				$error      = (string) ( $event['error'] ?? '' );
																				$object_url = $this->object_link( (string) $event['object_type'], (int) $event['object_id'] );
																				?>
																				<tr>
																						<td><?php echo esc_html( Money::to_persian_digits( (string) $event_id ) ); ?></td>
																						<td>
																								<strong><?php echo esc_html( EventLabels::type( $type ) ); ?></strong>
																								<?php if ( '' !== $object_url ) : ?>
																										— <a href="<?php echo esc_url( $object_url ); ?>"><?php echo esc_html( EventLabels::objects()[ $event['object_type'] ] ?? (string) $event['object_type'] ); ?> <?php echo esc_html( Money::to_persian_digits( (string) $event['object_id'] ) ); ?></a>
																								<?php else : ?>
																										— <?php echo esc_html( EventLabels::objects()[ $event['object_type'] ] ?? (string) $event['object_type'] ); ?> <?php echo esc_html( Money::to_persian_digits( (string) $event['object_id'] ) ); ?>
																								<?php endif; ?>
																								<?php if ( isset( $payload['to'] ) ) : ?>
																										<br /><span class="lclub-muted"><?php echo esc_html( (string) $payload['from'] ) . ' ← ' . esc_html( EventLabels::order_status( (string) $payload['to'] ) ); ?></span>
																								<?php elseif ( isset( $payload['refund_amount'] ) ) : ?>
																										<br /><span class="lclub-muted"><?php esc_html_e( 'مبلغ مستردشده:', 'lclub' ); ?> <?php echo esc_html( Money::format( (float) $payload['refund_amount'] ) ); ?></span>
																								<?php endif; ?>
																						</td>
																						<td><span class="lclub-badge lclub-badge--<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $statuses[ $state ] ?? $state ); ?></span></td>
																						<td><?php echo esc_html( Money::to_persian_digits( (string) ( $event['attempts'] ?? 0 ) ) . ' / ' . Money::to_persian_digits( (string) Processor::MAX_ATTEMPTS ) ); ?></td>
																						<td><?php echo esc_html( JalaliDate::format( (string) $event['created_at'] ) ); ?></td>
																						<td><?php echo esc_html( null === $event['processed_at'] ? '—' : JalaliDate::format( (string) $event['processed_at'] ) ); ?></td>
																						<td class="lclub-event-details">
																								<?php if ( '' !== $error ) : ?>
																										<span class="lclub-event-error" title="<?php echo esc_attr( $error ); ?>"><?php echo esc_html( $error ); ?></span>
																								<?php endif; ?>
																								<?php if ( in_array( $state, array( 'failed', 'skipped' ), true ) ) : ?>
																										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
																												<input type="hidden" name="action" value="lclub_requeue_event" />
																												<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>" />
																												<?php wp_nonce_field( 'lclub_requeue_event' ); ?>
																												<button class="button lclub-button-secondary" type="submit"><?php esc_html_e( 'پردازش مجدد', 'lclub' ); ?></button>
																										</form>
																								<?php endif; ?>
																								<?php if ( ! empty( $payload ) ) : ?>
																										<?php $payload_json = (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ); ?>
																										<details class="lclub-payload">
																												<summary><?php esc_html_e( 'داده', 'lclub' ); ?></summary>
																												<code><?php echo esc_html( $payload_json ); ?></code>
																										</details>
																								<?php endif; ?>
																						</td>
																				</tr>
																		<?php endforeach; ?>
																</tbody>
														</table>
												</div>
												<?php $this->pagination( $page, $result['total'], $base_url, $status ); ?>
										<?php endif; ?>
								</section>
						</div>
				</div>
				<?php
	}

		/**
		 * Deep link to the related object when possible.
		 */
	private function object_link( string $object_type, int $object_id ): string {
		if ( 'order' === $object_type && $object_id > 0 ) {
				return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $object_id );
		}
		if ( 'user' === $object_type && $object_id > 0 ) {
				return admin_url( 'user-edit.php?user_id=' . $object_id );
		}
		if ( 'product_review' === $object_type && $object_id > 0 ) {
				return admin_url( 'comment.php?action=editcomment&c=' . $object_id );
		}
			return '';
	}

		/**
		 * @param int    $page     Current page.
		 * @param int    $total    Total rows.
		 * @param string $base_url Base URL with page param.
		 * @param string $status   Active status filter.
		 */
	private function pagination( int $page, int $total, string $base_url, string $status ): void {
			$pages = (int) ceil( $total / 20 );
		if ( $pages <= 1 ) {
				return;
		}
			echo '<nav class="lclub-pagination" aria-label="' . esc_attr__( 'صفحه‌بندی رویدادها', 'lclub' ) . '">';
		$limit = min( $pages, 10 );
		for ( $i = 1; $i <= $limit; ++$i ) {
				$url   = add_query_arg(
					array(
						'paged'  => $i,
						'status' => $status,
					),
					$base_url
				);
				$class = $i === $page ? ' lclub-pagination-current' : '';
				echo '<a class="' . esc_attr( ltrim( $class ) ) . '" href="' . esc_url( $url ) . '">' . esc_html( Money::to_persian_digits( (string) $i ) ) . '</a>';
		}
			echo '</nav>';
	}
}
