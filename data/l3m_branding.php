<?php
// ============================================================
// Marque multi-clients — même plateforme partagée (admin-lba.php,
// espace-formateur.php, espace-eleve.php, page de connexion).
//
// Deux façons de résoudre la marque :
// - l3m_get_branding() : par domaine (HTTP_HOST) — utilisé pour les pages
//   anonymes (landing pages, page de connexion), seul signal disponible
//   avant qu'on sache qui se connecte.
// - l3m_get_branding_for_user($userid) : par utilisateur (cohorte client)
//   — utilisé sur les tableaux de bord authentifiés, puisque la connexion
//   se fait toujours sur lba-vc.l3m-holding.fr (seul wwwroot Moodle) : le
//   domaine ne suffit plus à distinguer un élève/formateur Saran d'un LBA.
//
// Pour ajouter un client : dupliquer une entrée dans l3m_get_brand_by_client()
// et l3m_host_client_map(), avec son propre sous-domaine, nom et couleurs.
// Déploiement : SFTP → /tmp/ → docker cp moodle_app:/var/data/www/public/l3m_branding.php
// ============================================================

// Idnumber des 2 cohortes système utilisées uniquement pour taguer le
// client d'appartenance d'un compte — distinctes des cohortes "classe"
// (idnumber = date de rentrée) gérées ailleurs dans admin-lba.php. Toute
// requête SQL qui liste les cohortes "classe" doit explicitement exclure
// ces 2 idnumber pour ne pas les afficher comme de fausses classes.
define('L3M_CLIENT_COHORT_IDNUMBER_LBA', 'l3mclient-lba');
define('L3M_CLIENT_COHORT_IDNUMBER_SARAN', 'l3mclient-saran');

function l3m_get_brand_by_client($client) {
    $lba = [
        'name'      => 'La Bonne Allure',
        'name_html' => 'La Bonne <em>Allure</em>',
        'logo'      => '/logo-la-bonne-allure.png',
        'teal'      => '#2E9488', 'teal_d' => '#1C5F58', 'teal_t' => '#E3F2EF',
        'orange'    => '#F2A03D', 'orange_d' => '#9A5E13', 'orange_t' => '#FBEDDA',
    ];
    $saran = [
        'name'      => 'Saran Conduite',
        'name_html' => 'Saran <em>Conduite</em>',
        'logo'      => '/logo-saran-conduite.png',
        'teal'      => '#1F1F1F', 'teal_d' => '#0D0D0D', 'teal_t' => '#EAEAE9',
        'orange'    => '#E9521A', 'orange_d' => '#C7431A', 'orange_t' => '#FDEAE1',
    ];
    $brands = ['lba' => $lba, 'saran' => $saran];
    return $brands[$client] ?? $lba;
}

function l3m_host_client_map() {
    return [
        'lba-vc.l3m-holding.fr' => 'lba',
        // Ancien domaine gardé en repli au cas où (redirige normalement vers
        // lba-vc au niveau nginx avant même d'atteindre ce code).
        'virtualclassroom-dev.l3m-holding.fr' => 'lba',
        'saran-vc.l3m-holding.fr' => 'saran',
    ];
}

function l3m_get_branding() {
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $map  = l3m_host_client_map();
    return l3m_get_brand_by_client($map[$host] ?? 'lba');
}

// Retourne l'id (le crée si besoin) de la cohorte système représentant le
// client donné ('lba' ou 'saran').
function l3m_get_or_create_client_cohort($client) {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/cohort/lib.php');

    $idnumber = ($client === 'saran') ? L3M_CLIENT_COHORT_IDNUMBER_SARAN : L3M_CLIENT_COHORT_IDNUMBER_LBA;
    $existing = $DB->get_record('cohort', ['idnumber' => $idnumber]);
    if ($existing) {
        return (int)$existing->id;
    }

    $cohort = new stdClass();
    $cohort->name              = ($client === 'saran') ? 'Client — Saran Conduite' : 'Client — La Bonne Allure';
    $cohort->idnumber          = $idnumber;
    $cohort->description       = '';
    $cohort->descriptionformat = FORMAT_HTML;
    $cohort->contextid         = context_system::instance()->id;
    $cohort->component         = '';
    $cohort->visible           = 1;
    return (int)cohort_add_cohort($cohort);
}

// 'saran' si l'utilisateur est membre de la cohorte client Saran, sinon
// 'lba' par défaut (comptes existants non tagués = LBA, client historique).
function l3m_get_user_client($userid) {
    global $DB;
    $row = $DB->get_record_sql(
        "SELECT c.idnumber
           FROM {cohort_members} cm
           JOIN {cohort} c ON c.id = cm.cohortid
          WHERE cm.userid = :uid AND c.idnumber = :idn",
        ['uid' => $userid, 'idn' => L3M_CLIENT_COHORT_IDNUMBER_SARAN]
    );
    return $row ? 'saran' : 'lba';
}

// Rattache l'utilisateur à un seul client à la fois (retire de l'autre
// cohorte client si besoin avant d'ajouter à la nouvelle).
function l3m_set_user_client($userid, $client) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/cohort/lib.php');

    $client    = ($client === 'saran') ? 'saran' : 'lba';
    $target_id = l3m_get_or_create_client_cohort($client);
    $other_id  = l3m_get_or_create_client_cohort($client === 'saran' ? 'lba' : 'saran');

    if ($DB->record_exists('cohort_members', ['cohortid' => $other_id, 'userid' => $userid])) {
        cohort_remove_member($other_id, $userid);
    }
    if (!$DB->record_exists('cohort_members', ['cohortid' => $target_id, 'userid' => $userid])) {
        cohort_add_member($target_id, $userid);
    }
}

function l3m_get_branding_for_user($userid) {
    return l3m_get_brand_by_client(l3m_get_user_client($userid));
}
