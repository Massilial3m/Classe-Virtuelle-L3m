<?php
define('CLI_SCRIPT', true);
require('/var/data/www/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');

cli_heading('Création des quiz ECSR – Moodle 5.x');

$admin = get_admin();

// ── Helpers ──────────────────────────────────────────────────────────────────

function get_or_create_top_category($context) {
    global $DB;
    $cat = $DB->get_record('question_categories', ['contextid' => $context->id, 'parent' => 0]);
    if ($cat) return $cat;
    $cat = new stdClass();
    $cat->name        = 'Haut de l\'arborescence';
    $cat->contextid   = $context->id;
    $cat->info        = '';
    $cat->infoformat  = FORMAT_HTML;
    $cat->parent      = 0;
    $cat->sortorder   = 0;
    $cat->stamp       = make_unique_id_code();
    $cat->idnumber    = null;
    $cat->id = $DB->insert_record('question_categories', $cat);
    return $cat;
}

function create_question_category($context, $name, $parentid) {
    global $DB;
    $cat = new stdClass();
    $cat->name       = $name;
    $cat->contextid  = $context->id;
    $cat->info       = '';
    $cat->infoformat = FORMAT_HTML;
    $cat->parent     = $parentid;
    $cat->sortorder  = 999;
    $cat->stamp      = make_unique_id_code();
    $cat->idnumber   = null;
    $cat->id = $DB->insert_record('question_categories', $cat);
    return $cat;
}

// Returns ['questionid' => ..., 'entryid' => ...]
function create_mcq($categoryid, $name, $qtext, $answers, $userid) {
    global $DB;

    $q = new stdClass();
    $q->parent               = 0;
    $q->name                 = $name;
    $q->questiontext         = '<p>' . $qtext . '</p>';
    $q->questiontextformat   = FORMAT_HTML;
    $q->generalfeedback      = '';
    $q->generalfeedbackformat = FORMAT_HTML;
    $q->defaultmark          = 1;
    $q->penalty              = 0.3333333;
    $q->qtype                = 'multichoice';
    $q->length               = 1;
    $q->stamp                = make_unique_id_code();
    $q->timecreated          = time();
    $q->timemodified         = time();
    $q->createdby            = $userid;
    $q->modifiedby           = $userid;
    $q->id = $DB->insert_record('question', $q);

    $entry = new stdClass();
    $entry->questioncategoryid = $categoryid;
    $entry->idnumber           = null;
    $entry->ownerid            = $userid;
    $entry->id = $DB->insert_record('question_bank_entries', $entry);

    $ver = new stdClass();
    $ver->questionbankentryid = $entry->id;
    $ver->version             = 1;
    $ver->questionid          = $q->id;
    $ver->status              = 'ready';
    $DB->insert_record('question_versions', $ver);

    $opts = new stdClass();
    $opts->questionid                    = $q->id;
    $opts->layout                        = 0;
    $opts->single                        = 1;
    $opts->shuffleanswers                = 1;
    $opts->correctfeedback               = '<p>Bonne réponse !</p>';
    $opts->correctfeedbackformat         = FORMAT_HTML;
    $opts->partiallycorrectfeedback      = '';
    $opts->partiallycorrectfeedbackformat = FORMAT_HTML;
    $opts->incorrectfeedback             = '<p>Réponse incorrecte. Relisez la section correspondante.</p>';
    $opts->incorrectfeedbackformat       = FORMAT_HTML;
    $opts->answernumbering               = 'abc';
    $opts->shownumcorrect                = 0;
    $opts->showstandardinstruction       = 0;
    $DB->insert_record('qtype_multichoice_options', $opts);

    foreach ($answers as $a) {
        $ans                = new stdClass();
        $ans->question      = $q->id;
        $ans->answer        = $a['text'];
        $ans->answerformat  = FORMAT_HTML;
        $ans->fraction      = $a['correct'] ? 1.0 : 0.0;
        $ans->feedback      = '';
        $ans->feedbackformat = FORMAT_HTML;
        $DB->insert_record('question_answers', $ans);
    }

    return ['questionid' => $q->id, 'entryid' => $entry->id];
}

