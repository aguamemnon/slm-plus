<?php
if (!defined('ABSPATH')) { exit; }

function slmms_register_catalog() {
    register_post_type('slm_software', array(
        'labels' => array('name' => 'Logiciels', 'singular_name' => 'Logiciel', 'add_new_item' => 'Ajouter un logiciel'),
        'public' => false, 'show_ui' => true, 'show_in_menu' => SLM_MAIN_MENU_SLUG,
        'show_in_rest' => false, 'supports' => array('title', 'editor'),
        'capability_type' => 'post',
        'menu_icon' => 'dashicons-desktop'
    ));
    register_post_type('slm_module', array(
        'labels' => array('name' => 'Modules logiciels', 'singular_name' => 'Module', 'add_new_item' => 'Ajouter un module'),
        'public' => false, 'show_ui' => true, 'show_in_menu' => SLM_MAIN_MENU_SLUG,
        'supports' => array('title', 'editor'), 'capability_type' => 'post'
    ));
}

function slmms_add_catalog_metaboxes() {
    add_meta_box('slmms_module_software', 'Logiciel associé', 'slmms_module_metabox',
        'slm_module', 'side');
}

function slmms_module_metabox($post) {
    wp_nonce_field('slmms_save_module', 'slmms_module_nonce');
    $current = absint(get_post_meta($post->ID, '_slmms_software', true));
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
            'seats' => 'Ajouter des utilisateurs'
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
    if (!in_array($mode, array('', 'new', 'renew', 'modules', 'renew_modules', 'seats'), true)) {
        $mode = '';
    }
    $software = isset($_POST['_slmms_software']) ? absint($_POST['_slmms_software']) : 0;
    $modules = isset($_POST['_slmms_modules']) && is_array($_POST['_slmms_modules'])
        ? array_values(array_unique(array_map('absint', wp_unslash($_POST['_slmms_modules']))))
        : array();
    $product->update_meta_data('_slmms_mode', $mode);
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
