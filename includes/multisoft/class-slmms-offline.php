<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Offline exchange: workstation signs a small NON-CLASSIFIED request with its
 * local EC device key; an authorized license manager returns a signed grant.
 * No hostname, username, project, IP or AD identifier is sent to WordPress.
 * For classified networks any export/import requires approved procedures.
 */
function slmms_offline_decode($input) {
    if (!is_string($input) || strlen($input) > 4096 || !preg_match('/^[A-Za-z0-9_-]+$/D', $input)) {
        return false;
    }
    $padding = str_repeat('=', (4 - strlen($input) % 4) % 4);
    $bytes = base64_decode(strtr($input, '-_', '+/') . $padding, true);
    if ($bytes === false || slmms_entitlement_b64url($bytes) !== $input) {
        return false;
    }
    return $bytes;
}

function slmms_offline_request_check($raw) {
    if (!is_string($raw) || strlen($raw) > 8192) { return null; }
    $obj = json_decode($raw, true);
    if (!is_array($obj) ||
        !isset($obj['type'], $obj['version'], $obj['software_id'], $obj['major'],
                $obj['nonce'], $obj['public_key_spki'], $obj['proof']) ||
        $obj['type'] !== 'OGIPEN-OFFLINE-REQUEST' ||
        $obj['version'] !== 1 || !is_int($obj['software_id']) ||
        !is_int($obj['major']) || $obj['software_id'] < 1 ||
        $obj['major'] < 1 || $obj['major'] > 999) { return null; }

    $nonce = slmms_offline_decode($obj['nonce']);
    $public = slmms_offline_decode($obj['public_key_spki']);
    $proof = slmms_offline_decode($obj['proof']);
    if ($nonce === false || strlen($nonce) !== 32 ||
        $public === false || strlen($public) > 1024 ||
        $proof === false || strlen($proof) < 64 || strlen($proof) > 256) { return null; }
    // Crypto public key is SPKI DER; inspect its curve and bit length.
    $pem = "-----BEGIN PUBLIC KEY-----\n" .
        chunk_split(base64_encode($public), 64, "\n") .
        "-----END PUBLIC KEY-----\n";
    $key = openssl_pkey_get_public($pem);
    if (!$key) { return null; }
    $details = openssl_pkey_get_details($key);
    if (!$details || $details['type'] !== OPENSSL_KEYTYPE_EC ||
        $details['bits'] !== 256 ||
        empty($details['ec']['curve_name']) ||
        $details['ec']['curve_name'] !== 'prime256v1') { return null; }
    $message = "OGIPEN-OFFLINE-REQUEST-v1\n" . $obj['software_id'] .
        "\n" . $obj['major'] . "\n" . $obj['nonce'] .
        "\n" . $obj['public_key_spki'] . "\n";
    if (openssl_verify($message, $proof, $key, OPENSSL_ALGO_SHA256) !== 1) { return null; }
    return array(
        'software_id' => $obj['software_id'],
        'major' => $obj['major'],
        'nonce' => $obj['nonce'],
        'device_hash' => hash('sha256', $public)
    );
}

function slmms_offline_eligible($license, $beneficiary, $request, $days, $occupied, $now) {
    if (!$license || !$beneficiary || !$request ||
        $license->status !== 'active' ||
        strtotime($license->expires_utc . ' UTC') <= $now ||
        (int)$license->software_id !== (int)$request['software_id'] ||
        (int)$license->major_version !== (int)$request['major'] ||
        (int)$license->major_version < 1 ||
        (int)$license->seats < 1 || $occupied > (int)$license->seats ||
        (int)$beneficiary->license_id !== (int)$license->id ||
        (int)$beneficiary->active !== 1 ||
        $days < 1 || $days > 365) { return false; }
    // For isolated SAM/AD workstations, this grant binds a nominated
    // beneficiary *seat*, NOT an authenticated Windows/AD account.
    return in_array($beneficiary->provider, array('ogipen', 'sam', 'ad'), true);
}

