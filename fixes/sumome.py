# Minimal fix for sumome (BDOW!) classes/class_sumome.php:
# 1) close and escape the <img> in the dashboard widget title (broken wp-admin markup)
# 2) public WooCommerce AJAX actions: stop cleanly when WooCommerce (or its cart) is not loaded,
#    and read $_POST['code'] safely. Behavior with WooCommerce active is unchanged.
import sys, pathlib
p = pathlib.Path(sys.argv[1]) / "classes" / "class_sumome.php"
s = p.read_text()
guard = "\n\t\tif(!function_exists('WC') || !WC()->cart){\n\t\t\twp_die();\n\t\t}"
edits = [
 ("' . plugins_url('images/icon_dark.png', SUMOME__PLUGIN_FILE) . '\"</i> Sumo'",
  "' . esc_url(plugins_url('images/icon_dark.png', SUMOME__PLUGIN_FILE)) . '\" alt=\"\" /></i> Sumo'", 1),
 ("public function ajax_sumo_add_woocommerce_coupon(){", "public function ajax_sumo_add_woocommerce_coupon(){" + guard, 1),
 ("public function ajax_sumo_remove_woocommerce_coupon(){", "public function ajax_sumo_remove_woocommerce_coupon(){" + guard, 1),
 ("public function ajax_sumo_get_woocommerce_cart_subtotal(){", "public function ajax_sumo_get_woocommerce_cart_subtotal(){" + guard, 1),
 ("$code = $_POST['code'];", "$code = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';", 2),
]
for old, new, n in edits:
    c = s.count(old)
    if c != n:
        sys.exit("pattern found %d times (need %d): %s" % (c, n, old))
    s = s.replace(old, new)
p.write_text(s)
print("sumome fix applied")
