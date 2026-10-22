<?php
/**
 * SLM Plus Multi-Software — isolated extension for software, modules, seats.
 * Designed for the fork. Legacy SLM keys/tables are deliberately untouched.
 */
if (!defined('ABSPATH')) { exit; }

define('SLMMS_VERSION', '0.1.0');
define('SLMMS_ROOT', __DIR__ . '/multisoft/');

require_once SLMMS_ROOT . 'class-slmms-storage.php';
require_once SLMMS_ROOT . 'class-slmms-catalog.php';
require_once SLMMS_ROOT . 'class-slmms-woocommerce.php';
require_once SLMMS_ROOT . 'class-slmms-accounts.php';

register_activation_hook(SLM_PATH . 'slm-plus.php', 'slmms_install_schema');
add_action('admin_init', 'slmms_maybe_upgrade_schema');
add_action('init', 'slmms_register_catalog');
add_action('add_meta_boxes', 'slmms_add_catalog_metaboxes');
add_action('save_post_slm_module', 'slmms_save_module', 10, 2);

add_action('plugins_loaded', 'slmms_boot_woocommerce', 20);
add_action('admin_menu', 'slmms_admin_menu');

function slmms_boot_woocommerce() {
    if (!class_exists('WooCommerce')) { return; }
    slmms_register_woocommerce_hooks();
    slmms_register_account_hooks();
}
