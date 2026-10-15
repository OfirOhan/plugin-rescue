<?php
// Repro for wp-bitly (official Bitly plugin, 2,000+ installs).
// User report, May 8 2025, 0 replies:
// wordpress.org/support/topic/php-error-in-array_reverse-on-line-126-of-class-wp-bitly-metabox-php/
// "Uncaught Error: array_reverse(): Argument #1 ($array) must be of type array, null given"
// in includes/class-wp-bitly-metabox.php, when editing a post.
// Path: the post edit screen renders the Bitly metabox (Wp_Bitly_Metabox::display_metabox),
// which asks the Bitly API for link clicks. When that request fails or Bitly answers with an
// error body (expired token, rate limit, forbidden), $clicks is not an array.
$slug = 'wp-bitly';
$dir  = WP_PLUGIN_DIR . "/$slug";
$sumf = getenv( 'GITHUB_STEP_SUMMARY' );
$sum  = function ( $t ) use ( $sumf ) { if ( $sumf ) { file_put_contents( $sumf, $t . "\n", FILE_APPEND ); } };

if ( ! getenv( 'BITLY_SCENARIO' ) ) {
// Source excerpt for the run summary (so the run page shows the code under test).
$src = file( "$dir/includes/class-wp-bitly-metabox.php" );
$out = array();
foreach ( $src as $i => $l ) { if ( $i >= 60 && $i < 220 ) { $out[] = sprintf( '%4d %s', $i + 1, rtrim( $l ) ); } }
$sum( "### class-wp-bitly-metabox.php lines 61-220\n```php\n" . implode( "\n", $out ) . "\n```" );
$api = file_get_contents( "$dir/includes/class-wp-bitly-api.php" );
if ( preg_match( '/function wpbitly_get.*?\n\s*}\n/s', $api, $m ) ) { $sum( "### wpbitly_get\n```php\n" . $m[0] . "\n```" ); }
}

if ( ! class_exists( 'Wp_Bitly_Metabox' ) ) {
	foreach ( glob( "$dir/includes/class-wp-bitly-*.php" ) as $f ) { require_once $f; }
}
if ( ! class_exists( 'Wp_Bitly_Metabox' ) ) { fwrite( STDERR, "Wp_Bitly_Metabox not found\n" ); exit( 2 ); }

wp_set_current_user( 1 );
$offline = function () { return new WP_Error( 'http_request_failed', 'no network during setup' ); };
add_filter( 'pre_http_request', $offline );
$post_id = wp_insert_post( array( 'post_title' => 'Hello', 'post_status' => 'publish', 'post_content' => 'x' ) );
update_post_meta( $post_id, '_wpbitly', 'https://bit.ly/3abcXYZ' );
$post = get_post( $post_id );
remove_filter( 'pre_http_request', $offline );
$args = array( 'args' => array( 'https://bit.ly/3abcXYZ' ) );

// Collect PHP errors from the plugin only from here on (post setup above is not part of the repro).
$problems = array();
set_error_handler( function ( $no, $msg, $file, $line ) use ( &$problems, $slug ) {
	if ( false !== strpos( $file, "plugins/$slug/" ) ) {
		$types = array( E_WARNING => 'Warning', E_NOTICE => 'Notice', E_DEPRECATED => 'Deprecated', E_USER_WARNING => 'Warning', E_USER_NOTICE => 'Notice', E_USER_DEPRECATED => 'Deprecated' );
		$problems[] = 'PHP ' . ( $types[ $no ] ?? 'Error' ) . ": $msg in $file on line $line";
	}
	return false;
} );


$scenarios = array(
	'Bitly API unreachable (WP_Error from wp_remote_get)' => function () {
		return new WP_Error( 'http_request_failed', 'simulated network failure' );
	},
	'Bitly API answers 403 FORBIDDEN (e.g. expired or revoked token)' => function () {
		return array( 'headers' => array(), 'cookies' => array(), 'filename' => null,
			'response' => array( 'code' => 403, 'message' => 'Forbidden' ),
			'body' => '{"message":"FORBIDDEN","resource":"bitlinks","description":"You are currently forbidden to access this resource."}' );
	},
);
// Each scenario runs in its own WP-CLI process (display_metabox() declares a helper function,
// so calling it twice in one PHP process would itself fatal).
$which = getenv( 'BITLY_SCENARIO' );
if ( false === $which || '' === $which ) {
	$fail = 0;
	foreach ( array_keys( array_values( $scenarios ) ) as $i ) {
		passthru( 'BITLY_SCENARIO=' . $i . ' wp eval-file ' . escapeshellarg( __FILE__ ), $code );
		if ( 0 !== $code ) { $fail = 1; }
	}
	if ( $fail ) { exit( 1 ); }
	echo "OK: no PHP errors from plugins/$slug/ in the post edit metabox when the Bitly API fails\n";
	exit( 0 );
}
$name = array_keys( $scenarios )[ (int) $which ];
$resp = $scenarios[ $name ];
add_filter( 'pre_http_request', function () use ( $resp ) { return $resp(); } );
ob_start();
try {
	( new Wp_Bitly_Metabox() )->display_metabox( $post, $args );
} catch ( Throwable $e ) {
	$problems[] = 'PHP Fatal error: Uncaught ' . get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
}
$html = ob_get_clean();
$line = "$name: " . ( $problems ? implode( ' | ', $problems ) : 'OK, metabox rendered ' . strlen( $html ) . ' bytes' );
echo $line . "\n";
$sum( '- ' . $line );
restore_error_handler();

foreach ( $problems as $p ) { error_log( $p ); echo "REPRODUCED: $p\n"; }
exit( $problems ? 1 : 0 );
