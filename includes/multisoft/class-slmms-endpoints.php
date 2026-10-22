<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Register endpoints with BOTH WordPress rewriting and WC_Query.
 *
 * Registering a WooCommerce menu entry alone does not add a WC query var.
 * Without this filter WC()->query->get_current_endpoint() cannot recognize
 * the new page, even if the WordPress permalink happens to resolve.
 */
define('SLMMS_ENDPOINTS_REV', '1.0.0');

function slmms_woocommerce_query_vars($vars) {
    $vars['software-licenses'] = 'software-licenses';
    $vars['software-identity'] = 'software-identity';
    return $vars;
}

/** Run once on installation and once whenever the routing contract changes. */
function slmms_refresh_account_rewrite_rules() {
    if (!class_exists('WooCommerce')) { return false; }
    // Activation may occur after init, so register both rewrite endpoints
    // explicitly before generating rewrite rules.
    slmms_register_account_endpoint();
    slmms_register_identity_endpoint();
    // Apache already has the normal WordPress front-controller .htaccess.
    // Soft flush updates the WP rewrite_rules option without rewriting it.
    flush_rewrite_rules(false);
    update_option('slmms_endpoints_rev', SLMMS_ENDPOINTS_REV, false);
    return true;
}

/** Plugin updates do not run the activation hook; repair stale rules once. */
function slmms_maybe_refresh_account_endpoints() {
    if (!class_exists('WooCommerce') ||
        get_option('slmms_endpoints_rev') === SLMMS_ENDPOINTS_REV ||
        !current_user_can('manage_options')) {
        return;
    }
    slmms_refresh_account_rewrite_rules();
}
