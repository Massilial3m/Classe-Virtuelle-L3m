<?php
define('CLI_SCRIPT', true);
require('/var/data/www/config.php');
require_once('/var/data/www/public/course/modlib.php');
require_once('/var/data/www/public/course/lib.php');

cli_heading('Configuration Classe virtuelle BBB (liens URL) – L3M Formation');

global $DB, $USER;
$USER = get_admin();

// Supprimer les activités BBB plugin existantes si présentes
$bbb_mod = $DB->get_record('modules', ['name' => 'bigbluebuttonbn']);
if ($bbb_mod) {
    $cms = $DB->get_records('course_modules', ['module' => $bbb_mod->id]);
    foreach ($cms as $cm) {
        $sections = $DB->get_records('course_sections', ['course' => $cm->course]);
        foreach ($sections as $s) {
            $items = array_filter(explode(',', $s->sequence), function($x) use ($cm) { return trim($x) !== (string)$cm->id; });
            $DB->set_field('course_sections', 'sequence', implode(',', array_values($items)), ['id' => $s->id]);
        }
        $DB->delete_records('bigbluebuttonbn', ['id' => $cm->instance]);
        $DB->delete_records('bigbluebuttonbn_logs', ['bigbluebuttonbnid' => $cm->instance]);
        $DB->delete_records('course_modules', ['id' => $cm->id]);
        cli_writeln('Supprimé BBB plugin cm=' . $cm->id . ' (course=' . $cm->course . ')');
    }
}

// Supprimer les liens URL classe virtuelle existants
$url_mod = $DB->get_record('modules', ['name' => 'url']);
if (!$url_mod) {
    cli_error('Module URL non trouvé.');
}
$existing_urls = $DB->get_records('url', null, '', 'id');
foreach ($existing_urls as $u) {
    $url_record = $DB->get_record('url', ['id' => $u->id]);
    if ($url_record && strpos($url_record->externalurl, 'bigbluebutton.com') !== false) {
        $cm = $DB->get_record('course_modules', ['module' => $url_mod->id, 'instance' => $u->id]);
        if ($cm) {
            $sections = $DB->get_records('course_sections', ['course' => $cm->course]);
            foreach ($sections as $s) {
                $items = array_filter(explode(',', $s->sequence), function($x) use ($cm) { return trim($x) !== (string)$cm->id; });
                $DB->set_field('course_sections', 'sequence', implode(',', array_values($items)), ['id' => $s->id]);
            }
            $DB->delete_records('course_modules', ['id' => $cm->id]);
        }
        $DB->delete_records('url', ['id' => $u->id]);
        cli_writeln('Supprimé lien URL BBB instance=' . $u->id);
    }
}

// Lien Greenlight BBB (salle partagée pour tous les cours ECSR)
$bbb_link = 'https://my.bigbluebutton.com/rooms/xtg-hjs-jqm-yuq/join';

$courses_data = [
    ['id' => 2,  'name' => 'Classe virtuelle – M1 Cadre juridique'],
    ['id' => 3,  'name' => 'Classe virtuelle – M2 Pédagogie et animation'],
    ['id' => 4,  'name' => 'Classe virtuelle – M3 Conception des séances'],
    ['id' => 5,  'name' => 'Classe virtuelle – M4 Animation milieu ouvert'],
    ['id' => 6,  'name' => 'Classe virtuelle – M5 Évaluation et suivi'],
    ['id' => 7,  'name' => 'Classe virtuelle – CCP1 Simulations examen'],
    ['id' => 8,  'name' => 'Classe virtuelle – M1 Veille réglementaire'],
    ['id' => 9,  'name' => 'Classe virtuelle – M2 Relation client'],
    ['id' => 10, 'name' => 'Classe virtuelle – M3 Développement commercial'],
    ['id' => 11, 'name' => 'Classe virtuelle – M4 Gestion administrative'],
    ['id' => 12, 'name' => 'Classe virtuelle – CCP2 Simulations examen'],
];

cli_writeln('');
cli_writeln('Création des liens Classe virtuelle...');

foreach ($courses_data as $entry) {
    $course = $DB->get_record('course', ['id' => $entry['id']], '*', MUST_EXIST);

    $moduleinfo = new stdClass();
    $moduleinfo->modulename  = 'url';
    $moduleinfo->module      = $url_mod->id;
    $moduleinfo->course      = $course->id;
    $moduleinfo->section     = 0;
    $moduleinfo->visible     = 1;
    $moduleinfo->name        = $entry['name'];
    $moduleinfo->externalurl = $bbb_link;
    $moduleinfo->display     = 0;
    $moduleinfo->popupwidth  = 620;
    $moduleinfo->popupheight = 450;
    $moduleinfo->intro       = '';
    $moduleinfo->introformat = FORMAT_HTML;

    try {
        $result = add_moduleinfo($moduleinfo, $course);
        cli_writeln('  OK course=' . $course->id . ' "' . $course->shortname . '" → cm=' . $result->coursemodule);
    } catch (Exception $e) {
        cli_writeln('  ERREUR course=' . $course->id . ': ' . $e->getMessage());
    }
}

purge_all_caches();
cli_writeln('');
cli_writeln('Classe virtuelle configurée avec succès.');