function create_quiz($courseid, $sectionnum, $name, $intro) {
    global $DB;

    $moduleid = $DB->get_field('modules', 'id', ['name' => 'quiz']);
    $section  = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $sectionnum]);

    $quiz = new stdClass();
    $quiz->course                    = $courseid;
    $quiz->name                      = $name;
    $quiz->intro                     = '<p>' . $intro . '</p>';
    $quiz->introformat               = FORMAT_HTML;
    $quiz->timeopen                  = 0;
    $quiz->timeclose                 = 0;
    $quiz->timelimit                 = 0;
    $quiz->overduehandling           = 'autosubmit';
    $quiz->graceperiod               = 0;
    $quiz->preferredbehaviour        = 'deferredfeedback';
    $quiz->canredoquestions          = 1;
    $quiz->attempts                  = 0;
    $quiz->attemptonlast             = 0;
    $quiz->grademethod               = 1;
    $quiz->decimalpoints             = 2;
    $quiz->questiondecimalpoints     = -1;
    $quiz->reviewattempt             = 69904;
    $quiz->reviewcorrectness         = 4368;
    $quiz->reviewmaxmarks            = 4368;
    $quiz->reviewmarks               = 4368;
    $quiz->reviewspecificfeedback    = 4368;
    $quiz->reviewgeneralfeedback     = 4368;
    $quiz->reviewrightanswer         = 4368;
    $quiz->reviewoverallfeedback     = 4368;
    $quiz->questionsperpage          = 1;
    $quiz->navmethod                 = 'free';
    $quiz->shuffleanswers            = 1;
    $quiz->sumgrades                 = 0;
    $quiz->grade                     = 10;
    $quiz->timecreated               = time();
    $quiz->timemodified              = time();
    $quiz->password                  = '';
    $quiz->subnet                    = '';
    $quiz->browsersecurity           = '-';
    $quiz->delay1                    = 0;
    $quiz->delay2                    = 0;
    $quiz->showuserpicture           = 0;
    $quiz->showblocks                = 0;
    $quiz->completionattemptsexhausted = 0;
    $quiz->completionminattempts     = 0;
    $quiz->allowofflineattempts      = 0;
    $quiz->precreateattempts         = 0;
    $quizid = $DB->insert_record('quiz', $quiz);

    $cm = new stdClass();
    $cm->course                    = $courseid;
    $cm->module                    = $moduleid;
    $cm->instance                  = $quizid;
    $cm->section                   = $section->id;
    $cm->visible                   = 1;
    $cm->visibleoncoursepage       = 1;
    $cm->groupmode                 = 0;
    $cm->groupingid                = 0;
    $cm->completion                = 1;
    $cm->completionview            = 1;
    $cm->completiongradeitemnumber = null;
    $cm->completionpassgrade       = 0;
    $cm->completionexpected        = 0;
    $cm->showdescription           = 0;
    $cm->deletioninprogress        = 0;
    $cm->added                     = time();
    $cm->lang                      = '';
    $cm->enableaitools             = 0;
    $cm->enabledaiactions          = null;
    $cmid = $DB->insert_record('course_modules', $cm);

    $seq = trim($section->sequence ?? '');
    $seq = $seq ? $seq . ',' . $cmid : (string)$cmid;
    $DB->set_field('course_sections', 'sequence', $seq, ['id' => $section->id]);

    // Grade item (grade_items table)
    quiz_grade_item_update($DB->get_record('quiz', ['id' => $quizid]));

    // quiz_grade_items (requis par Moodle 5.x pour les slots)
    $gitem = new stdClass();
    $gitem->quizid    = $quizid;
    $gitem->sortorder = 1;
    $gitem->name      = '';
    $gradeitemid = $DB->insert_record('quiz_grade_items', $gitem);

    // quiz_sections (requis pour l'affichage des questions)
    $qsection = new stdClass();
    $qsection->quizid          = $quizid;
    $qsection->firstslot       = 1;
    $qsection->heading         = '';
    $qsection->shufflequestions = 0;
    $DB->insert_record('quiz_sections', $qsection);

    rebuild_course_cache($courseid, true);

    return ['quizid' => $quizid, 'cmid' => $cmid, 'gradeitemid' => $gradeitemid];
}

function add_question_to_quiz($quizid, $entryid, $page, $cmid, $gradeitemid = null) {
    global $DB;

    $context = context_module::instance($cmid);
    $maxslot = (int)$DB->get_field_sql('SELECT COALESCE(MAX(slot),0) FROM {quiz_slots} WHERE quizid=?', [$quizid]);

    $slot = new stdClass();
    $slot->quizid          = $quizid;
    $slot->page            = $page;
    $slot->slot            = $maxslot + 1;
    $slot->displaynumber   = (string)($maxslot + 1);
    $slot->requireprevious = 0;
    $slot->maxmark         = 1.0;
    $slot->quizgradeitemid = $gradeitemid;
    $slotid = $DB->insert_record('quiz_slots', $slot);

    $ref = new stdClass();
    $ref->usingcontextid     = $context->id;
    $ref->component          = 'mod_quiz';
    $ref->questionarea       = 'slot';
    $ref->itemid             = $slotid;
    $ref->questionbankentryid = $entryid;
    $ref->version            = null;
    $DB->insert_record('question_references', $ref);

    $sum = $DB->get_field_sql('SELECT COALESCE(SUM(maxmark),0) FROM {quiz_slots} WHERE quizid=?', [$quizid]);
    $DB->set_field('quiz', 'sumgrades', $sum, ['id' => $quizid]);
}

// ── Données questions par cours ───────────────────────────────────────────────

