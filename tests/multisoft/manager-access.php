<?php
// Pure PHP smoke tests of portfolio delegation, tenancy and seat quotas.
define('ABSPATH', __DIR__ . '/');
function absint($v) { return abs((int)$v); }
function user_can($id, $cap) { return (int)$id === 99 && $cap === 'manage_options'; }
function current_user_can($cap) { return false; }
function wp_unslash($v) { return $v; }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_]/i', '', $v)); }
function sanitize_text_field($v) { return trim((string)$v); }
function sanitize_email($v) { return strtolower(trim((string)$v)); }
function is_email($v) { return strpos($v, '@') !== false; }
function get_user_by($key, $value) {
    $users = array('reader@example.test' => 20, 'editor@example.test' => 30,
        'buyer@example.test' => 10);
    return $key === 'email' && isset($users[$value]) ?
        (object)array('ID' => $users[$value], 'user_email' => $value) : false;
}
function add_action() {}

class FakeDb {
    public $prefix = 'wp_';
    public $managers = array();
    public $licenses = array();
    public $users = array();
    public $audit = array();
    public $insert_id = 0;
    public $last_error = '';
    public $rollbacks = 0;

    public function prepare($sql) {
        $args = func_get_args();
        array_shift($args);
        foreach ($args as $arg) {
            $value = is_int($arg) ? (string)$arg : "'" . addslashes($arg) . "'";
            $sql = preg_replace('/%[ds]/', $value, $sql, 1);
        }
        return $sql;
    }
    public function query($sql) {
        if ($sql === 'ROLLBACK') { ++$this->rollbacks; }
        return 1;
    }
    public function get_row($sql) {
        if (strpos($sql, 'wp_slmms_licenses') !== false) {
            if (!preg_match('/WHERE id = (\\d+)/', $sql, $m)) { return null; }
            $l = isset($this->licenses[(int)$m[1]]) ? $this->licenses[(int)$m[1]] : null;
            if ($l && preg_match('/AND owner_id = (\\d+)/', $sql, $o) &&
                (int)$l->owner_id !== (int)$o[1]) { return null; }
            return $l;
        }
        if (strpos($sql, 'wp_slmms_managers') !== false) {
            foreach ($this->managers as $m) {
                if (preg_match('/WHERE owner_id = (\\d+) AND manager_user_id = (\\d+)/', $sql, $id) &&
                    (int)$m->owner_id === (int)$id[1] &&
                    (int)$m->manager_user_id === (int)$id[2]) { return $m; }
                if (preg_match('/WHERE id = (\\d+) AND owner_id = (\\d+)/', $sql, $id) &&
                    (int)$m->id === (int)$id[1] && (int)$m->owner_id === (int)$id[2]) { return $m; }
            }
        }
        return null;
    }
    public function get_var($sql) {
        if (strpos($sql, 'wp_slmms_managers') !== false &&
            preg_match('/owner_id = (\\d+) AND manager_user_id = (\\d+)/', $sql, $ids)) {
            foreach ($this->managers as $m) {
                if ((int)$m->owner_id === (int)$ids[1] &&
                    (int)$m->manager_user_id === (int)$ids[2] && $m->active) { return $m->role; }
            }
            return '';
        }
        if (strpos($sql, 'COUNT(*)') !== false &&
            preg_match('/license_id = (\\d+) AND active = 1 AND id != (\\d+)/', $sql, $ids)) {
            $count = 0;
            foreach ($this->users as $u) {
                if ((int)$u->license_id === (int)$ids[1] &&
                    (int)$u->id !== (int)$ids[2] && $u->active) { ++$count; }
            }
            return $count;
        }
        return null;
    }
    public function insert($table, $data) {
        $id = ++$this->insert_id;
        $data['id'] = $id;
        $name = substr($table, strlen($this->prefix));
        if ($name === 'slmms_managers') { $this->managers[$id] = (object)$data; }
        elseif ($name === 'slmms_users') { $this->users[$id] = (object)$data; }
        elseif ($name === 'slmms_audit') { $this->audit[$id] = (object)$data; }
        else { return false; }
        return 1;
    }
    public function update($table, $data, $where) {
        $name = substr($table, strlen($this->prefix));
        if ($name !== 'slmms_managers') { return false; }
        $id = (int)$where['id'];
        if (!isset($this->managers[$id]) ||
            (int)$this->managers[$id]->owner_id !== (int)$where['owner_id']) { return 0; }
        foreach ($data as $k => $v) { $this->managers[$id]->$k = $v; }
        return 1;
    }
    public function get_results($sql) { return array(); }
}
$wpdb = new FakeDb();
$wpdb->licenses[1] = (object)array('id' => 1, 'owner_id' => 10,
    'status' => 'active', 'expires_utc' => '2030-01-01 00:00:00', 'seats' => 2);
