<?php
if (!defined('ABSPATH')) { exit; }

/** Only licensed administrators may edit the catalogue, never authors. */
function slmms_catalog_capabilities() {
    $capabilities = array(
        'edit_posts', 'edit_others_posts', 'edit_published_posts',
        'edit_private_posts', 'publish_posts', 'read_private_posts',
        'delete_posts', 'delete_others_posts', 'delete_published_posts',
        'delete_private_posts', 'create_posts'
    );
    return array_fill_keys($capabilities, 'manage_options');
}

function slmms_register_catalog() {
    register_post_type('slm_software', array(
        'labels' => array('name' => 'Logiciels', 'singular_name' => 'Logiciel', 'add_new_item' => 'Ajouter un logiciel'),
        'public' => false, 'show_ui' => true, 'show_in_menu' => SLM_MAIN_MENU_SLUG,
        'show_in_rest' => false, 'supports' => array('title', 'editor'),
        'capability_type' => 'post', 'map_meta_cap' => true,
        'capabilities' => slmms_catalog_capabilities(),
        'menu_icon' => 'dashicons-desktop'
    ));
    register_post_type('slm_module', array(
        'labels' => array('name' => 'Modules logiciels', 'singular_name' => 'Module', 'add_new_item' => 'Ajouter un module'),
        'public' => false, 'show_ui' => true, 'show_in_menu' => SLM_MAIN_MENU_SLUG,
        'supports' => array('title', 'editor'),
        'capability_type' => 'post', 'map_meta_cap' => true,
        'capabilities' => slmms_catalog_capabilities()
    ));
}

function slmms_add_catalog_metaboxes() {
    add_meta_box('slmms_software_catalog', 'Version et modules du logiciel', 'slmms_software_metabox',
        'slm_software', 'normal', 'high');
    add_meta_box('slmms_module_software', 'Logiciel associé', 'slmms_module_metabox',
        'slm_module', 'side');
}

/**
 * Edit all core software information in ONE form rather than sending admins
 * to an independent modules list to discover its configuration.
 */
