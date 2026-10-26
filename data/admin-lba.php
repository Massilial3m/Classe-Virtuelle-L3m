<?php
// ============================================================
// LBA Académie — Interface d'administration
// Auth : require_login + capability viewparticipants (niveau site)
// Déploiement : SFTP → /tmp/ → docker cp moodle_app:/var/data/www/public/admin-lba.php
// ============================================================
require('/var/data/www/config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once(__DIR__ . '/lia_engine.php');
require_once(__DIR__ . '/l3m_branding.php');
require_once(__DIR__ . '/l3m_referentiel.php');

require_login();
$ctx_sys = context_system::instance();

// Accès réservé aux coordinateurs (rôle Moodle "manager" au niveau site) et
// aux administrateurs du site — pas aux formateurs, même si viewparticipants
// leur est accordé pour leurs propres cours.
$manager_roleid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
$is_manager = $manager_roleid && $DB->record_exists('role_assignments', [
    'userid' => $USER->id, 'roleid' => $manager_roleid, 'contextid' => $ctx_sys->id,
]);
if (!is_siteadmin() && !$is_manager) {
    redirect($CFG->wwwroot . '/login/index.php');
}

// ===== Client de l'admin connecté (LBA ou Saran) — isolation des données =====
// Chaque admin (site admin ou manager) ne voit que les élèves/formateurs/
// leads/messages/documents de SON client, d'après la même cohorte de
// rattachement que celle utilisée sur les tableaux de bord élève/formateur.
// Par défaut (compte non tagué) = LBA, ce qui ne change rien au comportement
// actuel puisque toutes les données réelles existantes sont aujourd'hui LBA.
$admin_client       = l3m_get_user_client($USER->id);
$saran_cohort_id    = (int)l3m_get_or_create_client_cohort('saran');
$saran_category_id  = (int)($DB->get_field('course_categories', 'id', ['name' => 'Saran Conduite']) ?: 0);
$brand              = l3m_get_branding_for_user($USER->id);

// Fragment SQL réutilisable : restreint une colonne user id à ceux du même
// client que l'admin connecté (IN/NOT IN la cohorte Saran selon le cas).
function l3m_admin_client_sql($user_col, $admin_client, $saran_cohort_id) {
    $op = ($admin_client === 'saran') ? 'IN' : 'NOT IN';
    return "$user_col $op (SELECT userid FROM {cohort_members} WHERE cohortid = $saran_cohort_id)";
}

// Un lead (l3m_leads) n'a pas de compte utilisateur — son client se déduit
// du champ 'source' rempli par la landing page d'origine (lba-lead.php).
function l3m_lead_client_of($source) {
    return ($source === 'saran-conduite') ? 'saran' : 'lba';
}

// Vérifie qu'un userid ciblé par une action (note, document, message...)
// appartient bien au même client que l'admin connecté, avant lecture/écriture.
function l3m_admin_owns_user($userid, $admin_client) {
    return l3m_get_user_client((int)$userid) === $admin_client;
}

// Duplique la mise en page visuelle (bordure + textes ECSR) d'un template
// mod_customcert existant vers un template cible fraîchement créé (qui n'a
// encore qu'une page vide auto-générée par customcert_add_instance). Les
// éléments texte "coursename"/"studentname"/"date" sont des placeholders
// dynamiques Moodle — aucune donnée propre à un cours n'est copiée.
function l3m_clone_customcert_template($master_templateid, $target_templateid) {
    global $DB;

    $master_pages = $DB->get_records('customcert_pages', ['templateid' => $master_templateid], 'sequence ASC');
    if (!$master_pages) {
        return;
    }

    // Retire la page vide auto-créée pour le nouveau template (et ses
    // éventuels éléments) avant d'y recopier les vraies pages du modèle.
    $target_pages = $DB->get_records('customcert_pages', ['templateid' => $target_templateid]);
    foreach ($target_pages as $tp) {
        $DB->delete_records('customcert_elements', ['pageid' => $tp->id]);
        $DB->delete_records('customcert_pages', ['id' => $tp->id]);
    }

    $now = time();
    foreach ($master_pages as $mp) {
        $newpage               = clone $mp;
        unset($newpage->id);
        $newpage->templateid   = $target_templateid;
        $newpage->timecreated  = $now;
        $newpage->timemodified = $now;
        $newpageid = $DB->insert_record('customcert_pages', $newpage);

        $elements = $DB->get_records('customcert_elements', ['pageid' => $mp->id], 'sequence ASC');
        foreach ($elements as $el) {
            $newel               = clone $el;
            unset($newel->id);
            $newel->pageid       = $newpageid;
            $newel->timecreated  = $now;
            $newel->timemodified = $now;
            $DB->insert_record('customcert_elements', $newel);
        }
    }
}

// Ajoute automatiquement l'activité "Attestation de cours" (mod_customcert)
// sur un nouveau module, avec le même gabarit visuel que les 9 modules
// existants — évite de devoir reconstruire la mise en page à la main à
// chaque création. La restriction d'accès ("achèvement du quiz final +
// note ≥ 60%") n'est PAS reproduite automatiquement : le quiz n'existe pas
// encore à ce stade (le cours vient d'être créé vide), c'est donc à
// l'admin/formateur de l'ajouter une fois le quiz du module construit,
// via "Restreindre l'accès" sur l'activité (comme pour les 9 autres
// modules). Pour la même raison, l'envoi automatique par email est laissé
// DÉSACTIVÉ à la création (voir emailstudents ci-dessous) : la tâche
// planifiée Moodle qui envoie l'attestation par mail se base UNIQUEMENT
// sur cette restriction d'accès pour savoir qui est éligible — sans elle,
// elle enverrait l'attestation à tous les élèves inscrits dès la première
// minute (vécu en conditions réelles sur le module M6 avant sa mise en
// place). L'admin doit donc penser à cocher "Envoyer aux participants"
// dans les réglages de l'attestation EN MÊME TEMPS qu'il configure la
// restriction d'accès.
function l3m_add_default_certificate($course, $master_templateid = 23) {
    global $DB, $CFG;

    require_once($CFG->dirroot . '/course/modlib.php');

    $moduleid = (int)$DB->get_field('modules', 'id', ['name' => 'customcert']);
    if (!$moduleid) {
        return null;
    }
    if (!$DB->record_exists('customcert_templates', ['id' => $master_templateid])) {
        return null; // modèle de référence introuvable, on ne crée rien plutôt que de créer un certificat vide/casse.
    }

    $last_section = (int)$DB->get_field_sql(
        'SELECT MAX(section) FROM {course_sections} WHERE course = ?', [$course->id]
    );

    $mi = new stdClass();
    $mi->modulename         = 'customcert';
    $mi->module             = $moduleid;
    $mi->course             = $course->id;
    $mi->section            = $last_section;
    $mi->visible            = 1;
    $mi->visibleoncoursepage = 1;
    $mi->name               = 'Attestation de cours';
    $mi->intro              = '<p>Votre attestation est générée automatiquement à la fin de cette formation.</p>';
    $mi->introformat        = FORMAT_HTML;
    $mi->requiredtime       = 0;
    $mi->verifyany          = 0;
    $mi->deliveryoption     = 0;
    $mi->emailstudents      = 0; // activé manuellement par l'admin avec la restriction d'accès, voir commentaire ci-dessus
    $mi->emailteachers      = 0;
    $mi->emailothers        = '';
    $mi->language           = '';
    $mi->completion         = 2; // COMPLETION_TRACKING_AUTOMATIC, comme les 9 modules existants.
    $mi->completionview     = 1; // marquée terminée dès consultation (l'achèvement réel reste protégé par la restriction d'accès).
    $mi->groupmode          = 0;
    $mi->groupingid         = 0;

    $created = add_moduleinfo($mi, $course);

    $certid = (int)$DB->get_field('course_modules', 'instance', ['id' => $created->coursemodule]);
    $target_templateid = (int)$DB->get_field('customcert', 'templateid', ['id' => $certid]);
    if ($certid && $target_templateid) {
        l3m_clone_customcert_template($master_templateid, $target_templateid);
    }

    return $created->coursemodule;
}

// Liste des "formations" inscriptibles pour le client de l'admin — utilisée
// par convert_lead_to_student : un lead s'inscrit sur UNE formation entière,
// jamais sur un seul module. Côté LBA, une formation = une catégorie racine
// avec ses sous-catégories (ex. "ECSR..." = CCP1 + CCP2 + examens, ~11 cours
// modules) ; l'inscription porte alors sur tous les cours de l'arborescence.
// Côté Saran, les cours vivent à plat sous la catégorie Saran sans sous-
// structure (3 parcours indépendants) : chaque cours est sa propre formation.
function l3m_get_formations_list($admin_client, $saran_category_id) {
    global $DB;
    $formations = [];
    try {
        $all_cats = $DB->get_records('course_categories', null, 'id ASC', 'id, name, parent');
        $children_of = [];
        foreach ($all_cats as $cat) { $children_of[(int)$cat->parent][] = (int)$cat->id; }
        $collect_courses = function ($catid) use (&$collect_courses, $children_of) {
            global $DB;
            $ids = $DB->get_fieldset_select('course', 'id', 'category = :cid', ['cid' => $catid]);
            foreach (($children_of[$catid] ?? []) as $childid) {
                $ids = array_merge($ids, $collect_courses($childid));
            }
            return $ids;
        };

        if ($admin_client === 'saran') {
            $saran_courses = $DB->get_records('course', ['category' => $saran_category_id], 'fullname ASC', 'id, fullname');
            foreach ($saran_courses as $c) {
                $formations[] = ['id' => 'c' . $c->id, 'label' => $c->fullname, 'course_ids' => [(int)$c->id]];
            }
        } else {
            foreach (($children_of[0] ?? []) as $rootcatid) {
                if ($rootcatid === (int)$saran_category_id) continue;
                $cids = array_values(array_map('intval', $collect_courses($rootcatid)));
                if (!$cids) continue;
                $formations[] = ['id' => 'cat' . $rootcatid, 'label' => $all_cats[$rootcatid]->name, 'course_ids' => $cids];
            }
        }
    } catch (Exception $e) { $formations = []; }
    return $formations;
}

// ===== TABLE l3m_docs (auto-create) =====
try {
    $DB->execute("CREATE TABLE IF NOT EXISTS `{$CFG->prefix}l3m_docs` (
      `id` bigint(10) NOT NULL AUTO_INCREMENT,
      `userid` bigint(10) NOT NULL,
      `title` varchar(255) NOT NULL DEFAULT '',
      `subtitle` varchar(255) NOT NULL DEFAULT '',
      `status_type` varchar(20) NOT NULL DEFAULT 'pending',
      `status_label` varchar(100) NOT NULL DEFAULT '',
      `is_blocking` tinyint(1) NOT NULL DEFAULT 0,
      `blocking_msg` varchar(500) NOT NULL DEFAULT '',
      `action_type` varchar(20) NOT NULL DEFAULT 'none',
      `action_url` varchar(500) NOT NULL DEFAULT '',
      `sortorder` int(10) NOT NULL DEFAULT 0,
      `timecreated` bigint(10) NOT NULL DEFAULT 0,
      `timemodified` bigint(10) NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      KEY `userid` (`userid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}
try {
    $DB->execute("ALTER TABLE `{$CFG->prefix}l3m_docs` ADD COLUMN IF NOT EXISTS `signed_uploaded_at` bigint(10) NOT NULL DEFAULT 0");
} catch (Exception $e) {}

// ===== TABLE l3m_dispos (auto-create) =====
try {
    $DB->execute("CREATE TABLE IF NOT EXISTS `{$CFG->prefix}l3m_dispos` (
      `id` bigint(10) NOT NULL AUTO_INCREMENT,
      `userid` bigint(10) NOT NULL,
      `date_from` varchar(10) NOT NULL DEFAULT '',
      `date_to` varchar(10) NOT NULL DEFAULT '',
      `motif` varchar(20) NOT NULL DEFAULT '',
      `motif_label` varchar(100) NOT NULL DEFAULT '',
      `status` varchar(20) NOT NULL DEFAULT 'pending',
      `admin_note` varchar(500) NOT NULL DEFAULT '',
      `timecreated` bigint(10) NOT NULL DEFAULT 0,
      `timemodified` bigint(10) NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      KEY `userid` (`userid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// ===== TABLE l3m_msg_status (auto-create) — statut "traité" propre à L3M sur les conversations =====
try {
    $DB->execute("CREATE TABLE IF NOT EXISTS `{$CFG->prefix}l3m_msg_status` (
      `id` bigint(10) NOT NULL AUTO_INCREMENT,
      `conversationid` bigint(10) NOT NULL,
      `formateur_id` bigint(10) NOT NULL,
      `traite` tinyint(1) NOT NULL DEFAULT 0,
      `timemodified` bigint(10) NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      UNIQUE KEY `conv_form` (`conversationid`,`formateur_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// ===== AJAX GET : contenu détaillé d'un cours (viewer intégré, onglet Cours) =====
// Même viewer (TOC + iframe) que espace-formateur.php, pour que l'admin
// puisse vérifier le contenu réellement en ligne sans quitter le tableau de
// bord. Volontairement sans le suivi de complétion ni la notification
// "nouveau contenu" de la version formateur : ici l'admin consulte, il ne
// publie pas — pas d'effet de bord sur l3m_seen_modules.
if (isset($_GET['action']) && $_GET['action'] === 'course_detail') {
    header('Content-Type: application/json');
    $cid_cd = (int)($_GET['courseid'] ?? 0);
    if (!$cid_cd) { echo json_encode(['ok' => false, 'msg' => 'id manquant']); exit; }
    try {
        $course_cd = $DB->get_record('course', ['id' => $cid_cd], '*', MUST_EXIST);
        $is_saran_cat_cd = ((int)$course_cd->category === $saran_category_id);
        if (($admin_client === 'saran') !== $is_saran_cat_cd) {
            echo json_encode(['ok' => false, 'msg' => 'Module invalide pour ce client']); exit;
        }
        $minfo_cd = get_fast_modinfo($course_cd);
        $sections_cd = [];
        foreach ($minfo_cd->get_section_info_all() as $snum_cd => $sinfo_cd) {
            if ($snum_cd == 0 || !$sinfo_cd->visible) continue;
            $cmids_cd = $sinfo_cd->sequence ? explode(',', $sinfo_cd->sequence) : [];
            $mods_cd = [];
            foreach ($cmids_cd as $cmid_cd) {
                $cmid_cd = (int)$cmid_cd;
                if (!isset($minfo_cd->cms[$cmid_cd])) continue;
                $cm_cd = $minfo_cd->cms[$cmid_cd];
                if (!$cm_cd->visible) continue;
                $mods_cd[] = ['cmid' => $cmid_cd, 'name' => $cm_cd->name, 'modtype' => $cm_cd->modname, 'done' => false];
            }
            $sections_cd[] = [
                'num' => $snum_cd, 'name' => $sinfo_cd->name ?: ('Section ' . $snum_cd),
                'mods' => $mods_cd, 'total' => count($mods_cd), 'done_n' => 0,
            ];
        }
        // Fil d'ariane (catégorie racine → sous-catégories → cours) — masqué
        // par le CSS qui nettoie l'iframe (on y cache le breadcrumb natif
        // Moodle), donc reconstruit ici pour être affiché dans le panneau de
        // gauche du viewer, à côté de "Contenu du cours".
        $breadcrumb_cd = [];
        $catid_cd = (int)$course_cd->category;
        $safety_cd = 0;
        while ($catid_cd && $safety_cd < 10) {
            $cat_cd = $DB->get_record('course_categories', ['id' => $catid_cd], 'id,name,parent');
            if (!$cat_cd) break;
            array_unshift($breadcrumb_cd, $cat_cd->name);
            $catid_cd = (int)$cat_cd->parent;
            $safety_cd++;
        }
        $breadcrumb_cd[] = $course_cd->fullname;

        echo json_encode(['ok' => true, 'sections' => $sections_cd, 'coursename' => $course_cd->fullname,
            'courseid' => $cid_cd, 'contextid' => context_course::instance($cid_cd)->id, 'breadcrumb' => $breadcrumb_cd]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ===== ACTIONS POST (AJAX) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = optional_param('action', '', PARAM_ALPHANUMEXT);
    header('Content-Type: application/json');

    if ($action === 'header_counts') {
        $unread_notifs = 0;
        try {
            $unread_notifs = (int)\message_popup\api::count_unread_popup_notifications($USER->id);
        } catch (Exception $e) {}
        echo json_encode(['ok' => true, 'unread_notifications' => $unread_notifs], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'create_class') {
        $name      = required_param('name', PARAM_TEXT);
        $startdate = optional_param('startdate', '', PARAM_TEXT);
        $cohort = new stdClass();
        $cohort->name            = trim($name);
        $cohort->idnumber        = $startdate;
        $cohort->description     = '';
        $cohort->descriptionformat = FORMAT_HTML;
        $cohort->contextid       = $ctx_sys->id;
        // Tag le client dès la création : une classe neuve sans élève ne peut pas encore
        // être rattachée à un client par appartenance (voir l3m_admin_client_sql), donc les
        // requêtes de listing acceptent aussi ce tag en plus du critère de membres.
        $cohort->component       = 'l3m_client_' . $admin_client;
        $cohort->visible         = 1;
        $id = cohort_add_cohort($cohort);
        echo json_encode(['ok' => true, 'id' => $id, 'name' => $cohort->name]);
        exit;
    }

    if ($action === 'delete_class') {
        $cohortid = required_param('cohortid', PARAM_INT);
        $target_cohort = $DB->get_record('cohort', ['id' => $cohortid]);
        $reserved = $target_cohort && in_array($target_cohort->idnumber, [L3M_CLIENT_COHORT_IDNUMBER_LBA, L3M_CLIENT_COHORT_IDNUMBER_SARAN], true);
        if (!$target_cohort || $reserved) {
            echo json_encode(['ok' => false, 'msg' => l3m_term_classe() . ' introuvable']); exit;
        }
        // Meme regle d'appartenance que le listing "Classes & eleves" (voir
        // plus bas) : au moins un membre du client de l'admin, ou tag direct
        // du component pour une classe fraichement creee sans eleve encore.
        $owns = $DB->record_exists_sql(
            "SELECT 1 FROM {cohort_members} cm WHERE cm.cohortid = :cid AND " . l3m_admin_client_sql('cm.userid', $admin_client, $saran_cohort_id),
            ['cid' => $cohortid]
        ) || $target_cohort->component === 'l3m_client_' . $admin_client;
        if (!$owns) {
            echo json_encode(['ok' => false, 'msg' => l3m_term_classe() . ' introuvable']); exit;
        }
        // Ne supprime que le regroupement (cohorte) : les comptes eleves et
        // leurs inscriptions aux cours ne sont pas touches, ils sont juste
        // retires de cette classe.
        cohort_delete_cohort($target_cohort);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'save_class_secteur') {
        $cohortid = required_param('cohortid', PARAM_INT);
        $secteur  = optional_param('secteur', '', PARAM_TEXT);
        $target_cohort = $DB->get_record('cohort', ['id' => $cohortid]);
        $reserved = $target_cohort && in_array($target_cohort->idnumber, [L3M_CLIENT_COHORT_IDNUMBER_LBA, L3M_CLIENT_COHORT_IDNUMBER_SARAN], true);
        if (!$target_cohort || $reserved) {
            echo json_encode(['ok' => false, 'msg' => l3m_term_classe() . ' introuvable']); exit;
        }
        $owns = $DB->record_exists_sql(
            "SELECT 1 FROM {cohort_members} cm WHERE cm.cohortid = :cid AND " . l3m_admin_client_sql('cm.userid', $admin_client, $saran_cohort_id),
            ['cid' => $cohortid]
        ) || $target_cohort->component === 'l3m_client_' . $admin_client;
        if (!$owns) {
            echo json_encode(['ok' => false, 'msg' => l3m_term_classe() . ' introuvable']); exit;
        }
        // Champ natif "description" de la cohorte, inutilisé jusqu'ici —
        // réutilisé pour stocker le secteur géographique de la classe (même
        // pattern que "startdate" réutilise déjà idnumber). Lia s'en sert pour
        // prioriser un formateur du même secteur sur les séances véhicule/salle.
        $DB->set_field('cohort', 'description', trim($secteur), ['id' => $cohortid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'add_to_class') {
        $cohortid = required_param('cohortid', PARAM_INT);
        $userid   = required_param('userid', PARAM_INT);
        $target_cohort = $DB->get_record('cohort', ['id' => $cohortid]);
        $reserved = $target_cohort && in_array($target_cohort->idnumber, [L3M_CLIENT_COHORT_IDNUMBER_LBA, L3M_CLIENT_COHORT_IDNUMBER_SARAN], true);
        if (!$target_cohort || $reserved || !l3m_admin_owns_user($userid, $admin_client)) {
            echo json_encode(['ok' => false, 'msg' => l3m_term_classe() . ' ou élève introuvable']);
            exit;
        }
        if (!$DB->record_exists('cohort_members', ['cohortid' => $cohortid, 'userid' => $userid])) {
            cohort_add_member($cohortid, $userid);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'remove_from_class') {
        $cohortid = required_param('cohortid', PARAM_INT);
        $userid   = required_param('userid', PARAM_INT);
        $target_cohort = $DB->get_record('cohort', ['id' => $cohortid]);
        $reserved = $target_cohort && in_array($target_cohort->idnumber, [L3M_CLIENT_COHORT_IDNUMBER_LBA, L3M_CLIENT_COHORT_IDNUMBER_SARAN], true);
        if (!$target_cohort || $reserved || !l3m_admin_owns_user($userid, $admin_client)) {
            echo json_encode(['ok' => false, 'msg' => l3m_term_classe() . ' ou élève introuvable']);
            exit;
        }
        cohort_remove_member($cohortid, $userid);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'seed_test') {
        $student_roleid_seed = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
        $test_users_def = [
            ['Marie',   'Dupont',   'marie.dupont',   'marie.dupont@test.l3m.fr'],
            ['Lucas',   'Martin',   'lucas.martin',   'lucas.martin@test.l3m.fr'],
            ['Emma',    'Bernard',  'emma.bernard',   'emma.bernard@test.l3m.fr'],
            ['Théo',    'Petit',    'theo.petit',     'theo.petit@test.l3m.fr'],
            ['Camille', 'Rousseau', 'camille.rousseau','camille.rousseau@test.l3m.fr'],
            ['Hugo',    'Lefevre',  'hugo.lefevre',   'hugo.lefevre@test.l3m.fr'],
        ];
        $created_ids = [];
        foreach ($test_users_def as $tu) {
            if ($DB->record_exists('user', ['username' => $tu[2]])) {
                $created_ids[] = (int)$DB->get_field('user', 'id', ['username' => $tu[2]]);
                continue;
            }
            $u = new stdClass();
            $u->username    = $tu[2];
            $u->firstname   = $tu[0];
            $u->lastname    = $tu[1];
            $u->email       = $tu[3];
            $u->password    = 'Test@L3m2026!';
            $u->confirmed   = 1;
            $u->auth        = 'manual';
            $u->mnethostid  = $CFG->mnet_localhost_id;
            $u->lang        = 'fr';
            $u->country     = 'FR';
            $u->timezone    = 'Europe/Paris';
            $created_ids[]  = user_create_user($u, true, false);
        }
        // Inscrire dans tous les cours du client de l'admin connecté
        $seed_course_op = ($admin_client === 'saran') ? '=' : '!=';
        $all_courses_seed = $DB->get_records_select(
            'course', "id > 1 AND category $seed_course_op :satcat", ['satcat' => $saran_category_id], 'id ASC', 'id'
        );
        $enrol_plugin = enrol_get_plugin('manual');
        foreach ($all_courses_seed as $course) {
            $instances = $DB->get_records('enrol', ['enrol' => 'manual', 'courseid' => $course->id]);
            $inst = reset($instances);
            if (!$inst) continue;
            foreach ($created_ids as $uid) {
                try { $enrol_plugin->enrol_user($inst, $uid, $student_roleid_seed); } catch (Exception $e) { }
            }
        }
        // Créer 2 cohortes et y ajouter les élèves
        $cohort_defs = [
            ['Classe A', '2026-01', array_slice($created_ids, 0, 3)],
            ['Classe B', '2026-06', array_slice($created_ids, 3, 3)],
        ];
        foreach ($cohort_defs as $cd) {
            $existing = $DB->get_record('cohort', ['name' => $cd[0]]);
            if (!$existing) {
                $coh = new stdClass();
                $coh->name = $cd[0]; $coh->idnumber = $cd[1];
                $coh->description = ''; $coh->descriptionformat = FORMAT_HTML;
                $coh->contextid = $ctx_sys->id; $coh->component = ''; $coh->visible = 1;
                $cid = cohort_add_cohort($coh);
            } else { $cid = $existing->id; }
            foreach ($cd[2] as $uid) {
                if ($uid && !$DB->record_exists('cohort_members', ['cohortid' => $cid, 'userid' => $uid])) {
                    cohort_add_member($cid, $uid);
                }
            }
        }
        foreach ($created_ids as $uid) { l3m_set_user_client($uid, $admin_client); }
        echo json_encode(['ok' => true, 'msg' => count($created_ids) . ' élèves · 2 ' . l3m_term_classe_lc() . 's créées']);
        exit;
    }

    if ($action === 'save_formateur_meta') {
        $uid        = required_param('userid', PARAM_INT);
        if (!l3m_admin_owns_user($uid, $admin_client)) {
            echo json_encode(['ok' => false, 'msg' => 'Intervenant introuvable']); exit;
        }
        $role_label  = optional_param('role_label', '', PARAM_TEXT);
        $type        = optional_param('type', 'salarie', PARAM_ALPHA);
        $client      = optional_param('client', '', PARAM_ALPHA);
        $secteur     = optional_param('secteur', '', PARAM_TEXT);
        $is_ped_lead = (bool)optional_param('is_ped_lead', 0, PARAM_INT);
        // Cohortes suivies par ce formateur — determine qui apparait dans son
        // annuaire de messagerie (espace-formateur.php) ; vide = tous les
        // eleves du client, comportement par defaut (question du 22/10/2026).
        $cohorts = json_decode(optional_param('cohorts', '[]', PARAM_RAW), true);
        $cohorts = is_array($cohorts) ? array_values(array_map('intval', $cohorts)) : [];
        $meta = json_decode(get_config('local_l3madmin', 'formateur_meta') ?: '{}', true) ?: [];
        if (!isset($meta[$uid])) $meta[$uid] = [];
        $meta[$uid]['role_label']  = trim($role_label);
        $meta[$uid]['type']        = $type;
        $meta[$uid]['secteur']     = trim($secteur);
        $meta[$uid]['is_ped_lead'] = $is_ped_lead;
        $meta[$uid]['cohorts']     = $cohorts;
        set_config('formateur_meta', json_encode($meta), 'local_l3madmin');
        // Changer le rattachement client d'un compte existant est une action
        // inter-tenant sensible : réservée aux vrais administrateurs du site,
        // pas aux managers (déjà cantonnés à leur propre client).
        if (is_siteadmin() && ($client === 'lba' || $client === 'saran')) {
            l3m_set_user_client($uid, $client);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // Vérifie qu'une lettre de classe ('coh', ex. 'A') correspond bien à une
    // cohorte du client de l'admin connecté — même dérivation de lettre que
    // lia_build_cohorts(), même filtre client que $classes plus bas dans le
    // fichier (ce bloc POST s'exécute avant que $classes soit construit).
    $l3m_valid_lia_letter = function ($coh) use ($DB, $admin_client, $saran_cohort_id) {
        $cohorts_check = $DB->get_records_sql(
            "SELECT c.id, c.name FROM {cohort} c
             WHERE (c.idnumber IS NULL OR c.idnumber NOT IN (:clba, :csaran))
               AND (EXISTS (
                 SELECT 1 FROM {cohort_members} cm2
                 WHERE cm2.cohortid = c.id AND " . l3m_admin_client_sql('cm2.userid', $admin_client, $saran_cohort_id) . "
               ) OR c.component = :compclient)",
            ['clba' => L3M_CLIENT_COHORT_IDNUMBER_LBA, 'csaran' => L3M_CLIENT_COHORT_IDNUMBER_SARAN, 'compclient' => 'l3m_client_' . $admin_client]
        );
        foreach ($cohorts_check as $c) {
            $letter = lia_class_letter($c->name) ?: mb_substr($c->name, 0, 1);
            if ($letter === $coh) return true;
        }
        return false;
    };

    if ($action === 'save_lia_override') {
        $pid = required_param('pid', PARAM_INT);
        $w   = required_param('w', PARAM_INT);
        $coh = required_param('coh', PARAM_ALPHANUM);
        if (!l3m_admin_owns_user($pid, $admin_client)) {
            echo json_encode(['ok' => false, 'msg' => 'Intervenant introuvable']); exit;
        }
        if (!$l3m_valid_lia_letter($coh)) {
            echo json_encode(['ok' => false, 'msg' => l3m_term_classe() . ' invalide']); exit;
        }
        $type     = optional_param('type', 'visio', PARAM_ALPHA);
        $label    = trim(optional_param('label', 'Affectation manuelle', PARAM_TEXT));
        $h        = (float)optional_param('h', 0, PARAM_FLOAT);
        // "seqid" (id de Sequence, voir l3m_progseq_all()) remplace le 09/10/2026
        // l'ancien "courseid" (cours Moodle) — champ purement informatif,
        // jamais consomme par lia_engine.php, voir le commentaire plus haut.
        $seqid = optional_param('seqid', '', PARAM_ALPHANUMEXT);
        if (!in_array($type, ['visio', 'veh', 'salle', 'stage', 'exam'], true)) {
            echo json_encode(['ok' => false, 'msg' => 'Format invalide']); exit;
        }
        $overrides = json_decode(get_config('local_l3madmin', 'lia_overrides') ?: '[]', true) ?: [];
        $key = $pid . '|' . $w . '|' . $coh;
        $overrides = array_values(array_filter($overrides, fn($o) => ($o['pid'] . '|' . $o['w'] . '|' . $o['coh']) !== $key));
        $overrides[] = ['pid' => $pid, 'w' => $w, 'coh' => $coh, 'type' => $type, 'label' => $label, 'h' => $h, 'seqid' => $seqid];
        set_config('lia_overrides', json_encode($overrides), 'local_l3madmin');
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete_lia_override') {
        $pid = required_param('pid', PARAM_INT);
        $w   = required_param('w', PARAM_INT);
        $coh = required_param('coh', PARAM_ALPHANUM);
        if (!l3m_admin_owns_user($pid, $admin_client)) {
            echo json_encode(['ok' => false, 'msg' => 'Intervenant introuvable']); exit;
        }
        $overrides = json_decode(get_config('local_l3madmin', 'lia_overrides') ?: '[]', true) ?: [];
        $key = $pid . '|' . $w . '|' . $coh;
        $overrides = array_values(array_filter($overrides, fn($o) => ($o['pid'] . '|' . $o['w'] . '|' . $o['coh']) !== $key));
        set_config('lia_overrides', json_encode($overrides), 'local_l3madmin');
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'dismiss_lia_alert') {
        $kind = required_param('kind', PARAM_ALPHA);
        $pid  = optional_param('pid', 0, PARAM_INT);
        $w    = required_param('w', PARAM_INT);
        if ($pid && !l3m_admin_owns_user($pid, $admin_client)) {
            echo json_encode(['ok' => false, 'msg' => 'Intervenant introuvable']); exit;
        }
        $dismissed = json_decode(get_config('local_l3madmin', 'lia_dismissed_alerts') ?: '[]', true) ?: [];
        $key = $kind . '|' . ($pid ?: '') . '|' . $w;
        if (!in_array($key, $dismissed, true)) $dismissed[] = $key;
        set_config('lia_dismissed_alerts', json_encode($dismissed), 'local_l3madmin');
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'set_user_client') {
        if (!is_siteadmin()) {
            echo json_encode(['ok' => false, 'msg' => 'Action réservée aux administrateurs du site']);
            exit;
        }
        $uid    = required_param('userid', PARAM_INT);
        $client = required_param('client', PARAM_ALPHA);
        if ($client !== 'lba' && $client !== 'saran') {
            echo json_encode(['ok' => false, 'msg' => 'Client invalide']);
            exit;
        }
        l3m_set_user_client($uid, $client);
        echo json_encode(['ok' => true, 'client' => $client]);
        exit;
    }

    if ($action === 'add_hab') {
        $uid   = required_param('userid', PARAM_INT);
        $text  = required_param('text', PARAM_TEXT);
        $color = optional_param('color', 'teal', PARAM_ALPHA);
        $meta  = json_decode(get_config('local_l3madmin', 'formateur_meta') ?: '{}', true) ?: [];
        if (!isset($meta[$uid]))        $meta[$uid] = [];
        if (!isset($meta[$uid]['habs'])) $meta[$uid]['habs'] = [];
        $meta[$uid]['habs'][] = ['text' => trim($text), 'color' => $color];
        set_config('formateur_meta', json_encode($meta), 'local_l3madmin');
        echo json_encode(['ok' => true, 'habs' => $meta[$uid]['habs']]);
        exit;
    }

    if ($action === 'remove_hab') {
        $uid  = required_param('userid', PARAM_INT);
        $idx  = required_param('idx', PARAM_INT);
        $meta = json_decode(get_config('local_l3madmin', 'formateur_meta') ?: '{}', true) ?: [];
        if (isset($meta[$uid]['habs'][$idx])) {
            array_splice($meta[$uid]['habs'], $idx, 1);
        }
        set_config('formateur_meta', json_encode($meta), 'local_l3madmin');
        echo json_encode(['ok' => true, 'habs' => array_values($meta[$uid]['habs'] ?? [])]);
        exit;
    }

    if ($action === 'add_intervenant') {
        $firstname  = required_param('firstname', PARAM_TEXT);
        $lastname   = required_param('lastname', PARAM_TEXT);
        $email      = required_param('email', PARAM_EMAIL);
        $role_label  = optional_param('role_label', '', PARAM_TEXT);
        $type        = optional_param('type', 'salarie', PARAM_ALPHA);
        $client      = optional_param('client', 'lba', PARAM_ALPHA);
        $secteur     = optional_param('secteur', '', PARAM_TEXT);
        $is_ped_lead = (bool)optional_param('is_ped_lead', 0, PARAM_INT);
        $cohorts_ai = json_decode(optional_param('cohorts', '[]', PARAM_RAW), true);
        $cohorts_ai = is_array($cohorts_ai) ? array_values(array_map('intval', $cohorts_ai)) : [];
        // Un manager (non site admin) ne peut créer un intervenant que dans
        // son propre client — seul un vrai administrateur du site peut choisir.
        if (!is_siteadmin()) { $client = $admin_client; }
        $teacher_roleid_ai = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $existing = $DB->get_record('user', ['email' => $email, 'deleted' => 0]);
        if ($existing) {
            // Sécurité : ne jamais réutiliser silencieusement le compte d'un
            // élève existant pour en faire aussi un formateur — le rôle
            // formateur contourne les restrictions d'accès (verrouillage de
            // modules, attestations...), donc cumuler les deux rôles sur le
            // même compte casse le parcours de cet élève. On bloque avec un
            // message explicite plutôt que de laisser faire.
            $student_roleid_ai = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
            $existing_is_student = $student_roleid_ai && $DB->record_exists(
                'role_assignments', ['userid' => $existing->id, 'roleid' => $student_roleid_ai]
            );
            if ($existing_is_student) {
                echo json_encode(['ok' => false,
                    'msg' => 'Cette adresse e-mail est déjà associée à un compte élève. Veuillez utiliser une autre adresse e-mail.']);
                exit;
            }
            $uid = (int)$existing->id;
            $username_ai = $existing->username;
            $plainpw_ai  = null; // compte deja existant : mot de passe inchange, rien a afficher.
        } else {
            $base_un = clean_param(strtolower($firstname) . '.' . strtolower($lastname), PARAM_USERNAME);
            $username = $base_un; $counter = 1;
            while ($DB->record_exists('user', ['username' => $username])) { $username = $base_un . $counter++; }
            // Mot de passe unique genere aleatoirement (respecte la politique du
            // site — longueur/majuscule/minuscule/chiffre/caractere special),
            // au lieu d'un mot de passe fixe identique pour tous les
            // intervenants (risque : la fuite d'un seul compte compromettrait
            // tous les autres crees de la meme facon). Affiche une seule fois
            // a l'admin juste apres la creation, jamais stocke en clair ensuite.
            $plainpw_ai = generate_password(12);
            $u = new stdClass();
            $u->username   = $username; $u->firstname = trim($firstname); $u->lastname = trim($lastname);
            $u->email      = $email;   $u->password   = $plainpw_ai;
            $u->confirmed  = 1; $u->auth = 'manual'; $u->mnethostid = $CFG->mnet_localhost_id;
            $u->lang = 'fr'; $u->country = 'FR';
            $uid = user_create_user($u, true, false);
            $username_ai = $username;
        }
        // N'inscrire ce formateur que sur les cours de SON client (jamais sur
        // ceux de l'autre client, même si un site admin l'a créé pour l'autre).
        $ai_course_op = ($client === 'saran') ? '=' : '!=';
        $enrol_ai = enrol_get_plugin('manual');
        foreach ($DB->get_records_select('course', "id > 1 AND category $ai_course_op :satcat", ['satcat' => $saran_category_id], '', 'id') as $course) {
            $inst = $DB->get_record('enrol', ['enrol' => 'manual', 'courseid' => $course->id]);
            if ($inst) try { $enrol_ai->enrol_user($inst, $uid, $teacher_roleid_ai); } catch (Exception $e) {}
        }
        if ($role_label || $type || $secteur || $is_ped_lead || $cohorts_ai) {
            $meta = json_decode(get_config('local_l3madmin', 'formateur_meta') ?: '{}', true) ?: [];
            if (!isset($meta[$uid])) $meta[$uid] = [];
            $meta[$uid]['role_label']  = trim($role_label);
            $meta[$uid]['type']        = $type;
            $meta[$uid]['secteur']     = trim($secteur);
            $meta[$uid]['is_ped_lead'] = $is_ped_lead;
            $meta[$uid]['cohorts']     = $cohorts_ai;
            set_config('formateur_meta', json_encode($meta), 'local_l3madmin');
        }
        l3m_set_user_client($uid, $client === 'saran' ? 'saran' : 'lba');
        echo json_encode(['ok' => true, 'userid' => $uid, 'username' => $username_ai, 'password' => $plainpw_ai]);
        exit;
    }

    if ($action === 'toggle_reglement') {
        $uid = required_param('userid', PARAM_INT);
        if (!l3m_admin_owns_user($uid, $admin_client)) {
            echo json_encode(['ok' => false, 'msg' => 'Élève introuvable']); exit;
        }
        $signed = array_map('intval', json_decode(get_config('local_l3madmin', 'reglement_signed') ?: '[]', true) ?: []);
        $is_signed = in_array($uid, $signed);
        if ($is_signed) {
            $signed = array_values(array_filter($signed, fn($x) => $x !== $uid));
            $is_signed = false;
        } else {
            $signed[] = $uid;
            $is_signed = true;
        }
        set_config('reglement_signed', json_encode(array_values($signed)), 'local_l3madmin');
        echo json_encode(['ok' => true, 'signed' => $is_signed]);
        exit;
    }

    if ($action === 'save_note') {
        $uid  = required_param('userid', PARAM_INT);
        if (!l3m_admin_owns_user($uid, $admin_client)) {
            echo json_encode(['ok' => false, 'msg' => 'Élève introuvable']); exit;
        }
        $note = optional_param('note', '', PARAM_TEXT);
        $notes = json_decode(get_config('local_l3madmin', 'student_notes') ?: '{}', true) ?: [];
        if ($note !== '') { $notes[$uid] = $note; } else { unset($notes[$uid]); }
        set_config('student_notes', json_encode($notes), 'local_l3madmin');
        echo json_encode(['ok' => true]);
        exit;
    }

    // Assignation manuelle du sous-groupe (colonne "SOUS-GROUPE") — remplace
    // le calcul automatique par ordre alphabetique, qui ne reflete pas une
    // vraie repartition (ex. groupes vehicule), question du 22/10/2026.
    if ($action === 'save_subgroup') {
        $uid  = required_param('userid', PARAM_INT);
        if (!l3m_admin_owns_user($uid, $admin_client)) {
            echo json_encode(['ok' => false, 'msg' => 'Élève introuvable']); exit;
        }
        $sg = optional_param('subgroup', 0, PARAM_INT);
        $overrides = json_decode(get_config('local_l3madmin', 'student_subgroup') ?: '{}', true) ?: [];
        if ($sg > 0) { $overrides[$uid] = $sg; } else { unset($overrides[$uid]); }
        set_config('student_subgroup', json_encode($overrides), 'local_l3madmin');
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'reset_student_password') {
        require_once($CFG->dirroot . '/user/lib.php');
        $uid = required_param('userid', PARAM_INT);
        if (!l3m_admin_owns_user($uid, $admin_client)) {
            echo json_encode(['ok' => false, 'msg' => 'Élève introuvable']); exit;
        }
        $user_pw = $DB->get_record('user', ['id' => $uid, 'deleted' => 0], '*', IGNORE_MISSING);
        if (!$user_pw) {
            echo json_encode(['ok' => false, 'msg' => 'Élève introuvable']); exit;
        }
        // Mot de passe temporaire genere a la volee : jamais stocke en clair
        // nulle part (ni en config, ni en base) — on ne fait que le renvoyer
        // une fois dans cette reponse, comme au moment de la creation du
        // compte (convert_lead_to_student).
        $new_password = 'Eleve@' . random_int(1000, 9999) . 'L3m!';
        update_internal_user_password($user_pw, $new_password);
        echo json_encode(['ok' => true, 'username' => $user_pw->username, 'password' => $new_password]);
        exit;
    }

    if ($action === 'create_session') {
        header('Content-Type: application/json');
        try {
            $courseid     = required_param('courseid', PARAM_INT);
            $formateurid  = required_param('formateurid', PARAM_INT);
            $timestart    = required_param('timestart', PARAM_INT);
            $duration_min = optional_param('duration_min', 120, PARAM_INT);
            $event_name   = required_param('event_name', PARAM_TEXT);
            if (!$DB->record_exists('course', ['id' => $courseid])) {
                echo json_encode(['ok' => false, 'msg' => 'Module introuvable']); exit;
            }
            if (!l3m_admin_owns_user($formateurid, $admin_client)) {
                echo json_encode(['ok' => false, 'msg' => 'Formateur introuvable']); exit;
            }
            // Passe par l'API calendrier officielle (calendar_event::create)
            // plutot qu'un insert direct en base : un insert direct ne
            // declenche pas l'evenement Moodle core\event\calendar_event_created,
            // dont depend la notification WhatsApp existante ("Nouvelle
            // session planifiee") — avec un insert direct, aucune
            // notification n'est jamais envoyee.
            require_once($CFG->dirroot . '/calendar/lib.php');
            $ev = new stdClass();
            $ev->name         = clean_param(trim($event_name), PARAM_TEXT);
            $ev->description  = '';
            $ev->format       = FORMAT_HTML;
            $ev->courseid     = $courseid;
            $ev->groupid      = 0;
            // "userid" sert ici a identifier le formateur auquel la seance
            // est destinee (pas l'admin qui l'a creee) : les evenements de
            // type 'course' sont de toute facon visibles par tous les
            // inscrits cote Moodle natif, ce champ n'a donc pas d'usage
            // natif conflictuel a ce niveau — on le reutilise pour que
            // espace-formateur.php puisse filtrer "Mes seances" par
            // formateur precis (utile en cas de delegation Lia entre
            // plusieurs formateurs d'un meme module).
            $ev->userid       = $formateurid;
            $ev->eventtype    = 'course';
            $ev->timestart    = (int)$timestart;
            $ev->timeduration = (int)$duration_min * 60;
            $ev->visible      = 1;
            $created = calendar_event::create($ev, false);
            if (!$created) {
                echo json_encode(['ok' => false, 'msg' => 'Création impossible']); exit;
            }
            echo json_encode(['ok' => true, 'id' => (int)$created->id]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete_session') {
        header('Content-Type: application/json');
        try {
            $ev_id = required_param('event_id', PARAM_INT);
            // Meme raison que create_session : passer par l'API calendrier
            // (pas un delete_records direct) pour que la notification
            // WhatsApp "Session annulée" (core\event\calendar_event_deleted)
            // se declenche correctement.
            require_once($CFG->dirroot . '/calendar/lib.php');
            if (!$DB->record_exists('event', ['id' => $ev_id, 'eventtype' => 'course'])) {
                echo json_encode(['ok' => false, 'msg' => 'Séance introuvable']); exit;
            }
            calendar_event::load($ev_id)->delete();
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'save_doc') {
        $docid    = optional_param('docid', 0, PARAM_INT);
        $userid   = required_param('userid', PARAM_INT);
        if (!l3m_admin_owns_user($userid, $admin_client)) {
            echo json_encode(['ok' => false, 'msg' => 'Élève introuvable']); exit;
        }
        $title    = required_param('title', PARAM_TEXT);
        $subtitle = optional_param('subtitle', '', PARAM_TEXT);
        $stype    = optional_param('status_type', 'pending', PARAM_ALPHA);
        $slabel   = optional_param('status_label', '', PARAM_TEXT);
        $ablocking = optional_param('is_blocking', 0, PARAM_INT);
        $bmsg     = optional_param('blocking_msg', '', PARAM_TEXT);
        $atype    = optional_param('action_type', 'none', PARAM_ALPHA);
        $rec = new stdClass();
        $rec->userid      = $userid;
        $rec->title       = trim($title);
        $rec->subtitle    = trim($subtitle);
        $rec->status_type = $stype;
        $rec->status_label = trim($slabel);
        $rec->is_blocking = (int)$ablocking;
        $rec->blocking_msg = trim($bmsg);
        $rec->action_type  = $atype;
        $rec->timemodified = time();
        if ($docid) {
            $rec->id = $docid;
            $DB->update_record('l3m_docs', $rec);
        } else {
            $max = $DB->get_field_sql('SELECT COALESCE(MAX(sortorder),0) FROM {l3m_docs} WHERE userid=?', [$userid]);
            $rec->sortorder   = (int)$max + 1;
            $rec->timecreated = time();
            $docid = $DB->insert_record('l3m_docs', $rec);
            l3m_send_notification(
                $USER->id, $userid, 'Nouveau document : ' . trim($title),
                'Votre formateur vous a envoyé un document : ' . trim($title),
                $CFG->wwwroot . '/espace-eleve.php#docs', 'Voir mes documents'
            );
        }
        $doc = $DB->get_record('l3m_docs', ['id' => $docid]);
        echo json_encode(['ok' => true, 'doc' => $doc]);
        exit;
    }

    if ($action === 'delete_doc') {
        $docid = required_param('docid', PARAM_INT);
        $doc = $DB->get_record('l3m_docs', ['id' => $docid]);
        if ($doc && !l3m_admin_owns_user($doc->userid, $admin_client)) {
            echo json_encode(['ok' => false, 'msg' => 'Document introuvable']); exit;
        }
        if ($doc) {
            $fs = get_file_storage();
            $fs->delete_area_files(context_user::instance($doc->userid)->id, 'local_l3madmin', 'l3m_doc', $docid);
            $fs->delete_area_files(context_user::instance($doc->userid)->id, 'local_l3madmin', 'l3m_doc_signed', $docid);
        }
        $DB->delete_records('l3m_docs', ['id' => $docid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Marque un document signé reçu comme traité (sort du badge "Documents"
    // en attente) — manquait un bouton direct pour ça : jusqu'ici il fallait
    // ouvrir "Modifier" et changer le statut a la main, question du
    // 09/10/2026 "je vois toujours 1 dans doc". Ne touche qu'au statut, pas
    // au fichier signe lui-meme (toujours consultable via "Document signé
    // reçu"). ──
    if ($action === 'ack_signed_doc') {
        $docid = required_param('docid', PARAM_INT);
        $doc = $DB->get_record('l3m_docs', ['id' => $docid]);
        if (!$doc || !l3m_admin_owns_user($doc->userid, $admin_client)) {
            echo json_encode(['ok' => false, 'msg' => 'Document introuvable']); exit;
        }
        $doc->status_type = 'validated';
        $doc->timemodified = time();
        $DB->update_record('l3m_docs', $doc);
        echo json_encode(['ok' => true, 'doc' => $DB->get_record('l3m_docs', ['id' => $docid])]);
        exit;
    }

    if ($action === 'upload_doc_file') {
        $docid = required_param('docid', PARAM_INT);
        $doc   = $DB->get_record('l3m_docs', ['id' => $docid], '*', MUST_EXIST);
        if (!l3m_admin_owns_user($doc->userid, $admin_client)) {
            echo json_encode(['ok' => false, 'msg' => 'Document introuvable']); exit;
        }
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'msg' => 'Fichier manquant ou invalide']);
            exit;
        }
        $fs        = get_file_storage();
        $contextid = context_user::instance($doc->userid)->id;
        $fs->delete_area_files($contextid, 'local_l3madmin', 'l3m_doc', $docid);
        $filename = clean_param(basename($_FILES['file']['name']), PARAM_FILE);
        if ($filename === '') $filename = 'document';
        $filerecord = [
            'contextid' => $contextid,
            'component' => 'local_l3madmin',
            'filearea'  => 'l3m_doc',
            'itemid'    => $docid,
            'filepath'  => '/',
            'filename'  => $filename,
        ];
        $fs->create_file_from_pathname($filerecord, $_FILES['file']['tmp_name']);

        $doc->action_type   = 'download';
        $doc->action_url    = $CFG->wwwroot . '/l3m_doc_file.php?docid=' . $docid;
        $doc->timemodified  = time();
        $DB->update_record('l3m_docs', $doc);

        echo json_encode(['ok' => true, 'doc' => $DB->get_record('l3m_docs', ['id' => $docid])]);
        exit;
    }

    if ($action === 'get_student_docs') {
        $userid = required_param('userid', PARAM_INT);
        if (!l3m_admin_owns_user($userid, $admin_client)) {
            echo json_encode(['ok' => false, 'msg' => 'Élève introuvable']); exit;
        }
        $docs = array_values((array)$DB->get_records('l3m_docs', ['userid' => $userid], 'sortorder ASC, timecreated ASC'));
        echo json_encode(['ok' => true, 'docs' => $docs]);
        exit;
    }

    if ($action === 'count_unread_messages') {
        $count = (int)\core_message\api::count_unread_conversations($USER);
        echo json_encode(['ok' => true, 'count' => $count]);
        exit;
    }

    if ($action === 'count_pending_signed_docs') {
        $userids = [];
        try {
            // Meme perimetre client que le compteur initial ($pending_signed_docs
            // plus bas) : sans ce filtre, un admin LBA voyait apres coup (au
            // premier rafraichissement AJAX) le nombre tous clients confondus,
            // different du badge affiche au chargement de la page.
            $userids = array_map('intval', array_column(
                $DB->get_records_sql(
                    "SELECT DISTINCT d.userid FROM {l3m_docs} d
                     JOIN {user} u ON u.id = d.userid AND u.deleted = 0
                     WHERE d.signed_uploaded_at > 0 AND d.status_type = :st
                       AND " . l3m_admin_client_sql('d.userid', $admin_client, $saran_cohort_id),
                    ['st' => 'pending']
                ), 'userid'
            ));
        } catch (Exception $e) {}
        echo json_encode(['ok' => true, 'count' => count($userids), 'userids' => $userids]);
        exit;
    }

    if ($action === 'get_leads') {
        $leads = [];
        try {
            $leads = array_values(array_filter(
                (array)$DB->get_records('l3m_leads', null, 'timecreated DESC'),
                fn($l) => l3m_lead_client_of($l->source) === $admin_client
            ));
        } catch (Exception $e) {}
        echo json_encode(['ok' => true, 'leads' => $leads]);
        exit;
    }

    if ($action === 'update_lead_status') {
        $leadid = required_param('leadid', PARAM_INT);
        $status = required_param('status', PARAM_ALPHA);
        $lead = $DB->get_record('l3m_leads', ['id' => $leadid]);
        if (!$lead || l3m_lead_client_of($lead->source) !== $admin_client) {
            echo json_encode(['ok' => false, 'msg' => 'Lead introuvable']);
            exit;
        }
        $DB->update_record('l3m_leads', (object)['id' => $leadid, 'status' => $status]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete_lead') {
        $leadid = required_param('leadid', PARAM_INT);
        $lead = $DB->get_record('l3m_leads', ['id' => $leadid]);
        if (!$lead || l3m_lead_client_of($lead->source) !== $admin_client) {
            echo json_encode(['ok' => false, 'msg' => 'Lead introuvable']);
            exit;
        }
        // Un lead deja inscrit ("Inscrire") a un vrai compte Moodle + des
        // inscriptions aux cours (suspendues tant que l'acces n'est pas
        // active). Ces deux choses sont independantes de la fiche lead
        // elle-meme : la supprimer seule laissait le compte/les inscriptions
        // intacts, d'ou l'incoherence avec "En attente d'activation" qui se
        // base sur les inscriptions reelles, pas sur la table des leads. On
        // supprime donc aussi le compte associe (retrouve par e-mail, comme
        // le fait deja activate_student_access dans l'autre sens).
        $account_deleted = false;
        if (in_array($lead->status, ['inscrit', 'inscrit_attente'], true) && !empty($lead->email)) {
            $linked_user = $DB->get_record('user', ['email' => $lead->email, 'deleted' => 0]);
            if ($linked_user && l3m_admin_owns_user($linked_user->id, $admin_client)) {
                require_once($CFG->dirroot . '/user/lib.php');
                $ues_del = $DB->get_records_sql(
                    "SELECT ue.id, e.id as enrolid
                     FROM {user_enrolments} ue
                     JOIN {enrol} e ON e.id = ue.enrolid AND e.enrol = 'manual'
                     WHERE ue.userid = :uid",
                    ['uid' => $linked_user->id]
                );
                $plugin_del = enrol_get_plugin('manual');
                foreach ($ues_del as $ue_del) {
                    $inst_del = $DB->get_record('enrol', ['id' => $ue_del->enrolid]);
                    if ($inst_del) { try { $plugin_del->unenrol_user($inst_del, $linked_user->id); } catch (Exception $e) {} }
                }
                delete_user($linked_user);
                $account_deleted = true;
            }
        }
        $DB->delete_records('l3m_leads', ['id' => $leadid]);
        echo json_encode(['ok' => true, 'account_deleted' => $account_deleted]);
        exit;
    }

    if ($action === 'count_pending_leads') {
        $count = 0;
        try {
            $count = count(array_filter(
                (array)$DB->get_records('l3m_leads', ['status' => 'nouveau']),
                fn($l) => l3m_lead_client_of($l->source) === $admin_client
            ));
        } catch (Exception $e) {}
        echo json_encode(['ok' => true, 'count' => $count]);
        exit;
    }

    if ($action === 'convert_lead_to_student') {
        require_once($CFG->dirroot . '/user/lib.php');
        $leadid       = required_param('leadid', PARAM_INT);
        $firstname    = trim(required_param('firstname', PARAM_TEXT));
        $lastname     = trim(required_param('lastname', PARAM_TEXT));
        $email        = trim(required_param('email', PARAM_EMAIL));
        $formation_id = required_param('formation', PARAM_ALPHANUMEXT);

        $lead = $DB->get_record('l3m_leads', ['id' => $leadid]);
        if (!$lead || l3m_lead_client_of($lead->source) !== $admin_client) {
            echo json_encode(['ok' => false, 'msg' => 'Lead introuvable']);
            exit;
        }
        if ($firstname === '' || $lastname === '' || $email === '') {
            echo json_encode(['ok' => false, 'msg' => 'Champs manquants']);
            exit;
        }
        // La formation choisie doit appartenir au client de l'admin — on
        // recalcule la liste serveur (jamais confiance aux course_ids envoyés
        // par le client) et on n'accepte qu'un id présent dans cette liste.
        $formation_cv = null;
        foreach (l3m_get_formations_list($admin_client, $saran_category_id) as $f) {
            if ($f['id'] === $formation_id) { $formation_cv = $f; break; }
        }
        if (!$formation_cv) {
            echo json_encode(['ok' => false, 'msg' => 'Formation invalide']);
            exit;
        }
        $formation_course_ids = $formation_cv['course_ids'];

        $student_roleid_cv = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
        $existing_cv = $DB->get_record('user', ['email' => $email, 'deleted' => 0]);
        $temp_password = null;
        $username_cv = null;
        if ($existing_cv) {
            $uid_cv = (int)$existing_cv->id;
            $username_cv = $existing_cv->username;
        } else {
            $base_un = clean_param(strtolower($firstname) . '.' . strtolower($lastname), PARAM_USERNAME);
            $username_cv = $base_un; $counter_cv = 1;
            while ($DB->record_exists('user', ['username' => $username_cv])) { $username_cv = $base_un . $counter_cv++; }
            // Mot de passe unique genere aleatoirement (respecte la politique
            // du site), meme logique que pour les intervenants — au lieu d'un
            // format quasi-fixe ("Eleve@XXXXL3m!", seulement 9000 valeurs
            // possibles pour la partie variable).
            $temp_password = generate_password(12);
            $u_cv = new stdClass();
            $u_cv->username   = $username_cv; $u_cv->firstname = $firstname; $u_cv->lastname = $lastname;
            $u_cv->email      = $email;       $u_cv->password  = $temp_password;
            $u_cv->confirmed  = 1; $u_cv->auth = 'manual'; $u_cv->mnethostid = $CFG->mnet_localhost_id;
            $u_cv->lang = 'fr'; $u_cv->country = 'FR';
            $uid_cv = user_create_user($u_cv, true, false);
        }

        // Inscription "en attente" : le compte et l'inscription existent déjà
        // (utile pour préparer le dossier), mais l'accès aux cours reste
        // bloqué (ENROL_USER_SUSPENDED) jusqu'à ce qu'un admin clique sur
        // "Activer l'accès" — une fois les documents obligatoires reçus par
        // e-mail (hors plateforme, donc pas de validation automatique possible).
        $enrol_cv = enrol_get_plugin('manual');
        foreach ($formation_course_ids as $fcid_cv) {
            $inst_cv = $DB->get_record('enrol', ['enrol' => 'manual', 'courseid' => $fcid_cv]);
            if ($inst_cv) {
                try { $enrol_cv->enrol_user($inst_cv, $uid_cv, $student_roleid_cv, 0, 0, ENROL_USER_SUSPENDED); } catch (Exception $e) {}
            }
        }

        l3m_set_user_client($uid_cv, $admin_client);

        $DB->update_record('l3m_leads', (object)['id' => $leadid, 'status' => 'inscrit_attente']);

        echo json_encode([
            'ok' => true, 'userid' => $uid_cv, 'username' => $username_cv,
            'temp_password' => $temp_password, 'is_new' => !$existing_cv,
        ]);
        exit;
    }

    if ($action === 'get_pending_activations') {
        // Un élève peut avoir plusieurs cours suspendus (tous les modules
        // d'une même formation) — regroupé par élève, pas par cours, sinon
        // la liste afficherait une ligne par module (jusqu'à 11 pour l'ECSR).
        $student_roleid_pa = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
        $rows = $DB->get_records_sql(
            "SELECT u.id as userid, u.firstname, u.lastname, u.email,
               MIN(ue.timecreated) as timecreated, COUNT(DISTINCT c.id) as nb_courses
             FROM {user_enrolments} ue
             JOIN {enrol} e ON e.id = ue.enrolid AND e.enrol = 'manual'
             JOIN {course} c ON c.id = e.courseid
             JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
             JOIN {role_assignments} ra ON ra.userid = u.id
             JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = c.id
             WHERE ue.status = :suspended AND ra.roleid = :rid
               AND " . l3m_admin_client_sql('u.id', $admin_client, $saran_cohort_id) . "
             GROUP BY u.id, u.firstname, u.lastname, u.email
             ORDER BY MIN(ue.timecreated) DESC",
            ['suspended' => ENROL_USER_SUSPENDED, 'rid' => $student_roleid_pa]
        );
        echo json_encode(['ok' => true, 'items' => array_values($rows)]);
        exit;
    }

    if ($action === 'activate_student_access') {
        $uid_aa = required_param('userid', PARAM_INT);
        if (!l3m_admin_owns_user($uid_aa, $admin_client)) {
            echo json_encode(['ok' => false, 'msg' => 'Élève introuvable']); exit;
        }
        // Active tous les cours suspendus de cet élève (tous les modules de
        // sa formation), pas un seul — voir convert_lead_to_student qui
        // enrôle sur la formation entière.
        $suspended_ues = $DB->get_records_sql(
            "SELECT ue.id, e.id as enrolid
             FROM {user_enrolments} ue
             JOIN {enrol} e ON e.id = ue.enrolid AND e.enrol = 'manual'
             WHERE ue.userid = :uid AND ue.status = :suspended",
            ['uid' => $uid_aa, 'suspended' => ENROL_USER_SUSPENDED]
        );
        $plugin_aa = enrol_get_plugin('manual');
        foreach ($suspended_ues as $sue) {
            $inst_aa = $DB->get_record('enrol', ['id' => $sue->enrolid]);
            if ($inst_aa) $plugin_aa->update_user_enrol($inst_aa, $uid_aa, ENROL_USER_ACTIVE);
        }
        $u_aa = $DB->get_record('user', ['id' => $uid_aa]);
        if ($u_aa) {
            $lead_aa = $DB->get_record('l3m_leads', ['email' => $u_aa->email, 'status' => 'inscrit_attente']);
            if ($lead_aa) $DB->update_record('l3m_leads', (object)['id' => $lead_aa->id, 'status' => 'inscrit']);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'save_ecsr_params') {
        $int_fields = [
            'eleves_par_classe', 'decalage_rentrees', 'sous_groupes_nb', 'sous_groupes_taille',
            'duree_totale', 'alternance_cours', 'alternance_stage', 'salarie_h', 'vacataire_h',
            'suivi_hebdo', 'marge_depassement', 'elearning_pct', 'classes_virtuelles_pct',
            'presentiel_pct', 'stage_entreprise_h', 'delai_ccp1_ccp2', 'seuil_alerte_camera',
        ];
        $text_fields = ['regroupements_vehicule', 'regroupements_salle', 'ccp1_semaine', 'session_titre_semaine'];
        $bool_fields = ['autorisation_temporaire', 'relance_auto_documents', 'suivi_camera_global'];
        $params = [];
        foreach ($int_fields as $f)  { $params[$f] = required_param($f, PARAM_INT); }
        foreach ($text_fields as $f) { $params[$f] = trim(required_param($f, PARAM_TEXT)); }
        foreach ($bool_fields as $f) { $params[$f] = required_param($f, PARAM_INT) ? 1 : 0; }
        $params['proposition_classe_d'] = required_param('proposition_classe_d', PARAM_ALPHA);
        set_config('ecsr_params', json_encode($params, JSON_UNESCAPED_UNICODE), 'local_l3madmin');
        echo json_encode(['ok' => true, 'params' => $params]);
        exit;
    }

    if ($action === 'preview_lia_plan') {
        $letter = optional_param('letter', '', PARAM_ALPHA);
        $off    = optional_param('off', 0, PARAM_INT);

        $prev_ecsr = array_merge(
            ['salarie_h' => 28, 'vacataire_h' => 20, 'decalage_rentrees' => 2, 'regroupements_vehicule' => 'S7 · S13 · S19', 'regroupements_salle' => 'S25 · S31'],
            json_decode(get_config('local_l3madmin', 'ecsr_params') ?: '{}', true) ?: []
        );
        $prev_meta = json_decode(get_config('local_l3madmin', 'formateur_meta') ?: '{}', true) ?: [];

        preg_match_all('/\d+/', $prev_ecsr['regroupements_vehicule'], $prev_veh_matches);
        preg_match_all('/\d+/', $prev_ecsr['regroupements_salle'], $prev_salle_matches);
        lia_week_config(
            array_map('intval', $prev_veh_matches[0]) ?: [7, 13, 19],
            array_map('intval', $prev_salle_matches[0]) ?: [25, 31]
        );

        $prev_formateurs_raw = $DB->get_records_sql(
            "SELECT u.id, u.firstname, u.lastname
             FROM {user} u
             JOIN {role_assignments} ra ON ra.userid = u.id
             JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
             WHERE ra.roleid = :rid AND u.deleted = 0
               AND " . l3m_admin_client_sql('u.id', $admin_client, $saran_cohort_id) . "
             GROUP BY u.id, u.firstname, u.lastname",
            ['rid' => (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher'])]
        );
        $prev_formateurs = [];
        foreach ($prev_formateurs_raw as $f) {
            $fmeta = $prev_meta[(int)$f->id] ?? [];
            $ftype = $fmeta['type'] ?? 'salarie';
            $prev_formateurs[] = [
                'id'         => (int)$f->id,
                'nom'        => $f->firstname . ' ' . $f->lastname,
                'role_label' => $fmeta['role_label'] ?? '',
                'type'       => $ftype,
                'habs'       => $fmeta['habs'] ?? [],
                'capacity'   => $ftype === 'vacataire' ? (int)$prev_ecsr['vacataire_h'] : (int)$prev_ecsr['salarie_h'],
            ];
        }

        $prev_cohorts_raw = $DB->get_records_sql(
            "SELECT c.id, c.name, c.idnumber AS startdate, COUNT(DISTINCT cm.userid) AS nb_members
             FROM {cohort} c
             LEFT JOIN {cohort_members} cm ON cm.cohortid = c.id
             WHERE (c.idnumber IS NULL OR c.idnumber NOT IN (:clba, :csaran))
               AND (EXISTS (
                 SELECT 1 FROM {cohort_members} cm2
                 WHERE cm2.cohortid = c.id AND " . l3m_admin_client_sql('cm2.userid', $admin_client, $saran_cohort_id) . "
               ) OR c.component = :compclient)
             GROUP BY c.id, c.name, c.idnumber",
            ['clba' => L3M_CLIENT_COHORT_IDNUMBER_LBA, 'csaran' => L3M_CLIENT_COHORT_IDNUMBER_SARAN, 'compclient' => 'l3m_client_' . $admin_client]
        );
        $prev_classes = [];
        foreach ($prev_cohorts_raw as $coh) {
            $prev_classes[] = ['name' => $coh->name, 'startdate' => $coh->startdate ?? '', 'nb_members' => (int)$coh->nb_members];
        }
        if ($letter && !$off) {
            // Fallback si off non fourni : place la classe simulée à N semaines après la dernière,
            // N étant le paramètre configurable "Décalage entre rentrées".
            $maxOff = 0;
            foreach (lia_build_cohorts($prev_classes) as $c) { if ($c['off'] > $maxOff) $maxOff = $c['off']; }
            $off = $maxOff + (int)$prev_ecsr['decalage_rentrees'];
        }
        if ($letter) {
            $prev_classes[] = ['name' => 'Classe ' . $letter, 'startdate' => '', 'nb_members' => 0];
        }

        $prev_people   = lia_build_people($prev_formateurs);
        $prev_prof2p   = lia_build_prof2p($prev_formateurs);
        $prev_backups  = lia_build_backups($prev_prof2p, $prev_people);
        $prev_cohorts  = lia_build_cohorts($prev_classes);
        // La classe simulée n'a pas de vraie date de rentrée : on force son offset manuellement
        if ($letter) {
            foreach ($prev_cohorts as &$pc) {
                if ($pc['L'] === $letter) { $pc['off'] = $off; }
            }
            unset($pc);
        }
        $prev_plan = ($prev_cohorts && $prev_people) ? lia_compute_plan($prev_cohorts, $prev_people, $prev_prof2p, $prev_backups) : null;

        echo json_encode(['ok' => true, 'cohorts' => $prev_cohorts, 'plan' => $prev_plan], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'validate_dispo') {
        $id       = required_param('id', PARAM_INT);
        $decision = required_param('decision', PARAM_ALPHA);
        $note     = optional_param('note', '', PARAM_TEXT);
        if (!in_array($decision, ['validated', 'rejected'], true)) {
            echo json_encode(['ok' => false, 'msg' => 'Décision invalide']);
            exit;
        }
        $dispo_row = $DB->get_record('l3m_dispos', ['id' => $id]);
        if (!$dispo_row || !l3m_admin_owns_user($dispo_row->userid, $admin_client)) {
            echo json_encode(['ok' => false, 'msg' => 'Déclaration introuvable']);
            exit;
        }
        $rec = new stdClass();
        $rec->id           = $id;
        $rec->status       = $decision;
        $rec->admin_note   = trim($note);
        $rec->timemodified = time();
        $DB->update_record('l3m_dispos', $rec);
        echo json_encode(['ok' => true, 'status' => $decision]);
        exit;
    }

    if ($action === 'create_course') {
        require_once($CFG->dirroot . '/course/lib.php');
        $fullname   = required_param('fullname', PARAM_TEXT);
        $shortname  = required_param('shortname', PARAM_TEXT);
        $categoryid = required_param('categoryid', PARAM_INT);
        $summary    = optional_param('summary', '', PARAM_TEXT);
        $visible    = optional_param('visible', 1, PARAM_INT);

        $fullname  = trim($fullname);
        $shortname = trim($shortname);

        if ($fullname === '' || $shortname === '') {
            echo json_encode(['ok' => false, 'msg' => 'Nom complet et nom court requis']);
            exit;
        }
        if ($DB->record_exists('course', ['shortname' => $shortname])) {
            echo json_encode(['ok' => false, 'msg' => 'Ce nom court existe déjà']);
            exit;
        }
        if (!$DB->record_exists('course_categories', ['id' => $categoryid])) {
            echo json_encode(['ok' => false, 'msg' => 'Catégorie invalide']);
            exit;
        }
        $is_saran_cat = ($categoryid === $saran_category_id);
        if (($admin_client === 'saran') !== $is_saran_cat) {
            echo json_encode(['ok' => false, 'msg' => 'Catégorie invalide']);
            exit;
        }

        $data = new stdClass();
        $data->category                 = $categoryid;
        $data->fullname                 = $fullname;
        $data->shortname                = $shortname;
        $data->idnumber                 = '';
        $data->summary                  = trim($summary);
        $data->summaryformat            = FORMAT_HTML;
        $data->format                   = 'topics';
        $data->numsections              = 5;
        $data->showgrades               = 1;
        $data->newsitems                = 0;
        $data->startdate                = time();
        $data->enddate                  = 0;
        $data->visible                  = (int)$visible;
        $data->groupmode                = 0;
        $data->groupmodeforce           = 0;
        $data->lang                     = 'fr';
        $data->enablecompletion         = 1;
        $data->showactivitydates        = 1;
        $data->showcompletionconditions = 1;

        try {
            $course = create_course($data);
            // Inscrit automatiquement tous les formateurs deja existants du
            // meme client comme enseignant sur ce nouveau module — sinon
            // personne n'y a acces cote "Mes modules" (symetrique de
            // add_intervenant, qui inscrit un formateur neuf sur tous les
            // modules deja existants ; sans ce miroir, un module cree APRES
            // un formateur ne lui serait jamais assigne).
            $teacher_roleid_cc = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
            if ($teacher_roleid_cc) {
                $client_op_cc = $is_saran_cat ? '=' : '!=';
                $formateur_ids = $DB->get_fieldset_sql(
                    "SELECT DISTINCT ra.userid
                     FROM {role_assignments} ra
                     JOIN {context} cx ON cx.id = ra.contextid AND cx.contextlevel = 50
                     JOIN {course} c2 ON c2.id = cx.instanceid AND c2.id > 1 AND c2.category $client_op_cc :satcat2
                     WHERE ra.roleid = :rid",
                    ['satcat2' => $saran_category_id, 'rid' => $teacher_roleid_cc]
                );
                $enrol_cc = enrol_get_plugin('manual');
                $inst_cc  = $DB->get_record('enrol', ['enrol' => 'manual', 'courseid' => $course->id]);
                if ($inst_cc) {
                    foreach ($formateur_ids as $fid) {
                        try { $enrol_cc->enrol_user($inst_cc, (int)$fid, $teacher_roleid_cc); } catch (Exception $e) {}
                    }
                }
            }
            // Inscrit automatiquement tous les eleves deja inscrits dans un
            // AUTRE cours de la MEME formation (arbre de categories, ex.
            // "ECSR" = CCP1+CCP2+Prep) sur ce nouveau module — sans cela un
            // module ajoute apres la conversion d'un lead en eleve ne lui
            // serait jamais accessible (l'inscription faite a la conversion
            // du lead, cf l3m_get_formations_list, est un instantane des
            // cours existants a ce moment-la). Scope volontairement plus
            // etroit que pour les formateurs (toute la formation, pas tout
            // le client) : un meme client peut heberger plusieurs formations
            // distinctes (ex. LBA a aussi "Formation Test MAE").
            $student_roleid_cc = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
            if ($student_roleid_cc) {
                $root_cat_cc = $categoryid;
                while (true) {
                    $parent_cc = (int)$DB->get_field('course_categories', 'parent', ['id' => $root_cat_cc]);
                    if ($parent_cc <= 0) break;
                    $root_cat_cc = $parent_cc;
                }
                $formation_cats_cc = [$root_cat_cc];
                $queue_cc = [$root_cat_cc];
                while ($queue_cc) {
                    $cur_cc = array_shift($queue_cc);
                    $children_cc = $DB->get_fieldset_select(
                        'course_categories', 'id', 'parent = :p', ['p' => $cur_cc]
                    );
                    foreach ($children_cc as $ch) { $formation_cats_cc[] = (int)$ch; $queue_cc[] = (int)$ch; }
                }
                list($catsql_cc, $catparams_cc) = $DB->get_in_or_equal($formation_cats_cc, SQL_PARAMS_NAMED, 'fcat');
                $eleve_ids = $DB->get_fieldset_sql(
                    "SELECT DISTINCT ra.userid
                     FROM {role_assignments} ra
                     JOIN {context} cx ON cx.id = ra.contextid AND cx.contextlevel = 50
                     JOIN {course} c2 ON c2.id = cx.instanceid AND c2.id > 1 AND c2.category $catsql_cc
                     WHERE ra.roleid = :rid",
                    array_merge($catparams_cc, ['rid' => $student_roleid_cc])
                );
                $enrol_st_cc = enrol_get_plugin('manual');
                $inst_st_cc  = $DB->get_record('enrol', ['enrol' => 'manual', 'courseid' => $course->id]);
                if ($inst_st_cc) {
                    foreach ($eleve_ids as $eid) {
                        try { $enrol_st_cc->enrol_user($inst_st_cc, (int)$eid, $student_roleid_cc); } catch (Exception $e) {}
                    }
                }
            }
            // Attestation de cours pré-brandée automatiquement (voir
            // l3m_add_default_certificate) — non bloquant si ça échoue,
            // le module reste utilisable sans attestation.
            try { l3m_add_default_certificate($course); } catch (Exception $e) {}
            echo json_encode(['ok' => true, 'id' => (int)$course->id,
                'fullname' => $course->fullname, 'shortname' => $course->shortname]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete_course') {
        require_once($CFG->dirroot . '/course/lib.php');
        $courseid = required_param('courseid', PARAM_INT);
        $course_dc = $DB->get_record('course', ['id' => $courseid]);
        if (!$course_dc || $courseid <= 1) {
            echo json_encode(['ok' => false, 'msg' => 'Module introuvable']); exit;
        }
        // Meme critere d'appartenance client que le listing "Modules" et
        // create_course (categorie Saran vs tout le reste) — jamais
        // supprimer un module d'un autre client que celui de l'admin connecte.
        $course_is_saran_dc = ((int)$course_dc->category === $saran_category_id);
        if (($admin_client === 'saran') !== $course_is_saran_dc) {
            echo json_encode(['ok' => false, 'msg' => 'Module introuvable']); exit;
        }
        try {
            delete_course($course_dc, false); // false : pas d'affichage de progression (contexte AJAX)
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete_intervenant') {
        require_once($CFG->dirroot . '/user/lib.php');
        $uid_di = required_param('userid', PARAM_INT);
        $user_di = $DB->get_record('user', ['id' => $uid_di, 'deleted' => 0], '*', IGNORE_MISSING);
        // Jamais supprimer un compte hors de son propre client, ni un admin
        // du site (meme critere de proprietaire que le reste de l'admin).
        if (!$user_di || !l3m_admin_owns_user($uid_di, $admin_client) || is_siteadmin($uid_di)) {
            echo json_encode(['ok' => false, 'msg' => 'Intervenant introuvable']); exit;
        }
        try {
            delete_user($user_di); // API Moodle officielle : anonymise + marque deleted=1, gere les inscriptions/roles.
            $meta_di = json_decode(get_config('local_l3madmin', 'formateur_meta') ?: '{}', true) ?: [];
            unset($meta_di[$uid_di]);
            set_config('formateur_meta', json_encode($meta_di), 'local_l3madmin');
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete_student') {
        require_once($CFG->dirroot . '/user/lib.php');
        $uid_ds = required_param('userid', PARAM_INT);
        $user_ds = $DB->get_record('user', ['id' => $uid_ds, 'deleted' => 0], '*', IGNORE_MISSING);
        $student_roleid_ds = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
        $is_student_ds = $student_roleid_ds && $DB->record_exists('role_assignments', ['userid' => $uid_ds, 'roleid' => $student_roleid_ds]);
        // Jamais supprimer un compte hors de son propre client, ni un admin
        // du site, ni un compte qui n'a meme pas le role eleve.
        if (!$user_ds || !$is_student_ds || !l3m_admin_owns_user($uid_ds, $admin_client) || is_siteadmin($uid_ds)) {
            echo json_encode(['ok' => false, 'msg' => 'Élève introuvable']); exit;
        }
        try {
            delete_user($user_ds); // API Moodle officielle : anonymise + marque deleted=1, gere les inscriptions/roles.
            // Nettoie les cles de config ad-hoc propres a cet eleve (memes
            // cles que le reste de admin-lba.php/espace-formateur.php).
            $notes_ds = json_decode(get_config('local_l3madmin', 'student_notes') ?: '{}', true) ?: [];
            unset($notes_ds[$uid_ds]);
            set_config('student_notes', json_encode($notes_ds), 'local_l3madmin');

            $signed_ds = array_map('intval', json_decode(get_config('local_l3madmin', 'reglement_signed') ?: '[]', true) ?: []);
            $signed_ds = array_values(array_filter($signed_ds, fn($x) => $x !== $uid_ds));
            set_config('reglement_signed', json_encode($signed_ds), 'local_l3madmin');

            $geoloc_ds = json_decode(get_config('local_l3madmin', 'student_geoloc') ?: '{}', true) ?: [];
            unset($geoloc_ds[$uid_ds]);
            set_config('student_geoloc', json_encode($geoloc_ds), 'local_l3madmin');

            $unlocks_ds = json_decode(get_config('local_l3madmin', 'module_unlock_overrides') ?: '{}', true) ?: [];
            unset($unlocks_ds[$uid_ds]);
            set_config('module_unlock_overrides', json_encode($unlocks_ds), 'local_l3madmin');

            // Idem pour l'exception de deblocage ponctuel par Sequence (voir
            // l3m_seq_student_unlock_*() dans l3m_referentiel.php) — meme
            // principe, nouvelle cle.
            $sequnlocks_ds = json_decode(get_config('local_l3madmin', 'seq_unlock_overrides') ?: '{}', true) ?: [];
            unset($sequnlocks_ds[$uid_ds]);
            set_config('seq_unlock_overrides', json_encode($sequnlocks_ds), 'local_l3madmin');

            // Documents administratifs (l3m_docs) : sans ce nettoyage, un
            // document encore "en attente" reste orphelin et impossible a
            // retrouver/traiter une fois l'eleve supprime, tout en continuant
            // a compter dans le badge "Documents" — question du 09/10/2026
            // "je vois toujours 1 dans doc".
            $docs_ds = $DB->get_records('l3m_docs', ['userid' => $uid_ds]);
            if ($docs_ds) {
                $fs_ds = get_file_storage();
                $ctx_ds = context_user::instance($uid_ds, IGNORE_MISSING);
                foreach ($docs_ds as $doc_ds) {
                    if ($ctx_ds) {
                        $fs_ds->delete_area_files($ctx_ds->id, 'local_l3madmin', 'l3m_doc', $doc_ds->id);
                        $fs_ds->delete_area_files($ctx_ds->id, 'local_l3madmin', 'l3m_doc_signed', $doc_ds->id);
                    }
                }
                $DB->delete_records('l3m_docs', ['userid' => $uid_ds]);
            }

            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }

    // ── Testeurs bêta (voir l3m_branding.php) ──
    if ($action === 'add_beta_tester') {
        $identifier_bt = trim(required_param('identifier', PARAM_RAW));
        if ($identifier_bt === '') { echo json_encode(['ok' => false, 'msg' => 'Champ requis']); exit; }
        $user_bt = validate_email($identifier_bt)
            ? $DB->get_record('user', ['email' => $identifier_bt, 'deleted' => 0], 'id, firstname, lastname, email', IGNORE_MISSING)
            : $DB->get_record('user', ['username' => $identifier_bt, 'deleted' => 0], 'id, firstname, lastname, email', IGNORE_MISSING);
        if (!$user_bt || !l3m_admin_owns_user((int)$user_bt->id, $admin_client)) {
            echo json_encode(['ok' => false, 'msg' => 'Aucun compte trouvé avec cet identifiant/e-mail']); exit;
        }
        l3m_set_beta_tester((int)$user_bt->id, true);
        echo json_encode(['ok' => true, 'id' => (int)$user_bt->id, 'nom' => $user_bt->firstname . ' ' . $user_bt->lastname, 'email' => $user_bt->email]);
        exit;
    }

    if ($action === 'remove_beta_tester') {
        $uid_bt = required_param('userid', PARAM_INT);
        l3m_set_beta_tester($uid_bt, false);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Messagerie (vraie messagerie Moodle, UI custom) ──
    if ($action === 'msg_list') {
        $convs = \core_message\api::get_conversations(
            $USER->id, 0, 100, \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL
        );
        $status_map = [];
        foreach ($DB->get_records('l3m_msg_status', ['formateur_id' => $USER->id]) as $r) {
            $status_map[$r->conversationid] = $r;
        }

        $out  = [];
        $sids = [];
        foreach ($convs as $c) {
            $other  = $c->members ? reset($c->members) : null;
            $sid    = $other ? (int)$other->id : 0;
            if ($sid) $sids[] = $sid;
            $lastmsg = $c->messages[0] ?? null;
            $st      = $status_map[$c->id] ?? null;
            $out[] = [
                'convid'      => (int)$c->id,
                'userid'      => $sid,
                'name'        => $other ? $other->fullname : $c->name,
                'initials'    => $other ? $other->initials : '??',
                'isonline'    => $other ? (bool)$other->isonline : false,
                'isfavourite' => (bool)$c->isfavourite,
                'unreadcount' => (int)($c->unreadcount ?? 0),
                'lastmsg'     => $lastmsg ? trim(html_to_text($lastmsg->text, 0, false)) : '',
                'lasttime'    => $lastmsg ? (int)$lastmsg->timecreated : 0,
                'traite'      => $st ? (bool)$st->traite : false,
                'cls'         => '',
            ];
        }

        if ($sids) {
            list($in_sql_m, $in_params_m) = $DB->get_in_or_equal(array_unique($sids), SQL_PARAMS_NAMED, 'msgcid');
            $cls_map = [];
            foreach ($DB->get_records_sql(
                "SELECT cm.userid, c.name AS cohname
                 FROM {cohort_members} cm
                 JOIN {cohort} c ON c.id = cm.cohortid
                 WHERE cm.userid $in_sql_m
                   AND (c.idnumber IS NULL OR c.idnumber NOT IN (:clba, :csaran))",
                array_merge($in_params_m, ['clba' => L3M_CLIENT_COHORT_IDNUMBER_LBA, 'csaran' => L3M_CLIENT_COHORT_IDNUMBER_SARAN])
            ) as $row) {
                if (!isset($cls_map[$row->userid])) $cls_map[$row->userid] = lia_class_letter($row->cohname);
            }
            foreach ($out as &$o) { $o['cls'] = $cls_map[$o['userid']] ?? ''; }
            unset($o);
        }

        usort($out, function($a, $b) {
            if ($a['isfavourite'] !== $b['isfavourite']) return $b['isfavourite'] <=> $a['isfavourite'];
            return $b['lasttime'] <=> $a['lasttime'];
        });

        echo json_encode(['ok' => true, 'conversations' => $out], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'msg_thread') {
        $studentid = optional_param('studentid', 0, PARAM_INT);
        $convid    = optional_param('convid', 0, PARAM_INT);
        if (!$convid && $studentid) {
            $convid = (int)(\core_message\api::get_conversation_between_users([$USER->id, $studentid]) ?: 0);
        }
        if (!$convid) {
            echo json_encode(['ok' => true, 'convid' => 0, 'messages' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $thread = \core_message\api::get_conversation_messages($USER->id, $convid, 0, 0, 'timecreated ASC');
        \core_message\api::mark_all_messages_as_read($USER->id, $convid);
        $msgs = [];
        foreach ($thread['messages'] as $m) {
            $msgs[] = [
                'id'          => (int)$m->id,
                'mine'        => ((int)$m->useridfrom === (int)$USER->id),
                'text'        => $m->text,
                'timecreated' => (int)$m->timecreated,
            ];
        }
        echo json_encode(['ok' => true, 'convid' => $convid, 'messages' => $msgs], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'msg_send') {
        $studentid = required_param('studentid', PARAM_INT);
        $text      = trim(required_param('text', PARAM_TEXT));
        if ($text === '') {
            echo json_encode(['ok' => false, 'msg' => 'Message vide']);
            exit;
        }
        // Sans cours partage avec le destinataire (cas frequent pour un manager
        // qui n'est jamais "inscrit" dans un cours comme un formateur/eleve),
        // Moodle refuse l'envoi natif ("User X cannot send a message to
        // conversation Y"), meme si l'annuaire de cette plateforme a deja
        // valide qui peut contacter qui — meme correctif que espace-eleve.php
        // et espace-formateur.php : devenir contacts mutuels (unidirectionnel
        // suffit, is_contact() teste les 2 sens) avant l'envoi.
        if (!\core_message\api::is_contact($USER->id, $studentid)) {
            \core_message\api::add_contact($USER->id, $studentid);
        }
        $convid = (int)(\core_message\api::get_conversation_between_users([$USER->id, $studentid]) ?: 0);
        if (!$convid) {
            $newconv = \core_message\api::create_conversation(
                \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL, [$USER->id, $studentid]
            );
            $convid = (int)$newconv->id;
        }
        $sent = \core_message\api::send_message_to_conversation($USER->id, $convid, $text, FORMAT_MOODLE);
        // Relit le message via l'API de lecture pour obtenir le même texte formaté/filtré
        // (message_format_message_text) que get_conversation_messages, afin que le JS puisse
        // traiter systématiquement "text" comme du HTML de confiance, sans jamais échapper
        // ni faire confiance au texte brut saisi par l'utilisateur.
        $formatted = \core_message\api::get_most_recent_conversation_message($convid, $USER->id);
        echo json_encode(['ok' => true, 'convid' => $convid, 'message' => [
            'id'          => (int)$sent->id,
            'mine'        => true,
            'text'        => $formatted ? $formatted->text : s($sent->text),
            'timecreated' => (int)$sent->timecreated,
        ]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'msg_toggle_fav') {
        $convid = required_param('convid', PARAM_INT);
        $ctx    = context_user::instance($USER->id);
        $isfav  = $DB->record_exists('favourite', [
            'component' => 'core_message', 'itemtype' => 'message_conversations',
            'itemid' => $convid, 'contextid' => $ctx->id, 'userid' => $USER->id,
        ]);
        if ($isfav) {
            \core_message\api::unset_favourite_conversation($convid, $USER->id);
        } else {
            \core_message\api::set_favourite_conversation($convid, $USER->id);
        }
        echo json_encode(['ok' => true, 'isfavourite' => !$isfav], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Supprime la conversation (cote admin uniquement — mecanisme natif
    // Moodle, l'autre participant garde la sienne jusqu'a ce qu'il la
    // supprime lui-meme a son tour) et retire le contact associe, pour que
    // la ligne disparaisse vraiment de l'inbox plutot que de revenir au
    // prochain message de l'eleve. Question du 09/10/2026, icone de
    // suppression par contact.
    if ($action === 'msg_delete_conversation') {
        $convid_md = required_param('convid', PARAM_INT);
        $studentid_md = required_param('userid', PARAM_INT);
        try {
            if ($convid_md && \core_message\api::can_delete_conversation($USER->id, $convid_md)) {
                \core_message\api::delete_conversation_by_id($USER->id, $convid_md);
            }
            if ($studentid_md && \core_message\api::is_contact($USER->id, $studentid_md)) {
                \core_message\api::remove_contact($USER->id, $studentid_md);
            }
            if ($convid_md) {
                $DB->delete_records('l3m_msg_status', ['conversationid' => $convid_md, 'formateur_id' => $USER->id]);
            }
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'msg_toggle_traite') {
        $convid = required_param('convid', PARAM_INT);
        $row    = $DB->get_record('l3m_msg_status', ['conversationid' => $convid, 'formateur_id' => $USER->id]);
        if ($row) {
            $row->traite       = $row->traite ? 0 : 1;
            $row->timemodified = time();
            $DB->update_record('l3m_msg_status', $row);
            $newval = (bool)$row->traite;
        } else {
            $rec = new stdClass();
            $rec->conversationid = $convid;
            $rec->formateur_id   = $USER->id;
            $rec->traite         = 1;
            $rec->timemodified   = time();
            $DB->insert_record('l3m_msg_status', $rec);
            $newval = true;
        }
        echo json_encode(['ok' => true, 'traite' => $newval], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Action inconnue']);
    exit;
}

// ===== RÔLES =====
$student_roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
$teacher_roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
$sesskey = sesskey();

// ===== CONFIG RÈGLEMENT, NOTES & HABILITATIONS =====
$signed_ids     = array_map('intval', json_decode(get_config('local_l3madmin', 'reglement_signed') ?: '[]', true) ?: []);
$student_notes  = json_decode(get_config('local_l3madmin', 'student_notes') ?: '{}', true) ?: [];
$formateur_meta = json_decode(get_config('local_l3madmin', 'formateur_meta') ?: '{}', true) ?: [];
$lia_overrides  = json_decode(get_config('local_l3madmin', 'lia_overrides') ?: '[]', true) ?: [];
$lia_dismissed  = json_decode(get_config('local_l3madmin', 'lia_dismissed_alerts') ?: '[]', true) ?: [];
$ecsr_params    = array_merge([
    'eleves_par_classe'       => 12,
    'decalage_rentrees'       => 2,
    'sous_groupes_nb'         => 4,
    'sous_groupes_taille'     => 3,
    'duree_totale'            => 35,
    'alternance_cours'        => 3,
    'alternance_stage'        => 1,
    'regroupements_vehicule'  => 'S7 · S13 · S19',
    'regroupements_salle'     => 'S25 · S31',
    'salarie_h'               => 28,
    'vacataire_h'             => 20,
    'suivi_hebdo'             => 2,
    'marge_depassement'       => 10,
    'elearning_pct'           => 42,
    'classes_virtuelles_pct'  => 38,
    'presentiel_pct'          => 19,
    'stage_entreprise_h'      => 280,
    'ccp1_semaine'            => 'S26',
    'session_titre_semaine'   => 'S35',
    'delai_ccp1_ccp2'         => 12,
    'autorisation_temporaire' => 1,
    'relance_auto_documents'  => 1,
    'suivi_camera_global'     => 1,
    'seuil_alerte_camera'     => 70,
    'proposition_classe_d'    => 'manuelle',
], json_decode(get_config('local_l3madmin', 'ecsr_params') ?: '{}', true) ?: []);

// ===== COURS =====
// Les cours Saran vivent dans leur propre catégorie ("Saran Conduite") —
// tout le reste (catégories ECSR historiques) est implicitement LBA. Un
// admin ne doit voir/planifier que les cours de son propre client.
$course_client_op   = ($admin_client === 'saran') ? '=' : '!=';
$all_courses = $DB->get_records_select(
    'course',
    "id > 1 AND category $course_client_op :satcat",
    ['satcat' => $saran_category_id],
    'id ASC', 'id,fullname,shortname,category'
);
$course_ids  = array_keys((array)$all_courses);

// ===== KPI — Élèves actifs =====
$kpi_students = 0;
if ($course_ids) {
    $kpi_students = (int)$DB->count_records_sql(
        "SELECT COUNT(DISTINCT u.id)
         FROM {user} u
         JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = 0
         JOIN {enrol} e ON e.id = ue.enrolid
         JOIN {role_assignments} ra ON ra.userid = u.id
         JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
         WHERE ra.roleid = :rid AND u.deleted = 0 AND u.suspended = 0
           AND " . l3m_admin_client_sql('u.id', $admin_client, $saran_cohort_id),
        ['rid' => $student_roleid]
    );
}

// ===== KPI — Formateurs =====
$kpi_teachers = (int)$DB->count_records_sql(
    "SELECT COUNT(DISTINCT u.id) FROM {user} u
     JOIN {role_assignments} ra ON ra.userid = u.id
     WHERE ra.roleid = :rid AND u.deleted = 0
       AND " . l3m_admin_client_sql('u.id', $admin_client, $saran_cohort_id),
    ['rid' => $teacher_roleid]
);

// ===== COMPLETION PAR ÉLÈVE (batch) =====
// Progression reelle = Programme/Sequences (l3m_progseq_global_pct), pas
// l'ancienne completion de cours Moodle (course_modules_completion), devenue
// aveugle depuis que les eleves ne naviguent plus dans de vrais cours Moodle
// — meme bug deja corrige le 01/10/2026 cote Parcours eleve (espace-eleve.php,
// "c'est toujours 44%"), mais jamais applique ici, question du 22/10/2026 :
// "il a fini la sequance 3 mais sa progression reste a 0".
$student_completion = [];
$avg_completion = 0;
if ($course_ids && $student_roleid) {
    list($in_sql_comp, $in_params_comp) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'compcid');
    $comp_uids = $DB->get_fieldset_sql(
        "SELECT DISTINCT u.id
         FROM {user} u
         JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = 0
         JOIN {enrol} e ON e.id = ue.enrolid
         JOIN {role_assignments} ra ON ra.userid = u.id
         JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
         WHERE ra.roleid = :rid AND u.deleted = 0 AND e.courseid $in_sql_comp
           AND " . l3m_admin_client_sql('u.id', $admin_client, $saran_cohort_id),
        array_merge(['rid' => $student_roleid], $in_params_comp)
    );
    foreach ($comp_uids as $uid) {
        $student_completion[(int)$uid] = l3m_progseq_global_pct((int)$uid);
    }
    if (count($student_completion) > 0) {
        $avg_completion = round(array_sum($student_completion) / count($student_completion));
    }
}

// ===== KPI — Présence LTI (Glowbl) — 7 derniers jours =====
$week_ago = time() - 7 * 86400;
$lti_access_count = (int)$DB->count_records_sql(
    "SELECT COUNT(DISTINCT userid) FROM {logstore_standard_log}
     WHERE component = 'mod_lti' AND timecreated > :since
       AND " . l3m_admin_client_sql('userid', $admin_client, $saran_cohort_id),
    ['since' => $week_ago]
);
$kpi_presence = $kpi_students > 0 ? min(100, round($lti_access_count / $kpi_students * 100)) : 0;

// ===== PRÉSENCE VISIO PAR ÉLÈVE (LTI — 90 derniers jours) =====
$lti_since90 = time() - 90 * 86400;
$lti_per_student = []; // userid => pct (0-100), baseline = 20 sessions en 90j
$lti_rows = $DB->get_records_sql(
    "SELECT userid, COUNT(*) as cnt FROM {logstore_standard_log}
     WHERE component = 'mod_lti' AND timecreated > :since GROUP BY userid",
    ['since' => $lti_since90]
);
foreach ($lti_rows as $row) {
    $lti_per_student[$row->userid] = min(100, (int)round($row->cnt / 20 * 100));
}

// ===== CLASSES — Cohortes Moodle =====
// Batch : appartenance élève → cohorte
$all_coh_rows = $DB->get_records_sql(
    "SELECT cm.userid, c.id as cohortid, c.name as cohname
     FROM {cohort_members} cm
     JOIN {cohort} c ON c.id = cm.cohortid
     WHERE c.idnumber IS NULL OR c.idnumber NOT IN (:clba, :csaran)",
    ['clba' => L3M_CLIENT_COHORT_IDNUMBER_LBA, 'csaran' => L3M_CLIENT_COHORT_IDNUMBER_SARAN]
);
$student_cohort  = []; // userid => cohort_name (premier trouvé)
$cohort_members  = []; // cohortid => [userid, ...]
foreach ($all_coh_rows as $row) {
    if (!isset($student_cohort[$row->userid])) {
        $student_cohort[$row->userid] = $row->cohname;
    }
    $cohort_members[(int)$row->cohortid][] = (int)$row->userid;
}

// Batch : appartenance client (LBA/Saran) — cohortes dédiées, séparées des
// cohortes "classe" ci-dessus. Par défaut LBA pour tout utilisateur non tagué.
$client_by_user = [];
foreach ($DB->get_records_sql(
    "SELECT cm.userid, c.idnumber
       FROM {cohort_members} cm
       JOIN {cohort} c ON c.id = cm.cohortid
      WHERE c.idnumber IN (:clba, :csaran)",
    ['clba' => L3M_CLIENT_COHORT_IDNUMBER_LBA, 'csaran' => L3M_CLIENT_COHORT_IDNUMBER_SARAN]
) as $row) {
    $client_by_user[(int)$row->userid] = ($row->idnumber === L3M_CLIENT_COHORT_IDNUMBER_SARAN) ? 'saran' : 'lba';
}
function l3m_client_of($client_by_user, $userid) {
    return $client_by_user[(int)$userid] ?? 'lba';
}

// Cartes classes avec progression moyenne
$cohorts_raw = $DB->get_records_sql(
    "SELECT c.id, c.name, c.idnumber as startdate, c.description as secteur,
       COUNT(DISTINCT cm.userid) as nb_members
     FROM {cohort} c
     LEFT JOIN {cohort_members} cm ON cm.cohortid = c.id
     WHERE (c.idnumber IS NULL OR c.idnumber NOT IN (:clba, :csaran))
       AND (EXISTS (
         SELECT 1 FROM {cohort_members} cm2
         WHERE cm2.cohortid = c.id AND " . l3m_admin_client_sql('cm2.userid', $admin_client, $saran_cohort_id) . "
       ) OR c.component = :compclient)
     GROUP BY c.id, c.name, c.idnumber, c.description
     ORDER BY c.idnumber ASC, c.name ASC",
    ['clba' => L3M_CLIENT_COHORT_IDNUMBER_LBA, 'csaran' => L3M_CLIENT_COHORT_IDNUMBER_SARAN, 'compclient' => 'l3m_client_' . $admin_client]
);

// Secteur élève déduit automatiquement de sa géolocalisation d'émargement
// (aucune saisie admin) : on garde la ville de son entrée la plus récente,
// débarrassée du code postal ("44000 Nantes" -> "Nantes"), pour pouvoir se
// comparer textuellement au secteur (libre) des formateurs/classes.
$student_secteur_by_uid = [];
foreach (json_decode(get_config('local_l3madmin', 'student_geoloc') ?: '[]', true) ?: [] as $g) {
    $uid = (int)($g['userid'] ?? 0);
    $ts  = (int)($g['timecreated'] ?? 0);
    if (!$uid || empty($g['address'])) continue;
    if (isset($student_secteur_by_uid[$uid]) && $student_secteur_by_uid[$uid]['ts'] >= $ts) continue;
    $ville = trim(preg_replace('/^\d{4,5}\s+/', '', $g['address']));
    if ($ville === '') continue;
    $student_secteur_by_uid[$uid] = ['ts' => $ts, 'secteur' => $ville];
}

$classes = [];
foreach ($cohorts_raw as $coh) {
    $member_uids = $cohort_members[(int)$coh->id] ?? [];
    $progs = [];
    foreach ($member_uids as $uid) {
        if (isset($student_completion[$uid])) $progs[] = $student_completion[$uid];
    }
    $avg_prog = count($progs) > 0 ? round(array_sum($progs) / count($progs)) : 0;
    $unsigned_count = count(array_filter($member_uids, fn($uid) => !in_array($uid, $signed_ids)));

    // Secteur suggéré : ville la plus fréquente parmi les élèves de la
    // classe ayant une géoloc connue — proposé seulement si personne n'a
    // déjà saisi un secteur manuel pour cette classe.
    $secteur_suggested = ''; $secteur_suggested_count = 0; $secteur_known_count = 0;
    $secteur_manual = trim(strip_tags($coh->secteur ?? ''));
    if ($secteur_manual === '') {
        $tally = [];
        foreach ($member_uids as $uid) {
            if (!isset($student_secteur_by_uid[$uid])) continue;
            $secteur_known_count++;
            $v = $student_secteur_by_uid[$uid]['secteur'];
            $tally[$v] = ($tally[$v] ?? 0) + 1;
        }
        if ($tally) {
            arsort($tally);
            $secteur_suggested = array_key_first($tally);
            $secteur_suggested_count = $tally[$secteur_suggested];
        }
    }

    $classes[] = [
        'id'                       => (int)$coh->id,
        'name'                     => $coh->name,
        'startdate'                => $coh->startdate ?? '',
        'secteur'                  => $secteur_manual,
        'secteur_suggested'        => $secteur_suggested,
        'secteur_suggested_count'  => $secteur_suggested_count,
        'secteur_known_count'      => $secteur_known_count,
        'nb_members'               => (int)$coh->nb_members,
        'avg_prog'                 => $avg_prog,
        'member_ids'               => $member_uids,
        'sub_groups'               => max(0, (int)ceil((int)$coh->nb_members / 3)),
        'unsigned_count'           => $unsigned_count,
    ];
}
$kpi_groups = count($classes);

// ===== ALERTES LIA =====
$lia_alerts = [];
$inactive_user_ids = [];
if ($course_ids && $student_roleid) {
    $inactive = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname
         FROM {user} u
         JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = 0
         JOIN {enrol} e ON e.id = ue.enrolid
         JOIN {role_assignments} ra ON ra.userid = u.id
         JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
         WHERE ra.roleid = :rid AND u.deleted = 0
           AND u.id NOT IN (
             SELECT DISTINCT userid FROM {logstore_standard_log}
             WHERE timecreated > :since AND courseid > 0
           )
           AND " . l3m_admin_client_sql('u.id', $admin_client, $saran_cohort_id) . "
         ORDER BY u.lastname, u.firstname",
        ['rid' => $student_roleid, 'since' => $week_ago]
    );
    // Set exact des ids utilisé plus bas pour marquer 'inactive' sur chaque
    // élève de la table — doit rester la MÊME définition que celle utilisée
    // ici pour l'alerte (activité logstore), sinon le nombre annoncé dans
    // l'alerte et la liste obtenue via "Voir les élèves" divergent.
    $inactive_user_ids = array_map(fn($u) => (int)$u->id, (array)$inactive);
    if ($inactive) {
        $noms = implode(', ', array_map(fn($u) => $u->firstname . ' ' . $u->lastname, array_slice((array)$inactive, 0, 3)));
        $lia_alerts[] = [
            'type'  => 'danger',
            'title' => 'Élèves inactifs depuis 7 jours',
            'body'  => count($inactive) . ' élève(s) sans connexion cette semaine : ' . $noms . (count($inactive) > 3 ? '...' : '') . '.',
            'action'=> 'Voir les élèves',
            'target'=> 'coh',
            'filter'=> 'inactive',
        ];
    }
}

// ===== INTERVENANTS =====
$formateurs_raw = $DB->get_records_sql(
    "SELECT u.id, u.firstname, u.lastname, u.email, u.picture,
       COUNT(DISTINCT ctx.instanceid) as nb_courses
     FROM {user} u
     JOIN {role_assignments} ra ON ra.userid = u.id
     JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
     WHERE ra.roleid = :rid AND u.deleted = 0
       AND " . l3m_admin_client_sql('u.id', $admin_client, $saran_cohort_id) . "
     GROUP BY u.id, u.firstname, u.lastname, u.email, u.picture
     ORDER BY u.lastname, u.firstname",
    ['rid' => $teacher_roleid]
);

$gap_secs = 30 * 60;
function l3m_admin_hours($DB, $userid, $since, $gap) {
    $logs = $DB->get_fieldset_sql(
        "SELECT timecreated FROM {logstore_standard_log}
         WHERE userid = :uid AND courseid > 0 AND timecreated > :since
         ORDER BY timecreated ASC",
        ['uid' => $userid, 'since' => $since]
    );
    $secs = 0;
    for ($i = 1; $i < count($logs); $i++) {
        $diff = $logs[$i] - $logs[$i-1];
        if ($diff > 0 && $diff <= $gap) $secs += $diff;
    }
    return round($secs / 3600, 1);
}

// Batch : prochains événements par formateur (3 max chacun)
$teacher_ids_list = array_map(fn($f) => (int)$f->id, (array)$formateurs_raw);
$upcoming_events_map = [];  // teacherid => [{label, date}, ...]
$classes_per_teacher = [];  // teacherid => [cohname, ...]
if ($teacher_ids_list) {
    list($in_sql_ev, $in_params_ev) = $DB->get_in_or_equal($teacher_ids_list, SQL_PARAMS_NAMED, 'evtuid');
    $ev_rows = $DB->get_records_sql(
        "SELECT e.id, e.userid, e.name, e.timestart FROM {event} e
         WHERE e.userid $in_sql_ev AND e.timestart > :now
           AND e.eventtype IN ('user','course','group') AND e.visible = 1
         ORDER BY e.userid ASC, e.timestart ASC",
        array_merge($in_params_ev, ['now' => time()])
    );
    foreach ($ev_rows as $ev) {
        $tid = (int)$ev->userid;
        if (!isset($upcoming_events_map[$tid])) $upcoming_events_map[$tid] = [];
        if (count($upcoming_events_map[$tid]) < 3) {
            $upcoming_events_map[$tid][] = [
                'label' => $ev->name,
                'date'  => userdate($ev->timestart, '%a. %d %b %H:%M')
            ];
        }
    }

    // Batch : classes (cohortes) enseignées par formateur
    list($in_sql_tc, $in_params_tc) = $DB->get_in_or_equal($teacher_ids_list, SQL_PARAMS_NAMED, 'tctuid');
    $tc_rows = $DB->get_records_sql(
        "SELECT DISTINCT ra.userid as teacherid, coh.id as cohortid, coh.name as cohname
         FROM {role_assignments} ra
         JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
         JOIN {enrol} e ON e.courseid = ctx.instanceid
         JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.status = 0
         JOIN {cohort_members} cm ON cm.userid = ue.userid
         JOIN {cohort} coh ON coh.id = cm.cohortid
         WHERE ra.roleid = :tid AND ra.userid $in_sql_tc
           AND (coh.idnumber IS NULL OR coh.idnumber NOT IN (:clba, :csaran))",
        array_merge(['tid' => $teacher_roleid, 'clba' => L3M_CLIENT_COHORT_IDNUMBER_LBA, 'csaran' => L3M_CLIENT_COHORT_IDNUMBER_SARAN], $in_params_tc)
    );
    foreach ($tc_rows as $row) {
        $tid = (int)$row->teacherid;
        if (!isset($classes_per_teacher[$tid])) $classes_per_teacher[$tid] = [];
        $classes_per_teacher[$tid][$row->cohortid] = $row->cohname;
    }

    // Batch : heures par semaine sur 4 semaines (moyenne + pic) — une seule requête
    $teacher_hours_stats = [];
    $four_ago = time() - 28 * 86400;
    list($in_sql_th, $in_params_th) = $DB->get_in_or_equal($teacher_ids_list, SQL_PARAMS_NAMED, 'thuid');
    $t_logs = $DB->get_records_sql(
        "SELECT userid, timecreated FROM {logstore_standard_log}
         WHERE userid $in_sql_th AND courseid > 0 AND timecreated > :since
         ORDER BY userid ASC, timecreated ASC",
        array_merge($in_params_th, ['since' => $four_ago])
    );
    $logs_by_uid = [];
    foreach ($t_logs as $lg) { $logs_by_uid[$lg->userid][] = $lg->timecreated; }
    $now_ts = time();
    foreach ($teacher_ids_list as $uid) {
        $ts = $logs_by_uid[$uid] ?? [];
        $wsecs = [0, 0, 0, 0];
        for ($i = 1; $i < count($ts); $i++) {
            $diff = $ts[$i] - $ts[$i-1];
            if ($diff <= 0 || $diff > $gap_secs) continue;
            $wi = min(3, (int)floor(($now_ts - $ts[$i]) / (7 * 86400)));
            $wsecs[$wi] += $diff;
        }
        $hrs = array_map(fn($s) => round($s / 3600, 1), $wsecs);
        $teacher_hours_stats[$uid] = [
            'week' => $hrs[0],
            'avg'  => round(array_sum($hrs) / 4, 1),
            'peak' => max($hrs),
        ];
    }
}

$formateurs = [];
$colors = ['#2E9488','#7C6FDD','#F2A03D','#8B95A0','#D64545','#3EADA0'];
$ci = 0;
$capacity_salarie   = (int)$ecsr_params['salarie_h'];
$capacity_vacataire = (int)$ecsr_params['vacataire_h'];
foreach ($formateurs_raw as $f) {
    $fmeta      = $formateur_meta[(int)$f->id] ?? [];
    $f_type     = $fmeta['type'] ?? 'salarie';
    $capacity_f = $f_type === 'vacataire' ? $capacity_vacataire : $capacity_salarie;
    $stats      = $teacher_hours_stats[(int)$f->id] ?? ['week' => 0, 'avg' => 0, 'peak' => 0];
    $hours_week = $stats['week'];
    $hours_avg  = $stats['avg'];
    $hours_peak = $stats['peak'];
    $pct_load   = $capacity_f > 0 ? min(100, round($hours_avg / $capacity_f * 100)) : 0;
    $formateurs[] = [
        'id'         => (int)$f->id,
        'nom'        => $f->firstname . ' ' . $f->lastname,
        'email'      => $f->email,
        'nb_courses' => (int)$f->nb_courses,
        'hours_week' => $hours_week,
        'hours_avg'  => $hours_avg,
        'hours_peak' => $hours_peak,
        'capacity'   => $capacity_f,
        'pct_load'   => $pct_load,
        'color'      => $colors[$ci % count($colors)],
        'initials'   => mb_substr($f->firstname, 0, 1) . mb_substr($f->lastname, 0, 1),
        'role_label' => $fmeta['role_label'] ?? '',
        'type'       => $f_type,
        'client'     => l3m_client_of($client_by_user, $f->id),
        'habs'       => $fmeta['habs'] ?? [],
        'secteur'    => $fmeta['secteur'] ?? '',
        'is_ped_lead' => !empty($fmeta['is_ped_lead']),
        'cohorts'    => array_values(array_map('intval', $fmeta['cohorts'] ?? [])),
        'events'     => $upcoming_events_map[(int)$f->id] ?? [],
        'classes'    => array_values($classes_per_teacher[(int)$f->id] ?? []),
    ];
    $ci++;
}

// ===== ÉLÈVES — table complète (zéro SQL dans la boucle) =====
$students = [];
if ($course_ids && $student_roleid) {
    list($in_sql_st, $in_params_st) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'stcid');
    $students_raw = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username, u.lastaccess
         FROM {user} u
         JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = 0
         JOIN {enrol} e ON e.id = ue.enrolid
         JOIN {role_assignments} ra ON ra.userid = u.id
         JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
         WHERE ra.roleid = :rid AND u.deleted = 0 AND e.courseid $in_sql_st
           AND " . l3m_admin_client_sql('u.id', $admin_client, $saran_cohort_id) . "
         ORDER BY u.lastname, u.firstname",
        array_merge(['rid' => $student_roleid], $in_params_st)
    );

    // Sous-groupes assignes manuellement (admin-lba.php, colonne "SOUS-GROUPE")
    // — prioritaires sur le calcul automatique par ordre alphabetique
    // ci-dessous, qui ne reflete pas une vraie repartition (ex. groupes
    // vehicule) tant que personne n'a ajuste manuellement (question du
    // 22/10/2026).
    $subgroup_overrides = json_decode(get_config('local_l3madmin', 'student_subgroup') ?: '{}', true) ?: [];
    $cohort_positions = []; // cohortid => position counter (for sub-group)
    foreach ($students_raw as $s) {
        $pct        = $student_completion[$s->id] ?? 0;
        $is_inactive = in_array((int)$s->id, $inactive_user_ids, true);
        $presence   = $lti_per_student[$s->id] ?? 0;
        $reglement  = in_array((int)$s->id, $signed_ids);

        // Cohort + position → sous-groupe
        $student_cohort_id = null;
        foreach ($cohort_members as $cid => $uids) {
            if (in_array((int)$s->id, $uids)) { $student_cohort_id = $cid; break; }
        }
        $sg_num = null;
        if ($student_cohort_id) {
            if (!isset($cohort_positions[$student_cohort_id])) $cohort_positions[$student_cohort_id] = 0;
            $cohort_positions[$student_cohort_id]++;
            $sg_num = (int)ceil($cohort_positions[$student_cohort_id] / max(1, (int)$ecsr_params['sous_groupes_taille']));
        }
        if (isset($subgroup_overrides[$s->id])) {
            $sg_num = (int)$subgroup_overrides[$s->id];
        }

        $needs_action = !$reglement || $presence < 70 || $is_inactive;

        $students[] = [
            'id'          => (int)$s->id,
            'nom'         => $s->firstname . ' ' . $s->lastname,
            'email'       => $s->email,
            'username'    => $s->username,
            'cohort_id'   => $student_cohort_id,
            'cohort_name' => $student_cohort[$s->id] ?? null,
            'client'      => l3m_client_of($client_by_user, $s->id),
            'sg_num'      => $sg_num,
            'pct'         => $pct,
            'presence'    => $presence,
            'reglement'   => $reglement,
            'note'        => $student_notes[$s->id] ?? '',
            'last_access' => $s->lastaccess > 0 ? userdate($s->lastaccess, '%d/%m') : '—',
            'inactive'    => $is_inactive,
            'low'         => $pct < 20,
            'needs_action'=> $needs_action,
        ];
    }

    $low_list = array_filter($students, fn($s) => $s['low']);
    if ($low_list) {
        $noms = implode(', ', array_map(fn($s) => $s['nom'], array_slice(array_values($low_list), 0, 3)));
        $lia_alerts[] = [
            'type'  => 'warn',
            'title' => 'Progression faible (<20 %)',
            'body'  => count($low_list) . ' élève(s) ont moins de 20 % de modules complétés : ' . $noms . '.',
            'action'=> 'Voir les élèves',
            'target'=> 'coh',
            'filter'=> 'low',
        ];
    }
}

// ===== ALERTE ACTIVATIONS EN ATTENTE =====
// Élèves inscrits (compte + enrolment créés) mais dont l'accès reste
// bloqué (ENROL_USER_SUSPENDED) jusqu'à réception des documents par e-mail
// et activation manuelle — voir action 'activate_student_access'.
$pending_activation_count = (int)$DB->count_records_sql(
    "SELECT COUNT(DISTINCT u.id)
     FROM {user_enrolments} ue
     JOIN {enrol} e ON e.id = ue.enrolid AND e.enrol = 'manual'
     JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
     JOIN {role_assignments} ra ON ra.userid = u.id
     JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
     WHERE ue.status = :suspended AND ra.roleid = :rid
       AND " . l3m_admin_client_sql('u.id', $admin_client, $saran_cohort_id),
    ['suspended' => ENROL_USER_SUSPENDED, 'rid' => $student_roleid]
);
if ($pending_activation_count > 0) {
    $lia_alerts[] = [
        'type'  => 'warn',
        'title' => 'Activations en attente',
        'body'  => $pending_activation_count . ' élève(s) inscrit(s) mais bloqué(s) tant que le dossier documents n\'est pas confirmé reçu.',
        'action'=> 'Voir les leads',
        'target'=> 'leads',
    ];
}

// ===== ALERTE CAPACITÉ FORMATEURS =====
foreach ($formateurs as $f) {
    if ($f['hours_peak'] > 0 && $f['hours_peak'] > $f['capacity']) {
        $lia_alerts[] = [
            'type'  => 'warn',
            'title' => 'Capacité dépassée : ' . $f['nom'],
            'body'  => $f['nom'] . ' a atteint ' . $f['hours_peak'] . ' h sur une semaine (capacité ' . $f['capacity'] . ' h/sem).',
            'action'=> 'Voir les intervenants',
            'target'=> 'team'
        ];
    }
}

// ===== ÉVÉNEMENTS DU JOUR =====
$today_start = mktime(0, 0, 0);
$today_end   = mktime(23, 59, 59);
$events = $DB->get_records_sql(
    "SELECT e.id, e.name, e.timestart, e.courseid,
       c.shortname as courseshort,
       CONCAT(u.firstname, ' ', u.lastname) as username
     FROM {event} e
     LEFT JOIN {course} c ON c.id = e.courseid
     LEFT JOIN {user} u ON u.id = e.userid
     WHERE e.timestart BETWEEN :tstart AND :tend AND e.visible = 1
     ORDER BY e.timestart ASC LIMIT 10",
    ['tstart' => $today_start, 'tend' => $today_end]
);
$today_events = [];
foreach ($events as $ev) {
    $today_events[] = [
        'heure'  => userdate($ev->timestart, '%H:%M'),
        'titre'  => $ev->name,
        'cours'  => $ev->courseshort ?? '',
        'auteur' => $ev->username ?? ''
    ];
}

// ===== PLAN EVENTS (séances planifiées) =====
$plan_events = [];
try {
    $pe_future = time() + 300 * 86400;
    if ($course_ids) {
        list($pe_in, $pe_params) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'pecid');
        $pe_rows = $DB->get_records_sql(
            "SELECT e.id, e.name, e.timestart, e.timeduration, e.courseid,
                    c.shortname AS course_short, c.fullname AS course_full
             FROM {event} e
             JOIN {course} c ON c.id = e.courseid
             WHERE e.courseid $pe_in AND e.visible = 1
               AND e.timestart >= :penow AND e.timestart < :pefut
               AND e.eventtype = 'course'
             ORDER BY e.timestart ASC LIMIT 200",
            array_merge(['penow' => time(), 'pefut' => $pe_future], $pe_params)
        );
        $days_fr_pe = ['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'];
        foreach ($pe_rows as $pe) {
            $plan_events[] = [
                'id'           => (int)$pe->id,
                'name'         => $pe->name,
                'timestart'    => (int)$pe->timestart,
                'duration_h'   => $pe->timeduration > 0 ? round($pe->timeduration / 3600, 1) : 2.0,
                'courseid'     => (int)$pe->courseid,
                'course_short' => $pe->course_short,
                'day_label'    => $days_fr_pe[(int)date('w', (int)$pe->timestart)],
                'hour_label'   => date('H:i', (int)$pe->timestart),
            ];
        }
    }
} catch (Exception $pe_e) { $plan_events = []; }

// ===== LISTE COURS pour le sélecteur séance =====
$courses_list = [];
try {
    $courses_raw_list = $DB->get_records_select(
        'course', "id > 1 AND category $course_client_op :satcat3", ['satcat3' => $saran_category_id],
        'fullname ASC', 'id, fullname, shortname'
    );
    foreach ($courses_raw_list as $cr) {
        $courses_list[] = ['id' => (int)$cr->id, 'fullname' => $cr->fullname, 'shortname' => $cr->shortname];
    }
} catch (Exception $cl_e) { $courses_list = []; }

// ===== LISTE SEQUENCES pour le selecteur "Module (optionnel)" de
// l'affectation manuelle Lia — remplace le 09/10/2026 l'ancienne liste de
// cours Moodle (champ purement informatif, jamais consomme par
// lia_engine.php, voir save_lia_override plus haut) : on n'a plus de cours
// Moodle a proposer ici, on propose les Sequences du referentiel a la place.
$seq_options_list = [];
foreach (l3m_progseq_all() as $sid_opt => $seq_opt) {
    $seq_options_list[] = ['id' => $sid_opt, 'titre' => $seq_opt['titre'] ?? ("Séquence $sid_opt")];
}

// ===== MOTEUR LIA (calcul serveur, source unique avec espace-formateur.php) =====
preg_match_all('/\d+/', $ecsr_params['regroupements_vehicule'], $veh_matches);
preg_match_all('/\d+/', $ecsr_params['regroupements_salle'], $salle_matches);
$veh_weeks   = array_map('intval', $veh_matches[0]);
$salle_weeks = array_map('intval', $salle_matches[0]);
lia_week_config($veh_weeks ?: [7, 13, 19], $salle_weeks ?: [25, 31]);

$lia_people  = lia_build_people($formateurs);
$lia_prof2p  = lia_build_prof2p($formateurs);
$lia_backups = lia_build_backups($lia_prof2p, $lia_people);
$lia_cohorts = lia_build_cohorts($classes);

// Indisponibilités validées → capacité effective par semaine
$lia_ref_start   = lia_earliest_start($classes);
$lia_total_w     = lia_total_weeks($lia_cohorts);
$validated_rows  = $DB->get_records_sql(
    "SELECT * FROM {l3m_dispos} WHERE status = :st AND " . l3m_admin_client_sql('userid', $admin_client, $saran_cohort_id),
    ['st' => 'validated']
);
$lia_unavailable = lia_build_unavailable($validated_rows, $lia_ref_start, $lia_total_w);

$lia_plan = ($lia_cohorts && $lia_people)
    ? lia_compute_plan($lia_cohorts, $lia_people, $lia_prof2p, $lia_backups, $lia_unavailable, $lia_overrides)
    : null;
if ($lia_plan) {
    $lia_plan['alerts'] = lia_filter_dismissed_alerts($lia_plan['alerts'], $lia_dismissed);
}

// Toutes les déclarations (pour le panneau de validation admin)
$all_dispos_raw = $DB->get_records_sql(
    "SELECT * FROM {l3m_dispos} WHERE " . l3m_admin_client_sql('userid', $admin_client, $saran_cohort_id) . " ORDER BY timecreated DESC"
);
$formateur_names = [];
foreach ($formateurs as $f) { $formateur_names[$f['id']] = $f['nom']; }
$all_dispos = [];
foreach ($all_dispos_raw as $row) {
    $d = (array)$row;
    $d['formateur_nom'] = $formateur_names[(int)$row->userid] ?? ('Utilisateur #' . $row->userid);
    $all_dispos[] = $d;
}

// ===== JSON pour le JS =====
$unread_messages = (int)\core_message\api::count_unread_conversations($USER);
$pending_signed_docs = 0;
$pending_signed_doc_userids = [];
try {
    // JOIN deleted=0 : exclut les documents orphelins d'un compte eleve
    // supprime (il reste alors introuvable/impossible a traiter dans la
    // liste des eleves, le badge ne devait donc plus le compter — voir aussi
    // le nettoyage ajoute dans l'action 'delete_student' qui evite d'en
    // recreer, question du 09/10/2026).
    // Les userid concernes sont aussi exposes (pending_signed_doc_userids,
    // voir plus bas) pour que l'admin puisse reperer QUEL eleve a un document
    // a verifier directement dans la liste, sans cliquer sur chacun un par un
    // (question du 20/10/2026 : le badge "1" seul ne dit pas qui).
    $pending_signed_doc_userids = array_map('intval', array_column(
        $DB->get_records_sql(
            "SELECT DISTINCT d.userid FROM {l3m_docs} d
             JOIN {user} u ON u.id = d.userid AND u.deleted = 0
             WHERE d.signed_uploaded_at > 0 AND d.status_type = :st
               AND " . l3m_admin_client_sql('d.userid', $admin_client, $saran_cohort_id),
            ['st' => 'pending']
        ), 'userid'
    ));
    $pending_signed_docs = count($pending_signed_doc_userids);
} catch (Exception $e) {}

$pending_leads = 0;
try {
    $pending_leads = count(array_filter(
        (array)$DB->get_records('l3m_leads', ['status' => 'nouveau']),
        fn($l) => l3m_lead_client_of($l->source) === $admin_client
    ));
} catch (Exception $e) {}

// Testeurs beta — voir l3m_branding.php pour l'usage cote code (l3m_is_beta_tester).
$beta_testers = [];
foreach (l3m_get_beta_testers() as $bt_uid) {
    $bt_user = $DB->get_record('user', ['id' => $bt_uid, 'deleted' => 0], 'id, firstname, lastname, email', IGNORE_MISSING);
    if ($bt_user) {
        $beta_testers[] = ['id' => (int)$bt_user->id, 'nom' => $bt_user->firstname . ' ' . $bt_user->lastname, 'email' => $bt_user->email];
    }
}

$admin_data = json_encode([
    'is_siteadmin'        => is_siteadmin(),
    'is_ped_lead_viewer'  => l3m_is_ped_lead($USER->id) || is_siteadmin(),
    'admin_client'        => $admin_client,
    'pending_signed_docs' => $pending_signed_docs,
    'pending_signed_doc_userids' => $pending_signed_doc_userids,
    'unread_messages'     => $unread_messages,
    'pending_leads'       => $pending_leads,
    'beta_testers'        => $beta_testers,
    'kpi' => [
        'students'   => $kpi_students,
        'teachers'   => $kpi_teachers,
        'groups'     => $kpi_groups,
        'completion' => $avg_completion,
        'presence'   => $kpi_presence,
    ],
    'alerts'       => $lia_alerts,
    'formateurs'   => $formateurs,
    'classes'      => $classes,
    'students'     => $students,
    'today_events' => $today_events,
    'date_label'   => userdate(time(), '%A %d %B %Y'),
    'plan_events'  => $plan_events,
    'courses_list' => $courses_list,
    'seq_options'  => $seq_options_list,
    'formations'   => l3m_get_formations_list($admin_client, $saran_category_id),
    'prog_sequences' => l3m_progseq_for_display($USER->id),
    'lia_people'   => $lia_people,
    'lia_cohorts'  => $lia_cohorts,
    'lia_plan'     => $lia_plan,
    'veh_weeks'    => $veh_weeks,
    'salle_weeks'  => $salle_weeks,
    'dispos'       => $all_dispos,
    'ecsr_params'  => $ecsr_params,
], JSON_UNESCAPED_UNICODE);

$user_initials = mb_substr($USER->firstname, 0, 1) . mb_substr($USER->lastname, 0, 1);
$user_name     = $USER->firstname . ' ' . $USER->lastname;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Administration · <?= s($brand['name']) ?></title>
<link rel="icon" type="image/png" href="<?= s($brand['logo']) ?>">
<style>
:root {
  --teal:<?= $brand['teal'] ?>; --teal-d:<?= $brand['teal_d'] ?>; --teal-t:<?= $brand['teal_t'] ?>;
  --orange:<?= $brand['orange'] ?>; --orange-d:<?= $brand['orange_d'] ?>; --orange-t:<?= $brand['orange_t'] ?>;
  --violet:#7C6FDD; --violet-d:#4A3FA3; --violet-t:#ECEAFA;
  --red:#D64545; --red-t:#FBE7E7;
  --amber:#F6C685; --amber-d:#8a5a10;
  --sand:#B9B29E; --sand-t:#EFECE1;
  --ink:<?= $brand['ink'] ?>; --muted:<?= $brand['muted'] ?>;
  --bg:<?= $brand['bg'] ?>; --card:<?= $brand['card'] ?>; --line:<?= $brand['line'] ?>; --dark:#33424B;
}
<?php if ($admin_client === 'saran'): ?>
/* Sur fond sombre, ces titres (pensés à l'origine pour un texte foncé sur
   fond clair via --teal-d) doivent repasser en clair pour rester lisibles. */
.panel h3, h2, .students-title, .event .h, .pg-logline .pg-lw, #modal-seance h3 { color:var(--ink) !important; }
/* Icônes des cartes-outils (Émargement, Rapport d'heures) : --teal-t/--teal-d
   sont devenus des gris sombres structurels pour Saran, donc l'icône (glyphe
   sombre sur badge sombre) devient invisible sur la carte déjà sombre. */
.admin-tool-card .ico { background:rgba(255,255,255,.12) !important; color:#fff !important; }
/* Chips "Visible"/"présentiel"/"inscrit·e"/"LBA" : meme souci --teal-t/--teal-d
   (repurposes en gris structurel sombre pour Saran) que ci-dessus. */
.chip.c-ok, .chip.c-vi { color:var(--ink) !important; }
/* Menu lateral : --muted (inactif) trop sombre sur le fond deja sombre, et
   --teal-d (item actif) quasi noir sur le pastille --teal-t elle-meme sombre. */
aside button, aside button.on { color:var(--ink) !important; }
/* Bouton "signé" (meme souci --teal-t/--teal-d) et placeholder "Ajouter une
   note..." (--line devient un gris a peine plus clair que le fond du tableau). */
.reg-signed { color:var(--ink) !important; }
.note-input::placeholder { color:var(--muted) !important; }
<?php endif; ?>
*, *::before, *::after { box-sizing:border-box; }
body { margin:0; font-family:"Segoe UI",system-ui,sans-serif; background:var(--bg); color:var(--ink); font-size:14.5px; line-height:1.5; }
.top { position:sticky; top:0; z-index:40; background:var(--card); border-bottom:1px solid var(--line); display:flex; align-items:center; gap:16px; padding:10px 18px; box-shadow:0 1px 3px rgba(36,51,60,.06); }
.brand { display:flex; align-items:center; gap:9px; font-weight:800; letter-spacing:.04em; font-size:15px; color:var(--teal-d); white-space:nowrap; text-decoration:none; }
.brand em { font-style:normal; color:var(--orange); font-weight:400; font-size:17px; font-family:Georgia,serif; }
.brand small { font-weight:500; color:var(--muted); letter-spacing:0; margin-left:2px; }
.today { flex:1; text-align:center; font-size:12.5px; color:var(--muted); }
.today b { color:var(--ink); }
.user-wrap { position:relative; }
.user { display:flex; align-items:center; gap:8px; font-size:12.5px; font-weight:600; cursor:pointer; user-select:none; border:none; background:none; font-family:inherit; padding:4px; border-radius:10px; color:var(--ink); }
.user:hover { background:var(--bg); }
.user .av { width:28px; height:28px; border-radius:99px; background:var(--teal); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:12px; flex-shrink:0; }
.user-menu { position:absolute; top:calc(100% + 8px); right:0; background:var(--card); border:1px solid var(--line); border-radius:12px; box-shadow:0 8px 24px rgba(36,51,60,.14); min-width:190px; padding:6px; z-index:60; display:none; }
.user-menu.on { display:block; }
.user-menu a { display:block; padding:9px 12px; border-radius:8px; font-size:12.5px; font-weight:600; color:var(--ink); text-decoration:none; }
.user-menu a:hover { background:var(--bg); }
.user-menu a.danger { color:var(--red); }
.user-menu .sep { height:1px; background:var(--line); margin:6px 4px; }
.app { display:flex; min-height:calc(100vh - 49px); }
aside { flex:0 0 200px; background:var(--card); border-right:1px solid var(--line); padding:14px 10px; display:flex; flex-direction:column; gap:3px; position:sticky; top:49px; height:calc(100vh - 49px); overflow-y:auto; }
aside button { appearance:none; border:none; background:none; text-align:left; padding:10px 12px; border-radius:10px; font-size:13px; font-weight:600; color:var(--muted); cursor:pointer; font-family:inherit; display:flex; gap:9px; align-items:center; width:100%; transition:background .12s,color .12s; }
aside button.on { background:var(--teal-t); color:var(--teal-d); }
aside button:hover:not(.on) { background:var(--bg); color:var(--ink); }
.nav-badge { margin-left:auto; background:var(--red,#D64545); color:#fff; font-size:10.5px; font-weight:800; border-radius:99px; min-width:18px; height:18px; padding:0 5px; display:flex; align-items:center; justify-content:center; }
aside .sep { flex:1; }
aside .back-link { font-size:11.5px; color:var(--muted); padding:8px 12px; border-top:1px solid var(--line); text-decoration:none; display:block; }
aside .back-link:hover { color:var(--teal-d); }
main { flex:1; padding:22px 24px 80px; min-width:0; }

/* ── Menu drawer (navigation mobile) ── */
.mobile-nav-toggle { display:none; }
.mobile-nav-backdrop { display:none; }
@media(max-width:760px){
  .mobile-nav-toggle {
    display:flex; align-items:center; justify-content:center; flex-shrink:0;
    width:34px; height:34px; border:none; border-radius:9px;
    background:var(--teal-d); color:#fff; font-size:16px; cursor:pointer;
  }
  .mobile-nav-backdrop.on { display:block; position:fixed; inset:0; background:rgba(20,30,35,.45); z-index:55; }
  aside {
    position:fixed; top:0; left:0; height:100vh; width:230px; flex:0 0 230px;
    transform:translateX(-100%); transition:transform .22s ease; z-index:60;
    box-shadow:2px 0 18px rgba(0,0,0,.2); padding-top:16px;
  }
  aside.on { transform:translateX(0); }
}
.view { display:none; }
.view.on { display:block; }
h1 { font-size:20px; font-weight:800; margin:0 0 4px; }
.sub { color:var(--muted); font-size:13px; margin:0 0 18px; }
h2 { font-size:12px; font-weight:700; margin:22px 0 8px; color:var(--teal-d); text-transform:uppercase; letter-spacing:.07em; }
.kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:12px; margin-bottom:20px; }
.kpi { border-radius:16px; padding:18px 20px 20px; min-height:120px; display:flex; flex-direction:column; justify-content:space-between; border:1px solid transparent; transition:transform .15s,box-shadow .15s; }
.kpi:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(36,51,60,.08); }
.kpi-head { display:flex; justify-content:space-between; align-items:flex-start; }
.kpi-title { font-size:12px; font-weight:700; opacity:.7; }
.kpi-main { display:flex; justify-content:space-between; align-items:flex-end; margin-top:10px; gap:10px; }
.kpi-num { font-size:36px; font-weight:800; line-height:1; letter-spacing:-.03em; font-variant-numeric:tabular-nums; }
.kpi-num small { font-size:16px; opacity:.55; margin-left:2px; }
.kpi-pill { background:rgba(255,255,255,.65); border:1px solid rgba(255,255,255,.85); font-size:11px; font-weight:700; padding:4px 9px; border-radius:99px; white-space:nowrap; }
.kpi-sub { font-size:11px; opacity:.55; font-weight:500; margin-top:8px; }
.k-orange { background:#F9C57D; border-color:#F5B15F; color:#24333C; }
.k-mint { background:#C7EDE1; border-color:#A7DDCB; color:#24333C; }
.k-violet { background:#DDD9F7; border-color:#C9C3F0; color:#24333C; }
.k-cream { background:var(--card); border-color:var(--line); }
.k-danger { background:var(--red-t); border-color:#F5C4C4; color:#24333C; }
.k-danger .kpi-num { color:var(--red); }
.panel { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:16px 18px; margin-bottom:14px; min-width:0; }
.panel h3 { margin:0 0 12px; font-size:11px; font-weight:700; color:var(--teal-d); text-transform:uppercase; letter-spacing:.07em; }

/* "Mes modules" (Programme + Sequences) — porte depuis espace-formateur.php
   pour que l'admin voie exactement la meme chose (question du 02/10/2026). */
.section-title { font-size:16px; font-weight:800; margin:18px 0 10px; display:flex; align-items:center; gap:8px; }
.section-divider { height:1px; background:var(--line); margin:28px 0 6px; }
.prog-embed-box { border:1px solid var(--line); border-radius:14px; overflow:hidden; margin-bottom:8px; background:#fff; }
.seqp-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); gap:16px; margin-bottom:14px; }
.seqp-card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:20px; display:flex; flex-direction:column; min-height:150px; cursor:pointer; transition:border-color .15s,box-shadow .15s,transform .12s; position:relative; }
.seqp-card:hover { border-color:var(--teal); box-shadow:0 4px 14px rgba(20,30,45,.06); transform:translateY(-1px); }
.seqp-id { font-size:11.5px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:var(--teal-d); margin-bottom:8px; }
.seqp-title { font-size:16px; font-weight:700; line-height:1.35; margin:0 0 14px; flex-grow:1; color:var(--ink); }
.seqp-bar-track { height:5px; background:var(--line); border-radius:99px; overflow:hidden; margin-bottom:8px; }
.seqp-bar-fill { height:100%; background:linear-gradient(90deg,var(--teal),var(--teal-d)); border-radius:99px; }
.seqp-meta { font-size:13px; color:var(--muted); }
.seqp-chevron { position:absolute; top:20px; right:18px; color:var(--line); font-size:18px; }
.seqp-back { display:inline-flex; align-items:center; gap:6px; border:1px solid var(--line); background:var(--card); color:var(--ink); padding:8px 14px; border-radius:99px; font-size:13px; font-weight:600; cursor:pointer; font-family:inherit; margin-bottom:14px; }
.seqp-back:hover { border-color:var(--teal); }
.seqp-detail-id { font-size:11.5px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:var(--teal-d); margin-bottom:4px; }
.seqp-detail-title { font-size:20px; margin:0 0 14px; color:var(--ink); }
.seqp-mod-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:16px; }
.seqp-mod-card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:18px 20px; display:flex; align-items:center; min-height:64px; font-size:14px; font-weight:700; color:var(--ink); line-height:1.35; cursor:pointer; transition:border-color .15s,box-shadow .15s,transform .12s; }
.seqp-mod-card:hover { border-color:var(--teal); box-shadow:0 4px 14px rgba(20,30,45,.06); transform:translateY(-1px); }
.seqp-ovr-label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; display:block; margin-bottom:4px; }
.seqp-ovr-select { border:1px solid var(--line); border-radius:8px; padding:7px 10px; font-size:12.5px; font-family:inherit; background:#fff; color:var(--ink); }
.cols2 { display:grid; grid-template-columns:1.1fr .9fr; gap:14px; }
@media(max-width:900px) { .cols2 { grid-template-columns:1fr; } }
.event { display:flex; gap:10px; padding:9px 0; border-bottom:1px solid var(--line); font-size:13px; align-items:baseline; }
.event:last-child { border:none; }
.event .h { flex:0 0 52px; font-weight:700; color:var(--teal-d); font-variant-numeric:tabular-nums; font-size:12.5px; }
.chip { display:inline-block; font-size:10px; font-weight:700; border-radius:99px; padding:2px 8px; white-space:nowrap; vertical-align:1px; }
.c-vi { background:var(--teal-t); color:var(--teal-d); }
.c-ok { background:var(--teal-t); color:var(--teal-d); }
.c-ko { background:var(--red-t); color:var(--red); }
.c-mid { background:var(--orange-t); color:var(--orange-d); }
.lia-item { border-left:3px solid var(--orange); background:var(--orange-t); border-radius:0 10px 10px 0; padding:10px 13px; margin-bottom:10px; font-size:13px; }
.lia-item.danger { border-color:var(--red); background:var(--red-t); }
.lia-item b { color:var(--orange-d); }
.lia-item.danger b { color:var(--red); }
.lia-item .act { margin-top:8px; display:flex; gap:8px; }
.btn { display:inline-block; border:none; border-radius:99px; padding:7px 16px; font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; text-decoration:none; transition:filter .12s; }
.btn:hover { filter:brightness(.92); }
.btn.primary { background:var(--<?= $brand['button_accent'] ?>); color:#fff; }
.btn.ghost { background:var(--card); color:var(--<?= $brand['button_accent'] ?>-d); border:1.5px solid var(--<?= $brand['button_accent'] ?>); }
.btn.mini { padding:5px 12px; font-size:11px; }
/* La regle globale "aside button:hover" (menu lateral) matchait aussi nos
   boutons ghost places dans .msg-list-pane (qui est un <aside>). */
.msg-list-pane .btn.ghost:hover { background:var(--card); color:var(--<?= $brand['button_accent'] ?>-d); }
.btn.danger-btn { background:var(--red-t); color:var(--red); border:1.5px solid var(--red); }
.grid3 { display:grid; grid-template-columns:repeat(auto-fit,minmax(255px,1fr)); gap:12px; }
.pers { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:14px 16px; }
.pers .tp { display:flex; gap:10px; align-items:center; margin-bottom:8px; }
.av { width:34px; height:34px; border-radius:99px; color:#fff; font-weight:800; font-size:13px; display:flex; align-items:center; justify-content:center; flex:0 0 34px; }
.pers b { font-size:13.5px; font-weight:700; }
.pers .rt { font-size:11px; color:var(--muted); margin-top:1px; }
.pers p { margin:6px 0 0; font-size:11.5px; color:var(--muted); line-height:1.55; }
.dot { display:inline-block; width:7px; height:7px; border-radius:99px; background:var(--teal); margin-right:5px; flex-shrink:0; }
/* ---- cartes intervenants (mockup) ---- */
.grid4 { display:grid; grid-template-columns:repeat(auto-fill,minmax(215px,1fr)); gap:12px; }
.pers { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:14px 16px 13px; }
.pers-top { display:flex; gap:10px; align-items:flex-start; }
.edit-btn { border:none; background:none; color:var(--muted); cursor:pointer; font-size:14px; padding:0 0 0 4px; margin-left:auto; line-height:1; flex-shrink:0; margin-top:1px; }
.edit-btn:hover { color:var(--teal-d); }
.pers b { font-size:13.5px; font-weight:700; display:block; line-height:1.25; }
.rt { font-size:11px; color:var(--muted); margin-top:2px; }
.habs-row { display:flex; gap:5px; flex-wrap:wrap; margin:10px 0 8px; min-height:22px; }
.hab-tag { display:inline-flex; align-items:center; font-size:11px; font-weight:700; border-radius:8px; padding:3px 9px; white-space:nowrap; line-height:1.4; }
.status-line { font-size:11.5px; color:var(--muted); font-variant-numeric:tabular-nums; line-height:1.5; margin-bottom:5px; }
.f-prochain { font-size:11.5px; color:var(--muted); line-height:1.45; }
/* ---- couleur pickers habilitations ---- */
.hcb { width:15px; height:15px; border-radius:99px; border:2px solid transparent; cursor:pointer; padding:0; flex-shrink:0; }
.hcb[data-color="teal"]   { background:var(--teal); }
.hcb[data-color="orange"] { background:var(--orange); }
.hcb[data-color="violet"] { background:var(--violet); }
.hcb.active { border-color:var(--ink); }
/* ---- header intervenants ---- */
.team-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:4px; gap:12px; }
.team-header h1 { margin:0; }
/* ---- hab input in modal ---- */
#fmod-hab-text { border:1.5px solid var(--line); background:var(--bg); border-radius:10px; padding:8px 12px; font-size:13px; font-family:inherit; }
#fmod-hab-text:focus { outline:none; border-color:var(--teal); }
/* ---- planning & lia grid ---- */
.pg-pbar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:10px; }
.pg-toggle { display:flex; border:1px solid var(--line); border-radius:99px; overflow:hidden; background:var(--card); }
.pg-toggle button { appearance:none; border:none; background:none; padding:7px 16px; font-size:12.5px; font-weight:700; color:var(--muted); cursor:pointer; font-family:inherit; }
.pg-toggle button.on { background:var(--teal); color:#fff; }
.pg-legend { display:flex; flex-wrap:wrap; gap:5px 13px; font-size:11px; color:var(--muted); margin-bottom:8px; align-items:center; }
.pg-sw { display:inline-block; width:10px; height:10px; border-radius:3px; margin-right:3px; vertical-align:-1px; }
.pg-wrap { overflow-x:auto; background:var(--card); border:1px solid var(--line); border-radius:12px; padding-bottom:6px; margin-bottom:12px; }
.pg-row { display:flex; border-bottom:1px solid #F0EDE3; }
.pg-row:last-child { border:none; }
.pg-lab { flex:0 0 148px; max-width:148px; padding:4px 8px; font-size:11px; position:sticky; left:0; background:var(--card); z-index:5; border-right:1px solid var(--line); }
.pg-lab b { display:block; font-weight:700; font-size:11.5px; }
.pg-lab span { color:var(--muted); font-size:9.5px; display:block; }
.pg-cell { width:19px; flex:0 0 19px; height:28px; border-right:1px solid #F5F3EB; display:flex; align-items:center; justify-content:center; font-size:8px; gap:1px; position:relative; }
.pg-mrow .pg-cell, .pg-wrow .pg-cell { height:16px; color:var(--muted); font-size:8px; }
.pg-cell.pg-now { box-shadow:inset 0 0 0 2px var(--violet); }
.pg-c-d  { background:var(--teal-t); }
.pg-c-s  { background:var(--sand-t); }
.pg-c-r  { background:var(--orange); color:#fff; font-weight:700; }
.pg-c-rs { background:var(--amber); color:var(--amber-d); font-weight:700; }
.pg-c-e  { background:var(--dark); color:#fff; font-weight:700; }
.pg-p1 { background:#F2F7F0; } .pg-p2 { background:#E2EFE0; } .pg-p3 { background:#FBEDDA; } .pg-hot { background:var(--red-t); }
.pg-pcell { width:13px; height:13px; border-radius:3px; color:#fff; font-size:8px; font-weight:800; display:flex; align-items:center; justify-content:center; }
.pg-ch-v { background:var(--teal); } .pg-ch-veh { background:var(--orange); } .pg-ch-s { background:var(--amber); color:var(--amber-d); }
.pg-star { font-size:7px; color:var(--violet-d); line-height:1; }
/* Filtre par type dans la legende (Distance/Vehicule/Salle/Stage/Examen) —
   voir setPlanTypeFilter() : les cases/pastilles qui ne correspondent pas
   au type choisi sont attenuees plutot que masquees, pour garder le
   contexte de la grille (qui est occupe a cote). */
.pg-legend .pg-filter-btn { background:none; border:none; padding:0; margin:0; font:inherit; font-size:11px; color:var(--muted); cursor:pointer; display:inline-flex; align-items:center; }
.pg-legend .pg-filter-btn:hover { color:var(--ink); }
.pg-legend .pg-filter-btn.on { color:var(--ink); font-weight:800; }
.pg-dim { visibility:hidden; }
/* Version beta "calendrier simplifie" (testeurs uniquement, voir
   l3m_is_beta_tester) : cases plus grandes pour respirer, legende sur une
   seule ligne (scroll horizontal si besoin plutot que retour a la ligne).
   La colonne formateurs figee et le detail au survol existaient deja. */
.pg-legend.beta-cal { flex-wrap:nowrap; overflow-x:auto; white-space:nowrap; padding-bottom:4px; }
.pg-legend.beta-cal span { flex:0 0 auto; }
.pg-wrap.beta-cal .pg-cell { width:27px; flex:0 0 27px; height:36px; font-size:9px; }
.pg-wrap.beta-cal .pg-mrow .pg-cell, .pg-wrap.beta-cal .pg-wrow .pg-cell { height:18px; font-size:9px; }
.pg-wrap.beta-cal .pg-lab { flex:0 0 168px; max-width:168px; padding:6px 10px; }
.pg-wrap.beta-cal .pg-lab b { font-size:12.5px; }
.pg-wrap.beta-cal .pg-lab span { font-size:10.5px; }
.pg-wrap.beta-cal .pg-pcell { width:16px; height:16px; font-size:9px; border-radius:4px; }
.pg-wrap.beta-cal .pg-star { font-size:8px; }
.pg-wrap.beta-cal .pg-manual { font-size:7px; }
.pg-wrap.beta-cal .pg-cell.pg-now { background:var(--teal-t); box-shadow:none; }
.pg-wrap.beta-cal .pg-mrow .pg-cell.pg-now, .pg-wrap.beta-cal .pg-wrow .pg-cell.pg-now { background:var(--teal-t); font-weight:800; color:var(--teal-d); }
.pg-wrap.beta-cal .pg-ch-v { background:var(--violet-t); color:var(--violet-d); }
.pg-wrap.beta-cal .pg-c-d { background:var(--violet-t); color:var(--violet-d); }
.pg-wrap.beta-cal .pg-pcell.pg-overload { text-decoration:underline; text-decoration-color:var(--red); text-decoration-thickness:2px; text-underline-offset:1.5px; }
.pg-logline { font-size:12px; padding:6px 0; border-bottom:1px solid var(--line); display:flex; gap:8px; }
.pg-logline:last-child { border:none; }
.pg-logline .pg-lw { flex:0 0 34px; font-weight:800; color:var(--teal-d); font-variant-numeric:tabular-nums; }
/* Sans min-width:0, un enfant flex ne se laisse pas retrecir sous la
   largeur de son contenu texte et deborde silencieusement (pas de retour
   a la ligne, pas de scroll visible) des que la phrase est un peu longue —
   d'ou les entrees de journal coupees net sur mobile. */
.pg-logline .pg-ltxt { flex:1; min-width:0; word-wrap:break-word; overflow-wrap:break-word; }
.pg-alert { background:var(--red-t); border-left:4px solid var(--red); border-radius:0 8px 8px 0; padding:8px 12px; font-size:12.5px; margin-bottom:8px; }
.pg-ok { background:var(--teal-t); border-left:4px solid var(--teal); border-radius:0 8px 8px 0; padding:8px 12px; font-size:12.5px; }
.dv-card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:14px 16px; margin-bottom:10px; }
.dv-name { font-weight:700; font-size:13.5px; }
.dv-dates { color:var(--muted); font-size:12.5px; margin-top:2px; }
.dv-acts { display:flex; gap:8px; align-items:center; margin-top:10px; flex-wrap:wrap; }
.dv-badge { font-size:10.5px; font-weight:700; padding:2px 9px; border-radius:99px; white-space:nowrap; }
.dv-badge.validated { background:var(--teal-t); color:var(--teal-d); }
.dv-badge.rejected { background:var(--red-t); color:var(--red); }
.pg-proposal { display:none; background:var(--orange-t); border:1.5px solid var(--orange); border-radius:12px; padding:14px 16px; margin-bottom:12px; }
.pg-proposal.on { display:block; }
.pg-proposal ul { margin:8px 0; padding-left:18px; font-size:13px; line-height:1.8; }
/* ---- tableau élèves ---- */
.twrap { overflow-x:auto; border:1px solid var(--line); border-radius:14px; margin-bottom:14px; }
table { border-collapse:collapse; width:100%; font-size:12.5px; background:var(--card); }
th, td { padding:9px 11px; border-bottom:1px solid var(--line); text-align:left; white-space:nowrap; }
th { font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); background:var(--bg); font-weight:700; }
tr:last-child td { border:none; }
tr.inactive td { background:rgba(214,69,69,.04); }
.bar { height:6px; border-radius:3px; background:var(--line); width:90px; display:inline-block; vertical-align:middle; }
.bar i { display:block; height:100%; border-radius:3px; background:var(--violet); }
@media(max-width:640px){
  /* Tableaux a beaucoup de colonnes (Leads, En attente d'activation) :
     empilement en petite fiche verticale, avec un libelle de colonne
     rappele devant chaque valeur (data-label), car ici le contenu seul
     (ex. "Situation" en texte libre) ne suffit pas a se comprendre hors du
     contexte de son en-tete de colonne. */
  .rtable thead { display:none; }
  .rtable, .rtable tbody, .rtable tr, .rtable td { display:block; width:100%; white-space:normal; }
  .rtable tr { padding:12px 14px; border-bottom:1px solid var(--line); }
  .rtable td { padding:4px 0; border-bottom:none; }
  .rtable td:empty { display:none; }
  .rtable td[data-label]:not([data-label=""])::before {
    content:attr(data-label) " : "; font-weight:700; font-size:10.5px; text-transform:uppercase;
    letter-spacing:.05em; color:var(--muted);
  }
}
/* ---- cartes classes ---- */
.view-coh-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:4px; }
.view-coh-header h1 { margin:0; }
.class-cards { display:flex; flex-wrap:wrap; justify-content:center; gap:12px; margin-bottom:20px; margin-top:16px; }
.class-card { position:relative; flex:0 0 300px; background:var(--card); border:1px solid var(--line); border-radius:14px; padding:16px 18px; cursor:pointer; transition:transform .12s,box-shadow .12s; }
.class-card:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(36,51,60,.1); }
.class-card.active { border-color:var(--teal); box-shadow:0 0 0 2px var(--teal-t); }
.cc-delete { position:absolute; top:10px; right:10px; width:24px; height:24px; border:none; border-radius:50%; background:transparent; color:var(--muted); font-size:14px; line-height:1; cursor:pointer; display:flex; align-items:center; justify-content:center; }
.cc-delete:hover { background:var(--red-t,#FDE8E8); color:var(--red); }
.cc-name { font-size:16px; font-weight:800; color:var(--ink); margin-bottom:3px; }
.cc-week { font-size:12px; color:var(--muted); margin-bottom:12px; }
.cc-meta { font-size:12px; color:var(--muted); line-height:1.7; margin-bottom:10px; }
.cc-secteur { font-size:11.5px; color:var(--teal); font-weight:600; margin-bottom:10px; cursor:pointer; display:inline-block; }
.cc-secteur:hover { text-decoration:underline; }
.cc-secteur-empty { color:var(--muted); font-weight:500; font-style:italic; }
.cc-secteur-sugg { font-size:11.5px; color:var(--muted); margin:-4px 0 10px; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.cc-secteur-sugg strong { color:var(--ink); }
.cc-secteur-valid { border:1px solid var(--teal); background:transparent; color:var(--teal); border-radius:99px; font-size:10.5px; font-weight:700; padding:2px 9px; cursor:pointer; }
.cc-secteur-valid:hover { background:var(--teal); color:#fff; }
.cc-phase { color:var(--violet-d); font-weight:600; }
.cc-prog-row { display:flex; justify-content:space-between; align-items:center; margin-bottom:5px; }
.cc-prog-label { font-size:11.5px; color:var(--muted); }
.cc-prog-pct { font-size:13px; font-weight:800; font-variant-numeric:tabular-nums; }
.prog-wrap { background:var(--line); border-radius:99px; height:6px; overflow:hidden; margin-bottom:10px; }
.prog-fill { height:100%; border-radius:99px; background:var(--teal); transition:width .4s; }
.cc-milestones { font-size:12px; color:var(--muted); line-height:1.8; }
.cc-milestones strong { color:var(--ink); font-weight:700; }
.cc-alert { font-size:12px; color:var(--red); font-weight:600; margin-top:8px; }
.class-card-add { border:2px dashed var(--line); border-radius:14px; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; min-height:130px; cursor:pointer; transition:border-color .15s,background .15s; }
.class-card-add:hover { border-color:var(--teal); background:var(--teal-t); }
.class-card-add .icon { font-size:24px; color:var(--muted); }
.class-card-add .lbl { font-size:12px; font-weight:700; color:var(--muted); }
/* ---- tableau élèves enrichi ---- */
.sgchip { display:inline-block; background:var(--violet-t); color:var(--violet-d); font-size:11px; font-weight:800; border-radius:6px; padding:2px 7px; font-variant-numeric:tabular-nums; }
.reg-signed { display:inline-flex; align-items:center; gap:4px; background:var(--teal-t); color:var(--teal-d); font-size:11px; font-weight:700; border-radius:99px; padding:3px 9px; cursor:pointer; border:none; font-family:inherit; }
.reg-unsigned { display:inline-flex; align-items:center; gap:4px; background:var(--red-t); color:var(--red); font-size:11px; font-weight:700; border-radius:99px; padding:3px 9px; cursor:pointer; border:none; font-family:inherit; }
.reg-signed:hover, .reg-unsigned:hover { filter:brightness(.92); }
.pres-ok { color:var(--ink); font-variant-numeric:tabular-nums; }
.pres-ko { color:var(--red); font-variant-numeric:tabular-nums; font-weight:700; }
.pres-warning { font-size:11px; color:var(--red); margin-left:4px; }
.note-input { border:none; background:transparent; font-size:12px; font-family:inherit; color:var(--muted); width:160px; cursor:text; padding:2px 4px; border-radius:4px; }
.note-input:hover { background:var(--bg); }
.note-input:focus { outline:none; background:var(--bg); color:var(--ink); }
.note-input::placeholder { color:var(--line); }
.btn-agir { display:inline-block; border:none; border-radius:99px; padding:6px 14px; font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; background:var(--teal); color:#fff; transition:filter .12s; }
.btn-agir:hover { filter:brightness(.88); }
.students-title { font-size:15px; font-weight:800; margin:0 0 10px; color:var(--teal-d); }
/* ---- filtre classes ---- */
.filter-bar { display:flex; gap:8px; align-items:center; margin-bottom:12px; flex-wrap:wrap; }
.filter-bar .filter-lbl { font-size:12px; color:var(--muted); font-weight:600; }
.filter-btn { border:1.5px solid var(--line); background:var(--card); border-radius:99px; padding:5px 13px; font-size:12px; font-weight:600; cursor:pointer; font-family:inherit; color:var(--muted); transition:all .12s; }
.filter-btn.on { border-color:var(--teal); background:var(--teal-t); color:var(--teal-d); }
/* ---- dropdown affecter ---- */
.assign-wrap { position:relative; display:inline-block; }
.assign-select { border:1.5px solid var(--line); background:var(--bg); border-radius:8px; padding:4px 8px; font-size:11px; font-weight:600; font-family:inherit; cursor:pointer; color:var(--ink); }
.assign-select:focus { outline:none; border-color:var(--teal); }
/* ---- administratif ---- */
.admin-tools-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:14px; margin-top:16px; }
.admin-tool-card { appearance:none; display:flex; align-items:center; gap:16px; background:var(--card); border:1px solid var(--line); border-radius:16px; padding:20px 22px; text-decoration:none; color:inherit; font-family:inherit; text-align:left; cursor:pointer; transition:border-color .12s, box-shadow .12s; }
.admin-tool-card:hover { border-color:var(--teal); box-shadow:0 4px 14px rgba(36,51,60,.08); }
.admin-tool-card.on { border-color:var(--teal); background:var(--teal-t); }
.admin-tool-card .ico { width:44px; height:44px; border-radius:12px; background:var(--teal-t); color:var(--teal-d); display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
.admin-tool-card .text { flex:1; }
.admin-tool-card .text h3 { margin:0; font-size:14.5px; font-weight:800; }
.admin-tool-card .text p { margin:4px 0 0; font-size:12px; color:var(--muted); }
.admin-tool-card .arrow { font-size:18px; color:var(--muted); }
.admin-tool-frame-wrap { margin-top:18px; border:1px solid var(--line); border-radius:16px; overflow:hidden; background:#fff; }
.admin-tool-frame-wrap iframe { width:100%; height:78vh; border:none; display:block; }
/* ---- paramètres ---- */
.param-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(360px,1fr)); gap:14px; margin-bottom:20px; }
.param-card { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:20px 22px; }
.param-card .pc-head { display:flex; align-items:flex-start; gap:12px; margin-bottom:16px; }
.param-card .ico { width:40px; height:40px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
.param-card .pc-head .text h3 { margin:0; font-size:14px; font-weight:800; }
.param-card .pc-head .text p { margin:3px 0 0; font-size:11.5px; color:var(--muted); }
.param-row { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:9px 0; border-bottom:1px solid var(--line); font-size:12.5px; }
.param-row:last-child { border:none; }
.param-row .lbl { color:var(--muted); }
.param-row .val { font-weight:700; font-variant-numeric:tabular-nums; }
.param-row input[type="number"] { border:1px solid var(--line); background:var(--bg); color:var(--ink); border-radius:8px; padding:5px 10px; font-size:12px; font-weight:700; font-family:inherit; text-align:right; width:80px; }
.param-row input[type="number"].sm { width:56px; text-align:center; padding:5px 4px; }
.param-row input[type="text"] { border:1px solid var(--line); background:var(--bg); color:var(--ink); border-radius:8px; padding:5px 10px; font-size:12px; font-weight:700; font-family:inherit; text-align:right; width:110px; }
.param-row select { border:1px solid var(--line); background:var(--bg); border-radius:8px; padding:5px 8px; font-size:12px; font-weight:700; font-family:inherit; color:var(--ink); }
.param-row input:focus, .param-row select:focus { outline:none; border-color:var(--teal); }
.pc-orange .ico { background:var(--orange-t); color:var(--orange-d); }
.pc-teal .ico { background:var(--teal-t); color:var(--teal-d); }
.pc-violet .ico { background:var(--violet-t); color:var(--violet-d); }
.pc-sand .ico { background:var(--sand-t); color:var(--sand); }
.pc-seed .ico { background:#f0f9e8; color:#3a7a1c; }
.pc-rose .ico { background:var(--red-t); color:var(--red); }
.pc-actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; background:var(--card); border:1px solid var(--line); border-radius:14px; padding:14px 20px; }
.pc-actions .lbl { font-size:12.5px; color:var(--muted); flex:1; }
/* ---- planning placeholder ---- */
.placeholder { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:48px 24px; text-align:center; color:var(--muted); }
.placeholder .icon { font-size:40px; margin-bottom:14px; }
.placeholder h2 { color:var(--ink); margin:0 0 8px; font-size:17px; }
.placeholder p { font-size:13px; margin:0; }
/* ---- modal ---- */
.modal-bg { position:fixed; inset:0; background:rgba(36,51,60,.45); z-index:100; display:none; align-items:center; justify-content:center; }
.modal-bg.open { display:flex; }
.modal { background:var(--card); border-radius:18px; padding:28px 30px; width:400px; max-width:92vw; box-shadow:0 20px 60px rgba(0,0,0,.2); }
.modal h2 { margin:0 0 6px; font-size:16px; }
.modal .modal-sub { margin:0 0 20px; font-size:12.5px; color:var(--muted); }
.fld { margin-bottom:14px; }
.fld label { display:block; font-size:11.5px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; margin-bottom:5px; }
.fld input { width:100%; border:1.5px solid var(--line); background:var(--bg); border-radius:10px; padding:9px 13px; font-size:13.5px; font-family:inherit; transition:border-color .12s; }
.fld input:focus { outline:none; border-color:var(--teal); }
.modal-actions { display:flex; gap:8px; justify-content:flex-end; margin-top:20px; }
/* ─── viewer de cours intégré (TOC + iframe), même composant que espace-formateur.php ─── */
/* Ce composant, porté tel quel depuis espace-formateur.php, bascule sa
   visibilité via la classe .on (pas .open comme le reste des modales de ce
   fichier) — règle dédiée à cet id précis pour ne pas toucher .modal-bg. */
#course-modal-bg.on { display:flex; }
.course-modal { width:1200px; max-width:97vw; height:94vh; max-height:960px; display:flex; flex-direction:column; overflow:hidden; padding:0; }
.course-modal-head { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid var(--line); flex-shrink:0; }
.course-modal-head h2 { margin:0; font-size:15px; }
.course-modal-head-actions { display:flex; align-items:center; gap:10px; }
.course-modal-close { border:none; background:none; font-size:16px; color:var(--muted); cursor:pointer; padding:4px; line-height:1; }
.course-modal-close:hover { color:var(--ink); }
.course-modal-body { display:flex; flex:1; overflow:hidden; }
.dv-left{width:300px;flex-shrink:0;background:#fff;border-right:1px solid var(--line);display:flex;flex-direction:column;overflow:hidden}
.dv-left-head{padding:16px 18px 10px;border-bottom:1px solid var(--line);flex-shrink:0}
.dv-breadcrumb{font-size:11px;color:var(--muted);line-height:1.5;margin-bottom:8px;word-break:break-word}
.dv-breadcrumb .dv-crumb-sep{margin:0 4px;color:var(--muted)}
.dv-breadcrumb .dv-crumb-current{color:var(--ink);font-weight:700}
.dv-left-title-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.dv-left-title{font-size:16px;font-weight:800;color:var(--ink)}
.dv-unfold{font-size:11px;color:var(--teal);cursor:pointer;text-decoration:none;font-weight:600}
.dv-search{display:flex;align-items:center;gap:8px;background:var(--bg);border-radius:8px;padding:7px 12px}
.dv-search input{border:none;background:none;font-size:13px;color:var(--ink);outline:none;width:100%;font-family:inherit}
.dv-sections{flex:1;overflow-y:auto}
.det-sec{border-bottom:1px solid var(--line)}
.det-sec-head{display:flex;align-items:center;gap:12px;padding:12px 18px;cursor:pointer;transition:background .1s}
.det-sec-head:hover{background:var(--bg)}
.det-sec-num{width:32px;height:32px;border-radius:50%;border:2px solid var(--teal);color:var(--teal);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;transition:background .15s}
.det-sec-num.done{background:var(--teal);color:#fff}
.det-sec-info{flex:1;min-width:0}
.det-sec-name{font-size:13px;font-weight:700;color:var(--ink);line-height:1.3}
.det-sec-count{font-size:11px;color:var(--muted);margin-top:1px}
.det-sec-arrow{color:var(--muted);font-size:11px;flex-shrink:0;transition:transform .15s}
.det-sec-arrow.open{transform:rotate(90deg)}
.det-mods{display:none;padding:0 18px 8px 62px}
.det-mods.open{display:block}
.det-mod{display:flex;align-items:flex-start;gap:8px;padding:7px 0;cursor:pointer;font-size:13px;color:var(--ink);border-left:2px solid transparent;transition:color .12s}
.det-mod:hover .det-mod-name{color:var(--teal)}
.det-mod.active .det-mod-name{color:var(--orange);font-weight:700}
.det-mod-dot{width:10px;height:10px;border-radius:50%;background:var(--sand);flex-shrink:0;margin-top:3px}
.det-mod-dot.done{background:var(--teal)}
.det-mod-dot.active-dot{background:var(--orange)}
.det-mod-name{line-height:1.35;flex:1}
.dv-left .dv-left-title,.dv-left .det-sec-name,.dv-left .det-mod,.dv-left .dv-crumb-current{color:#24333C}
.dv-left .det-sec-count,.dv-left .dv-sections p,.dv-left .dv-breadcrumb,.dv-left .dv-crumb-sep{color:#68767E}
.dv-right{flex:1;overflow:hidden;display:flex;flex-direction:column;position:relative}
.dv-empty{display:flex;align-items:center;justify-content:center;flex:1;color:var(--muted);font-size:13px;font-style:italic}
.dv-loading { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:var(--bg); }
.dv-spinner { width:28px; height:28px; border-radius:50%; border:3px solid var(--line); border-top-color:var(--teal); animation:dvspin .7s linear infinite; }
@keyframes dvspin { to { transform:rotate(360deg); } }
#ce-iframe { flex:1; width:100%; border:none; opacity:1; transition:opacity .15s; }
/* ===== Notifications (icone topbar + modal iframe, meme mecanisme que
   espace-formateur.php/espace-eleve.php) ===== */
.topbar-bell { position:relative; border:none; background:none; cursor:pointer; font-size:18px; padding:6px 8px; border-radius:10px; color:var(--ink); }
.topbar-bell:hover { background:var(--bg); }
.topbar-bell-badge { position:absolute; top:0; right:0; background:var(--red,#D64545); color:#fff; font-size:10px; font-weight:800; border-radius:99px; min-width:16px; height:16px; padding:0 4px; display:none; align-items:center; justify-content:center; }
.util-modal-bg { position:fixed; inset:0; background:rgba(36,51,60,.45); z-index:200; display:none; align-items:center; justify-content:center; }
.util-modal-bg.on { display:flex; }
.util-modal { background:var(--card); border-radius:18px; box-shadow:0 20px 60px rgba(0,0,0,.25); width:720px; max-width:94vw; height:94vh; max-height:920px; display:flex; flex-direction:column; overflow:hidden; }
.util-modal-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid var(--line); flex-shrink:0; }
.util-modal-head h2 { margin:0; font-size:15px; }
.util-modal-close { border:none; background:none; font-size:16px; color:var(--muted); cursor:pointer; padding:4px; line-height:1; }
.util-modal-close:hover { color:var(--ink); }
.util-modal-body { position:relative; flex:1; overflow:hidden; }
.util-modal iframe { position:absolute; inset:0; width:100%; height:100%; border:none; opacity:0; transition:opacity .15s; }
.util-modal iframe.ready { opacity:1; }
.dv-mobile-back{display:none}
@media(max-width:760px){
  /* Meme correctif que cote formateur/eleve : liste des sections + apercu
     de l'activite en plein ecran avec bouton retour sur mobile. */
  /* width:100% (pas 100vw) : voir commentaire equivalent sur .msg-modal
     plus bas — 100vw inclut la barre de defilement et pousse le bord droit
     hors zone visible. */
  .course-modal { width:100%; max-width:100%; height:100vh; max-height:100vh; border-radius:0; }
  .dv-left { width:100%; }
  .dv-right { display:none; }
  .course-modal-body.mob-content .dv-left { display:none; }
  .course-modal-body.mob-content .dv-right { display:flex; }
  .dv-mobile-back { display:flex; align-items:center; gap:6px; padding:12px 16px; border-bottom:1px solid var(--line); background:#fff; font-weight:700; font-size:13px; color:var(--ink); cursor:pointer; flex-shrink:0; }
}
/* ---- toast ---- */
.pg-has-ev { background:var(--teal) !important; color:#fff !important; font-size:10px; }
.pg-has-ev:hover { opacity:.85; }
/* ─── modal séance ─── */
#modal-seance-bg { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000; display:none; align-items:center; justify-content:center; }
#modal-seance-bg.on { display:flex; }
#modal-seance { background:#fff; border-radius:16px; padding:28px 32px; width:420px; max-width:95vw; box-shadow:0 8px 40px rgba(0,0,0,.18); }
#modal-seance h3 { margin:0 0 4px; font-size:16px; color:var(--teal-d); }
#modal-seance .sub { color:var(--muted); font-size:12.5px; margin:0 0 20px; }
#modal-seance label { display:block; font-size:12px; font-weight:700; color:var(--ink); margin-bottom:4px; margin-top:14px; }
#modal-seance select, #modal-seance input[type=time], #modal-seance input[type=number], #modal-seance input[type=text] {
  width:100%; padding:8px 12px; border:1.5px solid var(--line); border-radius:8px; font-size:14px;
  font-family:inherit; box-sizing:border-box; outline:none; }
#modal-seance select:focus, #modal-seance input:focus { border-color:var(--teal); }
.seance-foot { display:flex; gap:10px; margin-top:22px; justify-content:flex-end; }
/* ─── modal affectation manuelle (Planning & Lia) ─── */
#modal-lia-bg { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000; display:none; align-items:center; justify-content:center; }
#modal-lia-bg.on { display:flex; }
#modal-lia { background:#fff; border-radius:16px; padding:28px 32px; width:420px; max-width:95vw; box-shadow:0 8px 40px rgba(0,0,0,.18); }
#modal-lia h3 { margin:0 0 4px; font-size:16px; color:var(--teal-d); }
#modal-lia .sub { color:var(--muted); font-size:12.5px; margin:0 0 20px; }
#modal-lia label { display:block; font-size:12px; font-weight:700; color:var(--ink); margin-bottom:4px; margin-top:14px; }
#modal-lia select, #modal-lia input[type=number], #modal-lia input[type=text] {
  width:100%; padding:8px 12px; border:1.5px solid var(--line); border-radius:8px; font-size:14px;
  font-family:inherit; box-sizing:border-box; outline:none; }
#modal-lia select:focus, #modal-lia input:focus { border-color:var(--teal); }
.lia-foot { display:flex; gap:10px; margin-top:22px; justify-content:flex-end; }
.lia-foot .btn.danger-btn { margin-right:auto; }
#modal-lia-hours-bg { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000; display:none; align-items:center; justify-content:center; }
#modal-lia-hours-bg.on { display:flex; }
#modal-lia-hours { background:#fff; border-radius:16px; padding:28px 32px; box-shadow:0 8px 40px rgba(0,0,0,.18); }
#modal-lia-hours h3 { margin:0 0 14px; font-size:16px; color:var(--teal-d); }
/* ─── planning : nouveaux formats + affectations manuelles ─── */
.pg-ch-stage { background:var(--sand); color:#fff; } .pg-ch-exam { background:var(--dark); color:#fff; }
.pg-manual { font-size:6px; color:var(--teal-d); line-height:1; }
.pg-lab b.pg-clickable { cursor:pointer; }
.pg-lab b.pg-clickable:hover { text-decoration:underline; }
.pg-alert-actions { display:flex; gap:8px; margin-top:8px; }
.pg-alert .btn.mini { font-size:11px; padding:5px 12px; }
.toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:var(--dark); color:#fff; border-radius:99px; padding:11px 22px; font-size:13px; opacity:0; transition:opacity .25s; pointer-events:none; z-index:2000; box-shadow:0 4px 16px rgba(0,0,0,.3); max-width:92vw; white-space:normal; text-align:center; }
.toast.on { opacity:1; }
/* ── Admin docs view ── */
.docs-split{display:grid;grid-template-columns:250px 1fr;gap:16px;align-items:start}
.docs-panel{background:var(--card);border:1px solid var(--line);border-radius:12px;overflow:hidden}
.docs-search{width:100%;border:none;border-bottom:1px solid var(--line);padding:10px 12px;font-size:13px;outline:none;background:transparent;color:var(--ink)}
.docs-slist{max-height:55vh;overflow-y:auto}
.docs-si{padding:10px 12px;cursor:pointer;border-bottom:1px solid var(--line);font-size:13px;transition:background .12s}
.docs-si:last-child{border-bottom:none}
.docs-si:hover,.docs-si.on{background:var(--teal-t)}
.docs-si.on{border-left:3px solid var(--teal);padding-left:9px}
.docs-si-name{font-weight:600;color:var(--ink)}
.docs-si-sub{font-size:11px;color:var(--muted);margin-top:2px}
.docs-right{display:flex;flex-direction:column;gap:12px}
.docs-empty-hint{color:var(--muted);font-size:13px;padding:40px;text-align:center;background:var(--card);border:1px solid var(--line);border-radius:12px}
.doc-adm-row{display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--card);border:1px solid var(--line);border-radius:10px;font-size:13px}
.doc-adm-row.blocking{border-color:var(--red)}
.doc-adm-info{flex:1;min-width:0}
.doc-adm-title{font-weight:600;color:var(--ink)}
.doc-adm-sub{font-size:11px;color:var(--muted);margin-top:2px}
.doc-adm-status{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap;flex-shrink:0}
.doc-adm-edit-btn{font-size:15px;line-height:1;padding:5px 13px}
.dsb-signed,.dsb-validated{background:#E3F5EE;color:#2E9488}
.dsb-pending{background:var(--red-t);color:var(--red)}
.dsb-future{background:var(--sand-t);color:var(--sand)}
.docs-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.docs-form-grid .fld{margin:0}
.docs-form-grid .fld.full{grid-column:1/-1}
.fld select{width:100%;border:1.5px solid var(--line);background:var(--bg);border-radius:10px;padding:9px 13px;font-size:13.5px;font-family:inherit;color:var(--ink);outline:none}
.fld select:focus{border-color:var(--teal)}
@media(max-width:760px) {
  /* Le menu lateral n'est plus reduit a une colonne d'icones permanente ici
     (ancien compromis) : il devient un vrai tiroir hors-ecran par defaut,
     voir le bloc ".mobile-nav-toggle"/"aside.on" plus bas — question du
     12/10/2026. */
  .today { display:none; }
  /* Grille KPI "Planning & Lia" forcee a 3 colonnes en dur (voir attribut
     style de #plan-pkpis) : sur mobile chaque carte devient trop etroite et
     "Cohortes planifiees" (liste qui peut s'allonger) deborde/est ecrasee —
     on repasse en 1 colonne, empilee, plutot que 3 colonnes compressees. */
  #plan-pkpis { grid-template-columns:1fr !important; }
  /* Legende "Type" (calendrier beta) : en scroll horizontal invisible sur
     desktop (indice de survol), mais sans affordance de scroll visible sur
     mobile — on revient a un retour a la ligne normal, il y a de la place
     verticale contrairement a l'horizontale. */
  .pg-legend.beta-cal { flex-wrap:wrap; overflow-x:visible; white-space:normal; }
  /* "Documents eleves" : grille fixe 250px + 1fr qui ecrase la colonne de
     droite (boutons/textes tronques) sur mobile — meme famille de bug que
     .cols2, on repasse en 1 colonne empilee : liste puis panneau du doc. */
  .docs-split { grid-template-columns:1fr; }
  .docs-slist { max-height:40vh; }
}
button:focus-visible { outline:2px solid var(--teal); outline-offset:2px; }
@media (prefers-reduced-motion:reduce) { *, *::before, *::after { transition:none !important; } }

/* ── Messagerie (modal, vraie messagerie Moodle en arrière-plan) ── */
.msg-modal { width:960px; max-width:96vw; height:94vh; max-height:920px; display:flex; flex-direction:row; overflow:hidden; padding:0; position:relative; }
.msg-modal-close { position:absolute; top:12px; right:12px; z-index:5; border:none; background:var(--ink); width:32px; height:32px; border-radius:50%; font-size:15px; color:#fff; cursor:pointer; box-shadow:0 2px 8px rgba(0,0,0,.25); line-height:1; display:flex; align-items:center; justify-content:center; }
.msg-modal-close:hover { color:var(--ink); }

/* position:static + z-index:auto : ".msg-list-pane" est un <aside>, comme le
   menu principal — sans ce reset il herite du "aside { position:sticky }"
   plus haut (regle generique sur la balise, pas la classe). Ici la balise
   generique n'a pas de z-index explicite donc pas de collision visible avec
   le bouton de fermeture (z-index:5) pour l'instant, mais le meme bug existe
   cote eleve/formateur (z-index:50 sur leur "aside") — reset par coherence
   et pour eviter une regression si un z-index est ajoute un jour ici. */
.msg-list-pane { width:308px; flex:0 0 308px; border-right:1px solid var(--line); display:flex; flex-direction:column; background:var(--card); position:static; z-index:auto; }
.msg-list-head { padding:20px 20px 4px; }
.msg-list-head h2 { margin:0; font-size:19px; font-weight:800; }
.msg-list-sub { font-size:12px; color:var(--muted); margin-top:2px; }
.msg-search { margin:12px 16px 4px; position:relative; }
.msg-search-ico { position:absolute; left:11px; top:50%; transform:translateY(-50%); font-size:11px; opacity:.5; }
.msg-search input { width:100%; padding:8px 12px 8px 30px; border:1px solid var(--line); border-radius:10px; font-size:12.5px; background:var(--bg); color:var(--ink); font-family:inherit; box-sizing:border-box; }
.msg-search input:focus { outline:none; border-color:var(--teal); }

.msg-contacts { flex:1; overflow-y:auto; padding:2px 8px 12px; }
.msg-group-label { font-size:10.5px; font-weight:800; letter-spacing:.08em; color:var(--muted); text-transform:uppercase; padding:12px 10px 6px; }
.msg-contact { display:flex; align-items:center; gap:10px; padding:9px 10px; border-radius:12px; cursor:pointer; position:relative; }
.msg-contact:hover { background:var(--bg); }
.msg-contact.on { background:var(--teal-t); }
.msg-contact-avatar { width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:800; flex-shrink:0; position:relative; }
.msg-contact-avatar .dot { position:absolute; right:-1px; bottom:-1px; width:9px; height:9px; border-radius:50%; background:var(--sand); border:2px solid var(--card); }
.msg-contact-avatar .dot.online { background:#3FBF6F; }
.msg-contact-info { flex:1; min-width:0; }
.msg-contact-row1 { display:flex; align-items:center; gap:6px; }
.msg-contact-name { font-size:13px; font-weight:700; color:var(--ink); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.msg-contact-cls { font-size:9px; font-weight:800; padding:1px 6px; border-radius:99px; background:var(--violet-t); color:var(--violet-d); flex-shrink:0; }
.msg-contact-preview { font-size:11.5px; color:var(--muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-top:1px; }
.msg-contact.unread .msg-contact-preview { color:var(--ink); font-weight:600; }
.msg-contact-time { font-size:10.5px; color:var(--muted); white-space:nowrap; margin-left:auto; }
.msg-contact-unread-badge { position:absolute; right:10px; bottom:9px; background:var(--teal); color:#fff; font-size:9.5px; font-weight:800; border-radius:99px; min-width:16px; height:16px; display:flex; align-items:center; justify-content:center; padding:0 4px; }
.msg-contact-del { flex-shrink:0; width:26px; height:26px; border:none; background:none; border-radius:50%; color:var(--muted); font-size:13px; cursor:pointer; display:flex; align-items:center; justify-content:center; opacity:0; transition:opacity .12s, background .12s, color .12s; }
.msg-contact:hover .msg-contact-del { opacity:1; }
.msg-contact-del:hover { background:var(--red-t); color:var(--red); }

.av-c0 { background:var(--violet-t); color:var(--violet-d); }
.av-c1 { background:var(--orange-t); color:var(--orange-d); }
.av-c2 { background:var(--teal-t); color:var(--teal-d); }

.msg-thread-pane { flex:1; display:flex; flex-direction:column; min-width:0; background:var(--bg); }
.msg-thread-head { display:flex; align-items:center; justify-content:space-between; padding:16px 52px 16px 22px; background:var(--card); border-bottom:1px solid var(--line); flex-shrink:0; }
.msg-thread-who { display:flex; align-items:center; gap:11px; }
.msg-thread-avatar { width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:800; flex-shrink:0; }
.msg-thread-name { font-size:14.5px; font-weight:800; }
.msg-thread-sub { font-size:11.5px; color:var(--muted); display:flex; align-items:center; gap:5px; }
.msg-thread-sub .dot { width:6px; height:6px; border-radius:50%; background:var(--sand); display:inline-block; }
.msg-thread-sub .dot.online { background:#3FBF6F; }
.msg-thread-actions { display:flex; gap:8px; }
.btn.mini.gold { background:var(--orange); color:#fff; border:none; font-weight:800; }
.btn.mini.gold:hover { background:var(--orange-d); }
.btn.mini.gold.on { background:var(--teal); }
.btn.mini.gold.on:hover { background:var(--teal-d); }

.msg-thread-body { flex:1; overflow-y:auto; padding:20px 26px; display:flex; flex-direction:column; gap:4px; }
.msg-day-sep { align-self:center; background:var(--card); border:1px solid var(--line); color:var(--muted); font-size:10.5px; font-weight:700; padding:3px 12px; border-radius:99px; margin:10px 0; }
.msg-bubble-row { display:flex; margin-bottom:2px; }
.msg-bubble-row.mine { justify-content:flex-end; }
.msg-bubble { max-width:62%; background:var(--card); border:1px solid var(--line); border-radius:14px 14px 14px 4px; padding:9px 13px; font-size:13px; }
.msg-bubble-row.mine .msg-bubble { background:var(--teal-t); border-color:transparent; border-radius:14px 14px 4px 14px; }
.msg-bubble-time { display:block; font-size:9.5px; color:var(--muted); margin-top:3px; text-align:right; }

.msg-thread-empty { flex:1; display:flex; align-items:center; justify-content:center; color:var(--muted); font-size:13px; }
.msg-thread-input { display:flex; align-items:center; gap:10px; padding:14px 20px; background:var(--card); border-top:1px solid var(--line); flex-shrink:0; }
.msg-attach { border:none; background:none; font-size:16px; cursor:pointer; opacity:.6; padding:4px; }
.msg-attach:hover { opacity:1; }
.msg-thread-input input { flex:1; border:1px solid var(--line); background:var(--bg); border-radius:99px; padding:10px 16px; font-size:13px; font-family:inherit; color:var(--ink); }
.msg-thread-input input:focus { outline:none; border-color:var(--teal); }
.msg-send-btn { width:36px; height:36px; border-radius:50%; background:var(--orange); color:#fff; border:none; font-size:14px; cursor:pointer; flex-shrink:0; display:flex; align-items:center; justify-content:center; }
.msg-send-btn:hover { background:var(--orange-d); }
.msg-thread-back { display:none; }
@media(max-width:760px){
  /* Meme correctif que la messagerie eleve : liste de contacts (308px
     fixes) qui ecrase la conversation sur mobile. Plein ecran + retour. */
  /* width:100% (pas 100vw) : 100vw inclut la largeur de la barre de
     defilement verticale du document sous-jacent, ce qui pousse le bord
     droit de la modale (et le bouton fermer positionne "right:12px" par
     rapport a elle) hors de la zone visible sans scroll horizontal — bug
     confirme par rendu reel, pas visible en lisant le CSS seul. */
  .msg-modal { width:100%; max-width:100%; height:100vh; max-height:100vh; border-radius:0; }
  .msg-list-pane { width:100%; flex:1 1 auto; }
  .msg-thread-pane { display:none; }
  .msg-modal.msg-mob-thread .msg-list-pane { display:none; }
  .msg-modal.msg-mob-thread .msg-thread-pane { display:flex; }
  .msg-thread-back { display:flex; align-items:center; gap:6px; padding:12px 16px; border-bottom:1px solid var(--line); font-weight:700; font-size:13px; color:var(--ink); cursor:pointer; flex-shrink:0; background:var(--card); }
  .msg-modal.msg-mob-thread .msg-modal-close { display:none; }
  .msg-modal-close { top:max(12px, env(safe-area-inset-top)) !important; }
}
</style>
</head>
<body>
<!-- TOPBAR -->
<div class="top">
  <button type="button" class="mobile-nav-toggle" onclick="toggleMobileNav()" aria-label="Ouvrir le menu">&#9776;</button>
  <a class="brand" href="<?= $CFG->wwwroot ?>">
    <?php if (!empty($brand['logo_dark'])): ?>
      <img src="<?= $brand['logo_dark'] ?>" alt="<?= s($brand['name']) ?>" style="height:38px;width:auto">
    <?php else: ?>
      <svg width="26" height="20" viewBox="0 0 34 24" aria-hidden="true">
        <path d="M3 21 A14 14 0 0 1 31 21" fill="none" stroke="<?= $brand['teal'] ?>" stroke-width="4" stroke-linecap="round"/>
        <line x1="17" y1="21" x2="26" y2="9" stroke="<?= $brand['orange'] ?>" stroke-width="3" stroke-linecap="round"/>
        <circle cx="17" cy="21" r="3" fill="<?= $brand['orange'] ?>"/>
      </svg>
      <?= $brand['name_html'] ?>
    <?php endif; ?>
    <small>· Administration</small>
  </a>
  <div class="today" id="date-label">Chargement…</div>
  <button type="button" class="topbar-bell" onclick="openUtilModal('notif')" title="Notifications" aria-label="Notifications">🔔<span class="topbar-bell-badge" id="notif-nav-badge">0</span></button>
  <div class="user-wrap">
    <button type="button" class="user" onclick="toggleUserMenu(event)">
      <span class="av"><?= htmlspecialchars($user_initials) ?></span>
      <?= htmlspecialchars($user_name) ?>
    </button>
    <div class="user-menu" id="user-menu">
      <a class="danger" href="<?= $CFG->wwwroot ?>/login/logout.php?sesskey=<?= sesskey() ?>" onclick="return doLogout(event)">Déconnexion</a>
    </div>
  </div>
</div>

<div class="mobile-nav-backdrop" id="mobile-nav-backdrop" onclick="closeMobileNav()"></div>

<div class="app">
<aside id="app-aside">
  <button class="on" data-v="dash" onclick="nav(this)">◧ <span>Tableau de bord</span></button>
  <button data-v="leads" onclick="nav(this)">☎ <span>Leads</span><span class="nav-badge" id="leads-nav-badge" style="display:none">0</span></button>
  <button data-v="plan" onclick="nav(this)">▦ <span>Planning &amp; Lia</span></button>
  <button data-v="team" onclick="nav(this)">◉ <span>Intervenants</span></button>
  <button data-v="coh"  onclick="nav(this)">⚑ <span><?= l3m_term_classe($USER->id) ?>s &amp; élèves</span></button>
  <button data-v="cours" onclick="nav(this)">🎓 <span>Programme</span></button>
  <button data-v="docs"  onclick="nav(this)">☰ <span>Documents</span><span class="nav-badge" id="docs-nav-badge" style="display:none">0</span></button>
  <button data-v="admin" onclick="nav(this)">🗂 <span>Administratif</span></button>
  <button data-v="param" onclick="nav(this)">⚙ <span>Paramètres</span></button>
  <button type="button" onclick="openMessageModal(0,'')">✉ <span>Messages</span><span class="nav-badge" id="msg-nav-badge" style="display:none">0</span></button>
  <div class="sep"></div>
</aside>

<main>

<!-- ===== TABLEAU DE BORD ===== -->
<div class="view on" id="v-dash">
  <h1 id="dash-greeting">Bonjour</h1>
  <p class="sub" id="dash-sub">Chargement des données…</p>
  <div class="kpis" id="kpi-grid"></div>
  <div class="cols2">
    <div class="panel">
      <h3>Aujourd'hui</h3>
      <div id="today-events"><p style="color:var(--muted);font-size:13px">Aucun événement calendrier aujourd'hui.</p></div>
      <h3 id="prochains-title" style="margin-top:18px;display:none">Prochains regroupements</h3>
      <div id="prochains-events"></div>
    </div>
    <div class="panel">
      <h3>✦ Points d'attention</h3>
      <div id="lia-alerts"><p style="color:var(--muted);font-size:13px">Analyse en cours…</p></div>
    </div>
  </div>
</div>

<!-- ===== PLANNING & LIA ===== -->
<div class="view" id="v-plan">
  <h1>Planning &amp; Lia</h1>
  <p class="sub">Le plan de l'équipe. Colonne teintée&nbsp;= cette semaine. Survolez une case pour le détail.</p>

  <div class="pg-pbar">
    <div class="pg-toggle">
      <button class="on" id="plan-tgP" onclick="setVuePlan('pers')">Vue intervenants</button>
      <button id="plan-tgC" onclick="setVuePlan('coh')">Vue <?= l3m_term_classe_lc($USER->id) ?>s</button>
    </div>
    <span style="flex:1"></span>
    <button class="btn primary" onclick="simulerClasse()">✦ Simuler une nouvelle <?= l3m_term_classe_lc($USER->id) ?></button>
  </div>

  <div class="pg-proposal" id="plan-prop">
    <b>✦ Proposition de Lia : nouvelle <?= l3m_term_classe_lc($USER->id) ?> <span id="plan-prop-letter">D</span></b>
    <ul>
      <li>Rentrée optimale : <b id="plan-prop-date"></b> (décalage <span id="plan-prop-decalage"></span> après la dernière <?= l3m_term_classe_lc($USER->id) ?>)</li>
      <li>Stages tuilés ✓ · semaines véhicule vérifiées ✓ · intervenants disponibles ✓</li>
    </ul>
    <button class="btn primary" id="plan-prop-btn">Créer la <?= l3m_term_classe_lc($USER->id) ?></button>
    <button class="btn ghost" onclick="document.getElementById('plan-prop').classList.remove('on')">Annuler</button>
  </div>

  <div class="pg-legend beta-cal" id="plan-legend">
    <b style="color:var(--ink);margin-right:2px">Type</b>
    <button type="button" class="pg-filter-btn on" data-type="" onclick="setPlanTypeFilter(null)">Tous</button>
    <button type="button" class="pg-filter-btn" data-type="distance" onclick="setPlanTypeFilter('distance')"><i class="pg-sw" style="background:var(--violet-t);border:1px solid var(--violet)"></i>Distance</button>
    <button type="button" class="pg-filter-btn" data-type="vehicule" onclick="setPlanTypeFilter('vehicule')"><i class="pg-sw" style="background:var(--orange)"></i>Véhicule</button>
    <button type="button" class="pg-filter-btn" data-type="salle" onclick="setPlanTypeFilter('salle')"><i class="pg-sw" style="background:var(--amber)"></i>Salle</button>
    <button type="button" class="pg-filter-btn" data-type="stage" onclick="setPlanTypeFilter('stage')"><i class="pg-sw" style="background:var(--sand-t);border:1px solid var(--sand)"></i>Stage</button>
    <button type="button" class="pg-filter-btn" data-type="examen" onclick="setPlanTypeFilter('examen')"><i class="pg-sw" style="background:var(--muted)"></i>Examen</button>
    <span style="margin-left:auto;color:var(--muted);font-style:italic">détail complet au survol (modalité, surcharge…)</span>
  </div>

  <div class="pg-wrap beta-cal" id="plan-grid"><p style="color:var(--muted);padding:20px;font-size:13px">Chargement du planning…</p></div>

  <div class="cols2">
    <div class="panel"><h3>Journal de Lia (automatique)</h3><div id="plan-log"></div></div>
    <div class="panel">
      <h3>À arbitrer</h3>
      <div id="plan-alerts"></div>
      <div class="kpis" id="plan-pkpis" style="margin-top:12px;grid-template-columns:repeat(3,1fr)"></div>
    </div>
  </div>

  <div class="panel" style="margin-top:12px">
    <h3>Indisponibilités à valider</h3>
    <p class="sub" style="margin:2px 0 12px">Une fois validée, une déclaration force le moteur Lia à réaffecter la charge de la semaine concernée.</p>
    <div id="dv-list"></div>
  </div>
</div>

<!-- ===== INTERVENANTS ===== -->
<div class="view" id="v-team">
  <div class="team-header">
    <div>
      <h1>Intervenants</h1>
      <p class="sub" style="margin-bottom:0">Chaque personne porte des habilitations : c'est sur elles que Lia s'appuie pour affecter les heures. Les anciens diplômes restent valides par équivalence.</p>
    </div>
    <button class="btn primary" style="flex-shrink:0;margin-top:4px" onclick="openAddFormateur()">+ Ajouter un intervenant</button>
  </div>
  <p id="team-count" style="font-size:12px;color:var(--muted);margin:10px 0 16px"></p>
  <div class="grid4" id="team-grid"></div>
</div>

<!-- ===== CLASSES & ÉLÈVES ===== -->
<div class="view" id="v-coh">
  <div class="view-coh-header">
    <h1><?= l3m_term_classe($USER->id) ?>s &amp; élèves</h1>
    <button class="btn primary" onclick="openModal()">+ Créer une <?= l3m_term_classe_lc($USER->id) ?></button>
  </div>
  <p class="sub" style="margin-bottom:0">Sous-groupes de conduite, règlement intérieur, progression et assiduité : tout ce qui déclenche une action est signalé.</p>

  <div class="class-cards" id="class-cards"></div>

  <div class="filter-bar">
    <span class="filter-lbl">Filtrer :</span>
    <button class="filter-btn on" data-filter="all" onclick="filterStudents(this)">Tous</button>
    <div id="class-filter-btns"></div>
  </div>
  <p class="students-title" id="students-title">Tous les élèves</p>
  <div class="twrap">
    <table>
      <thead>
        <tr>
          <th>Élève</th>
          <th><?= l3m_term_classe($USER->id) ?></th>
          <th>Sous-groupe</th>
          <th>Règlement int.</th>
          <th>E-learning</th>
          <th>Présence visio</th>
          <th>Note</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="students-tbody"></tbody>
    </table>
  </div>
</div>

<!-- ===== MES MODULES (Programme + Sequences) — meme contenu que
     espace-formateur.php, question du 02/10/2026 ===== -->
<div class="view" id="v-cours">
  <h1>Programme</h1>
  <?php if (l3m_is_ped_lead($USER->id) || is_siteadmin()): ?>
  <div style="text-align:right;margin-bottom:6px">
    <a href="<?= $CFG->wwwroot ?>/referentiel-editor.php" style="font-size:12.5px;font-weight:800;color:var(--<?= $brand['button_accent'] ?>-d);text-decoration:none">✏️ Éditer le programme</a>
  </div>
  <?php endif; ?>
  <div class="prog-embed-box">
    <iframe id="prog-iframe" src="<?= $CFG->wwwroot ?>/programme.php?embed=1" style="width:100%;border:none;display:block;min-height:220px" title="Programme"></iframe>
  </div>
  <script>
  window.addEventListener('message', function(e){
    if (e.data && e.data.type === 'l3m-prog-height') {
      var ifr = document.getElementById('prog-iframe');
      if (ifr) ifr.style.height = e.data.height + 'px';
    }
  });
  </script>

  <div class="section-divider"></div>

  <?php if (l3m_is_ped_lead($USER->id) || is_siteadmin()): ?>
  <div style="text-align:right;margin-bottom:6px">
    <a href="<?= $CFG->wwwroot ?>/sequences-config.php" style="font-size:12.5px;font-weight:800;color:var(--<?= $brand['button_accent'] ?>-d);text-decoration:none">✏️ Gérer les séquences</a>
  </div>
  <?php endif; ?>
  <h2 class="section-title">▶ Séquences</h2>
  <p class="sub" id="seq-sub">Chargement…</p>
  <div class="seq-list" id="seq-list"></div>
</div>

<div class="util-modal-bg" id="util-modal-bg" onclick="if(event.target===this)closeUtilModal()">
  <div class="util-modal">
    <div class="util-modal-head">
      <button type="button" id="util-modal-back" onclick="utilModalGoBack()" aria-label="Retour" title="Retour" style="display:none;background:none;border:none;font-size:14px;font-weight:700;color:var(--teal-d);cursor:pointer;padding:4px 8px 4px 0;margin-right:4px">&#8592; Retour</button>
      <h2 id="util-modal-title">Notifications</h2>
      <button type="button" class="util-modal-close" onclick="closeUtilModal()" aria-label="Fermer">&#10005;</button>
    </div>
    <div class="util-modal-body">
      <div class="dv-loading" id="util-modal-loading"><span class="dv-spinner"></span></div>
      <iframe id="util-modal-iframe" title="Notifications" src="about:blank"></iframe>
    </div>
  </div>
</div>

<div class="modal-bg" id="course-modal-bg" onclick="if(event.target===this)closeCourseIframe()">
  <div class="modal course-modal">
    <div class="course-modal-head">
      <h2 id="course-modal-title">Module</h2>
      <div class="course-modal-head-actions">
        <button type="button" id="ce-edit-link" class="btn mini ghost" onclick="ceOpenEditMode()">Activer le mode édition</button>
        <button type="button" class="course-modal-close" onclick="closeCourseIframe()" aria-label="Fermer">✕</button>
      </div>
    </div>
    <div class="course-modal-body">
      <div class="dv-left">
        <div class="dv-left-head">
          <div class="dv-breadcrumb" id="ce-breadcrumb"></div>
          <div class="dv-left-title-row">
            <span class="dv-left-title">Contenu du cours</span>
            <a class="dv-unfold" onclick="ceUnfoldAll()">Tout déplier</a>
          </div>
          <div class="dv-search">
            <span>&#128269;</span>
            <input type="text" id="ce-search-inp" placeholder="Rechercher" oninput="ceFilter(this.value)">
          </div>
        </div>
        <div class="dv-sections" id="ce-sections"></div>
      </div>
      <div class="dv-right">
        <div class="dv-mobile-back" onclick="ceMobileBack()">&#8592; Retour aux sections</div>
        <div class="dv-empty" id="ce-empty">Sélectionnez une activité dans le menu à gauche.</div>
        <div class="dv-loading" id="ce-loading" style="display:none"><span class="dv-spinner"></span></div>
        <iframe id="ce-iframe" src="" style="display:none"></iframe>
        <!-- Iframe cachee : precharge le mode edition en arriere-plan dès
             l'ouverture du cours, pour un affichage quasi instantane si
             l'utilisateur clique sur "Activer le mode edition" (voir
             cePrefetchEditMode / ceOpenEditMode). -->
        <iframe id="ce-prefetch-iframe" src="" style="display:none" aria-hidden="true" tabindex="-1"></iframe>
      </div>
    </div>
  </div>
</div>

<!-- ===== ADMINISTRATIF ===== -->
<div class="view" id="v-admin">
  <h1>Administratif</h1>
  <p class="sub">Suivi des présences et des heures de formation.</p>
  <div class="admin-tools-grid">
    <button type="button" class="admin-tool-card on" data-tool="emargement" onclick="adminToolShow(this)">
      <div class="ico">🖊</div>
      <div class="text">
        <h3>Émargement</h3>
        <p>Feuilles de présence par élève, export PDF/Excel.</p>
      </div>
      <div class="arrow">→</div>
    </button>
    <button type="button" class="admin-tool-card" data-tool="rapport" onclick="adminToolShow(this)">
      <div class="ico">⏱</div>
      <div class="text">
        <h3>Rapport d'heures</h3>
        <p>Heures de formation cumulées par élève, export PDF/Excel.</p>
      </div>
      <div class="arrow">→</div>
    </button>
  </div>
  <div class="admin-tool-frame-wrap">
    <iframe id="admin-tool-frame" title="Outil administratif"></iframe>
  </div>
</div>

<!-- ===== PARAMÈTRES ===== -->
<div class="view" id="v-param">
  <h1>Paramètres</h1>
  <p class="sub">Configuration du parcours ECSR. Les valeurs ci-dessous servent de base au moteur Lia (Phase 2).</p>
  <div class="param-grid">
    <div class="param-card pc-teal">
      <div class="pc-head"><div class="ico">⚑</div><div class="text"><h3>Structure des <?= l3m_term_classe_lc($USER->id) ?>s</h3><p>Effectifs et sous-groupes</p></div></div>
      <div class="param-row"><span class="lbl">Élèves par <?= l3m_term_classe_lc($USER->id) ?></span><span class="val"><input type="number" id="p-eleves_par_classe" value="<?= (int)$ecsr_params['eleves_par_classe'] ?>" min="1" max="20"> élèves</span></div>
      <div class="param-row"><span class="lbl">Sous-groupes</span><span class="val"><input type="number" class="sm" id="p-sous_groupes_nb" value="<?= (int)$ecsr_params['sous_groupes_nb'] ?>"> de <input type="number" class="sm" id="p-sous_groupes_taille" value="<?= (int)$ecsr_params['sous_groupes_taille'] ?>"> élèves</span></div>
      <div class="param-row"><span class="lbl">Élèves par véhicule</span><span class="val">3 <small style="color:var(--muted);font-size:10px">règle non modifiable · direction</small></span></div>
      <div class="param-row"><span class="lbl">Décalage entre rentrées</span><span class="val"><input type="number" id="p-decalage_rentrees" value="<?= (int)$ecsr_params['decalage_rentrees'] ?>"> semaines</span></div>
    </div>
    <div class="param-card pc-violet">
      <div class="pc-head"><div class="ico">▦</div><div class="text"><h3>Rythme du parcours</h3><p>Durée et alternance</p></div></div>
      <div class="param-row"><span class="lbl">Durée totale</span><span class="val"><input type="number" id="p-duree_totale" value="<?= (int)$ecsr_params['duree_totale'] ?>"> semaines</span></div>
      <div class="param-row"><span class="lbl">Alternance</span><span class="val"><input type="number" class="sm" id="p-alternance_cours" value="<?= (int)$ecsr_params['alternance_cours'] ?>"> sem. cours + <input type="number" class="sm" id="p-alternance_stage" value="<?= (int)$ecsr_params['alternance_stage'] ?>"> sem. stage</span></div>
      <div class="param-row"><span class="lbl">Regroupements véhicule</span><span class="val"><input type="text" id="p-regroupements_vehicule" value="<?= htmlspecialchars($ecsr_params['regroupements_vehicule']) ?>"></span></div>
      <div class="param-row"><span class="lbl">Regroupements salle</span><span class="val"><input type="text" id="p-regroupements_salle" value="<?= htmlspecialchars($ecsr_params['regroupements_salle']) ?>"></span></div>
    </div>
    <div class="param-card pc-orange">
      <div class="pc-head"><div class="ico">◉</div><div class="text"><h3>Capacité intervenants</h3><p>Charge horaire maximale</p></div></div>
      <div class="param-row"><span class="lbl">Salarié</span><span class="val"><input type="number" id="p-salarie_h" value="<?= (int)$ecsr_params['salarie_h'] ?>"> h/sem</span></div>
      <div class="param-row"><span class="lbl">Vacataire</span><span class="val"><input type="number" id="p-vacataire_h" value="<?= (int)$ecsr_params['vacataire_h'] ?>"> h/sem</span></div>
      <div class="param-row"><span class="lbl">Suivi hebdo par <?= l3m_term_classe_lc($USER->id) ?></span><span class="val"><input type="number" class="sm" id="p-suivi_hebdo" value="<?= (int)$ecsr_params['suivi_hebdo'] ?>"> h<br><small style="color:var(--muted);font-size:10px">tutorat</small></span></div>
      <div class="param-row"><span class="lbl">Marge dépassement</span><span class="val"><input type="number" id="p-marge_depassement" value="<?= (int)$ecsr_params['marge_depassement'] ?>"> %</span></div>
    </div>
    <div class="param-card pc-sand">
      <div class="pc-head"><div class="ico">◧</div><div class="text"><h3>Répartition ECSR</h3><p>Modalités pédagogiques</p></div></div>
      <div class="param-row"><span class="lbl">E-learning</span><span class="val"><input type="number" id="p-elearning_pct" value="<?= (int)$ecsr_params['elearning_pct'] ?>"> %</span></div>
      <div class="param-row"><span class="lbl">Classes virtuelles</span><span class="val"><input type="number" id="p-classes_virtuelles_pct" value="<?= (int)$ecsr_params['classes_virtuelles_pct'] ?>"> %</span></div>
      <div class="param-row"><span class="lbl">Présentiel</span><span class="val"><input type="number" id="p-presentiel_pct" value="<?= (int)$ecsr_params['presentiel_pct'] ?>"> %</span></div>
      <div class="param-row"><span class="lbl">Stage entreprise</span><span class="val"><input type="number" id="p-stage_entreprise_h" value="<?= (int)$ecsr_params['stage_entreprise_h'] ?>"> h</span></div>
    </div>
    <div class="param-card pc-rose">
      <div class="pc-head"><div class="ico">✦</div><div class="text"><h3>Examens &amp; jalons</h3><p>Certifications</p></div></div>
      <div class="param-row"><span class="lbl">CCP1</span><span class="val"><input type="text" class="sm" id="p-ccp1_semaine" value="<?= htmlspecialchars($ecsr_params['ccp1_semaine']) ?>"></span></div>
      <div class="param-row"><span class="lbl">Session titre</span><span class="val"><input type="text" class="sm" id="p-session_titre_semaine" value="<?= htmlspecialchars($ecsr_params['session_titre_semaine']) ?>"></span></div>
      <div class="param-row"><span class="lbl">Délai CCP1 → CCP2</span><span class="val"><input type="number" class="sm" id="p-delai_ccp1_ccp2" value="<?= (int)$ecsr_params['delai_ccp1_ccp2'] ?>"> mois max</span></div>
      <div class="param-row"><span class="lbl">Autorisation temporaire</span><span class="val" style="color:var(--ink)"><select id="p-autorisation_temporaire"><option value="1" <?= $ecsr_params['autorisation_temporaire'] ? 'selected' : '' ?>>activée</option><option value="0" <?= !$ecsr_params['autorisation_temporaire'] ? 'selected' : '' ?>>désactivée</option></select><br><small style="color:var(--muted);font-size:10px">après CCP1</small></span></div>
    </div>
    <div class="param-card pc-teal">
      <div class="pc-head"><div class="ico">✦</div><div class="text"><h3>Automatisations Lia</h3><p>Comportement du moteur IA</p></div></div>
      <div class="param-row"><span class="lbl">Relance auto documents</span><span class="val" style="color:var(--ink)"><select id="p-relance_auto_documents"><option value="1" <?= $ecsr_params['relance_auto_documents'] ? 'selected' : '' ?>>activée</option><option value="0" <?= !$ecsr_params['relance_auto_documents'] ? 'selected' : '' ?>>désactivée</option></select></span></div>
      <div class="param-row"><span class="lbl">Suivi caméra (Global)</span><span class="val" style="color:var(--ink)"><select id="p-suivi_camera_global"><option value="1" <?= $ecsr_params['suivi_camera_global'] ? 'selected' : '' ?>>activé</option><option value="0" <?= !$ecsr_params['suivi_camera_global'] ? 'selected' : '' ?>>désactivé</option></select></span></div>
      <div class="param-row"><span class="lbl">Seuil alerte caméra</span><span class="val"><input type="number" id="p-seuil_alerte_camera" value="<?= (int)$ecsr_params['seuil_alerte_camera'] ?>"> %</span></div>
      <div class="param-row"><span class="lbl">Proposition <?= l3m_term_classe_lc($USER->id) ?> D</span><span class="val"><select id="p-proposition_classe_d"><option value="manuelle" <?= $ecsr_params['proposition_classe_d'] === 'manuelle' ? 'selected' : '' ?>>manuelle</option><option value="automatique" <?= $ecsr_params['proposition_classe_d'] === 'automatique' ? 'selected' : '' ?>>automatique</option></select></span></div>
    </div>
  </div>
  <div class="pc-actions">
    <div class="lbl">Les modifications sont prises en compte au prochain calcul de Lia (Phase 2).</div>
    <button class="btn ghost" onclick="paramsCancel()">Annuler</button>
    <button class="btn primary" onclick="paramsSave()">Enregistrer</button>
  </div>

  <!-- Testeurs bêta : outil interne (gestion via script), volontairement
       retire de l'espace admin client — pas destine aux comptes admin
       type "Aby Gaye". Le JS (renderBetaTesters/add/removeBetaTester) reste
       en place, garde par une verif null sur #beta-testers-list, au cas ou
       ce bloc serait un jour reintroduit. -->
</div>

<!-- ===== DOCUMENTS ===== -->
<!-- ===== LEADS ===== -->
<div class="view" id="v-leads">
  <h1>Leads</h1>
  <p class="sub" style="margin-bottom:0">Demandes de contact reçues depuis la landing page <?= s($brand['name']) ?>.</p>
  <div class="twrap" style="margin-top:16px">
    <table class="rtable">
      <thead>
        <tr>
          <th>Contact</th>
          <th>Téléphone</th>
          <th>E-mail</th>
          <th>Situation</th>
          <th>Reçu le</th>
          <th>Statut</th>
          <th></th>
          <th></th>
        </tr>
      </thead>
      <tbody id="leads-tbody"></tbody>
    </table>
  </div>

  <h2 style="margin-top:28px">En attente d'activation</h2>
  <p class="sub" style="margin-bottom:0">Compte créé et inscrit, mais accès aux cours bloqué tant que le dossier (documents reçus par e-mail) n'est pas complet. Cliquer sur « Activer l'accès » une fois les documents en main.</p>
  <div class="twrap" style="margin-top:16px">
    <table class="rtable">
      <thead>
        <tr>
          <th>Élève</th>
          <th>E-mail</th>
          <th>Formation</th>
          <th>Inscrit·e le</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="pending-activation-tbody"></tbody>
    </table>
  </div>
</div>

<div class="modal-bg" id="modal-lead-bg" onclick="if(event.target===this)closeLeadModal()">
  <div class="modal" style="width:420px;max-width:94vw">
    <h2>Inscrire un élève</h2>
    <p class="modal-sub" id="lead-modal-sub"></p>
    <div class="fld"><label>Prénom</label><input type="text" id="lead-firstname"></div>
    <div class="fld"><label>Nom</label><input type="text" id="lead-lastname"></div>
    <div class="fld"><label>E-mail</label><input type="email" id="lead-email"></div>
    <div class="fld"><label>Formation</label><select id="lead-formation"></select></div>
    <div class="modal-actions">
      <button class="btn ghost" onclick="closeLeadModal()">Annuler</button>
      <button class="btn primary" id="lead-convert-btn" onclick="convertLead()">Inscrire</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="modal-creds-bg" onclick="if(event.target===this)closeCredsModal()">
  <div class="modal" style="width:380px;max-width:94vw">
    <h2>Identifiants de connexion</h2>
    <p class="modal-sub" id="creds-modal-sub"></p>
    <div class="fld"><label>Identifiant</label><input type="text" id="creds-username" readonly></div>
    <div class="fld" id="creds-pw-row" style="display:none">
      <label>Mot de passe (à transmettre maintenant, non récupérable ensuite)</label>
      <input type="text" id="creds-password" readonly>
    </div>
    <p class="sub" style="font-size:11.5px;margin:4px 0 0">
      Moodle ne stocke jamais le mot de passe en clair : on ne peut pas afficher l'actuel, seulement en définir un nouveau.
    </p>
    <div class="modal-actions">
      <button class="btn ghost" onclick="closeCredsModal()">Fermer</button>
      <button class="btn primary" id="creds-reset-btn" onclick="resetCredsPassword()">Réinitialiser le mot de passe</button>
    </div>
  </div>
</div>

<div class="view" id="v-docs">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
    <div>
      <h1 style="margin:0">Documents élèves</h1>
      <p class="sub" style="margin:4px 0 0">Envoyez des documents administratifs aux élèves : contrats, conventions, attestations.</p>
    </div>
  </div>

  <div class="docs-split" style="margin-top:16px">
    <!-- Liste élèves -->
    <div class="docs-panel">
      <input class="docs-search" type="text" placeholder="Rechercher un élève…" oninput="docsFilterStudents(this.value)" id="docs-search">
      <div class="docs-slist" id="docs-slist"></div>
    </div>

    <!-- Zone droite -->
    <div class="docs-right" id="docs-right">
      <div class="docs-empty-hint" id="docs-hint">Sélectionnez un élève pour gérer ses documents.</div>
    </div>
  </div>

  <!-- Formulaire ajout / édition (caché par défaut) -->
  <div id="docs-form-modal" style="display:none;position:fixed;inset:0;background:rgba(36,51,60,.35);z-index:200;align-items:center;justify-content:center">
    <div style="background:var(--card);border-radius:14px;padding:24px;width:560px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:0 8px 40px rgba(36,51,60,.18)">
      <h2 style="margin:0 0 4px;font-size:18px" id="docs-form-title">Nouveau document</h2>
      <p style="margin:0 0 16px;font-size:12px;color:var(--muted)" id="docs-form-eleve"></p>
      <input type="hidden" id="df-docid" value="0">
      <input type="hidden" id="df-userid" value="0">
      <div class="docs-form-grid">
        <div class="fld full">
          <label>Titre du document <span style="color:var(--red)">*</span></label>
          <input type="text" id="df-title" placeholder="ex : Convention de stage · période 3">
        </div>
        <div class="fld full">
          <label>Sous-titre <small style="color:var(--muted)">(optionnel)</small></label>
          <input type="text" id="df-subtitle" placeholder="ex : Auto-école du Parc · 23 → 27 nov">
        </div>
        <div class="fld">
          <label>Type de statut</label>
          <select id="df-stype" onchange="docsStatusPreset()">
            <option value="signed">✓ Signé</option>
            <option value="validated">✓ Validé</option>
            <option value="pending" selected>⚠ À signer / deadline</option>
            <option value="future">À venir</option>
          </select>
        </div>
        <div class="fld">
          <label>Label du statut</label>
          <input type="text" id="df-slabel" placeholder="ex : Avant ven 20 nov">
        </div>
        <div class="fld">
          <label>Action disponible</label>
          <select id="df-atype" onchange="docsToggleUrl()">
            <option value="none">Aucune</option>
            <option value="download">Télécharger</option>
            <option value="sign">Signer</option>
            <option value="view">Consulter</option>
          </select>
        </div>
        <div class="fld full" id="df-url-row" style="display:none">
          <label>Fichier</label>
          <input type="file" id="df-file">
          <div id="df-file-current" style="font-size:11.5px;color:var(--muted);margin-top:4px"></div>
        </div>
        <div class="fld full" style="display:flex;align-items:center;gap:8px;background:var(--red-t);border-radius:8px;padding:10px 12px;margin-top:4px">
          <input type="checkbox" id="df-blocking" style="width:14px;height:14px;accent-color:var(--red);flex-shrink:0" onchange="docsToggleBlockingMsg()">
          <label for="df-blocking" style="cursor:pointer;font-weight:600;color:var(--red);margin:0">Document bloquant</label>
        </div>
        <div class="fld full" id="df-bmsg-row" style="display:none">
          <label>Message d'alerte (affiché en bannière rouge)</label>
          <input type="text" id="df-bmsg" placeholder="ex : Sans signature avant ven 20 nov, votre départ en stage est suspendu.">
        </div>
      </div>
      <div style="display:flex;gap:8px;margin-top:18px;justify-content:flex-end">
        <button class="btn ghost" onclick="docsCloseForm()">Annuler</button>
        <button class="btn primary" id="docs-save-btn" onclick="docsSaveForm()">Enregistrer</button>
      </div>
    </div>
  </div>
</div>

</main>
</div>

<!-- MODAL Créer une classe -->
<div class="modal-bg" id="modal-bg" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <h2>Nouvelle <?= l3m_term_classe_lc($USER->id) ?></h2>
    <p class="modal-sub">Une <?= l3m_term_classe_lc($USER->id) ?> est une promotion d'élèves suivant le même parcours.</p>
    <div class="fld">
      <label>Nom de la <?= l3m_term_classe_lc($USER->id) ?></label>
      <input type="text" id="modal-name" placeholder="ex : Classe A · Promotion Automne 2026" maxlength="100">
    </div>
    <div class="fld">
      <label>Date de rentrée</label>
      <input type="date" id="modal-date">
    </div>
    <div class="modal-actions">
      <button class="btn ghost" onclick="closeModal()">Annuler</button>
      <button class="btn primary" id="modal-save" onclick="saveClass()">Créer la <?= l3m_term_classe_lc($USER->id) ?></button>
    </div>
  </div>
</div>


<!-- MODAL Ajouter / Modifier intervenant -->
<div class="modal-bg" id="modal-form-bg" onclick="if(event.target===this)closeFormModal()">
  <div class="modal" style="width:480px;max-width:94vw;max-height:90vh;overflow-y:auto">
    <h2 id="fmod-title">Modifier</h2>
    <p class="modal-sub" id="fmod-sub"></p>
    <div id="fmod-new-fields">
      <div class="fld"><label>Prénom</label><input type="text" id="fmod-firstname" placeholder="Prénom"></div>
      <div class="fld"><label>Nom</label><input type="text" id="fmod-lastname" placeholder="Nom de famille"></div>
      <div class="fld"><label>Email</label><input type="email" id="fmod-email" placeholder="prenom.nom@domaine.fr"></div>
    </div>
    <div class="fld">
      <label>Rôle / titre</label>
      <input type="text" id="fmod-role" placeholder="ex : Référent pédagogique, Formateur &amp; moniteur">
    </div>
    <div class="fld">
      <label>Secteur</label>
      <input type="text" id="fmod-secteur" placeholder="ex : Nord, Sud, Centre-ville…">
      <p style="font-size:11.5px;color:var(--muted);margin:4px 0 0">Lia priorise un formateur du même secteur que la <?= l3m_term_classe_lc($USER->id) ?> pour les séances véhicule/salle.</p>
    </div>
    <div class="fld" id="fmod-pedlead-field">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;text-transform:none;letter-spacing:0;font-weight:500;font-size:13px;color:var(--ink)">
        <input type="checkbox" id="fmod-pedlead"> Responsable pédagogique
      </label>
      <p style="font-size:11.5px;color:var(--muted);margin:4px 0 0">Accède à une section "Pédagogie" dans son espace formateur pour gérer l'accès des autres intervenants aux modules.</p>
    </div>
    <div class="fld">
      <label>Statut contractuel</label>
      <div style="display:flex;gap:18px;margin-top:5px">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:var(--ink);text-transform:none;letter-spacing:0;font-weight:500">
          <input type="radio" name="fmod-type" value="salarie" checked> Salarié · 28 h/sem
        </label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:var(--ink);text-transform:none;letter-spacing:0;font-weight:500">
          <input type="radio" name="fmod-type" value="vacataire"> Vacataire · 20 h/sem
        </label>
      </div>
    </div>
    <div class="fld" id="fmod-client-field">
      <label>Client</label>
      <div style="display:flex;gap:18px;margin-top:5px">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:var(--ink);text-transform:none;letter-spacing:0;font-weight:500">
          <input type="radio" name="fmod-client" value="lba" checked> LBA
        </label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:var(--ink);text-transform:none;letter-spacing:0;font-weight:500">
          <input type="radio" name="fmod-client" value="saran"> Saran
        </label>
      </div>
    </div>
    <div class="fld">
      <label>Cohortes suivies</label>
      <div id="fmod-cohorts-list" style="display:flex;flex-wrap:wrap;gap:6px;max-height:140px;overflow-y:auto;border:1px solid var(--line);border-radius:8px;padding:8px"></div>
      <p style="font-size:11.5px;color:var(--muted);margin:4px 0 0">Détermine quels élèves apparaissent dans son annuaire de messagerie. Aucune cohorte cochée = tous les élèves du client (par défaut).</p>
    </div>
    <div id="fmod-habs-section" class="fld">
      <label>Habilitations</label>
      <div id="fmod-habs-list" style="display:flex;flex-wrap:wrap;gap:6px;min-height:26px;margin-bottom:8px"></div>
      <div style="display:flex;gap:6px;align-items:center">
        <input type="text" id="fmod-hab-text" placeholder="Ajouter une habilitation…" style="flex:1" onkeydown="if(event.key==='Enter'){event.preventDefault();addHabFromInput()}">
        <div id="fmod-color-btns" style="display:flex;gap:5px;align-items:center">
          <button class="hcb active" data-color="teal"   onclick="selectHabColor(this)" title="Teal"   type="button"></button>
          <button class="hcb"        data-color="orange" onclick="selectHabColor(this)" title="Orange" type="button"></button>
          <button class="hcb"        data-color="violet" onclick="selectHabColor(this)" title="Violet" type="button"></button>
        </div>
        <button class="btn primary mini" type="button" onclick="addHabFromInput()">+ Ajouter</button>
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn ghost" onclick="closeFormModal()">Fermer</button>
      <button class="btn primary" id="fmod-save-btn" onclick="saveFormateurModal()">Enregistrer</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="cred-modal-bg">
  <div class="modal" style="width:380px">
    <h3 style="margin:0 0 6px" id="cred-title">✅ Compte créé</h3>
    <p style="color:var(--muted);font-size:13px;margin:0 0 16px">
      Communiquez ces identifiants à la personne concernée. Le mot de passe ne sera plus affiché ensuite.
    </p>
    <div style="background:var(--bg);border-radius:10px;padding:14px 16px;margin-bottom:16px">
      <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em">Identifiant</div>
      <div id="cred-username" style="font-size:15px;font-weight:700;margin-bottom:10px;word-break:break-all"></div>
      <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em">Mot de passe</div>
      <div id="cred-password" style="font-size:15px;font-weight:700;word-break:break-all"></div>
    </div>
    <div class="modal-actions">
      <button class="btn ghost" onclick="copyCredentials()">📋 Copier</button>
      <button class="btn primary" onclick="closeCredentialsModal()">Terminé</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="msg-modal-bg" onclick="if(event.target===this)closeMessageModal()">
  <div class="modal msg-modal">
    <button type="button" class="msg-modal-close" onclick="closeMessageModal()" aria-label="Fermer">✕</button>
    <aside class="msg-list-pane">
      <div class="msg-list-head">
        <h2>Messages</h2>
        <div class="msg-list-sub" id="msg-list-count">—</div>
      </div>
      <div class="msg-search">
        <span class="msg-search-ico">&#128269;</span>
        <input type="text" id="msg-search-input" placeholder="Rechercher un contact..." oninput="msgFilterList()">
      </div>
      <button type="button" class="btn mini ghost" id="msg-new-btn" style="margin:0 16px 8px;width:calc(100% - 32px);justify-content:center" onclick="msgToggleDirectory()">+ Nouveau message</button>
      <div class="msg-contacts" id="msg-contacts"></div>
    </aside>
    <section class="msg-thread-pane">
      <div class="msg-thread-back" onclick="msgMobileBack()">&#8592; Retour aux contacts</div>
      <div class="msg-thread-head" id="msg-thread-head" style="display:none">
        <div class="msg-thread-who">
          <span class="msg-thread-avatar" id="msg-thread-avatar"></span>
          <div>
            <div class="msg-thread-name" id="msg-thread-name"></div>
            <div class="msg-thread-sub" id="msg-thread-sub"></div>
          </div>
        </div>
        <div class="msg-thread-actions">
          <button type="button" class="btn mini gold" id="msg-thread-traite" onclick="msgToggleTraite()">Marquer traité</button>
        </div>
      </div>
      <div class="msg-thread-body" id="msg-thread-body"></div>
      <div class="msg-thread-empty" id="msg-thread-empty">Sélectionnez une conversation</div>
      <div class="msg-thread-input" id="msg-thread-input" style="display:none">
        <input type="text" id="msg-input-text" placeholder="Écrire un message..." onkeydown="if(event.key==='Enter'){msgSendCurrent();}">
        <button type="button" class="msg-send-btn" onclick="msgSendCurrent()" aria-label="Envoyer">&#10148;</button>
      </div>
    </section>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
var DATA = <?= $admin_data ?>;
// Terminologie beta "classe" -> "cohorte" (l3m_branding.php) — texte affiche
// uniquement, les noms reels de classe restent inchanges en base.
var TERM_CLASSE = <?= json_encode(l3m_term_classe($USER->id)) ?>;
var TERM_CLASSE_LC = <?= json_encode(l3m_term_classe_lc($USER->id)) ?>;
// Calendrier "Planning & Lia" simplifie (generalise a tous les comptes) :
// en-tete sur une ligne, heures semaine courante sur la fiche formateur,
// surcharge en soulignement rouge plutot qu'en fond de case.
var IS_BETA_CAL = true;
// Reecrit l'affichage d'un nom de classe stocke tel quel en base
// ("Classe X ...") en "<terme> X ..." cote testeur beta, sans jamais
// toucher la donnee reelle (utile pour ne pas casser le filtrage/regex).
function dispCohName(name) {
  if (!name) return name;
  name = name.replace(/^Classe\b/, TERM_CLASSE);
  // Retire le tiret separateur entre la lettre et la date ("Cohorte B - Juillet
  // 2026" / "Cohorte B- Septembre 2026" -> "Cohorte B Juillet 2026") — la donnee
  // brute en base n'est jamais touchee (regex d'extraction de lettre ailleurs).
  name = name.replace(/^(Cohorte\s+\S+)\s*[-–—]\s*/, '$1 ');
  return name;
}
var SESSKEY = '<?= $sesskey ?>';
var WWWROOT = '<?= $CFG->wwwroot ?>';
var ADMINURL = '<?= $CFG->wwwroot ?>/admin-lba.php';
var COLORS = ['#2E9488','#7C6FDD','#F2A03D','#8B95A0','#D64545','#3EADA0'];
var activeFilter = 'all';

/* ===== NAVIGATION ===== */
/* ─────────────────────────────────────────
   MES MODULES — Sequences (repertoire de modules du referentiel), meme
   logique que espace-formateur.php (question du 02/10/2026). Pas de bouton
   "Commencer la sequence" ici (PROG_IS_STUDENT=false) — juste le
   deverrouillage manuel (Responsable pedagogique) et le lien classe
   virtuelle.
───────────────────────────────────────── */
var _progSeqOpen = null;
var PROG_IS_STUDENT = false;
var PLAYERURL_PROG = WWWROOT + '/sequence-player.php';

function progSeqOpen(seqid) {
  _progSeqOpen = seqid;
  renderProgSequences();
}

// Clic sur un module -> renvoie vers lui dans Programme (plus haut sur la
// page) : on fait naviguer l'iframe jusqu'au bon noeud et on scrolle jusqu'a
// elle, la carte visee y est mise en valeur (voir OPEN_TARGET/HIGHLIGHT_ID
// et .card-highlight dans programme.php). Question du 06/10/2026.
function openInProgramme(target, highlight) {
  var ifr = document.getElementById('prog-iframe');
  if (!ifr) return;
  ifr.src = WWWROOT + '/programme.php?embed=1&open=' + encodeURIComponent(target) + '&highlight=' + encodeURIComponent(highlight);
  ifr.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function setSeqOverride(sel, dbid) {
  var value = sel.value;
  var fd = new FormData();
  fd.append('sesskey', SESSKEY);
  fd.append('action', 'set_override');
  fd.append('dbid', dbid);
  fd.append('value', value);
  sel.disabled = true;
  fetch(WWWROOT + '/programme.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r) { return r.json(); })
    .then(function(r) {
      sel.disabled = false;
      if (r.ok) toast('Mis à jour'); else alert(r.msg || 'Erreur');
    })
    .catch(function() { sel.disabled = false; alert('Erreur réseau'); });
}

function setCvModuleDone(seqid, ref, checked) {
  var fd = new FormData();
  fd.append('sesskey', SESSKEY);
  fd.append('action', 'set_cv_module_done');
  fd.append('seqid', seqid);
  fd.append('ref', ref);
  fd.append('done', checked ? 1 : 0);
  fetch(WWWROOT + '/sequences-config.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r) { return r.json(); })
    .then(function(r) {
      if (r.ok) toast('Mis à jour'); else alert(r.msg || 'Erreur');
    })
    .catch(function() { alert('Erreur réseau'); });
}

function renderProgSequences() {
  var seqs = DATA.prog_sequences || [];
  var sub = document.getElementById('seq-sub');
  var list = document.getElementById('seq-list');
  list.className = 'seqp-grid';
  if (!seqs.length) {
    sub.textContent = 'Aucune séquence pour l\'instant.';
    list.innerHTML = '';
    return;
  }

  var open = _progSeqOpen !== null ? seqs.filter(function(s) { return s.id === _progSeqOpen; })[0] : null;

  if (open) {
    sub.textContent = '';
    var mods = open.modules || [];
    var cvChecklist = DATA.is_ped_lead_viewer && open.type === 'classe_virtuelle';
    var doneSet = {};
    (open.cv_done_modules || []).forEach(function(r) { doneSet[r] = true; });
    var modsHtml = mods.length
      ? mods.map(function(m) {
          var cb = cvChecklist
            ? '<input type="checkbox" onclick="event.stopPropagation()" onchange="setCvModuleDone(' + open.id + ',\'' + m.ref + '\',this.checked)"' + (doneSet[m.ref] ? ' checked' : '') + ' style="margin-right:10px;flex-shrink:0">'
            : '';
          return '<div class="seqp-mod-card" onclick="openInProgramme(\'' + m.target + '\',\'' + m.highlight + '\')">' + cb + esc(m.nom) + '</div>';
        }).join('')
      : '<p class="sub" style="margin:0">Aucun module pour l\'instant.</p>';
    var actionHtml = '';
    if (open.action && open.action.kind === 'cv') {
      actionHtml = '<a class="btn mini primary" href="' + esc(open.action.url) + '" target="_blank" rel="noopener">🔴 Rejoindre la classe virtuelle</a>';
    }
    var overrideHtml = '';
    if (DATA.is_ped_lead_viewer && open.action && open.action.kind === 'etape' && open.action.dbid) {
      var ov = open.action.override || 'auto';
      overrideHtml = '<div style="margin-top:12px;margin-bottom:14px">' +
        '<label class="seqp-ovr-label">Déblocage manuel (Responsable pédagogique)</label>' +
        '<select class="seqp-ovr-select" onchange="setSeqOverride(this,\'' + open.action.dbid + '\')">' +
          '<option value="auto"' + (ov === 'auto' ? ' selected' : '') + '>Auto (selon progression de l\'élève)</option>' +
          '<option value="open"' + (ov === 'open' ? ' selected' : '') + '>🔓 Forcer disponible</option>' +
          '<option value="locked"' + (ov === 'locked' ? ' selected' : '') + '>🔒 Forcer verrouillé</option>' +
        '</select>' +
      '</div>';
    } else if (cvChecklist) {
      overrideHtml = '<p class="sub" style="margin-top:12px;margin-bottom:0">☑ Cochez ci-dessous les modules effectivement abordés pendant cette séance. Ça débloque leur support s\'ils sont partagés avec une autre séquence.</p>';
    }
    list.className = '';
    list.innerHTML =
      '<button type="button" class="seqp-back" onclick="progSeqOpen(null)">&larr; Retour aux séquences</button>' +
      '<div class="seqp-detail-id">Séquence ' + open.order + '</div>' +
      '<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:' + (overrideHtml ? '0' : '14px') + '">' +
        '<h3 class="seqp-detail-title" style="margin:0">' + esc(open.titre) + '</h3>' +
        actionHtml +
      '</div>' +
      overrideHtml +
      '<div class="seqp-mod-grid">' + modsHtml + '</div>';
    return;
  }

  sub.textContent = seqs.length + ' séquence' + (seqs.length > 1 ? 's' : '');
  list.innerHTML = seqs.map(function(seq) {
    var mods = seq.modules || [];
    return '<div class="seqp-card" onclick="progSeqOpen(' + seq.id + ')">' +
      '<div class="seqp-chevron">›</div>' +
      '<div class="seqp-id">Séquence ' + seq.order + '</div>' +
      '<div class="seqp-title">' + esc(seq.titre) + '</div>' +
      '<div class="seqp-bar-track"><div class="seqp-bar-fill" style="width:0%"></div></div>' +
      '<div class="seqp-meta">' + mods.length + ' module' + (mods.length > 1 ? 's' : '') + '</div>' +
    '</div>';
  }).join('');
}

function nav(btn) {
  document.querySelectorAll('aside button').forEach(b => b.classList.remove('on'));
  document.querySelectorAll('.view').forEach(v => v.classList.remove('on'));
  btn.classList.add('on');
  var vid = 'v-' + btn.dataset.v;
  document.getElementById(vid).classList.add('on');
  if (vid === 'v-plan') initPlan();
  if (vid === 'v-cours') renderProgSequences();
  if (vid === 'v-leads') { renderLeads(); renderPendingActivations(); }
  if (vid === 'v-admin' && !document.getElementById('admin-tool-frame').src) {
    adminToolShow(document.querySelector('#v-admin .admin-tool-card.on'));
  }
  closeMobileNav();
}

// ── Menu drawer (navigation mobile) : voir espace-eleve.php, meme
// mecanisme — question du 12/10/2026.
function toggleMobileNav() {
  var isOpen = document.getElementById('app-aside').classList.contains('on');
  if (isOpen) closeMobileNav(); else openMobileNav();
}
function openMobileNav() {
  document.getElementById('app-aside').classList.add('on');
  document.getElementById('mobile-nav-backdrop').classList.add('on');
}
function closeMobileNav() {
  document.getElementById('app-aside').classList.remove('on');
  document.getElementById('mobile-nav-backdrop').classList.remove('on');
}

/* Bouton "Voir les élèves" d'une alerte Lia : applique le même filtre que
   celui utilisé pour calculer l'alerte (sinon on atterrit sur la liste
   complète des élèves, incohérente avec le nombre annoncé dans l'alerte). */
function goToAlert(target, filter) {
  if (filter) {
    activeFilter = filter;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('on'));
    document.getElementById('students-title').textContent = filter === 'inactive'
      ? 'Élèves inactifs depuis 7 jours'
      : (filter === 'low' ? 'Élèves, progression < 20 %' : 'Tous les élèves');
    renderClassCards();
    renderStudentsTable();
  }
  nav(document.querySelector('[data-v=' + target + ']'));
}

/* ===== ADMINISTRATIF (émargement / rapport d'heures) ===== */
var ADMIN_TOOL_URLS = {
  emargement: '<?= $CFG->wwwroot ?>/emargement.php?embed=1',
  rapport:    '<?= $CFG->wwwroot ?>/rapport_heures.php?embed=1',
};
function adminToolShow(btn) {
  document.querySelectorAll('#v-admin .admin-tool-card').forEach(function(b) { b.classList.remove('on'); });
  btn.classList.add('on');
  document.getElementById('admin-tool-frame').src = ADMIN_TOOL_URLS[btn.dataset.tool];
}

/* ===== TOAST ===== */
var toastT;
function toast(msg, duration) {
  var t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('on');
  clearTimeout(toastT); toastT = setTimeout(() => t.classList.remove('on'), duration || 2800);
}

/* ===== POST helper ===== */
function apiPost(params) {
  var body = new URLSearchParams(params);
  body.append('sesskey', SESSKEY);
  return fetch(ADMINURL, { method: 'POST', body: body })
    .then(r => r.json());
}

/* ===== COMPTEUR NOTIFICATIONS (icone topbar) ===== */
function loadHeaderCounts() {
  apiPost({ action: 'header_counts' }).then(function(r) {
    if (!r.ok) return;
    var badge = document.getElementById('notif-nav-badge');
    badge.textContent = r.unread_notifications;
    badge.style.display = r.unread_notifications > 0 ? 'flex' : 'none';
  });
}

/* ===== NOTIFICATIONS (modal iframe, meme mecanisme que espace-formateur.php/espace-eleve.php) ===== */
var _utilPollT = null;
var _utilModalNavCount = 0;
var _utilModalGoingBack = false;
var UTIL_MODAL_URLS = {
  notif: { url: WWWROOT + '/message/output/popup/notifications.php', title: 'Notifications', match: '/message/output/popup/notifications.php' },
};

function utilModalInject() {
  var iframe = document.getElementById('util-modal-iframe');
  try {
    var doc = iframe.contentDocument;
    if (!doc || !doc.head) return false;
    if (doc.getElementById('l3m-util-style')) return true;
    var style = doc.createElement('style');
    style.id = 'l3m-util-style';
    <?php
      $accent   = $brand[$brand['button_accent']];
      $accent_d = $brand[$brand['button_accent'] . '_d'];
      $accent_t = $brand[$brand['button_accent'] . '_t'];
    ?>
    style.textContent = [
      '#page-header, #main-header, .navbar, #page-footer, .main-footer, footer,',
      '.breadcrumb-nav, .breadcrumb, #nav-drawer, .drawer-toggle, .skiplinks,',
      '#fsmod-header, .secondary-navigation, .tertiary-navigation, .activity-navigation { display:none !important; }',
      'html, body { background:#fff !important; }',
      '#page, #page.container-fluid, #region-main, #region-main-box { padding:0 !important; margin:0 !important; max-width:none !important; }',
      '.btn-primary, .bg-primary { background-color:<?= $accent ?> !important; border-color:<?= $accent ?> !important; }',
      '.btn-primary:hover { background-color:<?= $accent_d ?> !important; border-color:<?= $accent_d ?> !important; }',
      '.btn-secondary, .btn.btn-secondary { background:#fff !important; border-color:<?= $accent ?> !important; color:<?= $accent ?> !important; }',
      '.text-primary { color:<?= $accent ?> !important; }',
      '.badge-primary, .badge.bg-primary { background-color:<?= $brand['orange'] ?> !important; }',
      '.list-group-item.active, .list-group-item:focus, .list-group-item:hover { background-color:<?= $accent_t ?> !important; color:<?= $accent ?> !important; }',
      '#region-main h2 { display:none !important; }',
      '.notification-area { border:none !important; font-family:inherit !important; }',
      '.notification-area .control-area { background:#fff !important; }',
      '.notification-area .content-item-container.notification { position:relative; display:block !important; padding:12px 16px !important; border-bottom:1px solid #E4E1D6 !important; transition:background .12s; }',
      '.notification-area .content-item-container.notification:hover { background:#F7F6F1 !important; }',
      '.notification-area .content-item-container.notification, .notification-area .content-item-container.notification .context-link, .notification-area .content-item-container.notification a { -webkit-tap-highlight-color:transparent !important; }',
      '.notification-area .content-item-container.notification:focus, .notification-area .content-item-container.notification:active, .notification-area .content-item-container.notification .context-link:focus, .notification-area .content-item-container.notification .context-link:active, .notification-area .content-item-container.notification a:focus, .notification-area .content-item-container.notification a:active { background:<?= $accent_t ?> !important; outline:none !important; box-shadow:none !important; color:inherit !important; }',
      '.notification-area .content-item-container.notification a, .notification-area .content-item-container.notification a:link, .notification-area .content-item-container.notification a:visited, .notification-area .content-item-container.notification .context-link { color:inherit !important; text-decoration:none !important; display:block !important; }',
      '.notification-area .content-item-container.notification.unread { background:<?= $accent_t ?> !important; }',
      '.notification-area .content-item-container.notification.unread::before { content:""; position:absolute; left:0; top:0; bottom:0; width:3px; background:<?= $accent ?>; }',
      '.notification-area .notification-message { font-size:13px !important; color:#24333C !important; line-height:1.4 !important; }',
      '.notification-area .content-item-container.notification.unread .notification-message { font-weight:700 !important; }',
      '.notification-area .content-item-container .timestamp { font-size:10.5px !important; color:#24333C !important; margin-top:4px !important; }',
      '.notification-area .content-item-container .view-more { display:none !important; }',
      '.notification-area .empty-text { color:#68767E !important; font-size:13px !important; font-style:italic !important; }',
      '.notification-area .content-area .header { background:#fff !important; border-bottom:1px solid #E4E1D6 !important; }',
      '.notification-area .content-area .header .subject-container { font-size:14.5px !important; font-weight:800 !important; color:#24333C !important; }',
      '.notification-area .content-area .header .timestamp { font-size:11.5px !important; color:#68767E !important; }',
      '.notification-area .content-area .toggle-mode .btn-link { color:<?= $accent ?> !important; font-weight:600 !important; background:transparent !important; -webkit-tap-highlight-color:transparent !important; }',
      '.notification-area .content-area .toggle-mode .btn-link:hover, .notification-area .content-area .toggle-mode .btn-link:focus, .notification-area .content-area .toggle-mode .btn-link:active { color:<?= $accent_d ?> !important; background:transparent !important; outline:none !important; box-shadow:none !important; text-decoration:none !important; }',
      '.notification-area .content-area .footer a { color:<?= $accent ?> !important; font-weight:700 !important; text-decoration:none !important; }',
      '.notification-area .content-area [data-region="content"] a { color:<?= $accent ?> !important; }',
      '.notification-area .content-area [data-region="content"] a:hover { color:<?= $accent_d ?> !important; }',
      'html { scrollbar-color: <?= $accent ?> #f0f0f0 !important; }',
      '::-webkit-scrollbar { width: 10px; }',
      '::-webkit-scrollbar-track { background: #f0f0f0; }',
      '::-webkit-scrollbar-thumb { background: <?= $accent ?> !important; border-radius: 6px; }',
      '::-webkit-scrollbar-thumb:hover { background: <?= $accent_d ?> !important; }',
    ].join('\n');
    doc.head.appendChild(style);
    try {
      doc.defaultView.addEventListener('pagehide', function() {
        iframe.classList.remove('ready');
      });
    } catch (e2) {}
    return true;
  } catch (e) { return false; }
}

function openUtilModal(kind) {
  var cfg = UTIL_MODAL_URLS[kind];
  if (!cfg) return;
  document.getElementById('util-modal-title').textContent = cfg.title;
  var iframe  = document.getElementById('util-modal-iframe');
  var loading = document.getElementById('util-modal-loading');
  iframe.classList.remove('ready');
  loading.style.display = 'flex';
  clearInterval(_utilPollT);
  _utilModalNavCount = 0;
  document.getElementById('util-modal-back').style.display = 'none';

  var attempts = 0;
  _utilPollT = setInterval(function() {
    attempts++;
    var onRealPage = false;
    try { onRealPage = iframe.contentWindow.location.href.indexOf(cfg.match) !== -1; } catch (e) {}
    var injected = utilModalInject();
    if ((onRealPage && injected) || attempts > 250) {
      clearInterval(_utilPollT);
      iframe.classList.add('ready');
      loading.style.display = 'none';
    }
  }, 20);
  iframe.onload = function() {
    utilModalInject();
    iframe.classList.add('ready');
    loading.style.display = 'none';
    if (_utilModalGoingBack) {
      _utilModalGoingBack = false;
      _utilModalNavCount--;
    } else {
      _utilModalNavCount++;
    }
    document.getElementById('util-modal-back').style.display = _utilModalNavCount > 1 ? '' : 'none';
  };

  iframe.src = cfg.url;
  document.getElementById('util-modal-bg').classList.add('on');
}
function utilModalGoBack() {
  if (_utilModalNavCount <= 1) return;
  var iframe = document.getElementById('util-modal-iframe');
  _utilModalGoingBack = true;
  try { iframe.contentWindow.history.back(); } catch (e) { _utilModalGoingBack = false; }
}
function closeUtilModal() {
  clearInterval(_utilPollT);
  document.getElementById('util-modal-bg').classList.remove('on');
  var iframe = document.getElementById('util-modal-iframe');
  iframe.classList.remove('ready');
  iframe.src = 'about:blank';
  document.getElementById('util-modal-loading').style.display = 'none';
  document.getElementById('util-modal-back').style.display = 'none';
  loadHeaderCounts();
}

/* ===== MESSAGERIE (modal, UI custom, vraie messagerie Moodle en arrière-plan) ===== */
var _msg = {
  list: [],
  filter: '',
  activeConvId: 0,
  activeUserId: 0,
  pendingUserId: 0,
  pendingName: '',
  mode: 'conversations' // 'conversations' | 'directory'
};
var _msgAvColors = ['av-c0', 'av-c1', 'av-c2'];

function msgAvatarClass(userid) {
  return _msgAvColors[Math.abs(userid) % _msgAvColors.length];
}
function msgTimeShort(ts) {
  if (!ts) return '';
  var d = new Date(ts * 1000);
  var now = new Date();
  var sameDay = d.toDateString() === now.toDateString();
  if (sameDay) return d.getHours().toString().padStart(2,'0') + ':' + d.getMinutes().toString().padStart(2,'0');
  var yest = new Date(now); yest.setDate(now.getDate() - 1);
  if (d.toDateString() === yest.toDateString()) return 'hier';
  var M = ['janv.','févr.','mars','avr.','mai','juin','juil.','août','sept.','oct.','nov.','déc.'];
  return d.getDate() + ' ' + M[d.getMonth()];
}
function msgDayLabel(ts) {
  var d = new Date(ts * 1000);
  var M = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
  return d.getDate() + ' ' + M[d.getMonth()];
}

function openMessageModal(uid, nom) {
  document.getElementById('msg-modal-bg').classList.add('open');
  var modal = document.querySelector('.msg-modal');
  if (modal) modal.classList.remove('msg-mob-thread'); // repartir sur la liste des contacts en mobile
  _msg.pendingUserId = uid;
  _msg.pendingName   = nom;
  msgLoadList();
}
function closeMessageModal() {
  document.getElementById('msg-modal-bg').classList.remove('open');
  refreshMsgNavBadge();
}
function msgMobileBack() {
  var modal = document.querySelector('.msg-modal');
  if (modal) modal.classList.remove('msg-mob-thread');
}

function msgLoadList() {
  apiPost({ action: 'msg_list' }).then(function(r) {
    if (!r.ok) return;
    _msg.list = r.conversations;
    var nb = _msg.list.length;
    var unread = _msg.list.reduce(function(n,c){ return n + (c.unreadcount>0?1:0); }, 0);
    document.getElementById('msg-list-count').textContent =
      nb + ' conversation' + (nb>1?'s':'') + (unread ? ' · ' + unread + ' non lue' + (unread>1?'s':'') : '');
    msgRenderContacts();

    if (_msg.pendingUserId) {
      var uid = _msg.pendingUserId, nom = _msg.pendingName;
      _msg.pendingUserId = 0;
      var existing = _msg.list.find(function(c){ return c.userid === uid; });
      if (existing) {
        msgSelectContact(existing.convid, existing.userid, existing.name, existing.cls, existing.isonline);
      } else {
        msgSelectContact(0, uid, nom, '', false);
      }
    }
  });
}

function msgFilterList() {
  _msg.filter = document.getElementById('msg-search-input').value.trim().toLowerCase();
  msgRenderContacts();
}

// Supprime la conversation ET le contact (voir action 'msg_delete_conversation'
// cote PHP) : une suppression unique plutot que deux boutons distincts,
// puisque les deux sont crees ensemble au premier message envoye (question
// du 09/10/2026, icone de suppression par contact).
function msgDeleteConversation(convid, uid, nom) {
  if (!confirm('Supprimer la conversation avec ' + nom + ' ? Cette action est irréversible.')) return;
  apiPost({ action: 'msg_delete_conversation', convid: convid, userid: uid })
    .then(function(d) {
      if (!d.ok) { toast('Erreur : ' + (d.msg || '?')); return; }
      _msg.list = _msg.list.filter(function(c) { return c.userid !== uid; });
      if (_msg.activeUserId === uid) {
        _msg.activeUserId = 0;
        _msg.activeConvId = 0;
        document.getElementById('msg-thread-head').style.display = 'none';
        document.getElementById('msg-thread-input').style.display = 'none';
        document.getElementById('msg-thread-empty').style.display = 'flex';
        document.getElementById('msg-thread-body').innerHTML = '';
      }
      msgRenderContacts();
      refreshMsgNavBadge();
      toast('Conversation supprimée');
    })
    .catch(function() { toast('Erreur réseau'); });
}

function msgContactRow(c) {
  var active = (c.userid === _msg.activeUserId) ? ' on' : '';
  var unread = c.unreadcount > 0 ? ' unread' : '';
  var preview = c.lastmsg ? esc(c.lastmsg) : 'Aucun message pour l\'instant';
  return (
    '<div class="msg-contact' + active + unread + '" data-uid="' + c.userid + '" ' +
      'onclick="msgSelectContact(' + c.convid + ',' + c.userid + ',\'' + esc(c.name).replace(/'/g,"\\'") + '\',\'' + esc(c.cls) + '\',' + (c.isonline?'true':'false') + ')">' +
      '<span class="msg-contact-avatar ' + msgAvatarClass(c.userid) + '">' + esc(c.initials) +
        '<span class="dot' + (c.isonline?' online':'') + '"></span>' +
      '</span>' +
      '<div class="msg-contact-info">' +
        '<div class="msg-contact-row1">' +
          '<span class="msg-contact-name">' + esc(c.name) + '</span>' +
          (c.cls ? '<span class="msg-contact-cls">' + esc(c.cls) + '</span>' : '') +
          '<span class="msg-contact-time">' + msgTimeShort(c.lasttime) + '</span>' +
        '</div>' +
        '<div class="msg-contact-preview">' + preview + '</div>' +
      '</div>' +
      '<button type="button" class="msg-contact-del" title="Supprimer ce contact et cette conversation" ' +
        'onclick="event.stopPropagation();msgDeleteConversation(' + c.convid + ',' + c.userid + ',\'' + esc(c.name).replace(/'/g,"\\'") + '\')">🗑</button>' +
      (c.unreadcount > 0 ? '<span class="msg-contact-unread-badge">' + c.unreadcount + '</span>' : '') +
    '</div>'
  );
}

function msgRenderContacts() {
  if (_msg.mode === 'directory') { msgRenderDirectory(); return; }
  var box = document.getElementById('msg-contacts');
  var list = _msg.list;
  if (_msg.filter) {
    list = list.filter(function(c){ return c.name.toLowerCase().indexOf(_msg.filter) !== -1; });
  }
  var favs   = list.filter(function(c){ return c.isfavourite; });
  var others = list.filter(function(c){ return !c.isfavourite; });
  var html = '';
  if (favs.length) {
    html += '<div class="msg-group-label">Favoris</div>' + favs.map(msgContactRow).join('');
  }
  if (others.length) {
    html += '<div class="msg-group-label">Autres</div>' + others.map(msgContactRow).join('');
  }
  if (!favs.length && !others.length) {
    html = '<div class="msg-thread-empty" style="padding:30px 10px">Aucun contact</div>';
  }
  box.innerHTML = html;
}

// "+ Nouveau message" : bascule vers un annuaire (tous les élèves +
// formateurs du client, pas seulement les conversations déjà entamées),
// pour pouvoir démarrer un échange avec quelqu'un jamais encore contacté.
function msgToggleDirectory() {
  _msg.mode = (_msg.mode === 'directory') ? 'conversations' : 'directory';
  var btn = document.getElementById('msg-new-btn');
  if (btn) btn.textContent = (_msg.mode === 'directory') ? '← Retour aux conversations' : '+ Nouveau message';
  msgRenderContacts();
}

function msgRenderDirectory() {
  var box = document.getElementById('msg-contacts');
  var q = _msg.filter;
  var students   = (DATA.students   || []).map(function(s){ return {id: s.id, nom: s.nom, sub: 'Élève' + (s.cohort_name ? ' · ' + dispCohName(s.cohort_name) : '')}; });
  var formateurs = (DATA.formateurs || []).map(function(f){ return {id: f.id, nom: f.nom, sub: 'Formateur' + (f.role_label ? ' · ' + f.role_label : '')}; });
  var all = students.concat(formateurs).filter(function(p){ return p.id !== <?= (int)$USER->id ?>; });
  if (q) all = all.filter(function(p){ return p.nom.toLowerCase().indexOf(q) !== -1; });
  all.sort(function(a,b){ return a.nom.localeCompare(b.nom); });

  if (!all.length) {
    box.innerHTML = '<div class="msg-thread-empty" style="padding:30px 10px">Aucun contact trouvé</div>';
    return;
  }
  box.innerHTML = all.map(function(p) {
    var initials = p.nom.split(' ').map(function(w){ return w[0] || ''; }).slice(0,2).join('').toUpperCase();
    return '<div class="msg-contact" onclick="msgStartWith(' + p.id + ',\'' + esc(p.nom).replace(/'/g,"\\'") + '\')">' +
      '<span class="msg-contact-avatar ' + msgAvatarClass(p.id) + '">' + esc(initials) + '</span>' +
      '<div class="msg-contact-info">' +
        '<div class="msg-contact-row1"><span class="msg-contact-name">' + esc(p.nom) + '</span></div>' +
        '<div class="msg-contact-preview">' + esc(p.sub) + '</div>' +
      '</div>' +
    '</div>';
  }).join('');
}

// Démarre (ou reprend) une conversation depuis l'annuaire, puis revient à la
// vue "conversations" normale — si un échange existe déjà avec cette
// personne, on le rouvre plutôt que d'en simuler un nouveau.
function msgStartWith(uid, nom) {
  _msg.mode = 'conversations';
  var btn = document.getElementById('msg-new-btn');
  if (btn) btn.textContent = '+ Nouveau message';
  document.getElementById('msg-search-input').value = '';
  _msg.filter = '';
  msgRenderContacts();
  var existing = _msg.list.find(function(c){ return c.userid === uid; });
  if (existing) {
    msgSelectContact(existing.convid, existing.userid, existing.name, existing.cls, existing.isonline);
  } else {
    msgSelectContact(0, uid, nom, '', false);
  }
}

function msgSelectContact(convid, userid, nom, cls, isonline) {
  _msg.activeConvId = convid;
  _msg.activeUserId = userid;

  if (window.innerWidth <= 760) {
    var modal = document.querySelector('.msg-modal');
    if (modal) modal.classList.add('msg-mob-thread');
  }

  document.querySelectorAll('.msg-contact').forEach(function(el){ el.classList.remove('on'); });
  var row = document.querySelector('.msg-contact[data-uid="' + userid + '"]');
  if (row) { row.classList.add('on'); row.classList.remove('unread'); var b = row.querySelector('.msg-contact-unread-badge'); if (b) b.remove(); }

  document.getElementById('msg-thread-head').style.display = 'flex';
  document.getElementById('msg-thread-input').style.display = 'flex';
  document.getElementById('msg-thread-empty').style.display = 'none';
  document.getElementById('msg-thread-avatar').className = 'msg-thread-avatar ' + msgAvatarClass(userid);
  document.getElementById('msg-thread-avatar').textContent = nom.split(' ').map(function(p){return p[0]||'';}).join('').slice(0,2).toUpperCase();
  document.getElementById('msg-thread-name').textContent = nom;
  document.getElementById('msg-thread-sub').innerHTML =
    '<span class="dot' + (isonline?' online':'') + '"></span>' + (isonline ? 'En ligne' : 'Hors ligne') +
    (cls ? ' · ' + TERM_CLASSE + ' ' + esc(cls) : '');

  var entry = _msg.list.find(function(c){ return c.userid === userid; });
  msgSetTraiteBtn(entry ? entry.traite : false);

  var body = document.getElementById('msg-thread-body');
  body.innerHTML = '<div class="msg-thread-empty">Chargement…</div>';

  apiPost({ action: 'msg_thread', studentid: userid, convid: convid || 0 }).then(function(r) {
    if (!r.ok) return;
    if (r.convid) _msg.activeConvId = r.convid;
    msgRenderThread(r.messages);
  });
}

function msgRenderThread(messages) {
  var body = document.getElementById('msg-thread-body');
  if (!messages.length) {
    body.innerHTML = '<div class="msg-thread-empty">Aucun message pour l\'instant</div>';
    return;
  }
  var html = '';
  var lastDay = '';
  messages.forEach(function(m) {
    var day = msgDayLabel(m.timecreated);
    if (day !== lastDay) { html += '<div class="msg-day-sep">' + day + '</div>'; lastDay = day; }
    html +=
      '<div class="msg-bubble-row' + (m.mine ? ' mine' : '') + '">' +
        '<div class="msg-bubble">' + m.text +
          '<span class="msg-bubble-time">' + msgTimeShort(m.timecreated) + '</span>' +
        '</div>' +
      '</div>';
  });
  body.innerHTML = html;
  body.scrollTop = body.scrollHeight;
}

function msgSendCurrent() {
  var input = document.getElementById('msg-input-text');
  var text  = input.value.trim();
  if (!text || !_msg.activeUserId) return;
  input.value = '';
  apiPost({ action: 'msg_send', studentid: _msg.activeUserId, text: text }).then(function(r) {
    if (!r.ok) { toast(r.msg || 'Erreur envoi'); return; }
    _msg.activeConvId = r.convid;
    var body = document.getElementById('msg-thread-body');
    if (body.querySelector('.msg-thread-empty')) body.innerHTML = '';
    var lastDay = body.dataset.lastday;
    var day = msgDayLabel(r.message.timecreated);
    var html = '';
    if (day !== lastDay) { html += '<div class="msg-day-sep">' + day + '</div>'; body.dataset.lastday = day; }
    html +=
      '<div class="msg-bubble-row mine">' +
        '<div class="msg-bubble">' + r.message.text +
          '<span class="msg-bubble-time">' + msgTimeShort(r.message.timecreated) + '</span>' +
        '</div>' +
      '</div>';
    body.insertAdjacentHTML('beforeend', html);
    body.scrollTop = body.scrollHeight;
    msgLoadList();
  });
}

function msgSetTraiteBtn(traite) {
  var btn = document.getElementById('msg-thread-traite');
  btn.textContent = traite ? 'Traité ✓' : 'Marquer traité';
  btn.classList.toggle('on', traite);
}
function msgToggleTraite() {
  if (!_msg.activeConvId) { toast('Aucune conversation active'); return; }
  apiPost({ action: 'msg_toggle_traite', convid: _msg.activeConvId }).then(function(r) {
    if (!r.ok) return;
    msgSetTraiteBtn(r.traite);
    var entry = _msg.list.find(function(c){ return c.convid === _msg.activeConvId; });
    if (entry) entry.traite = r.traite;
  });
}
/* ===== MENU UTILISATEUR ===== */
function doLogout(ev) {
  ev.preventDefault();
  var logoutUrl = WWWROOT + '/login/logout.php?sesskey=' + SESSKEY;
  var target    = WWWROOT + '/login/index.php?loginredirect=1&client=<?= $admin_client ?>';
  fetch(logoutUrl, { credentials: 'same-origin' }).finally(function() {
    window.location.href = target;
  });
  return false;
}

function toggleUserMenu(e) {
  e.stopPropagation();
  document.getElementById('user-menu').classList.toggle('on');
}
document.addEventListener('click', function() {
  document.getElementById('user-menu').classList.remove('on');
});

/* ===== PARAMÈTRES ECSR ===== */
var PARAMS_FIELDS = [
  'eleves_par_classe','decalage_rentrees','sous_groupes_nb','sous_groupes_taille',
  'duree_totale','alternance_cours','alternance_stage','regroupements_vehicule','regroupements_salle',
  'salarie_h','vacataire_h','suivi_hebdo','marge_depassement',
  'elearning_pct','classes_virtuelles_pct','presentiel_pct','stage_entreprise_h',
  'ccp1_semaine','session_titre_semaine','delai_ccp1_ccp2','autorisation_temporaire',
  'relance_auto_documents','suivi_camera_global','seuil_alerte_camera','proposition_classe_d',
];

function paramsSave() {
  var vals = {action: 'save_ecsr_params'};
  PARAMS_FIELDS.forEach(function(f) { vals[f] = document.getElementById('p-' + f).value; });
  apiPost(vals).then(function(d) {
    if (d.ok) toast('Paramètres enregistrés'); else toast('Erreur: ' + (d.msg || ''));
  }).catch(function() { toast('Erreur réseau.'); });
}

function paramsCancel() {
  location.reload();
}

/* ===== RENDER DASHBOARD ===== */
function renderDash() {
  var k = DATA.kpi;

  // ---- Salutation dynamique ----
  var now = new Date();
  var hr  = now.getHours();
  var days = ['dimanche','lundi','mardi','mercredi','jeudi','vendredi','samedi'];
  var greet = hr < 12 ? 'Bonjour' : hr < 18 ? 'Bon après-midi' : 'Bonsoir';
  document.getElementById('date-label').innerHTML = '<b>' + DATA.date_label + '</b>';
  document.getElementById('dash-greeting').textContent = greet + ', voici votre ' + days[now.getDay()];

  // ---- Sous-titre contextuel ----
  var today0 = new Date(); today0.setHours(0,0,0,0);
  var rentrees = DATA.classes.filter(function(c) {
    var s = parseStartDate(c.startdate);
    if (!s) return false;
    s.setHours(0,0,0,0);
    return s.getTime() === today0.getTime();
  });
  var sub = k.groups + ' ' + TERM_CLASSE_LC + (k.groups > 1 ? 's' : '') + ' en cours';
  if (rentrees.length) sub += ' · la ' + rentrees.map(function(c){ return esc(dispCohName(c.name)); }).join(', ') + ' fait sa rentrée ce matin';
  document.getElementById('dash-sub').textContent = sub;

  // ---- KPI Classes enrichi ----
  var classesDetail = DATA.classes.map(function(c) {
    var letter = classLetter(c.name) || c.name.slice(0,1);
    var w = currentWeekOf(c.startdate);
    return letter + (w > 0 ? '·S' + w : '');
  }).join(' | ');
  var futur = DATA.classes.filter(function(c) {
    var s = parseStartDate(c.startdate);
    return s && s > now;
  });
  var classePill = futur.length ? (futur.length === 1 ? classLetter(futur[0].name) || futur[0].name.slice(0,1) : futur.length) + ' en préparation' : '';

  // ---- KPI cards ----
  var nbAlerts = DATA.alerts.length;
  var kpis = [
    { cls:'k-orange', title:'Élèves actifs',
      num: k.students, pill: '',
      sub: 'Répartis sur ' + k.groups + ' ' + TERM_CLASSE_LC + (k.groups > 1 ? 's' : '') +
           (DATA.classes.length ? ' (' + DATA.classes.map(function(c){ return classLetter(c.name)||c.name.slice(0,1); }).join(' · ') + ')' : '') },
    { cls:'k-mint',   title: TERM_CLASSE + 's en cours',
      num: k.groups,  pill: classePill,
      sub: classesDetail || 'Aucune ' + TERM_CLASSE_LC + ' démarrée' },
    { cls:'k-violet', title:'Présence classes virt.',
      num: k.presence + '%', pill: '7 derniers jours',
      sub: 'Élèves actifs sur Glowbl' },
    { cls:'k-cream',  title:'Intervenants',
      num: k.teachers, pill: '',
      sub: k.teachers + ' formateur' + (k.teachers > 1 ? 's' : '') + ' actif' + (k.teachers > 1 ? 's' : '') },
    { cls: nbAlerts > 0 ? 'k-danger' : 'k-mint',
      title: 'Points d\'attention', num: nbAlerts,
      pill: nbAlerts > 0 ? 'urgent' : '',
      sub:  nbAlerts > 0 ? 'Alertes Lia actives' : 'Aucune alerte, RAS ✓' }
  ];
  document.getElementById('kpi-grid').innerHTML = kpis.map(function(kpi) {
    return '<div class="kpi ' + kpi.cls + '">' +
      '<div class="kpi-head"><span class="kpi-title">' + kpi.title + '</span></div>' +
      '<div class="kpi-main"><div class="kpi-num">' + kpi.num + '</div>' +
      (kpi.pill ? '<span class="kpi-pill">' + kpi.pill + '</span>' : '') +
      '</div><div class="kpi-sub">' + kpi.sub + '</div></div>';
  }).join('');

  // ---- Événements du jour ----
  var evEl = document.getElementById('today-events');
  if (DATA.today_events.length) {
    evEl.innerHTML = DATA.today_events.map(function(ev) {
      return '<div class="event"><span class="h">' + ev.heure + '</span>' +
        '<span>' + esc(ev.titre) +
        (ev.cours  ? ' · <b>' + esc(ev.cours) + '</b>' : '') +
        (ev.auteur ? ' <span class="chip c-vi">' + esc(ev.auteur) + '</span>' : '') +
        '</span></div>';
    }).join('');
  } else {
    evEl.innerHTML = '<p style="color:var(--muted);font-size:13px">Aucun événement calendrier aujourd\'hui.</p>';
  }

  // ---- Prochains regroupements ----
  var milestones = [];
  DATA.classes.forEach(function(c) {
    var start = parseStartDate(c.startdate);
    if (!start) return;
    var letter = classLetter(c.name) || c.name;
    STAGE_WEEKS.forEach(function(w) {
      var d = weekToDate(start, w);
      if (d > now) milestones.push({ date: d, label: 'Stage entreprise', classe: letter, type: 'stage' });
    });
    VEHICLE_WEEKS.forEach(function(w) {
      var d = weekToDate(start, w);
      if (d > now) milestones.push({ date: d, label: 'Semaine véhicule · Lyon', classe: letter, type: 'vehicule' });
    });
  });
  milestones.sort(function(a, b){ return a.date - b.date; });
  milestones = milestones.slice(0, 5);
  var prochEl = document.getElementById('prochains-events');
  var titleEl = document.getElementById('prochains-title');
  if (milestones.length) {
    titleEl.style.display = '';
    prochEl.innerHTML = milestones.map(function(m) {
      var typeChip = m.type === 'vehicule'
        ? '<span class="chip c-vi">présentiel</span>'
        : '<span class="chip" style="background:var(--orange-t);color:var(--orange-d)">stage</span>';
      var dateStr = fmtDate(m.date);
      return '<div class="event"><span class="h" style="flex:0 0 56px">' + dateStr + '</span>' +
        '<span>' + TERM_CLASSE + ' ' + esc(m.classe) + ' · ' + esc(m.label) + ' ' + typeChip + '</span></div>';
    }).join('');
  } else {
    titleEl.style.display = 'none';
    prochEl.innerHTML = '';
  }

  // ---- Alertes Lia ----
  var alertEl = document.getElementById('lia-alerts');
  if (DATA.alerts.length) {
    alertEl.innerHTML = DATA.alerts.map(function(a) {
      return '<div class="lia-item' + (a.type === 'danger' ? ' danger' : '') + '">' +
        '<b>' + esc(a.title) + ' :</b> ' + esc(a.body) +
        (a.action ? '<div class="act"><button class="btn mini primary" onclick="goToAlert(\'' + a.target + '\',' + (a.filter ? '\'' + a.filter + '\'' : 'null') + ')">' + esc(a.action) + '</button></div>' : '') +
        '</div>';
    }).join('');
  } else {
    alertEl.innerHTML = '<div style="background:var(--teal-t);border-left:3px solid var(--teal);border-radius:0 10px 10px 0;padding:10px 13px;font-size:13px"><b>Aucune alerte.</b> Tout est nominal.</div>';
  }
}

/* ===== RENDER INTERVENANTS ===== */
function habStyleStr(color) {
  switch (color) {
    case 'orange': return 'background:var(--orange-t);color:var(--orange-d)';
    case 'violet': return 'background:var(--violet-t);color:var(--violet-d)';
    case 'sand':   return 'background:var(--sand-t);color:var(--sand)';
    default:       return 'background:var(--teal-t);color:var(--teal-d)';
  }
}

function renderTeam() {
  var n = DATA.formateurs.length;
  document.getElementById('team-count').textContent = n + ' intervenant' + (n > 1 ? 's' : '') + ' actif' + (n > 1 ? 's' : '') + ' sur la plateforme.';

  document.getElementById('team-grid').innerHTML = DATA.formateurs.map((f, i) => {
    var pct = f.pct_load || 0;
    var statusLabel = pct >= 100 ? 'Surchargé'  : pct >= 80 ? 'Chargé'  : 'Disponible';
    var dotColor    = pct >= 100 ? 'var(--red)'  : pct >= 80 ? 'var(--orange)' : 'var(--teal)';

    var typeLabel = (f.type === 'vacataire') ? 'vacataire' : 'salarié';
    var subtitle  = (f.role_label ? esc(f.role_label) + ' · ' : '') + typeLabel;
    var clientTag = '<span class="chip" style="margin-left:6px;' +
      (f.client === 'saran' ? 'background:var(--orange-t);color:var(--orange-d)' : 'background:var(--teal-t);color:var(--teal-d)') +
      '">' + (f.client === 'saran' ? 'Saran' : 'LBA') + '</span>';

    var habsHtml = (f.habs && f.habs.length)
      ? f.habs.map(h => '<span class="hab-tag" style="' + habStyleStr(h.color) + '">' + esc(h.text) + '</span>').join('')
      : '<span style="color:var(--muted);font-size:11px;font-style:italic">Aucune habilitation</span>';

    var statusLine = '<span style="color:' + dotColor + ';margin-right:2px">●</span> ' + statusLabel +
      ' · cap. ' + (f.capacity || 28) + ' h/sem' +
      ' · charge moy. ' + (f.hours_avg || 0) + ' h' +
      (f.hours_peak > 0 ? ' · pic ' + f.hours_peak + ' h' : '');

    var prochainHtml = (f.events && f.events.length)
      ? '<div class="f-prochain">Prochain : ' + esc(f.events[0].label) + ', <b>' + esc(f.events[0].date) + '</b></div>'
      : '<div class="f-prochain" style="font-style:italic">Aucun événement à venir</div>';

    return '<div class="pers">' +
      '<div class="pers-top">' +
        '<span class="av" style="background:' + (f.color || COLORS[i % COLORS.length]) + ';width:36px;height:36px;border-radius:99px;color:#fff;font-weight:800;font-size:14px;display:flex;align-items:center;justify-content:center;flex:0 0 36px">' + esc(f.initials) + '</span>' +
        '<div style="flex:1;min-width:0">' +
          '<b>' + esc(f.nom) + '</b>' + clientTag +
          '<div class="rt">' + subtitle + '</div>' +
        '</div>' +
        '<button class="edit-btn" onclick="editFormateur(' + f.id + ')" title="Modifier">✎</button>' +
        '<button class="edit-btn" style="color:var(--red,#C0392B)" onclick="deleteFormateur(' + f.id + ',\'' + esc(f.nom).replace(/'/g,"\\'") + '\')" title="Supprimer">🗑</button>' +
      '</div>' +
      '<div class="habs-row">' + habsHtml + '</div>' +
      '<div class="status-line">' + statusLine + '</div>' +
      prochainHtml +
      '</div>';
  }).join('') || '<p style="color:var(--muted)">Aucun formateur trouvé.</p>';
}

/* ===== FORMATEUR MODAL ===== */
var _fmodMode = 'edit';
var _fmodUid  = null;
var _habColor = 'teal';

// Cohortes suivies — checklist a partir de DATA.classes (deja filtre au
// client de l'admin connecte), pre-coche selon l'assignation existante.
function renderFmodCohortsList(selectedIds) {
  selectedIds = selectedIds || [];
  var box = document.getElementById('fmod-cohorts-list');
  var classes = DATA.classes || [];
  box.innerHTML = classes.length ? classes.map(function(c) {
    var checked = selectedIds.indexOf(c.id) !== -1 ? ' checked' : '';
    return '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12.5px;color:var(--ink);text-transform:none;letter-spacing:0;font-weight:500">' +
      '<input type="checkbox" class="fmod-cohort-cb" value="' + c.id + '"' + checked + '> ' + esc(dispCohName(c.name)) +
    '</label>';
  }).join('') : '<span style="color:var(--muted);font-size:11.5px;font-style:italic">Aucune cohorte</span>';
}

function openAddFormateur() {
  _fmodMode = 'add'; _fmodUid = null;
  document.getElementById('fmod-title').textContent = 'Ajouter un intervenant';
  document.getElementById('fmod-sub').textContent   = "L'utilisateur sera inscrit comme formateur sur tous les cours.";
  document.getElementById('fmod-new-fields').style.display = '';
  document.getElementById('fmod-habs-section').style.display = 'none';
  document.getElementById('fmod-client-field').style.display = 'none';
  ['fmod-firstname','fmod-lastname','fmod-email','fmod-role','fmod-secteur'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('fmod-pedlead').checked = false;
  renderFmodCohortsList([]);
  document.querySelector('input[name="fmod-type"][value="salarie"]').checked = true;
  document.querySelector('input[name="fmod-client"][value="' + (DATA.admin_client || 'lba') + '"]').checked = true;
  document.getElementById('modal-form-bg').classList.add('open');
  document.getElementById('fmod-firstname').focus();
}

// Modale d'identifiants reutilisee pour tout compte fraichement cree
// (intervenant ou eleve) — onClose permet a chaque appelant de decider de
// la suite (rechargement complet, simple rafraichissement deja fait...).
var _credModalOnClose = null;
function showCredentialsModal(username, password, onClose, title) {
  document.getElementById('cred-title').textContent = '✅ ' + (title || 'Compte créé');
  document.getElementById('cred-username').textContent = username;
  document.getElementById('cred-password').textContent = password;
  document.getElementById('cred-modal-bg').classList.add('open');
  _credModalOnClose = onClose || null;
}
function closeCredentialsModal() {
  document.getElementById('cred-modal-bg').classList.remove('open');
  var cb = _credModalOnClose; _credModalOnClose = null;
  if (cb) cb();
}

function copyCredentials() {
  var u = document.getElementById('cred-username').textContent;
  var p = document.getElementById('cred-password').textContent;
  var text = 'Identifiant : ' + u + '\nMot de passe : ' + p;
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(() => toast('Copié')).catch(() => toast('Impossible de copier'));
  } else {
    toast('Impossible de copier');
  }
}

function deleteStudent(uid, nom) {
  var msg = '⚠ Supprimer définitivement le compte de « ' + nom + ' » ? Cette action est irréversible : ' +
    'son compte, sa progression, ses notes et son historique de messages seront perdus.';
  if (!confirm(msg)) return;
  apiPost({ action: 'delete_student', userid: uid })
    .then(function(r) {
      if (r.ok) {
        DATA.students = DATA.students.filter(function(s) { return s.id !== uid; });
        renderStudentsTable();
        toast('Élève supprimé');
      } else { toast('Erreur : ' + (r.msg || '?')); }
    })
    .catch(function() { toast('Erreur réseau'); });
}

function deleteFormateur(uid, nom) {
  var msg = '⚠ Supprimer définitivement le compte de « ' + nom + ' » ? Cette action est irréversible : ' +
    'son compte, son historique de messages et ses corrections seront perdus.';
  if (!confirm(msg)) return;
  apiPost({ action: 'delete_intervenant', userid: uid })
    .then(function(r) {
      if (r.ok) {
        DATA.formateurs = DATA.formateurs.filter(function(f) { return f.id !== uid; });
        renderTeam();
        toast('Intervenant supprimé');
      } else { toast('Erreur : ' + (r.msg || '?')); }
    })
    .catch(function() { toast('Erreur réseau'); });
}

function editFormateur(uid) {
  _fmodMode = 'edit'; _fmodUid = uid;
  var f = DATA.formateurs.find(x => x.id === uid);
  if (!f) return;
  document.getElementById('fmod-title').textContent = 'Modifier ' + f.nom;
  document.getElementById('fmod-sub').textContent   = f.email;
  document.getElementById('fmod-new-fields').style.display  = 'none';
  document.getElementById('fmod-habs-section').style.display = '';
  document.getElementById('fmod-client-field').style.display = 'none';
  document.getElementById('fmod-role').value = f.role_label || '';
  document.getElementById('fmod-secteur').value = f.secteur || '';
  document.getElementById('fmod-pedlead').checked = !!f.is_ped_lead;
  var typeEl = document.querySelector('input[name="fmod-type"][value="' + (f.type || 'salarie') + '"]');
  if (typeEl) typeEl.checked = true;
  var clientEl = document.querySelector('input[name="fmod-client"][value="' + (f.client || 'lba') + '"]');
  if (clientEl) clientEl.checked = true;
  renderFmodCohortsList(f.cohorts || []);
  renderHabsList(uid);
  document.getElementById('modal-form-bg').classList.add('open');
  document.getElementById('fmod-role').focus();
}

function renderHabsList(uid) {
  var f = DATA.formateurs.find(x => x.id === uid);
  var habs = (f && f.habs) || [];
  document.getElementById('fmod-habs-list').innerHTML = habs.length
    ? habs.map((h, idx) =>
        '<span class="hab-tag" style="' + habStyleStr(h.color) + ';gap:5px">' + esc(h.text) +
        '<button type="button" onclick="removeHab(' + uid + ',' + idx + ')" style="border:none;background:none;cursor:pointer;padding:0;color:inherit;opacity:.65;font-size:12px;line-height:1">×</button></span>'
      ).join('')
    : '<span style="color:var(--muted);font-size:11.5px;font-style:italic">Aucune habilitation</span>';
}

function selectHabColor(btn) {
  document.querySelectorAll('.hcb').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  _habColor = btn.dataset.color;
}

function addHabFromInput() {
  if (!_fmodUid) return;
  var text = document.getElementById('fmod-hab-text').value.trim();
  if (!text) return;
  apiPost({ action: 'add_hab', userid: _fmodUid, text: text, color: _habColor })
    .then(r => {
      if (r.ok) {
        var f = DATA.formateurs.find(x => x.id === _fmodUid);
        if (f) f.habs = r.habs;
        document.getElementById('fmod-hab-text').value = '';
        renderHabsList(_fmodUid);
        renderTeam();
        toast('Habilitation ajoutée');
      }
    });
}

function removeHab(uid, idx) {
  apiPost({ action: 'remove_hab', userid: uid, idx: idx })
    .then(r => {
      if (r.ok) {
        var f = DATA.formateurs.find(x => x.id === uid);
        if (f) f.habs = r.habs;
        renderHabsList(uid);
        renderTeam();
      }
    });
}

function closeFormModal() {
  document.getElementById('modal-form-bg').classList.remove('open');
}

function saveFormateurModal() {
  var role_label = document.getElementById('fmod-role').value.trim();
  var secteur = document.getElementById('fmod-secteur').value.trim();
  var is_ped_lead = document.getElementById('fmod-pedlead').checked;
  var typeEl = document.querySelector('input[name="fmod-type"]:checked');
  var type = typeEl ? typeEl.value : 'salarie';
  var clientEl = document.querySelector('input[name="fmod-client"]:checked');
  var client = clientEl ? clientEl.value : 'lba';
  var cohorts = Array.prototype.map.call(document.querySelectorAll('.fmod-cohort-cb:checked'), function(cb){ return parseInt(cb.value, 10); });
  var btn = document.getElementById('fmod-save-btn');
  btn.disabled = true; btn.textContent = 'Enregistrement…';

  if (_fmodMode === 'add') {
    var fn = document.getElementById('fmod-firstname').value.trim();
    var ln = document.getElementById('fmod-lastname').value.trim();
    var em = document.getElementById('fmod-email').value.trim();
    if (!fn || !ln || !em) { btn.disabled = false; btn.textContent = 'Enregistrer'; toast('Tous les champs sont requis'); return; }
    apiPost({ action: 'add_intervenant', firstname: fn, lastname: ln, email: em, role_label: role_label, type: type, client: client, secteur: secteur, is_ped_lead: is_ped_lead ? 1 : 0, cohorts: JSON.stringify(cohorts) })
      .then(r => {
        btn.disabled = false; btn.textContent = 'Enregistrer';
        if (r.ok) {
          closeFormModal();
          if (r.password) {
            // Nouveau compte : identifiant + mot de passe genere a afficher
            // une seule fois (le rechargement se fait a la fermeture de
            // cette fenetre, pas avant, pour laisser le temps de les noter).
            showCredentialsModal(r.username, r.password, function(){ location.reload(); }, 'Intervenant créé');
          } else {
            // Compte deja existant reutilise : rien de nouveau a afficher.
            toast('Intervenant ajouté, rechargement…', 2000);
            setTimeout(() => location.reload(), 1500);
          }
        } else { toast('Erreur : ' + (r.msg || '?')); }
      })
      .catch(() => { btn.disabled = false; btn.textContent = 'Enregistrer'; toast('Erreur réseau'); });
  } else {
    apiPost({ action: 'save_formateur_meta', userid: _fmodUid, role_label: role_label, type: type, client: client, secteur: secteur, is_ped_lead: is_ped_lead ? 1 : 0, cohorts: JSON.stringify(cohorts) })
      .then(r => {
        btn.disabled = false; btn.textContent = 'Enregistrer';
        if (r.ok) {
          var f = DATA.formateurs.find(x => x.id === _fmodUid);
          if (f) {
            f.role_label = role_label; f.type = type; f.client = client; f.secteur = secteur; f.is_ped_lead = is_ped_lead; f.cohorts = cohorts;
            f.capacity = type === 'vacataire' ? 20 : 28;
            f.pct_load = f.capacity > 0 ? Math.min(100, Math.round((f.hours_avg || 0) / f.capacity * 100)) : 0;
          }
          closeFormModal(); renderTeam();
          toast('Modifications enregistrées');
        }
      })
      .catch(() => { btn.disabled = false; btn.textContent = 'Enregistrer'; toast('Erreur réseau'); });
  }
}

/* ===== PLANNING & LIA ENGINE =====
   Le calcul (curriculum, affectation par rôle, arbitrage) est fait côté
   serveur — voir lia_engine.php — et arrive tout prêt dans DATA.lia_plan /
   DATA.lia_people / DATA.lia_cohorts. Ne reste ici que le rendu et la
   classification de semaine (liaWType), utilisée pour les pastilles de la
   Vue classes. */

function liaWType(rel) {
  var vehWeeks   = DATA.veh_weeks   && DATA.veh_weeks.length   ? DATA.veh_weeks   : [7, 13, 19];
  var salleWeeks = DATA.salle_weeks && DATA.salle_weeks.length ? DATA.salle_weeks : [25, 31];
  if (rel === 35) return 'e';
  if (rel <= 32 && rel % 4 === 0) return 's';
  if (vehWeeks.indexOf(rel) !== -1) return 'r';
  if (salleWeeks.indexOf(rel) !== -1) return 'rs';
  if (rel === 26) return 'e1';
  return 'c';
}

var LIA_MONTHS_FR = ['janv.','févr.','mars','avr.','mai','juin','juil.','août','sept.','oct.','nov.','déc.'];
var LIA_PLAN = null, LIA_VUE = 'pers', LIA_PEOPLE = [], LIA_COHORTS = [], LIA_START = null, LIA_TODAY_W = 1, LIA_PLAN_EV_WEEKS = {};
// Filtre par type (legende Planning & Lia) — null = Tous. Clique a nouveau
// sur le meme type pour revenir a Tous (voir la legende, id="plan-legend").
var LIA_TYPE_FILTER = null;
function setPlanTypeFilter(t) {
  LIA_TYPE_FILTER = (LIA_TYPE_FILTER === t) ? null : t;
  document.querySelectorAll('#plan-legend .pg-filter-btn').forEach(function(b){
    b.classList.toggle('on', (b.dataset.type || null) === LIA_TYPE_FILTER);
  });
  liaRender();
}
// 'veh'/'salle'/'stage'/'exam' (valeurs de e.type, Vue intervenants) -> cle
// de filtre partagee avec la legende ; tout le reste (dont undefined =
// visio) tombe sur 'distance'.
function liaEntryTypeKey(type) {
  return type === 'veh' ? 'vehicule' : type === 'salle' ? 'salle' : type === 'stage' ? 'stage' : type === 'exam' ? 'examen' : 'distance';
}
// 'c'/'s'/'r'/'rs'/'e'/'e1' (liaWType, Vue cohortes) -> meme cle de filtre.
function liaWTypeKey(t) {
  return t === 's' ? 'stage' : t === 'r' ? 'vehicule' : t === 'rs' ? 'salle' : (t === 'e' || t === 'e1') ? 'examen' : 'distance';
}

function liaWeekTotal(plan, personId, w) {
  var es = plan && plan.grid && plan.grid[personId] ? plan.grid[personId][w] || [] : [];
  return es.reduce(function(s, e) { return s + e.h; }, 0);
}

function liaFmtDate(w) {
  if (!LIA_START) return 'S'+w;
  var d = new Date(LIA_START.getTime() + (w-1)*7*86400*1000);
  return d.getDate()+' '+LIA_MONTHS_FR[d.getMonth()];
}

function liaHeadRows(totalW) {
  var m = '';
  if (!IS_BETA_CAL) {
    m += '<div class="pg-row pg-mrow"><div class="pg-lab"></div>';
    var prevMo = -1, prevYr = -1;
    for (var w = 1; w <= totalW; w++) {
      var d = LIA_START ? new Date(LIA_START.getTime()+(w-1)*7*86400*1000) : null;
      var mo = d ? d.getMonth() : -1, yr = d ? d.getFullYear() : -1;
      var label = '';
      if (d && mo !== prevMo) {
        // L'année n'apparaît que quand elle change (janvier, ou tout premier
        // mois affiché) — sinon "janv." pourrait désigner 2026 ou 2027 sans
        // distinction sur un planning qui dépasse 12 mois.
        label = LIA_MONTHS_FR[mo] + (yr !== prevYr ? ' ' + yr : '');
      }
      m += '<div class="pg-cell'+(w===LIA_TODAY_W?' pg-now':'')+'">'+label+'</div>';
      prevMo = mo; prevYr = yr;
    }
    m += '</div>';
  }
  // Testeurs beta : une seule ligne d'en-tete (pas de ligne "mois"), le
  // libelle de la colonne fixe reprend celui de la vue active.
  var rowLabel = IS_BETA_CAL ? (LIA_VUE === 'coh' ? TERM_CLASSE : 'Formateur') : 'Semaine';
  m += '<div class="pg-row pg-wrow"><div class="pg-lab"><span>'+rowLabel+'</span></div>';
  for (var w = 1; w <= totalW; w++) m += '<div class="pg-cell'+(w===LIA_TODAY_W?' pg-now':'')+'">'+w+'</div>';
  return m + '</div>';
}

function liaRender() {
  var p = LIA_PLAN; if (!p) return;
  var html = liaHeadRows(p.totalW);

  if (LIA_VUE === 'coh') {
    LIA_COHORTS.forEach(function(cohort) {
      html += '<div class="pg-row"><div class="pg-lab"><b>'+TERM_CLASSE+' '+esc(cohort.L)+'</b><span>rentrée '+liaFmtDate(cohort.off+1)+(cohort.nb_members?' · '+cohort.nb_members+' élèves':'')+'</span></div>';
      for (var w = 1; w <= p.totalW; w++) {
        var rel = w-cohort.off, nowC = w===LIA_TODAY_W?' pg-now':'';
        if (rel<1||rel>35) { html += '<div class="pg-cell'+nowC+'"></div>'; continue; }
        var t = liaWType(rel);
        var dimC = (LIA_TYPE_FILTER && liaWTypeKey(t) !== LIA_TYPE_FILTER) ? ' pg-dim' : '';
        if      (t==='c')  {
          var hasDot = LIA_PLAN_EV_WEEKS[w] ? ' pg-has-ev' : '';
          html += '<div class="pg-cell pg-c-d'+nowC+dimC+hasDot+'" title="S'+w+' · visio · cliquer pour planifier" onclick="openSeanceModal(\'' + cohort.L + '\','+w+','+cohort.off+')" style="cursor:pointer">' + (hasDot ? '●' : '') + '</div>';
        }
        else if (t==='s')  html += '<div class="pg-cell pg-c-s'+nowC+dimC+'" title="S'+w+' · stage · cliquer pour affecter" onclick="openLiaAffectModal(null,'+w+',\''+cohort.L+'\')" style="cursor:pointer">St</div>';
        else if (t==='r')  html += '<div class="pg-cell pg-c-r'+nowC+dimC+'" title="S'+w+' · regroupement véhicule · cliquer pour affecter" onclick="openLiaAffectModal(null,'+w+',\''+cohort.L+'\')" style="cursor:pointer">R</div>';
        else if (t==='rs') html += '<div class="pg-cell pg-c-rs'+nowC+dimC+'" title="S'+w+' · regroupement salle · cliquer pour affecter" onclick="openLiaAffectModal(null,'+w+',\''+cohort.L+'\')" style="cursor:pointer">R</div>';
        else if (t==='e1') html += '<div class="pg-cell pg-c-e'+nowC+dimC+'" title="S'+w+' · examen CCP1 · cliquer pour affecter" onclick="openLiaAffectModal(null,'+w+',\''+cohort.L+'\')" style="cursor:pointer">C1</div>';
        else               html += '<div class="pg-cell pg-c-e'+nowC+dimC+'" title="S'+w+' · session titre · cliquer pour affecter" onclick="openLiaAffectModal(null,'+w+',\''+cohort.L+'\')" style="cursor:pointer">Ex</div>';
      }
      html += '</div>';
    });
  } else {
    LIA_PEOPLE.forEach(function(per) {
      var curH = Math.round(liaWeekTotal(p, per.id, LIA_TODAY_W));
      var hoursLine = IS_BETA_CAL
        ? '<span>'+esc(per.role)+'</span><span style="font-weight:700;color:'+(curH>per.cap?'var(--red)':'var(--muted)')+'">'+curH+'h / '+per.cap+'h</span>'
        : '<span>'+esc(per.role)+'</span>';
      html += '<div class="pg-row"><div class="pg-lab"><b class="pg-clickable" onclick="openLiaHoursDetail('+per.id+')">'+esc(per.nom)+'</b>'+hoursLine+'</div>';
      for (var w = 1; w <= p.totalW; w++) {
        var es = p.grid[per.id]?p.grid[per.id][w]||[]:[], t = liaWeekTotal(p,per.id,w), nowC = w===LIA_TODAY_W?' pg-now':'';
        var overload = t > per.cap+0.5;
        // Testeurs beta : fond de case allege (le detail de charge reste
        // dans l'infobulle), la surcharge se voit sur le soulignement des
        // pastilles plutot que sur toute la case (voir .pg-overload).
        var lc = IS_BETA_CAL ? '' : (overload ? ' pg-hot' : t > 24 ? ' pg-p3' : t > 14 ? ' pg-p2' : t > 0 ? ' pg-p1' : '');
        var click = ' onclick="openLiaAffectModal('+per.id+','+w+',null)" style="cursor:pointer"';
        if (!es.length) { html += '<div class="pg-cell'+lc+nowC+'" title="S'+w+' · '+esc(per.nom)+' · cliquer pour affecter"'+click+'></div>'; continue; }
        var byCoh = {};
        es.forEach(function(e){ (byCoh[e.coh]=byCoh[e.coh]||[]).push(e); });
        var chips = '', tip = 'S'+w+' · '+per.nom+' · '+Math.round(t)+' h&#10;';
        Object.keys(byCoh).sort().forEach(function(c) {
          var arr = byCoh[c];
          var mtype = arr.some(function(e){return e.type==='veh';}) ? 'veh' : arr.some(function(e){return e.type==='salle';}) ? 'salle' : arr.some(function(e){return e.type==='stage';}) ? 'stage' : arr.some(function(e){return e.type==='exam';}) ? 'exam' : null;
          var main = mtype==='veh' ? 'pg-ch-veh' : mtype==='salle' ? 'pg-ch-s' : mtype==='stage' ? 'pg-ch-stage' : mtype==='exam' ? 'pg-ch-exam' : 'pg-ch-v';
          var star = arr.some(function(e){return e.lia;});
          var manual = arr.some(function(e){return e.manual;});
          var overCls = (IS_BETA_CAL && overload) ? ' pg-overload' : '';
          var dimCls = (LIA_TYPE_FILTER && liaEntryTypeKey(mtype) !== LIA_TYPE_FILTER) ? ' pg-dim' : '';
          chips += '<span class="pg-pcell '+main+overCls+dimCls+'">'+c+'</span>'+(star?'<span class="pg-star'+dimCls+'">✦</span>':'')+(manual?'<span class="pg-manual'+dimCls+'">✎</span>':'');
          arr.forEach(function(e){ tip += (e.lia?'✦ ':'')+(e.manual?'✎ ':'')+c+' · '+(e.type==='veh'?'véhicule':e.type==='salle'?'salle':e.type==='stage'?'stage':e.type==='exam'?'examen':'visio')+' « '+e.label+' » · '+Math.round(e.h*2)/2+' h&#10;'; });
        });
        tip += 'cliquer pour affecter';
        html += '<div class="pg-cell'+lc+nowC+'" title="'+tip+'"'+click+'>'+chips+'</div>';
      }
      html += '</div>';
    });
  }

  document.getElementById('plan-grid').innerHTML = html;
  var logEl = document.getElementById('plan-log');
  var lastLog = p.log.slice(-8);
  logEl.innerHTML = lastLog.length
    ? lastLog.map(function(l){ return '<div class="pg-logline"><span class="pg-lw">S'+l.w+'</span><span class="pg-ltxt">'+l.txt+'</span></div>'; }).join('')
    : '<div class="pg-logline" style="color:var(--muted)">Aucun ajustement automatique.</div>';
  var alertEl = document.getElementById('plan-alerts');
  alertEl.innerHTML = p.alerts.length
    ? p.alerts.map(function(a){
        var actions = '<button class="btn mini ghost" onclick="dismissLiaAlert(\''+a.kind+'\','+(a.pid||'null')+','+a.w+',this)">Accepter</button>';
        if (a.pid) actions += '<button class="btn mini primary" onclick="openLiaAffectModal('+a.pid+','+a.w+',null)">Modifier l\'affectation</button>';
        return '<div class="pg-alert">'+esc(a.txt)+'<div class="pg-alert-actions">'+actions+'</div></div>';
      }).join('')
    : '<div class="pg-ok"><b>Rien à arbitrer.</b> Le plan tient tel quel.</div>';
  document.getElementById('plan-pkpis').innerHTML =
    '<div class="kpi k-mint" style="min-height:80px"><div class="kpi-head"><span class="kpi-title">Heures lissées par Lia</span></div><div class="kpi-main"><div class="kpi-num">'+p.movedH+'<small>h</small></div></div><div class="kpi-sub">sur '+p.weeks+' semaine(s)</div></div>'+
    '<div class="kpi k-cream" style="min-height:80px"><div class="kpi-head"><span class="kpi-title">Semaines d\'arrêt équipe</span></div><div class="kpi-main"><div class="kpi-num">'+p.idle.length+'</div></div><div class="kpi-sub">tous en stage simultanément</div></div>'+
    '<div class="kpi k-violet" style="min-height:80px"><div class="kpi-head"><span class="kpi-title">'+TERM_CLASSE+'s planifiées</span></div><div class="kpi-main"><div class="kpi-num">'+p.cohorts.length+'</div></div><div class="kpi-sub">'+p.cohorts.map(function(c){ return TERM_CLASSE+' '+c.L; }).join(' · ')+'</div></div>';
}

function setVuePlan(v) {
  LIA_VUE = v;
  document.getElementById('plan-tgP').classList.toggle('on', v==='pers');
  document.getElementById('plan-tgC').classList.toggle('on', v==='coh');
  liaRender();
}

function simulerClasse() {
  if (!LIA_PLAN || !LIA_START) return;
  var decalage = parseInt((DATA.ecsr_params || {}).decalage_rentrees, 10) || 2;
  var maxOff = Math.max.apply(null, [0].concat(LIA_COHORTS.map(function(c){ return c.off; })));
  var nextOff = maxOff + decalage;
  var nextL = String.fromCharCode(65 + LIA_COHORTS.length);
  var propDate = new Date(LIA_START.getTime() + nextOff * 7 * 86400 * 1000);
  document.getElementById('plan-prop-letter').textContent = nextL;
  document.getElementById('plan-prop-date').textContent = fmtDateFull(propDate);
  document.getElementById('plan-prop-decalage').textContent = decalage + (decalage === 1 ? ' semaine' : ' semaines');
  document.getElementById('plan-prop-btn').onclick = function() {
    var btn = document.getElementById('plan-prop-btn');
    var isoDate = propDate.getFullYear() + '-' + String(propDate.getMonth() + 1).padStart(2, '0') + '-' + String(propDate.getDate()).padStart(2, '0');
    btn.disabled = true; btn.textContent = 'Création…';
    apiPost({action: 'create_class', name: 'Classe ' + nextL, startdate: isoDate}).then(function(r) {
      if (!r.ok) { btn.disabled = false; btn.textContent = 'Créer la ' + TERM_CLASSE_LC; toast('Erreur : ' + (r.msg || '?')); return; }
      DATA.classes.push({ id: r.id, name: r.name, startdate: isoDate, secteur: '', nb_members: 0, avg_prog: 0, member_ids: [] });
      renderCoh();
      return apiPost({action: 'preview_lia_plan'}).then(function(d) {
        btn.disabled = false; btn.textContent = 'Créer la ' + TERM_CLASSE_LC;
        if (!d.ok) { toast('Classe créée, mais actualisation du planning en échec, rechargez la page.'); return; }
        LIA_COHORTS = d.cohorts;
        LIA_PLAN    = d.plan;
        liaRender();
        document.getElementById('plan-prop').classList.remove('on');
        toast('Classe ' + nextL + ' créée, rentrée le ' + fmtDateFull(propDate));
      });
    }).catch(function() { btn.disabled = false; btn.textContent = 'Créer la ' + TERM_CLASSE_LC; toast('Erreur réseau.'); });
  };
  document.getElementById('plan-prop').classList.add('on');
}

function initPlan() {
  renderDispoValidation();
  if (LIA_PLAN) { liaRender(); return; }
  LIA_PEOPLE  = DATA.lia_people || [];
  LIA_COHORTS = DATA.lia_cohorts || [];
  LIA_PLAN    = DATA.lia_plan || null;
  if (!LIA_COHORTS.length || !LIA_PEOPLE.length || !LIA_PLAN) {
    document.getElementById('plan-grid').innerHTML = '<p style="color:var(--muted);padding:20px;font-size:13px">Aucune classe avec date de rentrée. Configurez les classes dans « Classes &amp; élèves » d\'abord.</p>';
    return;
  }
  var sorted = DATA.classes.filter(function(c){ return c.startdate; }).sort(function(a,b){ return (parseStartDate(a.startdate)||new Date(0))-(parseStartDate(b.startdate)||new Date(0)); });
  LIA_START = parseStartDate(sorted[0].startdate);
  var diffMs = Date.now() - (LIA_START ? LIA_START.getTime() : 0);
  LIA_TODAY_W = Math.max(1, Math.floor(diffMs/(7*86400*1000))+1);
  // Build set of weeks that have real mdl_event sessions
  LIA_PLAN_EV_WEEKS = {};
  if (LIA_START && DATA.plan_events) {
    var liaStartTs = LIA_START.getTime() / 1000;
    DATA.plan_events.forEach(function(ev) {
      var diffSec = ev.timestart - liaStartTs;
      var w = Math.max(1, Math.floor(diffSec / (7 * 86400)) + 1);
      LIA_PLAN_EV_WEEKS[w] = (LIA_PLAN_EV_WEEKS[w] || 0) + 1;
    });
  }
  liaRender();
  // Le planning démarre toujours à la 1ère semaine de la classe la plus
  // ancienne (peut remonter à plusieurs mois, voire une année précédente) —
  // sans ça l'admin atterrit sur du passé et doit chercher "aujourd'hui" à
  // la main. Uniquement au premier chargement (pas après un enregistrement
  // d'affectation, pour ne pas perdre la position de défilement de l'admin).
  requestAnimationFrame(liaScrollToToday);
}

function liaScrollToToday() {
  var wrap = document.getElementById('plan-grid');
  var nowCell = wrap && wrap.querySelector('.pg-now');
  if (nowCell) nowCell.scrollIntoView({ inline: 'center', block: 'nearest' });
}

/* ===== INDISPONIBILITÉS À VALIDER ===== */
function renderDispoValidation() {
  var el = document.getElementById('dv-list');
  if (!el) return;
  var pending = (DATA.dispos || []).filter(function(d){ return d.status === 'pending'; });
  var resolved = (DATA.dispos || []).filter(function(d){ return d.status !== 'pending'; });

  var html = '';
  if (!pending.length) {
    html += '<div class="pg-ok"><b>Rien à valider.</b> Aucune déclaration en attente.</div>';
  } else {
    html += pending.map(function(d) {
      return '<div class="dv-card" id="dv-' + d.id + '">' +
        '<div class="dv-name">' + esc(d.formateur_nom) + '</div>' +
        '<div class="dv-dates">' + esc(d.date_from) + ' → ' + esc(d.date_to) + ' · ' + esc(d.motif_label) + '</div>' +
        '<div class="dv-acts">' +
          '<button class="btn mini primary" onclick="validateDispo(' + d.id + ',\'validated\')">Valider</button>' +
          '<button class="btn mini ghost" onclick="validateDispo(' + d.id + ',\'rejected\')">Refuser</button>' +
        '</div>' +
      '</div>';
    }).join('');
  }
  if (resolved.length) {
    html += resolved.slice(0, 5).map(function(d) {
      var badgeClass = d.status === 'validated' ? 'validated' : 'rejected';
      var badgeLabel = d.status === 'validated' ? '✓ Validée' : 'Refusée';
      return '<div class="dv-card" style="opacity:.7">' +
        '<div class="dv-name">' + esc(d.formateur_nom) + ' <span class="dv-badge ' + badgeClass + '">' + badgeLabel + '</span></div>' +
        '<div class="dv-dates">' + esc(d.date_from) + ' → ' + esc(d.date_to) + ' · ' + esc(d.motif_label) + '</div>' +
      '</div>';
    }).join('');
  }
  el.innerHTML = html;
}

function validateDispo(id, decision) {
  apiPost({action: 'validate_dispo', id: id, decision: decision}).then(function(d) {
    if (!d.ok) { toast('Erreur: ' + (d.msg || '')); return; }
    var row = (DATA.dispos || []).find(function(x){ return String(x.id) === String(id); });
    if (row) row.status = decision;
    renderDispoValidation();
    toast(decision === 'validated' ? 'Indisponibilité validée.' : 'Indisponibilité refusée.');
  }).catch(function() { toast('Erreur réseau.'); });
}

/* ===== RENDER CLASSES & ÉLÈVES ===== */
function renderCoh() {
  renderClassCards();
  renderFilterBtns();
  renderStudentsTable();
}

/* ===== HELPERS CURRICULUM ===== */
var FR_MONTHS = ['jan.','fév.','mars','avr.','mai','juin','juil.','août','sept.','oct.','nov.','déc.'];
var STAGE_WEEKS   = [4, 8, 12, 16, 20, 24, 28, 32];
var VEHICLE_WEEKS = [7, 13, 19];
var PHASES = { // [weekMin, weekMax] → label
  1:  'Introduction & réglementation',
  2:  'Introduction & réglementation',
  3:  'Introduction & réglementation',
  4:  'Stage entreprise',
  5:  'Premiers secours',
  6:  'Premiers secours',
  7:  'Premiers secours',
  8:  'Stage entreprise',
  9:  'Notions pédagogiques',
  10: 'Notions pédagogiques',
  11: 'Notions pédagogiques',
  12: 'Stage entreprise',
  13: 'Conduite accompagnée',
  14: 'Conduite accompagnée',
  15: 'Conduite accompagnée',
  16: 'Stage entreprise',
  17: 'Gestion des risques',
  18: 'Gestion des risques',
  19: 'Gestion des risques',
  20: 'Stage entreprise',
  21: 'Animation de séquences',
  22: 'Animation de séquences',
  23: 'Animation de séquences',
  24: 'Stage entreprise',
  25: 'Évaluation formative',
  26: 'Évaluation formative',
  27: 'Évaluation formative',
  28: 'Stage entreprise',
  29: 'Préparation examen',
  30: 'Préparation examen',
  31: 'Préparation examen',
  32: 'Stage entreprise',
  33: 'Certification ECSR',
  34: 'Certification ECSR',
  35: 'Certification ECSR',
};

function parseStartDate(startdate) {
  if (!startdate) return null;
  var s = startdate.length === 7 ? startdate + '-01' : startdate;
  return new Date(s);
}
function fmtDate(d) {
  return d.getDate() + ' ' + FR_MONTHS[d.getMonth()];
}
function fmtDateFull(d) {
  return d.getDate() + ' ' + FR_MONTHS[d.getMonth()] + ' ' + d.getFullYear();
}
function weekToDate(start, weekNum) {
  return new Date(start.getTime() + (weekNum - 1) * 7 * 24 * 3600 * 1000);
}
function currentWeekOf(startdate) {
  var start = parseStartDate(startdate);
  if (!start) return null;
  var diff = Date.now() - start.getTime();
  if (diff < 0) return 0;
  return Math.min(35, Math.floor(diff / (7 * 24 * 3600 * 1000)) + 1);
}

function renderClassCards() {
  var el = document.getElementById('class-cards');
  var cards = DATA.classes.map((c, i) => {
    var week  = currentWeekOf(c.startdate);
    var start = parseStartDate(c.startdate);
    var phase = (week && week > 0) ? PHASES[week] : null;

    var weekLabel = '';
    if (start) {
      weekLabel = 'Rentrée ' + fmtDateFull(start);
      if (week === 0) weekLabel += ' · pas encore commencée';
      else if (week > 0) weekLabel += ' · semaine ' + week + '/35';
    }

    var metaParts = [c.nb_members + ' élève' + (c.nb_members > 1 ? 's' : '')];
    if (c.sub_groups > 0) metaParts.push(c.sub_groups + ' sous-groupe' + (c.sub_groups > 1 ? 's' : '') + ' véhicule');
    var metaHtml = metaParts.join(' · ');
    if (phase) metaHtml += ' · phase <span class="cc-phase">« ' + phase + ' »</span>';

    // Milestones — inline text, vehicle in bold
    var milestonesHtml = '';
    if (start && week > 0 && week < 35) {
      var nextStageW   = STAGE_WEEKS.find(w => w > week);
      var nextVehicleW = VEHICLE_WEEKS.find(w => w > week);
      if (nextStageW || nextVehicleW) {
        var parts = [];
        if (nextStageW)   parts.push('stage (' + fmtDate(weekToDate(start, nextStageW)) + ')');
        if (nextVehicleW) parts.push('<strong>semaine véhicule (' + fmtDate(weekToDate(start, nextVehicleW)) + ', Lyon)</strong>');
        milestonesHtml = '<div class="cc-milestones">Prochaines échéances : ' + parts.join(' puis ') + '</div>';
      }
    }

    // Alert règlement
    var alertHtml = (c.unsigned_count > 0)
      ? '<div class="cc-alert">' + c.unsigned_count + ' règlement' + (c.unsigned_count > 1 ? 's' : '') + ' intérieur' + (c.unsigned_count > 1 ? 's' : '') + ' non signé' + (c.unsigned_count > 1 ? 's' : '') + '</div>'
      : '';

    return '<div class="class-card' + (activeFilter === String(c.id) ? ' active' : '') + '" id="cc-' + c.id + '" onclick="filterByClass(' + c.id + ')">' +
      '<button type="button" class="cc-delete" title="Supprimer la ' + TERM_CLASSE_LC + '" onclick="event.stopPropagation();deleteClass(' + c.id + ',\'' + esc(c.name).replace(/'/g,"\\'") + '\',' + c.nb_members + ')">&#10005;</button>' +
      '<div class="cc-name">' + esc(dispCohName(c.name)) + '</div>' +
      (weekLabel ? '<div class="cc-week">' + weekLabel + '</div>' : '') +
      '<div class="cc-meta">' + metaHtml + '</div>' +
      '<div class="cc-secteur" onclick="event.stopPropagation();editClassSecteur(' + c.id + ')">📍 ' +
        (c.secteur ? esc(c.secteur) : '<span class="cc-secteur-empty">Définir un secteur</span>') + '</div>' +
      (!c.secteur && c.secteur_suggested ? (
        '<div class="cc-secteur-sugg">' +
          '💡 Lia suggère <strong>' + esc(c.secteur_suggested) + '</strong> (' + c.secteur_suggested_count + '/' + c.secteur_known_count + ' élèves localisés) ' +
          '<button type="button" class="cc-secteur-valid" onclick="event.stopPropagation();validateSuggestedSecteur(' + c.id + ',\'' + esc(c.secteur_suggested).replace(/'/g,"\\'") + '\')">Valider</button>' +
        '</div>'
      ) : '') +
      '<div class="cc-prog-row"><span class="cc-prog-label">Progression e-learning</span><span class="cc-prog-pct">' + c.avg_prog + ' %</span></div>' +
      '<div class="prog-wrap"><div class="prog-fill" style="width:' + c.avg_prog + '%"></div></div>' +
      milestonesHtml + alertHtml +
      '</div>';
  });
  el.innerHTML = cards.join('');
}

function editClassSecteur(cohortId) {
  var c = DATA.classes.find(function(x) { return x.id === cohortId; });
  if (!c) return;
  var val = prompt('Secteur de la ' + TERM_CLASSE_LC + ' « ' + dispCohName(c.name) + ' » (ex : Nord, Sud, Centre-ville…), Lia priorise un formateur du même secteur pour les séances véhicule/salle :', c.secteur || c.secteur_suggested || '');
  if (val === null) return;
  saveClassSecteur(cohortId, val.trim());
}

// Accepte en un clic le secteur suggéré par Lia (déduit de la géoloc des
// élèves de la classe) sans repasser par la saisie manuelle.
function validateSuggestedSecteur(cohortId, secteur) {
  saveClassSecteur(cohortId, secteur);
}

function saveClassSecteur(cohortId, val) {
  apiPost({ action: 'save_class_secteur', cohortid: cohortId, secteur: val })
    .then(function(r) {
      if (r.ok) {
        var c = DATA.classes.find(function(x) { return x.id === cohortId; });
        if (c) c.secteur = val;
        renderClasses(); toast('Secteur enregistré');
      } else { toast('Erreur : ' + (r.msg || '?')); }
    })
    .catch(function() { toast('Erreur réseau'); });
}

function deleteClass(cohortId, name, nbMembers) {
  name = dispCohName(name);
  var msg = nbMembers > 0
    ? '⚠ Supprimer la ' + TERM_CLASSE_LC + ' « ' + name + '  » (' + nbMembers + ' élève' + (nbMembers > 1 ? 's' : '') + ') ? Les élèves ne seront pas supprimés, seulement retirés de cette ' + TERM_CLASSE_LC + '.'
    : 'Supprimer la ' + TERM_CLASSE_LC + ' « ' + name + ' » ?';
  if (!confirm(msg)) return;
  apiPost({ action: 'delete_class', cohortid: cohortId }).then(function(d) {
    if (!d.ok) { toast('Erreur : ' + (d.msg || '?')); return; }
    DATA.classes = DATA.classes.filter(function(c){ return c.id !== cohortId; });
    DATA.students.forEach(function(s){ if (s.cohort_id === cohortId) { s.cohort_id = null; s.cohort_name = null; } });
    if (activeFilter === String(cohortId)) activeFilter = 'all';
    renderClassCards();
    renderFilterBtns();
    renderStudentsTable();
    updateClassCards();
    toast(TERM_CLASSE + ' supprimée.');
  }).catch(function() { toast('Erreur réseau.'); });
}

function renderFilterBtns() {
  var container = document.getElementById('class-filter-btns');
  container.innerHTML = DATA.classes.map(c =>
    '<button class="filter-btn" data-filter="' + c.id + '" onclick="filterStudents(this)">' + esc(dispCohName(c.name)) + '</button>'
  ).join('');
}

function classLetter(className) {
  var m = String(className).match(/Classe\s+([A-Z])/i);
  return m ? m[1].toUpperCase() : '';
}

function filterByClass(cohortId) {
  activeFilter = String(cohortId);
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.toggle('on', b.dataset.filter === activeFilter));
  renderClassCards();
  var coh = DATA.classes.find(c => c.id === cohortId);
  document.getElementById('students-title').textContent = coh ? 'Élèves : ' + dispCohName(coh.name) : 'Tous les élèves';
  renderStudentsTable();
  document.getElementById('students-tbody').closest('.twrap').scrollIntoView({behavior:'smooth', block:'nearest'});
}

function filterStudents(btn) {
  activeFilter = btn.dataset.filter;
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('on'));
  btn.classList.add('on');
  document.getElementById('students-title').textContent = activeFilter === 'all' ? 'Tous les élèves'
    : 'Élèves : ' + (DATA.classes.find(c => String(c.id) === activeFilter)?.name || '');
  renderClassCards();
  renderStudentsTable();
}

function renderStudentsTable() {
  var filtered = activeFilter === 'all' ? DATA.students
    : activeFilter === 'inactive' ? DATA.students.filter(s => s.inactive)
    : activeFilter === 'low' ? DATA.students.filter(s => s.low)
    : DATA.students.filter(s => String(s.cohort_id) === activeFilter);

  var tbody = document.getElementById('students-tbody');
  if (!filtered.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="color:var(--muted);text-align:center;padding:20px">' +
      (DATA.students.length ? 'Aucun élève dans cette ' + TERM_CLASSE_LC + '.' : 'Aucun élève trouvé.') + '</td></tr>';
    return;
  }
  tbody.innerHTML = filtered.map(s => {
    var rowCls = s.needs_action ? ' style="background:rgba(214,69,69,.04)"' : '';
    var inactivePill = s.inactive ? ' <span class="chip c-ko">inactif &gt;7j</span>' : '';

    // Sous-groupe — editable (etait un simple badge calcule par ordre
    // alphabetique, ne reflete pas une vraie repartition, question du
    // 22/10/2026).
    var cohObj = DATA.classes.find(c => c.id === s.cohort_id);
    var letter = cohObj ? classLetter(cohObj.name) : '';
    var sgMax = parseInt((DATA.ecsr_params || {}).sous_groupes_nb, 10) || 4;
    var sgLabel = letter
      ? '<select data-uid="' + s.id + '" onchange="assignSubgroup(this)" style="font-size:12px;padding:4px 6px">' +
          Array.from({length: sgMax}, function(_, i) { return i + 1; }).map(function(n) {
            return '<option value="' + n + '"' + (s.sg_num === n ? ' selected' : '') + '>' + letter + n + '</option>';
          }).join('') +
        '</select>'
      : '<span style="color:var(--muted)">—</span>';

    // Classe (affectation)
    var classSelectHtml = '<select data-uid="' + s.id + '" data-current="' + (s.cohort_id || 0) + '" onchange="assignToClass(this)" style="font-size:12px;padding:4px 6px">' +
      '<option value="">—</option>' +
      DATA.classes.map(function(c) { return '<option value="' + c.id + '"' + (s.cohort_id === c.id ? ' selected' : '') + '>' + esc(dispCohName(c.name)) + '</option>'; }).join('') +
      '</select>';

    // Règlement
    var regBtn = s.reglement
      ? '<button class="reg-signed" onclick="toggleReglement(' + s.id + ',this)">✓ signé</button>'
      : '<button class="reg-unsigned" onclick="toggleReglement(' + s.id + ',this)">✗ non signé</button>';

    // E-learning
    var pctColor = s.low ? 'color:var(--red);font-weight:700' : '';
    var barHtml = '<span class="bar"><i style="width:' + s.pct + '%"></i></span> <span style="' + pctColor + '">' + s.pct + ' %</span>';

    // Présence visio
    var presHtml = s.presence < 70
      ? '<span class="pres-ko">' + s.presence + ' %</span><span class="pres-warning">· caméra coupée</span>'
      : '<span class="pres-ok">' + s.presence + ' %</span>';

    // Note
    var noteHtml = '<input class="note-input" type="text" value="' + esc(s.note) + '" placeholder="Ajouter une note…" data-uid="' + s.id + '" onblur="saveNote(this)" onkeydown="if(event.key===\'Enter\')this.blur()">';

    // Action button
    var actionBtn = s.needs_action
      ? '<button class="btn-agir" onclick="openMessageModal(' + s.id + ',\'' + esc(s.nom).replace(/'/g,"\\'") + '\')">Agir</button>'
      : '<a class="btn mini ghost" href="' + WWWROOT + '/user/view.php?id=' + s.id + '&course=1" target="_blank">Ouvrir</a>';
    var credsBtn = '<button class="btn mini ghost" title="Identifiants de connexion" onclick="openCredsModal(' + s.id + ',\'' + esc(s.nom).replace(/'/g,"\\'") + '\',\'' + esc(s.username || '').replace(/'/g,"\\'") + '\')">🔑</button>';
    var delBtn = '<button class="btn mini ghost" style="color:var(--red,#C0392B);border-color:var(--red,#C0392B)" title="Supprimer" onclick="deleteStudent(' + s.id + ',\'' + esc(s.nom).replace(/'/g,"\\'") + '\')">🗑</button>';

    return '<tr' + rowCls + '>' +
      '<td>' + esc(s.nom) + inactivePill + '</td>' +
      '<td>' + classSelectHtml + '</td>' +
      '<td>' + sgLabel + '</td>' +
      '<td>' + regBtn + '</td>' +
      '<td>' + barHtml + '</td>' +
      '<td>' + presHtml + '</td>' +
      '<td>' + noteHtml + '</td>' +
      '<td style="display:flex;gap:6px;align-items:center">' + actionBtn + credsBtn + delBtn + '</td>' +
      '</tr>';
  }).join('');
}

function toggleReglement(uid, btn) {
  apiPost({ action: 'toggle_reglement', userid: uid })
    .then(r => {
      if (r.ok) {
        var s = DATA.students.find(x => x.id === uid);
        if (s) {
          s.reglement = r.signed;
          s.needs_action = !s.reglement || s.presence < 70 || s.inactive;
        }
        renderStudentsTable();
        updateClassCards();
        toast(r.signed ? 'Règlement marqué signé' : 'Règlement retiré');
      }
    });
}

/* ===== SÉANCE MODAL ===== */
var _seanceWeek = 0, _seanceOff = 0, _seanceLetter = '';

function openSeanceModal(letter, week, off) {
  _seanceLetter = letter; _seanceWeek = week; _seanceOff = off;

  // Compute Monday date of that absolute week
  if (!LIA_START) return;
  var mondayDate = new Date(LIA_START.getTime() + (week - 1) * 7 * 86400 * 1000);
  var monStr = mondayDate.getDate() + ' ' + LIA_MONTHS_FR[mondayDate.getMonth()];
  var friDate = new Date(mondayDate.getTime() + 4 * 86400 * 1000);
  var friStr  = friDate.getDate() + ' ' + LIA_MONTHS_FR[friDate.getMonth()];

  document.getElementById('ms-title').textContent = 'Planifier une séance : ' + TERM_CLASSE + ' ' + letter + ' · S' + week;
  document.getElementById('ms-sub').textContent   = 'Semaine du ' + monStr + ' au ' + friStr;

  // Populate course selector
  var sel = document.getElementById('ms-course');
  sel.innerHTML = (DATA.courses_list || []).map(function(c) {
    return '<option value="' + c.id + '">' + esc(c.shortname) + ' – ' + esc(c.fullname) + '</option>';
  }).join('');

  // Populate formateur selector (DATA.lia_people, meme source que la vue
  // Intervenants) : necessaire pour que la seance n'apparaisse que dans
  // "Mes seances" du formateur choisi, pas de tous les enseignants du
  // module (utile notamment en cas de delegation Lia).
  var selF = document.getElementById('ms-formateur');
  selF.innerHTML = (DATA.lia_people || []).map(function(p) {
    return '<option value="' + p.id + '">' + esc(p.nom) + '</option>';
  }).join('');

  // Default name
  var rel = week - off;
  document.getElementById('ms-name').value = 'Séance ECSR · ' + TERM_CLASSE + ' ' + letter + ' · S' + week + (rel > 0 ? ' (rel. ' + rel + ')' : '');

  document.getElementById('modal-seance-bg').classList.add('on');
}

function closeSeanceModal() {
  document.getElementById('modal-seance-bg').classList.remove('on');
}

function saveSeance() {
  var courseid    = parseInt(document.getElementById('ms-course').value);
  var formateurid = parseInt(document.getElementById('ms-formateur').value);
  var name        = document.getElementById('ms-name').value.trim();
  var dayOff      = parseInt(document.getElementById('ms-day').value); // 0=Mon
  var timeStr     = document.getElementById('ms-time').value; // "14:00"
  var durMin      = parseInt(document.getElementById('ms-dur').value);

  if (!courseid || !formateurid || !name || !timeStr) { alert('Remplir tous les champs.'); return; }

  // Compute timestart Unix
  var mondayDate = new Date(LIA_START.getTime() + (_seanceWeek - 1) * 7 * 86400 * 1000);
  var sessionDate = new Date(mondayDate.getTime() + dayOff * 86400 * 1000);
  var parts = timeStr.split(':');
  sessionDate.setHours(parseInt(parts[0]), parseInt(parts[1]), 0, 0);
  var timestart = Math.floor(sessionDate.getTime() / 1000);

  var btn = document.querySelector('#modal-seance .btn.primary');
  btn.disabled = true; btn.textContent = 'Création...';

  apiPost({ action: 'create_session', courseid: courseid, formateurid: formateurid, timestart: timestart,
            duration_min: durMin, event_name: name })
    .then(function(d) {
      if (d.ok) {
        // Update local plan_events + rebuild week index
        DATA.plan_events = DATA.plan_events || [];
        var dayLabels = ['Lun','Mar','Mer','Jeu','Ven'];
        var hh = String(parseInt(parts[0])).padStart(2,'0'), mm = parts[1];
        DATA.plan_events.push({
          id: d.id, name: name, timestart: timestart,
          duration_h: durMin / 60, courseid: courseid,
          day_label: dayLabels[dayOff], hour_label: hh + ':' + mm
        });
        LIA_PLAN_EV_WEEKS[_seanceWeek] = (LIA_PLAN_EV_WEEKS[_seanceWeek] || 0) + 1;
        closeSeanceModal();
        liaRender();
        toast('Séance planifiée, visible dans espace formateur et élève');
      } else {
        alert('Erreur : ' + (d.msg || 'inconnue'));
      }
      btn.disabled = false; btn.textContent = 'Créer la séance';
    })
    .catch(function() {
      btn.disabled = false; btn.textContent = 'Créer la séance';
      alert('Erreur réseau.');
    });
}

/* ===== AFFECTATION MANUELLE (Planning & Lia) =====
   Une case cliquée (vue Intervenants ou Classes) ou un bouton "Modifier
   l'affectation" sur une alerte ouvrent la même modale : elle écrit/écrase
   une entrée unique par clé (formateur, semaine, classe) — voir
   lia_apply_overrides() côté serveur, qui la rend définitive (fix:true,
   jamais retouchée par le lissage automatique). */
var _liaAffectW = 0;

function liaFindEntry(pid, w, coh) {
  if (!pid || !coh || !LIA_PLAN || !LIA_PLAN.grid[pid] || !LIA_PLAN.grid[pid][w]) return null;
  return LIA_PLAN.grid[pid][w].find(function(e) { return e.coh === coh; }) || null;
}

function liaAffectOnClassChange() {
  var pid = parseInt(document.getElementById('lia-pid').value) || 0;
  var coh = document.getElementById('lia-coh').value;
  var e = liaFindEntry(pid, _liaAffectW, coh);
  document.getElementById('lia-type').value  = e ? e.type : 'visio';
  document.getElementById('lia-h').value     = e ? e.h : 2;
  document.getElementById('lia-label').value = e ? e.label : '';
  document.getElementById('lia-seq').value = e && e.seqid ? e.seqid : '';
  document.getElementById('lia-delete-btn').style.display = e ? '' : 'none';
}

function openLiaAffectModal(pid, w, coh) {
  if (!LIA_PLAN) return;
  _liaAffectW = w;

  var defaultCoh = coh;
  if (!defaultCoh && pid) {
    var es = (LIA_PLAN.grid[pid] && LIA_PLAN.grid[pid][w]) || [];
    if (es.length) defaultCoh = es[0].coh;
  }

  document.getElementById('lia-pid').innerHTML = '<option value="">Choisir</option>' +
    LIA_PEOPLE.map(function(p) { return '<option value="'+p.id+'"'+(pid===p.id?' selected':'')+'>'+esc(p.nom)+'</option>'; }).join('');
  document.getElementById('lia-coh').innerHTML = '<option value="">Choisir</option>' +
    LIA_COHORTS.map(function(c) { return '<option value="'+c.L+'"'+(defaultCoh===c.L?' selected':'')+'>'+TERM_CLASSE+' '+esc(c.L)+'</option>'; }).join('');
  document.getElementById('lia-seq').innerHTML = '<option value="">—</option>' +
    (DATA.seq_options || []).map(function(s) { return '<option value="'+s.id+'">'+esc(s.titre)+'</option>'; }).join('');

  document.getElementById('lia-title').textContent = 'Affectation manuelle : S' + w;
  document.getElementById('lia-sub').textContent = 'Semaine du ' + liaFmtDate(w);
  liaAffectOnClassChange();
  document.getElementById('modal-lia-bg').classList.add('on');
}

function closeLiaAffectModal() {
  document.getElementById('modal-lia-bg').classList.remove('on');
}

function liaApplyLocalOverride(pid, w, coh, type, label, h, seqid) {
  if (!LIA_PLAN.grid[pid]) LIA_PLAN.grid[pid] = {};
  if (!LIA_PLAN.grid[pid][w]) LIA_PLAN.grid[pid][w] = [];
  LIA_PLAN.grid[pid][w] = LIA_PLAN.grid[pid][w].filter(function(e) { return e.coh !== coh; });
  if (h > 0) LIA_PLAN.grid[pid][w].push({ coh: coh, type: type, label: label, h: h, fix: true, manual: true, seqid: seqid || '' });
}

function saveLiaAffect() {
  var pid      = parseInt(document.getElementById('lia-pid').value);
  var coh      = document.getElementById('lia-coh').value;
  var type     = document.getElementById('lia-type').value;
  var h        = parseFloat(document.getElementById('lia-h').value);
  var label    = document.getElementById('lia-label').value.trim();
  var seqid    = document.getElementById('lia-seq').value || '';
  var w        = _liaAffectW;

  if (!pid || !coh || !label || !(h > 0)) { alert('Remplir le formateur, la ' + TERM_CLASSE_LC + ', le module et un nombre d\'heures.'); return; }

  var btn = document.querySelector('#modal-lia .btn.primary');
  btn.disabled = true; btn.textContent = 'Enregistrement...';
  apiPost({ action: 'save_lia_override', pid: pid, w: w, coh: coh, type: type, label: label, h: h, seqid: seqid })
    .then(function(d) {
      btn.disabled = false; btn.textContent = 'Enregistrer';
      if (!d.ok) { alert('Erreur : ' + (d.msg || 'inconnue')); return; }
      liaApplyLocalOverride(pid, w, coh, type, label, h, seqid);
      closeLiaAffectModal();
      liaRender();
      toast('Affectation enregistrée');
    })
    .catch(function() { btn.disabled = false; btn.textContent = 'Enregistrer'; alert('Erreur réseau.'); });
}

function deleteLiaAffect() {
  var pid = parseInt(document.getElementById('lia-pid').value);
  var coh = document.getElementById('lia-coh').value;
  var w   = _liaAffectW;
  if (!pid || !coh) return;
  apiPost({ action: 'delete_lia_override', pid: pid, w: w, coh: coh })
    .then(function(d) {
      if (!d.ok) { alert('Erreur : ' + (d.msg || 'inconnue')); return; }
      liaApplyLocalOverride(pid, w, coh, null, null, 0);
      closeLiaAffectModal();
      liaRender();
      toast('Affectation supprimée');
    })
    .catch(function() { alert('Erreur réseau.'); });
}

function dismissLiaAlert(kind, pid, w) {
  apiPost({ action: 'dismiss_lia_alert', kind: kind, pid: pid || 0, w: w })
    .then(function(d) {
      if (!d.ok) { alert('Erreur : ' + (d.msg || 'inconnue')); return; }
      LIA_PLAN.alerts = LIA_PLAN.alerts.filter(function(a) { return !(a.kind === kind && (a.pid || null) === (pid || null) && a.w === w); });
      liaRender();
    })
    .catch(function() { alert('Erreur réseau.'); });
}

function openLiaHoursDetail(pid) {
  var per = LIA_PEOPLE.find(function(p) { return p.id === pid; });
  if (!per || !LIA_PLAN) return;
  var totals = {};
  var grid = LIA_PLAN.grid[pid] || {};
  Object.keys(grid).forEach(function(w) {
    (grid[w] || []).forEach(function(e) { totals[e.coh] = (totals[e.coh] || 0) + e.h; });
  });
  var cohLetters = Object.keys(totals).sort();
  var rows = cohLetters.map(function(c) {
    return '<div style="display:flex;justify-content:space-between"><span>'+TERM_CLASSE+' '+esc(c)+'</span><b>'+Math.round(totals[c])+' h</b></div>';
  }).join('');
  var total = cohLetters.reduce(function(s, c) { return s + totals[c]; }, 0);
  document.getElementById('lia-hours-title').textContent = 'Heures : ' + per.nom;
  document.getElementById('lia-hours-body').innerHTML =
    (rows || '<div style="color:var(--muted)">Aucune heure affectée.</div>') +
    '<div style="border-top:1px solid var(--line);margin-top:8px;padding-top:8px;display:flex;justify-content:space-between"><span>Total</span><b>'+Math.round(total)+' h</b></div>' +
    '<div style="color:var(--muted);font-size:12px">Capacité hebdo : '+per.cap+' h</div>';
  document.getElementById('modal-lia-hours-bg').classList.add('on');
}

function closeLiaHoursDetail() {
  document.getElementById('modal-lia-hours-bg').classList.remove('on');
}

function saveNote(input) {
  var uid  = parseInt(input.dataset.uid);
  var note = input.value.trim();
  var s = DATA.students.find(x => x.id === uid);
  if (s && s.note === note) return;
  apiPost({ action: 'save_note', userid: uid, note: note })
    .then(r => { if (r.ok && s) s.note = note; });
}

function assignSubgroup(sel) {
  var uid = parseInt(sel.dataset.uid, 10);
  var sg  = parseInt(sel.value, 10) || 0;
  apiPost({ action: 'save_subgroup', userid: uid, subgroup: sg })
    .then(function(r) {
      if (r.ok) {
        var s = DATA.students.find(function(x){ return x.id === uid; });
        if (s) s.sg_num = sg;
        toast('Sous-groupe mis à jour');
      } else { toast('Erreur : ' + (r.msg || '?')); }
    })
    .catch(function() { toast('Erreur réseau'); });
}

function assignToClass(sel) {
  var uid = parseInt(sel.dataset.uid);
  var newCohId = parseInt(sel.value) || 0;
  var prevCohId = parseInt(sel.dataset.current) || 0;

  var doAssign = function() {
    if (!newCohId) return;
    apiPost({ action: 'add_to_class', cohortid: newCohId, userid: uid })
      .then(r => {
        if (r.ok) {
          // Mettre à jour DATA localement
          var s = DATA.students.find(x => x.id === uid);
          if (s) {
            var coh = DATA.classes.find(c => c.id === newCohId);
            s.cohort_id = newCohId;
            s.cohort_name = coh ? coh.name : '';
            coh && coh.member_ids.push(uid);
          }
          sel.dataset.current = newCohId;
          toast('Élève affecté à la ' + TERM_CLASSE_LC);
          renderStudentsTable();
          updateClassCards();
        } else { toast('Erreur : ' + (r.msg || '?')); }
      });
  };

  if (prevCohId && prevCohId !== newCohId) {
    apiPost({ action: 'remove_from_class', cohortid: prevCohId, userid: uid })
      .then(r => { if (r.ok) doAssign(); });
  } else { doAssign(); }
}

function updateClassCards() {
  DATA.classes.forEach(c => {
    var members = DATA.students.filter(s => s.cohort_id === c.id);
    c.nb_members     = members.length;
    c.unsigned_count = members.filter(s => !s.reglement).length;
  });
  renderClassCards();
}

/* ===== MODAL ===== */
function openModal() {
  document.getElementById('modal-bg').classList.add('open');
  document.getElementById('modal-name').focus();
}
function closeModal() {
  document.getElementById('modal-bg').classList.remove('open');
  document.getElementById('modal-name').value = '';
  document.getElementById('modal-date').value = '';
}
function saveClass() {
  var name = document.getElementById('modal-name').value.trim();
  var date = document.getElementById('modal-date').value;
  if (!name) { document.getElementById('modal-name').focus(); return; }
  var btn = document.getElementById('modal-save');
  btn.disabled = true; btn.textContent = 'Création…';
  apiPost({ action: 'create_class', name: name, startdate: date })
    .then(r => {
      btn.disabled = false; btn.textContent = 'Créer la ' + TERM_CLASSE_LC;
      if (r.ok) {
        DATA.classes.push({ id: r.id, name: r.name, startdate: date, secteur: '', nb_members: 0, avg_prog: 0, member_ids: [] });
        closeModal();
        renderCoh();
        toast('Classe « ' + r.name + ' » créée');
      } else { toast('Erreur : ' + (r.msg || '?')); }
    })
    .catch(() => { btn.disabled = false; btn.textContent = 'Créer la ' + TERM_CLASSE_LC; toast('Erreur réseau'); });
}

/* ===== VIEWER DE COURS INTÉGRÉ (TOC + iframe) — même composant que
   espace-formateur.php, pour vérifier/éditer le contenu sans quitter le
   tableau de bord admin ni se faire rediriger vers Moodle nu. ===== */
var _ce = { id: 0, name: '', sections: [], contextid: 0 };
var _ceRefreshTimer = null;
// Moodle retient l'activation du mode edition pour toute la session
// (preference $USER->editing) : inutile de repasser par editmode.php (un
// aller-retour serveur en plus) une fois que c'est deja fait une premiere
// fois dans cette session.
var _ceEditingActivated = false;

var _ceIframeCSS = [
  '#page-header,#main-header,.navbar,#page-footer,.main-footer,footer,',
  '.breadcrumb-nav,.breadcrumb,#nav-drawer,.drawer-toggle,.skiplinks,',
  '.secondary-navigation,.tertiary-navigation,.activity-navigation,.section-navigation,#fsmod-header { display:none !important; }',
  // Zone "blocs visibles seulement par les admins" du theme mb2nl
  // (theme/mb2nl/layout/parts/region_adminblock.php, #adminblock-region) :
  // s'affiche automatiquement en mode edition pour les comptes ayant
  // moodle/site:config — pur bruit technique ici, aucun bloc n'y est place.
  '#adminblock-region { display:none !important; }',
  // Zone de bloc "Bottom" du theme mb2nl (theme/mb2nl/layout/parts/region_bottom.php) :
  // s'affiche meme vide, en style sombre (classe "dark1") — bandeau noir
  // sans contenu ni utilite ici. "#bottom-abcd" est un 2e bandeau distinct
  // (4 colonnes de blocs, meme style sombre "dark1") juste en dessous —
  // meme bug, oublie lors du 1er fix : lui aussi reste display:block et
  // visible meme quand ses 4 sous-regions sont vides.
  '#bottom,#bottom-abcd { display:none !important; }',
  // Bouton rond fixe "Ouvrir le panneau lateral" du theme mb2nl (.tgsdb_btn,
  // TOC native du cours) : flotte en position fixe par-dessus le contenu
  // (ex. sur une activite Video) — sans utilite ici puisque la plateforme a
  // deja sa propre TOC (colonne "Contenu du cours").
  '.tgsdb_btn { display:none !important; }',
  // Tiroir de messagerie natif Moodle (data-region="right-hand-drawer") :
  // reste visible malgre sa classe "hidden" (conflit de priorite avec le
  // theme) — sans interet ici puisque la plateforme a sa propre messagerie.
  '[data-region="right-hand-drawer"] { display:none !important; }',
  // Menu ⋮ d'une activite : ne garder que Parametres / Cacher / Dupliquer /
  // Supprimer. Classes stables de Moodle (course/lib.php,
  // course_get_cm_edit_actions + controlmenu.php), pas du texte devine.
  '.editing_movecm,.editing_moveright,.editing_moveleft,.editing_assign,.editing_groupmode { display:none !important; }',
  // Bouton "+ Ajouter un bloc" (selecteur stable, template core/add_block_button).
  '[data-region="add-block-button"] { display:none !important; }',
  // Fenetre "Ajouter une activite ou ressource" : ne garder que les types
  // reellement utilises sur cette plateforme (attribut data-internal stable,
  // voir course/format/templates/local/activitychooser/item.mustache).
  '[data-region="chooser-option-container"]:not([data-internal="page"]):not([data-internal="resource"]):not([data-internal="url"]):not([data-internal="lti"]):not([data-internal="quiz"]):not([data-internal="assign"]) { display:none !important; }',
  // Formulaire "Parametres" d'une activite : ne garder que Apparence /
  // Reglages courants / Conditions d'achevement + la case notification.
  // Retire Restriction d'acces / Tags / Competences (noms d'elements de
  // formulaire stables, voir course/moodleform_mod.php et admin/tool/lp/lib.php).
  '#id_availabilityconditionsheadercontainer,#id_tagshdrcontainer,#id_competenciessectioncontainer,',
  '.d-flex.align-items-center.mb-2:has(a[aria-controls="id_availabilityconditionsheadercontainer"]),',
  '.d-flex.align-items-center.mb-2:has(a[aria-controls="id_tagshdrcontainer"]),',
  '.d-flex.align-items-center.mb-2:has(a[aria-controls="id_competenciessectioncontainer"])',
  '{ display:none !important; }',
  // Formulaire "Parametres" d'un Quiz : ne garder que Temps / Note /
  // Feedback global. Retire Mise en page / Comportement des questions /
  // Options de relecture / Apparence / Restrictions sur les tentatives /
  // Safe Exam Browser (noms d'elements stables, voir mod/quiz/mod_form.php
  // et mod/quiz/accessrule/seb/classes/settings_provider.php).
  // Scope a "body.path-mod-quiz" : #id_displaycontainer est un id generique
  // (mod_form.php, header "display") reutilise TEL QUEL par d'autres
  // modules (ex. Fichier/resource, meme id) — sans ce filtre, masquer
  // "Apparence" sur un Quiz masquait aussi le meme bloc chez un Fichier,
  // empechant de choisir "Integrer" pour afficher la description avant le
  // PDF (elle etait injoignable, cachee par erreur).
  'body.path-mod-quiz #id_layouthdrcontainer,body.path-mod-quiz #id_interactionhdrcontainer,',
  'body.path-mod-quiz #id_reviewoptionshdrcontainer,body.path-mod-quiz #id_displaycontainer,',
  'body.path-mod-quiz #id_securitycontainer,body.path-mod-quiz #id_sebcontainer,',
  'body.path-mod-quiz .d-flex.align-items-center.mb-2:has(a[aria-controls="id_layouthdrcontainer"]),',
  'body.path-mod-quiz .d-flex.align-items-center.mb-2:has(a[aria-controls="id_interactionhdrcontainer"]),',
  'body.path-mod-quiz .d-flex.align-items-center.mb-2:has(a[aria-controls="id_reviewoptionshdrcontainer"]),',
  'body.path-mod-quiz .d-flex.align-items-center.mb-2:has(a[aria-controls="id_displaycontainer"]),',
  'body.path-mod-quiz .d-flex.align-items-center.mb-2:has(a[aria-controls="id_securitycontainer"]),',
  'body.path-mod-quiz .d-flex.align-items-center.mb-2:has(a[aria-controls="id_sebcontainer"])',
  '{ display:none !important; }',
  // Formulaire "Parametres" d'un Devoir : ne garder que Instructions de
  // l'activite / Fichiers supplementaires / Types de remise / Note.
  // Retire Types de feedback / Reglages de la remise des travaux / Reglages
  // de remises en groupe / Notifications (noms d'elements stables, voir
  // mod/assign/mod_form.php et mod/assign/locallib.php).
  '#id_feedbacktypescontainer,#id_submissionsettingscontainer,',
  '#id_groupsubmissionsettingscontainer,#id_notificationscontainer,',
  '.d-flex.align-items-center.mb-2:has(a[aria-controls="id_feedbacktypescontainer"]),',
  '.d-flex.align-items-center.mb-2:has(a[aria-controls="id_submissionsettingscontainer"]),',
  '.d-flex.align-items-center.mb-2:has(a[aria-controls="id_groupsubmissionsettingscontainer"]),',
  '.d-flex.align-items-center.mb-2:has(a[aria-controls="id_notificationscontainer"])',
  '{ display:none !important; }',
  // Formulaire "Question a choix multiple" (question/type/multichoice/
  // edit_multichoice_form.php) : ne garder que Nom / Texte de la question /
  // Note par defaut / Une seule reponse / les Choix (texte + note de chaque
  // reponse). Retire Feedback general, Melanger les reponses, Numeroter les
  // choix, Identifiant de la question, Feedback combine, Reglages tentatives
  // multiples (penalite + indices), Tags, et le commentaire de chaque
  // reponse (formateur remplit juste question + reponses, sans ces options
  // techniques secondaires).
  '#id_combinedfeedbackhdr,#id_multitriesheader,#id_tagsheader,#id_coursetagsheader,',
  '#fitem_id_generalfeedback,#fitem_id_answernumbering,',
  '#fitem_id_idnumber,[id^="fitem_id_feedback_"]',
  '{ display:none !important; }',
  // "Melanger les reponses" : cette ligne (advcheckbox) n'a pas d'id propre
  // sur son conteneur (particularite de ce template Moodle) — on cible via
  // :has() la ligne qui contient la case a cocher #id_shuffleanswers.
  '.row.fitem:has(#id_shuffleanswers) { display:none !important; }',
  'html, body { background:#fff !important; }',
  '#page,#page.container-fluid,#region-main,#region-main-box { padding:0 !important; margin:0 !important; max-width:none !important; }',
  '.btn-secondary,.btn.btn-secondary { background:#fff !important; border-color:#2E9488 !important; color:#2E9488 !important; }',
  '.btn-primary,.btn.btn-primary { background:#2E9488 !important; border-color:#1C5F58 !important; color:#fff !important; }',
  // Survol des boutons : sans ces regles, l'etat :hover retombe sur l'accent
  // dore par defaut du theme (memes couleurs de base que ci-dessus, juste
  // plus foncees, pour garder un retour visuel au survol).
  // Catch-all large : le theme colore beaucoup de boutons via des
  // selecteurs varies (.addresourcemodchooser a, data-action="add", etc.)
  // qui ne portent pas forcement .btn-primary/.btn-secondary. Le simple
  // !important suffit a gagner face aux regles du theme (non-!important).
  '.btn:hover { background:#1C5F58 !important; border-color:#1C5F58 !important; color:#fff !important; }',
  '.btn-primary:hover,.btn.btn-primary:hover { background:#1C5F58 !important; border-color:#1C5F58 !important; color:#fff !important; }',
  '.btn-secondary:hover,.btn.btn-secondary:hover { background:#EAF6F4 !important; border-color:#1C5F58 !important; color:#1C5F58 !important; }',
  // Liens de contenu (ex. fichier joint a un devoir) : couleur d'accent
  // doree par defaut du theme, remplacee par le teal de la charte (meme
  // regle deja en place cote lecteur eleve, _dvIframeCSS).
  'a { color:#2E9488 !important; }',
  'html { scrollbar-color: #2E9488 #f0f0f0 !important; }',
  '::-webkit-scrollbar { width: 10px; }',
  '::-webkit-scrollbar-track { background: #f0f0f0; }',
  '::-webkit-scrollbar-thumb { background: #2E9488 !important; border-radius: 6px; }',
  '::-webkit-scrollbar-thumb:hover { background: #1C5F58 !important; }',
  // Titre et description d'une activite (ex. nom du fichier + texte saisi
  // en "Apparence > Afficher la description") : par defaut en noir brut,
  // sans rapport avec la charte du reste de l'interface. On applique la
  // couleur/typo d'accent automatiquement, sans que l'admin ait besoin
  // d'ajouter du style HTML lui-meme a chaque fois.
  '.activity-name { color:#1C5F58 !important; font-weight:800 !important; }',
  '.activity-description { background:#E3F2EF !important; border-radius:10px; padding:14px 18px; }',
  '.activity-description strong { color:#1C5F58 !important; }',
  // Contenu d'une Page/Etiquette : classe differente (.box.generalbox, pas
  // .activity-description), meme logique de style automatique.
  '.box.generalbox { background:#E3F2EF !important; border-radius:10px; padding:14px 18px; }',
  '.box.generalbox strong { color:#1C5F58 !important; }',
  // Boutons "+" d\'insertion d\'activite entre deux activites existantes —
  // tres discrets par defaut (icone minuscule dans un dropdown Bootstrap).
  // Cible specifiquement id="dropdownMenuButton-XXX" (le declencheur du menu
  // "Ajouter une activite ou une ressource"), pas les autres dropdowns
  // (menus d\'action par activite, etc.) pour ne pas les denaturer.
  '[id^="dropdownMenuButton-"] { display:inline-flex !important; align-items:center !important; justify-content:center !important;',
  'width:30px !important; height:30px !important; min-width:30px !important; background:#2E9488 !important; color:#fff !important;',
  'border-radius:50% !important; opacity:1 !important; visibility:visible !important; margin:3px !important; box-shadow:0 1px 4px rgba(0,0,0,.25) !important; }',
  '[id^="dropdownMenuButton-"]:hover { background:#267d73 !important; }',
  '[id^="dropdownMenuButton-"] i,[id^="dropdownMenuButton-"] .icon { color:#fff !important; font-size:15px !important; }',
  // Neutralise l\'ancienne UI theme_mb2nl (TOC "a droite", voir
  // l3m_toc_scroll.js + theme_mb2nl customcss) qui masque .course-content et
  // affiche un message tant qu\'elle n\'a pas cree son propre
  // #l3m-activity-iframe-container — ce qui n\'arrive jamais quand
  // course/view.php est charge dans NOTRE iframe imbriquee. On force donc le
  // vrai contenu du cours a rester visible ici, sans toucher a cette
  // fonctionnalite ailleurs (visite directe hors de notre viewer).
  // Meme liste de selecteurs que la regle a neutraliser (specificite egale,
  // le nôtre gagne car injecte apres, a source-order egale) + doublement de
  // l\'ID pour le ::before (specificite superieure a leur selecteur compose
  // de :not()/:has()), plutot qu\'un simple override qui perdait face a une
  // regle plus specifique malgre le !important des deux cotes.
  '.path-course-view #region-main .course-content,',
  '.course-view-topics #region-main .course-content,',
  'body[class*="course-view"] #region-main .course-content { display:block !important; }',
  '#page-content#page-content::before { content:none !important; display:none !important; }',
  // Lien "Decouvrir plus d'activites sur Place du marche Moodle" (fenetre
  // "Ajouter une activite ou ressource") — renvoie vers un service externe
  // (marketplace.moodle.com), pas pertinent pour nos formateurs/admins.
  'div:has(> a[href*="marketplace.moodle.com"]) { display:none !important; }',
  // Zone de blocs (ex. "side-pre") : hors edition, elle est deja vide et
  // invisible, mais en mode edition Moodle affiche un encadre pointille avec
  // le nom brut de la zone ("SIDE-PRE") pour inviter a y glisser un bloc —
  // pas pertinent dans notre visualiseur (attribut stable, voir
  // lib/classes/output/core_renderer.php, methode blocks()).
  '[data-blockregion] { display:none !important; }',
  // Vue de correction d'un devoir (mod/assign, action=grader) : le filtre de
  // liste et "Reinitialiser les preferences" n'ont pas de sens quand on
  // arrive directement sur UN eleve precis (attributs stables, voir
  // mod/assign/templates/grading_navigation_user_selector.mustache).
  '[data-region="user-filters"],[data-region="user-resettable"] { display:none !important; }',
  // Selecteur de fichiers Moodle : texte des onglets en noir (illisible par
  // defaut, trop clair) et couleur d'accent orange remplacee par le vert
  // de la charte (voir repository/filepicker.js, classes .fp-repo/.nav-link
  // ajoutees/retirees dynamiquement selon l'onglet actif).
  '.fp-repo .fp-repo-name { color:#1A1714 !important; }',
  '.fp-repo-area .fp-repo .nav-link.active { background:#2E9488 !important; border-color:#2E9488 !important; }',
  '.fp-repo-area .fp-repo .nav-link.active .fp-repo-name { color:#fff !important; }',
  // Panneau "Deposer un fichier" : retire "Enregistrer sous" et "Choisir une
  // licence" (attributs stables, voir repository/filepicker.js).
  '.fp-saveas,.fp-setlicense { display:none !important; }',
  // Gestionnaire de fichiers (zone "Fichiers" d'un devoir/ressource) : menu
  // contextuel "..." par fichier, ouvre les memes champs superflus
  // (licence...) — classe stable core Moodle, voir theme/boost/scss/moodle/filemanager.scss.
  '.fp-contextmenu { display:none !important; }',
  // Vue de correction d'un devoir : retire le crayon "Modifier les reglages"
  // (lien vers course/modedit.php, risque de renommage/reglage accidentel
  // en pleine correction — attribut stable, voir
  // mod/assign/templates/grading_navigation.mustache) et le bandeau
  // "remis en avance de X" (classe stable, voir
  // mod/assign/classes/output/renderer.php::get_time_remaining).
  '[data-region="assignment-info"] a[href*="modedit.php"] { display:none !important; }',
  '.earlysubmission { display:none !important; }',
].join('\n');

function ceInjectCSS(iframe, css) {
  try {
    var doc = iframe.contentDocument || iframe.contentWindow.document;
    if (!doc || !doc.head) return;
    if (!doc.getElementById('l3m-ce-style')) {
      var s = doc.createElement('style');
      s.id = 'l3m-ce-style';
      s.textContent = css || _ceIframeCSS;
      doc.head.appendChild(s);
    }
    ceHideChooserAllTab(doc);
    ceHideRepoTabs(doc);
    ceFixGraderPlaceholder(doc);
    ceAutoFixVideoUrl(doc);
    ceWatchInplaceEdits(doc);
    // Une suppression d'activite passe par une page de confirmation qui
    // recharge entierement l'iframe (pas une simple mise a jour AJAX) : ni
    // les evenements ni l'observateur de ceWatchInplaceEdits ne la voient
    // dans ce cas, puisqu'ils sont attaches sur le document APRES coup, une
    // fois la suppression deja faite sur la page precedente. On rafraichit
    // donc la liste "Contenu du cours" a chaque chargement de l'iframe, ce
    // qui couvre aussi bien ce cas que les changements de completion en
    // navigant simplement d'une activite a l'autre.
    if (_ce.id) ceRefreshSections();
    // Evite le flash de la page Moodle non stylee quand on navigue via un
    // lien clique DANS l'iframe (ex. "Parametres" d'une activite) plutot
    // que via notre propre changement de src (deja masque par l'opacite
    // ailleurs) : on cache l'iframe dès que cette page commence a se quitter.
    try {
      doc.defaultView.addEventListener('pagehide', function() {
        iframe.style.opacity = '0';
      });
    } catch (e2) {}
  } catch (e) {}
}

// Champ "Changer d'utilisateur" de la vue de correction d'un devoir : texte
// du parametre "placeholder" (get_string('changeuser', 'mod_assign')) passe
// a l'autocomplete Moodle (lib/amd/src/form-autocomplete.js), insere en JS
// bien apres le chargement de l'iframe — selon l'etat du champ, il apparait
// soit comme attribut placeholder d'un <input>, soit comme texte affiche
// directement dans la case de selection (aucune classe propre pour ce cas,
// on repere donc le texte exact, meme principe que ceHideChooserAllTab).
// Pas de traduction Moodle a modifier globalement : on ne corrige que
// l'affichage ici.
function ceFixGraderPlaceholder(doc) {
  var fix = function() {
    doc.querySelectorAll('input[placeholder="Changer d’utilisateur"]').forEach(function(el) {
      el.placeholder = "Changer d'élève";
    });
    // Scan large (tout le document, pas seulement [data-region="user-selector"])
    // : le composant grading_app peut re-rendre cette zone via une structure
    // qui echappe a ce scope precis selon le contexte (grading vs grader).
    // Le filtre "feuille + texte exact" evite tout faux positif ailleurs.
    doc.querySelectorAll('*').forEach(function(el) {
      if (el.children.length === 0 && el.textContent.trim() === "Changer d’utilisateur") {
        el.textContent = "Changer d'élève";
      }
    });
  };
  // Toujours execute, meme si l'observateur est deja attache : l'app JS de
  // Moodle (grading_app) peut re-rendre cette zone plusieurs fois de suite
  // au chargement, et notre correction perdre cette course une fois sur
  // deux si on ne la retente qu'au prochain changement du DOM.
  fix();
  if (doc._l3mGraderObserver) return;
  try {
    var obs = new MutationObserver(fix);
    obs.observe(doc.body, {childList: true, subtree: true});
    doc._l3mGraderObserver = obs;
  } catch (e) {}
}

// L'onglet "Tout" de la fenetre "Ajouter une activite ou ressource" n'a pas
// de classe propre (meme markup que les autres onglets) — on le masque en
// comparant le texte, via un observateur car la fenetre est injectee bien
// apres le chargement de l'iframe (ouverture au clic sur le "+").
function ceHideChooserAllTab(doc) {
  if (doc._l3mChooserObserver) return;
  var hide = function() {
    var nav = doc.querySelector('.modchoosernav');
    if (!nav) return;
    nav.querySelectorAll('.nav-link').forEach(function(el) {
      if (el.textContent.trim() === 'Tout') el.style.display = 'none';
    });
    // "URL" -> "Vidéo" : c'est l'option utilisee ici pour ajouter un lien
    // video (Glowbl, YouTube...), le nom natif Moodle preterait a confusion.
    var urlTile = doc.querySelector('[data-region="chooser-option-container"][data-internal="url"] .optionname');
    if (urlTile && urlTile.textContent.trim() === 'URL') urlTile.textContent = 'Vidéo';
  };
  hide();
  try {
    var obs = new MutationObserver(function(muts) {
      for (var i = 0; i < muts.length; i++) {
        if (muts[i].addedNodes && muts[i].addedNodes.length) { hide(); break; }
      }
    });
    obs.observe(doc.body, {childList: true, subtree: true});
    doc._l3mChooserObserver = obs;
  } catch (e) {}
}

// "Selecteur de fichiers" Moodle (repository/filepicker.js) : chaque onglet
// n'a qu'un id numerique d'instance propre au site (pas de type stable),
// donc on filtre par le nom affiche (.fp-repo-name) — meme principe que
// ceHideChooserAllTab. Ne garde que "Deposer un fichier" et "Fichiers
// recents", les deux seuls cas d'usage reels d'un formateur/admin.
// Sur le formulaire d'une activite "Video" (mod_url), convertit
// automatiquement un lien YouTube colle sous sa forme normale
// (youtube.com/watch?v=ID ou youtu.be/ID) vers le format "embed/ID" —
// le seul que YouTube autorise a etre affiche dans une iframe (sa page de
// visionnage normale bloque explicitement le cadrage). Bascule aussi
// l'affichage sur "Integrer" pour que la video se lance directement au
// clic, sans que l'admin ait besoin de connaitre cette distinction.
function ceAutoFixVideoUrl(doc) {
  var urlInput = doc.getElementById('id_externalurl');
  if (!urlInput || urlInput._l3mVideoWatched) return;
  urlInput._l3mVideoWatched = true;
  var convert = function() {
    var val = urlInput.value.trim();
    if (/youtube\.com\/embed\//.test(val)) return; // deja au bon format
    var m = val.match(/[?&]v=([a-zA-Z0-9_-]{6,})/) || val.match(/youtu\.be\/([a-zA-Z0-9_-]{6,})/);
    if (!m) return;
    urlInput.value = 'https://www.youtube.com/embed/' + m[1];
    var disp = doc.getElementById('id_display');
    if (disp) disp.value = '1'; // RESOURCELIB_DISPLAY_EMBED
  };
  urlInput.addEventListener('blur', convert);
  urlInput.addEventListener('change', convert);
  var form = urlInput.closest('form');
  if (form) form.addEventListener('submit', convert, true);
}

function ceHideRepoTabs(doc) {
  if (doc._l3mRepoObserver) return;
  var HIDE = ['Banque de contenus', 'Fichiers disponibles', 'Téléchargement d’URL', 'Fichiers personnels', 'Wikimedia'];
  var hide = function() {
    doc.querySelectorAll('.fp-repo').forEach(function(el) {
      var nameEl = el.querySelector('.fp-repo-name');
      if (nameEl && HIDE.indexOf(nameEl.textContent.trim()) !== -1) {
        el.style.display = 'none';
      }
    });
  };
  hide();
  try {
    var obs = new MutationObserver(hide);
    obs.observe(doc.body, {childList: true, subtree: true});
    doc._l3mRepoObserver = obs;
  } catch (e) {}
}

function ceMobileBack() {
  var body = document.querySelector('.course-modal-body');
  if (body) body.classList.remove('mob-content');
}

function ceSelectMod(cmid, modtype, el) {
  document.querySelectorAll('.det-mod').forEach(function(e){ e.classList.remove('active'); });
  document.querySelectorAll('.det-mod-dot').forEach(function(e){ e.classList.remove('active-dot'); });
  if (window.innerWidth <= 760) {
    var body = document.querySelector('.course-modal-body');
    if (body) body.classList.add('mob-content');
  }
  if (el) {
    el.classList.add('active');
    var dot = el.querySelector('.det-mod-dot');
    if (dot) dot.classList.add('active-dot');
  }
  var url = WWWROOT + '/mod/' + modtype + '/view.php?id=' + cmid;
  var iframe  = document.getElementById('ce-iframe');
  var empty   = document.getElementById('ce-empty');
  var loading = document.getElementById('ce-loading');
  iframe.style.opacity = '0';
  loading.style.display = 'flex';
  iframe.onload = function() { ceInjectCSS(iframe); iframe.style.opacity = '1'; loading.style.display = 'none'; };
  iframe.src = url;
  iframe.style.display = 'block';
  empty.style.display  = 'none';
  // Meme filet de securite qu'en mode edition (ceOpenEditMode) : certaines
  // pages (notamment le "mode plein ecran" de theme_mb2nl sur une activite
  // achevee a 100%) ne se stylent pas de facon fiable au seul onload, sans
  // qu'on ait pu determiner precisement pourquoi — on reinjecte donc le CSS
  // periodiquement pendant que cette activite reste affichee.
  if (_ceRefreshTimer) clearInterval(_ceRefreshTimer);
  _ceRefreshTimer = setInterval(function() { ceInjectCSS(iframe); }, 1500);
}

function ceRenderSections(sections) {
  var box = document.getElementById('ce-sections');
  // Retient quelles sections etaient depliees avant de reconstruire tout le
  // HTML : ceRefreshSections() est appelee tres souvent (a chaque
  // navigation dans l'iframe, y compris ouvrir une simple activite), et
  // sans ça chaque rafraichissement refermait toutes les sections — d'ou
  // l'impression que cliquer sur "›" "ne marche pas".
  var openIdx = {};
  document.querySelectorAll('#ce-sections .det-mods.open').forEach(function(el){
    openIdx[el.id.replace('ce-mods-', '')] = true;
  });
  if (!sections || !sections.length) {
    box.innerHTML = '<p style="padding:20px 18px;color:var(--muted);font-size:13px">Aucun contenu.</p>';
    return;
  }
  var html = '';
  sections.forEach(function(s, si) {
    var allDone = s.total > 0 && s.done_n === s.total;
    var isOpen  = !!openIdx[si];
    html += '<div class="det-sec" id="ce-sec-' + si + '">';
    html += '<div class="det-sec-head" onclick="ceToggleSec(' + si + ')">';
    html += '<div class="det-sec-num' + (allDone ? ' done' : '') + '">' + s.num + '</div>';
    html += '<div class="det-sec-info"><div class="det-sec-name">' + esc(s.name) + '</div>';
    if (s.total > 0) html += '<div class="det-sec-count">(' + s.done_n + '/' + s.total + ')</div>';
    html += '</div><div class="det-sec-arrow' + (isOpen ? ' open' : '') + '" id="ce-arr-' + si + '">›</div></div>';
    html += '<div class="det-mods' + (isOpen ? ' open' : '') + '" id="ce-mods-' + si + '">';
    s.mods.forEach(function(m) {
      var dotCls = m.done ? ' done' : '';
      html += '<div class="det-mod" onclick="ceSelectMod(' + m.cmid + ',\'' + m.modtype + '\',this)">';
      html += '<div class="det-mod-dot' + dotCls + '"></div>';
      html += '<div class="det-mod-name">' + esc(m.name) + '</div></div>';
    });
    html += '</div></div>';
  });
  box.innerHTML = html;
}

function ceToggleSec(idx) {
  var mods = document.getElementById('ce-mods-' + idx);
  var arr  = document.getElementById('ce-arr-' + idx);
  if (!mods) return;
  mods.classList.toggle('open');
  arr.classList.toggle('open');
}
function ceUnfoldAll() {
  document.querySelectorAll('#ce-sections .det-mods').forEach(function(el){ el.classList.add('open'); });
  document.querySelectorAll('#ce-sections .det-sec-arrow').forEach(function(el){ el.classList.add('open'); });
}
function ceFilter(val) {
  var q = val.toLowerCase();
  document.querySelectorAll('#ce-sections .det-sec').forEach(function(sec) {
    var mods = sec.querySelectorAll('.det-mod');
    var anyVisible = false;
    mods.forEach(function(m) {
      var match = !q || m.querySelector('.det-mod-name').textContent.toLowerCase().indexOf(q) >= 0;
      m.style.display = match ? '' : 'none';
      if (match) anyVisible = true;
    });
    sec.style.display = (!q || anyVisible) ? '' : 'none';
    if (q && anyVisible) {
      sec.querySelector('.det-mods').classList.add('open');
      sec.querySelector('.det-sec-arrow').classList.add('open');
    }
  });
}

function ceOpenEditMode() {
  document.querySelectorAll('.det-mod').forEach(function(e){ e.classList.remove('active'); });
  document.querySelectorAll('.det-mod-dot').forEach(function(e){ e.classList.remove('active-dot'); });
  var iframe   = document.getElementById('ce-iframe');
  var prefetch = document.getElementById('ce-prefetch-iframe');
  var empty    = document.getElementById('ce-empty');
  var loading  = document.getElementById('ce-loading');
  var courseUrl = WWWROOT + '/course/view.php?id=' + _ce.id;

  // Le prechargement (cePrefetchEditMode) est deja pret pour ce cours : on
  // echange juste les deux iframes (id + visibilite), sans nouveau
  // chargement, pour un affichage quasi instantane.
  var canSwap = prefetch.dataset.prefetchCourseId === String(_ce.id) && prefetch.dataset.prefetchReady === '1';
  if (canSwap) {
    prefetch.id = 'ce-iframe';
    iframe.id = 'ce-prefetch-iframe';
    delete prefetch.dataset.prefetchCourseId;
    delete prefetch.dataset.prefetchReady;
    prefetch.style.display = 'block';
    prefetch.style.opacity = '1';
    iframe.style.display = 'none';
    iframe.style.opacity = '0';
    empty.style.display = 'none';
    loading.style.display = 'none';
    iframe = prefetch;
  } else {
    iframe.style.opacity = '0';
    loading.style.display = 'flex';
    iframe.onload = function() { ceInjectCSS(iframe); iframe.style.opacity = '1'; loading.style.display = 'none'; };
    if (_ce.contextid && !_ceEditingActivated) {
      // Seulement la premiere fois : $USER->editing reste actif pour la
      // session ensuite, pas besoin de repasser par ce redirect (gagne un
      // aller-retour serveur).
      iframe.src = WWWROOT + '/editmode.php?context=' + _ce.contextid +
        '&pageurl=' + encodeURIComponent(courseUrl) + '&setmode=1&sesskey=' + SESSKEY;
      _ceEditingActivated = true;
    } else {
      iframe.src = courseUrl;
    }
    iframe.style.display = 'block';
    empty.style.display  = 'none';
  }
  // Rafraichissement automatique de la liste "Contenu du cours" tant que le
  // mode edition est ouvert : plus simple et plus fiable que d'essayer de
  // detecter precisement chaque action Moodle (ajout, suppression, masquage,
  // deplacement...), dont les mecanismes internes varient et ne declenchent
  // pas toujours les evenements attendus. On reinjecte aussi le CSS a chaque
  // tick (sans effet si deja present, voir le garde-fou dans ceInjectCSS) :
  // certaines pages internes (ex. course/section.php, atteinte via une
  // sous-section) utilisent un autre gabarit Moodle ou l'injection au
  // premier chargement peut echouer silencieusement — ce filet corrige
  // l'affichage sans qu'il faille identifier la cause exacte au cas par cas.
  if (_ceRefreshTimer) clearInterval(_ceRefreshTimer);
  _ceRefreshTimer = setInterval(function() {
    ceInjectCSS(iframe);
    if (_ce.id) ceRefreshSections();
  }, 2500);
}

function ceRenderBreadcrumb(crumbs) {
  var el = document.getElementById('ce-breadcrumb');
  if (!crumbs || !crumbs.length) { el.innerHTML = ''; return; }
  el.innerHTML = crumbs.map(function(c, i) {
    var isLast = i === crumbs.length - 1;
    return (i > 0 ? '<span class="dv-crumb-sep">&gt;</span>' : '') +
      '<span' + (isLast ? ' class="dv-crumb-current"' : '') + '>' + esc(c) + '</span>';
  }).join('');
}

function openCourseIframe(courseId, courseName) {
  _ce.id = courseId; _ce.name = courseName;
  var ceBody = document.querySelector('.course-modal-body');
  if (ceBody) ceBody.classList.remove('mob-content'); // repartir sur la liste des sections en mobile
  document.getElementById('course-modal-title').textContent = courseName;
  document.getElementById('ce-iframe').style.display = 'none';
  document.getElementById('ce-iframe').src = '';
  document.getElementById('ce-empty').style.display = 'flex';
  document.getElementById('ce-search-inp').value = '';
  document.getElementById('ce-breadcrumb').innerHTML = '';
  document.getElementById('ce-sections').innerHTML = '<p style="padding:20px 18px;color:var(--muted);font-size:13px">Chargement…</p>';
  document.getElementById('course-modal-bg').classList.add('on');
  ceRefreshSections();
}

// Recharge la liste des sections/activites du cours ouvert (_ce.id) depuis
// le serveur, sans reinitialiser le reste de la modale (recherche, iframe
// ouverte...). Utilise a l'ouverture (openCourseIframe) et pour refleter en
// direct un renommage fait en mode edition (voir ceWatchInplaceEdits) sans
// que l'admin ait besoin de recharger toute la page.
function ceRefreshSections() {
  var courseId = _ce.id;
  // Anti-cache (_ts) : par precaution, pour exclure toute reponse mise en
  // cache par le navigateur sur cette requete appelee tres frequemment.
  fetch(window.location.pathname + '?action=course_detail&courseid=' + courseId + '&_ts=' + Date.now(), {cache: 'no-store'})
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (!d.ok || _ce.id !== courseId) return;
      _ce.sections = d.sections;
      _ce.contextid = d.contextid;
      ceRenderBreadcrumb(d.breadcrumb);
      ceRenderSections(d.sections);
      // Une seule fois par cours ouvert (pas a chaque rafraichissement
      // periodique) : _ce.contextid n'est connu qu'a partir d'ici, donc
      // c'est le premier moment ou le prechargement peut passer par
      // editmode.php correctement si besoin.
      if (_ce.prefetchedFor !== courseId) {
        _ce.prefetchedFor = courseId;
        cePrefetchEditMode(courseId);
      }
    })
    .catch(function() {
      document.getElementById('ce-sections').innerHTML =
        '<p style="padding:20px 18px;color:var(--muted);font-size:13px">Erreur réseau.</p>';
    });
}

// Precharge en arriere-plan (iframe cachee) le mode edition de ce cours, des
// l'ouverture de "Mes modules" : si l'utilisateur clique ensuite sur
// "Activer le mode edition", ceOpenEditMode peut afficher ce resultat
// quasi instantanement au lieu de charger from scratch. A noter : ce
// prechargement passe par editmode.php la premiere fois de la session, ce
// qui active $USER->editing cote serveur meme si l'utilisateur ne clique
// jamais sur le bouton — accepte ici car sans consequence pour un
// formateur/admin deja autorise a editer.
function cePrefetchEditMode(courseId) {
  var prefetch = document.getElementById('ce-prefetch-iframe');
  if (!prefetch) return;
  prefetch.dataset.prefetchCourseId = String(courseId);
  prefetch.dataset.prefetchReady = '0';
  var courseUrl = WWWROOT + '/course/view.php?id=' + courseId;
  prefetch.onload = function() {
    try {
      if (prefetch.contentWindow.location.href.indexOf('/course/view.php') === -1) return;
    } catch (e) {}
    if (prefetch.dataset.prefetchCourseId !== String(courseId)) return;
    ceInjectCSS(prefetch);
    prefetch.dataset.prefetchReady = '1';
  };
  if (_ce.contextid && !_ceEditingActivated) {
    prefetch.src = WWWROOT + '/editmode.php?context=' + _ce.contextid +
      '&pageurl=' + encodeURIComponent(courseUrl) + '&setmode=1&sesskey=' + SESSKEY;
    _ceEditingActivated = true;
  } else {
    prefetch.src = courseUrl;
  }
}

// Un renommage en direct (section, activite...) dans l'iframe "mode edition"
// utilise le mecanisme "inplace editable" natif de Moodle, qui declenche cet
// evenement (bouillonnant) une fois la sauvegarde AJAX terminee — voir
// lib/amd/src/local/inplace_editable/events.js. Un ajout/suppression/
// deplacement d'activite ou de section passe lui par l'editeur de cours
// reactif, qui declenche 'core_course:stateChanged' sur le document a
// chaque changement d'etat — voir course/format/amd/src/courseeditor.js
// (dispatchStateChangedEvent). Les deux sont ecoutes ici pour rafraichir la
// liste "Contenu du cours" sans que l'admin ait a recharger la page.
function ceWatchInplaceEdits(doc) {
  if (doc._l3mInplaceWatched) return;
  doc._l3mInplaceWatched = true;
  var refresh = function() { if (_ce.id) ceRefreshSections(); };
  doc.addEventListener('core/inplace_editable:updated', refresh);
  doc.addEventListener('core_course:stateChanged', refresh);
  // Filet de securite : certaines actions (masquer/afficher, supprimer...)
  // ne declenchent pas toujours les evenements ci-dessus selon le format de
  // cours. On observe donc aussi directement les changements de structure du
  // contenu du cours, avec un debounce pour ne rafraichir qu'une fois
  // l'action terminee (evite les rafales pendant l'animation Moodle).
  // Important : seulement si .course-content existe reellement (page de
  // cours) — sans ce garde-fou, le repli sur doc.body observait N'IMPORTE
  // QUELLE page chargee dans l'iframe (un PDF, un quiz...), et les
  // manipulations DOM internes d'un visualiseur (ex. PDF.js qui redessine
  // ses pages en continu) declenchaient des rafraichissements en boucle —
  // d'ou les ralentissements/l'affichage instable observes sur un PDF.
  try {
    var content = doc.querySelector('.course-content');
    if (content) {
      var timer = null;
      var obs = new MutationObserver(function() {
        clearTimeout(timer);
        timer = setTimeout(refresh, 500);
      });
      obs.observe(content, {childList: true, subtree: true});
    }
  } catch (e) {}
  // "Supprimer" (.editing_delete) ouvre une boite de dialogue de
  // confirmation Moodle avant d'agir : on ne peut pas savoir a l'avance
  // quand l'utilisateur va confirmer, donc on sonde quelques fois sur
  // quelques secondes des le clic, pour rafraichir des que la suppression
  // devient effective plutot que d'attendre une fermeture/reouverture de
  // la fenetre.
  doc.addEventListener('click', function(ev) {
    var link = ev.target.closest && ev.target.closest('.editing_delete');
    if (!link) return;
    var n = 0;
    var poll = setInterval(function() {
      n++;
      refresh();
      if (n >= 6) clearInterval(poll);
    }, 700);
  }, true);
}
function closeCourseIframe() {
  document.getElementById('course-modal-bg').classList.remove('on');
  var iframe = document.getElementById('ce-iframe');
  iframe.src = 'about:blank';
  if (_ceRefreshTimer) { clearInterval(_ceRefreshTimer); _ceRefreshTimer = null; }
}

/* ===== SEED TEST DATA ===== */
function seedTestData() {
  var btn = document.getElementById('seed-btn');
  btn.disabled = true; btn.textContent = 'Création en cours…';
  apiPost({ action: 'seed_test' })
    .then(r => {
      btn.disabled = false; btn.textContent = 'Créer les données de test';
      if (r.ok) {
        toast(r.msg + ', rechargement…', 3000);
        setTimeout(() => location.reload(), 3000);
      } else { toast('Erreur : ' + (r.msg || '?')); }
    })
    .catch(() => { btn.disabled = false; btn.textContent = 'Créer les données de test'; toast('Erreur réseau'); });
}

/* ===== DOCUMENTS ADMIN ===== */
var _docsSelectedUser = null;

function docsInitStudentList() {
  var list = document.getElementById('docs-slist');
  if (!list) return;
  // Repere visuel "a verifier" sur le/les eleve(s) dont le badge "Documents"
  // compte un document deja signe par l'eleve mais pas encore marque verifie
  // par l'admin — sans ca, le badge "1" dans le menu ne disait pas QUI
  // (question du 20/10/2026).
  var toVerify = DATA.pending_signed_doc_userids || [];
  var search = document.getElementById('docs-search');
  var q = search ? search.value.toLowerCase() : '';
  list.innerHTML = DATA.students.map(function(s) {
    var flag = toVerify.indexOf(s.id) !== -1
      ? '<span style="float:right;color:var(--red);font-size:11px;font-weight:700">⚠ À vérifier</span>' : '';
    var visible = (!q || s.nom.toLowerCase().includes(q)) ? '' : ' style="display:none"';
    var selected = _docsSelectedUser && _docsSelectedUser.id === s.id ? ' on' : '';
    return '<div class="docs-si' + selected + '" data-uid="' + s.id + '" onclick="docsSelectStudent(' + s.id + ',this)"' + visible + '>' +
      '<div class="docs-si-name">' + esc(s.nom) + flag + '</div>' +
      '<div class="docs-si-sub">' + (s.cohort_name ? esc(dispCohName(s.cohort_name)) : 'Sans ' + TERM_CLASSE_LC) + '</div>' +
    '</div>';
  }).join('');
}

function docsFilterStudents(q) {
  q = q.toLowerCase();
  document.querySelectorAll('#docs-slist .docs-si').forEach(function(el) {
    var name = el.querySelector('.docs-si-name').textContent.toLowerCase();
    el.style.display = (!q || name.includes(q)) ? '' : 'none';
  });
}

function docsSelectStudent(uid, el) {
  document.querySelectorAll('.docs-si').forEach(function(e){ e.classList.remove('on'); });
  if (el) el.classList.add('on');
  _docsSelectedUser = DATA.students.find(function(s){ return s.id === uid; }) || {id: uid, nom: ''};
  docsLoadDocs(uid);
}

function docsLoadDocs(uid) {
  var right = document.getElementById('docs-right');
  right.innerHTML = '<p style="color:var(--muted);font-size:13px;padding:10px">Chargement…</p>';
  apiPost({action: 'get_student_docs', userid: uid})
    .then(function(d) {
      if (!d.ok) { right.innerHTML = '<p style="color:var(--red)">Erreur: ' + esc(d.msg || '') + '</p>'; return; }
      docsRenderRight(d.docs);
    }).catch(function(){ right.innerHTML = '<p style="color:var(--red)">Erreur réseau.</p>'; });
}

function updateDocsNavBadge() {
  var badge = document.getElementById('docs-nav-badge');
  if (!badge) return;
  var count = DATA.pending_signed_docs || 0;
  badge.textContent = count;
  badge.style.display = count > 0 ? 'flex' : 'none';
}

function refreshDocsNavBadge() {
  apiPost({action: 'count_pending_signed_docs'}).then(function(d) {
    if (!d.ok) return;
    DATA.pending_signed_docs = d.count;
    DATA.pending_signed_doc_userids = d.userids || [];
    updateDocsNavBadge();
    docsInitStudentList(); // reaffiche le repere "a verifier" dans la liste
  });
}

function updateMsgNavBadge() {
  var badge = document.getElementById('msg-nav-badge');
  if (!badge) return;
  var count = DATA.unread_messages || 0;
  badge.textContent = count;
  badge.style.display = count > 0 ? 'flex' : 'none';
}

function refreshMsgNavBadge() {
  apiPost({action: 'count_unread_messages'}).then(function(d) {
    if (!d.ok) return;
    DATA.unread_messages = d.count;
    updateMsgNavBadge();
  });
}

function updateLeadsNavBadge() {
  var badge = document.getElementById('leads-nav-badge');
  if (!badge) return;
  var count = DATA.pending_leads || 0;
  badge.textContent = count;
  badge.style.display = count > 0 ? 'flex' : 'none';
}

function refreshLeadsNavBadge() {
  apiPost({action: 'count_pending_leads'}).then(function(d) {
    if (!d.ok) return;
    DATA.pending_leads = d.count;
    updateLeadsNavBadge();
  });
}

var LEAD_STATUS_LABELS = {nouveau: 'Nouveau', contacte: 'Contacté', qualifie: 'Qualifié', inscrit_attente: 'En attente d\'activation', inscrit: 'Inscrit·e', perdu: 'Perdu'};
var _leadsData = [];

function renderLeads() {
  var tbody = document.getElementById('leads-tbody');
  tbody.innerHTML = '<tr><td colspan="8" style="color:var(--muted);text-align:center;padding:20px">Chargement…</td></tr>';
  apiPost({action: 'get_leads'}).then(function(d) {
    if (!d.ok) { tbody.innerHTML = '<tr><td colspan="8" style="color:var(--red)">Erreur de chargement.</td></tr>'; return; }
    _leadsData = d.leads;
    if (!d.leads.length) {
      tbody.innerHTML = '<tr><td colspan="8" style="color:var(--muted);text-align:center;padding:20px">Aucun lead pour le moment.</td></tr>';
      return;
    }
    tbody.innerHTML = d.leads.map(function(l) {
      var date = new Date(parseInt(l.timecreated) * 1000);
      var dateLabel = date.toLocaleDateString('fr-FR') + ' ' + date.toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit'});
      var options = Object.keys(LEAD_STATUS_LABELS).map(function(k) {
        return '<option value="' + k + '"' + (k === l.status ? ' selected' : '') + '>' + LEAD_STATUS_LABELS[k] + '</option>';
      }).join('');
      var actionBtn = l.status === 'inscrit'
        ? '<span class="chip c-vi">✓ inscrit·e</span>'
        : l.status === 'inscrit_attente'
        ? '<span class="chip" style="background:var(--orange-t);color:var(--orange-d)">en attente d\'activation</span>'
        : '<button class="btn mini primary" onclick="openConvertLead(' + l.id + ')">Inscrire</button>';
      return '<tr>' +
        '<td data-label="Contact">' + esc(l.firstname) + ' ' + esc(l.lastname) + '</td>' +
        '<td data-label="Téléphone">' + esc(l.phone) + '</td>' +
        '<td data-label="E-mail">' + esc(l.email) + '</td>' +
        '<td data-label="Situation">' + esc(l.situation || '—') + '</td>' +
        '<td data-label="Reçu le">' + dateLabel + '</td>' +
        '<td data-label="Statut"><select onchange="updateLeadStatus(' + l.id + ',this.value)">' + options + '</select></td>' +
        '<td data-label="">' + actionBtn + '</td>' +
        '<td data-label=""><button class="btn mini ghost" onclick="deleteLead(' + l.id + ')">Supprimer</button></td>' +
      '</tr>';
    }).join('');
  });
}

function deleteLead(leadid) {
  var lead = (_leadsData || []).find(function(l){ return l.id === leadid; });
  var msg = 'Supprimer définitivement ce lead ?';
  if (lead && (lead.status === 'inscrit' || lead.status === 'inscrit_attente')) {
    msg = lead.status === 'inscrit'
      ? '⚠ Ce lead correspond à un compte élève ACTIF (avec accès aux cours). Supprimer le lead supprimera aussi définitivement ce compte et ses inscriptions. Continuer ?'
      : 'Ce lead a déjà un compte créé (en attente d\'activation). Supprimer le lead supprimera aussi ce compte et ses inscriptions. Continuer ?';
  }
  if (!confirm(msg)) return;
  apiPost({action: 'delete_lead', leadid: leadid}).then(function(d) {
    if (!d.ok) { toast('Erreur : ' + (d.msg || '?')); return; }
    refreshLeadsNavBadge();
    renderLeads();
    renderPendingActivations();
    toast(d.account_deleted ? 'Lead et compte associé supprimés.' : 'Lead supprimé.');
  }).catch(function() { toast('Erreur réseau.'); });
}

function updateLeadStatus(leadid, status) {
  apiPost({action: 'update_lead_status', leadid: leadid, status: status}).then(function(d) {
    if (!d.ok) { toast('Erreur.'); return; }
    refreshLeadsNavBadge();
    toast('Statut mis à jour.');
  });
}

function renderPendingActivations() {
  var tbody = document.getElementById('pending-activation-tbody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="5" style="color:var(--muted);text-align:center;padding:20px">Chargement…</td></tr>';
  apiPost({action: 'get_pending_activations'}).then(function(d) {
    if (!d.ok) { tbody.innerHTML = '<tr><td colspan="5" style="color:var(--red)">Erreur de chargement.</td></tr>'; return; }
    if (!d.items.length) {
      tbody.innerHTML = '<tr><td colspan="5" style="color:var(--muted);text-align:center;padding:20px">Aucune activation en attente.</td></tr>';
      return;
    }
    tbody.innerHTML = d.items.map(function(it) {
      var date = new Date(parseInt(it.timecreated) * 1000).toLocaleDateString('fr-FR');
      return '<tr>' +
        '<td data-label="Élève">' + esc(it.firstname) + ' ' + esc(it.lastname) + '</td>' +
        '<td data-label="E-mail">' + esc(it.email) + '</td>' +
        '<td data-label="Formation">' + it.nb_courses + (it.nb_courses > 1 ? ' modules' : ' module') + '</td>' +
        '<td data-label="Inscrit·e le">' + date + '</td>' +
        '<td data-label=""><button class="btn mini primary" onclick="activateStudentAccess(' + it.userid + ',this)">Activer l\'accès</button></td>' +
      '</tr>';
    }).join('');
  });
}

function activateStudentAccess(userid, btn) {
  btn.disabled = true; btn.textContent = 'Activation...';
  apiPost({ action: 'activate_student_access', userid: userid })
    .then(function(d) {
      if (!d.ok) { alert('Erreur : ' + (d.msg || 'inconnue')); btn.disabled = false; btn.textContent = 'Activer l\'accès'; return; }
      toast('Accès activé, l\'élève peut se connecter.');
      renderPendingActivations();
      renderLeads();
    })
    .catch(function() { alert('Erreur réseau.'); btn.disabled = false; btn.textContent = 'Activer l\'accès'; });
}

var _leadConvertId = null;

function openConvertLead(leadid) {
  var l = _leadsData.find(function(x) { return x.id == leadid; });
  if (!l) return;
  _leadConvertId = leadid;
  document.getElementById('lead-firstname').value = l.firstname;
  document.getElementById('lead-lastname').value = l.lastname;
  document.getElementById('lead-email').value = l.email;
  document.getElementById('lead-modal-sub').textContent = l.situation ? 'Situation indiquée : ' + l.situation : '';

  var formations = DATA.formations || [];
  var sel = document.getElementById('lead-formation');
  sel.innerHTML = formations.map(function(f) {
    return '<option value="' + f.id + '">' + esc(f.label) + ' (' + f.course_ids.length + ' cours)</option>';
  }).join('');
  // Présélection si le libellé de la formation choisie ressemble à une formation existante
  if (l.situation && formations.length) {
    var needle = l.situation.toLowerCase();
    var match = formations.find(function(f) { return needle.indexOf(f.label.toLowerCase()) !== -1 || f.label.toLowerCase().indexOf(needle) !== -1; });
    if (match) sel.value = match.id;
  }

  document.getElementById('modal-lead-bg').classList.add('open');
}

function closeLeadModal() {
  document.getElementById('modal-lead-bg').classList.remove('open');
}

var _credsUserId = null;
function openCredsModal(uid, nom, username) {
  _credsUserId = uid;
  document.getElementById('creds-modal-sub').textContent = nom;
  document.getElementById('creds-username').value = username || '—';
  document.getElementById('creds-pw-row').style.display = 'none';
  document.getElementById('creds-password').value = '';
  var btn = document.getElementById('creds-reset-btn');
  btn.disabled = false; btn.textContent = 'Réinitialiser le mot de passe';
  document.getElementById('modal-creds-bg').classList.add('open');
}
function closeCredsModal() {
  document.getElementById('modal-creds-bg').classList.remove('open');
}
function resetCredsPassword() {
  if (!_credsUserId) return;
  if (!confirm('Définir un nouveau mot de passe pour cet élève ? L\'ancien ne fonctionnera plus.')) return;
  var btn = document.getElementById('creds-reset-btn');
  btn.disabled = true; btn.textContent = 'Réinitialisation…';
  apiPost({ action: 'reset_student_password', userid: _credsUserId })
    .then(function(d) {
      btn.disabled = false; btn.textContent = 'Réinitialiser le mot de passe';
      if (!d.ok) { toast('Erreur : ' + (d.msg || '?')); return; }
      document.getElementById('creds-username').value = d.username;
      document.getElementById('creds-password').value = d.password;
      document.getElementById('creds-pw-row').style.display = '';
      toast('Nouveau mot de passe défini.');
    })
    .catch(function() { btn.disabled = false; btn.textContent = 'Réinitialiser le mot de passe'; toast('Erreur réseau'); });
}

function convertLead() {
  var fn  = document.getElementById('lead-firstname').value.trim();
  var ln  = document.getElementById('lead-lastname').value.trim();
  var em  = document.getElementById('lead-email').value.trim();
  var formationId = document.getElementById('lead-formation').value;
  if (!fn || !ln || !em || !formationId) { toast('Tous les champs sont requis'); return; }

  var btn = document.getElementById('lead-convert-btn');
  btn.disabled = true; btn.textContent = 'Inscription…';
  apiPost({ action: 'convert_lead_to_student', leadid: _leadConvertId, firstname: fn, lastname: ln, email: em, formation: formationId })
    .then(function(d) {
      btn.disabled = false; btn.textContent = 'Inscrire';
      if (d.ok) {
        closeLeadModal();
        renderLeads();
        renderPendingActivations();
        refreshLeadsNavBadge();
        if (d.is_new) {
          showCredentialsModal(d.username, d.temp_password, null, 'Élève inscrit');
        } else {
          // Compte deja existant reutilise (meme e-mail qu'un compte deja
          // cree) : pas de nouveau mot de passe genere, mais on affiche quand
          // meme une carte de confirmation avec l'identifiant existant, pour
          // que le retour reste coherent que le compte soit neuf ou non.
          showCredentialsModal(d.username, 'Inchangé (compte existant)', null, 'Élève inscrit');
        }
        toast('Accès bloqué jusqu\'à activation ("En attente d\'activation" ci-dessous).', 6000);
      } else {
        toast('Erreur : ' + (d.msg || '?'));
      }
    })
    .catch(function() { btn.disabled = false; btn.textContent = 'Inscrire'; toast('Erreur réseau'); });
}

function docsRenderRight(docs) {
  var right = document.getElementById('docs-right');
  var u = _docsSelectedUser;
  var html = '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:4px">';
  html += '<div style="font-weight:700;font-size:15px;color:var(--ink)">📋 ' + esc(u.nom) + '</div>';
  html += '<button class="btn mini primary" onclick="docsOpenForm()">+ Ajouter un document</button></div>';

  if (!docs.length) {
    html += '<div class="docs-empty-hint">Aucun document pour cet élève.<br><small>Cliquez sur "+ Ajouter un document" pour commencer.</small></div>';
  } else {
    docs.forEach(function(doc) {
      var sClass = 'dsb-' + doc.status_type;
      var sIcon  = (doc.status_type === 'signed' || doc.status_type === 'validated') ? '✓ ' : (doc.status_type === 'pending' ? '⚠ ' : '');
      var isBlocking = parseInt(doc.is_blocking) === 1;
      html += '<div class="doc-adm-row' + (isBlocking ? ' blocking' : '') + '">';
      html += '<div class="doc-adm-info">';
      html += '<div class="doc-adm-title">' + esc(doc.title) + (isBlocking ? ' <span style="color:var(--red);font-size:11px">● bloquant</span>' : '') + '</div>';
      if (doc.subtitle) html += '<div class="doc-adm-sub">' + esc(doc.subtitle) + '</div>';
      html += '</div>';
      var sDefaultLbl = { signed: 'Signé', validated: 'Validé', pending: 'En attente', future: 'À venir' };
      html += '<span class="doc-adm-status ' + sClass + '">' + sIcon + esc(doc.status_label || sDefaultLbl[doc.status_type] || doc.status_type) + '</span>';
      if (doc.signed_uploaded_at > 0) {
        html += '<a class="btn mini" style="color:var(--teal-d);border-color:var(--teal)" href="' + WWWROOT + '/l3m_doc_file.php?docid=' + doc.id + '&type=signed" target="_blank" rel="noopener">📎 Document signé reçu</a>';
        if (doc.status_type === 'pending') {
          html += '<button class="btn mini primary" onclick="docsAckSigned(' + doc.id + ')">✓ Marquer comme vérifié</button>';
        }
      }
      html += '<button class="btn mini ghost doc-adm-edit-btn" onclick="docsEditDoc(' + JSON.stringify(doc).replace(/"/g,'&quot;') + ')" title="Modifier" aria-label="Modifier">&#9998;&#65039;</button>';
      html += '<button class="btn mini ghost" style="color:var(--red)" onclick="docsDeleteDoc(' + doc.id + ')">✕</button>';
      html += '</div>';
    });
  }
  right.innerHTML = html;
}

function docsOpenForm(doc) {
  if (!_docsSelectedUser) { toast('Sélectionnez d\'abord un élève.'); return; }
  var saveBtn = document.getElementById('docs-save-btn');
  saveBtn.disabled = false;
  saveBtn.textContent = 'Enregistrer';
  document.getElementById('docs-form-title').textContent = doc ? 'Modifier le document' : 'Nouveau document';
  document.getElementById('docs-form-eleve').textContent = 'Élève : ' + _docsSelectedUser.nom;
  document.getElementById('df-docid').value   = doc ? doc.id : 0;
  document.getElementById('df-userid').value  = _docsSelectedUser.id;
  document.getElementById('df-title').value   = doc ? doc.title : '';
  document.getElementById('df-subtitle').value= doc ? doc.subtitle : '';
  document.getElementById('df-stype').value   = doc ? doc.status_type : 'pending';
  document.getElementById('df-slabel').value  = doc ? doc.status_label : '';
  document.getElementById('df-atype').value   = doc ? doc.action_type : 'none';
  document.getElementById('df-file').value    = '';
  document.getElementById('df-file-current').textContent =
    (doc && doc.action_url && doc.action_url.indexOf('l3m_doc_file.php') !== -1)
      ? 'Fichier déjà uploadé, choisissez un nouveau fichier pour le remplacer.' : '';
  document.getElementById('df-blocking').checked = doc ? !!parseInt(doc.is_blocking) : false;
  document.getElementById('df-bmsg').value    = doc ? doc.blocking_msg : '';
  docsToggleUrl();
  docsToggleBlockingMsg();
  document.getElementById('docs-form-modal').style.display = 'flex';
}

function docsEditDoc(doc) { docsOpenForm(doc); }

function docsCloseForm() { document.getElementById('docs-form-modal').style.display = 'none'; }

function docsToggleUrl() {
  var atype = document.getElementById('df-atype').value;
  document.getElementById('df-url-row').style.display = (atype !== 'none') ? '' : 'none';
}

function docsToggleBlockingMsg() {
  var checked = document.getElementById('df-blocking').checked;
  document.getElementById('df-bmsg-row').style.display = checked ? '' : 'none';
}

function docsStatusPreset() {
  var stype = document.getElementById('df-stype').value;
  var presets = {signed:'Signé', validated:'Validé', pending:'À signer', future:'À venir'};
  var lbl = document.getElementById('df-slabel');
  if (!lbl.value || Object.values(presets).indexOf(lbl.value) >= 0) lbl.value = presets[stype] || '';
}

function docsSaveForm() {
  var btn = document.getElementById('docs-save-btn');
  // Sans ce garde-fou, un double/triple-clic (l'utilisateur ne voit aucun
  // retour immediat pendant l'appel reseau) declenchait autant d'appels
  // "save_doc" independants, donc autant de documents crees (signale le
  // 19/10/2026 : "un seul clic entraine l'ajout de trois documents").
  if (btn.disabled) return;
  var title = document.getElementById('df-title').value.trim();
  if (!title) { toast('Le titre est obligatoire.'); return; }
  var params = {
    action: 'save_doc',
    docid: document.getElementById('df-docid').value,
    userid: document.getElementById('df-userid').value,
    title: title,
    subtitle: document.getElementById('df-subtitle').value,
    status_type: document.getElementById('df-stype').value,
    status_label: document.getElementById('df-slabel').value,
    action_type: document.getElementById('df-atype').value,
    is_blocking: document.getElementById('df-blocking').checked ? 1 : 0,
    blocking_msg: document.getElementById('df-bmsg').value,
  };
  btn.disabled = true;
  btn.textContent = 'Enregistrement…';
  apiPost(params).then(function(d) {
    if (!d.ok) { toast('Erreur: ' + (d.msg || '')); btn.disabled = false; btn.textContent = 'Enregistrer'; return; }
    var docid = d.doc.id;
    var file = document.getElementById('df-file').files[0];
    if (!file) {
      docsCloseForm();
      docsLoadDocs(parseInt(params.userid));
      refreshDocsNavBadge();
      toast('Document enregistré.');
      return;
    }
    var fd = new FormData();
    fd.append('action', 'upload_doc_file');
    fd.append('sesskey', SESSKEY);
    fd.append('docid', docid);
    fd.append('file', file);
    fetch(ADMINURL, { method: 'POST', body: fd }).then(function(r) { return r.json(); }).then(function(u) {
      docsCloseForm();
      docsLoadDocs(parseInt(params.userid));
      refreshDocsNavBadge();
      if (!u.ok) { toast('Document enregistré, mais échec de l\'upload : ' + (u.msg || '')); return; }
      toast('Document et fichier enregistrés.');
    }).catch(function() {
      btn.disabled = false; btn.textContent = 'Enregistrer';
      toast('Document enregistré, mais erreur réseau pendant l\'upload du fichier.');
    });
  }).catch(function() {
    btn.disabled = false; btn.textContent = 'Enregistrer';
    toast('Erreur réseau, réessaie.');
  });
}

function docsDeleteDoc(docid) {
  if (!confirm('Supprimer ce document ?')) return;
  apiPost({action: 'delete_doc', docid: docid}).then(function(d) {
    if (!d.ok) { toast('Erreur.'); return; }
    docsLoadDocs(_docsSelectedUser.id);
    refreshDocsNavBadge();
    toast('Document supprimé.');
  });
}

function docsAckSigned(docid) {
  apiPost({action: 'ack_signed_doc', docid: docid}).then(function(d) {
    if (!d.ok) { toast('Erreur : ' + (d.msg || '?')); return; }
    docsLoadDocs(_docsSelectedUser.id);
    refreshDocsNavBadge();
    toast('Document marqué comme vérifié.');
  });
}

/* ===== UTILS ===== */
function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ===== INIT ===== */
document.addEventListener('DOMContentLoaded', function() {
  renderDash();
  renderTeam();
  renderCoh();
  docsInitStudentList();
  updateDocsNavBadge();
  updateLeadsNavBadge();
  updateMsgNavBadge();
  renderBetaTesters();
  loadHeaderCounts();
});

/* ===== Testeurs bêta ===== */
function renderBetaTesters() {
  var box = document.getElementById('beta-testers-list');
  if (!box) return; // bloc retire de l'espace admin, voir HTML
  var list = DATA.beta_testers || [];
  if (!list.length) {
    box.innerHTML = '<p style="color:var(--muted);font-size:13px;margin:0">Aucun testeur pour l\'instant.</p>';
    return;
  }
  box.innerHTML = list.map(function(t) {
    return '<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--line)">' +
      '<div><b>' + esc(t.nom) + '</b> <span style="color:var(--muted);font-size:12px">' + esc(t.email) + '</span></div>' +
      '<button class="btn mini ghost" style="color:var(--red,#C0392B);border-color:var(--red,#C0392B)" onclick="removeBetaTester(' + t.id + ')">Retirer</button>' +
    '</div>';
  }).join('');
}

function addBetaTester() {
  var input = document.getElementById('beta-add-input');
  var identifier = input.value.trim();
  if (!identifier) return;
  apiPost({ action: 'add_beta_tester', identifier: identifier })
    .then(function(r) {
      if (r.ok) {
        if (!DATA.beta_testers.some(function(t){ return t.id === r.id; })) {
          DATA.beta_testers.push({ id: r.id, nom: r.nom, email: r.email });
        }
        input.value = '';
        renderBetaTesters();
        toast('Testeur ajouté');
      } else { toast('Erreur : ' + (r.msg || '?')); }
    })
    .catch(function() { toast('Erreur réseau'); });
}

function removeBetaTester(uid) {
  apiPost({ action: 'remove_beta_tester', userid: uid })
    .then(function(r) {
      if (r.ok) {
        DATA.beta_testers = DATA.beta_testers.filter(function(t){ return t.id !== uid; });
        renderBetaTesters();
        toast('Testeur retiré');
      } else { toast('Erreur : ' + (r.msg || '?')); }
    })
    .catch(function() { toast('Erreur réseau'); });
}
</script>
<!-- ===== MODAL SEANCE ===== -->
<div id="modal-seance-bg" onclick="if(event.target===this)closeSeanceModal()">
  <div id="modal-seance">
    <h3 id="ms-title">Planifier une séance</h3>
    <p class="sub" id="ms-sub"></p>
    <label>Module</label>
    <select id="ms-course"></select>
    <label>Formateur</label>
    <select id="ms-formateur"></select>
    <label>Nom de la séance</label>
    <input type="text" id="ms-name" placeholder="Séance ECSR · Classe A · S12">
    <div style="display:flex;gap:12px">
      <div style="flex:1">
        <label>Jour</label>
        <select id="ms-day">
          <option value="0">Lundi</option>
          <option value="1">Mardi</option>
          <option value="2">Mercredi</option>
          <option value="3">Jeudi</option>
          <option value="4">Vendredi</option>
        </select>
      </div>
      <div style="flex:1">
        <label>Heure de début</label>
        <input type="time" id="ms-time" value="14:00">
      </div>
      <div style="flex:1">
        <label>Durée (min)</label>
        <input type="number" id="ms-dur" value="120" min="30" max="480" step="30">
      </div>
    </div>
    <div class="seance-foot">
      <button class="btn ghost" onclick="closeSeanceModal()">Annuler</button>
      <button class="btn primary" onclick="saveSeance()">Créer la séance</button>
    </div>
  </div>
</div>

<div id="modal-lia-bg" onclick="if(event.target===this)closeLiaAffectModal()">
  <div id="modal-lia">
    <h3 id="lia-title">Affectation manuelle</h3>
    <p class="sub" id="lia-sub"></p>
    <label>Formateur</label>
    <select id="lia-pid" onchange="liaAffectOnClassChange()"></select>
    <label><?= l3m_term_classe($USER->id) ?></label>
    <select id="lia-coh" onchange="liaAffectOnClassChange()"></select>
    <div style="display:flex;gap:12px">
      <div style="flex:1">
        <label>Format</label>
        <select id="lia-type">
          <option value="visio">Visio</option>
          <option value="veh">Regroupement véhicule</option>
          <option value="salle">Regroupement salle</option>
          <option value="stage">Stage</option>
          <option value="exam">Examen</option>
        </select>
      </div>
      <div style="flex:1">
        <label>Heures</label>
        <input type="number" id="lia-h" value="2" min="0" max="40" step="0.5">
      </div>
    </div>
    <label>Module / intitulé</label>
    <input type="text" id="lia-label" placeholder="Ingénierie et construction de séance">
    <label>Séquence (optionnel)</label>
    <select id="lia-seq"><option value="">—</option></select>
    <div class="lia-foot">
      <button class="btn danger-btn" id="lia-delete-btn" onclick="deleteLiaAffect()" style="display:none">Supprimer cette affectation</button>
      <button class="btn ghost" onclick="closeLiaAffectModal()">Annuler</button>
      <button class="btn primary" onclick="saveLiaAffect()">Enregistrer</button>
    </div>
  </div>
</div>

<div id="modal-lia-hours-bg" onclick="if(event.target===this)closeLiaHoursDetail()">
  <div id="modal-lia-hours" style="width:340px">
    <h3 id="lia-hours-title">Heures par <?= l3m_term_classe_lc($USER->id) ?></h3>
    <div id="lia-hours-body" style="font-size:13.5px;line-height:2"></div>
    <div class="lia-foot">
      <button class="btn primary" onclick="closeLiaHoursDetail()">Fermer</button>
    </div>
  </div>
</div>
</body>
</html>