$all_courses = [

    'ECSR-CCP1-M1' => [
        'section'    => 4,
        'quiz_name'  => 'Quiz formatif – Cadre juridique et réglementaire',
        'quiz_intro' => '5 questions pour vérifier vos acquis sur le cadre légal de la formation à la conduite.',
        'questions'  => [
            ['q' => 'Le Titre Professionnel ECSR (RNCP 35329) correspond à quel niveau de qualification ?',
             'a' => [['text'=>'Niveau 3 (CAP/BEP)','correct'=>false],['text'=>'Niveau 4 (Baccalauréat)','correct'=>true],['text'=>'Niveau 5 (BTS/DUT)','correct'=>false],['text'=>'Niveau 6 (Licence)','correct'=>false]]],
            ['q' => 'Quelle autorité délivre l\'agrément d\'un établissement d\'enseignement de la conduite ?',
             'a' => [['text'=>'La DSR (Délégation à la Sécurité Routière)','correct'=>false],['text'=>'Le ministère de l\'Éducation nationale','correct'=>false],['text'=>'Le préfet de département','correct'=>true],['text'=>'La DREETS','correct'=>false]]],
            ['q' => 'Le TP ECSR a remplacé quel diplôme précédent ?',
             'a' => [['text'=>'Le BTS transport','correct'=>false],['text'=>'Le BEPECASER','correct'=>true],['text'=>'Le CAP conduite automobile','correct'=>false],['text'=>'Le CQP moniteur auto','correct'=>false]]],
            ['q' => 'L\'autorisation d\'enseigner la conduite doit être renouvelée tous les :',
             'a' => [['text'=>'2 ans','correct'=>false],['text'=>'3 ans','correct'=>false],['text'=>'5 ans','correct'=>true],['text'=>'10 ans','correct'=>false]]],
            ['q' => 'Quel organisme contrôle les établissements d\'enseignement de la conduite sur le terrain ?',
             'a' => [['text'=>'L\'ANAH','correct'=>false],['text'=>'La DREAL ou la DDT selon la région','correct'=>true],['text'=>'Le ministère de l\'Intérieur directement','correct'=>false],['text'=>'La CPAM','correct'=>false]]],
        ],
    ],

    'ECSR-CCP1-M2' => [
        'section'    => 5,
        'quiz_name'  => 'Quiz formatif – Pédagogie et techniques d\'animation',
        'quiz_intro' => '5 questions sur les fondamentaux de la pédagogie pour adultes et les techniques d\'animation.',
        'questions'  => [
            ['q' => 'L\'andragogie désigne :',
             'a' => [['text'=>'La pédagogie appliquée aux enfants','correct'=>false],['text'=>'La science de l\'éducation des adultes','correct'=>true],['text'=>'Une méthode d\'évaluation certificative','correct'=>false],['text'=>'Un type de formation en alternance obligatoire','correct'=>false]]],
            ['q' => 'Une pédagogie active place :',
             'a' => [['text'=>'Le formateur au centre de l\'apprentissage','correct'=>false],['text'=>'L\'apprenant en situation d\'acteur de ses propres apprentissages','correct'=>true],['text'=>'L\'évaluation avant l\'enseignement','correct'=>false],['text'=>'Les ressources numériques au premier plan','correct'=>false]]],
            ['q' => 'Face à un apprenant très anxieux au volant, la meilleure approche est de :',
             'a' => [['text'=>'Augmenter la difficulté pour le stimuler','correct'=>false],['text'=>'Mettre fin à la séance et le confier à un autre formateur','correct'=>false],['text'=>'Adapter le rythme, rassurer et valoriser chaque progrès','correct'=>true],['text'=>'Lui expliquer longuement les erreurs commises','correct'=>false]]],
            ['q' => 'La différenciation pédagogique consiste à :',
             'a' => [['text'=>'Appliquer rigoureusement le même programme à tous','correct'=>false],['text'=>'Adapter méthodes et supports aux besoins individuels de chaque apprenant','correct'=>true],['text'=>'Simplifier les objectifs pour les apprenants en difficulté','correct'=>false],['text'=>'Exclure les apprenants qui progressent trop lentement','correct'=>false]]],
            ['q' => 'Un feedback constructif se caractérise par :',
             'a' => [['text'=>'La critique systématique des erreurs','correct'=>false],['text'=>'L\'absence totale de commentaires négatifs','correct'=>false],['text'=>'Un retour précis sur ce qui fonctionne et des pistes d\'amélioration concrètes','correct'=>true],['text'=>'Des encouragements uniquement, sans relever les erreurs','correct'=>false]]],
        ],
    ],

    'ECSR-CCP1-M3' => [
        'section'    => 4,
        'quiz_name'  => 'Quiz formatif – Conception et préparation des séances',
        'quiz_intro' => '5 questions sur la conception de fiches de séance et la progression pédagogique.',
        'questions'  => [
            ['q' => 'SMART est un acronyme signifiant :',
             'a' => [['text'=>'Simple, Motivant, Actif, Réaliste, Temporel','correct'=>false],['text'=>'Spécifique, Mesurable, Atteignable, Réaliste, Temporellement défini','correct'=>true],['text'=>'Structuré, Méthodique, Analytique, Réflexif, Transférable','correct'=>false],['text'=>'Synchrone, Multimodal, Actif, Récurrent, Testé','correct'=>false]]],
            ['q' => 'Le diagnostic initial de l\'apprenant sert à :',
             'a' => [['text'=>'Fixer les tarifs de la formation','correct'=>false],['text'=>'Vérifier les documents administratifs de l\'élève','correct'=>false],['text'=>'Évaluer le niveau de départ et identifier les besoins de formation','correct'=>true],['text'=>'Définir le planning annuel de l\'auto-école','correct'=>false]]],
            ['q' => 'Dans une progression pédagogique, on commence généralement par :',
             'a' => [['text'=>'Les situations complexes pour stimuler l\'apprenant','correct'=>false],['text'=>'Les situations simples et maîtrisées, avant d\'augmenter progressivement la complexité','correct'=>true],['text'=>'Les exercices que l\'apprenant préfère','correct'=>false],['text'=>'Ce que le formateur maîtrise le mieux','correct'=>false]]],
            ['q' => 'Le livret d\'apprentissage permet de :',
             'a' => [['text'=>'Remplacer les cours de code théorique','correct'=>false],['text'=>'Calculer automatiquement la facturation','correct'=>false],['text'=>'Tracer la progression et guider les séances suivantes','correct'=>true],['text'=>'Évaluer les performances du formateur','correct'=>false]]],
            ['q' => 'Une fiche de séance efficace doit contenir :',
             'a' => [['text'=>'Le bilan comptable de la semaine','correct'=>false],['text'=>'Les objectifs, le déroulé, le matériel et les critères de réussite','correct'=>true],['text'=>'L\'historique complet de toutes les séances précédentes','correct'=>false],['text'=>'Le programme détaillé de toute l\'année','correct'=>false]]],
        ],
    ],

    'ECSR-CCP1-M4' => [
        'section'    => 5,
        'quiz_name'  => 'Quiz formatif – Animation de séances de conduite',
        'quiz_intro' => '5 questions sur les techniques d\'animation de séances de conduite en milieu ouvert.',
        'questions'  => [
            ['q' => 'En situation de conduite, la communication du formateur doit être :',
             'a' => [['text'=>'Longue et très détaillée pour être bien comprise','correct'=>false],['text'=>'Claire, concise et donnée au bon moment','correct'=>true],['text'=>'Uniquement non verbale pendant la conduite','correct'=>false],['text'=>'Réservée exclusivement aux moments d\'arrêt','correct'=>false]]],
            ['q' => 'L\'utilisation des doubles commandes est justifiée :',
             'a' => [['text'=>'Systématiquement à chaque séance de conduite','correct'=>false],['text'=>'Uniquement en cas de danger ou de risque d\'accident','correct'=>true],['text'=>'Pour aider l\'élève à freiner plus facilement','correct'=>false],['text'=>'Pour compenser l\'absence de miroir de recul','correct'=>false]]],
            ['q' => 'Un bon débrief de fin de séance commence par :',
             'a' => [['text'=>'Les erreurs les plus graves à corriger en priorité','correct'=>false],['text'=>'Les points positifs observés, puis les axes d\'amélioration','correct'=>true],['text'=>'La facturation de la séance','correct'=>false],['text'=>'Les objectifs de la prochaine leçon uniquement','correct'=>false]]],
            ['q' => 'Avant d\'enseigner la conduite sur voie rapide, le formateur doit :',
             'a' => [['text'=>'Attendre le dernier moment avant l\'examen','correct'=>false],['text'=>'Vérifier que l\'apprenant maîtrise les fondamentaux en milieu urbain','correct'=>true],['text'=>'Commencer directement à 130 km/h pour l\'habituer','correct'=>false],['text'=>'Éviter cet environnement considéré trop dangereux','correct'=>false]]],
            ['q' => 'L\'auto-évaluation guidée de l\'apprenant permet de :',
             'a' => [['text'=>'Remplacer entièrement l\'évaluation du formateur','correct'=>false],['text'=>'Développer la prise de conscience et l\'autonomie de l\'apprenant','correct'=>true],['text'=>'Réduire le temps de débrief','correct'=>false],['text'=>'Valider automatiquement toutes les compétences','correct'=>false]]],
        ],
    ],

    'ECSR-CCP1-M5' => [
        'section'    => 4,
        'quiz_name'  => 'Quiz formatif – Évaluation et suivi des apprenants',
        'quiz_intro' => '5 questions sur les outils et méthodes d\'évaluation de la progression des apprenants.',
        'questions'  => [
            ['q' => 'L\'évaluation formative a pour but de :',
             'a' => [['text'=>'Sanctionner définitivement les erreurs','correct'=>false],['text'=>'Décider de la date d\'examen','correct'=>false],['text'=>'Mesurer les progrès et adapter l\'enseignement en cours d\'apprentissage','correct'=>true],['text'=>'Remplacer l\'évaluation certificative','correct'=>false]]],
            ['q' => 'Une grille d\'observation permet de :',
             'a' => [['text'=>'Comparer les apprenants entre eux objectivement','correct'=>false],['text'=>'Évaluer des comportements observables selon des critères précis et prédéfinis','correct'=>true],['text'=>'Remplacer le livret d\'apprentissage','correct'=>false],['text'=>'Noter les apprenants de manière subjective','correct'=>false]]],
            ['q' => 'Le critère d\'évaluation se distingue de l\'indicateur car :',
             'a' => [['text'=>'Il n\'existe aucune différence entre les deux','correct'=>false],['text'=>'Le critère définit la qualité attendue, l\'indicateur permet de l\'observer','correct'=>true],['text'=>'L\'indicateur est plus important que le critère','correct'=>false],['text'=>'Le critère est uniquement utilisé lors de l\'examen final','correct'=>false]]],
            ['q' => 'En conduite accompagnée (AAC), le livret d\'apprentissage est :',
             'a' => [['text'=>'Facultatif pour les apprenants de plus de 20 ans','correct'=>false],['text'=>'À remplir uniquement par les parents accompagnateurs','correct'=>false],['text'=>'Obligatoire, signé par le formateur et l\'accompagnateur','correct'=>true],['text'=>'Remplacé par une application mobile depuis 2020','correct'=>false]]],
            ['q' => 'Le compte rendu de séance doit être communiqué prioritairement à :',
             'a' => [['text'=>'Uniquement à la direction de l\'auto-école','correct'=>false],['text'=>'Jamais, car c\'est confidentiel','correct'=>false],['text'=>'L\'apprenant, pour qu\'il comprenne ses progrès et axes d\'amélioration','correct'=>true],['text'=>'Exclusivement aux parents si l\'apprenant est mineur','correct'=>false]]],
        ],
    ],

    'ECSR-CCP1-EXAM' => [
        'section'    => 3,
        'quiz_name'  => 'Simulation d\'examen CCP1 – Épreuve complète',
        'quiz_intro' => '10 questions couvrant l\'ensemble du bloc CCP1. Condition d\'examen : correction après validation complète.',
        'questions'  => [
            ['q' => 'Le TP ECSR (RNCP 35329) correspond à quel niveau de qualification ?',
             'a' => [['text'=>'Niveau 3','correct'=>false],['text'=>'Niveau 4','correct'=>true],['text'=>'Niveau 5','correct'=>false],['text'=>'Niveau 6','correct'=>false]]],
            ['q' => 'Quelle autorité délivre l\'agrément d\'un établissement d\'enseignement de la conduite ?',
             'a' => [['text'=>'La DSR nationale','correct'=>false],['text'=>'Le ministère de l\'Intérieur directement','correct'=>false],['text'=>'Le préfet de département','correct'=>true],['text'=>'La DREETS','correct'=>false]]],
            ['q' => 'L\'andragogie est la science de :',
             'a' => [['text'=>'La pédagogie des enfants','correct'=>false],['text'=>'L\'éducation des adultes','correct'=>true],['text'=>'L\'évaluation certificative','correct'=>false],['text'=>'La formation en alternance','correct'=>false]]],
            ['q' => 'Un objectif SMART est Spécifique, Mesurable, Atteignable, Réaliste et :',
             'a' => [['text'=>'Structuré','correct'=>false],['text'=>'Simple','correct'=>false],['text'=>'Temporellement défini','correct'=>true],['text'=>'Synchrone','correct'=>false]]],
            ['q' => 'Les doubles commandes doivent être utilisées :',
             'a' => [['text'=>'Systématiquement dès le début de chaque séance','correct'=>false],['text'=>'En cas de danger ou de risque d\'accident imminent','correct'=>true],['text'=>'À la demande de l\'apprenant uniquement','correct'=>false],['text'=>'Jamais, elles sont réservées aux stages intensifs','correct'=>false]]],
            ['q' => 'L\'évaluation formative se déroule :',
             'a' => [['text'=>'Uniquement à la fin de la formation','correct'=>false],['text'=>'En cours d\'apprentissage pour adapter l\'enseignement','correct'=>true],['text'=>'Lors de l\'examen de certification uniquement','correct'=>false],['text'=>'Une seule fois en début de formation','correct'=>false]]],
            ['q' => 'La différenciation pédagogique consiste à :',
             'a' => [['text'=>'Appliquer le même programme à tous','correct'=>false],['text'=>'Adapter méthodes et supports aux besoins de chaque apprenant','correct'=>true],['text'=>'Exclure les apprenants en difficulté','correct'=>false],['text'=>'Simplifier toujours les objectifs','correct'=>false]]],
            ['q' => 'Un bon débrief de séance commence par :',
             'a' => [['text'=>'Les erreurs les plus graves','correct'=>false],['text'=>'Les points positifs, puis les axes d\'amélioration','correct'=>true],['text'=>'La facturation','correct'=>false],['text'=>'Les objectifs de la prochaine leçon uniquement','correct'=>false]]],
            ['q' => 'Le livret d\'apprentissage permet principalement de :',
             'a' => [['text'=>'Calculer la facturation','correct'=>false],['text'=>'Tracer la progression et guider les séances suivantes','correct'=>true],['text'=>'Remplacer les cours de code','correct'=>false],['text'=>'Évaluer les formateurs','correct'=>false]]],
            ['q' => 'L\'autorisation d\'enseigner la conduite est renouvelée tous les :',
             'a' => [['text'=>'2 ans','correct'=>false],['text'=>'3 ans','correct'=>false],['text'=>'5 ans','correct'=>true],['text'=>'10 ans','correct'=>false]]],
        ],
    ],

    'ECSR-CCP2-M1' => [
        'section'    => 4,
        'quiz_name'  => 'Quiz formatif – Veille réglementaire et professionnelle',
        'quiz_intro' => '5 questions sur les méthodes et outils de veille professionnelle pour l\'enseignant ECSR.',
        'questions'  => [
            ['q' => 'Légifrance est :',
             'a' => [['text'=>'Un réseau social professionnel pour les juristes','correct'=>false],['text'=>'Le site officiel d\'accès aux textes juridiques français','correct'=>true],['text'=>'Un logiciel de gestion d\'auto-école','correct'=>false],['text'=>'Une association d\'enseignants de la conduite','correct'=>false]]],
            ['q' => 'Un agrégateur RSS permet de :',
             'a' => [['text'=>'Gérer la facturation et les plannings','correct'=>false],['text'=>'Centraliser automatiquement les actualités de plusieurs sources','correct'=>true],['text'=>'Créer des supports de formation interactive','correct'=>false],['text'=>'Gérer les inscriptions des apprenants','correct'=>false]]],
            ['q' => 'La formation continue des enseignants ECSR est :',
             'a' => [['text'=>'Facultative et sans impact sur l\'autorisation d\'enseigner','correct'=>false],['text'=>'Obligatoire pour le renouvellement de l\'autorisation d\'enseigner','correct'=>true],['text'=>'Uniquement disponible en présentiel dans les centres agréés','correct'=>false],['text'=>'À la charge exclusive de l\'employeur','correct'=>false]]],
            ['q' => 'Diffuser une note de synthèse de veille à l\'équipe pédagogique permet de :',
             'a' => [['text'=>'Remplacer les réunions d\'équipe obligatoires','correct'=>false],['text'=>'Assurer l\'homogénéité des pratiques et la mise à jour des connaissances','correct'=>true],['text'=>'Éviter de lire les textes officiels soi-même','correct'=>false],['text'=>'Répondre aux obligations légales de traçabilité uniquement','correct'=>false]]],
            ['q' => 'Pour suivre les évolutions du code de la route, un enseignant ECSR doit :',
             'a' => [['text'=>'Attendre les informations de ses collègues','correct'=>false],['text'=>'Lire uniquement la presse généraliste','correct'=>false],['text'=>'Consulter régulièrement les sources officielles et s\'abonner aux alertes','correct'=>true],['text'=>'Attendre les formations obligatoires de renouvellement','correct'=>false]]],
        ],
    ],

    'ECSR-CCP2-M2' => [
        'section'    => 4,
        'quiz_name'  => 'Quiz formatif – Accueil, conseil et relation client',
        'quiz_intro' => '5 questions sur les techniques de relation client et de conseil en auto-école.',
        'questions'  => [
            ['q' => 'Lors d\'un accueil téléphonique, la première action doit être de :',
             'a' => [['text'=>'Demander immédiatement le motif de l\'appel','correct'=>false],['text'=>'Mettre l\'appelant en attente pour vérifier le planning','correct'=>false],['text'=>'Se présenter clairement et saluer l\'interlocuteur','correct'=>true],['text'=>'Donner les tarifs immédiatement','correct'=>false]]],
            ['q' => 'La méthode SONCAS permet d\'identifier :',
             'a' => [['text'=>'Les tarifs compétitifs du marché local','correct'=>false],['text'=>'Les motivations d\'achat du client','correct'=>true],['text'=>'La solvabilité financière du client','correct'=>false],['text'=>'Les principaux concurrents locaux','correct'=>false]]],
            ['q' => 'Face à une réclamation client, la première étape est de :',
             'a' => [['text'=>'Rejeter la réclamation si elle semble injustifiée','correct'=>false],['text'=>'Appliquer immédiatement un remboursement','correct'=>false],['text'=>'Transférer au responsable sans répondre','correct'=>false],['text'=>'Écouter activement et reformuler pour montrer que l\'on a bien compris','correct'=>true]]],
            ['q' => 'Le CPF (Compte Personnel de Formation) permet à l\'apprenant de :',
             'a' => [['text'=>'Obtenir son permis sans formation préalable','correct'=>false],['text'=>'Passer l\'examen en ligne','correct'=>false],['text'=>'Financer tout ou partie de sa formation au permis de conduire','correct'=>true],['text'=>'S\'inscrire automatiquement dans n\'importe quelle auto-école','correct'=>false]]],
            ['q' => 'Pour proposer une formule adaptée, le conseiller doit d\'abord :',
             'a' => [['text'=>'Présenter toutes les offres disponibles en même temps','correct'=>false],['text'=>'Toujours proposer la formule la plus chère','correct'=>false],['text'=>'Analyser le profil, le budget et les contraintes de l\'apprenant','correct'=>true],['text'=>'Laisser le client choisir seul sur la brochure','correct'=>false]]],
        ],
    ],

    'ECSR-CCP2-M3' => [
        'section'    => 4,
        'quiz_name'  => 'Quiz formatif – Développement commercial',
        'quiz_intro' => '5 questions sur les stratégies de communication et de développement de l\'auto-école.',
        'questions'  => [
            ['q' => 'Un partenariat avec un lycée peut permettre à l\'auto-école de :',
             'a' => [['text'=>'Obtenir des subventions automatiquement','correct'=>false],['text'=>'Former les professeurs à la conduite','correct'=>false],['text'=>'Proposer des formations BSR, AAC ou des sensibilisations sécurité routière','correct'=>true],['text'=>'Remplacer les cours de code théorique','correct'=>false]]],
            ['q' => 'Les avis clients en ligne sont importants car :',
             'a' => [['text'=>'Ils sont obligatoires légalement pour toutes les auto-écoles','correct'=>false],['text'=>'Ils remplacent la publicité payante','correct'=>false],['text'=>'Ils influencent directement la réputation et le choix des nouveaux clients','correct'=>true],['text'=>'Ils sont uniquement consultés par les concurrents','correct'=>false]]],
            ['q' => 'La conduite accompagnée (AAC) est un levier commercial car :',
             'a' => [['text'=>'Elle est plus rentable par heure que le permis classique','correct'=>false],['text'=>'Elle ne nécessite aucun agrément spécifique','correct'=>false],['text'=>'Elle fidélise les familles sur plusieurs années avec un suivi long terme','correct'=>true],['text'=>'Elle remplace le permis de conduire traditionnel','correct'=>false]]],
            ['q' => 'Une stratégie de communication locale efficace repose sur :',
             'a' => [['text'=>'Un seul canal de communication bien ciblé','correct'=>false],['text'=>'Exclusivement la publicité payante sur les réseaux sociaux','correct'=>false],['text'=>'Une combinaison de plusieurs canaux adaptés à la cible locale','correct'=>true],['text'=>'Uniquement le bouche-à-oreille','correct'=>false]]],
            ['q' => 'Google My Business permet à une auto-école de :',
             'a' => [['text'=>'Gérer sa comptabilité en ligne','correct'=>false],['text'=>'Former les apprenants à distance','correct'=>false],['text'=>'Gérer sa présence web locale et recueillir des avis clients','correct'=>true],['text'=>'Gérer le planning des leçons de conduite','correct'=>false]]],
        ],
    ],

    'ECSR-CCP2-M4' => [
        'section'    => 4,
        'quiz_name'  => 'Quiz formatif – Gestion administrative et financière',
        'quiz_intro' => '5 questions sur la gestion administrative d\'une auto-école et la conformité réglementaire.',
        'questions'  => [
            ['q' => 'Qualiopi est :',
             'a' => [['text'=>'Un logiciel de gestion d\'auto-école','correct'=>false],['text'=>'Un diplôme réservé aux directeurs d\'auto-école','correct'=>false],['text'=>'Une certification qualité obligatoire pour accéder aux financements publics de formation','correct'=>true],['text'=>'Une plateforme de formation en ligne','correct'=>false]]],
            ['q' => 'Le RGPD impose aux auto-écoles de :',
             'a' => [['text'=>'Partager les données clients avec les partenaires commerciaux','correct'=>false],['text'=>'Publier les données des apprenants en ligne pour la transparence','correct'=>false],['text'=>'Conserver toutes les données personnelles indéfiniment','correct'=>false],['text'=>'Protéger et gérer strictement les données personnelles des clients','correct'=>true]]],
            ['q' => 'Mon Compte Formation permet à un particulier de :',
             'a' => [['text'=>'Gérer son planning de leçons de conduite','correct'=>false],['text'=>'Passer l\'examen du permis de conduire en ligne','correct'=>false],['text'=>'Mobiliser ses droits CPF pour financer une formation éligible','correct'=>true],['text'=>'Évaluer la qualité des organismes de formation','correct'=>false]]],
            ['q' => 'En cas d\'inspection DREETS, l\'auto-école doit pouvoir présenter :',
             'a' => [['text'=>'Uniquement les bilans comptables annuels','correct'=>false],['text'=>'Seulement l\'agrément et les diplômes des formateurs','correct'=>false],['text'=>'Les conventions de formation, feuilles de présence et documents pédagogiques','correct'=>true],['text'=>'Le registre du personnel uniquement','correct'=>false]]],
            ['q' => 'L\'OPCO (Opérateur de Compétences) intervient pour :',
             'a' => [['text'=>'Former directement les salariés dans les entreprises','correct'=>false],['text'=>'Délivrer les agréments aux établissements de formation','correct'=>false],['text'=>'Financer et accompagner les formations professionnelles dans les entreprises','correct'=>true],['text'=>'Organiser les examens du permis de conduire','correct'=>false]]],
        ],
    ],

    'ECSR-CCP2-EXAM' => [
        'section'    => 3,
        'quiz_name'  => 'Simulation d\'examen CCP2 – Épreuve complète',
        'quiz_intro' => '10 questions couvrant l\'ensemble du bloc CCP2. Conditions d\'examen : correction après validation complète.',
        'questions'  => [
            ['q' => 'Qualiopi est obligatoire pour les organismes de formation souhaitant :',
             'a' => [['text'=>'Former tous les types de public','correct'=>false],['text'=>'Accéder aux financements publics (CPF, OPCO, etc.)','correct'=>true],['text'=>'Employer plus de 10 formateurs','correct'=>false],['text'=>'Proposer la formation en ligne uniquement','correct'=>false]]],
            ['q' => 'Légifrance permet d\'accéder à :',
             'a' => [['text'=>'Des formations professionnelles en ligne','correct'=>false],['text'=>'Les textes juridiques officiels français','correct'=>true],['text'=>'Un réseau professionnel de formateurs','correct'=>false],['text'=>'Un logiciel de gestion d\'auto-école','correct'=>false]]],
            ['q' => 'La méthode SONCAS aide à identifier :',
             'a' => [['text'=>'Les tarifs compétitifs du marché','correct'=>false],['text'=>'Les motivations d\'achat du client','correct'=>true],['text'=>'La solvabilité du prospect','correct'=>false],['text'=>'Les concurrents locaux','correct'=>false]]],
            ['q' => 'Face à une réclamation client, on doit d\'abord :',
             'a' => [['text'=>'Rembourser immédiatement','correct'=>false],['text'=>'Rejeter si la plainte semble infondée','correct'=>false],['text'=>'Écouter et reformuler la demande pour montrer que l\'on a bien compris','correct'=>true],['text'=>'Transmettre au responsable sans répondre','correct'=>false]]],
            ['q' => 'Le CPF permet à l\'apprenant de :',
             'a' => [['text'=>'Obtenir le permis sans formation','correct'=>false],['text'=>'Financer tout ou partie de sa formation au permis de conduire','correct'=>true],['text'=>'S\'inscrire sans dossier administratif','correct'=>false],['text'=>'Passer l\'examen en ligne','correct'=>false]]],
            ['q' => 'Le RGPD impose à l\'auto-école de :',
             'a' => [['text'=>'Partager les données avec les partenaires','correct'=>false],['text'=>'Protéger et gérer strictement les données personnelles des clients','correct'=>true],['text'=>'Conserver toutes les données indéfiniment','correct'=>false],['text'=>'Publier les résultats des apprenants','correct'=>false]]],
            ['q' => 'Google My Business sert à :',
             'a' => [['text'=>'Gérer la comptabilité','correct'=>false],['text'=>'Gérer la présence locale en ligne et collecter des avis clients','correct'=>true],['text'=>'Former les apprenants à distance','correct'=>false],['text'=>'Gérer les plannings de conduite','correct'=>false]]],
            ['q' => 'Les avis clients en ligne influencent directement :',
             'a' => [['text'=>'Uniquement le référencement payant','correct'=>false],['text'=>'La réputation et le choix des futurs clients','correct'=>true],['text'=>'Seulement les clients professionnels','correct'=>false],['text'=>'Rien, les clients choisissent uniquement sur les tarifs','correct'=>false]]],
            ['q' => 'La formation continue des enseignants ECSR est :',
             'a' => [['text'=>'Facultative','correct'=>false],['text'=>'Obligatoire pour renouveler l\'autorisation d\'enseigner','correct'=>true],['text'=>'Uniquement en présentiel','correct'=>false],['text'=>'À la charge de l\'apprenant','correct'=>false]]],
            ['q' => 'En cas d\'inspection DREETS, l\'auto-école doit présenter :',
             'a' => [['text'=>'Uniquement les bilans financiers','correct'=>false],['text'=>'Les conventions de formation, feuilles de présence et documents pédagogiques','correct'=>true],['text'=>'Seulement l\'agrément','correct'=>false],['text'=>'Le registre du personnel uniquement','correct'=>false]]],
        ],
    ],
];

