<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Core;

class Logger {
	/** @param array<string,mixed> $context */
	public function info( string $message, array $context = array() ): void {
		$this->write( 'info', $message, $context );
	}

	/** @param array<string,mixed> $context */
	public function error( string $message, array $context = array() ): void {
		$this->write( 'error', $message, $context );
	}

	/** @param array<string,mixed> $context */
	private function write( string $level, string $message, array $context ): void {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return;
		}

		$directory = trailingslashit( $upload['basedir'] ) . 'ariunetclub-logs';
		if ( ! wp_mkdir_p( $directory ) ) {
			return;
		}

		$this->protect_directory( $directory );
		$line = wp_json_encode(
			array(
				'time'    => gmdate( 'c' ),
				'level'   => $level,
				'message' => $message,
				'context' => $this->mask_context( $context ),
			)
		);
		if ( false !== $line ) {
			file_put_contents( trailingslashit( $directory ) . 'ariunetclub-' . gmdate( 'Y-m' ) . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	private function protect_directory( string $directory ): void {
		$files = array(
			'.htaccess'  => "Deny from all\n",
			'web.config' => '<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
			'index.html' => '',
		);
		foreach ( $files as $name => $contents ) {
			$path = trailingslashit( $directory ) . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $contents, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private function mask_context( array $context ): array {
		foreach ( $context as $key => $value ) {
			if ( is_string( $value ) && false !== stripos( (string) $key, 'phone' ) ) {
				$context[ $key ] = substr( $value, 0, 4 ) . '***' . substr( $value, -3 );
			}
		}
		return $context;
	}
}
