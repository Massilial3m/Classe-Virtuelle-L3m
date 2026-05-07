<?php
define('CLI_SCRIPT', true);
require('/var/data/www/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');

cli_heading('Ajout de l\'émargement numérique dans les cours ECSR');

global $DB;

$modid = $DB->get_field('modules', 'id', ['name' => 'attendance']);

function add_attendance_to_course($courseid, $sectionnum, $name) {
    global $DB, $modid;

    $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $sectionnum]);

    // Créer l'instance attendance
    $att = new stdClass();
    $att->course        = $courseid;
    $att->name          = $name;
    $att->intro         = '<p>Émargement numérique obligatoire. Veuillez valider votre présence à chaque séance.</p>';
    $att->introformat   = FORMAT_HTML;
    $att->grade         = 100;
    $att->timemodified  = time();
    $att->sessiondetailspos = 'left';
    $att->showextrauserdetails = 1;
    $att->showsessiondetails = 1;
    $att->subnet        = '';
    $att->autoassignstatus = 0;
    $att->preventsharedip = 0;
    $att->preventsharediptime = 0;
    $att->id = $DB->insert_record('attendance', $att);

    // Créer le course module
    $cm = new stdClass();
    $cm->course             = $courseid;
    $cm->module             = $modid;
    $cm->instance           = $att->id;
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

    // Ajouter en tête de section (position 0)
    $seq = trim($section->sequence ?? '');
    $seq = $seq ? $cmid . ',' . $seq : (string)$cmid;
    $DB->set_field('course_sections', 'sequence', $seq, ['id' => $section->id]);

    rebuild_course_cache($courseid, true);
    return $cmid;
}

// Ajouter dans tous les cours ECSR — section 0 (général)
$ecsr_courses = $DB->get_records_sql("
    SELECT c.id, c.shortname, c.fullname
    FROM {course} c
    JOIN {course_categories} cc ON cc.id = c.category
    WHERE cc.idnumber LIKE 'ECSR%'
    ORDER BY c.id
");

foreach ($ecsr_courses as $course) {
    $cmid = add_attendance_to_course($course->id, 0, 'Émargement – ' . $course->fullname);
    cli_writeln("✓ {$course->shortname} — émargement ajouté (cm=$cmid)");
}

purge_all_caches();
cli_writeln('');
cli_writeln('Émargement numérique ajouté dans les ' . count($ecsr_courses) . ' cours ECSR.');
