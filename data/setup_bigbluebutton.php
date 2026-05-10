<?php
define('CLI_SCRIPT', true);
require('/var/data/www/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once('/var/data/www/public/course/modlib.php');
require_once('/var/data/www/public/course/lib.php');

cli_heading('Configuration BigBlueButton – L3M Formation');

global $DB, $USER;

// Utiliser l'admin comme auteur
$USER = get_admin();

// Supprimer TOUTES les activités BBB existantes (nettoyage)
$mod = $DB->get_record('modules', ['name' => 'bigbluebuttonbn']);
if (!$mod) {
    cli_error('Module BigBlueButton non trouvé. Vérifiez que le plugin est installé.');
}

$existing_cms = $DB->get_records('course_modules', ['module' => $mod->id]);
if ($existing_cms) {
    cli_writeln('Suppression de ' . count($existing_cms) . ' activité(s) BBB existante(s)...');
    foreach ($existing_cms as $cm) {
        course_delete_module($cm->id);
        cli_writeln('  Supprimé cm=' . $cm->id . ' (course=' . $cm->course . ')');
    }
}

// Cours ECSR avec leurs sections générales (section 0)
$courses_data = [
    ['id' => 2,  'name' => 'M1 – Cadre juridique – Classe virtuelle'],
    ['id' => 3,  'name' => 'M2 – Pédagogie et animation – Classe virtuelle'],
    ['id' => 4,  'name' => 'M3 – Conception des séances – Classe virtuelle'],
    ['id' => 5,  'name' => 'M4 – Animation en milieu ouvert – Classe virtuelle'],
    ['id' => 6,  'name' => 'M5 – Évaluation et suivi – Classe virtuelle'],
    ['id' => 7,  'name' => 'CCP1 – Simulations d\'examen – Classe virtuelle'],
    ['id' => 8,  'name' => 'M1 – Veille réglementaire – Classe virtuelle'],
    ['id' => 9,  'name' => 'M2 – Relation client – Classe virtuelle'],
    ['id' => 10, 'name' => 'M3 – Développement commercial – Classe virtuelle'],
    ['id' => 11, 'name' => 'M4 – Gestion administrative – Classe virtuelle'],
    ['id' => 12, 'name' => 'CCP2 – Simulations d\'examen – Classe virtuelle'],
];

cli_writeln('');
cli_writeln('Création des activités BBB...');

foreach ($courses_data as $entry) {
    $course = $DB->get_record('course', ['id' => $entry['id']], '*', MUST_EXIST);

    $moduleinfo = new stdClass();
    $moduleinfo->modulename     = 'bigbluebuttonbn';
    $moduleinfo->module         = $mod->id;
    $moduleinfo->course         = $course->id;
    $moduleinfo->section        = 0;
    $moduleinfo->visible        = 1;
    $moduleinfo->name           = $entry['name'];

    // Paramètres BBB
    $moduleinfo->type                    = 0;   // 0 = room + recordings
    $moduleinfo->meetingid               = '';  // auto-généré
    $moduleinfo->record                  = 1;
    $moduleinfo->recordallfromstart      = 0;
    $moduleinfo->recordhidebutton        = 0;
    $moduleinfo->welcome                 = 'Bienvenue dans votre classe virtuelle ' . $course->shortname . ' !';
    $moduleinfo->userlimit               = 0;   // illimité
    $moduleinfo->wait                    = 1;   // attendre le modérateur
    $moduleinfo->openingtime             = 0;
    $moduleinfo->closingtime             = 0;
    $moduleinfo->muteOnStart             = 1;
    $moduleinfo->disablecam              = 0;
    $moduleinfo->disablemic              = 0;
    $moduleinfo->disableprivatechat      = 0;
    $moduleinfo->disablepublicchat       = 0;
    $moduleinfo->disablenote             = 0;
    $moduleinfo->hideuserlist            = 0;
    $moduleinfo->lockonjoin              = 0;
    $moduleinfo->lockonjoinconfigured    = 0;
    $moduleinfo->completionattendance    = 0;
    $moduleinfo->completionengagement    = 0;
    $moduleinfo->grade                   = 0;
    $moduleinfo->gradepass               = 0;

    try {
        $result = add_moduleinfo($moduleinfo, $course);
        cli_writeln('  OK course=' . $course->id . ' "' . $course->shortname . '" → cm=' . $result->coursemodule);
    } catch (Exception $e) {
        cli_writeln('  ERREUR course=' . $course->id . ': ' . $e->getMessage());
    }
}

purge_all_caches();
cli_writeln('');
cli_writeln('BigBlueButton configuré avec succès.');
