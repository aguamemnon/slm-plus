<?php
// Contract test: WP rewrite endpoints, WooCommerce routing and update repair.
// No database access or running WordPress is necessary for this test.
define('ABSPATH', __DIR__ . '/');
define('EP_ROOT', 64);
define('EP_PAGES', 128);
class WooCommerce {}
$GLOBALS['slmms_added_endpoints'] = array();
$GLOBALS['slmms_options'] = array();
$GLOBALS['slmms_flush_calls'] = array();
$GLOBALS['slmms_is_admin'] = true;
$GLOBALS['slmms_registered_actions'] = array();
function add_action($name, $callback) {
    $GLOBALS['slmms_registered_actions'][$name][] = $callback;
}
function add_filter($name, $callback) {
    $GLOBALS['slmms_registered_actions'][$name][] = $callback;
}
function add_rewrite_endpoint($name, $place) {
    $GLOBALS['slmms_added_endpoints'][$name] = $place;
}
function flush_rewrite_rules($hard = true) {
    $GLOBALS['slmms_flush_calls'][] = $hard;
}
function get_option($name) {
    return isset($GLOBALS['slmms_options'][$name])
        ? $GLOBALS['slmms_options'][$name] : false;
}
function update_option($name, $value, $autoload = true) {
    $GLOBALS['slmms_options'][$name] = $value;
    return true;
}
function current_user_can($capability) {
    return $capability === 'manage_options' && $GLOBALS['slmms_is_admin'];
}
function wc_get_account_endpoint_url($endpoint) {
    return 'https://example.test/mon-compte/' . $endpoint . '/';
}
function check_route($check, $why) {
    if (!$check) { throw new RuntimeException('FAIL: ' . $why); }
    echo 'OK: ' . $why . PHP_EOL;
}
require_once dirname(__DIR__, 2) . '/includes/multisoft/class-slmms-accounts.php';
require_once dirname(__DIR__, 2) . '/includes/multisoft/class-slmms-identities.php';
require_once dirname(__DIR__, 2) . '/includes/multisoft/class-slmms-endpoints.php';

slmms_register_account_hooks();
slmms_register_identity_hooks();
check_route(in_array('slmms_register_account_endpoint',
    $GLOBALS['slmms_registered_actions']['init'], true) &&
    in_array('slmms_register_identity_endpoint',
    $GLOBALS['slmms_registered_actions']['init'], true),
    'les deux endpoints sont enregistrés au bon hook WordPress');

$vars = slmms_woocommerce_query_vars(array('orders' => 'orders'));
check_route(isset($vars['software-licenses'], $vars['software-identity']) &&
    $vars['orders'] === 'orders',
    'WC_Query reconnait les deux endpoints sans effacer les routes standard');

$account_menu = slmms_identity_account_menu(slmms_account_menu(array(
    'dashboard' => 'Tableau de bord',
    'customer-logout' => 'Se déconnecter'
)));
check_route(array_keys($account_menu) === array(
    'dashboard', 'software-licenses', 'software-identity', 'customer-logout'),
    'les deux sections sont visibles avant Déconnexion');

check_route(slmms_account_url() ===
    'https://example.test/mon-compte/software-licenses/' &&
    slmms_identity_page_url() ===
    'https://example.test/mon-compte/software-identity/',
    'les liens clients pointent sur la page Mon compte');

slmms_maybe_refresh_account_endpoints();
check_route(count($GLOBALS['slmms_added_endpoints']) === 2 &&
    $GLOBALS['slmms_added_endpoints']['software-licenses'] === (EP_ROOT | EP_PAGES) &&
    $GLOBALS['slmms_added_endpoints']['software-identity'] === (EP_ROOT | EP_PAGES),
    'le premier chargement administrateur enregistre les deux règles');

check_route($GLOBALS['slmms_flush_calls'] === array(false) &&
    get_option('slmms_endpoints_rev') === SLMMS_ENDPOINTS_REV,
    'soft flush unique des règles (sans réécrire .htaccess)');

slmms_maybe_refresh_account_endpoints();
check_route(count($GLOBALS['slmms_flush_calls']) === 1,
    'aucun flush aux chargements suivants');

$GLOBALS['slmms_options']['slmms_endpoints_rev'] = '0.0.1';
$GLOBALS['slmms_is_admin'] = false;
slmms_maybe_refresh_account_endpoints();
check_route(count($GLOBALS['slmms_flush_calls']) === 1,
    'pas de flush sans permission administrateur');

$GLOBALS['slmms_is_admin'] = true;
slmms_maybe_refresh_account_endpoints();
check_route(count($GLOBALS['slmms_flush_calls']) === 2 &&
    $GLOBALS['slmms_flush_calls'][1] === false,
    'réparation unique lors d’une nouvelle version des routes');

echo "Les endpoints WooCommerce sont enregistrés et réparés correctement.\n";
