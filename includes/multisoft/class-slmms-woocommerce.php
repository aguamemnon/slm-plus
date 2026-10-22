<?php
if (!defined('ABSPATH')) { exit; }

/** New multi-software products are ordinary WooCommerce simple products. */
function slmms_register_woocommerce_hooks() {
    slmms_register_woocommerce_catalog_fields();
    add_action('woocommerce_before_add_to_cart_button', 'slmms_target_license_field');
    add_filter('woocommerce_add_to_cart_validation', 'slmms_validate_add_to_cart', 10, 5);
    add_filter('woocommerce_add_cart_item_data', 'slmms_capture_cart_item', 10, 3);
    add_filter('woocommerce_get_item_data', 'slmms_cart_summary', 10, 2);
    add_action('woocommerce_check_cart_items', 'slmms_validate_checkout_cart');
    add_action('woocommerce_checkout_create_order_line_item', 'slmms_snapshot_order_item', 10, 4);
    add_action('woocommerce_payment_complete', 'slmms_fulfill_paid_order', 20);
    add_action('woocommerce_order_status_processing', 'slmms_fulfill_paid_order', 20);
    add_action('woocommerce_order_status_completed', 'slmms_fulfill_paid_order', 20);
}

/** Used by legacy SLM completion hooks to avoid double-issuance. */
function slmms_order_contains_managed($order) {
    if (!$order) { return false; }
    foreach ($order->get_items('line_item') as $item) {
        if ($item->get_meta('_slmms_mode', true) ||
            get_post_meta($item->get_product_id(), '_slmms_mode', true)) { return true; }
    }
    return false;
}

function slmms_target_license_field() {
    global $product;
    if (!$product) { return; }
    $offer = slmms_product_offer($product->get_id());
    if (!$offer || $offer['mode'] === 'new') { return; }
    echo '<div class="slmms-target-license"><label for="slmms_target_license"><strong>' .
        esc_html__('Licence à modifier (obligatoire avant ajout au panier)', 'slm-plus') .
        '</strong></label><br /><input id="slmms_target_license" type="text" required ' .
        'name="slmms_target_license" autocomplete="off" maxlength="80" ' .
        'placeholder="MS-..." /></div>';
}

function slmms_validate_target($offer, $key, $owner_id) {
    if ($offer['mode'] === 'new') { return true; }
    if (!$owner_id) { return false; }
    $license = slmms_owned_license($key, $owner_id, $offer['software_id']);
    if (!$license || !in_array($license->status, array('active', 'expired'), true)) {
        return false;
    }
    $source_major = (int)$license->major_version;
    $target_major = (int)$offer['major'];
    if ($offer['mode'] === 'upgrade') {
        // Cannot buy a version change that leaves an expired license unusable.
        $expired = strtotime($license->expires_utc . ' UTC') <= time();
        return slmms_can_upgrade_major($source_major, $target_major) &&
            (!$expired || (int)$offer['days'] > 0);
    }
    return slmms_same_major($source_major, $target_major);
}

function slmms_validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0, $cart_item_data = array()) {
    $offer = slmms_product_offer($product_id);
    // Fail closed for a malformed multi-software product instead of charging
    // the buyer without generating their entitled licence.
    if (!$offer && get_post_meta($product_id, '_slmms_mode', true)) {
        wc_add_notice('La configuration de ce produit logiciel est incomplète.', 'error');
        return false;
    }
    if (!$offer) { return $passed; }
    if (!is_user_logged_in()) {
        wc_add_notice('Connectez-vous pour attribuer une licence à votre compte client.', 'error');
        return false;
    }
    // One operation per order in v0.1: prevents cross-product legacy hooks
    // and makes licenses and renewals deterministic.
    if ((int)$quantity !== 1 || (WC()->cart && !WC()->cart->is_empty())) {
        wc_add_notice('Placez une seule offre logicielle par commande.', 'error');
        return false;
    }
    $key = isset($_POST['slmms_target_license'])
        ? strtoupper(sanitize_text_field(wp_unslash($_POST['slmms_target_license']))) : '';
    if (!slmms_validate_target($offer, $key, get_current_user_id())) {
        wc_add_notice('Indiquez une licence existante qui vous appartient et correspond à ce logiciel.', 'error');
        return false;
    }
    return $passed;
}

