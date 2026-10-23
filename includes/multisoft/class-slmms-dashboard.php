<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Customer-specific licensed-software dashboard. Authorization continues to
 * live in slmms_licenses_for_actor / slmms_license_for_id / slmms_actor_can.
 */
function slmms_license_health($license, $now = null) {
    $now = $now === null ? time() : (int)$now;
    $expiry = strtotime($license->expires_utc . ' UTC');
    $days = $expiry === false ? -1 : (int)ceil(($expiry - $now) / DAY_IN_SECONDS);
    if ($license->status === 'blocked') {
        return array('text' => 'Bloquée', 'tone' => 'danger', 'days' => $days);
    }
    if ($license->status !== 'active') {
        return array('text' => 'Suspendue', 'tone' => 'warning', 'days' => $days);
    }
    if ($days <= 0) {
        $since = max(0, abs($days));
        return array('text' => $since ? 'Expirée depuis ' . $since . ' j' : 'Expirée',
            'tone' => 'danger', 'days' => $days);
    }
    if ($days <= 15) {
        return array('text' => 'Échéance dans ' . $days . ' j',
            'tone' => 'danger', 'days' => $days);
    }
    if ($days <= 30) {
        return array('text' => 'Échéance dans ' . $days . ' j',
            'tone' => 'warning', 'days' => $days);
    }
    return array('text' => 'Active', 'tone' => 'success', 'days' => $days);
}

function slmms_license_display_modules($license) {
    $names = array();
    foreach (slmms_modules($license) as $id) {
        if (get_post_type($id) === 'slm_module' &&
            absint(get_post_meta($id, '_slmms_software', true)) === (int)$license->software_id) {
            $names[] = get_the_title($id);
        }
    }
    return $names;
}

function slmms_license_version_label($license) {
    return (int)$license->major_version > 0
        ? 'v' . (int)$license->major_version . '.x'
        : 'Version majeure non attribuée';
}

function slmms_license_view_tab($requested) {
    return in_array($requested, array('summary', 'users', 'managers'), true)
        ? $requested : 'summary';
}

function slmms_license_dashboard_link($license_id = 0, $tab = 'summary') {
    $base = slmms_account_url($license_id);
    return $license_id ? add_query_arg('slmms_tab', slmms_license_view_tab($tab), $base) : $base;
}

function slmms_customer_licenses_dashboard($licenses, $actor_id) {
    global $wpdb;
    $selected = isset($_GET['slmms_license']) ? absint($_GET['slmms_license']) : 0;
    $tab = isset($_GET['slmms_tab']) ?
        sanitize_key(wp_unslash($_GET['slmms_tab'])) : 'summary';
    $tab = slmms_license_view_tab($tab);

    echo '<div class="slmms-client-dashboard">';
    echo '<header class="slmms-client-heading"><div><h2>Mes logiciels et licences</h2>';
    echo '<p>Retrouve les versions autorisées, tes modules et les personnes ayant accès aux logiciels.</p>';
    echo '</div></header>';

    if (!$licenses) {
        echo '<div class="slmms-empty"><h3>Aucune licence logicielle attribuée</h3>';
        echo '<p>Le titulaire de la licence ou un administrateur SLM Plus doit ' .
            'd’abord attribuer une licence à ton compte. ' .
            'Les anciennes licences classiques restent disponibles dans « My Licenses ».</p></div>';
        echo '</div>';
        return;
    }

    $users_table = slmms_table('users');
    $occupied_by_license = array();
    $count_licenses = 0;
    $total_seats = 0;
    $total_occupied = 0;
    $attention = 0;

    // One grouped query avoids a separate database query per software tile.
    $ids = array();
    foreach ($licenses as $license) { $ids[] = (int)$license->id; }
    if ($ids) {
        $slots = implode(',', array_fill(0, count($ids), '%d'));
        $q = $wpdb->prepare(
            "SELECT license_id, COUNT(*) AS occupied FROM $users_table " .
            "WHERE active = 1 AND license_id IN ($slots) GROUP BY license_id", $ids);
        foreach ((array)$wpdb->get_results($q) as $entry) {
            $occupied_by_license[(int)$entry->license_id] = (int)$entry->occupied;
        }
    }
    foreach ($licenses as $license) {
        ++$count_licenses;
        $total_seats += (int)$license->seats;
        $total_occupied += isset($occupied_by_license[(int)$license->id]) ?
            $occupied_by_license[(int)$license->id] : 0;
        if (slmms_license_health($license)['tone'] !== 'success') { ++$attention; }
    }

    echo '<div class="slmms-stats">';
    foreach (array(
        array('Licences logicielles', $count_licenses),
        array('Places attribuées', $total_occupied . ' / ' . $total_seats),
        array('Places disponibles', max(0, $total_seats - $total_occupied)),
        array('Licences à surveiller', $attention)
    ) as $stat) {
        echo '<div class="slmms-stat"><span>' . esc_html($stat[0]) . '</span>' .
            '<strong>' . esc_html((string)$stat[1]) . '</strong></div>';
    }
    echo '</div>';

    echo '<h3 class="slmms-section-heading">Mon portefeuille</h3>';
    echo '<div class="slmms-license-grid">';
    foreach ($licenses as $license) {
        $health = slmms_license_health($license);
        $modules = slmms_license_display_modules($license);
        $occupied = isset($occupied_by_license[(int)$license->id]) ?
            $occupied_by_license[(int)$license->id] : 0;
        $owner = get_userdata($license->owner_id);
        echo '<article class="slmms-license-tile' .
            ((int)$license->id === $selected ? ' is-selected' : '') . '">';
        echo '<div class="slmms-tile-heading"><h4>' .
            esc_html(get_the_title($license->software_id)) . '</h4>' .
            '<span class="slmms-status slmms-status--' . esc_attr($health['tone']) .
            '">' . esc_html($health['text']) . '</span></div>';
        echo '<p class="slmms-tile-meta"><strong>' .
            esc_html(slmms_license_version_label($license)) . '</strong>' .
            ' · ' . esc_html($occupied . ' / ' . $license->seats . ' places') . '</p>';
        echo '<p><strong>Modules :</strong> ' .
            esc_html($modules ? implode(', ', $modules) : 'Aucun module optionnel') . '</p>';
        echo '<p><strong>Expiration :</strong> ' .
            esc_html($license->expires_utc) . ' UTC</p>';
        echo '<p class="slmms-small">Titulaire : ' .
            esc_html($owner ? $owner->display_name : '#' . $license->owner_id) . '</p>';
        echo '<a class="slmms-link-button" href="' .
            esc_url(slmms_license_dashboard_link($license->id)) . '">Voir la licence</a>';
        echo '</article>';
    }
    echo '</div>';

    if ($selected) {
        // Check access again; never assume a selected ID belongs to the viewer.
        $license = slmms_license_for_id($selected, $actor_id);
        if (!$license || !slmms_actor_can($license, $actor_id, 'view')) {
            echo '<p role="alert">Cette licence est introuvable ou inaccessible.</p>';
        } else {
            slmms_customer_license_detail($license, $actor_id, $tab,
                isset($occupied_by_license[$selected]) ? $occupied_by_license[$selected] : 0);
        }
    }
    echo '</div>';
}

