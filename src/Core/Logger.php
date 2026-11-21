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
			$file = trailingslashit( $directory ) . 'ariunetclub-' . gmdate( 'Y-m' ) . '.log';
			$this->rotate_if_huge( $file );
			file_put_contents( $file, $line . PHP_EOL, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
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
				$context[ $key ] = self::mask_value( $value );
			}
		}
		return $context;
	}

	/**
	 * Masks a phone-like value; short values are hidden entirely so the
	 * mask itself can never reveal the whole number.
	 */
	private static function mask_value( string $value ): string {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
		if ( $length < 8 ) {
			return '***';
		}
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, 4, 'UTF-8' ) . '***' . mb_substr( $value, -3, 3, 'UTF-8' );
		}
		return substr( $value, 0, 4 ) . '***' . substr( $value, -3 );
	}

	/**
	 * Keeps monthly log files bounded: a file past ~5 MB is rotated away once.
	 */
	private function rotate_if_huge( string $file ): void {
		$size = is_file( $file ) ? (int) filesize( $file ) : 0; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_file, WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- cheap stat check before the append.
		if ( $size < 5 * 1024 * 1024 ) {
			return;
		}
		rename( $file, $file . '.old' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- a single rotation per month keeps disk usage bounded.
	}
}
