<?php
/**
 * SLM Plus Multi-Software — isolated extension for software, modules, seats.
 * Designed for the fork. Legacy SLM keys/tables are deliberately untouched.
 */
if (!defined('ABSPATH')) { exit; }

define('SLMMS_VERSION', '0.5.0');
define('SLMMS_ROOT', __DIR__ . '/multisoft/');

require_once SLMMS_ROOT . 'class-slmms-versions.php';
require_once SLMMS_ROOT . 'class-slmms-plan.php';
require_once SLMMS_ROOT . 'class-slmms-storage.php';
require_once SLMMS_ROOT . 'class-slmms-managers.php';
require_once SLMMS_ROOT . 'class-slmms-identities.php';
require_once SLMMS_ROOT . 'class-slmms-catalog.php';
require_once SLMMS_ROOT . 'class-slmms-offers.php';
require_once SLMMS_ROOT . 'class-slmms-overview.php';
require_once SLMMS_ROOT . 'class-slmms-manual.php';
require_once SLMMS_ROOT . 'class-slmms-woocommerce.php';
require_once SLMMS_ROOT . 'class-slmms-accounts.php';

register_activation_hook(SLM_PATH . 'slm-plus.php', 'slmms_install_schema');
add_action('admin_init', 'slmms_maybe_upgrade_schema');
add_action('init', 'slmms_register_catalog');
add_action('add_meta_boxes', 'slmms_add_catalog_metaboxes');
add_action('save_post_slm_module', 'slmms_save_module', 10, 2);
add_action('save_post_slm_software', 'slmms_save_software', 10, 2);
add_action('admin_init', 'slmms_register_software_list_hooks');

add_action('plugins_loaded', 'slmms_boot_woocommerce', 20);
// Register after legacy SLM Plus has declared its top-level menu.
add_action('admin_menu', 'slmms_admin_menu', 40);
add_action('admin_menu', 'slmms_register_manual_license_page', 41);

function slmms_boot_woocommerce() {
    if (!class_exists('WooCommerce')) { return; }
    slmms_register_woocommerce_hooks();
    slmms_register_account_hooks();
    slmms_register_identity_hooks();
}
