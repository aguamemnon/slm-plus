<?php
if (!defined('ABSPATH')) { exit; }

/** Separate fork tables; never alter SLM Plus legacy primary/foreign keys. */
function slmms_table($name) {
    global $wpdb;
    $names = array('license' => 'slmms_licenses', 'users' => 'slmms_users', 'ledger' => 'slmms_order_items');
    if (!isset($names[$name])) { throw new InvalidArgumentException('Unknown table'); }
    return $wpdb->prefix . $names[$name];
}

function slmms_install_schema() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $licenses = slmms_table('license');
    $users = slmms_table('users');
    $ledger = slmms_table('ledger');

    dbDelta("CREATE TABLE $licenses (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        license_key varchar(80) NOT NULL,
        owner_id bigint(20) unsigned NOT NULL,
        software_id bigint(20) unsigned NOT NULL,
        seats int(11) unsigned NOT NULL,
        modules_json longtext NOT NULL,
        expires_utc datetime NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'active',
        created_utc datetime NOT NULL,
        updated_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY license_key (license_key),
        KEY owner_id (owner_id),
        KEY software_id (software_id)
    ) $charset;");

    dbDelta("CREATE TABLE $users (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        license_id bigint(20) unsigned NOT NULL,
        provider varchar(20) NOT NULL,
        subject varchar(191) NOT NULL,
        display_name varchar(191) NOT NULL,
        email varchar(191) NOT NULL DEFAULT '',
        active tinyint(1) NOT NULL DEFAULT 1,
        created_utc datetime NOT NULL,
        updated_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY license_identity (license_id,provider,subject),
        KEY license_id (license_id)
    ) $charset;");

    dbDelta("CREATE TABLE $ledger (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        order_item_id bigint(20) unsigned NOT NULL,
        order_id bigint(20) unsigned NOT NULL,
        license_id bigint(20) unsigned NOT NULL,
        operation varchar(32) NOT NULL,
        applied_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY order_item_id (order_item_id),
        KEY license_id (license_id)
    ) $charset;");
    update_option('slmms_schema_version', SLMMS_VERSION);
}

function slmms_maybe_upgrade_schema() {
    if (current_user_can('manage_options') &&
        get_option('slmms_schema_version') !== SLMMS_VERSION) {
        slmms_install_schema();
    }
}

function slmms_owned_license($license_key, $owner_id = null, $software_id = 0) {
    global $wpdb;
    $key = (string) $license_key;
    if (!preg_match('/^MS-[A-F0-9]{32}$/D', $key)) { return null; }
    $table = slmms_table('license');
    if ($owner_id !== null) {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE license_key = %s AND owner_id = %d LIMIT 1",
            $key, $owner_id
        ));
    } else {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE license_key = %s LIMIT 1", $key
        ));
    }
    if (!$row || ($software_id && (int) $row->software_id !== (int) $software_id)) { return null; }
    return $row;
}

function slmms_license_for_id($id, $actor_id) {
    global $wpdb;
    $table = slmms_table('license');
    if (current_user_can('manage_options')) {
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    }
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE id = %d AND owner_id = %d", $id, $actor_id
    ));
}

function slmms_modules($license) {
    $value = json_decode($license->modules_json, true);
    return is_array($value) ? array_values(array_unique(array_map('absint', $value))) : array();
}

/** Product/module snapshots are always derived server-side; never trust posted prices, modules or seats. */
function slmms_product_offer($product_id) {
    $mode = get_post_meta($product_id, '_slmms_mode', true);
    if (!in_array($mode, array('new', 'renew', 'modules', 'renew_modules', 'seats'), true)) { return null; }
    $software_id = absint(get_post_meta($product_id, '_slmms_software', true));
    if (!$software_id || get_post_type($software_id) !== 'slm_software' ||
        get_post_status($software_id) !== 'publish') { return null; }

    $modules = get_post_meta($product_id, '_slmms_modules', true);
    if (!is_array($modules)) { $modules = array(); }
    $modules = array_values(array_unique(array_filter(array_map('absint', $modules))));
    foreach ($modules as $module_id) {
        if (get_post_type($module_id) !== 'slm_module' ||
            get_post_status($module_id) !== 'publish' ||
            absint(get_post_meta($module_id, '_slmms_software', true)) !== $software_id) {
            return null;
        }
    }
    $days = absint(get_post_meta($product_id, '_slmms_days', true));
    $seats = absint(get_post_meta($product_id, '_slmms_seats', true));
    if ($seats > 100000 || $days > 36500 ||
        (in_array($mode, array('new', 'renew', 'renew_modules'), true) && $days < 1) ||
        (in_array($mode, array('new', 'seats'), true) && $seats < 1) ||
        (in_array($mode, array('modules', 'renew_modules'), true) && !$modules)) { return null; }
    return compact('mode', 'software_id', 'modules', 'days', 'seats');
}
