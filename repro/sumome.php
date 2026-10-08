<?php
// Repro for BDOW! (formerly Sumo), slug sumome.
// 1) wordpress.org/support/topic/malformed-admin-dashboard-widget-html-making-all-wp-admin-text-into-italics/
//    The dashboard widget title has an <img> tag that is never closed, which breaks wp-admin markup.
// 2) The public (no login) AJAX actions sumo_get_woocommerce_cart_subtotal / sumo_add_woocommerce_coupon /
//    sumo_remove_woocommerce_coupon call WC() even when WooCommerce is not active: PHP fatal error.
class Rescue_WP_Die extends Exception {}
$die = function () { return function () { throw new Rescue_WP_Die( 'wp_die' ); }; };
add_filter( 'wp_die_handler', $die );
add_filter( 'wp_die_ajax_handler', $die );

$problems = array();
set_error_handler( function ( $no, $msg, $file, $line ) use ( &$problems ) {
	if ( false !== strpos( $file, 'plugins/sumome/' ) ) {
		$problems[] = "PHP Warning:  $msg in $file on line $line";
	}
	return false;
} );
$record = function ( Throwable $e ) use ( &$problems ) {
	if ( $e instanceof Rescue_WP_Die ) { return; }
	$problems[] = 'PHP Fatal error:  Uncaught ' . get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
};

require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user( 1 );
set_current_screen( 'dashboard' );

// 1) Dashboard widget title markup.
do_action( 'wp_dashboard_setup' );
global $wp_meta_boxes;
$title = $wp_meta_boxes['dashboard']['normal']['high']['my_dashboard_widget']['title'] ?? null;
echo 'dashboard widget title: ' . var_export( $title, true ) . "\n";
if ( null === $title ) {
	echo "dashboard widget not registered\n";
} elseif ( ! preg_match( '/<img\s[^<>]*>/', $title ) ) {
	$problems[] = 'PHP Warning:  malformed HTML (unclosed <img> tag) in dashboard widget title from plugins/sumome/classes/class_sumome.php dashboard_setup()';
}

// 2) Public AJAX endpoints with WooCommerce not active (logged-out visitor).
if ( function_exists( 'WC' ) ) { echo "WooCommerce is active, skipping part 2\n"; }
else {
	wp_set_current_user( 0 );
	$obj = ( new ReflectionClass( 'WP_Plugin_SumoMe' ) )->newInstanceWithoutConstructor();
	$_POST['code'] = 'TEST';
	foreach ( array( 'ajax_sumo_get_woocommerce_cart_subtotal', 'ajax_sumo_add_woocommerce_coupon', 'ajax_sumo_remove_woocommerce_coupon' ) as $method ) {
		ob_start();
		try { $obj->$method(); } catch ( Throwable $e ) { $record( $e ); }
		ob_end_clean();
		echo "$method: called\n";
	}
}

restore_error_handler();
foreach ( $problems as $p ) { error_log( $p ); echo "REPRODUCED: $p\n"; }
if ( $problems ) { exit( 1 ); }
echo "OK: dashboard widget markup valid, public AJAX actions safe without WooCommerce\n";
