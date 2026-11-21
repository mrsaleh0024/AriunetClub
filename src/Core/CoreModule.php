<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Core;

class CoreModule implements Module {
	/** @var Logger */
	private $logger;

	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	public function id(): string {
		return 'core';
	}

	public function dependencies(): array {
		return array();
	}

	public function boot(): void {
		$this->logger->info( 'Core module booted.' );
	}
}
