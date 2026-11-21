<?php
/**
 * QA gate: runs the static + unit + functional suites and writes qa-report.md.
 * Works on both Windows (cd /d) and Linux/macOS shells.
 */
$root     = dirname( __DIR__ );
$security = in_array( '--security', $argv, true );
$php      = '"' . PHP_BINARY . '"';
$commands = $security
	? array( $php . ' vendor/bin/psalm --taint-analysis' )
	: array(
		$php . ' vendor/bin/phpcs',
		$php . ' -d memory_limit=512M vendor/bin/phpstan analyse',
		$php . ' vendor/bin/phpunit',
		$php . ' tools/wallet-functional-tests.php',
		$php . ' tools/account-functional-tests.php',
		$php . ' tools/rebrand-functional-tests.php',
	);
$report = array( '# AriunetClub QA Report', '', 'Generated: ' . gmdate( 'c' ), '', '## Commands' );
$failed = false;
$cd     = ( '\\' === DIRECTORY_SEPARATOR ) ? 'cd /d ' : 'cd ';
foreach ( $commands as $command ) {
	$output = array(); $exit = 0;
	exec( $cd . escapeshellarg( $root ) . ' && ' . $command . ' 2>&1', $output, $exit );
	$report[] = ''; $report[] = '### ' . $command; $report[] = '```text'; $report[] = implode( PHP_EOL, $output ); $report[] = '```';
	if ( 0 !== $exit ) { $failed = true; }
}
file_put_contents( $root . DIRECTORY_SEPARATOR . 'qa-report.md', implode( PHP_EOL, $report ) . PHP_EOL );
exit( $failed ? 1 : 0 );
