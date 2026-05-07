<?php
define('CLI_SCRIPT', true);
require('/var/data/www/config.php');
require_once($CFG->libdir . '/clilib.php');

cli_heading('Ajout quiz_sections manquants');

global $DB;

$quizmodid = $DB->get_field('modules', 'id', ['name' => 'quiz']);
$cms = $DB->get_records('course_modules', ['module' => $quizmodid]);

foreach ($cms as $cm) {
    $quiz = $DB->get_record('quiz', ['id' => $cm->instance]);
    if (!$quiz) continue;

    $existing = $DB->get_record('quiz_sections', ['quizid' => $quiz->id]);
    if ($existing) {
        cli_writeln("→ Quiz '{$quiz->name}' — section déjà présente");
        continue;
    }

    $section = new stdClass();
    $section->quizid          = $quiz->id;
    $section->firstslot       = 1;
    $section->heading         = '';
    $section->shufflequestions = 0;
    $DB->insert_record('quiz_sections', $section);
    cli_writeln("✓ Section créée pour : {$quiz->name}");
}

purge_all_caches();
cli_writeln('');
cli_writeln('Terminé. Caches purgés.');
