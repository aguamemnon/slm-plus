<?php
// Contract tests, no WordPress or WooCommerce installation required.
// Key is generated in the runner temporary directory and never committed.
define('ABSPATH', '/var/www/html/');
define('SLMMS_SIGNING_PRIVATE_KEY_PATH', tempnam(sys_get_temp_dir(), 'slmms-key-'));
define('OPENSSL_EC_TEST', true);
function test_ok($condition, $label) {
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
    echo 'OK: ' . $label . PHP_EOL;
}
function absint($v) { return abs((int)$v); }
function wp_json_encode($v) { return json_encode($v); }
function home_url($x = '', $scheme = '') { return 'https://example.test'; }
function untrailingslashit($x) { return rtrim($x, '/'); }
function is_ssl() { return $GLOBALS['https']; }
function is_user_logged_in() { return $GLOBALS['authenticated']; }
function get_current_user_id() { return 7; }
function nocache_headers() {}
function rest_ensure_response($v) { return $v; }
function get_post_type($id) { return $id === 42 ? 'slm_software' :
    (in_array($id, array(10, 11), true) ? 'slm_module' : 'wrong'); }
function get_post_status($id) { return 'publish'; }
function get_post_meta($id, $key, $single = true) {
    return in_array($id, array(10,11), true) && $key === '_slmms_software' ? 42 : null;
}
function get_post_field($field, $id) { return $field === 'post_name' && $id === 42 ? 'progelec' : ''; }
function slmms_table($s) { return $s === 'license' ? 'test_licenses' : 'test_users'; }
function slmms_modules($row) { return $GLOBALS['modules']; }
function register_rest_route($namespace, $route, $options) {
    $GLOBALS['route'] = compact('namespace', 'route', 'options');
}
class WP_Error {
    public $code;
    public $message;
    public $data;
    function __construct($code, $message, $data) {
        $this->code = $code; $this->message = $message; $this->data = $data;
    }
}
class EntitlementRequest {
    private $data;
    public function __construct($data) { $this->data = $data; }
    public function get_param($key) { return isset($this->data[$key]) ? $this->data[$key] : null; }
}
class FakeDB {
    public $row;
    public $seats = 1;
    public $duplicates = false;
    public function prepare($sql, ...$args) { return vsprintf($sql, $args); }
    public function get_results($sql) {
        return $this->duplicates ? array($this->row, $this->row) :
            (strpos($sql, 'l.id = 55') !== false &&
             strpos($sql, 'u.identity_wp_user_id = 7') !== false ? array($this->row) : array());
    }
    public function get_var($sql) { return $this->seats; }
}
$GLOBALS['wpdb'] = new FakeDB();
$GLOBALS['modules'] = array(10, 11);
$GLOBALS['https'] = true;
$GLOBALS['authenticated'] = true;
$now = time();
$GLOBALS['wpdb']->row = (object)array(
    'id' => 55, 'user_license_id' => 55, 'beneficiary_id' => 99,
    'software_id' => 42, 'major_version' => 1, 'seats' => 2,
    'status' => 'active', 'expires_utc' => gmdate('Y-m-d H:i:s', $now + 3600),
    'provider' => 'ogipen', 'beneficiary_active' => 1,
    'identity_verified' => 1, 'identity_wp_user_id' => 7
);
require_once dirname(__DIR__, 2) . '/includes/multisoft/class-slmms-entitlements.php';

