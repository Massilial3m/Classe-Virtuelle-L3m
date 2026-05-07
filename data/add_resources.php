<?php
define('CLI_SCRIPT', true);
require('/var/data/www/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/lib/filestorage/file_storage.php');

cli_heading('Ajout des ressources PDF et vidéos dans les cours ECSR');

global $DB;

$fs      = get_file_storage();
$admin   = get_admin();
$modid   = $DB->get_field('modules', 'id', ['name' => 'resource']);
$urlmodid = $DB->get_field('modules', 'id', ['name' => 'url']);

// ── Helper : créer un module "resource" (fichier PDF) ──────────────────────
function add_pdf_resource($courseid, $sectionnum, $name, $intro, $filepath_on_disk) {
    global $DB, $fs, $admin, $modid;

    $course  = $DB->get_record('course', ['id' => $courseid]);
    $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $sectionnum]);

    // Créer l'instance resource
    $resource = new stdClass();
    $resource->course        = $courseid;
    $resource->name          = $name;
    $resource->intro         = '<p>' . $intro . '</p>';
    $resource->introformat   = FORMAT_HTML;
    $resource->tobemigrated  = 0;
    $resource->legacyfiles   = 0;
    $resource->legacyfileslast = null;
    $resource->display       = 0;
    $resource->displayoptions = '';
    $resource->filterfiles   = 0;
    $resource->revision      = 1;
    $resource->timemodified  = time();
    $resourceid = $DB->insert_record('resource', $resource);

    // Créer le course module
    $cm = new stdClass();
    $cm->course             = $courseid;
    $cm->module             = $modid;
    $cm->instance           = $resourceid;
    $cm->section            = $section->id;
    $cm->visible            = 1;
    $cm->visibleoncoursepage = 1;
    $cm->groupmode          = 0;
    $cm->groupingid         = 0;
    $cm->completion         = 1;
    $cm->completionview     = 1;
    $cm->completiongradeitemnumber = null;
    $cm->completionpassgrade = 0;
    $cm->completionexpected = 0;
    $cm->showdescription    = 0;
    $cm->deletioninprogress = 0;
    $cm->added              = time();
    $cm->lang               = '';
    $cm->enableaitools      = 0;
    $cm->enabledaiactions   = null;
    $cmid = $DB->insert_record('course_modules', $cm);

    // Ajouter au séquencement de la section
    $seq = trim($section->sequence ?? '');
    $seq = $seq ? $seq . ',' . $cmid : (string)$cmid;
    $DB->set_field('course_sections', 'sequence', $seq, ['id' => $section->id]);

    // Stocker le fichier dans Moodle file storage
    $context = context_module::instance($cmid);
    $filename = basename($filepath_on_disk);

    $file_record = [
        'contextid' => $context->id,
        'component' => 'mod_resource',
        'filearea'  => 'content',
        'itemid'    => 0,
        'filepath'  => '/',
        'filename'  => $filename,
        'timecreated'  => time(),
        'timemodified' => time(),
        'userid'    => $admin->id,
        'author'    => 'L3M Formation',
        'license'   => 'allrightsreserved',
    ];
    $fs->create_file_from_pathname($file_record, $filepath_on_disk);

    rebuild_course_cache($courseid, true);
    return $cmid;
}

// ── Helper : créer un module "url" (lien vidéo) ───────────────────────────
function add_url_resource($courseid, $sectionnum, $name, $intro, $url) {
    global $DB, $admin, $urlmodid;

    $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $sectionnum]);

    $urlrec = new stdClass();
    $urlrec->course        = $courseid;
    $urlrec->name          = $name;
    $urlrec->intro         = '<p>' . $intro . '</p>';
    $urlrec->introformat   = FORMAT_HTML;
    $urlrec->externalurl   = $url;
    $urlrec->display       = 0;
    $urlrec->displayoptions = '';
    $urlrec->parameters    = '';
    $urlrec->timemodified  = time();
    $urlid = $DB->insert_record('url', $urlrec);

    $cm = new stdClass();
    $cm->course             = $courseid;
    $cm->module             = $urlmodid;
    $cm->instance           = $urlid;
    $cm->section            = $section->id;
    $cm->visible            = 1;
    $cm->visibleoncoursepage = 1;
    $cm->groupmode          = 0;
    $cm->groupingid         = 0;
    $cm->completion         = 1;
    $cm->completionview     = 1;
    $cm->completiongradeitemnumber = null;
    $cm->completionpassgrade = 0;
    $cm->completionexpected = 0;
    $cm->showdescription    = 1;
    $cm->deletioninprogress = 0;
    $cm->added              = time();
    $cm->lang               = '';
    $cm->enableaitools      = 0;
    $cm->enabledaiactions   = null;
    $cmid = $DB->insert_record('course_modules', $cm);

    $seq = trim($section->sequence ?? '');
    $seq = $seq ? $seq . ',' . $cmid : (string)$cmid;
    $DB->set_field('course_sections', 'sequence', $seq, ['id' => $section->id]);

    rebuild_course_cache($courseid, true);
    return $cmid;
}

