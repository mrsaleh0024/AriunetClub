<?php
/**
 * ایجاد دادهٔ نمایشی فاز اول؛ فقط از خط فرمان اجرا شود.
 *
 * @package LClub
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$site_path = dirname( __DIR__, 4 );
require_once $site_path . '/wp-load.php';

if ( empty( $GLOBALS['lclub_ledger_service'] ) || ! $GLOBALS['lclub_ledger_service'] instanceof \LClub\Modules\Ledger\LedgerService ) {
	fwrite( STDERR, "LClub ledger service is unavailable.\n" );
	exit( 1 );
}

$ledger = $GLOBALS['lclub_ledger_service'];
$customers = array(
	array(
		'email'        => 'lclub-demo-1@example.test',
		'display_name' => 'مشتری آزمایشی یک',
		'phone'        => '09121234567',
		'entries'      => array(
			array( 'method' => 'credit', 'amount' => 150000, 'key' => 'demo-phase1-1-credit', 'note' => 'اعتبار آزمایشی' ),
			array( 'method' => 'debit', 'amount' => 30000, 'key' => 'demo-phase1-1-debit', 'note' => 'مصرف آزمایشی' ),
		),
	),
	array(
		'email'        => 'lclub-demo-2@example.test',
		'display_name' => 'مشتری آزمایشی دو',
		'phone'        => '+989121234568',
		'entries'      => array(
			array( 'method' => 'credit', 'amount' => 200000, 'key' => 'demo-phase1-2-credit', 'note' => 'اعتبار آزمایشی' ),
			array( 'method' => 'hold', 'amount' => 50000, 'key' => 'demo-phase1-2-hold', 'note' => 'رزرو آزمایشی' ),
		),
	),
	array(
		'email'        => 'lclub-demo-3@example.test',
		'display_name' => 'مشتری آزمایشی سه',
		'phone'        => '۰۹۱۲۱۲۳۴۵۶۹',
		'entries'      => array(
			array( 'method' => 'credit', 'amount' => 100000, 'key' => 'demo-phase1-3-credit', 'note' => 'اعتبار آزمایشی' ),
			array( 'method' => 'adjust', 'amount' => -20000, 'key' => 'demo-phase1-3-adjust', 'note' => 'تنظیم آزمایشی' ),
		),
	),
);

foreach ( $customers as $customer ) {
	$user = get_user_by( 'email', $customer['email'] );
	if ( ! $user ) {
		$user_id = wp_insert_user(
			array(
				'user_login'   => sanitize_user( $customer['email'], true ),
				'user_email'   => $customer['email'],
				'display_name' => $customer['display_name'],
				'user_pass'    => wp_generate_password( 32, true, true ),
				'role'         => 'customer',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			fwrite( STDERR, $user_id->get_error_message() . "\n" );
			continue;
		}
	} else {
		$user_id = (int) $user->ID;
	}

	update_user_meta( $user_id, 'billing_phone', $customer['phone'] );
	foreach ( $customer['entries'] as $entry ) {
		$ledger->{$entry['method']}(
			$user_id,
			(float) $entry['amount'],
			$entry['key'],
			array(
				'note'       => $entry['note'],
				'ref_type'   => 'demo',
				'created_by' => 0,
			)
		);
	}

	echo $customer['display_name'] . ' — ' . $customer['phone'] . "\n";
}
