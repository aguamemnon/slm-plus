<?php
define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__, 2) . '/includes/multisoft/class-slmms-versions.php';
require_once dirname(__DIR__, 2) . '/includes/multisoft/class-slmms-plan.php';

function check_major($actual, $expected, $name) {
    if ($actual !== $expected) { throw new RuntimeException("$name expected $expected"); }
    echo "OK: $name\n";
}
foreach (array('1.0.0' => 1, '1.8' => 1, '2.0.0' => 2,
               '10.1.20' => 10, '999.0' => 999) as $version => $major) {
    check_major(slmms_parse_major_version($version), $major, $version);
}
foreach (array('1', '0.0', '01.5.0', '1.2-beta', 'abc', '1.2.3.4',
               '1x2', '1.02', '1000.0') as $invalid) {
    check_major(slmms_parse_major_version($invalid), 0, 'invalid '.$invalid);
}
check_major(slmms_can_upgrade_major(1, 2), true, 'upgrade v1 vers v2');
check_major(slmms_can_upgrade_major(1, 3), true, 'upgrade v1 vers v3');
check_major(slmms_can_upgrade_major(2, 2), false, 'pas de réachat v2 vers v2');
check_major(slmms_can_upgrade_major(2, 1), false, 'pas de rétrogradation');
check_major(slmms_can_upgrade_major(0, 2), false, 'version inconnue: refus');
check_major(slmms_same_major(1, 1), true, 'renouvellement v1 vers v1');
check_major(slmms_same_major(1, 2), false, 'renouvellement v1 ne donne pas v2');

$now = new DateTimeImmutable('2026-10-22 00:00:00', new DateTimeZone('UTC'));
$upgrade = slmms_compute_entitlement('upgrade', 7, array(15, 22),
    '2027-02-10 00:00:00', 0, 0, array(22, 33), $now);
check_major($upgrade['seats'], 7, 'upgrade: mêmes places');
check_major($upgrade['modules'], array(15, 22, 33), 'upgrade: conserver modules et ajouter module');
check_major($upgrade['expires_utc'], '2027-02-10 00:00:00', 'upgrade: date inchangée');
$upAndRenew = slmms_compute_entitlement('upgrade', 7, array(15),
    '2027-02-10 00:00:00', 30, 0, array(), $now);
check_major($upAndRenew['expires_utc'], '2027-03-12 00:00:00',
    'upgrade + prolongation: garder les jours restants');

// Simulated target licence. WooCommerce must refuse cross-major renewals and
// downgrades BEFORE checkout, then re-check under DB lock after payment.
require_once dirname(__DIR__, 2) . '/includes/multisoft/class-slmms-woocommerce.php';
function slmms_owned_license($key, $owner_id = null, $software_id = 0) {
    if ($key !== 'MS-TESTKEY' || $owner_id !== 42 || $software_id !== 123) {
        return null;
    }
    return $GLOBALS['test_license'];
}
$GLOBALS['test_license'] = (object) array(
    'major_version' => 1, 'status' => 'active',
    'expires_utc' => '2027-02-10 00:00:00'
);
$target = array('mode' => 'upgrade', 'software_id' => 123, 'major' => 2, 'days' => 0);
check_major(slmms_validate_target($target, 'MS-TESTKEY', 42), true,
    'panier: upgrade v1 vers v2 autorisé');
check_major(slmms_validate_target($target, 'MS-TESTKEY', 43), false,
    'panier: licence d’un autre utilisateur refusée');
check_major(slmms_validate_target($target, 'MS-TESTKEY', 42) &&
    !slmms_validate_target($target + array('other' => 'irrelevant'), 'wrong', 42),
    true, 'panier: validation clé cible');
$renewV1 = array('mode' => 'renew', 'software_id' => 123, 'major' => 1, 'days' => 365);
check_major(slmms_validate_target($renewV1, 'MS-TESTKEY', 42), true,
    'panier: renouvellement v1 autorisé');
$renewV2 = array('mode' => 'renew', 'software_id' => 123, 'major' => 2, 'days' => 365);
check_major(slmms_validate_target($renewV2, 'MS-TESTKEY', 42), false,
    'panier: renouvellement v1 ne peut pas attribuer v2');
$GLOBALS['test_license']->major_version = 2;
check_major(slmms_validate_target($target, 'MS-TESTKEY', 42), false,
    'panier: upgrade v1 vers v2 non rejouable');
$GLOBALS['test_license']->major_version = 1;
$GLOBALS['test_license']->expires_utc = '2025-12-31 00:00:00';
check_major(slmms_validate_target($target, 'MS-TESTKEY', 42), false,
    'panier: upgrade sans prolongation sur licence expirée refusé');
$target['days'] = 365;
check_major(slmms_validate_target($target, 'MS-TESTKEY', 42), true,
    'panier: upgrade expiré accompagné d’un renouvellement autorisé');

echo "OK: tests de compatibilité des versions et offres upgrade terminés.\n";
