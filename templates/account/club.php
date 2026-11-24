<?php
/**
 * "Club" tab template for the customer's My Account area.
 *
 * Override it by copying it to yourtheme/ariunetclub/account/club.php
 * (the legacy yourtheme/lclub/account/club.php path is honoured too).
 * The $data array is prepared by ClubEndpoint::build_view(); every value
 * is pre-formatted (Persian digits, Jalali dates) and must still be
 * escaped when printed.
 *
 * @package AriunetClub
 *
 * @var array<string,mixed> $data View model from ClubEndpoint::build_view().
 */

defined( 'ABSPATH' ) || exit;

use AriunetClub\Core\Money;

$club_currency = isset( $data['currency'] ) && is_string( $data['currency'] ) ? $data['currency'] : '';
?>
<div class="ariunetclub-scope ariunetclub-account" dir="rtl">
		<div class="ariunetclub-account__panel">
				<div class="ariunetclub-account__head">
						<span class="ariunetclub-account__kicker"><?php esc_html_e( 'ARIUNETCLUB', 'ariunetclub' ); ?></span>
						<h2 class="ariunetclub-account__title"><?php esc_html_e( 'باشگاه مشتریان', 'ariunetclub' ); ?></h2>
				</div>

				<?php if ( empty( $data['logged_in'] ) ) : ?>
						<div class="ariunetclub-account__notice">
								<p><?php esc_html_e( 'برای دیدن اعتبار باشگاه و کش‌بک خود، ابتدا وارد حساب کاربری شوید.', 'ariunetclub' ); ?></p>
						</div>
				<?php else : ?>
						<div class="ariunetclub-account__balance">
								<span class="ariunetclub-account__balance-label"><?php esc_html_e( 'موجودی قابل استفاده', 'ariunetclub' ); ?></span>
								<strong class="ariunetclub-account__balance-value"><?php echo esc_html( $data['available_display'] ); ?>
								<?php
								if ( '' !== $club_currency ) :
									?>
									<span class="ariunetclub-account__currency"><?php echo esc_html( $club_currency ); ?></span><?php endif; ?></strong>
								<dl class="ariunetclub-account__balance-details">
										<div>
												<dt><?php esc_html_e( 'رزروشده', 'ariunetclub' ); ?></dt>
												<dd><?php echo esc_html( $data['held_display'] ); ?></dd>
										</div>
										<div>
												<dt><?php esc_html_e( 'موبایل حساب', 'ariunetclub' ); ?></dt>
												<dd><?php echo esc_html( '' !== $data['phone_masked'] ? $data['phone_masked'] : '—' ); ?></dd>
										</div>
								</dl>
						</div>

						<?php if ( empty( $data['has_phone'] ) ) : ?>
								<div class="ariunetclub-account__notice" role="note">
										<svg class="ariunetclub-account__notice-icon" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 15h-2v-2h2v2Zm0-4h-2V7h2v6Z" fill="currentColor"/></svg>
										<p><?php esc_html_e( 'شماره موبایلی برای حساب شما ثبت نشده است؛ بدون آن کش‌بک خریدها به‌صورت خودکار ثبت نمی‌شود.', 'ariunetclub' ); ?>
										<?php if ( ! empty( $data['edit_address_url'] ) ) : ?>
												<a href="<?php echo esc_url( $data['edit_address_url'] ); ?>"><?php esc_html_e( 'ثبت شماره موبایل در جزئیات حساب', 'ariunetclub' ); ?></a>
										<?php endif; ?></p>
								</div>
						<?php endif; ?>
					<?php if ( ! empty( $data['lots'] ) ) : ?>
												<section class="ariunetclub-account__section" aria-labelledby="ariunetclub-account-lots">
																<div class="ariunetclub-account__section-head">
																				<h3 id="ariunetclub-account-lots"><?php esc_html_e( 'ردیف‌های اعتبار باشگاه', 'ariunetclub' ); ?></h3>
																				<?php if ( ! empty( $data['lots_total'] ) ) : ?>
																								<span class="ariunetclub-account__count"><?php echo esc_html( Money::to_persian_digits( (string) $data['lots_total'] ) ); ?></span>
																				<?php endif; ?>
																</div>
																<div class="ariunetclub-account__table-scroll">
																				<table class="ariunetclub-account__table">
																								<thead>
																												<tr>
																																<th scope="col"><?php esc_html_e( 'مانده', 'ariunetclub' ); ?></th>
																																<th scope="col"><?php esc_html_e( 'تاریخ انقضا', 'ariunetclub' ); ?></th>
																																<th scope="col"><?php esc_html_e( 'دلیل', 'ariunetclub' ); ?></th>
																												</tr>
																								</thead>
																								<tbody>
																								<?php foreach ( $data['lots'] as $club_lot ) : ?>
																												<tr>
																																<td class="ariunetclub-account__amount"><span dir="ltr"><?php echo esc_html( $club_lot['remaining_display'] ); ?></span>
																																<?php
																																if ( ! empty( $club_lot['held'] ) ) :
																																	?>
																																	<span class="ariunetclub-account__lot-held"><?php esc_html_e( '(شامل بخش رزروشده)', 'ariunetclub' ); ?></span><?php endif; ?></td>
																																<td><?php echo esc_html( $club_lot['expires_display'] ); ?></td>
																																<td><?php echo esc_html( $club_lot['reason'] ); ?></td>
																												</tr>
																								<?php endforeach; ?>
																								</tbody>
																				</table>
																</div>
																<?php if ( (int) $data['lots_total'] > count( $data['lots'] ) ) : ?>
																				<p class="ariunetclub-account__hint"><?php echo esc_html( sprintf( /* translators: %s: number of rows in Persian digits. */ __( 'و %s ردیف دیگر…', 'ariunetclub' ), Money::to_persian_digits( (string) ( (int) $data['lots_total'] - count( $data['lots'] ) ) ) ) ); ?></p>
																<?php endif; ?>
																<p class="ariunetclub-account__hint"><?php esc_html_e( 'ثبت اعتبار خودکار است؛ هنگام خرید، نزدیک‌ترین ردیف به انقضا اول خرج می‌شود.', 'ariunetclub' ); ?></p>
												</section>
												<?php endif; ?>
