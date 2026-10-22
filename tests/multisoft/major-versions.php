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
echo "OK: tests de compatibilité des versions et offres upgrade terminés.\n";
