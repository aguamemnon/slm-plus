<?php
// WooCommerce product type compatibility: SLM's classic slm_license is supported
// by the Multi-Software product-data save handler, without changing legacy data.
define('ABSPATH', __DIR__ . '/');
function wp_unslash($x) { return $x; }
function sanitize_key($x) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($x)); }
function absint($x) { return abs((int)$x); }
class FakeWooProduct {
    public $type;
    public $saved = array();
    public function __construct($type) { $this->type = $type; }
    public function is_type($type) { return $type === $this->type; }
    public function update_meta_data($key, $value) { $this->saved[$key] = $value; }
}
require_once dirname(__DIR__, 2) . '/includes/multisoft/class-slmms-catalog.php';
function check_product($v, $message) {
    if (!$v) { throw new RuntimeException('Échec: ' . $message); }
    echo 'OK: ' . $message . PHP_EOL;
}
$_POST = array(
    '_slmms_mode' => 'new', '_slmms_major' => '1', '_slmms_software' => '10',
    '_slmms_seats' => '10', '_slmms_days' => '365',
    '_slmms_modules' => array('20', '21')
);
foreach (array('simple', 'slm_license') as $type) {
    $product = new FakeWooProduct($type);
    slmms_save_product_fields($product);
    check_product($product->saved['_slmms_mode'] === 'new' &&
        $product->saved['_slmms_major'] === 1 &&
        $product->saved['_slmms_seats'] === 10 &&
        $product->saved['_slmms_modules'] === array(20,21),
        'Offre multi-logiciels enregistrée sur produit ' . $type);
}
$variable = new FakeWooProduct('variable');
slmms_save_product_fields($variable);
check_product($variable->saved === array(),
    'Un type de produit non pris en charge n’est pas altéré');
echo 'Compatibilité Produit simple / License product SLM validée.\n';