// ── Traitement ────────────────────────────────────────────────────────────────

foreach ($all_courses as $shortname => $data) {
    $course = $DB->get_record('course', ['shortname' => $shortname]);
    if (!$course) {
        cli_writeln("✗ Cours introuvable : $shortname");
        continue;
    }

    cli_writeln("→ {$course->fullname}");

    $context  = context_course::instance($course->id);
    $topcat   = get_or_create_top_category($context);
    $category = create_question_category($context, 'Questions ' . $shortname, $topcat->id);

    // Créer les questions
    $entries = [];
    foreach ($data['questions'] as $i => $qdata) {
        $result   = create_mcq($category->id, 'Q' . ($i + 1) . ' – ' . $shortname, $qdata['q'], $qdata['a'], $admin->id);
        $entries[] = $result['entryid'];
        cli_writeln("  ✓ Question " . ($i + 1) . " créée");
    }

    // Créer le quiz
    $quiz_result = create_quiz($course->id, $data['section'], $data['quiz_name'], $data['quiz_intro']);
    cli_writeln("  ✓ Quiz créé : {$data['quiz_name']}");

    // Ajouter les questions au quiz
    foreach ($entries as $page => $entryid) {
        add_question_to_quiz($quiz_result['quizid'], $entryid, $page + 1, $quiz_result['cmid'], $quiz_result['gradeitemid']);
    }
    cli_writeln("  ✓ " . count($entries) . " questions ajoutées au quiz");
    cli_writeln('');
}

purge_all_caches();
cli_writeln('Terminé. Caches purgés.');
