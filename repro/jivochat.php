<?php
// Repro for JivoChat: wordpress.org/support/topic/cannot-use-object-of-type-wpphp373/
// Simulates a failed HTTP request to api.jivosite.com (timeout, DNS, host firewall).
// WordPress then returns a WP_Error object instead of a response array.
add_filter( 'pre_http_request', function () { return new WP_Error( 'http_request_failed', 'simulated network failure' ); } );

$problems = array();
set_error_handler( function ( $no, $msg, $file, $line ) use ( &$problems ) {
	if ( false !== strpos( $file, 'plugins/jivochat/' ) ) {
		$types = array( E_WARNING => 'Warning', E_NOTICE => 'Notice', E_DEPRECATED => 'Deprecated', E_USER_WARNING => 'Warning', E_USER_NOTICE => 'Notice', E_USER_DEPRECATED => 'Deprecated' );
		$problems[] = 'PHP ' . ( $types[ $no ] ?? 'Error' ) . ":  $msg in $file on line $line";
	}
	return false;
} );

if ( ! class_exists( 'Jivosite' ) ) { fwrite( STDERR, "Jivosite class not found\n" ); exit( 2 ); }
wp_set_current_user( 1 );

// 1) Admin settings page (JivoChat menu), the path users hit.
ob_start();
try {
	Jivosite::get_instance()->render();
	echo "render(): completed\n";
} catch ( Throwable $e ) {
	$problems[] = 'PHP Fatal error:  Uncaught ' . get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
}
$html = ob_get_clean();
echo 'render(): ' . strlen( $html ) . " bytes of HTML\n";

// 2) Signup/login request path (get_integration_install_response).
$r = new ReflectionClass( 'Jivosite' );
$m = $r->getMethod( 'get_integration_install_response' );
$m->setAccessible( true );
try {
	$out = $m->invoke( Jivosite::get_instance(), array( 'body' => array( 'partnerId' => 'wordpress' ) ) );
	echo 'get_integration_install_response(): returned ' . var_export( $out, true ) . "\n";
} catch ( Throwable $e ) {
	$problems[] = 'PHP Fatal error:  Uncaught ' . get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
}

restore_error_handler();
foreach ( $problems as $p ) { error_log( $p ); echo "REPRODUCED: $p\n"; }
if ( $problems ) { exit( 1 ); }
echo "OK: no PHP errors from plugins/jivochat/ with a failed HTTP request\n";
