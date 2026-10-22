<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Portal identity enrollment: one-time code delivered to the beneficiary's
 * mailbox and entered by that person while logged into their OWN WP account.
 *
 * This proves control of that portal account/mailbox only. It does NOT attest
 * a SAM/AD Windows session. NEVER infer Windows identity from a submitted name.
 */
function slmms_identity_code() {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 10; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

function slmms_identity_digest($beneficiary_id, $code) {
    return hash_hmac('sha256', (int)$beneficiary_id . ':' . $code, wp_salt('auth'));
}

function slmms_identity_claim_allowed($beneficiary, $license, $wp_account, $now) {
    if (!$beneficiary || !$license || !$wp_account ||
        (int)$beneficiary->license_id !== (int)$license->id ||
        $beneficiary->provider !== 'ogipen' || !(int)$beneficiary->active ||
        $license->status !== 'active' ||
        strtotime($license->expires_utc . ' UTC') <= $now ||
        !is_email($beneficiary->email) ||
        strcasecmp($beneficiary->email, $wp_account->user_email) !== 0 ||
        (int)$beneficiary->identity_verified === 1) {
        return false;
    }
    return true;
}

function slmms_register_identity_hooks() {
    add_action('init', 'slmms_register_identity_endpoint');
    add_filter('woocommerce_account_menu_items', 'slmms_identity_account_menu');
    add_action('woocommerce_account_software-identity_endpoint', 'slmms_identity_page');
}

function slmms_register_identity_endpoint() {
    add_rewrite_endpoint('software-identity', EP_ROOT | EP_PAGES);
}
function slmms_identity_account_menu($items) {
    $items['software-identity'] = 'Vérifier mon identité logicielle';
    return $items;
}
function slmms_identity_page_url() {
    return wc_get_account_endpoint_url('software-identity');
}

function slmms_identity_page() {
    if (!is_user_logged_in()) { return; }
    global $wpdb;
    $account = wp_get_current_user();
    $users = slmms_table('users');
    $licenses = slmms_table('license');
    $pending = $wpdb->get_results($wpdb->prepare(
        "SELECT u.id AS beneficiary_id, u.identity_verified, l.software_id
         FROM $users u
         INNER JOIN $licenses l ON l.id = u.license_id
         WHERE u.provider = 'ogipen' AND u.active = 1
           AND l.status = 'active' AND l.expires_utc > UTC_TIMESTAMP()
           AND u.email = %s
         ORDER BY u.id DESC LIMIT 50", $account->user_email
    ));

    echo '<h2>Vérification de mon identité logicielle</h2>';
    echo '<p>Cette étape vérifie ton compte sur le portail WordPress et le contrôle de ton ' .
        'adresse e-mail. Elle ne valide pas les identifiants Windows SAM ou Active Directory.</p>';
    if (isset($_GET['slmms_identity_notice'])) {
        $notice = sanitize_key(wp_unslash($_GET['slmms_identity_notice']));
        echo '<p role="status"><strong>' . esc_html($notice === 'verified'
            ? 'Identité du portail vérifiée pour cette licence.'
            : 'Code invalide, expiré ou accès indisponible.') . '</strong></p>';
    }
    if (!$pending) {
        echo '<p>Aucune invitation correspondant à ton adresse e-mail de compte WordPress.</p>';
        return;
    }
    foreach ($pending as $row) {
        $verified = (int)$row->identity_verified === 1;
        echo '<div style="border:1px solid #ddd;padding:14px;margin:12px 0">';
        echo '<strong>' . esc_html(get_the_title($row->software_id)) . '</strong> — ' .
            esc_html($verified ? 'Compte du portail vérifié' : 'Vérification en attente');
        if (!$verified) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('slmms_identity_claim_' . $row->beneficiary_id);
            echo '<input type="hidden" name="action" value="slmms_identity_claim" />' .
                '<input type="hidden" name="beneficiary_id" value="' .
                esc_attr($row->beneficiary_id) . '" />' .
                '<label>Code reçu par e-mail <input type="text" name="verification_code" ' .
                'pattern="[A-Za-z2-9]{10}" maxlength="10" required autocomplete="off" /></label> ' .
                '<button type="submit" class="button">Confirmer mon compte</button></form>';
        }
        echo '</div>';
    }
    echo '<p>Ton gestionnaire peut envoyer ou renvoyer un code depuis la fiche de ta licence. ' .
        'Un code expire après 20 minutes et n’est valable qu’une fois.</p>';
}

