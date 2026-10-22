<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Pure entitlement computation, reused by the paid-order handler.
 * No database access, no WooCommerce state; safe to test independently.
 */
function slmms_compute_entitlement($mode, $old_seats, $old_modules,
    $old_expiry, $days, $seats, $added_modules, DateTimeImmutable $now) {

    $valid = array('new', 'renew', 'modules', 'renew_modules', 'seats', 'upgrade');
    if (!in_array($mode, $valid, true) || $days < 0 || $seats < 0 ||
        $days > 36500 || $seats > 100000) {
        throw new InvalidArgumentException('Invalid licensing operation.');
    }

    $modules = array_values(array_unique(array_map('intval', (array)$old_modules)));
    $new_seats = (int)$old_seats;
    $expiry = $old_expiry;

    if ($mode === 'new') {
        if ($days < 1 || $seats < 1) { throw new InvalidArgumentException('Invalid initial duration or seats.'); }
        $new_seats = $seats;
        $modules = array_values(array_unique(array_map('intval', $added_modules)));
        $expiry = $now->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
    } elseif ($mode === 'upgrade') {
        // Upgrade v1 -> v2: same license, users/seats retained; optionally
        // buy modules and/or prolong validity as part of the upgrade offer.
        $modules = array_values(array_unique(array_merge($modules, $added_modules)));
        if ($days > 0) {
            $current = new DateTimeImmutable($old_expiry, new DateTimeZone('UTC'));
            $from = $current > $now ? $current : $now;
            $expiry = $from->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
        }
    } elseif ($mode === 'seats') {
        if ($seats < 1) { throw new InvalidArgumentException('No additional seats.'); }
        $new_seats += $seats;
    } elseif ($mode === 'modules') {
        if (!$added_modules) { throw new InvalidArgumentException('No additional modules.'); }
        $modules = array_values(array_unique(array_merge($modules, $added_modules)));
    } elseif ($mode === 'renew_modules' || $mode === 'renew') {
        if ($days < 1 || ($mode === 'renew_modules' && !$added_modules)) {
            throw new InvalidArgumentException('Invalid renewal.');
        }
        if ($mode === 'renew_modules') {
            $modules = array_values(array_unique(array_merge($modules, $added_modules)));
        }
        $current = new DateTimeImmutable($old_expiry, new DateTimeZone('UTC'));
        $from = $current > $now ? $current : $now;
        $expiry = $from->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
    }
    if ($new_seats < 1 || $new_seats > 100000) {
        throw new InvalidArgumentException('Seat limit exceeded.');
    }
    return array('seats' => $new_seats, 'modules' => $modules, 'expires_utc' => $expiry);
}