function slmms_software_metabox($post) {
    wp_nonce_field('slmms_save_software', 'slmms_software_nonce');
    $version = (string)get_post_meta($post->ID, '_slmms_version', true);
    echo '<p><label for="slmms_version"><strong>Version actuelle (majeure.mineure.correctif)</strong></label><br />';
    echo '<input id="slmms_version" name="slmms_version" type="text" pattern="[0-9]+\\.[0-9]+(\\.[0-9]+)?" ' .
        'placeholder="1.0.0" value="' . esc_attr($version) . '" /></p>';
    echo '<p>La licence couvre uniquement sa version majeure. Une licence v1 est renouvelable en v1, ' .
        'ou peut passer vers une v2 avec une offre de mise à niveau WooCommerce.</p>';

    $modules = get_posts(array('post_type' => 'slm_module', 'post_status' => array('publish', 'draft'),
        'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC',
        'meta_key' => '_slmms_software', 'meta_value' => $post->ID));
    echo '<h3>Modules rattachés au logiciel</h3>';
    if (!$modules) {
        echo '<p>Aucun module configuré pour ce logiciel.</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>Module</th><th>Statut</th></tr></thead><tbody>';
        foreach ($modules as $module) {
            echo '<tr><td><a href="' . esc_url(get_edit_post_link($module->ID)) . '">' .
                esc_html($module->post_title) . '</a></td><td>' .
                esc_html($module->post_status) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '<p><label for="slmms_quick_module">Ajouter un module à ce logiciel :</label> ';
    echo '<input id="slmms_quick_module" name="slmms_quick_module" type="text" ' .
        'placeholder="Nom du nouveau module" maxlength="140" /> ';
    echo '<small>Renseigne le nom puis clique sur « Mettre à jour » pour créer le module.</small></p>';
    echo '<p><a class="button" href="' . esc_url(add_query_arg(
        array('post_type' => 'slm_module', 'slmms_software' => $post->ID),
        admin_url('post-new.php'))) . '">Créer un module avec description</a></p>';
}

function slmms_save_software($post_id, $post) {
    if (wp_is_post_revision($post_id) || !current_user_can('edit_post', $post_id) ||
        !isset($_POST['slmms_software_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['slmms_software_nonce'])),
            'slmms_save_software')) { return; }

    $version = isset($_POST['slmms_version']) ?
        sanitize_text_field(wp_unslash($_POST['slmms_version'])) : '';
    if (slmms_parse_major_version($version) !== 0) {
        update_post_meta($post_id, '_slmms_version', $version);
    }
    // Existing paid licenses are not changed when software version is updated.
    $name = isset($_POST['slmms_quick_module']) ?
        sanitize_text_field(wp_unslash($_POST['slmms_quick_module'])) : '';
    if ($name !== '' && strlen($name) <= 140) {
        $module_id = wp_insert_post(array('post_title' => $name,
            'post_type' => 'slm_module', 'post_status' => 'publish',
            'post_author' => get_current_user_id()), true);
        if (!is_wp_error($module_id)) {
            update_post_meta($module_id, '_slmms_software', $post_id);
        }
    }
}

function slmms_module_metabox($post) {
    wp_nonce_field('slmms_save_module', 'slmms_module_nonce');
    $current = absint(get_post_meta($post->ID, '_slmms_software', true));
    if (!$current && isset($_GET['slmms_software'])) {
        $current = absint($_GET['slmms_software']);
    }
    echo '<label for="slmms_module_software_id">Logiciel parent</label> ';
    echo '<select id="slmms_module_software_id" name="slmms_module_software_id" required>';
    echo '<option value="">Choisir…</option>';
    foreach (get_posts(array('post_type' => 'slm_software', 'post_status' => 'publish',
                              'numberposts' => -1)) as $software) {
        echo '<option value="' . esc_attr($software->ID) . '" ' .
            selected($current, $software->ID, false) . '>' .
            esc_html($software->post_title) . '</option>';
    }
    echo '</select>';
}

function slmms_save_module($post_id, $post) {
    if (wp_is_post_revision($post_id) || !current_user_can('edit_post', $post_id) ||
        !isset($_POST['slmms_module_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['slmms_module_nonce'])), 'slmms_save_module')) {
        return;
    }
    $software_id = isset($_POST['slmms_module_software_id'])
        ? absint($_POST['slmms_module_software_id']) : 0;
    if ($software_id && get_post_type($software_id) === 'slm_software') {
        update_post_meta($post_id, '_slmms_software', $software_id);
    }
}

function slmms_product_fields() {
    global $product_object;
    $id = $product_object ? $product_object->get_id() : 0;
    echo '<div class="options_group">';
    woocommerce_wp_select(array(
        'id' => '_slmms_mode', 'label' => 'Offre logicielle (SLM Multi)',
        'value' => get_post_meta($id, '_slmms_mode', true),
        'options' => array(
            '' => 'Pas une offre multi-logiciels',
            'new' => 'Nouvelle licence',
            'renew' => 'Prolonger une licence',
            'modules' => 'Ajouter des modules',
            'renew_modules' => 'Prolonger et ajouter des modules',
            'seats' => 'Ajouter des utilisateurs',
            'upgrade' => 'Passage à une nouvelle version majeure'
        ),
        'description' => 'Sélectionnez un produit simple/virtuel. Les évolutions exigent une licence existante.',
        'desc_tip' => true
    ));
    $software_options = array('' => 'Choisir un logiciel');
    foreach (get_posts(array('post_type' => 'slm_software', 'post_status' => 'publish',
                              'numberposts' => -1)) as $software) {
        $software_options[$software->ID] = $software->post_title;
    }
    woocommerce_wp_select(array(
        'id' => '_slmms_software', 'label' => 'Logiciel',
        'value' => get_post_meta($id, '_slmms_software', true),
        'options' => $software_options
    ));
    woocommerce_wp_text_input(array(
        'id' => '_slmms_major', 'label' => 'Version majeure autorisée',
        'description' => 'Ex. 1 pour 1.x, 2 pour 2.x. Obligatoire pour les offres SLM Multi.',
        'desc_tip' => true, 'type' => 'number',
        'custom_attributes' => array('min' => '1', 'max' => '999'),
        'value' => get_post_meta($id, '_slmms_major', true)
    ));
    woocommerce_wp_text_input(array(
        'id' => '_slmms_seats', 'label' => 'Nombre de places',
        'description' => 'Nombre initial pour nouvelle licence, places supplémentaires pour extension.',
        'desc_tip' => true, 'type' => 'number',
        'custom_attributes' => array('min' => '0', 'max' => '100000'),
        'value' => get_post_meta($id, '_slmms_seats', true)
    ));
    woocommerce_wp_text_input(array(
        'id' => '_slmms_days', 'label' => 'Durée ajoutée (jours)',
        'type' => 'number', 'custom_attributes' => array('min' => '0', 'max' => '36500'),
        'value' => get_post_meta($id, '_slmms_days', true)
    ));
    $selected = get_post_meta($id, '_slmms_modules', true);
    if (!is_array($selected)) { $selected = array(); }
    echo '<p class="form-field"><label>Modules inclus/ajoutés</label>';
    echo '<span style="display:inline-block;max-width:65%;vertical-align:top">';
    foreach (get_posts(array('post_type' => 'slm_module', 'post_status' => 'publish',
                              'numberposts' => -1)) as $module) {
        $sid = absint(get_post_meta($module->ID, '_slmms_software', true));
        $software = get_the_title($sid);
        echo '<label style="display:block"><input type="checkbox" name="_slmms_modules[]" value="' .
            esc_attr($module->ID) . '" ' . checked(in_array($module->ID, $selected), true, false) .
            '> ' . esc_html($module->post_title . ' — ' . $software) . '</label>';
    }
    echo '</span></p></div>';
}

function slmms_save_product_fields($product) {
    if (!$product->is_type('simple')) { return; }
    $mode = isset($_POST['_slmms_mode'])
        ? sanitize_key(wp_unslash($_POST['_slmms_mode'])) : '';
    if (!in_array($mode, array('', 'new', 'renew', 'modules', 'renew_modules', 'seats', 'upgrade'), true)) {
        $mode = '';
    }
    $software = isset($_POST['_slmms_software']) ? absint($_POST['_slmms_software']) : 0;
    $modules = isset($_POST['_slmms_modules']) && is_array($_POST['_slmms_modules'])
        ? array_values(array_unique(array_map('absint', wp_unslash($_POST['_slmms_modules']))))
        : array();
    $product->update_meta_data('_slmms_mode', $mode);
    $product->update_meta_data('_slmms_major', isset($_POST['_slmms_major']) ?
        min(999, absint($_POST['_slmms_major'])) : 0);
    $product->update_meta_data('_slmms_software', $software);
    $product->update_meta_data('_slmms_modules', $modules);
    $product->update_meta_data('_slmms_days', isset($_POST['_slmms_days']) ?
        max(0, min(36500, absint($_POST['_slmms_days']))) : 0);
    $product->update_meta_data('_slmms_seats', isset($_POST['_slmms_seats']) ?
        max(0, min(100000, absint($_POST['_slmms_seats']))) : 0);
}

function slmms_register_woocommerce_catalog_fields() {
    add_action('woocommerce_product_options_general_product_data', 'slmms_product_fields');
    add_action('woocommerce_admin_process_product_object', 'slmms_save_product_fields');
}
