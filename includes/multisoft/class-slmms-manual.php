<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Administrator-only manual grants. Never run through WooCommerce or the
 * payment hooks; explicitly distinguished from paid entitlement operations.
 */
function slmms_manual_license_url() {
    return admin_url('admin.php?page=slmms-manual-license');
}

function slmms_register_manual_license_page() {
    add_submenu_page(null, 'Créer une licence logicielle', 'Créer une licence',
        'manage_options', 'slmms-manual-license', 'slmms_manual_license_page');
}
add_action('admin_post_slmms_manual_create', 'slmms_manual_license_submit');

/**
 * Display all software entries even if a version has never been saved.
 * Incomplete or draft software remains visible but cannot be selected.
 */
function slmms_manual_software_choice($software) {
    $major = slmms_software_major($software->ID);
    $published = $software->post_status === 'publish';
    $ready = $published && $major > 0;
    $suffix = !$published ? ' — à publier' :
        ($major === 0 ? ' — version non renseignée' : ' — v' . $major . '.x');
    return array('ready' => $ready, 'major' => $major,
        'label' => $software->post_title . $suffix);
}

function slmms_manual_license_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Accès interdit.', '', array('response' => 403));
    }
    // Do not hide a software merely because its version has not yet been
    // configured. Show it with an actionable error instead.
    $software_list = get_posts(array(
        'post_type' => 'slm_software',
        'post_status' => array('publish', 'draft', 'private', 'pending'),
        'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC'
    ));
    $modules = get_posts(array('post_type' => 'slm_module', 'post_status' => 'publish',
        'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC'));

    echo '<div class="wrap"><h1>Créer une licence logicielle manuellement</h1>';
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=slm_overview&slmms_view=software')) .
        '">← Retour à la gestion des licences</a></p>';
    echo '<div class="notice notice-warning inline"><p><strong>Attribution administrative :</strong> ' .
        'cette opération délivre une licence sans paiement WooCommerce. Elle est réservée ' .
        'aux administrateurs et enregistrée dans le journal d’audit. ' .
        'La création ne change aucune licence classique SLM Plus.</p></div>';
    if (isset($_GET['slmms_error'])) {
        $message = sanitize_key(wp_unslash($_GET['slmms_error']));
        $labels = array(
            'owner' => 'Le compte client WordPress est introuvable.',
            'software' => 'Le logiciel ou sa version majeure est invalide.',
            'modules' => 'Un module n’appartient pas au logiciel choisi.',
            'limits' => 'Vérifie la durée (1 à 36500 jours) et les places (1 à 100000).',
            'db' => 'Création impossible en base : aucune licence n’a été délivrée.'
        );
        echo '<div class="notice notice-error"><p>' .
            esc_html(isset($labels[$message]) ? $labels[$message] : 'Requête invalide.') .
            '</p></div>';
    }
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('slmms_manual_create');
    echo '<input type="hidden" name="request_key" value="' . esc_attr(wp_generate_uuid4()) . '" />';
    echo '<input type="hidden" name="action" value="slmms_manual_create" />';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th><label for="slmms_client_email">Client titulaire</label></th><td>' .
        '<input id="slmms_client_email" type="email" name="client_email" ' .
        'class="regular-text" required autocomplete="off" />' .
        '<p class="description">Adresse e-mail d’un compte client WordPress existant. ' .
        'Ce titulaire pourra déclarer ses utilisateurs, dans le quota de sa licence.</p></td></tr>';
    echo '<tr><th><label for="slmms_software">Logiciel</label></th><td>' .
        '<select id="slmms_software" name="software_id" required>' .
        '<option value="">Choisir un logiciel</option>';
    foreach ($software_list as $soft) {
        $entry = slmms_manual_software_choice($soft);
        echo '<option value="' . esc_attr($soft->ID) . '" data-major="' .
            esc_attr($entry['major']) . '" ' .
            ($entry['ready'] ? '' : 'disabled="disabled"') . '>' .
            esc_html($entry['label']) . '</option>';
    }
    echo '</select>';
    if (!$software_list) {
        echo '<p class="description" style="color:#b32d2e">Aucun logiciel créé. ' .
            'Commence par SLM Plus → Logiciels → Ajouter un logiciel.</p>';
    }
    $unready = array();
    foreach ($software_list as $soft) {
        if ($soft->post_status !== 'publish' || !slmms_software_major($soft->ID)) {
            $unready[] = $soft;
        }
    }
    if ($unready) {
        echo '<p class="description" style="color:#a45500">Les logiciels ci-dessous ' .
            'sont affichés mais ne peuvent pas recevoir une licence tant que ' .
            'leur version n’est pas enregistrée et qu’ils ne sont pas publiés :</p><ul>';
        foreach ($unready as $soft) {
            echo '<li><a href="' . esc_url(get_edit_post_link($soft->ID)) . '">' .
                esc_html($soft->post_title) . ' — renseigner sa version (ex. 1.0.0) ' .
                'et cliquer sur Mettre à jour</a></li>';
        }
        echo '</ul>';
    }
    echo '</td></tr>';
    echo '<tr><th><label for="slmms_major">Version majeure</label></th><td>' .
        '<input id="slmms_major" name="major_version" type="number" min="1" max="999" ' .
        'step="1" required style="width:100px" />' .
        '<p class="description">1 pour 1.x, 2 pour 2.x. La version choisie ne peut pas ' .
        'dépasser la version actuelle du logiciel.</p></td></tr>';
    echo '<tr><th>Modules autorisés</th><td><fieldset id="slmms_module_options">';
    if (!$modules) { echo '<p>Aucun module publié.</p>'; }
    foreach ($modules as $module) {
        $parent = absint(get_post_meta($module->ID, '_slmms_software', true));
        echo '<label class="slmms-module-option" data-software="' . esc_attr($parent) .
            '" style="display:none;margin:5px 0"><input type="checkbox" name="module_ids[]" value="' .
            esc_attr($module->ID) . '" /> ' . esc_html($module->post_title) .
            '</label>';
    }
    echo '<p class="description">Seuls les modules du logiciel sélectionné peuvent être autorisés.</p>' .
        '</fieldset></td></tr>';
    echo '<tr><th><label for="slmms_seats">Nombre de places</label></th><td>' .
        '<input id="slmms_seats" type="number" name="seats" min="1" max="100000" value="1" required /></td></tr>';
    echo '<tr><th><label for="slmms_days">Validité (jours)</label></th><td>' .
        '<input id="slmms_days" type="number" name="duration_days" min="1" max="36500" value="365" required />' .
        '<p class="description">À compter de la création (UTC). Aucun renouvellement automatique.</p></td></tr>';
    echo '</tbody></table>';
    submit_button('Créer et activer la licence');
    echo '</form>';
    // Input is never trusted: module/major/owner are verified again in the POST handler.
    echo '<script>(function(){var soft=document.getElementById("slmms_software"),' .
        'major=document.getElementById("slmms_major"),rows=document.querySelectorAll(".slmms-module-option");' .
        'function sync(){var id=soft.value,option=soft.options[soft.selectedIndex];' .
        'major.value=option&&Number(option.dataset.major)>0?option.dataset.major:"";' .
        'rows.forEach(function(row){var visible=row.dataset.software===id&&id!=="";' .
        'row.style.display=visible?"block":"none";if(!visible)row.querySelector("input").checked=false;});}' .
        'soft.addEventListener("change",sync);sync();})();</script>';
    echo '</div>';
}