function slmms_capture_cart_item($cart_item_data, $product_id, $variation_id) {
    $offer = slmms_product_offer($product_id);
    if (!$offer) { return $cart_item_data; }
    $offer['target'] = $offer['mode'] === 'new' ? '' :
        strtoupper(sanitize_text_field(wp_unslash(
            isset($_POST['slmms_target_license']) ? $_POST['slmms_target_license'] : ''
        )));
    $cart_item_data['slmms_offer'] = $offer;
    // Unique cart item; do not reuse an earlier buyer's selection.
    $cart_item_data['slmms_random'] = wp_generate_uuid4();
    return $cart_item_data;
}

function slmms_cart_summary($display, $cart_item) {
    if (empty($cart_item['slmms_offer'])) { return $display; }
    $offer = $cart_item['slmms_offer'];
    $display[] = array('key' => 'Opération licence', 'value' => esc_html($offer['mode']));
    if (!empty($offer['target'])) {
        $display[] = array('key' => 'Licence ciblée', 'value' => esc_html($offer['target']));
    }
    return $display;
}

function slmms_validate_checkout_cart() {
    if (!WC()->cart) { return; }
    $all = WC()->cart->get_cart();
    $managed = 0;
    foreach ($all as $item) {
        if (isset($item['slmms_offer'])) {
            ++$managed;
            if (!slmms_validate_target(
                $item['slmms_offer'], $item['slmms_offer']['target'], get_current_user_id()
            )) {
                wc_add_notice('La licence à modifier n’est plus disponible pour votre compte.', 'error');
            }
        }
    }
    if ($managed && ($managed !== 1 || count($all) !== 1)) {
        wc_add_notice('Séparez les opérations de licences multi-logiciels des autres produits.', 'error');
    }
}

function slmms_snapshot_order_item($item, $cart_item_key, $values, $order) {
    if (empty($values['slmms_offer'])) { return; }
    $o = $values['slmms_offer'];
    $item->add_meta_data('_slmms_mode', $o['mode'], true);
    $item->add_meta_data('_slmms_software', $o['software_id'], true);
    $item->add_meta_data('_slmms_major', $o['major'], true);
    $item->add_meta_data('_slmms_modules', $o['modules'], true);
    $item->add_meta_data('_slmms_days', $o['days'], true);
    $item->add_meta_data('_slmms_seats', $o['seats'], true);
    $item->add_meta_data('_slmms_target', $o['target'], true);
}

/** Only a paid order can create/modify entitlements; never fulfill 'pending'. */
function slmms_fulfill_paid_order($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || !$order->is_paid() || !$order->get_date_paid() || !$order->get_user_id()) {
        return;
    }
    if (!slmms_order_contains_managed($order)) { return; }
    $items = $order->get_items('line_item');
    if (count($items) !== 1) {
        $order->add_order_note('SLM Multi : commande mixte non prise en charge. Intervention requise.');
        return;
    }
    foreach ($items as $item_id => $item) {
        if (!$item->get_meta('_slmms_mode', true)) {
            $order->add_order_note('SLM Multi : opération manquante. Intervention requise.');
            return;
        }
        $applied = slmms_apply_paid_item($order, $item_id, $item);
        if (is_wp_error($applied)) {
            $order->add_order_note('SLM Multi : attribution différée : ' . $applied->get_error_message());
        } elseif ($applied !== false) {
            $order->add_order_note('SLM Multi : licence #' . (int) $applied . ' actualisée.');
        }
    }
}

