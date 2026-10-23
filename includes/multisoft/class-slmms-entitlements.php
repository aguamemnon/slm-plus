<?php
if (!defined('ABSPATH')) { exit; }

/** Duration of a portal-authorized grant, NOT an offline license. */
if (!defined('SLMMS_GRANT_SECONDS')) {
    define('SLMMS_GRANT_SECONDS', 3600);
}

function slmms_entitlement_b64url($binary) {
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

/**
 * Evaluate a license and *verified* portal beneficiary, or deny completely.
 * Never trust client-supplied seat counts, modules or identity provider.
 */
function slmms_entitlement_check($license, $user, $wp_user_id, $software_id,
                                 $major, $active_seats, $now) {
    if (!$license || !$user || !$wp_user_id || !$software_id || !$major ||
        (int)$license->id !== (int)$user->license_id ||
        (int)$license->software_id !== (int)$software_id ||
        (int)$license->major_version !== (int)$major ||
        (int)$major < 1 || (int)$major > 999 ||
        $license->status !== 'active' ||
        (int)$license->seats < 1 || $active_seats > (int)$license->seats ||
        strtotime($license->expires_utc . ' UTC') <= $now ||
        (int)$user->active !== 1 ||
        (int)$user->identity_verified !== 1 ||
        $user->provider !== 'ogipen' ||
        (int)$user->identity_wp_user_id !== (int)$wp_user_id) {
        return false;
    }
    return true;
}

function slmms_entitlement_resolve($license_id, $software_id, $major, $wp_user_id,
                                    $now = null) {
    if ($now === null) { $now = time(); }
    if ($license_id <= 0 || $software_id <= 0 || $major <= 0 ||
        $major > 999 || $wp_user_id <= 0) { return null; }
    global $wpdb;
    $licenses = slmms_table('license');
    $users = slmms_table('users');

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT l.*, u.id AS beneficiary_id, u.license_id AS user_license_id,
                u.provider, u.active AS beneficiary_active,
                u.identity_verified, u.identity_wp_user_id
         FROM $licenses l INNER JOIN $users u ON u.license_id = l.id
         WHERE l.id = %d AND u.identity_wp_user_id = %d
           AND u.provider = 'ogipen' AND u.active = 1
           AND u.identity_verified = 1 LIMIT 2",
        $license_id, $wp_user_id
    ));
    // A WP account must occupy precisely one verified seat on that license.
    if (!is_array($rows) || count($rows) !== 1) { return null; }
    $row = $rows[0];
    $user = (object) array(
        'license_id' => $row->user_license_id, 'active' => $row->beneficiary_active,
        'provider' => $row->provider, 'identity_verified' => $row->identity_verified,
        'identity_wp_user_id' => $row->identity_wp_user_id
    );
    $occupied = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $users WHERE license_id = %d AND active = 1",
        $license_id
    ));
    if (!slmms_entitlement_check($row, $user, $wp_user_id, $software_id,
                                  $major, $occupied, $now)) { return null; }

    if (get_post_type($software_id) !== 'slm_software' ||
        get_post_status($software_id) !== 'publish') { return null; }
    $software_slug = get_post_field('post_name', $software_id);
    if (!is_string($software_slug) || !preg_match('/^[a-z0-9-]{1,100}$/D', $software_slug)) {
        return null;
    }
    $modules = slmms_modules($row);
    if (count($modules) > 200) { return null; }
    foreach ($modules as $module_id) {
        if ($module_id <= 0 || get_post_type($module_id) !== 'slm_module' ||
            get_post_status($module_id) !== 'publish' ||
            (int)get_post_meta($module_id, '_slmms_software', true) !== $software_id) {
            return null;
        }
    }
    sort($modules, SORT_NUMERIC);
    return array(
        'license_id' => (int)$row->id,
        'beneficiary_id' => (int)$row->beneficiary_id,
        'wp_user_id' => (int)$wp_user_id,
        'software_id' => (int)$software_id,
        'software' => $software_slug,
        'major_version' => (int)$major,
        'module_ids' => $modules,
        'license_expires_at' => strtotime($row->expires_utc . ' UTC')
    );
}

/**
 * Read a P-256 signing key from an absolute path OUTSIDE the WordPress tree.
 * Never auto-generate/store keys in wp_options or inside plugin files.
 */
function slmms_signing_private_key() {
    if (!defined('SLMMS_SIGNING_PRIVATE_KEY_PATH')) { return null; }
    $file = SLMMS_SIGNING_PRIVATE_KEY_PATH;
    if (!is_string($file) || $file === '' || $file[0] !== '/') { return null; }
    $real = realpath($file);
    $wordpress = realpath(ABSPATH);
    if (!$real || !$wordpress || !is_file($real) || !is_readable($real) ||
        strpos($real, rtrim($wordpress, '/') . '/') === 0 ||
        filesize($real) > 16384) { return null; }
    $pem = file_get_contents($real);
    if ($pem === false) { return null; }
    $private = openssl_pkey_get_private($pem);
    if ($private === false) { return null; }
    $details = openssl_pkey_get_details($private);
    if (!$details || $details['type'] !== OPENSSL_KEYTYPE_EC ||
        empty($details['ec']['curve_name']) ||
        $details['ec']['curve_name'] !== 'prime256v1' ||
        $details['bits'] !== 256) { return null; }
    return $private;
}

