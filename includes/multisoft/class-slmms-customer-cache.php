<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Privacy on dynamic WooCommerce endpoints.
 *
 * Note: Apache's CacheQuickHandler can serve a HIT before WordPress runs.
 * Apache must exclude /mon-compte/, /my-account/, /panier/, /commande/
 * and /wp-json/ at the server level as well.
 */
function slmms_customer_nocache() {
    if (!function_exists('is_account_page') ||
        !function_exists('is_cart') ||
        !function_exists('is_checkout')) {
        return;
    }
    if (!is_account_page() && !is_cart() && !is_checkout()) { return; }
    if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
    if (function_exists('nocache_headers')) { nocache_headers(); }
    if (!headers_sent()) {
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
        header('Pragma: no-cache', true);
        header('Expires: Wed, 11 Jan 1984 05:00:00 GMT', true);
    }
}

function slmms_enqueue_customer_styles() {
    if (!function_exists('is_account_page') || !is_account_page()) { return; }
    wp_enqueue_style('slmms-client-dashboard',
        SLM_URL . '/public/assets/css/slmms-client.css', array(), SLM_VERSION);
}
