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
    $licenses = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE owner_id = %d ORDER BY id DESC LIMIT 200", $owner
    ));
    echo '<h2>Mes logiciels et licences</h2>';
    if (!$licenses) {
        echo '<p>Aucune licence multi-logiciels associée à ce compte.</p>';
        return;
    }
    $selected = isset($_GET['slmms_license']) ? absint($_GET['slmms_license']) : 0;
    echo '<table class="shop_table"><thead><tr><th>Logiciel</th><th>Licence</th><th>Places</th><th>Expiration UTC</th><th>Actions</th></tr></thead><tbody>';
    foreach ($licenses as $license) {
        echo '<tr><td>' . esc_html(get_the_title($license->software_id)) .
            '</td><td><code>' . esc_html($license->license_key) .
            '</code></td><td>' . esc_html($license->seats) .
            '</td><td>' . esc_html($license->expires_utc) .
            '</td><td><a href="' . esc_url(slmms_account_url($license->id)) .
            '">Gérer les utilisateurs</a></td></tr>';
    }
    echo '</tbody></table>';
    if ($selected) {
        $license = slmms_license_for_id($selected, $owner);
        if ($license && (int) $license->owner_id === $owner) {
            slmms_render_license_users($license, false);
        }
    }
}

function slmms_admin_menu() {
    add_submenu_page('edit.php?post_type=slm_software', 'Licences multi-logiciels',
        'Licences clients', 'manage_options', 'slmms-licenses', 'slmms_admin_page');
}

function slmms_admin_page() {
    if (!current_user_can('manage_options')) { wp_die('Accès refusé'); }
    global $wpdb;
    $table = slmms_table('license');
    $selected = isset($_GET['license_id']) ? absint($_GET['license_id']) : 0;
    echo '<div class="wrap"><h1>Licences multi-logiciels</h1>';
    $licenses = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 200");
    echo '<table class="widefat striped"><thead><tr><th>Entreprise / acheteur</th><th>Logiciel</th><th>Licence</th><th>Places</th><th>Validité</th><th>Action</th></tr></thead><tbody>';
    foreach ($licenses as $license) {
        $owner = get_userdata($license->owner_id);
        echo '<tr><td>' . esc_html($owner ? $owner->user_email : (string)$license->owner_id) .
            '</td><td>' . esc_html(get_the_title($license->software_id)) .
            '</td><td><code>' . esc_html($license->license_key) .
            '</code></td><td>' . esc_html($license->seats) .
            '</td><td>' . esc_html($license->expires_utc) .
            '</td><td><a href="' . esc_url(add_query_arg(
                array('post_type' => 'slm_software', 'page' => 'slmms-licenses',
                    'license_id' => (int)$license->id), admin_url('edit.php'))) .
            '">Gérer</a></td></tr>';
    }
    echo '</tbody></table>';
    if ($selected) {
        $license = slmms_license_for_id($selected, get_current_user_id());
        if ($license) { slmms_render_license_users($license, true); }
    }
    echo '</div>';
}

function slmms_render_license_users($license, $admin) {
    global $wpdb;
    $users_table = slmms_table('users');
    $users = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $users_table WHERE license_id = %d ORDER BY id DESC", $license->id
    ));
    $active = 0;
    foreach ($users as $u) { if ($u->active) { ++$active; } }
    echo '<h3>Utilisateurs — ' . esc_html(get_the_title($license->software_id)) .
        ' (' . esc_html($active) . '/' . esc_html($license->seats) . ')</h3>';
    if (isset($_GET['slmms_notice'])) {
        $notice = sanitize_key(wp_unslash($_GET['slmms_notice']));
        echo '<p role="status"><strong>' . esc_html($notice === 'saved' ?
            'Utilisateur enregistré.' : ($notice === 'deleted' ?
            'Utilisateur supprimé.' : 'Modification refusée : vérifiez la licence, les identités et le quota.')) .
            '</strong></p>';
    }
    echo '<p>Les comptes déclarés ici ne reçoivent aucun droit métier : ces droits sont gérés séparément par chaque logiciel.</p>';
    if ($users) {
        echo '<table class="' . ($admin ? 'widefat striped' : 'shop_table') .
            '"><thead><tr><th>Nom</th><th>Identité</th><th>E-mail</th><th>État</th><th>Action</th></tr></thead><tbody>';
        foreach ($users as $u) {
            echo '<tr><td>' . esc_html($u->display_name) . '</td><td>' .
                esc_html($u->provider . ' / ' . $u->subject) . '</td><td>' .
                esc_html($u->email) . '</td><td>' . esc_html($u->active ?
                'Actif' : 'Suspendu') . '</td><td>';
            slmms_user_form($license, $u, $admin);
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '<h4>Déclarer un utilisateur</h4>';
    slmms_user_form($license, null, $admin);
}

function slmms_user_form($license, $user, $admin) {
    $id = $user ? (int)$user->id : 0;
    $action = admin_url('admin-post.php');
    $target = $admin
        ? add_query_arg(array('post_type' => 'slm_software', 'page' => 'slmms-licenses',
            'license_id' => $license->id), admin_url('edit.php'))
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
        ? add_query_arg(array('post_type' => 'slm_software', 'page' => 'slmms-licenses',
            'license_id' => $license_id), admin_url('edit.php'))
        : slmms_account_url($license_id);
    $notice = slmms_modify_user($license_id, $user_id, $delete, $actor);
    wp_safe_redirect(add_query_arg('slmms_notice', $notice, $redirect));
    exit;
}

function slmms_modify_user($license_id, $user_id, $delete, $actor) {
    global $wpdb;
    $license = slmms_license_for_id($license_id, $actor);
    if (!$license) { return 'error'; }
    $table = slmms_table('users');
    if ($user_id) {
        $current = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND license_id = %d", $user_id, $license_id
        ));
        if (!$current) { return 'error'; }
    }
    if ($delete) {
        if (!$user_id) { return 'error'; }
        return $wpdb->delete($table, array('id' => $user_id, 'license_id' => $license_id)) === 1
            ? 'deleted' : 'error';
    }

    $provider = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : '';
    $subject = isset($_POST['subject']) ?
        sanitize_text_field(wp_unslash($_POST['subject'])) : '';
    $display = isset($_POST['display_name']) ?
        sanitize_text_field(wp_unslash($_POST['display_name'])) : '';
    $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
    $active = !empty($_POST['active']) ? 1 : 0;
    if (!in_array($provider, array('sam', 'ad', 'ogipen'), true) ||
        !$subject || !$display ||
        strlen($subject) > 191 || strlen($display) > 191) { return 'error'; }

    if ($active && !current_user_can('manage_options') &&
        ($license->status !== 'active' ||
         strtotime($license->expires_utc . ' UTC') <= time())) { return 'error'; }

    $count = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE license_id = %d AND active = 1 AND id != %d",
        $license_id, $user_id
    ));
    if ($active && $count >= (int)$license->seats) { return 'error'; }
    $data = array('provider' => $provider, 'subject' => $subject,
        'display_name' => $display, 'email' => $email, 'active' => $active,
        'updated_utc' => gmdate('Y-m-d H:i:s'));
    if ($user_id) {
        $done = $wpdb->update($table, $data,
            array('id' => $user_id, 'license_id' => $license_id));
    } else {
        $data['license_id'] = $license_id;
        $data['created_utc'] = gmdate('Y-m-d H:i:s');
        $done = $wpdb->insert($table, $data);
    }
    return $done === false ? 'error' : 'saved';
}
