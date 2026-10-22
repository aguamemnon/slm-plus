<?php
// Test of the real WordPress admin hook registrations without booting WordPress.
// Catches the original bug: new "Logiciels" top-level menu outside SLM Plus.
define('ABSPATH', __DIR__ . '/');
define('SLM_MAIN_MENU_SLUG', 'slm_overview');
$slmms_test_posts = array();
$slmms_test_menus = array();

function register_post_type($type, $args) {
    global $slmms_test_posts;
    $slmms_test_posts[$type] = $args;
}
function add_submenu_page($parent, $title, $menu_title, $capability, $slug, $callback) {
    global $slmms_test_menus;
    $slmms_test_menus[$slug] = array(
        'parent' => $parent, 'capability' => $capability,
        'callback' => $callback
    );
}
function add_action() { return null; }

require_once dirname(__DIR__, 2) . '/includes/multisoft/class-slmms-catalog.php';
require_once dirname(__DIR__, 2) . '/includes/multisoft/class-slmms-accounts.php';

slmms_register_catalog();
slmms_admin_menu();

foreach (array('slm_software', 'slm_module') as $type) {
    if (!isset($slmms_test_posts[$type]) ||
        $slmms_test_posts[$type]['show_in_menu'] !== SLM_MAIN_MENU_SLUG ||
        $slmms_test_posts[$type]['capabilities']['edit_posts'] !== 'manage_options') {
        throw new RuntimeException("Catalogue non inclus dans SLM Plus ou insuffisamment protégé : $type");
    }
}
foreach (array('slmms-licenses', 'slmms-offers') as $page) {
    if (!isset($slmms_test_menus[$page]) ||
        $slmms_test_menus[$page]['parent'] !== SLM_MAIN_MENU_SLUG) {
        throw new RuntimeException("Sous-menu SLM Plus manquant : $page");
    }
}
echo "OK: menus SLM Plus logiciels, modules, licences clients, offres WooCommerce." . PHP_EOL;