/** Per-order-item idempotent transaction. Unique ledger key guards concurrent callbacks. */
function slmms_apply_paid_item($order, $item_id, $item) {
    global $wpdb;
    $licenses = slmms_table('license');
    $ledger = slmms_table('ledger');
    $mode = $item->get_meta('_slmms_mode', true);
    $software = absint($item->get_meta('_slmms_software', true));
    $target_major = absint($item->get_meta('_slmms_major', true));
    $days = absint($item->get_meta('_slmms_days', true));
    $seats = absint($item->get_meta('_slmms_seats', true));
    $modules = $item->get_meta('_slmms_modules', true);
    if (!is_array($modules)) { $modules = array(); }
    $modules = array_values(array_unique(array_map('absint', $modules)));
    $target = (string) $item->get_meta('_slmms_target', true);
    $owner = (int) $order->get_user_id();
    if (!in_array($mode, array('new', 'renew', 'modules', 'renew_modules', 'seats', 'upgrade'), true) ||
        !$software || get_post_type($software) !== 'slm_software' ||
        !slmms_major_is_sellable($software, $target_major) ||
        $seats > 100000 || $days > 36500 ||
        (in_array($mode, array('new', 'renew', 'renew_modules'), true) && !$days) ||
        (in_array($mode, array('new', 'seats'), true) && !$seats)) {
        return new WP_Error('slmms_offer', 'Données d’offre invalides.');
    }
    foreach ($modules as $mid) {
        if (get_post_type($mid) !== 'slm_module' ||
            absint(get_post_meta($mid, '_slmms_software', true)) !== $software) {
            return new WP_Error('slmms_modules', 'Module incompatible avec le logiciel.');
        }
    }
    if ($wpdb->query('START TRANSACTION') === false) {
        return new WP_Error('slmms_db', 'Transaction SQL indisponible.');
    }

    try {
        $already = $wpdb->get_var($wpdb->prepare(
            "SELECT license_id FROM $ledger WHERE order_item_id = %d LIMIT 1", $item_id
        ));
        if ($already) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $stamp = $now->format('Y-m-d H:i:s');
        if ($mode === 'new') {
            $license_key = 'MS-' . strtoupper(bin2hex(random_bytes(16)));
            $plan = slmms_compute_entitlement('new', 0, array(), null,
                $days, $seats, $modules, $now);
            $expiry = $plan['expires_utc'];
            if ($wpdb->insert($licenses, array(
                'license_key' => $license_key, 'owner_id' => $owner,
                'software_id' => $software, 'major_version' => $target_major,
                'seats' => $plan['seats'],
                'modules_json' => wp_json_encode($plan['modules']),
                'expires_utc' => $expiry, 'status' => 'active',
                'created_utc' => $stamp, 'updated_utc' => $stamp
            )) === false) { throw new RuntimeException('Insertion licence échouée.'); }
            $license_id = (int) $wpdb->insert_id;
        } else {
            $license = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $licenses WHERE license_key = %s AND owner_id = %d AND software_id = %d FOR UPDATE",
                $target, $owner, $software
            ));
            if (!$license || !in_array($license->status, array('active', 'expired'), true)) {
                throw new RuntimeException('Licence cible introuvable ou non autorisée.');
            }
            // Never let a v2 upgrade be replayed as v2->v2, even after
            // concurrent callbacks or a stale shopping cart.
            $source_major = (int)$license->major_version;
            if ($mode === 'upgrade') {
                if (!slmms_can_upgrade_major($source_major, $target_major) ||
                    (strtotime($license->expires_utc . ' UTC') <= time() && !$days)) {
                    throw new RuntimeException('Mise à niveau impossible : version source ou durée incorrecte.');
                }
            } elseif (!slmms_same_major($source_major, $target_major)) {
                throw new RuntimeException('Version majeure non compatible avec cette licence.');
            }
            $license_id = (int) $license->id;
            $plan = slmms_compute_entitlement($mode, (int)$license->seats,
                slmms_modules($license), $license->expires_utc,
                $days, $seats, $modules, $now);
            if ($wpdb->update($licenses, array(
                'seats' => $plan['seats'],
                'modules_json' => wp_json_encode($plan['modules']),
                'expires_utc' => $plan['expires_utc'],
                'major_version' => $mode === 'upgrade' ? $target_major : $source_major,
                'status' => ($days > 0 && in_array($mode, array('renew', 'renew_modules', 'upgrade'), true)) ?
                    'active' : $license->status,
                'updated_utc' => $stamp
            ), array('id' => $license_id)) === false) {
                throw new RuntimeException('Mise à jour licence échouée.');
            }
        }

        if ($wpdb->insert($ledger, array(
            'order_item_id' => (int) $item_id,
            'order_id' => (int) $order->get_id(),
            'license_id' => $license_id, 'operation' => $mode, 'applied_utc' => $stamp
        )) === false) { throw new RuntimeException('Enregistrement d’opération échoué.'); }

        if ($wpdb->query('COMMIT') === false) {
            throw new RuntimeException('Transaction SQL non confirmée.');
        }
        return $license_id;
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('slmms_fulfill', $error->getMessage());
    }
}