/** Signed format: base64url(header).base64url(payload).base64url(DER ECDSA).
 * ES256-DER is deliberately NOT JWS ES256 (which uses raw 64-byte R||S).
 * Desktop verifier must enforce that precise format with a pinned public key.
 */
function slmms_entitlement_sign($entitlement, $private_key, $issuer, $now = null) {
    if ($now === null) { $now = time(); }
    if (!$private_key || !is_array($entitlement) ||
        !isset($entitlement['license_expires_at']) ||
        $entitlement['license_expires_at'] <= $now) { return null; }
    $details = openssl_pkey_get_details($private_key);
    if (!$details || empty($details['key'])) { return null; }
    $exp = min($now + SLMMS_GRANT_SECONDS, (int)$entitlement['license_expires_at']);
    if ($exp <= $now) { return null; }
    $kid = substr(hash('sha256', $details['key']), 0, 16);
    $header = array('typ' => 'SLMMS-ENT', 'alg' => 'ES256-DER', 'kid' => $kid, 'v' => 1);
    $claims = array(
        'iss' => $issuer,
        'aud' => 'slmms-desktop',
        'lic' => $entitlement['license_id'],
        'sub' => $entitlement['beneficiary_id'],
        'uid' => $entitlement['wp_user_id'],
        'software_id' => $entitlement['software_id'],
        'software' => $entitlement['software'],
        'major' => $entitlement['major_version'],
        'modules' => $entitlement['module_ids'],
        'iat' => $now, 'nbf' => $now, 'exp' => $exp
    );
    $serialized = slmms_entitlement_b64url(wp_json_encode($header)) . '.' .
        slmms_entitlement_b64url(wp_json_encode($claims));
    $signature = '';
    if (!openssl_sign($serialized, $signature, $private_key, OPENSSL_ALGO_SHA256)) {
        return null;
    }
    return array(
        'token' => $serialized . '.' . slmms_entitlement_b64url($signature),
        'expires_at' => $exp,
        'key_id' => $kid
    );
}

function slmms_register_entitlements_api() {
    register_rest_route('slmms/v1', '/entitlements/issue', array(
        'methods' => 'POST',
        'callback' => 'slmms_rest_issue_entitlement',
        'permission_callback' => 'slmms_rest_entitlement_permission'
    ));
}

function slmms_rest_entitlement_permission() {
    // This endpoint accepts WP user credentials via secure WP auth
    // (e.g. a per-user Application Password); NEVER an anonymous license key.
    if (!is_ssl()) {
        return new WP_Error('slmms_https_required', 'HTTPS obligatoire.', array('status' => 403));
    }
    if (!is_user_logged_in()) {
        return new WP_Error('slmms_auth_required', 'Compte utilisateur requis.', array('status' => 401));
    }
    return true;
}

function slmms_rest_issue_entitlement($request) {
    nocache_headers();
    if (!headers_sent()) {
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
    }
    if (!is_ssl() || !is_user_logged_in()) {
        return new WP_Error('slmms_auth_required', 'Authentification HTTPS requise.',
            array('status' => 401));
    }
    $license_id = absint($request->get_param('license_id'));
    $software_id = absint($request->get_param('software_id'));
    $major = absint($request->get_param('major_version'));
    if (!$license_id || !$software_id || !$major || $major > 999) {
        return new WP_Error('slmms_invalid_request', 'Paramètres invalides.', array('status' => 400));
    }

    $actor_id = get_current_user_id();
    $now = time();
    $entitlement = slmms_entitlement_resolve(
        $license_id, $software_id, $major, $actor_id, $now
    );
    if (!$entitlement) {
        return new WP_Error('slmms_not_authorized', 'Licence ou bénéficiaire non autorisé.',
            array('status' => 403));
    }
    $key = slmms_signing_private_key();
    if (!$key) {
        // A missing key must NEVER result in an unsigned license or fallback.
        return new WP_Error('slmms_signing_unavailable', 'Signature indisponible.',
            array('status' => 503));
    }
    $issuer = untrailingslashit(home_url('', 'https'));
    $signed = slmms_entitlement_sign($entitlement, $key, $issuer, $now);
    if (!$signed) {
        return new WP_Error('slmms_signing_failed', 'Signature impossible.',
            array('status' => 503));
    }
    return rest_ensure_response(array(
        'token' => $signed['token'], 'expires_at' => $signed['expires_at'],
        'key_id' => $signed['key_id'], 'valid_for_seconds' => $signed['expires_at'] - $now
    ));
}
