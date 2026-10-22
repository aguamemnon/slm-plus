<?php
if (!defined('ABSPATH')) { exit; }

function slmms_register_account_hooks() {
    add_action('init', 'slmms_register_account_endpoint');
    add_filter('woocommerce_account_menu_items', 'slmms_account_menu');
    add_action('woocommerce_account_software-licenses_endpoint', 'slmms_account_page');
}
function slmms_register_account_endpoint() {
    add_rewrite_endpoint('software-licenses', EP_ROOT | EP_PAGES);
}
function slmms_account_menu($items) {
    $items['software-licenses'] = 'Mes logiciels et licences';
    return $items;
}
function slmms_account_url($license_id = 0) {
    $base = function_exists('wc_get_account_endpoint_url')
        ? wc_get_account_endpoint_url('software-licenses')
        : home_url('/');
    return $license_id ? add_query_arg('slmms_license', $license_id, $base) : $base;
}

function slmms_account_page() {
    if (!is_user_logged_in()) { return; }
    global $wpdb;
    $table = slmms_table('license');
    $owner = get_current_user_id();
    $licenses = slmms_licenses_for_actor($owner);
    echo '<h2>Mes logiciels et licences</h2>';
    if (!$licenses) {
        echo '<p>Aucune licence multi-logiciels associée à ce compte.</p>';
        return;
    }
    $selected = isset($_GET['slmms_license']) ? absint($_GET['slmms_license']) : 0;
    echo '<table class="shop_table"><thead><tr><th>Client</th><th>Logiciel</th><th>Version</th><th>Licence</th><th>Utilisateurs</th><th>Modules</th><th>Expiration UTC</th><th>Actions</th></tr></thead><tbody>';
    foreach ($licenses as $license) {
        $owner_user = get_userdata($license->owner_id);
        $license_owner = $owner_user ? $owner_user->display_name : 'Compte #' . $license->owner_id;
        $beneficiaries = slmms_table('users');
        $active = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $beneficiaries WHERE license_id = %d AND active = 1", $license->id
        ));
        $modules = array();
        foreach (slmms_modules($license) as $module_id) {
            if (get_post_type($module_id) === 'slm_module') {
                $modules[] = get_the_title($module_id);
            }
        }
        echo '<tr><td>' . esc_html($license_owner) .
            '</td><td>' . esc_html(get_the_title($license->software_id)) .
            '</td><td>' . esc_html((int)$license->major_version > 0 ?
                'v' . $license->major_version . '.x' : 'Version non attribuée') .
            '</td><td><code>' . esc_html($license->license_key) .
            '</code></td><td>' . esc_html($active . '/' . $license->seats) .
            '</td><td>' . esc_html($modules ? implode(', ', $modules) : '—') .
            '</td><td>' . esc_html($license->expires_utc) .
            '</td><td><a href="' . esc_url(slmms_account_url($license->id)) .
            '">' . esc_html(slmms_actor_can($license, $owner, 'manage_users') ?
                'Gérer les utilisateurs' : 'Consulter') . '</a></td></tr>';
    }
    echo '</tbody></table>';
    if ($selected) {
        $license = slmms_license_for_id($selected, $owner);
        if ($license) {
            slmms_render_license_users($license, false);
            slmms_manager_panel($license, false);
        }
    }
}

function slmms_admin_menu() {
    // SLM Plus is the parent for the software/module custom post types and
    // management pages. There should be no second top-level "Logiciels".
    // Access via the integrated row in SLM Plus > Gestion des licences.
    // Keep the detail route registered but do not add a duplicate submenu.
    add_submenu_page(null, 'Détail de licence logicielle',
        'Détail de licence', 'manage_options', 'slmms-licenses', 'slmms_admin_page');
    add_submenu_page(SLM_MAIN_MENU_SLUG, 'Offres WooCommerce',
        'Offres WooCommerce', 'manage_woocommerce', 'slmms-offers', 'slmms_offers_page');
}

