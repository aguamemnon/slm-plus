<?php
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('SLM_URL', 'https://example.test/wp-content/plugins/slm-plus');
define('SLM_VERSION', '6.3.16');
function slmms_account_url($id = 0) {
    return 'https://example.test/mon-compte/software-licenses/' . ($id ? '?slmms_license=' . $id : '');
}
function add_query_arg($key, $val, $url) {
    return $url . (strpos($url, '?') === false ? '?' : '&') .
        rawurlencode($key) . '=' . rawurlencode($val);
}
function checked() {}
function assert_dashboard($condition, $label) {
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
    echo 'OK: ' . $label . PHP_EOL;
}
require_once dirname(__DIR__, 2) . '/includes/multisoft/class-slmms-dashboard.php';
require_once dirname(__DIR__, 2) . '/includes/multisoft/class-slmms-customer-cache.php';
$now = strtotime('2026-10-23 00:00:00 UTC');
$exp = function($end, $state = 'active') {
    return (object)array('expires_utc' => $end, 'status' => $state,
        'major_version' => 1);
};
$healthy = slmms_license_health($exp('2027-01-01 00:00:00'), $now);
assert_dashboard($healthy['tone'] === 'success', 'licence encore valide');
$thirty = slmms_license_health($exp('2026-11-15 00:00:00'), $now);
assert_dashboard($thirty['tone'] === 'warning' && $thirty['days'] === 23,
    'avertissement à moins de 30 jours');
$fifteen = slmms_license_health($exp('2026-10-30 00:00:00'), $now);
assert_dashboard($fifteen['tone'] === 'danger' && $fifteen['days'] === 7,
    'alerte renforcée à moins de 15 jours');
$overdue = slmms_license_health($exp('2026-10-01 00:00:00'), $now);
assert_dashboard($overdue['tone'] === 'danger' && $overdue['days'] < 0,
    'expiration visible sans accorder de délai de grâce');
assert_dashboard(slmms_license_health($exp('2027-01-01 00:00:00', 'blocked'), $now)['text'] === 'Bloquée',
    'licence bloquée prioritaire sur échéance');
assert_dashboard(slmms_license_health($exp('2027-01-01 00:00:00', 'suspended'), $now)['text'] === 'Suspendue',
    'licence suspendue prioritaire sur échéance');

assert_dashboard(slmms_license_version_label($exp('2027-01-01')) === 'v1.x',
    'version majeure visible');
assert_dashboard(slmms_license_version_label((object)array('major_version' => 0)) ===
    'Version majeure non attribuée', 'ancienne licence non attribuée reconnue');
assert_dashboard(slmms_license_view_tab('garbage') === 'summary' &&
    slmms_license_view_tab('users') === 'users' &&
    slmms_license_view_tab('managers') === 'managers',
    'navigation par onglets limitée aux options prévues');
$link = slmms_license_dashboard_link(55, 'users');
assert_dashboard(strpos($link, 'slmms_license=55') !== false &&
    strpos($link, 'slmms_tab=users') !== false,
    'liens préservent licence et onglet');
function wp_enqueue_style($handle, $url, $deps, $version) {
    $GLOBALS['style_calls'][] = compact('handle', 'url', 'version');
}
function is_account_page() { return !empty($GLOBALS['in_account']); }
function is_cart() { return false; }
function is_checkout() { return false; }
$GLOBALS['style_calls'] = array();
$GLOBALS['in_account'] = false;
slmms_enqueue_customer_styles();
assert_dashboard(!$GLOBALS['style_calls'], 'aucune feuille chargée hors Mon compte');
$GLOBALS['in_account'] = true;
slmms_enqueue_customer_styles();
assert_dashboard(count($GLOBALS['style_calls']) === 1 &&
    strpos($GLOBALS['style_calls'][0]['url'], 'slmms-client.css') !== false,
    'styles chargés uniquement dans Mon compte');
echo "Portail client : tests de statut, navigation et styles réussis.\n";
