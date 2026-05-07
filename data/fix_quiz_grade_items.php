<?php
define('CLI_SCRIPT', true);
require('/var/data/www/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');

cli_heading('Correction quiz_grade_items – Moodle 5.x');

global $DB;

$quizmodid = $DB->get_field('modules', 'id', ['name' => 'quiz']);
$cms = $DB->get_records('course_modules', ['module' => $quizmodid]);

foreach ($cms as $cm) {
    $quiz = $DB->get_record('quiz', ['id' => $cm->instance]);
    if (!$quiz) continue;

    // Vérifier si quiz_grade_items existe déjà
    $existing = $DB->get_record('quiz_grade_items', ['quizid' => $quiz->id]);
    if ($existing) {
        cli_writeln("→ Quiz '{$quiz->name}' — grade item déjà présent (id={$existing->id})");
        $gradeitemid = $existing->id;
    } else {
        // Créer le grade item par défaut
        $gitem = new stdClass();
        $gitem->quizid    = $quiz->id;
        $gitem->sortorder = 1;
        $gitem->name      = '';
        $gradeitemid = $DB->insert_record('quiz_grade_items', $gitem);
        cli_writeln("→ Quiz '{$quiz->name}' — grade item créé (id=$gradeitemid)");
    }

    // Mettre à jour tous les slots de ce quiz
    $slots = $DB->get_records('quiz_slots', ['quizid' => $quiz->id]);
    $updated = 0;
    foreach ($slots as $slot) {
        if ($slot->quizgradeitemid != $gradeitemid) {
            $DB->set_field('quiz_slots', 'quizgradeitemid', $gradeitemid, ['id' => $slot->id]);
            $updated++;
        }
    }
    cli_writeln("  ✓ $updated slot(s) mis à jour");
}

purge_all_caches();
cli_writeln('');
cli_writeln('Correction terminée. Caches purgés.');
