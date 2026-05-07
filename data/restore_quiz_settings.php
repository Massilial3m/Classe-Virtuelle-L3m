<?php
define('CLI_SCRIPT', true);
require('/var/data/www/config.php');
require_once($CFG->libdir . '/clilib.php');

cli_heading('Restauration paramètres quiz – L3M Formation');

global $DB;

// Feedback sur toutes les réponses
$answers = $DB->get_records('question_answers');
foreach ($answers as $a) {
    $a->feedback = $a->fraction > 0 ? 'Bonne réponse !' : 'Réponse incorrecte.';
    $a->feedbackformat = FORMAT_HTML;
    $DB->update_record('question_answers', $a);
}
cli_writeln(count($answers) . ' réponses mises à jour');

// Feedback général sur chaque question
$questions = $DB->get_records('question');
foreach ($questions as $q) {
    $q->generalfeedback = 'Consultez votre formateur pour plus d\'informations sur cette question.';
    $q->generalfeedbackformat = FORMAT_HTML;
    $DB->update_record('question', $q);
}
cli_writeln(count($questions) . ' questions mises à jour');

// Options de review et mode feedback immédiat sur tous les quiz
$full = 65536 | 4096 | 256 | 16;
$quizzes = $DB->get_records('quiz');
foreach ($quizzes as $quiz) {
    $quiz->preferredbehaviour      = 'immediatefeedback';
    $quiz->reviewattempt            = $full;
    $quiz->reviewcorrectness        = $full;
    $quiz->reviewmarks              = $full;
    $quiz->reviewspecificfeedback   = $full;
    $quiz->reviewgeneralfeedback    = $full;
    $quiz->reviewrightanswer        = $full;
    $quiz->reviewoverallfeedback    = $full;
    $DB->update_record('quiz', $quiz);
}
cli_writeln(count($quizzes) . ' quiz mis à jour');

purge_all_caches();
cli_writeln('Paramètres quiz restaurés avec succès.');