$wpdb->licenses[2] = (object)array('id' => 2, 'owner_id' => 50,
    'status' => 'active', 'expires_utc' => '2030-01-01 00:00:00', 'seats' => 2);

require_once dirname(__DIR__, 2) . '/includes/multisoft/class-slmms-storage.php';
require_once dirname(__DIR__, 2) . '/includes/multisoft/class-slmms-managers.php';
require_once dirname(__DIR__, 2) . '/includes/multisoft/class-slmms-accounts.php';
function check_access($ok, $description) {
    if (!$ok) { throw new RuntimeException('ÉCHEC ' . $description); }
    echo 'OK ' . $description . PHP_EOL;
}
$license = $wpdb->licenses[1];
check_access(slmms_actor_can($license, 10, 'manage_managers'), 'propriétaire contrôle les délégations');
check_access(slmms_actor_can($license, 99, 'manage_managers'), 'admin peut intervenir');
check_access(!slmms_actor_can($license, 20, 'view'), 'aucun accès non délégué');
check_access(!slmms_license_for_id(2, 20), 'aucune licence d’un tiers avant délégation');

$_POST = array('manager_email' => 'reader@example.test', 'manager_role' => 'viewer');
check_access(slmms_modify_manager($license, 10, false) === 'saved', 'ajout d’un lecteur par propriétaire');
check_access(slmms_actor_can($license, 20, 'view'), 'lecteur peut voir');
check_access(!slmms_actor_can($license, 20, 'manage_users'), 'lecteur ne peut modifier');
check_access(!slmms_actor_can($license, 20, 'manage_managers'), 'lecteur ne peut déléguer');
check_access(slmms_license_for_id(1, 20) !== null, 'consultation déléguée valide');
check_access(!slmms_license_for_id(2, 20), 'pas de fuite interclient');
check_access(slmms_modify_user(1, 0, false, 20) === 'error', 'POST direct du lecteur rejeté');

$_POST = array('manager_email' => 'editor@example.test', 'manager_role' => 'user_manager');
check_access(slmms_modify_manager($license, 20, false) === 'error', 'lecteur ne peut inviter');
check_access(slmms_modify_manager($license, 10, false) === 'saved', 'ajout gestionnaire utilisateur');
check_access(slmms_actor_can($license, 30, 'manage_users'), 'gestionnaire peut attribuer sièges');
check_access(!slmms_actor_can($wpdb->licenses[2], 30, 'view'), 'gestionnaire isolé par client');

$_POST = array('provider' => 'ogipen', 'subject' => 'user-001',
    'display_name' => 'Utilisateur 1', 'active' => 1);
check_access(slmms_modify_user(1, 0, false, 30) === 'error',
    'compte portail sans e-mail : refus de création');
$_POST['email'] = 'one@example.test';
check_access(slmms_modify_user(1, 0, false, 30) === 'saved', 'gestionnaire déclare utilisateur numéro 1');
$_POST['subject'] = 'user-002';
$_POST['email'] = 'two@example.test';
check_access(slmms_modify_user(1, 0, false, 30) === 'saved', 'gestionnaire déclare utilisateur numéro 2');
$_POST['subject'] = 'user-003';
$_POST['email'] = 'three@example.test';
check_access(slmms_modify_user(1, 0, false, 30) === 'error', 'dépassement quota 2/2 refusé');
check_access(count($wpdb->users) === 2, 'le quota réellement enregistré reste 2');

$editor_id = 0;
foreach ($wpdb->managers as $entry) {
    if ((int)$entry->manager_user_id === 30) { $editor_id = $entry->id; }
}
$_POST = array('manager_id' => $editor_id);
check_access(slmms_modify_manager($license, 10, true) === 'revoked', 'révocation du gestionnaire');
check_access(!slmms_actor_can($license, 30, 'view'), 'révocation immédiate');
check_access(slmms_modify_user(1, 0, false, 30) === 'error', 'aucune écriture après révocation');
check_access(count($wpdb->audit) === 5, 'journal d’ajout, bénéficiaires et révocation');
echo "Gestionnaires SLM Multi: tous contrôles réussis.\n";
