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

if (!isset($slmms_test_posts['slm_software']) ||
    $slmms_test_posts['slm_software']['show_in_menu'] !== SLM_MAIN_MENU_SLUG ||
    $slmms_test_posts['slm_software']['capabilities']['edit_posts'] !== 'manage_options') {
    throw new RuntimeException("La fiche logiciel doit rester dans SLM Plus et réservée aux admins.");
}
if (!isset($slmms_test_posts['slm_module']) ||
    $slmms_test_posts['slm_module']['show_in_menu'] !== false ||
    $slmms_test_posts['slm_module']['show_ui'] !== true ||
    $slmms_test_posts['slm_module']['capabilities']['edit_posts'] !== 'manage_options') {
    throw new RuntimeException("Module éditable depuis le logiciel, sans menu séparé.");
}
if (!isset($slmms_test_menus['slmms-licenses']) ||
    $slmms_test_menus['slmms-licenses']['parent'] !== null ||
    !isset($slmms_test_menus['slmms-offers']) ||
    $slmms_test_menus['slmms-offers']['parent'] !== SLM_MAIN_MENU_SLUG) {
    throw new RuntimeException("Écrans admin: intégration dans les licences SLM Plus incorrecte.");
}
$columns = slmms_software_list_columns(array('date' => 'Date', 'title' => 'Titre'));
if (!isset($columns['slmms_version'], $columns['slmms_modules'], $columns['slmms_licenses']) ||
    isset($columns['date'])) {
    throw new RuntimeException("Liste logiciels: colonnes version/modules/licences incorrectes.");
}
$software = (object)array('post_type' => 'slm_software');
$actions = slmms_software_row_actions(
    array('edit' => 'Modifier', 'inline hide' => 'Modification rapide',
          'view' => 'Voir'), $software);
if (isset($actions['inline hide'], $actions['view']) || !isset($actions['edit'])) {
    throw new RuntimeException("Modification rapide WordPress non supprimée.");
}
$bulk = slmms_software_bulk_actions(array('edit' => 'Modifier', 'trash' => 'Corbeille'));
if (isset($bulk['edit']) || !isset($bulk['trash'])) {
    throw new RuntimeException("Modification rapide groupée encore présente.");
}
echo "OK: catalogue SLM Plus sans menu Modules, sans Quick Edit, colonnes métier." . PHP_EOL;
