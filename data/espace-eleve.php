<?php
// Espace Eleve — LBA Academie
// Auth : require_login + role student
// Deploy : SFTP -> /tmp/espace-eleve.php -> docker cp moodle_app:/var/data/www/public/
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/lia_engine.php');
require_once(__DIR__ . '/l3m_branding.php');
require_login();
$user_client = l3m_get_user_client($USER->id);
$brand = l3m_get_brand_by_client($user_client);

// ===== BLOCAGE ACCÈS : dossier documents pas encore confirmé reçu =====
// admin-lba.php (action convert_lead_to_student) inscrit l'élève en
// ENROL_USER_SUSPENDED tant qu'un admin n'a pas cliqué "Activer l'accès"
// (action activate_student_access) après réception du dossier par e-mail.
// Cette page liste "mes cours" via les rôles attribués (role_assignments),
// qui restent présents même inscription suspendue — sans ce contrôle
// explicite, la suspension ne bloquerait donc rien ici. Un site admin garde
// toujours l'accès (support/débogage).
$l3m_has_active_enrol = is_siteadmin() || $DB->record_exists_sql(
    "SELECT 1 FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
     WHERE ue.userid = :uid AND ue.status = :active",
    ['uid' => $USER->id, 'active' => ENROL_USER_ACTIVE]
);
if (!$l3m_has_active_enrol) {
    if (isset($_GET['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => "Accès en attente d'activation"]);
        exit;
    }
    header('Content-Type: text/html; charset=UTF-8');
    ?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><title>Accès en attente</title>
<style>
body{font-family:"Segoe UI",system-ui,sans-serif;background:#F7F6F1;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.card{background:#fff;border-radius:16px;padding:36px 40px;max-width:440px;text-align:center;box-shadow:0 8px 30px rgba(0,0,0,.08)}
h1{font-size:18px;color:#24333C;margin:0 0 10px}
p{color:#68767E;font-size:14px;line-height:1.6}
</style></head>
<body><div class="card">
  <h1>Votre accès est en attente d'activation</h1>
  <p>Votre inscription a bien été enregistrée. L'accès aux cours sera activé par notre équipe dès réception de votre dossier (documents transmis par e-mail).</p>
</div></body></html>
    <?php
    exit;
}

// AJAX : documents administratifs de l'eleve
if (isset($_GET['action']) && $_GET['action'] === 'my_docs') {
    header('Content-Type: application/json');
    try {
        $docs = array_values((array)$DB->get_records('l3m_docs', ['userid' => $USER->id], 'sortorder ASC, timecreated ASC'));
        $blocking = array_filter($docs, function($d){ return (int)$d->is_blocking === 1; });
        echo json_encode(['ok' => true, 'docs' => $docs, 'blocking_count' => count($blocking)]);
    } catch (Exception $e) {
        echo json_encode(['ok' => true, 'docs' => [], 'blocking_count' => 0]);
    }
    exit;
}

// AJAX : contenu detaille d'un cours (sections + activites)
if (isset($_GET['action']) && $_GET['action'] === 'course_detail') {
    header('Content-Type: application/json');
    $cid = (int)($_GET['courseid'] ?? 0);
    if (!$cid) { echo json_encode(['ok'=>false,'msg'=>'id manquant']); exit; }
    try {
        $course  = $DB->get_record('course', ['id' => $cid], '*', MUST_EXIST);
        $minfo   = get_fast_modinfo($course);
        // Completions en masse
        $all_cms = array_keys($minfo->get_cms());
        $comps   = [];
        if ($all_cms) {
            list($insql, $inparams) = $DB->get_in_or_equal($all_cms);
            $rows = $DB->get_records_select('course_modules_completion',
                "userid = ? AND coursemoduleid $insql",
                array_merge([$USER->id], $inparams));
            foreach ($rows as $r) { $comps[$r->coursemoduleid] = (int)$r->completionstate; }
        }
        $sections = [];
        foreach ($minfo->get_section_info_all() as $snum => $sinfo) {
            if ($snum == 0 || !$sinfo->visible) continue;
            $cmids = $sinfo->sequence ? explode(',', $sinfo->sequence) : [];
            $mods  = [];
            foreach ($cmids as $cmid) {
                $cmid = (int)$cmid;
                if (!isset($minfo->cms[$cmid])) continue;
                $cm = $minfo->cms[$cmid];
                if (!$cm->visible) continue;
                $done = isset($comps[$cmid]) && $comps[$cmid] > 0;
                $mods[] = ['cmid'=>$cmid,'name'=>$cm->name,'modtype'=>$cm->modname,'done'=>$done];
            }
            $total = count($mods);
            $done_n = count(array_filter($mods, function($m){ return $m['done']; }));
            $sections[] = ['num'=>$snum,'name'=>$sinfo->name ?: ('Section '.$snum),
                           'mods'=>$mods,'total'=>$total,'done_n'=>$done_n];
        }
        // Fil d'ariane (catégorie racine → sous-catégories → cours) — masqué
        // par le CSS qui nettoie l'iframe (breadcrumb natif Moodle caché),
        // reconstruit ici pour le panneau de gauche du viewer.
        $breadcrumb = [];
        $catid_bc = (int)$course->category;
        $safety_bc = 0;
        while ($catid_bc && $safety_bc < 10) {
            $cat_bc = $DB->get_record('course_categories', ['id' => $catid_bc], 'id,name,parent');
            if (!$cat_bc) break;
            array_unshift($breadcrumb, $cat_bc->name);
            $catid_bc = (int)$cat_bc->parent;
            $safety_bc++;
        }
        $breadcrumb[] = $course->fullname;

        echo json_encode(['ok'=>true,'sections'=>$sections,
                          'coursename'=>$course->fullname,'courseid'=>$cid,'breadcrumb'=>$breadcrumb]);
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// AJAX : objectifs pédagogiques + éligibilité WhatsApp d'un cours (identique à la
// logique de theme_mb2nl/footer_normal.php, mais servi ici en leger pour un
// affichage instantane a la fermeture d'une activite, sans recharger toute la
// page de cours Moodle dans l'iframe (beaucoup plus lent).
if (isset($_GET['action']) && $_GET['action'] === 'course_objectifs') {
    header('Content-Type: application/json');
    $cid = (int)($_GET['courseid'] ?? 0);
    if (!$cid) { echo json_encode(['ok'=>false,'msg'=>'id manquant']); exit; }
    try {
        $items = [];
        $field = $DB->get_record('customfield_field', ['shortname' => 'objectifs']);
        if ($field) {
            $data = $DB->get_record('customfield_data', ['fieldid' => $field->id, 'instanceid' => $cid]);
            if ($data && !empty(trim(strip_tags($data->value)))) {
                preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $data->value, $matches);
                foreach (($matches[1] ?? []) as $item) {
                    $item = trim(strip_tags($item));
                    if ($item !== '') $items[] = $item;
                }
            }
        }
        $ecsr_courses = [2,3,4,5,6,7,8,9,10,11,12];
        echo json_encode([
            'ok' => true,
            'items' => $items,
            'whatsapp' => in_array($cid, $ecsr_courses),
            'whatsapp_url' => 'https://chat.whatsapp.com/CAqlpOsGrzJIHKPGxogmY3',
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

$student_roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
if (!$student_roleid || !$DB->record_exists('role_assignments', ['userid' => $USER->id, 'roleid' => $student_roleid])) {
    redirect(new moodle_url('/'));
}

$sesskey = sesskey();

// ── ACTIONS POST (AJAX) — messagerie (vraie messagerie Moodle, UI custom) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = optional_param('action', '', PARAM_ALPHANUMEXT);
    header('Content-Type: application/json');

    if ($action === 'msg_list') {
        $convs = \core_message\api::get_conversations(
            $USER->id, 0, 100, \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL
        );
        $out = [];
        foreach ($convs as $c) {
            $other   = $c->members ? reset($c->members) : null;
            $lastmsg = $c->messages[0] ?? null;
            $out[] = [
                'convid'      => (int)$c->id,
                'userid'      => $other ? (int)$other->id : 0,
                'name'        => $other ? $other->fullname : $c->name,
                'initials'    => $other ? $other->initials : '??',
                'isonline'    => $other ? (bool)$other->isonline : false,
                'isfavourite' => (bool)$c->isfavourite,
                'unreadcount' => (int)($c->unreadcount ?? 0),
                'lastmsg'     => $lastmsg ? trim(html_to_text($lastmsg->text, 0, false)) : '',
                'lasttime'    => $lastmsg ? (int)$lastmsg->timecreated : 0,
            ];
        }
        usort($out, function($a, $b) {
            if ($a['isfavourite'] !== $b['isfavourite']) return $b['isfavourite'] <=> $a['isfavourite'];
            return $b['lasttime'] <=> $a['lasttime'];
        });
        echo json_encode(['ok' => true, 'conversations' => $out], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'msg_thread') {
        $otherid = optional_param('otherid', 0, PARAM_INT);
        $convid  = optional_param('convid', 0, PARAM_INT);
        if (!$convid && $otherid) {
            $convid = (int)(\core_message\api::get_conversation_between_users([$USER->id, $otherid]) ?: 0);
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
        $otherid = required_param('otherid', PARAM_INT);
        $text    = trim(required_param('text', PARAM_TEXT));
        if ($text === '') {
            echo json_encode(['ok' => false, 'msg' => 'Message vide']);
            exit;
        }
        $convid = (int)(\core_message\api::get_conversation_between_users([$USER->id, $otherid]) ?: 0);
        if (!$convid) {
            $newconv = \core_message\api::create_conversation(
                \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL, [$USER->id, $otherid]
            );
            $convid = (int)$newconv->id;
        }
        $sent      = \core_message\api::send_message_to_conversation($USER->id, $convid, $text, FORMAT_MOODLE);
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

    if ($action === 'header_counts') {
        $unread_msgs = 0;
        try {
            $unread_msgs = (int)\core_message\api::count_unread_conversations($USER);
        } catch (Exception $e) {}
        $unread_notifs = 0;
        try {
            $unread_notifs = (int)\message_popup\api::count_unread_popup_notifications($USER->id);
        } catch (Exception $e) {}
        echo json_encode(['ok' => true, 'unread_messages' => $unread_msgs, 'unread_notifications' => $unread_notifs], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'upload_signed_doc') {
        $docid = required_param('docid', PARAM_INT);
        $doc   = $DB->get_record('l3m_docs', ['id' => $docid, 'userid' => $USER->id], '*', MUST_EXIST);
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'msg' => 'Fichier manquant ou invalide']);
            exit;
        }
        try {
            $DB->execute("ALTER TABLE `{$CFG->prefix}l3m_docs` ADD COLUMN IF NOT EXISTS `signed_uploaded_at` bigint(10) NOT NULL DEFAULT 0");
        } catch (Exception $e) {}

        $fs        = get_file_storage();
        $contextid = context_user::instance($USER->id)->id;
        $fs->delete_area_files($contextid, 'local_l3madmin', 'l3m_doc_signed', $docid);
        $filename = clean_param(basename($_FILES['file']['name']), PARAM_FILE);
        if ($filename === '') $filename = 'document_signe';
        $filerecord = [
            'contextid' => $contextid,
            'component' => 'local_l3madmin',
            'filearea'  => 'l3m_doc_signed',
            'itemid'    => $docid,
            'filepath'  => '/',
            'filename'  => $filename,
        ];
        $fs->create_file_from_pathname($filerecord, $_FILES['file']['tmp_name']);

        $doc->signed_uploaded_at = time();
        $DB->update_record('l3m_docs', $doc);

        // Notifie qui a reellement acces a admin-lba.php : les managers (role systeme)
        // ET les site admins (memes conditions que l'auth d'admin-lba.php).
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

        foreach (array_keys($recipients) as $rid) {
            l3m_send_notification(
                $USER->id, $rid, 'Document signé reçu',
                fullname($USER) . ' a envoyé le document signé : ' . $doc->title,
                $CFG->wwwroot . '/admin-lba.php#docs', 'Voir les documents'
            );
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Action inconnue']);
    exit;
}

$now = time();

$student_info = [
    'id'        => (int)$USER->id,
    'firstname' => $USER->firstname,
    'lastname'  => $USER->lastname,
    'email'     => $USER->email,
    'initials'  => mb_substr($USER->firstname, 0, 1) . mb_substr($USER->lastname, 0, 1),
    'nom'       => $USER->firstname . ' ' . $USER->lastname,
];

// Cohorte
$my_cohort_data = null;
try {
    $my_cohort_raw = $DB->get_record_sql(
        "SELECT c.id, c.name, c.idnumber AS startdate,
                (SELECT COUNT(*) FROM {cohort_members} cm2 WHERE cm2.cohortid = c.id) AS nb_members
         FROM {cohort} c
         JOIN {cohort_members} cm ON cm.cohortid = c.id AND cm.userid = :uid
         WHERE c.idnumber IS NOT NULL AND c.idnumber != ''
           AND c.idnumber NOT IN (:clba, :csaran)
         ORDER BY c.id DESC LIMIT 1",
        ['uid' => $USER->id, 'clba' => L3M_CLIENT_COHORT_IDNUMBER_LBA, 'csaran' => L3M_CLIENT_COHORT_IDNUMBER_SARAN]
    );
    if ($my_cohort_raw) {
        $members_sorted = $DB->get_fieldset_sql(
            "SELECT cm.userid FROM {cohort_members} cm WHERE cm.cohortid = :cid ORDER BY cm.userid",
            ['cid' => $my_cohort_raw->id]
        );
        $my_pos = array_search((string)$USER->id, array_map('strval', $members_sorted));
        $subgroup_num = ($my_pos !== false) ? (int)floor($my_pos / 3) + 1 : 1;
        $letter = preg_match('/Classe\s+([A-Z])/i', $my_cohort_raw->name, $m) ? strtoupper($m[1]) : 'A';
        $my_cohort_data = [
            'id'             => (int)$my_cohort_raw->id,
            'name'           => $my_cohort_raw->name,
            'startdate'      => $my_cohort_raw->startdate,
            'nb_members'     => (int)$my_cohort_raw->nb_members,
            'my_subgroup'    => $subgroup_num,
            'subgroup_label' => $letter . $subgroup_num,
        ];
    }
} catch (Exception $e) { $my_cohort_data = null; }

// Cours + completion
$my_courses = [];
$course_ids = [];
$global_pct = 0;
$last_course = null;
try {
    $courses_raw = $DB->get_records_sql(
        "SELECT DISTINCT c.id, c.fullname, c.shortname
         FROM {course} c
         JOIN {context} cx ON cx.instanceid = c.id AND cx.contextlevel = 50
         JOIN {role_assignments} ra ON ra.contextid = cx.id
         WHERE ra.userid = :uid AND ra.roleid = :rid AND c.id > 1
         ORDER BY
           CASE WHEN c.shortname LIKE '%CCP1%' THEN 1 WHEN c.shortname LIKE '%CCP2%' THEN 2 ELSE 3 END,
           CASE WHEN c.shortname LIKE '%EXAM%' THEN 1 ELSE 0 END,
           c.shortname",
        ['uid' => $USER->id, 'rid' => $student_roleid]
    );
    $course_ids = array_keys((array)$courses_raw);
    if ($course_ids) {
        list($in_c, $par_c) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'cid');
        $comp_raw = $DB->get_records_sql(
            "SELECT cm.course,
                    COUNT(DISTINCT cm.id) AS total_mods,
                    SUM(CASE WHEN cmc.completionstate >= 1 THEN 1 ELSE 0 END) AS done_mods
             FROM {course_modules} cm
             LEFT JOIN {course_modules_completion} cmc ON cmc.coursemoduleid = cm.id AND cmc.userid = :uid
             WHERE cm.course $in_c AND cm.completion > 0
             GROUP BY cm.course",
            array_merge(['uid' => $USER->id], $par_c)
        );
        $comp_map = [];
        $tot_all = 0;
        $don_all = 0;
        foreach ($comp_raw as $cd) {
            $p = (int)$cd->total_mods > 0 ? min(100, (int)round($cd->done_mods / $cd->total_mods * 100)) : 0;
            $comp_map[(int)$cd->course] = ['total' => (int)$cd->total_mods, 'done' => (int)$cd->done_mods, 'pct' => $p];
            $tot_all += (int)$cd->total_mods;
            $don_all += (int)$cd->done_mods;
        }
        $global_pct = $tot_all > 0 ? min(100, (int)round($don_all / $tot_all * 100)) : 0;

        list($in_la, $par_la) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'lacid');
        $last_raw = $DB->get_record_sql(
            "SELECT ul.courseid, c.fullname, c.shortname
             FROM {user_lastaccess} ul JOIN {course} c ON c.id = ul.courseid
             WHERE ul.userid = :uid AND ul.courseid $in_la
             ORDER BY ul.timeaccess DESC LIMIT 1",
            array_merge(['uid' => $USER->id], $par_la)
        );
        if ($last_raw) {
            $last_course = ['id' => (int)$last_raw->courseid, 'fullname' => $last_raw->fullname, 'shortname' => $last_raw->shortname];
        }
        foreach ($courses_raw as $c) {
            $co = $comp_map[$c->id] ?? ['total' => 0, 'done' => 0, 'pct' => 0];
            $my_courses[] = ['id' => (int)$c->id, 'fullname' => $c->fullname, 'shortname' => $c->shortname,
                             'total_mods' => $co['total'], 'done_mods' => $co['done'], 'pct' => $co['pct']];
        }
    }
} catch (Exception $e) { $my_courses = []; }

// Formation suivie (LBA = toujours 'ecsr' ; Saran a 3 formations distinctes,
// reperees par le prefixe du shortname du cours ou l'eleve est inscrit).
// Determine la duree totale (semaines) et le contenu affiches sur les vues
// Accueil/Agenda/Calendrier/Parcours cote JS (voir FORMATIONS dans le JS).
$formation = 'ecsr';
foreach ($my_courses as $mc) {
    if (strpos($mc['shortname'], 'SARAN-GESTIONNAIRE') === 0) { $formation = 'gestionnaire'; break; }
    if (strpos($mc['shortname'], 'SARAN-SUPPORT') === 0)      { $formation = 'support'; break; }
}
$formation_weeks = ['ecsr' => 35, 'gestionnaire' => 17, 'support' => 13];
$formation_total_weeks = $formation_weeks[$formation];
$formation_labels = [
    'ecsr'         => 'Formation ECSR',
    'gestionnaire' => "Formation Gestionnaire d'auto-école",
    'support'      => 'Formation Support commercial & pédagogique',
];
$formation_label = $formation_labels[$formation];
$formation_titles = [
    'ecsr'         => 'VOTRE PARCOURS VERS LE TITRE PRO ECSR',
    'gestionnaire' => "VOTRE PARCOURS VERS LE CERTIFICAT GESTIONNAIRE",
    'support'      => 'VOTRE PARCOURS SUPPORT COMMERCIAL & PÉDAGOGIQUE',
];
$formation_title = $formation_titles[$formation];

// Devoirs en attente
$pending_assigns = [];
if ($course_ids) {
    try {
        list($in_a, $par_a) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'acid');
        // cm.id (course_modules.id) est requis par mod/assign/view.php?id=...,
        // pas a.id (l'id de l'instance assign) : ce sont deux ids distincts
        // dans Moodle (meme bug/fix que "Corrections en attente" cote
        // espace-formateur.php).
        $assigns_raw = $DB->get_records_sql(
            "SELECT cm.id AS id, a.name, a.course, c.shortname AS course_short,
                    c.fullname AS course_full, a.duedate, COALESCE(s.status,'') AS status
             FROM {assign} a
             JOIN {course} c ON c.id = a.course
             JOIN {modules} md ON md.name = 'assign'
             JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = md.id
             LEFT JOIN {assign_submission} s ON s.assignment = a.id AND s.userid = :uid
             WHERE a.course $in_a AND a.duedate >= :cutoff
               AND (s.status IS NULL OR s.status != 'submitted')
             ORDER BY a.duedate ASC LIMIT 10",
            array_merge(['uid' => $USER->id, 'cutoff' => $now - 86400 * 7], $par_a)
        );
        foreach ($assigns_raw as $a) {
            $pending_assigns[] = [
                'id'           => (int)$a->id,
                'name'         => $a->name,
                'course_short' => $a->course_short,
                'course_full'  => $a->course_full,
                'course'       => (int)$a->course,
                'duedate'      => (int)$a->duedate,
                'duedate_label'=> $a->duedate > 0 ? userdate($a->duedate, '%d/%m/%Y') : '',
                'status'       => $a->status ?: '',
            ];
        }
    } catch (Exception $e) { $pending_assigns = []; }
}

// Evenements Moodle 30j
$my_events = [];
if ($course_ids) {
    try {
        list($in_ev, $par_ev) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'evcid');
        $days_fr_ev = ['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'];
        $events_raw = $DB->get_records_sql(
            "SELECT e.id, e.name, e.timestart, e.timeduration, e.courseid, e.eventtype,
                    c.shortname AS course_short
             FROM {event} e
             JOIN {course} c ON c.id = e.courseid
             WHERE e.courseid $in_ev AND e.visible = 1
               AND e.timestart >= :now AND e.timestart < :future
               AND e.eventtype NOT IN ('due','gradingdue','open','close')
             ORDER BY e.timestart ASC LIMIT 20",
            array_merge(['now' => $now, 'future' => $now + 30 * 86400], $par_ev)
        );
        foreach ($events_raw as $e) {
            $my_events[] = [
                'id'          => (int)$e->id,
                'name'        => $e->name,
                'timestart'   => (int)$e->timestart,
                'day_label'   => $days_fr_ev[(int)date('w', (int)$e->timestart)],
                'hour_label'  => date('H:i', (int)$e->timestart),
                'date_num'    => (int)date('j', (int)$e->timestart),
                'duration_h'  => $e->timeduration > 0 ? round($e->timeduration / 3600, 1) : 2.0,
                'date_label'  => userdate($e->timestart, '%a %d %b'),
                'course_short'=> $e->course_short,
                'eventtype'   => $e->eventtype,
                'courseid'    => (int)$e->courseid,
            ];
        }
    } catch (Exception $e) { $my_events = []; }
}

// Correspondance cours -> activité LTI "Classe virtuelle Glowbl", pour lancer une
// vraie session authentifiée (SSO) au lieu du lien statique vers la salle générale.
$glowbl_lti_map = [];
if ($course_ids) {
    try {
        list($in_lti, $par_lti) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'lticid');
        $lti_rows = $DB->get_records_sql(
            "SELECT cm.id AS cmid, cm.course
             FROM {course_modules} cm
             JOIN {modules} m ON m.id = cm.module AND m.name = 'lti'
             WHERE cm.course $in_lti AND cm.visible = 1",
            $par_lti
        );
        foreach ($lti_rows as $r) { $glowbl_lti_map[(int)$r->course] = (int)$r->cmid; }
    } catch (Exception $e) { $glowbl_lti_map = []; }
}

// Semaine courante LIA
$current_week_rel = 1;
if ($my_cohort_data) {
    $sd = $my_cohort_data['startdate'];
    $ts = strtotime(strlen($sd) === 7 ? $sd . '-01' : $sd);
    if ($ts) $current_week_rel = max(1, min($formation_total_weeks, (int)floor(($now - $ts) / (7 * 86400)) + 1));
}

$eleve_data = json_encode([
    'student'         => $student_info,
    'cohort'          => $my_cohort_data,
    'courses'         => $my_courses,
    'global_pct'      => $global_pct,
    'last_course'     => $last_course,
    'pending_assigns' => $pending_assigns,
    'events'          => $my_events,
    'glowbl_lti_map'  => $glowbl_lti_map,
    'formation'       => $formation,
    'current_week'    => $current_week_rel,
    'week_label'      => userdate($now, '%d %B %Y'),
    'date_label'      => userdate($now, '%A %d %B %Y'),
], JSON_UNESCAPED_UNICODE);

$user_initials = mb_substr($USER->firstname, 0, 1) . mb_substr($USER->lastname, 0, 1);
$glowbl_url    = 'https://l3m.glowbl.com/l3m-ecsr';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mon Espace — <?= s($brand['name']) ?></title>
<style>
:root {
  --teal:<?= $brand['teal'] ?>; --teal-d:<?= $brand['teal_d'] ?>; --teal-t:<?= $brand['teal_t'] ?>;
  --orange:<?= $brand['orange'] ?>; --orange-d:<?= $brand['orange_d'] ?>; --orange-t:<?= $brand['orange_t'] ?>;
  --violet:#7C6FDD; --violet-d:#4A3FA3; --violet-t:#ECEAFA;
  --red:#D64545; --red-t:#FBE7E7;
  --sand:#B9B29E; --sand-t:#EFECE1;
  --ink:<?= $brand['ink'] ?>; --muted:<?= $brand['muted'] ?>;
  --bg:<?= $brand['bg'] ?>; --card:<?= $brand['card'] ?>; --line:<?= $brand['line'] ?>; --dark:#33424B;
}
<?php if ($user_client === 'saran'): ?>
/* Sur fond sombre, ces titres (pensés à l'origine pour un texte foncé sur
   fond clair via --teal-d) doivent repasser en clair pour rester lisibles. */
.parc-phase-head, .okbox { color:var(--ink) !important; }
<?php endif; ?>
*, *::before, *::after { box-sizing: border-box; }
body { margin:0; font-family:"Segoe UI",system-ui,sans-serif; background:var(--bg); color:var(--ink); font-size:14.5px; line-height:1.5; }

/* Layout */
.app { display:flex; min-height:100vh; }
aside { flex:0 0 72px; background:var(--teal-d); padding:0 5px 12px; display:flex; flex-direction:column; gap:2px; position:sticky; top:0; height:100vh; overflow:visible; z-index:50; }
aside .nav-brand { padding:10px 4px 8px; text-align:center; border-bottom:1px solid rgba(255,255,255,.15); margin-bottom:4px; }
aside .nb-label { display:block; font-size:7px; font-weight:800; color:rgba(255,255,255,.5); letter-spacing:.14em; text-transform:uppercase; margin-top:3px; }
aside .cls-chip { display:block; margin:4px auto 0; background:rgba(255,255,255,.18); color:rgba(255,255,255,.9); font-size:8px; font-weight:800; border-radius:6px; padding:2px 5px; letter-spacing:.04em; max-width:60px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; text-align:center; }
aside button { appearance:none; border:none; background:none; padding:8px 3px 6px; border-radius:10px; font-size:8px; font-weight:700; color:rgba(255,255,255,.55); cursor:pointer; font-family:inherit; display:flex; flex-direction:column; align-items:center; gap:3px; width:100%; transition:background .12s,color .12s; text-transform:uppercase; letter-spacing:.03em; }
aside button .nav-ico { font-size:15px; line-height:1.2; position:relative; }
aside button.on { background:rgba(255,255,255,.18); color:#fff; }
aside button:hover:not(.on) { background:rgba(255,255,255,.1); color:rgba(255,255,255,.85); }
aside .sep { flex:1; }
aside .nav-av { margin:6px auto 2px; width:34px; height:34px; border-radius:99px; background:rgba(255,255,255,.2); display:flex; align-items:center; justify-content:center; color:#fff; font-weight:800; font-size:11px; border:2px solid rgba(255,255,255,.35); flex-shrink:0; }
aside .nav-av-lbl { font-size:7px; color:rgba(255,255,255,.45); text-align:center; font-weight:600; letter-spacing:.04em; }
.nav-user-wrap { position:relative; padding-bottom:12px; }
.nav-av-btn { appearance:none; border:none; background:none; padding:0; margin:0; width:100%; display:flex; flex-direction:column; align-items:center; cursor:pointer; font-family:inherit; }
.nav-user-menu { display:none; position:absolute; left:calc(100% + 8px); bottom:0; background:var(--card); border:1px solid var(--line); border-radius:10px; box-shadow:0 8px 24px rgba(0,0,0,.18); padding:6px; min-width:150px; z-index:80; }
.nav-user-menu.open { display:block; }
.nav-user-menu-item { display:block; padding:8px 12px; border-radius:6px; font-size:12.5px; font-weight:600; color:var(--ink); text-decoration:none; white-space:nowrap; }
.nav-user-menu-item:hover { background:var(--bg); }
.nav-user-menu-item.danger { color:var(--red); }
main { flex:1; padding:24px 28px 80px; min-width:0; overflow-x:hidden; }

.view { display:none; }
.view.on { display:block; }

.eleve-icon-badge {
  position:absolute; top:-5px; right:-9px; min-width:14px; height:14px; padding:0 3px;
  background:var(--red,#D64545); color:#fff; border:1.5px solid var(--teal-d,#1C5F58);
  border-radius:99px; font-size:8.5px; font-weight:800; line-height:1;
  display:flex; align-items:center; justify-content:center;
}

.util-modal-bg { position:fixed; inset:0; background:rgba(36,51,60,.45); z-index:100; display:none; align-items:center; justify-content:center; }
.util-modal-bg.on { display:flex; }
.util-modal { background:var(--card); border-radius:18px; box-shadow:0 20px 60px rgba(0,0,0,.25); width:720px; max-width:94vw; height:94vh; max-height:920px; display:flex; flex-direction:column; overflow:hidden; }
.util-modal-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid var(--line); flex-shrink:0; }
.util-modal-head h2 { margin:0; font-size:15px; }
.util-modal-close { border:none; background:none; font-size:16px; color:var(--muted); cursor:pointer; padding:4px; line-height:1; }
.util-modal-close:hover { color:var(--ink); }
.util-modal-body { position:relative; flex:1; overflow:hidden; }
.util-modal iframe { position:absolute; inset:0; width:100%; height:100%; border:none; opacity:0; transition:opacity .15s; }
.util-modal iframe.ready { opacity:1; }

/* ===== Messagerie (UI custom, meme visuel que espace-formateur) ===== */
.msg-modal { width:960px; max-width:96vw; height:94vh; max-height:920px; display:flex; flex-direction:row; overflow:hidden; padding:0; position:relative; background:var(--card); border-radius:18px; box-shadow:0 20px 60px rgba(0,0,0,.25); }
.msg-modal-close { position:absolute; top:14px; right:16px; z-index:5; border:none; background:rgba(255,255,255,.92); width:28px; height:28px; border-radius:50%; font-size:13px; color:var(--muted); cursor:pointer; box-shadow:0 2px 6px rgba(0,0,0,.14); line-height:1; }
.msg-modal-close:hover { color:var(--ink); }

.msg-list-pane { width:308px; flex:0 0 308px; border-right:1px solid var(--line); display:flex; flex-direction:column; background:var(--card); }
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
.msg-contact-preview { font-size:11.5px; color:var(--muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-top:1px; }
.msg-contact.unread .msg-contact-preview { color:var(--ink); font-weight:600; }
.msg-contact-time { font-size:10.5px; color:var(--muted); white-space:nowrap; margin-left:auto; }
.msg-contact-unread-badge { position:absolute; right:10px; bottom:9px; background:var(--teal); color:#fff; font-size:9.5px; font-weight:800; border-radius:99px; min-width:16px; height:16px; display:flex; align-items:center; justify-content:center; padding:0 4px; }
.msg-contact-fav-star { border:none; background:none; cursor:pointer; font-size:14px; color:var(--sand); flex-shrink:0; }
.msg-contact-fav-star.on { color:var(--orange); }

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
  /* Meme probleme que "Modules" : la liste de contacts (308px fixes) et la
     conversation se partagent la largeur de la modale, ce qui ecrase la
     conversation sur mobile. On passe en plein ecran avec bouton retour. */
  .msg-modal { width:100vw; max-width:100vw; height:100vh; max-height:100vh; border-radius:0; }
  .msg-list-pane { width:100%; flex:1 1 auto; }
  .msg-thread-pane { display:none; }
  .msg-modal.msg-mob-thread .msg-list-pane { display:none; }
  .msg-modal.msg-mob-thread .msg-thread-pane { display:flex; }
  .msg-thread-back { display:flex; align-items:center; gap:6px; padding:12px 16px; border-bottom:1px solid var(--line); font-weight:700; font-size:13px; color:var(--ink); cursor:pointer; flex-shrink:0; background:var(--card); }
  .msg-modal.msg-mob-thread .msg-modal-close { display:none; }
}

/* Typography */
h1 { font-size:20px; font-weight:800; margin:0 0 4px; }
.sub { color:var(--muted); font-size:13px; margin:0 0 18px; }
.eyebrow { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--muted); margin:0 0 4px; }

/* Panel */
.panel { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:16px 18px; }
.panel-title { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--muted); margin:0 0 12px; }

/* Buttons */
.btn { display:inline-flex; align-items:center; gap:5px; border:none; border-radius:99px; padding:7px 16px; font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; text-decoration:none; transition:filter .12s; white-space:nowrap; }
.btn:hover { filter:brightness(.92); }
.btn.primary { background:var(--<?= $brand['button_accent'] ?>); color:#fff; }
.btn.ghost { background:var(--card); color:var(--<?= $brand['button_accent'] ?>-d); border:1.5px solid var(--<?= $brand['button_accent'] ?>); }
.btn.mini { padding:5px 12px; font-size:11px; }
.btn.orange-btn { background:var(--orange); color:#fff; }
.btn.violet-btn { background:var(--violet); color:#fff; }

/* Toast */
.toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:var(--dark); color:#fff; border-radius:99px; padding:11px 22px; font-size:13px; opacity:0; transition:opacity .25s; pointer-events:none; z-index:99; box-shadow:0 4px 16px rgba(0,0,0,.3); white-space:nowrap; }
.toast.on { opacity:1; }

/* Hero */
.hero { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:20px 22px; display:flex; gap:20px; align-items:center; margin-bottom:18px; flex-wrap:wrap; }
.hero-ring-wrap { flex-shrink:0; position:relative; width:110px; height:110px; }
.hero-ring-pct { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:22px; font-weight:800; color:var(--teal-d); }
.hero-content { flex:1; min-width:180px; }
.hero-course { font-size:12px; color:var(--muted); margin:0 0 3px; font-weight:600; text-transform:uppercase; letter-spacing:.05em; }
.hero-course-name { font-size:15px; font-weight:700; color:var(--ink); margin:0 0 10px; }
.hero-week-info { font-size:12.5px; color:var(--muted); margin-top:10px; }
.hero-week-info b { color:var(--ink); }

/* KPIs */
.kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-bottom:18px; }
.kpi { border-radius:14px; padding:16px 18px 18px; min-height:95px; display:flex; flex-direction:column; justify-content:space-between; border:1px solid transparent; }
.kpi-title { font-size:11px; font-weight:700; opacity:.7; }
.kpi-num { font-size:30px; font-weight:800; line-height:1; letter-spacing:-.03em; font-variant-numeric:tabular-nums; margin-top:8px; }
.kpi-num small { font-size:14px; opacity:.55; margin-left:1px; }
.kpi-sub { font-size:11px; opacity:.55; font-weight:500; margin-top:6px; }
.k-orange { background:#F9C57D; border-color:#F5B15F; color:#24333C; }
.k-mint   { background:#C7EDE1; border-color:#A7DDCB; color:#24333C; }
.k-violet { background:#DDD9F7; border-color:#C9C3F0; color:#24333C; }
.k-cream  { background:var(--card); border-color:var(--line); }
.k-danger { background:var(--red-t); border-color:#F5C4C4; color:#24333C; }
.k-danger .kpi-num { color:var(--red); }

/* Grid 2 cols */
.cols2 { display:grid; grid-template-columns:1.15fr .85fr; gap:14px; }
@media(max-width:900px) { .cols2 { grid-template-columns:1fr; } }
.col-stack { display:flex; flex-direction:column; gap:14px; }

/* Sig / devoir cards */
.sig-card { background:var(--bg); border:1px solid var(--line); border-radius:11px; padding:12px 13px; margin-bottom:7px; }
.sig-card:last-child { margin-bottom:0; }
.sig-student { font-size:13.5px; font-weight:700; }
.sig-detail { font-size:12px; color:var(--muted); margin-top:2px; }
.sig-acts { display:flex; gap:6px; margin-top:9px; flex-wrap:wrap; }
.okbox { background:var(--teal-t); border:1px solid var(--teal); border-radius:10px; padding:14px 16px; font-size:13px; font-weight:700; color:var(--teal-d); }

/* Week blocs */
.week-lbl { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--muted); margin:0 0 8px; }
.week-bloc { display:flex; align-items:center; gap:10px; padding:7px 0; border-bottom:1px solid var(--line); font-size:13px; }
.week-bloc:last-of-type { border:none; }
.wb-h { flex:0 0 26px; font-weight:800; color:var(--teal-d); font-size:12px; }
.wb-name { flex:1; }

/* Parcours strip (reuse planning style) */
.strip-wrap { overflow-x:auto; margin-bottom:6px; }
.strip-youarehere { position:absolute; top:-24px; left:50%; transform:translateX(-50%); background:var(--violet); color:#fff; font-size:9px; font-weight:800; padding:2px 7px; border-radius:99px; white-space:nowrap; pointer-events:none; }
/* ─── parcours phases ─── */
.parc-phase { background:var(--card); border-radius:14px; border:1px solid var(--line); margin-bottom:14px; overflow:hidden; }
.parc-phase-head { padding:12px 18px; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--teal-d); border-bottom:1px solid var(--line); display:flex; align-items:center; gap:8px; }
.parc-wrange { font-weight:500; color:var(--muted); letter-spacing:0; text-transform:none; font-size:11px; }
.parc-row { display:flex; align-items:center; gap:12px; padding:12px 18px; border-bottom:1px solid var(--line); }
.parc-row:last-of-type { border-bottom:none; }
.parc-body { flex:1; min-width:0; }
.parc-name { font-size:13.5px; font-weight:600; color:var(--ink); }
.parc-sub-lbl { font-size:11.5px; color:var(--muted); margin-top:2px; }
.parc-badges { display:flex; gap:5px; flex-wrap:wrap; margin-top:5px; }
.parc-right { display:flex; align-items:center; gap:8px; flex-shrink:0; }
.pi-done { width:22px; height:22px; border-radius:50%; background:var(--teal); display:flex; align-items:center; justify-content:center; color:#fff; flex-shrink:0; }
.pi-cur  { width:22px; height:22px; border-radius:50%; background:var(--violet); flex-shrink:0; }
.pi-ns   { width:22px; height:22px; border-radius:50%; border:2px solid var(--line); background:var(--card); flex-shrink:0; }
.parc-exam { background:#24333C; color:#fff; padding:14px 18px; }
.badge { display:inline-block; padding:2px 9px; border-radius:99px; font-size:10.5px; font-weight:700; }
.b-el    { background:rgba(124,111,221,.12); color:#5a50b8; }
.b-visio { background:rgba(46,148,136,.14); color:var(--teal-d); }
.b-pres  { background:rgba(242,160,61,.15); color:#b07020; }
.strip-inner { display:flex; gap:3px; align-items:flex-end; padding:10px 0 0; min-width:520px; min-height:80px; }
.strip-bar { flex:1; border-radius:4px 4px 0 0; min-width:9px; cursor:default; transition:filter .12s; }
.strip-bar:hover { filter:brightness(.85); }
.strip-bar.cur-week { outline:2.5px solid var(--violet); outline-offset:2px; }
.strip-lbl { display:flex; gap:3px; min-width:520px; padding:3px 0 1px; }
.strip-lbl span { flex:1; font-size:8px; color:var(--muted); text-align:center; font-variant-numeric:tabular-nums; }
.strip-lbl span.cur-lbl { color:var(--violet); font-weight:800; }
.parc-legend { display:flex; gap:10px; flex-wrap:wrap; font-size:11px; color:var(--muted); margin-bottom:12px; align-items:center; }
.psw { display:inline-block; width:10px; height:10px; border-radius:3px; margin-right:3px; vertical-align:-1px; }

/* Cours cards */
.cours-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:12px; }

.cours-card { background:var(--card); border:1px solid var(--line); border-radius:13px; padding:16px; display:flex; flex-direction:column; gap:8px; transition:box-shadow .15s; }
/* ─── vue detail (contenu cours dynamique) ─── */
#v-detail.on{display:flex!important;flex-direction:column;margin:-24px -28px -80px;height:100vh;overflow:hidden;background:var(--bg)}
.dv-topbar{padding:18px 24px 0;background:var(--bg);flex-shrink:0}
.dv-topbar .eyebrow{margin-bottom:2px}
.dv-topbar h1{font-size:20px;margin:0 0 3px}
.dv-topbar .sub{margin:0 0 12px}
.dv-body{display:flex;flex:1;overflow:hidden}
/* Panneau gauche : arbre du cours */
.dv-left{width:300px;flex-shrink:0;background:#fff;border-right:1px solid #E4E1D6;display:flex;flex-direction:column;overflow:hidden}
.dv-left-head{padding:16px 18px 10px;border-bottom:1px solid #E4E1D6;flex-shrink:0}
.dv-breadcrumb{font-size:11px;color:var(--muted);line-height:1.5;margin-bottom:8px;word-break:break-word}
.dv-breadcrumb .dv-crumb-sep{margin:0 4px;color:var(--muted)}
.dv-breadcrumb .dv-crumb-current{color:var(--ink);font-weight:700}
.dv-left-title-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.dv-left-title{font-size:16px;font-weight:800;color:var(--ink)}
.dv-unfold{font-size:11px;color:var(--teal);cursor:pointer;text-decoration:none;font-weight:600}
.dv-search{display:flex;align-items:center;gap:8px;background:var(--bg);border-radius:8px;padding:7px 12px}
.dv-search input{border:none;background:none;font-size:13px;color:var(--ink);outline:none;width:100%;font-family:inherit}
.dv-sections{flex:1;overflow-y:auto}
/* Section item */
.det-sec{border-bottom:1px solid #F0EDE4}
.det-sec-head{display:flex;align-items:center;gap:12px;padding:12px 18px;cursor:pointer;transition:background .1s}
.det-sec-head:hover{background:var(--bg)}
.det-sec-num{width:32px;height:32px;border-radius:50%;border:2px solid var(--teal);color:var(--teal);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;transition:background .15s}
.det-sec-num.done{background:var(--teal);color:#fff}
.det-sec-info{flex:1;min-width:0}
.det-sec-name{font-size:13px;font-weight:700;color:var(--ink);line-height:1.3}
.det-sec-count{font-size:11px;color:var(--muted);margin-top:1px}
.det-sec-arrow{color:var(--muted);font-size:11px;flex-shrink:0;transition:transform .15s}
.det-sec-arrow.open{transform:rotate(90deg)}
/* Activity items */
.det-mods{display:none;padding:0 18px 8px 62px}
.det-mods.open{display:block}
.det-mod{display:flex;align-items:flex-start;gap:8px;padding:7px 0;cursor:pointer;font-size:13px;color:var(--ink);border-left:2px solid transparent;transition:color .12s}
.det-mod:hover .det-mod-name{color:var(--teal)}
.det-mod.active .det-mod-name{color:var(--orange);font-weight:700}
.det-mod-dot{width:10px;height:10px;border-radius:50%;background:#C8C4B8;flex-shrink:0;margin-top:3px}
.det-mod-dot.done{background:var(--teal)}
.det-mod-dot.active-dot{background:var(--orange)}
.det-mod-name{line-height:1.35;flex:1}
/* Panneau droite : iframe */
.dv-right{flex:1;overflow:hidden;display:flex;flex-direction:column;position:relative}
#dv-iframe{flex:1;border:none;width:100%;display:block;opacity:1;transition:opacity .15s}
.dv-loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:var(--bg)}
.dv-spinner{width:28px;height:28px;border-radius:50%;border:3px solid var(--line);border-top-color:var(--teal);animation:dvspin2 .7s linear infinite}
@keyframes dvspin2{to{transform:rotate(360deg)}}
.dv-empty{display:flex;align-items:center;justify-content:center;flex:1;color:var(--muted);font-size:13px;font-style:italic}
.dv-mobile-back{display:none}
@media(max-width:760px){
  /* "Modules" en deux colonnes fixes (liste 300px + contenu) ecrase le
     contenu sur mobile. On passe en navigation plein ecran : la liste des
     sections occupe tout l'ecran par defaut, et des qu'une activite est
     choisie (classe .dv-mob-content posee par dvSelectMod), c'est le
     contenu qui prend tout l'ecran a son tour, avec un bouton retour. */
  .dv-left{width:100%}
  .dv-right{display:none}
  .dv-mobile-back{display:flex;align-items:center;gap:6px;padding:12px 16px;border-bottom:1px solid #E4E1D6;background:#fff;font-weight:700;font-size:13px;color:var(--ink);cursor:pointer;flex-shrink:0}
  .dv-body.dv-mob-content .dv-left{display:none}
  .dv-body.dv-mob-content .dv-right{display:flex}
}
/* Apercu instantane (objectifs + WhatsApp) affiche a la fermeture d'une activite,
   sans recharger toute la page de cours Moodle dans l'iframe (beaucoup plus lent). */
.dv-overview{flex:1;overflow-y:auto;padding:28px 32px;background:var(--bg)}
.dv-overview .l3m-objectifs{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:24px 28px;margin:0 0 24px 0}
.dv-overview .l3m-obj-title{font-size:15px;font-weight:800;color:var(--ink);margin:0 0 18px 0;text-transform:uppercase;letter-spacing:.06em}
.dv-overview .l3m-obj-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 24px}
.dv-overview .l3m-obj-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:10px}
.dv-overview .l3m-obj-list li{display:flex;align-items:flex-start;gap:10px;font-size:13px;color:var(--ink);line-height:1.5}
.dv-overview .l3m-obj-list li::before{content:"\2713";color:var(--teal);font-weight:800;font-size:14px;flex-shrink:0;margin-top:1px}
.dv-overview-whatsapp{display:inline-flex;align-items:center;gap:10px;background:#25D366;color:#fff;font-weight:700;font-size:14px;padding:12px 24px;border-radius:50px;text-decoration:none;box-shadow:0 4px 16px rgba(0,0,0,.25)}
.dv-overview-whatsapp:hover{background:#1ebe57}
.dv-overview-empty{color:var(--muted);font-size:13px;font-style:italic;text-align:center;padding:60px 0}
@media (max-width:640px){.dv-overview .l3m-obj-grid{grid-template-columns:1fr}}
.cours-card:hover { box-shadow:0 4px 16px rgba(36,51,60,.08); }
.cours-shortname { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--muted); }
.cours-fullname { font-size:13.5px; font-weight:700; color:var(--ink); line-height:1.35; min-height:calc(13.5px * 1.35 * 2); display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.cours-pct { font-size:12px; color:var(--muted); }
.pbar { height:6px; border-radius:3px; background:var(--line); }
.pbar i { display:block; height:100%; border-radius:3px; background:var(--teal); }
.cours-card .btn.mini.primary { margin-top:auto !important; align-self:flex-start; }

/* Agenda */
.cal-wrap{display:flex;flex-direction:column;height:calc(100vh - 40px);margin:-24px -28px -80px;overflow:hidden;background:var(--bg)}
.cal-topbar{padding:16px 110px 10px 22px;background:var(--bg);flex-shrink:0;border-bottom:1px solid var(--line)}
.cal-head-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:8px}
.cal-head-row h1{margin:0;font-size:20px;font-weight:800;color:var(--ink);flex:0 0 auto}
.cal-nav{display:flex;align-items:center;gap:6px}
.cal-arrow{width:30px;height:30px;border:1px solid var(--line);border-radius:8px;background:var(--card);cursor:pointer;font-size:15px;color:var(--ink);display:flex;align-items:center;justify-content:center;padding:0;line-height:1}
.cal-month-lbl{font-size:15px;font-weight:700;color:var(--ink);min-width:148px;text-align:center}
.cal-view-btns{display:flex;border:1px solid var(--line);border-radius:8px;overflow:hidden;flex-shrink:0}
.cal-view-btns button{padding:5px 13px;font-size:12px;font-weight:600;border:none;background:var(--card);color:var(--muted);cursor:pointer}
.cal-view-btns button.on{background:var(--teal);color:#fff}
.cal-filters{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto}
.cal-flbl{display:flex;align-items:center;gap:4px;font-size:11px;font-weight:600;cursor:pointer;color:var(--ink)}
.cal-flbl input{accent-color:var(--teal);width:13px;height:13px;cursor:pointer}
.cal-legend{display:flex;gap:14px;flex-wrap:wrap}
.cal-legend-item{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--muted)}
.cal-legend-dot{width:10px;height:10px;border-radius:3px;flex-shrink:0}
.cal-grid-wrap{flex:1;overflow:auto}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);min-width:560px}
.cal-dow{position:sticky;top:0;background:#F7F6F1;z-index:2;padding:7px 0 5px;text-align:center;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);border-bottom:1px solid var(--line);border-right:1px solid var(--line)}
.cal-dow:last-child{border-right:none}
.cal-cell{padding:5px 4px;border-right:1px solid var(--line);border-bottom:1px solid var(--line);min-height:90px;overflow:hidden}
.cal-cell:nth-child(7n){border-right:none}
.cal-cell.other-month .cal-dn{color:#C8C4B8}
.cal-cell.today .cal-dn{background:var(--violet);color:#fff;border-radius:50%}
.cal-dn{font-size:11px;font-weight:700;color:var(--ink);width:22px;height:22px;display:flex;align-items:center;justify-content:center;margin-bottom:2px}
.cal-ev{font-size:10px;font-weight:600;padding:2px 5px;border-radius:3px;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.4}
@media(max-width:760px){
  /* Vue Mois : on retire la largeur minimale et on resserre tout pour que
     les 7 colonnes tiennent entièrement dans l'écran, sans scroll horizontal. */
  .cal-grid{min-width:0}
  .cal-dow{font-size:8px;padding:5px 0 3px}
  .cal-cell{padding:3px 2px;min-height:64px}
  .cal-dn{font-size:10px;width:17px;height:17px;margin-bottom:1px}
  .cal-ev{font-size:7px;padding:1px 3px;border-radius:2px;margin-bottom:1px;line-height:1.3}
}
@media(max-width:400px){
  .cal-cell{min-height:54px}
  .cal-dn{font-size:9px;width:15px;height:15px}
  .cal-ev{font-size:6.5px}
}
@media(max-width:760px){
  /* Barre du haut : les 110px de marge droite (pensés pour le desktop) et
     la largeur fixe du libellé du mois font sortir "Aujourd'hui" de l'écran
     sur mobile. On resserre tout et on autorise le retour à la ligne. */
  .cal-topbar{padding:14px 16px 8px}
  .cal-nav{flex-wrap:wrap;row-gap:6px}
  .cal-month-lbl{min-width:0;font-size:13px}
}
.cal-ev.ev-elearning{background:#ECEAFA;color:#5B4DC0}
.cal-ev.ev-visio{background:#E3F2EF;color:#1C5F58}
.cal-ev.ev-presentiel{background:#FEF3E7;color:#B86A10}
.cal-ev.ev-stage{background:#F0EDE4;color:#7A6B52}
.cal-ev.ev-examen{background:#2A2A2A;color:#fff}
.cal-ev.ev-devoir{background:#FBE7E7;color:#D64545}
.cal-ev.ev-moodle{background:#E3EDF2;color:#2C6B8A}
.cal-liste-weeks{padding:16px 22px}
.cal-wrow{display:flex;align-items:flex-start;gap:16px;background:var(--card);border-radius:10px;border:1px solid var(--line);border-left:4px solid var(--teal);padding:14px 16px;margin-bottom:10px}
.cal-wrow-left{flex:0 0 130px;min-width:0}
.cal-wrow-dates{font-size:13px;font-weight:700;color:var(--ink);white-space:nowrap}
.cal-wrow-snum{font-size:11px;color:var(--muted);margin-top:3px;line-height:1.4}
.cal-wrow-right{flex:1;min-width:0}
.cal-wrow-title{font-size:14px;font-weight:700;color:var(--ink);display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-bottom:4px}
.cal-wrow-desc{font-size:12px;color:var(--muted);margin-bottom:4px}
.cal-wrow-detail{font-size:11px;color:var(--teal-d);margin-top:4px;padding:5px 8px;background:var(--teal-t);border-radius:6px}
.cal-wrow .cal-ev{font-size:11px;padding:2px 8px;border-radius:99px}
.cal-liste-footer{display:flex;justify-content:space-between;align-items:center;padding:8px 22px 16px;gap:8px}
.cal-liste-footer .cal-footer-center{font-size:12px;color:var(--muted);text-align:center}
.cal-liste-btns{display:flex;gap:8px;padding:0 22px 16px;flex-wrap:wrap}

/* Doc / devoir table */
.doc-item { display:flex; gap:10px; align-items:center; padding:10px 0; border-bottom:1px solid var(--line); font-size:13px; flex-wrap:wrap; }
.doc-item:last-child { border:none; }
.doc-name { flex:1; font-weight:600; min-width:0; }
.doc-course { font-size:11.5px; color:var(--muted); }
.doc-due { font-size:12px; color:var(--muted); white-space:nowrap; }
.doc-due.overdue { color:var(--red); font-weight:700; }
/* Documents administratifs */
.docs-table{width:100%;border-collapse:collapse;font-size:13px}
.docs-table thead tr{border-bottom:1px solid var(--line)}
.docs-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);padding:10px 16px;text-align:left}
.docs-table td{padding:14px 16px;border-bottom:1px solid var(--line);vertical-align:middle}
.docs-table tr:last-child td{border-bottom:none}
.docs-table tr:hover td{background:var(--teal-t)}
.doc-row-name{font-weight:700;color:var(--ink)}
.doc-row-sub{font-size:11.5px;color:var(--muted);margin-top:3px}
.doc-status-badge{display:inline-flex;align-items:center;padding:4px 10px;margin-left:-10px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap}
@media(max-width:640px){
  /* Le tableau 4 colonnes (document / statut / action / envoi) deborde sur
     mobile et le bouton d'action se retrouve coupe hors ecran. On empile
     chaque ligne verticalement comme une petite fiche a la place. */
  .docs-table thead{display:none}
  .docs-table, .docs-table tbody, .docs-table tr, .docs-table td{display:block;width:100%}
  .docs-table tr{padding:14px 16px;border-bottom:1px solid var(--line)}
  .docs-table td{padding:0;border-bottom:none}
  .docs-table td:empty{display:none}
  .docs-table td:not(:first-child){margin-top:8px}
  .docs-table .doc-status-badge{margin-left:0}
}
.dsb-signed,.dsb-validated{background:#E3F5EE;color:#2E9488}
.dsb-pending{background:var(--red-t);color:var(--red)}
.dsb-future{background:var(--sand-t);color:var(--sand)}

/* Params */
.params-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(270px,1fr)); gap:14px; }
.set-card { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:18px 20px; }
.set-card .sc-head { display:flex; align-items:flex-start; gap:10px; margin-bottom:14px; }
.ico { width:38px; height:38px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
.ico-teal { background:var(--teal-t); color:var(--teal-d); }
.ico-orange { background:var(--orange-t); color:var(--orange-d); }
.ico-violet { background:var(--violet-t); color:var(--violet-d); }
.sc-head .text h3 { margin:0; font-size:14px; font-weight:800; }
.sc-head .text p { margin:3px 0 0; font-size:11.5px; color:var(--muted); }
.param-row { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid var(--line); font-size:12.5px; }
.param-row:last-child { border:none; }
.param-row .lbl { color:var(--muted); }
.param-row .val { font-weight:700; text-align:right; }

/* Veh card */
.veh-card { border-radius:10px; overflow:hidden; margin-bottom:8px; }
.veh-header { background:var(--orange); color:#fff; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.07em; padding:7px 13px; }
.veh-body { font-size:13px; color:var(--ink); padding:10px 13px; background:#FBF0E4; border:1px solid #F2C99A; border-top:none; border-radius:0 0 10px 10px; }


/* Alert banner */
.alert-banner { display:flex; align-items:center; gap:12px; background:#FDE8E8; border:1px solid #FBBABA; border-radius:12px; padding:14px 18px; margin-bottom:16px; color:#24333C; }
.ab-icon { font-size:20px; flex-shrink:0; }
.ab-content { flex:1; font-size:13px; }
.ab-content b { color:#B91C1C; display:block; }
.ab-content span { color:var(--muted); }

/* Hero redesign */
.hero-card { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:26px 28px; display:flex; justify-content:space-between; align-items:center; gap:24px; margin-bottom:16px; flex-wrap:wrap; }
.hero-left { flex:1; min-width:200px; }
.hero-badge { display:inline-flex; align-items:center; gap:5px; background:var(--teal-t); color:var(--teal-d); border:1px solid var(--teal); border-radius:99px; font-size:10.5px; font-weight:700; letter-spacing:.05em; padding:4px 12px; margin-bottom:14px; }
.hero-greeting { font-size:26px; font-weight:800; margin:0 0 8px; }
.hero-desc { font-size:13.5px; color:var(--muted); margin:0 0 16px; line-height:1.55; max-width:480px; }
.hero-desc b { color:var(--ink); }
.hero-ctas { display:flex; gap:10px; flex-wrap:wrap; }
.hero-right { flex-shrink:0; display:flex; flex-direction:column; align-items:center; }
.hero-ring-wrap { position:relative; width:150px; height:150px; }
.ring-inner { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; }
.ring-pct-big { font-size:32px; font-weight:800; color:var(--ink); line-height:1; }
.ring-lbl { font-size:8.5px; font-weight:800; text-transform:uppercase; letter-spacing:.09em; color:var(--muted); margin-top:4px; text-align:center; max-width:90px; }
.ring-sub { font-size:11px; color:var(--muted); margin-top:8px; text-align:center; }

/* Ma semaine */
.panel-hd { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
.panel-hd .panel-title { margin:0; }
.link-btn { background:none; border:none; color:var(--teal-d); font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; padding:0; }
.link-btn:hover { text-decoration:underline; }
.semaine-row { display:flex; align-items:center; gap:8px; padding:9px 0; border-bottom:1px solid var(--line); font-size:13px; flex-wrap:wrap; }
.semaine-row:last-child { border:none; }
.sr-day { flex:0 0 38px; font-size:10.5px; font-weight:800; color:var(--teal-d); text-align:center; background:var(--teal-t); border-radius:7px; padding:4px 2px; line-height:1.3; }
.sr-name { flex:1; font-weight:600; min-width:0; }
.sr-type { font-size:10px; font-weight:700; border-radius:99px; padding:2px 8px; white-space:nowrap; flex-shrink:0; }
.sr-type.t-visio { background:var(--teal-t); color:var(--teal-d); border:1px solid var(--teal); }
.sr-type.t-el { background:var(--violet-t); color:var(--violet-d); border:1px solid var(--violet); }
.sr-type.t-due { background:var(--orange-t); color:var(--orange-d); border:1px solid #F2C99A; }

/* Mes actions */
.action-item { display:flex; align-items:center; gap:12px; padding:10px 0; border-bottom:1px solid var(--line); text-decoration:none; color:var(--ink); }
.action-item:last-child { border:none; }
.action-item:hover { opacity:.78; }
.ai-ico { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; }
.ai-body { flex:1; min-width:0; }
.ai-title { font-size:13px; font-weight:700; }
.ai-sub { font-size:11.5px; color:var(--muted); margin-top:1px; }
.ai-arr { color:var(--line); font-size:16px; flex-shrink:0; }

/* Mini grid vehicule + stage */
.mini-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px; }
@media(max-width:700px) { .mini-grid { grid-template-columns:1fr; } }
.mc { border-radius:14px; border:2px solid var(--line); padding:16px; background:var(--card); }
.mc.mc-veh { border-color:#F2C99A; background:#FFFAF4; }
.mc.mc-stage { border-color:var(--line); background:var(--bg); }
.mc-hd { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; margin-bottom:6px; }
.mc-title { font-size:13.5px; font-weight:800; }
.mc-dates-lbl { font-size:11.5px; color:var(--muted); white-space:nowrap; }
.mc-sub1 { font-size:13px; font-weight:600; margin-bottom:2px; }
.mc-sub2 { font-size:12px; color:var(--muted); line-height:1.45; }
.mc-warn { font-size:11.5px; color:var(--red); font-weight:600; margin-top:6px; }

/* Timeline */
.tl-wrap { margin-top:16px; margin-bottom:0; }
.tl-hd { display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:6px; margin-bottom:18px; }
.tl-track { position:relative; height:8px; background:var(--line); border-radius:99px; margin:32px 0 44px; }
.tl-fill { position:absolute; left:0; top:0; height:100%; background:linear-gradient(90deg,var(--teal),var(--violet)); border-radius:99px; transition:width .9s ease; }
.tl-dots { position:absolute; top:50%; left:0; right:0; transform:translateY(-50%); }
.tl-dot { position:absolute; top:50%; transform:translate(-50%,-50%); width:34px; height:34px; border-radius:99px; border:3px solid var(--card); box-shadow:0 0 0 2px var(--line); background:var(--card); cursor:default; display:flex; align-items:center; justify-content:center; font-size:14px; color:var(--muted); }
.tl-dot.done { background:var(--teal-t); box-shadow:0 0 0 2px var(--teal); color:var(--teal-d); }
.tl-dot.here { background:var(--violet); box-shadow:0 0 0 4px rgba(124,111,221,.25); width:38px; height:38px; }
.tl-dot.here::after { content:''; width:11px; height:11px; border-radius:99px; background:#fff; }
.tl-bot { display:flex; justify-content:space-between; font-size:11px; color:var(--muted); margin-top:-30px; }
.tl-bot-item { text-align:center; max-width:130px; white-space:normal; }
.tl-bot-item b { display:block; font-size:13px; font-weight:800; color:var(--ink); margin-bottom:1px; }
.tl-bot-item.here b { color:var(--violet); }

button:focus-visible { outline:2px solid var(--teal); outline-offset:2px; }
@media(max-width:760px) { aside { flex-basis:52px; } main { padding:16px 14px 60px; } .cols2 { grid-template-columns:1fr; } }
@media(prefers-reduced-motion:reduce) { *, *::before, *::after { transition:none !important; } }
</style>
</head>
<body>
<div class="app">

<aside>
  <div class="nav-brand">
    <?php if (!empty($brand['logo_dark'])): ?>
      <img src="<?= $brand['logo_dark'] ?>" alt="<?= s($brand['name']) ?>" style="width:60px;height:auto;display:block;margin:0 auto">
    <?php else: ?>
      <svg width="20" height="15" viewBox="0 0 34 24" aria-hidden="true">
        <path d="M3 21 A14 14 0 0 1 31 21" fill="none" stroke="rgba(255,255,255,.7)" stroke-width="4" stroke-linecap="round"/>
        <line x1="17" y1="21" x2="26" y2="9" stroke="<?= $brand['orange'] ?>" stroke-width="3" stroke-linecap="round"/>
        <circle cx="17" cy="21" r="3" fill="<?= $brand['orange'] ?>"/>
      </svg>
    <?php endif; ?>
    <span class="nb-label">Eleve</span>
    <span class="cls-chip" id="sidebar-cls">—</span>
  </div>
  <button class="on" data-v="accueil"  onclick="nav(this)"><span class="nav-ico">🏠</span>Accueil</button>
  <button data-v="parcours" onclick="nav(this)"><span class="nav-ico">📚</span>Parcours</button>
  <button data-v="cours"    onclick="nav(this)"><span class="nav-ico">▶</span>Modules</button>
  <button data-v="agenda"   onclick="nav(this)"><span class="nav-ico">📅</span>Agenda</button>
  <button data-v="docs"     onclick="nav(this)" id="nav-docs-btn"><span class="nav-ico">📄</span>Docs</button>
  <button data-v="params"   onclick="nav(this)"><span class="nav-ico">⚙</span>Réglages</button>
  <div class="sep"></div>
  <button type="button" onclick="openUtilModal('notif')" title="Notifications" aria-label="Notifications"><span class="nav-ico">&#128276;<span class="eleve-icon-badge" id="notif-badge" style="display:none">0</span></span>Notifs</button>
  <button type="button" onclick="openMessageModal()" title="Messages" aria-label="Messages"><span class="nav-ico">&#128172;<span class="eleve-icon-badge" id="msg-badge" style="display:none">0</span></span>Messages</button>
  <div class="nav-user-wrap">
    <button type="button" class="nav-av-btn" onclick="toggleNavUserMenu(event)">
      <span class="nav-av"><?= htmlspecialchars($user_initials) ?></span>
      <span class="nav-av-lbl"><?= s($USER->firstname) ?></span>
    </button>
    <div class="nav-user-menu" id="nav-user-menu">
      <a class="nav-user-menu-item danger" href="<?= $CFG->wwwroot ?>/login/logout.php?sesskey=<?= sesskey() ?>" onclick="return doLogout(event)">Déconnexion</a>
    </div>
  </div>
</aside>

<main>

<!-- VUE ACCUEIL -->
<div class="view on" id="v-accueil">

  <!-- Bandeau alerte -->
  <div class="alert-banner" id="acc-alert" style="display:none">
    <span class="ab-icon">&#9888;</span>
    <div class="ab-content"><b id="ab-title"></b><span id="ab-desc"></span></div>
    <a class="btn mini orange-btn" id="ab-cta" href="#" target="_blank" rel="noopener">Traiter</a>
  </div>

  <!-- Hero -->
  <div class="hero-card" id="acc-hero">
    <div class="hero-left">
      <div class="hero-badge" id="hero-badge">SEMAINE 1/<?= $formation_total_weeks ?> &middot; EN COURS</div>
      <h2 class="hero-greeting" id="acc-greeting">Bonjour !</h2>
      <p class="hero-desc" id="hero-desc">Chargement&hellip;</p>
      <div class="hero-ctas">
        <a class="btn primary" id="hero-resume-btn" href="#" target="_blank" rel="noopener">Reprendre le cours &#8594;</a>
        <button class="btn ghost" onclick="navTo('parcours')">Voir mon parcours</button>
      </div>
    </div>
    <div class="hero-right">
      <div class="hero-ring-wrap">
        <svg width="150" height="150" viewBox="0 0 150 150">
          <circle cx="75" cy="75" r="62" fill="none" stroke="#F0EDE4" stroke-width="12"/>
          <circle id="ring-fg" cx="75" cy="75" r="62" fill="none" stroke="#F2A03D" stroke-width="12"
            stroke-dasharray="390" stroke-dashoffset="390"
            stroke-linecap="round" transform="rotate(-90 75 75)" style="transition:stroke-dashoffset .9s ease"/>
        </svg>
        <div class="ring-inner">
          <div class="ring-pct-big" id="ring-pct">0%</div>
          <div class="ring-lbl" id="ring-lbl">e-learning</div>
        </div>
      </div>
      <div class="ring-sub" id="ring-sub">29&nbsp;% de l'e-learning complété</div>
    </div>
  </div>

  <!-- Ma semaine + Mes actions -->
  <div class="cols2" style="margin-bottom:14px">
    <div class="panel">
      <div class="panel-hd">
        <p class="panel-title">MA SEMAINE &middot; <span id="week-dates-lbl"></span></p>
        <button class="link-btn" onclick="navTo('agenda')">Agenda &#8594;</button>
      </div>
      <div id="acc-semaine"></div>
    </div>
    <div class="panel">
      <p class="panel-title">MES ACTIONS</p>
      <div id="acc-actions"></div>
    </div>
  </div>

  <!-- Mini-cards vehicule + stage -->
  <div class="mini-grid" id="acc-mini-grid" style="display:none"></div>

  <!-- Timeline parcours -->
  <div class="panel" id="acc-tl" style="display:none">
    <div class="tl-hd">
      <p class="panel-title" style="margin:0"><?= $formation_title ?></p>
      <span id="tl-summary" style="font-size:12px;color:var(--muted)"></span>
    </div>
    <div class="tl-wrap">
      <div class="tl-track">
        <div class="tl-fill" id="tl-fill" style="width:0%"></div>
        <div class="tl-dots" id="tl-dots"></div>
      </div>
      <div class="tl-bot" id="tl-bot"></div>
    </div>
  </div>

</div>

<!-- VUE PARCOURS -->
<div class="view" id="v-parcours">
  <p class="eyebrow"><?= $formation_label ?></p>
  <h1>Mon parcours</h1>
  <p class="sub" id="parc-sub"><?= $formation_total_weeks ?> semaines</p>
  <div class="panel" style="margin-bottom:14px;padding:16px 14px 12px">
    <p class="panel-title">LA FRISE DES <?= $formation_total_weeks ?> SEMAINES</p>
    <div class="strip-wrap">
      <div class="strip-inner" id="parc-bars"></div>
      <div class="strip-lbl"   id="parc-labels"></div>
    </div>
    <div class="parc-legend">
      <span><i class="psw" style="background:#7CC9AC"></i>Cours a distance</span>
      <span><i class="psw" style="background:#E8E4DA"></i>Stage en entreprise</span>
      <span><i class="psw" style="background:#F2A03D"></i>Semaine vehicule</span>
      <span><i class="psw" style="background:#F6C685"></i>Regroupement en salle</span>
      <span><i class="psw" style="background:#24333C"></i>Examens</span>
    </div>
  </div>
  <div id="parc-phases"></div>
</div>

<!-- VUE MODULES -->
<div class="view" id="v-cours">
  <p class="eyebrow">Mes formations</p>
  <h1>Mes modules</h1>
  <p class="sub" id="cours-sub">Chargement…</p>
  <div class="cours-grid" id="cours-grid"></div>
</div>

<!-- VUE DETAIL -->
<div class="view" id="v-detail">

  <!-- Topbar dynamique -->
  <div class="dv-topbar">
    <p class="eyebrow">Formation</p>
    <h1 id="dv-course-title">S&#233;lectionnez un cours</h1>
    <p class="sub" id="dv-course-sub"></p>
  </div>

  <div class="dv-body">

    <!-- Panneau gauche : arbre du cours -->
    <div class="dv-left">
      <div class="dv-left-head">
        <div class="dv-breadcrumb" id="dv-breadcrumb"></div>
        <div class="dv-left-title-row">
          <span class="dv-left-title">Contenu du module</span>
          <a class="dv-unfold" onclick="dvUnfoldAll()">Tout d&#233;plier</a>
        </div>
        <div class="dv-search">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#B9B29E" stroke-width="2.5">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
          <input type="text" id="dv-search-inp" placeholder="Rechercher" oninput="dvFilter(this.value)">
        </div>
      </div>
      <div class="dv-sections" id="dv-sections">
        <p style="padding:20px 18px;color:var(--muted);font-size:13px">
          Cliquez sur un module pour voir son contenu.
        </p>
      </div>
    </div>

    <!-- Panneau droite : iframe contenu -->
    <div class="dv-right">
      <div class="dv-mobile-back" onclick="dvMobileBack()">&#8592; Retour aux sections</div>
      <div class="dv-empty" id="dv-empty">S&#233;lectionnez une activit&#233; dans le menu &#224; gauche.</div>
      <div class="dv-loading" id="dv-loading" style="display:none"><span class="dv-spinner"></span></div>
      <iframe id="dv-iframe" src="" title="Contenu du module" allowfullscreen style="display:none"></iframe>
      <div class="dv-overview" id="dv-overview" style="display:none"></div>
    </div>

  </div>
</div>

<!-- VUE AGENDA -->
<div class="view" id="v-agenda">
  <div class="cal-wrap">
    <div class="cal-topbar">
      <p class="eyebrow" style="margin:0 0 6px">Planning</p>
      <div class="cal-head-row">
        <h1>Calendrier</h1>
        <div class="cal-nav">
          <button class="cal-arrow" onclick="calPrev()">&#8249;</button>
          <span class="cal-month-lbl" id="cal-month-lbl"></span>
          <button class="cal-arrow" onclick="calNext()">&#8250;</button>
          <button class="btn mini primary" onclick="calGoToday()">Aujourd'hui</button>
        </div>
        <div class="cal-view-btns">
          <button class="on" id="cal-btn-mois" onclick="calView('mois',this)">Mois</button>
          <button id="cal-btn-liste" onclick="calView('liste',this)">Liste</button>
        </div>
        <div class="cal-filters">
          <label class="cal-flbl"><input type="checkbox" checked data-cat="elearning" onchange="calDraw()">E-learning</label>
          <label class="cal-flbl"><input type="checkbox" checked data-cat="visio" onchange="calDraw()">Classe virtuelle</label>
          <label class="cal-flbl"><input type="checkbox" checked data-cat="presentiel" onchange="calDraw()">Présentiel</label>
          <label class="cal-flbl"><input type="checkbox" checked data-cat="stage" onchange="calDraw()">Stage entreprise</label>
          <label class="cal-flbl"><input type="checkbox" checked data-cat="examen" onchange="calDraw()">Examen</label>
        </div>
      </div>
      <div class="cal-legend">
        <span class="cal-legend-item"><span class="cal-legend-dot" style="background:#ECEAFA;border:1.5px solid #7C6FDD"></span>E-learning</span>
        <span class="cal-legend-item"><span class="cal-legend-dot" style="background:#E3F2EF;border:1.5px solid #2E9488"></span>Classe virtuelle</span>
        <span class="cal-legend-item"><span class="cal-legend-dot" style="background:#FEF3E7;border:1.5px solid #F2A03D"></span>Présentiel</span>
        <span class="cal-legend-item"><span class="cal-legend-dot" style="background:#F0EDE4;border:1.5px solid #9A8A72"></span>Stage entreprise</span>
        <span class="cal-legend-item"><span class="cal-legend-dot" style="background:#2A2A2A"></span>Examen</span>
      </div>
    </div>
    <div class="cal-grid-wrap" id="cal-body"></div>
  </div>
</div>

<!-- VUE DOCS / DOCUMENTS -->
<div class="view" id="v-docs">
  <p class="eyebrow">Administratif</p>
  <h1>Documents</h1>
  <p class="sub">Pièces signées et à signer. Un document bloquant est signalé en rouge.</p>

  <div id="docs-banner" style="display:none;background:var(--red-t);border:1.5px solid var(--red);border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;font-weight:600;color:var(--red)">
    ⚠ <span id="docs-banner-msg"></span>
  </div>

  <div class="panel" style="padding:0;overflow:hidden">
    <div id="docs-body"><p style="color:var(--muted);font-size:13px;padding:16px">Chargement…</p></div>
  </div>
  <input type="file" id="doc-signed-file-input" style="display:none" onchange="docsUploadSignedFile(this)">
</div>

<!-- VUE REGLAGES -->
<div class="view" id="v-params">
  <p class="eyebrow">Mon compte</p>
  <h1>Réglages</h1>
  <p class="sub">Profil et informations de parcours.</p>
  <div class="params-grid">
    <div class="set-card">
      <div class="sc-head"><div class="ico ico-teal">◉</div><div class="text"><h3>Mon profil</h3><p>Donnees Moodle — lecture seule</p></div></div>
      <div class="param-row"><span class="lbl">Prenom</span><span class="val"><?= s($USER->firstname) ?></span></div>
      <div class="param-row"><span class="lbl">Nom</span><span class="val"><?= s($USER->lastname) ?></span></div>
      <div class="param-row"><span class="lbl">Email</span><span class="val" style="font-size:12px"><?= s($USER->email) ?></span></div>
    </div>
    <div class="set-card">
      <div class="sc-head"><div class="ico ico-orange">⚑</div><div class="text"><h3>Ma classe</h3><p>Cohorte et sous-groupe</p></div></div>
      <div id="params-classe">—</div>
    </div>
    <div class="set-card">
      <div class="sc-head"><div class="ico ico-violet">◧</div><div class="text"><h3>Mon parcours</h3><p>Statistiques de formation</p></div></div>
      <div id="params-stats">—</div>
    </div>
  </div>
</div>

</main>
</div>

<div class="toast" id="toast"></div>

<div class="util-modal-bg" id="util-modal-bg" onclick="if(event.target===this)closeUtilModal()">
  <div class="util-modal">
    <div class="util-modal-head">
      <button type="button" id="util-modal-back" onclick="utilModalGoBack()" aria-label="Retour" title="Retour" style="display:none;background:none;border:none;font-size:14px;font-weight:700;color:var(--teal-d);cursor:pointer;padding:4px 8px 4px 0;margin-right:4px">&#8592; Retour</button>
      <h2 id="util-modal-title">Messages</h2>
      <button type="button" class="util-modal-close" onclick="closeUtilModal()" aria-label="Fermer">&#10005;</button>
    </div>
    <div class="util-modal-body">
      <div class="dv-loading" id="util-modal-loading"><span class="dv-spinner"></span></div>
      <iframe id="util-modal-iframe" title="Messagerie" src="about:blank"></iframe>
    </div>
  </div>
</div>

<div class="util-modal-bg" id="msg-modal-bg" onclick="if(event.target===this)closeMessageModal()">
  <div class="msg-modal">
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
      </div>
      <div class="msg-thread-body" id="msg-thread-body"></div>
      <div class="msg-thread-empty" id="msg-thread-empty">Sélectionnez une conversation</div>
      <div class="msg-thread-input" id="msg-thread-input" style="display:none">
        <button type="button" class="msg-attach" title="Joindre">&#128206;</button>
        <input type="text" id="msg-input-text" placeholder="Écrire un message..." onkeydown="if(event.key==='Enter'){msgSendCurrent();}">
        <button type="button" class="msg-send-btn" onclick="msgSendCurrent()" aria-label="Envoyer">&#10148;</button>
      </div>
    </section>
  </div>
</div>

<script>
var DATA    = <?= $eleve_data ?>;
var WWWROOT = '<?= $CFG->wwwroot ?>';
var GLOWBL  = '<?= s($glowbl_url) ?>';

// Lance la vraie activité LTI Glowbl du cours (authentification automatique),
// avec repli sur le lien statique si aucune correspondance n'est trouvée.
function glowblJoinUrl(courseid) {
  var cmid = courseid && DATA.glowbl_lti_map ? DATA.glowbl_lti_map[courseid] : null;
  return cmid ? (WWWROOT + '/mod/lti/view.php?id=' + cmid) : GLOWBL;
}
var SESSKEY = '<?= $sesskey ?>';
var ELEVEURL = '<?= $CFG->wwwroot ?>/espace-eleve.php';

function apiPost(params) {
  var body = new URLSearchParams(params);
  body.append('sesskey', SESSKEY);
  return fetch(ELEVEURL, { method: 'POST', body: body }).then(function(r) { return r.json(); });
}

var _rendered = {};

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ===== COMPTEURS ICÔNES (messages / notifications) ===== */
function loadHeaderCounts() {
  apiPost({ action: 'header_counts' }).then(function(r) {
    if (!r.ok) return;
    var msgBadge = document.getElementById('msg-badge');
    msgBadge.textContent = r.unread_messages;
    msgBadge.style.display = r.unread_messages > 0 ? 'flex' : 'none';
    var notifBadge = document.getElementById('notif-badge');
    notifBadge.textContent = r.unread_notifications;
    notifBadge.style.display = r.unread_notifications > 0 ? 'flex' : 'none';
  });
}

/* ===== NOTIFICATIONS (modal iframe, reste sur espace-eleve.php) ===== */
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
      // Cette popup (notifications natives Moodle) reste sur fond blanc quel
      // que soit le client (meme convention que .dv-left), mais son accent
      // (bordure non-lue, liens, hover) doit suivre teal (LBA) / orange (Saran).
      $accent   = $brand[$brand['button_accent']];
      $accent_d = $brand[$brand['button_accent'] . '_d'];
      $accent_t = $brand[$brand['button_accent'] . '_t'];
    ?>
    style.textContent = [
      '#page-header, #main-header, .navbar, #page-footer, .main-footer, footer,',
      '.breadcrumb-nav, .breadcrumb, #nav-drawer, .drawer-toggle, .skiplinks,',
      // Bandeau du "mode plein ecran" de theme_mb2nl (utilise par les devoirs
      // notamment, avec son anneau de progression) : cette modale de
      // notifications n'a pas vocation a naviguer jusque-la, mais un lien de
      // notification peut y mener (ex. "votre travail") — meme selecteur
      // deja verifie et utilise cote lecteur "Mes modules" (_dvIframeCSS).
      '#fsmod-header, .secondary-navigation, .tertiary-navigation, .activity-navigation { display:none !important; }',
      'html, body { background:#fff !important; }',
      '#page, #page.container-fluid, #region-main, #region-main-box { padding:0 !important; margin:0 !important; max-width:none !important; }',
      '.btn-primary, .bg-primary { background-color:<?= $accent ?> !important; border-color:<?= $accent ?> !important; }',
      '.btn-primary:hover { background-color:<?= $accent_d ?> !important; border-color:<?= $accent_d ?> !important; }',
      '.btn-secondary, .btn.btn-secondary { background:#fff !important; border-color:<?= $accent ?> !important; color:<?= $accent ?> !important; }',
      '.text-primary { color:<?= $accent ?> !important; }',
      '.badge-primary, .badge.bg-primary { background-color:<?= $brand['orange'] ?> !important; }',
      '.list-group-item.active, .list-group-item:focus, .list-group-item:hover { background-color:<?= $accent_t ?> !important; color:<?= $accent ?> !important; }',
      /* Habillage visuel de la liste de notifications, dans le style de la messagerie custom
         (aucune règle de display/position/overflow touchée — la logique liste/détail de Moodle reste intacte) */
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
      // Liens dans le corps du message d'une notification ouverte (fil
      // d'ariane cours/devoir + "votre travail") : couleur d'accent doree
      // par defaut de Moodle, remplacee par teal/orange selon le client
      // (attribut stable, voir message/output/popup/templates/notification_area.mustache).
      '.notification-area .content-area [data-region="content"] a { color:<?= $accent ?> !important; }',
      '.notification-area .content-area [data-region="content"] a:hover { color:<?= $accent_d ?> !important; }',
      /* Scrollbar de la fenetre aux couleurs de la marque (teal LBA / orange Saran) plutot que l'accent dore par defaut. */
      'html { scrollbar-color: <?= $accent ?> #f0f0f0 !important; }',
      '::-webkit-scrollbar { width: 10px; }',
      '::-webkit-scrollbar-track { background: #f0f0f0; }',
      '::-webkit-scrollbar-thumb { background: <?= $accent ?> !important; border-radius: 6px; }',
      '::-webkit-scrollbar-thumb:hover { background: <?= $accent_d ?> !important; }',
    ].join('\n');
    doc.head.appendChild(style);
    // Evite le flash de la page Moodle non stylee quand on navigue via un
    // lien clique DANS l'iframe (ex. "votre travail" depuis une
    // notification) : on la masque dès que cette page commence a se
    // quitter, jusqu'a ce que la nouvelle soit stylee (meme principe que
    // ceInjectCSS cote "Mes modules").
    try {
      doc.defaultView.addEventListener('pagehide', function() {
        iframe.classList.remove('ready');
      });
    } catch (e2) {}
    utilHideAssignExtras(doc);
    utilFixNotifBreadcrumb(doc);
    return true;
  } catch (e) { return false; }
}

// Vue d'un devoir ouverte depuis une notification ("votre travail") : retire
// la boite de description/intro (attribut stable, voir
// mod/assign/classes/output/renderer.php::render_assign_header, qui
// enveloppe l'intro dans box_start('generalbox boxaligncenter')), les
// boutons d'action (pas de classe propre, on matche le texte) et la ligne
// "Texte en ligne" du tableau de statut de remise — hors sujet dans ce
// contexte, le contenu de la notification suffit.
function utilHideAssignExtras(doc) {
  if (doc._l3mAssignExtrasObserver) return;
  var HIDE_BTN_TEXT = ['Modifier le travail', 'Supprimer travail remis'];
  var HIDE_ROW_LABELS = ['Texte en ligne'];
  var hide = function() {
    doc.querySelectorAll('.box.generalbox.boxaligncenter').forEach(function(el) {
      el.style.display = 'none';
    });
    doc.querySelectorAll('a.btn, button.btn').forEach(function(el) {
      if (HIDE_BTN_TEXT.indexOf(el.textContent.trim()) !== -1) el.style.display = 'none';
    });
    doc.querySelectorAll('table tr').forEach(function(tr) {
      var cell = tr.querySelector('th, td');
      if (cell && HIDE_ROW_LABELS.indexOf(cell.textContent.trim()) !== -1) {
        tr.style.display = 'none';
      }
    });
  };
  hide();
  try {
    var obs = new MutationObserver(hide);
    obs.observe(doc.body, {childList: true, subtree: true});
    doc._l3mAssignExtrasObserver = obs;
  } catch (e) {}
}

// Fil d'ariane d'une notification (ex. "ECSR-CCP1-M1 -> Devoir -> test 1") :
// ne garder cliquable que le dernier element (l'activite elle-meme), les
// precedents (cours, categorie) sont convertis en texte simple. Le contenu
// venant de fullmessagehtml (voir message/output/popup/templates/
// notification_area_content_area_content.mustache), il change a chaque clic
// sur une notification differente — on retraite donc chaque nouveau bloc,
// marque une fois fait pour ne pas le refaire en boucle via l'observateur.
function utilFixNotifBreadcrumb(doc) {
  if (doc._l3mNotifBcObserver) return;
  var fix = function() {
    // Uniquement <p> : le fil d'ariane est un <p> distinct du <div> du
    // message (voir le fullmessagehtml stocke en base). Scanner aussi les
    // <div> attraperait le conteneur englobant les DEUX blocs (fil d'ariane
    // + message, ex. "votre travail"), qui a alors 4 liens au total et
    // casse "test 1" en croyant qu'il fait partie du fil a raccourcir.
    doc.querySelectorAll('p').forEach(function(container) {
      if (container.dataset.l3mBcDone) return;
      var links = container.querySelectorAll('a');
      if (links.length < 2 || container.textContent.indexOf('->') === -1) return;
      for (var i = 0; i < links.length - 1; i++) {
        var a = links[i];
        var span = doc.createElement('span');
        span.textContent = a.textContent;
        a.replaceWith(span);
      }
      container.dataset.l3mBcDone = '1';
    });
  };
  fix();
  try {
    var obs = new MutationObserver(fix);
    obs.observe(doc.body, {childList: true, subtree: true});
    doc._l3mNotifBcObserver = obs;
  } catch (e) {}
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
  // Compteur de navigations dans cette iframe : le tout premier chargement
  // (la page de notifications elle-meme) ne doit pas afficher "Retour" —
  // seulement les navigations suivantes (ex. clic sur "votre travail"),
  // pour lesquelles il n'existe sinon aucun moyen de revenir sans fermer
  // toute la fenetre.
  _utilModalNavCount = 0;
  document.getElementById('util-modal-back').style.display = 'none';

  var attempts = 0;
  _utilPollT = setInterval(function() {
    attempts++;
    var onRealPage = false;
    try { onRealPage = iframe.contentWindow.location.href.indexOf(cfg.match) !== -1; } catch (e) {}
    // L'injection est toujours tentee (utilModalInject est sans effet si deja
    // fait sur ce document) : sinon, un lien de notification menant hors de
    // cfg.match (ex. "votre travail" -> mod/assign/view.php) reste stylé avec
    // le theme Moodle par defaut, faute de reinjection. Seul le repli du
    // spinner de chargement reste conditionne a onRealPage.
    var injected = utilModalInject();
    if ((onRealPage && injected) || attempts > 250) {
      clearInterval(_utilPollT);
      iframe.classList.add('ready');
      loading.style.display = 'none';
    }
  }, 20);
  iframe.onload = function() {
    // Pas de retour anticipe hors de cfg.match ici : contrairement au
    // sondage ci-dessus (qui doit ignorer les redirections intermediaires),
    // onload ne se declenche que pour une page entierement chargee — donc
    // toujours reafficher une fois stylee, meme sur une navigation interne
    // ulterieure (ex. "votre travail" -> mod/assign/view.php), sinon
    // l'iframe reste invisible indefiniment (masquee par le pagehide de la
    // page precedente, voir utilModalInject).
    utilModalInject();
    iframe.classList.add('ready');
    loading.style.display = 'none';
    // Compte la profondeur reelle de navigation : un onload declenche par
    // utilModalGoBack() (via history.back()) doit decrementer, pas
    // incrementer comme une navigation en avant — sinon le bouton reste
    // affiche indefiniment et un clic supplementaire viderait l'iframe
    // (retour au-dela de la premiere page, vers about:blank).
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
  if (_utilModalNavCount <= 1) return; // deja a la racine (notifications) : rien a faire
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
var _msg = { list: [], filter: '', activeConvId: 0, activeUserId: 0 };
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

function openMessageModal() {
  document.getElementById('msg-modal-bg').classList.add('on');
  var modal = document.querySelector('.msg-modal');
  if (modal) modal.classList.remove('msg-mob-thread'); // repartir sur la liste des contacts en mobile
  msgLoadList();
}
function closeMessageModal() {
  document.getElementById('msg-modal-bg').classList.remove('on');
  loadHeaderCounts();
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
    document.getElementById('msg-list-count').textContent = nb
      ? (nb + ' conversation' + (nb>1?'s':'') + (unread ? ' · ' + unread + ' non lue' + (unread>1?'s':'') : ''))
      : 'Aucune conversation';
    msgRenderContacts();

    if (!_msg.activeUserId && _msg.list.length) {
      var first = _msg.list[0];
      msgSelectContact(first.convid, first.userid, first.name, first.isonline);
    }
  });
}

function msgFilterList() {
  _msg.filter = document.getElementById('msg-search-input').value.trim().toLowerCase();
  msgRenderContacts();
}

function msgContactRow(c) {
  var active = (c.userid === _msg.activeUserId) ? ' on' : '';
  var unread = c.unreadcount > 0 ? ' unread' : '';
  var preview = c.lastmsg ? esc(c.lastmsg) : 'Aucun message pour l\'instant';
  return (
    '<div class="msg-contact' + active + unread + '" data-uid="' + c.userid + '" ' +
      'onclick="msgSelectContact(' + c.convid + ',' + c.userid + ',\'' + esc(c.name).replace(/'/g,"\\'") + '\',' + (c.isonline?'true':'false') + ')">' +
      '<span class="msg-contact-avatar ' + msgAvatarClass(c.userid) + '">' + esc(c.initials) +
        '<span class="dot' + (c.isonline?' online':'') + '"></span>' +
      '</span>' +
      '<div class="msg-contact-info">' +
        '<div class="msg-contact-row1">' +
          '<span class="msg-contact-name">' + esc(c.name) + '</span>' +
          '<span class="msg-contact-time">' + msgTimeShort(c.lasttime) + '</span>' +
        '</div>' +
        '<div class="msg-contact-preview">' + preview + '</div>' +
      '</div>' +
      (c.unreadcount > 0 ? '<span class="msg-contact-unread-badge">' + c.unreadcount + '</span>' : '') +
      '<button type="button" class="msg-contact-fav-star' + (c.isfavourite?' on':'') + '" onclick="event.stopPropagation();msgToggleFav(' + c.convid + ')" title="Favori">&#9733;</button>' +
    '</div>'
  );
}

function msgRenderContacts() {
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
    html += '<div class="msg-group-label">' + (favs.length ? 'Autres' : 'Contacts') + '</div>' + others.map(msgContactRow).join('');
  }
  if (!favs.length && !others.length) {
    html = '<div class="msg-thread-empty" style="padding:30px 10px">Aucun message pour l\'instant</div>';
  }
  box.innerHTML = html;
}

function msgSelectContact(convid, userid, nom, isonline) {
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
    '<span class="dot' + (isonline?' online':'') + '"></span>' + (isonline ? 'En ligne' : 'Hors ligne');

  var body = document.getElementById('msg-thread-body');
  body.innerHTML = '<div class="msg-thread-empty">Chargement…</div>';

  apiPost({ action: 'msg_thread', otherid: userid, convid: convid || 0 }).then(function(r) {
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
  apiPost({ action: 'msg_send', otherid: _msg.activeUserId, text: text }).then(function(r) {
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

function msgToggleFav(convid) {
  apiPost({ action: 'msg_toggle_fav', convid: convid }).then(function(r) {
    if (!r.ok) return;
    var entry = _msg.list.find(function(c){ return c.convid === convid; });
    if (entry) entry.isfavourite = r.isfavourite;
    msgRenderContacts();
  });
}

var _toastT;
function toast(msg) {
  var t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('on');
  clearTimeout(_toastT);
  _toastT = setTimeout(function(){ t.classList.remove('on'); }, 2800);
}

function doLogout(ev) {
  ev.preventDefault();
  var logoutUrl = WWWROOT + '/login/logout.php?sesskey=' + SESSKEY;
  var target    = WWWROOT + '/login/index.php?loginredirect=1&client=<?= $user_client ?>';
  fetch(logoutUrl, { credentials: 'same-origin' }).finally(function() {
    window.location.href = target;
  });
  return false;
}

function toggleNavUserMenu(ev) {
  ev.stopPropagation();
  document.getElementById('nav-user-menu').classList.toggle('open');
}
document.addEventListener('click', function(ev) {
  var menu = document.getElementById('nav-user-menu');
  if (menu && menu.classList.contains('open') && !menu.contains(ev.target)) {
    menu.classList.remove('open');
  }
});

function nav(btn) {
  document.querySelectorAll('aside button').forEach(function(b){ b.classList.remove('on'); });
  document.querySelectorAll('.view').forEach(function(v){ v.classList.remove('on'); });
  btn.classList.add('on');
  var v = btn.dataset.v;
  document.getElementById('v-' + v).classList.add('on');
  go(v);
}
function go(v) {
  if (_rendered[v]) return;
  _rendered[v] = true;
  if      (v === 'accueil')  renderAccueil();
  else if (v === 'parcours') renderParcours();
  else if (v === 'cours')    renderCours();
  else if (v === 'detail')   renderDetail();
  else if (v === 'agenda')   renderAgenda();
  else if (v === 'docs')     renderDocs();
  else if (v === 'params')   renderParams();
}

/* ── MOTEUR LIA ── */
var LIA_BLOCS = [
  {n:'Politique de la sécurité routière',    h:65},
  {n:'Cadre de l\'activite ECSR',            h:60},
  {n:'L\'automobile',                        h:60},
  {n:'Analyse de la tache de conduite',      h:30},
  {n:'Physiologie du conducteur',            h:30},
  {n:'Psychologie du conducteur',            h:10},
  {n:'Notions pédagogiques',                 h:40},
  {n:'Ingénierie et construction de séance', h:65},
  {n:'Techniques d\'animation',              h:35},
  {n:'Gestion des conflits',                 h:10},
  {n:'Gestion du stress',                    h:20},
  {n:'Anticipation des risques',             h:25},
  {n:'Evaluation et docimologie',            h:30},
  {n:'Difficultes et remediation',           h:20},
  {n:'Analyse de la demande (CCP2)',         h:110},
  {n:'Construction d\'une action',           h:60},
  {n:'Animation d\'une sensibilisation',     h:35},
  {n:'Evaluation des pratiques',             h:30}
];
/* Saran a 2 autres formations (durée et contenu différents de l'ECSR) —
   LIA_BLOCS_GESTIONNAIRE / LIA_BLOCS_SUPPORT sont l'équivalent de LIA_BLOCS
   ci-dessus, pour ces 2 formations. */
var LIA_BLOCS_GESTIONNAIRE = [
  {n:'Réglementation et agrément préfectoral',       h:30},
  {n:'Obligations de l\'exploitant d\'auto-école',   h:25},
  {n:'Comptabilité analytique et rentabilité',       h:30},
  {n:'Gestion financière de l\'auto-école',          h:25},
  {n:'Recrutement des enseignants',                  h:20},
  {n:'Management et planning des équipes',           h:30},
  {n:'Acquisition clients et stratégie commerciale', h:30},
  {n:'Suivi CPF et financements des élèves',         h:34}
];
var LIA_BLOCS_SUPPORT = [
  {n:'Accueil, inscription et accompagnement',  h:22},
  {n:'Relation client et fidélisation',         h:20},
  {n:'Gestion des plannings moniteurs',         h:22},
  {n:'Gestion des passages d\'examen',           h:18},
  {n:'Suivi des financements CPF',               h:22},
  {n:'Permis à 1€ et aides France Travail',      h:12},
  {n:'Outils de gestion au quotidien',           h:12}
];

/* FORMATIONS : config par formation (durée, type de semaine, contenu).
   ACTIVE_FORMATION est choisie une fois, selon DATA.formation (calculé
   côté PHP depuis le cours réel de l'élève) — 'ecsr' par défaut, ce qui
   couvre LBA (toujours ecsr) et préserve le comportement existant. */
var FORMATIONS = {
  ecsr: {
    totalWeeks: 35,
    weekType: function(rel) {
      if (rel === 35) return 'e';
      if (rel <= 32 && rel % 4 === 0) return 's';
      if (rel === 7 || rel === 13 || rel === 19) return 'r';
      if (rel === 25 || rel === 31) return 'rs';
      if (rel === 26) return 'e1';
      return 'c';
    },
    blocsH: LIA_BLOCS,
    subtitle: '35 semaines \xb7 Titre professionnel ECSR RNCP 35329'
  },
  gestionnaire: {
    totalWeeks: 17,
    weekType: function(rel) { return rel === 17 ? 'r' : 'c'; },
    blocsH: LIA_BLOCS_GESTIONNAIRE,
    subtitle: '17 semaines \xb7 Formation Gestionnaire d’auto-\xe9cole'
  },
  support: {
    totalWeeks: 13,
    weekType: function(rel) { return rel === 13 ? 'r' : 'c'; },
    blocsH: LIA_BLOCS_SUPPORT,
    subtitle: '13 semaines \xb7 Formation Support commercial & p\xe9dagogique'
  }
};
var ACTIVE_FORMATION = FORMATIONS[DATA.formation] || FORMATIONS.ecsr;

var LIA_DIST_WEEKS = [];
function liaWType(rel) { return ACTIVE_FORMATION.weekType(rel); }
(function(){ for (var i = 1; i < ACTIVE_FORMATION.totalWeeks; i++) { var t = liaWType(i); if (t === 'c' || t === 'e1') LIA_DIST_WEEKS.push(i); } })();

var LIA_SESSION_H = 2;
var LIA_WLABELS = {c:'Cours a distance', s:'Semaine entreprise', r:'Regroupement salle', rs:'Regroupement + stage', e1:'Cours + prepa examen', e:'Semaine d\'examen'};

function liaParseDate(s) {
  if (!s) return null;
  var d = s.length === 7 ? new Date(s + '-01') : new Date(s);
  return isNaN(d.getTime()) ? null : d;
}

function liaGetWeekBlocs(rel) {
  var wtype = liaWType(rel);
  if (wtype !== 'c' && wtype !== 'e1') return {wtype: wtype, blocs: []};
  var idx = LIA_DIST_WEEKS.indexOf(rel);
  if (idx < 0) return {wtype: wtype, blocs: []};
  var blocsH  = ACTIVE_FORMATION.blocsH;
  var totalH  = blocsH.reduce(function(s, b) { return s + b.h; }, 0);
  var perWeek = totalH / LIA_DIST_WEEKS.length;
  var rS = idx * perWeek, rE = (idx + 1) * perWeek, blocs = [], cum = 0;
  blocsH.forEach(function(b) {
    var bE = cum + b.h;
    if (bE > rS && cum < rE) blocs.push({n: b.n, h: Math.round(Math.min(bE, rE) - Math.max(cum, rS))});
    cum += b.h;
  });
  return {wtype: wtype, blocs: blocs};
}

function liaWeekDates(startdate, rel) {
  var sd = liaParseDate(startdate);
  if (!sd) return '';
  var ms   = sd.getTime() + (rel - 1) * 7 * 24 * 3600 * 1000;
  var from = new Date(ms);
  var to   = new Date(ms + 4 * 24 * 3600 * 1000);
  var mo   = ['jan','fev','mars','avr','mai','juin','juil','aout','sep','oct','nov','dec'];
  function fmt(d) { return d.getDate() + ' ' + mo[d.getMonth()]; }
  return fmt(from) + (from.getMonth() === to.getMonth() ? ' -> ' + to.getDate() : ' -> ' + fmt(to));
}

/* -- ACCUEIL -- */
function navTo(v) {
  var btn = document.querySelector('[data-v=' + v + ']');
  if (btn) nav(btn);
}

function renderAccueil() {
  var d   = DATA;
  var pct = d.global_pct || 0;
  var cur = d.current_week || 1;
  var coh = d.cohort;

  // Sidebar chip
  var sclEl = document.getElementById('sidebar-cls');
  if (coh) sclEl.textContent = (coh.name.match(/Classe\s+([A-Z])/i) || ['',''])[1].toUpperCase() + '\xb7' + coh.subgroup_label;

  // Hero badge
  var winfo = liaGetWeekBlocs(cur);
  var wdates = coh ? liaWeekDates(coh.startdate, cur) : '';
  document.getElementById('hero-badge').textContent = 'SEMAINE ' + cur + '/' + ACTIVE_FORMATION.totalWeeks + ' \xb7 ' + (LIA_WLABELS[winfo.wtype] || '').toUpperCase();

  // Greeting
  var hr = new Date().getHours();
  var greet = hr < 12 ? 'Bonjour' : hr < 18 ? 'Bon après-midi' : 'Bonsoir';
  document.getElementById('acc-greeting').textContent = greet + ', ' + d.student.firstname + ' !';

  // Description
  var mood = pct >= 70 ? "en excellente progression sur l'e-learning" : pct >= 40 ? "a bonne allure sur l'e-learning" : pct >= 15 ? "en progression sur l'e-learning" : "au debut de l'e-learning";
  var descEl = document.getElementById('hero-desc');
  var lc = d.last_course || (d.courses.length ? d.courses[0] : null);
  if (lc && !lc.pct && d.courses.length) {
    var lcFull = d.courses.find(function(c){ return c.id === lc.id; });
    if (lcFull) lc = lcFull;
  }
  // Cible unique de reprise : toujours le dernier cours reellement consulte
  // (memes regles pour la banniere et "Mes actions"), sans filtrer par progression.
  var resumeCourse = lc;
  if (resumeCourse) {
    descEl.innerHTML = 'Vous êtes <b>' + mood + '</b>. Reprenez là où vous en étiez : <b>' + esc(resumeCourse.fullname) + '</b>.';
  } else {
    descEl.textContent = 'Bienvenue dans votre espace de formation. Acces rapide a vos modules et planning.';
  }

  // Resume button → ouvre le detail du cours dans espace-eleve
  var resumeBtn = document.getElementById('hero-resume-btn');
  if (resumeCourse) {
    resumeBtn.removeAttribute('href');
    resumeBtn.removeAttribute('target');
    resumeBtn.removeAttribute('rel');
    resumeBtn.style.cursor = 'pointer';
    resumeBtn.onclick = function(e) {
      e.preventDefault();
      openDetail(resumeCourse.id, resumeCourse.fullname, resumeCourse.pct || 0);
    };
  } else {
    resumeBtn.style.display = 'none';
  }

  // Ring
  var circ = 2 * Math.PI * 62;
  var ringColor = pct >= 70 ? '#2E9488' : pct >= 40 ? '#F2A03D' : '#7C6FDD';
  var lbl = pct >= 70 ? 'Excellent !' : pct >= 40 ? 'A bonne allure' : pct >= 15 ? 'En progression' : 'Debutant';
  var ringEl = document.getElementById('ring-fg');
  ringEl.setAttribute('stroke-dasharray', circ.toFixed(1));
  setTimeout(function() {
    ringEl.setAttribute('stroke-dashoffset', (circ * (1 - pct / 100)).toFixed(1));
    ringEl.setAttribute('stroke', ringColor);
  }, 50);
  document.getElementById('ring-pct').textContent = pct + '%';
  document.getElementById('ring-lbl').textContent = lbl;
  document.getElementById('ring-sub').textContent = pct + " % de l'e-learning complété";

  // Alerte devoir en retard
  var alertEl = document.getElementById('acc-alert');
  var now_ts = Math.floor(Date.now() / 1000);
  var overdues = (d.pending_assigns || []).filter(function(a) { return a.duedate > 0 && a.duedate < now_ts; });
  if (overdues.length) {
    var ov = overdues[0];
    document.getElementById('ab-title').textContent = 'Devoir en retard : ' + ov.name;
    document.getElementById('ab-desc').textContent = 'Date limite depassee le ' + ov.duedate_label;
    document.getElementById('ab-cta').onclick = function(ev) {
      ev.preventDefault();
      openActionAssign(ov.course, ov.course_full || ov.course_short, ov.id);
    };
    alertEl.style.display = '';
  }

  // Ma semaine
  var today = new Date();
  var dow = today.getDay();
  var mondayOff = dow === 0 ? -6 : 1 - dow;
  var monday = new Date(today.getFullYear(), today.getMonth(), today.getDate() + mondayOff);
  var sunday = new Date(monday.getTime() + 6 * 24 * 3600 * 1000);
  var moNames = ['jan','fev','mars','avr','mai','juin','juil','aout','sep','oct','nov','dec'];
  var dayNames = ['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'];
  function fmtD(dd) { return dd.getDate() + ' ' + moNames[dd.getMonth()]; }
  document.getElementById('week-dates-lbl').textContent = (fmtD(monday) + ' -> ' + fmtD(sunday)).toUpperCase();

  var mondayTs = Math.floor(monday.getTime() / 1000);
  var sundayTs  = Math.floor(sunday.getTime() / 1000) + 86400;

  var rows = [];
  // Priorité : events Moodle réels (admin a planifié les cours avec heures)
  var weekMoodleEvents = (d.events || []).filter(function(e) {
    return e.timestart >= mondayTs && e.timestart <= sundayTs;
  });
  if (weekMoodleEvents.length) {
    weekMoodleEvents.forEach(function(e) {
      rows.push({
        dayLabel: e.day_label || dayNames[new Date(e.timestart * 1000).getDay()],
        dateNum:  e.date_num  || new Date(e.timestart * 1000).getDate(),
        hourLabel: e.hour_label || '',
        name: e.name + (e.course_short ? ' · ' + e.course_short : ''),
        typeClass: 't-visio', typeLbl: 'visio',
        ts: e.timestart, glowbl: true, assignId: null, courseid: e.courseid
      });
    });
  } else {
    // Repli LIA : blocs ECSR sans heure précise
    if (winfo.blocs.length) {
      var weekDays = [1,2,3,4,5];
      winfo.blocs.slice(0, 5).forEach(function(b, i) {
        var dayIdx = weekDays[i % weekDays.length];
        var dayDate = new Date(monday.getTime() + (dayIdx - 1) * 24 * 3600 * 1000);
        rows.push({dayLabel: dayNames[dayDate.getDay()], dateNum: dayDate.getDate(),
                   hourLabel: '', name: b.n, typeClass: 't-visio', typeLbl: 'visio',
                   ts: dayDate.getTime() / 1000, glowbl: true, assignId: null});
      });
    } else {
      rows.push({dayLabel: '', dateNum: 0, hourLabel: '',
                 name: LIA_WLABELS[winfo.wtype] || 'Semaine speciale',
                 typeClass: 't-el', typeLbl: 'hors-presentiel',
                 ts: mondayTs, glowbl: false, assignId: null});
    }
  }
  // Devoirs à rendre cette semaine
  (d.pending_assigns || []).forEach(function(a) {
    if (a.duedate >= mondayTs && a.duedate <= sundayTs) {
      var dDate = new Date(a.duedate * 1000);
      rows.push({dayLabel: dayNames[dDate.getDay()], dateNum: dDate.getDate(),
                 hourLabel: '', name: 'Devoir : ' + a.name,
                 typeClass: 't-due', typeLbl: 'a rendre',
                 ts: a.duedate, glowbl: false, assignId: a.id,
                 assignCourseId: a.course, assignCourseName: a.course_full || a.course_short});
    }
  });
  rows.sort(function(a, b) { return a.ts - b.ts; });

  var semEl = document.getElementById('acc-semaine');
  if (!rows.length) {
    semEl.innerHTML = '<p style="color:var(--muted);font-size:13px;margin:0">Aucun element cette semaine.</p>';
  } else {
    semEl.innerHTML = rows.map(function(r) {
      var dayHtml = r.dayLabel
        ? '<div class="sr-day">' + r.dayLabel + '<br><span style="font-size:12px">' + r.dateNum + '</span>' +
          (r.hourLabel ? '<br><span style="font-size:10px;font-weight:800;color:var(--teal-d)">' + r.hourLabel + '</span>' : '') + '</div>'
        : '<div class="sr-day" style="background:transparent;color:var(--muted)">—</div>';
      var btn = r.glowbl
        ? '<a class="btn mini orange-btn" href="' + esc(glowblJoinUrl(r.courseid)) + '" target="_blank" rel="noopener">Rejoindre</a>'
        : (r.assignId ? '<button type="button" class="btn mini primary" onclick="openActionAssign(' + r.assignCourseId + ',\'' +
            (r.assignCourseName||'').replace(/\\/g,'\\\\').replace(/'/g,'\\\'') + '\',' + r.assignId + ')">Rendre</button>' : '');
      return '<div class="semaine-row">' +
        dayHtml +
        '<span class="sr-name">' + esc(r.name) + '</span>' +
        '<span class="sr-type ' + r.typeClass + '">' + r.typeLbl + '</span>' +
        btn +
      '</div>';
    }).join('');
  }

  // Mes actions
  var actEl = document.getElementById('acc-actions');
  var actions = [];
  var nextCourse = resumeCourse;
  if (nextCourse) {
    actions.push({ico: '\u25B6', icoStyle: 'background:var(--teal-t);color:var(--teal-d)',
      title: nextCourse.fullname, sub: nextCourse.pct + '% complété \xb7 e-learning',
      courseId: nextCourse.id, coursePct: nextCourse.pct || 0});
  }
  if (d.pending_assigns && d.pending_assigns.length) {
    var pa = d.pending_assigns[0];
    actions.push({ico: '\u270F', icoStyle: 'background:var(--orange-t);color:var(--orange-d)',
      title: pa.name,
      sub: (pa.duedate_label ? 'Rendre avant le ' + pa.duedate_label : 'A rendre') + ' \xb7 ' + pa.course_short,
      assignCourseId: pa.course, assignCourseName: pa.course_full || pa.course_short, assignCmid: pa.id});
  }
  actions.push({ico: '\u2709', icoStyle: 'background:var(--violet-t);color:var(--violet-d)',
    title: 'Contacter votre coordinateur', sub: 'Messagerie \xb7 réponse sous 24 h',
    msgOpen: true});
  actEl.innerHTML = actions.map(function(a) {
    if (a.courseId) {
      return '<div class="action-item" style="cursor:pointer" onclick="openDetail(' + a.courseId + ',\'' + (a.title||'').replace(/\\/g,'\\\\').replace(/'/g,'\\\'') + '\',' + a.coursePct + ')">' +
        '<div class="ai-ico" style="' + a.icoStyle + '">' + a.ico + '</div>' +
        '<div class="ai-body"><div class="ai-title">' + esc(a.title) + '</div><div class="ai-sub">' + esc(a.sub) + '</div></div>' +
        '<span class="ai-arr">\u203a</span>' +
      '</div>';
    }
    if (a.msgOpen) {
      return '<div class="action-item" style="cursor:pointer" onclick="openMessageModal()">' +
        '<div class="ai-ico" style="' + a.icoStyle + '">' + a.ico + '</div>' +
        '<div class="ai-body"><div class="ai-title">' + esc(a.title) + '</div><div class="ai-sub">' + esc(a.sub) + '</div></div>' +
        '<span class="ai-arr">›</span>' +
      '</div>';
    }
    var courseNameJs = (a.assignCourseName || '').replace(/\\/g,'\\\\').replace(/'/g,'\\\'');
    return '<div class="action-item" style="cursor:pointer" onclick="openActionAssign(' + a.assignCourseId + ',\'' + courseNameJs + '\',' + a.assignCmid + ')">' +
      '<div class="ai-ico" style="' + a.icoStyle + '">' + a.ico + '</div>' +
      '<div class="ai-body"><div class="ai-title">' + esc(a.title) + '</div><div class="ai-sub">' + esc(a.sub) + '</div></div>' +
      '<span class="ai-arr">\u203a</span>' +
    '</div>';
  }).join('');

  // Mini-cards vehicule + stage — concept propre a la formation ECSR
  // (semaines vehicule / stage entreprise) : sans objet pour Gestionnaire
  // et Support, qui n'ont pas ces notions.
  var miniEl = document.getElementById('acc-mini-grid');
  var miniCards = [];
  if (coh && ACTIVE_FORMATION === FORMATIONS.ecsr) {
    var sd2 = liaParseDate(coh.startdate);
    if (sd2) {
      var relNow = Math.floor((Date.now() - sd2.getTime()) / (7 * 24 * 3600 * 1000)) + 1;
      if (relNow >= 1 && relNow <= ACTIVE_FORMATION.totalWeeks) {
        var vehRels = [7, 13, 19];
        var tgtVeh = null;
        for (var vi = 0; vi < vehRels.length; vi++) {
          if (relNow <= vehRels[vi]) { tgtVeh = vehRels[vi]; break; }
        }
        if (tgtVeh) {
          var vActive = (relNow === tgtVeh);
          var vDates  = liaWeekDates(coh.startdate, tgtVeh);
          var vSes    = tgtVeh === 7 ? 1 : tgtVeh === 13 ? 2 : 3;
          var wUntilV = tgtVeh - relNow;
          var vTitlePfx = vActive ? 'Semaine vehicule' : 'Semaine vehicule (dans ' + wUntilV + ' sem.)';
          miniCards.push('<div class="mc mc-veh">' +
            '<div class="mc-hd"><span class="mc-title">\u{1F697} ' + vTitlePfx + '</span><span class="mc-dates-lbl">' + esc(vDates) + '</span></div>' +
            '<div class="mc-sub1">Sous-groupe ' + esc(coh.subgroup_label) + '</div>' +
            '<div class="mc-sub2">3 élèves max par voiture \xb7 Stage ' + vSes + '/3</div>' +
            (vActive ? '<div style="margin-top:10px"><a class="btn mini ghost" href="' + esc(GLOWBL) + '" target="_blank" rel="noopener">Infos pratiques</a></div>' : '') +
          '</div>');
        }
        var stageList = [4,8,12,16,20,24,25,28,31,32];
        var tgtStage = null;
        for (var si = 0; si < stageList.length; si++) {
          if (relNow <= stageList[si]) { tgtStage = stageList[si]; break; }
        }
        if (tgtStage) {
          var sDates   = liaWeekDates(coh.startdate, tgtStage);
          var sActive  = (relNow === tgtStage);
          var stageNum = stageList.indexOf(tgtStage) + 1;
          var wUntilS  = tgtStage - relNow;
          var sTitlePfx = sActive ? 'Stage \xb7 période ' + stageNum : 'Stage (dans ' + wUntilS + ' sem.)';
          var hasPendingConv = (d.pending_assigns || []).some(function(a2) {
            return a2.name.toLowerCase().indexOf('convention') >= 0;
          });
          miniCards.push('<div class="mc mc-stage">' +
            '<div class="mc-hd"><span class="mc-title">\u{1F4CB} ' + sTitlePfx + '</span><span class="mc-dates-lbl">' + esc(sDates) + '</span></div>' +
            '<div class="mc-sub1">Semaine en entreprise</div>' +
            '<div class="mc-sub2">' + esc(LIA_WLABELS[liaWType(tgtStage)] || '') + '</div>' +
            (hasPendingConv ? '<div class="mc-warn">\u26A0 Convention a signer</div>' : '') +
          '</div>');
        }
      }
    }
  }
  if (miniCards.length) {
    miniEl.style.display = '';
    miniEl.innerHTML = miniCards.join('');
  }

  // Timeline
  if (coh) {
    var tlEl = document.getElementById('acc-tl');
    tlEl.style.display = '';
    var fillPct = Math.max(0, Math.min(100, (cur - 1) / (ACTIVE_FORMATION.totalWeeks - 1) * 100));
    document.getElementById('tl-fill').style.width = fillPct.toFixed(1) + '%';
    // Deux mesures distinctes, à ne pas fusionner sur une seule phrase : S{cur}/{tw}
    // = position réelle dans le calendrier de formation, pct = complétion du
    // contenu e-learning (indépendante du calendrier — un élève peut finir son
    // e-learning très en avance sur son parcours calendaire, ou l'inverse).
    document.getElementById('tl-summary').textContent = 'S' + cur + '/' + ACTIVE_FORMATION.totalWeeks + " \xb7 e-learning : " + pct + '%';

    var sd = liaParseDate(coh.startdate);
    function weekDate(w) { return sd ? new Date(sd.getTime() + (w - 1) * 7 * 24 * 3600 * 1000) : null; }
    function fmtMonthYear(d) {
      if (!d) return '';
      var mo = ['Janv','Fevr','Mars','Avr','Mai','Juin','Juil','Aout','Sept','Oct','Nov','Dec'];
      return mo[d.getMonth()] + ' ' + d.getFullYear();
    }

    var tw = ACTIVE_FORMATION.totalWeeks;
    var milestones = [
      {pct: 0,       sub: 'Entree en formation', date: fmtMonthYear(weekDate(1)),  done: true,    here: false, ico: '✓'},
      {pct: fillPct, sub: 'Vous êtes ici',        date: fmtMonthYear(new Date()),   done: false,   here: true,  ico: ''},
    ];
    if (ACTIVE_FORMATION === FORMATIONS.ecsr) {
      milestones.push({pct: 26 / (tw - 1) * 100, sub: 'Examen CCP1', date: fmtMonthYear(weekDate(26)), done: cur > 26, here: false, ico: '\u{1F6A9}'});
      milestones.push({pct: 100, sub: 'Titre pro ECSR', date: fmtMonthYear(weekDate(tw)), done: false, here: false, ico: '\u{1F393}'});
    } else {
      milestones.push({pct: 100, sub: 'Fin de formation', date: fmtMonthYear(weekDate(tw)), done: false, here: false, ico: '\u{1F393}'});
    }
    var filtered = milestones.filter(function(m) {
      if (m.here && fillPct < 3) return false;
      if (m.here && fillPct > 97) return false;
      return true;
    });
    var dotsHtml = '', botHtml = '';
    filtered.forEach(function(m, i) {
      var cls = 'tl-dot' + (m.here ? ' here' : m.done ? ' done' : '');
      var icoHtml = m.here ? '' : (m.done ? '✓' : m.ico);
      dotsHtml += '<div class="' + cls + '" style="left:' + m.pct.toFixed(1) + '%">' + icoHtml + '</div>';
      // Les libellés aux deux extrémités débordent du panneau s'ils restent
      // centrés (translateX(-50%)) : on les cale contre le bord (gauche/droite)
      // au lieu de les centrer sur le point à 0 % / 100 %.
      var align = (i === 0) ? 'left' : (i === filtered.length - 1) ? 'right' : 'center';
      var posStyle = align === 'left' ? 'left:' + m.pct.toFixed(1) + '%;transform:none;text-align:left'
        : align === 'right' ? 'right:' + (100 - m.pct).toFixed(1) + '%;left:auto;transform:none;text-align:right'
        : 'left:' + m.pct.toFixed(1) + '%;transform:translateX(-50%);text-align:center';
      botHtml  += '<div class="tl-bot-item' + (m.here ? ' here' : '') +
        '" data-pct="' + m.pct + '" style="position:absolute;top:0;' + posStyle + '">' +
        '<b>' + esc(m.date) + '</b>' + esc(m.sub) + '</div>';
    });
    document.getElementById('tl-dots').innerHTML = dotsHtml;
    var tlBot = document.getElementById('tl-bot');
    tlBot.style.cssText = 'position:relative;height:36px';
    tlBot.innerHTML = botHtml;

    // Les libellés se chevauchent si deux jalons sont trop proches en pixels —
    // seuil qui dépend de la largeur réelle du panneau (mobile vs desktop), pas
    // d'un pourcentage fixe. On mesure donc les positions réellement rendues et
    // on empile sur une ligne suivante ceux qui se recouvrent, quelle que soit
    // la largeur d'écran.
    requestAnimationFrame(function() {
      var items = Array.prototype.slice.call(tlBot.querySelectorAll('.tl-bot-item'));
      items.sort(function(a, b) { return parseFloat(a.dataset.pct) - parseFloat(b.dataset.pct); });
      var GAP_H = 8, GAP_ROW = 6;
      var rowRightEdge = [], rowHeight = [], rowOf = [];
      items.forEach(function(el, idx) {
        var rect = el.getBoundingClientRect();
        var row = 0;
        while (rowRightEdge[row] !== undefined && rowRightEdge[row] + GAP_H > rect.left) { row++; }
        rowRightEdge[row] = rect.right;
        rowHeight[row] = Math.max(rowHeight[row] || 0, rect.height);
        rowOf[idx] = row;
      });
      var maxRow = rowHeight.length - 1;
      var cumulativeTop = [0];
      for (var r = 1; r <= maxRow; r++) {
        cumulativeTop[r] = cumulativeTop[r - 1] + rowHeight[r - 1] + GAP_ROW;
      }
      items.forEach(function(el, idx) {
        var row = rowOf[idx];
        if (row > 0) { el.style.top = cumulativeTop[row] + 'px'; el.classList.add('stacked'); }
      });
      if (maxRow > 0) { tlBot.style.height = (cumulativeTop[maxRow] + rowHeight[maxRow]) + 'px'; }
    });
  }
}


/* ── PARCOURS ── */
/* ── PARCOURS ── */
/* Extended blocs: m = modalities (el/visio/pres) */
var LIA_BLOCS_EX = [
  {n:'Politique de la sécurité routière',    m:['el']},
  {n:'Cadre de l\'activite ECSR',            m:['el']},
  {n:'L\'automobile',                        m:['el','visio']},
  {n:'Analyse de la tache de conduite',      m:['el']},
  {n:'Physiologie du conducteur',            m:['el']},
  {n:'Psychologie du conducteur',            m:['el','visio']},
  {n:'Notions pédagogiques',                 m:['el','visio']},
  {n:'Ingénierie et construction de séance', m:['el','visio']},
  {n:'Techniques d\'animation',              m:['el','visio']},
  {n:'Gestion des conflits',                 m:['visio']},
  {n:'Gestion du stress',                    m:['visio']},
  {n:'Anticipation des risques',             m:['visio','pres']},
  {n:'Evaluation et docimologie',            m:['visio','pres']},
  {n:'Difficultes et remediation',           m:['pres']},
  {n:'Analyse de la demande (CCP2)',         m:['el','visio']},
  {n:'Construction d\'une action',           m:['el','visio']},
  {n:'Animation d\'une sensibilisation',     m:['visio','pres']},
  {n:'Evaluation des pratiques',             m:['pres']}
];
var LIA_PHASES = [
  {label:'Module Commun', blocs:[0,1,2,3,4,5,6], s:1, e:11, exam:null},
  {label:'CCP1 · Former les apprentis conducteurs', blocs:[7,8,9,10,11,12,13], s:12, e:26,
   exam:{label:'Examen CCP1', week:26, desc:'QCU · pédagogie en salle · pédagogie en véhicule · entretien. Réussi = autorisation temporaire d\'exercer, 12 mois pour valider le CCP2.'}},
  {label:'CCP2 · Sensibiliser à la sécurité routière', blocs:[14,15,16,17], s:27, e:35,
   exam:{label:'Session titre', week:35, desc:'Dossier professionnel + entretien final. Titre professionnel ECSR (niveau 5, RNCP).'}}
];

/* Gestionnaire d'auto-école et Support commercial & pédagogique : mêmes
   structures que LIA_BLOCS_EX/LIA_PHASES ci-dessus, mais propres à ces
   2 formations (voir LIA_BLOCS_GESTIONNAIRE/LIA_BLOCS_SUPPORT plus haut
   pour l'équivalent pondéré en heures). */
var LIA_BLOCS_EX_GESTIONNAIRE = [
  {n:'Réglementation et agrément préfectoral',       m:['el']},
  {n:'Obligations de l\'exploitant d\'auto-école',   m:['el']},
  {n:'Comptabilité analytique et rentabilité',       m:['el','visio']},
  {n:'Gestion financière de l\'auto-école',          m:['el']},
  {n:'Recrutement des enseignants',                  m:['el','visio']},
  {n:'Management et planning des équipes',           m:['el','visio']},
  {n:'Acquisition clients et stratégie commerciale', m:['el']},
  {n:'Suivi CPF et financements des élèves',         m:['el','visio']}
];
var LIA_PHASES_GESTIONNAIRE = [
  {label:'Gestionnaire d’auto-école', blocs:[0,1,2,3,4,5,6,7], s:1, e:16, exam:null}
];

var LIA_BLOCS_EX_SUPPORT = [
  {n:'Accueil, inscription et accompagnement',  m:['el']},
  {n:'Relation client et fidélisation',         m:['el','visio']},
  {n:'Gestion des plannings moniteurs',         m:['el']},
  {n:'Gestion des passages d\'examen',           m:['el','visio']},
  {n:'Suivi des financements CPF',               m:['el']},
  {n:'Permis à 1€ et aides France Travail',      m:['el']},
  {n:'Outils de gestion au quotidien',           m:['el','visio']}
];
var LIA_PHASES_SUPPORT = [
  {label:'Support commercial & pédagogique', blocs:[0,1,2,3,4,5,6], s:1, e:12, exam:null}
];

FORMATIONS.ecsr.phases         = LIA_PHASES;
FORMATIONS.ecsr.blocsEx        = LIA_BLOCS_EX;
FORMATIONS.gestionnaire.phases  = LIA_PHASES_GESTIONNAIRE;
FORMATIONS.gestionnaire.blocsEx = LIA_BLOCS_EX_GESTIONNAIRE;
FORMATIONS.support.phases       = LIA_PHASES_SUPPORT;
FORMATIONS.support.blocsEx      = LIA_BLOCS_EX_SUPPORT;

var LIA_MONTHS_FR_P = ['janv.','fevr.','mars','avr.','mai','juin','juil.','aout','sept.','oct.','nov.','dec.'];

function renderParcours() {
  var d = DATA;
  var cur = d.current_week || 1;
  var cohort = d.cohort;

  document.getElementById('parc-sub').textContent = ACTIVE_FORMATION.subtitle +
    (cohort ? ' · ' + cohort.name : '');

  // ─── Frise de la formation ───
  var barsHtml = '', labsHtml = '';
  for (var w = 1; w <= ACTIVE_FORMATION.totalWeeks; w++) {
    var wt = liaWType(w);
    var isCur = (w === cur);
    var clr;
    if      (wt === 'r')  clr = '#F2A03D';
    else if (wt === 'rs') clr = '#F6C685';
    else if (wt === 's')  clr = '#E8E4DA';
    else if (wt === 'e')  clr = '#24333C';
    else if (wt === 'e1') clr = '#B0A8E0';
    else                  clr = (w < cur) ? '#2E9488' : '#7CC9AC';
    var tipTxt = 'S' + w + ' · ' + (LIA_WLABELS[wt] || wt);
    barsHtml += '<div class="strip-bar' + (isCur ? ' cur-week' : '') + '"' +
      ' style="height:54px;background:' + clr + ';opacity:' + (w < cur && wt === 'c' ? '.5' : '1') + ';position:relative"' +
      ' title="' + tipTxt + '">' +
      (isCur ? '<div class="strip-youarehere">Vous êtes ici</div>' : '') +
      '</div>';
    labsHtml += '<span' + (isCur ? ' style="color:var(--violet);font-weight:800"' : '') + '>' + w + '</span>';
  }
  document.getElementById('parc-bars').innerHTML   = barsHtml;
  document.getElementById('parc-labels').innerHTML = labsHtml;

  var startDate = cohort && cohort.startdate ? liaParseDate(cohort.startdate) : null;
  var startTs   = startDate ? Math.floor(startDate.getTime() / 1000) : 0;

  // Sort courses by shortname for index-based mapping
  var sortedC = (d.courses || []).slice().sort(function(a, b) {
    return a.shortname.localeCompare(b.shortname);
  });

  function weekDate(w) {
    if (!startTs) return 'S' + w;
    var dt = new Date((startTs + (w - 1) * 7 * 86400) * 1000);
    return dt.getDate() + ' ' + LIA_MONTHS_FR_P[dt.getMonth()] + ' ' + dt.getFullYear();
  }

  function blocStatus(bIdx) {
    var bWeek = LIA_DIST_WEEKS[bIdx];
    if (!bWeek) return 'ns';
    if (cur > bWeek) return 'done';
    if (cur === bWeek) return 'cur';
    return 'ns';
  }

  var html = '';
  ACTIVE_FORMATION.phases.forEach(function(phase) {
    var firstW = LIA_DIST_WEEKS[phase.blocs[0]] || phase.s;
    html += '<div class="parc-phase">';
    html += '<div class="parc-phase-head">' + esc(phase.label) +
            '<span class="parc-wrange">S' + firstW + ' → S' + phase.e + '</span></div>';

    phase.blocs.forEach(function(bIdx) {
      var b      = ACTIVE_FORMATION.blocsEx[bIdx];
      var status = blocStatus(bIdx);
      var course = sortedC[bIdx] || null;
      var pct    = course ? course.pct : 0;

      var icoHtml;
      if (status === 'done') {
        icoHtml = '<div class="pi-done"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"><polyline points="20 6 9 17 4 12"/></svg></div>';
      } else if (status === 'cur') {
        icoHtml = '<div class="pi-cur"></div>';
      } else {
        icoHtml = '<div class="pi-ns"></div>';
      }

      var badgesHtml = b.m.map(function(mod) {
        if (mod === 'el')    return '<span class="badge b-el">e-learning</span>';
        if (mod === 'visio') return '<span class="badge b-visio">visio</span>';
        if (mod === 'pres')  return '<span class="badge b-pres">présentiel</span>';
        return '';
      }).join('');

      var rightHtml = '';
      if (status === 'cur' && course) {
        var pctClr = pct >= 80 ? 'var(--teal)' : pct >= 40 ? 'var(--violet)' : 'var(--orange)';
        rightHtml = '<div class="parc-right">' +
          '<div class="pbar" style="width:90px"><i style="width:' + pct + '%;background:' + pctClr + '"></i></div>' +
          '<span style="font-size:12px;font-weight:700;color:' + pctClr + ';white-space:nowrap">' + pct + ' %</span>' +
          '<button type="button" class="btn mini primary" onclick="openDetail(' + course.id + ',\'' + esc(b.n).replace(/'/g,"\\'") + '\',' + pct + ')">Reprendre</button>' +
          '</div>';
      } else if (status === 'done' && course) {
        rightHtml = '<div class="parc-right">' +
          '<button type="button" class="btn mini ghost" onclick="openDetail(' + course.id + ',\'' + esc(b.n).replace(/'/g,"\\'") + '\',' + pct + ')">Revoir</button>' +
          '</div>';
      }

      var subHtml = '';
      if (status === 'cur' && course && course.total_mods > 0) {
        subHtml = '<div class="parc-sub-lbl">bloc en cours · ' + course.done_mods + '/' + course.total_mods + ' activités</div>';
      }

      html += '<div class="parc-row parc-row-' + status + '">' +
        icoHtml +
        '<div class="parc-body">' +
          '<div class="parc-name">' + esc(b.n) + '</div>' +
          subHtml +
          '<div class="parc-badges">' + badgesHtml + '</div>' +
        '</div>' +
        rightHtml +
        '</div>';
    });

    if (phase.exam) {
      var examDateStr = weekDate(phase.exam.week);
      html += '<div class="parc-exam">' +
        '<div style="font-size:13px;font-weight:700">' + esc(phase.exam.label) +
        ' · semaine du ' + examDateStr + ' (S' + phase.exam.week + ')</div>' +
        '<div style="font-size:12px;margin-top:4px;opacity:.75">' + esc(phase.exam.desc) + '</div>' +
        '</div>';
    }

    html += '</div>';
  });

  document.getElementById('parc-phases').innerHTML = html;
}

/* ── COURS ── */
function renderCours() {
  var d = DATA;
  document.getElementById('cours-sub').textContent = d.courses.length + ' cours assigné' + (d.courses.length > 1 ? 's' : '');
  var grid = document.getElementById('cours-grid');
  if (!d.courses.length) {
    grid.innerHTML = '<p style="color:var(--muted);font-size:13px">Aucun cours assigné.</p>';
    return;
  }
  var lcId = d.last_course ? d.last_course.id : null;
  grid.innerHTML = d.courses.map(function(c) {
    var link = WWWROOT + '/course/view.php?id=' + c.id;
    var isLast = (c.id === lcId);
    var pctClr = c.pct >= 80 ? 'var(--teal)' : c.pct >= 40 ? 'var(--violet)' : 'var(--orange)';
    return '<div class="cours-card" style="cursor:pointer;' + (isLast ? 'border-color:var(--teal);box-shadow:0 0 0 2px var(--teal-t)' : '') + '" data-cid="' + c.id + '" data-pct="' + c.pct + '" onclick="openDetail(+this.dataset.cid,this.querySelector(\'.cours-fullname\').textContent,+this.dataset.pct)">' +
      (isLast ? '<div style="font-size:10px;font-weight:700;color:var(--teal-d);margin-bottom:2px">Dernier cours ouvert</div>' : '') +
      '<div class="cours-shortname">' + esc(c.shortname) + '</div>' +
      '<div class="cours-fullname">' + esc(c.fullname) + '</div>' +
      '<div class="pbar"><i style="width:' + c.pct + '%;background:' + pctClr + '"></i></div>' +
      '<div class="cours-pct">' + c.pct + '% complété · ' + c.done_mods + '/' + c.total_mods + ' activités</div>' +
      '<span class="btn mini primary" style="display:inline-block">' +
        (isLast ? 'Reprendre' : 'Ouvrir') + ' \u2192' +
      '</span>' +
    '</div>';
  }).join('');
}

/* ── DETAIL ── */
var _dv = {id:0, name:'', pct:0, sections:[]};

function openDetail(courseId, courseName, coursePct) {
  _dv.id = courseId; _dv.name = courseName; _dv.pct = coursePct; _dv.sections = [];
  _rendered['detail'] = false;
  var dvBody = document.querySelector('.dv-body');
  if (dvBody) dvBody.classList.remove('dv-mob-content'); // repartir sur la liste des sections en mobile
  document.querySelectorAll('aside button').forEach(function(b){ b.classList.remove('on'); });
  document.querySelectorAll('.view').forEach(function(v){ v.classList.remove('on'); });
  document.getElementById('v-detail').classList.add('on');
  go('detail');
}

function dvRenderBreadcrumb(crumbs) {
  var el = document.getElementById('dv-breadcrumb');
  if (!el) return;
  if (!crumbs || !crumbs.length) { el.innerHTML = ''; return; }
  el.innerHTML = crumbs.map(function(c, i) {
    var isLast = i === crumbs.length - 1;
    return (i > 0 ? '<span class="dv-crumb-sep">&gt;</span>' : '') +
      '<span' + (isLast ? ' class="dv-crumb-current"' : '') + '>' + esc(c) + '</span>';
  }).join('');
}

function renderDetail() {
  document.getElementById('dv-course-title').textContent = _dv.name || 'Selectionnez un cours';
  document.getElementById('dv-course-sub').textContent  = _dv.pct ? 'progression ' + _dv.pct + ' %' : '';
  document.getElementById('dv-iframe').style.display = 'none';
  document.getElementById('dv-empty').style.display  = 'none';
  document.getElementById('dv-overview').style.display = 'none';
  document.getElementById('dv-loading').style.display = 'none';
  dvRenderBreadcrumb(null);
  if (!_dv.id) {
    document.getElementById('dv-empty').style.display = 'flex';
    document.getElementById('dv-sections').innerHTML =
      '<p style="padding:20px 18px;color:var(--muted);font-size:13px">Cliquez sur un cours pour voir son contenu.</p>';
    return;
  }
  dvRenderOverview(); // par défaut, tant qu'aucune activité n'est sélectionnée
  document.getElementById('dv-sections').innerHTML =
    '<p style="padding:20px 18px;color:var(--muted);font-size:13px">Chargement…</p>';
  fetch(window.location.pathname + '?action=course_detail&courseid=' + _dv.id)
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (!d.ok) {
        document.getElementById('dv-sections').innerHTML =
          '<p style="padding:20px 18px;color:var(--muted);font-size:13px">Erreur chargement.</p>';
        return;
      }
      _dv.sections = d.sections;
      dvRenderBreadcrumb(d.breadcrumb);
      dvRenderSections(d.sections);
    })
    .catch(function(){
      document.getElementById('dv-sections').innerHTML =
        '<p style="padding:20px 18px;color:var(--muted);font-size:13px">Erreur reseau.</p>';
    });
}

function dvRenderSections(sections) {
  if (!sections || !sections.length) {
    document.getElementById('dv-sections').innerHTML =
      '<p style="padding:20px 18px;color:var(--muted);font-size:13px">Aucun contenu.</p>';
    return;
  }
  var html = '';
  sections.forEach(function(s, si) {
    var allDone = s.total > 0 && s.done_n === s.total;
    html += '<div class="det-sec" id="det-sec-' + si + '">';
    html += '<div class="det-sec-head" onclick="dvToggleSec(' + si + ')">';
    html += '<div class="det-sec-num' + (allDone ? ' done' : '') + '">' + s.num + '</div>';
    html += '<div class="det-sec-info"><div class="det-sec-name">' + esc(s.name) + '</div>';
    if (s.total > 0) html += '<div class="det-sec-count">(' + s.done_n + '/' + s.total + ')</div>';
    html += '</div><div class="det-sec-arrow" id="det-arr-' + si + '">›</div></div>';
    html += '<div class="det-mods" id="det-mods-' + si + '">';
    s.mods.forEach(function(m) {
      var dotCls = m.done ? ' done' : '';
      html += '<div class="det-mod" id="det-mod-' + m.cmid + '" onclick="dvSelectMod(' + m.cmid + ',\'' + m.modtype + '\',this)">';
      html += '<div class="det-mod-dot' + dotCls + '"></div>';
      html += '<div class="det-mod-name">' + esc(m.name) + '</div></div>';
    });
    html += '</div></div>';
  });
  document.getElementById('dv-sections').innerHTML = html;
}

function dvToggleSec(idx) {
  var mods = document.getElementById('det-mods-' + idx);
  var arr  = document.getElementById('det-arr-' + idx);
  if (!mods) return;
  mods.classList.toggle('open');
  arr.classList.toggle('open');
}

function dvUnfoldAll() {
  document.querySelectorAll('.det-mods').forEach(function(el){ el.classList.add('open'); });
  document.querySelectorAll('.det-sec-arrow').forEach(function(el){ el.classList.add('open'); });
}

var _dvIframeCSS = [
  /* header / footer / nav */
  '#page-header,#page-footer,#page-navbar,nav.navbar,.navbar,#masthead,',
  '#master-header,#master-footer,#page-mast,#page-top,footer,#footer,.main-footer,',
  '.l3m-eleve-btn,.l3m-footer-grid,',
  /* Moodle course index / drawers (précis, pas [class*=sidebar]) */
  '.courseindex,.drawer-right,[data-region="courseindex"],[data-region="fixed-drawer"],',
  '#theme-course-index,.mb2-course-index,.mb2nl-course-index,',
  '.course-index,.course-index-drawer,.block_tableofcontents,.block_course_toc,',
  '.sidebar-col,',
  /* mb2nl coursetoc panel */
  '#l3m-activity-iframe-container,[class*="coursetoc"],[id*="coursetoc"],',
  /* secondary / tertiary nav, activity prev-next */
  '.secondary-navigation,.tertiary-navigation,.activity-navigation{display:none!important}',
  /* layout */
  'body,#page{background:#F7F6F1!important}',
  '#page-content,#region-main{padding-top:8px!important}',
  '#region-main-box,#region-main{width:100%!important;max-width:100%!important}',
  /* colors */
  'h1,h2,h3{color:#1C5F58!important}',
  /* bandeaux d\'intro de module (fond teal plein, contenu authored avec ses
     propres styles inline) : le override h1/h2/h3 ci-dessus rendait le titre
     illisible (teal foncé sur teal). On force du blanc uniquement dans ces
     bandeaux, repérés par leur fond inline #2E9488 commun aux ~40 pages. */
  'div[style*="background:#2E9488"] h1,div[style*="background:#2E9488"] h2,div[style*="background:#2E9488"] h3,div[style*="background:#2E9488"] p{color:#fff!important}',
  'a{color:#2E9488!important}',
  '.btn-primary,.btn.btn-primary{background:#2E9488!important;border-color:#1C5F58!important}',
  '.btn-secondary,.btn.btn-secondary{background:#fff!important;border-color:#2E9488!important;color:#2E9488!important}',
  '.nav-tabs .nav-link.active{border-bottom-color:#2E9488!important;color:#1C5F58!important}',
  '.badge-info,.badge-primary{background:#2E9488!important}',
  '.completioncheckbox input[type=checkbox]:checked{accent-color:#2E9488}',
  '.progress-bar{background:#2E9488!important}',
  '.activity-header{border-bottom:3px solid #2E9488!important}',
  /* scrollbar aux couleurs LBA (teal) plutôt que l\'accent doré par défaut du thème */
  'html{scrollbar-color:#2E9488 #F7F6F1!important}',
  '::-webkit-scrollbar{width:10px}',
  '::-webkit-scrollbar-track{background:#F7F6F1}',
  '::-webkit-scrollbar-thumb{background:#2E9488!important;border-radius:6px}',
  '::-webkit-scrollbar-thumb:hover{background:#1C5F58!important}',
  // Selecteur de fichiers Moodle : texte des onglets en noir (illisible par
  // defaut) et accent orange remplace par le teal de la charte (voir
  // repository/filepicker.js, classes .fp-repo/.nav-link ajoutees/retirees
  // dynamiquement selon l\'onglet actif).
  '.fp-repo .fp-repo-name{color:#1A1714!important}',
  '.fp-repo-area .fp-repo .nav-link.active{background:#2E9488!important;border-color:#2E9488!important}',
  '.fp-repo-area .fp-repo .nav-link.active .fp-repo-name{color:#fff!important}',
  // Panneau "Deposer un fichier" : retire "Enregistrer sous" et "Choisir une
  // licence" (attributs stables, voir repository/filepicker.js).
  '.fp-saveas,.fp-setlicense{display:none!important}',
  // Gestionnaire de fichiers (zone "Fichiers" d'un devoir) : menu contextuel
  // "..." par fichier, ouvre les memes champs superflus (licence...) —
  // classe stable core Moodle, voir theme/boost/scss/moodle/filemanager.scss.
  '.fp-contextmenu{display:none!important}'
].join('');

/* ── Aperçu instantané (objectifs + WhatsApp), sans recharger la page de cours ── */
var _dvObjectifs = {}; // courseId -> Promise({items, whatsapp, whatsapp_url})

function dvFetchObjectifs(courseId) {
  if (_dvObjectifs[courseId]) return _dvObjectifs[courseId];
  var p = fetch(window.location.pathname + '?action=course_objectifs&courseid=' + courseId)
    .then(function(r) { return r.json(); })
    .catch(function() { return {ok:false}; });
  _dvObjectifs[courseId] = p;
  return p;
}

function dvRenderOverview() {
  var overview = document.getElementById('dv-overview');
  var iframe   = document.getElementById('dv-iframe');
  var empty    = document.getElementById('dv-empty');
  iframe.style.display = 'none';
  empty.style.display  = 'none';
  overview.style.display = 'block';
  overview.innerHTML = '<div class="dv-overview-empty">Chargement…</div>';

  var forCourseId = _dv.id;
  dvFetchObjectifs(forCourseId).then(function(r) {
    // Si l'utilisateur a rouvert une activité entre-temps, l'aperçu n'est plus visible : ne rien faire.
    if (overview.style.display === 'none' || _dv.id !== forCourseId) return;
    var html = '';
    if (r.ok && r.items && r.items.length) {
      var mid  = Math.ceil(r.items.length / 2);
      var col1 = r.items.slice(0, mid);
      var col2 = r.items.slice(mid);
      function renderCol(col) {
        return '<ul class="l3m-obj-list">' + col.map(function(it) { return '<li>' + esc(it) + '</li>'; }).join('') + '</ul>';
      }
      html += '<div class="l3m-objectifs"><h3 class="l3m-obj-title">Ce que vous allez apprendre</h3>' +
              '<div class="l3m-obj-grid"><div class="l3m-obj-col">' + renderCol(col1) + '</div>' +
              (col2.length ? '<div class="l3m-obj-col">' + renderCol(col2) + '</div>' : '') +
              '</div></div>';
    }
    if (r.ok && r.whatsapp) {
      html += '<div style="text-align:center;margin-top:8px">' +
        '<a class="dv-overview-whatsapp" href="' + esc(r.whatsapp_url) + '" target="_blank" rel="noopener">' +
        '💬 Rejoindre le groupe WhatsApp</a></div>';
    }
    if (!html) html = '<div class="dv-overview-empty">Sélectionnez une activité dans le menu à gauche.</div>';
    overview.innerHTML = html;
  });
}

function dvInjectCSS(iframe) {
  try {
    var doc = iframe.contentDocument || iframe.contentWindow.document;
    if (!doc || !doc.head) return;
    // Le thème mb2nl rend certaines activités en "mode plein écran" avec son propre
    // lien de retour natif (a.fsmod-backlink → /course/view.php?id=X). Ce lien
    // navigue normalement vers toute la page de cours Moodle (lourde, plusieurs
    // secondes à charger) juste pour en extraire le bandeau WhatsApp et le bloc
    // "Ce que vous allez apprendre". On intercepte ce clic et on affiche ces deux
    // éléments nous-mêmes, instantanément, via notre propre appel léger.
    if (doc.body && !doc.body.dataset.l3mDvClickBound) {
      doc.addEventListener('click', function(ev) {
        var a = ev.target.closest && ev.target.closest('a[href]');
        if (!a) return;
        var h = a.getAttribute('href') || '';
        if (h.indexOf('/course/view.php') !== -1) {
          ev.preventDefault();
          ev.stopPropagation();
          dvRenderOverview();
        }
      }, true);
      doc.body.dataset.l3mDvClickBound = '1';
    }
    // Appele avant le retour anticipe ci-dessous (garde-fou interne propre a
    // la fonction, qui doit re-agir a chaque ouverture du selecteur de
    // fichiers, y compris apres la premiere injection de style sur ce doc).
    dvHideRepoTabs(doc);
    dvHideEditSubmissionBtn(doc);
    if (doc.getElementById('l3m-dv-style')) return;
    var s = doc.createElement('style');
    s.id = 'l3m-dv-style';
    s.textContent = _dvIframeCSS;
    doc.head.appendChild(s);
    // Remove meta-refresh redirects
    doc.querySelectorAll('meta[http-equiv="refresh"]').forEach(function(m){ m.remove(); });
  } catch(e) {}
}

// "Selecteur de fichiers" Moodle (repository/filepicker.js), quand un
// eleve remet un devoir : ne garder que "Deposer un fichier" (upload local
// depuis l'ordinateur), retirer tous les autres onglets. Chaque onglet n'a
// qu'un id numerique d'instance propre au site (pas de type stable), donc on
// filtre par le nom affiche (.fp-repo-name) — meme principe que
// ceHideChooserAllTab cote formateur/admin.
function dvHideRepoTabs(doc) {
  if (doc._l3mRepoObserver) return;
  var KEEP = 'Déposer un fichier';
  var hide = function() {
    doc.querySelectorAll('.fp-repo').forEach(function(el) {
      var nameEl = el.querySelector('.fp-repo-name');
      if (nameEl && nameEl.textContent.trim() !== KEEP) {
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

// Une fois un devoir remis, l'eleve ne doit pas pouvoir le modifier (evite
// les remises "definitives" changees apres coup). Pas de classe/attribut
// propre sur ce bouton, on filtre par le texte affiche (meme principe que
// dvHideRepoTabs). "Supprimer travail remis" n'est pas concerne par cette
// demande, on ne touche qu'a "Modifier le travail".
function dvHideEditSubmissionBtn(doc) {
  if (doc._l3mEditSubmissionObserver) return;
  var hide = function() {
    doc.querySelectorAll('a.btn, button.btn').forEach(function(el) {
      if (el.textContent.trim() === 'Modifier le travail') el.style.display = 'none';
    });
  };
  hide();
  try {
    var obs = new MutationObserver(hide);
    obs.observe(doc.body, {childList: true, subtree: true});
    doc._l3mEditSubmissionObserver = obs;
  } catch (e) {}
}

function dvWatchIframe(iframe) {
  // Inject CSS as soon as possible via MutationObserver on the iframe document
  try {
    var win = iframe.contentWindow;
    if (!win) return;
    var obs = new win.MutationObserver(function() {
      var doc = win.document;
      if (doc && doc.head && !doc.getElementById('l3m-dv-style')) {
        dvInjectCSS(iframe);
      }
    });
    obs.observe(win.document.documentElement || win.document, {childList:true, subtree:true});
    // Also apply immediately if already loaded
    dvInjectCSS(iframe);
  } catch(e) {}
}

function dvMobileBack() {
  var body = document.querySelector('.dv-body');
  if (body) body.classList.remove('dv-mob-content');
}

function dvSelectMod(cmid, modtype, el) {
  document.querySelectorAll('.det-mod').forEach(function(e){ e.classList.remove('active'); });
  document.querySelectorAll('.det-mod-dot').forEach(function(e){ e.classList.remove('active-dot'); });
  if (window.innerWidth <= 760) {
    var body = document.querySelector('.dv-body');
    if (body) body.classList.add('dv-mob-content');
  }
  if (el) {
    el.classList.add('active');
    var dot = el.querySelector('.det-mod-dot');
    if (dot) dot.classList.add('active-dot');
  }
  var url = WWWROOT + '/mod/' + modtype + '/view.php?id=' + cmid;
  var iframe   = document.getElementById('dv-iframe');
  var empty    = document.getElementById('dv-empty');
  var overview = document.getElementById('dv-overview');
  var loading  = document.getElementById('dv-loading');
  // On estompe l'iframe avant de naviguer : sinon l'activite precedente reste
  // affichee jusqu'a la fin du chargement de la nouvelle (flash de l'ancien
  // contenu). Un spinner comble le temps de chargement reseau reel (le
  // contenu de l'activite doit de toute facon venir du serveur).
  iframe.style.opacity = '0';
  loading.style.display = 'flex';
  iframe.onload = function() { dvInjectCSS(iframe); dvWatchIframe(iframe); iframe.style.opacity = '1'; loading.style.display = 'none'; };
  iframe.src = url;
  iframe.style.display = 'block';
  empty.style.display  = 'none';
  overview.style.display = 'none';
}

// Ouvre un devoir (Mes actions, banniere "en retard", "Ma semaine") dans le
// visualiseur "Modules" (meme experience que la navigation manuelle) plutot
// que dans un nouvel onglet Moodle non habille.
function openActionAssign(courseId, courseName, cmid) {
  openDetail(courseId, courseName, 0);
  var tries = 0;
  var wait = setInterval(function() {
    tries++;
    if (_dv.sections && _dv.sections.length) {
      clearInterval(wait);
      var el = document.getElementById('det-mod-' + cmid);
      if (el) {
        var mods = el.closest('.det-mods');
        if (mods) {
          mods.classList.add('open');
          var sec = mods.closest('.det-sec');
          var arrow = sec && sec.querySelector('.det-sec-arrow');
          if (arrow) arrow.classList.add('open');
        }
      }
      dvSelectMod(cmid, 'assign', el);
    } else if (tries > 100) {
      clearInterval(wait); // ~10s : abandon silencieux, l'eleve navigue a la main
    }
  }, 100);
}

function dvFilter(val) {
  var q = val.toLowerCase();
  document.querySelectorAll('.det-sec').forEach(function(sec) {
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

/* ── AGENDA / CALENDRIER ── */
var _calDate = (function(){ var d = new Date(); d.setDate(1); return d; })();
var _calViewMode = 'mois';

var _calMobileDefaultDone = false;
function renderAgenda() {
  // Sur petit écran, la vue "Mois" (grille 7 colonnes) dépasse la largeur
  // disponible et oblige à scroller horizontalement pour voir Ven/Sam/Dim
  // — peu lisible/pas évident. On bascule donc sur "Liste" par défaut sur
  // mobile (une seule fois, pour ne pas écraser un choix de vue déjà fait
  // par l'élève pendant sa session).
  if (!_calMobileDefaultDone) {
    _calMobileDefaultDone = true;
    if (window.innerWidth <= 760) {
      _calViewMode = 'liste';
      document.querySelectorAll('.cal-view-btns button').forEach(function(b){ b.classList.remove('on'); });
      var listeBtn = document.getElementById('cal-btn-liste');
      if (listeBtn) listeBtn.classList.add('on');
    }
  }
  calDraw();
}

function calPrev() { _calDate.setMonth(_calDate.getMonth() - 1); calDraw(); }
function calNext() { _calDate.setMonth(_calDate.getMonth() + 1); calDraw(); }
function calGoToday() { _calDate = new Date(); _calDate.setDate(1); calDraw(); }

function calView(v, btn) {
  _calViewMode = v;
  document.querySelectorAll('.cal-view-btns button').forEach(function(b){ b.classList.remove('on'); });
  if (btn) btn.classList.add('on');
  calDraw();
}

function calActiveCats() {
  var cats = {};
  document.querySelectorAll('.cal-filters [data-cat]').forEach(function(inp) {
    cats[inp.dataset.cat] = inp.checked;
  });
  return cats;
}

function calGetEvents(year, month) {
  var evs = {};
  function addEv(dateObj, label, cat) {
    var k = dateObj.getFullYear() + '-' + (dateObj.getMonth()+1) + '-' + dateObj.getDate();
    if (!evs[k]) evs[k] = [];
    evs[k].push({label: label, cat: cat});
  }

  var cohort = DATA.cohort;
  if (cohort && cohort.startdate) {
    var sd = liaParseDate(cohort.startdate);
    if (sd) {
      var sdMs = new Date(sd.getFullYear(), sd.getMonth(), sd.getDate()).getTime();
      var daysInMonth = new Date(year, month + 1, 0).getDate();
      for (var day = 1; day <= daysInMonth; day++) {
        var cur = new Date(year, month, day);
        var diffD = Math.round((cur.getTime() - sdMs) / 86400000);
        if (diffD < 0) continue;
        var wk  = Math.floor(diffD / 7) + 1;
        if (wk > ACTIVE_FORMATION.totalWeeks) continue;
        var dow = cur.getDay(); // 0=dim
        var wt  = liaWType(wk);
        if (wt === 'e') {
          if (dow >= 1 && dow <= 5) addEv(cur, 'Examen', 'examen');
        } else if (wt === 's') {
          if (dow >= 1 && dow <= 6) addEv(cur, 'Stage', 'stage');
        } else if (wt === 'r' || wt === 'rs') {
          if (dow >= 1 && dow <= 5) addEv(cur, 'Regroupement', 'presentiel');
        } else {
          // semaine cours normale
          if (dow >= 1 && dow <= 5) addEv(cur, 'E-learning', 'elearning');
          if (dow === 3) addEv(cur, 'Suivi hebdo', 'visio');
          if (dow === 4) addEv(cur, 'Cours pédago', 'presentiel');
          if (dow === 5) addEv(cur, 'Quiz de bloc', 'elearning');
          if (dow === 0) addEv(cur, 'Dépôt devoir', 'devoir');
        }
      }
    }
  }

  (DATA.events || []).forEach(function(e) {
    if (!e.timestart) return;
    var ed = new Date(e.timestart * 1000);
    if (ed.getFullYear() === year && ed.getMonth() === month) {
      addEv(ed, e.name, 'moodle');
    }
  });

  return evs;
}

function calDraw() {
  var MOIS_FR = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
  var lbl = document.getElementById('cal-month-lbl');
  if (lbl) lbl.textContent = MOIS_FR[_calDate.getMonth()] + ' ' + _calDate.getFullYear();
  var body = document.getElementById('cal-body');
  if (!body) return;
  var year  = _calDate.getFullYear();
  var month = _calDate.getMonth();
  var evs   = calGetEvents(year, month);
  var cats  = calActiveCats();
  var today = new Date();
  if (_calViewMode === 'liste') calDrawListe(body, evs, cats, year, month, today);
  else calDrawMois(body, evs, cats, year, month, today);
}

function calDrawMois(body, evs, cats, year, month, today) {
  var DOW = ['LUN','MAR','MER','JEU','VEN','SAM','DIM'];
  var html = '<div class="cal-grid">';
  DOW.forEach(function(d){ html += '<div class="cal-dow">' + d + '</div>'; });
  var first = new Date(year, month, 1);
  var startDow = (first.getDay() + 6) % 7; // 0=Lun
  var daysInMonth = new Date(year, month + 1, 0).getDate();
  var totalCells = Math.ceil((startDow + daysInMonth) / 7) * 7;
  for (var i = 0; i < totalCells; i++) {
    var cellDate = new Date(year, month, 1 + i - startDow);
    var isOther  = cellDate.getMonth() !== month;
    var isToday  = cellDate.getFullYear() === today.getFullYear() &&
                   cellDate.getMonth()    === today.getMonth() &&
                   cellDate.getDate()     === today.getDate();
    var key = cellDate.getFullYear() + '-' + (cellDate.getMonth()+1) + '-' + cellDate.getDate();
    var dayEvs = (evs[key] || []).filter(function(e){ return cats[e.cat] !== false; });
    // deduplicate same category
    var seen = {}; dayEvs = dayEvs.filter(function(e){ if(seen[e.cat+e.label]) return false; seen[e.cat+e.label]=1; return true; });
    html += '<div class="cal-cell' + (isOther ? ' other-month' : '') + (isToday ? ' today' : '') + '">';
    html += '<div class="cal-dn">' + cellDate.getDate() + '</div>';
    dayEvs.forEach(function(e) {
      html += '<div class="cal-ev ev-' + e.cat + '">' + esc(e.label) + '</div>';
    });
    html += '</div>';
  }
  html += '</div>';
  body.innerHTML = html;
}

function calDrawListe(body, evs, cats, year, month, today) {
  var MOIS_ABR = ['jan','fév','mars','avr','mai','juin','juil','août','sep','oct','nov','déc'];
  var MOIS_FR  = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];

  var cohort = DATA.cohort;
  var weeks  = [];

  if (cohort && cohort.startdate) {
    var sd = liaParseDate(cohort.startdate);
    if (sd) {
      var sdMs       = new Date(sd.getFullYear(), sd.getMonth(), sd.getDate()).getTime();
      var monthStart = new Date(year, month, 1).getTime();
      var monthEnd   = new Date(year, month + 1, 0, 23, 59, 59).getTime();

      // Count stage weeks before each week for période label
      var stagesBefore = 0;
      for (var wk = 1; wk <= ACTIVE_FORMATION.totalWeeks; wk++) {
        var wkStart = new Date(sdMs + (wk - 1) * 7 * 86400000);
        var wkFri   = new Date(wkStart.getTime() + 4 * 86400000);
        var wt      = liaWType(wk);
        if (wt === 's' || wt === 'rs') stagesBefore++;
        // Only show if week overlaps current month
        if (wkFri.getTime() < monthStart || wkStart.getTime() > monthEnd) continue;
        weeks.push({ wk: wk, wt: wt, start: wkStart, end: wkFri, stagePeriod: stagesBefore });
      }

      // Cohort span for pagination info
      var cohortEnd  = new Date(sdMs + (ACTIVE_FORMATION.totalWeeks - 1) * 7 * 86400000 + 4 * 86400000);
      var pgStart    = MOIS_ABR[sd.getMonth()] + ' ' + sd.getFullYear();
      var pgEnd      = MOIS_ABR[cohortEnd.getMonth()] + ' ' + cohortEnd.getFullYear();
      // Current page index
      var pgIdx  = (year - sd.getFullYear()) * 12 + (month - sd.getMonth()) + 1;
      var pgTotal = (cohortEnd.getFullYear() - sd.getFullYear()) * 12 + (cohortEnd.getMonth() - sd.getMonth()) + 1;
      var pgInfo  = pgIdx > 0 && pgTotal > 0 ? 'Page ' + pgIdx + ' / ' + pgTotal + ' · ' + pgStart + ' → ' + pgEnd : '';
    }
  }

  // Moodle events as extra rows
  var moodleEvs = (DATA.events || []).filter(function(e) {
    if (!e.timestart) return false;
    var ed = new Date(e.timestart * 1000);
    return ed.getFullYear() === year && ed.getMonth() === month;
  });

  var html = '<div class="cal-liste-weeks">';

  if (!weeks.length && !moodleEvs.length) {
    html += '<p style="padding:20px;color:var(--muted);font-size:13px">Aucune semaine LIA ce mois.</p>';
  }

  weeks.forEach(function(w) {
    var wt = w.wt;
    // Filter
    var cat = (wt==='e') ? 'examen' : (wt==='s') ? 'stage' : (wt==='r'||wt==='rs') ? 'presentiel' : 'elearning';
    if (cats[cat] === false) return;

    var sLabel = w.start.getDate() + ' ' + MOIS_ABR[w.start.getMonth()];
    var eLabel = w.end.getDate()   + ' ' + MOIS_ABR[w.end.getMonth()];
    var inCurWeek = today >= w.start && today <= new Date(w.end.getTime() + 2 * 86400000);

    var borderColor, title, chipsHtml, desc;
    if (wt === 'e') {
      borderColor = '#2A2A2A';
      title = "Semaine d'examen";
      chipsHtml = '<span class="cal-ev ev-examen">Examen</span>';
      desc = 'Épreuve finale du TP ECSR.';
    } else if (wt === 's') {
      borderColor = '#9A8A72';
      title = 'Stage · période ' + w.stagePeriod;
      chipsHtml = '<span class="cal-ev ev-stage">Stage</span>';
      desc = 'Formation en entreprise partenaire.';
    } else if (wt === 'r') {
      borderColor = '#C07820';
      title = 'Regroupement véhicule';
      chipsHtml = '<span class="cal-ev ev-presentiel">Présentiel</span>';
      desc = 'Formation sur véhicule · 3 élèves max.';
    } else if (wt === 'rs') {
      borderColor = '#C07820';
      title = 'Regroupement + stage';
      chipsHtml = '<span class="cal-ev ev-presentiel">Présentiel</span><span class="cal-ev ev-stage">Stage</span>';
      desc = 'Regroupement véhicule et formation en entreprise.';
    } else {
      borderColor = '#2E9488';
      title = 'Cours à distance';
      chipsHtml = '<span class="cal-ev ev-elearning">E-learning</span>';
      if (cats['visio'] !== false) chipsHtml += '<span class="cal-ev ev-visio">Visio</span>';
      desc = 'Depuis chez vous, au rythme du bloc.';
    }

    html += '<div class="cal-wrow" style="border-left-color:' + borderColor + '">';
    html += '<div class="cal-wrow-left">';
    html += '<div class="cal-wrow-dates">' + sLabel + ' → ' + eLabel + '</div>';
    html += '<div class="cal-wrow-snum">S' + w.wk + ' / ' + ACTIVE_FORMATION.totalWeeks +
            (inCurWeek ? ' · <b style="color:var(--violet)">cette semaine</b>' : '') + '</div>';
    html += '</div>';
    html += '<div class="cal-wrow-right">';
    html += '<div class="cal-wrow-title">' + esc(title) + chipsHtml + '</div>';
    html += '<div class="cal-wrow-desc">' + esc(desc) + '</div>';
    if (inCurWeek && (wt === 'c' || wt === 'e1')) {
      html += '<div class="cal-wrow-detail">Mer · Suivi hebdo (visio) &nbsp;·&nbsp; Jeu · Cours pédago &nbsp;·&nbsp; Ven · Quiz &nbsp;·&nbsp; Dim · Dépôt devoir</div>';
    }
    html += '</div></div>';
  });

  // Moodle events at bottom
  moodleEvs.forEach(function(e) {
    if (cats['moodle'] === false) return;
    var ed = new Date(e.timestart * 1000);
    var dLabel = ed.getDate() + ' ' + MOIS_ABR[ed.getMonth()];
    html += '<div class="cal-wrow" style="border-left-color:#2C6B8A">';
    html += '<div class="cal-wrow-left"><div class="cal-wrow-dates">' + dLabel + '</div></div>';
    html += '<div class="cal-wrow-right">';
    html += '<div class="cal-wrow-title">' + esc(e.name) + '<span class="cal-ev ev-moodle">Moodle</span></div>';
    html += '</div></div>';
  });

  html += '</div>';

  // Footer nav
  html += '<div class="cal-liste-footer">';
  html += '<button class="btn mini" onclick="calPrev()">← Précédent</button>';
  if (typeof pgInfo !== 'undefined' && pgInfo) {
    html += '<div class="cal-footer-center">' + pgInfo + '</div>';
  }
  html += '<button class="btn mini" onclick="calNext()">Suivant →</button>';
  html += '</div>';
  html += '<div class="cal-liste-btns">';
  html += '<button class="btn mini" onclick="calExport()">Ajouter à mon agenda</button>';
  html += '<button class="btn mini" onclick="window.print()">Imprimer le mois</button>';
  html += '</div>';

  body.innerHTML = html;
}

function calExport() {
  var cohort = DATA.cohort;
  if (!cohort || !cohort.startdate) { alert('Aucun calendrier disponible.'); return; }
  alert('Export ICS à venir.');
}

/* ── DOCS / DOCUMENTS ADMINISTRATIFS ── */
function renderDocs() {
  var docsBody = document.getElementById('docs-body');
  if (!docsBody) return;
  docsBody.innerHTML = '<p style="color:var(--muted);font-size:13px;padding:16px">Chargement…</p>';
  fetch(window.location.pathname + '?action=my_docs')
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (!d.ok) { docsBody.innerHTML = '<p style="color:var(--red);padding:16px">Erreur de chargement.</p>'; return; }
      docsRenderList(d.docs, d.blocking_count);
    })
    .catch(function(){ docsBody.innerHTML = '<p style="color:var(--red);padding:16px">Erreur réseau.</p>'; });
}

function docsRenderList(docs, blockingCount) {
  var docsBody = document.getElementById('docs-body');
  var banner   = document.getElementById('docs-banner');
  var blockingDoc = (docs || []).find(function(d){ return parseInt(d.is_blocking) === 1 && d.status_type === 'pending'; });

  if (blockingDoc && blockingDoc.blocking_msg) {
    banner.style.display = '';
    document.getElementById('docs-banner-msg').textContent = blockingCount + ' document bloquant · ' + blockingDoc.blocking_msg;
  } else {
    banner.style.display = 'none';
  }

  if (!docs || !docs.length) {
    docsBody.innerHTML = '<div class="okbox" style="margin:16px">Aucun document administratif pour le moment.</div>';
    return;
  }

  var STATUS_ICONS = {signed:'✓ ', validated:'✓ ', pending:'⚠ ', future:''};
  var STATUS_LABELS_FR = {signed:'Signé', validated:'Validé', pending:'En attente', future:'À venir'};
  var ACTION_LABELS = {download:'Télécharger', sign:'Signer', view:'Consulter'};
  var ACTION_CLASSES = {download:'', sign:'primary', view:''};

  var html = '<table class="docs-table"><thead><tr><th>DOCUMENT</th><th>STATUT</th><th></th><th></th></tr></thead><tbody>';
  docs.forEach(function(doc) {
    var sClass = 'dsb-' + doc.status_type;
    var sIcon  = STATUS_ICONS[doc.status_type] || '';
    var actionHtml = '';
    if (doc.action_type && doc.action_type !== 'none' && doc.action_url) {
      var btnCls = ACTION_CLASSES[doc.action_type] || '';
      actionHtml = '<a href="' + esc(doc.action_url) + '" target="_blank" rel="noopener" class="btn mini ' + btnCls + '">' + (ACTION_LABELS[doc.action_type] || doc.action_type) + '</a>';
    }
    var sendHtml = '';
    if (doc.status_type === 'pending') {
      sendHtml = doc.signed_uploaded_at > 0
        ? '<span style="font-size:11.5px;color:var(--teal-d)">✓ Envoyé le ' + docsFmtDate(doc.signed_uploaded_at) + '</span> <button class="btn mini ghost" onclick="docsTriggerSignedUpload(' + doc.id + ')">Remplacer</button>'
        : '<button class="btn mini ghost" onclick="docsTriggerSignedUpload(' + doc.id + ')">Envoyer le document signé</button>';
    }
    html += '<tr>' +
      '<td><div class="doc-row-name">' + esc(doc.title) + '</div>' + (doc.subtitle ? '<div class="doc-row-sub">' + esc(doc.subtitle) + '</div>' : '') + '</td>' +
      '<td><span class="doc-status-badge ' + sClass + '">' + sIcon + esc(doc.status_label || STATUS_LABELS_FR[doc.status_type] || doc.status_type) + '</span></td>' +
      '<td>' + actionHtml + '</td>' +
      '<td>' + sendHtml + '</td>' +
    '</tr>';
  });
  html += '</tbody></table>';
  docsBody.innerHTML = html;
}

function docsFmtDate(ts) {
  var d = new Date(ts * 1000);
  var mo = ['janv.','févr.','mars','avr.','mai','juin','juil.','août','sept.','oct.','nov.','déc.'];
  return d.getDate() + ' ' + mo[d.getMonth()];
}

function docsTriggerSignedUpload(docid) {
  var input = document.getElementById('doc-signed-file-input');
  input.dataset.docid = docid;
  input.click();
}

function docsUploadSignedFile(input) {
  var file = input.files[0];
  var docid = input.dataset.docid;
  if (!file || !docid) return;
  var fd = new FormData();
  fd.append('action', 'upload_signed_doc');
  fd.append('sesskey', SESSKEY);
  fd.append('docid', docid);
  fd.append('file', file);
  fetch(window.location.pathname, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(r) {
    input.value = '';
    if (!r.ok) { toast('Erreur : ' + (r.msg || '')); return; }
    toast('Document signé envoyé.');
    renderDocs();
  });
}

/* ── REGLAGES ── */
function renderParams() {
  var d = DATA;
  var cohort = d.cohort;
  var classEl = document.getElementById('params-classe');
  if (cohort) {
    classEl.innerHTML =
      '<div class="param-row"><span class="lbl">Classe</span><span class="val">' + esc(cohort.name) + '</span></div>' +
      '<div class="param-row"><span class="lbl">Sous-groupe</span><span class="val">' + esc(cohort.subgroup_label) + '</span></div>' +
      '<div class="param-row"><span class="lbl">Effectif classe</span><span class="val">' + cohort.nb_members + ' élèves</span></div>' +
      '<div class="param-row"><span class="lbl">Rentrée</span><span class="val">' + esc(cohort.startdate) + '</span></div>';
  } else {
    classEl.innerHTML = '<p style="color:var(--muted);font-size:13px">Aucune cohorte assignee.</p>';
  }
  var statsEl = document.getElementById('params-stats');
  statsEl.innerHTML =
    '<div class="param-row"><span class="lbl">Modules assignés</span><span class="val">' + d.courses.length + '</span></div>' +
    '<div class="param-row"><span class="lbl">E-learning complété</span><span class="val">' + d.global_pct + '%</span></div>' +
    '<div class="param-row"><span class="lbl">Semaine LIA</span><span class="val">S ' + d.current_week + '/' + ACTIVE_FORMATION.totalWeeks + '</span></div>' +
    '<div class="param-row"><span class="lbl">Devoirs en attente</span><span class="val">' + d.pending_assigns.length + '</span></div>';
}

// Init — navigation par hash (liens depuis la sidebar Moodle)
(function(){
  var h = (location.hash || '').replace('#', '');
  var tabs = ['parcours','cours','detail','agenda','docs','params'];
  if (tabs.indexOf(h) >= 0) go(h);
  else go('accueil');
})();
loadHeaderCounts();
</script>
</body>
</html>