// ── PDF DIR ───────────────────────────────────────────────────────────────
$pdf_dir = '/var/data/pdf_resources';

// ── CCP1 M1 – Section 1 : PDF cadre juridique + vidéo ────────────────────
$course_m1_ccp1 = $DB->get_record('course', ['shortname' => 'ECSR-CCP1-M1']);

$cmid = add_pdf_resource(
    $course_m1_ccp1->id, 1,
    'Support de cours – Cadre juridique et réglementaire',
    'Document PDF complet sur les textes réglementaires, les institutions et les obligations de l\'enseignant ECSR.',
    $pdf_dir . '/CCP1-M1-cadre-juridique.pdf'
);
cli_writeln("✓ PDF ajouté dans CCP1-M1 section 1 (cm=$cmid)");

$cmid = add_url_resource(
    $course_m1_ccp1->id, 2,
    'Vidéo – Le permis de conduire en France : cadre réglementaire',
    'Présentation du cadre légal de la formation à la conduite et du rôle des institutions.',
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ'
);
cli_writeln("✓ Vidéo ajoutée dans CCP1-M1 section 2 (cm=$cmid)");

// ── CCP1 M2 – Section 1 : PDF pédagogie + vidéo ──────────────────────────
$course_m2_ccp1 = $DB->get_record('course', ['shortname' => 'ECSR-CCP1-M2']);

$cmid = add_pdf_resource(
    $course_m2_ccp1->id, 1,
    'Support de cours – Pédagogie et techniques d\'animation',
    'Document PDF sur l\'andragogie, les méthodes pédagogiques actives et la posture du formateur ECSR.',
    $pdf_dir . '/CCP1-M2-pedagogie.pdf'
);
cli_writeln("✓ PDF ajouté dans CCP1-M2 section 1 (cm=$cmid)");

$cmid = add_url_resource(
    $course_m2_ccp1->id, 2,
    'Vidéo – Techniques pédagogiques pour la formation à la conduite',
    'Introduction aux méthodes d\'animation pédagogique adaptées à l\'enseignement de la conduite.',
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ'
);
cli_writeln("✓ Vidéo ajoutée dans CCP1-M2 section 2 (cm=$cmid)");

// ── CCP2 M4 – Section 1 : PDF gestion administrative ─────────────────────
$course_m4_ccp2 = $DB->get_record('course', ['shortname' => 'ECSR-CCP2-M4']);

$cmid = add_pdf_resource(
    $course_m4_ccp2->id, 1,
    'Support de cours – Gestion administrative et financière',
    'Document PDF sur les obligations administratives, le CPF, Qualiopi et le RGPD pour les auto-écoles.',
    $pdf_dir . '/CCP2-M4-gestion-administrative.pdf'
);
cli_writeln("✓ PDF ajouté dans CCP2-M4 section 1 (cm=$cmid)");

$cmid = add_url_resource(
    $course_m4_ccp2->id, 2,
    'Vidéo – Comprendre la certification Qualiopi',
    'Présentation de la certification Qualiopi, ses exigences et son impact sur le financement des formations.',
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ'
);
cli_writeln("✓ Vidéo ajoutée dans CCP2-M4 section 2 (cm=$cmid)");

purge_all_caches();
cli_writeln('');
cli_writeln('Ressources ajoutées avec succès. Caches purgés.');