function slmms_admin_page() {
    if (!current_user_can('manage_options')) { wp_die('Accès refusé'); }
    global $wpdb;
    $table = slmms_table('license');
    $selected = isset($_GET['license_id']) ? absint($_GET['license_id']) : 0;
    echo '<div class="wrap"><h1>Licences multi-logiciels</h1>';
    $licenses = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 200");
    echo '<table class="widefat striped"><thead><tr><th>Entreprise / acheteur</th><th>Logiciel</th><th>Version</th><th>Licence</th><th>Places</th><th>Validité</th><th>Action</th></tr></thead><tbody>';
    foreach ($licenses as $license) {
        $owner = get_userdata($license->owner_id);
        echo '<tr><td>' . esc_html($owner ? $owner->user_email : (string)$license->owner_id) .
            '</td><td>' . esc_html(get_the_title($license->software_id)) .
            '</td><td>' . esc_html((int)$license->major_version > 0 ?
                'v' . $license->major_version . '.x' : 'Version à attribuer') .
            '</td><td><code>' . esc_html($license->license_key) .
            '</code></td><td>' . esc_html($license->seats) .
            '</td><td>' . esc_html($license->expires_utc) .
            '</td><td><a href="' . esc_url(add_query_arg(
                array('page' => 'slmms-licenses',
                    'license_id' => (int)$license->id), admin_url('admin.php'))) .
            '">Gérer</a></td></tr>';
    }
    echo '</tbody></table>';
    if ($selected) {
        $license = slmms_license_for_id($selected, get_current_user_id());
        if ($license) {
            echo '<h3>Administration de la licence</h3>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('slmms_status_' . $license->id);
            echo '<input type="hidden" name="action" value="slmms_license_status" />' .
                '<input type="hidden" name="license_id" value="' . esc_attr($license->id) . '" />';
            echo '<select name="license_status">';
            foreach (array('active' => 'Active', 'suspended' => 'Suspendue', 'blocked' => 'Bloquée') as $code => $label) {
                echo '<option value="' . esc_attr($code) . '" ' .
                    selected($license->status, $code, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select> <button class="button button-primary" type="submit">Enregistrer le statut</button></form>';
            slmms_render_license_users($license, true);
            slmms_manager_panel($license, true);
        }
    }
    echo '</div>';
}

function slmms_render_license_users($license, $admin) {
    global $wpdb;
    $can_edit = slmms_actor_can($license, get_current_user_id(), 'manage_users');
    $users_table = slmms_table('users');
    $users = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $users_table WHERE license_id = %d ORDER BY id DESC", $license->id
    ));
    $active = 0;
    foreach ($users as $u) { if ($u->active) { ++$active; } }
    echo '<h3>Utilisateurs — ' . esc_html(get_the_title($license->software_id)) .
        ' (' . esc_html($active) . '/' . esc_html($license->seats) . ')</h3>';
    $module_names = array();
    foreach (slmms_modules($license) as $module_id) {
        if (get_post_type($module_id) === 'slm_module' &&
            absint(get_post_meta($module_id, '_slmms_software', true)) === (int)$license->software_id) {
            $module_names[] = get_the_title($module_id);
        }
    }
    echo '<p><strong>Modules souscrits :</strong> ' .
        esc_html($module_names ? implode(', ', $module_names) : 'Aucun module optionnel') . '</p>';
    echo '<p><strong>Version majeure de licence :</strong> ' .
        esc_html((int)$license->major_version > 0 ?
            'v' . $license->major_version . '.x' : 'À attribuer') . '</p>';
    echo '<p><strong>Statut :</strong> ' . esc_html($license->status) .
        ' &nbsp; <strong>Expiration UTC :</strong> ' . esc_html($license->expires_utc) . '</p>';
    if (isset($_GET['slmms_notice'])) {
        $notice = sanitize_key(wp_unslash($_GET['slmms_notice']));
        echo '<p role="status"><strong>' . esc_html($notice === 'saved' ?
            'Utilisateur enregistré.' : ($notice === 'deleted' ?
            'Utilisateur supprimé.' : 'Modification refusée : vérifiez la licence, les identités et le quota.')) .
            '</strong></p>';
    }
    if (isset($_GET['slmms_invite_notice'])) {
        $invite = sanitize_key(wp_unslash($_GET['slmms_invite_notice']));
        echo '<p role="status"><strong>' . esc_html($invite === 'sent' ?
            'Code de vérification transmis au service de messagerie.' :
            'Code non envoyé : utilisateur, licence, délai ou messagerie indisponible.') .
            '</strong></p>';
    }
    echo '<p>Les comptes déclarés ici ne reçoivent aucun droit métier : ces droits sont gérés séparément par chaque logiciel.</p>';
    echo '<p><strong>Sécurité :</strong> une identité déclarée n’est pas encore vérifiée. ' .
        'La validation sera effectuée lors d’un enrôlement sécurisé du logiciel (SAM, AD ou compte portail). ' .
        'Aucune identité non vérifiée ne doit recevoir un jeton d’accès.</p>';
    if (!$can_edit) {
        echo '<p><strong>Accès en lecture seule :</strong> tu peux consulter les utilisateurs, pas les modifier.</p>';
    }
    if ($users) {
        echo '<table class="' . ($admin ? 'widefat striped' : 'shop_table') .
            '"><thead><tr><th>Nom</th><th>Identité</th><th>Vérification</th><th>E-mail</th><th>État</th><th>Action</th></tr></thead><tbody>';
        foreach ($users as $u) {
            echo '<tr><td>' . esc_html($u->display_name) . '</td><td>' .
                esc_html($u->provider . ' / ' . $u->subject) . '</td><td>' .
                esc_html(!empty($u->identity_verified) ? 'Identité vérifiée' : 'En attente de vérification') . '</td><td>' .
                esc_html($u->email) . '</td><td>' . esc_html($u->active ?
                'Actif' : 'Suspendu') . '</td><td>';
            if ($can_edit) {
                slmms_user_form($license, $u, $admin);
                slmms_identity_invite_button($license, $u, $admin);
            } else { echo 'Lecture seule'; }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    if ($can_edit) {
        echo '<h4>Déclarer un utilisateur</h4>';
        slmms_user_form($license, null, $admin);
    }
}

function slmms_user_form($license, $user, $admin) {
    $id = $user ? (int)$user->id : 0;
    $action = admin_url('admin-post.php');
    $target = $admin
        ? add_query_arg(array('page' => 'slmms-licenses',
            'license_id' => $license->id), admin_url('admin.php'))
        : slmms_account_url($license->id);
    echo '<form method="post" action="' . esc_url($action) . '" style="display:inline-block;margin:6px">';
    wp_nonce_field('slmms_user_' . $license->id);
    echo '<input type="hidden" name="action" value="slmms_user_save" />' .
        '<input type="hidden" name="license_id" value="' . esc_attr($license->id) . '" />' .
        '<input type="hidden" name="user_id" value="' . esc_attr($id) . '" />' .
        '<input type="hidden" name="return_admin" value="' . ($admin ? '1' : '0') . '" />';
    echo '<select name="provider" aria-label="Type de compte">';
    foreach (array('ogipen' => 'OGIPEN', 'sam' => 'SAM Windows', 'ad' => 'AD local') as $key => $label) {
        echo '<option value="' . esc_attr($key) . '" ' .
            selected($user ? $user->provider : 'ogipen', $key, false) . '>' .
            esc_html($label) . '</option>';
    }
    echo '</select> ';
    foreach (array(
        'subject' => array('Compte unique', $user ? $user->subject : ''),
        'display_name' => array('Nom affiché', $user ? $user->display_name : ''),
        'email' => array('Email facultatif', $user ? $user->email : '')
    ) as $name => $data) {
        echo '<input name="' . esc_attr($name) . '" aria-label="' . esc_attr($data[0]) .
            '" placeholder="' . esc_attr($data[0]) . '" maxlength="191" value="' .
            esc_attr($data[1]) . '" ' . ($name === 'email' ? 'type="email"' : 'type="text"') .
            ($name === 'email' ? '' : ' required') . ' /> ';
    }
    echo '<label><input name="active" type="checkbox" value="1" ' .
        checked($user ? (bool)$user->active : true, true, false) . '> Actif</label> ';
    echo '<button type="submit" class="button">' . ($user ? 'Modifier' : 'Ajouter') . '</button>';
    echo '</form>';
    if ($user) {
        echo '<form method="post" action="' . esc_url($action) .
            '" style="display:inline-block">';
        wp_nonce_field('slmms_user_' . $license->id);
        echo '<input type="hidden" name="action" value="slmms_user_delete" />' .
            '<input type="hidden" name="license_id" value="' . esc_attr($license->id) . '" />' .
            '<input type="hidden" name="user_id" value="' . esc_attr($id) . '" />' .
            '<input type="hidden" name="return_admin" value="' . ($admin ? '1' : '0') . '" />' .
            '<button type="submit" class="button">Supprimer</button></form>';
    }
}

add_action('admin_post_slmms_license_status', 'slmms_update_license_status');

function slmms_update_license_status() {
    if (!current_user_can('manage_options')) {
        wp_die('Accès refusé', '', array('response' => 403));
    }
    $id = isset($_POST['license_id']) ? absint($_POST['license_id']) : 0;
    check_admin_referer('slmms_status_' . $id);
    $status = isset($_POST['license_status']) ?
        sanitize_key(wp_unslash($_POST['license_status'])) : '';
    if (!$id || !in_array($status, array('active', 'suspended', 'blocked'), true)) {
        wp_die('Statut de licence invalide.', '', array('response' => 400));
    }
    global $wpdb;
    $ok = $wpdb->update(slmms_table('license'), array(
        'status' => $status,
        'updated_utc' => gmdate('Y-m-d H:i:s')
    ), array('id' => $id));
    if ($ok === false) { wp_die('Mise à jour du statut impossible.'); }
    wp_safe_redirect(add_query_arg(array(
        'page' => 'slmms-licenses',
        'license_id' => $id
    ), admin_url('admin.php')));
    exit;
}

add_action('admin_post_slmms_user_save', 'slmms_handle_user_action');
add_action('admin_post_slmms_user_delete', 'slmms_handle_user_action');

function slmms_handle_user_action() {
    if (!is_user_logged_in()) { wp_die('Identification requise.', '', array('response' => 403)); }
    $license_id = isset($_POST['license_id']) ? absint($_POST['license_id']) : 0;
    $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
    $delete = isset($_POST['action']) && $_POST['action'] === 'slmms_user_delete';
    check_admin_referer('slmms_user_' . $license_id);
    $actor = get_current_user_id();
    $admin = current_user_can('manage_options') &&
        !empty($_POST['return_admin']);
    $redirect = $admin
        ? add_query_arg(array('page' => 'slmms-licenses',
            'license_id' => $license_id), admin_url('admin.php'))
        : slmms_account_url($license_id);
    $notice = slmms_modify_user($license_id, $user_id, $delete, $actor);
    wp_safe_redirect(add_query_arg('slmms_notice', $notice, $redirect));
    exit;
}

function slmms_modify_user($license_id, $user_id, $delete, $actor) {
    global $wpdb;
    $license_table = slmms_table('license');
    $user_table = slmms_table('users');
    if ($wpdb->query('START TRANSACTION') === false) { return 'error'; }
    try {
        // Serialize seat allocation for all admins/customers of one license.
        $license = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $license_table WHERE id = %d FOR UPDATE", $license_id
        ));
        if (!$license || !slmms_actor_can($license, $actor, 'manage_users')) {
            throw new RuntimeException('Licence inaccessible ou droit absent.');
        }
        $current = null;
        if ($user_id) {
            $current = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $user_table WHERE id = %d AND license_id = %d",
                $user_id, $license_id
            ));
            if (!$current) { throw new RuntimeException('Utilisateur inconnu.'); }
        }
        if ($delete) {
            if (!$current || $wpdb->delete($user_table, array(
                'id' => $user_id, 'license_id' => $license_id
            )) !== 1) {
                throw new RuntimeException('Suppression refusée.');
            }
            if (!slmms_audit($license->owner_id, $actor, $license_id,
                'beneficiary_deleted', $user_id)) {
                throw new RuntimeException('Journal de suppression indisponible.');
            }
            $result = 'deleted';
        } else {
            $provider = isset($_POST['provider']) ?
                sanitize_key(wp_unslash($_POST['provider'])) : '';
            $subject = isset($_POST['subject']) ?
                sanitize_text_field(wp_unslash($_POST['subject'])) : '';
            $display = isset($_POST['display_name']) ?
                sanitize_text_field(wp_unslash($_POST['display_name'])) : '';
            $email = isset($_POST['email']) ?
                sanitize_email(wp_unslash($_POST['email'])) : '';
            $active = !empty($_POST['active']) ? 1 : 0;
            if (!in_array($provider, array('sam', 'ad', 'ogipen'), true) ||
                !$subject || !$display ||
                strlen($subject) > 191 || strlen($display) > 191) {
                throw new RuntimeException('Identité invalide.');
            }
            // Even an administrator cannot quietly add active seats to a
            // suspended, blocked or expired contract.
            if ($active && ($license->status !== 'active' ||
                strtotime($license->expires_utc . ' UTC') <= time())) {
                throw new RuntimeException('Licence expirée ou suspendue.');
            }
            $count = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $user_table WHERE license_id = %d AND active = 1 AND id != %d",
                $license_id, $user_id
            ));
            if ($active && $count >= (int)$license->seats) {
                throw new RuntimeException('Nombre maximal de places atteint.');
            }
            $identity_changed = !$current || $current->provider !== $provider ||
                $current->subject !== $subject || $current->email !== $email ||
                ((int)$current->active === 1 && !$active);
            $data = array(
                'provider' => $provider, 'subject' => $subject,
                'display_name' => $display, 'email' => $email, 'active' => $active,
                'updated_utc' => gmdate('Y-m-d H:i:s')
            );
            if ($identity_changed) {
                // Identity edits invalidate any previous proof. The portal UI
                // must never mark an identity as verified by itself.
                $data['identity_verified'] = 0;
                $data['identity_wp_user_id'] = 0;
                $data['identity_verified_utc'] = null;
                $data['verification_code_hash'] = '';
                $data['verification_expires_utc'] = null;
                $data['verification_sent_utc'] = null;
                $data['verification_attempts'] = 0;
            }

            if ($current) {
                $ok = $wpdb->update($user_table, $data,
                    array('id' => $user_id, 'license_id' => $license_id));
            } else {
                $data['license_id'] = $license_id;
                $data['created_utc'] = gmdate('Y-m-d H:i:s');
                $ok = $wpdb->insert($user_table, $data);
            }
            if ($ok === false) {
                throw new RuntimeException('Identité déjà déclarée ou base indisponible.');
            }
            $beneficiary_id = $user_id ? $user_id : (int)$wpdb->insert_id;
            if (!slmms_audit($license->owner_id, $actor, $license_id,
                $user_id ? 'beneficiary_updated' : 'beneficiary_added',
                $beneficiary_id)) {
                throw new RuntimeException('Journal utilisateur indisponible.');
            }
            $result = 'saved';
        }
        if ($wpdb->query('COMMIT') === false) {
            throw new RuntimeException('Commit impossible.');
        }
        return $result;
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        return 'error';
    }
}
