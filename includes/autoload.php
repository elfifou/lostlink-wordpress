<?php
/**
 * Autoloader for the plugin classes.
 *
 * Maps Lostlink\Hit_Recorder to includes/class-hit-recorder.php and
 * Lostlink\Redirect_Backend to includes/interface-redirect-backend.php,
 * Lostlink\Priority (an enum) to includes/enum-priority.php,
 * following the WordPress file naming conventions.
 *
 * @package Lostlink
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Lostlink\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$name = str_replace( '_', '-', strtolower( substr( $class_name, strlen( $prefix ) ) ) );
		foreach ( array( 'class', 'interface', 'enum' ) as $kind ) {
			$file = __DIR__ . "/$kind-$name.php";
			if ( is_readable( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
);
