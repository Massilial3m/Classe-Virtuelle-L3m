<?php
// ── Espace Formateur — LBA Académie ───────────────────────────────────────────
// Auth : require_login + rôle editingteacher
// Déploiement : SFTP → /tmp/ → docker cp moodle_app:/var/data/www/public/espace-formateur.php
// ─────────────────────────────────────────────────────────────────────────────
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/lia_engine.php');
require_once(__DIR__ . '/l3m_branding.php');
require_login();
$brand = l3m_get_branding_for_user($USER->id);

// ── Auth : editingteacher uniquement ──
$teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
if (!$teacherroleid || !$DB->record_exists('role_assignments', ['userid' => $USER->id, 'roleid' => $teacherroleid])) {
    redirect(new moodle_url('/'));
}

$sesskey = sesskey();
$now     = time();

// ── TABLE l3m_seen_modules (auto-create) — suivi des activités déjà vues par formateur,
//    pour détecter les nouveaux contenus ajoutés et notifier les élèves ──
try {
    $DB->execute("CREATE TABLE IF NOT EXISTS `{$CFG->prefix}l3m_seen_modules` (
      `id` bigint(10) NOT NULL AUTO_INCREMENT,
      `courseid` bigint(10) NOT NULL,
      `cmid` bigint(10) NOT NULL,
      `timecreated` bigint(10) NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      UNIQUE KEY `course_cm` (`courseid`,`cmid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// ── AJAX : contenu détaillé d'un cours (sections + activités), pour l'aperçu
//    "Ajouter du contenu" — même logique que espace-eleve.php pour un rendu identique ──
if (isset($_GET['action']) && $_GET['action'] === 'course_detail') {
    header('Content-Type: application/json');
    $cid = (int)($_GET['courseid'] ?? 0);
    if (!$cid) { echo json_encode(['ok'=>false,'msg'=>'id manquant']); exit; }
    try {
        $course = $DB->get_record('course', ['id' => $cid], '*', MUST_EXIST);
        $minfo  = get_fast_modinfo($course);
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
        $visible_cmids = [];
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
                $visible_cmids[] = $cmid;
            }
            $total = count($mods);
            $done_n = count(array_filter($mods, function($m){ return $m['done']; }));
            $sections[] = ['num'=>$snum,'name'=>$sinfo->name ?: ('Section '.$snum),
                           'mods'=>$mods,'total'=>$total,'done_n'=>$done_n];
        }

        // ── Détection de nouveau contenu → notifie les élèves inscrits à ce cours ──
        $seen_cmids = $DB->get_fieldset_select('l3m_seen_modules', 'cmid', 'courseid = ?', [$cid]);
        if (!$seen_cmids) {
            // Premier passage sur ce cours : on enregistre l'état actuel sans notifier
            // (sinon tout le contenu existant serait notifié d'un coup au premier chargement).
            foreach ($visible_cmids as $cmid) {
                $DB->insert_record('l3m_seen_modules', (object)['courseid'=>$cid,'cmid'=>$cmid,'timecreated'=>$now]);
            }
        } else {
            $new_cmids = array_diff($visible_cmids, $seen_cmids);
            if ($new_cmids) {
                $course_studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
                $enrolled = $course_studentroleid ? $DB->get_records_sql(
                    "SELECT DISTINCT ra.userid
                     FROM {role_assignments} ra
                     JOIN {context} cx ON cx.id = ra.contextid AND cx.contextlevel = 50
                     WHERE cx.instanceid = :cid AND ra.roleid = :rid",
                    ['cid' => $cid, 'rid' => $course_studentroleid]
                ) : [];
                foreach ($new_cmids as $cmid) {
                    $modname = $minfo->cms[$cmid]->name;
                    foreach ($enrolled as $stu) {
                        l3m_send_notification(
                            $USER->id, (int)$stu->userid, 'Nouvelle séance ajoutée',
                            'Votre formateur a ajouté une nouvelle séance cette semaine : ' . $modname . ' (' . $course->fullname . ')',
                            $CFG->wwwroot . '/espace-eleve.php#cours', 'Voir mes cours'
                        );
                    }
                    $DB->insert_record('l3m_seen_modules', (object)['courseid'=>$cid,'cmid'=>$cmid,'timecreated'=>$now]);
                }
            }
        }

        echo json_encode(['ok'=>true,'sections'=>$sections,
                          'coursename'=>$course->fullname,'courseid'=>$cid,
                          'contextid'=>context_course::instance($cid)->id]);
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── TABLE l3m_dispos (auto-create) ──
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

// ── TABLE l3m_msg_status (auto-create) — statut "traité" propre à L3M sur les conversations ──
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

// ── ACTIONS POST (AJAX) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = optional_param('action', '', PARAM_ALPHANUMEXT);
    header('Content-Type: application/json');

    if ($action === 'save_dispo') {
        $date_from = required_param('date_from', PARAM_TEXT);
        $date_to   = required_param('date_to', PARAM_TEXT);
        $motif     = optional_param('motif', 'autre', PARAM_ALPHA);
        $labels    = ['conge' => 'Congé', 'maladie' => 'Arrêt maladie', 'formation' => 'Formation', 'personnel' => 'Raison personnelle', 'autre' => 'Autre'];
        $rec = new stdClass();
        $rec->userid      = (int)$USER->id;
        $rec->date_from   = $date_from;
        $rec->date_to     = $date_to;
        $rec->motif       = $motif;
        $rec->motif_label = $labels[$motif] ?? $motif;
        $rec->status      = 'pending';
        $rec->admin_note  = '';
        $rec->timecreated = time();
        $rec->timemodified = time();
        $DB->insert_record('l3m_dispos', $rec);
        $dispos = array_values((array)$DB->get_records('l3m_dispos', ['userid' => (int)$USER->id], 'timecreated DESC'));
        echo json_encode(['ok' => true, 'dispos' => $dispos], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete_dispo') {
        $id  = required_param('id', PARAM_INT);
        $row = $DB->get_record('l3m_dispos', ['id' => $id]);
        if ($row && (int)$row->userid === (int)$USER->id && $row->status === 'pending') {
            $DB->delete_records('l3m_dispos', ['id' => $id]);
        }
        $dispos = array_values((array)$DB->get_records('l3m_dispos', ['userid' => (int)$USER->id], 'timecreated DESC'));
        echo json_encode(['ok' => true, 'dispos' => $dispos], JSON_UNESCAPED_UNICODE);
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

// ── Infos formateur ──
$formateur_info = [
    'id'        => (int)$USER->id,
    'firstname' => $USER->firstname,
    'lastname'  => $USER->lastname,
    'email'     => $USER->email,
    'initials'  => mb_substr($USER->firstname, 0, 1) . mb_substr($USER->lastname, 0, 1),
    'nom'       => $USER->firstname . ' ' . $USER->lastname,
];

// ── Formateur meta (habs, type, role_label) ──
$all_meta = json_decode(get_config('local_l3madmin', 'formateur_meta') ?: '{}', true) ?: [];
$my_meta  = $all_meta[$USER->id] ?? [];
$f_type   = $my_meta['type'] ?? 'salarie';

// ── Paramètres ECSR (partagés avec admin-lba.php) ──
$ecsr_params = array_merge([
    'salarie_h'              => 28,
    'vacataire_h'             => 20,
    'regroupements_vehicule'  => 'S7 · S13 · S19',
    'regroupements_salle'     => 'S25 · S31',
], json_decode(get_config('local_l3madmin', 'ecsr_params') ?: '{}', true) ?: []);
$capacity = ($f_type === 'vacataire') ? (int)$ecsr_params['vacataire_h'] : (int)$ecsr_params['salarie_h'];

preg_match_all('/\d+/', $ecsr_params['regroupements_vehicule'], $veh_matches);
preg_match_all('/\d+/', $ecsr_params['regroupements_salle'], $salle_matches);
$veh_weeks   = array_map('intval', $veh_matches[0]);
$salle_weeks = array_map('intval', $salle_matches[0]);
lia_week_config($veh_weeks ?: [7, 13, 19], $salle_weeks ?: [25, 31]);

// ── Moteur Lia (calcul serveur, source unique avec admin-lba.php) ──
// Le calcul de délégation/arbitrage a besoin de voir toute l'équipe et toutes
// les classes (pas seulement les cours de ce formateur) pour être correct.
$lia_all_formateurs_raw = $DB->get_records_sql(
    "SELECT u.id, u.firstname, u.lastname
     FROM {user} u
     JOIN {role_assignments} ra ON ra.userid = u.id
     JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
     WHERE ra.roleid = :rid AND u.deleted = 0
     GROUP BY u.id, u.firstname, u.lastname",
    ['rid' => (int)$teacherroleid]
);
$lia_all_formateurs = [];
foreach ($lia_all_formateurs_raw as $f) {
    $fmeta = $all_meta[(int)$f->id] ?? [];
    $ftype = $fmeta['type'] ?? 'salarie';
    $lia_all_formateurs[] = [
        'id'         => (int)$f->id,
        'nom'        => $f->firstname . ' ' . $f->lastname,
        'role_label' => $fmeta['role_label'] ?? '',
        'type'       => $ftype,
        'habs'       => $fmeta['habs'] ?? [],
        'capacity'   => $ftype === 'vacataire' ? (int)$ecsr_params['vacataire_h'] : (int)$ecsr_params['salarie_h'],
    ];
}

$lia_all_cohorts_raw = $DB->get_records_sql(
    "SELECT c.id, c.name, c.idnumber AS startdate, COUNT(DISTINCT cm.userid) AS nb_members
     FROM {cohort} c
     LEFT JOIN {cohort_members} cm ON cm.cohortid = c.id
     WHERE c.idnumber IS NULL OR c.idnumber NOT IN (:clba, :csaran)
     GROUP BY c.id, c.name, c.idnumber",
    ['clba' => L3M_CLIENT_COHORT_IDNUMBER_LBA, 'csaran' => L3M_CLIENT_COHORT_IDNUMBER_SARAN]
);
$lia_all_classes = [];
foreach ($lia_all_cohorts_raw as $coh) {
    $lia_all_classes[] = ['name' => $coh->name, 'startdate' => $coh->startdate ?? '', 'nb_members' => (int)$coh->nb_members];
}

$lia_people  = lia_build_people($lia_all_formateurs);
$lia_prof2p  = lia_build_prof2p($lia_all_formateurs);
$lia_backups = lia_build_backups($lia_prof2p, $lia_people);
$lia_cohorts = lia_build_cohorts($lia_all_classes);

// ── Indisponibilités validées → capacité effective par semaine ──
$lia_ref_start   = lia_earliest_start($lia_all_classes);
$lia_total_w     = lia_total_weeks($lia_cohorts);
$validated_rows  = $DB->get_records('l3m_dispos', ['status' => 'validated']);
$lia_unavailable = lia_build_unavailable($validated_rows, $lia_ref_start, $lia_total_w);

$lia_plan = ($lia_cohorts && $lia_people)
    ? lia_compute_plan($lia_cohorts, $lia_people, $lia_prof2p, $lia_backups, $lia_unavailable)
    : null;

$my_id         = (int)$USER->id;
$my_grid       = $lia_plan['grid'][$my_id] ?? [];
$my_week_hours = [];
if ($lia_plan) {
    for ($w = 1; $w <= $lia_plan['totalW']; $w++) {
        $my_week_hours[] = lia_week_total($lia_plan['grid'], $my_id, $w);
    }
}
$my_log    = $lia_plan ? array_values(array_filter($lia_plan['log'],    fn($l) => ($l['pid'] ?? null) === $my_id)) : [];
$my_alerts = $lia_plan ? array_values(array_filter($lia_plan['alerts'], fn($a) => ($a['pid'] ?? null) === $my_id)) : [];

// ── Mes déclarations d'indisponibilité ──
$my_dispos = array_values((array)$DB->get_records('l3m_dispos', ['userid' => $my_id], 'timecreated DESC'));

// ── Ses cours ──
$my_courses = [];
try {
    $my_courses = $DB->get_records_sql(
        "SELECT DISTINCT c.id, c.fullname, c.shortname
         FROM {course} c
         JOIN {context} cx ON cx.instanceid = c.id AND cx.contextlevel = 50
         JOIN {role_assignments} ra ON ra.contextid = cx.id
         WHERE ra.userid = :uid AND ra.roleid = :rid AND c.id > 1
         ORDER BY c.id",
        ['uid' => $USER->id, 'rid' => $teacherroleid]
    );
} catch (Exception $e) {
    $my_courses = [];
}
$course_ids = array_keys((array)$my_courses);

// ── Date de référence semaine ──
$monday = mktime(0, 0, 0, (int)date('n'), (int)date('j') - (int)date('N') + 1);
$sunday = $monday + 7 * 86400;

// ── Cohortes du formateur (source Planning & Lia) ──
// Les séances sont calculées en JS depuis startdate + LIA_BLOCS
$formateur_cohorts = []; // rempli dans la section Planning ci-dessous

// ── Corrections en attente ──
$corrections = [];
if ($course_ids) {
    try {
        list($in_sql_corr, $in_params_corr) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'corrcid');
        $corr_raw = $DB->get_records_sql(
            "SELECT s.id, s.userid, u.firstname, u.lastname, a.name AS assign_name, a.course,
                    s.timemodified, a.id AS assignid
             FROM {assign_submission} s
             JOIN {assign} a ON a.id = s.assignment
             JOIN {user} u ON u.id = s.userid
             LEFT JOIN {assign_grades} g ON g.assignment = s.assignment AND g.userid = s.userid AND g.grade >= 0
             WHERE a.course $in_sql_corr AND s.status = 'submitted' AND g.id IS NULL
             ORDER BY s.timemodified ASC LIMIT 20",
            $in_params_corr
        );
        foreach ($corr_raw as $c) {
            $corrections[] = [
                'id'           => (int)$c->id,
                'userid'       => (int)$c->userid,
                'firstname'    => $c->firstname,
                'lastname'     => $c->lastname,
                'assign_name'  => $c->assign_name,
                'course'       => (int)$c->course,
                'assignid'     => (int)$c->assignid,
                'timemodified' => (int)$c->timemodified,
                'date_label'   => userdate($c->timemodified, '%d/%m/%Y'),
            ];
        }
    } catch (Exception $e) {
        $corrections = [];
    }
}

// ── Élèves (rôle student) dans les cours du formateur ──
$student_roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
$students       = [];
$signal_lia     = [];

if ($course_ids && $student_roleid) {
    try {
        list($in_sql_st, $in_params_st) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'stcid');

        $students_raw = $DB->get_records_sql(
            "SELECT DISTINCT ra.userid AS id, u.firstname, u.lastname, u.lastaccess, u.email
             FROM {role_assignments} ra
             JOIN {context} cx ON cx.id = ra.contextid AND cx.contextlevel = 50
             JOIN {user} u ON u.id = ra.userid
             WHERE cx.instanceid $in_sql_st AND ra.roleid = :srid AND u.deleted = 0
             ORDER BY u.lastname, u.firstname",
            array_merge($in_params_st, ['srid' => $student_roleid])
        );

        // ── Complétion globale (batch) ──
        $completion_map = [];
        if ($students_raw) {
            try {
                $comp_data = $DB->get_records_sql(
                    "SELECT u.id,
                            COUNT(DISTINCT cm.id) AS total_mods,
                            SUM(CASE WHEN cmc.completionstate >= 1 THEN 1 ELSE 0 END) AS done_mods
                     FROM {user} u
                     JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = 0
                     JOIN {enrol} e ON e.id = ue.enrolid
                     JOIN {role_assignments} ra ON ra.userid = u.id
                     JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
                     JOIN {course_modules} cm ON cm.course = e.courseid AND cm.completion > 0
                     LEFT JOIN {course_modules_completion} cmc ON cmc.coursemoduleid = cm.id AND cmc.userid = u.id
                     WHERE ra.roleid = :rid AND u.deleted = 0 AND e.courseid $in_sql_st
                     GROUP BY u.id",
                    array_merge(['rid' => $student_roleid], $in_params_st)
                );
                foreach ($comp_data as $cd) {
                    $completion_map[$cd->id] = $cd->total_mods > 0
                        ? min(100, (int)round($cd->done_mods / $cd->total_mods * 100))
                        : 0;
                }
            } catch (Exception $e) {
                $completion_map = [];
            }
        }

        // ── Cohortes (classe) des élèves ──
        $cohort_map = [];
        $student_ids = array_keys((array)$students_raw);
        if ($student_ids) {
            try {
                list($in_sql_coh, $in_params_coh) = $DB->get_in_or_equal($student_ids, SQL_PARAMS_NAMED, 'cohuid');
                $coh_rows = $DB->get_records_sql(
                    "SELECT cm.userid, c.name AS cohname
                     FROM {cohort_members} cm
                     JOIN {cohort} c ON c.id = cm.cohortid
                     WHERE cm.userid $in_sql_coh
                       AND (c.idnumber IS NULL OR c.idnumber NOT IN (:clba, :csaran))",
                    array_merge($in_params_coh, ['clba' => L3M_CLIENT_COHORT_IDNUMBER_LBA, 'csaran' => L3M_CLIENT_COHORT_IDNUMBER_SARAN])
                );
                foreach ($coh_rows as $row) {
                    if (!isset($cohort_map[$row->userid])) {
                        $cohort_map[$row->userid] = $row->cohname;
                    }
                }
            } catch (Exception $e) {
                $cohort_map = [];
            }
        }

        // ── Construire les élèves + Signal Lia ──
        foreach ($students_raw as $s) {
            $pct        = $completion_map[$s->id] ?? 0;
            $days_since = $s->lastaccess > 0 ? (int)floor(($now - $s->lastaccess) / 86400) : 999;
            $inactive   = ($days_since > 7);
            $students[] = [
                'id'          => (int)$s->id,
                'firstname'   => $s->firstname,
                'lastname'    => $s->lastname,
                'email'       => $s->email,
                'cohort_name' => $cohort_map[$s->id] ?? null,
                'pct'         => $pct,
                'last_access' => $s->lastaccess > 0 ? userdate($s->lastaccess, '%d/%m/%Y') : '—',
                'days_since'  => $days_since < 999 ? $days_since : null,
                'inactive'    => $inactive,
            ];
            if ($inactive) {
                $signal_lia[] = [
                    'id'     => (int)$s->id,
                    'nom'    => $s->firstname . ' ' . $s->lastname,
                    'days'   => $days_since < 999 ? $days_since : '?',
                    'cohort' => $cohort_map[$s->id] ?? '',
                    'pct'    => $pct,
                ];
            }
        }
    } catch (Exception $e) {
        $students = [];
    }
}

// ── Heures cette semaine (sessions depuis logs) ──
$hours_this_week = 0;
try {
    $log_ts = $DB->get_fieldset_sql(
        "SELECT timecreated FROM {logstore_standard_log}
         WHERE userid = :uid AND courseid > 0 AND timecreated >= :week_mon
         ORDER BY timecreated ASC",
        ['uid' => $USER->id, 'week_mon' => $monday]
    );
    $gap_secs = 1800; // 30 min → nouvelle session
    $secs = 0;
    for ($i = 1; $i < count($log_ts); $i++) {
        $diff = (int)$log_ts[$i] - (int)$log_ts[$i - 1];
        if ($diff > 0 && $diff <= $gap_secs) {
            $secs += $diff;
        }
    }
    $hours_this_week = round($secs / 3600, 1);
} catch (Exception $e) {
    $hours_this_week = 0;
}

// ── Planning charge — events par semaine sur 35 semaines ──
$planning_charge  = [];
$current_week_rel = 0;
$ref_start        = $monday; // fallback : lundi courant

try {
    // Cohortes liées aux cours du formateur (startdate = cohort.idnumber)
    if ($course_ids && $student_roleid) {
        list($in_sql_plan, $in_params_plan) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'plancid');
        $cohort_dates = $DB->get_records_sql(
            "SELECT DISTINCT c.id, c.name, c.idnumber AS startdate,
                    COUNT(DISTINCT cm.userid) AS nb_members
             FROM {cohort} c
             JOIN {cohort_members} cm ON cm.cohortid = c.id
             JOIN {role_assignments} ra ON ra.userid = cm.userid
             JOIN {context} cx ON cx.id = ra.contextid AND cx.contextlevel = 50
             WHERE cx.instanceid $in_sql_plan AND ra.roleid = :srid
               AND c.idnumber IS NOT NULL AND c.idnumber != ''
               AND c.idnumber NOT IN (:clba, :csaran)
             GROUP BY c.id, c.name, c.idnumber
             ORDER BY c.idnumber ASC",
            array_merge($in_params_plan, ['srid' => $student_roleid, 'clba' => L3M_CLIENT_COHORT_IDNUMBER_LBA, 'csaran' => L3M_CLIENT_COHORT_IDNUMBER_SARAN])
        );
        $earliest_ts = null;
        foreach ($cohort_dates as $cd) {
            if (empty($cd->startdate)) continue;
            $date_str = strlen($cd->startdate) === 7 ? $cd->startdate . '-01' : $cd->startdate;
            $ts = strtotime($date_str);
            if ($ts && ($earliest_ts === null || $ts < $earliest_ts)) {
                $earliest_ts = $ts;
            }
            $formateur_cohorts[] = [
                'id'         => (int)$cd->id,
                'name'       => $cd->name,
                'startdate'  => $cd->startdate,
                'nb_members' => (int)$cd->nb_members,
            ];
        }
        if ($earliest_ts) {
            $ref_start = $earliest_ts;
        }
    }

    // Calculer semaine courante relative à ref_start
    $diff_w = ($now - $ref_start) / (7 * 86400);
    $current_week_rel = max(1, min(35, (int)floor($diff_w) + 1));

    // Récupérer tous les events des cours du formateur dans la fenêtre 35 sem
    $plan_end = $ref_start + 35 * 7 * 86400;
    $weeks_h  = array_fill(0, 35, 0.0);

    if ($course_ids) {
        list($in_sql_plan2, $in_params_plan2) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'plan2cid');
        $plan_events = $DB->get_records_sql(
            "SELECT e.timestart, e.timeduration
             FROM {event} e
             WHERE e.courseid $in_sql_plan2
               AND e.timestart >= :pstart AND e.timestart < :pend
               AND e.visible = 1",
            array_merge($in_params_plan2, ['pstart' => $ref_start, 'pend' => $plan_end])
        );
        foreach ($plan_events as $pe) {
            $w = (int)floor(($pe->timestart - $ref_start) / (7 * 86400));
            if ($w >= 0 && $w < 35) {
                $weeks_h[$w] += $pe->timeduration > 0 ? round($pe->timeduration / 3600, 1) : 1;
            }
        }
    }

    for ($i = 0; $i < 35; $i++) {
        $planning_charge[] = ['w' => $i + 1, 'h_events' => round($weeks_h[$i], 1)];
    }
} catch (Exception $e) {
    $planning_charge = [];
}

// ── Présentiels à venir (semaines véhicule/salle, depuis la semaine courante) ──
$presentiels_label = '';
if ($lia_plan && $lia_ref_start) {
    $upcoming = [];
    for ($w = max(1, $current_week_rel); $w <= $lia_plan['totalW'] && count($upcoming) < 2; $w++) {
        foreach (($my_grid[$w] ?? []) as $e) {
            if ($e->type === 'veh' || $e->type === 'salle') {
                $wStart = $lia_ref_start + ($w - 1) * 7 * 86400;
                $upcoming[] = [
                    'label' => $e->type === 'veh' ? 'Sem. véhicule' : 'Regroupement salle',
                    'date'  => userdate($wStart, '%d %b'),
                    'coh'   => $e->coh,
                ];
            }
        }
    }
    if ($upcoming) {
        $parts = array_map(fn($u) => $u['date'] . ' (' . $u['coh'] . ')', $upcoming);
        $presentiels_label = $upcoming[0]['label'] . ' : ' . implode(' · ', $parts);
    }
}

// ── Séances semaine courante (mdl_event) ──
$week_events = [];
if ($course_ids) {
    try {
        $days_fr = ['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'];
        list($in_sql_we, $in_params_we) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'wecid');
        $week_ev_raw = $DB->get_records_sql(
            "SELECT e.id, e.name, e.timestart, e.timeduration, e.courseid, e.eventtype,
                    c.fullname AS course_name, c.shortname AS course_short
             FROM {event} e
             JOIN {course} c ON c.id = e.courseid
             WHERE e.courseid $in_sql_we AND e.visible = 1
               AND e.timestart >= :wstart AND e.timestart < :wend
               AND e.eventtype NOT IN ('due','gradingdue','open','close')
             ORDER BY e.timestart ASC",
            array_merge(['wstart' => $monday, 'wend' => $sunday], $in_params_we)
        );
        foreach ($week_ev_raw as $ev) {
            $week_events[] = [
                'id'           => (int)$ev->id,
                'name'         => $ev->name,
                'timestart'    => (int)$ev->timestart,
                'day_label'    => $days_fr[(int)date('w', (int)$ev->timestart)],
                'hour_label'   => date('H:i', (int)$ev->timestart),
                'date_num'     => (int)date('j', (int)$ev->timestart),
                'duration_h'   => $ev->timeduration > 0 ? round($ev->timeduration / 3600, 1) : 2.0,
                'courseid'     => (int)$ev->courseid,
                'course_name'  => $ev->course_name,
                'course_short' => $ev->course_short,
            ];
        }
    } catch (Exception $e) { $week_events = []; }
}

// ── Correspondance cours -> activité LTI "Classe virtuelle Glowbl" (SSO) ──
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

// ── Label semaine ──
$week_label = userdate($monday, '%d %B %Y');

// ── JSON DATA → window.DATA ──
$formateur_data = json_encode([
    'formateur'    => $formateur_info,
    'meta'         => [
        'role_label' => $my_meta['role_label'] ?? '',
        'type'       => $f_type,
        'capacity'   => $capacity,
        'habs'       => $my_meta['habs'] ?? [],
    ],
    'courses'      => array_values(array_map(function($c) {
        return ['id' => (int)$c->id, 'fullname' => $c->fullname, 'shortname' => $c->shortname];
    }, (array)$my_courses)),
    'cohorts'      => $formateur_cohorts,
    'corrections'  => $corrections,
    'students'     => $students,
    'signal_lia'   => $signal_lia,
    'hours_week'   => $hours_this_week,
    'planning'     => $planning_charge,
    'current_week' => $current_week_rel,
    'veh_weeks'    => $veh_weeks,
    'salle_weeks'  => $salle_weeks,
    'week_events'  => $week_events,
    'glowbl_lti_map' => $glowbl_lti_map,
    'week_label'   => $week_label,
    'date_label'   => userdate($now, '%A %d %B %Y'),
    'my_week_hours' => $my_week_hours,
    'my_grid'       => $my_grid,
    'my_log'        => $my_log,
    'my_alerts'     => $my_alerts,
    'my_dispos'         => $my_dispos,
    'presentiels_label' => $presentiels_label,
], JSON_UNESCAPED_UNICODE);

$user_initials = mb_substr($USER->firstname, 0, 1) . mb_substr($USER->lastname, 0, 1);
$glowbl_url    = 'https://l3m.glowbl.com/l3m-ecsr';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Espace Formateur — <?= s($brand['name']) ?></title>
<style>
:root {
  --teal:<?= $brand['teal'] ?>; --teal-d:<?= $brand['teal_d'] ?>; --teal-t:<?= $brand['teal_t'] ?>;
  --orange:<?= $brand['orange'] ?>; --orange-d:<?= $brand['orange_d'] ?>; --orange-t:<?= $brand['orange_t'] ?>;
  --violet:#7C6FDD; --violet-d:#4A3FA3; --violet-t:#ECEAFA;
  --red:#D64545; --red-t:#FBE7E7;
  --amber:#F6C685; --amber-d:#8a5a10;
  --sand:#B9B29E; --sand-t:#EFECE1;
  --ink:#24333C; --muted:#68767E;
  --bg:#F7F6F1; --card:#FFFFFF; --line:#E4E1D6; --dark:#33424B;
}
*, *::before, *::after { box-sizing:border-box; }
body { margin:0; font-family:"Segoe UI",system-ui,sans-serif; background:var(--bg); color:var(--ink); font-size:14.5px; line-height:1.5; }

/* ── Topbar ── */
.top { display:none; }
.top.scrolled { border-bottom-color:var(--line); box-shadow:0 1px 3px rgba(36,51,60,.06); }
.brand { display:flex; align-items:center; gap:9px; font-weight:800; letter-spacing:.04em; font-size:15px; color:var(--teal-d); white-space:nowrap; text-decoration:none; }
.brand em { font-style:normal; color:var(--orange); font-weight:400; font-size:17px; font-family:Georgia,serif; }
.brand small { font-weight:500; color:var(--muted); letter-spacing:0; margin-left:2px; }
.top-date { flex:1; text-align:center; font-size:12.5px; color:var(--muted); }
.top-date b { color:var(--ink); }
.top-user { display:flex; align-items:center; gap:8px; font-size:12.5px; font-weight:600; }

/* ── Layout ── */
.app { display:flex; min-height:100vh; }
aside { flex:0 0 76px; background:var(--teal-d); padding:0 6px 12px; display:flex; flex-direction:column; gap:2px; position:sticky; top:0; height:100vh; overflow:visible; z-index:50; }
aside .nav-brand { padding:12px 4px 10px; text-align:center; border-bottom:1px solid rgba(255,255,255,.15); margin-bottom:6px; }
aside .nav-brand svg { display:block; margin:0 auto 3px; }
aside .nb-label { display:block; font-size:7.5px; font-weight:800; color:rgba(255,255,255,.5); letter-spacing:.14em; text-transform:uppercase; }
aside .cls-chip { display:block; margin:5px auto 0; background:rgba(255,255,255,.18); color:rgba(255,255,255,.9); font-size:8.5px; font-weight:800; border-radius:6px; padding:2px 6px; letter-spacing:.05em; max-width:62px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; text-align:center; }
aside button { appearance:none; border:none; background:none; padding:9px 4px 7px; border-radius:10px; font-size:8.5px; font-weight:700; color:rgba(255,255,255,.55); cursor:pointer; font-family:inherit; display:flex; flex-direction:column; align-items:center; gap:3px; width:100%; transition:background .12s,color .12s; position:relative; letter-spacing:.03em; text-transform:uppercase; }
aside button .nav-ico { font-size:16px; line-height:1.2; }
aside button.on { background:rgba(255,255,255,.18); color:#fff; border-radius:10px; }
aside button:hover:not(.on) { background:rgba(255,255,255,.1); color:rgba(255,255,255,.85); }
.nav-badge { position:absolute; right:8px; top:6px; background:var(--red); color:#fff; font-size:9px; font-weight:800; border-radius:99px; padding:1px 5px; min-width:15px; text-align:center; line-height:1.6; }
aside .sep { flex:1; }
aside .nav-av { margin:8px auto 2px; width:36px; height:36px; border-radius:99px; background:rgba(255,255,255,.2); display:flex; align-items:center; justify-content:center; color:#fff; font-weight:800; font-size:12px; border:2px solid rgba(255,255,255,.35); flex-shrink:0; }
aside .nav-av-lbl { font-size:7px; color:rgba(255,255,255,.45); text-align:center; font-weight:600; letter-spacing:.04em; }
.nav-user-wrap { position:relative; padding-bottom:12px; }
.nav-av-btn { appearance:none; border:none; background:none; padding:0; margin:0; width:100%; display:flex; flex-direction:column; align-items:center; cursor:pointer; font-family:inherit; }
.nav-user-menu { display:none; position:absolute; left:calc(100% + 8px); bottom:0; background:var(--card); border:1px solid var(--line); border-radius:10px; box-shadow:0 8px 24px rgba(0,0,0,.18); padding:6px; min-width:150px; z-index:80; }
.nav-user-menu.open { display:block; }
.nav-user-menu-item { display:block; padding:8px 12px; border-radius:6px; font-size:12.5px; font-weight:600; color:var(--ink); text-decoration:none; white-space:nowrap; }
.nav-user-menu-item:hover { background:var(--bg); }
.nav-user-menu-item.danger { color:var(--red); }
main { flex:1; padding:26px 30px 80px; min-width:0; overflow-x:hidden; }

/* ── KPI chip / action ── */
.kpi-chip { display:inline-flex; align-items:center; background:rgba(255,255,255,.65); border:1px solid rgba(255,255,255,.85); border-radius:99px; padding:2px 9px; font-size:10.5px; font-weight:700; color:var(--ink); white-space:nowrap; max-width:100%; overflow:hidden; text-overflow:ellipsis; }
.kpi-chip.chip-orange { background:rgba(242,160,61,.18); border-color:rgba(242,160,61,.4); color:#7A4B0E; }
.kpi-chip.chip-red { background:rgba(214,69,69,.12); border-color:rgba(214,69,69,.25); color:var(--red); }
.kpi-action { display:inline-flex; align-items:center; gap:4px; background:var(--teal-d); color:#fff; border-radius:99px; padding:4px 13px; font-size:10.5px; font-weight:700; cursor:pointer; border:none; font-family:inherit; margin-top:7px; transition:filter .12s; }
.kpi-action:hover { filter:brightness(1.1); }
.kpi-action-ghost { background:transparent; color:var(--teal-d); border:1.5px solid var(--teal-d); }

/* ── Signal à traiter ── */
.sig-card { background:var(--bg); border:1px solid var(--line); border-radius:11px; padding:12px 13px; margin-bottom:7px; }
.sig-card:last-child { margin-bottom:0; }
.sig-card.sig-orange { background:#FBF0E4; border-color:#F2C99A; }
.sig-sec-title { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--orange-d,#9A5E13); margin:0 0 9px; }
.sig-student { font-size:13.5px; font-weight:700; color:var(--ink); }
.sig-detail { font-size:12px; color:var(--muted); margin-top:3px; line-height:1.4; }
.sig-acts { display:flex; gap:6px; margin-top:9px; flex-wrap:wrap; }
.sig-tags { display:flex; gap:5px; flex-wrap:wrap; margin-top:5px; }
.sig-tag { font-size:11px; font-weight:700; border-radius:99px; padding:2px 8px; border:1px solid transparent; }
.sig-tag.t-red    { background:#FDE8E8; color:#B91C1C; border-color:#FBBABA; }
.sig-tag.t-orange { background:#FBF0E4; color:#9A5E13; border-color:#F2C99A; }
.sig-tag.t-blue   { background:#E8F0FE; color:#1A56A5; border-color:#BFCFEE; }

/* ── Semaine véhicule ── */
.veh-card { border-radius:10px; overflow:hidden; margin-bottom:8px; }
.veh-card:last-child { margin-bottom:0; }
.veh-header { background:var(--orange,#F2A03D); color:#fff; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.07em; padding:7px 13px; }
.veh-body { font-size:13px; color:var(--ink); margin:0; padding:10px 13px 4px; background:#FBF0E4; border:1px solid #F2C99A; border-top:none; line-height:1.5; }
.veh-groups { display:flex; flex-wrap:wrap; gap:5px; padding:0 13px 8px; background:#FBF0E4; border:1px solid #F2C99A; border-top:none; }
.veh-grp-chip { background:#fff; border:1.5px solid #F2A03D; color:#9A5E13; border-radius:99px; font-size:11px; font-weight:700; padding:2px 9px; }
.veh-acts { padding:8px 13px 10px; background:#FBF0E4; border:1px solid #F2C99A; border-top:none; border-radius:0 0 10px 10px; display:flex; gap:7px; }
.view { display:none; }
.view.on { display:block; }

/* ── Avatar ── */
.av { display:flex; align-items:center; justify-content:center; border-radius:99px; color:#fff; font-weight:800; flex-shrink:0; }
.av-sm { width:28px; height:28px; font-size:12px; }

/* ── Typography ── */
h1 { font-size:20px; font-weight:800; margin:0 0 4px; }
.sub { color:var(--muted); font-size:13px; margin:0 0 18px; }

/* ── KPIs ── */
.kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin-bottom:20px; }
.kpi { border-radius:16px; padding:18px 20px 20px; min-height:110px; display:flex; flex-direction:column; justify-content:space-between; border:1px solid transparent; transition:transform .15s,box-shadow .15s; }
.kpi:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(36,51,60,.08); }
.kpi-head { display:flex; justify-content:space-between; align-items:flex-start; }
.kpi-title { font-size:12px; font-weight:700; opacity:.7; }
.kpi-main { display:flex; justify-content:space-between; align-items:flex-end; margin-top:10px; gap:10px; }
.kpi-num { font-size:34px; font-weight:800; line-height:1; letter-spacing:-.03em; font-variant-numeric:tabular-nums; }
.kpi-num small { font-size:16px; opacity:.55; margin-left:2px; }
.kpi-sub { font-size:11px; opacity:.55; font-weight:500; margin-top:8px; }
.k-orange { background:#F9C57D; border-color:#F5B15F; }
.k-mint   { background:#C7EDE1; border-color:#A7DDCB; }
.k-violet { background:#DDD9F7; border-color:#C9C3F0; }
.k-cream  { background:var(--card); border-color:var(--line); }
.k-danger { background:var(--red-t); border-color:#F5C4C4; }
.k-danger .kpi-num { color:var(--red); }

/* ── Grid 2 cols ── */
.cols2 { display:grid; grid-template-columns:1.1fr .9fr; gap:14px; }
@media(max-width:900px) { .cols2 { grid-template-columns:1fr; } }
.col-stack { display:flex; flex-direction:column; gap:14px; }

/* ── Panel ── */
.panel { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:16px 18px; }
.panel h3 { margin:0 0 12px; font-size:11px; font-weight:700; color:var(--teal-d); text-transform:uppercase; letter-spacing:.07em; }

/* ── Events / séances ── */
.event { display:flex; gap:10px; padding:9px 0; border-bottom:1px solid var(--line); font-size:13px; align-items:center; flex-wrap:wrap; }
.event:last-child { border:none; }
.lw { flex:0 0 100px; font-weight:700; color:var(--teal-d); font-size:12px; }
.event .info { flex:1; min-width:0; }
.event .acts { display:flex; gap:6px; align-items:center; flex-shrink:0; }

/* ── Chips ── */
.chip { display:inline-block; font-size:10px; font-weight:700; border-radius:99px; padding:2px 8px; white-space:nowrap; vertical-align:1px; }
.c-today { background:var(--violet-t); color:var(--violet-d); border:1px solid var(--violet); }
.c-ko { background:var(--red-t); color:var(--red); }

/* ── Buttons ── */
.btn { display:inline-flex; align-items:center; gap:5px; border:none; border-radius:99px; padding:7px 16px; font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; text-decoration:none; transition:filter .12s; white-space:nowrap; }
.btn:hover { filter:brightness(.92); }
.btn.primary { background:var(--teal); color:#fff; }
.btn.ghost { background:var(--card); color:var(--teal-d); border:1.5px solid var(--teal); }
.btn.mini { padding:5px 12px; font-size:11px; }
.btn.orange-btn { background:var(--orange); color:#fff; }

/* ── Signal Lia ── */
.lia-item { display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid var(--line); font-size:13px; gap:8px; }
.lia-item:last-child { border:none; }
.lia-nom { font-weight:600; }
.lia-days { font-size:11.5px; color:var(--red); font-weight:700; white-space:nowrap; }

/* ── Accès rapides ── */
.quick-links { display:flex; flex-direction:column; gap:6px; }
.quick-link { display:flex; align-items:center; gap:8px; padding:8px 10px; border:1px solid var(--line); border-radius:10px; background:var(--bg); text-decoration:none; color:var(--ink); font-size:12.5px; font-weight:600; transition:background .12s,border-color .12s; }
.quick-link:hover { background:var(--teal-t); border-color:var(--teal); color:var(--teal-d); }

/* ── Planning frise ── */
.plan-legend { display:flex; gap:12px; flex-wrap:wrap; font-size:11px; color:var(--muted); margin-bottom:10px; align-items:center; }
.plan-sw { display:inline-block; width:10px; height:10px; border-radius:3px; margin-right:3px; vertical-align:-1px; }
.strip-wrap { overflow-x:auto; }
.strip-inner { display:flex; gap:3px; align-items:flex-end; padding:10px 0 0; min-width:560px; min-height:160px; }
.strip-bar { flex:1; border-radius:4px 4px 0 0; min-width:10px; cursor:default; transition:filter .12s; }
.strip-bar:hover { filter:brightness(.85); }
.strip-bar.cur-week { outline:2.5px solid var(--violet); outline-offset:2px; }
.strip-lbl { display:flex; gap:3px; min-width:560px; padding:4px 0 2px; }
.strip-lbl span { flex:1; font-size:8.5px; color:var(--muted); text-align:center; font-variant-numeric:tabular-nums; }
.strip-lbl span.cur-lbl { color:var(--violet); font-weight:800; }
.strip-nums { display:flex; gap:3px; min-width:560px; padding:0 0 3px; }
.strip-nums span { flex:1; font-size:9px; font-weight:700; color:var(--ink); text-align:center; min-width:10px; font-variant-numeric:tabular-nums; }
.strip-nums span.pn-empty { color:transparent; }
.plan-eyebrow { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--muted); margin:0 0 4px; }
.plan-sec-title { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--muted); margin:0 0 10px; }
.plan-alert { background:#FDE8E8; border:1px solid #FBBABA; border-radius:8px; padding:10px 14px; font-size:12.5px; color:#B91C1C; margin-top:12px; }
.plan-session { display:flex; align-items:center; gap:10px; padding:9px 0; border-bottom:1px solid var(--line); }
.plan-session:last-child { border-bottom:none; }
.plan-session-name { flex:1; font-size:13.5px; color:var(--ink); }
.plan-session-cls { color:var(--muted); font-size:12px; font-weight:400; }
.plan-session-h { color:var(--teal-d); font-weight:700; font-size:12.5px; white-space:nowrap; }
.visio-tag { background:var(--teal-t,#E3F2EF); color:var(--teal-d); border:1px solid var(--teal); border-radius:99px; font-size:10.5px; font-weight:700; padding:2px 9px; white-space:nowrap; }
.veh-tag { background:var(--orange-t,#FBEDDA); color:var(--orange-d,#9A5E13); border:1px solid var(--orange); border-radius:99px; font-size:10.5px; font-weight:700; padding:2px 9px; white-space:nowrap; }
.salle-tag { background:#FBF3E3; color:var(--amber-d,#8a5a10); border:1px solid var(--amber,#F6C685); border-radius:99px; font-size:10.5px; font-weight:700; padding:2px 9px; white-space:nowrap; }

/* ── Corrections ── */
.cor { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:14px 16px; margin-bottom:10px; }
.cor-name { font-weight:700; font-size:13.5px; }
.cor-assign { color:var(--muted); font-size:12.5px; margin-top:2px; }
.cor-date { font-size:11.5px; color:var(--muted); margin-top:4px; }
.cor-acts { display:flex; gap:8px; align-items:center; margin-top:10px; flex-wrap:wrap; }
.okbox { background:var(--teal-t); border:1px solid var(--teal); border-radius:12px; padding:20px 24px; text-align:center; font-size:14px; font-weight:700; color:var(--teal-d); }

/* ── Table élèves ── */
.filter-bar { display:flex; gap:8px; align-items:center; margin-bottom:12px; flex-wrap:wrap; }
.filter-lbl { font-size:12px; color:var(--muted); font-weight:600; }
.filter-btn { border:1.5px solid var(--line); background:var(--card); border-radius:99px; padding:5px 13px; font-size:12px; font-weight:600; cursor:pointer; font-family:inherit; color:var(--muted); transition:all .12s; }
.filter-btn.on { border-color:var(--teal); background:var(--teal-t); color:var(--teal-d); }
.twrap { overflow-x:auto; border:1px solid var(--line); border-radius:14px; margin-bottom:14px; }
table { border-collapse:collapse; width:100%; font-size:12.5px; background:var(--card); }
th, td { padding:9px 11px; border-bottom:1px solid var(--line); text-align:left; white-space:nowrap; }
th { font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); background:var(--bg); font-weight:700; }
tr:last-child td { border:none; }
tr.row-inactive td { background:rgba(214,69,69,.04); }
tr.row-highlight td { background:var(--teal-t,#E3F2EF) !important; transition:background .3s; }
.bar { height:6px; border-radius:3px; background:var(--line); width:80px; display:inline-block; vertical-align:middle; }
.bar i { display:block; height:100%; border-radius:3px; background:var(--violet); }

/* ── Dispos ── */
.set-card { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:20px 22px; margin-bottom:14px; }
.set-card .sc-head { display:flex; align-items:flex-start; gap:12px; margin-bottom:16px; }
.ico { width:40px; height:40px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
.pc-teal .ico, .ico-teal { background:var(--teal-t); color:var(--teal-d); }
.pc-orange .ico, .ico-orange { background:var(--orange-t); color:var(--orange-d); }
.pc-violet .ico, .ico-violet { background:var(--violet-t); color:var(--violet-d); }
.pc-sand .ico, .ico-sand { background:var(--sand-t); color:var(--sand); }
.sc-head .text h3 { margin:0; font-size:14px; font-weight:800; }
.sc-head .text p { margin:3px 0 0; font-size:11.5px; color:var(--muted); }
.param-row { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:9px 0; border-bottom:1px solid var(--line); font-size:12.5px; }
.param-row:last-child { border:none; }
.param-row .lbl { color:var(--muted); }
.param-row .val { font-weight:700; }
.hab-tag { display:inline-flex; align-items:center; font-size:11px; font-weight:700; border-radius:8px; padding:3px 9px; white-space:nowrap; line-height:1.4; margin:2px; }
.fld { margin-bottom:14px; }
.fld label { display:block; font-size:11.5px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; margin-bottom:5px; }
.fld input, .fld select { width:100%; border:1.5px solid var(--line); background:var(--bg); border-radius:10px; padding:9px 13px; font-size:13px; font-family:inherit; transition:border-color .12s; }
.fld input:focus, .fld select:focus { outline:none; border-color:var(--teal); }
.dispo-item { display:flex; gap:10px; align-items:center; padding:8px 0; border-bottom:1px solid var(--line); font-size:13px; }
.dispo-item:last-child { border:none; }
.di-del { border:none; background:none; color:var(--red); cursor:pointer; font-size:14px; flex-shrink:0; padding:0; }
.dispo-badge { font-size:10.5px; font-weight:700; padding:2px 9px; border-radius:99px; white-space:nowrap; flex-shrink:0; }
.dispo-badge.pending   { background:var(--amber,#F6C685); color:var(--amber-d,#8a5a10); }
.dispo-badge.validated { background:var(--teal-t,#E3F2EF); color:var(--teal-d); }
.dispo-badge.rejected  { background:var(--red-t,#FBE7E7); color:var(--red,#D64545); }
.fld-row { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
.fld-row .fld { margin-bottom:0; flex:1; min-width:150px; }
.fld-row .btn { flex-shrink:0; }

/* ── Réglages ── */
.params-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:14px; }
.toggle-row { display:flex; justify-content:space-between; align-items:center; padding:9px 0; border-bottom:1px solid var(--line); font-size:12.5px; }
.toggle-row:last-child { border:none; }
.toggle-row .lbl { color:var(--muted); }
.switch { position:relative; display:inline-block; width:36px; height:20px; }
.switch input { opacity:0; width:0; height:0; }
.slider { position:absolute; cursor:pointer; inset:0; background:var(--line); border-radius:99px; transition:.2s; }
.slider::before { position:absolute; content:""; height:14px; width:14px; left:3px; bottom:3px; background:#fff; border-radius:99px; transition:.2s; }
input:checked + .slider { background:var(--teal); }
input:checked + .slider::before { transform:translateX(16px); }

/* ── Toast ── */
.toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:var(--dark); color:#fff; border-radius:99px; padding:11px 22px; font-size:13px; opacity:0; transition:opacity .25s; pointer-events:none; z-index:99; box-shadow:0 4px 16px rgba(0,0,0,.3); white-space:nowrap; }
.toast.on { opacity:1; }
.modal-bg { position:fixed; inset:0; background:rgba(36,51,60,.45); z-index:100; display:none; align-items:center; justify-content:center; }
.modal-bg.on { display:flex; }
.modal { background:var(--card); border-radius:18px; box-shadow:0 20px 60px rgba(0,0,0,.25); }
.msg-modal { width:960px; max-width:96vw; height:94vh; max-height:920px; display:flex; flex-direction:row; overflow:hidden; padding:0; position:relative; }
.course-modal { width:1200px; max-width:97vw; height:94vh; max-height:960px; display:flex; flex-direction:column; overflow:hidden; padding:0; }
.course-modal-head { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid var(--line); flex-shrink:0; }
.course-modal-head h2 { margin:0; font-size:15px; }
.course-modal-head-actions { display:flex; align-items:center; gap:10px; }
.course-modal-close { border:none; background:none; font-size:16px; color:var(--muted); cursor:pointer; padding:4px; line-height:1; }
.course-modal-close:hover { color:var(--ink); }
.course-modal-body { display:flex; flex:1; overflow:hidden; }

/* Panneau gauche : arbre du cours (même look que espace-eleve.php) */
.dv-left{width:300px;flex-shrink:0;background:#fff;border-right:1px solid var(--line);display:flex;flex-direction:column;overflow:hidden}
.dv-left-head{padding:16px 18px 10px;border-bottom:1px solid var(--line);flex-shrink:0}
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

/* Panneau droite : activité sélectionnée */
.dv-right{flex:1;overflow:hidden;display:flex;flex-direction:column;position:relative}
.dv-empty{display:flex;align-items:center;justify-content:center;flex:1;color:var(--muted);font-size:13px;font-style:italic}
#ce-iframe { flex:1; width:100%; border:none; opacity:1; transition:opacity .15s; }
.dv-loading { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:var(--bg); }
.dv-spinner { width:28px; height:28px; border-radius:50%; border:3px solid var(--line); border-top-color:var(--teal); animation:dvspin .7s linear infinite; }
@keyframes dvspin { to { transform:rotate(360deg); } }
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
.msg-contact-cls { font-size:9px; font-weight:800; padding:1px 6px; border-radius:99px; background:var(--violet-t); color:var(--violet-d); flex-shrink:0; }
.msg-contact-preview { font-size:11.5px; color:var(--muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-top:1px; }
.msg-contact.unread .msg-contact-preview { color:var(--ink); font-weight:600; }
.msg-contact-time { font-size:10.5px; color:var(--muted); white-space:nowrap; margin-left:auto; }
.msg-contact-unread-badge { position:absolute; right:10px; bottom:9px; background:var(--teal); color:#fff; font-size:9.5px; font-weight:800; border-radius:99px; min-width:16px; height:16px; display:flex; align-items:center; justify-content:center; padding:0 4px; }

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

@media(max-width:760px) { aside { flex-basis:54px; } aside button .nav-ico { font-size:13px; } }
button:focus-visible { outline:2px solid var(--teal); outline-offset:2px; }
@media (prefers-reduced-motion:reduce) { *, *::before, *::after { transition:none !important; } }
</style>
</head>
<body>

<!-- ── TOPBAR ── -->
<div class="top" id="topbar">
  <a class="brand" href="<?= $CFG->wwwroot ?>">
    <?php if (!empty($brand['logo'])): ?>
      <img src="<?= $brand['logo'] ?>" alt="<?= s($brand['name']) ?>" style="height:38px;width:auto">
    <?php else: ?>
      <svg width="26" height="20" viewBox="0 0 34 24" aria-hidden="true">
        <path d="M3 21 A14 14 0 0 1 31 21" fill="none" stroke="<?= $brand['teal'] ?>" stroke-width="4" stroke-linecap="round"/>
        <line x1="17" y1="21" x2="26" y2="9" stroke="<?= $brand['orange'] ?>" stroke-width="3" stroke-linecap="round"/>
        <circle cx="17" cy="21" r="3" fill="<?= $brand['orange'] ?>"/>
      </svg>
      <?= $brand['name_html'] ?>
    <?php endif; ?>
    <small>· Formateur</small>
  </a>
  <div class="top-date" id="date-label">Chargement…</div>
  <div class="top-user">
    <span class="av av-sm" style="background:var(--teal)"><?= htmlspecialchars($user_initials) ?></span>
    <?= s($USER->firstname . ' ' . $USER->lastname) ?>
  </div>
</div>

<div class="app">

<!-- ── SIDEBAR ── -->
<aside>
  <div class="nav-brand">
    <?php if (!empty($brand['logo'])): ?>
      <img src="<?= $brand['logo'] ?>" alt="<?= s($brand['name']) ?>" style="width:60px;height:auto;display:block;margin:0 auto 3px">
    <?php else: ?>
      <svg width="22" height="16" viewBox="0 0 34 24" aria-hidden="true">
        <path d="M3 21 A14 14 0 0 1 31 21" fill="none" stroke="rgba(255,255,255,.7)" stroke-width="4" stroke-linecap="round"/>
        <line x1="17" y1="21" x2="26" y2="9" stroke="<?= $brand['orange'] ?>" stroke-width="3" stroke-linecap="round"/>
        <circle cx="17" cy="21" r="3" fill="<?= $brand['orange'] ?>"/>
      </svg>
    <?php endif; ?>
    <span class="nb-label">Formateur</span>
    <span class="cls-chip" id="sidebar-cls">—</span>
  </div>
  <button class="on" data-v="jour"   onclick="nav(this)"><span class="nav-ico">🏠</span>Aujourd'hui</button>
  <button data-v="plan"   onclick="nav(this)"><span class="nav-ico">📅</span>Planning</button>
  <button data-v="corr"   onclick="nav(this)" id="nav-corr-btn"><span class="nav-ico">✓</span>Corrections</button>
  <button data-v="elev"   onclick="nav(this)"><span class="nav-ico">👥</span>Élèves</button>
  <button data-v="dispo"  onclick="nav(this)"><span class="nav-ico">🕒</span>Dispos</button>
  <button data-v="params" onclick="nav(this)"><span class="nav-ico">⚙</span>Réglages</button>
  <div class="sep"></div>
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

<!-- ═══════════════════════════════════════
     VUE AUJOURD'HUI
═══════════════════════════════════════ -->
<div class="view on" id="v-jour">
  <p style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin:0 0 4px">Tableau de bord</p>
  <h1 id="jour-greeting">Bonjour !</h1>
  <p class="sub" id="jour-sub">Chargement…</p>
  <div class="kpis" id="jour-kpis"></div>
  <div class="cols2">
    <div class="panel">
      <h3 id="seances-title">Mes séances</h3>
      <div id="seances-list"></div>
    </div>
    <div class="col-stack">
      <div class="panel" id="signal-panel">
        <p class="sig-sec-title">Signal à traiter</p>
        <div id="lia-list"></div>
      </div>
      <div class="panel" id="veh-panel">
        <p class="sig-sec-title" style="color:var(--orange,#F2A03D)">Semaine véhicule</p>
        <div id="veh-list"></div>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════
     VUE PLANNING
═══════════════════════════════════════ -->
<div class="view" id="v-plan">
  <p class="plan-eyebrow">Charge de travail</p>
  <h1>Mon planning</h1>
  <p class="sub" id="plan-sub">Votre charge semaine par semaine. Violet = cette semaine.</p>
  <div class="panel" style="padding:16px 14px 12px">
    <p class="plan-sec-title">CHARGE SUR LE PLAN &middot; S1 &rarr; S35</p>
    <div class="strip-wrap">
      <div class="strip-nums"  id="plan-nums"></div>
      <div class="strip-inner" id="plan-bars"></div>
      <div class="strip-lbl"   id="plan-labels"></div>
    </div>
    <div class="plan-legend">
      <span><i class="plan-sw" style="background:#E3F2EF;border:1px solid #A7DDCB"></i>&lt; 15 h</span>
      <span><i class="plan-sw" style="background:#7CC9AC"></i>15&ndash;24 h</span>
      <span><i class="plan-sw" style="background:#F2A03D"></i>25&ndash;28 h</span>
      <span><i class="plan-sw" style="background:#D64545"></i>&gt; 28 h &mdash; &agrave; arbitrer</span>
      <span style="color:var(--violet);font-weight:700;font-size:11px">&#9632; semaine courante</span>
      <span style="color:var(--violet);font-weight:700;font-size:11px">&#10022; ajustement Lia</span>
    </div>
    <div id="plan-alert" style="display:none"></div>
  </div>
  <div class="panel" style="margin-top:12px;padding:16px 14px 14px">
    <p class="plan-sec-title" id="plan-week-title">SEMAINE EN COURS</p>
    <div id="plan-week-list"></div>
  </div>
</div>

<!-- ═══════════════════════════════════════
     VUE CORRECTIONS
═══════════════════════════════════════ -->
<div class="view" id="v-corr">
  <h1>Corrections en attente</h1>
  <p class="sub" id="corr-sub"></p>
  <div id="corr-list"></div>
</div>

<!-- ═══════════════════════════════════════
     VUE ÉLÈVES
═══════════════════════════════════════ -->
<div class="view" id="v-elev">
  <h1>Mes élèves</h1>
  <p class="sub">Apprenants de vos cours. Lignes en rouge clair = inactifs depuis plus de 7 jours.</p>
  <div class="filter-bar">
    <span class="filter-lbl">Classe :</span>
    <button class="filter-btn on" data-coh="all" onclick="filterElev(this)">Tous</button>
    <div id="elev-filter-btns" style="display:contents"></div>
  </div>
  <div class="twrap">
    <table>
      <thead>
        <tr>
          <th>Élève</th>
          <th>Classe</th>
          <th>Complétion</th>
          <th>Dernier accès</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="elev-tbody"></tbody>
    </table>
  </div>
</div>

<!-- ═══════════════════════════════════════
     VUE DISPOS
═══════════════════════════════════════ -->
<div class="view" id="v-dispo">
  <p class="plan-eyebrow">Contrat</p>
  <h1>Disponibilités</h1>
  <p class="sub">Déclarez vos indisponibilités. Lia proposera une re-répartition, le coordinateur validera.</p>

  <div class="set-card pc-teal">
    <div class="sc-head">
      <div class="ico">◉</div>
      <div class="text"><h3>Mon contrat</h3><p>Statut et capacité horaire</p></div>
    </div>
    <div class="param-row"><span class="lbl">Statut</span><span class="val" id="dispo-statut">—</span></div>
    <div class="param-row"><span class="lbl">Capacité hebdo</span><span class="val" id="dispo-cap">—</span></div>
    <div class="param-row"><span class="lbl">Habilitations</span><span class="val" id="dispo-habs">—</span></div>
    <div class="param-row" id="dispo-presentiels-row" style="display:none"><span class="lbl">Présentiels à venir</span><span class="val" id="dispo-presentiels">—</span></div>
  </div>

  <div class="set-card pc-orange">
    <div class="sc-head">
      <div class="ico">◷</div>
      <div class="text"><h3>Déclarer une indisponibilité</h3><p>Formulaire de déclaration</p></div>
    </div>
    <div class="fld-row">
      <div class="fld"><label>Du</label><input type="date" id="dispo-from"></div>
      <div class="fld"><label>Au</label><input type="date" id="dispo-to"></div>
      <div class="fld">
        <label>Motif</label>
        <select id="dispo-motif">
          <option value="conge">Congés</option>
          <option value="maladie">Arrêt maladie</option>
          <option value="formation">Formation</option>
          <option value="personnel">Raison personnelle</option>
          <option value="autre">Autre</option>
        </select>
      </div>
      <button class="btn primary" onclick="addDispo()">Transmettre</button>
    </div>
  </div>

  <div class="set-card pc-violet">
    <div class="sc-head">
      <div class="ico">▦</div>
      <div class="text"><h3>Mes déclarations</h3><p>Statut de validation par le coordinateur</p></div>
    </div>
    <div id="dispo-list-el"></div>
  </div>
</div>

<!-- ═══════════════════════════════════════
     VUE RÉGLAGES
═══════════════════════════════════════ -->
<div class="view" id="v-params">
  <h1>Réglages</h1>
  <p class="sub">Profil, habilitations et préférences d'interface.</p>
  <div class="params-grid">

    <div class="set-card pc-teal">
      <div class="sc-head"><div class="ico">◉</div><div class="text"><h3>Mon profil</h3><p>Données Moodle — lecture seule</p></div></div>
      <div class="param-row"><span class="lbl">Prénom</span><span class="val"><?= s($USER->firstname) ?></span></div>
      <div class="param-row"><span class="lbl">Nom</span><span class="val"><?= s($USER->lastname) ?></span></div>
      <div class="param-row"><span class="lbl">Email</span><span class="val" style="font-size:12px"><?= s($USER->email) ?></span></div>
      <div class="param-row"><span class="lbl">Rôle</span><span class="val" id="params-role">—</span></div>
    </div>

    <div class="set-card pc-orange">
      <div class="sc-head"><div class="ico">⚑</div><div class="text"><h3>Habilitations</h3><p>Issues de la fiche formateur</p></div></div>
      <div id="params-habs" style="min-height:30px">—</div>
    </div>

    <div class="set-card pc-violet">
      <div class="sc-head"><div class="ico">◧</div><div class="text"><h3>Notifications</h3><p>Préférences d'alertes (Phase 2)</p></div></div>
      <div class="toggle-row"><span class="lbl">Signal Lia — inactivité élève</span><label class="switch"><input type="checkbox" checked><span class="slider"></span></label></div>
      <div class="toggle-row"><span class="lbl">Nouvelle correction déposée</span><label class="switch"><input type="checkbox" checked><span class="slider"></span></label></div>
      <div class="toggle-row"><span class="lbl">Rappel séance du jour</span><label class="switch"><input type="checkbox"><span class="slider"></span></label></div>
    </div>

    <div class="set-card pc-teal">
      <div class="sc-head"><div class="ico">🎓</div><div class="text"><h3>Mes cours</h3><p>Ajoutez le contenu (sections, activités) directement sur Moodle</p></div></div>
      <div id="params-mes-cours">—</div>
    </div>

    <div class="set-card pc-sand">
      <div class="sc-head"><div class="ico">◷</div><div class="text"><h3>Préférences</h3><p>Statistiques de session</p></div></div>
      <div class="param-row"><span class="lbl">Cours enseignés</span><span class="val" id="params-nb-cours">—</span></div>
      <div class="param-row"><span class="lbl">Élèves suivis</span><span class="val" id="params-nb-eleves">—</span></div>
      <div class="param-row"><span class="lbl">Heures cette semaine</span><span class="val" id="params-heure">—</span></div>
    </div>

  </div>
</div>

</main>
</div><!-- .app -->

<div class="toast" id="toast"></div>

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
      <div class="msg-contacts" id="msg-contacts"></div>
    </aside>
    <section class="msg-thread-pane">
      <div class="msg-thread-head" id="msg-thread-head" style="display:none">
        <div class="msg-thread-who">
          <span class="msg-thread-avatar" id="msg-thread-avatar"></span>
          <div>
            <div class="msg-thread-name" id="msg-thread-name"></div>
            <div class="msg-thread-sub" id="msg-thread-sub"></div>
          </div>
        </div>
        <div class="msg-thread-actions">
          <button type="button" class="btn mini ghost" id="msg-thread-fiche" onclick="msgGoFiche()">Fiche élève</button>
          <button type="button" class="btn mini gold" id="msg-thread-traite" onclick="msgToggleTraite()">Marquer traité</button>
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

<div class="modal-bg" id="course-modal-bg" onclick="if(event.target===this)closeCourseIframe()">
  <div class="modal course-modal">
    <div class="course-modal-head">
      <h2 id="course-modal-title">Cours</h2>
      <div class="course-modal-head-actions">
        <button type="button" id="ce-edit-link" class="btn mini ghost" onclick="ceOpenEditMode()">Activer le mode édition</button>
        <button type="button" class="course-modal-close" onclick="closeCourseIframe()" aria-label="Fermer">✕</button>
      </div>
    </div>
    <div class="course-modal-body">
      <div class="dv-left">
        <div class="dv-left-head">
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
        <div class="dv-empty" id="ce-empty">Sélectionnez une activité dans le menu à gauche.</div>
        <div class="dv-loading" id="ce-loading" style="display:none"><span class="dv-spinner"></span></div>
        <iframe id="ce-iframe" src="" style="display:none"></iframe>
      </div>
    </div>
  </div>
</div>

<script>
// ── Données PHP injectées ──
var DATA    = <?= $formateur_data ?>;
var WWWROOT = '<?= $CFG->wwwroot ?>';
var GLOWBL  = '<?= s($glowbl_url) ?>';

// Lance la vraie activité LTI Glowbl du cours (authentification automatique),
// avec repli sur le lien statique si aucune correspondance n'est trouvée.
function glowblJoinUrl(courseid) {
  var cmid = courseid && DATA.glowbl_lti_map ? DATA.glowbl_lti_map[courseid] : null;
  return cmid ? (WWWROOT + '/mod/lti/view.php?id=' + cmid) : GLOWBL;
}
var SESSKEY = '<?= $sesskey ?>';
var FORMURL = '<?= $CFG->wwwroot ?>/espace-formateur.php';

/* ===== POST helper ===== */
function apiPost(params) {
  var body = new URLSearchParams(params);
  body.append('sesskey', SESSKEY);
  return fetch(FORMURL, { method: 'POST', body: body }).then(function(r) { return r.json(); });
}

// ── État ──
var _rendered   = {};
var _elevFilter = 'all';

/* ─────────────────────────────────────────
   HELPERS
───────────────────────────────────────── */
function esc(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtDate(ts) {
  var d = new Date(ts * 1000);
  var M = ['janv.','févr.','mars','avr.','mai','juin','juil.','août','sept.','oct.','nov.','déc.'];
  return d.getDate() + ' ' + M[d.getMonth()] + ' ' + d.getFullYear();
}
function habStyle(color) {
  if (color === 'orange') return 'background:var(--orange-t);color:var(--orange-d)';
  if (color === 'violet') return 'background:var(--violet-t);color:var(--violet-d)';
  return 'background:var(--teal-t);color:var(--teal-d)';
}

/* ─────────────────────────────────────────
   TOAST
───────────────────────────────────────── */
var _toastT;
function toast(msg, dur) {
  var t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('on');
  clearTimeout(_toastT);
  _toastT = setTimeout(function(){ t.classList.remove('on'); }, dur || 2800);
}

/* ===== MESSAGERIE (modal, UI custom, vraie messagerie Moodle en arrière-plan) ===== */
var _msg = {
  list: [],
  filter: '',
  activeConvId: 0,
  activeUserId: 0,
  pendingUserId: 0,
  pendingName: ''
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
  document.getElementById('msg-modal-bg').classList.add('on');
  _msg.pendingUserId = uid;
  _msg.pendingName   = nom;
  msgLoadList();
}
function closeMessageModal() {
  document.getElementById('msg-modal-bg').classList.remove('on');
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
      (c.unreadcount > 0 ? '<span class="msg-contact-unread-badge">' + c.unreadcount + '</span>' : '') +
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
    html += '<div class="msg-group-label">Signalés</div>' + others.map(msgContactRow).join('');
  }
  if (!favs.length && !others.length) {
    html = '<div class="msg-thread-empty" style="padding:30px 10px">Aucun contact</div>';
  }
  box.innerHTML = html;
}

function msgSelectContact(convid, userid, nom, cls, isonline) {
  _msg.activeConvId = convid;
  _msg.activeUserId = userid;

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
    (cls ? ' · Classe ' + esc(cls) : '');

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
function msgGoFiche() {
  var uid = _msg.activeUserId;
  closeMessageModal();
  if (uid) goToStudent(uid);
}

/* ===== "AJOUTER DU CONTENU" (modal, même look que espace-eleve.php, reste sur espace-formateur.php) ===== */
var _ce = { id: 0, name: '', sections: [], contextid: 0 };

var _ceIframeCSS = [
  '#page-header,#main-header,.navbar,#page-footer,.main-footer,footer,',
  '.breadcrumb-nav,.breadcrumb,#nav-drawer,.drawer-toggle,.skiplinks,',
  '.secondary-navigation,.tertiary-navigation,.activity-navigation,#fsmod-header { display:none !important; }',
  'html, body { background:#fff !important; }',
  '#page,#page.container-fluid,#region-main,#region-main-box { padding:0 !important; margin:0 !important; max-width:none !important; }',
  '.btn-secondary,.btn.btn-secondary { background:#fff !important; border-color:#2E9488 !important; color:#2E9488 !important; }',
].join('\n');

// Mode édition : on garde #page-header visible (c'est là que vit le vrai bouton
// natif "Activer le mode édition" — le masquer le rendait injoignable), on ne
// nettoie que le chrome global du site (nav/footer).
var _ceCourseCSS = [
  '#main-header,.navbar,#page-footer,.main-footer,footer,',
  '#nav-drawer,.drawer-toggle,.skiplinks,',
  '.secondary-navigation,.tertiary-navigation,.activity-navigation,#fsmod-header { display:none !important; }',
  'html, body { background:#fff !important; }',
  '#page,#page.container-fluid,#region-main,#region-main-box { padding:0 !important; margin:0 !important; max-width:none !important; }',
  '.btn-secondary,.btn.btn-secondary { background:#fff !important; border-color:#2E9488 !important; color:#2E9488 !important; }',
].join('\n');

function ceInjectCSS(iframe, css) {
  try {
    var doc = iframe.contentDocument || iframe.contentWindow.document;
    if (!doc || !doc.head) return;
    if (doc.getElementById('l3m-ce-style')) return;
    var s = doc.createElement('style');
    s.id = 'l3m-ce-style';
    s.textContent = css || _ceIframeCSS;
    doc.head.appendChild(s);
  } catch (e) {}
}

function ceSelectMod(cmid, modtype, el) {
  document.querySelectorAll('.det-mod').forEach(function(e){ e.classList.remove('active'); });
  document.querySelectorAll('.det-mod-dot').forEach(function(e){ e.classList.remove('active-dot'); });
  if (el) {
    el.classList.add('active');
    var dot = el.querySelector('.det-mod-dot');
    if (dot) dot.classList.add('active-dot');
  }
  var url = WWWROOT + '/mod/' + modtype + '/view.php?id=' + cmid;
  var iframe  = document.getElementById('ce-iframe');
  var empty   = document.getElementById('ce-empty');
  var loading = document.getElementById('ce-loading');
  // On estompe l'iframe avant de naviguer : sinon l'activite precedente reste
  // affichee jusqu'a la fin du chargement de la nouvelle (flash de l'ancien
  // contenu). Un spinner comble le temps de chargement reseau reel (le
  // contenu de l'activite doit de toute facon venir du serveur).
  iframe.style.opacity = '0';
  loading.style.display = 'flex';
  iframe.onload = function() { ceInjectCSS(iframe); iframe.style.opacity = '1'; loading.style.display = 'none'; };
  iframe.src = url;
  iframe.style.display = 'block';
  empty.style.display  = 'none';
}

function ceRenderSections(sections) {
  var box = document.getElementById('ce-sections');
  if (!sections || !sections.length) {
    box.innerHTML = '<p style="padding:20px 18px;color:var(--muted);font-size:13px">Aucun contenu.</p>';
    return;
  }
  var html = '';
  sections.forEach(function(s, si) {
    var allDone = s.total > 0 && s.done_n === s.total;
    html += '<div class="det-sec" id="ce-sec-' + si + '">';
    html += '<div class="det-sec-head" onclick="ceToggleSec(' + si + ')">';
    html += '<div class="det-sec-num' + (allDone ? ' done' : '') + '">' + s.num + '</div>';
    html += '<div class="det-sec-info"><div class="det-sec-name">' + esc(s.name) + '</div>';
    if (s.total > 0) html += '<div class="det-sec-count">(' + s.done_n + '/' + s.total + ')</div>';
    html += '</div><div class="det-sec-arrow" id="ce-arr-' + si + '">›</div></div>';
    html += '<div class="det-mods" id="ce-mods-' + si + '">';
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
  var iframe  = document.getElementById('ce-iframe');
  var empty   = document.getElementById('ce-empty');
  var loading = document.getElementById('ce-loading');
  var courseUrl = WWWROOT + '/course/view.php?id=' + _ce.id;
  iframe.style.opacity = '0';
  loading.style.display = 'flex';
  iframe.onload = function() { ceInjectCSS(iframe, _ceCourseCSS); iframe.style.opacity = '1'; loading.style.display = 'none'; };
  if (_ce.contextid) {
    // Le bouton natif "Activer le mode édition" du thème est display:none par
    // défaut (remplacé par un mécanisme JS ailleurs) — on active directement le
    // mode édition via l'URL officielle Moodle plutôt que de dépendre d'un
    // élément caché dans la page.
    iframe.src = WWWROOT + '/editmode.php?context=' + _ce.contextid +
      '&pageurl=' + encodeURIComponent(courseUrl) + '&setmode=1&sesskey=' + SESSKEY;
  } else {
    iframe.src = courseUrl;
  }
  iframe.style.display = 'block';
  empty.style.display  = 'none';
}

function openCourseIframe(courseId, courseName) {
  _ce.id = courseId; _ce.name = courseName;
  document.getElementById('course-modal-title').textContent = courseName;
  document.getElementById('ce-iframe').style.display = 'none';
  document.getElementById('ce-iframe').src = '';
  document.getElementById('ce-empty').style.display = 'flex';
  document.getElementById('ce-search-inp').value = '';
  document.getElementById('ce-sections').innerHTML = '<p style="padding:20px 18px;color:var(--muted);font-size:13px">Chargement…</p>';
  document.getElementById('course-modal-bg').classList.add('on');

  fetch(window.location.pathname + '?action=course_detail&courseid=' + courseId)
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (!d.ok) {
        document.getElementById('ce-sections').innerHTML =
          '<p style="padding:20px 18px;color:var(--muted);font-size:13px">Erreur chargement.</p>';
        return;
      }
      _ce.sections = d.sections;
      _ce.contextid = d.contextid;
      ceRenderSections(d.sections);
    })
    .catch(function() {
      document.getElementById('ce-sections').innerHTML =
        '<p style="padding:20px 18px;color:var(--muted);font-size:13px">Erreur réseau.</p>';
    });
}
function closeCourseIframe() {
  document.getElementById('course-modal-bg').classList.remove('on');
  var iframe = document.getElementById('ce-iframe');
  iframe.src = 'about:blank';
}

/* ─────────────────────────────────────────
   NAVIGATION
───────────────────────────────────────── */
function doLogout(ev) {
  ev.preventDefault();
  var logoutUrl = WWWROOT + '/login/logout.php?sesskey=' + SESSKEY;
  var target    = WWWROOT + '/login/index.php?loginredirect=1';
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
  if      (v === 'jour')   renderJour();
  else if (v === 'plan')   renderPlan();
  else if (v === 'corr')   renderCorr();
  else if (v === 'elev')   renderElev();
  else if (v === 'dispo')  renderDispo();
  else if (v === 'params') renderParams();
}

/* ─────────────────────────────────────────
   TOPBAR — effet scroll
───────────────────────────────────────── */
window.addEventListener('scroll', function(){
  document.getElementById('topbar').classList.toggle('scrolled', window.scrollY > 4);
}, { passive: true });

/* ─────────────────────────────────────────
   MOTEUR PLANNING & LIA — ECSR 35 semaines
───────────────────────────────────────── */
var LIA_BLOCS = [
  {n:'Politique de la sécurité routière',    h:65},
  {n:'Cadre de l\'activité ECSR',            h:60},
  {n:'L\'automobile',                        h:60},
  {n:'Analyse de la tâche de conduite',      h:30},
  {n:'Physiologie du conducteur',            h:30},
  {n:'Psychologie du conducteur',            h:10},
  {n:'Notions pédagogiques',                 h:40},
  {n:'Ingénierie et construction de séance', h:65},
  {n:'Techniques d\'animation',              h:35},
  {n:'Gestion des conflits',                 h:10},
  {n:'Gestion du stress',                    h:20},
  {n:'Anticipation des risques',             h:25},
  {n:'Évaluation et docimologie',            h:30},
  {n:'Difficultés et remédiation',           h:20},
  {n:'Analyse de la demande (CCP2)',         h:110},
  {n:'Construction d\'une action',           h:60},
  {n:'Animation d\'une sensibilisation',     h:35},
  {n:'Évaluation des pratiques',             h:30}
];
var LIA_DIST_WEEKS = [];
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
(function(){ for(var i=1;i<=34;i++){var t=liaWType(i);if(t==='c'||t==='e1')LIA_DIST_WEEKS.push(i);} })();

var LIA_SESSION_H = 2; // durée d'une séance (heures)

var LIA_WLABELS = {c:'Cours à distance',s:'Semaine entreprise',r:'Regroupement salle',rs:'Regroupement + stage',e1:'Cours + prépa examen',e:"Semaine d'examen"};

function liaParseDate(s) {
  if (!s) return null;
  var d = s.length === 7 ? new Date(s + '-01') : new Date(s);
  return isNaN(d.getTime()) ? null : d;
}

// Blocs actifs pour une semaine relative (1..35)
function liaGetWeekBlocs(rel) {
  var wtype = liaWType(rel);
  if (wtype !== 'c' && wtype !== 'e1') return {wtype:wtype, blocs:[]};
  var idx = LIA_DIST_WEEKS.indexOf(rel);
  if (idx < 0) return {wtype:wtype, blocs:[]};
  var rS = idx*35, rE = (idx+1)*35, blocs = [], cum = 0;
  LIA_BLOCS.forEach(function(b) {
    var bE = cum + b.h;
    if (bE > rS && cum < rE) blocs.push({n:b.n, h:Math.round(Math.min(bE,rE)-Math.max(cum,rS))});
    cum += b.h;
  });
  return {wtype:wtype, blocs:blocs};
}

// Calcul plage de dates pour une semaine relative (retourne "30 nov → 4 déc")
function liaWeekDates(startdate, rel) {
  var sd = liaParseDate(startdate);
  if (!sd) return '';
  var ms   = sd.getTime() + (rel - 1) * 7 * 24 * 3600 * 1000;
  var from = new Date(ms);
  var to   = new Date(ms + 4 * 24 * 3600 * 1000);
  var mo   = ['jan','fév','mars','avr','mai','juin','juil','août','sep','oct','nov','déc'];
  function fmt(d) { return d.getDate() + ' ' + mo[d.getMonth()]; }
  return fmt(from) + (from.getMonth() === to.getMonth() ? ' → ' + to.getDate() : ' → ' + fmt(to));
}

// Semaine courante + blocs pour une cohorte
function liaCohorteWeek(cohort) {
  var sd = liaParseDate(cohort.startdate);
  if (!sd) return null;
  var rel = Math.floor((Date.now() - sd.getTime()) / (7*24*3600*1000)) + 1;
  if (rel < 1 || rel > 35) return null;
  var info = liaGetWeekBlocs(rel);
  return {name:cohort.name, nb:cohort.nb_members, rel:rel, wtype:info.wtype, blocs:info.blocs, label:LIA_WLABELS[info.wtype]||''};
}

/* ─────────────────────────────────────────
   VUE AUJOURD'HUI
───────────────────────────────────────── */
function renderJour() {
  var hr    = new Date().getHours();
  var greet = hr < 12 ? 'Bonjour' : hr < 18 ? 'Bon après-midi' : 'Bonsoir';
  document.getElementById('date-label').innerHTML = '<b>' + esc(DATA.date_label) + '</b>';
  document.getElementById('jour-greeting').textContent = greet + ', ' + DATA.formateur.firstname + ' !';

  // Blocs ECSR semaine courante (toutes cohortes)
  var cohortWeeks = (DATA.cohorts || []).map(liaCohorteWeek).filter(Boolean);
  var totalBlocs  = cohortWeeks.reduce(function(s,cw){ return s+cw.blocs.length; }, 0);
  var totalH      = cohortWeeks.reduce(function(s,cw){ return s+cw.blocs.reduce(function(a,b){ return a+b.h; },0); }, 0);

  // Sous-titre
  var parts = [
    DATA.courses.length + ' cours',
    DATA.students.length + ' apprenant' + (DATA.students.length > 1 ? 's' : '')
  ];
  if (cohortWeeks.length) parts.push(cohortWeeks.length + ' classe' + (cohortWeeks.length > 1 ? 's actives' : ' active'));
  if (DATA.meta.role_label) parts.unshift(DATA.meta.role_label);
  document.getElementById('jour-sub').textContent = parts.join(' · ');

  // Sidebar chip — première classe active
  var sclEl = document.getElementById('sidebar-cls');
  if (sclEl && DATA.cohorts.length) sclEl.textContent = DATA.cohorts[0].name;

  // 5 KPIs
  var nbCorr = DATA.corrections.length;
  var nbLia  = DATA.signal_lia.length;
  var totalH_cours = totalBlocs * LIA_SESSION_H;
  var cap = DATA.meta.capacity || 28;
  var firstLiaName = nbLia ? (DATA.signal_lia[0].nom.split(' ')[0] + ' ' + DATA.signal_lia[0].nom.split(' ').slice(-1)[0].slice(0,1) + '.') : '';
  var kpis = [
    { cls:'k-orange', title:'Heures cours · semaine',
      num: totalH_cours + '<small>h</small>',
      chip: 'sur ' + cap + ' h', chipCls: '',
      sub: 'Capacité contractuelle ' + (DATA.meta.type === 'vacataire' ? 'vacataire' : 'salariée'),
      action: null },
    { cls:'k-mint', title:'Séances aujourd\'hui',
      num: totalBlocs,
      chip: cohortWeeks.length ? cohortWeeks.map(function(cw){return cw.name.split(' ').pop();}).join(' · ') : null, chipCls:'',
      sub: totalBlocs > 0 ? totalBlocs + ' séance' + (totalBlocs>1?'s':'') + ' · ' + LIA_SESSION_H + 'h chacune' : 'Semaine du ' + esc(DATA.week_label),
      action: null },
    { cls:'k-violet', title:'Élèves suivis',
      num: DATA.students.length,
      chip: DATA.cohorts.length + ' classe' + (DATA.cohorts.length>1?'s':''), chipCls:'',
      sub: DATA.courses.length + ' cours assigné' + (DATA.courses.length > 1 ? 's' : ''),
      action: null },
    { cls: nbCorr > 0 ? 'k-danger' : 'k-cream',
      title: 'Corrections en attente',
      num: nbCorr,
      chip: null, chipCls:'',
      sub: nbCorr > 0 ? nbCorr + ' devoir' + (nbCorr>1?'s':'') + ' à corriger' : 'Tout est corrigé ✓',
      action: nbCorr > 0 ? '<button class="kpi-action" onclick="go(\'corr\')">Traiter \u2192</button>' : null },
    { cls: nbLia > 0 ? 'k-danger' : 'k-cream',
      title: 'Signal Lia',
      num: nbLia,
      chip: firstLiaName || null, chipCls: ' chip-orange',
      sub: nbLia > 0 ? 'Inactifs depuis > 7 j' : 'Aucun inactif ✓',
      action: null },
  ];
  document.getElementById('jour-kpis').innerHTML = kpis.map(function(k){
    return '<div class="kpi ' + k.cls + '">' +
      '<div class="kpi-head"><span class="kpi-title">' + k.title + '</span></div>' +
      '<div class="kpi-main">' +
        '<div class="kpi-num">' + k.num + '</div>' +
        (k.chip ? '<span class="kpi-chip' + (k.chipCls||'') + '">' + esc(k.chip) + '</span>' : '') +
      '</div>' +
      '<div class="kpi-sub">' + k.sub + '</div>' +
      (k.action || '') +
    '</div>';
  }).join('');

  if (nbCorr > 0 && !document.querySelector('#nav-corr-btn .nav-badge')) {
    var badge = document.createElement('span');
    badge.className   = 'nav-badge';
    badge.textContent = nbCorr;
    document.getElementById('nav-corr-btn').appendChild(badge);
  }

  // Semaines véhicule actives
  // Semaines véhicule : actives ou à venir (une entrée par cohorte)
  var vehItems = (DATA.cohorts || []).map(function(cohort) {
    var sd = liaParseDate(cohort.startdate);
    if (!sd) return null;
    var rel = Math.floor((Date.now() - sd.getTime()) / (7 * 24 * 3600 * 1000)) + 1;
    if (rel > 35) return null;
    var vehRels = [7, 13, 19];
    var targetRel = null;
    for (var i = 0; i < vehRels.length; i++) {
      if (rel <= vehRels[i]) { targetRel = vehRels[i]; break; }
    }
    if (!targetRel) return null; // toutes les semaines véhicule sont passées
    var active = (rel === targetRel);
    var weeksUntil = targetRel - rel;
    var sesNum = targetRel === 7 ? 1 : targetRel === 13 ? 2 : 3;
    var dates = liaWeekDates(cohort.startdate, targetRel);
    var nb = cohort.nb_members || 3;
    var nbGroups = Math.ceil(nb / 3);
    var letter = (cohort.name.match(/Classe\s+([A-Z])/i) || ['','A'])[1].toUpperCase();
    var groups = [];
    for (var g = 1; g <= nbGroups; g++) groups.push(letter + g);
    return {name: cohort.name, dates: dates, rel: targetRel, sesNum: sesNum,
            nb: nb, groups: groups, active: active, weeksUntil: weeksUntil};
  }).filter(Boolean);

  var vehPanel = document.getElementById('veh-panel');
  var vehEl    = document.getElementById('veh-list');
  vehPanel.style.display = vehItems.length ? '' : 'none';
  if (vehItems.length) {
    vehEl.innerHTML = vehItems.map(function(vw) {
      if (vw.active) {
        return '<div class="veh-card">' +
          '<div class="veh-header">&#128663; Semaine véhicule · ' + esc(vw.dates) + '</div>' +
          '<p class="veh-body">' + esc(vw.name) + ' · ' + vw.nb + ' apprenant' + (vw.nb > 1 ? 's' : '') +
            ' · 3 élèves max par voiture</p>' +
          '<div class="veh-groups">' +
            vw.groups.map(function(g){ return '<span class="veh-grp-chip">Grp ' + esc(g) + '</span>'; }).join('') +
          '</div>' +
          '<div class="veh-acts">' +
            '<button class="btn mini ghost" onclick="go(\'plan\')">Voir le planning</button>' +
            '<span style="font-size:11px;color:var(--muted);align-self:center">Stage ' + vw.sesNum + '/3</span>' +
          '</div>' +
        '</div>';
      } else {
        var weeksLabel = vw.weeksUntil === 1 ? 'la semaine prochaine' : 'dans ' + vw.weeksUntil + ' sem.';
        return '<div style="border:1px solid var(--line);border-radius:10px;overflow:hidden;margin-bottom:8px">' +
          '<div style="background:var(--line);padding:6px 13px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)">' +
            '&#128663; Prochaine semaine véhicule · ' + weeksLabel +
          '</div>' +
          '<div style="padding:10px 13px;font-size:13px;color:var(--ink)">' +
            '<b>' + esc(vw.name) + '</b> · ' + esc(vw.dates) + '<br>' +
            '<span style="color:var(--muted)">' + vw.nb + ' apprenant' + (vw.nb > 1 ? 's' : '') +
              ' · ' + vw.groups.length + ' sous-groupe' + (vw.groups.length > 1 ? 's' : '') +
              ' · Stage ' + vw.sesNum + '/3</span>' +
          '</div>' +
        '</div>';
      }
    }).join('');
  }

  // Séances : events Moodle si disponibles, sinon LIA
  document.getElementById('seances-title').textContent = 'Séances · semaine du ' + DATA.week_label;
  var seancesEl = document.getElementById('seances-list');

  if (DATA.week_events && DATA.week_events.length) {
    // Events réels depuis mdl_event (admin a planifié les cours)
    seancesEl.innerHTML = DATA.week_events.map(function(ev) {
      var cohName = '';
      DATA.cohorts.forEach(function(c) {
        // On essaie de matcher le cours au nom de la cohorte via le shortname
        if (!cohName && ev.course_name) cohName = ev.course_name;
      });
      return '<div class="event" style="align-items:center;gap:8px">' +
        '<span class="lw" style="flex:0 0 56px;font-size:12px;font-weight:800;color:var(--teal-d);line-height:1.3">' +
          esc(ev.day_label) + '<br>' + esc(ev.hour_label) +
        '</span>' +
        '<span class="info">' +
          esc(ev.name) +
          (ev.course_short ? ' <span style="color:var(--muted);font-weight:400;font-size:12px">· ' + esc(ev.course_short) + '</span>' : '') +
        '</span>' +
        '<span style="color:var(--teal-d);font-weight:700;font-size:12.5px;white-space:nowrap">' + ev.duration_h + ' h</span>' +
        '<span class="visio-tag">visio</span>' +
        '<a class="btn mini orange-btn" href="' + esc(glowblJoinUrl(ev.courseid)) + '" target="_blank" rel="noopener">Rejoindre</a>' +
      '</div>';
    }).join('');
  } else if (!cohortWeeks.length) {
    seancesEl.innerHTML = '<p style="color:var(--muted);font-size:13px">Aucune cohorte active — vérifiez la date de rentrée (champ idnumber).</p>';
  } else {
    // Repli LIA : blocs calculés sans heure précise
    seancesEl.innerHTML = cohortWeeks.map(function(cw, i){
      var wtypeColor = {c:'var(--teal)',s:'var(--muted)',r:'var(--violet)',rs:'var(--violet)',e1:'var(--orange)',e:'var(--orange)'}[cw.wtype]||'var(--muted)';
      var chip = '<span style="display:inline-block;background:' + wtypeColor + '22;color:' + wtypeColor + ';border:1px solid ' + wtypeColor + '44;font-size:10.5px;font-weight:700;border-radius:99px;padding:2px 9px">' + esc(cw.label) + '</span>';
      var header = '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px">' +
        '<b style="font-size:14px;color:var(--ink)">' + esc(cw.name) + '</b>' +
        '<span style="color:var(--muted);font-size:12px">Sem. ' + cw.rel + '/35</span>' + chip +
        (cw.nb ? '<span style="color:var(--muted);font-size:12px">· ' + cw.nb + ' apprenant' + (cw.nb>1?'s':'') + '</span>' : '') +
      '</div>';
      var body = '';
      if (!cw.blocs.length) {
        var icons = {s:'🏢',r:'🏫',rs:'🏫',e:'📝',e1:'📝'};
        body = '<p style="color:var(--muted);font-size:13px;margin:0">' + (icons[cw.wtype]||'') + ' ' + esc(cw.label) + ' — pas de cours à distance cette semaine.</p>';
      } else {
        body = cw.blocs.map(function(b){
          return '<div class="event" style="align-items:center">' +
            '<span class="lw" style="color:var(--teal-d);min-width:38px;font-size:13px;font-weight:800">' + LIA_SESSION_H + ' h</span>' +
            '<span class="info" style="font-size:13.5px">' + esc(b.n) + '</span>' +
          '</div>';
        }).join('');
        body += '<div style="margin-top:10px"><a class="btn mini orange-btn" href="' + esc(GLOWBL) + '" target="_blank" rel="noopener">&#9654; Rejoindre Glowbl</a></div>';
      }
      var sep = i > 0 ? '<hr style="border:none;border-top:1px solid var(--line);margin:14px 0">' : '';
      return sep + '<div>' + header + body + '</div>';
    }).join('');
  }


  // Signal à traiter — cartes actionnables
  var liaEl = document.getElementById('lia-list');
  if (!DATA.signal_lia.length) {
    liaEl.innerHTML = '<div style="background:var(--teal-t);border:1px solid var(--teal);border-radius:10px;padding:12px 14px;font-size:13px;font-weight:700;color:var(--teal-d)">✓ Tous vos apprenants sont actifs</div>';
  } else {
    liaEl.innerHTML = DATA.signal_lia.map(function(l){
      var cohBadge = l.cohort ? '<span style="font-size:11px;font-weight:500;color:var(--muted)"> · ' + esc(l.cohort) + '</span>' : '';
      // Tags signaux
      var tags = [];
      tags.push(l.days === '?'
        ? '<span class="sig-tag t-red">Jamais connecté</span>'
        : '<span class="sig-tag t-red">Inactif ' + l.days + ' j</span>');
      if (typeof l.pct === 'number' && l.pct < 30) {
        tags.push('<span class="sig-tag t-orange">Progression ' + l.pct + '%</span>');
      }
      var corrCount = (DATA.corrections || []).filter(function(c){ return c.userid === l.id; }).length;
      if (corrCount > 0) {
        tags.push('<span class="sig-tag t-blue">' + corrCount + ' devoir' + (corrCount > 1 ? 's' : '') + ' à noter</span>');
      }
      return '<div class="sig-card sig-orange">' +
        '<div class="sig-student">' + esc(l.nom) + cohBadge + '</div>' +
        '<div class="sig-tags">' + tags.join('') + '</div>' +
        '<div class="sig-acts">' +
          '<button type="button" class="btn mini ghost" onclick="goToStudent(' + l.id + ')">Fiche</button>' +
          '<button type="button" class="btn mini ghost" onclick="openMessageModal(' + l.id + ',\'' + esc(l.nom).replace(/'/g, "\\'") + '\')">Message</button>' +
        '</div>' +
      '</div>';
    }).join('');
  }

}

/* ─────────────────────────────────────────
   VUE PLANNING
───────────────────────────────────────── */
function renderPlan() {
  var cohorts  = DATA.cohorts || [];
  var cap      = DATA.meta.capacity || 28;
  var cur      = DATA.current_week || 1;
  var vehWeeks = DATA.veh_weeks || [7, 13, 19];
  var myGrid   = DATA.my_grid || {};
  // Un vrai plan Lia n'existe que s'il y a au moins une classe avec date de
  // rentrée connue de tout le monde ; sinon on retombe sur l'estimation
  // théorique (curriculum seul, sans arbitrage réel).
  var hasPlan  = Array.isArray(DATA.my_week_hours) && DATA.my_week_hours.length > 0;

  function barColor(h) {
    if (h <= 0)   return '#E3F2EF';
    if (h < 15)   return '#7CC9AC';
    if (h < 25)   return '#2E9488';
    if (h <= cap) return '#F2A03D';
    return '#D64545';
  }

  // Heures réelles calculées par le moteur Lia (fallback : estimation théorique)
  var weekHours;
  if (hasPlan) {
    weekHours = DATA.my_week_hours.map(function(h) { return Math.round(h * 10) / 10; });
  } else {
    weekHours = [];
    for (var w = 1; w <= 35; w++) {
      var info = liaGetWeekBlocs(w);
      weekHours.push(info.blocs.length * LIA_SESSION_H * Math.max(cohorts.length, 1));
    }
  }
  var maxH  = Math.max.apply(null, weekHours) || 1;
  var MAXPX = 110;

  var numsHtml = '', barsHtml = '', labsHtml = '';
  weekHours.forEach(function(h, i) {
    var w     = i + 1;
    var isCur = (w === cur);
    var isVeh = vehWeeks.indexOf(w) !== -1;
    var isLia = hasPlan && (myGrid[w] || []).some(function(e) { return e.lia; });
    var px    = h > 0 ? Math.max(6, Math.round(h / maxH * MAXPX)) : 4;

    numsHtml += '<span class="' + (h === 0 ? 'pn-empty' : '') + '">' + (h > 0 ? Math.round(h) : '') +
      (isLia ? ' <span style="color:var(--violet)">✦</span>' : '') + '</span>';
    barsHtml += '<div class="strip-bar' + (isCur ? ' cur-week' : '') + '"' +
      ' style="height:' + px + 'px;background:' + barColor(h) + '"' +
      ' title="S' + w + (isVeh ? ' · Regroupement véhicule' : '') + (isLia ? ' · ✦ ajusté par Lia' : '') + ' · ' + Math.round(h) + 'h"></div>';
    labsHtml += '<span' + (isCur ? ' class="cur-lbl"' : '') + '>' + w + '</span>';
  });

  document.getElementById('plan-nums').innerHTML   = numsHtml;
  document.getElementById('plan-bars').innerHTML   = barsHtml;
  document.getElementById('plan-labels').innerHTML = labsHtml;

  document.getElementById('plan-sub').textContent =
    'Votre charge semaine par semaine. Capacité contractuelle : ' + cap + ' h. Violet = cette semaine.' +
    (hasPlan ? ' ✦ = ajustement Lia.' : '');

  // Alerte semaines surchargees
  var alertEl     = document.getElementById('plan-alert');
  var overloadedI = weekHours.map(function(h, i){ return h > cap ? (i + 1) : null; }).filter(function(w){ return w !== null; });
  var overloaded  = overloadedI.map(function(w){ return 'S' + w; });
  if (overloaded.length) {
    var hasVeh   = overloadedI.some(function(w){ return vehWeeks.indexOf(w) !== -1; });
    var realTxts = hasPlan
      ? (DATA.my_log || []).concat(DATA.my_alerts || []).filter(function(l){ return overloadedI.indexOf(l.w) !== -1; }).map(function(l){ return l.txt; })
      : [];
    alertEl.style.display = '';
    if (realTxts.length) {
      alertEl.innerHTML = realTxts.slice(0, 3).map(function(t){ return '<div>' + esc(t) + '</div>'; }).join('');
    } else {
      alertEl.innerHTML = '<b>' + overloaded.length + ' semaine' + (overloaded.length > 1 ? 's' : '') +
        ' au-dessus de ' + cap + ' h</b> (' + overloaded.join(', ') + ')' +
        (hasVeh ? ' — semaines véhicule. Lia a soumis une re-répartition ' +
          '<span style="background:var(--amber,#F6C685);color:var(--amber-d,#8a5a10);' +
          'font-weight:800;font-size:10.5px;padding:2px 8px;border-radius:99px;margin-left:4px">en arbitrage</span>' : '');
    }
  } else {
    alertEl.style.display = 'none';
  }

  // Detail semaine courante
  var titleEl  = document.getElementById('plan-week-title');
  var listEl   = document.getElementById('plan-week-list');
  var curDates = cohorts.length ? liaWeekDates(cohorts[0].startdate, cur) : '';
  var dtLabel  = curDates ? curDates.split(' → ')[0].toUpperCase() : ('S' + cur + '/35');

  if (hasPlan) {
    var curEntries = myGrid[cur] || [];
    var totalH = curEntries.reduce(function(s, e) { return s + e.h; }, 0);
    titleEl.textContent = 'SEMAINE DU ' + dtLabel + (totalH > 0 ? ' · ' + Math.round(totalH) + ' H' : '');

    if (!curEntries.length) {
      listEl.innerHTML = '<p style="color:var(--muted);font-size:13px;padding:4px 0">Rien de planifié cette semaine.</p>';
    } else {
      var tagClass = function(t) { return t === 'veh' ? 'veh-tag' : t === 'salle' ? 'salle-tag' : 'visio-tag'; };
      var tagLabel = function(t) { return t === 'veh' ? 'véhicule' : t === 'salle' ? 'salle' : 'visio'; };
      listEl.innerHTML = curEntries.map(function(e) {
        return '<div class="plan-session">' +
          '<div class="plan-session-name">' + esc(e.label) + (e.lia ? ' <span style="color:var(--violet)">✦</span>' : '') +
            ' <span class="plan-session-cls">· classe ' + esc(e.coh) + '</span>' +
          '</div>' +
          '<span class="plan-session-h">' + Math.round(e.h * 2) / 2 + ' h</span>' +
          '<span class="' + tagClass(e.type) + '">' + tagLabel(e.type) + '</span>' +
        '</div>';
      }).join('');
    }
  } else {
    // Repli : pas de plan Lia calculable (ex. aucune classe avec date de rentrée) — estimation théorique
    var curInfo = liaGetWeekBlocs(cur);
    var totalH  = curInfo.blocs.length * LIA_SESSION_H * cohorts.length;
    titleEl.textContent = 'SEMAINE DU ' + dtLabel + (totalH > 0 ? ' · ' + totalH + ' H' : '');

    if (!curInfo.blocs.length) {
      listEl.innerHTML = '<p style="color:var(--muted);font-size:13px;padding:4px 0">' +
        esc(LIA_WLABELS[curInfo.wtype] || 'Semaine spéciale') + ' — pas de cours à distance.</p>';
    } else {
      var rows = [];
      cohorts.forEach(function(cohort) {
        curInfo.blocs.forEach(function(b) {
          rows.push(
            '<div class="plan-session">' +
              '<div class="plan-session-name">' + esc(b.n) +
                ' <span class="plan-session-cls">· ' + esc(cohort.name) + '</span>' +
              '</div>' +
              '<span class="plan-session-h">' + LIA_SESSION_H + ' h</span>' +
              '<span class="visio-tag">visio</span>' +
            '</div>'
          );
        });
      });
      listEl.innerHTML = rows.join('');
    }
  }
}

/* ─────────────────────────────────────────
   VUE CORRECTIONS
───────────────────────────────────────── */
function renderCorr() {
  var n = DATA.corrections.length;
  document.getElementById('corr-sub').textContent = n > 0
    ? n + ' correction' + (n > 1 ? 's' : '') + ' en attente.'
    : 'Aucune correction en attente.';

  var el = document.getElementById('corr-list');
  if (!n) {
    el.innerHTML = '<div class="okbox">Aucune correction en attente ✓</div>';
    return;
  }
  el.innerHTML = DATA.corrections.map(function(c, i){
    var nom  = esc(c.firstname + ' ' + c.lastname);
    var link = WWWROOT + '/mod/assign/view.php?id=' + c.assignid + '&action=grading';
    return '<div class="cor" id="cor-' + i + '">' +
      '<div class="cor-name">' + nom + '</div>' +
      '<div class="cor-assign">Devoir : ' + esc(c.assign_name) + '</div>' +
      '<div class="cor-date">Déposé le ' + esc(c.date_label) + '</div>' +
      '<div class="cor-acts">' +
        '<a class="btn mini primary" href="' + esc(link) + '" target="_blank" rel="noopener">Ouvrir</a>' +
        '<button class="btn mini ghost" onclick="markCorrected(' + i + ')">Marquer comme corrigé</button>' +
      '</div>' +
    '</div>';
  }).join('');
}

function markCorrected(idx) {
  var el = document.getElementById('cor-' + idx);
  if (el) { el.style.opacity = '.35'; el.style.pointerEvents = 'none'; }
  toast('Marqué comme corrigé — ouvrez Moodle pour valider la note.');
}

/* ─────────────────────────────────────────
   VUE ÉLÈVES
───────────────────────────────────────── */
function renderElev() {
  // Lister les classes distinctes
  var cohNames = [];
  DATA.students.forEach(function(s){
    if (s.cohort_name && cohNames.indexOf(s.cohort_name) < 0) cohNames.push(s.cohort_name);
  });
  document.getElementById('elev-filter-btns').innerHTML = cohNames.map(function(n){
    return '<button class="filter-btn" data-coh="' + esc(n) + '" onclick="filterElev(this)">' + esc(n) + '</button>';
  }).join('');

  renderElevTable();
}

function filterElev(btn) {
  _elevFilter = btn.dataset.coh;
  document.querySelectorAll('.filter-btn').forEach(function(b){ b.classList.remove('on'); });
  btn.classList.add('on');
  renderElevTable();
}

/* Depuis "Signal à traiter" (tableau de bord) : ouvre la page Élèves et
   met en évidence la ligne de cet élève. */
function goToStudent(uid) {
  var navBtn = document.querySelector('aside button[data-v="elev"]');
  if (navBtn) nav(navBtn);

  var allBtn = document.querySelector('.filter-btn[data-coh="all"]');
  if (allBtn && !allBtn.classList.contains('on')) {
    filterElev(allBtn);
  }

  setTimeout(function() {
    var row = document.querySelector('#elev-tbody tr[data-uid="' + uid + '"]');
    if (!row) return;
    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    row.classList.add('row-highlight');
    setTimeout(function(){ row.classList.remove('row-highlight'); }, 2000);
  }, 60);
}

function renderElevTable() {
  var list = _elevFilter === 'all'
    ? DATA.students
    : DATA.students.filter(function(s){ return s.cohort_name === _elevFilter; });

  var tbody = document.getElementById('elev-tbody');
  if (!list.length) {
    tbody.innerHTML = '<tr><td colspan="5" style="color:var(--muted);text-align:center;padding:20px">Aucun élève trouvé.</td></tr>';
    return;
  }

  tbody.innerHTML = list.map(function(s){
    var rowCls  = s.inactive ? ' class="row-inactive"' : '';
    var inact   = s.inactive ? ' <span class="chip c-ko">&gt;7 j inactif</span>' : '';
    var coh     = s.cohort_name ? esc(s.cohort_name) : '<span style="color:var(--muted)">—</span>';
    var pctClr  = s.pct < 20 ? 'color:var(--red);font-weight:700' : '';
    var bar     = '<span class="bar"><i style="width:' + s.pct + '%"></i></span>' +
                  ' <span style="' + pctClr + '">' + s.pct + ' %</span>';
    return '<tr data-uid="' + s.id + '"' + rowCls + '>' +
      '<td>' + esc(s.firstname + ' ' + s.lastname) + inact + '</td>' +
      '<td>' + coh + '</td>' +
      '<td>' + bar + '</td>' +
      '<td>' + esc(s.last_access) + '</td>' +
      '<td style="display:flex;gap:6px;align-items:center">' +
        '<button class="btn mini ghost" onclick="toast(\'Point avec ' + esc(s.firstname) + ' — Phase 2\')">Point</button>' +
      '</td>' +
    '</tr>';
  }).join('');
}

/* ─────────────────────────────────────────
   VUE DISPOS
───────────────────────────────────────── */
function renderDispo() {
  var meta = DATA.meta;
  document.getElementById('dispo-statut').textContent = meta.type === 'vacataire' ? 'Vacataire' : 'Salarié';
  document.getElementById('dispo-cap').textContent    = meta.capacity + ' h / semaine';

  document.getElementById('dispo-habs').textContent = (meta.habs && meta.habs.length)
    ? meta.habs.map(function(h){ return h.text; }).join(' · ')
    : 'Aucune habilitation renseignée';

  var presRow = document.getElementById('dispo-presentiels-row');
  if (DATA.presentiels_label) {
    document.getElementById('dispo-presentiels').textContent = DATA.presentiels_label;
    presRow.style.display = '';
  } else {
    presRow.style.display = 'none';
  }

  renderDispoList(DATA.my_dispos || []);
}

function addDispo() {
  var from  = document.getElementById('dispo-from').value;
  var to    = document.getElementById('dispo-to').value;
  var motif = document.getElementById('dispo-motif').value;
  if (!from || !to) { toast('Veuillez renseigner les deux dates.'); return; }
  if (to < from)    { toast('La date de fin doit être après le début.'); return; }
  apiPost({action: 'save_dispo', date_from: from, date_to: to, motif: motif}).then(function(d) {
    if (!d.ok) { toast('Erreur: ' + (d.msg || '')); return; }
    DATA.my_dispos = d.dispos;
    document.getElementById('dispo-from').value = '';
    document.getElementById('dispo-to').value   = '';
    renderDispoList(DATA.my_dispos);
    toast('Indisponibilité transmise — en attente de validation.');
  }).catch(function() { toast('Erreur réseau.'); });
}

function removeDispo(id) {
  apiPost({action: 'delete_dispo', id: id}).then(function(d) {
    if (!d.ok) { toast('Erreur: ' + (d.msg || '')); return; }
    DATA.my_dispos = d.dispos;
    renderDispoList(DATA.my_dispos);
    toast('Indisponibilité supprimée.');
  }).catch(function() { toast('Erreur réseau.'); });
}

function renderDispoList(dispos) {
  var el = document.getElementById('dispo-list-el');
  if (!dispos.length) {
    el.innerHTML = '<p style="color:var(--muted);font-size:13px">Aucune déclaration pour l\'instant.</p>';
    return;
  }
  var badges = { pending: ['pending', 'En attente'], validated: ['validated', '✓ Validée — plan ajusté'], rejected: ['rejected', 'Refusée'] };
  el.innerHTML = dispos.map(function(d){
    var b = badges[d.status] || badges.pending;
    var note = (d.status === 'rejected' && d.admin_note) ? ' <span style="color:var(--muted);font-size:11.5px">(' + esc(d.admin_note) + ')</span>' : '';
    return '<div class="dispo-item">' +
      '<span style="flex:1"><b>' + esc(d.date_from) + '</b> → <b>' + esc(d.date_to) + '</b> · ' + esc(d.motif_label) + note + '</span>' +
      '<span class="dispo-badge ' + b[0] + '">' + b[1] + '</span>' +
      (d.status === 'pending' ? '<button class="di-del" onclick="removeDispo(' + d.id + ')" title="Supprimer">✕</button>' : '') +
    '</div>';
  }).join('');
}

/* ─────────────────────────────────────────
   VUE RÉGLAGES
───────────────────────────────────────── */
function renderParams() {
  var meta = DATA.meta;
  document.getElementById('params-role').textContent      = meta.role_label || '—';
  document.getElementById('params-nb-cours').textContent  = DATA.courses.length;
  document.getElementById('params-nb-eleves').textContent = DATA.students.length;
  document.getElementById('params-heure').textContent     = DATA.hours_week + ' h';

  var habsHtml = (meta.habs && meta.habs.length)
    ? meta.habs.map(function(h){ return '<span class="hab-tag" style="' + habStyle(h.color) + '">' + esc(h.text) + '</span>'; }).join('')
    : '<span style="color:var(--muted);font-style:italic;font-size:13px">Aucune habilitation renseignée</span>';
  document.getElementById('params-habs').innerHTML = habsHtml;

  var coursesHtml = DATA.courses.length
    ? DATA.courses.map(function(c) {
        return '<div class="param-row">' +
          '<span class="lbl">' + esc(c.fullname) + '</span>' +
          '<button type="button" class="btn mini ghost" onclick="openCourseIframe(' + c.id + ',\'' + esc(c.fullname).replace(/'/g,"\\'") + '\')">Ajouter du contenu</button>' +
        '</div>';
      }).join('')
    : '<span style="color:var(--muted);font-style:italic;font-size:13px">Aucun cours assigné</span>';
  document.getElementById('params-mes-cours').innerHTML = coursesHtml;
}

/* ─────────────────────────────────────────
   INIT
───────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function(){
  go('jour');
});
</script>
</body>
</html>
