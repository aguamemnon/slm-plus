<?php
define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__, 2) . '/includes/multisoft/class-slmms-plan.php';

function slmms_assert($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    echo "OK: " . $message . PHP_EOL;
}

$now = new DateTimeImmutable('2026-10-22 00:00:00', new DateTimeZone('UTC'));
$new = slmms_compute_entitlement('new', 0, array(), null, 365, 5, array(10, 20), $now);
slmms_assert($new['seats'] === 5 && $new['modules'] === array(10, 20) &&
    $new['expires_utc'] === '2027-10-22 00:00:00', 'Nouveau logiciel: cinq places, deux modules et un an');

$add = slmms_compute_entitlement('modules', 5, array(10, 20),
    $new['expires_utc'], 0, 0, array(20, 30), $now);
slmms_assert($add['seats'] === 5 && $add['modules'] === array(10, 20, 30) &&
    $add['expires_utc'] === $new['expires_utc'], 'Ajout module: aucune double attribution ni prolongation');

$seats = slmms_compute_entitlement('seats', 5, array(10, 20),
    $new['expires_utc'], 0, 3, array(), $now);
slmms_assert($seats['seats'] === 8, 'Achat de trois places supplémentaires');

$renew = slmms_compute_entitlement('renew', 5, array(10),
    '2027-10-22 00:00:00', 30, 0, array(), $now);
slmms_assert($renew['expires_utc'] === '2027-11-21 00:00:00',
    'Prolongation à partir de la date de fin existante');

$expired = slmms_compute_entitlement('renew_modules', 5, array(10),
    '2026-09-01 00:00:00', 30, 0, array(20), $now);
slmms_assert($expired['expires_utc'] === '2026-11-21 00:00:00' &&
    $expired['modules'] === array(10, 20),
    'Renouvellement d’une licence expirée depuis aujourd’hui et ajout de module');

try {
    slmms_compute_entitlement('new', 0, array(), null, 0, 0, array(), $now);
    throw new RuntimeException('Création invalide acceptée');
} catch (InvalidArgumentException $expected) {
    echo "OK: refus d'une licence sans durée ou places" . PHP_EOL;
}
echo "SLM Multi-Software: tests de logique réussis." . PHP_EOL;
