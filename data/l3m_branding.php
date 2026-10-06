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
        // Tableaux de bord LBA restent clairs : même logo que partout ailleurs.
        'logo_dark' => '/logo-la-bonne-allure.png',
        'teal'      => '#2E9488', 'teal_d' => '#1C5F58', 'teal_t' => '#E3F2EF',
        'orange'    => '#F2A03D', 'orange_d' => '#9A5E13', 'orange_t' => '#FBEDDA',
        // Surfaces des tableaux de bord (fond de page, cartes, texte) —
        // valeurs actuelles inchangées pour LBA.
        'bg' => '#F7F6F1', 'card' => '#FFFFFF', 'ink' => '#24333C',
        'muted' => '#68767E', 'line' => '#E4E1D6',
        // 'saran' si un jour LBA a aussi 2 accents distincts ; ici teal reste
        // l'accent principal, pas besoin de bouton-override.
        'button_accent' => 'teal',
    ];
    $saran = [
        'name'      => 'Saran Conduite',
        'name_html' => 'Saran <em>Conduite</em>',
        'logo'      => '/logo-saran-conduite.png',
        // Variante texte clair (icône orange inchangée) pour les tableaux de
        // bord, dont le fond est sombre — le logo normal (texte gris foncé)
        // n'est lisible que sur fond clair (landing page, carte de connexion).
        'logo_dark' => '/logo-saran-conduite-dark.png',
        'teal'      => '#313339', 'teal_d' => '#26282D', 'teal_t' => '#46484E',
        'orange'    => '#E8632A', 'orange_d' => '#C25320', 'orange_t' => '#E8632A1A',
        // Palette bleu-gris foncé + orange (une seule teinte d'accent),
        // reprise telle quelle de la maquette fournie.
        'bg' => '#2B2D33', 'card' => '#35373D', 'ink' => '#F3F4F1',
        'muted' => '#A6A6A2', 'line' => '#46484E',
        // Les boutons/actions doivent utiliser l'orange (pas le noir "teal").
        'button_accent' => 'orange',
    ];
    $brands = ['lba' => $lba, 'saran' => $saran];
    $brand  = $brands[$client] ?? $lba;

    // Anti-cache : ajoute un ?v=<mtime du fichier> sur le logo, pour que
    // toute mise à jour de l'image soit vue immédiatement par les
    // navigateurs qui l'ont déjà en cache, sans dépendre d'un rechargement
    // forcé côté utilisateur.
    global $CFG;
    foreach (['logo', 'logo_dark'] as $logo_key) {
        if (empty($brand[$logo_key])) continue;
        $logo_path = ($CFG->dirroot ?? '') . $brand[$logo_key];
        $mtime = @filemtime($logo_path);
        if ($mtime) {
            $brand[$logo_key] .= '?v=' . $mtime;
        }
    }

    return $brand;
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

// Salle Glowbl generale deja utilisee partout ailleurs sur la plateforme
// (Agenda, rappels de classe virtuelle...) — voir $glowbl_url dans
// espace-eleve.php/espace-formateur.php. Centralise ici pour que
// sequences-config.php puisse proposer le meme lien en un clic plutot que
// de faire chercher/coller l'URL au Responsable pedagogique.
function l3m_glowbl_url(): string {
    return 'https://l3m.glowbl.com/l3m-ecsr';
}

// Reserve de cmid d'activites LTI "Classe virtuelle Glowbl" existantes sur
// la plateforme (tous cours confondus), dans un ordre stable — chaque
// activite pointe vers une salle Glowbl DISTINCTE (le contexte LTI de
// chaque activite differe), ce qui sert a donner a chaque Sequence
// "connexion automatique" sa propre salle plutot qu'une seule partagee par
// toute la plateforme (question du 05/10/2026, apres qu'un premier essai a
// tout fait pointer vers la meme salle). Jamais expose au Responsable
// pedagogique comme un choix de cours/module — juste une case a cocher
// "Connexion automatique" (voir sequences-config.php) ; l'assignation d'une
// salle de cette reserve est automatique et invisible.
function l3m_glowbl_lti_pool(): array {
    global $DB;
    try {
        $rows = $DB->get_records_sql(
            "SELECT cm.id AS cmid
             FROM {course_modules} cm
             JOIN {modules} m ON m.id = cm.module AND m.name = 'lti'
             WHERE cm.visible = 1
             ORDER BY cm.id ASC"
        );
        return array_values(array_map(fn($r) => (int)$r->cmid, $rows));
    } catch (Exception $e) {
        return [];
    }
}

// Choisit, dans la reserve, un cmid pas deja utilise par une autre Sequence
// (pour que chacune garde sa propre salle) — retombe sur la 1ere salle de la
// reserve si toutes sont deja prises (plus de Sequences "automatique" que
// de salles reellement disponibles : collision inevitable au-dela).
function l3m_glowbl_assign_room(array $already_used_cmids): ?int {
    $pool = l3m_glowbl_lti_pool();
    if (!$pool) return null;
    foreach ($pool as $cmid) {
        if (!in_array($cmid, $already_used_cmids, true)) return $cmid;
    }
    return $pool[0];
}

