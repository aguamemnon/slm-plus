<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Back office read-only view of the WooCommerce products whose licensing is
 * handled by SLM Multi-Software. Price and stock editing stay in WooCommerce.
 */
function slmms_offers_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Accès refusé.', 'slm-plus'));
    }

    echo '<div class="wrap"><h1>SLM Plus — Offres WooCommerce</h1>';
    if (!function_exists('wc_get_product')) {
        echo '<div class="notice notice-warning"><p>WooCommerce doit être installé et activé pour gérer les offres.</p></div></div>';
        return;
    }

    echo '<p>Une offre multi-logiciels se configure sur un <strong>produit simple</strong>, dans son onglet <strong>Général</strong>, ' .
        'aux champs <em>Offre logicielle (SLM Multi)</em>, <em>Logiciel</em>, <em>Modules</em>, <em>Places</em> et <em>Durée</em>.</p>';
    echo '<p><strong>Attention :</strong> le type de produit « Licence logicielle » du SLM Plus d’origine utilise ' .
        'l’ancien moteur. Pour ce nouveau circuit, choisir <strong>Produit simple</strong>.</p>';
    echo '<p><a class="button button-primary" href="' .
        esc_url(admin_url('post-new.php?post_type=product')) .
        '">Créer une offre WooCommerce</a> <a class="button" href="' .
        esc_url(admin_url('edit.php?post_type=product')) . '">Tous les produits</a></p>';

    $query = new WP_Query(array(
        'post_type' => 'product',
        'post_status' => array('publish', 'draft', 'pending', 'private'),
        'posts_per_page' => 100,
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_query' => array(array(
            'key' => '_slmms_mode', 'value' => '', 'compare' => '!='
        ))
    ));

    $labels = array(
        'new' => 'Nouvelle licence',
        'renew' => 'Prolongation',
        'modules' => 'Modules supplémentaires',
        'renew_modules' => 'Prolongation et modules',
        'seats' => 'Utilisateurs supplémentaires'
    );
    echo '<table class="widefat striped"><thead><tr><th>Produit WooCommerce</th><th>Logiciel</th>' .
        '<th>Opération</th><th>Places</th><th>Durée (jours)</th><th>Configuration</th></tr></thead><tbody>';
    if (!$query->have_posts()) {
        echo '<tr><td colspan="6">Aucune offre multi-logiciels. Crée un produit simple dans WooCommerce.</td></tr>';
    } else {
        foreach ($query->posts as $post) {
            $offer = slmms_product_offer($post->ID);
            $mode = get_post_meta($post->ID, '_slmms_mode', true);
            $software_id = absint(get_post_meta($post->ID, '_slmms_software', true));
            $seats = absint(get_post_meta($post->ID, '_slmms_seats', true));
            $days = absint(get_post_meta($post->ID, '_slmms_days', true));
            echo '<tr><td><a href="' . esc_url(get_edit_post_link($post->ID)) . '">' .
                esc_html(get_the_title($post->ID)) . '</a></td><td>' .
                esc_html(get_the_title($software_id)) . '</td><td>' .
                esc_html(isset($labels[$mode]) ? $labels[$mode] : (string)$mode) .
                '</td><td>' . esc_html($seats) . '</td><td>' . esc_html($days) .
                '</td><td>' . ($offer ? '<span style="color:green">Valide</span>' :
                    '<strong style="color:#b32d2e">À corriger</strong>') . '</td></tr>';
        }
    }
    echo '</tbody></table><p class="description">La liste présente les 100 offres les plus récentes. ' .
        'Les produits sont modifiés uniquement via leur fiche WooCommerce.</p></div>';
    wp_reset_postdata();
}
