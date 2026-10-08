# Minimal fix for jivochat 1.3.6.x class-jivosite.php:
# 1) check is_wp_error before reading the HTTP response body (fatal on PHP 8 when the request fails)
# 2) return an empty list instead of null when languages cannot be fetched (foreach on null)
# 3) declare the $widget_id property that render() reads (undefined property warning). Behavior unchanged.
import sys, pathlib
p = pathlib.Path(sys.argv[1]) / "class-jivosite.php"
s = p.read_text()
edits = [
 ("return json_decode( wp_remote_get( JIVOSITE_LANGUAGES_URL )['body'], true );",
  "$response = wp_remote_get( JIVOSITE_LANGUAGES_URL );\n"
  "\t\t\tif ( is_wp_error( $response ) ) {\n\t\t\t\treturn array();\n\t\t\t}\n"
  "\t\t\t$list = json_decode( wp_remote_retrieve_body( $response ), true );\n"
  "\t\t\treturn is_array( $list ) ? $list : array();"),
 ("return json_decode( file_get_contents( JIVOSITE_LANGUAGES_URL ), true );",
  "$list = json_decode( (string) @file_get_contents( JIVOSITE_LANGUAGES_URL ), true );\n"
  "\t\t\treturn is_array( $list ) ? $list : array();"),
 ("return wp_remote_post( JIVOSITE_INTEGRATION_URL . '/install', $query )['body'];",
  "$response = wp_remote_post( JIVOSITE_INTEGRATION_URL . '/install', $query );\n"
  "\t\t\tif ( is_wp_error( $response ) ) {\n\t\t\t\treturn 'Error: ' . $response->get_error_message();\n\t\t\t}\n"
  "\t\t\treturn wp_remote_retrieve_body( $response );"),
 ("private $transport_enabled;",
  "private $transport_enabled;\n\n\t/**\n\t * Widget id (read by render()).\n\t *\n\t * @var mixed $widget_id\n\t */\n\tprivate $widget_id;"),
]
for old, new in edits:
    n = s.count(old)
    if n != 1:
        sys.exit("pattern found %d times (need 1): %s" % (n, old))
    s = s.replace(old, new)
p.write_text(s)
print("jivochat fix applied")
