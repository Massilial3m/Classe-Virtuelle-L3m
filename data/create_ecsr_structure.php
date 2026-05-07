<?php
define('CLI_SCRIPT', true);
require('/var/data/www/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');

cli_heading('Création de la structure pédagogique ECSR - RNCP 35329');

// ─── Catégorie racine ECSR ────────────────────────────────────────────────────
$ecsr = core_course_category::create([
    'name'        => 'ECSR - Enseignant de la Conduite et de la Sécurité Routière',
    'idnumber'    => 'ECSR',
    'description' => 'Titre Professionnel ECSR – RNCP 35329 | Lindberg Formation',
    'visible'     => 1,
    'parent'      => 0,
]);
cli_writeln("✓ Catégorie créée : ECSR (id={$ecsr->id})");

// ─── CCP1 ─────────────────────────────────────────────────────────────────────
$ccp1 = core_course_category::create([
    'name'        => 'CCP1 – Animer des actions de formation à la conduite',
    'idnumber'    => 'ECSR-CCP1',
    'description' => 'Bloc 1 : Animer des actions de formation à la conduite et à la sécurité routière en milieu ouvert.',
    'visible'     => 1,
    'parent'      => $ecsr->id,
]);
cli_writeln("✓ Sous-catégorie créée : CCP1 (id={$ccp1->id})");

// ─── CCP2 ─────────────────────────────────────────────────────────────────────
$ccp2 = core_course_category::create([
    'name'        => 'CCP2 – Veille professionnelle et développement de l\'activité',
    'idnumber'    => 'ECSR-CCP2',
    'description' => 'Bloc 2 : Assurer une veille professionnelle et contribuer au développement de l\'activité de l\'école de conduite.',
    'visible'     => 1,
    'parent'      => $ecsr->id,
]);
cli_writeln("✓ Sous-catégorie créée : CCP2 (id={$ccp2->id})");

// ─── Cours CCP1 ───────────────────────────────────────────────────────────────
$ccp1_courses = [
    [
        'shortname' => 'ECSR-CCP1-M1',
        'fullname'  => 'M1 – Cadre juridique et réglementaire de la conduite',
        'summary'   => '<p>Cadre légal de l\'enseignement de la conduite en France : textes réglementaires, agréments, rôle de la DSR, obligations de l\'enseignant.</p>',
        'numsections' => 4,
    ],
    [
        'shortname' => 'ECSR-CCP1-M2',
        'fullname'  => 'M2 – Pédagogie et techniques d\'animation',
        'summary'   => '<p>Fondamentaux de la pédagogie pour adultes : méthodes actives, posture de formateur, gestion de groupe, adaptation aux profils d\'apprenants.</p>',
        'numsections' => 5,
    ],
    [
        'shortname' => 'ECSR-CCP1-M3',
        'fullname'  => 'M3 – Conception et préparation des séances de conduite',
        'summary'   => '<p>Élaborer des progressions pédagogiques, ficher de séance, objectifs SMART, outils de préparation adaptés au niveau de l\'apprenant.</p>',
        'numsections' => 4,
    ],
    [
        'shortname' => 'ECSR-CCP1-M4',
        'fullname'  => 'M4 – Animation de séances de conduite en milieu ouvert',
        'summary'   => '<p>Techniques d\'enseignement en situation réelle : communication en voiture, gestion des risques, adaptation au trafic, analyse de conduite.</p>',
        'numsections' => 5,
    ],
    [
        'shortname' => 'ECSR-CCP1-M5',
        'fullname'  => 'M5 – Évaluation et suivi de la progression des apprenants',
        'summary'   => '<p>Outils d\'évaluation formative et sommative, grilles d\'observation, livret d\'apprentissage, compte rendu de séance, accompagnement personnalisé.</p>',
        'numsections' => 4,
    ],
    [
        'shortname' => 'ECSR-CCP1-EXAM',
        'fullname'  => 'Préparation à l\'évaluation CCP1 – Simulations d\'examen',
        'summary'   => '<p>Quiz formatifs, études de cas, mises en situation et simulations des épreuves de certification du bloc CCP1.</p>',
        'numsections' => 3,
    ],
];

foreach ($ccp1_courses as $data) {
    $course = create_course((object) array_merge($data, [
        'category'  => $ccp1->id,
        'format'    => 'topics',
        'visible'   => 1,
        'lang'      => 'fr',
        'startdate' => mktime(0, 0, 0, 9, 1, 2025),
    ]));
    cli_writeln("  ✓ Cours créé : {$data['fullname']} (id={$course->id})");
}

// ─── Cours CCP2 ───────────────────────────────────────────────────────────────
$ccp2_courses = [
    [
        'shortname' => 'ECSR-CCP2-M1',
        'fullname'  => 'M1 – Veille réglementaire et professionnelle',
        'summary'   => '<p>Méthodes de veille juridique et pédagogique : sources officielles, évolutions du code de la route, normes ECSR, outils numériques de veille.</p>',
        'numsections' => 4,
    ],
    [
        'shortname' => 'ECSR-CCP2-M2',
        'fullname'  => 'M2 – Accueil, conseil et relation client',
        'summary'   => '<p>Posture commerciale en auto-école : accueil physique et téléphonique, analyse des besoins, présentation des formules, gestion des situations difficiles.</p>',
        'numsections' => 4,
    ],
    [
        'shortname' => 'ECSR-CCP2-M3',
        'fullname'  => 'M3 – Développement commercial de l\'école de conduite',
        'summary'   => '<p>Stratégie de développement : communication locale, réseaux sociaux, partenariats (lycées, entreprises), fidélisation de la clientèle.</p>',
        'numsections' => 4,
    ],
    [
        'shortname' => 'ECSR-CCP2-M4',
        'fullname'  => 'M4 – Gestion administrative et financière',
        'summary'   => '<p>Outils de gestion d\'une auto-école : logiciels métier, suivi des heures, facturation, CPF, financement de la formation, obligations comptables.</p>',
        'numsections' => 4,
    ],
    [
        'shortname' => 'ECSR-CCP2-EXAM',
        'fullname'  => 'Préparation à l\'évaluation CCP2 – Simulations d\'examen',
        'summary'   => '<p>Quiz formatifs, mises en situation commerciales et administratives, simulations des épreuves de certification du bloc CCP2.</p>',
        'numsections' => 3,
    ],
];

foreach ($ccp2_courses as $data) {
    $course = create_course((object) array_merge($data, [
        'category'  => $ccp2->id,
        'format'    => 'topics',
        'visible'   => 1,
        'lang'      => 'fr',
        'startdate' => mktime(0, 0, 0, 9, 1, 2025),
    ]));
    cli_writeln("  ✓ Cours créé : {$data['fullname']} (id={$course->id})");
}

cli_writeln('');
cli_writeln('Structure ECSR créée avec succès.');
cli_writeln('Accéder à : Administration > Cours > Gérer les cours et catégories');