/** Pure validation, shared with tests and the server-side POST. */
function slmms_manual_license_validate($owner, $software_id, $major, $modules, $seats, $days) {
    if (!$owner || !isset($owner->ID)) { return 'owner'; }
    if (!$software_id || get_post_type($software_id) !== 'slm_software' ||
        get_post_status($software_id) !== 'publish' ||
        !slmms_major_is_sellable($software_id, $major)) { return 'software'; }
    if ($seats < 1 || $seats > 100000 || $days < 1 || $days > 36500) {
        return 'limits';
    }
    foreach ($modules as $id) {
        if (get_post_type($id) !== 'slm_module' ||
            get_post_status($id) !== 'publish' ||
            absint(get_post_meta($id, '_slmms_software', true)) !== $software_id) {
            return 'modules';
        }
    }
    return '';
}

function slmms_manual_license_submit() {
    if (!current_user_can('manage_options')) {
        wp_die('Accès interdit.', '', array('response' => 403));
    }
    check_admin_referer('slmms_manual_create');

    $email = isset($_POST['client_email']) ?
        sanitize_email(wp_unslash($_POST['client_email'])) : '';
    $owner = is_email($email) ? get_user_by('email', $email) : false;
    $software_id = isset($_POST['software_id']) ? absint($_POST['software_id']) : 0;
    $major = isset($_POST['major_version']) ? absint($_POST['major_version']) : 0;
    $seats = isset($_POST['seats']) ? absint($_POST['seats']) : 0;
    $days = isset($_POST['duration_days']) ? absint($_POST['duration_days']) : 0;
    $modules = isset($_POST['module_ids']) && is_array($_POST['module_ids'])
        ? array_values(array_unique(array_filter(array_map('absint',
            wp_unslash($_POST['module_ids']))))) : array();
    $error = slmms_manual_license_validate($owner, $software_id, $major, $modules, $seats, $days);
    if ($error) {
        wp_safe_redirect(add_query_arg('slmms_error', $error, slmms_manual_license_url()));
        exit;
    }
    $request_key = isset($_POST['request_key']) ?
        sanitize_text_field(wp_unslash($_POST['request_key'])) : '';
    $id = slmms_manual_license_insert((int)$owner->ID, $software_id,
        $major, $modules, $seats, $days, get_current_user_id(), $request_key);

    if (!$id) {
        wp_safe_redirect(add_query_arg('slmms_error', 'db', slmms_manual_license_url()));
    } else {
        // The generated key is available only in the authenticated licence detail.
        wp_safe_redirect(add_query_arg(array('page' => 'slmms-licenses',
            'license_id' => $id), admin_url('admin.php')));
    }
    exit;
}

