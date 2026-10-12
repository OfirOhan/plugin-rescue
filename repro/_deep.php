<?php
// Deep repro shared by several plugins: renders every admin page the plugin registers
// (normal and with a failed HTTP request), plus front-end wp_head/wp_footer output.
// Collects any PHP Warning/Notice/Deprecated/Fatal raised from plugins/<slug>/.
// Usage: repro/<slug>.php sets $rescue_slug and includes this file.
global $rescue_slug;
$slug = $rescue_slug;
$needle = 'plugins/' . $slug . '/';
$problems = array();
set_error_handler( function ( $no, $msg, $file, $line ) use ( &$problems, $needle ) {
	if ( false !== strpos( $file, $needle ) ) {
		$types = array( E_WARNING => 'Warning', E_NOTICE => 'Notice', E_DEPRECATED => 'Deprecated', E_USER_WARNING => 'Warning', E_USER_NOTICE => 'Notice', E_USER_DEPRECATED => 'Deprecated' );
		$problems[ "$msg|$file|$line" ] = 'PHP ' . ( $types[ $no ] ?? 'Error' ) . ": $msg in $file on line $line";
	}
	return false;
} );
$catch = function ( $label, $fn ) use ( &$problems, $needle ) {
	ob_start();
	try { $fn(); } catch ( Throwable $e ) {
		$problems[ $label . $e->getMessage() ] = "PHP Fatal error ($label): Uncaught " . get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
	}
	$html = ob_get_clean();
	echo "$label: " . strlen( $html ) . " bytes\n";
	return $html;
};

require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user( 1 );
set_current_screen( 'dashboard' );
$catch( 'admin_init', function () { do_action( 'admin_init' ); } );
$catch( 'admin_menu', function () { do_action( 'admin_menu' ); } );

// Find admin page hooks whose callbacks live in this plugin.
global $wp_filter, $_registered_pages;
$hooks = array();
foreach ( array_keys( (array) $_registered_pages ) as $hook ) {
	if ( empty( $wp_filter[ $hook ] ) ) { continue; }
	foreach ( $wp_filter[ $hook ]->callbacks as $cbs ) {
		foreach ( $cbs as $cb ) {
			try {
				$f = $cb['function'];
				$r = is_array( $f ) ? new ReflectionMethod( $f[0], $f[1] ) : ( is_string( $f ) && strpos( $f, '::' ) ? new ReflectionMethod( $f ) : new ReflectionFunction( $f ) );
				if ( false !== strpos( (string) $r->getFileName(), $needle ) ) { $hooks[ $hook ] = true; }
			} catch ( Throwable $e ) {}
		}
	}
}
echo 'plugin admin pages: ' . implode( ', ', array_keys( $hooks ) ) . "\n";

foreach ( array( 'normal', 'http_fail' ) as $mode ) {
	if ( 'http_fail' === $mode ) {
		add_filter( 'pre_http_request', function () { return new WP_Error( 'http_request_failed', 'simulated network failure' ); } );
	}
	foreach ( array_keys( $hooks ) as $hook ) {
		$page = preg_replace( '/^.*_page_/', '', $hook );
		$_GET['page'] = $page; $_REQUEST['page'] = $page;
		$GLOBALS['plugin_page'] = $page;
		set_current_screen( $hook );
		$catch( "$mode load-$hook", function () use ( $hook ) { do_action( 'load-' . $hook ); } );
		$catch( "$mode admin_enqueue_scripts", function () use ( $hook ) { do_action( 'admin_enqueue_scripts', $hook ); } );
		$catch( "$mode admin_notices", function () { do_action( 'admin_notices' ); } );
		$catch( "$mode render $hook", function () use ( $hook ) { do_action( $hook ); } );
	}
	set_current_screen( 'dashboard' );
	$catch( "$mode wp_dashboard_setup", function () { do_action( 'wp_dashboard_setup' ); } );
}

// Front end as a logged-out visitor.
wp_set_current_user( 0 );
$catch( 'front wp_enqueue_scripts', function () { do_action( 'wp_enqueue_scripts' ); } );
$catch( 'front wp_head', function () { do_action( 'wp_head' ); } );
$catch( 'front wp_footer', function () { do_action( 'wp_footer' ); } );

restore_error_handler();
foreach ( $problems as $p ) { error_log( $p ); echo "REPRODUCED: $p\n"; }
if ( $problems ) { exit( 1 ); }
echo "OK: no PHP errors from $needle\n";
