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

    echo '<p>Une offre multi-logiciels se configure sur un produit <strong>License product</strong> ou <strong>Produit simple</strong>, dans son onglet <strong>Général</strong>, ' .
        'aux champs <em>Offre logicielle (SLM Multi)</em>, <em>Logiciel</em>, <em>Modules</em>, <em>Places</em> et <em>Durée</em>.</p>';
    echo '<p>Sur un produit <strong>License product</strong>, le choix ' .
        '<strong>Offre logicielle (SLM Multi)</strong> détermine le moteur utilisé. ' .
        'Sans cette option, il reste une licence SLM classique.</p>';
    echo '<p><a class="button button-primary" href="' .
        esc_url(admin_url('post-new.php?post_type=product')) .
        '">Créer une offre WooCommerce</a> <a class="button" href="' .
        esc_url(admin_url('edit.php?post_type=product')) . '">Tous les produits</a></p>';

    $query = new WP_Query(array(
        'post_type' => 'product',
        'post_status' => array('publish', 'draft', 'pending', 'private'),
        'posts_per_page' => 100,
        'orderby' => 'date',
        'order' => 'DESC'
    ));

    $labels = array(
        'new' => 'Nouvelle licence',
        'renew' => 'Prolongation',
        'modules' => 'Modules supplémentaires',
        'renew_modules' => 'Prolongation et modules',
        'seats' => 'Utilisateurs supplémentaires',
        'upgrade' => 'Migration de version majeure'
    );
    echo '<table class="widefat striped"><thead><tr><th>Produit WooCommerce</th><th>Logiciel</th>' .
        '<th>Opération</th><th>Places</th><th>Durée (jours)</th><th>Configuration</th></tr></thead><tbody>';
    $shown = 0;
    foreach ($query->posts as $post) {
        $product = wc_get_product($post->ID);
        $mode = (string)get_post_meta($post->ID, '_slmms_mode', true);
        $legacy = $product && $product->is_type('slm_license');
        if (!$legacy && $mode === '') { continue; }
        ++$shown;
        $offer = $mode !== '' ? slmms_product_offer($post->ID) : null;
        $software_id = absint(get_post_meta($post->ID, '_slmms_software', true));
        $seats = absint(get_post_meta($post->ID, '_slmms_seats', true));
        $days = absint(get_post_meta($post->ID, '_slmms_days', true));
        $status = $mode === ''
            ? '<em>Licence SLM classique (non configurée pour SLM Multi)</em>'
            : ($offer ? '<strong style="color:#12752a">SLM Multi : valide</strong>'
                : '<strong style="color:#b32d2e">SLM Multi : à corriger</strong>');
        echo '<tr><td><a href="' . esc_url(get_edit_post_link($post->ID)) . '">' .
            esc_html(get_the_title($post->ID)) . '</a></td><td>' .
            esc_html($software_id ? get_the_title($software_id) : '—') . '</td><td>' .
            esc_html(isset($labels[$mode]) ? $labels[$mode] : 'Licence classique') .
            '</td><td>' . esc_html($mode !== '' ? $seats : '—') .
            '</td><td>' . esc_html($mode !== '' ? $days : '—') .
            '</td><td>' . $status . '</td></tr>';
    }
    if ($shown === 0) {
        echo '<tr><td colspan="6">Aucune licence ou offre détectée dans les produits récents.</td></tr>';
    }
    echo '</tbody></table><p class="description">Cette vue reprend les 100 produits WooCommerce les plus récents ' .
        'et signale ceux de type License product, même non encore configurés pour SLM Multi. ' .
        'Les paramètres et les prix se modifient sur leur fiche WooCommerce.</p></div>';
    wp_reset_postdata();
}