function slmms_offline_license_sign($license, $beneficiary, $request,
                                    $activation_id, $days, $now, $private_key) {
    if (!$private_key || $activation_id < 1) { return null; }
    $expires = min($now + $days * DAY_IN_SECONDS,
        strtotime($license->expires_utc . ' UTC'));
    if ($expires <= $now) { return null; }
    $software_slug = get_post_field('post_name', $license->software_id);
    if (!is_string($software_slug) ||
        !preg_match('/^[a-z0-9-]{1,100}$/D', $software_slug)) { return null; }
    $modules = slmms_modules($license);
    if (count($modules) > 200) { return null; }
    foreach ($modules as $mid) {
        if ($mid < 1 || get_post_type($mid) !== 'slm_module' ||
            get_post_status($mid) !== 'publish' ||
            absint(get_post_meta($mid, '_slmms_software', true)) !==
                (int)$license->software_id) { return null; }
    }
    sort($modules, SORT_NUMERIC);
    $details = openssl_pkey_get_details($private_key);
    if (!$details || empty($details['key']) || $details['type'] !== OPENSSL_KEYTYPE_EC ||
        $details['bits'] !== 256) { return null; }
    $header = array('typ' => 'SLMMS-OFFLINE', 'alg' => 'ES256-DER', 'v' => 1,
        'kid' => substr(hash('sha256', $details['key']), 0, 16));
    $claims = array(
        'iss' => untrailingslashit(home_url('', 'https')),
        'aud' => 'ogipen-offline',
        'lic' => (int)$license->id,
        'sub' => (int)$beneficiary->id,
        'activation' => (int)$activation_id,
        'software_id' => (int)$license->software_id,
        'software' => $software_slug,
        'major' => (int)$license->major_version,
        'modules' => $modules,
        'device_sha256' => $request['device_hash'],
        'nonce' => $request['nonce'],
        'iat' => (int)$now,
        'exp' => (int)$expires
    );
    $data = slmms_entitlement_b64url(wp_json_encode($header)) . '.' .
        slmms_entitlement_b64url(wp_json_encode($claims));
    $signature = '';
    if (!openssl_sign($data, $signature, $private_key, OPENSSL_ALGO_SHA256)) { return null; }
    return wp_json_encode(array(
        'type' => 'OGIPEN-OFFLINE-LICENSE', 'version' => 1,
        'token' => $data . '.' . slmms_entitlement_b64url($signature)
    ));
}

function slmms_offline_grant($license_id, $beneficiary_id, $actor_id, $request, $days) {
    global $wpdb;
    $licenses = slmms_table('license');
    $users = slmms_table('users');
    $activations = slmms_table('offline');
    if (!is_array($request) || !$license_id || !$beneficiary_id ||
        !$actor_id || !isset($request['device_hash'], $request['nonce'])) {
        return null;
    }
    // Key read before DB transaction; missing signer fails closed.
    $private = slmms_signing_private_key();
    if (!$private || $wpdb->query('START TRANSACTION') === false) { return null; }
    try {
        $license = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $licenses WHERE id = %d FOR UPDATE", $license_id
        ));
        if (!$license || !slmms_actor_can($license, $actor_id, 'manage_users')) {
            throw new RuntimeException('Not authorized');
        }
        $beneficiary = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $users WHERE license_id = %d AND id = %d FOR UPDATE",
            $license_id, $beneficiary_id
        ));
        $occupied = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $users WHERE license_id = %d AND active = 1",
            $license_id
        ));
        $now = time();
        if (!slmms_offline_eligible($license, $beneficiary, $request, $days, $occupied, $now)) {
            throw new RuntimeException('Not eligible');
        }
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $activations WHERE license_id = %d AND beneficiary_id = %d FOR UPDATE",
            $license_id, $beneficiary_id
        ));
        if ($record && !$record->revoked &&
            $record->device_sha256 !== $request['device_hash']) {
            // A manager must explicitly revoke the old activation before
            // authorizing a new workstation for the same seat.
            throw new RuntimeException('Already activated for another device');
        }
        $expiry = gmdate('Y-m-d H:i:s', min($now + $days * DAY_IN_SECONDS,
            strtotime($license->expires_utc . ' UTC')));
        if ($record) {
            $ok = $wpdb->update($activations, array(
                'device_sha256' => $request['device_hash'],
                'expires_utc' => $expiry,
                'issued_utc' => gmdate('Y-m-d H:i:s', $now),
                'revoked' => 0, 'actor_user_id' => $actor_id
            ), array('id' => $record->id));
            if ($ok === false) { throw new RuntimeException('Update failed'); }
            $activation_id = (int)$record->id;
        } else {
            $ok = $wpdb->insert($activations, array(
                'license_id' => $license_id,
                'beneficiary_id' => $beneficiary_id,
                'device_sha256' => $request['device_hash'],
                'expires_utc' => $expiry,
                'issued_utc' => gmdate('Y-m-d H:i:s', $now),
                'revoked' => 0, 'actor_user_id' => $actor_id
            ));
            if ($ok === false) { throw new RuntimeException('Insert failed'); }
            $activation_id = (int)$wpdb->insert_id;
        }
        $document = slmms_offline_license_sign(
            $license, $beneficiary, $request, $activation_id, $days, $now, $private);
        if (!$document ||
            !slmms_audit($license->owner_id, $actor_id, $license_id,
                'offline_issued', $beneficiary_id) ||
            $wpdb->query('COMMIT') === false) {
            throw new RuntimeException('Sign/audit/commit failed');
        }
        return $document;
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        return null;
    }
}

