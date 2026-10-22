<?php
if (!defined('ABSPATH')) { exit; }

/**
 * A portfolio belongs to the WooCommerce purchaser (owner_id). Delegation
 * covers this owner's licences; beneficiaries do NOT become WordPress admins.
 * The owner always retains control. A viewer never edits beneficiaries.
 */
function slmms_manager_role($owner_id, $actor_id) {
    if (!$owner_id || !$actor_id) { return ''; }
    global $wpdb;
    $table = slmms_table('managers');
    return (string)$wpdb->get_var($wpdb->prepare(
        "SELECT role FROM $table WHERE owner_id = %d AND manager_user_id = %d AND active = 1 LIMIT 1",
        $owner_id, $actor_id
    ));
}

function slmms_actor_can($license, $actor_id, $action = 'view') {
    if (!$license || !$actor_id || !in_array($action,
        array('view', 'manage_users', 'manage_managers'), true)) { return false; }
    if (user_can($actor_id, 'manage_options') ||
        (int)$license->owner_id === (int)$actor_id) { return true; }
    if ($action === 'manage_managers') { return false; }
    $role = slmms_manager_role((int)$license->owner_id, $actor_id);
    if ($action === 'view') {
        return in_array($role, array('viewer', 'user_manager'), true);
    }
    return $role === 'user_manager';
}

function slmms_licenses_for_actor($actor_id) {
    if (!$actor_id) { return array(); }
    global $wpdb;
    $licenses = slmms_table('license');
    $managers = slmms_table('managers');
    return $wpdb->get_results($wpdb->prepare(
        "SELECT l.* FROM $licenses l
         LEFT JOIN $managers m ON m.owner_id = l.owner_id
            AND m.manager_user_id = %d AND m.active = 1
         WHERE l.owner_id = %d OR m.manager_user_id IS NOT NULL
         ORDER BY l.id DESC LIMIT 200",
         $actor_id, $actor_id
    ));
}

/** Only IDs/actions, not license keys or Windows/AD identities, in audit logs. */
function slmms_audit($owner_id, $actor_id, $license_id, $action, $subject_id) {
    global $wpdb;
    if (!in_array($action, array(
        'manager_added', 'manager_updated', 'manager_revoked',
        'beneficiary_added', 'beneficiary_updated', 'beneficiary_deleted',
        'identity_invited', 'identity_verified', 'license_manual_created'
    ), true)) { return false; }
    return $wpdb->insert(slmms_table('audit'), array(
        'owner_id' => (int)$owner_id, 'actor_user_id' => (int)$actor_id,
        'license_id' => (int)$license_id, 'action' => $action,
        'subject_id' => (int)$subject_id, 'occurred_utc' => gmdate('Y-m-d H:i:s')
    )) !== false;
}

function slmms_manager_panel($license, $admin = false) {
    $actor = get_current_user_id();
    if (!slmms_actor_can($license, $actor, 'manage_managers')) { return; }

    global $wpdb;
    $table = slmms_table('managers');
    $list = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE owner_id = %d ORDER BY active DESC,id ASC",
        $license->owner_id
    ));
    echo '<section style="padding:18px;border:1px solid #c3c4c7;margin:18px 0">';
    echo '<h3>Gestionnaires du compte client</h3>';
    echo '<p>Ils accèdent aux licences achetées par ce compte. Le rôle Lecteur permet uniquement de consulter ; ' .
        'le rôle Gestionnaire peut aussi gérer les utilisateurs bénéficiaires. ' .
        'Ni l’un ni l’autre ne peut acheter à la place du client, modifier la licence ou inviter d’autres gestionnaires.</p>';
    if (isset($_GET['slmms_manager_notice'])) {
        $status = sanitize_key(wp_unslash($_GET['slmms_manager_notice']));
        echo '<p role="status"><strong>' . esc_html($status === 'saved' ?
            'Gestionnaire enregistré.' : ($status === 'revoked' ?
            'Accès du gestionnaire révoqué.' : 'Action refusée : vérifie le compte, les droits et la saisie.')) .
            '</strong></p>';
    }
    echo '<table class="widefat striped"><thead><tr><th>Compte WordPress</th><th>Rôle</th><th>État</th><th>Action</th></tr></thead><tbody>';
    if (!$list) { echo '<tr><td colspan="4">Aucun gestionnaire délégué.</td></tr>'; }
    foreach ($list as $manager) {
        $wp_user = get_userdata($manager->manager_user_id);
        $email = $wp_user ? $wp_user->user_email : '[compte supprimé]';
        echo '<tr><td>' . esc_html($email) . '</td><td>' .
            esc_html($manager->role === 'user_manager' ? 'Gestionnaire des utilisateurs' : 'Lecteur') .
            '</td><td>' . esc_html($manager->active ? 'Actif' : 'Révoqué') . '</td><td>';
        if ($manager->active) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('slmms_manager_' . $license->id);
            echo '<input type="hidden" name="action" value="slmms_manager_revoke" />' .
                '<input type="hidden" name="license_id" value="' . esc_attr($license->id) . '" />' .
                '<input type="hidden" name="manager_id" value="' . esc_attr($manager->id) . '" />' .
                '<input type="hidden" name="return_admin" value="' . ($admin ? '1' : '0') . '" />' .
                '<button type="submit" class="button">Révoquer</button></form>';
        } else { echo '—'; }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<h4>Ajouter ou modifier un gestionnaire existant</h4>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('slmms_manager_' . $license->id);
    echo '<input type="hidden" name="action" value="slmms_manager_save" />' .
        '<input type="hidden" name="license_id" value="' . esc_attr($license->id) . '" />' .
        '<input type="hidden" name="return_admin" value="' . ($admin ? '1' : '0') . '" />' .
        '<label>Adresse e-mail du compte WordPress ' .
        '<input type="email" name="manager_email" required maxlength="191" /></label> ' .
        '<label>Permission <select name="manager_role">' .
        '<option value="viewer">Lecteur</option>' .
        '<option value="user_manager">Gestionnaire des utilisateurs</option>' .
        '</select></label> ' .
        '<button class="button button-primary" type="submit">Accorder l’accès</button></form>';
    echo '<p class="description">Le gestionnaire doit disposer de son propre compte WordPress. ' .
        'Aucun mot de passe n’est communiqué. Ce gestionnaire ne consomme pas de place dans la licence ' .
        'tant qu’il n’est pas également inscrit comme utilisateur bénéficiaire.</p>';
    echo '</section>';
}

