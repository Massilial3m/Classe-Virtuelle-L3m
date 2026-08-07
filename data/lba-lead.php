<?php
// ── Réception des leads de la landing page publique (lba-academie.html) ───────
// Public, sans authentification (visiteurs anonymes) — protégé par un
// honeypot simple contre les bots.
// Déploiement : SFTP → /tmp/ → docker cp moodle_app:/var/data/www/public/lba-lead.php
// ─────────────────────────────────────────────────────────────────────────────
require_once(__DIR__ . '/../config.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Méthode non autorisée']);
    exit;
}

// Honeypot anti-spam : champ caché, doit rester vide pour un humain.
$honeypot = optional_param('website', '', PARAM_RAW);
if ($honeypot !== '') {
    echo json_encode(['ok' => true]);
    exit;
}

$firstname = trim(optional_param('firstname', '', PARAM_TEXT));
$lastname  = trim(optional_param('lastname', '', PARAM_TEXT));
$phone     = trim(optional_param('phone', '', PARAM_TEXT));
$email     = trim(optional_param('email', '', PARAM_EMAIL));
$postal    = trim(optional_param('postal_code', '', PARAM_TEXT));
$city      = trim(optional_param('city', '', PARAM_TEXT));
$situation = trim(optional_param('situation', '', PARAM_TEXT));
$source    = trim(optional_param('source', 'lba-academie', PARAM_ALPHANUMEXT));

if ($firstname === '' || $lastname === '' || $phone === '' || $email === '') {
    echo json_encode(['ok' => false, 'msg' => 'Champs obligatoires manquants']);
    exit;
}

global $DB;

try {
    $DB->execute("CREATE TABLE IF NOT EXISTS `{$CFG->prefix}l3m_leads` (
      `id` bigint(10) NOT NULL AUTO_INCREMENT,
      `firstname` varchar(100) NOT NULL DEFAULT '',
      `lastname` varchar(100) NOT NULL DEFAULT '',
      `phone` varchar(30) NOT NULL DEFAULT '',
      `email` varchar(255) NOT NULL DEFAULT '',
      `postal_code` varchar(10) NOT NULL DEFAULT '',
      `city` varchar(100) NOT NULL DEFAULT '',
      `situation` varchar(100) NOT NULL DEFAULT '',
      `source` varchar(50) NOT NULL DEFAULT 'lba-academie',
      `status` varchar(20) NOT NULL DEFAULT 'nouveau',
      `timecreated` bigint(10) NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// Ajoute la colonne `city` aux tables déjà créées avant son introduction.
try {
    $DB->execute("ALTER TABLE `{$CFG->prefix}l3m_leads` ADD COLUMN `city` varchar(100) NOT NULL DEFAULT '' AFTER `postal_code`");
} catch (Exception $e) {}

$rec              = new stdClass();
$rec->firstname   = $firstname;
$rec->lastname    = $lastname;
$rec->phone       = $phone;
$rec->email       = $email;
$rec->postal_code = $postal;
$rec->city        = $city;
$rec->situation   = $situation;
$rec->source      = $source;
$rec->status      = 'nouveau';
$rec->timecreated = time();
$leadid = $DB->insert_record('l3m_leads', $rec);

// Notification email aux mêmes destinataires que admin-lba.php (managers + site admins).
try {
    $recipients     = [];
    $manager_roleid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
    if ($manager_roleid) {
        $ctx_sys  = context_system::instance();
        $managers = $DB->get_records_sql(
            "SELECT DISTINCT ra.userid FROM {role_assignments} ra WHERE ra.roleid = :rid AND ra.contextid = :ctxid",
            ['rid' => $manager_roleid, 'ctxid' => $ctx_sys->id]
        );
        foreach ($managers as $m) { $recipients[(int)$m->userid] = true; }
    }
    foreach (get_admins() as $admin) { $recipients[(int)$admin->id] = true; }

    $subject = 'Nouveau lead ' . $source . ' : ' . $firstname . ' ' . $lastname;
    $body    = "Nouveau contact depuis la landing page ($source) :\n\n"
        . "Nom : $firstname $lastname\n"
        . "Téléphone : $phone\n"
        . "E-mail : $email\n"
        . ($postal !== '' ? "Code postal : $postal\n" : '')
        . ($city !== '' ? "Ville : $city\n" : '')
        . ($situation !== '' ? "Situation : $situation\n" : '');

    $noreply = core_user::get_noreply_user();
    foreach (array_keys($recipients) as $rid) {
        $touser = $DB->get_record('user', ['id' => $rid]);
        if ($touser) {
            email_to_user($touser, $noreply, $subject, $body);
        }
    }
} catch (Exception $e) {}

echo json_encode(['ok' => true, 'id' => $leadid]);