function slmms_identity_invite_button($license, $beneficiary, $admin) {
    if ($beneficiary->provider !== 'ogipen' ||
        !(int)$beneficiary->active || (int)$beneficiary->identity_verified ||
        !is_email($beneficiary->email)) { return; }

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) .
        '" style="display:inline-block;margin:4px">';
    wp_nonce_field('slmms_identity_invite_' . $license->id);
    echo '<input type="hidden" name="action" value="slmms_identity_invite" />' .
        '<input type="hidden" name="license_id" value="' . esc_attr($license->id) . '" />' .
        '<input type="hidden" name="beneficiary_id" value="' . esc_attr($beneficiary->id) . '" />' .
        '<input type="hidden" name="return_admin" value="' . ($admin ? '1' : '0') . '" />' .
        '<button type="submit" class="button">Envoyer un code de vérification</button></form>';
}

add_action('admin_post_slmms_identity_invite', 'slmms_handle_identity_invite');
add_action('admin_post_slmms_identity_claim', 'slmms_handle_identity_claim');

function slmms_handle_identity_invite() {
    if (!is_user_logged_in()) { wp_die('Connexion obligatoire.', '', array('response' => 403)); }
    $license_id = isset($_POST['license_id']) ? absint($_POST['license_id']) : 0;
    $beneficiary_id = isset($_POST['beneficiary_id']) ? absint($_POST['beneficiary_id']) : 0;
    check_admin_referer('slmms_identity_invite_' . $license_id);
    $actor_id = get_current_user_id();
    $license = slmms_license_for_id($license_id, $actor_id);
    if (!$license || !slmms_actor_can($license, $actor_id, 'manage_users')) {
        wp_die('Accès interdit.', '', array('response' => 403));
    }

    $result = slmms_issue_identity_code($license_id, $beneficiary_id, $actor_id);
    $admin = current_user_can('manage_options') && !empty($_POST['return_admin']);
    $back = $admin
        ? add_query_arg(array('page' => 'slmms-licenses', 'license_id' => $license_id),
            admin_url('admin.php'))
        : slmms_account_url($license_id);
    wp_safe_redirect(add_query_arg('slmms_invite_notice', $result, $back));
    exit;
}

function slmms_issue_identity_code($license_id, $beneficiary_id, $actor_id) {
    global $wpdb;
    if (!$license_id || !$beneficiary_id || !$actor_id) { return 'error'; }
    $licenses = slmms_table('license');
    $users = slmms_table('users');
    if ($wpdb->query('START TRANSACTION') === false) { return 'error'; }
    try {
        $license = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $licenses WHERE id = %d FOR UPDATE", $license_id
        ));
        if (!$license || !slmms_actor_can($license, $actor_id, 'manage_users') ||
            $license->status !== 'active' ||
            strtotime($license->expires_utc . ' UTC') <= time()) {
            throw new RuntimeException('Licence non autorisée.');
        }
        $beneficiary = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $users WHERE id = %d AND license_id = %d FOR UPDATE",
            $beneficiary_id, $license_id
        ));
        if (!$beneficiary || $beneficiary->provider !== 'ogipen' ||
            !(int)$beneficiary->active || (int)$beneficiary->identity_verified ||
            !is_email($beneficiary->email)) {
            throw new RuntimeException('Utilisateur non éligible.');
        }
        $last_sent = $beneficiary->verification_sent_utc
            ? strtotime($beneficiary->verification_sent_utc . ' UTC') : 0;
        if ($last_sent && $last_sent > time() - 120) {
            throw new RuntimeException('Envoi trop récent.');
        }
        $code = slmms_identity_code();
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + 20 * MINUTE_IN_SECONDS);
        $updated = $wpdb->update($users, array(
            'verification_code_hash' => slmms_identity_digest($beneficiary_id, $code),
            'verification_expires_utc' => $expires,
            'verification_sent_utc' => $now,
            'verification_attempts' => 0
        ), array('id' => $beneficiary_id, 'license_id' => $license_id));
        if ($updated === false) { throw new RuntimeException('Mise à jour indisponible.'); }
        $mail_sent = wp_mail($beneficiary->email,
            'Vérification de votre accès logiciel',
            "Un gestionnaire vous a déclaré comme bénéficiaire d'un logiciel.\n" .
            "Connectez-vous à votre compte client WordPress avec cette adresse e-mail.\n" .
            "Ouvrez la rubrique « Vérifier mon identité logicielle ».\n" .
            "Code à usage unique (20 minutes) : $code\n\n" .
            "Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail.");
        if (!$mail_sent) {
            throw new RuntimeException('Courriel impossible à envoyer.');
        }
        if (!slmms_audit($license->owner_id, $actor_id, $license_id,
            'identity_invited', $beneficiary_id)) {
            throw new RuntimeException('Journal indisponible.');
        }
        if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Commit échoué.'); }
        return 'sent';
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        return 'error';
    }
}

