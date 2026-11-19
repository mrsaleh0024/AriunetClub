<?php
/**
 * @package LClub
 */

namespace LClub\Core;

use RuntimeException;

class ModuleRegistry {
	/** @var Module[] */
	private $modules = array();

	/** @var string[] */
	private $booted = array();

	public function register( Module $module ): void {
		$this->modules[ $module->id() ] = $module;
	}

	/**
	 * @param string[] $enabled_modules Module IDs explicitly enabled in settings.
	 */
	public function boot( array $enabled_modules ): void {
		foreach ( $enabled_modules as $module_id ) {
			$this->boot_module( $module_id );
		}
	}

	private function boot_module( string $module_id ): void {
		if ( in_array( $module_id, $this->booted, true ) ) {
			return;
		}

		if ( ! isset( $this->modules[ $module_id ] ) ) {
			throw new RuntimeException( sprintf( 'LClub module "%s" is not registered.', esc_html( $module_id ) ) );
		}

		foreach ( $this->modules[ $module_id ]->dependencies() as $dependency ) {
			$this->boot_module( $dependency );
		}

		$this->modules[ $module_id ]->boot();
		$this->booted[] = $module_id;
	}
}