function slmms_offline_panel($license, $admin = false) {
    if (!slmms_actor_can($license, get_current_user_id(), 'manage_users')) { return; }
    global $wpdb;
    $users = slmms_table('users');
    $activations = slmms_table('offline');
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $users WHERE license_id = %d AND active = 1 ORDER BY display_name",
        $license->id
    ));
    $issued = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $activations WHERE license_id = %d ORDER BY id DESC",
        $license->id
    ));
    echo '<section style="border:1px solid #c3c4c7;padding:16px;margin:18px 0">';
    echo '<h3>Activation hors ligne</h3>';
    echo '<p>Pour un poste isolé : 1. générer un fichier <code>.ogreq</code> sur le poste ; ' .
        '2. transférer sa demande uniquement via le circuit de sécurité autorisé ; ' .
        '3. importer le fichier ci-dessous ; 4. télécharger le <code>.oglic</code> signé ; ' .
        '5. le transférer et l’importer dans le logiciel sur ce même poste.</p>';
    echo '<p><strong>Aucune connexion Internet requise sur le poste à activer.</strong> ' .
        'En environnement classifié, ne transférer aucune donnée sans validation du RSSI/autorité ' .
        'd’homologation. La demande ne contient ni nom d’hôte, ni domaine AD, ni projet.</p>';
    echo '<form method="post" enctype="multipart/form-data" action="' .
        esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('slmms_offline_issue_' . $license->id);
    echo '<input type="hidden" name="action" value="slmms_offline_issue" />' .
        '<input type="hidden" name="license_id" value="' . esc_attr($license->id) . '" />';
    echo '<p><label>Bénéficiaire (place) <select required name="beneficiary_id">' .
        '<option value="">Choisir un utilisateur déclaré</option>';
    foreach ($rows as $row) {
        echo '<option value="' . esc_attr($row->id) . '">' .
            esc_html($row->display_name . ' (' . $row->provider . ')') . '</option>';
    }
    echo '</select></label></p>';
    echo '<p><label>Demande du poste (.ogreq) ' .
        '<input type="file" accept=".ogreq,application/json" name="activation_request" required /></label></p>';
    echo '<p><label>Validité hors ligne ' .
        '<select name="offline_days">';
    foreach (array(7, 30, 90, 180, 365) as $days) {
        echo '<option value="' . $days . '"' .
            selected($days, 30, false) . '>' . $days . ' jours</option>';
    }
    echo '</select></label> (sans dépasser l’échéance de la licence)</p>';
    echo '<button type="submit" class="button button-primary">Signer et télécharger la licence (.oglic)</button></form>';
    echo '<p><strong>Activations :</strong></p>';
    if (!$issued) { echo '<p>Aucune activation enregistrée.</p>'; }
    foreach ($issued as $record) {
        $name = 'Bénéficiaire #' . (int)$record->beneficiary_id;
        foreach ($rows as $person) {
            if ((int)$person->id === (int)$record->beneficiary_id) {
                $name = $person->display_name; break;
            }
        }
        echo '<p>' . esc_html($name) . ' — ' .
            esc_html($record->revoked ? 'Révoquée (la copie hors ligne peut rester valide)' :
                'Active jusqu’au ' . $record->expires_utc . ' UTC') . '</p>';
        if (!$record->revoked) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('slmms_offline_revoke_' . $record->id);
            echo '<input type="hidden" name="action" value="slmms_offline_revoke" />' .
                '<input type="hidden" name="license_id" value="' . esc_attr($license->id) . '" />' .
                '<input type="hidden" name="activation_id" value="' . esc_attr($record->id) . '" />' .
                '<button type="submit" class="button">Révoquer l’activation dans le portail</button></form>';
        }
    }
    echo '</section>';
}

