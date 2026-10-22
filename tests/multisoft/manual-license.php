<?php
define('ABSPATH', __DIR__ . '/');
function add_action() {}
function admin_url($path) { return 'https://test.example/wp-admin/' . $path; }
function user_can($user, $permission) { return $user === 99 && $permission === 'manage_options'; }
function get_userdata($id) { return $id === 5 ? (object)array('ID' => 5) : false; }
function get_post_type($id) {
    return $id === 10 ? 'slm_software' : (in_array($id, array(20, 21, 30), true) ? 'slm_module' : '');
}
function get_post_status($id) { return 'publish'; }
function slmms_software_major($id) {
    return $id === 10 ? 2 : 0;
}
function slmms_major_is_sellable($software_id, $major) {
    return $software_id === 10 && $major >= 1 && $major <= 2;
}
function get_post_meta($id, $key, $single = true) {
    if ($key === '_slmms_software') {
        return in_array($id, array(20, 21), true) ? 10 : 11;
    }
    return '';
}
function absint($value) { return abs((int)$value); }
function wp_json_encode($value) { return json_encode($value); }
function slmms_table($type) {
    $types = array('license' => 'test_licenses', 'manual_requests' => 'test_requests');
    return $types[$type];
}
$GLOBALS['audit_events'] = array();
function slmms_audit($owner, $actor, $license_id, $event, $subject) {
    $GLOBALS['audit_events'][] = compact('owner', 'actor', 'license_id', 'event', 'subject');
    return true;
}
function verify($condition, $name) {
    if (!$condition) { throw new RuntimeException('FAILED: ' . $name); }
    echo 'OK: ' . $name . PHP_EOL;
}

class ManualDbFake {
    public $insert_id = 0;
    public $licenses = array();
    public $requests = array();
    private $snapshot;
    public function query($sql) {
        if ($sql === 'START TRANSACTION') {
            $this->snapshot = array($this->licenses, $this->requests);
        }
        if ($sql === 'ROLLBACK' && $this->snapshot !== null) {
            list($this->licenses, $this->requests) = $this->snapshot;
            $this->snapshot = null;
        }
        if ($sql === 'COMMIT') { $this->snapshot = null; }
        return 1;
    }
    public function insert($table, $data) {
        if ($table === 'test_requests') {
            $key = $data['request_key'];
            if (isset($this->requests[$key])) { return false; }
            $this->requests[$key] = $data;
            return 1;
        }
        if ($table === 'test_licenses') {
            $this->insert_id = count($this->licenses) + 1;
            $this->licenses[$this->insert_id] = $data;
            return 1;
        }
        return false;
    }
    public function update($table, $data, $where) {
        if ($table !== 'test_requests') { return false; }
        $key = $where['request_key'];
        if (!isset($this->requests[$key]) ||
            $this->requests[$key]['actor_user_id'] !== $where['actor_user_id']) {
            return false;
        }
        $this->requests[$key] = array_merge($this->requests[$key], $data);
        return 1;
    }
}
$GLOBALS['wpdb'] = new ManualDbFake();
require_once dirname(__DIR__, 2) . '/includes/multisoft/class-slmms-manual.php';

$ready = slmms_manual_software_choice((object)array(
    'ID' => 10, 'post_status' => 'publish', 'post_title' => 'Progelec'));
verify($ready['ready'] === true && $ready['major'] === 2 &&
    strpos($ready['label'], 'v2.x') !== false,
    'logiciel publié avec version enregistrée disponible');

$no_version = slmms_manual_software_choice((object)array(
    'ID' => 11, 'post_status' => 'publish', 'post_title' => 'OGIPEN'));
verify($no_version['ready'] === false &&
    strpos($no_version['label'], 'version non renseignée') !== false,
    'logiciel sans version reste visible mais impossible à sélectionner');

$draft = slmms_manual_software_choice((object)array(
    'ID' => 10, 'post_status' => 'draft', 'post_title' => 'Progelec'));
verify($draft['ready'] === false &&
    strpos($draft['label'], 'à publier') !== false,
    'logiciel en brouillon reste visible mais désactivé');

$customer = get_userdata(5);
verify(slmms_manual_license_validate($customer, 10, 2, array(20,21), 3, 365) === '',
    'logiciel v2, deux modules et trois utilisateurs validés');
verify(slmms_manual_license_validate($customer, 10, 3, array(), 3, 365) === 'software',
    'version majeure non publiée interdite');
verify(slmms_manual_license_validate($customer, 10, 2, array(30), 3, 365) === 'modules',
    'module d’un autre logiciel refusé');
verify(slmms_manual_license_validate($customer, 10, 2, array(), 0, 365) === 'limits',
    'places nulles refusées');
verify(slmms_manual_license_validate($customer, 10, 2, array(), 1, 0) === 'limits',
    'licence sans expiration refusée');
verify(slmms_manual_license_validate(false, 10, 2, array(), 1, 365) === 'owner',
    'compte client inconnu refusé');

$key = '29800d44-8b54-4ee8-ae39-4db9945f2385';
$id = slmms_manual_license_insert(5, 10, 2, array(20,21), 3, 365, 99, $key);
verify($id === 1 && count($wpdb->licenses) === 1 &&
    $wpdb->licenses[1]['major_version'] === 2 &&
    $wpdb->licenses[1]['modules_json'] === '[20,21]' &&
    $wpdb->licenses[1]['seats'] === 3, 'création manuelle et droits cohérents');
verify(preg_match('/^MS-[A-F0-9]{32}$/D', $wpdb->licenses[1]['license_key']) === 1,
    'clé de licence aléatoire au format sûr');
verify(count($GLOBALS['audit_events']) === 1 &&
    $GLOBALS['audit_events'][0]['event'] === 'license_manual_created',
    'émission journalisée');
verify(slmms_manual_license_insert(5, 10, 2, array(20,21), 3, 365, 99, $key) === 0 &&
    count($wpdb->licenses) === 1, 'double soumission bloquée');
verify(slmms_manual_license_insert(5, 10, 2, array(), 1, 365, 10,
    'aaa1c844-8b54-4ee8-ae39-4db9945f2385') === 0, 'compte non admin refusé');
echo "Gestion des créations administrateur : contrôles réussis.\n";
