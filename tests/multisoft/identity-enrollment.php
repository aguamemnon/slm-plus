<?php
// Isolated integration-style checks: fake WP identity, mailbox and transactional
// SQL. Real WP/WooCommerce acceptance tests are still required before production.
define('ABSPATH', __DIR__ . '/');
define('SLMMS_TESTING', true);
define('MINUTE_IN_SECONDS', 60);

function add_action() {}
function wp_salt() { return 'tests-only-secret-key-do-not-ship'; }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL) !== false; }
function slmms_table($name) { return $name === 'license' ? 'test_license' : 'test_users'; }
function slmms_actor_can($license, $actor, $action) {
    return (int)$license->owner_id === (int)$actor && $action === 'manage_users';
}
function slmms_audit($owner, $actor, $license, $event, $subject) {
    $GLOBALS['events'][] = $event;
    return true;
}
function get_userdata($id) {
    $accounts = array(
        11 => (object)array('ID' => 11, 'user_email' => 'recipient@example.test'),
        12 => (object)array('ID' => 12, 'user_email' => 'wrong@example.test')
    );
    return isset($accounts[$id]) ? $accounts[$id] : false;
}
function wp_mail($to, $subject, $message) {
    if ($to !== 'recipient@example.test') { return false; }
    if (!preg_match('/Code à usage unique \\(20 minutes\\) : ([A-HJ-NP-Z2-9]{10})/',
        $message, $m)) { return false; }
    $GLOBALS['mail_code'] = $m[1];
    return true;
}
function check_it($condition, $description) {
    if (!$condition) { throw new RuntimeException('FAIL: ' . $description); }
    echo 'OK: ' . $description . PHP_EOL;
}

class FakeIdentityDb {
    public $license;
    public $beneficiary;
    public $snapshot;
    public $duplicate_account = false;

    public function __construct() {
        $this->license = (object)array(
            'id' => 25, 'owner_id' => 1, 'software_id' => 5,
            'status' => 'active', 'expires_utc' => gmdate('Y-m-d H:i:s', time() + 86400),
            'seats' => 2
        );
        $this->beneficiary = (object)array(
            'id' => 71, 'license_id' => 25,
            'provider' => 'ogipen', 'subject' => 'recipient',
            'email' => 'recipient@example.test', 'active' => 1,
            'identity_verified' => 0, 'identity_wp_user_id' => 0,
            'verification_code_hash' => '', 'verification_expires_utc' => null,
            'verification_sent_utc' => null, 'verification_attempts' => 0
        );
    }
    public function prepare($query, ...$args) {
        $types = preg_match_all('/%[sd]/', $query, $m);
        if ($types !== count($args)) { throw new RuntimeException('Invalid SQL parameters.'); }
        foreach ($args as $arg) {
            $query = preg_replace('/%[sd]/', is_numeric($arg) ? (string)$arg :
                "'" . str_replace("'", "''", (string)$arg) . "'", $query, 1);
        }
        return $query;
    }
    public function query($query) {
        if ($query === 'START TRANSACTION') {
            $this->snapshot = array(clone $this->license, clone $this->beneficiary);
        } elseif ($query === 'ROLLBACK' && $this->snapshot) {
            list($this->license, $this->beneficiary) = $this->snapshot;
            $this->snapshot = null;
        } elseif ($query === 'COMMIT') { $this->snapshot = null; }
        return 1;
    }
    public function get_row($query) {
        if (strpos($query, 'FROM test_license') !== false &&
            strpos($query, 'id = 25') !== false) { return clone $this->license; }
        if (strpos($query, 'FROM test_users') !== false &&
            strpos($query, 'id = 71') !== false) {
            return strpos($query, 'SELECT license_id') !== false
                ? (object)array('license_id' => 25) : clone $this->beneficiary;
        }
        return null;
    }
    public function get_var($query) {
        if (strpos($query, 'identity_wp_user_id') !== false) {
            return $this->duplicate_account ? 1 : 0;
        }
        if (strpos($query, 'COUNT(*)') !== false) { return 1; }
        throw new RuntimeException('Unknown SQL: ' . $query);
    }
    public function update($table, $data, $where) {
        if ($table !== 'test_users' ||
            (int)$where['id'] !== (int)$this->beneficiary->id) { return false; }
        foreach ($data as $name => $value) { $this->beneficiary->$name = $value; }
        return 1;
    }
}
$GLOBALS['wpdb'] = new FakeIdentityDb();
$GLOBALS['events'] = array();

require_once dirname(__DIR__, 2) . '/includes/multisoft/class-slmms-identities.php';

$code = slmms_identity_code();
check_it(strlen($code) === 10 && preg_match('/^[A-HJ-NP-Z2-9]{10}$/D', $code) === 1,
    'code non prédictible au format strict');
check_it(slmms_identity_digest(71, $code) !== $code &&
    slmms_identity_digest(71, $code) !== slmms_identity_digest(72, $code),
    'hachage de code lié au bénéficiaire');

check_it(slmms_issue_identity_code(25, 71, 1) === 'sent', 'invitation par propriétaire');
$mail_code = $GLOBALS['mail_code'];
check_it($wpdb->beneficiary->verification_code_hash !== $mail_code &&
    $wpdb->beneficiary->verification_attempts === 0,
    'code brut absent du stockage');
check_it(slmms_issue_identity_code(25, 71, 1) === 'error', 'limitation des réémissions');
check_it(slmms_issue_identity_code(25, 71, 99) === 'error',
    'invitation par un autre client refusée');

check_it(slmms_confirm_identity_code(71, 12, $mail_code) === 'error',
    'un autre compte WordPress ne peut réclamer la place');
check_it(slmms_confirm_identity_code(71, 11, 'AAAAAAAAAA') === 'error' &&
    $wpdb->beneficiary->verification_attempts === 1,
    'code incorrect incrémente le compteur');
$wpdb->duplicate_account = true;
check_it(slmms_confirm_identity_code(71, 11, $mail_code) === 'error',
    'un compte WordPress ne peut consommer deux sièges dans la même licence');
$wpdb->duplicate_account = false;
check_it(slmms_confirm_identity_code(71, 11, $mail_code) === 'verified',
    'preuve d’e-mail et compte portail validée');
check_it($wpdb->beneficiary->identity_wp_user_id === 11 &&
    $wpdb->beneficiary->identity_verified === 1 &&
    $wpdb->beneficiary->verification_code_hash === '',
    'liaison WP persistante; OTP révoqué');
check_it(slmms_confirm_identity_code(71, 11, $mail_code) === 'error',
    'code à usage unique non rejouable');
check_it(in_array('identity_invited', $GLOBALS['events'], true) &&
    in_array('identity_verified', $GLOBALS['events'], true),
    'audits d’invitation et de vérification');

$wpdb->beneficiary->identity_verified = 0;
$wpdb->beneficiary->provider = 'sam';
check_it(!slmms_identity_claim_allowed($wpdb->beneficiary, $wpdb->license,
    get_userdata(11), time()), 'SAM local ne peut être vérifié par un code WordPress');
$wpdb->beneficiary->provider = 'ad';
check_it(!slmms_identity_claim_allowed($wpdb->beneficiary, $wpdb->license,
    get_userdata(11), time()), 'AD local ne peut être vérifié par un code WordPress');

echo "Vérifications identités portails terminées.\n";
