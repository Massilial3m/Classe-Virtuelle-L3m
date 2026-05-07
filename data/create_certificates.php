<?php
define('CLI_SCRIPT', true);
require('/var/data/www/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');

cli_heading('Création des certificats ECSR – L3M Formation');

global $DB;

$modid = $DB->get_field('modules', 'id', ['name' => 'customcert']);
if (!$modid) {
    cli_error('Module customcert introuvable.');
}

// Supprimer les anciens certificats mal créés
$old_certs = $DB->get_records_sql("
    SELECT c.id, c.templateid FROM {customcert} c
    JOIN {course} co ON co.id = c.course
    JOIN {course_categories} cc ON cc.id = co.category
    WHERE cc.idnumber LIKE 'ECSR%'
");
foreach ($old_certs as $old) {
    if ($old->templateid) {
        $pages = $DB->get_records('customcert_pages', ['templateid' => $old->templateid]);
        foreach ($pages as $page) {
            $DB->delete_records('customcert_elements', ['pageid' => $page->id]);
        }
        $DB->delete_records('customcert_pages', ['templateid' => $old->templateid]);
        $DB->delete_records('customcert_templates', ['id' => $old->templateid]);
    }
    $DB->delete_records('customcert_issues', ['customcertid' => $old->id]);
    // Supprimer le course module associé
    $cm = $DB->get_record('course_modules', ['module' => $modid, 'instance' => $old->id]);
    if ($cm) {
        $section = $DB->get_record('course_sections', ['id' => $cm->section]);
        if ($section && $section->sequence) {
            $seq = array_filter(explode(',', $section->sequence), fn($v) => trim($v) != $cm->id);
            $DB->set_field('course_sections', 'sequence', implode(',', $seq), ['id' => $section->id]);
        }
        $DB->delete_records('course_modules', ['id' => $cm->id]);
    }
    $DB->delete_records('customcert', ['id' => $old->id]);
}
cli_writeln('Anciens certificats supprimés.');

$courses = $DB->get_records_sql("
    SELECT c.id, c.shortname, c.fullname
    FROM {course} c
    JOIN {course_categories} cc ON cc.id = c.category
    WHERE cc.idnumber LIKE 'ECSR%'
    ORDER BY c.id
");

foreach ($courses as $course) {
    $section   = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 0]);
    $contextid = context_course::instance($course->id)->id;

    // 1. Créer le template
    $tpl = new stdClass();
    $tpl->name        = 'Attestation ECSR';
    $tpl->contextid   = $contextid;
    $tpl->timecreated  = time();
    $tpl->timemodified = time();
    $templateid = $DB->insert_record('customcert_templates', $tpl);

    // 2. Créer la page (A4 paysage)
    $page = new stdClass();
    $page->templateid   = $templateid;
    $page->width        = 297;
    $page->height       = 210;
    $page->leftmargin   = 0;
    $page->rightmargin  = 0;
    $page->sequence     = 1;
    $page->timecreated  = time();
    $page->timemodified = time();
    $pageid = $DB->insert_record('customcert_pages', $page);

    // 3. Éléments du certificat
    $elements = [
        [
            'name'    => 'Bordure',
            'element' => 'border',
            'data'    => json_encode(['colour' => '#C9A227', 'width' => 2]),
            'posx' => 0, 'posy' => 0, 'alignment' => 'L', 'sequence' => 1,
        ],
        [
            'name'    => 'Organisme',
            'element' => 'text',
            'data'    => json_encode(['value' => 'L3M Formation – Lindberg Formation', 'font' => 'helveticaB', 'fontsize' => 11, 'colour' => '#C9A227', 'width' => 0]),
            'posx' => 90, 'posy' => 15, 'alignment' => 'L', 'sequence' => 2,
        ],
        [
            'name'    => 'Titre',
            'element' => 'text',
            'data'    => json_encode(['value' => 'ATTESTATION DE FORMATION', 'font' => 'helveticaB', 'fontsize' => 26, 'colour' => '#1A1A1A', 'width' => 0]),
            'posx' => 55, 'posy' => 38, 'alignment' => 'L', 'sequence' => 3,
        ],
        [
            'name'    => 'Sous-titre',
            'element' => 'text',
            'data'    => json_encode(['value' => 'Titre Professionnel ECSR – RNCP 35329', 'font' => 'helvetica', 'fontsize' => 13, 'colour' => '#C9A227', 'width' => 0]),
            'posx' => 80, 'posy' => 58, 'alignment' => 'L', 'sequence' => 4,
        ],
        [
            'name'    => 'Intro',
            'element' => 'text',
            'data'    => json_encode(['value' => 'Il est certifié que', 'font' => 'helvetica', 'fontsize' => 12, 'colour' => '#555555', 'width' => 0]),
            'posx' => 118, 'posy' => 83, 'alignment' => 'L', 'sequence' => 5,
        ],
        [
            'name'    => 'Nom apprenant',
            'element' => 'studentname',
            'data'    => json_encode(['font' => 'helveticaB', 'fontsize' => 22, 'colour' => '#1A1A1A', 'width' => 0]),
            'posx' => 70, 'posy' => 96, 'alignment' => 'L', 'sequence' => 6,
        ],
        [
            'name'    => 'Texte formation',
            'element' => 'text',
            'data'    => json_encode(['value' => 'a suivi et validé la formation :', 'font' => 'helvetica', 'fontsize' => 12, 'colour' => '#555555', 'width' => 0]),
            'posx' => 100, 'posy' => 118, 'alignment' => 'L', 'sequence' => 7,
        ],
        [
            'name'    => 'Nom cours',
            'element' => 'coursename',
            'data'    => json_encode(['value' => 0, 'font' => 'helveticaB', 'fontsize' => 14, 'colour' => '#C9A227', 'width' => 0]),
            'posx' => 50, 'posy' => 131, 'alignment' => 'L', 'sequence' => 8,
        ],
        [
            'name'    => 'Date',
            'element' => 'date',
            'data'    => json_encode(['dateitem' => '-1', 'dateformat' => 'j F Y', 'font' => 'helvetica', 'fontsize' => 10, 'colour' => '#888888', 'width' => 0]),
            'posx' => 115, 'posy' => 158, 'alignment' => 'L', 'sequence' => 9,
        ],
        [
            'name'    => 'Pied de page',
            'element' => 'text',
            'data'    => json_encode(['value' => 'www.l3m-formation.fr  |  contact@l3m-formation.fr', 'font' => 'helvetica', 'fontsize' => 9, 'colour' => '#888888', 'width' => 0]),
            'posx' => 90, 'posy' => 195, 'alignment' => 'L', 'sequence' => 10,
        ],
    ];

    foreach ($elements as $el) {
        $e = new stdClass();
        $e->pageid       = $pageid;
        $e->name         = $el['name'];
        $e->element      = $el['element'];
        $e->data         = $el['data'];
        $e->posx         = $el['posx'];
        $e->posy         = $el['posy'];
        $e->refpoint     = 0;
        $e->alignment    = $el['alignment'];
        $e->sequence     = $el['sequence'];
        $e->timecreated  = time();
        $e->timemodified = time();
        $DB->insert_record('customcert_elements', $e);
    }

    // 4. Créer l'instance customcert liée au template
    $cert = new stdClass();
    $cert->course          = $course->id;
    $cert->templateid      = $templateid;
    $cert->name            = 'Attestation de formation';
    $cert->intro           = '<p>Votre attestation est générée automatiquement à la fin de cette formation.</p>';
    $cert->introformat     = FORMAT_HTML;
    $cert->requiredtime    = 0;
    $cert->verifyany       = 0;
    $cert->deliveryoption  = 0;
    $cert->usecustomfilename = 0;
    $cert->customfilenamepattern = '';
    $cert->emailstudents   = 1;
    $cert->emailteachers   = 0;
    $cert->emailothers     = '';
    $cert->protection      = '';
    $cert->language        = '';
    $cert->timecreated     = time();
    $cert->timemodified    = time();
    $cert->id = $DB->insert_record('customcert', $cert);

    // 5. Course module
    $cm = new stdClass();
    $cm->course             = $course->id;
    $cm->module             = $modid;
    $cm->instance           = $cert->id;
    $cm->section            = $section->id;
    $cm->visible            = 1;
    $cm->visibleoncoursepage = 1;
    $cm->groupmode          = 0;
    $cm->groupingid         = 0;
    $cm->completion         = 2;
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

    $seq = trim($section->sequence ?? '');
    $seq = $seq ? $seq . ',' . $cmid : (string)$cmid;
    $DB->set_field('course_sections', 'sequence', $seq, ['id' => $section->id]);

    rebuild_course_cache($course->id, true);
    cli_writeln("✓ {$course->shortname} (cm=$cmid)");
}

purge_all_caches();
cli_writeln('');
cli_writeln('Certificats créés avec succès dans tous les cours ECSR.');
