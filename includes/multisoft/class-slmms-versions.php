<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Software display version (major.minor[.patch]). Licensing scope is ONLY the
 * first number, never a loose prefix such as "1" matching "10".
 */
function slmms_parse_major_version($version) {
    if (!is_string($version) ||
        !preg_match('/^([1-9][0-9]{0,2})\\.(0|[1-9][0-9]{0,3})(?:\\.(0|[1-9][0-9]{0,3}))?$/D', $version, $m)) {
        return 0;
    }
    return (int) $m[1];
}

/** Current published major version of a software, or 0 if unconfigured. */
function slmms_software_major($software_id) {
    if (!$software_id || get_post_type($software_id) !== 'slm_software') { return 0; }
    return slmms_parse_major_version((string)get_post_meta($software_id, '_slmms_version', true));
}

/**
 * The major is captured at purchase. Changing the software's current version
 * NEVER changes the version already granted by a license.
 * Older paid offers remain sellable until explicitly retired.
 */
function slmms_major_is_sellable($software_id, $major) {
    $current = slmms_software_major($software_id);
    return $current >= 1 && $major >= 1 && $major <= $current;
}

function slmms_can_upgrade_major($source_major, $target_major) {
    return (int)$source_major >= 1 && (int)$target_major > (int)$source_major &&
        (int)$target_major <= 999;
}

function slmms_same_major($license_major, $offer_major) {
    return (int)$license_major >= 1 && (int)$license_major === (int)$offer_major;
}
