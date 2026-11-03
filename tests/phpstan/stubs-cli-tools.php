<?php
/**
 * Declaration of the WP-CLI progress bar (php-cli-tools), missing from the
 * WP-CLI stubs, for PHPStan only.
 *
 * @package Lostlink
 */

// phpcs:ignoreFile -- Third-party declarations.

namespace cli\progress;

class Bar {
	public function tick( int $increment = 1, ?string $msg = null ): void {}

	public function finish(): void {}
}