function slmms_handle_identity_claim() {
    if (!is_user_logged_in()) { wp_die('Connexion obligatoire.', '', array('response' => 403)); }
    $beneficiary_id = isset($_POST['beneficiary_id']) ? absint($_POST['beneficiary_id']) : 0;
    check_admin_referer('slmms_identity_claim_' . $beneficiary_id);
    $code = isset($_POST['verification_code']) ?
        strtoupper(trim(sanitize_text_field(wp_unslash($_POST['verification_code'])))) : '';
    $result = slmms_confirm_identity_code($beneficiary_id, get_current_user_id(), $code);
    wp_safe_redirect(add_query_arg('slmms_identity_notice', $result,
        slmms_identity_page_url()));
    exit;
}

function slmms_confirm_identity_code($beneficiary_id, $claimant_id, $code) {
    global $wpdb;
    if (!$beneficiary_id || !$claimant_id ||
        !preg_match('/^[A-HJ-NP-Z2-9]{10}$/D', $code)) { return 'error'; }

    $account = get_userdata($claimant_id);
    if (!$account) { return 'error'; }
    $users = slmms_table('users');
    $licenses = slmms_table('license');

    if ($wpdb->query('START TRANSACTION') === false) { return 'error'; }
    try {
        // Lock the matching license first, then beneficiary: identical order
        // to the invite & beneficiary editor prevents cross-operation races.
        $ref = $wpdb->get_row($wpdb->prepare(
            "SELECT license_id FROM $users WHERE id = %d", $beneficiary_id
        ));
        $license = $ref ? $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $licenses WHERE id = %d FOR UPDATE", $ref->license_id
        )) : null;
        $beneficiary = $license ? $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $users WHERE id = %d AND license_id = %d FOR UPDATE",
            $beneficiary_id, $license->id
        )) : null;

        $now = time();
        if (!slmms_identity_claim_allowed($beneficiary, $license, $account, $now) ||
            !$beneficiary->verification_code_hash ||
            !$beneficiary->verification_expires_utc ||
            strtotime($beneficiary->verification_expires_utc . ' UTC') <= $now ||
            (int)$beneficiary->verification_attempts >= 5) {
            throw new RuntimeException('Invitation invalide ou expirée.');
        }
        $expected = slmms_identity_digest($beneficiary_id, $code);
        if (!hash_equals($beneficiary->verification_code_hash, $expected)) {
            $attempts = (int)$beneficiary->verification_attempts + 1;
            if ($wpdb->update($users, array('verification_attempts' => $attempts),
                array('id' => $beneficiary_id, 'license_id' => $license->id)) === false ||
                $wpdb->query('COMMIT') === false) {
                throw new RuntimeException('Impossible de journaliser la tentative.');
            }
            return 'error';
        }

        $used = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $users WHERE license_id = %d AND active = 1",
            $license->id
        ));
        if ($used > (int)$license->seats) {
            throw new RuntimeException('Quota de la licence dépassé.');
        }
        $updated = $wpdb->update($users, array(
            'identity_verified' => 1,
            'identity_wp_user_id' => (int)$claimant_id,
            'identity_verified_utc' => gmdate('Y-m-d H:i:s'),
            'verification_code_hash' => '',
            'verification_expires_utc' => null,
            'verification_attempts' => 0
        ), array('id' => $beneficiary_id, 'license_id' => $license->id));
        if ($updated === false ||
            !slmms_audit($license->owner_id, $claimant_id, $license->id,
                'identity_verified', $beneficiary_id) ||
            $wpdb->query('COMMIT') === false) {
            throw new RuntimeException('Validation non enregistrée.');
        }
        return 'verified';
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        return 'error';
    }
}