add_action('admin_post_slmms_manager_save', 'slmms_handle_manager_action');
add_action('admin_post_slmms_manager_revoke', 'slmms_handle_manager_action');

function slmms_handle_manager_action() {
    if (!is_user_logged_in()) { wp_die('Connexion obligatoire.', '', array('response' => 403)); }
    $license_id = isset($_POST['license_id']) ? absint($_POST['license_id']) : 0;
    check_admin_referer('slmms_manager_' . $license_id);
    $actor_id = get_current_user_id();
    $license = slmms_license_for_id($license_id, $actor_id);
    if (!$license || !slmms_actor_can($license, $actor_id, 'manage_managers')) {
        wp_die('Autorisation refusée.', '', array('response' => 403));
    }
    $revoke = isset($_POST['action']) && $_POST['action'] === 'slmms_manager_revoke';
    $notice = slmms_modify_manager($license, $actor_id, $revoke);
    $return_admin = current_user_can('manage_options') && !empty($_POST['return_admin']);
    $back = $return_admin ?
        add_query_arg(array('page' => 'slmms-licenses', 'license_id' => $license_id),
            admin_url('admin.php')) : slmms_account_url($license_id);
    wp_safe_redirect(add_query_arg('slmms_manager_notice', $notice, $back));
    exit;
}

function slmms_modify_manager($license, $actor_id, $revoke) {
    if (!slmms_actor_can($license, $actor_id, 'manage_managers')) { return 'error'; }
    global $wpdb;
    $table = slmms_table('managers');
    $owner_id = (int)$license->owner_id;
    $now = gmdate('Y-m-d H:i:s');
    if ($wpdb->query('START TRANSACTION') === false) { return 'error'; }
    try {
        // Serialize edits for this licence and avoid cross-tenant tampering.
        $license_table = slmms_table('license');
        $locked = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $license_table WHERE id = %d AND owner_id = %d FOR UPDATE",
            $license->id, $owner_id
        ));
        if (!$locked) { throw new RuntimeException('Licence inaccessible.'); }
        $id = 0;
        if ($revoke) {
            $id = isset($_POST['manager_id']) ? absint($_POST['manager_id']) : 0;
            $row = $id ? $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE id = %d AND owner_id = %d FOR UPDATE",
                $id, $owner_id
            )) : null;
            if (!$row || !$row->active ||
                $wpdb->update($table, array('active' => 0, 'updated_utc' => $now),
                    array('id' => $id, 'owner_id' => $owner_id)) !== 1) {
                throw new RuntimeException('Révocation impossible.');
            }
            $action = 'manager_revoked';
        } else {
            $email = isset($_POST['manager_email']) ?
                sanitize_email(wp_unslash($_POST['manager_email'])) : '';
            $role = isset($_POST['manager_role']) ?
                sanitize_key(wp_unslash($_POST['manager_role'])) : '';
            $account = $email && is_email($email) ? get_user_by('email', $email) : false;
            if (!$account || !in_array($role, array('viewer', 'user_manager'), true) ||
                (int)$account->ID === $owner_id) {
                throw new RuntimeException('Utilisateur ou rôle incorrect.');
            }
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE owner_id = %d AND manager_user_id = %d FOR UPDATE",
                $owner_id, $account->ID
            ));
            if ($existing) {
                $id = (int)$existing->id;
                if ($wpdb->update($table, array('role' => $role, 'active' => 1,
                    'updated_utc' => $now), array('id' => $id, 'owner_id' => $owner_id)) === false) {
                    throw new RuntimeException('Mise à jour impossible.');
                }
                $action = 'manager_updated';
            } else {
                if ($wpdb->insert($table, array(
                    'owner_id' => $owner_id, 'manager_user_id' => $account->ID,
                    'role' => $role, 'active' => 1, 'created_utc' => $now,
                    'updated_utc' => $now
                )) === false) {
                    throw new RuntimeException('Création impossible.');
                }
                $id = (int)$wpdb->insert_id;
                $action = 'manager_added';
            }
        }
        if (!slmms_audit($owner_id, $actor_id, (int)$license->id, $action, $id)) {
            throw new RuntimeException('Écriture du journal impossible.');
        }
        if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Commit impossible.'); }
        return $revoke ? 'revoked' : 'saved';
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        return 'error';
    }
}