try {
    slmms_register_entitlements_api();
    test_ok($GLOBALS['route']['namespace'] === 'slmms/v1' &&
        $GLOBALS['route']['route'] === '/entitlements/issue' &&
        $GLOBALS['route']['options']['methods'] === 'POST',
        'endpoint REST POST enregistré');
    $request = new EntitlementRequest(array('license_id' => 55,
        'software_id' => 42, 'major_version' => 1));
    $GLOBALS['https'] = false;
    test_ok(slmms_rest_entitlement_permission() instanceof WP_Error,
        'HTTP non chiffré refusé');
    $GLOBALS['https'] = true;
    $GLOBALS['authenticated'] = false;
    test_ok(slmms_rest_entitlement_permission() instanceof WP_Error,
        'connexion anonyme refusée');
    $GLOBALS['authenticated'] = true;
    test_ok(slmms_rest_entitlement_permission() === true,
        'compte connecté en HTTPS accepté pour contrôle des droits');
    test_ok(slmms_entitlement_resolve(55, 42, 1, 7, $now)['beneficiary_id'] === 99,
        'la licence et le compte WordPress vérifié correspondent');

    $row = $GLOBALS['wpdb']->row;
    $user = (object)array('license_id' => 55, 'provider' => 'ogipen',
        'identity_verified' => 1, 'identity_wp_user_id' => 7, 'active' => 1);
    test_ok(slmms_entitlement_check($row,$user,7,42,1,1,$now),
        'licence v1 avec module/places conforme');
    test_ok(!slmms_entitlement_check($row,$user,7,42,2,1,$now),
        'refus version majeure v1 vers v2 sans upgrade');
    test_ok(!slmms_entitlement_check($row,$user,7,43,1,1,$now),
        'refus autre logiciel');
    test_ok(!slmms_entitlement_check($row,$user,7,42,1,3,$now),
        'refus quota dépassé');
    $user->provider = 'sam';
    test_ok(!slmms_entitlement_check($row,$user,7,42,1,1,$now),
        'SAM déclaré ne vaut pas attestation');
    $user->provider = 'ad';
    test_ok(!slmms_entitlement_check($row,$user,7,42,1,1,$now),
        'AD déclaré ne vaut pas attestation');
    $user->provider = 'ogipen'; $user->identity_verified = 0;
    test_ok(!slmms_entitlement_check($row,$user,7,42,1,1,$now),
        'portail sans code confirmé refusé');
    $user->identity_verified = 1;
    $row->status = 'blocked';
    test_ok(!slmms_entitlement_check($row,$user,7,42,1,1,$now),
        'licence bloquée refusée');
    $row->status = 'active';
    $GLOBALS['modules'] = array(10, 9999);
    test_ok(slmms_entitlement_resolve(55, 42, 1, 7, $now) === null,
        'module étranger/inexistant empêche émission');
    $GLOBALS['modules'] = array(10, 11);
    $GLOBALS['wpdb']->duplicates = true;
    test_ok(slmms_entitlement_resolve(55, 42, 1, 7, $now) === null,
        'plusieurs bénéficiaires vérifiés sur même licence refusés');
    $GLOBALS['wpdb']->duplicates = false;

    $private = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1'));
    test_ok($private !== false, 'clé EC prime256v1 générée pour les tests');
    openssl_pkey_export($private, $pem);
    file_put_contents(SLMMS_SIGNING_PRIVATE_KEY_PATH, $pem);
    chmod(SLMMS_SIGNING_PRIVATE_KEY_PATH, 0600);
    $key = slmms_signing_private_key();
    test_ok($key !== null, 'clé lue en dehors du dossier WordPress');
    $entitlement = slmms_entitlement_resolve(55,42,1,7,$now);
    $signed = slmms_entitlement_sign($entitlement,$key,'https://example.test',$now);
    test_ok($signed && $signed['expires_at'] <= $entitlement['license_expires_at'] &&
        $signed['expires_at'] <= $now + 3600, 'attestation expirant avant licence et dans 1 h');
    $segments = explode('.', $signed['token']);
    test_ok(count($segments) === 3, 'format signé à trois segments');
    $data = $segments[0] . '.' . $segments[1];
    $sig = base64_decode(strtr($segments[2], '-_', '+/'));
    $pub = openssl_pkey_get_details($key)['key'];
    test_ok(openssl_verify($data,$sig,$pub,OPENSSL_ALGO_SHA256) === 1,
        'signature ECDSA vérifiable avec clé publique');
    test_ok(openssl_verify($data . 'x',$sig,$pub,OPENSSL_ALGO_SHA256) === 0,
        'modification du jeton rend la signature invalide');
    $claims = json_decode(base64_decode(strtr($segments[1], '-_', '+/')),true);
    test_ok($claims['major'] === 1 && $claims['software'] === 'progelec' &&
        $claims['modules'] === array(10,11) && $claims['sub'] === 99,
        'attestation liée au bon logiciel, bénéficiaire et modules');
    $issued = slmms_rest_issue_entitlement($request);
    test_ok(is_array($issued) && !empty($issued['token']) &&
        $issued['valid_for_seconds'] <= 3600, 'endpoint émet attestation valide');
    $rejected = slmms_rest_issue_entitlement(new EntitlementRequest(array(
        'license_id' => 55, 'software_id' => 42, 'major_version' => 2
    )));
    test_ok($rejected instanceof WP_Error && $rejected->data['status'] === 403,
        'endpoint refuse licence pour autre version majeure');
    $GLOBALS['wpdb']->row->expires_utc = gmdate('Y-m-d H:i:s',$now-5);
    $expired = slmms_rest_issue_entitlement($request);
    test_ok($expired instanceof WP_Error && $expired->data['status'] === 403,
        'licence expirée ne peut recevoir de jeton');
    echo "OK: tous les tests de l’API d’attestation nominative ont réussi.\n";
} finally {
    @unlink(SLMMS_SIGNING_PRIVATE_KEY_PATH);
}