function slmms_customer_license_detail($license, $actor_id, $tab, $occupied) {
    $health = slmms_license_health($license);
    $can_users = slmms_actor_can($license, $actor_id, 'manage_users');
    $can_managers = slmms_actor_can($license, $actor_id, 'manage_managers');
    $tabs = array('summary' => 'Informations', 'users' => 'Utilisateurs');
    if ($can_managers) { $tabs['managers'] = 'Gestionnaires'; }

    echo '<section class="slmms-license-detail" aria-label="Détail de licence">';
    echo '<div class="slmms-detail-heading"><div><h3>' .
        esc_html(get_the_title($license->software_id)) . ' — ' .
        esc_html(slmms_license_version_label($license)) . '</h3>';
    echo '<p>Licence ' . esc_html($license->license_key) . '</p></div>';
    echo '<a class="slmms-back-link" href="' . esc_url(slmms_account_url()) .
        '">Retour au portefeuille</a></div>';
    echo '<nav class="slmms-detail-tabs" aria-label="Gestion de la licence">';
    foreach ($tabs as $key => $name) {
        echo '<a href="' . esc_url(slmms_license_dashboard_link($license->id, $key)) .
            '" class="' . ($tab === $key ? 'is-current' : '') . '"' .
            ($tab === $key ? ' aria-current="page"' : '') .
            '>' . esc_html($name) . '</a>';
    }
    echo '</nav>';
    if ($tab === 'users') {
        slmms_render_license_users($license, false);
    } elseif ($tab === 'managers' && $can_managers) {
        slmms_manager_panel($license, false);
    } else {
        $modules = slmms_license_display_modules($license);
        echo '<div class="slmms-license-summary"><dl>';
        foreach (array(
            'Statut' => $health['text'],
            'Version autorisée' => slmms_license_version_label($license),
            'Modules autorisés' => $modules ? implode(', ', $modules) : 'Aucun module',
            'Utilisateurs actifs' => $occupied . ' / ' . (int)$license->seats,
            'Places disponibles' => (string)max(0, (int)$license->seats - $occupied),
            'Expiration (UTC)' => $license->expires_utc
        ) as $field => $value) {
            echo '<div><dt>' . esc_html($field) . '</dt><dd>' .
                esc_html($value) . '</dd></div>';
        }
        echo '</dl></div>';
        if ($can_users) { slmms_offline_panel($license, false); }
        if ($health['tone'] !== 'success') {
            echo '<p class="slmms-warning" role="status">Cette licence nécessite ' .
                'une attention. Une licence expirée ou suspendue ne doit pas autoriser ' .
                'de nouvelles connexions logicielles.</p>';
        }
        echo '<p class="slmms-detail-cta"><a class="slmms-link-button" href="' .
            esc_url(slmms_license_dashboard_link($license->id, 'users')) . '">' .
            ($can_users ? 'Gérer les utilisateurs' : 'Consulter les utilisateurs') . '</a></p>';
    }
    echo '</section>';
}