function slmms_handle_offline_issue() {
    if (!is_user_logged_in()) { wp_die('Connexion obligatoire', '', array('response' => 403)); }
    $license_id = isset($_POST['license_id']) ? absint($_POST['license_id']) : 0;
    $beneficiary_id = isset($_POST['beneficiary_id']) ? absint($_POST['beneficiary_id']) : 0;
    $days = isset($_POST['offline_days']) ? absint($_POST['offline_days']) : 0;
    check_admin_referer('slmms_offline_issue_' . $license_id);
    $license = slmms_license_for_id($license_id, get_current_user_id());
    if (!$license || !slmms_actor_can($license, get_current_user_id(), 'manage_users') ||
        !isset($_FILES['activation_request']) ||
        (int)$_FILES['activation_request']['error'] !== UPLOAD_ERR_OK ||
        (int)$_FILES['activation_request']['size'] > 8192 ||
        (int)$_FILES['activation_request']['size'] < 100 ||
        !is_uploaded_file($_FILES['activation_request']['tmp_name'])) {
        wp_die('Demande invalide ou accès interdit.', '', array('response' => 403));
    }
    $raw = file_get_contents($_FILES['activation_request']['tmp_name'], false, null, 0, 8193);
    $request = slmms_offline_request_check($raw);
    if (!$request) { wp_die('Signature ou demande de poste invalide.', '', array('response' => 400)); }
    $license_file = slmms_offline_grant($license_id, $beneficiary_id,
        get_current_user_id(), $request, $days);
    if (!$license_file) {
        wp_die('Création impossible : droits, quota, poste déjà activé ou signature indisponible.',
            '', array('response' => 403));
    }
    nocache_headers();
    header('Cache-Control: private, no-store, max-age=0');
    header('Content-Type: application/octet-stream');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="ogipen-offline-license.oglic"');
    header('Content-Length: ' . strlen($license_file));
    echo $license_file;
    exit;
}

function slmms_handle_offline_revoke() {
    if (!is_user_logged_in()) { wp_die('Connexion obligatoire', '', array('response' => 403)); }
    $license_id = isset($_POST['license_id']) ? absint($_POST['license_id']) : 0;
    $activation_id = isset($_POST['activation_id']) ? absint($_POST['activation_id']) : 0;
    check_admin_referer('slmms_offline_revoke_' . $activation_id);
    $license = slmms_license_for_id($license_id, get_current_user_id());
    if (!$license || !slmms_actor_can($license, get_current_user_id(), 'manage_users')) {
        wp_die('Accès interdit.', '', array('response' => 403));
    }
    global $wpdb;
    $record = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . slmms_table('offline') . " WHERE id = %d AND license_id = %d",
        $activation_id, $license_id
    ));
    if (!$record) { wp_die('Activation introuvable.', '', array('response' => 404)); }
    $wpdb->update(slmms_table('offline'), array('revoked' => 1),
        array('id' => $activation_id, 'license_id' => $license_id));
    slmms_audit($license->owner_id, get_current_user_id(), $license_id,
        'offline_revoked', $record->beneficiary_id);
    wp_safe_redirect(slmms_account_url($license_id));
    exit;
}

add_action('admin_post_slmms_offline_issue', 'slmms_handle_offline_issue');
add_action('admin_post_slmms_offline_revoke', 'slmms_handle_offline_revoke');
