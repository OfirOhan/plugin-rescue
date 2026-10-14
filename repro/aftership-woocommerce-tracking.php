<?php
// Repro for AfterShip Tracking (aftership-woocommerce-tracking).
// User report, wordpress.org/support/topic/massive-amount-of-errors-in-debug-log/ (Feb 2026):
//   PHP Warning: Undefined array key "slug" in .../includes/class-aftership-actions.php on line 559
//   PHP Warning: Undefined array key "additional_fields" in .../includes/class-aftership-actions.php on line 563
// Path: when ShipStation marks an order shipped, the WooCommerce ShipStation integration fires
// woocommerce_shipstation_shipnotify. AfterShip's handle_woocommerce_shipstation_shipnotify()
// then calls add_tracking_item() with only a tracking_number (no slug, no additional_fields).
$slug     = 'aftership-woocommerce-tracking';
$problems = array();
set_error_handler( function ( $no, $msg, $file, $line ) use ( &$problems, $slug ) {
	if ( false !== strpos( $file, "plugins/$slug/" ) ) {
		$types      = array( E_WARNING => 'Warning', E_NOTICE => 'Notice', E_DEPRECATED => 'Deprecated', E_USER_WARNING => 'Warning', E_USER_NOTICE => 'Notice', E_USER_DEPRECATED => 'Deprecated' );
		$problems[] = 'PHP ' . ( $types[ $no ] ?? 'Error' ) . ": $msg in $file on line $line";
	}
	return false;
} );

if ( ! function_exists( 'wc_create_order' ) ) { fwrite( STDERR, "WooCommerce is not active\n" ); exit( 2 ); }
if ( ! has_action( 'woocommerce_shipstation_shipnotify' ) ) { fwrite( STDERR, "Nothing hooked to woocommerce_shipstation_shipnotify (ShipStation integration not active?)\n" ); exit( 2 ); }

$order = wc_create_order();
$order->save();
echo 'order ' . $order->get_id() . " created\n";
try {
	// Same arguments shape the ShipStation integration passes.
	do_action( 'woocommerce_shipstation_shipnotify', $order, array( 'tracking_number' => '1Z999AA10123456784', 'carrier' => 'UPS', 'ship_date' => time(), 'xml' => null ) );
	echo "woocommerce_shipstation_shipnotify handled\n";
} catch ( Throwable $e ) {
	$problems[] = 'PHP Fatal error: Uncaught ' . get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
}
restore_error_handler();

foreach ( $problems as $p ) { error_log( $p ); echo "REPRODUCED: $p\n"; }
if ( $problems ) { exit( 1 ); }
echo "OK: no PHP errors from plugins/$slug/ when ShipStation reports a shipment\n";
