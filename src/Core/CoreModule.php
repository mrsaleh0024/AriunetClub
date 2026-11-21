<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Core;

class CoreModule implements Module {
	public function id(): string {
		return 'core';
	}

	public function dependencies(): array {
		return array();
	}

	public function boot(): void {
	}
}