/**
 * Insert + audit in a single InnoDB transaction. This does not touch the
 * WooCommerce paid-order ledger: manual issue is an explicit admin action.
 */
function slmms_manual_license_insert($owner, $software, $major, $modules, $seats, $days, $actor, $request_key) {
    global $wpdb;
    if (!$actor || !user_can($actor, 'manage_options') ||
        !is_string($request_key) ||
        !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $request_key) ||
        slmms_manual_license_validate(get_userdata($owner),
            $software, $major, $modules, $seats, $days)) {
        return 0;
    }
    if ($wpdb->query('START TRANSACTION') === false) { return 0; }
    try {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $requests = slmms_table('manual_requests');
        // The unique request key serializes retries even if two callbacks race.
        if ($wpdb->insert($requests, array(
            'request_key' => $request_key,
            'actor_user_id' => $actor,
            'license_id' => 0,
            'created_utc' => $now->format('Y-m-d H:i:s')
        )) === false) {
            throw new RuntimeException('Already issued or request ID rejected');
        }
        $key = 'MS-' . strtoupper(bin2hex(random_bytes(16)));
        $insert = $wpdb->insert(slmms_table('license'), array(
            'license_key' => $key,
            'owner_id' => $owner,
            'software_id' => $software,
            'major_version' => $major,
            'seats' => $seats,
            'modules_json' => wp_json_encode($modules),
            'expires_utc' => $now->modify('+' . $days . ' days')->format('Y-m-d H:i:s'),
            'status' => 'active',
            'created_utc' => $now->format('Y-m-d H:i:s'),
            'updated_utc' => $now->format('Y-m-d H:i:s')
        ));
        if ($insert === false) { throw new RuntimeException('DB insert failed'); }
        $id = (int)$wpdb->insert_id;
        if (!$id || $wpdb->update($requests, array('license_id' => $id),
            array('request_key' => $request_key, 'actor_user_id' => $actor)) !== 1 ||
            !slmms_audit($owner, $actor, $id, 'license_manual_created', 0) ||
            $wpdb->query('COMMIT') === false) {
            throw new RuntimeException('DB audit or commit failed');
        }
        return $id;
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        return 0;
    }
}
