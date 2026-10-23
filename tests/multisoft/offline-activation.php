<?php
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
function slmms_entitlement_b64url($input) {
    return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
}
function wp_json_encode($obj) { return json_encode($obj); }
function untrailingslashit($s) { return rtrim($s, '/'); }
function home_url($path = '', $scheme = '') { return 'https://www.trueshield.fr'; }
function slmms_modules($license) { return array(10, 11); }
function get_post_type($id) { return in_array($id, array(10, 11), true) ? 'slm_module' : ''; }
function get_post_status($id) { return 'publish'; }
function get_post_meta($id, $meta, $single = true) { return $meta === '_slmms_software' ? 42 : null; }
function get_post_field($name, $id) { return $name === 'post_name' && $id === 42 ? 'progelec' : ''; }
function absint($input) { return abs((int)$input); }
function add_action() {}
function test_offline($condition, $label) {
    if (!$condition) { throw new RuntimeException('Échec : ' . $label); }
    echo 'OK : ' . $label . PHP_EOL;
}
require_once dirname(__DIR__, 2) . '/includes/multisoft/class-slmms-offline.php';

$device_key = openssl_pkey_new(array(
    'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'
));
test_offline($device_key !== false, 'génération de clé appareil P256');
$details = openssl_pkey_get_details($device_key);
$der_spki = base64_decode(preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s/', '', $details['key']));
$pub = slmms_entitlement_b64url($der_spki);
$nonce = slmms_entitlement_b64url(random_bytes(32));
$message = "OGIPEN-OFFLINE-REQUEST-v1\n42\n1\n" . $nonce . "\n" . $pub . "\n";
openssl_sign($message, $signature, $device_key, OPENSSL_ALGO_SHA256);
$request = array(
    'type' => 'OGIPEN-OFFLINE-REQUEST', 'version' => 1,
    'software_id' => 42, 'major' => 1, 'nonce' => $nonce,
    'public_key_spki' => $pub,
    'proof' => slmms_entitlement_b64url($signature)
);
$decoded = slmms_offline_request_check(wp_json_encode($request));
test_offline($decoded !== null && $decoded['device_hash'] === hash('sha256', $der_spki),
    'défi appareil signé et empreinte calculée');
$request['major'] = 2;
test_offline(slmms_offline_request_check(wp_json_encode($request)) === null,
    'requête altérée/refabrication d’une v2 refusée');
$request['major'] = 1;
$request['nonce'] = slmms_entitlement_b64url(random_bytes(32));
test_offline(slmms_offline_request_check(wp_json_encode($request)) === null,
    'réutilisation de signature pour nouveau défi refusée');
$request['nonce'] = $nonce;
$request['proof'] = 'non-base64url!';
test_offline(slmms_offline_request_check(wp_json_encode($request)) === null,
    'signature malformée refusée');
$request['proof'] = slmms_entitlement_b64url($signature);
test_offline(slmms_offline_request_check(wp_json_encode($request)) !== null,
    'requête saine de nouveau acceptée');

$now = time();
$license = (object) array('id' => 55, 'software_id' => 42,
    'major_version' => 1, 'seats' => 2,
    'expires_utc' => gmdate('Y-m-d H:i:s', $now + 7*DAY_IN_SECONDS),
    'status' => 'active');
$beneficiary = (object)array('id' => 88, 'license_id' => 55,
    'active' => 1, 'provider' => 'sam');
test_offline(slmms_offline_eligible($license, $beneficiary, $decoded, 30, 2, $now),
    'place SAM déclarée éligible pour attribution matérielle, sans prétendre prouver le login');
test_offline(!slmms_offline_eligible($license, $beneficiary, $decoded, 30, 3, $now),
    'dépassement places refusé');
test_offline(!slmms_offline_eligible($license, $beneficiary, $decoded, 366, 1, $now),
    'validité hors ligne limitée à 365 jours');
$license->major_version = 2;
test_offline(!slmms_offline_eligible($license, $beneficiary, $decoded, 30, 1, $now),
    'version majeure demandée différente refusée');
$license->major_version = 1;

$signer = openssl_pkey_new(array(
    'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'
));
$file = slmms_offline_license_sign($license, $beneficiary, $decoded,
    1234, 30, $now, $signer);
test_offline(is_string($file), 'fichier OGIPEN hors ligne signé');
$parsed = json_decode($file, true);
test_offline($parsed['type'] === 'OGIPEN-OFFLINE-LICENSE' &&
    $parsed['version'] === 1, 'enveloppe .oglic reconnue');
$parts = explode('.', $parsed['token']);
test_offline(count($parts) === 3, 'attestation à trois segments');
$claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
test_offline($claims['device_sha256'] === $decoded['device_hash'] &&
    $claims['nonce'] === $nonce && $claims['sub'] === 88 &&
    $claims['modules'] === array(10, 11),
    'droits liés au poste et à un bénéficiaire sans nom AD ou projet');
test_offline($claims['exp'] <= $now + 7*DAY_IN_SECONDS,
    'durée hors ligne plafonnée par l’échéance de la licence');
test_offline(openssl_verify($parts[0].'.'.$parts[1],
    base64_decode(strtr($parts[2], '-_', '+/')),
    openssl_pkey_get_details($signer)['key'], OPENSSL_ALGO_SHA256) === 1,
    'signature licence vérifiée à la clé publique serveur');
$claims['exp'] = $now + 365*DAY_IN_SECONDS;
test_offline($claims['exp'] > $now + 7*DAY_IN_SECONDS,
    'une prolongation locale de validité nécessiterait une nouvelle signature');
echo "Contrat hors ligne P256 vérifié.\n";
