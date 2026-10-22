<?php
/** Application service loading. Features remain opt-in through object configuration. */
namespace ACF_VF\App;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static $started = false;
	public static function boot(): void {
		spl_autoload_register( static function ( string $class ): void {
			$prefix = __NAMESPACE__ . '\\';
			if ( strpos( $class, $prefix ) !== 0 ) { return; }
			$name = substr( $class, strlen( $prefix ) );
			if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_]*$/D', $name ) ) { return; }
			$file = __DIR__ . '/' . $name . '.php';
			if ( is_file( $file ) ) { require_once $file; }
		} );
        Localization::early_boot();
        // Stored application data remains protected while ACF is unavailable.
        NativeGuard::boot();
        Schema::early_boot();
        add_action( 'acf/init', array( __CLASS__, 'services' ), 30 );
	}

	public static function services(): void {
		if (self::$started) { return; } self::$started = true;
        do_action('acf_vf/app/register_extensions');
        Extensions::finish();
		foreach ( array( 'NativeGuard', 'Rules', 'NativeFields', 'BusinessFields', 'Relationships', 'Audit', 'Jobs', 'Index', 'Localization', 'Lists', 'Rest', 'GraphQL', 'Admin', 'Schema' ) as $name ) {
			$class = __NAMESPACE__ . '\\' . $name;
			if ( class_exists( $class ) && is_callable( array( $class, 'boot' ) ) ) { $class::boot(); }
		}
        // Generic handlers may register later in the request. Only known absent
        // providers can be parked at bootstrap; the worker checks all other kinds.
        $missing=[];
        foreach (['email'=>'automation.actions','webhook'=>'automation.actions','record_action'=>'automation.actions','rollup'=>'calculations.rollup'] as $kind=>$feature) {
            if (!Extensions::has($feature)) { $missing[]=$kind; }
        }
        if ($missing) { Jobs::park_unavailable($missing); }
	}
}
