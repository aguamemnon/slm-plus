<?php
if (!defined('ABSPATH')) { exit; }

/** Software licenses are visible on the EXISTING SLM Plus license dashboard. */
function slmms_licenses_overview() {
    if (!current_user_can('manage_options')) { return; }
    global $wpdb;
    $table = slmms_table('license');
    $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 100");
    $active = 0;
    $expiring = 0;
    $expired = 0;
    $suspended = 0;
    $now = time();
    foreach ($rows as $license) {
        if ($license->status !== 'active') { ++$suspended; }
        elseif (strtotime($license->expires_utc . ' UTC') <= $now) { ++$expired; }
        elseif (strtotime($license->expires_utc . ' UTC') <= $now + 30 * DAY_IN_SECONDS) { ++$expiring; }
        else { ++$active; }
    }

    echo '<section class="slmms-dashboard" style="margin:20px 0 25px">';
    echo '<h2>Licences logicielles et versions majeures</h2>';
    echo '<p><a class="button button-primary" href="' .
        esc_url(slmms_manual_license_url()) . '">Créer une licence logicielle</a> ' .
        '<span class="description">Attribution manuelle administrateur, sans commande WooCommerce.</span></p>';
    echo '<p>Gestion unifiée du portefeuille SLM Plus. Les licences classiques restent affichées en dessous. ' .
        'Les nouveaux contrats multi-logiciels affichent leurs modules, versions et utilisateurs.</p>';
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin:15px 0">';
    foreach (array(
        'Licences logicielles' => count($rows),
        'Actives (plus de 30j)' => $active,
        'Échéance sous 30j' => $expiring,
        'Expirées' => $expired,
        'Suspendues / bloquées' => $suspended
    ) as $label => $value) {
        echo '<div style="background:white;border:1px solid #c3c4c7;padding:12px 18px;min-width:120px">' .
            '<strong style="font-size:21px;display:block">' . esc_html($value) . '</strong>' .
            esc_html($label) . '</div>';
    }
    echo '</div>';
    echo '<table class="widefat striped"><thead><tr>' .
        '<th>Licence / client</th><th>Logiciel</th><th>Version</th><th>Modules</th>' .
        '<th>Places</th><th>Fin de validité (UTC)</th><th>État</th><th>Gestion</th>' .
        '</tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="8">Aucune licence multi-logiciels émise pour le moment.</td></tr>';
    }
    foreach ($rows as $license) {
        $owner = get_userdata($license->owner_id);
        $modules = array();
        foreach (slmms_modules($license) as $mid) {
            if (get_post_type($mid) === 'slm_module') {
                $modules[] = get_the_title($mid);
            }
        }
        $usertable = slmms_table('users');
        $used = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $usertable WHERE license_id = %d AND active = 1",
            $license->id
        ));
        $version = (int)$license->major_version;
        $detail = add_query_arg(array('page' => 'slmms-licenses',
            'license_id' => $license->id), admin_url('admin.php'));
        echo '<tr><td><code>' . esc_html($license->license_key) . '</code><br />' .
            esc_html($owner ? $owner->user_email : 'Compte ' . $license->owner_id) .
            '</td><td>' . esc_html(get_the_title($license->software_id)) .
            '</td><td>' . esc_html($version ? 'v' . $version . '.x' : 'À attribuer') .
            '</td><td>' . esc_html($modules ? implode(', ', $modules) : '—') .
            '</td><td>' . esc_html($used . ' / ' . $license->seats) .
            '</td><td>' . esc_html($license->expires_utc) .
            '</td><td>' . esc_html($license->status) .
            '</td><td><a class="button button-small" href="' .
            esc_url($detail) . '">Ouvrir / utilisateurs</a></td></tr>';
    }
    echo '</tbody></table>';
    echo '<p class="description">Affichage des 100 dernières licences logicielles. ' .
        'Une licence sans version majeure ne doit pas être autorisée pour v1 ou v2 automatiquement.</p></section>';
}