function l3m_get_branding() {
    // Signal explicite prioritaire : le lien "Se connecter" de chaque landing
    // page pointe directement vers ?client=... (la connexion converge de
    // toute façon sur le même wwwroot Moodle — voir commentaire en tête de
    // fichier — donc le domaine seul ne suffit plus à distinguer l'origine
    // une fois sur la page de connexion).
    $client_param = strtolower($_GET['client'] ?? '');
    if ($client_param === 'lba' || $client_param === 'saran') {
        return l3m_get_brand_by_client($client_param);
    }

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
    $cohort->name              = ($client === 'saran') ? 'Client · Saran Conduite' : 'Client · La Bonne Allure';
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

// ============================================================
// Testeurs bêta — permet de déployer une nouvelle fonctionnalité en
// production sans qu'elle soit visible pour tout le monde : le code d'une
// nouvelle fonctionnalité se protège avec
//   if (l3m_is_beta_tester($USER->id)) { ... nouveau comportement ... }
//   else { ... comportement actuel inchangé ... }
// Liste d'ids stockée en config local_l3madmin (même pattern que
// formateur_meta/module_unlock_overrides), gérée depuis Paramètres dans
// admin-lba.php. Pas de nouvel environnement à maintenir.
// ============================================================
function l3m_get_beta_testers() {
    return json_decode(get_config('local_l3madmin', 'beta_testers') ?: '[]', true) ?: [];
}

function l3m_is_beta_tester($userid) {
    return in_array((int)$userid, l3m_get_beta_testers(), true);
}

// ============================================================
// Responsable pédagogique — meme meta "formateur_meta" (config local_l3madmin,
// cle is_ped_lead) deja utilisee par admin-lba.php (gestion des intervenants)
// et espace-formateur.php (acces/edition du module Pedagogie). Reutilise ici
// pour reserver la creation/edition du referentiel et des sequences (voir
// l3m_referentiel.php, referentiel-editor.php, sequence-editor.php) au
// Responsable pedagogique plutot qu'a tout formateur — depuis le 16/09/2026,
// un formateur "simple" (editingteacher/teacher sans is_ped_lead) garde un
// acces EN LECTURE (Sequences/Programme/Resultats) mais pas d'edition.
function l3m_is_ped_lead($userid): bool {
    $meta = json_decode(get_config('local_l3madmin', 'formateur_meta') ?: '{}', true) ?: [];
    return !empty($meta[(int)$userid]['is_ped_lead']);
}

// ============================================================
// Terminologie "classe" -> "cohorte" — generalisee a tous les comptes
// (validee via testeurs beta, voir historique). Ne change QUE le texte
// affiche (titres, libelles, boutons) ; les noms de classes reellement
// stockes en base restent "Classe A/B/..." (regex d'extraction de lettre
// et affichage dispCohName() inchanges, ils continuent de fonctionner).
function l3m_term_classe($userid = null) {
    return 'Cohorte';
}

function l3m_term_classe_lc($userid = null) {
    return 'cohorte';
}

// ============================================================
// Sequences de positionnement pedagogique (testeurs beta uniquement) — un
// fichier de contenu JSON par module ECSR (sequence_content_mod_<id>.json,
// meme structure fixe partout, seul le texte change — voir sequence-editor.php).
// Un seul module a du contenu reel pour l'instant (id=2), les 10 autres sont
// des squelettes vides a remplir. Repertorie ici pour eviter de dupliquer la
// liste dans sequence-player.php / sequence-editor.php / sequence-select.php.
// ============================================================
function l3m_seq_modules() {
    return [
        2  => "M1 – Cadre juridique et réglementaire de la conduite",
        3  => "M2 – Pédagogie et techniques d'animation",
        4  => "M3 – Conception et préparation des séances de conduite",
        5  => "M4 – Animation de séances de conduite en milieu ouvert",
        6  => "M5 – Évaluation et suivi de la progression des apprenants",
        7  => "Préparation à l'évaluation CCP1 – Simulations d'examen",
        8  => "M1 – Veille réglementaire et professionnelle",
        9  => "M2 – Accueil, conseil et relation client",
        10 => "M3 – Développement commercial de l'école de conduite",
        11 => "M4 – Gestion administrative et financière",
        12 => "Préparation à l'évaluation CCP2 – Simulations d'examen",
    ];
}

// Chemin du fichier de contenu pour un module donne (n'existe pas forcement
// -> l'appelant doit verifier file_exists avant de lire).
function l3m_seq_content_path($moduleid) {
    return __DIR__ . '/sequence_content_mod_' . (int)$moduleid . '.json';
}

// Un module a du "vrai" contenu si son fichier existe et n'est pas marque
// comme squelette vide (voir generation dans generate_multi_seq.php).
function l3m_seq_has_content($moduleid) {
    $path = l3m_seq_content_path($moduleid);
    if (!file_exists($path)) return false;
    $c = json_decode(file_get_contents($path), true);
    return $c && empty($c['_squelette_vide']) && strpos($c['_avertissement'] ?? '', 'SQUELETTE VIDE') === false;
}

function l3m_set_beta_tester($userid, $enabled) {
    $list   = l3m_get_beta_testers();
    $userid = (int)$userid;
    if ($enabled) {
        if (!in_array($userid, $list, true)) $list[] = $userid;
    } else {
        $list = array_values(array_filter($list, fn($x) => $x !== $userid));
    }
    set_config('beta_testers', json_encode($list), 'local_l3madmin');
}
