"""Fix for aftership-woocommerce-tracking: add_tracking_item() reads $args['slug'] and
$args['additional_fields'][...] without checking they exist. The ShipStation hook
(handle_woocommerce_shipstation_shipnotify) passes only tracking_number, so every shipment
logs 'Undefined array key' warnings. Default missing keys to '' (they were stored as null before, with a warning per key)."""
import pathlib
import re
import sys

p = pathlib.Path(sys.argv[1]) / "includes" / "class-aftership-actions.php"
s = p.read_text()
start = s.index("public function add_tracking_item(")
end = s.index("$tracking_item['metrics']", start)
body = s[start:end]
n_total = 0
body, n = re.subn(r"\$args\['additional_fields'\]\['(\w+)'\]", r"( $args['additional_fields']['\1'] ?? '' )", body)
n_total += n
body, n = re.subn(r"\$args\['(slug|tracking_number)'\]", r"( $args['\1'] ?? '' )", body)
n_total += n
assert n_total == 8, f"expected 8 replacements, got {n_total}"
p.write_text(s[:start] + body + s[end:])
print(f"patched {p} ({n_total} array reads now default to '')")
