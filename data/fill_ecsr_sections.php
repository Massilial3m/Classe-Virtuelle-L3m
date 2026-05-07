<?php
define('CLI_SCRIPT', true);
require('/var/data/www/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');

cli_heading('Remplissage des sections – Cours ECSR');

// Sections par shortname de cours
$courses_sections = [

    // ── CCP1 ────────────────────────────────────────────────────────────────

    'ECSR-CCP1-M1' => [
        1 => ['name' => 'Les textes réglementaires de la formation à la conduite',
              'summary' => '<p>Code de la route, arrêtés ministériels, circulaires DSR. Textes fondateurs de l\'enseignement de la conduite en France.</p>'],
        2 => ['name' => 'Rôle des institutions : DSR, DREAL, DREETS',
              'summary' => '<p>Missions et organisation des autorités de tutelle. Contrôles, agréments et relations avec les établissements.</p>'],
        3 => ['name' => 'Agréments et obligations de l\'enseignant ECSR',
              'summary' => '<p>BEPECASER / TP ECSR : conditions d\'obtention, renouvellement, obligations de formation continue, déontologie.</p>'],
        4 => ['name' => 'Responsabilités civile et pénale de l\'enseignant',
              'summary' => '<p>Cadre de responsabilité en situation de formation : accidents, litiges, assurances professionnelles, jurisprudence.</p>'],
    ],

    'ECSR-CCP1-M2' => [
        1 => ['name' => 'Principes de la pédagogie pour adultes (andragogie)',
              'summary' => '<p>Spécificités de l\'apprentissage adulte : motivation, autonomie, expérience antérieure, rapport au savoir.</p>'],
        2 => ['name' => 'Méthodes et outils pédagogiques actifs',
              'summary' => '<p>Pédagogie par l\'action, démonstration, questionnement, jeux de rôle, supports visuels. Choix selon les objectifs.</p>'],
        3 => ['name' => 'Posture et communication du formateur',
              'summary' => '<p>Communication verbale et non verbale, écoute active, feedback constructif, gestion de l\'erreur comme levier d\'apprentissage.</p>'],
        4 => ['name' => 'Gestion de groupe et différenciation pédagogique',
              'summary' => '<p>Dynamiques de groupe, profils d\'apprenants, adaptation du discours et des activités aux besoins individuels.</p>'],
        5 => ['name' => 'Adaptation aux apprenants en situation de blocage',
              'summary' => '<p>Identifier et lever les freins psychologiques : anxiété au volant, manque de confiance, situations d\'échec répété.</p>'],
    ],

    'ECSR-CCP1-M3' => [
        1 => ['name' => 'Diagnostic initial et analyse des besoins de l\'apprenant',
              'summary' => '<p>Évaluation de départ : test de positionnement, entretien pédagogique, identification du niveau et des objectifs.</p>'],
        2 => ['name' => 'Élaboration d\'une progression pédagogique',
              'summary' => '<p>Construction d\'un parcours personnalisé : séquençage des apprentissages, jalons, respect du référentiel ECSR.</p>'],
        3 => ['name' => 'Conception de fiches de séance',
              'summary' => '<p>Structure d\'une fiche de séance : objectifs SMART, déroulé, matériel, critères de réussite, durée estimée.</p>'],
        4 => ['name' => 'Outils et supports de préparation',
              'summary' => '<p>Livret d\'apprentissage, outils numériques, applications de suivi, grilles de préparation standardisées.</p>'],
    ],

    'ECSR-CCP1-M4' => [
        1 => ['name' => 'Communication et relation pédagogique en voiture',
              'summary' => '<p>Techniques de guidage oral, langage clair et précis, gestion de la prise de parole, encouragement en situation réelle.</p>'],
        2 => ['name' => 'Gestion des risques et sécurité active/passive',
              'summary' => '<p>Anticipation des dangers, double-commandes, vigilance partagée, gestion des incidents en cours de séance.</p>'],
        3 => ['name' => 'Techniques d\'enseignement progressif de la conduite',
              'summary' => '<p>Progression par paliers : maniabilité, circulation urbaine, voie rapide, conduite de nuit, conditions difficiles.</p>'],
        4 => ['name' => 'Analyse et débrief de séance avec l\'apprenant',
              'summary' => '<p>Méthodes de débriefing : auto-évaluation guidée, retour structuré, fixation des objectifs de la séance suivante.</p>'],
        5 => ['name' => 'Situations complexes : voie rapide, conduite de nuit, intempéries',
              'summary' => '<p>Gestion pédagogique des situations à risque élevé. Préparation de l\'apprenant, adaptations comportementales.</p>'],
    ],

    'ECSR-CCP1-M5' => [
        1 => ['name' => 'Évaluation formative et sommative de la conduite',
              'summary' => '<p>Distinguer évaluation en cours d\'apprentissage et évaluation certificative. Outils adaptés à chaque étape.</p>'],
        2 => ['name' => 'Grilles d\'observation et critères d\'évaluation',
              'summary' => '<p>Utilisation des grilles réglementaires, construction de critères observables, cotation des compétences conduites.</p>'],
        3 => ['name' => 'Livret d\'apprentissage et suivi personnalisé',
              'summary' => '<p>Renseignement du livret, traçabilité des séances, communication des progrès à l\'apprenant et à l\'équipe.</p>'],
        4 => ['name' => 'Compte rendu de séance et communication pédagogique',
              'summary' => '<p>Rédaction de comptes rendus clairs, communication avec les parents (AAC), coordination en équipe pédagogique.</p>'],
    ],

    'ECSR-CCP1-EXAM' => [
        1 => ['name' => 'Quiz formatifs – Cadre réglementaire et pédagogie',
              'summary' => '<p>Séries de questions portant sur les modules M1 et M2. Correction automatique avec explication des réponses.</p>'],
        2 => ['name' => 'Études de cas – Situations pédagogiques réelles',
              'summary' => '<p>Analyse de scénarios de formation : choisir la bonne approche pédagogique, gérer une difficulté, rédiger un bilan.</p>'],
        3 => ['name' => 'Simulation d\'épreuve CCP1 – Conditions d\'examen',
              'summary' => '<p>Épreuve blanche complète dans les conditions réelles de la certification. Feedback détaillé sur les résultats.</p>'],
    ],

    // ── CCP2 ────────────────────────────────────────────────────────────────

    'ECSR-CCP2-M1' => [
        1 => ['name' => 'Sources et méthodes de veille juridique et professionnelle',
              'summary' => '<p>Légifrance, Journal Officiel, bulletins officiels, associations professionnelles, réseaux ECSR. Organiser sa veille.</p>'],
        2 => ['name' => 'Évolutions du code de la route et normes ECSR',
              'summary' => '<p>Suivi des modifications réglementaires : nouvelles infractions, évolutions des épreuves, réformes du permis de conduire.</p>'],
        3 => ['name' => 'Outils numériques de veille professionnelle',
              'summary' => '<p>Agrégateurs RSS, alertes Google, newsletters spécialisées, plateformes de formation continue en ligne.</p>'],
        4 => ['name' => 'Synthèse et diffusion de l\'information dans l\'équipe',
              'summary' => '<p>Rédiger une note de synthèse, animer une réunion d\'information, mettre à jour les supports pédagogiques.</p>'],
    ],

    'ECSR-CCP2-M2' => [
        1 => ['name' => 'Techniques d\'accueil physique et téléphonique',
              'summary' => '<p>Premier contact, image de l\'établissement, écoute active, reformulation, prise de rendez-vous et suivi.</p>'],
        2 => ['name' => 'Analyse des besoins et conseil personnalisé',
              'summary' => '<p>Méthode SONCAS, questions ouvertes, identification du profil client, recommandation de la formule adaptée.</p>'],
        3 => ['name' => 'Présentation des offres : permis, CPF, financements',
              'summary' => '<p>Argumentaire des formules, explication du CPF, aides régionales, permis à 1€/jour, construction d\'un devis.</p>'],
        4 => ['name' => 'Gestion des situations difficiles et réclamations',
              'summary' => '<p>Traitement des objections, gestion de l\'insatisfaction, procédure de réclamation, suivi post-résolution.</p>'],
    ],

    'ECSR-CCP2-M3' => [
        1 => ['name' => 'Stratégie de communication locale',
              'summary' => '<p>Plan de communication : affichage, presse locale, radio, flyers, événements. Ciblage géographique et démographique.</p>'],
        2 => ['name' => 'Réseaux sociaux et marketing digital',
              'summary' => '<p>Facebook, Instagram, Google My Business : créer du contenu, gérer les avis clients, publicité en ligne, analytics.</p>'],
        3 => ['name' => 'Partenariats : lycées, entreprises, collectivités',
              'summary' => '<p>Identifier et approcher des partenaires locaux, conventions de partenariat, actions communes (sensibilisation, BSR).</p>'],
        4 => ['name' => 'Fidélisation et développement du portefeuille client',
              'summary' => '<p>Programme de fidélité, recommandations, suivi post-permis, offres complémentaires (conduite supervisée, AAC).</p>'],
    ],

    'ECSR-CCP2-M4' => [
        1 => ['name' => 'Logiciels de gestion d\'auto-école',
              'summary' => '<p>Présentation des principaux logiciels métier : gestion des élèves, planning, facturation, suivi pédagogique intégré.</p>'],
        2 => ['name' => 'Suivi des heures de conduite et facturation',
              'summary' => '<p>Enregistrement des séances, calcul des soldes d\'heures, édition de factures conformes, gestion des impayés.</p>'],
        3 => ['name' => 'Financement CPF et dossiers de prise en charge',
              'summary' => '<p>Fonctionnement du CPF sur Mon Compte Formation, montage de dossier, relations avec les OPCO, conformité Qualiopi.</p>'],
        4 => ['name' => 'Obligations comptables et administratives',
              'summary' => '<p>Documents obligatoires, archivage, obligations DREETS sur la formation à distance, protection des données (RGPD).</p>'],
    ],

    'ECSR-CCP2-EXAM' => [
        1 => ['name' => 'Quiz formatifs – Veille et relation client',
              'summary' => '<p>Questions portant sur les modules M1 et M2. Évaluation des connaissances réglementaires et commerciales.</p>'],
        2 => ['name' => 'Mises en situation commerciales et administratives',
              'summary' => '<p>Jeux de rôle : accueil client, présentation CPF, gestion d\'une réclamation. Montage d\'un dossier de prise en charge.</p>'],
        3 => ['name' => 'Simulation d\'épreuve CCP2 – Conditions d\'examen',
              'summary' => '<p>Épreuve blanche complète dans les conditions réelles de la certification. Feedback détaillé sur les résultats.</p>'],
    ],
];

// ── Application des sections ─────────────────────────────────────────────────
foreach ($courses_sections as $shortname => $sections) {
    $course = $DB->get_record('course', ['shortname' => $shortname]);
    if (!$course) {
        cli_writeln("✗ Cours introuvable : $shortname");
        continue;
    }
    cli_writeln("→ {$course->fullname}");

    foreach ($sections as $sectionnum => $data) {
        $section = $DB->get_record('course_sections', [
            'course'  => $course->id,
            'section' => $sectionnum,
        ]);
        if (!$section) {
            cli_writeln("  ✗ Section $sectionnum introuvable");
            continue;
        }
        $DB->update_record('course_sections', (object)[
            'id'            => $section->id,
            'name'          => $data['name'],
            'summary'       => $data['summary'],
            'summaryformat' => FORMAT_HTML,
        ]);
        cli_writeln("  ✓ Section $sectionnum : {$data['name']}");
    }
}

// Purge caches
purge_all_caches();
cli_writeln('');
cli_writeln('Sections mises à jour. Caches purgés.');