<?php endif; ?>

				<section class="ariunetclub-account__section" aria-labelledby="ariunetclub-account-history">
						<div class="ariunetclub-account__section-head">
								<h3 id="ariunetclub-account-history"><?php esc_html_e( 'تاریخچه تراکنش‌ها', 'ariunetclub' ); ?></h3>
								<?php if ( ! empty( $data['total'] ) ) : ?>
										<span class="ariunetclub-account__count"><?php echo esc_html( Money::to_persian_digits( (string) $data['total'] ) ); ?></span>
								<?php endif; ?>
						</div>

						<?php if ( empty( $data['entries'] ) ) : ?>
								<p class="ariunetclub-account__empty"><?php esc_html_e( 'هنوز تراکنشی ثبت نشده است؛ با ثبت و تکمیل سفارش‌ها، کش‌بک شما اینجا نمایش داده می‌شود.', 'ariunetclub' ); ?></p>
						<?php else : ?>
								<div class="ariunetclub-account__table-scroll">
										<table class="ariunetclub-account__table">
												<thead>
														<tr>
																<th scope="col"><?php esc_html_e( 'تاریخ', 'ariunetclub' ); ?></th>
																<th scope="col"><?php esc_html_e( 'نوع', 'ariunetclub' ); ?></th>
																<th scope="col"><?php esc_html_e( 'مبلغ', 'ariunetclub' ); ?></th>
																<th scope="col"><?php esc_html_e( 'توضیح', 'ariunetclub' ); ?></th>
														</tr>
												</thead>
												<tbody>
												<?php foreach ( $data['entries'] as $club_entry ) : ?>
														<tr>
																<td><?php echo esc_html( $club_entry['date'] ); ?></td>
																<td><span class="ariunetclub-account__badge ariunetclub-account__badge--<?php echo esc_attr( $club_entry['family'] ); ?>"><?php echo esc_html( $club_entry['label'] ); ?></span></td>
																<td class="ariunetclub-account__amount ariunetclub-account__amount--<?php echo esc_attr( $club_entry['family'] ); ?>"><span dir="ltr"><?php echo esc_html( $club_entry['amount_display'] ); ?></span></td>
																<td>
																		<?php echo esc_html( $club_entry['note'] ); ?>
																		<?php if ( ! empty( $club_entry['order_url'] ) ) : ?>
																				<a class="ariunetclub-account__order-link" href="<?php echo esc_url( $club_entry['order_url'] ); ?>"><?php echo esc_html( sprintf( /* translators: %s: order number in Persian digits. */ __( 'سفارش #%s', 'ariunetclub' ), Money::to_persian_digits( (string) $club_entry['order_id'] ) ) ); ?></a>
																		<?php endif; ?>
																</td>
														</tr>
												<?php endforeach; ?>
												</tbody>
										</table>
								</div>

								<?php if ( (int) $data['pages'] > 1 && function_exists( 'wc_get_endpoint_url' ) ) : ?>
										<nav class="ariunetclub-account__pagination" aria-label="<?php esc_attr_e( 'صفحه‌بندی تاریخچه', 'ariunetclub' ); ?>">
												<?php for ( $club_page = 1; $club_page <= (int) $data['pages']; $club_page++ ) : ?>
														<?php $club_url = (string) wc_get_endpoint_url( 'club', 1 === $club_page ? '' : 'page/' . $club_page ); ?>
														<a href="<?php echo esc_url( $club_url ); ?>"<?php echo $club_page === (int) $data['page'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( Money::to_persian_digits( (string) $club_page ) ); ?></a>
												<?php endfor; ?>
										</nav>
								<?php endif; ?>
						<?php endif; ?>
				</section>

				<section class="ariunetclub-account__section" aria-labelledby="ariunetclub-account-rules">
						<div class="ariunetclub-account__section-head">
								<h3 id="ariunetclub-account-rules"><?php esc_html_e( 'چطور کش‌بک بگیرم؟', 'ariunetclub' ); ?></h3>
						</div>

						<?php if ( empty( $data['rules'] ) ) : ?>
								<p class="ariunetclub-account__empty"><?php esc_html_e( 'در حال حاضر قانون کش‌بک فعالی وجود ندارد؛ به‌زودی قوانین جدید اضافه می‌شود.', 'ariunetclub' ); ?></p>
						<?php else : ?>
								<ul class="ariunetclub-account__rules">
										<?php foreach ( $data['rules'] as $club_rule ) : ?>
												<li>
														<span class="ariunetclub-account__rule-title"><?php echo esc_html( $club_rule['title'] ); ?></span>
														<span class="ariunetclub-account__rule-reward"><?php echo esc_html( $club_rule['reward'] ); ?>
														<?php
														if ( 'fixed' === ( $club_rule['reward_kind'] ?? '' ) && '' !== $club_currency ) :
															?>
															<?php echo esc_html( $club_currency ); ?><?php endif; ?></span>
												</li>
										<?php endforeach; ?>
								</ul>
								<p class="ariunetclub-account__hint"><?php esc_html_e( 'کش‌بک پس از رسیدن سفارش به وضعیت تعیین‌شدهٔ هر قانون، به‌صورت خودکار به موجودی شما اضافه می‌شود و در چک‌اوت بعدی قابل خرج کردن است.', 'ariunetclub' ); ?></p>
						<?php endif; ?>
				</section>
		</div>
</div>
