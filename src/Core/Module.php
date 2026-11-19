<?php
/**
 * @package LClub
 */

namespace LClub\Core;

interface Module {
	public function id(): string;

	/**
	 * @return string[]
	 */
	public function dependencies(): array;

	public function boot(): void;
}
